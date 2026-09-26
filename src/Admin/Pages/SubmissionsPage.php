<?php
declare(strict_types=1);

namespace Convermetry\Admin\Pages;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\Capability;
use Convermetry\Analytics\SubmissionContext;
use Convermetry\Database\FormSubmissions;
use Convermetry\Forms\SubmissionFieldList;
use Convermetry\Forms\SubmissionFields;
use Convermetry\Leads\LeadEvents;
use Convermetry\Leads\LeadService;
use Convermetry\Leads\LeadStatus;
use Convermetry\Webhook\DeliveryState;
use Convermetry\Webhook\EndpointOutcome;
use Convermetry\Leads\Money;
use Convermetry\Settings\Options;
use Convermetry\Support\Pagination;

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
            HomePage::MENU_SLUG,
            __('Convermetry Submissions', 'convermetry'),
            __('Submissions', 'convermetry'),
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

        wp_enqueue_style(
            'cvm-submissions',
            CVM_PLUGIN_URL . 'assets/css/admin-submissions.css',
            [AdminAssets::COMMON_HANDLE],
            CVM_VERSION
        );

        wp_enqueue_script(
            'cvm-submissions',
            CVM_PLUGIN_URL . 'assets/js/submissions.js',
            ['wp-i18n'],
            CVM_VERSION,
            true
        );
        wp_set_script_translations('cvm-submissions', 'convermetry');

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
            'monthNames'   => AdminAssets::monthNames(),
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
            sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST' ||
            !isset($_POST['cvm_action']) ||
            sanitize_key(wp_unslash($_POST['cvm_action'])) !== 'clear_submissions' ||
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
            wp_die(esc_html__('Invalid or expired export link.', 'convermetry'), '', ['response' => 403]);
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

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by self::authorize() above.
        $perPage = Pagination::perPage(isset($_POST['per_page']) ? intval(wp_unslash($_POST['per_page'])) : Pagination::DEFAULT_PER_PAGE);

        $filters = self::filtersFromRequest($_POST);

        // Clamped BEFORE the query — see Pagination::resolve().
        $total  = FormSubmissions::getCount($filters);
        $paging = Pagination::resolve(isset($_POST['page']) ? intval(wp_unslash($_POST['page'])) : 1, $perPage, $total);
        // phpcs:enable WordPress.Security.NonceVerification.Missing

        $page       = $paging['page'];
        $totalPages = $paging['totalPages'];

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

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by self::authorize() above.
        $id  = isset($_POST['submission_row']) ? intval(wp_unslash($_POST['submission_row'])) : 0;
        $row = $id > 0 ? FormSubmissions::get($id) : null;

        if ($row === null) {
            wp_send_json_error(['message' => __('That submission no longer exists.', 'convermetry')]);
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

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by self::authorize() above.
        $id = isset($_POST['submission_row']) ? intval(wp_unslash($_POST['submission_row'])) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid submission id.', 'convermetry')]);
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

        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by self::authorize() above.
        $submissionId = sanitize_text_field(wp_unslash((string) ($_POST['submission_id'] ?? '')));
        if ($submissionId === '') {
            wp_send_json_error(['message' => __('Invalid submission id.', 'convermetry')]);
        }

        $status = array_key_exists('lead_status', $_POST)
            ? sanitize_key((string) wp_unslash($_POST['lead_status']))
            : null;

        $value = array_key_exists('lead_value', $_POST)
            ? sanitize_text_field((string) wp_unslash($_POST['lead_value']))
            : null;
        // phpcs:enable WordPress.Security.NonceVerification.Missing

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
            wp_send_json_error(['message' => __('Unauthorized.', 'convermetry')]);
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
     * @return array<string, array{state: DeliveryState, label: string, endpoints: list<EndpointOutcome>}>
     *         Statuses keyed by submission_id.
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
     * @return array{state: DeliveryState, label: string, endpoints: list<EndpointOutcome>}
     */
    private static function deliveryStatus(array $row): array
    {
        $endpoints = array_map(
            EndpointOutcome::fromStoredArray(...),
            array_values(array_filter(self::decodeJson((string) ($row['delivery_json'] ?? '')), 'is_array'))
        );

        // A row whose state has not been recorded yet (pre-1.3.0, awaiting
        // backfill) is classified from whatever detail it does carry, so the
        // list stays correct while the migration drains.
        $state = DeliveryState::tryFromMixed($row['delivery_state'] ?? null)
            ?? FormSubmissions::classifyDelivery($endpoints);

        return [
            'state'     => $state,
            'label'     => self::statusLabel($state, $endpoints),
            'endpoints' => $endpoints,
        ];
    }

    /**
     * Human wording for a delivery state.
     *
     * @param DeliveryState         $state     The recorded (or derived) state.
     * @param list<EndpointOutcome>  $endpoints Per-endpoint outcomes.
     * @return string
     */
    private static function statusLabel(DeliveryState $state, array $endpoints): string
    {
        $count = count($endpoints);

        if ($state === DeliveryState::Pending) {
            $attempts = array_map(static fn(EndpointOutcome $e): int => $e->attempt, $endpoints);
            $attempt  = $attempts === [] ? 0 : max($attempts);

            return $attempt > 0
                /* translators: %d: retry attempt number. */
                ? sprintf(__('Queued · retry %d', 'convermetry'), $attempt)
                : __('Queued', 'convermetry');
        }

        if ($state === DeliveryState::NotSent) {
            return match (self::webhookPosture()) {
                'none'   => __('Not sent — no form webhook', 'convermetry'),
                'paused' => __('Not sent — webhooks paused', 'convermetry'),
                default  => __('Not sent', 'convermetry'),
            };
        }

        $ok = count(array_filter($endpoints, static fn(EndpointOutcome $e): bool => $e->ok));

        return match ($state) {
            DeliveryState::Delivered => $count > 1
                /* translators: %d: number of endpoints the submission was delivered to. */
                ? sprintf(__('Delivered (%d)', 'convermetry'), $count)
                : __('Delivered', 'convermetry'),
            /* translators: 1: endpoints delivered to, 2: endpoints attempted. */
            DeliveryState::Partial   => sprintf(__('Partial (%1$d/%2$d)', 'convermetry'), $ok, $count),
            default                  => __('Failed', 'convermetry'),
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
            <h1><?php esc_html_e('Submissions', 'convermetry'); ?></h1>

            <?php if ($cleared): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('All submissions have been deleted.', 'convermetry'); ?></p></div>
            <?php endif; ?>

            <p class="description cvm-submissions-intro">
                <?php esc_html_e('Every form submission Convermetry confirmed server-side, joined to the analytics session that produced it. Submissions are recorded whether or not a webhook is configured — expand a row to see the form, its attribution, and the visitor\'s answers.', 'convermetry'); ?>
            </p>

            <?php if ($posture === 'none'): ?>
                <div class="notice notice-info inline cvm-retention-notice">
                    <p>
                        <?php
                        echo wp_kses_post(sprintf(
                            /* translators: %s: URL of the Webhooks screen. */
                            __('No webhook endpoint is currently set to receive form submissions, so every row below shows <strong>Not sent</strong>. That is expected — recording and attribution work independently of delivery. Add an endpoint under <a href="%s">Webhooks</a> to forward leads onward.', 'convermetry'),
                            esc_url(add_query_arg(['page' => WebhooksPage::MENU_SLUG], self_admin_url('admin.php')))
                        ));
                        ?>
                    </p>
                </div>
            <?php elseif ($posture === 'paused'): ?>
                <div class="notice notice-info inline cvm-retention-notice">
                    <p>
                        <?php
                        echo wp_kses_post(sprintf(
                            /* translators: %s: URL of the Webhooks screen. */
                            __('Webhook delivery is currently <strong>paused</strong>, so new submissions are recorded here but not forwarded. Resume it under <a href="%s">Webhooks</a>; queued deliveries are kept, not discarded.', 'convermetry'),
                            esc_url(add_query_arg(['page' => WebhooksPage::MENU_SLUG], self_admin_url('admin.php')))
                        ));
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="notice notice-info inline cvm-retention-notice">
                <p>
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: %d: retention period in days. */
                        _n(
                            '<strong>Data retention:</strong> Submissions older than %d day are automatically removed daily. The retention period is shared with the analytics data and can be changed under <strong>Settings</strong>. Submissions contain the information visitors typed into your forms — treat exports accordingly.',
                            '<strong>Data retention:</strong> Submissions older than %d days are automatically removed daily. The retention period is shared with the analytics data and can be changed under <strong>Settings</strong>. Submissions contain the information visitors typed into your forms — treat exports accordingly.',
                            Options::retentionDays(),
                            'convermetry'
                        ),
                        Options::retentionDays()
                    ));
                    ?>
                </p>
            </div>

            <div class="cvm-delivery-toolbar">
                <form method="post" action="" class="cvm-clear-form">
                    <?php wp_nonce_field('cvm_clear_submissions', 'cvm_clear_nonce'); ?>
                    <input type="hidden" name="cvm_action" value="clear_submissions">
                    <button
                        type="submit"
                        class="button button-secondary cvm-btn-danger"
                        onclick="return confirm(<?php echo esc_attr((string) wp_json_encode(__('Delete every stored submission? This permanently removes the lead data and cannot be undone. Activity Log entries are not affected.', 'convermetry'))); ?>);"
                        <?php disabled($total, 0); ?>
                    >
                        <?php esc_html_e('Clear All Submissions', 'convermetry'); ?>
                    </button>
                </form>

                <?php if ($total > 0): ?>
                    <div class="cvm-export-buttons">
                        <a href="#" class="button button-secondary cvm-export-filtered"><?php esc_html_e('Export Current Filters', 'convermetry'); ?></a>
                        <a
                            href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => self::MENU_SLUG, 'cvm_export' => 'csv'], self_admin_url('admin.php')), 'cvm_submissions_export_csv')); ?>"
                            class="button button-secondary"
                        >
                            <?php esc_html_e('Export All To CSV', 'convermetry'); ?>
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
     * @param array<string, mixed> $row Submission row.
     * @param array{state: DeliveryState, label: string, endpoints: list<EndpointOutcome>}|null $status
     *        Delivery status, or null when unknown.
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

        $state      = ($status['state'] ?? DeliveryState::NotSent)->value;
        $stateLabel = $status['label'] ?? __('Not sent', 'convermetry');
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
                    /* translators: 1: the lead's name or contact, 2: form name, 3: submission date, 4: delivery status. */
                    __('Submission from %1$s via %2$s on %3$s — %4$s. Expand for details.', 'convermetry'),
                    $lead,
                    $formName !== '' ? $formName : __('an unnamed form', 'convermetry'),
                    self::formatDate($created),
                    $stateLabel
                )); ?>"
            >
                <span class="cvm-sub-col cvm-sub-date"><?php echo esc_html(self::formatDate($created)); ?></span>
                <span class="cvm-sub-col cvm-sub-lead"><?php echo esc_html($lead); ?></span>
                <span class="cvm-sub-col cvm-sub-form">
                    <?php echo esc_html($formName !== '' ? $formName : __('(unnamed form)', 'convermetry')); ?>
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
                <?php foreach (self::extraColumns($row) as $key => $html): ?>
                    <span class="cvm-sub-col cvm-sub-ext" data-column="<?php echo esc_attr($key); ?>"><?php
                        // The callback owns escaping (see extraColumns()); this
                        // is a second line of defence that still permits the
                        // chips and links the filter exists for.
                        echo wp_kses_post($html);
                    ?></span>
                <?php endforeach; ?>
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
     * Extra summary-row cells contributed by an integration.
     *
     * @param array<string, mixed> $row Submission row (PII).
     * @return array<string, string> Key => already-escaped cell HTML.
     */
    private static function extraColumns(array $row): array
    {
        /**
         * Filters extra cells appended to each row of the submissions list.
         *
         * Return a map of KEY => already-escaped HTML. Each entry becomes one
         * <span class="cvm-sub-col cvm-sub-ext" data-column="{key}"> at the end
         * of the row, after the delivery-status chip and before the expand
         * arrow. With nothing registered no span is emitted and the list's HTML
         * is unchanged.
         *
         * THE VALUE IS PRINTED VERBATIM and MUST already be escaped — pass it
         * through esc_html() yourself. It is a string of HTML rather than a
         * plain value so a callback can render a chip or a link the way the core
         * columns do; that flexibility is what makes the escaping your job.
         *
         * The row is a horizontal flex layout sized for its eight core columns,
         * so keep additions to one short value; this list is not a data grid.
         *
         * Runs once per row rendered, inside the cvm_get_submissions AJAX
         * response, after that handler's nonce and submissions.view checks.
         *
         * $row CONTAINS PERSONAL DATA, including the visitor's submitted values
         * and IP address.
         *
         * @param array<string, string> $columns Empty map to add to.
         * @param array<string, mixed>  $row     The submission row (PII).
         */
        $columns = apply_filters('convermetry_submissions_columns', [], $row);

        $out = [];
        foreach (is_array($columns) ? $columns : [] as $key => $html) {
            $key = trim((string) $key);
            if ($key !== '' && is_scalar($html)) {
                $out[$key] = (string) $html;
            }
        }

        return $out;
    }

    /**
     * Renders one submission's expanded detail panel.
     *
     * @param array<string, mixed> $row Submission row (context already enriched).
     * @param array{state: DeliveryState, label: string, endpoints: list<EndpointOutcome>}|null $status
     *        Delivery status.
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

        // Lists of [label, value] rather than label-keyed maps: two labels can
        // translate to the same word, and a map would silently drop one.
        $formPairs = [
            [__('Provider', 'convermetry'), (string) ($row['provider'] ?? '')],
            [__('Form name', 'convermetry'), (string) ($row['form_name'] ?? '')],
            [__('Form ID', 'convermetry'), (string) ($row['form_id'] ?? '')],
            [__('Native form ID', 'convermetry'), (string) ($row['native_form_id'] ?? '')],
            [__('Conversion page', 'convermetry'), (string) ($row['page_url'] ?? '')],
            /* translators: %s: date and time in UTC. */
            [__('Submitted', 'convermetry'), sprintf(__('%s UTC', 'convermetry'), (string) ($row['created_at'] ?? ''))],
            [__('Submission ID', 'convermetry'), (string) ($row['submission_id'] ?? '')],
            [__('Conversion ID', 'convermetry'), (string) ($row['conversion_id'] ?? '')],
        ];

        $analyticsPairs = [
            [__('Channel', 'convermetry'), (string) ($row['channel'] ?? '')],
            [__('Source', 'convermetry'), (string) ($attribution['utm_source'] ?? '')],
            [__('Medium', 'convermetry'), (string) ($attribution['utm_medium'] ?? '')],
            [__('Campaign', 'convermetry'), (string) ($attribution['utm_campaign'] ?? '')],
            [__('Campaign ID', 'convermetry'), (string) ($attribution['utm_id'] ?? '')],
            [__('Term', 'convermetry'), (string) ($attribution['utm_term'] ?? '')],
            [__('Content', 'convermetry'), (string) ($attribution['utm_content'] ?? '')],
            [__('Ad click type', 'convermetry'), (string) ($attribution['click_id_type'] ?? '')],
            [__('Entrance referrer', 'convermetry'), (string) ($context['entrance_referrer'] ?? '')],
            [__('Landing page', 'convermetry'), $landing],
            [__('Device', 'convermetry'), (string) ($context['device'] ?? '')],
            [__('Session ID', 'convermetry'), (string) ($row['session_id'] ?? '')],
            [__('Session started', 'convermetry'), (string) ($context['session_started_at'] ?? '')],
            [__('Pages viewed', 'convermetry'), isset($context['pageview_count']) ? (string) (int) $context['pageview_count'] : ''],
        ];

        // The IP is resolved server-side from the request, independently of
        // the tracker, so it is deliberately NOT part of this test: a
        // server-to-server submission has an address and no attribution at
        // all, and that is precisely when the explanation below is needed.
        $hasContext = !self::allEmpty(array_column($analyticsPairs, 1));

        $analyticsPairs[] = [__('IP address', 'convermetry'), (string) ($row['ip_address'] ?? '')];

        ob_start();
        ?>
        <div class="cvm-detail-inner">

            <div class="cvm-detail-actions">
                <button type="button" class="button cvm-submission-delete-btn"><?php esc_html_e('Delete Submission', 'convermetry'); ?></button>
                <?php
                /**
                 * Fires in one submission's action bar, after the Delete button.
                 *
                 * For buttons and links that act on this submission — resending
                 * it to a CRM, opening it in another system. Runs after the same
                 * nonce and capability checks as the panel around it.
                 *
                 * A callback ECHOES its own markup and MUST escape everything it
                 * prints. Use class="button" to match the existing control, and
                 * nonce-protect anything that acts.
                 *
                 * @param array<string, mixed> $row The submission row (PII).
                 */
                do_action('convermetry_submission_row_actions', $row);
                ?>
            </div>

            <?php self::printLeadBlock($row); ?>

            <div class="cvm-detail-block">
                <h4><?php esc_html_e('Form', 'convermetry'); ?></h4>
                <?php self::printPairs($formPairs); ?>
            </div>

            <div class="cvm-detail-block">
                <h4><?php esc_html_e('Analytics & attribution', 'convermetry'); ?></h4>
                <?php if (!$hasContext): ?>
                    <p class="cvm-empty-msg">
                        <?php esc_html_e('No analytics context was captured for this submission — the tracker\'s correlation fields did not reach the server (JavaScript blocked, tracking disabled, a privacy signal honored, or a server-to-server submission).', 'convermetry'); ?>
                    </p>
                <?php endif; ?>
                <?php self::printPairs($analyticsPairs); ?>
            </div>

            <?php if ($recentPages !== []): ?>
                <div class="cvm-detail-block">
                    <h4><?php esc_html_e('Visitor journey', 'convermetry'); ?></h4>
                    <ol class="cvm-journey">
                        <?php foreach (array_reverse($recentPages) as $pageUrl): ?>
                            <li><?php echo esc_html(self::pathOf((string) $pageUrl)); ?></li>
                        <?php endforeach; ?>
                        <li class="cvm-journey-end"><?php esc_html_e('Form submitted', 'convermetry'); ?></li>
                    </ol>
                </div>
            <?php endif; ?>

            <div class="cvm-detail-block">
                <h4><?php esc_html_e('Submitted fields', 'convermetry'); ?></h4>
                <?php if ($fields->isEmpty()): ?>
                    <p class="cvm-empty-msg"><?php esc_html_e('This submission recorded no field values.', 'convermetry'); ?></p>
                <?php else: ?>
                    <div class="cvm-field-table-wrap">
                        <table class="cvm-field-table">
                            <tbody>
                            <?php foreach ($fields->toDisplayPairs() as $pair): ?>
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
                    <h4><?php esc_html_e('Page query parameters', 'convermetry'); ?></h4>
                    <?php self::printPairs(array_map(
                        static fn(string|int $key, mixed $v): array => [(string) $key, self::flatten($v)],
                        array_keys($pageQuery),
                        array_values($pageQuery)
                    )); ?>
                </div>
            <?php endif; ?>

            <div class="cvm-detail-block">
                <h4><?php esc_html_e('Webhook delivery', 'convermetry'); ?></h4>
                <?php self::printDeliveryBlock($status, (string) ($row['submission_id'] ?? '')); ?>
            </div>

            <?php
            /**
             * Fires at the end of one submission's detail panel.
             *
             * Runs inside the cvm_get_submission_detail AJAX response, after
             * that handler's nonce check and its submissions.view capability
             * check — so a callback need not re-authorize, though it must apply
             * its own check for anything a viewer of this screen should not see.
             *
             * A callback ECHOES its own markup and MUST escape everything it
             * prints; Convermetry escapes none of it. Wrap output in a
             * <div class="cvm-detail-block"> with an <h4> to match the panels
             * above it.
             *
             * $row IS THE SUBMISSION ROW AND CONTAINS PERSONAL DATA: the
             * visitor's submitted field values in submission_data, their IP
             * address, and their full attribution context. Do not echo any of it
             * without deciding that it belongs on this screen.
             *
             * @param array<string, mixed> $row The submission row (PII).
             */
            do_action('convermetry_submission_detail_sections', $row);
            ?>

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
     * @return void
     */
    private static function printLeadBlock(array $row): void
    {
        $submissionId = (string) ($row['submission_id'] ?? '');
        $status       = LeadStatus::normalize($row['lead_status'] ?? null);
        $value        = $row['lead_value'] === null ? '' : (string) $row['lead_value'];
        $currency     = (string) ($row['lead_currency'] ?? '');
        $updatedAt    = (string) ($row['lead_status_at'] ?? '');

        $editable = LeadService::userCanEdit();
        $history  = LeadEvents::forSubmission($submissionId, 10);

        ?>
        <div class="cvm-detail-block cvm-lead-block" data-submission-id="<?php echo esc_attr($submissionId); ?>">
            <h4><?php esc_html_e('Lead outcome', 'convermetry'); ?></h4>

            <?php if (!$editable): ?>
                <p class="cvm-empty-msg">
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: %s: lead status, such as "Qualified". */
                        __('Status: <strong>%s</strong>', 'convermetry'),
                        esc_html(LeadStatus::label($status))
                    ));
                    ?>
                    <?php if ($value !== ''): ?>
                        &middot; <?php echo esc_html(Money::format($value, $currency)); ?>
                    <?php endif; ?>
                </p>
            <?php else: ?>
                <div class="cvm-lead-controls">
                    <label class="cvm-lead-field">
                        <span><?php esc_html_e('Status', 'convermetry'); ?></span>
                        <select class="cvm-lead-status">
                            <?php foreach (LeadStatus::labels() as $machine => $label): ?>
                                <option value="<?php echo esc_attr($machine); ?>" <?php selected($machine, $status); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="cvm-lead-field">
                        <span><?php
                        echo esc_html($currency !== ''
                            /* translators: %s: ISO currency code, such as USD. */
                            ? sprintf(__('Value (%s)', 'convermetry'), $currency)
                            : __('Value', 'convermetry'));
                        ?></span>
                        <input
                            type="text"
                            class="cvm-lead-value"
                            value="<?php echo esc_attr($value); ?>"
                            placeholder="<?php echo esc_attr(Options::leadCurrency() !== '' ? '12,500.00' : '0.00'); ?>"
                            inputmode="decimal"
                        >
                    </label>

                    <button type="button" class="button button-primary cvm-lead-save"><?php esc_html_e('Save', 'convermetry'); ?></button>
                    <span class="cvm-lead-feedback" role="status" aria-live="polite"></span>
                </div>

                <p class="description">
                    <?php esc_html_e('Recorded here only — lead outcomes are never sent to webhook endpoints in this version, because a submission\'s payload is frozen when it is first delivered and could never reflect a change made afterwards. Clear the value field to remove a recorded amount.', 'convermetry'); ?>
                </p>
            <?php endif; ?>

            <?php if ($updatedAt !== '' || $history !== []): ?>
                <div class="cvm-lead-history">
                    <h5><?php esc_html_e('History', 'convermetry'); ?></h5>
                    <ul>
                        <?php foreach ($history as $entry): ?>
                            <li>
                                <?php
                                $user = (int) $entry['user_id'] > 0 ? get_userdata((int) $entry['user_id']) : null;
                                $who  = $user instanceof \WP_User ? $user->display_name : __('someone', 'convermetry');

                                $change = sprintf(
                                    /* translators: 1: previous lead status, 2: new lead status. */
                                    __('%1$s → %2$s', 'convermetry'),
                                    LeadStatus::label((string) $entry['from_status']),
                                    LeadStatus::label((string) $entry['to_status'])
                                );

                                if ($entry['value'] !== null) {
                                    $change .= ' · ' . Money::format((string) $entry['value'], (string) $entry['currency']);
                                }

                                echo esc_html(sprintf(
                                    /* translators: 1: status change (and value), 2: date, 3: name of the user who made the change. */
                                    __('%1$s · %2$s by %3$s', 'convermetry'),
                                    $change,
                                    self::formatDate((string) $entry['created_at']),
                                    $who
                                ));
                                ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Prints the per-endpoint delivery results, with deep links into the
     * Activity Log.
     *
     * @param array{state: DeliveryState, label: string, endpoints: list<EndpointOutcome>}|null $status
     *        Delivery status.
     * @param string $submissionId The submission's id.
     * @return void
     */
    private static function printDeliveryBlock(?array $status, string $submissionId): void
    {
        $state     = $status['state'] ?? DeliveryState::NotSent;
        $endpoints = $status['endpoints'] ?? [];

        if ($state === DeliveryState::NotSent) {
            ?>
            <p class="cvm-empty-msg">
                <?php switch (self::webhookPosture()):
                    case 'none': ?>
                        <?php esc_html_e('Not sent — no webhook endpoint is configured to receive form submissions. The submission is still fully recorded here.', 'convermetry'); ?>
                        <?php break;
                    case 'paused': ?>
                        <?php esc_html_e('Not sent — webhook delivery is currently paused. The submission is fully recorded here and will not be delivered until delivery is resumed.', 'convermetry'); ?>
                        <?php break;
                    default: ?>
                        <?php esc_html_e('No delivery has been attempted for this submission yet.', 'convermetry'); ?>
                <?php endswitch; ?>
            </p>
            <?php
            return;
        }

        $logUrl = add_query_arg(['page' => ActivityLogPage::MENU_SLUG], self_admin_url('admin.php'));
        ?>
        <ul class="cvm-delivery-list">
            <?php foreach ($endpoints as $endpoint): ?>
                <?php
                $label  = $endpoint->label;
                $url    = $endpoint->url;
                $ok     = $endpoint->ok;
                $queued = $endpoint->queued;
                ?>
                <li class="cvm-delivery-row">
                    <span class="cvm-delivery-mark <?php echo esc_attr($queued ? 'queued' : ($ok ? 'ok' : 'fail')); ?>" aria-hidden="true">
                        <?php echo esc_html($queued ? '⏳' : ($ok ? '✓' : '✕')); ?>
                    </span>
                    <span class="cvm-delivery-name"><?php echo esc_html($label !== '' ? $label : $url); ?></span>
                    <span class="cvm-delivery-result">
                        <?php
                        if ($queued) {
                            echo esc_html($endpoint->attempt > 0
                                ? sprintf(
                                    /* translators: %d: number of failed delivery attempts. */
                                    _n('Queued · %d failed attempt', 'Queued · %d failed attempts', $endpoint->attempt, 'convermetry'),
                                    $endpoint->attempt
                                )
                                : __('Queued', 'convermetry'));
                        } elseif ($ok) {
                            echo esc_html($endpoint->code !== 0
                                /* translators: %d: HTTP response status code. */
                                ? sprintf(_x('Delivered (%d)', 'with an HTTP status code', 'convermetry'), $endpoint->code)
                                : __('Delivered', 'convermetry'));
                        } else {
                            echo esc_html($endpoint->code !== 0
                                /* translators: %d: HTTP response status code. */
                                ? sprintf(_x('Failed (%d)', 'with an HTTP status code', 'convermetry'), $endpoint->code)
                                : __('Failed', 'convermetry'));
                        }
                        ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="cvm-delivery-loglink">
            <?php
            echo wp_kses_post(sprintf(
                /* translators: 1: URL of the Activity Log screen, 2: the submission id. */
                __('<a href="%1$s">Open the Activity Log</a> and search for %2$s to see every attempt, its payload, and the endpoint\'s response.', 'convermetry'),
                esc_url($logUrl),
                '<code>' . esc_html($submissionId) . '</code>'
            ));
            ?>
        </p>
        <?php
    }

    /**
     * Prints a label/value definition grid, skipping empty values.
     *
     * @param list<array{0: string, 1: string}> $pairs [label, value] pairs.
     * @return void
     */
    private static function printPairs(array $pairs): void
    {
        $pairs = array_filter($pairs, static fn(array $pair): bool => trim($pair[1]) !== '');

        if ($pairs === []) {
            return;
        }

        echo '<dl class="cvm-detail-grid">';
        foreach ($pairs as [$label, $value]) {
            echo '<dt>' . esc_html($label) . '</dt><dd>' . esc_html($value) . '</dd>';
        }
        echo '</dl>';
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

        // php://output does not fail in practice, but fopen() is declared as
        // able to — and every write below would then be a TypeError inside a
        // response that has already sent CSV headers, so the browser would save
        // a file containing a fatal error. Stopping with an empty body is the
        // honest outcome.
        if ($output === false) {
            exit;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- $output is php://output, the download response itself; no file is written, so WP_Filesystem does not apply.
        fwrite($output, "\xEF\xBB\xBF");

        // escape: '' is required, not cosmetic. Leaving it at the default
        // emits a deprecation notice on PHP 8.4+ — and this function streams
        // straight to the browser, so that notice lands INSIDE the downloaded
        // file and corrupts it. '' is also the RFC 4180 behaviour PHP 9 will
        // default to, and the only correct choice for a spreadsheet export.
        $columns = self::exportColumns();

        fputcsv($output, array_values($columns), escape: '');

        $beforeId = PHP_INT_MAX;

        do {
            $rows     = FormSubmissions::getChunk($beforeId, self::EXPORT_CHUNK, $filters);
            $statuses = self::deliveryStatuses($rows);

            foreach ($rows as $row) {
                $beforeId = (int) ($row['id'] ?? 0);

                $values = self::exportValues(
                    $row,
                    $statuses[(string) ($row['submission_id'] ?? '')] ?? null
                );

                // Assembled by walking the COLUMN keys, so a row can never be
                // shorter, longer, or shuffled relative to the header — a
                // missing key becomes an empty cell rather than a shift that
                // silently files one visitor's email under another's column.
                $cells = [];
                foreach (array_keys($columns) as $key) {
                    $cells[] = self::escapeCsvCell((string) ($values[$key] ?? ''));
                }

                fputcsv($output, $cells, escape: '');
            }
        } while (count($rows) === self::EXPORT_CHUNK);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the php://output stream opened above; no file is involved.
        fclose($output);
        exit;
    }

    /**
     * The export's columns, as an ordered key => header-label map.
     *
     * @return array<string, string>
     */
    private static function exportColumns(): array
    {
        $columns = [
            'created_at'      => 'Date/Time (UTC)',
            'submission_id'   => 'Submission ID',
            'conversion_id'   => 'Conversion ID',
            'session_id'      => 'Session ID',
            'provider'        => 'Provider',
            'form_name'       => 'Form Name',
            'form_id'         => 'Form ID',
            'native_form_id'  => 'Native Form ID',
            'page_url'        => 'Conversion Page',
            'channel'         => 'Channel',
            'utm_source'      => 'UTM Source',
            'utm_medium'      => 'UTM Medium',
            'utm_campaign'    => 'UTM Campaign',
            'utm_term'        => 'UTM Term',
            'utm_content'     => 'UTM Content',
            'click_id_type'   => 'Ad Click Type',
            'referrer'        => 'Entrance Referrer',
            'landing_page'    => 'Landing Page',
            'device'          => 'Device',
            'ip_address'      => 'IP Address',
            'delivery_status' => 'Delivery Status',
            // Currency travels as its own column rather than being folded into
            // the value. A spreadsheet that mixed "12500.00" and "€12500.00" in
            // one column could not be summed or sorted, and a value with no code
            // beside it is not safely addable on a multi-currency site.
            'lead_status'     => 'Lead Status',
            'lead_value'      => 'Lead Value',
            'lead_currency'   => 'Lead Currency',
            'submission_data' => 'Submission Data (JSON)',
        ];

        /**
         * Filters the submissions CSV export's columns.
         *
         * The map is KEY => HEADER LABEL, and its order is the column order.
         * Add a key here and supply its value from
         * convermetry_submission_csv_values; the two are matched by key, never
         * by position, so a value that is missing for one row becomes an empty
         * cell rather than shifting every later column along by one.
         *
         * Removing a core key removes that column from the file. Renaming a
         * label is safe; renaming a KEY is what breaks the pairing.
         *
         * Runs once per export, before the header row is written. Both this and
         * the values filter run only for a user who passed the
         * submissions.export capability check.
         *
         * @param array<string, string> $columns Ordered key => header label.
         */
        $filtered = apply_filters('convermetry_submission_csv_columns', $columns);

        if ($filtered === $columns) {
            return $columns;
        }

        $out = [];
        foreach (is_array($filtered) ? $filtered : [] as $key => $label) {
            $key = trim((string) $key);
            if ($key !== '' && is_scalar($label)) {
                $out[$key] = (string) $label;
            }
        }

        return $out === [] ? $columns : $out;
    }

    /**
     * One export row's values, as a key => value map matching the column keys.
     *
     * @param array<string, mixed>      $row    Submission row.
     * @param array<string, mixed>|null $status Resolved delivery status, when known.
     * @return array<string, string>
     */
    private static function exportValues(array $row, ?array $status): array
    {
        $context     = self::decodeJson((string) ($row['context'] ?? ''));
        $attribution = is_array($context['attribution'] ?? null) ? $context['attribution'] : [];
        $landing     = is_array($context['landing_page'] ?? null)
            ? (string) ($context['landing_page']['url'] ?? '')
            : '';

        $values = [
            'created_at'      => (string) ($row['created_at'] ?? ''),
            'submission_id'   => (string) ($row['submission_id'] ?? ''),
            'conversion_id'   => (string) ($row['conversion_id'] ?? ''),
            'session_id'      => (string) ($row['session_id'] ?? ''),
            'provider'        => (string) ($row['provider'] ?? ''),
            'form_name'       => (string) ($row['form_name'] ?? ''),
            'form_id'         => (string) ($row['form_id'] ?? ''),
            'native_form_id'  => (string) ($row['native_form_id'] ?? ''),
            'page_url'        => (string) ($row['page_url'] ?? ''),
            'channel'         => (string) ($row['channel'] ?? ''),
            'utm_source'      => (string) ($attribution['utm_source'] ?? ''),
            'utm_medium'      => (string) ($attribution['utm_medium'] ?? ''),
            'utm_campaign'    => (string) ($attribution['utm_campaign'] ?? ''),
            'utm_term'        => (string) ($attribution['utm_term'] ?? ''),
            'utm_content'     => (string) ($attribution['utm_content'] ?? ''),
            'click_id_type'   => (string) ($attribution['click_id_type'] ?? ''),
            'referrer'        => (string) ($context['entrance_referrer'] ?? ''),
            'landing_page'    => $landing,
            'device'          => (string) ($context['device'] ?? ''),
            'ip_address'      => (string) ($row['ip_address'] ?? ''),
            'delivery_status' => (string) ($status['label'] ?? __('Not sent', 'convermetry')),
            'lead_status'     => LeadStatus::label(LeadStatus::normalize($row['lead_status'] ?? null)),
            // The raw decimal string, not the formatted display value:
            // a spreadsheet needs a number it can sum, not "12,500.00 USD".
            'lead_value'      => $row['lead_value'] === null ? '' : (string) $row['lead_value'],
            'lead_currency'   => (string) ($row['lead_currency'] ?? ''),
            'submission_data' => (string) wp_json_encode(
                SubmissionFields::fromStoredJson((string) ($row['submission_data'] ?? ''))->toArray(),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ];

        /**
         * Filters one submission's CSV export values.
         *
         * The map is KEY => VALUE, matched against the keys from
         * convermetry_submission_csv_columns. A key with no column is ignored; a
         * column with no value here becomes an empty cell. Position never
         * matters, so the two filters cannot drift out of alignment.
         *
         * Runs once per exported row — a filtered export of ten thousand
         * submissions runs a callback ten thousand times, streaming, so keep it
         * cheap and do not query per row.
         *
         * Values must be scalar or null; anything else is dropped. Every value,
         * core and third-party alike, is then passed through the same
         * formula-injection escaping, which prefixes a tab to anything starting
         * =, +, -, or @ so a spreadsheet treats it as text. Do not pre-escape.
         *
         * $row AND the returned values CONTAIN PERSONAL DATA — the export exists
         * to produce a file of visitors' names, email addresses, and messages.
         *
         * @param array<string, string> $values Key => value for this row.
         * @param array<string, mixed>  $row    The raw submission row.
         */
        $filtered = apply_filters('convermetry_submission_csv_values', $values, $row);

        if ($filtered === $values) {
            return $values;
        }

        $out = [];
        foreach (is_array($filtered) ? $filtered : [] as $key => $value) {
            $key = trim((string) $key);
            if ($key !== '' && ($value === null || is_scalar($value))) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
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
     * @param SubmissionFieldList $fields Normalized submission fields.
     * @return string
     */
    private static function leadLabel(SubmissionFieldList $fields): string
    {
        $email = '';
        $name  = '';
        $first = '';
        $last  = '';
        $phone = '';

        foreach ($fields as $field) {
            $flat = $field->displayValue();
            if ($flat === '') {
                continue;
            }

            $id    = strtolower($field->id);
            $label = strtolower($field->label);

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

        return __('(no contact details)', 'convermetry');
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

        // wp_date() rather than gmdate() so the month name is localized; the
        // time stays in UTC, which is what the list has always shown.
        /* translators: compact date and time format for the Submissions list, see https://www.php.net/manual/datetime.format.php */
        return $ts === false ? $datetime : (string) wp_date(__('M j, Y H:i', 'convermetry'), $ts, new \DateTimeZone('UTC'));
    }

    /**
     * Whether every value is blank.
     *
     * @param list<string> $values Values to test.
     * @return bool
     */
    private static function allEmpty(array $values): bool
    {
        foreach ($values as $value) {
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
