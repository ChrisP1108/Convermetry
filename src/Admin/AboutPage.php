<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

/**
 * The "Convermetry → About" submenu page — the plugin's documentation
 * inside wp-admin: what it does, how the pieces connect, the identifier
 * model, payload samples, the developer API, and privacy posture.
 */
final class AboutPage
{
    /** Menu slug for the submenu page. */
    public const MENU_SLUG = 'convermetry-about';

    /**
     * Registers the menu hook.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
    }

    /**
     * Adds the About submenu.
     *
     * @return void
     */
    public static function addMenu(): void
    {
        add_submenu_page(
            AnalyticsPage::MENU_SLUG,
            'About Convermetry',
            'About',
            'manage_options',
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Opens one documentation card.
     *
     * @param string $title Card heading.
     * @return void
     */
    private static function cardStart(string $title): void
    {
        echo '<div class="cvm-card cvm-about-card">';
        echo '<h2 class="cvm-card-title">' . esc_html($title) . '</h2>';
    }

    /**
     * Closes a documentation card.
     *
     * @return void
     */
    private static function cardEnd(): void
    {
        echo '</div>';
    }

    /**
     * Renders the About page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        echo '<div class="wrap cvm-wrap cvm-about">';
        echo '<h1>About Convermetry</h1>';
        echo '<p class="cvm-about-intro">Convermetry ' . esc_html(CVM_VERSION) . ' — visitor analytics, campaign '
            . 'attribution, and server-confirmed form conversion tracking with reliable webhook delivery. '
            . 'It answers the full funnel question: where a visitor came from, what they did, which form they '
            . 'submitted, what they submitted, which campaign produced the lead, and whether the lead reached '
            . 'your downstream systems.</p>';

        self::cardStart('How the pieces connect');
        echo '<p>A dependency-free frontend tracker records page views, clicks, form attempts, hovers, scroll '
            . 'depth, and confirmed conversions, with last-touch campaign attribution persisted per session '
            . '(30-minute inactivity window, no cookies). When a visitor submits a form, the tracker injects '
            . 'hidden internal fields — a per-attempt <code>cvm_conversion_id</code> token, the '
            . '<code>cvm_session_id</code>, and an attribution snapshot — into the form before any AJAX handler '
            . 'serializes it. The server-side form-provider integration reads those fields when the form plugin '
            . 'confirms the submission, strips them from the lead data, records the conversion under the same '
            . 'token, and queues webhook deliveries in the background. Correlation is token-based end to end; '
            . 'timestamps are never used to match a submission to a session.</p>';
        echo '<pre class="cvm-about-code">session_id
    ├── source / medium, campaign, click-id type
    ├── entrance referrer, landing page
    ├── page views and interactions
    ├── device
    └── conversion_id
            └── server-confirmed form submission → webhook delivery</pre>';
        self::cardEnd();

        self::cardStart('The three identifiers');
        echo '<ul class="cvm-about-features">';
        echo '<li><strong>submission_id</strong> — identifies the form submission itself. Identical in every '
            . 'delivery of that submission, to every endpoint.</li>';
        echo '<li><strong>conversion_id</strong> — joins the submission to its analytics conversion and session. '
            . 'The frontend success event and the server-confirmed record share one conversion id, so every '
            . 'conversion report deduplicates by it and the two detection paths can never double-count.</li>';
        echo '<li><strong>delivery_id</strong> — identifies one outbound webhook delivery (endpoint-specific), '
            . 'echoed as the <code>Idempotency-Key</code> header and stable across every retry. Receivers '
            . 'deduplicate by delivery_id alone.</li>';
        echo '</ul>';
        self::cardEnd();

        self::cardStart('Supported form providers');
        echo '<p>Providers are feature-detected — nothing breaks when a plugin is absent — and their forms are '
            . 'discovered automatically. Detected forms are included by default; exclusions and per-form '
            . 'configuration live on the <strong>Forms</strong> page.</p>';
        echo '<ul class="cvm-about-features">';
        echo '<li><strong>Elementor Pro</strong> — <code>elementor_pro/forms/new_record</code>; per-form settings key by form name.</li>';
        echo '<li><strong>Gravity Forms</strong> — <code>gform_after_submission</code> via public APIs.</li>';
        echo '<li><strong>WPForms</strong> — <code>wpforms_process_complete</code>.</li>';
        echo '<li><strong>Contact Form 7</strong> — <code>wpcf7_mail_sent</code>.</li>';
        echo '<li><strong>Fluent Forms</strong> — <code>fluentform/submission_inserted</code>.</li>';
        echo '</ul>';
        echo '<p>Custom forms integrate through the public API below, and third-party provider adapters can be '
            . 'registered with the <code>convermetry_form_providers</code> filter.</p>';
        self::cardEnd();

        self::cardStart('Webhook delivery & reliability');
        echo '<p>Every endpoint on the <strong>Webhooks</strong> page chooses its delivery types: '
            . '<strong>Analytics Reports</strong> (aggregated analytics on an hourly/twice-daily/daily/weekly '
            . 'schedule, per-endpoint delivery windows, optional history backfill, lossless conversion delivery) '
            . 'and/or <strong>Form Submissions</strong> (each confirmed lead, delivered immediately from a '
            . 'database-backed background queue). Failed deliveries retry after 5 minutes, 30 minutes, 2 hours, '
            . '6 hours, and 16 hours. The exact original request — URL, query parameters, headers, and JSON '
            . 'body — is frozen on the first attempt and replayed byte-for-byte under the same '
            . '<code>delivery_id</code>; a configuration change after a failure never mutates a frozen retry, '
            . 'and endpoints that already acknowledged a delivery are never re-sent. Delivery is at-least-once; '
            . 'deduplicate by <code>delivery_id</code>. Optional HMAC-SHA256 signing adds an '
            . '<code>X-Convermetry-Signature: sha256=&lt;hex&gt;</code> header over the exact body bytes '
            . '(per-endpoint secrets override the shared one). Verify with a constant-time comparison such as '
            . 'PHP\'s <code>hash_equals()</code>.</p>';
        self::cardEnd();

        self::cardStart('Form submission payload (excerpt)');
        echo '<pre class="cvm-about-code">' . esc_html('{
    "schema_version": "1.0",
    "source": "convermetry",
    "plugin_version": "' . CVM_VERSION . '",
    "message_type": "form_submission",
    "website_info": {
        "name": "Example Financial", "url": "https://example.com",
        "domain": "example.com", "id": "site-123",
        "client": { "first_name": "Jane", "last_name": "Smith", "id": "client-456" },
        "page": { "url": "https://example.com/contact",
                  "query": { "utm_source": "google", "utm_medium": "cpc" } }
    },
    "generated_at": "2026-08-22T14:32:00+00:00",
    "delivery_id": "endpoint-specific-idempotent-id",
    "form_submission": {
        "submission_id": "s5f2a…", "conversion_id": "c9d41…",
        "provider": "elementor", "form_name": "Contact Form",
        "form_id": "contact-form-01", "native_form_id": "7ac3d1f",
        "submission_data": { "name": "John Doe", "email": "john@example.com" }
    },
    "analytics_context": {
        "session_id": "…", "channel": "Paid Search",
        "attribution": { "utm_source": "google", "utm_medium": "cpc",
                         "utm_campaign": "retirement-planning", "utm_id": "",
                         "utm_term": "financial advisor", "utm_content": "ad-b",
                         "click_id_type": "gclid" },
        "entrance_referrer": "https://www.google.com/",
        "landing_page": { "url": "https://example.com/retirement-planning/" },
        "device": "desktop",
        "pageview_count": 4, "session_started_at": "2026-08-22T14:20:11+00:00",
        "recent_pages": ["https://example.com/contact", "…"]
    }
}') . '</pre>';
        self::cardEnd();

        self::cardStart('Developer API');
        echo '<p><strong>Custom form submissions</strong> (background delivery, fire-and-forget):</p>';
        echo '<pre class="cvm-about-code">' . esc_html("do_action('convermetry_form_submission',
    ['form_name' => 'Booking Widget', 'form_id' => 'booking-1'],
    ['name' => \$name, 'email' => \$email],
    ['url_query' => [], 'headers' => []] // optional
);") . '</pre>';
        echo '<p><strong>Result-aware submissions</strong> (synchronous; the caller handles failures):</p>';
        echo '<pre class="cvm-about-code">' . esc_html("\$result = convermetry_submit_form(
    ['form_name' => 'Booking Widget'], \$fields
);
if (!\$result->ok) { /* \$result->msg, \$result->failedDeliveries */ }") . '</pre>';
        echo '<p><strong>Server-side custom events:</strong> <code>cvm_track_event(\'purchase\', [...])</code>. '
            . '<strong>Frontend custom conversions:</strong> '
            . '<code>document.dispatchEvent(new CustomEvent(\'convermetry:conversion\', {detail: {name: \'booked\'}}))</code>.</p>';
        echo '<p><strong>Filters:</strong> <code>convermetry_tracked_event</code>, <code>convermetry_webhook_payload</code>, '
            . '<code>convermetry_webhook_report_limit</code>, <code>convermetry_allowed_hosts</code>, '
            . '<code>convermetry_client_ip</code>, <code>convermetry_rate_limits</code>, '
            . '<code>convermetry_source_aliases</code>, <code>convermetry_channel</code>, '
            . '<code>convermetry_delivery_log_row</code>, <code>convermetry_allow_insecure_webhooks</code>, '
            . '<code>convermetry_form_providers</code>, <code>convermetry_retry_schedule</code>. '
            . 'See README.md for full signatures.</p>';
        self::cardEnd();

        self::cardStart('REST APIs');
        echo '<ul class="cvm-about-features">';
        echo '<li><code>POST /wp-json/convermetry/v1/track</code> — public event ingestion for the tracker: '
            . 'idempotent batches, per-IP and site-wide rate limits, same-host URL validation, Origin/Referer '
            . 'protection, bot filtering, DNT/GPC enforcement.</li>';
        echo '<li><code>GET /wp-json/convermetry/v1/deliveries</code> — read-only Activity Log API (enable on the '
            . 'Activity Log page). API-key authenticated — only a SHA-256 hash of the key is stored, failed '
            . 'attempts are throttled, endpoint URLs are redacted to scheme + host, and pagination metadata is '
            . 'returned in <code>X-WP-Total</code> / <code>X-WP-TotalPages</code> / <code>X-CVM-Page</code> headers.</li>';
        echo '</ul>';
        self::cardEnd();

        self::cardStart('Privacy posture');
        echo '<ul class="cvm-about-features">';
        echo '<li>No cookies. The session id lives in localStorage and rotates after 30 minutes of inactivity.</li>';
        echo '<li>Tracked URLs are canonicalized to scheme + host + path — query strings never reach the database. '
            . 'Ad-click identifiers store only the parameter <em>name</em> (<code>click_id_type</code>), never the value.</li>';
        echo '<li>UTM values containing an <code>@</code> are dropped as likely email addresses.</li>';
        echo '<li>No visitor IP addresses or user agents are stored — the IP is used only as a hashed, short-lived '
            . 'rate-limit key; no external geolocation service is ever called.</li>';
        echo '<li>Optional Do Not Track / Global Privacy Control handling, enforced in the tracker, at the REST '
            . 'endpoint, and in the server-side conversion recorder.</li>';
        echo '<li>Everything ages out with the configurable retention window; uninstall removes every table, '
            . 'option, and cron event (on every site of a multisite network).</li>';
        echo '</ul>';
        self::cardEnd();

        echo '</div>';
    }
}
