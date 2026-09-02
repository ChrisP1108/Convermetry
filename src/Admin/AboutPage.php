<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

/**
 * The "Convermetry → About" submenu page — the plugin's documentation
 * inside wp-admin: what it does, how the pieces connect, the identifier
 * model, payload samples, the complete action/filter hook reference, and the
 * privacy posture.
 *
 * The page is long by design — it is the README rendered where an
 * administrator already is. To keep it navigable it is divided into anchored
 * sections fronted by a sticky nav bar (see {@see SECTIONS}), which is
 * generated from one list so a section can never exist without its link, or
 * a link without its section.
 */
final class AboutPage
{
    /** Menu slug for the submenu page. */
    public const string MENU_SLUG = 'convermetry-about';

    /**
     * The page's sections, in render order: anchor id => sticky-nav label.
     *
     * One source of truth for both the nav and the section headings.
     *
     * @var array<string, string>
     */
    private const array SECTIONS = [
        'overview'      => 'Overview',
        'admin-pages'   => 'Admin pages',
        'tracking'      => 'Tracking',
        'conversions'   => 'Conversions',
        'forms'         => 'Forms',
        'identifiers'   => 'Identifiers',
        'webhooks'      => 'Webhooks',
        'payloads'      => 'Payloads',
        'notifications' => 'Notifications',
        'developer'     => 'Developer API',
        'hooks'         => 'Hooks',
        'rest'          => 'REST APIs',
        'privacy'       => 'Privacy & data',
    ];

    /**
     * The hook groups, in render order: group name => introductory note.
     *
     * @var array<string, string>
     */
    private const array HOOK_GROUPS = [
        'Webhook delivery' =>
            'Composition filters run <strong>once per logical delivery, before the request is '
                . 'frozen</strong>. A retry re-sends the frozen bytes and never re-runs them, so nothing here can '
                . 'change a delivery already in flight. The lifecycle actions all receive the same credential-free '
                . '<code>$context</code>: <code>message_type</code>, <code>kind</code> '
                . '(<code>scheduled</code>/<code>immediate</code>/<code>retry</code>/<code>test</code>), '
                . '<code>attempt</code>, <code>delivery_id</code>, <code>is_test</code>, <code>endpoint_key</code>, '
                . '<code>endpoint_label</code>, <code>endpoint_origin</code> (scheme + host only), '
                . '<code>submission_id</code>, <code>conversion_id</code>, <code>form_key</code>, '
                . '<code>window_start</code>, <code>window_end</code>, <code>transport_attempted</code>, '
                . '<code>disposition</code>. Every key is always present.'
            ,
        'Tracking' =>
            'The ingestion path is the plugin\'s hottest code. These hooks run inside it, so keep callbacks '
                . 'cheap — and register them early enough to exist: a theme\'s <code>functions.php</code> is already '
                . 'too late for some.'
            ,
        'Analytics' =>
            'The dashboard and the analytics payload read through one query layer, so a section registered '
                . 'here appears in both rather than in one of them.'
            ,
        'Forms &amp; submissions' =>
            'The submission pipeline in hook order: decide whether to record, shape the fields, attach '
                . 'context, then observe what was written. Anything marked as carrying PII sees real submitted '
                . 'values.'
            ,
        'Goals, funnels &amp; leads' =>
            'Goal completions are written with <code>INSERT IGNORE</code> against a dedupe key, which is why '
                . 'the counts these hooks carry differ from the rows they were offered.'
            ,
        'Notifications' =>
            'One queue row is one recipient. That is why the recipient list is filtered once at queue time '
                . 'and cannot be changed per attempt.'
            ,
        'Operations, settings &amp; API' =>
            'Observability for the work that runs unattended — retention passes, schema migrations, verified '
                . 'storage failures — plus the two hooks that decide who may see an admin screen and what a REST '
                . 'item carries.'
            ,
    ];

    /**
     * Every public hook: [name, type, signature, group, summary].
     *
     * Kept in step with README.md's hook reference — the two are the same
     * catalogue, rendered for two audiences.
     *
     * @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private const array HOOKS = [
        [
            'convermetry_webhook_payload',
            'filter',
            'apply_filters(\'convermetry_webhook_payload\', array $payload, string $messageType, array $meta)',
            'Webhook delivery',
            'Modify any outbound payload before it is frozen/encoded. <code>$meta</code> is '
                . '<code>[\'start\',\'end\']</code> for reports, <code>[\'submission_id\']</code> for submissions. Runs '
                . 'last, after the extensions filter'
            ,
        ],
        [
            'convermetry_webhook_payload_extensions',
            'filter',
            'apply_filters(\'convermetry_webhook_payload_extensions\', array $extensions, string $messageType, array $meta)',
            'Webhook delivery',
            'Add namespaced data as the payload\'s <code>extensions</code> property. Keys must be '
                . '<code>vendor/thing</code>; bounded to 32 KB / 50 keys / JSON primitives; an empty result adds no '
                . 'property at all'
            ,
        ],
        [
            'convermetry_webhook_query_args',
            'filter',
            'apply_filters(\'convermetry_webhook_query_args\', array $params, array $context)',
            'Webhook delivery',
            'Query parameters appended to the endpoint URL, after the global → page → per-form → runtime '
                . 'merge. The result is re-normalized to bounded scalar keys/values, order preserved; the URL still passes '
                . '<code>wp_safe_remote_post()</code>\'s SSRF checks'
            ,
        ],
        [
            'convermetry_webhook_headers',
            'filter',
            'apply_filters(\'convermetry_webhook_headers\', array $headers, array $context)',
            'Webhook delivery',
            'Non-protocol request headers, after the global → per-form → runtime merge. A callback may '
                . '<strong>not</strong> add, alter, or remove <code>Content-Type</code>, <code>Host</code>, '
                . '<code>Content-Length</code>, <code>Transfer-Encoding</code>, <code>Connection</code>, '
                . '<code>User-Agent</code>, <code>Idempotency-Key</code>, or <code>X-Convermetry-Signature</code>; '
                . 'those are restored to their pre-filter state. Values are sent as-is, and the Activity Log '
                . 'redacts by NAME'
            ,
        ],
        [
            'convermetry_webhook_timeout',
            'filter',
            'apply_filters(\'convermetry_webhook_timeout\', int $timeout, array $context)',
            'Webhook delivery',
            'HTTP timeout in seconds for one attempt (default 15). Runs per network attempt; values outside '
                . '1–30 are <strong>ignored, not clamped</strong>. Raising it costs queue throughput: the worker\'s '
                . 'whole pass budget is 45s. Redirects, TLS verification, blocking mode, and the response-size cap '
                . 'are deliberately not filterable'
            ,
        ],
        [
            'convermetry_form_delivery_queued',
            'action',
            'do_action(\'convermetry_form_delivery_queued\', array $context);',
            'Webhook delivery',
            'A submission was genuinely queued for one endpoint. Only when the <code>INSERT IGNORE</code> '
                . 'created a row; a suppressed duplicate fires nothing. Nothing is sent or frozen yet'
            ,
        ],
        [
            'convermetry_webhook_delivery_frozen',
            'action',
            'do_action(\'convermetry_webhook_delivery_frozen\', array $context, string $storage, int $bodyBytes);',
            'Webhook delivery',
            'A delivery\'s body, URL, and headers are now fixed. <code>$storage</code> is '
                . '<code>\'memory\'</code> (analytics, persisted only if a retry follows) or <code>\'queue_row\'</code> '
                . '(form queue, verified written). Never fires on a frozen retry, nor on the synchronous or test '
                . 'paths, which freeze nothing'
            ,
        ],
        [
            'convermetry_webhook_before_send',
            'action',
            'do_action(\'convermetry_webhook_before_send\', array $context, array $meta);',
            'Webhook delivery',
            'Immediately before a real network request, after signing. <code>$meta</code> is '
                . '<code>[\'body_bytes\',\'body_sha256\',\'header_names\',\'signed\']</code>, metadata only: no URL, no '
                . 'header values, no body. Fires per attempt; does <strong>not</strong> fire when encoding or a '
                . 'report query failed before the wire. Do not throw from here — the request it announces would not '
                . 'happen'
            ,
        ],
        [
            'convermetry_webhook_delivery_attempted',
            'action',
            'do_action(\'convermetry_webhook_delivery_attempted\', array $context, bool $ok, int $code, string $message);',
            'Webhook delivery',
            'One attempt\'s <strong>transport</strong> result. <code>transport_attempted</code> is false when '
                . 'nothing reached the wire. Deliberately does not report the retry/queue disposition; nothing has '
                . 'decided one yet'
            ,
        ],
        [
            'convermetry_delivery_attempt_logged',
            'action',
            'do_action(\'convermetry_delivery_attempt_logged\', array $context, string $disposition);',
            'Webhook delivery',
            'What became of the Activity Log row. <code>\'stored\'</code>, <code>\'suppressed\'</code> (a '
                . '<code>convermetry_delivery_log_row</code> callback returned false), or <code>\'failed\'</code>'
            ,
        ],
        [
            'convermetry_webhook_delivery_succeeded',
            'action',
            'do_action(\'convermetry_webhook_delivery_succeeded\', array $context);',
            'Webhook delivery',
            'The endpoint accepted it <strong>and</strong> the bookkeeping committed. Analytics: last-sent '
                . 'advanced and retry chain cleared; form queue: row deleted and delivery state recomputed. Once '
                . 'per successful attempt per endpoint'
            ,
        ],
        [
            'convermetry_webhook_retry_scheduled',
            'action',
            'do_action(\'convermetry_webhook_retry_scheduled\', array $context, int $nextAttempt, int $nextAttemptAt);',
            'Webhook delivery',
            'The next attempt is persisted. Never speculative; never on a test or on the synchronous path'
            ,
        ],
        [
            'convermetry_webhook_retry_chain_exhausted',
            'action',
            'do_action(\'convermetry_webhook_retry_chain_exhausted\', array $context);',
            'Webhook delivery',
            'An <strong>analytics</strong> chain gave up, and its terminal state is persisted. <em>Not</em> '
                . 'abandonment: the frozen body stays in the retry state and the next scheduled dispatch resumes '
                . 'it. Read as "this endpoint is failing", not "this data is gone"'
            ,
        ],
        [
            'convermetry_webhook_delivery_abandoned',
            'action',
            'do_action(\'convermetry_webhook_delivery_abandoned\', array $context, string $reason);',
            'Webhook delivery',
            'A queued <strong>form</strong> delivery is gone for good, its row deleted. Genuinely terminal, '
                . 'unlike the analytics chain'
            ,
        ],
        [
            'convermetry_webhook_delivery_canceled',
            'action',
            'do_action(\'convermetry_webhook_delivery_canceled\', array $context, string $reason);',
            'Webhook delivery',
            'A queued delivery was removed unsent. Currently <code>\'submission_deleted\'</code>: the '
                . 'submission was deleted before the worker reached the row'
            ,
        ],
        [
            'convermetry_retry_schedule',
            'filter',
            'apply_filters(\'convermetry_retry_schedule\', int[] $delays)',
            'Webhook delivery',
            'The webhook retry backoff delays in seconds (both message types). Default <code>[300, 1800, '
                . '7200, 21600, 57600]</code>; each entry is clamped to a minimum of 60, and an empty or fully '
                . 'invalid list falls back to the default. The list length <strong>is</strong> the attempt count'
            ,
        ],
        [
            'convermetry_webhook_report_limit',
            'filter',
            'apply_filters(\'convermetry_webhook_report_limit\', int $limit)',
            'Webhook delivery',
            'Max rows per <code>top_*</code> list in analytics payloads. Default 200, clamped to a minimum of '
                . '1. Does <strong>not</strong> apply to <code>conversions.recent[]</code>, which is lossless: a '
                . 'window holding more than 100 conversions is split across consecutive deliveries rather than '
                . 'truncated'
            ,
        ],
        [
            'convermetry_delivery_log_row',
            'filter',
            'apply_filters(\'convermetry_delivery_log_row\', array|false $row)',
            'Webhook delivery',
            'Redact/modify an Activity Log row before storage. Return an array to store it, anything else to '
                . 'skip logging that attempt; skipping affects only the log, never the delivery. Bodies reaching it '
                . 'are already sensitive-key-redacted and capped at 64 KB. The field-level redaction point for '
                . 'anything the built-in policy misses, including an analytics report\'s '
                . '<code>conversions.recent[].ip_address</code>'
            ,
        ],
        [
            'convermetry_allow_insecure_webhooks',
            'filter',
            'apply_filters(\'convermetry_allow_insecure_webhooks\', bool $allow)',
            'Webhook delivery',
            'Return <code>true</code> to allow <code>http://</code> endpoints (development only). Evaluated '
                . 'when endpoints are <strong>saved</strong>, not at send time, so turning it back off does not '
                . 'retire an <code>http://</code> endpoint already stored'
            ,
        ],
        [
            'convermetry_allowed_hosts',
            'filter',
            'apply_filters(\'convermetry_allowed_hosts\', string[] $hosts)',
            'Webhook delivery',
            'Hostnames accepted in tracked URLs / Origin checks, treated as internal in referrer reports. '
                . 'Lowercase, no scheme or path; defaults to the hosts of <code>home_url()</code> and '
                . '<code>site_url()</code>. <strong>Memoized per request.</strong> This widens what the public '
                . 'ingestion endpoint accepts, so add only hosts you control'
            ,
        ],
        [
            'convermetry_should_enqueue_tracker',
            'filter',
            'apply_filters(\'convermetry_should_enqueue_tracker\', bool $should, array $enabled)',
            'Tracking',
            'Whether to load the frontend tracker on this request. Runs after the configured exclusions, so '
                . 'it can only suppress, never resurrect. Runs on <code>wp_enqueue_scripts</code>, so conditional '
                . 'tags are available'
            ,
        ],
        [
            'convermetry_tracker_config_extensions',
            'filter',
            'apply_filters(\'convermetry_tracker_config_extensions\', array $extensions, array $enabled)',
            'Tracking',
            'Add namespaced data to <code>window.ConvermetryConfig.extensions</code>. Smallest budget in the '
                . 'plugin (8 KB / 20 keys), because this is inlined into every page view. <strong>This data is '
                . 'public</strong>: never put a key, token, or anything visitor-specific here. The REST endpoint '
                . 'and batching limits cannot be replaced'
            ,
        ],
        [
            'convermetry_should_track_event',
            'filter',
            'apply_filters(\'convermetry_should_track_event\', bool $should, string $type, array $data)',
            'Tracking',
            'Whether to record one tracked event. Runs <strong>last</strong> in sanitization, so '
                . '<code>$data</code> is whitelisted and bounded; raw anonymous input from the public endpoint is '
                . 'never exposed to a hook. Returning <code>false</code> drops this event only'
            ,
        ],
        [
            'convermetry_tracked_event',
            'filter',
            'apply_filters(\'convermetry_tracked_event\', array $row, string $type)',
            'Tracking',
            'Inspect/modify an event row before storage; return <code>false</code> to drop.'
            ,
        ],
        [
            'convermetry_client_ip',
            'filter',
            'apply_filters(\'convermetry_client_ip\', string $ip)',
            'Tracking',
            'Map the client IP used for tracking rate limits <strong>and</strong> as the basis of the stored '
                . 'address (reverse proxies / CDNs). Defaults to <code>REMOTE_ADDR</code>; the result must validate '
                . 'as IPv4/IPv6 or it stores empty, and is <strong>memoized for the request</strong>. A forwarded '
                . 'header is spoofable unless a trusted proxy overwrites it, and a comma-joined '
                . '<code>X-Forwarded-For</code> chain is not an address — pick the hop your proxy guarantees. '
                . 'Pseudonymize with <code>convermetry_stored_ip</code>, not here'
            ,
        ],
        [
            'convermetry_stored_ip',
            'filter',
            'apply_filters(\'convermetry_stored_ip\', string $ip)',
            'Tracking',
            'The address about to be <strong>persisted</strong>, after the privacy gates. The '
                . 'pseudonymization hook (truncate, hash, or return <code>\'\'</code>). Must return a valid IPv4/IPv6 '
                . 'address or <code>\'\'</code>. Deliberately does <strong>not</strong> affect the rate-limit '
                . 'identity, which would collapse every visitor into one bucket'
            ,
        ],
        [
            'convermetry_tracking_batch_recorded',
            'action',
            'do_action(\'convermetry_tracking_batch_recorded\', int $stored, int $accepted, int $offered, ?string $batchId);',
            'Tracking',
            'One batch was written. One action per batch, never per event, on the plugin\'s hottest path. The '
                . 'three counts differ: offered → accepted (survived sanitization) → stored (survived '
                . 'deduplication)'
            ,
        ],
        [
            'convermetry_tracking_rate_limited',
            'action',
            'do_action(\'convermetry_tracking_rate_limited\', int $events, int $window);',
            'Tracking',
            'A batch was rejected by the rate limiter. Carries no address and no hash of one; the endpoint is '
                . 'public and unauthenticated'
            ,
        ],
        [
            'convermetry_rate_limits',
            'filter',
            'apply_filters(\'convermetry_rate_limits\', array $defaults)',
            'Tracking',
            '<code>[\'per_ip\' => 300, \'site_wide\' => 3000]</code> events/minute. Both clamped to a minimum of '
                . '1; a non-array return or a missing key falls back. Charged <strong>per event</strong>, so one '
                . '20-event batch costs 20, and the per-IP check runs first so a flooding IP never consumes the '
                . 'site-wide budget'
            ,
        ],
        [
            'convermetry_source_aliases',
            'filter',
            'apply_filters(\'convermetry_source_aliases\', array $aliases)',
            'Tracking',
            'Extend/override the utm_source alias map. Keys are raw lowercase source values, values the '
                . 'canonical name. Return the map with entries <strong>added</strong>: a replacement map loses the '
                . 'defaults, and a source with no entry is stored as submitted'
            ,
        ],
        [
            'convermetry_channel',
            'filter',
            'apply_filters(\'convermetry_channel\', string $channel, array $row, string $type)',
            'Tracking',
            'Override the marketing channel assigned at ingestion.'
            ,
        ],
        [
            'convermetry_analytics_sections',
            'filter',
            'apply_filters(\'convermetry_analytics_sections\', array $sections)',
            'Analytics',
            'Register <code>AnalyticsSectionInterface</code> adapters that add a dashboard panel '
                . '<strong>and</strong> contribute to <code>analytics.extensions</code> on the wire. A typed '
                . 'registry, never SQL: there is deliberately no way to pass a query fragment or table name to a '
                . 'path that runs unattended on cron. Keys must be namespaced; a section that throws is dropped, '
                . 'not propagated'
            ,
        ],
        [
            'convermetry_analytics_extensions',
            'filter',
            'apply_filters(\'convermetry_analytics_extensions\', array $extensions, string $start, string $end, int $limit)',
            'Analytics',
            'Extension data attached to an analytics summary. Pre-populated from registered sections. '
                . 'Computed inside <code>Reports::buildSummary()</code>, i.e. once per delivery at freeze time; a '
                . 'retry never rebuilds it. Bounded to 32 KB / 50 keys'
            ,
        ],
        [
            'convermetry_analytics_periods',
            'filter',
            'apply_filters(\'convermetry_analytics_periods\', int[] $periods)',
            'Analytics',
            'Reporting periods (in days) offered on the dashboard. Default <code>[7, 30, 90]</code>; '
                . 'validated, deduplicated, sorted, and <strong>clamped to the retention window</strong> so a '
                . 'period longer than the data cannot draw a chart that looks like a traffic collapse'
            ,
        ],
        [
            'convermetry_analytics_report_failed',
            'action',
            'do_action(\'convermetry_analytics_report_failed\', string $component, string $reportKey, string $start, string $end, string $error);',
            'Analytics',
            'A report could not be generated. <code>$error</code> is an exception <strong>class '
                . 'name</strong>, never a message: a database error quotes the failing statement'
            ,
        ],
        [
            'convermetry_analytics_admin_panels',
            'action',
            'do_action(\'convermetry_analytics_admin_panels\', string $start, string $end);',
            'Analytics',
            'Render extra panels at the end of the dashboard. Runs after this screen\'s capability check; '
                . '<strong>your callback must escape its own output</strong>'
            ,
        ],
        [
            'convermetry_should_record_submission',
            'filter',
            'apply_filters(\'convermetry_should_record_submission\', bool $should, string $formKey, string $provider, array $fields)',
            'Forms &amp; submissions',
            'Whether to record a submission at all. Runs after normalization (so spam rules can read the '
                . 'fields) and before <strong>any</strong> write. <code>false</code> skips the conversion event, '
                . 'the row, the queue, and the notifications. The visitor sees success: returning a failure would '
                . 'make Elementor\'s synchronous mode reject a valid form. <strong><code>$fields</code> contains '
                . 'PII</strong>'
            ,
        ],
        [
            'convermetry_submission_fields',
            'filter',
            'apply_filters(\'convermetry_submission_fields\', array $fields, string $formKey, string $provider)',
            'Forms &amp; submissions',
            'The normalized field descriptors. A <strong>changed</strong> result is re-normalized, so '
                . '<code>cvm_*</code> stays stripped and the descriptor shape holds. <strong>Contains PII</strong>'
            ,
        ],
        [
            'convermetry_submission_context_extensions',
            'filter',
            'apply_filters(\'convermetry_submission_context_extensions\', array $extensions, string $formKey, string $provider)',
            'Forms &amp; submissions',
            'Namespaced data added to the stored analytics context. Attached once before persistence, so '
                . 'every endpoint and every retry sees the same context. Cannot replace conversion id, session id, '
                . 'attribution, timestamps, or form identity'
            ,
        ],
        [
            'convermetry_submission_recorded',
            'action',
            'do_action(\'convermetry_submission_recorded\', $submissionId, $conversionId, $context);',
            'Forms &amp; submissions',
            'Fires after a submission is recorded, before webhook delivery is considered — so listeners run '
                . 'even with no endpoints configured (this is where notifications are queued).'
            ,
        ],
        [
            'convermetry_submission_recorded_details',
            'action',
            'do_action(\'convermetry_submission_recorded_details\', int $rowId, string $submissionId, array $form, array $fields);',
            'Forms &amp; submissions',
            'Fires immediately after the above with what its fixed signature cannot carry. <code>$form</code> '
                . 'is <code>{provider, form_key, form_name, native_id}</code>. <strong><code>$fields</code> '
                . 'contains PII</strong>; use <code>convermetry_submission_recorded</code> if you only need to know '
                . 'a submission happened'
            ,
        ],
        [
            'convermetry_submission_duplicate',
            'action',
            'do_action(\'convermetry_submission_duplicate\', string $submissionId, string $conversionId, string $formKey);',
            'Forms &amp; submissions',
            'A duplicate of an already-recorded submission (double-fired callback, replayed AJAX). Nothing is '
                . 'written or re-queued; <strong>do not re-send anything</strong>'
            ,
        ],
        [
            'convermetry_submission_delivery_state_changed',
            'action',
            'do_action(\'convermetry_submission_delivery_state_changed\', string $submissionId, string $state, string $previous);',
            'Forms &amp; submissions',
            'The recorded delivery state genuinely changed. Only on a transition; the state is recomputed '
                . 'several times per delivery and most recomputations are silent'
            ,
        ],
        [
            'convermetry_submission_deleted',
            'action',
            'do_action(\'convermetry_submission_deleted\', int $id, string $submissionId);',
            'Forms &amp; submissions',
            'A submission and everything attached to it are gone. Fires last, after the queue rows, queued '
                . 'notifications, and lead history are removed. Carries ids only: the data is what is being erased'
            ,
        ],
        [
            'convermetry_submissions_cleared',
            'action',
            'do_action(\'convermetry_submissions_cleared\');',
            'Forms &amp; submissions',
            'Every submission, queued delivery, queued notification, and lead history row was removed. Once '
                . 'for the whole operation; the rows are dropped with <code>TRUNCATE</code> and bulk deletes that '
                . 'never load one'
            ,
        ],
        [
            'convermetry_form_settings_saved',
            'action',
            'do_action(\'convermetry_form_settings_saved\', string[] $formKeys);',
            'Forms &amp; submissions',
            'Per-form settings were written. Fires from the storage layer on a real write, so CLI callers '
                . 'raise it too'
            ,
        ],
        [
            'convermetry_discovered_forms',
            'filter',
            'apply_filters(\'convermetry_discovered_forms\', array $forms, string $providerKey)',
            'Forms &amp; submissions',
            'The forms discovered for one provider. Runs <strong>before</strong> the 5-minute cache is '
                . 'written, so the result is normalized back to <code>{native_id, name}</code>, empty ids dropped, '
                . 'duplicates collapsed'
            ,
        ],
        [
            'convermetry_form_providers',
            'filter',
            'apply_filters(\'convermetry_form_providers\', FormProviderInterface[] $providers)',
            'Forms &amp; submissions',
            'Register custom <code>FormProviderInterface</code> adapters. Entries that are not instances of '
                . 'the interface are silently discarded, and providers are keyed by <code>getKey()</code>, so an '
                . 'adapter reusing a bundled key <strong>replaces</strong> it. <strong>Memoized on first '
                . 'use</strong>: register at plugin load time, not on <code>init</code>'
            ,
        ],
        [
            'convermetry_submission_csv_columns',
            'filter',
            'apply_filters(\'convermetry_submission_csv_columns\', array $columns)',
            'Forms &amp; submissions',
            'The export\'s columns as an ordered <code>key => header label</code> map. Paired with the values '
                . 'filter <strong>by key, never by position</strong>, so the two cannot drift out of alignment'
            ,
        ],
        [
            'convermetry_submission_csv_values',
            'filter',
            'apply_filters(\'convermetry_submission_csv_values\', array $values, array $row)',
            'Forms &amp; submissions',
            'One exported row\'s <code>key => value</code> map. Runs per row while streaming, so keep it '
                . 'cheap. Values must be scalar or null and go through the same formula-injection escaping as core '
                . 'ones. <strong>Contains PII</strong>'
            ,
        ],
        [
            'convermetry_submissions_columns',
            'filter',
            'apply_filters(\'convermetry_submissions_columns\', array $columns, array $row)',
            'Forms &amp; submissions',
            'Extra cells appended to each row of the submissions list. <code>key => already-escaped '
                . 'HTML</code>, <strong>printed verbatim, so escape it yourself</strong>. <strong><code>$row</code> '
                . 'contains PII</strong>'
            ,
        ],
        [
            'convermetry_submission_detail_sections',
            'action',
            'do_action(\'convermetry_submission_detail_sections\', array $row);',
            'Forms &amp; submissions',
            'Render extra blocks at the end of a submission\'s detail panel. After the nonce and capability '
                . 'checks; <strong>escape your own output</strong>. <strong><code>$row</code> contains PII</strong>'
            ,
        ],
        [
            'convermetry_submission_row_actions',
            'action',
            'do_action(\'convermetry_submission_row_actions\', array $row);',
            'Forms &amp; submissions',
            'Render extra buttons in a submission\'s action bar. Nonce-protect anything that acts. '
                . '<strong><code>$row</code> contains PII</strong>'
            ,
        ],
        [
            'convermetry_forms_admin_sections',
            'action',
            'do_action(\'convermetry_forms_admin_sections\');',
            'Forms &amp; submissions',
            'Render extra content at the end of the Forms screen. Outside the settings form, so post your own '
                . 'form to <code>admin-post.php</code>. <strong>Escape your own output</strong>'
            ,
        ],
        [
            'convermetry_form_submission',
            'action',
            'do_action(\'convermetry_form_submission\', array $formIdentifier, array $fields, array $context = []);',
            'Forms &amp; submissions',
            'Submit a custom form (fire-and-forget, background delivery). <code>$fields</code> accepts a list '
                . 'of <code>[\'id\', \'label\', \'value\']</code> descriptors <strong>or</strong> the historical '
                . '<code>name => value</code> map. See Custom form integration API'
            ,
        ],
        [
            'convermetry_should_record_goal_completion',
            'filter',
            'apply_filters(\'convermetry_should_record_goal_completion\', bool $should, array $row, array $goal)',
            'Goals, funnels &amp; leads',
            'Whether to record one matched completion. A decision only: the row is passed for inspection, and '
                . 'nothing returned changes it. The completion id, definition hash, event uid, dedupe key, and '
                . 'timestamp are identity, and a hook that could rewrite them could silently defeat '
                . 'once-per-session goals'
            ,
        ],
        [
            'convermetry_goal_completion',
            'filter',
            'apply_filters(\'convermetry_goal_completion\', array $row, array $goal)',
            'Goals, funnels &amp; leads',
            'Inspect/modify a goal completion row before it is written.'
            ,
        ],
        [
            'convermetry_goal_matched',
            'action',
            'do_action(\'convermetry_goal_matched\', int $stored, array $rows);',
            'Goals, funnels &amp; leads',
            'Fires after a batch of goal completions is stored.'
            ,
        ],
        [
            'convermetry_goal_completions_recorded',
            'action',
            'do_action(\'convermetry_goal_completions_recorded\', int $stored, int $offered, array $completionIds);',
            'Goals, funnels &amp; leads',
            'Fires immediately after the above with the offered/stored split. The two counts differ because '
                . 'completions are written with <code>INSERT IGNORE</code> against a dedupe key; '
                . '<code>$completionIds</code> are the ids <strong>offered</strong>, not the ids stored'
            ,
        ],
        [
            'convermetry_goal_saved',
            'action',
            'do_action(\'convermetry_goal_saved\', string $goalId, array $goal, ?array $previous);',
            'Goals, funnels &amp; leads',
            'A goal definition was persisted. <code>$previous</code> is null for a new goal. Fires from the '
                . 'repository, so CLI callers raise it too, and only on a successful write'
            ,
        ],
        [
            'convermetry_goal_deleted',
            'action',
            'do_action(\'convermetry_goal_deleted\', string $goalId, string $now);',
            'Goals, funnels &amp; leads',
            'A goal was deleted. Only when it existed and the write succeeded. The deletion is soft: '
                . 'completions and the name survive so historical reports keep working'
            ,
        ],
        [
            'convermetry_funnel_saved',
            'action',
            'do_action(\'convermetry_funnel_saved\', string $funnelId, array $funnel, ?array $previous);',
            'Goals, funnels &amp; leads',
            'A funnel definition was persisted. Editing a funnel changes what every past report says, '
                . 'retroactively'
            ,
        ],
        [
            'convermetry_funnel_deleted',
            'action',
            'do_action(\'convermetry_funnel_deleted\', string $funnelId, string $now);',
            'Goals, funnels &amp; leads',
            'A funnel was deleted.'
            ,
        ],
        [
            'convermetry_lead_status_updated',
            'action',
            'do_action(\'convermetry_lead_status_updated\', $submissionId, $toStatus, $fromStatus, ?string $value, string $currency);',
            'Goals, funnels &amp; leads',
            'Fires after a lead\'s status or value changes.'
            ,
        ],
        [
            'convermetry_lead_updated',
            'action',
            'do_action(\'convermetry_lead_updated\', string $submissionId, array $to, array $from, int $userId, string $leadEventId);',
            'Goals, funnels &amp; leads',
            'Fires immediately after the above with the full before/after. '
                . '<code>$to</code>/<code>$from</code> are <code>{status, value, currency}</code>. Both fire '
                . '<strong>after</strong> the transaction commits. Values are exact decimal '
                . '<strong>strings</strong>, never floats; currency is stamped, not converted, and a null value is '
                . 'not <code>\'0.00\'</code>'
            ,
        ],
        [
            'convermetry_should_queue_notification',
            'filter',
            'apply_filters(\'convermetry_should_queue_notification\', bool $should, string $formKey, array $identity)',
            'Notifications',
            'Whether to queue notifications for a submission. Runs after the configured rules said yes, so it '
                . 'can only narrow. <code>$identity</code> is identity columns, never field values'
            ,
        ],
        [
            'convermetry_notification_recipients',
            'filter',
            'apply_filters(\'convermetry_notification_recipients\', array $recipients, string $formKey, array $identity)',
            'Notifications',
            'The addresses to queue. Runs once at <strong>queue</strong> time; each address becomes its own '
                . 'row with its own retry chain. Re-validated through '
                . '<code>sanitize_email()</code>/<code>is_email()</code>, deduplicated, capped at 20'
            ,
        ],
        [
            'convermetry_notification_message',
            'filter',
            'apply_filters(\'convermetry_notification_message\', array $message, string $submissionId, int $attempt)',
            'Notifications',
            'Subject, HTML body, and additional headers, per attempt. The <strong>recipient is not '
                . 'changeable</strong>: one row is one address, and a per-attempt rewrite could collapse two rows '
                . 'onto one mailbox. Subject gets the header-injection strip and 200-char cap, the body the 256 KB '
                . 'cap, and the four required headers are reinstated. <strong><code>$message[\'html\']</code> '
                . 'contains PII</strong>'
            ,
        ],
        [
            'convermetry_notification_queued',
            'action',
            'do_action(\'convermetry_notification_queued\', string $submissionId, string $recipient, int $attempt);',
            'Notifications',
            'A notification was genuinely queued. Only for a real insert'
            ,
        ],
        [
            'convermetry_notification_before_send',
            'action',
            'do_action(\'convermetry_notification_before_send\', string $submissionId, string $recipient, int $attempt);',
            'Notifications',
            'Immediately before <code>wp_mail()</code>. No subject, no body, no fields'
            ,
        ],
        [
            'convermetry_notification_accepted',
            'action',
            'do_action(\'convermetry_notification_accepted\', string $submissionId, string $recipient, int $attempt);',
            'Notifications',
            '<code>wp_mail()</code> returned true <strong>and</strong> the queue row was removed. "accepted", '
                . 'never "delivered": the local transport took the message, which is not receipt'
            ,
        ],
        [
            'convermetry_notification_retry_scheduled',
            'action',
            'do_action(\'convermetry_notification_retry_scheduled\', string $submissionId, string $recipient, int $nextAttempt, int $nextAttemptAt);',
            'Notifications',
            'The next attempt is persisted.'
            ,
        ],
        [
            'convermetry_notification_abandoned',
            'action',
            'do_action(\'convermetry_notification_abandoned\', string $submissionId, string $recipient, int $attempt, string $error);',
            'Notifications',
            'Retries spent, row deleted, message will never be sent.'
            ,
        ],
        [
            'convermetry_notification_canceled',
            'action',
            'do_action(\'convermetry_notification_canceled\', string $submissionId, string $recipient, string $reason, int $count);',
            'Notifications',
            'Queued notifications were cancelled unsent. <code>$reason</code> is <code>\'expired\'</code>, '
                . '<code>\'submission_deleted\'</code>, or <code>\'admin_clear\'</code>. Per-row from the worker; '
                . '<strong>one aggregate action</strong> for bulk clears, with <code>$recipient</code> empty — '
                . 'addresses are never read back purely to emit a hook'
            ,
        ],
        [
            'convermetry_notification_retry_schedule',
            'filter',
            'apply_filters(\'convermetry_notification_retry_schedule\', int[] $delays)',
            'Notifications',
            'The email-notification retry backoff in seconds. Default <code>[300, 900, 3600]</code>; entries '
                . 'clamped to a minimum of 60, non-numeric entries discarded, empty falls back. Deliberately '
                . 'separate from the webhook schedule — a stale lead notification is worse than none, and email has '
                . 'no receiver-side idempotency. The hard two-hour TTL sits above it regardless'
            ,
        ],
        [
            'convermetry_sensitive_keys',
            'filter',
            'apply_filters(\'convermetry_sensitive_keys\', string[] $patterns)',
            'Notifications',
            'Extend the credential-looking field/header names redacted from the Activity Log '
                . '<strong>and</strong> omitted from notification emails (e.g. add <code>ssn</code>). Defaults are '
                . '<code>password</code>, <code>passwd</code>, <code>pwd</code>, <code>secret</code>, '
                . '<code>token</code>, <code>api_key</code>, <code>apikey</code>, <code>authorization</code>, '
                . '<code>credential</code>, <code>private_key</code>, <code>access_token</code>, '
                . '<code>refresh_token</code>, <code>client_secret</code>, plus <code>cookie</code> for headers. '
                . 'Matched as substrings of a canonical form: lowercase with non-alphanumeric runs collapsed to '
                . '<code>_</code>, so <code>API Key</code>, <code>x-api-key</code> and <code>API_KEY</code> all '
                . 'match <code>api_key</code>. The returned list <strong>is</strong> the effective list — extend '
                . 'it; a shorter one weakens both surfaces. Note the asymmetry: a match is <code>[REDACTED]</code> '
                . 'in the log, but omitted with no placeholder from an email, because a placeholder announces that '
                . 'a secret exists'
            ,
        ],
        [
            'convermetry_retention_cleanup_started',
            'action',
            'do_action(\'convermetry_retention_cleanup_started\', string $store, string $cutoff);',
            'Operations, settings &amp; API',
            'One store begins deleting past the retention cutoff. Observational: a listener '
                . '<strong>cannot</strong> cancel a pass, change the cutoff, or extend retention'
            ,
        ],
        [
            'convermetry_retention_cleanup_completed',
            'action',
            'do_action(\'convermetry_retention_cleanup_completed\', string $store, string $cutoff, int $deleted, bool $moreRemain, string $outcome);',
            'Operations, settings &amp; API',
            'One store\'s pass finished. <code>$outcome</code> is '
                . '<code>completed</code>/<code>truncated</code>/<code>query_failed</code>/<code>lock_lost</code>. '
                . 'Convermetry schedules any follow-up pass itself'
            ,
        ],
        [
            'convermetry_migration_started',
            'action',
            'do_action(\'convermetry_migration_started\', string $context);',
            'Operations, settings &amp; API',
            'A migration pass began, with the lease held. <code>\'cli\'</code>, <code>\'cron\'</code>, or '
                . '<code>\'admin\'</code>. Do not throw: the lease would be held until it expires. No SQL is passed '
                . 'to any migration hook'
            ,
        ],
        [
            'convermetry_migration_completed',
            'action',
            'do_action(\'convermetry_migration_completed\', string $context, bool $pending);',
            'Operations, settings &amp; API',
            'A pass finished. Fires after the lease is released and after the pending check '
                . '<strong>and</strong> reschedule decision, so <code>$pending</code> is settled. A pending '
                . 'migration is normal mid-migration, not an error'
            ,
        ],
        [
            'convermetry_migration_failed',
            'action',
            'do_action(\'convermetry_migration_failed\', string $context, string $error);',
            'Operations, settings &amp; API',
            'A pass threw. <code>$error</code> is the exception <strong>class name</strong>. Fires after the '
                . 'lease is released and before the failure continues to the caller. A migration that merely did '
                . 'not land is not a failure'
            ,
        ],
        [
            'convermetry_storage_error',
            'action',
            'do_action(\'convermetry_storage_error\', string $subsystem, string $operation, string $code, array $context);',
            'Operations, settings &amp; API',
            'A database operation Convermetry needed verifiably failed. Reserved for verified failures: a '
                . 'duplicate <code>INSERT IGNORE</code>, an abandoned notification, or a still-pending migration do '
                . '<strong>not</strong> fire it. Never carries SQL, <code>$wpdb->last_error</code>, submitted '
                . 'fields, IPs, or secrets'
            ,
        ],
        [
            'convermetry_settings_saved',
            'action',
            'do_action(\'convermetry_settings_saved\', string $section, string[] $changedKeys);',
            'Operations, settings &amp; API',
            'A settings section was written. Listens on WordPress\'s own option-write hooks, so it fires on a '
                . 'real write only (never for a form submitted without edits) and catches CLI and migration writers '
                . 'too. <strong>Key names only, never values</strong>: two sections hold signing secrets and '
                . 'token-bearing endpoint URLs'
            ,
        ],
        [
            'convermetry_admin_capability',
            'filter',
            'apply_filters(\'convermetry_admin_capability\', string $capability, string $scope)',
            'Operations, settings &amp; API',
            'The capability required for one admin surface. Scopes: <code>analytics.view</code>, '
                . '<code>submissions.view</code>, <code>submissions.export</code>, <code>submissions.delete</code>, '
                . '<code>leads.edit</code>, <code>goals.manage</code>, <code>funnels.manage</code>, '
                . '<code>forms.manage</code>, <code>notifications.manage</code>, <code>webhooks.manage</code>, '
                . '<code>activity.view</code>, <code>activity.manage</code>, <code>api.manage</code>, '
                . '<code>settings.manage</code>. All default to <code>manage_options</code> and are applied to menu '
                . 'visibility <strong>and</strong> every handler behind it. Must return a non-empty lowercase '
                . '<code>[a-z0-9_]</code> name; anything else falls back, because <code>current_user_can(\'\')</code> '
                . 'would lock the owner out. Grant deliberately — <code>submissions.export</code> is every lead\'s '
                . 'name and email in one file'
            ,
        ],
        [
            'convermetry_delivery_log_api_item',
            'filter',
            'apply_filters(\'convermetry_delivery_log_api_item\', array $extensions, array $item)',
            'Operations, settings &amp; API',
            'Add a namespaced <code>extensions</code> property to one delivery-log REST item. Runs after '
                . 'endpoint-URL redaction and body decoding. The core keys are <strong>immutable</strong>: a filter '
                . 'that could rewrite <code>success</code> would let a plugin lie to a monitoring dashboard. '
                . 'Bounded to 4 KB / 10 keys'
            ,
        ],
    ];

    /**
     * The credential-free delivery `$context` every webhook lifecycle hook
     * receives. Written once because all twelve of them share it exactly.
     */
    private const string DELIVERY_CONTEXT =
        'Always the same fifteen keys, always all present: <code>message_type</code> '
        . '(<code>analytics_report</code>/<code>form_submission</code>), <code>kind</code> '
        . '(<code>scheduled</code>/<code>immediate</code>/<code>retry</code>/<code>test</code>), '
        . '<code>attempt</code>, <code>delivery_id</code>, <code>is_test</code>, <code>endpoint_key</code>, '
        . '<code>endpoint_label</code>, <code>endpoint_origin</code> (scheme + host only), '
        . '<code>submission_id</code>, <code>conversion_id</code>, <code>form_key</code>, '
        . '<code>window_start</code>, <code>window_end</code>, <code>transport_attempted</code>, '
        . '<code>disposition</code>. Never a full URL, a header value, a body, or a signing secret';

