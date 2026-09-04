<?php
declare(strict_types=1);

namespace Convermetry\Api;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Settings\Options;
use Convermetry\Support\ClientIp;
use Convermetry\Support\Errors;
use Convermetry\Support\Url;

/**
 * Public REST endpoint that receives batched events from the frontend tracker.
 *
 * Route: POST /wp-json/convermetry/v1/track
 * Body:  {"batch_id": "b3f2a9c1b8e0d", "events": [{"type": "pageview", ...}, ...]}
 *
 * The endpoint is intentionally unauthenticated — anonymous visitors are the
 * whole point — so it defends itself instead:
 *  - request bodies over a size cap are rejected before JSON parsing,
 *  - an Origin (or Referer) header naming a foreign host is rejected,
 *  - requests from empty or known-bot user agents are silently ignored,
 *  - only whitelisted event types with tracking enabled are accepted,
 *  - every event's page_url must belong to this site's host and is
 *    canonicalized to scheme://host/path (campaign parameters are extracted
 *    into dedicated utm_* fields; all other query data is discarded),
 *  - click/form target URLs are stripped of query strings and fragments,
 *  - every field must be scalar and is sanitized and truncated before storage,
 *  - batches are capped per request, and rate limits are charged per *event*
 *    (not per request), both per-IP and site-wide (see 'convermetry_rate_limits').
 *
 * Delivery from the tracker is at-least-once: a batch whose response is lost
 * is replayed by a later flush. The batch_id the tracker sends makes replays
 * idempotent — rows are stored under a unique (batch_id, event ordinal) index
 * and a replayed batch's rows are skipped instead of inflating every count.
 * When the INSERT itself fails (e.g. the database is briefly unavailable) the
 * endpoint answers 503 so the tracker keeps the batch persisted and retries,
 * rather than acknowledging data that was never stored.
 *
 * The visitor's IP reaches this endpoint twice over, for two unrelated
 * purposes: hashed into a transient rate-limit key here, and — when IP
 * storage is enabled in Settings — written to each event row by
 * DatabaseManager. Both resolve it through Support\ClientIp, which runs
 * 'convermetry_client_ip' once per request and memoizes the result, so the
 * bucket key and the stored address are always the same value.
 */
final class TrackingController
{
    /** REST namespace for all plugin routes. */
    private const string ROUTE_NAMESPACE = 'convermetry/v1';

    /** Maximum events accepted in a single request. */
    private const int MAX_EVENTS_PER_REQUEST = 25;

    /** Maximum request body size in bytes (64 KB). */
    private const int MAX_BODY_BYTES = 65536;

    /** Default maximum events accepted per IP per rate-limit window. */
    private const int RATE_LIMIT_MAX = 300;

    /** Default maximum events accepted site-wide per rate-limit window. */
    private const int RATE_LIMIT_GLOBAL_MAX = 3000;

    /** Rate-limit window length in seconds. */
    private const int RATE_LIMIT_WINDOW = 60;

    /** Object-cache group for rate-limit counters. */
    private const string CACHE_GROUP = 'cvm_rate_limit';

    /** Transient flagging that the site-wide rate limit was hit (read by the dashboard). */
    public const string RATE_LIMITED_FLAG = 'cvm_rate_limited_at';

    /** @var string[] Campaign parameters extracted from attributed events into dedicated columns. */
    private const array CAMPAIGN_PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term', 'utm_content'];

