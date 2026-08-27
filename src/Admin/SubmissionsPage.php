<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

use Convermetry\Analytics\SubmissionContext;
use Convermetry\Database\FormSubmissions;
use Convermetry\Forms\SubmissionFields;
use Convermetry\Leads\LeadEvents;
use Convermetry\Leads\LeadService;
use Convermetry\Leads\LeadStatus;
use Convermetry\Leads\Money;
use Convermetry\Settings\Options;

/**
 * The "Convermetry → Submissions" admin page.
 *
 * The lead-facing counterpart to the Activity Log: where that page shows every
 * outbound DELIVERY ATTEMPT, this one shows the SUBMISSIONS themselves — the
 * durable records in {@see FormSubmissions} — and answers "who converted, and
 * which marketing produced them?".
 *
 * The submission is authoritative here; webhook delivery is merely something
 * that happened to it. Every confirmed submission is listed whether or not any
 * webhook endpoint is configured, and the delivery-status chip reads
 * "Not sent" — neutrally, not as an error — when none is.
 *
 * The page renders as a list of collapsed rows (date, lead, form, page,
 * channel, campaign, delivery status), each expanding into a detail panel with
 * the form's identity, the analytics/attribution context and visitor journey,
 * the visitor's own field values, and per-endpoint delivery results linking
 * back into the Activity Log.
 *
 * Rows are fetched client-side (assets/js/submissions.js) via the
 * cvm_get_submissions AJAX action, with detail panels loaded lazily on first
 * expand via cvm_get_submission_detail.
 */
final class SubmissionsPage
{
    /** Menu slug for the submenu page. */
    public const string MENU_SLUG = 'convermetry-submissions';

    /** Rows fetched per database round-trip while streaming an export. */
    private const int EXPORT_CHUNK = 200;

    /** Filter values the delivery-status dropdown accepts. */
    private const array STATES = ['delivered', 'partial', 'failed', 'pending', 'not_sent'];

