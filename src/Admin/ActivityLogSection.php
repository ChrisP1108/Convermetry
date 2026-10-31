<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\Pages\WebhooksPage;
use Convermetry\Api\DeliveryLogController;
use Convermetry\Settings\Options;
use Convermetry\Support\Pagination;
use Convermetry\Webhook\AnalyticsDispatcher;
use Convermetry\Webhook\DeliveryLog;

/**
 * The Activity Log: the last section of "Convermetry → Webhooks".
 *
 * It used to be a screen of its own (admin.php?page=convermetry-activity).
 * Delivery attempts are the outcome of the endpoints configured on Webhooks,
 * so the log now sits under them, at the {@see self::ANCHOR} fragment, and the
 * old slug redirects there.
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
 * This class owns the section's markup, its script configuration, and every
 * AJAX and admin-post handler behind it. {@see WebhooksPage::init()} registers
 * those handlers once per admin request, whichever screen the request is for,
 * and {@see WebhooksPage::render()} prints the section.
 *
 * Its permissions are its own and are never folded into webhooks.manage, which
 * also exposes signing secrets: viewing needs activity.view, deleting and
 * clearing need activity.manage, and the API card needs api.manage. Each
 * control is printed only for the scope that may use it, and each handler
 * checks that scope again.
 *
 * Log lists are populated client-side (assets/js/admin-webhooks.js) via the
 * cvmtry_get_activity_logs AJAX action; only row counts are fetched on page
 * load. Log data lives in the {@see DeliveryLog} table.
 */
final class ActivityLogSection
{
    /** The section's id on the Webhooks screen; links open it as a fragment. */
    public const string ANCHOR = 'cvmtry-activity-log';

    /** The Deliveries API card's id, for the screen's section links. */
    public const string API_ANCHOR = 'cvmtry-deliveries-api';

    /** Slug of the retired standalone screen. Requests for it are redirected. */
    public const string LEGACY_SLUG = 'convermetry-activity';

    /** Script handle for assets/js/admin-webhooks.js. */
    public const string SCRIPT_HANDLE = 'cvmtry-webhooks';

    /** Rows fetched per database round-trip while streaming an export. */
    private const int EXPORT_CHUNK = 200;

    /** admin-post action, and nonce action, for Clear All Logs. */
    public const string CLEAR_ACTION = 'cvmtry_clear_activity_logs';

    /** admin-post action, and nonce action, for the CSV export link. */
    public const string EXPORT_CSV_ACTION = 'cvmtry_activity_export_csv';

    /** admin-post action, and nonce action, for the JSON export link. */
    public const string EXPORT_JSON_ACTION = 'cvmtry_activity_export_json';

    /** AJAX action, and nonce action, for one page of the log. */
    public const string LIST_ACTION = 'cvmtry_get_activity_logs';

    /** AJAX action, and nonce action, for deleting one entry. */
    public const string DELETE_ACTION = 'cvmtry_delete_activity_log';

    /** AJAX action, and nonce action, for the Deliveries API toggle. */
    public const string API_TOGGLE_ACTION = 'cvmtry_toggle_delivery_api';

    /** AJAX action, and nonce action, for regenerating the API key. */
    public const string API_REGEN_ACTION = 'cvmtry_regen_delivery_api_key';

    /**
     * Registers the legacy-screen redirect and the action and AJAX hooks.
     *
     * Called from {@see WebhooksPage::init()}, which runs once per admin
     * request, so each handler is registered exactly once.
     *
     * Clear and export hang off admin_post_{action}, so WordPress only calls
     * them for their own form or link — ordinary admin page loads never reach
     * them. Each export format is its own action, so its nonce action is fixed
     * before any other input is read.
     *
     * The redirect hangs off admin_menu because the old slug is no longer
     * registered: wp-admin/includes/menu.php refuses an unregistered page right
     * after admin_menu, before admin_init ever runs.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'redirectLegacyScreen'], 0);

        add_action('admin_post_' . self::CLEAR_ACTION, [self::class, 'processClearLogs']);
        add_action('admin_post_' . self::EXPORT_CSV_ACTION, [self::class, 'processExportCsv']);
        add_action('admin_post_' . self::EXPORT_JSON_ACTION, [self::class, 'processExportJson']);

        add_action('wp_ajax_' . self::LIST_ACTION, [self::class, 'handleGetLogsAjax']);
        add_action('wp_ajax_' . self::DELETE_ACTION, [self::class, 'handleDeleteLogAjax']);
        add_action('wp_ajax_' . self::API_TOGGLE_ACTION, [self::class, 'handleApiToggleAjax']);
        add_action('wp_ajax_' . self::API_REGEN_ACTION, [self::class, 'handleApiRegenKeyAjax']);
    }

    /**
     * The Webhooks screen, opened at this section.
     *
     * Built with WordPress's URL helpers, so it follows the site's admin URL
     * (and self_admin_url()'s network/user variants) rather than a fixed host.
     *
     * @param array<string, string> $args Extra query arguments.
     * @return string
     */
    public static function url(array $args = []): string
    {
        return add_query_arg(['page' => WebhooksPage::MENU_SLUG] + $args, self_admin_url('admin.php')) . '#' . self::ANCHOR;
    }

