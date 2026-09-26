<?php
declare(strict_types=1);

namespace Convermetry\Admin\Pages;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\Capability;
use Convermetry\Settings\Options;
use Convermetry\Settings\WebhookEndpoint;
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
    private const string SAVE_ACTION = 'cvm_save_webhooks';

    /** Admin action name for discarding one pending analytics retry. */
    private const string DISCARD_ACTION = 'cvm_discard_retry';

    /**
     * Registers menu, save, discard, notice, asset, and AJAX hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_post_' . self::SAVE_ACTION, [self::class, 'handleSave']);
        add_action('admin_init', [self::class, 'handleDiscardRetry']);
        add_action('admin_notices', [self::class, 'maybeShowNotices']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('wp_ajax_cvm_test_webhook', [self::class, 'handleTestAjax']);
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
            'cvm-webhooks',
            CVM_PLUGIN_URL . 'assets/css/admin-webhooks.css',
            [AdminAssets::COMMON_HANDLE],
            CVM_VERSION
        );

        wp_enqueue_script(
            'cvm-admin',
            CVM_PLUGIN_URL . 'assets/js/admin.js',
            ['wp-i18n'],
            CVM_VERSION,
            true
        );
        wp_set_script_translations('cvm-admin', 'convermetry');

        wp_localize_script('cvm-admin', 'CVM_ADMIN', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'testNonce' => wp_create_nonce('cvm_test_webhook'),
        ]);
    }

    /**
     * Validates and persists the webhook settings POST.
     *
     * @return void
     */
    public static function handleSave(): void
    {
        if (
            !Capability::currentUserCan(Capability::WEBHOOKS_MANAGE)
            || !isset($_POST['cvm_webhooks_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvm_webhooks_nonce'])), self::SAVE_ACTION)
        ) {
            wp_die(esc_html__('Invalid request.', 'convermetry'), '', ['response' => 403]);
        }

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
        $schemes       = $allowInsecure ? ['http', 'https'] : ['https'];

        $rejected  = [];
        $endpoints = [];
        $seen      = [];

        // Only ids that are ALREADY configured may be carried through a save.
        // A posted id that matches nothing is discarded and the row is treated
        // as new, so a hand-crafted POST cannot graft one endpoint's identity
        // (and therefore its signing secret and retry chain) onto another.
        $knownIds = [];
        foreach (Options::endpoints() as $configured) {
            if ($configured->id !== '') {
                $knownIds[$configured->id] = true;
            }
        }

        $claimedIds = [];

        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is validated or sanitized in the loop below.
        $rawEndpoints = isset($_POST['cvm_webhooks']) && is_array($_POST['cvm_webhooks'])
            ? wp_unslash($_POST['cvm_webhooks'])
            : [];
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

        foreach ($rawEndpoints as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $rawUrl = trim((string) ($entry['url'] ?? ''));
            if ($rawUrl === '') {
                continue;
            }

            if (!$allowInsecure && stripos($rawUrl, 'http://') === 0) {
                $rejected[] = $rawUrl;
                continue;
            }

            $url = esc_url_raw($rawUrl, $schemes);
            if ($url === '' || !wp_http_validate_url($url) || isset($seen[$url])) {
                if ($url === '' || !wp_http_validate_url($url)) {
                    $rejected[] = $rawUrl;
                }
                continue;
            }

            $postedId = trim((string) ($entry['id'] ?? ''));
            $id       = ($postedId !== '' && isset($knownIds[$postedId]) && !isset($claimedIds[$postedId]))
                ? $postedId
                : '';

            if ($id !== '') {
                $claimedIds[$id] = true;
            }

            $seen[$url]  = true;
            $endpoints[] = [
                'id'        => $id,
                'url'       => $url,
                'label'     => mb_substr(sanitize_text_field((string) ($entry['label'] ?? '')), 0, 100),
                'secret'    => mb_substr(sanitize_text_field((string) ($entry['secret'] ?? '')), 0, 190),
                'analytics' => !empty($entry['analytics']),
                'forms'     => !empty($entry['forms']),
            ];
        }

        $interval = sanitize_key((string) ($_POST['cvm_interval'] ?? 'daily'));

        $settings = [
            'active'              => !empty($_POST['cvm_webhook_active']) && $endpoints !== [],
            'endpoints'           => $endpoints,
            'interval'            => in_array($interval, Options::INTERVALS, true) ? $interval : 'daily',
            'shared_secret'       => mb_substr(sanitize_text_field(wp_unslash($_POST['cvm_shared_secret'] ?? '')), 0, 190),
            'backfill'            => !empty($_POST['cvm_backfill']),
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitizePairs() unslashes and sanitizes every key and value.
            'global_headers'      => self::sanitizePairs($_POST['cvm_global_headers'] ?? null),
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- sanitizePairs() unslashes and sanitizes every key and value.
            'global_query'        => self::sanitizePairs($_POST['cvm_global_query'] ?? null),
            'include_page_params' => !empty($_POST['cvm_include_page_params']),
            'failure_mode'        => sanitize_key(wp_unslash($_POST['cvm_failure_mode'] ?? '')) === 'show_error' ? 'show_error' : 'background',
        ];

        update_option(Options::WEBHOOK_OPTION_KEY, $settings);

        // Newly added rows were stored with an empty id; mint one for each.
        // Existing ids came through the form untouched and are never
        // regenerated, so a routine save cannot strand state keyed by them.
        Options::ensureEndpointIds();

        if ($rejected !== []) {
            set_transient('cvm_webhook_rejected_' . get_current_user_id(), $rejected, MINUTE_IN_SECONDS);
        }

        wp_safe_redirect(add_query_arg(
            ['page' => self::MENU_SLUG, 'cvm_saved' => '1'],
            self_admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Sanitizes a posted key/value pair list.
     *
     * @param mixed $raw Raw POST value.
     * @return array<int, array{key: string, value: string}>
     */
    private static function sanitizePairs(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach (wp_unslash($raw) as $pair) {
            if (!is_array($pair)) {
                continue;
            }

            $key = sanitize_text_field((string) ($pair['key'] ?? ''));
            if ($key === '') {
                continue;
            }

            $out[] = [
                'key'   => $key,
                'value' => sanitize_text_field((string) ($pair['value'] ?? '')),
            ];
        }

        return $out;
    }

    /**
     * Handles the "Discard" action on a pending analytics webhook retry.
     *
     * @return void
     */
    public static function handleDiscardRetry(): void
    {
        if (
            empty($_GET['action']) ||
            $_GET['action'] !== self::DISCARD_ACTION ||
            empty($_GET['cvm_retry']) ||
            empty($_GET['cvm_nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['cvm_nonce'])), self::DISCARD_ACTION) ||
            !Capability::currentUserCan(Capability::WEBHOOKS_MANAGE)
        ) {
            return;
        }

        $key  = sanitize_key(wp_unslash($_GET['cvm_retry']));
        $done = AnalyticsDispatcher::discardRetry($key);

        wp_safe_redirect(self_admin_url(
            'admin.php?page=' . self::MENU_SLUG . '&cvm_retry_discarded=' . ($done ? '1' : 'busy')
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
        if (
            !isset($_POST['nonce']) ||
            !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'cvm_test_webhook') ||
            !Capability::currentUserCan(Capability::WEBHOOKS_MANAGE)
        ) {
            wp_send_json_error(['message' => __('Unauthorized.', 'convermetry')]);
        }

        $rawUrl = isset($_POST['url']) && is_string($_POST['url'])
            ? trim(esc_url_raw(wp_unslash($_POST['url'])))
            : '';
        $type   = sanitize_key((string) ($_POST['type'] ?? 'analytics'));

        $allowInsecure = (bool) apply_filters('convermetry_allow_insecure_webhooks', false);

        if (
            $rawUrl === ''
            || !wp_http_validate_url($rawUrl)
            || (!$allowInsecure && stripos($rawUrl, 'https://') !== 0)
        ) {
            wp_send_json_error(['message' => __('Enter a valid HTTPS endpoint URL first.', 'convermetry')]);
        }

        $result = $type === 'form'
            ? FormDeliveryQueue::testEndpoint($rawUrl)
            : AnalyticsDispatcher::testEndpoint($rawUrl);

        wp_send_json_success($result);
    }

    /**
     * Shows the saved / rejected-endpoint / retry-discarded notices.
     *
     * @return void
     */
    public static function maybeShowNotices(): void
    {
        if (!isset($_GET['page']) || $_GET['page'] !== self::MENU_SLUG) {
            return;
        }

        if (!empty($_GET['cvm_saved'])) {
            ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Webhook settings saved.', 'convermetry'); ?></p></div>
            <?php

            $rejected = get_transient('cvm_webhook_rejected_' . get_current_user_id());
            if (is_array($rejected) && $rejected !== []) {
                delete_transient('cvm_webhook_rejected_' . get_current_user_id());
                foreach ($rejected as $url) {
                    ?>
                    <div class="notice notice-warning"><p><?php
                    echo wp_kses_post(sprintf(
                        /* translators: 1: the rejected endpoint URL, 2: the name of a PHP filter. */
                        __('Endpoint %1$s was not saved: endpoints must be valid HTTPS URLs. (Development setups can allow HTTP via the %2$s filter.)', 'convermetry'),
                        '<code>' . esc_html((string) $url) . '</code>',
                        '<code>convermetry_allow_insecure_webhooks</code>'
                    ));
                    ?></p></div>
                    <?php
                }
            }
        }

        if (!empty($_GET['cvm_retry_discarded'])) {
            if ($_GET['cvm_retry_discarded'] === 'busy') {
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
        <div class="wrap cvm-wrap">
        <h1><?php esc_html_e('Convermetry Webhooks', 'convermetry'); ?></h1>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php
        wp_nonce_field(self::SAVE_ACTION, 'cvm_webhooks_nonce');
        ?>
        <input type="hidden" name="action" value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
        <?php

        // ── Webhook Status toggle card ─────────────────────────────────
        ?>
        <div class="cvm-card cvm-toggle-card" id="cvm-webhook-toggle-card"<?php echo ($hasAnyUrl ? '' : ' style="display:none"'); ?>>
        <h2 class="cvm-card-title"><?php esc_html_e('Webhook Status', 'convermetry'); ?></h2>
        <div class="cvm-toggle-row">
        <label class="cvm-toggle" for="cvm_webhook_active" aria-label="<?php esc_attr_e('Toggle webhook active state', 'convermetry'); ?>">
        <input type="checkbox" id="cvm_webhook_active" name="cvm_webhook_active" value="1" <?php echo checked(!empty($settings['active']), true, false); ?>>
        <span class="cvm-toggle-slider" aria-hidden="true"></span></label>
        <span class="cvm-toggle-label" id="cvm-webhook-toggle-label"><?php echo esc_html(!empty($settings['active']) ? __('Active', 'convermetry') : __('Inactive', 'convermetry')); ?></span></div>
        <p class="description"><?php esc_html_e('When inactive, no new deliveries are sent — scheduled analytics reports pause and newly confirmed form submissions wait in the queue. Saved endpoints and settings are preserved.', 'convermetry'); ?></p></div>
        <?php

        // ── Endpoints card ─────────────────────────────────────────────
        ?>
        <div class="cvm-card">
        <h2 class="cvm-card-title"><?php esc_html_e('Webhook Endpoints', 'convermetry'); ?></h2>
        <p class="description" style="margin-bottom:14px;"><?php echo wp_kses_post(__('Each endpoint chooses which message types it receives: <strong>Analytics Reports</strong> (aggregated analytics on the schedule below) and/or <strong>Form Submissions</strong> (each confirmed lead, delivered immediately in the background). Endpoints must use HTTPS. Add a label so each endpoint is easy to identify in the Activity Log. Failed deliveries retry automatically — 5m, 30m, 2h, 6h, then 16h after the initial attempt.', 'convermetry')); ?></p>
        <div id="cvm-webhooks-container">
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
        <button type="button" id="cvm-add-webhook" class="button" style="margin-top:12px;"><?php esc_html_e('+ Add Endpoint', 'convermetry'); ?></button></div>
        <?php

        // ── Delivery settings card ─────────────────────────────────────
        ?>
        <div class="cvm-card">
        <h2 class="cvm-card-title"><?php esc_html_e('Delivery Settings', 'convermetry'); ?></h2>
        <table class="form-table" role="presentation">
        <tr><th scope="row"><label for="cvm-shared-secret"><?php esc_html_e('Shared signing secret', 'convermetry'); ?> <span class="description"><?php esc_html_e('(optional)', 'convermetry'); ?></span></label></th><td>
        <input type="text" id="cvm-shared-secret" class="regular-text code" autocomplete="off" name="cvm_shared_secret" value="<?php echo esc_attr((string) $settings['shared_secret']); ?>">
        <p class="description"><?php echo wp_kses_post(__('When set, every webhook request includes an <code>X-Convermetry-Signature</code> header — <code>sha256=&lt;hex&gt;</code>, the HMAC-SHA256 of the raw JSON body keyed with this secret — so receivers can verify payloads genuinely came from this site. An endpoint block\'s own signing secret overrides this shared one for that endpoint, so one receiver never learns the key that signs payloads for the others.', 'convermetry')); ?></p></td></tr>
        <tr><th scope="row"><label for="cvm-interval"><?php esc_html_e('Analytics send interval', 'convermetry'); ?></label></th><td>
        <select id="cvm-interval" name="cvm_interval">
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
        <label><input type="checkbox" name="cvm_backfill" value="1" <?php echo checked(!empty($settings['backfill']), true, false); ?>>
        <?php esc_html_e('Send retained history to new analytics endpoints', 'convermetry'); ?></label>
        <p class="description"><?php esc_html_e('When enabled, an endpoint that has never received an analytics delivery starts from the beginning of the retention window instead of one send interval ago. History is delivered in interval-sized windows (up to 10 per scheduled run), so a long backlog is worked off over a few runs.', 'convermetry'); ?></p></td></tr>
        <tr><th scope="row"><?php esc_html_e('Form delivery failure mode', 'convermetry'); ?></th><td>
        <label style="display:block;margin-bottom:6px;"><input type="radio" name="cvm_failure_mode" value="background" <?php echo checked($settings['failure_mode'] !== 'show_error', true, false); ?>>
        <?php echo wp_kses_post(__('<strong>Retry in background</strong> (recommended) — the visitor always sees the form\'s normal success state; failed deliveries retry automatically.', 'convermetry')); ?></label>
        <label style="display:block;"><input type="radio" name="cvm_failure_mode" value="show_error" <?php echo checked($settings['failure_mode'] === 'show_error', true, false); ?>>
        <?php echo wp_kses_post(__('<strong>Show error to visitor</strong> — delivery runs during the submission and a failure is reported back to the form (supported for Elementor Pro and Bricks Builder forms; every other provider always uses background delivery). Only a genuinely failed delivery is reported: an excluded form, a submission a filter declined, and a site with no endpoints configured all stay silent. Whether the form then <em>displays</em> an error is the builder\'s own decision — see the README for what each one does with a failed action.', 'convermetry')); ?></label></td></tr></table></div>
        <?php

        // ── Request customization card ─────────────────────────────────
        ?>
        <div class="cvm-card">
        <h2 class="cvm-card-title"><?php esc_html_e('Request Customization', 'convermetry'); ?></h2>
        <p class="description" style="margin-bottom:14px;"><?php esc_html_e('Headers and URL query parameters added to every webhook request. Per-form headers and parameters (configured on the Forms page) are merged after these; when page URL parameters are included, the precedence is: global parameters → page parameters → per-form parameters. Header values that look like credentials are redacted in the Activity Log but sent intact.', 'convermetry'); ?></p>
        <h3><?php esc_html_e('Global Request Headers', 'convermetry'); ?></h3>
        <?php
        self::renderKvBuilder('cvm_global_headers', Options::globalHeaders(), __('e.g. Authorization', 'convermetry'));

        ?>
        <h3><?php esc_html_e('Global URL Query Parameters', 'convermetry'); ?></h3>
        <?php
        self::renderKvBuilder('cvm_global_query', Options::globalQueryParams(), __('e.g. source', 'convermetry'));

        ?>
        <p style="margin-top:12px;"><label><input type="checkbox" name="cvm_include_page_params" value="1" <?php echo checked(!empty($settings['include_page_params']), true, false); ?>>
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
        <div class="cvm-webhook-block" data-webhook-index="<?php echo esc_attr((string) $index); ?>">
        <?php
        // The durable endpoint id rides along with the row. Without it a save
        // rebuilds the endpoint list from POST alone and every id is lost,
        // orphaning the delivery window, retry chain and per-endpoint secret
        // that are keyed by it. Rows added in the browser post no id and are
        // assigned one by Options::ensureEndpointIds() after the save.
        ?>
        <input type="hidden" name="cvm_webhooks[<?php echo esc_attr((string) $index); ?>][id]" value="<?php echo esc_attr($id); ?>">
        <div class="cvm-webhook-block-header">
        <strong class="cvm-webhook-block-title"><?php
        echo esc_html(sprintf(
            /* translators: %d: the endpoint's position in the list. */
            __('Endpoint %d', 'convermetry'),
            $index + 1
        ));
        ?></strong>
        <?php
        if ($index > 0) {
            ?>
            <button type="button" class="button cvm-remove-webhook-btn" aria-label="<?php
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
        <div class="cvm-webhook-url-row">
        <input type="url" class="cvm-webhook-url-input regular-text code" name="cvm_webhooks[<?php echo esc_attr((string) $index); ?>][url]" value="<?php echo esc_attr($url); ?>" placeholder="https://example.com/convermetry-hook" aria-label="<?php
        /* translators: %d: the endpoint's position in the list. */
        echo esc_attr(sprintf(__('Endpoint %d URL', 'convermetry'), $index + 1));
        ?>"></div>
        <div class="cvm-webhook-field">
        <input type="text" class="regular-text cvm-webhook-label-input" name="cvm_webhooks[<?php echo esc_attr((string) $index); ?>][label]" value="<?php echo esc_attr($label); ?>" placeholder="<?php esc_attr_e('Label (optional — shown in the Activity Log)', 'convermetry'); ?>" aria-label="<?php
        /* translators: %d: the endpoint's position in the list. */
        echo esc_attr(sprintf(__('Endpoint %d label', 'convermetry'), $index + 1));
        ?>"></div>
        <div class="cvm-webhook-field">
        <input type="text" class="regular-text code cvm-webhook-secret-input" autocomplete="off" name="cvm_webhooks[<?php echo esc_attr((string) $index); ?>][secret]" value="<?php echo esc_attr($secret); ?>" placeholder="<?php esc_attr_e('Signing secret (optional — overrides the shared secret)', 'convermetry'); ?>" aria-label="<?php
        /* translators: %d: the endpoint's position in the list. */
        echo esc_attr(sprintf(__('Endpoint %d signing secret', 'convermetry'), $index + 1));
        ?>"></div>
        <fieldset class="cvm-webhook-types">
        <legend class="screen-reader-text"><?php
        /* translators: %d: the endpoint's position in the list. */
        echo esc_html(sprintf(__('Delivery types for endpoint %d', 'convermetry'), $index + 1));
        ?></legend>
        <label><input type="checkbox" name="cvm_webhooks[<?php echo esc_attr((string) $index); ?>][analytics]" value="1" <?php echo checked($analytics, true, false); ?>>
        <?php esc_html_e('Analytics Reports', 'convermetry'); ?></label> 
        <label><input type="checkbox" name="cvm_webhooks[<?php echo esc_attr((string) $index); ?>][forms]" value="1" <?php echo checked($forms, true, false); ?>>
        <?php esc_html_e('Form Submissions', 'convermetry'); ?></label></fieldset>
        <div class="cvm-endpoint-tests">
        <button type="button" class="button cvm-test-endpoint" data-type="analytics"><?php esc_html_e('Send analytics test', 'convermetry'); ?></button> 
        <button type="button" class="button cvm-test-endpoint" data-type="form"><?php esc_html_e('Send form test', 'convermetry'); ?></button>
        <span class="cvm-test-result" role="status" aria-live="polite"></span></div></div>
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
        <div class="cvm-kv-builder" data-kv-name="<?php echo esc_attr($name); ?>" data-kv-next="<?php echo esc_attr((string) count($pairs)); ?>">
        <div class="cvm-kv-rows">
        <?php

        foreach ($pairs as $index => $pair) {
            ?>
            <div class="cvm-kv-row">
            <input type="text" class="regular-text code cvm-kv-key" name="<?php echo esc_attr($name . '[' . $index . '][key]'); ?>" placeholder="<?php echo esc_attr($placeholder); ?>" value="<?php echo esc_attr((string) ($pair['key'] ?? '')); ?>">
            <input type="text" class="regular-text code cvm-kv-value" name="<?php echo esc_attr($name . '[' . $index . '][value]'); ?>" placeholder="<?php esc_attr_e('Value', 'convermetry'); ?>" value="<?php echo esc_attr((string) ($pair['value'] ?? '')); ?>">
            <button type="button" class="button cvm-kv-remove" aria-label="<?php esc_attr_e('Remove this row', 'convermetry'); ?>"><?php esc_html_e('Remove', 'convermetry'); ?></button></div>
            <?php
        }

        ?>
        </div>
        <button type="button" class="button cvm-kv-add"><?php esc_html_e('+ Add', 'convermetry'); ?></button></div>
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

            $discardUrl = wp_nonce_url(
                add_query_arg(
                    ['page' => self::MENU_SLUG, 'action' => self::DISCARD_ACTION, 'cvm_retry' => md5($url)],
                    self_admin_url('admin.php')
                ),
                self::DISCARD_ACTION,
                'cvm_nonce'
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