    /**
     * Registers the rest_api_init hook.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);
    }

    /**
     * Registers the /track collection route.
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, '/track', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [self::class, 'handleTrack'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Validates, sanitizes, and stores a batch of tracked events.
     *
     * @param \WP_REST_Request $request The incoming request.
     * @return \WP_REST_Response 202 with {"stored": n}, 400 on a malformed
     *                           body, 403 on a foreign Origin/Referer, 413 on
     *                           an oversized body, 429 when rate-limited, or
     *                           503 when the events could not be stored (the
     *                           tracker keeps the batch and retries).
     */
    public static function handleTrack(\WP_REST_Request $request): \WP_REST_Response
    {
        if (strlen((string) $request->get_body()) > self::MAX_BODY_BYTES) {
            return new \WP_REST_Response(['error' => 'payload_too_large'], 413);
        }

        if (self::isForeignRequest($request)) {
            return new \WP_REST_Response(['error' => 'foreign_origin'], 403);
        }

        // Belt-and-braces: the tracker script is never enqueued for logged-in
        // users when exclusion is on, but drop any stray authenticated batch too.
        if (Options::excludeLoggedIn() && is_user_logged_in()) {
            return new \WP_REST_Response(['stored' => 0], 202);
        }

        // The tracker never starts for DNT/GPC visitors when the option is on;
        // enforcing it here too covers senders that bypass the tracker.
        if (Options::respectDnt() && self::sendsPrivacySignal($request)) {
            return new \WP_REST_Response(['stored' => 0], 202);
        }

        // Crawlers that execute JS would otherwise pollute every metric.
        // Accept-and-discard so bots see nothing worth probing.
        if (self::isBot()) {
            return new \WP_REST_Response(['stored' => 0], 202);
        }

        $body   = $request->get_json_params();
        $events = is_array($body) && isset($body['events']) ? $body['events'] : null;

        if (!is_array($events) || $events === []) {
            return new \WP_REST_Response(['error' => 'invalid_payload'], 400);
        }

        $events = array_slice($events, 0, self::MAX_EVENTS_PER_REQUEST);

        if (!self::chargeRateLimit(count($events))) {
            /**
             * Fires when a tracking batch is rejected by the rate limiter.
             *
             * Fires once per rejected request, before anything is stored.
             *
             * Carries NO identity — not the IP address and not a hash of one.
             * The tracking endpoint is public and unauthenticated, so this
             * action fires on input from anyone at all; handing every caller's
             * address to arbitrary listeners would turn a rate limiter into a
             * visitor log. What a listener can legitimately do with this is
             * alert on volume: a site that is suddenly shedding batches is
             * either under attack or has outgrown its limits, and
             * convermetry_rate_limits is where the limits are raised.
             *
             * @param int $events The number of events in the rejected batch.
             * @param int $window The rate-limit window in seconds.
             */
            do_action('convermetry_tracking_rate_limited', count($events), self::RATE_LIMIT_WINDOW);

            // Tell the tracker how long to wait rather than leaving it to
            // guess — this is the one piece of pacing only the server knows.
            return new \WP_REST_Response(['error' => 'rate_limited'], 429, ['Retry-After' => '60']);
        }

        // The tracker's batch id makes replayed batches idempotent (see the
        // class docblock). A missing or malformed id falls back to null —
        // those inserts are not deduplicated.
        $batchId = null;
        if (isset($body['batch_id']) && is_string($body['batch_id'])
            && preg_match('~^[A-Za-z0-9_\-]{8,40}$~', $body['batch_id'])
        ) {
            $batchId = $body['batch_id'];
        }

        $device = wp_is_mobile() ? 'mobile' : 'desktop';
        $batch  = [];

        foreach ($events as $index => $event) {
            $sanitized = self::sanitizeEvent($event, $device);
            if ($sanitized !== null) {
                // 'seq' is the event's position in the ORIGINAL batch — the
                // stable half of the (batch_id, seq) dedup key, unaffected by
                // how many neighboring events sanitization drops.
                $sanitized['seq'] = (int) $index;
                $batch[]          = $sanitized;
            }
        }

        // One multi-row INSERT instead of one query per event.
        $stored = DatabaseManager::insertEvents($batch, $batchId);

        // A failed INSERT must not be acknowledged: a 2xx would make the
        // tracker discard the batch permanently, silently losing analytics
        // during a transient database outage. 503 keeps the batch persisted
        // client-side; the batch id deduplicates the eventual replay.
        if ($stored === false) {
            return new \WP_REST_Response(['error' => 'storage_unavailable'], 503);
        }

        /**
         * Fires after a batch of tracking events has been written.
         *
         * One action per BATCH, deliberately, rather than one per event. The
         * tracker sends up to 25 events per request and the endpoint is the
         * hottest path in the plugin; a per-event action would run arbitrary
         * third-party code dozens of times per visitor and turn every listener
         * into a page-speed regression.
         *
         * The three counts differ and the difference is the useful part:
         * $offered is what the browser sent, $accepted is what survived the
         * whitelist and the convermetry_should_track_event filter, and $stored
         * is what the INSERT actually created — lower again when a replayed
         * batch was deduplicated on (batch_id, seq).
         *
         * Does not fire when the batch was rate limited or rejected by the
         * privacy, bot, or origin gates, none of which reach a write.
         *
         * @param int         $stored   Rows actually inserted.
         * @param int         $accepted Events that passed sanitization.
         * @param int         $offered  Events the request contained.
         * @param string|null $batchId  The tracker's batch id, or null when absent/malformed.
         */
        do_action('convermetry_tracking_batch_recorded', $stored, count($batch), count($events), $batchId);

        return new \WP_REST_Response(['stored' => $stored], 202);
    }

