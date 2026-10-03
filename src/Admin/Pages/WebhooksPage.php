<?php
declare(strict_types=1);

namespace Convermetry\Admin\Pages;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\AdminRequest;
use Convermetry\Admin\Capability;
use Convermetry\Settings\Options;
use Convermetry\Settings\WebhookEndpoint;
use Convermetry\Settings\WebhookSettingsInput;
use Convermetry\Webhook\AnalyticsDispatcher;
use Convermetry\Webhook\FormDeliveryQueue;

/**
 * The "Convermetry → Webhooks" admin page.
 *
 * Manages every outbound delivery setting:
 *  - the Webhook Status master toggle (pauses all new deliveries without
 *    discarding configuration),
 *  - the endpoint repeater — URL, optional label, optional per-endpoint
 *    signing secret, and the two Delivery Types checkboxes (Analytics
 *    Reports / Form Submissions) that decide which message types each
 *    endpoint receives,
 *  - the shared signing secret, analytics send interval, and history
 *    backfill,
 *  - global request headers and global URL query parameters applied to
 *    every delivery, plus the global "include page URL parameters" default
 *    for form submissions,
 *  - the form-delivery failure mode (background retries vs. show the error
 *    on the form),
 *  - per-endpoint test buttons for both payload types, pending
 *    analytics-retry chains (with Discard), and the pending form-delivery
 *    queue.
 *
 * Endpoints must use HTTPS; the 'convermetry_allow_insecure_webhooks'
 * filter allows plain HTTP for development setups.
 */
final class WebhooksPage
{
    /** Menu slug for the submenu page. */
    public const string MENU_SLUG = 'convermetry-webhooks';

    /** admin-post action name for saving the page. */
    private const string SAVE_ACTION = 'cvmtry_save_webhooks';

    /** admin-post action, and nonce action, for discarding one pending analytics retry. */
    private const string DISCARD_ACTION = 'cvmtry_discard_retry';

    /** Per-user transient prefix for what the last save refused (structured, never raw input). */
    private const string REJECTED_TRANSIENT = 'cvmtry_webhook_rejected_';