    /**
     * What each argument actually holds, keyed by hook name then by the
     * argument as it appears in the signature.
     *
     * Separate from {@see HOOKS} rather than widening that tuple: the entries
     * there are one line of prose each and stay readable as a catalogue, while
     * these are paragraphs. Anything absent here simply renders no argument
     * table.
     *
     * @var array<string, array<string, string>>
     */
    private const array HOOK_ARGS = [
        'convermetry_webhook_payload' => [
            'array $payload' => 'The complete payload about to be encoded — <code>schema_version</code>, '
                . '<code>source</code>, <code>plugin_version</code>, <code>message_type</code>, '
                . '<code>website_info</code>, <code>generated_at</code>, and then either <code>analytics</code> or '
                . '<code>submission</code>. Return it modified',
            'string $messageType' => '<code>\'analytics_report\'</code> or <code>\'form_submission\'</code>',
            'array $meta' => '<code>[\'start\', \'end\']</code> (Y-m-d) for reports, '
                . '<code>[\'submission_id\']</code> for submissions',
        ],
        'convermetry_webhook_payload_extensions' => [
            'array $extensions' => 'Namespaced blocks to publish as the payload\'s <code>extensions</code> '
                . 'property. Keys <strong>must</strong> be <code>vendor/thing</code>; the whole structure is bounded '
                . 'to 32 KB, 50 keys, and JSON primitives. Return it empty and no property is added at all',
            'string $messageType' => '<code>\'analytics_report\'</code> or <code>\'form_submission\'</code>',
            'array $meta' => 'As above: report window, or the submission id',
        ],
        'convermetry_webhook_query_args' => [
            'array $params' => 'Query parameters to append to the endpoint URL, already merged global → page → '
                . 'per-form → runtime. Re-normalized after you return it to bounded scalar keys and values, order '
                . 'preserved',
            'array $context' => self::DELIVERY_CONTEXT,
        ],
        'convermetry_webhook_headers' => [
            'array $headers' => 'Non-protocol request headers, already merged global → per-form → runtime. '
                . '<code>Content-Type</code>, <code>Host</code>, <code>Content-Length</code>, '
                . '<code>Transfer-Encoding</code>, <code>Connection</code>, <code>User-Agent</code>, '
                . '<code>Idempotency-Key</code> and <code>X-Convermetry-Signature</code> are restored to their '
                . 'pre-filter state afterwards, so touching them has no effect',
            'array $context' => self::DELIVERY_CONTEXT,
        ],
        'convermetry_webhook_timeout' => [
            'int $timeout' => 'Seconds for one attempt; default 15. A return outside 1–30 is '
                . '<strong>ignored, not clamped</strong> — the default stands',
            'array $context' => self::DELIVERY_CONTEXT,
        ],
        'convermetry_form_delivery_queued' => [
            'array $context' => self::DELIVERY_CONTEXT,
        ],
        'convermetry_webhook_delivery_frozen' => [
            'array $context' => self::DELIVERY_CONTEXT,
            'string $storage' => '<code>\'memory\'</code> for analytics (persisted only if a retry follows) or '
                . '<code>\'queue_row\'</code> for a form delivery (verified written to the queue table)',
            'int $bodyBytes' => 'Size of the frozen body. These exact bytes are what every retry re-sends',
        ],
        'convermetry_webhook_before_send' => [
            'array $context' => self::DELIVERY_CONTEXT,
            'array $meta' => 'Metadata only — <code>body_bytes</code>, <code>body_sha256</code>, '
                . '<code>header_names</code> (names, not values) and <code>signed</code>. Deliberately no URL, no '
                . 'header values, no body',
        ],
        'convermetry_webhook_delivery_attempted' => [
            'array $context' => self::DELIVERY_CONTEXT,
            'bool $ok' => 'Whether the transport succeeded. Not whether the delivery is finished — nothing has '
                . 'decided a retry or queue disposition yet',
            'int $code' => 'HTTP status, or <code>0</code> when nothing reached the wire',
            'string $message' => 'Short transport-level reason; empty on success',
        ],
        'convermetry_delivery_attempt_logged' => [
            'array $context' => self::DELIVERY_CONTEXT,
            'string $disposition' => '<code>\'stored\'</code>, <code>\'suppressed\'</code> (a '
                . '<code>convermetry_delivery_log_row</code> callback returned false) or <code>\'failed\'</code>',
        ],
        'convermetry_webhook_delivery_succeeded' => [
            'array $context' => self::DELIVERY_CONTEXT,
        ],
        'convermetry_webhook_retry_scheduled' => [
            'array $context' => self::DELIVERY_CONTEXT,
            'int $nextAttempt' => 'The attempt number that will run next (1-based)',
            'int $nextAttemptAt' => 'Unix timestamp it becomes due — already persisted, never speculative',
        ],
        'convermetry_webhook_retry_chain_exhausted' => [
            'array $context' => self::DELIVERY_CONTEXT,
        ],
        'convermetry_webhook_delivery_abandoned' => [
            'array $context' => self::DELIVERY_CONTEXT,
            'string $reason' => 'Why it was given up on. Terminal: the queue row is deleted',
        ],
        'convermetry_webhook_delivery_canceled' => [
            'array $context' => self::DELIVERY_CONTEXT,
            'string $reason' => 'Currently <code>\'submission_deleted\'</code> — the submission went away '
                . 'before the worker reached its row',
        ],
        'convermetry_retry_schedule' => [
            'int[] $delays' => 'Seconds to wait before each retry; default '
                . '<code>[300, 1800, 7200, 21600, 57600]</code>. Each entry is clamped to a minimum of 60, and an '
                . 'empty or fully invalid list falls back to the default. <strong>The list length is the attempt '
                . 'count</strong> — a three-entry list means three retries',
        ],
        'convermetry_webhook_report_limit' => [
            'int $limit' => 'Max rows per <code>top_*</code> list in an analytics payload; default 200, '
                . 'clamped to a minimum of 1. Does not apply to <code>conversions.recent[]</code>',
        ],
        'convermetry_delivery_log_row' => [
            'array|false $row' => 'The Activity Log row about to be written: endpoint, status, timing, and '
                . 'already-redacted request/response bodies capped at 64 KB. Return an array to store it, or '
                . '<code>false</code> (or anything non-array) to skip logging this attempt. Skipping affects the log '
                . 'only — the delivery still happens',
        ],
        'convermetry_allow_insecure_webhooks' => [
            'bool $allow' => 'Whether an <code>http://</code> endpoint may be saved. Evaluated when endpoints '
                . 'are <strong>saved</strong>, not at send time',
        ],
        'convermetry_allowed_hosts' => [
            'string[] $hosts' => 'Lowercase hostnames, no scheme or path. Defaults to the hosts of '
                . '<code>home_url()</code> and <code>site_url()</code>. <strong>Memoized per request.</strong> This '
                . 'widens what the public ingestion endpoint accepts — add only hosts you control',
        ],

        'convermetry_should_enqueue_tracker' => [
            'bool $should' => 'Whether to enqueue the tracker on this request. Runs after the configured '
                . 'exclusions, so returning <code>true</code> cannot resurrect a suppressed load — you can only '
                . 'narrow',
            'array $enabled' => 'Event types currently switched on, as <code>type => true</code>',
        ],
        'convermetry_tracker_config_extensions' => [
            'array $extensions' => 'Namespaced data for <code>window.ConvermetryConfig.extensions</code>. '
                . 'Smallest budget in the plugin — 8 KB, 20 keys — because it is inlined into every page view. '
                . '<strong>Public to every visitor</strong>: never a key, a token, or anything visitor-specific',
            'array $enabled' => 'Event types currently switched on, as <code>type => true</code>',
        ],
        'convermetry_should_track_event' => [
            'bool $should' => 'Whether to record this one event. <code>false</code> drops it and nothing else',
            'string $type' => 'Event type — <code>pageview</code>, <code>click</code>, '
                . '<code>form_submit</code>, <code>form_success</code>, <code>form_view</code>, '
                . '<code>form_start</code>, <code>form_error</code>, <code>hover</code>, '
                . '<code>scroll_depth</code>, <code>custom_event</code>',
            'array $data' => 'The sanitized event. Runs <strong>last</strong> in sanitization, so this is '
                . 'already whitelisted, bounded and scalar — raw anonymous input never reaches a hook',
        ],
        'convermetry_tracked_event' => [
            'array $row' => 'The database row about to be inserted: <code>event_type</code>, '
                . '<code>page_url</code>, <code>target_url</code>, <code>session_id</code>, '
                . '<code>element_label</code>, <code>form_key</code>, <code>event_value</code>, the campaign '
                . 'columns, and <code>ip_address</code> when IP storage is on. Return <code>false</code> to drop it',
            'string $type' => 'The event type, for convenience',
        ],
        'convermetry_client_ip' => [
            'string $ip' => 'Defaults to <code>REMOTE_ADDR</code>. Must return something that validates as '
                . 'IPv4/IPv6 or the address stores empty. <strong>Memoized per request.</strong> A comma-joined '
                . '<code>X-Forwarded-For</code> chain is not an address — pick the hop your proxy guarantees. '
                . 'Pseudonymize in <code>convermetry_stored_ip</code>, not here: this value is also the rate-limit '
                . 'identity',
        ],
        'convermetry_stored_ip' => [
            'string $ip' => 'The address about to be <strong>persisted</strong>, after the privacy gates. '
                . 'Truncate, hash, or return <code>\'\'</code> to store nothing. Must be a valid IPv4/IPv6 address '
                . 'or <code>\'\'</code>. Does not affect the rate-limit identity, which would collapse every visitor '
                . 'into one bucket',
        ],
        'convermetry_tracking_batch_recorded' => [
            'int $stored' => 'Rows that survived deduplication and were written',
            'int $accepted' => 'Events that survived sanitization',
            'int $offered' => 'Events the batch arrived with. <code>offered ≥ accepted ≥ stored</code>',
            '?string $batchId' => 'The client\'s batch id, or <code>null</code> when it sent none',
        ],
        'convermetry_tracking_rate_limited' => [
            'int $events' => 'How many events the rejected batch carried',
            'int $window' => 'The limiter window in seconds (60)',
        ],
        'convermetry_rate_limits' => [
            'array $defaults' => '<code>[\'per_ip\' => 300, \'site_wide\' => 3000]</code>, in events per '
                . 'minute. Both clamped to a minimum of 1; a non-array return or a missing key falls back to the '
                . 'default. Charged <strong>per event</strong>, so one 20-event batch costs 20',
        ],
        'convermetry_source_aliases' => [
            'array $aliases' => 'Raw lowercase <code>utm_source</code> value => canonical name. '
                . '<strong>Add to the map, do not replace it</strong> — a fresh array loses every default. A source '
                . 'with no entry is stored exactly as submitted',
        ],
        'convermetry_channel' => [
            'string $channel' => 'The channel classified at ingestion — <code>direct</code>, '
                . '<code>organic</code>, <code>paid</code>, <code>social</code>, <code>email</code>, '
                . '<code>referral</code>, <code>other</code>',
            'array $row' => 'The event row being classified, including its campaign columns and referrer',
            'string $type' => 'The event type being classified',
        ],

        'convermetry_analytics_sections' => [
            'array $sections' => '<code>AnalyticsSectionInterface</code> adapters, keyed by a namespaced key. '
                . 'Each adds a dashboard panel <strong>and</strong> contributes to <code>analytics.extensions</code> '
                . 'on the wire. A typed registry, never SQL — there is deliberately no way to hand a query fragment '
                . 'or table name to a path that runs unattended on cron. A section that throws is dropped, not '
                . 'propagated',
        ],
        'convermetry_analytics_extensions' => [
            'array $extensions' => 'Extension data for this summary, pre-populated from registered sections. '
                . 'Bounded to 32 KB / 50 keys. Computed once per delivery at freeze time — a retry never rebuilds it',
            'string $start' => 'Window start, <code>Y-m-d</code>',
            'string $end' => 'Window end, <code>Y-m-d</code>',
            'int $limit' => 'The effective <code>top_*</code> row limit for this report',
        ],
        'convermetry_analytics_periods' => [
            'int[] $periods' => 'Reporting periods in days offered on the dashboard; default '
                . '<code>[7, 30, 90]</code>. Validated, deduplicated, sorted, and <strong>clamped to the retention '
                . 'window</strong> — a period longer than the data would draw a chart that looks like a traffic '
                . 'collapse',
        ],
        'convermetry_analytics_report_failed' => [
            'string $component' => 'Which subsystem was building the report',
            'string $reportKey' => 'The specific report that failed',
            'string $start' => 'Window start, <code>Y-m-d</code>',
            'string $end' => 'Window end, <code>Y-m-d</code>',
            'string $error' => 'The exception <strong>class name</strong> — never a message, because a database '
                . 'error message quotes the failing statement',
        ],
        'convermetry_analytics_admin_panels' => [
            'string $start' => 'Window start of the dashboard\'s current period, <code>Y-m-d</code>',
            'string $end' => 'Window end, <code>Y-m-d</code>',
        ],

        'convermetry_should_record_submission' => [
            'bool $should' => 'Whether to record at all. <code>false</code> skips the conversion event, the '
                . 'row, the queue and the notifications — and the visitor still sees success, because returning a '
                . 'failure would make Elementor\'s synchronous mode reject a valid form',
            'string $formKey' => 'Provider-qualified form identity, e.g. <code>gravityforms:7</code>',
            'string $provider' => 'Provider key, e.g. <code>gravityforms</code>',
            'array $fields' => 'Normalized <code>[\'id\', \'label\', \'value\']</code> descriptors, so spam '
                . 'rules can read them. <strong>Contains PII</strong>',
        ],
        'convermetry_submission_fields' => [
            'array $fields' => 'Normalized <code>[\'id\', \'label\', \'value\']</code> descriptors. A '
                . '<strong>changed</strong> result is re-normalized, so <code>cvm_*</code> stays stripped and the '
                . 'descriptor shape holds. <strong>Contains PII</strong>',
            'string $formKey' => 'Provider-qualified form identity',
            'string $provider' => 'Provider key',
        ],
        'convermetry_submission_context_extensions' => [
            'array $extensions' => 'Namespaced data added to the stored analytics context. Attached once '
                . 'before persistence, so every endpoint and every retry sees the same thing. Cannot replace '
                . 'conversion id, session id, attribution, timestamps or form identity',
            'string $formKey' => 'Provider-qualified form identity',
            'string $provider' => 'Provider key',
        ],
        'convermetry_submission_recorded' => [
            '$submissionId' => 'The public submission id (string)',
            '$conversionId' => 'The conversion id shared with the tracker\'s own <code>form_success</code> '
                . 'event, so the two can never double-count',
            '$context' => 'The stored analytics context — session id, channel, attribution, entrance referrer, '
                . 'landing page, device',
        ],
        'convermetry_submission_recorded_details' => [
            'int $rowId' => 'Database row id',
            'string $submissionId' => 'The public submission id',
            'array $form' => '<code>{provider, form_key, form_name, native_id}</code>',
            'array $fields' => 'The stored field descriptors. <strong>Contains PII</strong> — use '
                . '<code>convermetry_submission_recorded</code> if you only need to know a submission happened',
        ],
        'convermetry_submission_duplicate' => [
            'string $submissionId' => 'The id of the submission already recorded',
            'string $conversionId' => 'Its conversion id',
            'string $formKey' => 'Provider-qualified form identity',
        ],
        'convermetry_submission_delivery_state_changed' => [
            'string $submissionId' => 'The public submission id',
            'string $state' => 'The new state — <code>pending</code>, <code>delivered</code>, '
                . '<code>failed</code>, <code>abandoned</code> or <code>not_configured</code>',
            'string $previous' => 'The state it moved from. Fires only on a genuine transition; the state is '
                . 'recomputed several times per delivery and most recomputations are silent',
        ],
        'convermetry_submission_deleted' => [
            'int $id' => 'The database row id that was removed',
            'string $submissionId' => 'The public submission id. Ids only — the data is what is being erased',
        ],
        'convermetry_form_settings_saved' => [
            'string[] $formKeys' => 'The form keys whose settings were written. Fires from the storage layer '
                . 'on a real write, so WP-CLI callers raise it too',
        ],
        'convermetry_discovered_forms' => [
            'array $forms' => 'Forms found for this provider. Runs <strong>before</strong> the 5-minute cache '
                . 'is written, and the result is normalized back to <code>{native_id, name}</code> with empty ids '
                . 'dropped and duplicates collapsed',
            'string $providerKey' => 'Which provider was asked, e.g. <code>wpforms</code>',
        ],
        'convermetry_form_providers' => [
            'FormProviderInterface[] $providers' => 'Adapters keyed by <code>getKey()</code>. Entries that are '
                . 'not instances of the interface are silently discarded, and an adapter reusing a bundled key '
                . '<strong>replaces</strong> it. <strong>Memoized on first use</strong> — register at plugin load '
                . 'time, not on <code>init</code>',
        ],
        'convermetry_submission_csv_columns' => [
            'array $columns' => 'Ordered <code>key => header label</code> map for the export. Paired with the '
                . 'values filter <strong>by key, never by position</strong>, so the two cannot drift apart',
        ],
        'convermetry_submission_csv_values' => [
            'array $values' => 'One row as <code>key => value</code>. Values must be scalar or null, and go '
                . 'through the same formula-injection escaping as the core ones. <strong>Contains PII</strong>',
            'array $row' => 'The full submission row being exported. Runs per row while streaming — keep it '
                . 'cheap',
        ],
        'convermetry_submissions_columns' => [
            'array $columns' => 'Extra cells for this list row, as <code>key => already-escaped HTML</code>. '
                . '<strong>Printed verbatim — escape it yourself</strong>',
            'array $row' => 'The submission row. <strong>Contains PII</strong>',
        ],
        'convermetry_submission_detail_sections' => [
            'array $row' => 'The submission being displayed. Runs after the nonce and capability checks; '
                . '<strong>escape your own output</strong>. <strong>Contains PII</strong>',
        ],
        'convermetry_submission_row_actions' => [
            'array $row' => 'The submission whose action bar is rendering. Nonce-protect anything that acts. '
                . '<strong>Contains PII</strong>',
        ],
        'convermetry_form_submission' => [
            'array $formIdentifier' => '<code>[\'form_name\' => ..., \'form_id\' => ...]</code>. '
                . '<code>form_id</code> is your own stable identifier for this form',
            'array $fields' => 'Either a list of <code>[\'id\', \'label\', \'value\']</code> descriptors '
                . '(preferred — <code>value</code> may be an array for multi-selects) <strong>or</strong> the '
                . 'historical <code>name => value</code> map',
            'array $context = []' => 'Optional. <code>url_query</code> and <code>headers</code> maps merged '
                . 'into this delivery only',
        ],

        'convermetry_should_record_goal_completion' => [
            'bool $should' => 'Whether to record this matched completion',
            'array $row' => 'The completion row, <strong>for inspection only</strong> — nothing you return '
                . 'changes it. Its completion id, definition hash, event uid, dedupe key and timestamp are identity, '
                . 'and a gate that could rewrite them could silently defeat once-per-session goals',
            'array $goal' => 'The goal definition that matched',
        ],
        'convermetry_goal_completion' => [
            'array $row' => 'The completion row about to be written — completion id, goal id, definition '
                . 'hash, session id, event uid, dedupe key, value, timestamp',
            'array $goal' => 'The goal definition that matched',
        ],
        'convermetry_goal_matched' => [
            'int $stored' => 'How many completions were actually written',
            'array $rows' => 'The completion rows that were offered',
        ],
        'convermetry_goal_completions_recorded' => [
            'int $stored' => 'Rows written after <code>INSERT IGNORE</code> against the dedupe key',
            'int $offered' => 'Rows attempted. The two differ whenever a completion was already recorded',
            'array $completionIds' => 'The ids <strong>offered</strong>, not the ids stored',
        ],
        'convermetry_goal_saved' => [
            'string $goalId' => 'Immutable goal id',
            'array $goal' => 'The definition as stored',
            '?array $previous' => 'The definition before this write, or <code>null</code> for a new goal',
        ],
        'convermetry_goal_deleted' => [
            'string $goalId' => 'Immutable goal id. The deletion is soft: completions and the name survive so '
                . 'historical reports keep working',
            'string $now' => 'UTC <code>Y-m-d H:i:s</code> deletion timestamp',
        ],
        'convermetry_funnel_saved' => [
            'string $funnelId' => 'Immutable funnel id',
            'array $funnel' => 'The definition as stored, including its ordered steps',
            '?array $previous' => 'The definition before this write, or <code>null</code> for a new funnel. '
                . 'Editing a funnel changes what every past report says, retroactively',
        ],
        'convermetry_funnel_deleted' => [
            'string $funnelId' => 'Immutable funnel id',
            'string $now' => 'UTC <code>Y-m-d H:i:s</code> deletion timestamp',
        ],
        'convermetry_lead_status_updated' => [
            '$submissionId' => 'The submission whose lead changed (string)',
            '$toStatus' => 'The new status — <code>new</code>, <code>contacted</code>, '
                . '<code>qualified</code>, <code>won</code>, <code>lost</code>',
            '$fromStatus' => 'The status it moved from',
            '?string $value' => 'Exact decimal <strong>string</strong>, never a float. <code>null</code> means '
                . 'no value — which is not <code>\'0.00\'</code>',
            'string $currency' => 'ISO 4217 code, stamped at the time of the change and never converted',
        ],
        'convermetry_lead_updated' => [
            'string $submissionId' => 'The submission whose lead changed',
            'array $to' => '<code>{status, value, currency}</code> after the change',
            'array $from' => '<code>{status, value, currency}</code> before it',
            'int $userId' => 'Who made the change',
            'string $leadEventId' => 'The history row this change wrote. Fires <strong>after</strong> the '
                . 'transaction commits',
        ],

        'convermetry_should_queue_notification' => [
            'bool $should' => 'Whether to queue notifications for this submission. Runs after the configured '
                . 'rules already said yes, so it can only narrow',
            'string $formKey' => 'Provider-qualified form identity',
            'array $identity' => 'Identity columns only — <strong>never field values</strong>',
        ],
        'convermetry_notification_recipients' => [
            'array $recipients' => 'Addresses to queue. Each becomes its own row with its own retry chain. '
                . 'Re-validated through <code>sanitize_email()</code>/<code>is_email()</code>, deduplicated, and '
                . 'capped at 20. Runs once at <strong>queue</strong> time',
            'string $formKey' => 'Provider-qualified form identity',
            'array $identity' => 'Identity columns only',
        ],
        'convermetry_notification_message' => [
            'array $message' => '<code>subject</code>, <code>html</code> and <code>headers</code>. The '
                . '<strong>recipient is not changeable</strong> — one row is one address, and a per-attempt rewrite '
                . 'could collapse two rows onto one mailbox. Subject gets header-injection stripping and a 200-char '
                . 'cap, the body a 256 KB cap, and the four required headers are reinstated. '
                . '<strong><code>html</code> contains PII</strong>',
            'string $submissionId' => 'The submission being notified about',
            'int $attempt' => 'Which attempt this is — the filter runs per attempt',
        ],
        'convermetry_notification_queued' => [
            'string $submissionId' => 'The submission being notified about',
            'string $recipient' => 'The address this row will mail',
            'int $attempt' => 'Attempt number, 1 on first queue',
        ],
        'convermetry_notification_before_send' => [
            'string $submissionId' => 'The submission being notified about',
            'string $recipient' => 'The address about to be mailed. No subject, no body, no fields',
            'int $attempt' => 'Which attempt is about to run',
        ],
        'convermetry_notification_accepted' => [
            'string $submissionId' => 'The submission being notified about',
            'string $recipient' => 'The address that was mailed',
            'int $attempt' => 'Which attempt succeeded. "Accepted", never "delivered" — the local transport '
                . 'took the message, which is not receipt',
        ],
        'convermetry_notification_retry_scheduled' => [
            'string $submissionId' => 'The submission being notified about',
            'string $recipient' => 'The address that will be retried',
            'int $nextAttempt' => 'The attempt number that will run next',
            'int $nextAttemptAt' => 'Unix timestamp it becomes due',
        ],
        'convermetry_notification_abandoned' => [
            'string $submissionId' => 'The submission that will never be notified about',
            'string $recipient' => 'The address that will never be mailed',
            'int $attempt' => 'The attempt that exhausted the chain',
            'string $error' => 'Why the last attempt failed',
        ],
        'convermetry_notification_canceled' => [
            'string $submissionId' => 'The submission whose notifications were dropped',
            'string $recipient' => 'The address — <strong>empty on a bulk clear</strong>, which fires one '
                . 'aggregate action rather than reading addresses back purely to emit a hook',
            'string $reason' => '<code>\'expired\'</code>, <code>\'submission_deleted\'</code> or '
                . '<code>\'admin_clear\'</code>',
            'int $count' => 'How many rows were cancelled — 1 per row from the worker, N for a bulk clear',
        ],
        'convermetry_notification_retry_schedule' => [
            'int[] $delays' => 'Seconds before each email retry; default <code>[300, 900, 3600]</code>. '
                . 'Entries clamped to a minimum of 60, non-numeric entries discarded, an empty list falls back. '
                . 'Deliberately separate from the webhook schedule, and the hard two-hour TTL sits above it '
                . 'regardless',
        ],
        'convermetry_sensitive_keys' => [
            'string[] $patterns' => 'The <strong>effective</strong> list of credential-looking names redacted '
                . 'from the Activity Log and omitted from notification emails — so extend it; a shorter list weakens '
                . 'both surfaces. Matched as substrings of a canonical form (lowercased, non-alphanumeric runs '
                . 'collapsed to <code>_</code>), so <code>API Key</code>, <code>x-api-key</code> and '
                . '<code>API_KEY</code> all match <code>api_key</code>',
        ],

        'convermetry_retention_cleanup_started' => [
            'string $store' => 'Which store is being pruned',
            'string $cutoff' => 'UTC <code>Y-m-d H:i:s</code>; rows older than this go. Observational — a '
                . 'listener cannot cancel the pass, change the cutoff, or extend retention',
        ],
        'convermetry_retention_cleanup_completed' => [
            'string $store' => 'Which store was pruned',
            'string $cutoff' => 'The cutoff that was applied',
            'int $deleted' => 'Rows removed in this pass',
            'bool $moreRemain' => 'Whether rows past the cutoff are still there. Convermetry schedules any '
                . 'follow-up pass itself',
            'string $outcome' => '<code>completed</code>, <code>truncated</code>, <code>query_failed</code> '
                . 'or <code>lock_lost</code>',
        ],
        'convermetry_migration_started' => [
            'string $context' => '<code>\'cli\'</code>, <code>\'cron\'</code> or <code>\'admin\'</code>. The '
                . 'lease is held while this runs — <strong>do not throw</strong>, or it stays held until it expires. '
                . 'No SQL is passed to any migration hook',
        ],
        'convermetry_migration_completed' => [
            'string $context' => '<code>\'cli\'</code>, <code>\'cron\'</code> or <code>\'admin\'</code>',
            'bool $pending' => 'Whether migrations remain. Fires after the lease is released and after the '
                . 'reschedule decision, so this is settled. Pending mid-migration is normal, not an error',
        ],
        'convermetry_migration_failed' => [
            'string $context' => '<code>\'cli\'</code>, <code>\'cron\'</code> or <code>\'admin\'</code>',
            'string $error' => 'The exception <strong>class name</strong>. Fires after the lease is released '
                . 'and before the failure continues to the caller. A migration that merely did not land is not a '
                . 'failure',
        ],
        'convermetry_storage_error' => [
            'string $subsystem' => 'Which subsystem needed the write',
            'string $operation' => 'What it was doing',
            'string $code' => 'A stable short code for the failure',
            'array $context' => 'Identifying ids and counts. Never SQL, never '
                . '<code>$wpdb->last_error</code>, never submitted fields, IPs or secrets. Reserved for '
                . '<em>verified</em> failures: a duplicate <code>INSERT IGNORE</code>, an abandoned notification or '
                . 'a still-pending migration do not fire it',
        ],
        'convermetry_settings_saved' => [
            'string $section' => '<code>general</code>, <code>webhooks</code>, <code>notifications</code>, '
                . '<code>goals</code> or <code>funnels</code>',
            'string[] $changedKeys' => '<strong>Key names only, never values</strong> — two of these sections '
                . 'hold signing secrets and token-bearing endpoint URLs. Listens on WordPress\'s own option-write '
                . 'hooks, so it fires on a real write only and catches CLI and migration writers too',
        ],
        'convermetry_admin_capability' => [
            'string $capability' => 'The capability required, defaulting to <code>manage_options</code>. Must '
                . 'be a non-empty lowercase <code>[a-z0-9_]</code> name; anything else falls back, because '
                . '<code>current_user_can(\'\')</code> would lock the owner out',
            'string $scope' => 'Which surface — <code>analytics.view</code>, <code>submissions.view</code>, '
                . '<code>submissions.export</code>, <code>submissions.delete</code>, <code>leads.edit</code>, '
                . '<code>goals.manage</code>, <code>funnels.manage</code>, <code>forms.manage</code>, '
                . '<code>notifications.manage</code>, <code>webhooks.manage</code>, <code>activity.view</code>, '
                . '<code>activity.manage</code>, <code>api.manage</code>, <code>settings.manage</code>. Applied to '
                . 'menu visibility <strong>and</strong> every handler behind it',
        ],
        'convermetry_delivery_log_api_item' => [
            'array $extensions' => 'Namespaced additions for this REST item, bounded to 4 KB / 10 keys',
            'array $item' => 'The item as it will be returned, after endpoint-URL redaction and body decoding. '
                . 'Its core keys are <strong>immutable</strong> — a filter that could rewrite <code>success</code> '
                . 'would let a plugin lie to a monitoring dashboard',
        ],
    ];