    /**
     * Whether the request carries a Do Not Track or Global Privacy Control
     * header. Only consulted when the site owner enabled the privacy-signal
     * option — DNT/GPC is honored as an opt-out, not treated as consent.
     *
     * @param \WP_REST_Request $request The incoming request.
     * @return bool
     */
    private static function sendsPrivacySignal(\WP_REST_Request $request): bool
    {
        return $request->get_header('dnt') === '1'
            || $request->get_header('sec_gpc') === '1'
            || $request->get_header('sec-gpc') === '1';
    }

    /**
     * Validates a single raw event from the request body.
     *
     * @param mixed  $event  Raw event entry from the JSON body.
     * @param string $device Device bucket derived from the request's user agent.
     * @return array{type: string, data: array<string, string|bool|list<string>>}|null Null when the
     *         event is malformed, of an unknown or disabled type, or claims a
     *         page_url that does not belong to this site.
     */
    private static function sanitizeEvent(mixed $event, string $device): ?array
    {
        if (!is_array($event)) {
            return null;
        }

        $type = sanitize_key(self::scalarString($event['type'] ?? ''));

        if (!in_array($type, Options::EVENT_TYPES, true) || !Options::isTypeEnabled($type)) {
            return null;
        }

        $pageUrl = self::normalizePageUrl(self::scalarString($event['page_url'] ?? ''));
        if ($pageUrl === '') {
            return null;
        }

        // A confirmed conversion without a usable conversion id would break
        // the dedup guarantee downstream. The tracker always generates one
        // ('c' + 16 hex chars), so a form_success without a valid, bounded
        // id is rejected outright.
        if ($type === 'form_success'
            && !preg_match('~^[A-Za-z0-9_.:\-]{8,100}$~', self::scalarString($event['event_value'] ?? ''))
        ) {
            return null;
        }

        $data = [
            'page_url'      => $pageUrl,
            'page_title'    => self::scalarString($event['page_title'] ?? ''),
            'element_tag'   => self::scalarString($event['element_tag'] ?? ''),
            'element_label' => self::scalarString($event['element_label'] ?? ''),
            'target_url'    => self::normalizeTargetUrl(self::scalarString($event['target_url'] ?? '')),
            'event_value'   => self::scalarString($event['event_value'] ?? ''),
            'referrer'      => self::normalizeReferrer(self::scalarString($event['referrer'] ?? '')),
            'session_id'    => self::scalarString($event['session_id'] ?? ''),
            'device'        => $device,
        ];

        // The form lifecycle's shared dimension. Accepted only for those types;
        // DatabaseManager independently refuses to store it on anything else, so
        // a crafted request cannot attach a form identity to a pageview and
        // pollute the engagement reports.
        if (in_array($type, self::FORM_TYPES, true)) {
            $data['form_key'] = self::scalarString($event['form_key'] ?? '');
        }

        // A validation error is the one event that describes a specific FIELD,
        // which makes it the one place where a careless implementation could
        // record what a visitor typed. It is rebuilt from three whitelisted
        // pieces rather than sanitized in place: the field's id, the field's
        // type, and an error CATEGORY from a fixed list. Anything else the
        // request contained — including a 'value' key — is discarded here, and
        // is discarded by construction rather than by a blocklist that would
        // need updating every time a browser invents a new property.
        if ($type === 'form_error') {
            $data['element_label'] = self::fieldIdentifier(self::scalarString($event['field_id'] ?? ''));
            $data['element_tag']   = self::scalarString($event['field_type'] ?? '');
            $data['event_value']   = self::errorType(self::scalarString($event['error_type'] ?? ''));
            $data['target_url']    = '';
        }

        // A custom event's NAME is the only thing that can match a goal. Its
        // payload is never stored as text; a numeric value is read only when a
        // goal is configured to accept one, and it is parsed to an exact
        // decimal by Money before it goes anywhere near the database.
        if ($type === 'custom_event') {
            $data['element_label'] = self::scalarString($event['name'] ?? '');
            $data['element_tag']   = 'custom';
            $data['event_value']   = '';
            $data['target_url']    = '';
            $data['goal_value']    = self::scalarString($event['value'] ?? '');
        }

        // Goal ingestion context. Neither is a stored column: the landing page
        // is written only onto a goal completion, and the selector matches are
        // re-validated against the configured goals and then discarded.
        if (in_array($type, self::GOAL_ELIGIBLE_TYPES, true)) {
            $data['session_landing'] = self::normalizePageUrl(self::scalarString($event['session_landing'] ?? ''));

            if (isset($event['selector_goals']) && is_array($event['selector_goals'])) {
                $data['selector_goals'] = array_values(array_filter(
                    array_map([self::class, 'scalarString'], $event['selector_goals']),
                    static fn(string $id): bool => $id !== ''
                ));
            }
        }

        // Campaign attribution rides on EVERY tracker event — clicks, form
        // attempts, hovers, and scroll milestones are all segmentable by
        // channel, not just pageviews and conversions — so this is
        // unconditional. It used to be guarded by a private list of
        // "attributed types" that was a verbatim copy of Options::EVENT_TYPES;
        // the guard could therefore never be false, and the copy was one more
        // list to forget when a type is added. Which types get a CHANNEL
        // derived is a separate, still-guarded decision, and it belongs to the
        // storage layer: see DatabaseManager::ATTRIBUTED_TYPES.
        //
        // Only the ad-click identifier's TYPE is accepted — the value itself is
        // never sent or stored. session_referrer is the referrer the session
        // ENTERED through; session_direct is the explicit "verified Direct"
        // marker. Neither is stored as its own column — they feed channel
        // classification only.
        foreach (self::CAMPAIGN_PARAMS as $param) {
            $data[$param] = self::campaignValue(self::scalarString($event[$param] ?? ''));
        }
        $data['click_id_type']    = sanitize_key(self::scalarString($event['click_id_type'] ?? ''));
        $data['session_referrer'] = self::normalizeReferrer(self::scalarString($event['session_referrer'] ?? ''));
        $data['session_direct']   = self::scalarString($event['session_direct'] ?? '') === '1';

        /**
         * Filters whether to record one tracked event.
         *
         * Runs LAST in sanitization, and that placement is the security
         * property: by the time a callback sees an event, its type has been
         * checked against the enabled list, its URLs normalized and stripped of
         * query strings, its campaign values validated, and every field it
         * carries reduced to a known key with a bounded scalar value. The raw
         * request body from the public, unauthenticated tracking endpoint is
         * never exposed to a hook — a callback that received it would be handed
         * attacker-controlled input on the plugin's hottest path.
         *
         * Return false to drop this event. The rest of the batch is unaffected,
         * and the response still reports success to the tracker: a dropped event
         * is a site's own decision, not an error the browser should retry.
         *
         * Runs once per event within a batch, on the visitor's request, before
         * any write. Goal matching happens after storage, so dropping an event
         * also drops any goal it would have completed.
         *
         * @param bool                 $should Whether to record. Default true.
         * @param string               $type   Sanitized event type.
         * @param array<string, mixed> $data   Sanitized, whitelisted event fields.
         */
        if (!apply_filters('convermetry_should_track_event', true, $type, $data)) {
            return null;
        }

        return ['type' => $type, 'data' => $data];
    }