    /**
     * Registers menu, save, discard, notice, asset, and AJAX hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_' . self::SAVE_ACTION, [self::class, 'handleSave']);
        add_action('admin_post_' . self::DISCARD_ACTION, [self::class, 'handleDiscardRetry']);
        add_action('admin_notices', [self::class, 'maybeShowNotices']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_cvmtry_test_webhook', [self::class, 'handleTestAjax']);
    }

    /**
     * Adds the Webhooks submenu.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            HomePage::MENU_SLUG,
            __('Convermetry Webhooks', 'convermetry'),
            __('Webhooks', 'convermetry'),
            Capability::required(Capability::WEBHOOKS_MANAGE),
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Enqueues the admin script (endpoint repeater, key/value builders,
     * test buttons) on this page only.
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
            'cvmtry-webhooks',
            CVMTRY_PLUGIN_URL . 'assets/css/admin-webhooks.css',
            [AdminAssets::COMMON_HANDLE],
            CVMTRY_VERSION
        );

        wp_enqueue_script(
            'cvmtry-admin',
            CVMTRY_PLUGIN_URL . 'assets/js/admin.js',
            ['wp-i18n'],
            CVMTRY_VERSION,
            true
        );
        wp_set_script_translations('cvmtry-admin', 'convermetry');

        wp_localize_script('cvmtry-admin', 'CVMTRY_ADMIN', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'testNonce' => wp_create_nonce('cvmtry_test_webhook'),
        ]);
    }

    /**
     * Validates and persists the webhook settings POST
     * (admin_post_cvmtry_save_webhooks).
     *
     * @return never
     */
    public static function handleSave(): never
    {
        // 1. Method: only the settings form's POST.
        if (!AdminRequest::isPost()) {
            AdminRequest::deny(__('Webhook settings can only be saved from the Webhooks screen.', 'convermetry'), 405);
        }

        // 2. Capability: endpoints and their signing secrets.
        if (!current_user_can(Capability::required(Capability::WEBHOOKS_MANAGE))) {
            AdminRequest::deny(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['cvmtry_webhooks_nonce']) || !is_string($_POST['cvmtry_webhooks_nonce'])) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for saving webhook settings.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvmtry_webhooks_nonce'])), self::SAVE_ACTION)) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 5. Input. Each field is unslashed exactly once, here, and handed to
        // WebhookSettingsInput, which checks its type and validates and
        // sanitizes it for its meaning before anything is stored.

        /**
         * Filters whether plain-HTTP webhook endpoints may be saved.
         *
         * Off by default: webhook payloads carry visitor analytics and lead
         * data and are HMAC-signed, and both are exposed to any on-path
         * observer over plain HTTP. Return true only for development setups.
         *
         * @param bool $allow Whether http:// endpoint URLs are accepted.
         */
        $allowInsecure = (bool) apply_filters('convermetry_allow_insecure_webhooks', false);

        // Only ids that are ALREADY configured may be carried through a save.
        $knownIds = [];
        foreach (Options::endpoints() as $configured) {
            if ($configured->id !== '') {
                $knownIds[$configured->id] = true;
            }
        }

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- unslashed once here; WebhookSettingsInput::sanitize() type-checks every field and validates and sanitizes each for its meaning before anything is stored.
        $input = WebhookSettingsInput::sanitize(
            [
                'endpoints'           => isset($_POST['cvmtry_webhooks']) ? wp_unslash($_POST['cvmtry_webhooks']) : null,
                'active'              => isset($_POST['cvmtry_webhook_active']) ? wp_unslash($_POST['cvmtry_webhook_active']) : null,
                'interval'            => isset($_POST['cvmtry_interval']) ? wp_unslash($_POST['cvmtry_interval']) : null,
                'shared_secret'       => isset($_POST['cvmtry_shared_secret']) ? wp_unslash($_POST['cvmtry_shared_secret']) : null,
                'backfill'            => isset($_POST['cvmtry_backfill']) ? wp_unslash($_POST['cvmtry_backfill']) : null,
                'global_headers'      => isset($_POST['cvmtry_global_headers']) ? wp_unslash($_POST['cvmtry_global_headers']) : null,
                'global_query'        => isset($_POST['cvmtry_global_query']) ? wp_unslash($_POST['cvmtry_global_query']) : null,
                'include_page_params' => isset($_POST['cvmtry_include_page_params']) ? wp_unslash($_POST['cvmtry_include_page_params']) : null,
                'failure_mode'        => isset($_POST['cvmtry_failure_mode']) ? wp_unslash($_POST['cvmtry_failure_mode']) : null,
            ],
            $knownIds,
            $allowInsecure
        );
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        // A request that is not the shape this form posts changes nothing.
        if ($input['malformed']) {
            wp_safe_redirect(add_query_arg(
                ['page' => self::MENU_SLUG, 'cvmtry_error' => 'malformed'],
                self_admin_url('admin.php')
            ));
            exit;
        }

        update_option(Options::WEBHOOK_OPTION_KEY, $input['settings']);

        // Newly added rows were stored with an empty id; mint one for each.
        // Existing ids came through the form untouched and are never
        // regenerated, so a routine save cannot strand state keyed by them.
        Options::ensureEndpointIds();

        // Structured only — a position, a reason code and a sanitized,
        // bounded excerpt. The URL as typed is never stored.
        if ($input['rejected'] !== [] || $input['rejected_pairs'] > 0) {
            set_transient(
                self::REJECTED_TRANSIENT . get_current_user_id(),
                ['endpoints' => $input['rejected'], 'pairs' => $input['rejected_pairs']],
                MINUTE_IN_SECONDS
            );
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'cvmtry_saved' => '1'],
            self_admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Handles the "Discard" link on a pending analytics webhook retry
     * (admin_post_cvmtry_discard_retry).
     *
     * @return never
     */
    public static function handleDiscardRetry(): never
    {
        // 1. Method: the Discard link is followed.
        if (!AdminRequest::isGet()) {
            AdminRequest::deny(__('Retries can only be discarded from the Webhooks screen.', 'convermetry'), 405);
        }

        // 2. Capability: endpoints and their delivery state.
        if (!current_user_can(Capability::required(Capability::WEBHOOKS_MANAGE))) {
            AdminRequest::deny(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_GET['cvmtry_nonce']) || !is_string($_GET['cvmtry_nonce'])) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for discarding a retry.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['cvmtry_nonce'])), self::DISCARD_ACTION)) {
            AdminRequest::deny(AdminRequest::expiredMessage());
        }

        // 5. Input: the retry's key, an md5 of its endpoint URL.
        $key = isset($_GET['cvmtry_retry']) && is_string($_GET['cvmtry_retry'])
            ? sanitize_key(wp_unslash($_GET['cvmtry_retry']))
            : '';

        if (preg_match('/^[a-f0-9]{32}$/', $key) !== 1) {
            AdminRequest::deny(__('That retry could not be found.', 'convermetry'), 400);
        }

        $done = AnalyticsDispatcher::discardRetry($key);

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'cvmtry_retry_discarded' => $done ? '1' : 'busy'],
            self_admin_url('admin.php')
        ));
        exit;
    }

    /**
     * AJAX handler for the per-endpoint test buttons.
     *
     * Sends an analytics-report or form-submission test payload (marked
     * "test": true) to the URL currently typed into the endpoint block —
     * unsaved URLs can be tested, matching the legacy behavior. The URL is
     * validated with the same HTTPS rules as saving, and the request runs
     * through the same safe transport as real deliveries.
     *
     * @return never
     */
    public static function handleTestAjax(): never
    {
        // 1. Method: the test button POSTs.
        if (!AdminRequest::isPost()) {
            AdminRequest::denyAjax(__('Invalid request.', 'convermetry'), 405);
        }

        // 2. Capability: endpoints — a test sends a request to one.
        if (!current_user_can(Capability::required(Capability::WEBHOOKS_MANAGE))) {
            AdminRequest::denyAjax(AdminRequest::forbiddenMessage());
        }

        // 3. Nonce present, as one string.
        if (!isset($_POST['nonce']) || !is_string($_POST['nonce'])) {
            AdminRequest::denyAjax(AdminRequest::expiredMessage());
        }

        // 4. Nonce issued for testing an endpoint.
        if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cvmtry_test_webhook')) {
            AdminRequest::denyAjax(AdminRequest::expiredMessage());
        }

        // 5. Input: which payload, and one URL that passes the same checks a
        // saved endpoint must — before any request is made.
        $type = 'analytics';
        if (isset($_POST['type'])) {
            $type = is_string($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '';
        }

        if ($type !== 'analytics' && $type !== 'form') {
            AdminRequest::denyAjax(__('Invalid request.', 'convermetry'), 400);
        }

        $url = isset($_POST['url']) && is_string($_POST['url'])
            ? trim(esc_url_raw(wp_unslash($_POST['url'])))
            : '';

        /** This filter is documented in WebhooksPage::handleSave(). */
        $allowInsecure = (bool) apply_filters('convermetry_allow_insecure_webhooks', false);

        if ($url === '' || !wp_http_validate_url($url) || (!$allowInsecure && stripos($url, 'https://') !== 0)) {
            AdminRequest::denyAjax(__('Enter a valid HTTPS endpoint URL first.', 'convermetry'), 400);
        }

        $result = $type === 'form'
            ? FormDeliveryQueue::testEndpoint($url)
            : AnalyticsDispatcher::testEndpoint($url);

        wp_send_json_success($result);
    }

    /**
     * Shows the saved / malformed / rejected-endpoint / retry-discarded notices.
     *
     * @return void
     */
    public static function maybeShowNotices(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only: identifies this screen and reads the flags from the redirects after handleSave() and handleDiscardRetry(), which verify their nonce and capability; each flag is compared with fixed values and only selects one of the fixed notices below.
        $page      = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $saved     = isset($_GET['cvmtry_saved']) && sanitize_key(wp_unslash($_GET['cvmtry_saved'])) === '1';
        $error     = isset($_GET['cvmtry_error']) ? sanitize_key(wp_unslash($_GET['cvmtry_error'])) : '';
        $discarded = isset($_GET['cvmtry_retry_discarded']) ? sanitize_key(wp_unslash($_GET['cvmtry_retry_discarded'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ($page !== self::MENU_SLUG) {
            return;
        }

        if ($error === 'malformed') {
            ?>
            <div class="notice notice-error is-dismissible"><p><?php esc_html_e('Webhook settings were not saved: the submitted form was incomplete or malformed, so the stored settings were left unchanged. Reload this page and try again.', 'convermetry'); ?></p></div>
            <?php
        }

        if ($saved) {
            ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Webhook settings saved.', 'convermetry'); ?></p></div>
            <?php

            self::renderRejectedNotices();
        }

        if ($discarded !== '') {
            if ($discarded === 'busy') {
                ?>
                <div class="notice notice-warning is-dismissible"><p><?php esc_html_e('A webhook dispatch run is in progress; the retry was not discarded. Try again in a moment.', 'convermetry'); ?></p></div>
                <?php
            } else {
                ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('The pending retry was discarded. The endpoint\'s next scheduled delivery will cover that window\'s data again under a new delivery id.', 'convermetry'); ?></p></div>
                <?php
            }
        }
    }

    /**
     * One warning per endpoint the last save refused, and one for refused
     * header or query rows, read once from the per-user transient.
     *
     * The transient holds only what handleSave() put there — a position, a
     * reason code and an already sanitized, bounded excerpt — and each part is
     * re-checked here and escaped for its context on output.
     *
     * @return void
     */
    private static function renderRejectedNotices(): void
    {
        $transient = self::REJECTED_TRANSIENT . get_current_user_id();
        $stored    = get_transient($transient);

        if (!is_array($stored)) {
            return;
        }

        delete_transient($transient);

        $endpoints = is_array($stored['endpoints'] ?? null) ? $stored['endpoints'] : [];

        foreach ($endpoints as $entry) {
            if (!is_array($entry) || !is_int($entry['row'] ?? null) || !is_string($entry['display'] ?? null)) {
                continue;
            }

            $message = ($entry['reason'] ?? '') === WebhookSettingsInput::REASON_INSECURE
                /* translators: 1: the endpoint's position in the list, 2: an excerpt of the rejected URL, 3: the name of a PHP filter. */
                ? __('Endpoint %1$d (%2$s) was not saved: endpoints must use HTTPS. (Development setups can allow HTTP via the %3$s filter.)', 'convermetry')
                /* translators: 1: the endpoint's position in the list, 2: an excerpt of the rejected URL, 3: the name of a PHP filter. */
                : __('Endpoint %1$d (%2$s) was not saved: it is not a valid HTTPS URL that this site is allowed to send to. (Development setups can allow HTTP via the %3$s filter.)', 'convermetry');

            ?>
            <div class="notice notice-warning"><p><?php
            echo wp_kses_post(sprintf(
                $message,
                $entry['row'],
                '<code>' . esc_html(mb_substr($entry['display'], 0, WebhookSettingsInput::MAX_DISPLAY_LEN)) . '</code>',
                '<code>convermetry_allow_insecure_webhooks</code>'
            ));
            ?></p></div>
            <?php
        }

        $pairs = is_int($stored['pairs'] ?? null) ? $stored['pairs'] : 0;

        if ($pairs > 0) {
            ?>
            <div class="notice notice-warning"><p><?php
            echo esc_html(sprintf(
                /* translators: %d: number of header or query-parameter rows that were not saved. */
                _n(
                    '%d header or query-parameter row was not saved. A header name may contain only letters, digits, hyphens and a few other punctuation marks (no spaces or colons), and no name or value may contain line breaks or other control characters.',
                    '%d header or query-parameter rows were not saved. A header name may contain only letters, digits, hyphens and a few other punctuation marks (no spaces or colons), and no name or value may contain line breaks or other control characters.',
                    $pairs,
                    'convermetry'
                ),
                $pairs
            ));
            ?></p></div>
            <?php
        }
    }

    /**
     * Renders the Webhooks page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!Capability::currentUserCan(Capability::WEBHOOKS_MANAGE)) {
            return;
        }

        $settings  = Options::webhookAll();
        $endpoints = Options::endpoints();
        if ($endpoints === []) {
            // One blank block so a site with nothing configured still gets a
            // form to fill in. Both delivery types default on, which is what an
            // administrator adding their first endpoint almost always wants.
            $endpoints = [new WebhookEndpoint(url: '', analytics: true, forms: true)];
        }

        $hasAnyUrl = false;
        foreach ($endpoints as $endpoint) {
            if ($endpoint->url !== '') {
                $hasAnyUrl = true;
                break;
            }
        }

        ?>
        <div class="wrap cvmtry-wrap">
        <h1><?php esc_html_e('Convermetry Webhooks', 'convermetry'); ?></h1>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php
        wp_nonce_field(self::SAVE_ACTION, 'cvmtry_webhooks_nonce');
        ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
        <?php

        // ── Webhook Status toggle card ─────────────────────────────────
        ?>
        <div class="cvmtry-card cvmtry-toggle-card" id="cvmtry-webhook-toggle-card"<?php echo ($hasAnyUrl ? '' : ' style="display:none"'); ?>>
        <h2 class="cvmtry-card-title"><?php esc_html_e('Webhook Status', 'convermetry'); ?></h2>
        <div class="cvmtry-toggle-row">
        <label class="cvmtry-toggle" for="cvmtry_webhook_active" aria-label="<?php esc_attr_e('Toggle webhook active state', 'convermetry'); ?>">
        <input type="checkbox" id="cvmtry_webhook_active" name="cvmtry_webhook_active" value="1" <?php echo checked(!empty($settings['active']), true, false); ?>>
        <span class="cvmtry-toggle-slider" aria-hidden="true"></span></label>
        <span class="cvmtry-toggle-label" id="cvmtry-webhook-toggle-label"><?php echo esc_html(!empty($settings['active']) ? __('Active', 'convermetry') : __('Inactive', 'convermetry')); ?></span></div>
        <p class="description"><?php esc_html_e('When inactive, no new deliveries are sent — scheduled analytics reports pause and newly confirmed form submissions wait in the queue. Saved endpoints and settings are preserved.', 'convermetry'); ?></p></div>
        <?php

        // ── Endpoints card ─────────────────────────────────────────────
        ?>
        <div class="cvmtry-card">
        <h2 class="cvmtry-card-title"><?php esc_html_e('Webhook Endpoints', 'convermetry'); ?></h2>
        <p class="description" style="margin-bottom:14px;"><?php echo wp_kses_post(__('Each endpoint chooses which message types it receives: <strong>Analytics Reports</strong> (aggregated analytics on the schedule below) and/or <strong>Form Submissions</strong> (each confirmed lead, delivered immediately in the background). Endpoints must use HTTPS. Add a label so each endpoint is easy to identify in the Activity Log. Failed deliveries retry automatically — 5m, 30m, 2h, 6h, then 16h after the initial attempt.', 'convermetry')); ?></p>
        <div id="cvmtry-webhooks-container">
        <?php
        foreach ($endpoints as $idx => $endpoint) {
            self::renderEndpointBlock(
                $idx,
                $endpoint->url,
                $endpoint->label,
                $endpoint->secret,
                $endpoint->analytics,
                $endpoint->forms,
                $endpoint->id
            );
        }
        ?>
        </div>
        <button type="button" id="cvmtry-add-webhook" class="button" style="margin-top:12px;"><?php esc_html_e('+ Add Endpoint', 'convermetry'); ?></button></div>
        <?php

        // ── Delivery settings card ─────────────────────────────────────
        ?>
        <div class="cvmtry-card">
        <h2 class="cvmtry-card-title"><?php esc_html_e('Delivery Settings', 'convermetry'); ?></h2>
        <table class="form-table" role="presentation">
        <tr><th scope="row"><label for="cvmtry-shared-secret"><?php esc_html_e('Shared signing secret', 'convermetry'); ?> <span class="description"><?php esc_html_e('(optional)', 'convermetry'); ?></span></label></th><td>
        <input type="text" id="cvmtry-shared-secret" class="regular-text code" autocomplete="off" name="cvmtry_shared_secret" value="<?php echo esc_attr((string) $settings['shared_secret']); ?>">
        <p class="description"><?php echo wp_kses_post(__('When set, every webhook request includes an <code>X-Convermetry-Signature</code> header — <code>sha256=&lt;hex&gt;</code>, the HMAC-SHA256 of the raw JSON body keyed with this secret — so receivers can verify payloads genuinely came from this site. An endpoint block\'s own signing secret overrides this shared one for that endpoint, so one receiver never learns the key that signs payloads for the others.', 'convermetry')); ?></p></td></tr>
        <tr><th scope="row"><label for="cvmtry-interval"><?php esc_html_e('Analytics send interval', 'convermetry'); ?></label></th><td>
        <select id="cvmtry-interval" name="cvmtry_interval">
        <?php
        $intervalLabels = [
            'hourly'     => __('Hourly', 'convermetry'),
            'twicedaily' => __('Twice daily', 'convermetry'),
            'daily'      => __('Daily', 'convermetry'),
            'weekly'     => __('Weekly', 'convermetry'),
        ];
        foreach ($intervalLabels as $value => $label) {
            ?>
            <option value="<?php echo esc_attr($value); ?>" <?php echo selected($settings['interval'], $value, false); ?>><?php echo esc_html($label); ?></option>
            <?php
        }
        ?>
        </select>
        <p class="description"><?php esc_html_e('Applies to Analytics Reports only — form submissions always deliver immediately. Each site delivers at a random, stable time within the interval (scattered up to 24 hours), so many sites sharing an endpoint don\'t all send at the same moment.', 'convermetry'); ?>
        <?php

        $next = wp_next_scheduled(AnalyticsDispatcher::CRON_HOOK);
        if ($next !== false) {
            echo ' ' . wp_kses_post(sprintf(
                $next > time()
                    /* translators: 1: UTC date and time of the next analytics send, 2: human-readable time until then, such as "5 mins". */
                    ? __('Next scheduled send: <strong>%1$s UTC</strong> (in %2$s).', 'convermetry')
                    /* translators: %1$s: UTC date and time of the next analytics send. */
                    : __('Next scheduled send: <strong>%1$s UTC</strong> (as soon as WP-Cron next runs).', 'convermetry'),
                esc_html(gmdate('Y-m-d H:i', (int) $next)),
                esc_html(human_time_diff(time(), (int) $next))
            ));
        }

        /* The close tag hugs </p>: on its own line it would emit a newline
           between the sentence above and the closing tag. */
        ?></p></td></tr>
        <tr><th scope="row"><?php esc_html_e('History backfill', 'convermetry'); ?></th><td>
        <label><input type="checkbox" name="cvmtry_backfill" value="1" <?php echo checked(!empty($settings['backfill']), true, false); ?>>
        <?php esc_html_e('Send retained history to new analytics endpoints', 'convermetry'); ?></label>
        <p class="description"><?php esc_html_e('When enabled, an endpoint that has never received an analytics delivery starts from the beginning of the retention window instead of one send interval ago. History is delivered in interval-sized windows (up to 10 per scheduled run), so a long backlog is worked off over a few runs.', 'convermetry'); ?></p></td></tr>
        <tr><th scope="row"><?php esc_html_e('Form delivery failure mode', 'convermetry'); ?></th><td>
        <label style="display:block;margin-bottom:6px;"><input type="radio" name="cvmtry_failure_mode" value="background" <?php echo checked($settings['failure_mode'] !== 'show_error', true, false); ?>>
        <?php echo wp_kses_post(__('<strong>Retry in background</strong> (recommended) — the visitor always sees the form\'s normal success state; failed deliveries retry automatically.', 'convermetry')); ?></label>
        <label style="display:block;"><input type="radio" name="cvmtry_failure_mode" value="show_error" <?php echo checked($settings['failure_mode'] === 'show_error', true, false); ?>>
        <?php echo wp_kses_post(__('<strong>Show error to visitor</strong> — delivery runs during the submission and a failure is reported back to the form (supported for Elementor Pro and Bricks Builder forms; every other provider always uses background delivery). Only a genuinely failed delivery is reported: an excluded form, a submission a filter declined, and a site with no endpoints configured all stay silent. Whether the form then <em>displays</em> an error is the builder\'s own decision — see the README for what each one does with a failed action.', 'convermetry')); ?></label></td></tr></table></div>
        <?php

        // ── Request customization card ─────────────────────────────────
        ?>
        <div class="cvmtry-card">
        <h2 class="cvmtry-card-title"><?php esc_html_e('Request Customization', 'convermetry'); ?></h2>
        <p class="description" style="margin-bottom:14px;"><?php esc_html_e('Headers and URL query parameters added to every webhook request. Per-form headers and parameters (configured on the Forms page) are merged after these; when page URL parameters are included, the precedence is: global parameters → page parameters → per-form parameters. Header values that look like credentials are redacted in the Activity Log but sent intact.', 'convermetry'); ?></p>
        <h3><?php esc_html_e('Global Request Headers', 'convermetry'); ?></h3>
        <?php
        self::renderKvBuilder('cvmtry_global_headers', Options::globalHeaders(), __('e.g. Authorization', 'convermetry'));

        ?>
        <h3><?php esc_html_e('Global URL Query Parameters', 'convermetry'); ?></h3>
        <?php
        self::renderKvBuilder('cvmtry_global_query', Options::globalQueryParams(), __('e.g. source', 'convermetry'));

        ?>
        <p style="margin-top:12px;"><label><input type="checkbox" name="cvmtry_include_page_params" value="1" <?php echo checked(!empty($settings['include_page_params']), true, false); ?>>
        <?php echo wp_kses_post(__('Include page URL parameters — query parameters present on the page a form was submitted from (e.g. <code>?utm_source=google&amp;gclid=…</code>) are appended to the webhook URL for that submission.', 'convermetry')); ?></label></p></div>
        <?php

        submit_button(__('Save Webhook Settings', 'convermetry'));
        ?>
        </form>
        <?php

        self::renderPendingRetries();
        self::renderPendingQueue();

        $logUrl = add_query_arg(['page' => ActivityLogPage::MENU_SLUG], self_admin_url('admin.php'));
        ?>
        <h2><?php esc_html_e('Activity Log', 'convermetry'); ?></h2>
        <p><?php
        echo wp_kses_post(sprintf(
            /* translators: %s: URL of the Activity Log screen. */
            __('Every delivery attempt — analytics report or form submission, scheduled, immediate, retry, or test — is recorded with its payload and response (sensitive values redacted) on the <a href="%s">Activity Log</a> page.', 'convermetry'),
            esc_url($logUrl)
        ));
        ?></p></div>
        <?php
    }

    /**
     * Renders one endpoint block of the repeater.
     *
     * @param int    $index     Zero-based position in the repeater.
     * @param string $url       Saved endpoint URL, or '' for an empty block.
     * @param string $label     Saved label.
     * @param string $secret    Saved per-endpoint secret.
     * @param bool   $analytics Whether the endpoint receives analytics reports.
     * @param bool   $forms     Whether the endpoint receives form submissions.
     * @param string $id        Durable endpoint id, or '' for a new row.
     * @return void
     */
    private static function renderEndpointBlock(int $index, string $url, string $label, string $secret, bool $analytics, bool $forms, string $id = ''): void
    {
        ?>
        <div class="cvmtry-webhook-block" data-webhook-index="<?php echo esc_attr((string) $index); ?>">
        <?php
        // The durable endpoint id rides along with the row. Without it a save
        // rebuilds the endpoint list from POST alone and every id is lost,
        // orphaning the delivery window, retry chain and per-endpoint secret
        // that are keyed by it. Rows added in the browser post no id and are
        // assigned one by Options::ensureEndpointIds() after the save.
        ?>
        <input type="hidden" class="cvmtry-webhook-id-input" name="cvmtry_webhooks[<?php echo esc_attr((string) $index); ?>][id]" value="<?php echo esc_attr($id); ?>">
        <div class="cvmtry-webhook-block-header">
        <strong class="cvmtry-webhook-block-title"><?php
        echo esc_html(sprintf(
            /* translators: %d: the endpoint's position in the list. */
            __('Endpoint %d', 'convermetry'),
            $index + 1
        ));
        ?></strong>
        <?php
        if ($index > 0) {
            ?>
            <button type="button" class="button cvmtry-remove-webhook-btn" aria-label="<?php
            echo esc_attr(sprintf(
                /* translators: %d: the endpoint's position in the list. */
                __('Remove endpoint %d', 'convermetry'),
                $index + 1
            ));
            ?>"><?php esc_html_e('Remove', 'convermetry'); ?></button>
            <?php
        }
        ?>
        </div>
        <div class="cvmtry-webhook-url-row">
        <input type="url" class="cvmtry-webhook-url-input regular-text code" name="cvmtry_webhooks[<?php echo esc_attr((string) $index); ?>][url]" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com/convermetry-hook" aria-label="<?php
        /* translators: %d: the endpoint's position in the list. */
        echo esc_attr(sprintf(__('Endpoint %d URL', 'convermetry'), $index + 1));
        ?>"></div>
        <div class="cvmtry-webhook-field">
        <input type="text" class="regular-text cvmtry-webhook-label-input" name="cvmtry_webhooks[<?php echo esc_attr((string) $index); ?>][label]" value="<?php echo esc_attr($label); ?>" placeholder="<?php esc_attr_e('Label (optional — shown in the Activity Log)', 'convermetry'); ?>" aria-label="<?php
        /* translators: %d: the endpoint's position in the list. */
        echo esc_attr(sprintf(__('Endpoint %d label', 'convermetry'), $index + 1));
        ?>"></div>
        <div class="cvmtry-webhook-field">
        <input type="text" class="regular-text code cvmtry-webhook-secret-input" autocomplete="off" name="cvmtry_webhooks[<?php echo esc_attr((string) $index); ?>][secret]" value="<?php echo esc_attr($secret); ?>" placeholder="<?php esc_attr_e('Signing secret (optional — overrides the shared secret)', 'convermetry'); ?>" aria-label="<?php
        /* translators: %d: the endpoint's position in the list. */
        echo esc_attr(sprintf(__('Endpoint %d signing secret', 'convermetry'), $index + 1));
        ?>"></div>
        <fieldset class="cvmtry-webhook-types">
        <legend class="screen-reader-text"><?php
        /* translators: %d: the endpoint's position in the list. */
        echo esc_html(sprintf(__('Delivery types for endpoint %d', 'convermetry'), $index + 1));
        ?></legend>
        <label><input type="checkbox" name="cvmtry_webhooks[<?php echo esc_attr((string) $index); ?>][analytics]" value="1" <?php echo checked($analytics, true, false); ?>>
        <?php esc_html_e('Analytics Reports', 'convermetry'); ?></label> 
        <label><input type="checkbox" name="cvmtry_webhooks[<?php echo esc_attr((string) $index); ?>][forms]" value="1" <?php echo checked($forms, true, false); ?>>
        <?php esc_html_e('Form Submissions', 'convermetry'); ?></label></fieldset>
        <div class="cvmtry-endpoint-tests">
        <button type="button" class="button cvmtry-test-endpoint" data-type="analytics"><?php esc_html_e('Send analytics test', 'convermetry'); ?></button> 
        <button type="button" class="button cvmtry-test-endpoint" data-type="form"><?php esc_html_e('Send form test', 'convermetry'); ?></button>
        <span class="cvmtry-test-result" role="status" aria-live="polite"></span></div></div>
        <?php
    }

    /**
     * Renders one key/value builder (rows plus an Add button).
     *
     * @param string                                          $name        Field name prefix.
     * @param array<int, array{key?: string, value?: string}> $pairs       Saved pairs.
     * @param string                                          $placeholder Key placeholder hint.
     * @return void
     */
    private static function renderKvBuilder(string $name, array $pairs, string $placeholder): void
    {
        ?>
        <div class="cvmtry-kv-builder" data-kv-name="<?php echo esc_attr($name); ?>" data-kv-next="<?php echo esc_attr((string) count($pairs)); ?>">
        <div class="cvmtry-kv-rows">
        <?php

        foreach ($pairs as $index => $pair) {
            ?>
            <div class="cvmtry-kv-row">
            <input type="text" class="regular-text code cvmtry-kv-key" name="<?php echo esc_attr($name . '[' . $index . '][key]'); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" value="<?php echo esc_attr((string) ($pair['key'] ?? '')); ?>">
            <input type="text" class="regular-text code cvmtry-kv-value" name="<?php echo esc_attr($name . '[' . $index . '][value]'); ?>" placeholder="<?php esc_attr_e('Value', 'convermetry'); ?>" value="<?php echo esc_attr((string) ($pair['value'] ?? '')); ?>">
            <button type="button" class="button cvmtry-kv-remove" aria-label="<?php esc_attr_e('Remove this row', 'convermetry'); ?>"><?php esc_html_e('Remove', 'convermetry'); ?></button></div>
            <?php
        }

        ?>
        </div>
        <button type="button" class="button cvmtry-kv-add"><?php esc_html_e('+ Add', 'convermetry'); ?></button></div>
        <?php
    }

    /**
     * Renders a warning listing analytics deliveries currently waiting on a
     * retry. Outputs nothing when no retries are pending (the normal state).
     *
     * @return void
     */
    private static function renderPendingRetries(): void
    {
        $pending = AnalyticsDispatcher::getPendingRetries();
        if ($pending === []) {
            return;
        }

        $max = AnalyticsDispatcher::maxRetries();

        ?>
        <div class="notice notice-warning inline"><p><strong><?php esc_html_e('Pending analytics delivery retries', 'convermetry'); ?></strong></p><ul style="margin-left:1.5em;list-style:disc;">
        <?php

        foreach ($pending as $retry) {
            $when = (int) ($retry['scheduled_for'] ?? 0);
            $url  = (string) ($retry['url'] ?? '');

            if (!empty($retry['exhausted'])) {
                /* translators: 1: attempt number, 2: maximum attempts, 3: endpoint URL, 4: URL that discards the retry. */
                $line = __('Retry %1$d of %2$d to %3$s — next attempt with the next scheduled send (retry chain exhausted; the frozen payload is kept and re-sent first). <a href="%4$s">Discard this retry</a>', 'convermetry');
            } elseif ($when > time()) {
                /* translators: 1: attempt number, 2: maximum attempts, 3: endpoint URL, 4: URL that discards the retry, 5: human-readable time until the next attempt, such as "5 mins". */
                $line = __('Retry %1$d of %2$d to %3$s — next attempt in %5$s. <a href="%4$s">Discard this retry</a>', 'convermetry');
            } else {
                /* translators: 1: attempt number, 2: maximum attempts, 3: endpoint URL, 4: URL that discards the retry. */
                $line = __('Retry %1$d of %2$d to %3$s — next attempt as soon as WP-Cron next runs. <a href="%4$s">Discard this retry</a>', 'convermetry');
            }

            $discardUrl = add_query_arg(
                [
                    'action'       => self::DISCARD_ACTION,
                    'cvmtry_retry' => md5($url),
                    'cvmtry_nonce' => wp_create_nonce(self::DISCARD_ACTION),
                ],
                admin_url('admin-post.php')
            );

            echo '<li>' . wp_kses_post(sprintf(
                $line,
                (int) ($retry['attempt'] ?? 1),
                (int) $max,
                '<code>' . esc_html($url) . '</code>',
                esc_url($discardUrl),
                esc_html(human_time_diff(time(), max(time(), $when)))
            )) . '</li>';
        }

        ?>
        </ul>
        <p class="description"><?php esc_html_e('Discarding a retry drops its frozen payload; the data itself is not lost — the endpoint\'s next scheduled delivery covers that window again under a new delivery id (a receiver that already processed the frozen delivery would then see that data twice). Frozen retries older than the retention window are discarded automatically.', 'convermetry'); ?></p></div>
        <?php
    }

    /**
     * Renders the pending form-delivery queue (deliveries waiting for their
     * first attempt or a retry). Outputs nothing when the queue is empty.
     *
     * @return void
     */
    private static function renderPendingQueue(): void
    {
        $count = FormDeliveryQueue::pendingCount();
        if ($count === 0) {
            return;
        }

        $rows = FormDeliveryQueue::pendingRows(10);
        $max  = AnalyticsDispatcher::maxRetries() + 1;

        ?>
        <div class="notice notice-info inline"><p><?php
        echo wp_kses_post(sprintf(
            /* translators: %s: number of queued form-submission deliveries. */
            _n(
                '<strong>%s pending form-submission delivery</strong> waiting in the background queue.',
                '<strong>%s pending form-submission deliveries</strong> waiting in the background queue.',
                $count,
                'convermetry'
            ),
            esc_html(number_format_i18n($count))
        ));
        ?></p>
        <ul style="margin-left:1.5em;list-style:disc;">
        <?php

        foreach ($rows as $row) {
            $due = (int) strtotime((string) $row['next_attempt_at'] . ' UTC');
            $line = $due > time()
                /* translators: 1: submission id, 2: endpoint URL, 3: attempt number, 4: maximum attempts, 5: human-readable time until the next attempt, such as "5 mins". */
                ? __('Submission %1$s → %2$s — attempt %3$d of %4$d, next in %5$s.', 'convermetry')
                /* translators: 1: submission id, 2: endpoint URL, 3: attempt number, 4: maximum attempts. */
                : __('Submission %1$s → %2$s — attempt %3$d of %4$d, next as soon as WP-Cron next runs.', 'convermetry');

            echo '<li>' . wp_kses_post(sprintf(
                $line,
                '<code>' . esc_html((string) $row['submission_id']) . '</code>',
                '<code>' . esc_html((string) $row['endpoint_url']) . '</code>',
                (int) $row['attempt'] + 1,
                (int) $max,
                esc_html(human_time_diff(time(), max(time(), $due)))
            )) . '</li>';
        }

        ?>
        </ul></div>
        <?php
    }
}