    /**
     * Where a request for the retired standalone screen should go, or '' when
     * it should not be redirected.
     *
     * Only a user who could open the old screen — activity.view — is sent on.
     * Anyone else gets WordPress's own refusal for an unknown page, exactly
     * what they got before, rather than a redirect to a screen that would
     * refuse them or that has nothing for them.
     *
     * @param string $page The requested, sanitized page slug.
     * @return string
     */
    public static function legacyRedirectTarget(string $page): string
    {
        if ($page !== self::LEGACY_SLUG || !Capability::currentUserCan(Capability::ACTIVITY_VIEW)) {
            return '';
        }

        return self::url();
    }

    /**
     * Redirects admin.php?page=convermetry-activity to the Webhooks screen's
     * Activity Log section (admin_menu, priority 0).
     *
     * @return void
     */
    public static function redirectLegacyScreen(): void
    {
        global $pagenow;

        if ($pagenow !== 'admin.php') {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing: the slug is compared with one fixed value and only selects a redirect to a fixed admin URL; nothing is changed.
        $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';

        $target = self::legacyRedirectTarget($page);
        if ($target === '') {
            return;
        }

        wp_safe_redirect($target);
        exit;
    }

    /**
     * Enqueues assets/js/admin-webhooks.js and its configuration.
     *
     * Called by {@see WebhooksPage::enqueueAssets()} on the Webhooks screen,
     * for a user who may view the log. A nonce is issued only for an action the
     * user's scopes allow; every key is always present, so the script's
     * configuration has one shape.
     *
     * @return void
     */
    public static function enqueueScript(): void
    {
        wp_enqueue_script(
            self::SCRIPT_HANDLE,
            CVMTRY_PLUGIN_URL . 'assets/js/admin-webhooks.js',
            ['wp-i18n', AdminAssets::CONFIRM_HANDLE],
            CVMTRY_VERSION,
            true
        );
        wp_set_script_translations(self::SCRIPT_HANDLE, 'convermetry');

        $canDelete = Capability::currentUserCan(Capability::ACTIVITY_MANAGE);
        $canApi    = Capability::currentUserCan(Capability::API_MANAGE);

        wp_localize_script(self::SCRIPT_HANDLE, 'CVMTRY_LOG', [
            'ajaxUrl'        => admin_url('admin-ajax.php'),
            'logsNonce'      => wp_create_nonce(self::LIST_ACTION),
            'deleteNonce'    => $canDelete ? wp_create_nonce(self::DELETE_ACTION) : '',
            'apiToggleNonce' => $canApi ? wp_create_nonce(self::API_TOGGLE_ACTION) : '',
            'apiRegenNonce'  => $canApi ? wp_create_nonce(self::API_REGEN_ACTION) : '',
            'monthNames'     => AdminAssets::monthNames(),
        ]);
    }

    /**
     * Clears all stored delivery logs (admin_post_cvmtry_clear_activity_logs),
     * then redirects back to this section with a notice flag.
     *
     * @return never
     */
    public static function processClearLogs(): never
    {
        // 1. Method: only the Clear All Logs form's POST.
        if (!AdminRequest::isPost()) {
            AdminRequest::deny(__('The Activity Log can only be cleared from the Webhooks screen.', 'convermetry'), 405);
        }

        // 2. Capability: deleting log entries, not merely viewing them.
        if (!current_user_can(Capability::required(Capability::ACTIVITY_MANAGE))) {
            AdminRequest::deny(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['cvmtry_clear_nonce']) || !is_string($_POST['cvmtry_clear_nonce'])) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for clearing the log.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvmtry_clear_nonce'])), self::CLEAR_ACTION)) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 5. No further input: the action takes no parameters.
        DeliveryLog::clearLogs();

        wp_safe_redirect(self::url(['cvmtry_cleared' => '1']));
        exit;
    }

    /**
     * Streams every log row as CSV (admin_post_cvmtry_activity_export_csv).
     *
     * @return never
     */
    public static function processExportCsv(): never
    {
        // 1. Method: export links are followed, never posted.
        if (!AdminRequest::isGet()) {
            AdminRequest::deny(__('Activity Log exports are downloaded from the Webhooks screen.', 'convermetry'), 405);
        }

        // 2. Capability: the export holds every row the section can show.
        if (!current_user_can(Capability::required(Capability::ACTIVITY_VIEW))) {
            AdminRequest::deny(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_GET['_wpnonce']) || !is_string($_GET['_wpnonce'])) {
            AdminRequest::deny(__('Invalid or expired export link.', 'convermetry'));
        }

        // 4. Nonce issued for the CSV export — no other link's nonce opens it.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::EXPORT_CSV_ACTION)) {
            AdminRequest::deny(__('Invalid or expired export link.', 'convermetry'));
        }

        // 5. No further input: the export always covers the whole log.
        self::exportCsv();
    }