    /**
     * @var string[] Event types that carry a form identity.
     */
    private const array FORM_TYPES = ['form_view', 'form_start', 'form_error', 'form_submit', 'form_success'];

    /**
     * @var string[] Event types a configured goal can be matched against, and
     *      therefore the only ones that carry goal-ingestion context.
     */
    private const array GOAL_ELIGIBLE_TYPES = ['pageview', 'click', 'custom_event'];

    /**
     * @var string[] The error CATEGORIES a validation event may report.
     *
     * Taken from the HTML5 ValidityState flags, because that is the one source
     * of validation state every form provider shares and none of it derives from
     * what the visitor typed. A category outside this list is stored as
     * 'invalid' rather than passed through — the list is a whitelist precisely
     * so that a browser (or a crafted request) cannot smuggle free text into a
     * column that reporting will later display.
     */
    private const array ERROR_TYPES = [
        'required', 'type_mismatch', 'pattern', 'too_short', 'too_long',
        'range', 'step', 'invalid',
    ];

    /** Maximum characters kept from a field identifier. */
    private const int MAX_FIELD_ID_LEN = 64;

    /**
     * Reduces a reported field identifier to something that cannot carry a
     * visitor's answer.
     *
     * A field id is a developer-chosen name ("phone", "desired-service",
     * "field_a1b2c3"), so it is restricted to the characters those actually use
     * and truncated hard. An implementation that sent a typed value here instead
     * would be stripped to nothing recognizable rather than quietly stored, and
     * the length bound alone rules out message bodies and email addresses.
     *
     * @param string $raw Reported field id.
     * @return string
     */
    private static function fieldIdentifier(string $raw): string
    {
        $clean = (string) preg_replace('~[^A-Za-z0-9_.:\[\]-]~', '', $raw);

        return substr($clean, 0, self::MAX_FIELD_ID_LEN);
    }