    /**
     * A short, runnable example per hook.
     *
     * Each one is a complete registration a reader can paste into an mu-plugin
     * and adjust — the priority and argument count are always shown, because
     * the commonest hook mistake in WordPress is a callback that silently never
     * receives its later arguments.
     *
     * @var array<string, string>
     */
    private const array HOOK_EXAMPLES = [
        'convermetry_webhook_payload' => "add_filter('convermetry_webhook_payload', function (array \$payload, string \$messageType, array \$meta): array {\n"
            . "    if (\$messageType === 'form_submission') {\n"
            . "        \$payload['submission']['received_by'] = get_bloginfo('name');\n"
            . "    }\n\n"
            . "    return \$payload;\n"
            . "}, 10, 3);",
        'convermetry_webhook_payload_extensions' => "add_filter('convermetry_webhook_payload_extensions', function (array \$extensions, string \$messageType, array \$meta): array {\n"
            . "    \$extensions['acme/crm'] = ['tenant' => get_option('acme_tenant_id'), 'source' => 'wordpress'];\n\n"
            . "    return \$extensions;\n"
            . "}, 10, 3);",
        'convermetry_webhook_query_args' => "add_filter('convermetry_webhook_query_args', function (array \$params, array \$context): array {\n"
            . "    \$params['env'] = wp_get_environment_type();\n\n"
            . "    return \$params;\n"
            . "}, 10, 2);",
        'convermetry_webhook_headers' => "add_filter('convermetry_webhook_headers', function (array \$headers, array \$context): array {\n"
            . "    // Content-Type, Host, User-Agent, Idempotency-Key and the signature\n"
            . "    // header are restored afterwards — setting them here does nothing.\n"
            . "    \$headers['X-Acme-Tenant'] = get_option('acme_tenant_id');\n\n"
            . "    return \$headers;\n"
            . "}, 10, 2);",
        'convermetry_webhook_timeout' => "add_filter('convermetry_webhook_timeout', function (int \$timeout, array \$context): int {\n"
            . "    // Outside 1-30 the return is ignored, not clamped.\n"
            . "    return \$context['message_type'] === 'analytics_report' ? 25 : \$timeout;\n"
            . "}, 10, 2);",
        'convermetry_form_delivery_queued' => "add_action('convermetry_form_delivery_queued', function (array \$context): void {\n"
            . "    error_log(sprintf('queued %s for %s', \$context['delivery_id'], \$context['endpoint_label']));\n"
            . "});",
        'convermetry_webhook_delivery_frozen' => "add_action('convermetry_webhook_delivery_frozen', function (array \$context, string \$storage, int \$bodyBytes): void {\n"
            . "    if (\$bodyBytes > 512000) {\n"
            . "        error_log(\"large payload frozen ({\$bodyBytes} bytes) for {\$context['endpoint_label']}\");\n"
            . "    }\n"
            . "}, 10, 3);",
        'convermetry_webhook_before_send' => "add_action('convermetry_webhook_before_send', function (array \$context, array \$meta): void {\n"
            . "    // Do not throw here — the request this announces would not happen.\n"
            . "    error_log(sprintf('sending %s attempt %d, %d bytes, signed: %s',\n"
            . "        \$context['delivery_id'], \$context['attempt'], \$meta['body_bytes'],\n"
            . "        \$meta['signed'] ? 'yes' : 'no'));\n"
            . "}, 10, 2);",
        'convermetry_webhook_delivery_attempted' => "add_action('convermetry_webhook_delivery_attempted', function (array \$context, bool \$ok, int \$code, string \$message): void {\n"
            . "    if (!\$ok) {\n"
            . "        error_log(\"delivery {\$context['delivery_id']} failed: {\$code} {\$message}\");\n"
            . "    }\n"
            . "}, 10, 4);",
        'convermetry_delivery_attempt_logged' => "add_action('convermetry_delivery_attempt_logged', function (array \$context, string \$disposition): void {\n"
            . "    if (\$disposition === 'failed') {\n"
            . "        error_log(\"could not log attempt for {\$context['delivery_id']}\");\n"
            . "    }\n"
            . "}, 10, 2);",
        'convermetry_webhook_delivery_succeeded' => "add_action('convermetry_webhook_delivery_succeeded', function (array \$context): void {\n"
            . "    if (\$context['message_type'] === 'form_submission') {\n"
            . "        do_action('acme/crm_synced', \$context['submission_id']);\n"
            . "    }\n"
            . "});",
        'convermetry_webhook_retry_scheduled' => "add_action('convermetry_webhook_retry_scheduled', function (array \$context, int \$nextAttempt, int \$nextAttemptAt): void {\n"
            . "    error_log(sprintf('retry %d for %s due at %s', \$nextAttempt, \$context['endpoint_label'],\n"
            . "        gmdate('c', \$nextAttemptAt)));\n"
            . "}, 10, 3);",
        'convermetry_webhook_retry_chain_exhausted' => "add_action('convermetry_webhook_retry_chain_exhausted', function (array \$context): void {\n"
            . "    // \"This endpoint is failing\", not \"this data is gone\" — the frozen\n"
            . "    // body stays in the retry state and the next dispatch resumes it.\n"
            . "    wp_mail(get_option('admin_email'), 'Webhook endpoint failing',\n"
            . "        \$context['endpoint_label'] . ' has exhausted its retry chain.');\n"
            . "});",
        'convermetry_webhook_delivery_abandoned' => "add_action('convermetry_webhook_delivery_abandoned', function (array \$context, string \$reason): void {\n"
            . "    error_log(\"abandoned {\$context['submission_id']} to {\$context['endpoint_label']}: {\$reason}\");\n"
            . "}, 10, 2);",
        'convermetry_webhook_delivery_canceled' => "add_action('convermetry_webhook_delivery_canceled', function (array \$context, string \$reason): void {\n"
            . "    error_log(\"canceled {\$context['delivery_id']}: {\$reason}\");\n"
            . "}, 10, 2);",
        'convermetry_retry_schedule' => "add_filter('convermetry_retry_schedule', function (array \$delays): array {\n"
            . "    // Three retries instead of five; each entry is clamped to >= 60.\n"
            . "    return [300, 3600, 21600];\n"
            . "});",
        'convermetry_webhook_report_limit' => "add_filter('convermetry_webhook_report_limit', function (int \$limit): int {\n"
            . "    return 50; // Smaller top_* lists in analytics payloads.\n"
            . "});",
        'convermetry_delivery_log_row' => "add_filter('convermetry_delivery_log_row', function (\$row) {\n"
            . "    if (!is_array(\$row)) {\n"
            . "        return \$row;\n"
            . "    }\n\n"
            . "    // Skip logging successful test pings entirely.\n"
            . "    if (!empty(\$row['is_test']) && !empty(\$row['success'])) {\n"
            . "        return false;\n"
            . "    }\n\n"
            . "    return \$row;\n"
            . "});",
        'convermetry_allow_insecure_webhooks' => "add_filter('convermetry_allow_insecure_webhooks', function (bool \$allow): bool {\n"
            . "    // Development only. Evaluated when an endpoint is SAVED, not at send time.\n"
            . "    return wp_get_environment_type() === 'local';\n"
            . "});",
        'convermetry_allowed_hosts' => "add_filter('convermetry_allowed_hosts', function (array \$hosts): array {\n"
            . "    \$hosts[] = 'cdn.example.com'; // Lowercase host only, no scheme or path.\n\n"
            . "    return \$hosts;\n"
            . "});",

        'convermetry_should_enqueue_tracker' => "add_filter('convermetry_should_enqueue_tracker', function (bool \$should, array \$enabled): bool {\n"
            . "    // Can only suppress — returning true cannot resurrect a suppressed load.\n"
            . "    return \$should && !is_page('internal-tools');\n"
            . "}, 10, 2);",
        'convermetry_tracker_config_extensions' => "add_filter('convermetry_tracker_config_extensions', function (array \$extensions, array \$enabled): array {\n"
            . "    // PUBLIC to every visitor. Never a key, token, or anything per-visitor.\n"
            . "    \$extensions['acme/site'] = ['locale' => get_locale()];\n\n"
            . "    return \$extensions;\n"
            . "}, 10, 2);",
        'convermetry_should_track_event' => "add_filter('convermetry_should_track_event', function (bool \$should, string \$type, array \$data): bool {\n"
            . "    if (\$type === 'scroll_depth') {\n"
            . "        return false; // Drop this event type only.\n"
            . "    }\n\n"
            . "    return \$should;\n"
            . "}, 10, 3);",
        'convermetry_tracked_event' => "add_filter('convermetry_tracked_event', function (\$row, string \$type) {\n"
            . "    if (str_contains((string) \$row['page_url'], '/staging/')) {\n"
            . "        return false; // Drop the row entirely.\n"
            . "    }\n\n"
            . "    return \$row;\n"
            . "}, 10, 2);",
        'convermetry_client_ip' => "// Register in an mu-plugin: this runs on every request, and it is memoized.\n"
            . "add_filter('convermetry_client_ip', function (string \$ip): string {\n"
            . "    // Trust only the hop your proxy guarantees — a joined XFF chain is not an address.\n"
            . "    \$forwarded = \$_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';\n\n"
            . "    return filter_var(\$forwarded, FILTER_VALIDATE_IP) ? \$forwarded : \$ip;\n"
            . "});",
        'convermetry_stored_ip' => "add_filter('convermetry_stored_ip', function (string \$ip): string {\n"
            . "    // Pseudonymize here, not in convermetry_client_ip: that one is also\n"
            . "    // the rate-limit identity, and hashing it collapses every visitor into one bucket.\n"
            . "    \$packed = @inet_pton(\$ip);\n"
            . "    if (\$packed === false) {\n"
            . "        return '';\n"
            . "    }\n\n"
            . "    // Zero the last octet of an IPv4 address.\n"
            . "    return strlen(\$packed) === 4 ? inet_ntop(substr(\$packed, 0, 3) . chr(0)) : \$ip;\n"
            . "});",
        'convermetry_tracking_batch_recorded' => "add_action('convermetry_tracking_batch_recorded', function (int \$stored, int \$accepted, int \$offered, ?string \$batchId): void {\n"
            . "    // One action per BATCH, on the hottest path in the plugin. Keep it cheap.\n"
            . "    if (\$offered !== \$stored) {\n"
            . "        error_log(\"batch {\$batchId}: offered {\$offered}, accepted {\$accepted}, stored {\$stored}\");\n"
            . "    }\n"
            . "}, 10, 4);",
        'convermetry_tracking_rate_limited' => "add_action('convermetry_tracking_rate_limited', function (int \$events, int \$window): void {\n"
            . "    error_log(\"tracking rate limit hit: {\$events} events in a {\$window}s window\");\n"
            . "}, 10, 2);",
        'convermetry_rate_limits' => "// Register in an mu-plugin — this runs on the public ingestion path.\n"
            . "add_filter('convermetry_rate_limits', function (array \$defaults): array {\n"
            . "    // Charged PER EVENT, so one 20-event batch costs 20.\n"
            . "    return ['per_ip' => 600, 'site_wide' => 6000];\n"
            . "});",
        'convermetry_source_aliases' => "add_filter('convermetry_source_aliases', function (array \$aliases): array {\n"
            . "    // ADD to the map — returning a fresh array loses every default.\n"
            . "    \$aliases['fb'] = 'facebook';\n"
            . "    \$aliases['ig'] = 'instagram';\n\n"
            . "    return \$aliases;\n"
            . "});",
        'convermetry_channel' => "add_filter('convermetry_channel', function (string \$channel, array \$row, string \$type): string {\n"
            . "    if ((\$row['utm_source'] ?? '') === 'partner-portal') {\n"
            . "        return 'referral';\n"
            . "    }\n\n"
            . "    return \$channel;\n"
            . "}, 10, 3);",

        'convermetry_analytics_sections' => "add_filter('convermetry_analytics_sections', function (array \$sections): array {\n"
            . "    // Must implement AnalyticsSectionInterface. A section that throws is dropped.\n"
            . "    \$sections['acme/revenue'] = new Acme_Revenue_Section();\n\n"
            . "    return \$sections;\n"
            . "});",
        'convermetry_analytics_extensions' => "add_filter('convermetry_analytics_extensions', function (array \$extensions, string \$start, string \$end, int \$limit): array {\n"
            . "    \$extensions['acme/orders'] = ['count' => acme_orders_between(\$start, \$end)];\n\n"
            . "    return \$extensions;\n"
            . "}, 10, 4);",
        'convermetry_analytics_periods' => "add_filter('convermetry_analytics_periods', function (array \$periods): array {\n"
            . "    // Clamped to the retention window afterwards, then sorted and deduplicated.\n"
            . "    return [7, 14, 30, 90];\n"
            . "});",
        'convermetry_analytics_report_failed' => "add_action('convermetry_analytics_report_failed', function (string \$component, string \$reportKey, string \$start, string \$end, string \$error): void {\n"
            . "    error_log(\"report {\$component}/{\$reportKey} failed for {\$start}..{\$end}: {\$error}\");\n"
            . "}, 10, 5);",
        'convermetry_analytics_admin_panels' => "add_action('convermetry_analytics_admin_panels', function (string \$start, string \$end): void {\n"
            . "    // Runs after this screen's capability check. ESCAPE YOUR OWN OUTPUT.\n"
            . "    printf('<div class=\"cvm-card\"><h3>%s</h3><p>%s</p></div>',\n"
            . "        esc_html__('Revenue', 'acme'),\n"
            . "        esc_html(acme_revenue_between(\$start, \$end)));\n"
            . "}, 10, 2);",

        'convermetry_should_record_submission' => "add_filter('convermetry_should_record_submission', function (bool \$should, string \$formKey, string \$provider, array \$fields): bool {\n"
            . "    foreach (\$fields as \$field) {\n"
            . "        if (\$field['id'] === 'email' && str_ends_with((string) \$field['value'], '@spam.test')) {\n"
            . "            return false; // Visitor still sees success.\n"
            . "        }\n"
            . "    }\n\n"
            . "    return \$should;\n"
            . "}, 10, 4);",
        'convermetry_submission_fields' => "add_filter('convermetry_submission_fields', function (array \$fields, string \$formKey, string \$provider): array {\n"
            . "    foreach (\$fields as &\$field) {\n"
            . "        if (\$field['id'] === 'phone') {\n"
            . "            \$field['value'] = preg_replace('/\\D+/', '', (string) \$field['value']);\n"
            . "        }\n"
            . "    }\n\n"
            . "    return \$fields; // Re-normalized after you return it.\n"
            . "}, 10, 3);",
        'convermetry_submission_context_extensions' => "add_filter('convermetry_submission_context_extensions', function (array \$extensions, string \$formKey, string \$provider): array {\n"
            . "    \$extensions['acme/ab'] = ['variant' => \$_COOKIE['ab_variant'] ?? 'control'];\n\n"
            . "    return \$extensions;\n"
            . "}, 10, 3);",
        'convermetry_submission_recorded' => "add_action('convermetry_submission_recorded', function (\$submissionId, \$conversionId, \$context): void {\n"
            . "    // Fires before webhook delivery is considered, so it runs even with\n"
            . "    // no endpoints configured.\n"
            . "    acme_crm_enqueue(\$submissionId, \$context['session_id'] ?? '');\n"
            . "}, 10, 3);",
        'convermetry_submission_recorded_details' => "add_action('convermetry_submission_recorded_details', function (int \$rowId, string \$submissionId, array \$form, array \$fields): void {\n"
            . "    // \$fields contains PII — use convermetry_submission_recorded if you\n"
            . "    // only need to know that a submission happened.\n"
            . "    error_log(sprintf('%s via %s (%d fields)', \$form['form_name'], \$form['provider'], count(\$fields)));\n"
            . "}, 10, 4);",
        'convermetry_submission_duplicate' => "add_action('convermetry_submission_duplicate', function (string \$submissionId, string \$conversionId, string \$formKey): void {\n"
            . "    // Nothing was written or re-queued. DO NOT re-send anything.\n"
            . "    error_log(\"duplicate submission suppressed for {\$formKey}\");\n"
            . "}, 10, 3);",
        'convermetry_submission_delivery_state_changed' => "add_action('convermetry_submission_delivery_state_changed', function (string \$submissionId, string \$state, string \$previous): void {\n"
            . "    if (\$state === 'failed') {\n"
            . "        error_log(\"submission {\$submissionId} moved {\$previous} -> {\$state}\");\n"
            . "    }\n"
            . "}, 10, 3);",
        'convermetry_submission_deleted' => "add_action('convermetry_submission_deleted', function (int \$id, string \$submissionId): void {\n"
            . "    acme_crm_forget(\$submissionId);\n"
            . "}, 10, 2);",
        'convermetry_submissions_cleared' => "add_action('convermetry_submissions_cleared', function (): void {\n"
            . "    // Once for the whole operation; rows are dropped without being loaded.\n"
            . "    acme_crm_forget_all();\n"
            . "});",
        'convermetry_form_settings_saved' => "add_action('convermetry_form_settings_saved', function (array \$formKeys): void {\n"
            . "    error_log('form settings written for: ' . implode(', ', \$formKeys));\n"
            . "});",
        'convermetry_discovered_forms' => "add_filter('convermetry_discovered_forms', function (array \$forms, string \$providerKey): array {\n"
            . "    // Normalized back to {native_id, name} after you return it.\n"
            . "    \$forms[] = ['native_id' => 'custom-1', 'name' => 'Booking widget'];\n\n"
            . "    return \$forms;\n"
            . "}, 10, 2);",
        'convermetry_form_providers' => "// Memoized on first use — register at plugin load time, not on init.\n"
            . "add_filter('convermetry_form_providers', function (array \$providers): array {\n"
            . "    // Keyed by getKey(); reusing a bundled key REPLACES that provider.\n"
            . "    \$providers[] = new Acme_Form_Provider();\n\n"
            . "    return \$providers;\n"
            . "});",
        'convermetry_submission_csv_columns' => "add_filter('convermetry_submission_csv_columns', function (array \$columns): array {\n"
            . "    \$columns['acme_score'] = 'Lead score'; // Paired with the values filter BY KEY.\n\n"
            . "    return \$columns;\n"
            . "});",
        'convermetry_submission_csv_values' => "add_filter('convermetry_submission_csv_values', function (array \$values, array \$row): array {\n"
            . "    // Runs per row while streaming — keep it cheap. Scalars or null only.\n"
            . "    \$values['acme_score'] = acme_score_for(\$row['submission_id']);\n\n"
            . "    return \$values;\n"
            . "}, 10, 2);",
        'convermetry_submissions_columns' => "add_filter('convermetry_submissions_columns', function (array \$columns, array \$row): array {\n"
            . "    // PRINTED VERBATIM — escape it yourself.\n"
            . "    \$columns['acme_score'] = esc_html(acme_score_for(\$row['submission_id']));\n\n"
            . "    return \$columns;\n"
            . "}, 10, 2);",
        'convermetry_submission_detail_sections' => "add_action('convermetry_submission_detail_sections', function (array \$row): void {\n"
            . "    // After the nonce and capability checks. ESCAPE YOUR OWN OUTPUT.\n"
            . "    printf('<h4>%s</h4><p>%s</p>',\n"
            . "        esc_html__('CRM', 'acme'),\n"
            . "        esc_html(acme_crm_link(\$row['submission_id'])));\n"
            . "});",
        'convermetry_submission_row_actions' => "add_action('convermetry_submission_row_actions', function (array \$row): void {\n"
            . "    printf('<a class=\"button\" href=\"%s\">%s</a>',\n"
            . "        esc_url(wp_nonce_url(admin_url('admin-post.php?action=acme_push&id=' . rawurlencode(\$row['submission_id'])), 'acme_push')),\n"
            . "        esc_html__('Push to CRM', 'acme'));\n"
            . "});",
        'convermetry_forms_admin_sections' => "add_action('convermetry_forms_admin_sections', function (): void {\n"
            . "    // Outside the settings form — post your own form to admin-post.php.\n"
            . "    echo '<div class=\"cvm-card\"><h3>' . esc_html__('Acme sync', 'acme') . '</h3></div>';\n"
            . "});",
        'convermetry_form_submission' => "do_action('convermetry_form_submission',\n"
            . "    ['form_name' => 'Booking Widget', 'form_id' => 'booking-1'],\n"
            . "    [\n"
            . "        ['id' => 'email',     'label' => 'Email address',        'value' => \$email],\n"
            . "        ['id' => 'interests', 'label' => 'Services of interest', 'value' => ['Tax planning', 'Retirement']],\n"
            . "    ],\n"
            . "    ['url_query' => ['channel' => 'widget'], 'headers' => ['X-Source' => 'booking']] // optional\n"
            . ");",

        'convermetry_should_record_goal_completion' => "add_filter('convermetry_should_record_goal_completion', function (bool \$should, array \$row, array \$goal): bool {\n"
            . "    // A decision only — \$row is for inspection; nothing you return changes it.\n"
            . "    return \$should && (\$goal['name'] ?? '') !== 'Internal test';\n"
            . "}, 10, 3);",
        'convermetry_goal_completion' => "add_filter('convermetry_goal_completion', function (array \$row, array \$goal): array {\n"
            . "    \$row['value'] = \$row['value'] ?: '25.00';\n\n"
            . "    return \$row;\n"
            . "}, 10, 2);",
        'convermetry_goal_matched' => "add_action('convermetry_goal_matched', function (int \$stored, array \$rows): void {\n"
            . "    error_log(sprintf('%d of %d goal completions stored', \$stored, count(\$rows)));\n"
            . "}, 10, 2);",
        'convermetry_goal_completions_recorded' => "add_action('convermetry_goal_completions_recorded', function (int \$stored, int \$offered, array \$completionIds): void {\n"
            . "    // \$completionIds are the ids OFFERED, not the ids stored.\n"
            . "    if (\$stored < \$offered) {\n"
            . "        error_log(sprintf('%d duplicate completions suppressed', \$offered - \$stored));\n"
            . "    }\n"
            . "}, 10, 3);",
        'convermetry_goal_saved' => "add_action('convermetry_goal_saved', function (string \$goalId, array \$goal, ?array \$previous): void {\n"
            . "    error_log((\$previous === null ? 'created' : 'updated') . \" goal {\$goal['name']}\");\n"
            . "}, 10, 3);",
        'convermetry_goal_deleted' => "add_action('convermetry_goal_deleted', function (string \$goalId, string \$now): void {\n"
            . "    // Soft: completions and the name survive so historical reports keep working.\n"
            . "    error_log(\"goal {\$goalId} retired at {\$now}\");\n"
            . "}, 10, 2);",
        'convermetry_funnel_saved' => "add_action('convermetry_funnel_saved', function (string \$funnelId, array \$funnel, ?array \$previous): void {\n"
            . "    // Editing a funnel changes what every past report says, retroactively.\n"
            . "    error_log(\"funnel {\$funnel['name']} now has \" . count(\$funnel['steps']) . ' steps');\n"
            . "}, 10, 3);",
        'convermetry_funnel_deleted' => "add_action('convermetry_funnel_deleted', function (string \$funnelId, string \$now): void {\n"
            . "    error_log(\"funnel {\$funnelId} deleted at {\$now}\");\n"
            . "}, 10, 2);",
        'convermetry_lead_status_updated' => "add_action('convermetry_lead_status_updated', function (\$submissionId, \$toStatus, \$fromStatus, ?string \$value, string \$currency): void {\n"
            . "    if (\$toStatus === 'won') {\n"
            . "        // \$value is an exact decimal STRING or null — never a float.\n"
            . "        acme_crm_close(\$submissionId, \$value, \$currency);\n"
            . "    }\n"
            . "}, 10, 5);",
        'convermetry_lead_updated' => "add_action('convermetry_lead_updated', function (string \$submissionId, array \$to, array \$from, int \$userId, string \$leadEventId): void {\n"
            . "    // Fires AFTER the transaction commits.\n"
            . "    error_log(sprintf('%s: %s -> %s by user %d', \$submissionId, \$from['status'], \$to['status'], \$userId));\n"
            . "}, 10, 5);",

        'convermetry_should_queue_notification' => "add_filter('convermetry_should_queue_notification', function (bool \$should, string \$formKey, array \$identity): bool {\n"
            . "    // Runs after the configured rules said yes, so it can only narrow.\n"
            . "    return \$should && \$formKey !== 'gravityforms:9';\n"
            . "}, 10, 3);",
        'convermetry_notification_recipients' => "add_filter('convermetry_notification_recipients', function (array \$recipients, string \$formKey, array \$identity): array {\n"
            . "    // Each address becomes its own row with its own retry chain. Capped at 20.\n"
            . "    \$recipients[] = 'sales@example.com';\n\n"
            . "    return \$recipients;\n"
            . "}, 10, 3);",
        'convermetry_notification_message' => "add_filter('convermetry_notification_message', function (array \$message, string \$submissionId, int \$attempt): array {\n"
            . "    // The recipient is deliberately not changeable here.\n"
            . "    \$message['subject'] = '[Lead] ' . \$message['subject'];\n\n"
            . "    return \$message;\n"
            . "}, 10, 3);",
        'convermetry_notification_queued' => "add_action('convermetry_notification_queued', function (string \$submissionId, string \$recipient, int \$attempt): void {\n"
            . "    error_log(\"notification queued for {\$recipient}\");\n"
            . "}, 10, 3);",
        'convermetry_notification_before_send' => "add_action('convermetry_notification_before_send', function (string \$submissionId, string \$recipient, int \$attempt): void {\n"
            . "    // No subject, no body, no fields.\n"
            . "    error_log(\"mailing {\$recipient}, attempt {\$attempt}\");\n"
            . "}, 10, 3);",
        'convermetry_notification_accepted' => "add_action('convermetry_notification_accepted', function (string \$submissionId, string \$recipient, int \$attempt): void {\n"
            . "    // \"Accepted\" by the local transport — that is not receipt.\n"
            . "    error_log(\"mail accepted for {\$recipient}\");\n"
            . "}, 10, 3);",
        'convermetry_notification_retry_scheduled' => "add_action('convermetry_notification_retry_scheduled', function (string \$submissionId, string \$recipient, int \$nextAttempt, int \$nextAttemptAt): void {\n"
            . "    error_log(sprintf('retry %d for %s at %s', \$nextAttempt, \$recipient, gmdate('c', \$nextAttemptAt)));\n"
            . "}, 10, 4);",
        'convermetry_notification_abandoned' => "add_action('convermetry_notification_abandoned', function (string \$submissionId, string \$recipient, int \$attempt, string \$error): void {\n"
            . "    error_log(\"gave up mailing {\$recipient} for {\$submissionId}: {\$error}\");\n"
            . "}, 10, 4);",
        'convermetry_notification_canceled' => "add_action('convermetry_notification_canceled', function (string \$submissionId, string \$recipient, string \$reason, int \$count): void {\n"
            . "    // \$recipient is empty on a bulk clear, where \$count is the total.\n"
            . "    error_log(\"cancelled {\$count} notification(s): {\$reason}\");\n"
            . "}, 10, 4);",
        'convermetry_notification_retry_schedule' => "add_filter('convermetry_notification_retry_schedule', function (array \$delays): array {\n"
            . "    // The hard two-hour TTL still sits above this regardless.\n"
            . "    return [600, 1800];\n"
            . "});",
        'convermetry_sensitive_keys' => "add_filter('convermetry_sensitive_keys', function (array \$patterns): array {\n"
            . "    // The returned list IS the effective list — extend it, never shorten it.\n"
            . "    \$patterns[] = 'ssn';\n"
            . "    \$patterns[] = 'national_id';\n\n"
            . "    return \$patterns;\n"
            . "});",

        'convermetry_retention_cleanup_started' => "add_action('convermetry_retention_cleanup_started', function (string \$store, string \$cutoff): void {\n"
            . "    // Observational — you cannot cancel the pass or change the cutoff.\n"
            . "    error_log(\"pruning {\$store} older than {\$cutoff}\");\n"
            . "}, 10, 2);",
        'convermetry_retention_cleanup_completed' => "add_action('convermetry_retention_cleanup_completed', function (string \$store, string \$cutoff, int \$deleted, bool \$moreRemain, string \$outcome): void {\n"
            . "    error_log(\"{\$store}: {\$deleted} deleted, outcome {\$outcome}\" . (\$moreRemain ? ', more remain' : ''));\n"
            . "}, 10, 5);",
        'convermetry_migration_started' => "add_action('convermetry_migration_started', function (string \$context): void {\n"
            . "    // The lease is held here. DO NOT THROW.\n"
            . "    error_log(\"migrations started via {\$context}\");\n"
            . "});",
        'convermetry_migration_completed' => "add_action('convermetry_migration_completed', function (string \$context, bool \$pending): void {\n"
            . "    // \$pending is settled by now. Pending mid-migration is normal, not an error.\n"
            . "    error_log(\"migrations finished via {\$context}\" . (\$pending ? ' (more pending)' : ''));\n"
            . "}, 10, 2);",
        'convermetry_migration_failed' => "add_action('convermetry_migration_failed', function (string \$context, string \$error): void {\n"
            . "    // \$error is a class name, never a message.\n"
            . "    wp_mail(get_option('admin_email'), 'Convermetry migration failed', \"{\$context}: {\$error}\");\n"
            . "}, 10, 2);",
        'convermetry_storage_error' => "add_action('convermetry_storage_error', function (string \$subsystem, string \$operation, string \$code, array \$context): void {\n"
            . "    // Verified failures only — never SQL, fields, IPs or secrets.\n"
            . "    error_log(\"storage error {\$code} in {\$subsystem}/{\$operation}\");\n"
            . "}, 10, 4);",
        'convermetry_settings_saved' => "add_action('convermetry_settings_saved', function (string \$section, array \$changedKeys): void {\n"
            . "    // Key names only — two sections hold secrets and token-bearing URLs.\n"
            . "    error_log(\"{\$section} settings changed: \" . implode(', ', \$changedKeys));\n"
            . "}, 10, 2);",
        'convermetry_admin_capability' => "add_filter('convermetry_admin_capability', function (string \$capability, string \$scope): string {\n"
            . "    // Grant deliberately: submissions.export is every lead's name and email in one file.\n"
            . "    return \$scope === 'analytics.view' ? 'edit_posts' : \$capability;\n"
            . "}, 10, 2);",
        'convermetry_delivery_log_api_item' => "add_filter('convermetry_delivery_log_api_item', function (array \$extensions, array \$item): array {\n"
            . "    // The item's core keys are immutable; only extensions are yours.\n"
            . "    \$extensions['acme/trace'] = ['id' => acme_trace_for(\$item['delivery_id'] ?? '')];\n\n"
            . "    return \$extensions;\n"
            . "}, 10, 2);",
    ];