    /**
     * Streams every log row as JSON (admin_post_cvmtry_activity_export_json).
     *
     * @return never
     */
    public static function processExportJson(): never
    {
        // 1. Method: export links are followed, never posted.
        if (!AdminRequest::isGet()) {
            AdminRequest::deny(__('Activity Log exports are downloaded from the Webhooks screen.', 'convermetry'), 405);
        }

        // 2. Capability: the export holds every row the section can show.
        if (!current_user_can(Capability::required(Capability::ACTIVITY_VIEW))) {
            AdminRequest::deny(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_GET['_wpnonce']) || !is_string($_GET['_wpnonce'])) {
            AdminRequest::deny(__('Invalid or expired export link.', 'convermetry'));
        }

        // 4. Nonce issued for the JSON export — no other link's nonce opens it.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), self::EXPORT_JSON_ACTION)) {
            AdminRequest::deny(__('Invalid or expired export link.', 'convermetry'));
        }

        // 5. No further input: the export always covers the whole log.
        self::exportJson();
    }

    /**
     * Handles the cvmtry_get_activity_logs AJAX action.
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
        // 1. Method: the list is fetched by POST.
        if (!AdminRequest::isPost()) {
            AdminRequest::denyAjax(__('Invalid request.', 'convermetry'), 405);
        }

        // 2. Capability: viewing the log.
        if (!current_user_can(Capability::required(Capability::ACTIVITY_VIEW))) {
            AdminRequest::denyAjax(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['nonce']) || !is_string($_POST['nonce'])) {
            AdminRequest::denyAjax(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for listing the log.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), self::LIST_ACTION)) {
            AdminRequest::denyAjax(AdminRequest::expiredMessage());
        }

        // 5. Input: paging and filters, each read as one scalar — an
        // array-valued field is treated as absent rather than cast.
        $requestedPage = AdminRequest::positiveId(sanitize_text_field(AdminRequest::scalar($_POST, 'page')));
        $requestedSize = AdminRequest::positiveId(sanitize_text_field(AdminRequest::scalar($_POST, 'per_page')));
        $perPage       = Pagination::perPage($requestedSize > 0 ? $requestedSize : Pagination::DEFAULT_PER_PAGE);
        $filters       = self::filtersFromRequest($_POST);

        // Clamped BEFORE the query — see Pagination::resolve(). Without it,
        // deleting the last row on the last page left this list showing
        // "Showing 11-10 of 10" with no navigation to get back.
        $total  = DeliveryLog::getLogCount($filters);
        $paging = Pagination::resolve($requestedPage > 0 ? $requestedPage : 1, $perPage, $total);

        $page       = $paging['page'];
        $totalPages = $paging['totalPages'];

        $logs  = DeliveryLog::getLogsPaginated($page, $perPage, $filters);
        $dates = DeliveryLog::getDistinctDates(['status' => $filters['status'], 'endpoint' => $filters['endpoint']]);

        // Delete is offered only to a user the delete handler would accept.
        $canDelete = Capability::currentUserCan(Capability::ACTIVITY_MANAGE);

        $html = '';
        foreach ($logs as $entry) {
            $html .= self::renderLogEntryHtml($entry, $canDelete);
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
     * Sanitizes the list filters out of the (nonce-verified) AJAX request.
     *
     * @param array<string, mixed> $src The request array.
     * @return array{status: string, year: string, month: string, search: string, endpoint: string, message_type: string, provider: string, form_name: string}
     */
    private static function filtersFromRequest(array $src): array
    {
        $status = sanitize_key(AdminRequest::scalar($src, 'status'));

        return [
            'status'       => in_array($status, ['success', 'error'], true) ? $status : '',
            'year'         => sanitize_text_field(AdminRequest::scalar($src, 'filter_year')),
            'month'        => sanitize_text_field(AdminRequest::scalar($src, 'filter_month')),
            'search'       => sanitize_text_field(AdminRequest::scalar($src, 'search')),
            'endpoint'     => esc_url_raw(AdminRequest::scalar($src, 'endpoint')),
            'message_type' => sanitize_key(AdminRequest::scalar($src, 'message_type')),
            'provider'     => sanitize_key(AdminRequest::scalar($src, 'provider')),
            'form_name'    => sanitize_text_field(AdminRequest::scalar($src, 'form_name')),
        ];
    }

    /**
     * AJAX handler that deletes a single log entry.
     *
     * @return never
     */
    public static function handleDeleteLogAjax(): never
    {
        // 1. Method: deletes are POSTed.
        if (!AdminRequest::isPost()) {
            AdminRequest::denyAjax(__('Invalid request.', 'convermetry'), 405);
        }

        // 2. Capability: deleting log entries, not merely viewing them.
        if (!current_user_can(Capability::required(Capability::ACTIVITY_MANAGE))) {
            AdminRequest::denyAjax(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['nonce']) || !is_string($_POST['nonce'])) {
            AdminRequest::denyAjax(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for deleting a log entry.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), self::DELETE_ACTION)) {
            AdminRequest::denyAjax(AdminRequest::expiredMessage());
        }

        // 5. Input: one positive row id. ['5'] or '5abc' is refused, not
        // coerced — intval() would have turned an array into row 1.
        $id = isset($_POST['log_id']) && is_string($_POST['log_id'])
            ? AdminRequest::positiveId(sanitize_text_field(wp_unslash($_POST['log_id'])))
            : 0;

        if ($id === 0) {
            AdminRequest::denyAjax(__('Invalid log id.', 'convermetry'), 400);
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
        // 1. Method: the toggle POSTs.
        if (!AdminRequest::isPost()) {
            AdminRequest::denyAjax(__('Invalid request.', 'convermetry'), 405);
        }

        // 2. Capability: managing the API and its credentials.
        if (!current_user_can(Capability::required(Capability::API_MANAGE))) {
            AdminRequest::denyAjax(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['nonce']) || !is_string($_POST['nonce'])) {
            AdminRequest::denyAjax(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for toggling the API.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), self::API_TOGGLE_ACTION)) {
            AdminRequest::denyAjax(AdminRequest::expiredMessage());
        }

        // 5. Input: exactly '1' or '0'. Anything else changes nothing, so a
        // malformed request can neither disable the API nor mint a key.
        $requested = isset($_POST['active']) && is_string($_POST['active'])
            ? sanitize_key(wp_unslash($_POST['active']))
            : '';

        if ($requested !== '1' && $requested !== '0') {
            AdminRequest::denyAjax(__('Invalid request.', 'convermetry'), 400);
        }

        $active = $requested === '1';
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
        // 1. Method: regeneration POSTs.
        if (!AdminRequest::isPost()) {
            AdminRequest::denyAjax(__('Invalid request.', 'convermetry'), 405);
        }

        // 2. Capability: managing the API and its credentials.
        if (!current_user_can(Capability::required(Capability::API_MANAGE))) {
            AdminRequest::denyAjax(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['nonce']) || !is_string($_POST['nonce'])) {
            AdminRequest::denyAjax(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for regenerating the key — the toggle's nonce does
        // not qualify.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), self::API_REGEN_ACTION)) {
            AdminRequest::denyAjax(AdminRequest::expiredMessage());
        }

        // 5. No input: a new key replaces the old one's hash.
        wp_send_json_success(['key' => DeliveryLogController::generateKey()]);
    }

    /**
     * Renders the Activity Log section of the Webhooks screen.
     *
     * Printed inside the Webhooks screen's own .wrap, after its settings form
     * has closed, so the Clear All Logs form is never nested in it. The
     * section carries the anchor links open, and cvmtry-delivery-wrap, which
     * scopes this section's card styling away from the Webhooks cards above.
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

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flag from the redirect after processClearLogs(), which verifies its nonce and capability; it only selects a notice.
        $cleared     = isset($_GET['cvmtry_cleared']) && sanitize_key(wp_unslash($_GET['cvmtry_cleared'])) === '1';
        $totalOk     = DeliveryLog::getLogCount(['status' => 'success']);
        $totalErrors = DeliveryLog::getLogCount(['status' => 'error']);
        $totalAll    = $totalOk + $totalErrors;
        $canClear    = Capability::currentUserCan(Capability::ACTIVITY_MANAGE);
        $canApi      = Capability::currentUserCan(Capability::API_MANAGE);

        // Review first — the log, its exports and its retention — then, apart
        // from it, the one destructive action, Clear All Logs. The Deliveries
        // API card is configuration rather than review, so it takes its own
        // column beside the log on a wide screen and follows it on a narrow
        // one, under its own heading.
        ?>
        <section id="<?php echo esc_attr(self::ANCHOR); ?>" class="cvmtry-delivery-wrap" aria-labelledby="cvmtry-activity-log-title">
            <div class="cvmtry-activity-head">
                <h2 id="cvmtry-activity-log-title"><?php esc_html_e('Activity Log', 'convermetry'); ?></h2>

                <?php if ($totalAll > 0): ?>
                    <div class="cvmtry-export-buttons">
                        <a
                            href="<?php echo esc_url(self::exportUrl(self::EXPORT_CSV_ACTION)); ?>"
                            class="button button-secondary"
                        >
                            <?php esc_html_e('Export All To CSV', 'convermetry'); ?>
                        </a>
                        <a
                            href="<?php echo esc_url(self::exportUrl(self::EXPORT_JSON_ACTION)); ?>"
                            class="button button-secondary"
                        >
                            <?php esc_html_e('Export All To JSON', 'convermetry'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($cleared): ?>
                <?php // inline: WordPress moves every other notice up under the page title, out of sight of the section this redirect opens. ?>
                <div class="notice notice-success is-dismissible inline"><p><?php esc_html_e('All activity logs have been cleared.', 'convermetry'); ?></p></div>
            <?php endif; ?>

            <div class="cvmtry-activity-layout">
            <div class="cvmtry-activity-main">

            <div class="notice notice-info inline cvmtry-retention-notice">
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

            <!-- ── Successful Deliveries Accordion ─────────────────────────── -->
            <div class="cvmtry-accordion">
                <button type="button" class="cvmtry-accordion-header" aria-expanded="false" aria-controls="cvmtry-acc-success">
                    <span class="cvmtry-accordion-title"><?php esc_html_e('Successful Deliveries', 'convermetry'); ?></span>
                    <span class="cvmtry-badge"><?php echo esc_html((string) $totalOk); ?></span>
                    <span class="cvmtry-accordion-arrow" aria-hidden="true">&#9660;</span>
                </button>
                <div class="cvmtry-accordion-body" id="cvmtry-acc-success" data-status="success" hidden>
                    <!-- Log list injected by admin-webhooks.js via cvmtry_get_activity_logs AJAX -->
                </div>
            </div>

            <!-- ── Failed Deliveries Accordion ─────────────────────────────── -->
            <div class="cvmtry-accordion">
                <button type="button" class="cvmtry-accordion-header" aria-expanded="false" aria-controls="cvmtry-acc-errors">
                    <span class="cvmtry-accordion-title"><?php esc_html_e('Failed Deliveries', 'convermetry'); ?></span>
                    <span class="cvmtry-badge cvmtry-badge-error"><?php echo esc_html((string) $totalErrors); ?></span>
                    <span class="cvmtry-accordion-arrow" aria-hidden="true">&#9660;</span>
                </button>
                <div class="cvmtry-accordion-body" id="cvmtry-acc-errors" data-status="error" hidden>
                    <!-- Log list injected by admin-webhooks.js via cvmtry_get_activity_logs AJAX -->
                </div>
            </div>

            <?php if ($canClear): ?>
                <div class="cvmtry-delivery-toolbar cvmtry-danger-zone">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="cvmtry-clear-form">
                        <?php wp_nonce_field(self::CLEAR_ACTION, 'cvmtry_clear_nonce'); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::CLEAR_ACTION); ?>">
                        <button
                            type="submit"
                            class="button button-secondary cvmtry-btn-danger"
                            data-cvmtry-confirm="<?php echo esc_attr(__('Are you sure you want to clear all activity logs? This cannot be undone.', 'convermetry')); ?>"
                        >
                            <?php esc_html_e('Clear All Logs', 'convermetry'); ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>

            </div>

            <?php if ($canApi): ?>
                <div class="cvmtry-activity-aside">
                    <?php self::renderApiCard(); ?>
                </div>
            <?php endif; ?>
            </div>

        </section>
        <?php
    }

    /**
     * The Deliveries API card: the toggle, the endpoint, and the masked key
     * with its Copy and Regenerate controls. Printed for api.manage only.
     *
     * @return void
     */
    private static function renderApiCard(): void
    {
        $apiActive = DeliveryLogController::isActive();
        $hasKey    = DeliveryLogController::hasKey();

        ?>
        <!-- ── Deliveries API Card ────────────────────────────────────── -->
        <div class="cvmtry-card cvmtry-delivery-api-card" id="<?php echo esc_attr(self::API_ANCHOR); ?>">
            <h2 class="cvmtry-card-title"><?php esc_html_e('Deliveries API', 'convermetry'); ?></h2>
            <p class="description" style="margin-bottom:12px;">
                <?php echo wp_kses_post(__('Enable a read-only REST endpoint that returns activity log data as JSON, with <code>status</code>, <code>message_type</code>, <code>endpoint</code>, <code>provider</code>, and <code>form_id</code> filters. Pass the API key as the <code>Authorization</code> header on every request. This API is intended for <strong>server-to-server</strong> use — never embed the key in public frontend JavaScript, where any visitor could read it.', 'convermetry')); ?>
            </p>

            <div class="cvmtry-toggle-row">
                <label class="cvmtry-toggle" for="cvmtry-delivery-api-toggle" aria-label="<?php esc_attr_e('Toggle Deliveries API active state', 'convermetry'); ?>">
                    <input
                        type="checkbox"
                        id="cvmtry-delivery-api-toggle"
                        <?php checked($apiActive, true); ?>
                    >
                    <span class="cvmtry-toggle-slider" aria-hidden="true"></span>
                </label>
                <span class="cvmtry-toggle-label" id="cvmtry-api-toggle-label">
                    <?php echo esc_html($apiActive ? __('Active', 'convermetry') : __('Inactive', 'convermetry')); ?>
                </span>
            </div>

            <div id="cvmtry-api-key-section"<?php echo $apiActive ? '' : ' hidden'; ?>>
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
                            <div class="cvmtry-api-key-row">
                                <code id="cvmtry-api-key-value" data-masked="1"><?php echo esc_html($hasKey ? '••••••••••••••••••••' : __('(no key generated yet)', 'convermetry')); ?></code>
                                <button type="button" class="button cvmtry-copy-key-btn" hidden><?php esc_html_e('Copy', 'convermetry'); ?></button>
                                <button type="button" class="button cvmtry-regen-key-btn"><?php esc_html_e('Regenerate', 'convermetry'); ?></button>
                            </div>
                            <p class="description" style="margin-top:6px;">
                                <?php echo wp_kses_post(__('Only a hash of the key is stored, so the key is shown <strong>once</strong> — right after it is generated. Copy it then; if it is lost, regenerate a new one (any integrations using the old key stop working).', 'convermetry')); ?>
                            </p>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * A nonce-carrying export link for one format.
     *
     * Built with add_query_arg() rather than wp_nonce_url(), which
     * HTML-encodes its result and is meant for direct output; this value is
     * escaped once, where it is printed.
     *
     * @param string $action The export's admin-post and nonce action.
     * @return string
     */
    private static function exportUrl(string $action): string
    {
        return add_query_arg(
            ['action' => $action, '_wpnonce' => wp_create_nonce($action)],
            admin_url('admin-post.php')
        );
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
     * @param array<string, mixed> $entry     A single DeliveryLog row.
     * @param bool                 $canDelete Whether to offer the Delete button.
     * @return string
     */
    private static function renderLogEntryHtml(array $entry, bool $canDelete): string
    {
        $isError     = (int) ($entry['success'] ?? 0) === 0;
        $itemClass   = $isError ? 'cvmtry-log-error' : 'cvmtry-log-success';
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
        <li class="cvmtry-log-item <?php echo esc_attr($itemClass); ?>" data-log-id="<?php echo esc_attr((string) ($entry['id'] ?? '')); ?>">

            <div class="cvmtry-log-meta">
                <span class="cvmtry-log-time"><?php
                /* translators: %s: date and time in UTC. */
                echo esc_html(sprintf(__('%s UTC', 'convermetry'), $timestamp));
                ?></span>
                <?php if ($label !== ''): ?>
                    <span class="cvmtry-log-webhook-label"><?php echo esc_html($label); ?></span>
                <?php endif; ?>
                <span class="cvmtry-log-kind-label"><?php echo esc_html(self::kindLabel($messageType, $kind, $attempt)); ?></span>
                <span class="cvmtry-log-status <?php echo esc_attr($statusClass); ?>">
                    <?php echo esc_html($statusText); ?>
                    <?php if ($code !== 0): ?>
                        (<?php echo esc_html((string) $code); ?>)
                    <?php endif; ?>
                </span>
                <?php if ($canDelete): ?>
                    <button type="button" class="button cvmtry-log-delete-btn" aria-label="<?php
                    /* translators: %s: the Activity Log entry's numeric id. */
                    echo esc_attr(sprintf(__('Delete log entry %s', 'convermetry'), (string) ($entry['id'] ?? '')));
                    ?>"><?php esc_html_e('Delete', 'convermetry'); ?></button>
                <?php endif; ?>
            </div>

            <?php if ($endpoint !== ''): ?>
                <div class="cvmtry-log-url">
                    <strong><?php esc_html_e('Endpoint:', 'convermetry'); ?></strong> <code><?php echo esc_html($endpoint); ?></code>
                </div>
            <?php endif; ?>

            <?php if ($requestUrl !== '' && $requestUrl !== $endpoint): ?>
                <div class="cvmtry-log-url">
                    <strong><?php esc_html_e('Request URL:', 'convermetry'); ?></strong> <code><?php echo esc_html($requestUrl); ?></code>
                </div>
            <?php endif; ?>

            <?php if ($deliveryId !== ''): ?>
                <div class="cvmtry-log-url">
                    <strong><?php esc_html_e('Delivery ID:', 'convermetry'); ?></strong> <code><?php echo esc_html($deliveryId); ?></code>
                </div>
            <?php endif; ?>

            <?php if ((string) ($entry['submission_id'] ?? '') !== ''): ?>
                <div class="cvmtry-log-url">
                    <strong><?php esc_html_e('Submission ID:', 'convermetry'); ?></strong> <code><?php echo esc_html((string) $entry['submission_id']); ?></code>
                    <?php if ((string) ($entry['conversion_id'] ?? '') !== ''): ?>
                        &nbsp;<strong><?php esc_html_e('Conversion ID:', 'convermetry'); ?></strong> <code><?php echo esc_html((string) $entry['conversion_id']); ?></code>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ((string) ($entry['form_provider'] ?? '') !== ''): ?>
                <div class="cvmtry-log-url">
                    <strong><?php esc_html_e('Provider:', 'convermetry'); ?></strong> <?php echo esc_html((string) $entry['form_provider']); ?>
                    <?php if ((string) ($entry['form_name'] ?? '') !== ''): ?>
                        &nbsp;<strong><?php esc_html_e('Form:', 'convermetry'); ?></strong> <?php echo esc_html((string) $entry['form_name']); ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage !== ''): ?>
                <div class="cvmtry-log-error-msg">
                    <strong><?php esc_html_e('Error:', 'convermetry'); ?></strong> <?php echo esc_html($errorMessage); ?>
                </div>
            <?php endif; ?>

            <?php if ($prettyHeaders !== ''): ?>
                <details class="cvmtry-log-headers">
                    <summary><?php esc_html_e('Request headers (sensitive values redacted)', 'convermetry'); ?></summary>
                    <pre><?php echo esc_html($prettyHeaders); ?></pre>
                </details>
            <?php endif; ?>

            <div class="cvmtry-log-data">
                <strong><?php esc_html_e('Payload:', 'convermetry'); ?></strong>
                <pre><?php echo esc_html((string) $prettyRequest); ?></pre>
            </div>

            <?php if ($rawResponse !== '' && $errorMessage === ''): ?>
                <div class="cvmtry-log-response">
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