    /**
     * Maps a reported validation error to a known category.
     *
     * @param string $raw Reported error type.
     * @return string One of {@see self::ERROR_TYPES}.
     */
    private static function errorType(string $raw): string
    {
        $key = sanitize_key($raw);

        return in_array($key, self::ERROR_TYPES, true) ? $key : 'invalid';
    }

    /**
     * Filters one campaign (utm_*) value before storage.
     *
     * Campaign values are meant to be labels like "spring-sale", but nothing
     * stops a mailer from interpolating the recipient's address into a UTM
     * parameter — that would be PII, so any value containing an email-like
     * "@" is dropped rather than stored.
     *
     * @param string $value Raw campaign parameter value.
     * @return string The value, or '' when it looks like it contains an email.
     */
    private static function campaignValue(string $value): string
    {
        return str_contains($value, '@') ? '' : $value;
    }

    /**
     * Returns a scalar value as a string, and anything else as ''.
     *
     * @param mixed $value Raw value from the request body.
     * @return string
     */
    private static function scalarString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Normalizes a tracked page URL — see {@see Url::pageUrl()}, which owns the
     * rule so the correlation reader and goal ingestion cannot drift from it.
     *
     * @param string $url Raw page URL from the event.
     * @return string Canonical URL, or '' when the URL is invalid or foreign.
     */
    private static function normalizePageUrl(string $url): string
    {
        return Url::pageUrl($url);
    }

    /**
     * Normalizes a click/form destination URL — see {@see Url::targetUrl()}.
     *
     * @param string $url Raw destination from the event.
     * @return string Normalized destination, or '' when empty or unsafe.
     */
    private static function normalizeTargetUrl(string $url): string
    {
        return Url::targetUrl($url);
    }

    /**
     * Normalizes a referrer URL — see {@see Url::referrer()}.
     *
     * @param string $url Raw referrer from the event.
     * @return string Normalized referrer, or '' when unparsable.
     */
    private static function normalizeReferrer(string $url): string
    {
        return Url::referrer($url);
    }

    /**
     * Whether the request carries an Origin (or, failing that, Referer)
     * header naming a foreign host.
     *
     * Browsers always send Origin with the tracker's POSTs, so a mismatch is
     * a strong foreign signal. Requests with neither header (e.g. some
     * privacy tools) are allowed through — the per-event host check and rate
     * limits still apply.
     *
     * @param \WP_REST_Request $request The incoming request.
     * @return bool True when the request should be rejected.
     */
    private static function isForeignRequest(\WP_REST_Request $request): bool
    {
        foreach (['origin', 'referer'] as $header) {
            $value = (string) $request->get_header($header);
            if ($value === '') {
                continue;
            }

            $host = strtolower((string) wp_parse_url($value, PHP_URL_HOST));

            return $host === '' || !in_array($host, Options::allowedHosts(), true);
        }

        return false;
    }

