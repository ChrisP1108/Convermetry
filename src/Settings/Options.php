<?php
declare(strict_types=1);

namespace Convermetry\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Typed read access to the plugin's settings.
 *
 * Settings live in two option arrays:
 *
 *  - 'cvm_settings'         — tracking toggles, privacy, retention, and the
 *                             website/client identity sent in every payload
 *                             (managed by Admin\SettingsPage).
 *  - 'cvm_webhook_settings' — webhook endpoints, delivery types, signing,
 *                             schedule, global headers/query parameters, and
 *                             form failure mode (managed by Admin\WebhooksPage).
 *
 * This class is the only place that knows either option's shape and defaults;
 * every other subsystem reads configuration through the typed getters below
 * instead of touching get_option() directly. Per-form configuration lives in
 * {@see \Convermetry\Forms\FormSettings}.
 */
final class Options
{
    /** The wp_options key holding tracking/data/identity settings. */
    public const string OPTION_KEY = 'cvm_settings';

    /** The wp_options key holding webhook configuration. */
    public const string WEBHOOK_OPTION_KEY = 'cvm_webhook_settings';

    /** @var string[] Event types the frontend tracker can record. */
    public const array EVENT_TYPES = ['pageview', 'click', 'form_submit', 'form_success', 'hover', 'scroll_depth'];

    /** @var string[] Cron recurrences selectable for analytics webhook dispatch. */
    public const array INTERVALS = ['hourly', 'twicedaily', 'daily', 'weekly'];

    /**
     * Returns the default value for every general setting.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'track_pageview'      => true,
            'track_click'         => true,
            'track_form_submit'   => true,
            'track_form_success'  => true,
            'track_hover'         => true,
            'track_scroll_depth'  => true,
            'exclude_logged_in'   => true,
            'respect_dnt'         => false,
            'retention_days'      => 90,
            'hover_dwell_ms'      => 800,
            'log_submission_data' => true,
            'store_ip_address'    => true,
            'client_first_name'   => '',
            'client_last_name'    => '',
            'client_id'           => '',
            'website_id'          => '',
        ];
    }

    /**
     * Returns the default value for every webhook setting.
     *
     * @return array<string, mixed>
     */
    public static function webhookDefaults(): array
    {
        return [
            'active'              => true,
            'endpoints'           => [],
            'interval'            => 'daily',
            'shared_secret'       => '',
            'backfill'            => false,
            'global_headers'      => [],
            'global_query'        => [],
            'include_page_params' => false,
            'failure_mode'        => 'background',
        ];
    }

    /**
     * Returns all general settings merged over the defaults.
     *
     * Unknown keys stored in the option (e.g. from an older version) are
     * preserved so upgrades never silently drop data.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        $saved = get_option(self::OPTION_KEY, []);

        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    /**
     * Returns all webhook settings merged over the defaults.
     *
     * @return array<string, mixed>
     */
    public static function webhookAll(): array
    {
        $saved = get_option(self::WEBHOOK_OPTION_KEY, []);

        return array_merge(self::webhookDefaults(), is_array($saved) ? $saved : []);
    }

    /**
     * Whether a given event type should be recorded.
     *
     * Unknown types (custom events recorded via cvm_track_event()) are always
     * allowed; only the built-in tracker types can be toggled off.
     *
     * @param string $type Event type key (e.g. "pageview").
     * @return bool
     */
    public static function isTypeEnabled(string $type): bool
    {
        if (!in_array($type, self::EVENT_TYPES, true)) {
            return true;
        }

        return !empty(self::all()['track_' . $type]);
    }

    /**
     * Returns the built-in event types currently enabled for tracking.
     *
     * @return string[]
     */
    public static function enabledTypes(): array
    {
        return array_values(array_filter(
            self::EVENT_TYPES,
            static fn(string $type): bool => self::isTypeEnabled($type)
        ));
    }

    /**
     * Whether logged-in users should be excluded from tracking.
     *
     * @return bool
     */
    public static function excludeLoggedIn(): bool
    {
        return !empty(self::all()['exclude_logged_in']);
    }

    /**
     * Whether visitors sending Do Not Track / Global Privacy Control signals
     * should be excluded from tracking. Off by default — enabling it is a
     * site-owner choice that typically reduces recorded traffic.
     *
     * @return bool
     */
    public static function respectDnt(): bool
    {
        return !empty(self::all()['respect_dnt']);
    }

    /**
     * Whether the Activity Log stores each form-submission payload's
     * submission_data. When off, delivery metadata is still recorded but the
     * visitor's field values are replaced with a placeholder before the log
     * row is written — useful when compliance rules forbid keeping a second
     * copy of lead data in the log table.
     *
     * @return bool
     */
    public static function logSubmissionData(): bool
    {
        return !empty(self::all()['log_submission_data']);
    }