    /**
     * Registers menu, asset, action, and AJAX hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'processClearSubmissions']);
        add_action('admin_init', [self::class, 'processExport']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);

        add_action('wp_ajax_cvm_get_submissions', [self::class, 'handleGetSubmissionsAjax']);
        add_action('wp_ajax_cvm_get_submission_detail', [self::class, 'handleGetDetailAjax']);
        add_action('wp_ajax_cvm_delete_submission', [self::class, 'handleDeleteAjax']);
        add_action('wp_ajax_cvm_update_lead', [self::class, 'handleUpdateLeadAjax']);
    }

    /**
     * Adds the Submissions submenu.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            AnalyticsPage::MENU_SLUG,
            'Convermetry Submissions',
            'Submissions',
            Capability::required(Capability::SUBMISSIONS_VIEW),
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Enqueues the page's script on this page only.
     *
     * The shared admin stylesheet is already enqueued for every Convermetry
     * screen by {@see AnalyticsPage::enqueueAssets()}.
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    public static function enqueueAssets(string $hook): void
    {
        if (!str_contains($hook, self::MENU_SLUG)) {
            return;
        }

        wp_enqueue_script(
            'cvm-submissions',
            CVM_PLUGIN_URL . 'assets/js/submissions.js',
            [],
            CVM_VERSION,
            true
        );

        wp_localize_script('cvm-submissions', 'CVM_SUB', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            // Seeds the list's search box from the URL, so a deep link can
            // open one submission. Notification emails link here with the
            // submission id, and buildWhereClause() matches submission_id
            // exactly — without this the link would silently open the full,
            // unfiltered list, which is worse than no link at all.
            'initialSearch' => isset($_GET['cvm_search'])
                ? sanitize_text_field(wp_unslash($_GET['cvm_search']))
                : '',
            'listNonce'    => wp_create_nonce('cvm_get_submissions'),
            'detailNonce'  => wp_create_nonce('cvm_get_submission_detail'),
            'deleteNonce'  => wp_create_nonce('cvm_delete_submission'),
            'leadNonce'    => wp_create_nonce('cvm_update_lead'),
            'leadStatuses' => LeadStatus::labels(),
            'exportBase'   => wp_nonce_url(
                add_query_arg(
                    ['page' => self::MENU_SLUG, 'cvm_export' => 'csv_filtered'],
                    self_admin_url('admin.php')
                ),
                'cvm_submissions_export_csv_filtered'
            ),
        ]);
    }

    // ── Request handlers ─────────────────────────────────────────────────────

    /**
     * Deletes every stored submission if a valid nonce-protected POST is
     * detected, then redirects back with a notice flag.
     *
     * @return void
     */
    public static function processClearSubmissions(): void
    {
        if (
            ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' ||
            !isset($_POST['cvm_action']) ||
            $_POST['cvm_action'] !== 'clear_submissions' ||
            !isset($_POST['cvm_clear_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvm_clear_nonce'])), 'cvm_clear_submissions') ||
            !Capability::currentUserCan(Capability::SUBMISSIONS_DELETE)
        ) {
            return;
        }

        FormSubmissions::clearAll();

        wp_safe_redirect(
            add_query_arg(['page' => self::MENU_SLUG, 'cvm_cleared' => '1'], self_admin_url('admin.php'))
        );
        exit;
    }

    /**
     * Streams a CSV file download when a valid export link is followed.
     *
     * Two variants: 'csv' exports every submission, 'csv_filtered' exports
     * only those matching the filters carried in the query string (the JS
     * keeps that link in sync with what is on screen).
     *
     * @return void
     */
    public static function processExport(): void
    {
        if (!isset($_GET['cvm_export']) || !Capability::currentUserCan(Capability::SUBMISSIONS_EXPORT)) {
            return;
        }

        // Only act on this plugin's page so the shared query var can never
        // hijack another admin screen.
        if (!isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG) {
            return;
        }

        $type = sanitize_key((string) $_GET['cvm_export']);
        if ($type !== 'csv' && $type !== 'csv_filtered') {
            return;
        }

        if (
            !isset($_GET['_wpnonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_GET['_wpnonce'])),
                'cvm_submissions_export_' . $type
            )
        ) {
            wp_die('Invalid or expired export link.');
        }

        // The filtered export re-sanitizes the query string through the exact
        // code path the AJAX list uses, so the file can never contain rows the
        // screen would have excluded.
        self::exportCsv($type === 'csv_filtered' ? self::filtersFromRequest($_GET) : []);
    }

    /**
     * Handles the cvm_get_submissions AJAX action.
     *
     * Returns one page of rendered submission rows plus the totals and the
     * distinct values every filter dropdown needs.
     *
     * @return never
     */
    public static function handleGetSubmissionsAjax(): never
    {
        self::authorize('cvm_get_submissions', Capability::SUBMISSIONS_VIEW);

        $page    = max(1, (int) ($_POST['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($_POST['per_page'] ?? 10)));

        $filters = self::filtersFromRequest($_POST);

        $total      = FormSubmissions::getCount($filters);
        $totalPages = max(1, (int) ceil($total / $perPage));

        // Clamp before querying. A page can fall off the end for several
        // reasons — the last row on it was just deleted, a filter narrowed the
        // set, retention pruned it, a bookmarked page number went stale — and
        // an unclamped request answers with an empty list plus a nonsensical
        // "Showing 11-10 of 10" and no way back. Fixing it here covers every
        // cause at once; the client syncs to the currentPage it gets back.
        $page = min($page, $totalPages);

        $rows  = FormSubmissions::getPaginated($page, $perPage, $filters);
        $dates = FormSubmissions::getDistinctDates($filters);

        $statuses = self::deliveryStatuses($rows);

        $html = '';
        foreach ($rows as $row) {
            $html .= self::renderRowHtml($row, $statuses[(string) ($row['submission_id'] ?? '')] ?? null);
        }

        wp_send_json_success([
            'html'        => $html,
            'total'       => $total,
            'totalPages'  => $totalPages,
            'currentPage' => $page,
            'years'       => $dates['years'],
            'months'      => $dates['months'],
            'providers'   => FormSubmissions::getDistinctValues('provider'),
            'formNames'   => FormSubmissions::getDistinctValues('form_name'),
            'channels'    => FormSubmissions::getDistinctValues('channel'),
            'campaigns'   => FormSubmissions::getDistinctValues('utm_campaign'),
            'posture'     => self::webhookPosture(),
        ]);
    }

    /**
     * Handles the cvm_get_submission_detail AJAX action.
     *
     * @return never
     */
    public static function handleGetDetailAjax(): never
    {
        self::authorize('cvm_get_submission_detail', Capability::SUBMISSIONS_VIEW);

        $id  = (int) ($_POST['submission_row'] ?? 0);
        $row = $id > 0 ? FormSubmissions::get($id) : null;

        if ($row === null) {
            wp_send_json_error(['message' => 'That submission no longer exists.']);
        }

        // The session summary (pageview count, session start, recent pages)
        // is normally computed when a webhook delivery freezes its payload —
        // which never happens on a site with no endpoints configured. Fill it
        // in lazily here instead; SubmissionContext::enrich() persists what it
        // computes, so this runs at most once per submission ever.
        $row = SubmissionContext::enrich($row);

        $status = self::deliveryStatuses([$row])[(string) ($row['submission_id'] ?? '')] ?? null;

        wp_send_json_success(['html' => self::renderDetailHtml($row, $status)]);
    }

    /**
     * Handles the cvm_delete_submission AJAX action.
     *
     * @return never
     */
    public static function handleDeleteAjax(): never
    {
        self::authorize('cvm_delete_submission', Capability::SUBMISSIONS_DELETE);

        $id = (int) ($_POST['submission_row'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error(['message' => 'Invalid submission id.']);
        }

        FormSubmissions::deleteSubmission($id);
        wp_send_json_success();
    }

    /**
     * Handles the cvm_update_lead AJAX action.
     *
     * Status and value are sent independently — the UI updates whichever the
     * administrator touched — so an absent key means "leave unchanged" while an
     * empty value string means "clear the recorded value". Conflating the two
     * would make it impossible to remove a value once entered.
     *
     * @return never
     */
    public static function handleUpdateLeadAjax(): never
    {
        self::authorize('cvm_update_lead', Capability::LEADS_EDIT);

        $submissionId = sanitize_text_field((string) ($_POST['submission_id'] ?? ''));
        if ($submissionId === '') {
            wp_send_json_error(['message' => 'Invalid submission id.']);
        }

        $status = array_key_exists('lead_status', $_POST)
            ? sanitize_key((string) wp_unslash($_POST['lead_status']))
            : null;

        $value = array_key_exists('lead_value', $_POST)
            ? sanitize_text_field((string) wp_unslash($_POST['lead_value']))
            : null;

        $result = LeadService::update($submissionId, $status, $value, get_current_user_id());

        if (!$result['ok']) {
            wp_send_json_error(['message' => $result['message']]);
        }

        wp_send_json_success([
            'status'      => $result['status'],
            'statusLabel' => LeadStatus::label($result['status']),
            'chipClass'   => LeadStatus::chipClass($result['status']),
            'value'       => $result['value'],
            'valueLabel'  => Money::format($result['value'], $result['currency']),
        ]);
    }

    /**
     * Shared nonce + capability guard for every AJAX action on this page.
     *
     * The scope is a parameter rather than a constant because these four
     * actions are not equally privileged: two of them read, one deletes a
     * submission outright, and one writes a lead's commercial value. Sharing a
     * single capability here would have made the scope split on the rest of the
     * page decorative.
     *
     * @param string $action The action name the nonce was created for.
     * @param string $scope  The {@see Capability} scope this action needs.
     * @return void
     */
    private static function authorize(string $action, string $scope): void
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), $action) ||
            !Capability::currentUserCan($scope)
        ) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }
    }

    /**
     * Sanitizes the filter set out of a request array ($_POST for AJAX,
     * $_GET for the filtered export), so both paths agree by construction.
     *
     * @param array<string, mixed> $src Raw request array.
     * @return array<string, string>
     */
    private static function filtersFromRequest(array $src): array
    {
        // Every value is read through scalarParam(): a request is free to send
        // ?channel[]=x, and casting that array to string would emit a PHP
        // warning and filter on the literal "Array".
        $status = sanitize_key(self::scalarParam($src, 'delivery_status'));

        $leadStatus = sanitize_key(self::scalarParam($src, 'lead_status'));
        $hasValue   = sanitize_key(self::scalarParam($src, 'has_value'));

        return [
            'year'            => sanitize_text_field(self::scalarParam($src, 'filter_year')),
            'month'           => sanitize_text_field(self::scalarParam($src, 'filter_month')),
            'provider'        => sanitize_key(self::scalarParam($src, 'provider')),
            'form_name'       => sanitize_text_field(self::scalarParam($src, 'form_name')),
            'channel'         => sanitize_text_field(self::scalarParam($src, 'channel')),
            'campaign'        => sanitize_text_field(self::scalarParam($src, 'campaign')),
            'search'          => sanitize_text_field(self::scalarParam($src, 'search')),
            'delivery_status' => in_array($status, self::STATES, true) ? $status : '',
            'lead_status'     => LeadStatus::isValid($leadStatus) ? $leadStatus : '',
            'has_value'       => in_array($hasValue, ['yes', 'no'], true) ? $hasValue : '',
        ];
    }

    /**
     * Reads one unslashed scalar value out of a request array, treating any
     * non-scalar (an array from `?key[]=…`) as absent.
     *
     * @param array<string, mixed> $src Raw request array.
     * @param string               $key Parameter name.
     * @return string
     */
    private static function scalarParam(array $src, string $key): string
    {
        $value = $src[$key] ?? '';

        return is_scalar($value) ? (string) wp_unslash($value) : '';
    }

    // ── Delivery status ──────────────────────────────────────────────────────

    /**
     * How webhook delivery is currently configured, for wording only.
     *
     * 'paused' and 'none' both mean nothing will be delivered, but they are
     * different situations and telling a user with three configured endpoints
     * that they have "no form webhook" is simply wrong.
     *
     * @return string 'active', 'paused', or 'none'.
     */
    private static function webhookPosture(): string
    {
        if (Options::formEndpoints() === []) {
            return 'none';
        }

        return Options::webhooksActive() ? 'active' : 'paused';
    }

    /**
     * Reads the recorded delivery status of every submission in a page of rows.
     *
     * No queries at all now: the state and its per-endpoint detail are stored
     * on the submission itself by {@see FormSubmissions::refreshDeliveryState()}.
     * This used to run two cross-table queries and re-derive the answer from
     * the Activity Log, which meant clearing the log rewrote history.
     *
     * @param array<int, array<string, mixed>> $rows Submission rows.
     * @return array<string, array<string, mixed>> Status arrays keyed by submission_id.
     */
    private static function deliveryStatuses(array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            $submissionId = (string) ($row['submission_id'] ?? '');
            if ($submissionId === '') {
                continue;
            }

            $out[$submissionId] = self::deliveryStatus($row);
        }

        return $out;
    }

    /**
     * The display status for one submission row.
     *
     * @param array<string, mixed> $row Submission row.
     * @return array{state: string, label: string, endpoints: array<int, array<string, mixed>>}
     */
    private static function deliveryStatus(array $row): array
    {
        $endpoints = self::decodeJson((string) ($row['delivery_json'] ?? ''));
        $endpoints = array_values(array_filter($endpoints, 'is_array'));

        $state = (string) ($row['delivery_state'] ?? '');

        // A row whose state has not been recorded yet (pre-1.3.0, awaiting
        // backfill) is classified from whatever detail it does carry, so the
        // list stays correct while the migration drains.
        if (!in_array($state, FormSubmissions::DELIVERY_STATES, true)) {
            $state = FormSubmissions::classifyDelivery($endpoints);
        }

        return [
            'state'     => $state,
            'label'     => self::statusLabel($state, $endpoints),
            'endpoints' => $endpoints,
        ];
    }

    /**
     * Human wording for a delivery state.
     *
     * @param array<int, array<string, mixed>> $endpoints Per-endpoint outcomes.
     * @return string
     */
    private static function statusLabel(string $state, array $endpoints): string
    {
        $count = count($endpoints);

        if ($state === 'pending') {
            $attempts = array_map(static fn(array $e): int => (int) ($e['attempt'] ?? 0), $endpoints);
            $attempt  = $attempts === [] ? 0 : max($attempts);

            return $attempt > 0 ? sprintf('Queued · retry %d', $attempt) : 'Queued';
        }

        if ($state === 'not_sent') {
            return match (self::webhookPosture()) {
                'none'   => 'Not sent — no form webhook',
                'paused' => 'Not sent — webhooks paused',
                default  => 'Not sent',
            };
        }

        $ok = count(array_filter($endpoints, static fn(array $e): bool => !empty($e['ok'])));

        return match ($state) {
            'delivered' => $count > 1 ? sprintf('Delivered (%d)', $count) : 'Delivered',
            'partial'   => sprintf('Partial (%d/%d)', $ok, $count),
            default     => 'Failed',
        };
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    /**
     * Renders the full Submissions page shell. The list itself is injected by
     * submissions.js on load.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!Capability::currentUserCan(Capability::SUBMISSIONS_VIEW)) {
            return;
        }

        $cleared = isset($_GET['cvm_cleared']) && $_GET['cvm_cleared'] === '1';
        $total   = FormSubmissions::getCount();
        $posture = self::webhookPosture();

        // Nudge the derived-column migration along. Sites whose WP-Cron never
        // fires would otherwise show blank attribution and a broken status
        // filter indefinitely; the pass is bounded by its own time budget and
        // is a no-op once every row is populated.
        if (FormSubmissions::needsBackfill()) {
            FormSubmissions::backfillDerivedColumns();
        }

        ?>
        <div class="wrap cvm-wrap cvm-submissions-wrap">
            <h1>Submissions</h1>

            <?php if ($cleared): ?>
                <div class="notice notice-success is-dismissible"><p>All submissions have been deleted.</p></div>
            <?php endif; ?>

            <p class="description cvm-submissions-intro">
                Every form submission Convermetry confirmed server-side, joined to the analytics
                session that produced it. Submissions are recorded whether or not a webhook is
                configured — expand a row to see the form, its attribution, and the visitor's answers.
            </p>

            <?php if ($posture === 'none'): ?>
                <div class="notice notice-info inline cvm-retention-notice">
                    <p>
                        No webhook endpoint is currently set to receive form submissions, so every row
                        below shows <strong>Not sent</strong>. That is expected — recording and
                        attribution work independently of delivery. Add an endpoint under
                        <a href="<?php echo esc_url(add_query_arg(['page' => WebhooksPage::MENU_SLUG], self_admin_url('admin.php'))); ?>">Webhooks</a>
                        to forward leads onward.
                    </p>
                </div>
            <?php elseif ($posture === 'paused'): ?>
                <div class="notice notice-info inline cvm-retention-notice">
                    <p>
                        Webhook delivery is currently <strong>paused</strong>, so new submissions are
                        recorded here but not forwarded. Resume it under
                        <a href="<?php echo esc_url(add_query_arg(['page' => WebhooksPage::MENU_SLUG], self_admin_url('admin.php'))); ?>">Webhooks</a>;
                        queued deliveries are kept, not discarded.
                    </p>
                </div>
            <?php endif; ?>

            <div class="notice notice-info inline cvm-retention-notice">
                <p>
                    <strong>Data retention:</strong>
                    Submissions older than <?php echo esc_html((string) Options::retentionDays()); ?> days are
                    automatically removed daily. The retention period is shared with the analytics data
                    and can be changed under <strong>Settings</strong>. Submissions contain the
                    information visitors typed into your forms — treat exports accordingly.
                </p>
            </div>

            <div class="cvm-delivery-toolbar">
                <form method="post" action="" class="cvm-clear-form">
                    <?php wp_nonce_field('cvm_clear_submissions', 'cvm_clear_nonce'); ?>
                    <input type="hidden" name="cvm_action" value="clear_submissions">
                    <button
                        type="submit"
                        class="button button-secondary cvm-btn-danger"
                        onclick="return confirm('Delete every stored submission? This permanently removes the lead data and cannot be undone. Activity Log entries are not affected.');"
                        <?php disabled($total, 0); ?>
                    >
                        Clear All Submissions
                    </button>
                </form>

                <?php if ($total > 0): ?>
                    <div class="cvm-export-buttons">
                        <a href="#" class="button button-secondary cvm-export-filtered">Export Current Filters</a>
                        <a
                            href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => self::MENU_SLUG, 'cvm_export' => 'csv'], self_admin_url('admin.php')), 'cvm_submissions_export_csv')); ?>"
                            class="button button-secondary"
                        >
                            Export All To CSV
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div id="cvm-submissions" data-total="<?php echo esc_attr((string) $total); ?>">
                <!-- Controls, list, and pagination injected by submissions.js -->
            </div>
        </div>
        <?php
    }

    /**
     * Renders one collapsed submission row (the accordion header plus the
     * empty body its detail is lazily loaded into).
     *
     * @param array<string, mixed>      $row    Submission row.
     * @param array<string, mixed>|null $status Delivery status, or null when unknown.
     * @return string
     */
    private static function renderRowHtml(array $row, ?array $status): string
    {
        $rowId    = (int) ($row['id'] ?? 0);
        $subId    = (string) ($row['submission_id'] ?? '');
        $created  = (string) ($row['created_at'] ?? '');
        $formName = (string) ($row['form_name'] ?? '');
        $provider = (string) ($row['provider'] ?? '');
        $channel  = (string) ($row['channel'] ?? '');
        $campaign = (string) ($row['utm_campaign'] ?? '');
        $pageUrl  = (string) ($row['page_url'] ?? '');
        $lead     = self::leadLabel(SubmissionFields::fromStoredJson((string) ($row['submission_data'] ?? '')));

        $state      = (string) ($status['state'] ?? 'not_sent');
        $stateLabel = (string) ($status['label'] ?? 'Not sent');
        $bodyId     = 'cvm-sub-body-' . $rowId;

        $leadStatus = LeadStatus::normalize($row['lead_status'] ?? null);
        $leadValue  = Money::format(
            $row['lead_value'] === null ? null : (string) $row['lead_value'],
            (string) ($row['lead_currency'] ?? '')
        );

        ob_start();
        ?>
        <li class="cvm-submission-item" data-row-id="<?php echo esc_attr((string) $rowId); ?>">
            <button
                type="button"
                class="cvm-submission-summary"
                aria-expanded="false"
                aria-controls="<?php echo esc_attr($bodyId); ?>"
                aria-label="<?php echo esc_attr(sprintf(
                    'Submission from %s via %s on %s — %s. Expand for details.',
                    $lead,
                    $formName !== '' ? $formName : 'an unnamed form',
                    self::formatDate($created),
                    $stateLabel
                )); ?>"
            >
                <span class="cvm-sub-col cvm-sub-date"><?php echo esc_html(self::formatDate($created)); ?></span>
                <span class="cvm-sub-col cvm-sub-lead"><?php echo esc_html($lead); ?></span>
                <span class="cvm-sub-col cvm-sub-form">
                    <?php echo esc_html($formName !== '' ? $formName : '(unnamed form)'); ?>
                    <?php if ($provider !== ''): ?>
                        <span class="cvm-sub-provider"><?php echo esc_html($provider); ?></span>
                    <?php endif; ?>
                </span>
                <span class="cvm-sub-col cvm-sub-page"><?php echo esc_html(self::pathOf($pageUrl)); ?></span>
                <span class="cvm-sub-col cvm-sub-channel"><?php echo esc_html($channel !== '' ? $channel : '—'); ?></span>
                <span class="cvm-sub-col cvm-sub-campaign"><?php echo esc_html($campaign !== '' ? $campaign : '—'); ?></span>
                <span class="cvm-sub-col cvm-sub-lead-status">
                    <span class="cvm-status-chip <?php echo esc_attr(LeadStatus::chipClass($leadStatus)); ?>">
                        <?php echo esc_html(LeadStatus::label($leadStatus)); ?>
                    </span>
                    <?php if ($leadValue !== '') : ?>
                        <span class="cvm-sub-lead-value"><?php echo esc_html($leadValue); ?></span>
                    <?php endif; ?>
                </span>
                <span class="cvm-sub-col cvm-sub-status">
                    <span class="cvm-status-chip cvm-status-<?php echo esc_attr($state); ?>">
                        <?php echo esc_html($stateLabel); ?>
                    </span>
                </span>
                <span class="cvm-accordion-arrow" aria-hidden="true">&#9660;</span>
            </button>

            <div class="cvm-submission-detail" id="<?php echo esc_attr($bodyId); ?>" data-submission-id="<?php echo esc_attr($subId); ?>" hidden>
                <!-- Injected by submissions.js via cvm_get_submission_detail -->
            </div>
        </li>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renders one submission's expanded detail panel.
     *
     * @param array<string, mixed>      $row    Submission row (context already enriched).
     * @param array<string, mixed>|null $status Delivery status.
     * @return string
     */
    private static function renderDetailHtml(array $row, ?array $status): string
    {
        $context     = self::decodeJson((string) ($row['context'] ?? ''));
        $attribution = is_array($context['attribution'] ?? null) ? $context['attribution'] : [];
        // Historical rows still hold the pre-2.0 associative map; the
        // normalizer reads either shape, so both render identically here.
        $fields      = SubmissionFields::fromStoredJson((string) ($row['submission_data'] ?? ''));
        $pageQuery   = self::decodeJson((string) ($row['page_query'] ?? ''));
        $landing     = is_array($context['landing_page'] ?? null)
            ? (string) ($context['landing_page']['url'] ?? '')
            : '';
        $recentPages = is_array($context['recent_pages'] ?? null) ? $context['recent_pages'] : [];

        $formPairs = [
            'Provider'       => (string) ($row['provider'] ?? ''),
            'Form name'      => (string) ($row['form_name'] ?? ''),
            'Form ID'        => (string) ($row['form_id'] ?? ''),
            'Native form ID' => (string) ($row['native_form_id'] ?? ''),
            'Conversion page' => (string) ($row['page_url'] ?? ''),
            'Submitted'      => (string) ($row['created_at'] ?? '') . ' UTC',
            'Submission ID'  => (string) ($row['submission_id'] ?? ''),
            'Conversion ID'  => (string) ($row['conversion_id'] ?? ''),
        ];

        $analyticsPairs = [
            'Channel'           => (string) ($row['channel'] ?? ''),
            'Source'            => (string) ($attribution['utm_source'] ?? ''),
            'Medium'            => (string) ($attribution['utm_medium'] ?? ''),
            'Campaign'          => (string) ($attribution['utm_campaign'] ?? ''),
            'Campaign ID'       => (string) ($attribution['utm_id'] ?? ''),
            'Term'              => (string) ($attribution['utm_term'] ?? ''),
            'Content'           => (string) ($attribution['utm_content'] ?? ''),
            'Ad click type'     => (string) ($attribution['click_id_type'] ?? ''),
            'Entrance referrer' => (string) ($context['entrance_referrer'] ?? ''),
            'Landing page'      => $landing,
            'Device'            => (string) ($context['device'] ?? ''),
            'Session ID'        => (string) ($row['session_id'] ?? ''),
            'Session started'   => (string) ($context['session_started_at'] ?? ''),
            'Pages viewed'      => isset($context['pageview_count']) ? (string) (int) $context['pageview_count'] : '',
        ];

        // The IP is resolved server-side from the request, independently of
        // the tracker, so it is deliberately NOT part of this test: a
        // server-to-server submission has an address and no attribution at
        // all, and that is precisely when the explanation below is needed.
        $hasContext = !self::allEmpty($analyticsPairs);

        $analyticsPairs['IP address'] = (string) ($row['ip_address'] ?? '');

        ob_start();
        ?>
        <div class="cvm-detail-inner">

            <div class="cvm-detail-actions">
                <button type="button" class="button cvm-submission-delete-btn">Delete Submission</button>
            </div>

            <?php echo self::renderLeadBlock($row); ?>

            <div class="cvm-detail-block">
                <h4>Form</h4>
                <?php echo self::renderPairs($formPairs); ?>
            </div>

            <div class="cvm-detail-block">
                <h4>Analytics &amp; attribution</h4>
                <?php if (!$hasContext): ?>
                    <p class="cvm-empty-msg">
                        No analytics context was captured for this submission — the tracker's
                        correlation fields did not reach the server (JavaScript blocked, tracking
                        disabled, a privacy signal honored, or a server-to-server submission).
                    </p>
                <?php endif; ?>
                <?php echo self::renderPairs($analyticsPairs); ?>
            </div>

            <?php if ($recentPages !== []): ?>
                <div class="cvm-detail-block">
                    <h4>Visitor journey</h4>
                    <ol class="cvm-journey">
                        <?php foreach (array_reverse($recentPages) as $pageUrl): ?>
                            <li><?php echo esc_html(self::pathOf((string) $pageUrl)); ?></li>
                        <?php endforeach; ?>
                        <li class="cvm-journey-end">Form submitted</li>
                    </ol>
                </div>
            <?php endif; ?>

            <div class="cvm-detail-block">
                <h4>Submitted fields</h4>
                <?php if ($fields === []): ?>
                    <p class="cvm-empty-msg">This submission recorded no field values.</p>
                <?php else: ?>
                    <div class="cvm-field-table-wrap">
                        <table class="cvm-field-table">
                            <tbody>
                            <?php foreach (SubmissionFields::toDisplayPairs($fields) as $pair): ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html($pair['label']); ?></th>
                                    <td><?php echo esc_html($pair['value']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($pageQuery !== []): ?>
                <div class="cvm-detail-block">
                    <h4>Page query parameters</h4>
                    <?php echo self::renderPairs(array_map(
                        static fn(mixed $v): string => self::flatten($v),
                        $pageQuery
                    )); ?>
                </div>
            <?php endif; ?>

            <div class="cvm-detail-block">
                <h4>Webhook delivery</h4>
                <?php echo self::renderDeliveryBlock($status, (string) ($row['submission_id'] ?? '')); ?>
            </div>

        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renders the lead qualification controls.
     *
     * Placed at the very top of the detail panel, above the form's own details,
     * because it is the only part of this panel an administrator ever WRITES —
     * everything below it is a record of what happened. Burying the one
     * interactive control beneath four blocks of read-only history would make
     * the common action the hardest to find.
     *
     * The history list is capped and deliberately terse. It answers "who changed
     * this and when", which is the question that actually gets asked about a
     * lead whose value someone disputes; it is not an activity feed.
     *
     * @param array<string, mixed> $row Submission row.
     * @return string
     */
    private static function renderLeadBlock(array $row): string
    {
        $submissionId = (string) ($row['submission_id'] ?? '');
        $status       = LeadStatus::normalize($row['lead_status'] ?? null);
        $value        = $row['lead_value'] === null ? '' : (string) $row['lead_value'];
        $currency     = (string) ($row['lead_currency'] ?? '');
        $updatedAt    = (string) ($row['lead_status_at'] ?? '');

        $editable = LeadService::userCanEdit();
        $history  = LeadEvents::forSubmission($submissionId, 10);

        ob_start();
        ?>
        <div class="cvm-detail-block cvm-lead-block" data-submission-id="<?php echo esc_attr($submissionId); ?>">
            <h4>Lead outcome</h4>

            <?php if (!$editable): ?>
                <p class="cvm-empty-msg">
                    Status: <strong><?php echo esc_html(LeadStatus::label($status)); ?></strong>
                    <?php if ($value !== ''): ?>
                        &middot; <?php echo esc_html(Money::format($value, $currency)); ?>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <div class="cvm-lead-controls">
                    <label class="cvm-lead-field">
                        <span>Status</span>
                        <select class="cvm-lead-status">
                            <?php foreach (LeadStatus::labels() as $machine => $label): ?>
                                <option value="<?php echo esc_attr($machine); ?>" <?php selected($machine, $status); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="cvm-lead-field">
                        <span>Value<?php echo $currency !== '' ? ' (' . esc_html($currency) . ')' : ''; ?></span>
                        <input
                            type="text"
                            class="cvm-lead-value"
                            value="<?php echo esc_attr($value); ?>"
                            placeholder="<?php echo esc_attr(Options::leadCurrency() !== '' ? '12,500.00' : '0.00'); ?>"
                            inputmode="decimal"
                        >
                    </label>

                    <button type="button" class="button button-primary cvm-lead-save">Save</button>
                    <span class="cvm-lead-feedback" role="status" aria-live="polite"></span>
                </div>

                <p class="description">
                    Recorded here only — lead outcomes are never sent to webhook endpoints in this version,
                    because a submission's payload is frozen when it is first delivered and could never reflect
                    a change made afterwards. Clear the value field to remove a recorded amount.
                </p>
            <?php endif; ?>

            <?php if ($updatedAt !== '' || $history !== []): ?>
                <div class="cvm-lead-history">
                    <h5>History</h5>
                    <ul>
                        <?php foreach ($history as $entry): ?>
                            <li>
                                <?php
                                $user = (int) $entry['user_id'] > 0 ? get_userdata((int) $entry['user_id']) : null;
                                $who  = $user instanceof \WP_User ? $user->display_name : 'someone';

                                printf(
                                    '%s &rarr; %s%s &middot; %s by %s',
                                    esc_html(LeadStatus::label((string) $entry['from_status'])),
                                    esc_html(LeadStatus::label((string) $entry['to_status'])),
                                    $entry['value'] === null
                                        ? ''
                                        : ' &middot; ' . esc_html(Money::format(
                                            (string) $entry['value'],
                                            (string) $entry['currency']
                                        )),
                                    esc_html(self::formatDate((string) $entry['created_at'])),
                                    esc_html($who)
                                );
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renders the per-endpoint delivery results, with deep links into the
     * Activity Log.
     *
     * @param array<string, mixed>|null $status       Delivery status.
     * @param string                    $submissionId The submission's id.
     * @return string
     */
    private static function renderDeliveryBlock(?array $status, string $submissionId): string
    {
        $state     = (string) ($status['state'] ?? 'not_sent');
        $endpoints = is_array($status['endpoints'] ?? null) ? $status['endpoints'] : [];

        ob_start();

        if ($state === 'not_sent') {
            ?>
            <p class="cvm-empty-msg">
                <?php switch (self::webhookPosture()):
                    case 'none': ?>
                        Not sent — no webhook endpoint is configured to receive form submissions.
                        The submission is still fully recorded here.
                        <?php break;
                    case 'paused': ?>
                        Not sent — webhook delivery is currently paused. The submission is
                        fully recorded here and will not be delivered until delivery is resumed.
                        <?php break;
                    default: ?>
                        No delivery has been attempted for this submission yet.
                <?php endswitch; ?>
            </p>
            <?php
            return (string) ob_get_clean();
        }

        $logUrl = add_query_arg(['page' => ActivityLogPage::MENU_SLUG], self_admin_url('admin.php'));
        ?>
        <ul class="cvm-delivery-list">
            <?php foreach ($endpoints as $endpoint): ?>
                <?php
                $label = (string) ($endpoint['label'] ?? '');
                $url   = (string) ($endpoint['url'] ?? '');
                $ok    = !empty($endpoint['ok']);
                $queued = !empty($endpoint['queued']);
                ?>
                <li class="cvm-delivery-row">
                    <span class="cvm-delivery-mark <?php echo esc_attr($queued ? 'queued' : ($ok ? 'ok' : 'fail')); ?>" aria-hidden="true">
                        <?php echo $queued ? '⏳' : ($ok ? '✓' : '✕'); ?>
                    </span>
                    <span class="cvm-delivery-name"><?php echo esc_html($label !== '' ? $label : $url); ?></span>
                    <span class="cvm-delivery-result">
                        <?php if ($queued): ?>
                            Queued<?php echo (int) ($endpoint['attempt'] ?? 0) > 0
                                ? esc_html(sprintf(' · %d failed attempt(s)', (int) $endpoint['attempt']))
                                : ''; ?>
                        <?php elseif ($ok): ?>
                            Delivered<?php echo (int) ($endpoint['code'] ?? 0) !== 0
                                ? esc_html(sprintf(' (%d)', (int) $endpoint['code']))
                                : ''; ?>
                        <?php else: ?>
                            Failed<?php echo (int) ($endpoint['code'] ?? 0) !== 0
                                ? esc_html(sprintf(' (%d)', (int) $endpoint['code']))
                                : ''; ?>
                        <?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="cvm-delivery-loglink">
            <a href="<?php echo esc_url($logUrl); ?>">Open the Activity Log</a>
            and search for <code><?php echo esc_html($submissionId); ?></code> to see every attempt,
            its payload, and the endpoint's response.
        </p>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Renders a label/value definition grid, skipping empty values.
     *
     * @param array<string, string> $pairs Label => value.
     * @return string
     */
    private static function renderPairs(array $pairs): string
    {
        $pairs = array_filter($pairs, static fn(string $value): bool => trim($value) !== '');

        if ($pairs === []) {
            return '';
        }

        $html = '<dl class="cvm-detail-grid">';
        foreach ($pairs as $label => $value) {
            $html .= '<dt>' . esc_html((string) $label) . '</dt>'
                   . '<dd>' . esc_html($value) . '</dd>';
        }

        return $html . '</dl>';
    }

    // ── Export ───────────────────────────────────────────────────────────────

    /**
     * Streams matching submissions as a UTF-8 CSV file and exits.
     *
     * Field sets differ from form to form, so there is no honest
     * column-per-field header: the fixed columns carry the identity,
     * attribution, and delivery state, and the visitor's own answers travel in
     * a final JSON column.
     *
     * That column is always the canonical descriptor list, normalized on the
     * way out. Historical rows still hold the pre-2.0 associative map, and
     * streaming each row's raw column verbatim would produce one file
     * containing two different JSON shapes — which no downstream importer
     * could parse without sniffing every row.
     *
     * Rows are fetched in keyset-paginated chunks and written as they arrive,
     * so memory stays bounded no matter how large the table is.
     *
     * @param array<string, string> $filters Active filters ([] exports everything).
     * @return never
     */
    private static function exportCsv(array $filters): never
    {
        $filename = 'convermetry-submissions-' . gmdate('Y-m-d') . '.csv';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');

        fwrite($output, "\xEF\xBB\xBF");

        // escape: '' is required, not cosmetic. Leaving it at the default
        // emits a deprecation notice on PHP 8.4+ — and this function streams
        // straight to the browser, so that notice lands INSIDE the downloaded
        // file and corrupts it. '' is also the RFC 4180 behaviour PHP 9 will
        // default to, and the only correct choice for a spreadsheet export.
        fputcsv($output, [
            'Date/Time (UTC)', 'Submission ID', 'Conversion ID', 'Session ID',
            'Provider', 'Form Name', 'Form ID', 'Native Form ID', 'Conversion Page',
            'Channel', 'UTM Source', 'UTM Medium', 'UTM Campaign', 'UTM Term',
            'UTM Content', 'Ad Click Type', 'Entrance Referrer', 'Landing Page',
            'Device', 'IP Address', 'Delivery Status',
            // Currency travels as its own column rather than being folded into
            // the value. A spreadsheet that mixed "12500.00" and "€12500.00" in
            // one column could not be summed or sorted, and a value with no code
            // beside it is not safely addable on a multi-currency site.
            'Lead Status', 'Lead Value', 'Lead Currency',
            'Submission Data (JSON)',
        ], escape: '');

        $beforeId = PHP_INT_MAX;

        do {
            $rows     = FormSubmissions::getChunk($beforeId, self::EXPORT_CHUNK, $filters);
            $statuses = self::deliveryStatuses($rows);

            foreach ($rows as $row) {
                $beforeId = (int) ($row['id'] ?? 0);

                $context     = self::decodeJson((string) ($row['context'] ?? ''));
                $attribution = is_array($context['attribution'] ?? null) ? $context['attribution'] : [];
                $landing     = is_array($context['landing_page'] ?? null)
                    ? (string) ($context['landing_page']['url'] ?? '')
                    : '';
                $status = $statuses[(string) ($row['submission_id'] ?? '')] ?? null;

                fputcsv($output, array_map([self::class, 'escapeCsvCell'], [
                    (string) ($row['created_at'] ?? ''),
                    (string) ($row['submission_id'] ?? ''),
                    (string) ($row['conversion_id'] ?? ''),
                    (string) ($row['session_id'] ?? ''),
                    (string) ($row['provider'] ?? ''),
                    (string) ($row['form_name'] ?? ''),
                    (string) ($row['form_id'] ?? ''),
                    (string) ($row['native_form_id'] ?? ''),
                    (string) ($row['page_url'] ?? ''),
                    (string) ($row['channel'] ?? ''),
                    (string) ($attribution['utm_source'] ?? ''),
                    (string) ($attribution['utm_medium'] ?? ''),
                    (string) ($attribution['utm_campaign'] ?? ''),
                    (string) ($attribution['utm_term'] ?? ''),
                    (string) ($attribution['utm_content'] ?? ''),
                    (string) ($attribution['click_id_type'] ?? ''),
                    (string) ($context['entrance_referrer'] ?? ''),
                    $landing,
                    (string) ($context['device'] ?? ''),
                    (string) ($row['ip_address'] ?? ''),
                    (string) ($status['label'] ?? 'Not sent'),
                    LeadStatus::label(LeadStatus::normalize($row['lead_status'] ?? null)),
                    // The raw decimal string, not the formatted display value:
                    // a spreadsheet needs a number it can sum, not "12,500.00 USD".
                    $row['lead_value'] === null ? '' : (string) $row['lead_value'],
                    (string) ($row['lead_currency'] ?? ''),
                    (string) wp_json_encode(
                        SubmissionFields::fromStoredJson((string) ($row['submission_data'] ?? '')),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ]), escape: '');
            }
        } while (count($rows) === self::EXPORT_CHUNK);

        fclose($output);
        exit;
    }

    /**
     * Prefixes spreadsheet-formula trigger characters so Excel/Sheets treat
     * the value as text rather than a formula.
     *
     * @param string $value Raw cell value.
     * @return string
     */
    private static function escapeCsvCell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "\t" . $value;
        }

        return $value;
    }

    // ── Small helpers ────────────────────────────────────────────────────────

    /**
     * The best available human label for a lead, from their own field values.
     *
     * Prefers an email address (the field most reliably present and unique),
     * then a name assembled from name-ish fields, then a phone number.
     *
     * Each heuristic tests BOTH the field id and its human label, and either
     * matching is enough. That is what structured fields bought here: a
     * Gravity Forms field matches on "Email address" while an Elementor field
     * — whose id is an opaque 'field_a1b2c3' — matches on its title. Under the
     * old label-keyed map, Elementor leads had nothing to match on at all and
     * routinely rendered as "(no contact details)".
     *
     * @param list<array{id: string, label: string, value: string|list<string>}> $fields Normalized submission fields.
     * @return string
     */
    private static function leadLabel(array $fields): string
    {
        $email = '';
        $name  = '';
        $first = '';
        $last  = '';
        $phone = '';

        foreach ($fields as $field) {
            $flat = SubmissionFields::flatten($field['value'] ?? '');
            if ($flat === '') {
                continue;
            }

            $id    = strtolower((string) ($field['id'] ?? ''));
            $label = strtolower((string) ($field['label'] ?? ''));

            $matches = static function (string ...$needles) use ($id, $label): bool {
                foreach ($needles as $needle) {
                    if (str_contains($id, $needle) || str_contains($label, $needle)) {
                        return true;
                    }
                }

                return false;
            };

            if ($email === '' && ($matches('email') || is_email($flat))) {
                $email = $flat;
                continue;
            }
            if ($first === '' && $matches('first', 'fname')) {
                $first = $flat;
                continue;
            }
            if ($last === '' && $matches('last', 'lname', 'surname')) {
                $last = $flat;
                continue;
            }
            if ($name === '' && $matches('name')) {
                $name = $flat;
                continue;
            }
            if ($phone === '' && $matches('phone', 'tel', 'mobile')) {
                $phone = $flat;
            }
        }

        $full = trim($first . ' ' . $last);
        if ($full === '') {
            $full = $name;
        }

        if ($full !== '' && $email !== '') {
            return $full . ' · ' . $email;
        }

        foreach ([$full, $email, $phone] as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return '(no contact details)';
    }

    /**
     * Reduces a stored field value to a single displayable string.
     *
     * @param mixed $value Scalar or array of scalars (checkbox groups etc.).
     * @return string
     */
    private static function flatten(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(
                static fn(mixed $item): string => is_scalar($item) ? (string) $item : '',
                $value
            ));
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The path (and query) of a URL, for compact display. Returns the input
     * unchanged when it does not parse as a URL.
     *
     * @param string $url Absolute URL.
     * @return string
     */
    private static function pathOf(string $url): string
    {
        if ($url === '') {
            return '—';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['path'])) {
            return $url;
        }

        return (string) $parts['path'] . (empty($parts['query']) ? '' : '?' . $parts['query']);
    }

    /**
     * Formats a stored UTC datetime for the compact list column.
     *
     * @param string $datetime 'Y-m-d H:i:s' in UTC.
     * @return string
     */
    private static function formatDate(string $datetime): string
    {
        $ts = strtotime($datetime . ' UTC');

        return $ts === false ? $datetime : gmdate('M j, Y H:i', $ts);
    }

    /**
     * Whether every value in a label/value map is blank.
     *
     * @param array<string, string> $pairs Label => value.
     * @return bool
     */
    private static function allEmpty(array $pairs): bool
    {
        foreach ($pairs as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Decodes a stored JSON column into an array, tolerating empty/invalid
     * values.
     *
     * @param string $json Stored JSON string.
     * @return array<string, mixed>
     */
    private static function decodeJson(string $json): array
    {
        if ($json === '' || !json_validate($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