    /**
     * Whether the request's user agent is empty or a known crawler.
     *
     * @return bool
     */
    private static function isBot(): bool
    {
        $ua = isset($_SERVER['HTTP_USER_AGENT'])
            ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']))
            : '';

        if ($ua === '') {
            return true;
        }

        return (bool) preg_match('~bot|crawl|spider|slurp|preview|headless|curl|wget|python-requests~i', $ua);
    }

    /**
     * Charges $events against both the per-IP and the site-wide rate limit.
     *
     * Charging by event count (not request count) closes the gap where a
     * sender packs the maximum batch into every request; the site-wide
     * bucket bounds distributed floods and, with it, table growth. Tune both
     * limits via the 'convermetry_rate_limits' filter, and put edge/WAF
     * protection in front of very-high-traffic sites.
     *
     * @param int $events Number of events in this request.
     * @return bool True when the request is within both limits.
     */
    private static function chargeRateLimit(int $events): bool
    {
        $limits = self::rateLimits();
        $ip     = self::clientIp();

        // Per-IP first, and reject WITHOUT touching the site-wide bucket:
        // charging the global budget for already-rejected requests would let
        // a single flooding IP burn through the entire site-wide allowance
        // and block legitimate visitors for the rest of the window.
        if ($ip !== '' && !self::chargeBucket('cvm_rl_' . md5($ip), $events, $limits['per_ip'])) {
            return false;
        }

        $siteAllowed = self::chargeBucket('cvm_rl_site', $events, $limits['site_wide']);

        // Hitting the site-wide cap means legitimate events may be dropped —
        // surface that on the dashboard instead of failing silently.
        if (!$siteAllowed && get_transient(self::RATE_LIMITED_FLAG) === false) {
            set_transient(self::RATE_LIMITED_FLAG, time(), DAY_IN_SECONDS);
        }

        return $siteAllowed;
    }

    /**
     * Returns the effective rate limits (events per minute).
     *
     * @return array{per_ip: int, site_wide: int}
     */
    private static function rateLimits(): array
    {
        $defaults = [
            'per_ip'    => self::RATE_LIMIT_MAX,
            'site_wide' => self::RATE_LIMIT_GLOBAL_MAX,
        ];

        /**
         * Filters the ingestion rate limits, in events per minute.
         *
         * @param array{per_ip: int, site_wide: int} $defaults The default limits.
         */
        $limits = apply_filters('convermetry_rate_limits', $defaults);
        $limits = is_array($limits) ? $limits : $defaults;

        return [
            'per_ip'    => max(1, (int) ($limits['per_ip'] ?? $defaults['per_ip'])),
            'site_wide' => max(1, (int) ($limits['site_wide'] ?? $defaults['site_wide'])),
        ];
    }

