<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\FormSubmissions;
use Convermetry\Settings\Options;
use Convermetry\Webhook\DeliveryLog;
use Convermetry\Webhook\FormDeliveryQueue;

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
            'manage_options',
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
            'listNonce'    => wp_create_nonce('cvm_get_submissions'),
            'detailNonce'  => wp_create_nonce('cvm_get_submission_detail'),
            'deleteNonce'  => wp_create_nonce('cvm_delete_submission'),
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
            !current_user_can('manage_options')
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
        if (!isset($_GET['cvm_export']) || !current_user_can('manage_options')) {
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
        self::authorize('cvm_get_submissions');

        $page    = max(1, (int) ($_POST['page'] ?? 1));
        $perPage = min(100, max(5, (int) ($_POST['per_page'] ?? 10)));

        $filters = self::filtersFromRequest($_POST);

        $rows  = FormSubmissions::getPaginated($page, $perPage, $filters);
        $total = FormSubmissions::getCount($filters);
        $dates = FormSubmissions::getDistinctDates($filters);

        $statuses      = self::deliveryStatuses($rows);
        $endpointCount = self::formEndpointCount();

        $html = '';
        foreach ($rows as $row) {
            $html .= self::renderRowHtml($row, $statuses[(string) ($row['submission_id'] ?? '')] ?? null);
        }

        wp_send_json_success([
            'html'          => $html,
            'total'         => $total,
            'totalPages'    => max(1, (int) ceil($total / $perPage)),
            'currentPage'   => $page,
            'years'         => $dates['years'],
            'months'        => $dates['months'],
            'providers'     => FormSubmissions::getDistinctValues('provider'),
            'formNames'     => FormSubmissions::getDistinctValues('form_name'),
            'channels'      => FormSubmissions::getDistinctValues('channel'),
            'campaigns'     => FormSubmissions::getDistinctValues('utm_campaign'),
            'endpointCount' => $endpointCount,
        ]);
    }

    /**
     * Handles the cvm_get_submission_detail AJAX action.
     *
     * @return never
     */
    public static function handleGetDetailAjax(): never
    {
        self::authorize('cvm_get_submission_detail');

        $id  = (int) ($_POST['submission_row'] ?? 0);
        $row = $id > 0 ? FormSubmissions::get($id) : null;

        if ($row === null) {
            wp_send_json_error(['message' => 'That submission no longer exists.']);
        }

        // The session summary (pageview count, session start, recent pages)
        // is normally computed when a webhook delivery freezes its payload —
        // which never happens on a site with no endpoints configured. Fill it
        // in lazily here instead; enrichContext() persists what it computes,
        // so this runs at most once per submission ever.
        $row = FormDeliveryQueue::enrichContext($row);

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
        self::authorize('cvm_delete_submission');

        $id = (int) ($_POST['submission_row'] ?? 0);
        if ($id <= 0) {
            wp_send_json_error(['message' => 'Invalid submission id.']);
        }

        FormSubmissions::deleteSubmission($id);
        wp_send_json_success();
    }

    /**
     * Shared nonce + capability guard for every AJAX action on this page.
     *
     * @param string $action The action name the nonce was created for.
     * @return void
     */
    private static function authorize(string $action): void
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), $action) ||
            !current_user_can('manage_options')
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

        return [
            'year'            => sanitize_text_field(self::scalarParam($src, 'filter_year')),
            'month'           => sanitize_text_field(self::scalarParam($src, 'filter_month')),
            'provider'        => sanitize_key(self::scalarParam($src, 'provider')),
            'form_name'       => sanitize_text_field(self::scalarParam($src, 'form_name')),
            'channel'         => sanitize_text_field(self::scalarParam($src, 'channel')),
            'campaign'        => sanitize_text_field(self::scalarParam($src, 'campaign')),
            'search'          => sanitize_text_field(self::scalarParam($src, 'search')),
            'delivery_status' => in_array($status, self::STATES, true) ? $status : '',
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
     * Number of endpoints currently configured to receive form submissions,
     * or 0 when webhooks are paused entirely.
     *
     * @return int
     */
    private static function formEndpointCount(): int
    {
        return Options::webhooksActive() ? count(Options::formEndpoints()) : 0;
    }

    /**
     * Resolves the delivery status of every submission in one page of rows.
     *
     * Two queries for the whole page rather than two per row: the delivery
     * log and the queue are both indexed by submission_id, so the page's ids
     * go in as a single IN list and the results are grouped in PHP.
     *
     * @param array<int, array<string, mixed>> $rows Submission rows.
     * @return array<string, array<string, mixed>> Status arrays keyed by submission_id.
     */
    private static function deliveryStatuses(array $rows): array
    {
        global $wpdb;

        $ids = array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['submission_id'] ?? ''),
            $rows
        ), static fn(string $id): bool => $id !== ''));

        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '%s'));
        $endpointCount = self::formEndpointCount();

        $logRows = $wpdb->get_results($wpdb->prepare(
            'SELECT submission_id, endpoint_url, MAX(success) AS ok, MAX(attempt) AS attempt,'
            . ' MAX(response_code) AS code'
            . ' FROM ' . DeliveryLog::tableName()
            . " WHERE message_type = 'form_submission' AND submission_id IN ({$placeholders})"
            . ' GROUP BY submission_id, endpoint_url',
            $ids
        ), ARRAY_A);

        $queueRows = $wpdb->get_results($wpdb->prepare(
            'SELECT submission_id, endpoint_url, status, attempt, next_attempt_at'
            . ' FROM ' . FormDeliveryQueue::tableName()
            . " WHERE submission_id IN ({$placeholders})",
            $ids
        ), ARRAY_A);

        $byId = array_fill_keys($ids, ['log' => [], 'queue' => []]);

        foreach ((is_array($logRows) ? $logRows : []) as $row) {
            $byId[(string) $row['submission_id']]['log'][] = $row;
        }
        foreach ((is_array($queueRows) ? $queueRows : []) as $row) {
            $byId[(string) $row['submission_id']]['queue'][] = $row;
        }

        $out = [];
        foreach ($byId as $id => $group) {
            $out[$id] = self::deliveryStatus($group['log'], $group['queue'], $endpointCount);
        }

        return $out;
    }

    /**
     * Classifies one submission's delivery state from its log and queue rows.
     *
     * Pure — no database, no WordPress state — so the classification rules can
     * be unit-tested directly.
     *
     * A submission is judged against the endpoints it was ACTUALLY attempted
     * against, never against the endpoints configured right now: adding a
     * third endpoint today must not retroactively downgrade last month's
     * successful two-endpoint delivery to "partial". The current endpoint
     * count is used only to word the "not sent" case, which is the ordinary
     * state for a site using the plugin without webhooks and must never read
     * as a failure.
     *
     * @param array<int, array<string, mixed>> $logRows       One row per (submission, endpoint), with ok/attempt/code.
     * @param array<int, array<string, mixed>> $queueRows     Undelivered queue rows for this submission.
     * @param int                              $endpointCount Endpoints currently configured for form delivery.
     * @return array{state: string, label: string, endpoints: array<int, array<string, mixed>>}
     */
    private static function deliveryStatus(array $logRows, array $queueRows, int $endpointCount): array
    {
        $endpoints = [];

        foreach ($logRows as $row) {
            $url = (string) ($row['endpoint_url'] ?? '');
            $endpoints[$url] = [
                'url'     => $url,
                'label'   => Options::endpointLabel($url),
                'ok'      => (int) ($row['ok'] ?? 0) === 1,
                'attempt' => (int) ($row['attempt'] ?? 0),
                'code'    => (int) ($row['code'] ?? 0),
                'queued'  => false,
            ];
        }

        // A queue row outranks any log row for the same endpoint: the delivery
        // is still in flight, so its last failed attempt is not the outcome.
        foreach ($queueRows as $row) {
            $url = (string) ($row['endpoint_url'] ?? '');
            $endpoints[$url] = [
                'url'     => $url,
                'label'   => Options::endpointLabel($url),
                'ok'      => false,
                'attempt' => (int) ($row['attempt'] ?? 0),
                'code'    => 0,
                'queued'  => true,
                'next'    => (string) ($row['next_attempt_at'] ?? ''),
            ];
        }

        $endpoints = array_values($endpoints);

        if ($queueRows !== []) {
            $attempt = max(array_map(static fn(array $r): int => (int) ($r['attempt'] ?? 0), $queueRows));

            return [
                'state'     => 'pending',
                'label'     => $attempt > 0 ? sprintf('Queued · retry %d', $attempt) : 'Queued',
                'endpoints' => $endpoints,
            ];
        }

        if ($logRows === []) {
            return [
                'state'     => 'not_sent',
                'label'     => $endpointCount === 0 ? 'Not sent — no form webhook' : 'Not sent',
                'endpoints' => [],
            ];
        }

        $attempted = count($logRows);
        $ok        = count(array_filter($endpoints, static fn(array $e): bool => $e['ok']));

        if ($ok === $attempted) {
            return [
                'state'     => 'delivered',
                'label'     => $attempted > 1 ? sprintf('Delivered (%d)', $attempted) : 'Delivered',
                'endpoints' => $endpoints,
            ];
        }

        if ($ok > 0) {
            return [
                'state'     => 'partial',
                'label'     => sprintf('Partial (%d/%d)', $ok, $attempted),
                'endpoints' => $endpoints,
            ];
        }

        return ['state' => 'failed', 'label' => 'Failed', 'endpoints' => $endpoints];
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
        if (!current_user_can('manage_options')) {
            return;
        }

        $cleared       = isset($_GET['cvm_cleared']) && $_GET['cvm_cleared'] === '1';
        $total         = FormSubmissions::getCount();
        $endpointCount = self::formEndpointCount();

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

            <?php if ($endpointCount === 0): ?>
                <div class="notice notice-info inline cvm-retention-notice">
                    <p>
                        No webhook endpoint is currently set to receive form submissions, so every row
                        below shows <strong>Not sent</strong>. That is expected — recording and
                        attribution work independently of delivery. Add an endpoint under
                        <a href="<?php echo esc_url(add_query_arg(['page' => WebhooksPage::MENU_SLUG], self_admin_url('admin.php'))); ?>">Webhooks</a>
                        to forward leads onward.
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
        $lead     = self::leadLabel(self::decodeJson((string) ($row['submission_data'] ?? '')));

        $state      = (string) ($status['state'] ?? 'not_sent');
        $stateLabel = (string) ($status['label'] ?? 'Not sent');
        $bodyId     = 'cvm-sub-body-' . $rowId;

        ob_start();
        ?>
        <li class="cvm-submission-item" data-row-id="<?php echo esc_attr((string) $rowId); ?>">
            <button
                type="button"
                class="cvm-submission-summary"
                aria-expanded="false"
                aria-controls="<?php echo esc_attr($bodyId); ?>"
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
        $fields      = self::decodeJson((string) ($row['submission_data'] ?? ''));
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
                            <?php foreach ($fields as $name => $value): ?>
                                <tr>
                                    <th scope="row"><?php echo esc_html((string) $name); ?></th>
                                    <td><?php echo esc_html(self::flatten($value)); ?></td>
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
            $configured = self::formEndpointCount() > 0;
            ?>
            <p class="cvm-empty-msg">
                <?php if ($configured): ?>
                    No delivery has been attempted for this submission yet.
                <?php else: ?>
                    Not sent — no webhook endpoint is configured to receive form submissions.
                    The submission is still fully recorded here.
                <?php endif; ?>
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
            'Device', 'IP Address', 'Delivery Status', 'Submission Data (JSON)',
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
                    (string) ($row['submission_data'] ?? '{}'),
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
     * @param array<string, mixed> $fields Decoded submission data.
     * @return string
     */
    private static function leadLabel(array $fields): string
    {
        $email = '';
        $name  = '';
        $first = '';
        $last  = '';
        $phone = '';

        foreach ($fields as $key => $value) {
            $flat = self::flatten($value);
            if ($flat === '') {
                continue;
            }

            $k = strtolower((string) $key);

            if ($email === '' && (str_contains($k, 'email') || is_email($flat))) {
                $email = $flat;
                continue;
            }
            if ($first === '' && (str_contains($k, 'first') || str_contains($k, 'fname'))) {
                $first = $flat;
                continue;
            }
            if ($last === '' && (str_contains($k, 'last') || str_contains($k, 'lname') || str_contains($k, 'surname'))) {
                $last = $flat;
                continue;
            }
            if ($name === '' && str_contains($k, 'name')) {
                $name = $flat;
                continue;
            }
            if ($phone === '' && (str_contains($k, 'phone') || str_contains($k, 'tel') || str_contains($k, 'mobile'))) {
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