    /**
     * Whether visitor IP addresses are captured at all — one switch covering
     * both write paths:
     *
     *  - analytics events (every tracked page view, click, hover, scroll
     *    milestone and conversion), stored in the events table's ip_address
     *    column and surfaced in analytics reports; and
     *  - server-confirmed form submissions, stored on the submission row and
     *    sent as form_submission.ip_address in webhook payloads.
     *
     * On by default. Turning it off stops new rows from recording an address
     * (the column is simply left empty); rows already stored are unchanged
     * and age out with the retention window.
     *
     * Gated again by Do Not Track: when the site honors DNT/GPC and the
     * request carries one, no address is stored on either path — including a
     * form submission, which is still recorded and delivered but with an
     * empty ip_address. Both paths resolve through ClientIp::forStorage(),
     * which owns that policy.
     *
     * @return bool
     */
    public static function storeIpAddress(): bool
    {
        return !empty(self::all()['store_ip_address']);
    }

    /**
     * Hostnames that tracked page URLs (and Origin/Referer checks) may use,
     * and that referrer reports treat as internal.
     *
     * @return string[] Lowercase hostnames; by default the home and site URL
     *                  hosts. Extend via the 'convermetry_allowed_hosts'
     *                  filter for multi-domain setups.
     */
    public static function allowedHosts(): array
    {
        // Memoized: the REST endpoint consults this once per event in a
        // batch, and the URLs it derives from cannot change mid-request.
        static $allowed = null;

        if ($allowed !== null) {
            return $allowed;
        }

        $hosts = array_values(array_unique(array_filter([
            strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)),
            strtolower((string) wp_parse_url(site_url(), PHP_URL_HOST)),
        ])));

        /**
         * Filters the hostnames accepted in tracked page URLs and
         * Origin/Referer checks, and treated as internal in referrer reports.
         *
         * @param string[] $hosts Lowercase hostnames.
         */
        return $allowed = (array) apply_filters('convermetry_allowed_hosts', $hosts);
    }

    /**
     * Number of days analytics rows are retained before the daily cleanup
     * cron deletes them. Clamped to a sane 7–365 range.
     *
     * @return int
     */
    public static function retentionDays(): int
    {
        return min(365, max(7, (int) self::all()['retention_days']));
    }

    /**
     * Milliseconds the pointer must rest on an element before a hover event
     * is recorded. Clamped to 200–10000 so a typo can't flood the table.
     *
     * @return int
     */
    public static function hoverDwellMs(): int
    {
        return min(10000, max(200, (int) self::all()['hover_dwell_ms']));
    }

    /**
     * Whether webhook delivery is currently active.
     *
     * Toggling this off pauses all new deliveries — scheduled analytics
     * reports and newly queued form submissions alike — without discarding
     * any saved endpoint configuration. Already-scheduled retry attempts
     * (started before the toggle was flipped off) are left to run their
     * course rather than being torn down mid-chain.
     *
     * @return bool
     */
    public static function webhooksActive(): bool
    {
        return !empty(self::webhookAll()['active']);
    }

    /**
     * Returns the configured webhook endpoints. Each carries its URL, an
     * optional label, an optional per-endpoint signing secret, and two
     * delivery-type flags controlling which message types it receives.
     *
     * @return array<int, array{url: string, label: string, secret: string, analytics: bool, forms: bool}>
     */
    public static function endpoints(): array
    {
        $raw = self::webhookAll()['endpoints'];
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $url = trim((string) ($entry['url'] ?? ''));
            if ($url === '') {
                continue;
            }

            $out[] = [
                'url'       => $url,
                'label'     => trim((string) ($entry['label'] ?? '')),
                'secret'    => trim((string) ($entry['secret'] ?? '')),
                'analytics' => !empty($entry['analytics']),
                'forms'     => !empty($entry['forms']),
            ];
        }

        return $out;
    }

    /**
     * Endpoints that receive scheduled analytics reports.
     *
     * @return array<int, array{url: string, label: string, secret: string, analytics: bool, forms: bool}>
     */
    public static function analyticsEndpoints(): array
    {
        return array_values(array_filter(self::endpoints(), static fn(array $e): bool => $e['analytics']));
    }

    /**
     * Endpoints that receive immediate form-submission deliveries.
     *
     * @return array<int, array{url: string, label: string, secret: string, analytics: bool, forms: bool}>
     */
    public static function formEndpoints(): array
    {
        return array_values(array_filter(self::endpoints(), static fn(array $e): bool => $e['forms']));
    }

    /**
     * URLs of endpoints that receive scheduled analytics reports.
     *
     * @return string[] Zero or more absolute http(s) URLs.
     */
    public static function analyticsEndpointUrls(): array
    {
        return array_values(array_unique(array_column(self::analyticsEndpoints(), 'url')));
    }

    /**
     * The human-readable label configured for an endpoint, or '' when none
     * was set. Used to badge Activity Log entries so a specific endpoint is
     * easy to spot at a glance.
     *
     * @param string $url Endpoint URL (exact match against saved endpoints).
     * @return string
     */
    public static function endpointLabel(string $url): string
    {
        foreach (self::endpoints() as $endpoint) {
            if ($endpoint['url'] === $url) {
                return $endpoint['label'];
            }
        }

        return '';
    }

    /**
     * Returns the cron recurrence used for analytics webhook dispatch.
     *
     * @return string One of {@see self::INTERVALS}.
     */
    public static function webhookInterval(): string
    {
        $interval = (string) self::webhookAll()['interval'];

        return in_array($interval, self::INTERVALS, true) ? $interval : 'daily';
    }

    /**
     * The shared secret used to sign webhook request bodies, or '' when
     * signing is not configured.
     *
     * When set, every webhook request carries an X-Convermetry-Signature
     * header of the form 'sha256=<hex>' — the HMAC-SHA256 of the exact raw
     * JSON body, keyed with this secret — so a receiver can verify the
     * payload came from this installation and was not altered in transit.
     * The signature is computed at send time from the frozen body, so
     * rotating the secret mid-retry simply signs the identical bytes with
     * the new key.
     *
     * @return string
     */
    public static function sharedSecret(): string
    {
        return (string) self::webhookAll()['shared_secret'];
    }

    /**
     * The signing secret effective for one endpoint: the per-endpoint secret
     * saved on its endpoint block when set, otherwise the shared secret.
     * Per-endpoint secrets mean one compromised receiver never learns the
     * key that authenticates payloads to every other receiver.
     *
     * @param string $url Endpoint URL (exact match against saved endpoints).
     * @return string Secret to sign with, or '' when signing is not configured.
     */
    public static function secretFor(string $url): string
    {
        foreach (self::endpoints() as $endpoint) {
            if ($endpoint['url'] === $url && $endpoint['secret'] !== '') {
                return $endpoint['secret'];
            }
        }

        return self::sharedSecret();
    }

    /**
     * Whether a newly added analytics endpoint should be backfilled with the
     * full retained history (in interval-sized windows) instead of starting
     * from one send interval ago.
     *
     * @return bool
     */
    public static function webhookBackfill(): bool
    {
        return !empty(self::webhookAll()['backfill']);
    }

    /**
     * Custom HTTP headers included on every webhook request to every
     * endpoint (e.g. an Authorization header for a downstream API).
     *
     * @return array<int, array{key: string, value: string}>
     */
    public static function globalHeaders(): array
    {
        $headers = self::webhookAll()['global_headers'];

        return is_array($headers) ? array_values(array_filter($headers, 'is_array')) : [];
    }

    /**
     * URL query parameters appended to every webhook URL on every request.
     *
     * @return array<int, array{key: string, value: string}>
     */
    public static function globalQueryParams(): array
    {
        $params = self::webhookAll()['global_query'];

        return is_array($params) ? array_values(array_filter($params, 'is_array')) : [];
    }

    /**
     * Whether query parameters present on the page a form was submitted from
     * are appended to the webhook URL for that submission (global default;
     * each form can also enable this individually).
     *
     * @return bool
     */
    public static function includePageParams(): bool
    {
        return !empty(self::webhookAll()['include_page_params']);
    }

    /**
     * What a visitor experiences when webhook delivery fails for a supported
     * form provider's submission.
     *
     *  - 'background' (default): the form shows its normal success state and
     *    failed deliveries are retried automatically in the background.
     *  - 'show_error': delivery runs synchronously during the submission and
     *    a failure is surfaced on the form (currently supported for
     *    Elementor Pro, whose AJAX handler exposes an error channel).
     *
     * @return string 'background' or 'show_error'.
     */
    public static function formFailureMode(): string
    {
        $mode = (string) self::webhookAll()['failure_mode'];

        return $mode === 'show_error' ? 'show_error' : 'background';
    }

    /**
     * The client's first name, sent as 'website_info.client.first_name' in
     * every webhook payload. '' when not configured.
     *
     * @return string
     */
    public static function clientFirstName(): string
    {
        return (string) self::all()['client_first_name'];
    }

    /**
     * The client's last name, sent as 'website_info.client.last_name' in
     * every webhook payload. '' when not configured.
     *
     * @return string
     */
    public static function clientLastName(): string
    {
        return (string) self::all()['client_last_name'];
    }

    /**
     * Optional client identifier, sent as 'website_info.client.id' in every
     * webhook payload. '' when not configured.
     *
     * @return string
     */
    public static function clientId(): string
    {
        return (string) self::all()['client_id'];
    }

    /**
     * Optional website identifier, sent as 'website_info.id' in every
     * webhook payload. '' when not configured.
     *
     * @return string
     */
    public static function websiteId(): string
    {
        return (string) self::all()['website_id'];
    }

    /**
     * Converts a cron recurrence name to its length in seconds.
     *
     * @param string $interval One of {@see self::INTERVALS}.
     * @return int
     */
    public static function intervalSeconds(string $interval): int
    {
        return match ($interval) {
            'hourly'     => HOUR_IN_SECONDS,
            'twicedaily' => 12 * HOUR_IN_SECONDS,
            'weekly'     => WEEK_IN_SECONDS,
            default      => DAY_IN_SECONDS,
        };
    }
}