    /**
     * Registers the menu and asset hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'addMenu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
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
            Capability::required(Capability::ANALYTICS_VIEW),
            self::MENU_SLUG,
            [self::class, 'render']
        );
    }

    /**
     * Enqueues the About page's own script (sticky-nav active-section
     * highlighting) on this screen only. The shared admin stylesheet is
     * already enqueued for every Convermetry screen by AnalyticsPage.
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
            'cvm-about',
            CVM_PLUGIN_URL . 'assets/js/about.js',
            [],
            CVM_VERSION,
            true
        );
    }

    /**
     * Renders the sticky section navigation.
     *
     * The "Top" link is a plain anchor to the page heading, so it works with
     * JavaScript disabled exactly as it does with it enabled.
     *
     * @return void
     */
    private static function nav(): void
    {
        ?>
        <nav class="cvm-about-nav" aria-label="Documentation sections">
        <div class="cvm-about-nav-inner">
        <a class="cvm-about-nav-top" href="#cvm-about-top"><span aria-hidden="true">&uarr;</span> Top</a>
        <?php

        foreach (self::SECTIONS as $id => $label) {
            printf(
                '<a class="cvm-about-nav-link" href="#%1$s" data-cvm-section="%1$s">%2$s</a>',
                esc_attr($id),
                esc_html($label)
            );
        }

        ?>
        </div></nav>
        <?php
    }