    /**
     * Adds $events to one rolling counter and reports whether it is under $max.
     *
     * FAILS CLOSED. A refused counter write, a counter that cannot be read
     * back, and a malformed value all REJECT the request and emit sanitized
     * telemetry. The result of the charge used to be discarded, and an
     * unreadable counter fell back to this request's own event count — which is
     * below any sane cap — so a broken counter waved every request through
     * while reporting nothing.
     *
     * Uses an atomic object-cache increment when a persistent object cache is
     * available; otherwise — and whenever the cache increment fails — an
     * atomic single-statement counter in the options table
     * (INSERT … ON DUPLICATE KEY UPDATE, so concurrent requests can never all
     * read one stale count and be accepted together). The row stores
     * "window|count" and resets itself whenever the minute-window rolls over;
     * rows are purged by the daily cleanup cron and on uninstall.
     *
     * @param string $key    Counter key.
     * @param int    $events Amount to charge.
     * @param int    $max    Maximum events per window.
     * @return bool True when the counter (including this charge) is within $max.
     */
    private static function chargeBucket(string $key, int $events, int $max): bool
    {
        if (wp_using_ext_object_cache()) {
            wp_cache_add($key, 0, self::CACHE_GROUP, self::RATE_LIMIT_WINDOW);
            $count = wp_cache_incr($key, $events, self::CACHE_GROUP);

            if ($count !== false) {
                return $count <= $max;
            }
            // The increment failed (key evicted between add and incr, or the
            // cache backend errored). Fail CLOSED into the database counter
            // below rather than waving the request through — a flaky cache
            // must not silently disable the rate limit.
        }

        global $wpdb;

        // The window is computed by MySQL from ITS OWN clock, inside the
        // statement, rather than in PHP before the query is issued. PHP-side
        // windows could move the counter BACKWARDS: a request that computed
        // window N but reached the server after a request that had already
        // written window N+1 took the "different window" branch and reset the
        // row to N, discarding the newer window's accumulated count. Two
        // requests straddling a minute boundary could flip the row back and
        // forth, resetting the total each time and under-counting a flood at
        // exactly the moment the limit matters.
        //
        // Evaluating UNIX_TIMESTAMP() inside the statement removes the race by
        // construction: statements against one row are serialised, so they all
        // read one clock in a fixed order.
        //
        // The comparison is >= rather than =, which makes the window monotonic
        // outright: a stored window at or ahead of the current one is charged
        // INTO, never replaced. Only a genuinely older window resets the row.
        // That also survives the database clock being stepped backwards by NTP,
        // which would otherwise discard a newer window's accumulated count.
        //
        // The REGEXP guard is load-bearing, not defensive decoration. Under
        // STRICT_TRANS_TABLES — the MySQL 8 default — CAST()ing a non-numeric
        // value raises ERROR 1292 rather than coercing to 0, which would fail
        // the whole statement. Combined with failing closed, a single corrupted
        // counter row would then reject EVERY tracking request until someone
        // edited the database by hand. Guarding the shape first means a
        // malformed value simply takes the reset branch and self-heals.
        //
        // These rows are written directly (never through get_option/
        // update_option) so they bypass — and can never pollute — WordPress's
        // option caches.
        $charged = $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, CONCAT(FLOOR(UNIX_TIMESTAMP() / %d), '|', %d), 'off')
             ON DUPLICATE KEY UPDATE option_value = IF(
                 option_value REGEXP '^[0-9]+[|][0-9]+$'
                 AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED)
                     >= FLOOR(UNIX_TIMESTAMP() / %d),
                 CONCAT(
                     SUBSTRING_INDEX(option_value, '|', 1),
                     '|',
                     CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) + %d
                 ),
                 CONCAT(FLOOR(UNIX_TIMESTAMP() / %d), '|', %d)
             )",
            $key,
            self::RATE_LIMIT_WINDOW,
            $events,
            self::RATE_LIMIT_WINDOW,
            $events,
            self::RATE_LIMIT_WINDOW,
            $events
        ));

        // The charge itself failed, so this request's events were never counted
        // against the window. Continuing would let EVERY request through for as
        // long as the counter stays unwritable, which is precisely when a limiter
        // matters most. The documented intent is to fail CLOSED, so do that.
        if ($charged === false) {
            Errors::storage('tracking', 'rate_limit_charge', 'counter_write_failed', [
                'events' => $events,
            ]);

            return false;
        }

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $key
        ));

        if (!is_string($value) || $value === '') {
            Errors::storage('tracking', 'rate_limit_verify', 'counter_unreadable', [
                'events' => $events,
            ]);

            return false;
        }

        $parts = explode('|', $value);

        if (count($parts) !== 2 || !ctype_digit($parts[0]) || !ctype_digit($parts[1])) {
            Errors::storage('tracking', 'rate_limit_verify', 'counter_malformed', [
                'events' => $events,
            ]);

            return false;
        }

        // Which window the row names does not change the decision, so it is not
        // compared against a PHP-side clock — doing so rejected legitimate
        // requests on every window boundary and reported a phantom failure.
        //
        // Whatever window the row holds, its count is authoritative: every
        // charge either increments the current window or resets the row to it,
        // so a row still naming an older window is one nothing has charged
        // since, and its count is the one this request was added to. The read
        // runs after the increment, so under heavy concurrency it may also see
        // later charges — the count is >= this request's true position, which
        // can only over-reject at the margin, never let the stored volume
        // exceed the cap.
        return (int) $parts[1] <= $max;
    }

    /**
     * The client IP used for rate limiting.
     *
     * Delegates to the shared resolver, which filters, validates, and
     * memoizes once per request — so the rate-limit bucket and the IP stored
     * on event rows are literally the same value, even if
     * 'convermetry_client_ip' is stateful. An unresolvable address yields '',
     * and the caller then charges only the site-wide bucket.
     *
     * @return string
     */
    private static function clientIp(): string
    {
        return ClientIp::get();
    }
}
