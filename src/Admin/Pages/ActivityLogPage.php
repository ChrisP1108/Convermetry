<?php
declare(strict_types=1);

namespace Convermetry\Admin\Pages;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\Capability;
use Convermetry\Api\DeliveryLogController;
use Convermetry\Settings\Options;
use Convermetry\Support\Pagination;
use Convermetry\Webhook\AnalyticsDispatcher;
use Convermetry\Webhook\DeliveryLog;

/**
 * The "Convermetry → Activity Log" admin page.
 *
 * Detailed visibility into every outbound webhook delivery attempt —
 * analytics reports and form submissions; scheduled, immediate, retry, and
 * test — with:
 *  - an API card that toggles the read-only deliveries REST endpoint and
 *    manages its (hashed) key,
 *  - a retention notice and a toolbar (clear all logs, streaming CSV/JSON
 *    export),
 *  - two paginated accordions — Successful and Failed deliveries — with
 *    year/month, message-type, endpoint, provider, and form filters,
 *    debounced payload search, per-page selection, and per-entry delete.
 *
 * Log lists are populated client-side (assets/js/activity-log.js) via the
 * cvm_get_activity_logs AJAX action; only row counts are fetched on page
 * load. Log data lives in the {@see DeliveryLog} table.
 */
final class ActivityLogPage
{
    /** Menu slug for the submenu page. */
    public const string MENU_SLUG = 'convermetry-activity';

    /** Rows fetched per database round-trip while streaming an export. */
    private const int EXPORT_CHUNK = 200;