    /**
     * Opens one anchored section.
     *
     * @param string $id Section id; must be a key of {@see SECTIONS}.
     * @return void
     */
    private static function sectionStart(string $id): void
    {
        ?>
        <section class="cvm-about-section" id="<?php echo esc_attr($id); ?>">
        <h2 class="cvm-about-section-title"><?php echo esc_html(self::SECTIONS[$id] ?? $id); ?></h2>
        <?php
    }

    /**
     * Closes a section.
     *
     * @return void
     */
    private static function sectionEnd(): void
    {
        ?>
        </section>
        <?php
    }

    /**
     * Opens one documentation card.
     *
     * @param string $title Card heading.
     * @return void
     */
    private static function cardStart(string $title): void
    {
        ?>
        <div class="cvm-card cvm-about-card">
        <h3 class="cvm-card-title"><?php echo esc_html($title); ?></h3>
        <?php
    }

    /**
     * Closes a documentation card.
     *
     * @return void
     */
    private static function cardEnd(): void
    {
        ?>
        </div>
        <?php
    }

    /**
     * Renders one code block.
     *
     * @param string $code Raw code; escaped here.
     * @return void
     */
    private static function code(string $code): void
    {
        ?>
        <pre class="cvm-about-code"><?php echo esc_html($code); ?></pre>
        <?php
    }

    /**
     * Renders one hook's heading, signature, and collapsed detail panel.
     *
     * The per-argument breakdown and the worked example live behind a
     * "Learn More" toggle rather than inline. With eighty-five hooks, showing
     * them all would make a already-long page unreadable — and the detail is
     * reference material a reader wants for the one hook they are wiring up,
     * not something to scroll past eighty-four times.
     *
     * The panel is a sibling of the button rather than a nested element so the
     * button keeps its position when the panel opens.
     *
     * @param string $name      Hook name.
     * @param string $type      'action' or 'filter'.
     * @param string $signature The call as it appears in the source.
     * @param string $summary   One-line description (may contain safe inline HTML).
     * @return void
     */
    private static function hookStart(string $name, string $type, string $signature, string $summary): void
    {
        $args     = self::HOOK_ARGS[$name] ?? [];
        $example  = self::HOOK_EXAMPLES[$name] ?? '';
        $detailId = 'hook-detail-' . $name;
        ?>
        <div class="cvm-about-hook" id="hook-<?php echo esc_attr($name); ?>">
        <h4 class="cvm-about-hook-name"><code><?php echo esc_html($name); ?></code><span class="cvm-about-hook-type cvm-about-hook-type-<?php echo esc_attr($type); ?>"><?php echo esc_html($type); ?></span></h4>
        <p class="cvm-about-hook-summary"><?php echo wp_kses_post($summary); ?></p>
        <?php
        self::code($signature);

        if ($args === [] && $example === '') {
            return;
        }
        ?>
        <button type="button" class="cvm-about-hook-toggle" aria-expanded="false"
                aria-controls="<?php echo esc_attr($detailId); ?>">Learn More</button>
        <div class="cvm-about-hook-detail" id="<?php echo esc_attr($detailId); ?>" hidden>
            <?php if ($args !== []) { ?>
            <p class="cvm-about-hook-detail-title">Arguments</p>
            <table class="cvm-about-hook-args">
            <tbody>
            <?php foreach ($args as $arg => $note) { ?>
                <tr>
                <th scope="row"><code><?php echo esc_html($arg); ?></code></th>
                <td><?php echo wp_kses_post($note); ?></td>
                </tr>
            <?php } ?>
            </tbody>
            </table>
            <?php } ?>

            <?php if ($example !== '') { ?>
            <p class="cvm-about-hook-detail-title">Example</p>
            <?php self::code($example); ?>
            <?php } ?>
        </div>
        <?php
    }

    /**
     * Closes a hook block.
     *
     * @return void
     */
    private static function hookEnd(): void
    {
        ?>
        </div>
        <?php
    }

    /**
     * Renders the About page.
     *
     * @return void
     */
    public static function render(): void
    {
        if (!Capability::currentUserCan(Capability::ANALYTICS_VIEW)) {
            return;
        }

        ?>
        <div class="wrap cvm-wrap cvm-about">
        <h1 id="cvm-about-top">About Convermetry</h1>
        <p class="cvm-about-intro">Convermetry <?php echo esc_html(CVM_VERSION); ?> — visitor analytics, campaign attribution,
        and server-confirmed form conversion tracking with reliable webhook delivery. It answers the full funnel question: where a
        visitor came from, what they did, which form they submitted, what they submitted, which campaign produced the lead, what that
        lead turned out to be worth, and whether it reached your downstream systems.</p>
        <p class="cvm-about-meta">Everything on this page is also in the plugin's <code>README.md</code>. Use the bar below
        to jump between sections.</p>
        <?php

        self::nav();

        self::renderOverview();
        self::renderAdminPages();
        self::renderTracking();
        self::renderConversions();
        self::renderForms();
        self::renderIdentifiers();
        self::renderWebhooks();
        self::renderPayloads();
        self::renderNotifications();
        self::renderDeveloper();
        self::renderHooks();
        self::renderRest();
        self::renderPrivacy();

        ?>
        </div>
        <?php
    }

