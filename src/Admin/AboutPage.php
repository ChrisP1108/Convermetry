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
    public const string MENU_SLUG = 'convermetry-about';

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

        self::cardStart('Conversion intelligence');
        echo '<p>Beyond form submissions, Convermetry measures three further things — each answering a question '
            . 'the conversion count alone cannot.</p>';
        echo '<ul class="cvm-about-features">';
        echo '<li><strong>Goals</strong> count important actions that are not form submissions: a phone number '
            . 'tapped, a PDF opened, a booking link followed, a pricing page reached. Matching happens on the '
            . 'server against activity the tracker already reports, so your list of valuable actions is never '
            . 'published to visitors and a visitor cannot fabricate a conversion by claiming one. Phone and email '
            . 'goals need no configuration at all. Each goal counts either once per visit or every occurrence, '
            . 'enforced by a database uniqueness constraint rather than a PHP check.</li>';
        echo '<li><strong>Funnels</strong> measure the ordered path to a conversion — how many sessions reached '
            . 'each step and how many were lost between them. Steps must occur in sequence: a session that '
            . 'reached step three without step two is not counted at step three.</li>';
        echo '<li><strong>Form engagement</strong> reports views, starts, attempts, successes and abandonment '
            . 'per form, plus which fields fail validation most often. <strong>No value a visitor typed is ever '
            . 'recorded</strong> — a validation event carries only the field id, its type, and which browser '
            . 'validity check failed.</li>';
        echo '<li><strong>Lead status and value</strong> let you mark a submission qualified, won, lost or spam '
            . 'and record what it was worth, so campaign reporting can be measured against outcomes rather than '
            . 'treating every conversion as equal. This is deliberately not a CRM: six statuses, no pipeline '
            . 'stages, no assignees.</li>';
        echo '</ul>';
        echo '<div class="cvm-about-note">Lead status and value are recorded <strong>locally only</strong> in '
            . 'this version. A form payload is frozen when it is first delivered and scheduled analytics windows '
            . 'never revisit, so a lead field on either could only ever report &ldquo;new&rdquo; — wrong for every '
            . 'lead you qualify. Goal completions do travel, in the analytics report payload, because a '
            . 'completion either happened in the window or it did not.</div>';
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
        echo '<li><strong>Fluent Forms</strong> — <code>fluentform/submission_inserted</code> (plus the legacy '
            . 'alias, guarded against the double fire).</li>';
        echo '<li><strong>Ninja Forms</strong> — <code>ninja_forms_after_submission</code>; admin form previews '
            . 'are skipped, and multi-instance form ids are normalized to the numeric form id.</li>';
        echo '<li><strong>Formidable Forms</strong> — <code>frm_after_create_entry</code> at priority 30; '
            . 'repeater/embedded child entries and saved drafts are skipped.</li>';
        echo '</ul>';
        echo '<p>Per-form settings key by the provider\'s own form id for every provider except Elementor Pro, '
            . 'which keys by form name. Custom forms integrate through the public API below, and third-party '
            . 'provider adapters can be registered with the <code>convermetry_form_providers</code> filter.</p>';
        self::cardEnd();

        self::cardStart('The two outbound message types');
        echo '<p>Convermetry sends two kinds of webhook message. Every endpoint on the <strong>Webhooks</strong> '
            . 'page chooses which it receives, and the two are fully independent — an endpoint may take either '
            . 'one on its own, or both.</p>';
        echo '<ul class="cvm-about-features">';
        echo '<li><strong>Analytics Reports</strong> — <code>message_type: analytics_report</code>. Scheduled, '
            . '<em>aggregated</em> reporting for a time window, sent on the site-wide schedule you pick: hourly, '
            . 'twice daily, daily, or weekly. This is <em>not</em> one webhook per page view or click — an entire '
            . 'window is summarized into a single delivery. Each endpoint tracks its own delivery window, so a '
            . 'payload covers the time since <em>that</em> endpoint\'s last successful delivery, and a newly '
            . 'added endpoint can optionally be backfilled with the retained history. '
            . '<strong>Send analytics test</strong> on the Webhooks page delivers one on demand.</li>';
        echo '<li><strong>Form Submissions</strong> — <code>message_type: form_submission</code>. One message per '
            . 'server-confirmed lead, delivered immediately through the background form-delivery queue instead of '
            . 'on a schedule. <strong>Send form test</strong> delivers one on demand.</li>';
        echo '</ul>';
        echo '<p><strong>Internal email notifications are a third, separate path.</strong> The '
            . '<strong>Notifications</strong> page emails a chosen internal address when a submission is '
            . 'recorded, with the same analytics context attached. It has its own master switch, its own '
            . 'queue, and works with no webhook endpoints at all — and because it is email, a sent '
            . 'notification is a copy of lead data outside Convermetry\'s retention and deletion controls. '
            . 'Notification sends do not appear in the Activity Log, which covers webhook deliveries only.</p>';
        echo '<p><strong>For an analytics-only endpoint</strong>, check <strong>Analytics Reports</strong> and '
            . 'leave <strong>Form Submissions</strong> unchecked — no submitted form field values are ever sent to '
            . 'that endpoint. Analytics reports do still describe individual conversions '
            . '(<code>conversions.recent[]</code>: conversion id, form name and ids, provider, and the visitor\'s '
            . 'IP when IP storage is on), so "analytics-only" means no field values, not no lead identifiers. '
            . 'The reverse works the same way. Both message types appear in the <strong>Activity Log</strong>, '
            . 'where you can tell them apart by their <code>message_type</code>.</p>';
        self::cardEnd();

        self::cardStart('Webhook delivery & reliability');
        echo '<p>Both message types share one delivery pipeline — an Analytics Report is frozen per reporting '
            . 'window, a Form Submission per submission, and from there the guarantees are identical. Failed '
            . 'deliveries retry after 5 minutes, 30 minutes, 2 hours, 6 hours, and 16 hours. The request URL, the '
            . 'configured headers, and the JSON body are frozen on the first attempt and replayed byte-for-byte '
            . 'under the same <code>delivery_id</code>; a configuration change after a failure never mutates '
            . 'them, and endpoints that already acknowledged a delivery are never re-sent. Three headers are '
            . 'regenerated per attempt from that frozen body — <code>Idempotency-Key</code> (always the same '
            . 'delivery id), <code>User-Agent</code>, and <code>X-Convermetry-Signature</code>, which uses the '
            . 'secret current at send time so a rotated key still verifies. Delivery is at-least-once; deduplicate by <code>delivery_id</code>. Conversion '
            . 'delivery inside Analytics Reports is lossless — a window holding more than 100 individual '
            . 'conversions is split into consecutive deliveries rather than truncated. Optional HMAC-SHA256 '
            . 'signing adds an <code>X-Convermetry-Signature: sha256=&lt;hex&gt;</code> header over the exact '
            . 'body bytes (per-endpoint secrets override the shared one). Verify with a constant-time comparison '
            . 'such as PHP\'s <code>hash_equals()</code>.</p>';
        self::cardEnd();

        self::cardStart('Analytics report payload (excerpt)');
        echo '<p>Reporting data for one time window — aggregates plus the individual conversions that '
            . 'occurred in it. No other lead data is included.</p>';
        echo '<pre class="cvm-about-code">' . esc_html('{
    "schema_version": "1.1",
    "source": "convermetry",
    "plugin_version": "' . CVM_VERSION . '",
    "message_type": "analytics_report",
    "website_info": {
        "name": "Example Financial", "url": "https://example.com",
        "domain": "example.com", "id": "site-123",
        "client": { "first_name": "Jane", "last_name": "Smith", "id": "client-456" }
    },
    "generated_at": "2026-08-22T14:00:00+00:00",
    "delivery_id": "endpoint-specific-idempotent-id",
    "period": {
        "start": "2026-08-21T14:00:00+00:00",
        "end": "2026-08-22T14:00:00+00:00"
    },
    "analytics": {
        "totals": { "pageview": 1240, "click": 512, "form_submit": 38, "form_success": 24 },
        "daily_pageviews": [ { "date": "2026-08-21", "count": 610 } ],
        "top_pages": [ { "page_url": "https://example.com/", "page_title": "Home",
                         "views": 400, "sessions": 310 } ],
        "top_landing_pages": [ { "page_url": "https://example.com/quote/",
                                 "page_title": "Get a Quote", "sessions": 180 } ],
        "top_clicks": [ { "element_label": "Get a Quote", "element_tag": "a",
                          "target_url": "https://example.com/quote", "clicks": 88 } ],
        "top_forms": [ { "element_label": "contact-form",
                         "page_url": "https://example.com/contact", "submissions": 21 } ],
        "top_hovers": [ { "element_label": "Pricing", "element_tag": "a", "hovers": 130 } ],
        "top_referrers": [ { "referrer": "https://www.google.com/", "visits": 210 } ],
        "top_campaigns": [ { "utm_source": "google", "utm_medium": "cpc",
                             "utm_campaign": "retirement-planning", "utm_id": "cmp-3301",
                             "channel": "Paid Search", "views": 96, "sessions": 74,
                             "conversions": 7, "converting_sessions": 6,
                             "conversion_rate": 8.11 } ],
        "top_campaign_content": [ { "utm_source": "google", "utm_medium": "cpc",
                                    "utm_campaign": "retirement-planning", "utm_id": "cmp-3301",
                                    "utm_term": "financial advisor", "utm_content": "ad-b",
                                    "views": 42, "sessions": 31, "conversions": 3 } ],
        "channels": [ { "channel": "Paid Search", "views": 320, "sessions": 240,
                        "conversions": 12, "converting_sessions": 11,
                        "conversion_rate": 4.58 } ],
        "conversions": {
            "total": 24,
            "server_confirmed": 19,
            "recent": [ { "conversion_id": "c9d41…", "form": "Contact Form",
                          "page_url": "https://example.com/contact",
                          "referrer": "https://example.com/services",
                          "device": "desktop", "ip_address": "203.0.113.42",
                          "session_id": "9f2c…",
                          "occurred_at": "2026-08-22 09:14:02",
                          "server_confirmed": true, "submission_id": "s5f2a…",
                          "provider": "elementor", "form_id": "contact-form-01",
                          "native_form_id": "7ac3d1f",
                          "attribution": { "channel": "Paid Search", "utm_source": "google",
                                           "utm_medium": "cpc",
                                           "utm_campaign": "retirement-planning",
                                           "utm_id": "", "utm_term": "", "utm_content": "",
                                           "click_id_type": "gclid" } } ]
        },
        "devices": { "desktop": 820, "mobile": 420 }
    }
}') . '</pre>';
        echo '<ul class="cvm-about-features">';
        echo '<li><code>period</code> is the UTC window the report covers — <code>start</code> inclusive, '
            . '<code>end</code> exclusive.</li>';
        echo '<li>The <code>analytics</code> section comes from the same reporting query layer the dashboard '
            . 'uses, so a payload and the admin screens cannot disagree.</li>';
        echo '<li><code>analytics.conversions.recent</code> lists the <em>individual</em> conversions inside the '
            . 'window — each with the visitor\'s <code>ip_address</code> when IP storage is on — while '
            . 'every other section is aggregate reporting data. <code>total</code> is deduplicated by '
            . 'conversion id, and <code>server_confirmed</code> counts the stored server-confirmed '
            . 'submissions.</li>';
        echo '<li>Deduplicate received deliveries by <code>delivery_id</code> (echoed as the '
            . '<code>Idempotency-Key</code> header) — never by <code>period</code>.</li>';
        echo '<li>An <strong>analytics test</strong> covers the last 7 days, carries <code>"test": true</code>, '
            . 'is never retried, and does not advance the endpoint\'s normal delivery marker — so testing an '
            . 'endpoint never creates a gap in its scheduled reporting.</li>';
        echo '</ul>';
        self::cardEnd();

        self::cardStart('Form submission payload (excerpt)');
        echo '<p>One lead\'s own data plus the analytics context correlated to it. An Analytics Report is the '
            . 'mirror image: reporting data for a window, with no submitted field values — though its '
            . '<code>conversions.recent[]</code> does identify individual conversions. '
            . '<code>ip_address</code> is the submitter\'s address, captured during the visitor\'s own request '
            . 'and frozen with the record; it is always present, and empty when disabled in Settings or when no '
            . 'valid address could be determined.</p>';
        echo '<p><strong>Schema 2.0:</strong> <code>submission_data</code> is an ordered list of '
            . '<code>{id, label, value}</code> field descriptors — the provider-native id for automation, '
            . 'the human label for display, and a string or list of strings for the value. It replaced a '
            . 'label-keyed object that discarded the field id and silently merged two fields sharing a '
            . 'label. Submissions recorded before this change keep emitting <strong>schema 1.0</strong> '
            . 'with their original object, so one submission never arrives in two shapes — '
            . '<strong>branch on <code>schema_version</code>, never on <code>plugin_version</code></strong>. '
            . 'Analytics reports are unaffected and remain 1.0.</p>';
        echo '<pre class="cvm-about-code">' . esc_html('{
    "schema_version": "2.0",
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
        "ip_address": "203.0.113.42",
        "submission_data": [
            { "id": "name",  "label": "Full name",     "value": "John Doe" },
            { "id": "email", "label": "Email address", "value": "john@example.com" },
            { "id": "svc",   "label": "Services",      "value": ["Tax planning", "Retirement"] }
        ]
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
        echo '<p><strong>Field shapes:</strong> the <code>[\'email\' =&gt; $email]</code> map above is fully '
            . 'supported and becomes one field per key (id = label = key). To supply a distinct human label, '
            . 'pass a list of <code>[\'id\' =&gt; …, \'label\' =&gt; …, \'value\' =&gt; …]</code> descriptors '
            . 'instead; <code>value</code> may be a string or a list of strings.</p>';
        echo '<p><strong>Server-side custom events:</strong> <code>cvm_track_event(\'purchase\', [...])</code>. '
            . '<strong>Frontend custom conversions:</strong> '
            . '<code>document.dispatchEvent(new CustomEvent(\'convermetry:conversion\', {detail: {name: \'booked\'}}))</code>.</p>';
        echo '<p><strong>Filters:</strong> <code>convermetry_tracked_event</code>, <code>convermetry_webhook_payload</code>, '
            . '<code>convermetry_webhook_report_limit</code>, <code>convermetry_allowed_hosts</code>, '
            . '<code>convermetry_client_ip</code>, <code>convermetry_rate_limits</code>, '
            . '<code>convermetry_source_aliases</code>, <code>convermetry_channel</code>, '
            . '<code>convermetry_delivery_log_row</code>, <code>convermetry_allow_insecure_webhooks</code>, '
            . '<code>convermetry_form_providers</code>, <code>convermetry_retry_schedule</code>, '
            . '<code>convermetry_notification_retry_schedule</code>, <code>convermetry_sensitive_keys</code>. '
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
        echo '<li>Visitor IP addresses are stored by default on both write paths — every analytics event and '
            . 'every server-confirmed form submission — for fraud checks, spam review, and CRM deduplication. '
            . 'Turn it off under <strong>Settings → Privacy → IP addresses</strong>; new rows then record an empty '
            . 'value while existing rows are untouched and age out with retention. User agents are never stored. '
            . '<strong>In the EU/UK an IP is personal data</strong> — retaining it for general visitor activity '
            . 'usually needs disclosure and a lawful basis.</li>';
        echo '<li>To anonymize instead of disabling, rewrite <code>ip_address</code> in the '
            . '<code>convermetry_tracked_event</code> filter (e.g. zero the last octet) — it runs on every '
            . 'analytics row before it is written. Behind a proxy or CDN, map the real address with '
            . '<code>convermetry_client_ip</code>.</li>';
        echo '<li>When the site honors Do Not Track / Global Privacy Control and a visitor sends one, no IP is '
            . 'stored on either path: their analytics events are not recorded at all, and a form they submit is '
            . 'still recorded and delivered — they actively submitted it — but carries an empty '
            . '<code>ip_address</code>.</li>';
        echo '<li>The Activity Log keeps a redacted copy of each delivery\'s request payload, so IPs appear there too. The form '
            . 'payload\'s copy is replaced when lead-data logging is disabled; an analytics report\'s conversion '
            . 'IPs are logged regardless. Values under sensitive-looking keys (password, token, secret, …) are '
            . 'redacted from stored request and response bodies alike, and log rows age out with retention.</li>';
        echo '<li>No external geolocation service is ever called, and a stored IP is never sent anywhere except '
            . 'your own webhook endpoints.</li>';
        echo '<li>Optional Do Not Track / Global Privacy Control handling, enforced in the tracker, at the REST '
            . 'endpoint, and in the server-side conversion recorder.</li>';
        echo '<li><strong>Form abandonment records no field values.</strong> A validation event is rebuilt on '
            . 'the server from three whitelisted pieces — the field\'s id, its type, and which browser validity '
            . 'check failed — and every other key in the request is discarded by construction. Field ids are '
            . 'character-restricted and truncated, so an implementation that mistakenly sent a typed value would '
            . 'be stripped to something unrecognizable rather than quietly stored.</li>';
        echo '<li><strong>Custom event payloads are not storage.</strong> <code>Convermetry.track()</code> sends '
            . 'the event name and, only where a goal is configured for it, a single numeric value. Nothing else '
            . 'in that object is transmitted. An event matching no goal is discarded and never stored.</li>';
        echo '<li>Goal and funnel records carry no submitted field values — only normalized URLs, the attribution '
            . 'snapshot, and a device bucket. Lead status and value stay on the submission record and its history '
            . 'table, and are never duplicated into event storage.</li>';
        echo '<li>Everything ages out with the configurable retention window; uninstall removes every table, '
            . 'option, and cron event (on every site of a multisite network).</li>';
        echo '</ul>';
        self::cardEnd();

        echo '</div>';
    }
}
