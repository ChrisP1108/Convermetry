<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;
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
            AnalyticsPage::MENU_SLUG,
            'Convermetry Webhooks',
            'Webhooks',
            'manage_options',
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

        wp_enqueue_script(
            'cvm-admin',
            CVM_PLUGIN_URL . 'assets/js/admin.js',
            [],
            CVM_VERSION,
            true
        );

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
            !current_user_can('manage_options')
            || !isset($_POST['cvm_webhooks_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['cvm_webhooks_nonce'])), self::SAVE_ACTION)
        ) {
            wp_die('Invalid request.');
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

        $rawEndpoints = isset($_POST['cvm_webhooks']) && is_array($_POST['cvm_webhooks'])
            ? wp_unslash($_POST['cvm_webhooks'])
            : [];

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

            $seen[$url]  = true;
            $endpoints[] = [
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
            'global_headers'      => self::sanitizePairs($_POST['cvm_global_headers'] ?? null),
            'global_query'        => self::sanitizePairs($_POST['cvm_global_query'] ?? null),
            'include_page_params' => !empty($_POST['cvm_include_page_params']),
            'failure_mode'        => (($_POST['cvm_failure_mode'] ?? '') === 'show_error') ? 'show_error' : 'background',
        ];

        update_option(Options::WEBHOOK_OPTION_KEY, $settings);

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
            !current_user_can('manage_options')
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
            !current_user_can('manage_options')
        ) {
            wp_send_json_error(['message' => 'Unauthorized.']);
        }

        $rawUrl = esc_url_raw(trim((string) wp_unslash($_POST['url'] ?? '')));
        $type   = sanitize_key((string) ($_POST['type'] ?? 'analytics'));

        $allowInsecure = (bool) apply_filters('convermetry_allow_insecure_webhooks', false);

        if (
            $rawUrl === ''
            || !wp_http_validate_url($rawUrl)
            || (!$allowInsecure && stripos($rawUrl, 'https://') !== 0)
        ) {
            wp_send_json_error(['message' => 'Enter a valid HTTPS endpoint URL first.']);
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
            echo '<div class="notice notice-success is-dismissible"><p>Webhook settings saved.</p></div>';

            $rejected = get_transient('cvm_webhook_rejected_' . get_current_user_id());
            if (is_array($rejected) && $rejected !== []) {
                delete_transient('cvm_webhook_rejected_' . get_current_user_id());
                foreach ($rejected as $url) {
                    echo '<div class="notice notice-warning"><p>Endpoint <code>' . esc_html((string) $url)
                        . '</code> was not saved: endpoints must be valid HTTPS URLs. (Development setups can allow '
                        . 'HTTP via the <code>convermetry_allow_insecure_webhooks</code> filter.)</p></div>';
                }
            }
        }

        if (!empty($_GET['cvm_retry_discarded'])) {
            if ($_GET['cvm_retry_discarded'] === 'busy') {
                echo '<div class="notice notice-warning is-dismissible"><p>'
                    . 'A webhook dispatch run is in progress; the retry was not discarded. Try again in a moment.'
                    . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>'
                    . 'The pending retry was discarded. The endpoint\'s next scheduled delivery will cover that '
                    . 'window\'s data again under a new delivery id.'
                    . '</p></div>';
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
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings  = Options::webhookAll();
        $endpoints = Options::endpoints();
        if ($endpoints === []) {
            $endpoints = [['url' => '', 'label' => '', 'secret' => '', 'analytics' => true, 'forms' => true]];
        }
        $hasAnyUrl = (bool) array_filter(array_column($endpoints, 'url'));

        echo '<div class="wrap cvm-wrap">';
        echo '<h1>Convermetry Webhooks</h1>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::SAVE_ACTION, 'cvm_webhooks_nonce');
        echo '<input type="hidden" name="action" value="' . esc_attr(self::SAVE_ACTION) . '">';

        // ── Webhook Status toggle card ─────────────────────────────────
        echo '<div class="cvm-card cvm-toggle-card" id="cvm-webhook-toggle-card"'
            . ($hasAnyUrl ? '' : ' style="display:none"') . '>';
        echo '<h2 class="cvm-card-title">Webhook Status</h2>';
        echo '<div class="cvm-toggle-row">';
        echo '<label class="cvm-toggle" for="cvm_webhook_active" aria-label="Toggle webhook active state">';
        echo '<input type="checkbox" id="cvm_webhook_active" name="cvm_webhook_active" value="1" '
            . checked(!empty($settings['active']), true, false) . '>';
        echo '<span class="cvm-toggle-slider" aria-hidden="true"></span>';
        echo '</label>';
        echo '<span class="cvm-toggle-label" id="cvm-webhook-toggle-label">'
            . (!empty($settings['active']) ? 'Active' : 'Inactive') . '</span>';
        echo '</div>';
        echo '<p class="description">When inactive, no new deliveries are sent — scheduled analytics reports pause '
            . 'and newly confirmed form submissions wait in the queue. Saved endpoints and settings are preserved.</p>';
        echo '</div>';

        // ── Endpoints card ─────────────────────────────────────────────
        echo '<div class="cvm-card">';
        echo '<h2 class="cvm-card-title">Webhook Endpoints</h2>';
        echo '<p class="description" style="margin-bottom:14px;">Each endpoint chooses which message types it receives: '
            . '<strong>Analytics Reports</strong> (aggregated analytics on the schedule below) and/or '
            . '<strong>Form Submissions</strong> (each confirmed lead, delivered immediately in the background). '
            . 'Endpoints must use HTTPS. Add a label so each endpoint is easy to identify in the Activity Log. '
            . 'Failed deliveries retry automatically — 5m, 30m, 2h, 6h, then 16h after the initial attempt.</p>';

        echo '<div id="cvm-webhooks-container">';
        foreach ($endpoints as $idx => $endpoint) {
            self::renderEndpointBlock(
                $idx,
                (string) $endpoint['url'],
                (string) $endpoint['label'],
                (string) ($endpoint['secret'] ?? ''),
                !empty($endpoint['analytics']),
                !empty($endpoint['forms'])
            );
        }
        echo '</div>';

        echo '<button type="button" id="cvm-add-webhook" class="button" style="margin-top:12px;">+ Add Endpoint</button>';
        echo '</div>';

        // ── Delivery settings card ─────────────────────────────────────
        echo '<div class="cvm-card">';
        echo '<h2 class="cvm-card-title">Delivery Settings</h2>';
        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row"><label for="cvm-shared-secret">Shared signing secret <span class="description">(optional)</span></label></th><td>';
        echo '<input type="text" id="cvm-shared-secret" class="regular-text code" autocomplete="off" name="cvm_shared_secret" value="'
            . esc_attr((string) $settings['shared_secret']) . '">';
        echo '<p class="description">When set, every webhook request includes an <code>X-Convermetry-Signature</code> header — '
            . '<code>sha256=&lt;hex&gt;</code>, the HMAC-SHA256 of the raw JSON body keyed with this secret — so receivers can '
            . 'verify payloads genuinely came from this site. An endpoint block\'s own signing secret overrides this shared one '
            . 'for that endpoint, so one receiver never learns the key that signs payloads for the others.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="cvm-interval">Analytics send interval</label></th><td>';
        echo '<select id="cvm-interval" name="cvm_interval">';
        $intervalLabels = [
            'hourly'     => 'Hourly',
            'twicedaily' => 'Twice daily',
            'daily'      => 'Daily',
            'weekly'     => 'Weekly',
        ];
        foreach ($intervalLabels as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($settings['interval'], $value, false) . '>'
                . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">Applies to Analytics Reports only — form submissions always deliver immediately. '
            . 'Each site delivers at a random, stable time within the interval (scattered up to 24 hours), so many sites '
            . 'sharing an endpoint don\'t all send at the same moment.';

        $next = wp_next_scheduled(AnalyticsDispatcher::CRON_HOOK);
        if ($next !== false) {
            $due = $next > time()
                ? 'in ' . human_time_diff(time(), (int) $next)
                : 'as soon as WP-Cron next runs';

            echo ' Next scheduled send: <strong>' . esc_html(gmdate('Y-m-d H:i', (int) $next)) . ' UTC</strong> ('
                . esc_html($due) . ').';
        }
        echo '</p></td></tr>';

        echo '<tr><th scope="row">History backfill</th><td>';
        echo '<label><input type="checkbox" name="cvm_backfill" value="1" '
            . checked(!empty($settings['backfill']), true, false) . '> Send retained history to new analytics endpoints</label>';
        echo '<p class="description">When enabled, an endpoint that has never received an analytics delivery starts from the '
            . 'beginning of the retention window instead of one send interval ago. History is delivered in interval-sized '
            . 'windows (up to 10 per scheduled run), so a long backlog is worked off over a few runs.</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">Form delivery failure mode</th><td>';
        echo '<label style="display:block;margin-bottom:6px;"><input type="radio" name="cvm_failure_mode" value="background" '
            . checked($settings['failure_mode'] !== 'show_error', true, false) . '> '
            . '<strong>Retry in background</strong> (recommended) — the visitor always sees the form\'s normal success state; '
            . 'failed deliveries retry automatically.</label>';
        echo '<label style="display:block;"><input type="radio" name="cvm_failure_mode" value="show_error" '
            . checked($settings['failure_mode'] === 'show_error', true, false) . '> '
            . '<strong>Show error to visitor</strong> — delivery runs during the submission and a failure is shown on the form '
            . '(supported for Elementor Pro forms; other providers always use background delivery).</label>';
        echo '</td></tr>';

        echo '</table>';
        echo '</div>';

        // ── Request customization card ─────────────────────────────────
        echo '<div class="cvm-card">';
        echo '<h2 class="cvm-card-title">Request Customization</h2>';
        echo '<p class="description" style="margin-bottom:14px;">Headers and URL query parameters added to every webhook '
            . 'request. Per-form headers and parameters (configured on the Forms page) are merged after these; when page URL '
            . 'parameters are included, the precedence is: global parameters &rarr; page parameters &rarr; per-form parameters. '
            . 'Header values that look like credentials are redacted in the Activity Log but sent intact.</p>';

        echo '<h3>Global Request Headers</h3>';
        self::renderKvBuilder('cvm_global_headers', Options::globalHeaders(), 'e.g. Authorization');

        echo '<h3>Global URL Query Parameters</h3>';
        self::renderKvBuilder('cvm_global_query', Options::globalQueryParams(), 'e.g. source');

        echo '<p style="margin-top:12px;"><label><input type="checkbox" name="cvm_include_page_params" value="1" '
            . checked(!empty($settings['include_page_params']), true, false) . '> '
            . 'Include page URL parameters — query parameters present on the page a form was submitted from '
            . '(e.g. <code>?utm_source=google&amp;gclid=…</code>) are appended to the webhook URL for that submission.</label></p>';

        echo '</div>';

        submit_button('Save Webhook Settings');
        echo '</form>';

        self::renderPendingRetries();
        self::renderPendingQueue();

        $logUrl = add_query_arg(['page' => ActivityLogPage::MENU_SLUG], self_admin_url('admin.php'));
        echo '<h2>Activity Log</h2>';
        echo '<p>Every delivery attempt — analytics report or form submission, scheduled, immediate, retry, or test — is '
            . 'recorded with its payload and response (sensitive values redacted) on the <a href="' . esc_url($logUrl) . '">Activity Log</a> page.</p>';

        echo '</div>';
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
     * @return void
     */
    private static function renderEndpointBlock(int $index, string $url, string $label, string $secret, bool $analytics, bool $forms): void
    {
        echo '<div class="cvm-webhook-block" data-webhook-index="' . esc_attr((string) $index) . '">';

        echo '<div class="cvm-webhook-block-header">';
        echo '<strong class="cvm-webhook-block-title">Endpoint ' . esc_html((string) ($index + 1)) . '</strong>';
        if ($index > 0) {
            echo '<button type="button" class="button cvm-remove-webhook-btn" aria-label="Remove endpoint '
                . esc_attr((string) ($index + 1)) . '">Remove</button>';
        }
        echo '</div>';

        echo '<div class="cvm-webhook-url-row">';
        echo '<input type="url" class="cvm-webhook-url-input regular-text code" '
            . 'name="cvm_webhooks[' . esc_attr((string) $index) . '][url]" '
            . 'value="' . esc_attr($url) . '" placeholder="https://example.com/convermetry-hook" '
            . 'aria-label="Endpoint ' . esc_attr((string) ($index + 1)) . ' URL">';
        echo '</div>';

        echo '<div class="cvm-webhook-field">';
        echo '<input type="text" class="regular-text cvm-webhook-label-input" '
            . 'name="cvm_webhooks[' . esc_attr((string) $index) . '][label]" '
            . 'value="' . esc_attr($label) . '" placeholder="Label (optional — shown in the Activity Log)" '
            . 'aria-label="Endpoint ' . esc_attr((string) ($index + 1)) . ' label">';
        echo '</div>';

        echo '<div class="cvm-webhook-field">';
        echo '<input type="text" class="regular-text code cvm-webhook-secret-input" autocomplete="off" '
            . 'name="cvm_webhooks[' . esc_attr((string) $index) . '][secret]" '
            . 'value="' . esc_attr($secret) . '" placeholder="Signing secret (optional — overrides the shared secret)" '
            . 'aria-label="Endpoint ' . esc_attr((string) ($index + 1)) . ' signing secret">';
        echo '</div>';

        echo '<fieldset class="cvm-webhook-types">';
        echo '<legend class="screen-reader-text">Delivery types for endpoint ' . esc_attr((string) ($index + 1)) . '</legend>';
        echo '<label><input type="checkbox" name="cvm_webhooks[' . esc_attr((string) $index) . '][analytics]" value="1" '
            . checked($analytics, true, false) . '> Analytics Reports</label> ';
        echo '<label><input type="checkbox" name="cvm_webhooks[' . esc_attr((string) $index) . '][forms]" value="1" '
            . checked($forms, true, false) . '> Form Submissions</label>';
        echo '</fieldset>';

        echo '<div class="cvm-endpoint-tests">';
        echo '<button type="button" class="button cvm-test-endpoint" data-type="analytics">Send analytics test</button> ';
        echo '<button type="button" class="button cvm-test-endpoint" data-type="form">Send form test</button>';
        echo '<span class="cvm-test-result" role="status" aria-live="polite"></span>';
        echo '</div>';

        echo '</div>';
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
        echo '<div class="cvm-kv-builder" data-kv-name="' . esc_attr($name) . '" data-kv-next="' . esc_attr((string) count($pairs)) . '">';
        echo '<div class="cvm-kv-rows">';

        foreach ($pairs as $index => $pair) {
            echo '<div class="cvm-kv-row">';
            echo '<input type="text" class="regular-text code cvm-kv-key" name="' . esc_attr($name . '[' . $index . '][key]')
                . '" placeholder="' . esc_attr($placeholder) . '" value="' . esc_attr((string) ($pair['key'] ?? '')) . '">';
            echo '<input type="text" class="regular-text code cvm-kv-value" name="' . esc_attr($name . '[' . $index . '][value]')
                . '" placeholder="Value" value="' . esc_attr((string) ($pair['value'] ?? '')) . '">';
            echo '<button type="button" class="button cvm-kv-remove" aria-label="Remove this row">Remove</button>';
            echo '</div>';
        }

        echo '</div>';
        echo '<button type="button" class="button cvm-kv-add">+ Add</button>';
        echo '</div>';
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

        echo '<div class="notice notice-warning inline"><p><strong>Pending analytics delivery retries</strong></p><ul style="margin-left:1.5em;list-style:disc;">';

        foreach ($pending as $retry) {
            $when = (int) ($retry['scheduled_for'] ?? 0);
            $url  = (string) ($retry['url'] ?? '');

            if (!empty($retry['exhausted'])) {
                $due = 'with the next scheduled send (retry chain exhausted; the frozen payload is kept and re-sent first)';
            } elseif ($when > time()) {
                $due = 'in ' . human_time_diff(time(), $when);
            } else {
                $due = 'as soon as WP-Cron next runs';
            }

            $discardUrl = wp_nonce_url(
                add_query_arg(
                    ['page' => self::MENU_SLUG, 'action' => self::DISCARD_ACTION, 'cvm_retry' => md5($url)],
                    self_admin_url('admin.php')
                ),
                self::DISCARD_ACTION,
                'cvm_nonce'
            );

            printf(
                '<li>Retry %d of %d to <code>%s</code> — next attempt %s. <a href="%s">Discard this retry</a></li>',
                (int) ($retry['attempt'] ?? 1),
                (int) $max,
                esc_html($url),
                esc_html($due),
                esc_url($discardUrl)
            );
        }

        echo '</ul>';
        echo '<p class="description">Discarding a retry drops its frozen payload; the data itself is not lost — the '
            . 'endpoint\'s next scheduled delivery covers that window again under a new delivery id (a receiver that '
            . 'already processed the frozen delivery would then see that data twice). Frozen retries older than the '
            . 'retention window are discarded automatically.</p>';
        echo '</div>';
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

        echo '<div class="notice notice-info inline"><p><strong>'
            . esc_html(number_format_i18n($count)) . ' pending form-submission '
            . ($count === 1 ? 'delivery' : 'deliveries') . '</strong> waiting in the background queue.</p>';
        echo '<ul style="margin-left:1.5em;list-style:disc;">';

        foreach ($rows as $row) {
            $due = (int) strtotime((string) $row['next_attempt_at'] . ' UTC');
            printf(
                '<li>Submission <code>%s</code> &rarr; <code>%s</code> — attempt %d of %d, next %s.</li>',
                esc_html((string) $row['submission_id']),
                esc_html((string) $row['endpoint_url']),
                (int) $row['attempt'] + 1,
                (int) $max,
                $due > time() ? 'in ' . esc_html(human_time_diff(time(), $due)) : 'as soon as WP-Cron next runs'
            );
        }

        echo '</ul></div>';
    }
}