    /**
     * Overview: what the plugin is and how its pieces connect.
     *
     * @return void
     */
    private static function renderOverview(): void
    {
        self::sectionStart('overview');

        self::cardStart('The question Convermetry answers');
        self::code('Where did this visitor come from?
        ↓
What pages did they visit?
        ↓
What did they interact with?
        ↓
Which form did they submit?
        ↓
Was the submission actually accepted by WordPress?
        ↓
What data did they submit?
        ↓
Which marketing campaign produced the lead?
        ↓
Was the lead worth anything?
        ↓
Was it successfully delivered to external systems?');
        ?>
        <p>Convermetry works standalone — a full analytics dashboard, form integrations, lead outcomes, and webhook delivery
        inside one WordPress install — and is architected so a future Convermetry SaaS can receive <code>analytics_report</code>
        and <code>form_submission</code> messages from many installations, keyed by a shared, versioned payload schema.</p>
        <ul class="cvm-about-requirements">
        <li><span class="cvm-about-label">Version</span> <?php echo esc_html(CVM_VERSION); ?></li>
        <li><span class="cvm-about-label">WordPress</span> 6.3+</li>
        <li><span class="cvm-about-label">PHP</span> 8.3+</li>
        <li><span class="cvm-about-label">REST namespace</span> <code>convermetry/v1</code></li>
        <li><span class="cvm-about-label">PHP namespace</span> <code>Convermetry\</code></li>
        <li><span class="cvm-about-label">License</span> GPL-2.0-or-later</li></ul>
        <?php
        self::cardEnd();

        self::cardStart('How the pieces connect');
        ?>
        <p>A dependency-free frontend tracker records page views, clicks, form attempts, hovers, scroll depth, and confirmed
        conversions, with last-touch campaign attribution persisted per session (30-minute inactivity window, no cookies). When a visitor
        submits a form, the tracker injects hidden internal fields — a per-attempt <code>cvm_conversion_id</code> token, the <code>cvm_session_id</code>,
        and an attribution snapshot — into the form before any AJAX handler serializes it. The server-side form-provider integration
        reads those fields when the form plugin confirms the submission, strips them from the lead data, records the conversion under
        the same token, and queues webhook deliveries in the background. Correlation is token-based end to end; timestamps are never
        used to match a submission to a session.</p>
        <?php
        self::code('session_id
    ├── source / medium, campaign, click-id type
    ├── entrance referrer, landing page
    ├── page views and interactions
    ├── device
    └── conversion_id
            └── server-confirmed form submission → webhook delivery
                                                 → email notification
                                                 → lead status & value');
        ?>
        <div class="cvm-about-note">Nothing in that chain waits on a third party. A form submission is recorded and returned
        to the visitor before any payload is built or any HTTP request is made — an external webhook outage can never make a valid
        submission appear to fail.</div>
        <?php
        self::cardEnd();

        self::sectionEnd();
    }

    /**
     * The admin menu map.
     *
     * @return void
     */
    private static function renderAdminPages(): void
    {
        self::sectionStart('admin-pages');

        self::cardStart('Where everything lives');
        self::code('Convermetry
    Analytics      — the reporting dashboard (top-level default)
    Submissions    — every server-confirmed lead, with its attribution,
                     answers, status and value
    Goals          — conversions that are not form submissions
    Funnels        — the ordered path to a conversion, and where visitors drop out
    Forms          — provider status, discovered forms, per-form configuration,
                     engagement and abandonment
    Notifications  — internal email alerts for new submissions
    Webhooks       — endpoints, delivery types, signing, schedule, customization
    Activity Log   — every delivery attempt with its (redacted) payload and response
    Settings       — website/client identity, tracking toggles, privacy, retention
    About          — this documentation');
        self::cardEnd();

        self::cardStart('Submissions vs. Activity Log');
        ?>
        <p>These two are easy to confuse, and they answer different questions. Clearing one never touches the other.</p>
        <table class="cvm-about-table"><thead><tr><th scope="col"></th><th scope="col">Submissions</th><th scope="col">Activity
        Log</th></tr></thead><tbody>
        <tr><td>One row is</td><td>one form submission</td><td>one delivery <em>attempt</em></td></tr>
        <tr><td>Exists without webhooks</td><td><strong>Yes</strong></td><td>No</td></tr>
        <tr><td>Shows</td><td>the lead, its attribution, its answers, its outcome</td><td>the payload sent and the response
        returned</td></tr>
        <tr><td>Cleared by</td><td>Clear All Submissions</td><td>Clear All Logs</td></tr></tbody></table>
        <p><strong>Notifications</strong> is independent of both: its own master switch, its own queue, and it works on a site
        with no webhook endpoints configured at all.</p>
        <?php
        self::cardEnd();

        self::cardStart('The Analytics dashboard');
        ?>
        <p>For a selectable 7/30/90-day period (UTC calendar days, clamped to the retention window with an explanatory notice
        when clamped): summary cards, an accessible daily page-view chart (single-Tab-stop keyboard navigation, touch/mouse tooltips,
        visible axes, data-table fallback), and collapsible sections for Content, Engagement, Acquisition, Devices, Conversions, Goals,
        Lead outcomes, and Recent Activity. A <strong>Print / Save as PDF</strong> button produces a print-optimized report. Empty
        states and per-section database-error notices are explicit — a failed query is never rendered as a silent zero.</p>
        <p>Three form metrics are deliberately kept distinct, because merging them would hide which evidence each rests on:</p>
        <ul class="cvm-about-features">
        <li><strong>Form Submit Attempts</strong> — frontend <code>submit</code> events; success unconfirmed.</li>
        <li><strong>Confirmed Conversions</strong> — unique conversions deduplicated by <code>conversion_id</code> across
        both detection paths.</li>
        <li><strong>Server-Confirmed Submissions</strong> — submissions a form plugin's own server-side success hook confirmed.
        Where a provider integration exists, this signal is authoritative.</li></ul>
        <?php
        self::cardEnd();

        self::cardStart('Who can see what');
        ?>
        <p>Every Convermetry screen resolves its required capability through one named scope, and the scope is applied to <strong>menu
        visibility and every handler behind it</strong> — never to the menu alone, which would hide a screen while leaving its POST
        handler reachable. All fourteen default to <code>manage_options</code>, so nothing changes until you filter one.</p>
        <table class="cvm-about-table"><thead><tr><th scope="col">Scope</th><th scope="col">Covers</th></tr></thead><tbody>
        <tr><td><code>analytics.view</code></td><td>The dashboard and this About page.</td></tr>
        <tr><td><code>submissions.view</code></td><td>The Submissions list and detail panels.</td></tr>
        <tr><td><code>submissions.export</code></td><td>CSV export — <strong>every lead's name and email in one file</strong>.
        Grant deliberately.</td></tr>
        <tr><td><code>submissions.delete</code></td><td>Deleting one submission, or clearing them all.</td></tr>
        <tr><td><code>leads.edit</code></td><td>Setting lead status and value.</td></tr>
        <tr><td><code>goals.manage</code> · <code>funnels.manage</code></td><td>Creating and editing goal and funnel definitions.</td></tr>
        <tr><td><code>forms.manage</code></td><td>Per-form configuration and exclusions.</td></tr>
        <tr><td><code>notifications.manage</code> · <code>webhooks.manage</code></td><td>Notification and endpoint settings
        — both hold credentials.</td></tr>
        <tr><td><code>activity.view</code> · <code>activity.manage</code></td><td>Reading the Activity Log, versus clearing
        it and managing its API key.</td></tr>
        <tr><td><code>api.manage</code></td><td>The delivery-log REST API and its key.</td></tr>
        <tr><td><code>settings.manage</code></td><td>Identity, tracking, privacy, and retention.</td></tr></tbody></table>
        <p>Remap one with the <code>convermetry_admin_capability</code> filter. It must return a non-empty lowercase <code>[a-z0-9_]</code>
        capability name; anything else falls back to the default, because <code>current_user_can('')</code> would lock the owner out
        of their own site.</p>
        <?php
        self::cardEnd();

        self::sectionEnd();
    }

    /**
     * Tracking, sessions, and attribution.
     *
     * @return void
     */
    private static function renderTracking(): void
    {
        self::sectionStart('tracking');

        self::cardStart('The tracker');
        ?>
        <p>A single dependency-free script is enqueued deferred on frontend pages — never for logged-in users while exclusion
        is on, which is the default. It batches events and delivers them to <code>POST /wp-json/convermetry/v1/track</code>.</p>
        <p><strong>Tracked event types</strong>, each individually toggleable under <strong>Settings → Tracking</strong>:</p>
        <table class="cvm-about-table"><thead><tr><th scope="col">Type</th><th scope="col">Records</th></tr></thead><tbody>
        <tr><td><code>pageview</code></td><td>One per page view, with the session's attribution snapshot.</td></tr>
        <tr><td><code>click</code></td><td>Clicked element label/tag and destination.</td></tr>
        <tr><td><code>form_view</code></td><td>A form scrolled into view. Fires once per visible form per page view.</td></tr>
        <tr><td><code>form_start</code></td><td>Someone began filling a form in.</td></tr>
        <tr><td><code>form_error</code></td><td>A native browser validation failure — field id, field type, and which <code>ValidityState</code>
        flag failed. <strong>Never the value typed.</strong></td></tr>
        <tr><td><code>form_submit</code></td><td>A submit press. Success unconfirmed.</td></tr>
        <tr><td><code>form_success</code></td><td>A confirmed conversion, carrying the <code>conversion_id</code> in <code>event_value</code>.</td></tr>
        <tr><td><code>hover</code></td><td>Configurable dwell time; opt-in per element via <code>data-cvm-hover</code>.</td></tr>
        <tr><td><code>scroll_depth</code></td><td>50% and 100% milestones.</td></tr>
        <tr><td><code>custom_event</code></td><td>A named event from <code>Convermetry.track()</code>, kept only when a goal
        matches its name.</td></tr></tbody></table>
        <p><strong>Delivery reliability.</strong> Batches flush every 5 seconds, at 20 events, and on page exit via <code>navigator.sendBeacon</code>.
        Every batch is persisted to a bounded <code>sessionStorage</code> store <em>before</em> it is sent and removed only on server
        acknowledgment. Failed sends back off exponentially with jitter; a 429 pauses the whole tab and honors <code>Retry-After</code>.
        Delivery is <strong>at-least-once</strong> and replays are <strong>idempotent</strong> — rows are stored under a unique (batch
        id, event ordinal) key, so a replayed batch never inflates counts.</p>
        <p><strong>Endpoint defenses.</strong> Whitelisted, currently-enabled event types only; tracked page URLs must be <code>http(s)</code>
        on this site's host and are canonicalized to scheme + host + path; foreign <code>Origin</code>/<code>Referer</code> rejected;
        bots and empty user agents ignored; DNT/GPC enforced server-side when enabled; request bodies and batch sizes capped; scalar-only
        field values, sanitized and truncated; rate limits charged <strong>per event</strong> — 300 per IP per minute plus 3,000
        site-wide — via atomic object-cache counters, falling back (and failing <strong>closed</strong>) to an atomic single-statement
        database counter. The per-IP check runs first, so a flooding IP never consumes the site-wide budget.</p>
        <p><strong>Sessions are cookie-free.</strong> The id lives in <code>localStorage</code> and rotates after 30 minutes
        of inactivity.</p>
        <?php
        self::cardEnd();

        self::cardStart('Campaign and channel attribution');
        ?>
        <p>All six UTM parameters (<code>utm_source</code>, <code>utm_medium</code>, <code>utm_campaign</code>, <code>utm_id</code>,
        <code>utm_term</code>, <code>utm_content</code>) are captured from tagged landing URLs. Ad-click identifiers (<code>gclid</code>,
        <code>gbraid</code>, <code>wbraid</code>, <code>fbclid</code>, <code>msclkid</code>, <code>ttclid</code>, <code>twclid</code>,
        <code>li_fat_id</code>) are recognized — only the parameter <strong>name</strong> is stored, never the value — and imply
        source/medium when no UTM tags are present.</p>
        <p>Attribution is <strong>last-touch within the session</strong>: the most recent tagged landing attributes the visit
        from that point on, and the snapshot rides on <em>every</em> event in the session. Untagged acquisition persists too — the
        session's entrance referrer travels alongside (with an explicit marker for verified direct entrances), so organic, social and
        referral visits keep their channel across internal navigation.</p>
        <p><strong>Channels</strong> — every attributed event is classified at ingestion into: Paid Search, Paid Social,
        Organic Search, Organic Social, Email, Display, Affiliate, SMS, Referral, Direct, Other. There is exactly <strong>one</strong>
        attribution engine: the dashboard, the analytics payloads, every goal completion, and every form submission's <code>analytics_context.channel</code>
        classify through the same code, so they cannot disagree. Source aliases are normalized (<code>convermetry_source_aliases</code>)
        and the result is overridable per event (<code>convermetry_channel</code>).</p>
        <?php
        self::cardEnd();

        self::cardStart('Session → submission → conversion correlation');
        ?>
        <p>The link between analytics and leads is <strong>token-based — never timestamps</strong>.</p>
        <ol class="cvm-about-list">
        <li>On page load, and again at submit time in the capture phase <em>before</em> any AJAX handler serializes the form,
        the tracker injects three hidden fields: <code>cvm_conversion_id</code> (a fresh token per submission attempt), <code>cvm_session_id</code>,
        and <code>cvm_context</code> (a compact JSON snapshot of attribution, entrance referrer, landing page, and page URL).</li>
        <li>The form plugin processes the submission normally. When its <strong>server-side success hook</strong> fires, Convermetry's
        adapter extracts and strictly validates those fields — every transport shape is handled, including Fluent Forms' serialized
        <code>data</code> blob — and <strong>strips every <code>cvm_*</code> field</strong> from the lead data.</li>
        <li>The confirmed conversion is recorded as a <code>form_success</code> analytics event under that same token, together
        with a durable submission row.</li>
        <li>The tracker's own frontend success listeners reuse the <strong>same token</strong>, so whichever paths fire, every
        report deduplicates them into <strong>one</strong> conversion.</li></ol>
        <p>AJAX forms are fully supported. When the fields are absent — tracker disabled, privacy signals honored, JavaScript
        blocked, server-to-server submissions — the conversion id is generated on the server and the submission still records and
        delivers, just with an empty <code>analytics_context</code>. No cookies are used at any point.</p>
        <div class="cvm-about-note"><strong>Duplicate protection at every layer:</strong> a double-fired provider callback
        hits the <code>UNIQUE conversion_id</code> index and records nothing twice; queue rows are unique per (submission, endpoint);
        reports count <code>DISTINCT conversion_id</code>; receivers deduplicate by <code>delivery_id</code>.</div>
        <?php
        self::cardEnd();

        self::sectionEnd();
    }

    /**
     * Goals, funnels, engagement, and lead outcomes.
     *
     * @return void
     */
    private static function renderConversions(): void
    {
        self::sectionStart('conversions');

        self::cardStart('Four things beyond the conversion count');
        ?>
        <ul class="cvm-about-features">
        <li><strong>Goals</strong> count important actions that are not form submissions: a phone number tapped, a PDF opened,
        a booking link followed, a pricing page reached.</li>
        <li><strong>Funnels</strong> measure the ordered path to a conversion — how many sessions reached each step and how
        many were lost between them.</li>
        <li><strong>Form engagement</strong> reports views, starts, attempts, successes and abandonment per form, plus which
        fields fail validation most often.</li>
        <li><strong>Lead status and value</strong> record what a submission turned out to be worth, so campaign reporting can
        be measured against outcomes rather than treating every conversion as equal.</li></ul>
        <?php
        self::cardEnd();

        self::cardStart('Goals');
        ?>
        <p><strong>A confirmed form submission is not a goal.</strong> A submission is <em>server-confirmed</em> — the form
        plugin's own success hook said so. A goal completion is a <em>browser-observed</em> signal. They are stored in different tables
        and counted separately on purpose: folding submissions into goals would quietly downgrade the plugin's most trustworthy number
        to the standard of its least.</p>
        <p><strong>Matching happens on the server</strong>, at ingestion, against data the tracker already sends. The browser
        is never told what your goals are. Three consequences:</p>
        <ul class="cvm-about-features">
        <li>Your list of valuable actions is competitive information and stays on the server.</li>
        <li><strong>Phone and email goals need no configuration at all.</strong> The tracker already reports click destinations
        and keeps <code>tel:</code> and <code>mailto:</code> URLs whole while stripping query strings from everything else — pick
        "on a phone number link" and you are done. No CSS selector required.</li>
        <li>A visitor cannot manufacture a conversion by claiming one. They can only report the same raw activity any visitor
        reports; the server decides what it means.</li></ul>
        <p>The one exception is a <strong>CSS selector</strong>, which genuinely cannot be evaluated without the DOM. Only
        those selectors are sent to the tracker, and the goal ids it reports back are re-validated against your enabled selector goals
        before anything is recorded.</p>
        <table class="cvm-about-table"><thead><tr><th scope="col">Goal type</th><th scope="col">Rules</th></tr></thead><tbody>
        <tr><td>Reaching a page</td><td>is exactly · contains · starts with · ends with. A path (<code>/thank-you/</code>)
        matches the URL's path; a full URL matches the whole URL. Trailing slashes are forgiven and matching is case-insensitive.</td></tr>
        <tr><td>A click</td><td>on a phone number link · on an email link · that leaves this site · where the link contains
        / is exactly · matching a CSS selector. A phone tap is deliberately <strong>not</strong> also counted as an external link.</td></tr>
        <tr><td>A custom event</td><td>Matched by name, fired from your own code with <code>Convermetry.track('name')</code>.</td></tr></tbody></table>
        <p><strong>Counting.</strong> Each goal counts either <em>once per visit</em> or <em>every occurrence</em>, and deduplication
        is enforced by a <strong>UNIQUE database constraint</strong> rather than a PHP check — so an at-least-once replay of a tracker
        batch collides with the original instead of double-counting.</p>
        <p><strong>Editing.</strong> A goal keeps its id forever. Editing its <em>matching rule</em> starts a new measurement
        series (and reports say the definition changed) so two different questions are never blended into one line. Renaming, pausing,
        or repricing a goal resets nothing. Removing one is a soft delete: past completions are kept and still appear, correctly labelled,
        in reports for earlier periods. Goals count from when you create them and are never applied retroactively.</p>
        <div class="cvm-about-note">Goals do <strong>not</strong> override the tracking toggles they depend on — a click
        goal cannot fire while click tracking is off. Silently re-enabling tracking you switched off would be the wrong fix, so the
        Goals screen names the specific setting and links to it instead.</div>
        <?php
        self::cardEnd();

        self::cardStart('Funnels');
        self::code('Retirement Consultation Funnel

Landing Page          1,242 sessions
  ↓ 62% continued · 471 lost
Services                771 sessions
  ↓ 38% continued · 480 lost
Form Started            291 sessions
  ↓ 44% continued · 163 lost
Submission Attempted    128 sessions
  ↓ 81% continued · 24 lost
Confirmed Submission    104 sessions

Overall conversion: 8.37%');
        ?>
        <p>Step types: <strong>visited a page</strong>, <strong>completed a goal</strong>, <strong>saw a form</strong>, <strong>started
        filling a form</strong>, <strong>attempted to submit</strong>, and <strong>submission confirmed by the form plugin</strong>.
        A form step with no specific form counts any form on the site.</p>
        <p><strong>Ordering is real.</strong> Steps must occur in sequence — a session that reached step three without step
        two is not counted at step three. Each step is constrained to occur strictly after the previous one, because the naive approach
        gets this wrong in a way that looks right on small data:</p>
        <?php
        self::code('Session did:  B at 09:00,  A at 10:00,  B at 11:00
A → B funnel: SHOULD succeed (they did A, then B)
Earliest-occurrence comparison: MIN(B)=09:00 < A, so it reports failure.');
        ?>
        <p>Ordering uses the event id rather than the timestamp: the events table's <code>created_at</code> is the moment the
        row was <em>inserted</em>, so the two are the same order by construction and the id is the finer, tie-free version of it. A
        consequence worth knowing: funnel order is <em>ingestion</em> order. Within one batch the browser's order is preserved; a batch
        that failed and was resent from a later page sorts by when it arrived.</p>
        <p>Sessions with no session id are excluded — an empty session id is not one visitor, it is every visitor whose session
        could not be established, and grouping on it would produce one enormous pseudo-session that appears to complete every funnel.</p>
        <p><strong>Cohorts.</strong> A funnel is the set of sessions that reached step 1 during the selected period. Later
        steps are counted for up to <strong>24 hours past the end of the period</strong>, so a session that entered at 23:55 on the
        last day is not unfairly cut off. Each funnel's result is cached for five minutes, keyed by its definition, so editing a step
        invalidates the cache automatically. Limits: 8 steps per funnel, 20 funnels per site.</p>
        <?php
        self::cardEnd();

        self::cardStart('Form engagement & abandonment');
        ?>
        <p>Mixing units here would make every rate meaningless and the mix would be invisible, so each column states its unit
        and its evidence:</p>
        <table class="cvm-about-table"><thead><tr><th scope="col">Column</th><th scope="col">Unit</th><th scope="col">Evidence</th></tr></thead><tbody>
        <tr><td>Views</td><td><strong>sessions</strong> in which the form scrolled into view</td><td>browser-observed</td></tr>
        <tr><td>Started</td><td><strong>sessions</strong> in which someone began filling it in</td><td>browser-observed</td></tr>
        <tr><td>Attempts</td><td><strong>raw submit presses</strong> — one visitor fighting a validation error produces several,
        which is the point</td><td>browser-observed</td></tr>
        <tr><td>Successful</td><td><strong>distinct conversion ids</strong></td><td><strong>server-confirmed</strong></td></tr>
        <tr><td>Abandoned</td><td><strong>sessions</strong> that started and did not succeed</td><td>browser-observed</td></tr></tbody></table>
        <p>A <strong>completion rate above 100% is not an error.</strong> It means confirmed submissions outnumbered observed
        starts — which happens when visitors submit with JavaScript blocked. The browser-observed columns are undercounting, and
        clamping the number to 100 would hide the one figure that tells you so.</p>
        <p><strong>Abandonment has a grace period.</strong> A form started ninety seconds ago is being filled in, not abandoned.
        A start counts as abandoned only after <strong>30 minutes</strong> pass with no confirmed submission; anything more recent
        shows as <em>still in progress</em>. Without that, abandonment would spike toward 100% for the most recent hour of any window
        and then decay — an artifact that looks exactly like a real problem. The 30 minutes matches the tracker's session idle window.</p>
        <p><strong>Friction points.</strong> Where a provider uses native browser validation, Convermetry records which field
        failed and why:</p>
        <?php
        self::code('Most common friction points

Field            Type     Problem                                  Errors
phone            tel      Left empty                                  218
desired-service  select   Left empty                                  164
email            email    Wrong format (e.g. not an email address)    131');
        ?>
        <div class="cvm-about-note"><strong>No value a visitor typed is ever recorded.</strong> A validation event is rebuilt
        on the server from exactly three whitelisted pieces — the field's developer-chosen id, its type, and which <code>ValidityState</code>
        flag failed — and every other key in the request is discarded <em>by construction</em> rather than by a blocklist. Field
        ids are character-restricted and truncated to 64 characters, so an implementation that mistakenly sent a typed value would
        be stripped to something unrecognizable rather than quietly stored.</div>
        <p><strong>Elementor is excluded from form-level engagement.</strong> It identifies a form by its display <em>name</em>
        on the server while exposing a widget <em>id</em> in the browser, so the two cannot be matched reliably — and an engagement
        figure attributed to the wrong form is worse than none. Elementor submissions are recorded, attributed, delivered and reported
        normally everywhere else. The other six providers are fully supported.</p>
        <?php
        self::cardEnd();

        self::cardStart('Lead status & value');
        ?>
        <p>Set both on the <strong>Submissions</strong> detail panel; both are filterable in the list.</p>
        <table class="cvm-about-table"><thead><tr><th scope="col">Status</th><th scope="col">Meaning</th></tr></thead><tbody>
        <tr><td><code>new</code></td><td>Not yet assessed — the default for every submission.</td></tr>
        <tr><td><code>qualified</code></td><td>A real, well-matched lead.</td></tr>
        <tr><td><code>unqualified</code></td><td>A genuine person who was not a fit.</td></tr>
        <tr><td><code>won</code></td><td>Converted into business.</td></tr>
        <tr><td><code>lost</code></td><td>A real lead that did not convert.</td></tr>
        <tr><td><code>spam</code></td><td>Never a lead at all.</td></tr></tbody></table>
        <p><strong>This is deliberately not a CRM.</strong> Six statuses; no assignees, pipeline stages, follow-up dates, or
        activity notes. Every one of those would be a worse version of a tool you already have, and none changes the answer to <em>"which
        marketing produced valuable leads?"</em></p>
        <ul class="cvm-about-features">
        <li><strong><code>won</code> counts as qualified.</strong> A lead that converted was self-evidently qualified, and
        requiring it to pass through <code>qualified</code> first would under-report every site that records the final outcome in one
        step.</li>
        <li><strong>Only <code>spam</code> leaves the denominator.</strong> An unqualified or lost lead was still a lead your
        marketing produced. Excluding those would make a channel look better the more poor-quality leads it sent — which is exactly
        why <code>spam</code> is separate from <code>unqualified</code>.</li></ul>
        <p><strong>Value and currency.</strong> Values are stored as exact <code>DECIMAL(13,2)</code> and handled as decimal
        <strong>strings</strong> end to end — never floating point. A lead worth 0.10 recorded ten thousand times totals exactly
        1000.00. Input is forgiving about presentation and strict about value: <code>$12,500.00</code>, <code>12 500</code>, <code>&euro;1.234,56</code>
        and <code>1234.56 USD</code> all parse; <code>12abc</code> is <strong>rejected</strong> rather than silently read as 12. The
        site currency is <strong>stamped onto each lead</strong> when you first record a value, so changing the setting later never
        rewrites what is already recorded — and reports group by currency and <strong>never sum across codes</strong>.</p>
        <p><strong>History.</strong> Every status or value change records who made it and when. The change and its history
        row are written in a single transaction, so a lead can never end up in a state its history disagrees with.</p>
        <p><strong>Reporting.</strong> <em>Analytics → Lead outcomes</em> breaks leads down by channel, campaign, landing
        page, and form: Lead Qualification Rate = (qualified + won) ÷ total; Lead-to-Win Rate = won ÷ total; Attributed Lead Value;
        Attributed Revenue (the same, restricted to <code>won</code>). <strong>Nothing is called ROI or ROAS</strong> — both are
        ratios against ad <em>spend</em>, Convermetry has no cost data, and a "return" computed without the investment half is not
        a weaker version of the metric, it is a different number wearing its name. <strong>Time to lead</strong> is measured from the
        first page view of the session that converted, and reported as medians rather than averages, because the distribution is heavily
        right-skewed.</p>
        <div class="cvm-about-note">Lead status and value are recorded <strong>locally only</strong> in this version. A form
        payload is frozen when it is first delivered and scheduled analytics windows never revisit, so a lead field on either could
        only ever report &ldquo;new&rdquo; — wrong for every lead you qualify, and a field that lies is worse than an absent one.
        Use the <code>convermetry_lead_status_updated</code> action to push outcomes to your own systems today. Goal completions <em>do</em>
        travel, in the analytics report payload, because a completion either happened in the window or it did not.</div>
        <?php
        self::cardEnd();

        self::sectionEnd();
    }

    /**
     * Form providers and per-form configuration.
     *
     * @return void
     */
    private static function renderForms(): void
    {
        self::sectionStart('forms');

        self::cardStart('Supported form providers');
        ?>
        <p>Providers are feature-detected — nothing breaks when a plugin is absent, and activation never fatals on a site
        with no form plugin at all — and their forms are discovered automatically. Detected forms are <strong>included by default</strong>,
        so a new form needs no setup; exclusions and per-form configuration live on the <strong>Forms</strong> page.</p>
        <table class="cvm-about-table"><thead><tr><th scope="col">Provider</th><th scope="col">Server-side hook and notes</th></tr></thead><tbody>
        <tr><td>Elementor Pro</td><td><code>elementor_pro/forms/new_record</code>. Per-form settings key by form <strong>name</strong>.</td></tr>
        <tr><td>Gravity Forms</td><td><code>gform_after_submission</code>, via public APIs.</td></tr>
        <tr><td>WPForms</td><td><code>wpforms_process_complete</code>.</td></tr>
        <tr><td>Contact Form 7</td><td><code>wpcf7_mail_sent</code>.</td></tr>
        <tr><td>Fluent Forms</td><td><code>fluentform/submission_inserted</code>, plus the legacy alias, guarded against the
        double fire.</td></tr>
        <tr><td>Ninja Forms</td><td><code>ninja_forms_after_submission</code>. Admin form previews are skipped, and multi-instance
        form ids are normalized to the numeric form id.</td></tr>
        <tr><td>Formidable Forms</td><td><code>frm_after_create_entry</code> at priority 30. Repeater/embedded child entries
        and saved drafts are skipped.</td></tr></tbody></table>
        <p>Per-form settings key by the provider's own form id for every provider except Elementor Pro, which keys by form
        name. Custom forms integrate through the <a href="#developer">public API</a>, and third-party adapters register with the <a href="#hook-convermetry_form_providers"><code>convermetry_form_providers</code></a>
        filter.</p>
        <?php
        self::cardEnd();

        self::cardStart('Per-form configuration');
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col">Setting</th><th scope="col">Meaning</th></tr></thead><tbody>
        <tr><td>Native Form ID</td><td>The provider's own identity (read-only).</td></tr>
        <tr><td>Custom/External Form ID</td><td>Sent as <code>form_id</code> in payloads; the native id is the fallback.</td></tr>
        <tr><td>Enabled / Excluded</td><td>Detected forms are included by default. Exclusion stops processing; configuration
        is <strong>preserved</strong> while excluded.</td></tr>
        <tr><td>Include page URL query parameters</td><td>Per-form override of the global setting.</td></tr>
        <tr><td>URL Query Parameters</td><td>Per-form parameters, appended to outbound webhook URLs.</td></tr>
        <tr><td>Request Headers</td><td>Per-form headers, added to outbound requests.</td></tr></tbody></table>
        <p>Merge precedence for query parameters, later overriding earlier for shared keys:</p>
        <?php
        self::code('Global URL parameters → Page URL parameters → Per-form parameters → Runtime parameters

Headers: Content-Type → global → per-form → runtime
         (User-Agent, Idempotency-Key and X-Convermetry-Signature added at send time)');
        self::cardEnd();

        self::cardStart('Submissions');
        ?>
        <p><strong>Convermetry → Submissions</strong> lists every server-confirmed lead with its date, form, provider, channel
        and campaign, delivery status, lead status and value. Filters cover date range, provider, form, channel, campaign, delivery
        state, lead status, and free-text search; the detail panel is loaded on demand and shows the submitted answers, the full analytics
        context, the delivery outcome per endpoint, and the lead's status history. CSV export streams in keyset-paginated chunks, so
        even a very large table exports in bounded memory.</p>
        <p>Deleting a submission also cancels anything still queued for it — webhook queue rows and email notifications alike
        — and cascades its lead history away, firing <code>convermetry_submission_deleted</code> once everything attached to it is
        gone.</p>
        <p>Every submission — bundled provider or custom API — passes the same two extension points before anything is
        written: <a href="#hook-convermetry_should_record_submission"><code>convermetry_should_record_submission</code></a> can veto
        the whole write (the visitor still sees success), and <a href="#hook-convermetry_submission_fields"><code>convermetry_submission_fields</code></a>
        sees the normalized descriptors, with any change re-normalized so the <code>cvm_*</code> strip and the descriptor shape hold.</p>
        <?php
        self::cardEnd();

        self::sectionEnd();
    }

    /**
     * The three identifiers.
     *
     * @return void
     */
    private static function renderIdentifiers(): void
    {
        self::sectionStart('identifiers');

        self::cardStart('submission_id · conversion_id · delivery_id');
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col">Identifier</th><th scope="col">Identifies</th><th scope="col">Scope</th></tr></thead><tbody>
        <tr><td><code>submission_id</code></td><td>The form submission itself.</td><td><strong>Global</strong> — identical
        in every delivery of that submission, to every endpoint. Deduplicate by it when aggregating the same lead arriving via multiple
        endpoints.</td></tr>
        <tr><td><code>conversion_id</code></td><td>The analytics conversion joined to the submission, and its session.</td><td>Shared
        between the frontend <code>form_success</code> event and the server-confirmed record, so the two detection paths can never
        double-count. Every Convermetry conversion report deduplicates by it.</td></tr>
        <tr><td><code>delivery_id</code></td><td>One outbound webhook delivery.</td><td><strong>Endpoint-specific</strong>;
        stable across every retry; echoed as the <code>Idempotency-Key</code> header. <strong>Receivers deduplicate by this alone.</strong></td></tr></tbody></table>
        <p>Supporting identifiers: a batch id plus each event's ordinal make tracker ingestion idempotent, <code>session_id</code>
        groups one visit, <code>completion_id</code> identifies one goal completion, and <code>lead_event_id</code> identifies one
        lead status change.</p>
        <?php
        self::cardEnd();

        self::sectionEnd();
    }

    /**
     * Webhook endpoints, message types, and delivery guarantees.
     *
     * @return void
     */
    private static function renderWebhooks(): void
    {
        self::sectionStart('webhooks');

        self::cardStart('The two outbound message types');
        ?>
        <p>Convermetry sends two kinds of webhook message. Every endpoint on the <strong>Webhooks</strong> page chooses which
        it receives, and the two are fully independent — an endpoint may take either one on its own, or both.</p>
        <ul class="cvm-about-features">
        <li><strong>Analytics Reports</strong> — <code>message_type: analytics_report</code>. Scheduled, <em>aggregated</em>
        reporting for a time window, sent on the site-wide schedule you pick: hourly, twice daily, daily, or weekly. This is <em>not</em>
        one webhook per page view or click — an entire window is summarized into a single delivery. Each endpoint tracks its own
        delivery window, so a payload covers the time since <em>that</em> endpoint's last successful delivery, and a newly added endpoint
        can optionally be backfilled with the retained history. <strong>Send analytics test</strong> delivers one on demand.</li>
        <li><strong>Form Submissions</strong> — <code>message_type: form_submission</code>. One message per server-confirmed
        lead, delivered immediately through the background form-delivery queue instead of on a schedule. <strong>Send form test</strong>
        delivers one on demand.</li></ul>
        <?php
        self::code('Convermetry SaaS            Analytics ✓   Form Submissions ✓
HubSpot Middleware          Analytics ✗   Form Submissions ✓   (leads only)
Reporting Data Warehouse    Analytics ✓   Form Submissions ✗   (analytics only)');
        ?>
        <p><strong>For an analytics-only endpoint</strong>, check <strong>Analytics Reports</strong> and leave <strong>Form
        Submissions</strong> unchecked — no submitted form field values are ever sent to that endpoint. Analytics reports do still
        describe individual conversions (<code>conversions.recent[]</code>: conversion id, form name and ids, provider, and the visitor's
        IP when IP storage is on), so "analytics-only" means no field values, not no lead identifiers. The reverse works the same way.
        Both message types appear in the <strong>Activity Log</strong>, where you can tell them apart by their <code>message_type</code>.</p>
        <p><strong>Internal email notifications are a third, separate path</strong> with its own master switch and its own
        queue — see <a href="#notifications">Notifications</a>. Notification sends do not appear in the Activity Log, which covers
        webhook deliveries only.</p>
        <?php
        self::cardEnd();

        self::cardStart('Endpoint configuration');
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col">Field</th><th scope="col">Purpose</th></tr></thead><tbody>
        <tr><td>Webhook URL</td><td>HTTPS required. The <code>convermetry_allow_insecure_webhooks</code> filter permits <code>http://</code>
        for development.</td></tr>
        <tr><td>Label</td><td>Optional; badges Activity Log entries and identifies endpoints in the REST API.</td></tr>
        <tr><td>Signing Secret</td><td>Optional per-endpoint HMAC key; overrides the shared secret for this endpoint only,
        so one receiver never learns the key that signs payloads for others.</td></tr>
        <tr><td>Delivery Types</td><td><strong>Analytics Reports</strong> and/or <strong>Form Submissions</strong>.</td></tr></tbody></table>
        <p>All requests go through one transport: <code>wp_safe_remote_post()</code> with the URL re-validated at request time
        (SSRF protection even if DNS changed after saving), redirects disabled, response downloads capped at 64 KB at the transport
        layer, and a 15-second timeout.</p>
        <?php
        self::cardEnd();

        self::cardStart('Delivery, retries & idempotency');
        ?>
        <p>Both message types share one delivery pipeline — an Analytics Report is frozen per reporting window, a Form Submission
        per submission, and from there the guarantees are identical.</p>
        <?php
        self::code('Initial delivery → 5 min → 30 min → 2 h → 6 h → 16 h    (~24.6 h total)');
        ?>
        <p><strong>Frozen requests.</strong> On the first attempt the final URL (all query-parameter layers merged), the configured
        headers, and the serialized JSON body are frozen and replayed byte-for-byte under the same <code>delivery_id</code>. A configuration
        change after a failure never mutates them, and endpoints that already acknowledged a delivery are never re-sent.</p>
        <p><strong>Three headers are regenerated per attempt</strong> from that frozen body: <code>Idempotency-Key</code> (always
        the same delivery id), <code>User-Agent</code> (carries the plugin version, so it changes if the site updates mid-chain), and
        <code>X-Convermetry-Signature</code>, computed with the secret <em>current at send time</em> — so rotating a secret changes
        a retry's signature, intentionally, so a rotated key still verifies.</p>
        <ul class="cvm-about-features">
        <li><strong>Analytics reports</strong> retry through per-endpoint single-event crons. An exhausted chain — or one
        whose cron could not be scheduled, detected as <em>orphaned</em> — keeps its frozen delivery; the next scheduled dispatch
        re-sends it under the original id first, and only after acknowledgment does the endpoint's marker advance, exactly to the frozen
        window's end, so consecutive deliveries never overlap. Dispatch runs under a site-wide mutex (MySQL named lock, with a lease-based
        fallback), and each site's schedule is anchored at a stable random offset so fleets sharing one endpoint never stampede it.</li>
        <li><strong>Form submissions</strong> use one queue row per submission &times; endpoint. Rows are claimed atomically
        by a token-stamped conditional <code>UPDATE</code>, so overlapping workers cannot double-send, and rows stranded by a dead
        worker are reclaimed after 10 minutes. Acknowledged endpoints are deleted from the queue and never re-sent when a sibling endpoint
        fails.</li></ul>
        <p><strong>Conversion delivery inside Analytics Reports is lossless</strong> — a window holding more than 100 individual
        conversions is split into consecutive deliveries rather than truncated. Each <code>top_*</code> list holds up to 200 rows.</p>
        <div class="cvm-about-note"><strong>Delivery is at-least-once.</strong> Any duplicate a receiver can ever see carries
        a <code>delivery_id</code> it has already processed — deduplicating by <code>delivery_id</code> is sufficient to never double-process.</div>
        <p>Every stage is observable and most are customizable: the URL, headers, timeout and payload are composed through
        filters that run <strong>once, before the freeze</strong> (<code>convermetry_webhook_query_args</code>, <code>convermetry_webhook_headers</code>,
        <code>convermetry_webhook_timeout</code>, <code>convermetry_webhook_payload</code>), and the lifecycle — queued, frozen,
        about to send, attempted, logged, succeeded, retry scheduled, chain exhausted, abandoned, canceled — is reported by ten actions
        that all carry the same credential-free context. See <a href="#hooks">Hooks</a>.</p>
        <?php
        self::cardEnd();

        self::cardStart('HMAC signatures');
        ?>
        <p>When a signing secret is configured — shared, or per-endpoint, where the endpoint's own secret wins — every
        request carries the HMAC-SHA256 of the <strong>raw JSON body bytes</strong>:</p>
        <?php
        self::code('X-Convermetry-Signature: sha256=<hex>');
        self::code('$expected = \'sha256=\' . hash_hmac(\'sha256\', $rawBody, $secret);
if (!hash_equals($expected, $_SERVER[\'HTTP_X_CONVERMETRY_SIGNATURE\'] ?? \'\')) {
    http_response_code(401);
    exit;
}');
        ?>
        <p>Verify by recomputing over the exact received bytes and comparing with a constant-time function. Note that the Activity
        Log stores a <em>redacted</em> representation of the request, not a byte-exact copy — so a stored body will not reproduce
        its signature. Verify against what your endpoint received, never against a log copy.</p>
        <?php
        self::cardEnd();

        self::sectionEnd();
    }

    /**
     * Payload samples and the schema-version contract.
     *
     * @return void
     */
    private static function renderPayloads(): void
    {
        self::sectionStart('payloads');

        self::cardStart('The shared envelope');
        ?>
        <p>Every outbound message carries the same envelope, whatever its type.</p>
        <?php
        self::code('{
    "schema_version": "1.0 | 1.1 | 2.0",
    "source": "convermetry",
    "plugin_version": "' . CVM_VERSION . '",
    "message_type": "analytics_report | form_submission",
    "website_info": { … },
    "generated_at": "ISO-8601 UTC",
    "delivery_id": "endpoint-specific idempotent id",
    …one type-specific block…
}');
        ?>
        <p>The two message types version <strong>independently</strong>: analytics reports are at <code>1.1</code> (<code>1.0</code>
        plus an additive <code>analytics.goals</code> section), and form submissions at <code>2.0</code> — except rows recorded before
        the field-descriptor change, which keep emitting <code>1.0</code> forever. <strong>Branch on <code>schema_version</code>, never
        on <code>plugin_version</code>.</strong></p>
        <?php
        self::cardEnd();

        self::cardStart('Analytics report payload (excerpt)');
        ?>
        <p>Reporting data for one time window — aggregates plus the individual conversions that occurred in it. No other
        lead data is included.</p>
        <?php
        self::code('{
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
        "goals": [ { "goal_id": "g_phone_tap", "name": "Phone number tapped",
                     "completions": 34, "sessions": 29, "value": "0.00" } ],
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
}');
        ?>
        <ul class="cvm-about-features">
        <li><code>period</code> is the UTC window the report covers — <code>start</code> inclusive, <code>end</code> exclusive.</li>
        <li>The <code>analytics</code> section comes from the same reporting query layer the dashboard uses, so a payload and
        the admin screens cannot disagree.</li>
        <li><code>analytics.conversions.recent</code> lists the <em>individual</em> conversions inside the window — each
        with the visitor's <code>ip_address</code> when IP storage is on — while every other section is aggregate reporting data.
        <code>total</code> is deduplicated by conversion id, and <code>server_confirmed</code> counts the stored server-confirmed submissions.</li>
        <li>Deduplicate received deliveries by <code>delivery_id</code> (echoed as the <code>Idempotency-Key</code> header)
        — never by <code>period</code>.</li>
        <li>An <strong>analytics test</strong> covers the last 7 days, carries <code>"test": true</code>, is never retried,
        and does not advance the endpoint's normal delivery marker — so testing an endpoint never creates a gap in its scheduled
        reporting.</li></ul>
        <?php
        self::cardEnd();

        self::cardStart('Form submission payload (excerpt)');
        ?>
        <p>One lead's own data plus the analytics context correlated to it. An Analytics Report is the mirror image: reporting
        data for a window, with no submitted field values — though its <code>conversions.recent[]</code> does identify individual
        conversions. <code>ip_address</code> is the submitter's address, captured during the visitor's own request and frozen with
        the record; it is always present, and empty when disabled in Settings or when no valid address could be determined.</p>
        <?php
        self::code('{
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
}');
        self::cardEnd();

        self::cardStart('submission_data: schema 2.0');
        ?>
        <p><code>submission_data</code> is an <strong>ordered list of field descriptors</strong>, not an object. Match on <code>id</code>;
        show <code>label</code>.</p>
        <table class="cvm-about-table"><thead><tr><th scope="col">Key</th><th scope="col">Type</th><th scope="col">Notes</th></tr></thead><tbody>
        <tr><td><code>id</code></td><td>string</td><td>The provider-native field ID or key. Stable across renames. <strong>Never
        empty</strong> — an entry without one is dropped.</td></tr>
        <tr><td><code>label</code></td><td>string</td><td>The human-readable label captured at submission time. <strong>Falls
        back to <code>id</code></strong> when the provider exposes no reliable label.</td></tr>
        <tr><td><code>value</code></td><td>string | string[]</td><td>A sanitized string, or a list of sanitized strings for
        multi-value fields. <strong>Never a nested object.</strong></td></tr></tbody></table>
        <p><strong>Why a list.</strong> The pre-2.0 format was a <code>label =&gt; value</code> object, which forced every
        provider to discard either the stable ID (Gravity Forms, WPForms, Ninja Forms and Formidable key by label) or the human label
        (Elementor keys by ID). It also <strong>silently merged two fields that shared a label</strong> — two fields both called
        "Name" became one. A list preserves provider order, preserves duplicates, and keeps the ID for automation alongside the label
        for humans.</p>
        <p>Label availability differs by provider, and Convermetry does not guess:</p>
        <table class="cvm-about-table"><thead><tr><th scope="col">Provider</th><th scope="col"><code>id</code></th><th scope="col"><code>label</code></th></tr></thead><tbody>
        <tr><td>Elementor</td><td>field ID</td><td>the field's title</td></tr>
        <tr><td>Gravity Forms</td><td>field ID</td><td>the field label</td></tr>
        <tr><td>WPForms</td><td>field ID</td><td>the field name</td></tr>
        <tr><td>Ninja Forms</td><td>field ID (or key)</td><td>the field label, else its key</td></tr>
        <tr><td>Formidable Forms</td><td>field ID</td><td>the field name, else its key</td></tr>
        <tr><td>Contact Form 7</td><td>posted field name</td><td><strong>same as <code>id</code></strong> — CF7 exposes no
        reliable label without parsing form markup</td></tr>
        <tr><td>Fluent Forms</td><td>submitted key</td><td><strong>same as <code>id</code></strong> — labels live in an internal
        JSON blob, not a public API</td></tr></tbody></table>
        <p>Convermetry's own correlation fields (<code>cvm_conversion_id</code>, <code>cvm_session_id</code>, <code>cvm_context</code>)
        are stripped before storage and never appear here.</p>
        <p><strong>Migrating from schema 1.0.</strong> Historical rows are <strong>never</strong> rewritten, in the database
        or on the wire — otherwise one <code>submission_id</code> could arrive in two different shapes, and a frozen retry could
        deliver a <code>1.0</code> body long after the upgrade. Branch on <code>schema_version</code>:</p>
        <?php
        self::code('$data = $payload[\'form_submission\'][\'submission_data\'];

$fields = $payload[\'schema_version\'] === \'1.0\'
    // Legacy object: the key is the label, and the ID is unavailable.
    ? array_map(
        static fn($label, $value) => [\'id\' => $label, \'label\' => $label, \'value\' => $value],
        array_keys($data),
        $data
    )
    : $data;');
        ?>
        <p>The <strong>Send form test</strong> button on the Webhooks page sends schema <code>2.0</code>, so you can verify
        a receiver against the current format before real leads arrive.</p>
        <?php
        self::cardEnd();

        self::sectionEnd();
    }

    /**
     * Email notifications.
     *
     * @return void
     */
    private static function renderNotifications(): void
    {
        self::sectionStart('notifications');

        self::cardStart('Internal email alerts');
        ?>
        <p><strong>Convermetry → Notifications</strong> emails a chosen internal address when a form submission is recorded,
        enriched with the attribution Convermetry already captured for that visitor. It is <strong>off by default</strong> and has
        its own master switch — it works with no webhook endpoints configured, and disabling webhooks does not disable it. These
        are <strong>internal</strong> notifications: Convermetry never emails the person who submitted the form, and visitor autoresponders
        are out of scope.</p>
        <ul class="cvm-about-features">
        <li><strong>Email creates a copy of lead data outside Convermetry's controls.</strong> Deleting a submission — or
        letting retention expire it — cancels anything still queued and guarantees no queued message can be rendered afterwards,
        because the queue stores no lead data of its own. It <strong>cannot recall a message already sent</strong>. If you are relying
        on Convermetry's retention window for a compliance story, enabling this changes that story.</li>
        <li><strong>Your form plugin probably already emails you.</strong> These are in addition, not a replacement.</li>
        <li><strong>"Sent" means handed to your mail system.</strong> Convermetry uses <code>wp_mail()</code>; a <code>true</code>
        return means the local transport <em>accepted</em> the message. Nothing in the plugin claims a notification was "delivered"
        — that word is reserved for webhooks, where a receiver actually returned 2xx.</li>
        <li>Convermetry stores <strong>no mail credentials</strong> and implements no SMTP transport of its own. Any SMTP plugin
        you already run keeps working unchanged.</li></ul>
        <?php
        self::cardEnd();

        self::cardStart('Settings');
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col">Setting</th><th scope="col">Default</th><th scope="col">Notes</th></tr></thead><tbody>
        <tr><td>Enable notifications</td><td><strong>Off</strong></td><td>Master switch.</td></tr>
        <tr><td>Recipients</td><td>none</td><td>One address per line. Validated, deduplicated case-insensitively, capped at
        20. Each recipient gets a <strong>separate message</strong>, so nobody sees the rest of the list. Never derived from submitted
        data.</td></tr>
        <tr><td>Subject</td><td><code>New {form_name} submission on {site_name}</code></td><td>Token allowlist below.</td></tr>
        <tr><td>Scope</td><td>Every form</td><td><em>Every form</em> notifies unless a form is switched off; <em>Only selected
        forms</em> notifies only forms switched on. Per-form rules are inherit / always / never.</td></tr>
        <tr><td>Submitted fields</td><td>On</td><td>The visitor's answers, as label/value rows.</td></tr>
        <tr><td>Analytics summary</td><td>On</td><td>Channel, UTM source/medium/campaign, landing page, conversion page, device,
        pages viewed, session start.</td></tr>
        <tr><td>Visitor journey</td><td><strong>Off</strong></td><td>The pages this visitor viewed — browsing history for
        an identifiable person.</td></tr>
        <tr><td>IP address</td><td><strong>Off</strong></td><td>Personal data in the EU/UK; only available when IP storage
        is on in Settings.</td></tr></tbody></table>
        <p>Subject tokens are a fixed allowlist, substituted literally — there is no expression language and no PHP evaluation:
        <code>{site_name}</code>, <code>{form_name}</code>, <code>{provider}</code>, <code>{channel}</code>, <code>{submission_id}</code>,
        <code>{form_id}</code>, <code>{campaign}</code>, <code>{date}</code>. Anything else stays literal text, and CR/LF and NUL are
        stripped <em>after</em> substitution, so a form named <code>Contact\r\nBcc: …</code> cannot inject a mail header.</p>
        <?php
        self::cardEnd();

        self::cardStart('What is never emailed, and how sending works');
        ?>
        <p>Fields whose ID <strong>or</strong> label looks credential-bearing — passwords, tokens, API keys, secrets, authorization
        values — are <strong>omitted entirely</strong>, even with <em>Submitted fields</em> on. They are not shown as <code>[REDACTED]</code>:
        a placeholder would tell every recipient that a secret exists. This is the same policy as Activity Log redaction, so <code>convermetry_sensitive_keys</code>
        extends both at once. Convermetry's <code>cvm_*</code> fields never appear either.</p>
        <ul class="cvm-about-features">
        <li>Notifications are <strong>queued, never sent during the visitor's request</strong>. No <code>wp_mail()</code>,
        payload build, or analytics query happens while they wait.</li>
        <li>One queue row per <strong>(submission, recipient)</strong>, unique — a double-fired submission cannot produce
        two emails to one address, and one failing address does not re-mail the others.</li>
        <li>The queue stores a recipient, a settings snapshot, and scheduling state — <strong>never the rendered email or
        the lead's answers</strong>. The submission is fetched fresh at send time, which is what makes deletion effective.</li>
        <li>Settings are <strong>snapshotted when the lead arrives</strong>. Turning the master switch off stops new notifications
        but does not pause queued ones — there is an explicit <strong>Discard queued notifications</strong> button for that.</li>
        <li>Retries are bounded and short: 5 min, 15 min, 1 h, then the row is abandoned and a wp-admin warning appears. Every
        row also carries a hard <strong>two-hour time-to-live</strong>, so a notification that could not be sent inside it is dropped
        rather than delivered days late as though it just arrived.</li>
        <li>Only a short failure reason is retained. The rendered body and the submitted values are never logged, and notification
        sends do not appear in the Activity Log.</li></ul>
        <p><strong>Send test email</strong> builds its message entirely from fabricated data — a <code>Convermetry Test Form</code>,
        <code>test@example.com</code>, and the RFC 5737 documentation address <code>203.0.113.42</code>. It never loads a real submission,
        so testing cannot expose a lead.</p>
        <?php
        self::cardEnd();

        self::sectionEnd();
    }

    /**
     * The public developer API: custom form submissions, helpers, browser API.
     *
     * @return void
     */
    private static function renderDeveloper(): void
    {
        self::sectionStart('developer');

        self::cardStart('Custom form integration — two entry points');
        ?>
        <p>Any form Convermetry has no bundled provider for — a hand-rolled <code>&lt;form&gt;</code>, a headless front end,
        a booking widget, a server-to-server lead post — goes through one of two public entry points. Both run the <strong>same pipeline</strong>;
        they differ only in who handles a failed delivery.</p>
        <table class="cvm-about-table"><thead><tr><th scope="col"></th><th scope="col"><code>convermetry_form_submission</code></th><th scope="col"><code>convermetry_submit_form()</code></th></tr></thead><tbody>
        <tr><td>Semantics</td><td>Fire-and-forget</td><td>Result-aware</td></tr>
        <tr><td>Delivery</td><td>Queued, sent by the background worker</td><td><strong>Synchronous</strong>, inside your request</td></tr>
        <tr><td>Retries</td><td>Automatic — the full webhook retry chain</td><td><strong>None</strong> — failures are handed
        back to you</td></tr>
        <tr><td>Returns</td><td>Nothing</td><td>A readonly <code>SubmissionResult</code></td></tr>
        <tr><td>Use when</td><td>A visitor is waiting, and reliability matters more than immediacy</td><td>You must know the
        outcome before responding</td></tr></tbody></table>
        <p><strong>Prefer the action.</strong> Synchronous delivery puts every configured endpoint's latency inside your request,
        and a failed synchronous delivery is <em>yours</em> to retry — Convermetry deliberately does not queue it behind your back,
        because that would deliver the same lead twice to a caller that already retried.</p>
        <?php
        self::code("do_action('convermetry_form_submission',
    ['form_name' => 'Booking Widget', 'form_id' => 'booking-1'],
    [
        ['id' => 'email',     'label' => 'Email address',         'value' => \$email],
        ['id' => 'interests', 'label' => 'Services of interest',  'value' => ['Tax planning', 'Retirement']],
    ],
    ['url_query' => ['channel' => 'widget'], 'headers' => ['X-Source' => 'booking']] // optional
);");
        self::code("\$result = convermetry_submit_form(
    ['form_name' => 'Booking Widget', 'form_id' => 'booking-1'],
    \$fields,
    \$url_query,        // optional, this call only
    \$request_headers   // optional, this call only
);

if (!\$result->ok) {
    // \$result->msg              — user-facing description
    // \$result->status           — last HTTP status (0 for early exits / transport errors)
    // \$result->failedDeliveries — the exact requests that failed, for your own retry
}
// \$result->submissionId / \$result->conversionId — the recorded identifiers");
        self::cardEnd();

        self::cardStart('The form identifier');
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col">Key</th><th scope="col">Required</th><th scope="col">Meaning</th></tr></thead><tbody>
        <tr><td><code>form_name</code></td><td><strong>Yes</strong></td><td>The human name of the form. Travels as <code>form_submission.form_name</code>,
        titles notification emails, and labels the Submissions list. An empty <code>form_name</code> is rejected outright.</td></tr>
        <tr><td><code>form_id</code></td><td>No</td><td>Your own stable identifier. Travels as <code>native_form_id</code>,
        and as <code>form_id</code> unless a Custom/External Form ID is set for it on the Forms page.</td></tr></tbody></table>
        <p>Per-form settings — exclusion, URL parameters, headers, notification rules — key these submissions as <code>custom:&lt;form_id&gt;</code>
        when you pass a <code>form_id</code>, and <code>custom:&lt;form_name&gt;</code> when you do not. Passing a stable <code>form_id</code>
        is therefore what lets you rename the form later without resetting its configuration.</p>
        <?php
        self::cardEnd();

        self::cardStart('Submission fields — id, label, value');
        ?>
        <p><strong>Two shapes are accepted, and both are fully supported.</strong> The richer descriptor list is preferred;
        the historical map is not deprecated.</p>
        <p class="cvm-about-subheading">(a) Descriptor list — preferred</p>
        <p>An ordered list of <code>{id, label, value}</code> arrays, matching the <a href="#payloads"><code>submission_data</code>
        schema 2.0</a> wire format one-for-one:</p>
        <?php
        self::code("convermetry_submit_form(
    ['form_name' => 'Booking Widget', 'form_id' => 'booking-1'],
    [
        ['id' => 'email',     'label' => 'Email address',        'value' => \$email],
        ['id' => 'phone',     'label' => 'Phone',                'value' => \$phone],
        ['id' => 'interests', 'label' => 'Services of interest', 'value' => ['Tax planning', 'Retirement']],
    ]
);");
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col">Key</th><th scope="col">Required</th><th scope="col">Rules</th></tr></thead><tbody>
        <tr><td><code>id</code></td><td><strong>Yes</strong></td><td>The field's stable, machine-readable identifier — what
        a receiver should match on. Passed through <code>sanitize_text_field()</code>. An entry whose <code>id</code> is empty after
        sanitizing is <strong>dropped</strong>, as is any <code>id</code> beginning with <code>cvm_</code> in any letter case.</td></tr>
        <tr><td><code>label</code></td><td>No</td><td>The human-readable label — what a person reads in the Submissions panel,
        a CSV export, or a notification email. Sanitized the same way, and <strong>falls back to <code>id</code></strong> when missing,
        blank, or not a scalar.</td></tr>
        <tr><td><code>value</code></td><td>No</td><td>A scalar (cast to string) or a <strong>list of scalars</strong>, each
        sanitized. Arrays are reindexed with <code>array_values()</code>, so a multi-select's own keys are not part of the contract.
        Anything non-scalar — an object, a nested array — becomes an empty string rather than nested data. A missing <code>value</code>
        is an empty string.</td></tr></tbody></table>
        <p class="cvm-about-subheading">(b) name =&gt; value map — the long-standing shape</p>
        <p>Every key becomes both the field's <code>id</code> <strong>and</strong> its <code>label</code>:</p>
        <?php
        self::code("convermetry_submit_form(
    ['form_name' => 'Booking Widget'],
    ['name' => \$name, 'email' => \$email, 'interests' => ['Tax planning', 'Retirement']]
);

// …is recorded and delivered as:
[
    { \"id\": \"name\",      \"label\": \"name\",      \"value\": \"Ada Lovelace\" },
    { \"id\": \"email\",     \"label\": \"email\",     \"value\": \"ada@example.com\" },
    { \"id\": \"interests\", \"label\": \"interests\", \"value\": [\"Tax planning\", \"Retirement\"] }
]");
        ?>
        <p>That is the only difference between the two shapes: the map cannot express a label distinct from the id. Use it
        when you have no separate label to give; reach for the descriptor list the moment you do.</p>
        <div class="cvm-about-note"><strong>Shape detection is strict, and deliberately so.</strong> An array is treated as
        a descriptor list only when it is list-keyed <em>and every entry</em> is an array carrying a scalar <code>id</code>. One entry
        that fails sends the whole array down the map path, where nothing is lost — a permissive test that sniffed only the first
        entry would misread a map whose values happen to be arrays with an <code>id</code> key and silently discard your data.</div>
        <p class="cvm-about-subheading">Rules that apply to both shapes</p>
        <ul class="cvm-about-features">
        <li><strong><code>cvm_*</code> keys are always stripped</strong>, from either shape, in any letter case. Convermetry's
        correlation fields never reach storage, payloads, exports, emails, or the Activity Log.</li>
        <li><strong>Duplicate labels are preserved as separate fields.</strong> Nothing keys or deduplicates by label; two
        fields both labelled "Name" stay two fields. This is the whole reason the wire format is a list.</li>
        <li><strong>Order is preserved</strong> exactly as you passed it.</li>
        <li><strong>An empty field list is valid</strong> — it records a submission with an empty schema 2.0 list, not a
        legacy-shaped payload.</li>
        <li>Values are <strong>sanitized, never validated</strong>. Convermetry is not your form's validator; it records what
        the form accepted.</li></ul>
        <?php
        self::cardEnd();

        self::cardStart('Runtime parameters and the result object');
        ?>
        <p>Both entry points accept extra query parameters and headers for <strong>this submission only</strong>. They are
        scalar maps (non-scalar values are dropped) and sit at the end of the merge precedence chain, so they win over everything configured
        in wp-admin. The action takes them as one <code>$context</code> array; the function takes them as two arguments.</p>
        <p><code>convermetry_submit_form()</code> returns a readonly <code>Convermetry\Forms\SubmissionResult</code>:</p>
        <table class="cvm-about-table"><thead><tr><th scope="col">Property</th><th scope="col">Type</th><th scope="col">Meaning</th></tr></thead><tbody>
        <tr><td><code>ok</code></td><td>bool</td><td>True when the submission was recorded <strong>and</strong> every attempted
        delivery succeeded (or was queued).</td></tr>
        <tr><td><code>submissionId</code></td><td>string</td><td>The globally unique submission id; empty when nothing was
        recorded.</td></tr>
        <tr><td><code>conversionId</code></td><td>string</td><td>The conversion id shared with analytics; empty when nothing
        was recorded.</td></tr>
        <tr><td><code>status</code></td><td>int</td><td>HTTP status of the <strong>last</strong> synchronous delivery. <code>0</code>
        for early exits, transport errors, and queued deliveries.</td></tr>
        <tr><td><code>msg</code></td><td>string</td><td>User-facing failure description; empty on success.</td></tr>
        <tr><td><code>data</code></td><td>mixed</td><td>The last endpoint's response body — JSON-decoded when valid JSON,
        raw string otherwise; <code>null</code> for early exits and background deliveries. Diagnostic, not for public display.</td></tr>
        <tr><td><code>queued</code></td><td>bool</td><td>True when deliveries were queued rather than sent inline.</td></tr>
        <tr><td><code>failedDeliveries</code></td><td>array</td><td>One entry per endpoint whose <strong>synchronous</strong>
        dispatch failed — <code>url</code>, <code>endpoint_url</code>, <code>headers</code>, <code>body</code>, <code>label</code>:
        the exact request that was sent, so you can implement your own retry. Always empty for early exits and queued deliveries, whose
        retries Convermetry owns.</td></tr></tbody></table>
        <p><code>ok === false</code> covers three genuinely different situations, distinguishable by the other fields: nothing
        was recorded (<code>submissionId</code> empty — a missing <code>form_name</code>, an excluded form, an insert failure), or
        it was recorded and some delivery failed (<code>failedDeliveries</code> non-empty), or it was recorded with nothing to deliver.</p>
        <?php
        self::cardEnd();

        self::cardStart('What both paths do');
        ?>
        <ol class="cvm-about-list">
        <li><strong>Per-form settings are honored.</strong> An excluded <code>custom:…</code> form records nothing and reports
        why.</li>
        <li><strong>Correlation fields are read from the current request</strong>, so a submission posted from a page the tracker
        ran on carries the visitor's real session, channel, campaign, entrance referrer, and landing page. When they are absent, a
        conversion id is generated server-side and the submission still records and delivers, with an empty <code>analytics_context</code>.</li>
        <li><strong>A <code>form_success</code> analytics event is recorded</strong> under the same conversion token, so the
        dashboard's conversion count includes it exactly once.</li>
        <li><strong>The submission row is written</strong>, with the denormalized channel, campaign and landing-page columns
        the Submissions filters and lead reports use, and the submitter's IP when IP storage is on.</li>
        <li><strong><code>convermetry_submission_recorded</code> fires</strong> — notifications queue here, and listeners
        run even with no webhook endpoints configured.</li>
        <li><strong>Delivery</strong>: queued for the background worker (action), or dispatched synchronously to every form
        endpoint (function).</li></ol>
        <p>Duplicate protection is the same as for bundled providers: a repeated <code>cvm_conversion_id</code> hits the <code>UNIQUE
        conversion_id</code> index, and the second call reports success <strong>without recording or delivering anything twice</strong>.</p>
        <?php
        self::cardEnd();

        self::cardStart('Extending Convermetry — the extensions buckets');
        ?>
        <p>Five surfaces accept <strong>namespaced extension data</strong> from other plugins. Each is a filter that starts
        empty, and <strong>nothing appears until something fills it</strong> — with no callbacks registered, no <code>extensions</code>
        property exists anywhere.</p>
        <table class="cvm-about-table"><thead><tr><th scope="col">Surface</th><th scope="col">Filter</th><th scope="col">Budget</th></tr></thead><tbody>
        <tr><td>Outbound webhook payloads</td><td><code>convermetry_webhook_payload_extensions</code></td><td>32 KB · 50 keys</td></tr>
        <tr><td>Analytics summaries (dashboard + payload)</td><td><code>convermetry_analytics_extensions</code></td><td>32
        KB · 50 keys</td></tr>
        <tr><td>A submission's stored analytics context</td><td><code>convermetry_submission_context_extensions</code></td><td>8
        KB · 20 keys</td></tr>
        <tr><td><code>window.ConvermetryConfig</code></td><td><code>convermetry_tracker_config_extensions</code></td><td>8
        KB · 20 keys</td></tr>
        <tr><td>One delivery-log REST item</td><td><code>convermetry_delivery_log_api_item</code></td><td>4 KB · 10 keys</td></tr></tbody></table>
        <ul class="cvm-about-features">
        <li><strong>Keys must be namespaced</strong> as <code>vendor/thing</code>, so two plugins writing to the same payload
        cannot collide.</li>
        <li>Values must be <strong>JSON primitives</strong> — no objects, no resources, bounded depth. Anything over budget
        is dropped rather than truncated into invalid data.</li>
        <li><strong>Core keys are never replaceable.</strong> A filter cannot rewrite a conversion id, a session id, attribution,
        timestamps, form identity, or a REST item's <code>success</code> flag — a plugin that could would be able to lie to a monitoring
        dashboard.</li>
        <li>The tracker bucket is inlined into <strong>every page view and is public</strong>. Never put a key, a token, or
        anything visitor-specific there.</li></ul>
        <p class="cvm-about-subheading">A dashboard panel that also travels on the wire</p>
        <p>Register an <code>AnalyticsSectionInterface</code> adapter and it contributes both a panel on the Analytics screen
        and an entry in <code>analytics.extensions</code> — from one implementation, so the screen and the payload cannot disagree.</p>
        <?php
        self::code("add_filter('convermetry_analytics_sections', function (array \$sections): array {
    \$sections[] = new Acme_Subscriptions_Section(); // getKey() returns 'acme/subscriptions'
    return \$sections;
});

interface AnalyticsSectionInterface {
    public function getKey(): string;                                   // 'vendor/thing'
    public function getLabel(): string;
    public function getDescription(): string;
    public function summarize(string \$start, string \$end, int \$limit): array;
    public function render(array \$summary): void;                      // escape your own output
}");
        ?>
        <p>It is a <strong>typed registry, never SQL</strong>: there is deliberately no way to pass a query fragment or a table
        name to a path that runs unattended on cron. A section that throws is dropped and reported through <code>convermetry_analytics_report_failed</code>
        rather than taking the report down with it.</p>
        <p class="cvm-about-subheading">Admin surfaces</p>
        <p>Actions exist to render extra panels on the dashboard (<code>convermetry_analytics_admin_panels</code>), extra blocks
        and buttons on a submission (<code>convermetry_submission_detail_sections</code>, <code>convermetry_submission_row_actions</code>),
        extra content on the Forms screen (<code>convermetry_forms_admin_sections</code>), and filters to add list columns and CSV
        columns (<code>convermetry_submissions_columns</code>, <code>convermetry_submission_csv_columns</code> / <code>_values</code>).
        They run after this screen's capability check — but <strong>your callback must escape its own output</strong>, and CSV values
        go through the same formula-injection escaping as core ones.</p>
        <?php
        self::cardEnd();

        self::cardStart('Helper functions and the browser API');
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col">Call</th><th scope="col">Purpose</th></tr></thead><tbody>
        <tr><td><code>convermetry_submit_form()</code></td><td>Result-aware, synchronous custom-form submission — see above.</td></tr>
        <tr><td><code>cvm_track_event($type, $data)</code></td><td>Records a custom server-side analytics event. <code>$type</code>
        is at most 20 characters of lowercase letters, digits, dashes and underscores; recognized <code>$data</code> keys are the event
        row's own columns and unknown keys are ignored. A <code>form_success</code> event <strong>requires</strong> <code>event_value</code>
        to be a unique conversion id (8–100 chars of <code>A-Za-z0-9_.:-</code>), so conversion dedup stays consistent.</td></tr>
        <tr><td><code>Convermetry.track(name, { value })</code></td><td>Reports a named custom event that <a href="#conversions">goals</a>
        can match. Only the name — and a numeric <code>value</code> where the matching goal accepts one — is transmitted; an event
        matching no configured goal is discarded and never stored.</td></tr>
        <tr><td><code>convermetry:conversion</code> DOM event</td><td>The pre-existing custom frontend conversion event, unchanged.
        Pass a <code>conversion_id</code> in its detail to correlate it with a server-side record.</td></tr></tbody></table>
        <?php
        self::code("cvm_track_event('purchase', ['page_url' => \$url, 'event_value' => '99.00']);

Convermetry.track('appointment_booked');
Convermetry.track('appointment_booked', { value: 250 });

document.dispatchEvent(new CustomEvent('convermetry:conversion', {
    detail: { name: 'appointment_booked' }
}));");
        self::cardEnd();

        self::sectionEnd();
    }

    /**
     * The complete action and filter reference — every public hook, grouped by
     * the surface it touches.
     *
     * The rows come from {@see HOOKS}, which is the page's own copy of the
     * catalogue; each entry carries the hook's signature so a reader never has
     * to guess an argument list.
     *
     * @return void
     */
    private static function renderHooks(): void
    {
        self::sectionStart('hooks');

        /* Every detail panel below is rendered collapsed, which depends on
         * about.js to open it. With scripting off the toggle is useless, so the
         * panels are simply shown instead — the page gets long, but nothing
         * becomes unreachable. */
        ?>
        <noscript><style>
            .cvm-about-hook-toggle { display: none; }
            .cvm-about-hook-detail[hidden] { display: block; }
        </style></noscript>
        <?php

        self::cardStart('How the hook API behaves');
        ?>
        <p>Convermetry exposes a public hook API for plugins and code snippets — <strong><?php echo count(self::HOOKS); ?>
        hooks</strong> in all. Two rules hold across every one of them.</p>
        <p>Each entry below lists its name, type, purpose and signature. <strong>Learn More</strong> expands what every
        argument actually holds — including the keys of the array ones — plus a runnable example you can paste into an
        mu-plugin.</p>
        <ul class="cvm-about-features">
        <li><strong>Nothing registered means nothing changes.</strong> With no callbacks, payload bytes, request URLs and headers,
        delivery ids, signatures, retry schedules, analytics results, admin HTML, REST output, CSV files, and tracker configuration
        are all exactly what they were. No <code>extensions</code> property appears anywhere until something fills it.</li>
        <li><strong>Filters that customize data may see that data; observers may not.</strong> A filter whose job is to change
        an email body necessarily sees the email body. The observational actions deliberately carry ids, counts, and outcomes — never
        submitted fields, rendered emails, request or response bodies, signing secrets, credential-bearing URLs, or raw IP addresses.
        Where an argument does carry personal data, its entry says so.</li></ul>
        <p class="cvm-about-subheading">Three kinds of hook</p>
        <ul class="cvm-about-features">
        <li><strong>Decision filters</strong> (<code>convermetry_should_*</code>) answer one yes/no question. The data is passed
        for inspection and nothing you return from them changes it — a gate that could also rewrite a dedupe key or a completion
        id would be able to silently defeat the guarantees built on them.</li>
        <li><strong>Composition filters</strong> shape data on its way out: payloads, URLs, headers, fields, recipients, columns.
        They run <strong>once per logical delivery, before the request is frozen</strong> — a retry re-sends frozen bytes and re-runs
        none of them, so a callback added mid-chain cannot reach a delivery already in flight.</li>
        <li><strong>Observational actions</strong> report what happened, after it is durably true. They never fire speculatively:
        a retry action fires once the next attempt is persisted, a success action once the bookkeeping committed.</li></ul>
        <div class="cvm-about-note"><strong>Where to register them.</strong> A theme's <code>functions.php</code> loads after
        <code>plugins_loaded</code>, which is late for the ingestion path. Put anything that must be in place for <em>every</em> request
        — <code>convermetry_client_ip</code>, <code>convermetry_stored_ip</code>, <code>convermetry_allowed_hosts</code>, <code>convermetry_rate_limits</code>,
        <code>convermetry_tracked_event</code>, <code>convermetry_should_track_event</code> — in an <strong>mu-plugin</strong>, or
        in a plugin file that registers at load time. Three filters are <strong>memoized per request</strong> and run only on their
        first use: <code>convermetry_client_ip</code>, <code>convermetry_allowed_hosts</code> and <code>convermetry_form_providers</code>;
        registering those later has no effect for the rest of the request. And <strong>do not throw from a callback</strong> — several
        run while a lease is held or immediately before a network request, where an exception costs the work the hook was announcing.</div>
        <?php
        self::cardEnd();

        $grouped = [];
        foreach (self::HOOKS as $hook) {
            $grouped[$hook[3]][] = $hook;
        }

        foreach (self::HOOK_GROUPS as $group => $blurb) {
            $hooks = $grouped[$group] ?? [];

            self::cardStart($group . ' — ' . count($hooks) . ' hooks');

            // Every group in HOOK_GROUPS carries a blurb; the emptiness guard
            // that used to be here could never be false.
            ?>
            <p><?php echo wp_kses_post($blurb); ?></p>
            <?php

            foreach ($hooks as [$name, $type, $signature, , $summary]) {
                self::hookStart($name, $type, $signature, $summary);
                self::hookEnd();
            }

            self::cardEnd();
        }

        self::cardStart('Worked examples');
        ?>
        <p class="cvm-about-subheading">Submit a custom form</p>
        <p>Fire-and-forget, with background delivery and automatic retries. <code>$fields</code> takes either a list of <code>['id',
        'label', 'value']</code> descriptors or the historical <code>name =&gt; value</code> map — see <a href="#developer">Developer
        API</a>.</p>
        <?php
        self::code("do_action('convermetry_form_submission',
    ['form_name' => 'Booking Widget', 'form_id' => 'booking-1'],
    [
        ['id' => 'email',     'label' => 'Email address',        'value' => \$email],
        ['id' => 'interests', 'label' => 'Services of interest', 'value' => ['Tax planning', 'Retirement']],
    ],
    ['url_query' => ['channel' => 'widget'], 'headers' => ['X-Source' => 'booking']] // optional
);");

        ?>
        <p class="cvm-about-subheading">Add data to every outbound webhook payload</p>
        <p>Runs before the payload is frozen, so retries re-send it unchanged. Keys must be namespaced <code>vendor/thing</code>,
        and an empty result adds no property at all.</p>
        <?php
        self::code("add_filter('convermetry_webhook_payload_extensions', function (array \$extensions, string \$messageType, array \$meta): array {
    if (\$messageType === 'form_submission') {
        \$extensions['acme/crm'] = ['tenant' => get_option('acme_tenant_id'), 'source' => 'wordpress'];
    }

    return \$extensions;
}, 10, 3);");

        ?>
        <p class="cvm-about-subheading">Add a header to one endpoint only</p>
        <p>The context identifies the endpoint without exposing its URL. A callback may not touch the protocol headers —
        <code>Content-Type</code>, <code>Host</code>, <code>Content-Length</code>, <code>Transfer-Encoding</code>, <code>Connection</code>,
        <code>User-Agent</code>, <code>Idempotency-Key</code>, <code>X-Convermetry-Signature</code> — which are restored to their
        pre-filter state.</p>
        <?php
        self::code("add_filter('convermetry_webhook_headers', function (array \$headers, array \$context): array {
    if (\$context['endpoint_origin'] === 'https://hooks.acme.test') {
        \$headers['X-Acme-Tenant'] = get_option('acme_tenant_id');
    }

    return \$headers;
}, 10, 2);");

        ?>
        <p class="cvm-about-subheading">Skip recording a submission</p>
        <p>Runs after normalization, so spam rules can read the fields, and before <strong>any</strong> write — the conversion
        event, the row, the queue, and the notifications are all skipped. The visitor still sees success: returning a failure would
        make Elementor's synchronous mode reject a valid form.</p>
        <?php
        self::code("add_filter('convermetry_should_record_submission', function (bool \$should, string \$formKey, string \$provider, array \$fields): bool {
    foreach (\$fields as \$field) {
        if (\$field['id'] === 'email' && str_ends_with((string) \$field['value'], '\@internal.example')) {
            return false; // Staff testing the form — not a lead.
        }
    }

    return \$should;
}, 10, 4);");

        ?>
        <p class="cvm-about-subheading">Pseudonymize the stored IP address</p>
        <p><code>convermetry_stored_ip</code> runs after the privacy gates, on the address about to be persisted. It deliberately
        does not affect the rate-limit identity, which would collapse every visitor into one bucket.</p>
        <?php
        self::code("add_filter('convermetry_stored_ip', function (string \$ip): string {
    // Keep the network, drop the host: still useful for spam review, no longer an identifier.
    return filter_var(\$ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ? preg_replace('/\\\\.\\\\d+\$/', '.0', \$ip)
        : '';
});");

        ?>
        <p class="cvm-about-subheading">Observe deliveries, and react to a lead outcome</p>
        <p>Note which action means what: an exhausted <em>analytics</em> chain is resumable, an abandoned <em>form</em> delivery
        is not. Lead values are exact decimal strings, never floats.</p>
        <?php
        self::code("add_action('convermetry_webhook_delivery_abandoned', function (array \$context, string \$reason): void {
    // Terminal: this submission will never reach this endpoint.
    acme_alert(\"Gave up delivering {\$context['submission_id']} to {\$context['endpoint_label']} (\$reason)\");
}, 10, 2);

add_action('convermetry_lead_updated', function (string \$submissionId, array \$to, array \$from, int \$userId, string \$leadEventId): void {
    if (\$to['status'] === 'won' && \$from['status'] !== 'won') {
        acme_crm_close_deal(\$submissionId, \$to['value'], \$to['currency'], \$leadEventId);
    }
}, 10, 5);");

        ?>
        <p class="cvm-about-subheading">Scope an admin screen to a narrower capability</p>
        <p>Applied to menu visibility <strong>and</strong> every handler behind it. Grant deliberately: <code>submissions.export</code>
        is every lead's name and email in one file.</p>
        <?php
        self::code("add_filter('convermetry_admin_capability', function (string \$capability, string \$scope): string {
    return \$scope === 'analytics.view' ? 'edit_posts' : \$capability;
}, 10, 2);");
        self::cardEnd();

        self::sectionEnd();
    }


    /**
     * The REST surface.
     *
     * @return void
     */
    private static function renderRest(): void
    {
        self::sectionStart('rest');

        self::cardStart('POST /wp-json/convermetry/v1/track');
        ?>
        <p><strong>Public.</strong> The tracker's ingestion endpoint: idempotent batches, per-IP and site-wide rate limits,
        same-host URL validation, Origin/Referer protection, bot filtering, and DNT/GPC enforcement. It answers <code>202</code> with
        <code>{"stored": n}</code>, <code>400</code>/<code>403</code>/<code>413</code>/<code>429</code> (with <code>Retry-After</code>)
        on rejection, and <code>503</code> when storage failed — in which case the tracker keeps the batch and retries.</p>
        <p>It accepts events from this site's own tracker into the local database only. It never forwards anything to a webhook
        receiver; everything a receiver gets is produced later by the two outbound paths.</p>
        <?php
        self::cardEnd();

        self::cardStart('GET /wp-json/convermetry/v1/deliveries');
        ?>
        <p><strong>API-key authenticated, read-only, and off by default</strong> — enable it and manage its key on the Activity
        Log page.</p>
        <?php
        self::code('GET /wp-json/convermetry/v1/deliveries?page=1&per_page=25&status=error&message_type=form_submission
Authorization: <api-key>');
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col">Parameter</th><th scope="col">Values</th></tr></thead><tbody>
        <tr><td><code>page</code> / <code>per_page</code></td><td>Pagination; <code>per_page</code> max 100.</td></tr>
        <tr><td><code>status</code></td><td><code>success</code> | <code>error</code></td></tr>
        <tr><td><code>message_type</code></td><td><code>analytics_report</code> | <code>form_submission</code></td></tr>
        <tr><td><code>endpoint</code></td><td>An endpoint <strong>label</strong>, or the <code>endpoint_key</code> echoed in
        responses.</td></tr>
        <tr><td><code>provider</code></td><td>Form provider key.</td></tr>
        <tr><td><code>form_id</code></td><td>Exact form name.</td></tr>
        <tr><td><code>after</code></td><td><code>YYYY-MM</code> or <code>YYYY-MM-DD</code>.</td></tr></tbody></table>
        <p>Pagination metadata returns in <code>X-WP-Total</code>, <code>X-WP-TotalPages</code> and <code>X-CVM-Page</code>
        headers. Only a SHA-256 hash of the key is stored — the raw key is shown <strong>once</strong> at generation, and regenerating
        invalidates the old key immediately. Wrong keys get <code>401</code>, throttled per IP after repeated failures; a disabled
        API answers <code>403</code>.</p>
        <div class="cvm-about-note">In responses, <code>endpoint_url</code> is <strong>redacted to scheme + host</strong> —
        webhook URLs frequently embed bearer tokens, and this read-only key must never hand out downstream write credentials. Identify
        endpoints by <code>endpoint_label</code> or <code>endpoint_key</code>; full URLs stay visible to admins in wp-admin. Intended
        for <strong>server-to-server</strong> use — never embed the key in public frontend JavaScript.</div>
        <?php
        self::cardEnd();

        self::sectionEnd();
    }

    /**
     * Privacy posture, storage, and lifecycle.
     *
     * @return void
     */
    private static function renderPrivacy(): void
    {
        self::sectionStart('privacy');

        self::cardStart('Privacy posture');
        ?>
        <ul class="cvm-about-features">
        <li><strong>Email notifications are opt-in and leave your retention window.</strong> When enabled, each notification
        is a copy of lead data in a mailbox Convermetry does not control. Deleting a submission cancels anything still queued, but
        <strong>cannot recall a message already sent</strong>.</li>
        <li><strong>No cookies.</strong> The session id lives in <code>localStorage</code> and rotates after 30 minutes of
        inactivity.</li>
        <li>Tracked URLs are canonicalized to scheme + host + path — <strong>query strings never reach the database</strong>.
        Referrers and click/form destinations are likewise stripped; whole <code>mailto:</code>/<code>tel:</code> destinations are
        kept, because for those links the address <em>is</em> the destination.</li>
        <li>Campaign values are stored after sanitization, except values containing <code>@</code>, which are dropped as likely
        email addresses — never put personal data in UTM parameters. Ad-click identifiers store only the parameter <strong>name</strong>;
        the value never leaves the browser.</li>
        <li><strong>Visitor IP addresses are stored by default</strong>, on both write paths: every analytics event and every
        server-confirmed form submission. Turn it off with <strong>Settings → Privacy → IP addresses</strong>; new rows then record
        an empty value while existing rows are untouched and age out with retention. User agents are never stored on either path.</li>
        <li><strong>In the EU/UK an IP address is personal data.</strong> Retaining it for general visitor activity — not
        only for leads someone actively submitted — normally has to be disclosed in your privacy policy and rest on a lawful basis.
        Consider whether your consent tooling should gate the tracker.</li>
        <li>To <strong>anonymize rather than disable</strong>, use <a href="#hook-convermetry_stored_ip"><code>convermetry_stored_ip</code></a>
        — it runs after the privacy gates on the address about to be persisted, covers both write paths at once, and deliberately
        does not touch the rate-limit identity, which would collapse every visitor into one bucket. Behind a proxy or CDN, map the
        real address with <a href="#hook-convermetry_client_ip"><code>convermetry_client_ip</code></a> instead.</li>
        <li>When the site honors <strong>Do Not Track / Global Privacy Control</strong> and a visitor sends one, <strong>no
        IP is stored on either path</strong>: their analytics events are not recorded at all, and a form they submit is still recorded
        and delivered — they actively submitted it — but carries an empty <code>ip_address</code>. Both paths go through one gate,
        so the setting, the signal, and this documentation cannot drift apart. DNT/GPC is an opt-out signal, not a consent mechanism.</li>
        <li><strong>No external geolocation service is ever contacted.</strong> A form submission never waits on any third
        party, and a stored IP is never sent anywhere except your own webhook endpoints.</li>
        <li>Logged-in users are excluded from tracking by default.</li>
        <li><strong>Form abandonment records no field values, ever</strong> — only a field id, a field type, and which validity
        flag failed. <strong>Custom event payloads are not storage</strong> — only the event name, and a numeric value where a goal
        accepts one. <strong>Goal and funnel records carry no PII</strong> — only normalized URLs, the attribution snapshot, and
        a device bucket. <strong>Lead status and value stay on the submission record</strong> and its history table.</li>
        <li><strong>Goal definitions are not published to visitors.</strong> The one exception is a CSS selector goal, whose
        selector must reach the browser to be evaluated; the ids reported back are re-validated server-side before anything is recorded.</li>
        <li>The Activity Log stores a <strong>redacted</strong> copy of each delivery's request payload. A form payload's copy
        is replaced when <em>Store form submission data in the Activity Log</em> is off; an analytics report's <code>conversions.recent[].ip_address</code>
        is logged regardless. Log rows age out with the same retention window, and <a href="#hook-convermetry_delivery_log_row"><code>convermetry_delivery_log_row</code></a>
        can redact anything further.</li>
        <li>Everything is deleted after the configurable retention window (7–365 days, default 90) by bounded, chunked cleanup
        jobs.</li></ul>
        <?php
        self::cardEnd();

        self::cardStart('What is stored, and where');
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col">Table</th><th scope="col">Purpose</th></tr></thead><tbody>
        <tr><td><code>cvm_events</code></td><td>One row per visitor interaction — the analytics engine. A unique (batch id,
        sequence) makes tracker replays idempotent.</td></tr>
        <tr><td><code>cvm_form_submissions</code></td><td>One row per server-confirmed submission: identifiers, form identity,
        page URL and query, IP, sanitized <code>submission_data</code>, the frozen analytics context, the indexed campaign/channel/landing-page
        columns, the lead outcome columns, and the recorded delivery state.</td></tr>
        <tr><td><code>cvm_delivery_queue</code></td><td>The background form-delivery queue: one row per submission &times;
        endpoint, holding the frozen URL, headers and body. Deleted on acknowledgment or abandonment.</td></tr>
        <tr><td><code>cvm_notification_queue</code></td><td>The email queue: one row per submission &times; recipient, carrying
        <strong>no lead data</strong> — the submission is read at send time.</td></tr>
        <tr><td><code>cvm_webhook_deliveries</code></td><td>The Activity Log: one row per delivery attempt with redacted headers
        and bodies, capped at 64 KB each.</td></tr>
        <tr><td><code>cvm_goal_completions</code></td><td>One row per goal completion. <code>dedupe_key</code> carries a UNIQUE
        index and is the entire deduplication mechanism.</td></tr>
        <tr><td><code>cvm_lead_events</code></td><td>Lead status-change history: one row per transition, cascaded away when
        the submission is deleted.</td></tr></tbody></table>
        <p><strong>Schema migrations never run inside a visitor's request.</strong> Adding an index is a table rebuild on every
        engine, so migrations run only in WP-Cron, WP-CLI, or a genuine admin page view, one at a time under a lease. While one is
        outstanding the Goals and Funnels screens say so plainly rather than querying a column that does not exist yet.</p>
        <p><strong>Deactivation preserves everything:</strong> tables and data are kept, analytics retry chains are suspended
        and resume under their original delivery ids, and queued form deliveries wait for the re-armed worker. <strong>Deleting the
        plugin</strong> drops all seven tables and deletes every option, transient, rate-limit counter row, and scheduled cron event
        — per site across a whole multisite network. No trace remains.</p>
        <?php
        self::cardEnd();

        self::cardStart('Watching the unattended work');
        ?>
        <p>Retention passes, schema migrations, and queue workers all run without anyone looking. Four observational actions
        report what they did, so a monitoring integration does not have to infer it from row counts.</p>
        <ul class="cvm-about-features">
        <li><code>convermetry_retention_cleanup_started</code> / <code>_completed</code> — one store begins and finishes
        deleting past the cutoff. The completion carries how many rows went, whether more remain, and an outcome of <code>completed</code>,
        <code>truncated</code>, <code>query_failed</code>, or <code>lock_lost</code>. Observational only: a listener cannot cancel
        a pass, change the cutoff, or extend retention, and Convermetry schedules any follow-up pass itself.</li>
        <li><code>convermetry_migration_started</code> / <code>_completed</code> / <code>_failed</code> — a migration pass,
        with its context (<code>cli</code>, <code>cron</code>, or <code>admin</code>). A failure carries the exception <strong>class
        name</strong>, never a message: a database error quotes the failing statement. <strong>No SQL is passed to any migration hook</strong>,
        and a migration that merely has not landed yet is not a failure.</li>
        <li><code>convermetry_storage_error</code> — a database operation Convermetry needed <em>verifiably</em> failed.
        Reserved for real failures: a duplicate <code>INSERT IGNORE</code>, an abandoned notification, or a still-pending migration
        do not fire it. It never carries SQL, the raw database error, submitted fields, IP addresses, or secrets.</li>
        <li><code>convermetry_settings_saved</code> — a settings section was written, listening on WordPress's own option-write
        hooks so it fires on a real write only (never for a form submitted without edits) and catches CLI and migration writers too.
        <strong>Key names only, never values</strong>: two sections hold signing secrets and token-bearing endpoint URLs.</li></ul>
        <?php
        self::cardEnd();

        self::sectionEnd();
    }
}