    /**
     * Registers menu, asset, action, and AJAX hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_init', [self::class, 'processClearLogs']);
        add_action('admin_init', [self::class, 'processExport']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);

        add_action('wp_ajax_cvm_get_activity_logs', [self::class, 'handleGetLogsAjax']);
        add_action('wp_ajax_cvm_delete_activity_log', [self::class, 'handleDeleteLogAjax']);
        add_action('wp_ajax_cvm_toggle_delivery_api', [self::class, 'handleApiToggleAjax']);
        add_action('wp_ajax_cvm_regen_delivery_api_key', [self::class, 'handleApiRegenKeyAjax']);
    }

    /**
     * Adds the Activity Log submenu.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            HomePage::MENU_SLUG,
            __('Convermetry Activity Log', 'convermetry'),
            __('Activity Log', 'convermetry'),
            Capability::required(Capability::ACTIVITY_VIEW),
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Enqueues the page's script on this page only.
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
            'cvm-activity-log',
            CVM_PLUGIN_URL . 'assets/css/admin-activity-log.css',
            [AdminAssets::COMMON_HANDLE],
            CVM_VERSION
        );

        wp_enqueue_script(
            'cvm-activity-log',
            CVM_PLUGIN_URL . 'assets/js/activity-log.js',
            ['wp-i18n'],
            CVM_VERSION,
            true
        );
        wp_set_script_translations('cvm-activity-log', 'convermetry');

        wp_localize_script('cvm-activity-log', 'CVM_LOG', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'logsNonce'      => wp_create_nonce('cvm_get_activity_logs'),
            'deleteNonce'    => wp_create_nonce('cvm_delete_activity_log'),
            'apiToggleNonce' => wp_create_nonce('cvm_toggle_delivery_api'),
            'apiRegenNonce'  => wp_create_nonce('cvm_regen_delivery_api_key'),
            'monthNames'     => AdminAssets::monthNames(),
        ]);
    }

    /**
     * Clears all stored delivery logs if a valid nonce-protected POST is
     * detected, then redirects back with a notice flag.
     *
     * @return void
     */
    public static function processClearLogs(): void
    {
        if (
            sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST' ||
            !isset($_POST['cvm_action']) ||
            sanitize_key(wp_unslash($_POST['cvm_action'])) !== 'clear_activity_logs' ||
            !isset($_POST['cvm_clear_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvm_clear_nonce'])), 'cvm_clear_activity_logs') ||
            !Capability::currentUserCan(Capability::ACTIVITY_MANAGE)
        ) {
            return;
        }

        DeliveryLog::clearLogs();

        wp_safe_redirect(
            add_query_arg(['page' => self::MENU_SLUG, 'cvm_cleared' => '1'], self_admin_url('admin.php'))
        );
        exit;
    }

    /**
     * Streams a CSV or JSON file download when a valid export link is followed.
     *
     * @return void
     */
    public static function processExport(): void
    {
        if (!isset($_GET['cvm_export']) || !Capability::currentUserCan(Capability::ACTIVITY_VIEW)) {
            return;
        }

        // Only act on this plugin's page so the shared query var can never
        // hijack another admin screen.
        if (!isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG) {
            return;
        }

        $type = sanitize_key((string) $_GET['cvm_export']);
        if ($type !== 'csv' && $type !== 'json') {
            return;
        }

        if (
            !isset($_GET['_wpnonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'cvm_export_' . $type)
        ) {
            wp_die(esc_html__('Invalid or expired export link.', 'convermetry'), '', ['response' => 403]);
        }

        $type === 'csv' ? self::exportCsv() : self::exportJson();
    }

    /**
     * Handles the cvm_get_activity_logs AJAX action.
     *
     * Accepts page, per_page, status, search, filter_year, filter_month,
     * endpoint, message_type, provider, and form_name from POST. Returns
     * JSON with rendered log-item HTML, row counts, and the distinct filter
     * value arrays.
     *
     * @return never
     */
    public static function handleGetLogsAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cvm_get_activity_logs') ||
            !Capability::currentUserCan(Capability::ACTIVITY_VIEW)
        ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'convermetry')]);
        }

        $perPage = Pagination::perPage(isset($_POST['per_page']) ? intval(wp_unslash($_POST['per_page'])) : Pagination::DEFAULT_PER_PAGE);

        $status = sanitize_key((string) ($_POST['status'] ?? ''));

        $filters = [
            'status'       => in_array($status, ['success', 'error'], true) ? $status : '',
            'year'         => sanitize_text_field(wp_unslash($_POST['filter_year'] ?? '')),
            'month'        => sanitize_text_field(wp_unslash($_POST['filter_month'] ?? '')),
            'search'       => sanitize_text_field(wp_unslash($_POST['search'] ?? '')),
            'endpoint'     => esc_url_raw(wp_unslash($_POST['endpoint'] ?? '')),
            'message_type' => sanitize_key((string) ($_POST['message_type'] ?? '')),
            'provider'     => sanitize_key((string) ($_POST['provider'] ?? '')),
            'form_name'    => sanitize_text_field(wp_unslash($_POST['form_name'] ?? '')),
        ];

        // Clamped BEFORE the query — see Pagination::resolve(). Without it,
        // deleting the last row on the last page left this screen showing
        // "Showing 11-10 of 10" with no navigation to get back.
        $total  = DeliveryLog::getLogCount($filters);
        $paging = Pagination::resolve(isset($_POST['page']) ? intval(wp_unslash($_POST['page'])) : 1, $perPage, $total);

        $page       = $paging['page'];
        $totalPages = $paging['totalPages'];

        $logs  = DeliveryLog::getLogsPaginated($page, $perPage, $filters);
        $dates = DeliveryLog::getDistinctDates(['status' => $filters['status'], 'endpoint' => $filters['endpoint']]);

        $html = '';
        foreach ($logs as $entry) {
            $html .= self::renderLogEntryHtml($entry);
        }

        wp_send_json_success([
            'html'        => $html,
            'total'       => $total,
            'totalPages'  => $totalPages,
            'currentPage' => $page,
            'years'       => $dates['years'],
            'months'      => $dates['months'],
            'endpoints'   => DeliveryLog::getDistinctEndpoints(),
            'providers'   => DeliveryLog::getDistinctProviders(),
            'formNames'   => DeliveryLog::getDistinctFormNames(),
        ]);
    }

    /**
     * AJAX handler that deletes a single log entry.
     *
     * @return never
     */
    public static function handleDeleteLogAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cvm_delete_activity_log') ||
            !Capability::currentUserCan(Capability::ACTIVITY_MANAGE)
        ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'convermetry')]);
        }

        $id = isset($_POST['log_id']) ? intval(wp_unslash($_POST['log_id'])) : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => __('Invalid log id.', 'convermetry')]);
        }

        DeliveryLog::deleteLog($id);
        wp_send_json_success();
    }

    /**
     * AJAX handler that toggles the deliveries API on or off.
     *
     * When turning ON for the first time, generates an API key if none
     * exists and returns the raw key — the only time it is ever sent; the
     * server stores just its hash.
     *
     * @return never
     */
    public static function handleApiToggleAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cvm_toggle_delivery_api') ||
            !Capability::currentUserCan(Capability::API_MANAGE)
        ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'convermetry')]);
        }

        $active = isset($_POST['active']) && $_POST['active'] === '1';
        DeliveryLogController::setActive($active);

        $key = '';
        if ($active && !DeliveryLogController::hasKey()) {
            $key = DeliveryLogController::generateKey();
        }

        wp_send_json_success(['active' => $active, 'key' => $key]);
    }

    /**
     * AJAX handler that generates a new deliveries API key and returns it.
     *
     * @return never
     */
    public static function handleApiRegenKeyAjax(): never
    {
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cvm_regen_delivery_api_key') ||
            !Capability::currentUserCan(Capability::API_MANAGE)
        ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'convermetry')]);
        }

        wp_send_json_success(['key' => DeliveryLogController::generateKey()]);
    }

    /**
     * Renders the full Activity Log page HTML.
     *
     * Only row counts are fetched on page load; log entries load lazily into
     * each accordion when it is first opened.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!Capability::currentUserCan(Capability::ACTIVITY_VIEW)) {
            return;
        }

        $cleared     = isset($_GET['cvm_cleared']) && $_GET['cvm_cleared'] === '1';
        $totalOk     = DeliveryLog::getLogCount(['status' => 'success']);
        $totalErrors = DeliveryLog::getLogCount(['status' => 'error']);
        $totalAll    = $totalOk + $totalErrors;
        $apiActive   = DeliveryLogController::isActive();
        $hasKey      = DeliveryLogController::hasKey();

        ?>
        <div class="wrap cvm-wrap cvm-delivery-wrap">
            <h1><?php esc_html_e('Activity Log', 'convermetry'); ?></h1>

            <?php if ($cleared): ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('All activity logs have been cleared.', 'convermetry'); ?></p></div>
            <?php endif; ?>

            <!-- ── Deliveries API Card ────────────────────────────────────── -->
            <div class="cvm-card cvm-delivery-api-card">
                <h2 class="cvm-card-title"><?php esc_html_e('Deliveries API', 'convermetry'); ?></h2>
                <p class="description" style="margin-bottom:12px;">
                    <?php echo wp_kses_post(__('Enable a read-only REST endpoint that returns activity log data as JSON, with <code>status</code>, <code>message_type</code>, <code>endpoint</code>, <code>provider</code>, and <code>form_id</code> filters. Pass the API key as the <code>Authorization</code> header on every request. This API is intended for <strong>server-to-server</strong> use — never embed the key in public frontend JavaScript, where any visitor could read it.', 'convermetry')); ?>
                </p>

                <div class="cvm-toggle-row">
                    <label class="cvm-toggle" for="cvm-delivery-api-toggle" aria-label="<?php esc_attr_e('Toggle Deliveries API active state', 'convermetry'); ?>">
                        <input
                            type="checkbox"
                            id="cvm-delivery-api-toggle"
                            <?php checked($apiActive, true); ?>
                        >
                        <span class="cvm-toggle-slider" aria-hidden="true"></span>
                    </label>
                    <span class="cvm-toggle-label" id="cvm-api-toggle-label">
                        <?php echo esc_html($apiActive ? __('Active', 'convermetry') : __('Inactive', 'convermetry')); ?>
                    </span>
                </div>

                <div id="cvm-api-key-section"<?php echo $apiActive ? '' : ' hidden'; ?>>
                    <table class="form-table" role="presentation" style="margin-top:12px;">
                        <tr>
                            <th scope="row"><?php esc_html_e('Endpoint', 'convermetry'); ?></th>
                            <td>
                                <code><?php echo esc_url(rest_url('convermetry/v1/deliveries')); ?></code>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('API Key', 'convermetry'); ?></th>
                            <td>
                                <div class="cvm-api-key-row">
                                    <code id="cvm-api-key-value" data-masked="1"><?php echo esc_html($hasKey ? '••••••••••••••••••••' : __('(no key generated yet)', 'convermetry')); ?></code>
                                    <button type="button" class="button cvm-copy-key-btn" hidden><?php esc_html_e('Copy', 'convermetry'); ?></button>
                                    <button type="button" class="button cvm-regen-key-btn"><?php esc_html_e('Regenerate', 'convermetry'); ?></button>
                                </div>
                                <p class="description" style="margin-top:6px;">
                                    <?php echo wp_kses_post(__('Only a hash of the key is stored, so the key is shown <strong>once</strong> — right after it is generated. Copy it then; if it is lost, regenerate a new one (any integrations using the old key stop working).', 'convermetry')); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="notice notice-info inline cvm-retention-notice">
                <p>
                    <?php
                    echo wp_kses_post(sprintf(
                        /* translators: %d: retention period in days. */
                        _n(
                            '<strong>Log retention policy:</strong> Entries older than %d day are automatically removed daily. The retention period is shared with the analytics data and can be changed under <strong>Settings</strong>.',
                            '<strong>Log retention policy:</strong> Entries older than %d days are automatically removed daily. The retention period is shared with the analytics data and can be changed under <strong>Settings</strong>.',
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
                    <?php wp_nonce_field('cvm_clear_activity_logs', 'cvm_clear_nonce'); ?>
                    <input type="hidden" name="cvm_action" value="clear_activity_logs">
                    <button
                        type="submit"
                        class="button button-secondary cvm-btn-danger"
                        onclick="return confirm(<?php echo esc_attr((string) wp_json_encode(__('Are you sure you want to clear all activity logs? This cannot be undone.', 'convermetry'))); ?>);"
                    >
                        <?php esc_html_e('Clear All Logs', 'convermetry'); ?>
                    </button>
                </form>

                <?php if ($totalAll > 0): ?>
                    <div class="cvm-export-buttons">
                        <a
                            href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => self::MENU_SLUG, 'cvm_export' => 'csv'], self_admin_url('admin.php')), 'cvm_export_csv')); ?>"
                            class="button button-secondary"
                        >
                            <?php esc_html_e('Export All To CSV', 'convermetry'); ?>
                        </a>
                        <a
                            href="<?php echo esc_url(wp_nonce_url(add_query_arg(['page' => self::MENU_SLUG, 'cvm_export' => 'json'], self_admin_url('admin.php')), 'cvm_export_json')); ?>"
                            class="button button-secondary"
                        >
                            <?php esc_html_e('Export All To JSON', 'convermetry'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ── Successful Deliveries Accordion ─────────────────────────── -->
            <div class="cvm-accordion">
                <button type="button" class="cvm-accordion-header" aria-expanded="false" aria-controls="cvm-acc-success">
                    <span class="cvm-accordion-title"><?php esc_html_e('Successful Deliveries', 'convermetry'); ?></span>
                    <span class="cvm-badge"><?php echo esc_html((string) $totalOk); ?></span>
                    <span class="cvm-accordion-arrow" aria-hidden="true">&#9660;</span>
                </button>
                <div class="cvm-accordion-body" id="cvm-acc-success" data-status="success" hidden>
                    <!-- Log list injected by activity-log.js via cvm_get_activity_logs AJAX -->
                </div>
            </div>

            <!-- ── Failed Deliveries Accordion ─────────────────────────────── -->
            <div class="cvm-accordion">
                <button type="button" class="cvm-accordion-header" aria-expanded="false" aria-controls="cvm-acc-errors">
                    <span class="cvm-accordion-title"><?php esc_html_e('Failed Deliveries', 'convermetry'); ?></span>
                    <span class="cvm-badge cvm-badge-error"><?php echo esc_html((string) $totalErrors); ?></span>
                    <span class="cvm-accordion-arrow" aria-hidden="true">&#9660;</span>
                </button>
                <div class="cvm-accordion-body" id="cvm-acc-errors" data-status="error" hidden>
                    <!-- Log list injected by activity-log.js via cvm_get_activity_logs AJAX -->
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Human-readable label for a normalized (message_type, kind, attempt)
     * triple — display only; filtering always uses the normalized columns.
     *
     * @param string $messageType Stored message_type.
     * @param string $kind        Stored kind.
     * @param int    $attempt     Stored attempt number.
     * @return string
     */
    private static function kindLabel(string $messageType, string $kind, int $attempt): string
    {
        $type = $messageType === 'form_submission' ? __('Form Submission', 'convermetry') : __('Analytics Report', 'convermetry');

        return match ($kind) {
            /* translators: %s: message type, "Form Submission" or "Analytics Report". */
            'test'      => sprintf(__('%s · Test', 'convermetry'), $type),
            'retry'     => sprintf(
                /* translators: 1: message type, 2: attempt number, 3: maximum attempts. */
                __('%1$s · Retry %2$d/%3$d', 'convermetry'),
                $type,
                $attempt,
                AnalyticsDispatcher::maxRetries() + ($messageType === 'form_submission' ? 1 : 0)
            ),
            /* translators: %s: message type, "Form Submission" or "Analytics Report". */
            'immediate' => sprintf(__('%s · Immediate', 'convermetry'), $type),
            /* translators: %s: message type, "Form Submission" or "Analytics Report". */
            default     => sprintf(__('%s · Scheduled', 'convermetry'), $type),
        };
    }

    /**
     * Renders a single log row as an HTML list-item string.
     *
     * @param array<string, mixed> $entry A single DeliveryLog row.
     * @return string
     */
    private static function renderLogEntryHtml(array $entry): string
    {
        $isError     = (int) ($entry['success'] ?? 0) === 0;
        $itemClass   = $isError ? 'cvm-log-error' : 'cvm-log-success';
        $statusText  = $isError ? __('Error', 'convermetry') : __('Success', 'convermetry');
        $statusClass = $isError ? 'error' : 'success';
        $timestamp   = (string) ($entry['created_at'] ?? '');
        $endpoint    = (string) ($entry['endpoint_url'] ?? '');
        $requestUrl  = (string) ($entry['request_url'] ?? '');
        $code        = (int) ($entry['response_code'] ?? 0);
        $rawResponse = (string) ($entry['response_data'] ?? '');
        $deliveryId  = (string) ($entry['delivery_id'] ?? '');
        $attempt     = (int) ($entry['attempt'] ?? 0);
        $messageType = (string) ($entry['message_type'] ?? '');
        $kind        = (string) ($entry['kind'] ?? 'scheduled');

        // Prefer the label stored at delivery time; fall back to the current
        // configuration for rows logged before a label was set.
        $label = (string) ($entry['endpoint_label'] ?? '');
        if ($label === '' && $endpoint !== '') {
            $label = Options::endpointLabel($endpoint);
        }

        $requestDecoded = json_decode((string) ($entry['request_data'] ?? '{}'), true);
        $prettyRequest  = is_array($requestDecoded)
            ? json_encode($requestDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : (string) ($entry['request_data'] ?? '');

        $headersDecoded = json_decode((string) ($entry['request_headers'] ?? ''), true);
        // (string) rather than a bare json_encode(): the call is declared as
        // able to return false, and false is not '', so the panel would have
        // rendered an empty <details> block rather than omitting it.
        $prettyHeaders  = is_array($headersDecoded) && $headersDecoded !== []
            ? (string) json_encode($headersDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : '';

        // Transport errors are stored as {"error": "..."} with response code 0.
        $responseDecoded = json_decode($rawResponse, true);
        $errorMessage    = ($code === 0 && is_array($responseDecoded))
            ? (string) ($responseDecoded['error'] ?? '')
            : '';

        ob_start();
        ?>
        <li class="cvm-log-item <?php echo esc_attr($itemClass); ?>" data-log-id="<?php echo esc_attr((string) ($entry['id'] ?? '')); ?>">

            <div class="cvm-log-meta">
                <span class="cvm-log-time"><?php
                /* translators: %s: date and time in UTC. */
                echo esc_html(sprintf(__('%s UTC', 'convermetry'), $timestamp));
                ?></span>
                <?php if ($label !== ''): ?>
                    <span class="cvm-log-webhook-label"><?php echo esc_html($label); ?></span>
                <?php endif; ?>
                <span class="cvm-log-kind-label"><?php echo esc_html(self::kindLabel($messageType, $kind, $attempt)); ?></span>
                <span class="cvm-log-status <?php echo esc_attr($statusClass); ?>">
                    <?php echo esc_html($statusText); ?>
                    <?php if ($code !== 0): ?>
                        (<?php echo esc_html((string) $code); ?>)
                    <?php endif; ?>
                </span>
                <button type="button" class="button cvm-log-delete-btn" aria-label="<?php
                /* translators: %s: the Activity Log entry's numeric id. */
                echo esc_attr(sprintf(__('Delete log entry %s', 'convermetry'), (string) ($entry['id'] ?? '')));
                ?>"><?php esc_html_e('Delete', 'convermetry'); ?></button>
            </div>

            <?php if ($endpoint !== ''): ?>
                <div class="cvm-log-url">
                    <strong><?php esc_html_e('Endpoint:', 'convermetry'); ?></strong> <code><?php echo esc_html($endpoint); ?></code>
                </div>
            <?php endif; ?>

            <?php if ($requestUrl !== '' && $requestUrl !== $endpoint): ?>
                <div class="cvm-log-url">
                    <strong><?php esc_html_e('Request URL:', 'convermetry'); ?></strong> <code><?php echo esc_html($requestUrl); ?></code>
                </div>
            <?php endif; ?>

            <?php if ($deliveryId !== ''): ?>
                <div class="cvm-log-url">
                    <strong><?php esc_html_e('Delivery ID:', 'convermetry'); ?></strong> <code><?php echo esc_html($deliveryId); ?></code>
                </div>
            <?php endif; ?>

            <?php if ((string) ($entry['submission_id'] ?? '') !== ''): ?>
                <div class="cvm-log-url">
                    <strong><?php esc_html_e('Submission ID:', 'convermetry'); ?></strong> <code><?php echo esc_html((string) $entry['submission_id']); ?></code>
                    <?php if ((string) ($entry['conversion_id'] ?? '') !== ''): ?>
                        &nbsp;<strong><?php esc_html_e('Conversion ID:', 'convermetry'); ?></strong> <code><?php echo esc_html((string) $entry['conversion_id']); ?></code>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ((string) ($entry['form_provider'] ?? '') !== ''): ?>
                <div class="cvm-log-url">
                    <strong><?php esc_html_e('Provider:', 'convermetry'); ?></strong> <?php echo esc_html((string) $entry['form_provider']); ?>
                    <?php if ((string) ($entry['form_name'] ?? '') !== ''): ?>
                        &nbsp;<strong><?php esc_html_e('Form:', 'convermetry'); ?></strong> <?php echo esc_html((string) $entry['form_name']); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="cvm-log-error-msg">
                    <strong><?php esc_html_e('Error:', 'convermetry'); ?></strong> <?php echo esc_html($errorMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($prettyHeaders !== ''): ?>
                <details class="cvm-log-headers">
                    <summary><?php esc_html_e('Request headers (sensitive values redacted)', 'convermetry'); ?></summary>
                    <pre><?php echo esc_html($prettyHeaders); ?></pre>
                </details>
            <?php endif; ?>

            <div class="cvm-log-data">
                <strong><?php esc_html_e('Payload:', 'convermetry'); ?></strong>
                <pre><?php echo esc_html((string) $prettyRequest); ?></pre>
            </div>

            <?php if ($rawResponse !== '' && $errorMessage === ''): ?>
                <div class="cvm-log-response">
                    <strong><?php esc_html_e('Response:', 'convermetry'); ?></strong>
                    <pre><?php echo esc_html($rawResponse); ?></pre>
                </div>
            <?php endif; ?>

        </li>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Streams all log rows as a UTF-8 CSV file and exits.
     *
     * Rows are fetched in keyset-paginated chunks and written as they
     * arrive — with two potentially-64 KB bodies per row, loading the whole
     * table into memory could exhaust PHP on a long-retention site.
     *
     * @return never
     */
    private static function exportCsv(): never
    {
        $filename = 'convermetry-activity-log-' . gmdate('Y-m-d') . '.csv';

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
        fputcsv($output, [
            'ID', 'Date/Time (UTC)', 'Success', 'Message Type', 'Kind', 'Attempt',
            'Endpoint', 'Endpoint Label', 'Delivery ID', 'Submission ID', 'Conversion ID',
            'Form Provider', 'Form Name', 'Response Code', 'Request URL', 'Payload', 'Response',
        ], escape: '');

        $beforeId = PHP_INT_MAX;

        do {
            $logs = DeliveryLog::getLogsChunk($beforeId, self::EXPORT_CHUNK);

            foreach ($logs as $entry) {
                $beforeId = (int) ($entry['id'] ?? 0);

                fputcsv($output, [
                    (int) ($entry['id'] ?? 0),
                    self::escapeCsvCell((string) ($entry['created_at'] ?? '')),
                    (int) ($entry['success'] ?? 0) === 1 ? 'Yes' : 'No',
                    self::escapeCsvCell((string) ($entry['message_type'] ?? '')),
                    self::escapeCsvCell((string) ($entry['kind'] ?? '')),
                    (int) ($entry['attempt'] ?? 0),
                    self::escapeCsvCell((string) ($entry['endpoint_url'] ?? '')),
                    self::escapeCsvCell((string) ($entry['endpoint_label'] ?? '')),
                    self::escapeCsvCell((string) ($entry['delivery_id'] ?? '')),
                    self::escapeCsvCell((string) ($entry['submission_id'] ?? '')),
                    self::escapeCsvCell((string) ($entry['conversion_id'] ?? '')),
                    self::escapeCsvCell((string) ($entry['form_provider'] ?? '')),
                    self::escapeCsvCell((string) ($entry['form_name'] ?? '')),
                    (int) ($entry['response_code'] ?? 0),
                    self::escapeCsvCell((string) ($entry['request_url'] ?? '')),
                    self::escapeCsvCell((string) ($entry['request_data'] ?? '')),
                    self::escapeCsvCell((string) ($entry['response_data'] ?? '')),
                ], escape: '');
            }
        } while (count($logs) === self::EXPORT_CHUNK);

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closes the php://output stream opened above; no file is involved.
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

    /**
     * Streams all log rows as a pretty-printed JSON array and exits.
     *
     * The array is streamed structurally — an opening bracket, each entry
     * encoded and emitted individually, then a closing bracket — so memory
     * use stays bounded regardless of table size.
     *
     * @return never
     */
    private static function exportJson(): never
    {
        $filename = 'convermetry-activity-log-' . gmdate('Y-m-d') . '.json';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "[\n";

        $beforeId = PHP_INT_MAX;
        $first    = true;

        do {
            $logs = DeliveryLog::getLogsChunk($beforeId, self::EXPORT_CHUNK);

            foreach ($logs as $entry) {
                $beforeId = (int) ($entry['id'] ?? 0);

                echo ($first ? '' : ",\n") . wp_json_encode(
                    DeliveryLogController::formatEntry($entry),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );
                $first = false;
            }
        } while (count($logs) === self::EXPORT_CHUNK);

        echo "\n]";
        exit;
    }
}
