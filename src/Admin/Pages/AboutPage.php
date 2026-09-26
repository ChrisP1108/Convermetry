<?php
declare(strict_types=1);

namespace Convermetry\Admin\Pages;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AdminAssets;
use Convermetry\Admin\Capability;

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
     * One source of truth for both the nav and the section headings. A method
     * rather than a constant only because the labels are translated.
     *
     * @return array<string, string>
     */
    private static function sections(): array
    {
        return [
            'overview' => __('Overview', 'convermetry'),
            'admin-pages' => __('Admin pages', 'convermetry'),
            'tracking' => __('Tracking', 'convermetry'),
            'conversions' => __('Conversions', 'convermetry'),
            'forms' => __('Forms', 'convermetry'),
            'identifiers' => __('Identifiers', 'convermetry'),
            'webhooks' => __('Webhooks', 'convermetry'),
            'payloads' => __('Payloads', 'convermetry'),
            'notifications' => __('Notifications', 'convermetry'),
            'developer' => __('Developer API', 'convermetry'),
            'hooks' => __('Hooks', 'convermetry'),
            'rest' => __('REST APIs', 'convermetry'),
            'privacy' => __('Privacy & data', 'convermetry'),
        ];
    }

    /**
     * The hook groups, in render order: group key => [heading, introductory note].
     *
     * The key is an identifier — each entry of {@see hooks()} names its group by
     * it — so it stays as written; the heading is its translated form.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function hookGroups(): array
    {
        return [
            'Webhook delivery' => [
                __('Webhook delivery', 'convermetry'),
                __('Composition filters run <strong>once per logical delivery, before the request is frozen</strong>. A retry re-sends the frozen bytes and never re-runs them, so nothing here can change a delivery already in flight. The lifecycle actions all receive the same credential-free <code>$context</code>: <code>message_type</code>, <code>kind</code> (<code>scheduled</code>/<code>immediate</code>/<code>retry</code>/<code>test</code>), <code>attempt</code>, <code>delivery_id</code>, <code>is_test</code>, <code>endpoint_key</code>, <code>endpoint_label</code>, <code>endpoint_origin</code> (scheme + host only), <code>submission_id</code>, <code>conversion_id</code>, <code>form_key</code>, <code>window_start</code>, <code>window_end</code>, <code>transport_attempted</code>, <code>disposition</code>. Every key is always present.', 'convermetry'),
            ],
            'Tracking' => [
                __('Tracking', 'convermetry'),
                __('The ingestion path is the plugin\'s hottest code. These hooks run inside it, so keep callbacks cheap — and register them early enough to exist: a theme\'s <code>functions.php</code> is already too late for some.', 'convermetry'),
            ],
            'Analytics' => [
                __('Analytics', 'convermetry'),
                __('The dashboard and the analytics payload read through one query layer, so a section registered here appears in both rather than in one of them.', 'convermetry'),
            ],
            'Forms &amp; submissions' => [
                __('Forms & submissions', 'convermetry'),
                __('The submission pipeline in hook order: decide whether to record, shape the fields, attach context, then observe what was written. Anything marked as carrying PII sees real submitted values.', 'convermetry'),
            ],
            'Goals, funnels &amp; leads' => [
                __('Goals, funnels & leads', 'convermetry'),
                __('Goal completions are written with <code>INSERT IGNORE</code> against a dedupe key, which is why the counts these hooks carry differ from the rows they were offered.', 'convermetry'),
            ],
            'Notifications' => [
                __('Notifications', 'convermetry'),
                __('One queue row is one recipient. That is why the recipient list is filtered once at queue time and cannot be changed per attempt.', 'convermetry'),
            ],
            'Operations, settings &amp; API' => [
                __('Operations, settings & API', 'convermetry'),
                __('Observability for the work that runs unattended — retention passes, schema migrations, verified storage failures — plus the two hooks that decide who may see an admin screen and what a REST item carries.', 'convermetry'),
            ],
        ];
    }

    /**
     * Every public hook: [name, type, signature, group, summary].
     *
     * Kept in step with README.md's hook reference — the two are the same
     * catalogue, rendered for two audiences. The summary is translated; the
     * name, type, signature and group key are identifiers and are not.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    private static function hooks(): array
    {
        return [
            [
                'convermetry_webhook_payload',
                'filter',
                'apply_filters(\'convermetry_webhook_payload\', array $payload, string $messageType, array $meta)',
                'Webhook delivery',
                __('Modify any outbound payload before it is frozen/encoded. <code>$meta</code> is <code>[\'start\',\'end\']</code> for reports, <code>[\'submission_id\']</code> for submissions. Runs last, after the extensions filter', 'convermetry'),
            ],
            [
                'convermetry_webhook_payload_extensions',
                'filter',
                'apply_filters(\'convermetry_webhook_payload_extensions\', array $extensions, string $messageType, array $meta)',
                'Webhook delivery',
                __('Add namespaced data as the payload\'s <code>extensions</code> property. Keys must be <code>vendor/thing</code>; bounded to 32 KB / 50 keys / JSON primitives; an empty result adds no property at all', 'convermetry'),
            ],
            [
                'convermetry_webhook_query_args',
                'filter',
                'apply_filters(\'convermetry_webhook_query_args\', array $params, array $context)',
                'Webhook delivery',
                __('Query parameters appended to the endpoint URL, after the global → page → per-form → runtime merge. The result is re-normalized to bounded scalar keys/values, order preserved; the URL still passes <code>wp_safe_remote_post()</code>\'s SSRF checks', 'convermetry'),
            ],
            [
                'convermetry_webhook_headers',
                'filter',
                'apply_filters(\'convermetry_webhook_headers\', array $headers, array $context)',
                'Webhook delivery',
                __('Non-protocol request headers, after the global → per-form → runtime merge. A callback may <strong>not</strong> add, alter, or remove <code>Content-Type</code>, <code>Host</code>, <code>Content-Length</code>, <code>Transfer-Encoding</code>, <code>Connection</code>, <code>User-Agent</code>, <code>Idempotency-Key</code>, or <code>X-Convermetry-Signature</code>; those are restored to their pre-filter state. Values are sent as-is, and the Activity Log redacts by NAME', 'convermetry'),
            ],
            [
                'convermetry_webhook_timeout',
                'filter',
                'apply_filters(\'convermetry_webhook_timeout\', int $timeout, array $context)',
                'Webhook delivery',
                __('HTTP timeout in seconds for one attempt (default 15). Runs per network attempt; values outside 1–30 are <strong>ignored, not clamped</strong>. Raising it costs queue throughput: the worker\'s whole pass budget is 45s. Redirects, TLS verification, blocking mode, and the response-size cap are deliberately not filterable', 'convermetry'),
            ],
            [
                'convermetry_form_delivery_queued',
                'action',
                'do_action(\'convermetry_form_delivery_queued\', array $context);',
                'Webhook delivery',
                __('A submission was genuinely queued for one endpoint. Only when the <code>INSERT IGNORE</code> created a row; a suppressed duplicate fires nothing. Nothing is sent or frozen yet', 'convermetry'),
            ],
            [
                'convermetry_webhook_delivery_frozen',
                'action',
                'do_action(\'convermetry_webhook_delivery_frozen\', array $context, string $storage, int $bodyBytes);',
                'Webhook delivery',
                __('A delivery\'s body, URL, and headers are now fixed. <code>$storage</code> is <code>\'memory\'</code> (analytics, persisted only if a retry follows) or <code>\'queue_row\'</code> (form queue, verified written). Never fires on a frozen retry, nor on the synchronous or test paths, which freeze nothing', 'convermetry'),
            ],
            [
                'convermetry_webhook_before_send',
                'action',
                'do_action(\'convermetry_webhook_before_send\', array $context, array $meta);',
                'Webhook delivery',
                __('Immediately before a real network request, after signing. <code>$meta</code> is <code>[\'body_bytes\',\'body_sha256\',\'header_names\',\'signed\']</code>, metadata only: no URL, no header values, no body. Fires per attempt; does <strong>not</strong> fire when encoding or a report query failed before the wire. Do not throw from here — the request it announces would not happen', 'convermetry'),
            ],
            [
                'convermetry_webhook_delivery_attempted',
                'action',
                'do_action(\'convermetry_webhook_delivery_attempted\', array $context, bool $ok, int $code, string $message);',
                'Webhook delivery',
                __('One attempt\'s <strong>transport</strong> result. <code>transport_attempted</code> is false when nothing reached the wire. Deliberately does not report the retry/queue disposition; nothing has decided one yet', 'convermetry'),
            ],
            [
                'convermetry_delivery_attempt_logged',
                'action',
                'do_action(\'convermetry_delivery_attempt_logged\', array $context, string $disposition);',
                'Webhook delivery',
                __('What became of the Activity Log row. <code>\'stored\'</code>, <code>\'suppressed\'</code> (a <code>convermetry_delivery_log_row</code> callback returned false), or <code>\'failed\'</code>', 'convermetry'),
            ],
            [
                'convermetry_webhook_delivery_succeeded',
                'action',
                'do_action(\'convermetry_webhook_delivery_succeeded\', array $context);',
                'Webhook delivery',
                __('The endpoint accepted it <strong>and</strong> the bookkeeping committed. Analytics: last-sent advanced and retry chain cleared; form queue: row deleted and delivery state recomputed. Once per successful attempt per endpoint', 'convermetry'),
            ],
            [
                'convermetry_webhook_retry_scheduled',
                'action',
                'do_action(\'convermetry_webhook_retry_scheduled\', array $context, int $nextAttempt, int $nextAttemptAt);',
                'Webhook delivery',
                __('The next attempt is persisted. Never speculative; never on a test or on the synchronous path', 'convermetry'),
            ],
            [
                'convermetry_webhook_retry_chain_exhausted',
                'action',
                'do_action(\'convermetry_webhook_retry_chain_exhausted\', array $context);',
                'Webhook delivery',
                __('An <strong>analytics</strong> chain gave up, and its terminal state is persisted. <em>Not</em> abandonment: the frozen body stays in the retry state and the next scheduled dispatch resumes it. Read as "this endpoint is failing", not "this data is gone"', 'convermetry'),
            ],
            [
                'convermetry_webhook_delivery_abandoned',
                'action',
                'do_action(\'convermetry_webhook_delivery_abandoned\', array $context, string $reason);',
                'Webhook delivery',
                __('A queued <strong>form</strong> delivery is gone for good, its row deleted. Genuinely terminal, unlike the analytics chain', 'convermetry'),
            ],
            [
                'convermetry_webhook_delivery_canceled',
                'action',
                'do_action(\'convermetry_webhook_delivery_canceled\', array $context, string $reason);',
                'Webhook delivery',
                __('A queued delivery was removed unsent. Currently <code>\'submission_deleted\'</code>: the submission was deleted before the worker reached the row', 'convermetry'),
            ],
            [
                'convermetry_retry_schedule',
                'filter',
                'apply_filters(\'convermetry_retry_schedule\', int[] $delays)',
                'Webhook delivery',
                __('The webhook retry backoff delays in seconds (both message types). Default <code>[300, 1800, 7200, 21600, 57600]</code>; each entry is clamped to a minimum of 60, and an empty or fully invalid list falls back to the default. The list length <strong>is</strong> the attempt count', 'convermetry'),
            ],
            [
                'convermetry_webhook_report_limit',
                'filter',
                'apply_filters(\'convermetry_webhook_report_limit\', int $limit)',
                'Webhook delivery',
                __('Max rows per <code>top_*</code> list in analytics payloads. Default 200, clamped to a minimum of 1. Does <strong>not</strong> apply to <code>conversions.recent[]</code>, which is lossless: a window holding more than 100 conversions is split across consecutive deliveries rather than truncated', 'convermetry'),
            ],
            [
                'convermetry_delivery_log_row',
                'filter',
                'apply_filters(\'convermetry_delivery_log_row\', array|false $row)',
                'Webhook delivery',
                __('Redact/modify an Activity Log row before storage. Return an array to store it, anything else to skip logging that attempt; skipping affects only the log, never the delivery. Bodies reaching it are already sensitive-key-redacted and capped at 64 KB. The field-level redaction point for anything the built-in policy misses, including an analytics report\'s <code>conversions.recent[].ip_address</code>', 'convermetry'),
            ],
            [
                'convermetry_allow_insecure_webhooks',
                'filter',
                'apply_filters(\'convermetry_allow_insecure_webhooks\', bool $allow)',
                'Webhook delivery',
                __('Return <code>true</code> to allow <code>http://</code> endpoints (development only). Evaluated when endpoints are <strong>saved</strong>, not at send time, so turning it back off does not retire an <code>http://</code> endpoint already stored', 'convermetry'),
            ],
            [
                'convermetry_allowed_hosts',
                'filter',
                'apply_filters(\'convermetry_allowed_hosts\', string[] $hosts)',
                'Webhook delivery',
                __('Hostnames accepted in tracked URLs / Origin checks, treated as internal in referrer reports. Lowercase, no scheme or path; defaults to the hosts of <code>home_url()</code> and <code>site_url()</code>. <strong>Memoized per request.</strong> This widens what the public ingestion endpoint accepts, so add only hosts you control', 'convermetry'),
            ],
            [
                'convermetry_should_enqueue_tracker',
                'filter',
                'apply_filters(\'convermetry_should_enqueue_tracker\', bool $should, array $enabled)',
                'Tracking',
                __('Whether to load the frontend tracker on this request. Runs after the configured exclusions, so it can only suppress, never resurrect. Runs on <code>wp_enqueue_scripts</code>, so conditional tags are available', 'convermetry'),
            ],
            [
                'convermetry_tracker_config_extensions',
                'filter',
                'apply_filters(\'convermetry_tracker_config_extensions\', array $extensions, array $enabled)',
                'Tracking',
                __('Add namespaced data to <code>window.ConvermetryConfig.extensions</code>. Smallest budget in the plugin (8 KB / 20 keys), because this is inlined into every page view. <strong>This data is public</strong>: never put a key, token, or anything visitor-specific here. The REST endpoint and batching limits cannot be replaced', 'convermetry'),
            ],
            [
                'convermetry_should_track_event',
                'filter',
                'apply_filters(\'convermetry_should_track_event\', bool $should, string $type, array $data)',
                'Tracking',
                __('Whether to record one tracked event. Runs <strong>last</strong> in sanitization, so <code>$data</code> is whitelisted and bounded; raw anonymous input from the public endpoint is never exposed to a hook. Returning <code>false</code> drops this event only', 'convermetry'),
            ],
            [
                'convermetry_tracked_event',
                'filter',
                'apply_filters(\'convermetry_tracked_event\', array $row, string $type)',
                'Tracking',
                __('Inspect/modify an event row before storage; return <code>false</code> to drop.', 'convermetry'),
            ],
            [
                'convermetry_client_ip',
                'filter',
                'apply_filters(\'convermetry_client_ip\', string $ip)',
                'Tracking',
                __('Map the client IP used for tracking rate limits <strong>and</strong> as the basis of the stored address (reverse proxies / CDNs). Defaults to <code>REMOTE_ADDR</code>; the result must validate as IPv4/IPv6 or it stores empty, and is <strong>memoized for the request</strong>. A forwarded header is spoofable unless a trusted proxy overwrites it, and a comma-joined <code>X-Forwarded-For</code> chain is not an address — pick the hop your proxy guarantees. Pseudonymize with <code>convermetry_stored_ip</code>, not here', 'convermetry'),
            ],
            [
                'convermetry_stored_ip',
                'filter',
                'apply_filters(\'convermetry_stored_ip\', string $ip)',
                'Tracking',
                __('The address about to be <strong>persisted</strong>, after the privacy gates. The pseudonymization hook (truncate, hash, or return <code>\'\'</code>). Must return a valid IPv4/IPv6 address or <code>\'\'</code>. Deliberately does <strong>not</strong> affect the rate-limit identity, which would collapse every visitor into one bucket', 'convermetry'),
            ],
            [
                'convermetry_tracking_batch_recorded',
                'action',
                'do_action(\'convermetry_tracking_batch_recorded\', int $stored, int $accepted, int $offered, ?string $batchId);',
                'Tracking',
                __('One batch was written. One action per batch, never per event, on the plugin\'s hottest path. The three counts differ: offered → accepted (survived sanitization) → stored (survived deduplication)', 'convermetry'),
            ],
            [
                'convermetry_tracking_rate_limited',
                'action',
                'do_action(\'convermetry_tracking_rate_limited\', int $events, int $window);',
                'Tracking',
                __('A batch was rejected by the rate limiter. Carries no address and no hash of one; the endpoint is public and unauthenticated', 'convermetry'),
            ],
            [
                'convermetry_rate_limits',
                'filter',
                'apply_filters(\'convermetry_rate_limits\', array $defaults)',
                'Tracking',
                __('<code>[\'per_ip\' => 300, \'site_wide\' => 3000]</code> events/minute. Both clamped to a minimum of 1; a non-array return or a missing key falls back. Charged <strong>per event</strong>, so one 20-event batch costs 20, and the per-IP check runs first so a flooding IP never consumes the site-wide budget', 'convermetry'),
            ],
            [
                'convermetry_source_aliases',
                'filter',
                'apply_filters(\'convermetry_source_aliases\', array $aliases)',
                'Tracking',
                __('Extend/override the utm_source alias map. Keys are raw lowercase source values, values the canonical name. Return the map with entries <strong>added</strong>: a replacement map loses the defaults, and a source with no entry is stored as submitted', 'convermetry'),
            ],
            [
                'convermetry_channel',
                'filter',
                'apply_filters(\'convermetry_channel\', string $channel, array $row, string $type)',
                'Tracking',
                __('Override the marketing channel assigned at ingestion.', 'convermetry'),
            ],
            [
                'convermetry_analytics_sections',
                'filter',
                'apply_filters(\'convermetry_analytics_sections\', array $sections)',
                'Analytics',
                __('Register <code>AnalyticsSectionInterface</code> adapters that add a dashboard panel <strong>and</strong> contribute to <code>analytics.extensions</code> on the wire. A typed registry, never SQL: there is deliberately no way to pass a query fragment or table name to a path that runs unattended on cron. Keys must be namespaced; a section that throws is dropped, not propagated', 'convermetry'),
            ],
            [
                'convermetry_analytics_extensions',
                'filter',
                'apply_filters(\'convermetry_analytics_extensions\', array $extensions, string $start, string $end, int $limit)',
                'Analytics',
                __('Extension data attached to an analytics summary. Pre-populated from registered sections. Computed inside <code>Reports::buildSummary()</code>, i.e. once per delivery at freeze time; a retry never rebuilds it. Bounded to 32 KB / 50 keys', 'convermetry'),
            ],
            [
                'convermetry_analytics_periods',
                'filter',
                'apply_filters(\'convermetry_analytics_periods\', int[] $periods)',
                'Analytics',
                __('Reporting periods (in days) offered on the dashboard. Default <code>[7, 30, 90]</code>; validated, deduplicated, sorted, and <strong>clamped to the retention window</strong> so a period longer than the data cannot draw a chart that looks like a traffic collapse', 'convermetry'),
            ],
            [
                'convermetry_analytics_report_failed',
                'action',
                'do_action(\'convermetry_analytics_report_failed\', string $component, string $reportKey, string $start, string $end, string $error);',
                'Analytics',
                __('A report could not be generated. <code>$error</code> is an exception <strong>class name</strong>, never a message: a database error quotes the failing statement', 'convermetry'),
            ],
            [
                'convermetry_analytics_admin_panels',
                'action',
                'do_action(\'convermetry_analytics_admin_panels\', string $start, string $end);',
                'Analytics',
                __('Render extra panels at the end of the dashboard. Runs after this screen\'s capability check; <strong>your callback must escape its own output</strong>', 'convermetry'),
            ],
            [
                'convermetry_should_record_submission',
                'filter',
                'apply_filters(\'convermetry_should_record_submission\', bool $should, string $formKey, string $provider, array $fields)',
                'Forms &amp; submissions',
                __('Whether to record a submission at all. Runs after normalization (so spam rules can read the fields) and before <strong>any</strong> write. <code>false</code> skips the conversion event, the row, the queue, and the notifications. The visitor sees success: returning a failure would make Elementor\'s synchronous mode reject a valid form. <strong><code>$fields</code> contains PII</strong>', 'convermetry'),
            ],
            [
                'convermetry_submission_fields',
                'filter',
                'apply_filters(\'convermetry_submission_fields\', array $fields, string $formKey, string $provider)',
                'Forms &amp; submissions',
                __('The normalized field descriptors. A <strong>changed</strong> result is re-normalized, so <code>cvm_*</code> stays stripped and the descriptor shape holds. <strong>Contains PII</strong>', 'convermetry'),
            ],
            [
                'convermetry_submission_context_extensions',
                'filter',
                'apply_filters(\'convermetry_submission_context_extensions\', array $extensions, string $formKey, string $provider)',
                'Forms &amp; submissions',
                __('Namespaced data added to the stored analytics context. Attached once before persistence, so every endpoint and every retry sees the same context. Cannot replace conversion id, session id, attribution, timestamps, or form identity', 'convermetry'),
            ],
            [
                'convermetry_submission_recorded',
                'action',
                'do_action(\'convermetry_submission_recorded\', $submissionId, $conversionId, $context);',
                'Forms &amp; submissions',
                __('Fires after a submission is recorded, before webhook delivery is considered — so listeners run even with no endpoints configured (this is where notifications are queued).', 'convermetry'),
            ],
            [
                'convermetry_submission_recorded_details',
                'action',
                'do_action(\'convermetry_submission_recorded_details\', int $rowId, string $submissionId, array $form, array $fields);',
                'Forms &amp; submissions',
                __('Fires immediately after the above with what its fixed signature cannot carry. <code>$form</code> is <code>{provider, form_key, form_name, native_id}</code>. <strong><code>$fields</code> contains PII</strong>; use <code>convermetry_submission_recorded</code> if you only need to know a submission happened', 'convermetry'),
            ],
            [
                'convermetry_submission_duplicate',
                'action',
                'do_action(\'convermetry_submission_duplicate\', string $submissionId, string $conversionId, string $formKey);',
                'Forms &amp; submissions',
                __('A duplicate of an already-recorded submission (double-fired callback, replayed AJAX). Nothing is written or re-queued; <strong>do not re-send anything</strong>', 'convermetry'),
            ],
            [
                'convermetry_submission_delivery_state_changed',
                'action',
                'do_action(\'convermetry_submission_delivery_state_changed\', string $submissionId, string $state, string $previous);',
                'Forms &amp; submissions',
                __('The recorded delivery state genuinely changed. Only on a transition; the state is recomputed several times per delivery and most recomputations are silent', 'convermetry'),
            ],
            [
                'convermetry_submission_deleted',
                'action',
                'do_action(\'convermetry_submission_deleted\', int $id, string $submissionId);',
                'Forms &amp; submissions',
                __('A submission and everything attached to it are gone. Fires last, after the queue rows, queued notifications, and lead history are removed. Carries ids only: the data is what is being erased', 'convermetry'),
            ],
            [
                'convermetry_submissions_cleared',
                'action',
                'do_action(\'convermetry_submissions_cleared\');',
                'Forms &amp; submissions',
                __('Every submission, queued delivery, queued notification, and lead history row was removed. Once for the whole operation; the rows are dropped with <code>TRUNCATE</code> and bulk deletes that never load one', 'convermetry'),
            ],
            [
                'convermetry_form_settings_saved',
                'action',
                'do_action(\'convermetry_form_settings_saved\', string[] $formKeys);',
                'Forms &amp; submissions',
                __('Per-form settings were written. Fires from the storage layer on a real write, so CLI callers raise it too', 'convermetry'),
            ],
            [
                'convermetry_discovered_forms',
                'filter',
                'apply_filters(\'convermetry_discovered_forms\', array $forms, string $providerKey)',
                'Forms &amp; submissions',
                __('The forms discovered for one provider. Runs <strong>before</strong> the 5-minute cache is written, so the result is normalized back to <code>{native_id, name}</code>, empty ids dropped, duplicates collapsed', 'convermetry'),
            ],
            [
                'convermetry_form_providers',
                'filter',
                'apply_filters(\'convermetry_form_providers\', FormProviderInterface[] $providers)',
                'Forms &amp; submissions',
                __('Register custom <code>FormProviderInterface</code> adapters. Entries that are not instances of the interface are silently discarded, and providers are keyed by <code>getKey()</code>, so an adapter reusing a bundled key <strong>replaces</strong> it. <strong>Memoized on first use</strong>: register at plugin load time, not on <code>init</code>', 'convermetry'),
            ],
            [
                'convermetry_submission_csv_columns',
                'filter',
                'apply_filters(\'convermetry_submission_csv_columns\', array $columns)',
                'Forms &amp; submissions',
                __('The export\'s columns as an ordered <code>key => header label</code> map. Paired with the values filter <strong>by key, never by position</strong>, so the two cannot drift out of alignment', 'convermetry'),
            ],
            [
                'convermetry_submission_csv_values',
                'filter',
                'apply_filters(\'convermetry_submission_csv_values\', array $values, array $row)',
                'Forms &amp; submissions',
                __('One exported row\'s <code>key => value</code> map. Runs per row while streaming, so keep it cheap. Values must be scalar or null and go through the same formula-injection escaping as core ones. <strong>Contains PII</strong>', 'convermetry'),
            ],
            [
                'convermetry_submissions_columns',
                'filter',
                'apply_filters(\'convermetry_submissions_columns\', array $columns, array $row)',
                'Forms &amp; submissions',
                __('Extra cells appended to each row of the submissions list. <code>key => already-escaped HTML</code>, <strong>printed verbatim, so escape it yourself</strong>. <strong><code>$row</code> contains PII</strong>', 'convermetry'),
            ],
            [
                'convermetry_submission_detail_sections',
                'action',
                'do_action(\'convermetry_submission_detail_sections\', array $row);',
                'Forms &amp; submissions',
                __('Render extra blocks at the end of a submission\'s detail panel. After the nonce and capability checks; <strong>escape your own output</strong>. <strong><code>$row</code> contains PII</strong>', 'convermetry'),
            ],
            [
                'convermetry_submission_row_actions',
                'action',
                'do_action(\'convermetry_submission_row_actions\', array $row);',
                'Forms &amp; submissions',
                __('Render extra buttons in a submission\'s action bar. Nonce-protect anything that acts. <strong><code>$row</code> contains PII</strong>', 'convermetry'),
            ],
            [
                'convermetry_forms_admin_sections',
                'action',
                'do_action(\'convermetry_forms_admin_sections\');',
                'Forms &amp; submissions',
                __('Render extra content at the end of the Forms screen. Outside the settings form, so post your own form to <code>admin-post.php</code>. <strong>Escape your own output</strong>', 'convermetry'),
            ],
            [
                'convermetry_form_submission',
                'action',
                'do_action(\'convermetry_form_submission\', array $formIdentifier, array $fields, array $context = []);',
                'Forms &amp; submissions',
                __('Submit a custom form (fire-and-forget, background delivery). <code>$fields</code> accepts a list of <code>[\'id\', \'label\', \'value\']</code> descriptors <strong>or</strong> the historical <code>name => value</code> map. See Custom form integration API', 'convermetry'),
            ],
            [
                'convermetry_should_record_goal_completion',
                'filter',
                'apply_filters(\'convermetry_should_record_goal_completion\', bool $should, array $row, array $goal)',
                'Goals, funnels &amp; leads',
                __('Whether to record one matched completion. A decision only: the row is passed for inspection, and nothing returned changes it. The completion id, definition hash, event uid, dedupe key, and timestamp are identity, and a hook that could rewrite them could silently defeat once-per-session goals', 'convermetry'),
            ],
            [
                'convermetry_goal_completion',
                'filter',
                'apply_filters(\'convermetry_goal_completion\', array $row, array $goal)',
                'Goals, funnels &amp; leads',
                __('Inspect/modify a goal completion row before it is written.', 'convermetry'),
            ],
            [
                'convermetry_goal_matched',
                'action',
                'do_action(\'convermetry_goal_matched\', int $stored, array $rows);',
                'Goals, funnels &amp; leads',
                __('Fires after a batch of goal completions is stored.', 'convermetry'),
            ],
            [
                'convermetry_goal_completions_recorded',
                'action',
                'do_action(\'convermetry_goal_completions_recorded\', int $stored, int $offered, array $completionIds);',
                'Goals, funnels &amp; leads',
                __('Fires immediately after the above with the offered/stored split. The two counts differ because completions are written with <code>INSERT IGNORE</code> against a dedupe key; <code>$completionIds</code> are the ids <strong>offered</strong>, not the ids stored', 'convermetry'),
            ],
            [
                'convermetry_goal_saved',
                'action',
                'do_action(\'convermetry_goal_saved\', string $goalId, array $goal, ?array $previous);',
                'Goals, funnels &amp; leads',
                __('A goal definition was persisted. <code>$previous</code> is null for a new goal. Fires from the repository, so CLI callers raise it too, and only on a successful write', 'convermetry'),
            ],
            [
                'convermetry_goal_deleted',
                'action',
                'do_action(\'convermetry_goal_deleted\', string $goalId, string $now);',
                'Goals, funnels &amp; leads',
                __('A goal was deleted. Only when it existed and the write succeeded. The deletion is soft: completions and the name survive so historical reports keep working', 'convermetry'),
            ],
            [
                'convermetry_funnel_saved',
                'action',
                'do_action(\'convermetry_funnel_saved\', string $funnelId, array $funnel, ?array $previous);',
                'Goals, funnels &amp; leads',
                __('A funnel definition was persisted. Editing a funnel changes what every past report says, retroactively', 'convermetry'),
            ],
            [
                'convermetry_funnel_deleted',
                'action',
                'do_action(\'convermetry_funnel_deleted\', string $funnelId, string $now);',
                'Goals, funnels &amp; leads',
                __('A funnel was deleted.', 'convermetry'),
            ],
            [
                'convermetry_lead_status_updated',
                'action',
                'do_action(\'convermetry_lead_status_updated\', $submissionId, $toStatus, $fromStatus, ?string $value, string $currency);',
                'Goals, funnels &amp; leads',
                __('Fires after a lead\'s status or value changes.', 'convermetry'),
            ],
            [
                'convermetry_lead_updated',
                'action',
                'do_action(\'convermetry_lead_updated\', string $submissionId, array $to, array $from, int $userId, string $leadEventId);',
                'Goals, funnels &amp; leads',
                __('Fires immediately after the above with the full before/after. <code>$to</code>/<code>$from</code> are <code>{status, value, currency}</code>. Both fire <strong>after</strong> the transaction commits. Values are exact decimal <strong>strings</strong>, never floats; currency is stamped, not converted, and a null value is not <code>\'0.00\'</code>', 'convermetry'),
            ],
            [
                'convermetry_should_queue_notification',
                'filter',
                'apply_filters(\'convermetry_should_queue_notification\', bool $should, string $formKey, array $identity)',
                'Notifications',
                __('Whether to queue notifications for a submission. Runs after the configured rules said yes, so it can only narrow. <code>$identity</code> is identity columns, never field values', 'convermetry'),
            ],
            [
                'convermetry_notification_recipients',
                'filter',
                'apply_filters(\'convermetry_notification_recipients\', array $recipients, string $formKey, array $identity)',
                'Notifications',
                __('The addresses to queue. Runs once at <strong>queue</strong> time; each address becomes its own row with its own retry chain. Re-validated through <code>sanitize_email()</code>/<code>is_email()</code>, deduplicated, capped at 20', 'convermetry'),
            ],
            [
                'convermetry_notification_message',
                'filter',
                'apply_filters(\'convermetry_notification_message\', array $message, string $submissionId, int $attempt)',
                'Notifications',
                __('Subject, HTML body, and additional headers, per attempt. The <strong>recipient is not changeable</strong>: one row is one address, and a per-attempt rewrite could collapse two rows onto one mailbox. Subject gets the header-injection strip and 200-char cap, the body the 256 KB cap, and the four required headers are reinstated. <strong><code>$message[\'html\']</code> contains PII</strong>', 'convermetry'),
            ],
            [
                'convermetry_notification_queued',
                'action',
                'do_action(\'convermetry_notification_queued\', string $submissionId, string $recipient, int $attempt);',
                'Notifications',
                __('A notification was genuinely queued. Only for a real insert', 'convermetry'),
            ],
            [
                'convermetry_notification_before_send',
                'action',
                'do_action(\'convermetry_notification_before_send\', string $submissionId, string $recipient, int $attempt);',
                'Notifications',
                __('Immediately before <code>wp_mail()</code>. No subject, no body, no fields', 'convermetry'),
            ],
            [
                'convermetry_notification_accepted',
                'action',
                'do_action(\'convermetry_notification_accepted\', string $submissionId, string $recipient, int $attempt);',
                'Notifications',
                __('<code>wp_mail()</code> returned true <strong>and</strong> the queue row was removed. "accepted", never "delivered": the local transport took the message, which is not receipt', 'convermetry'),
            ],
            [
                'convermetry_notification_retry_scheduled',
                'action',
                'do_action(\'convermetry_notification_retry_scheduled\', string $submissionId, string $recipient, int $nextAttempt, int $nextAttemptAt);',
                'Notifications',
                __('The next attempt is persisted.', 'convermetry'),
            ],
            [
                'convermetry_notification_abandoned',
                'action',
                'do_action(\'convermetry_notification_abandoned\', string $submissionId, string $recipient, int $attempt, string $error);',
                'Notifications',
                __('Retries spent, row deleted, message will never be sent.', 'convermetry'),
            ],
            [
                'convermetry_notification_canceled',
                'action',
                'do_action(\'convermetry_notification_canceled\', string $submissionId, string $recipient, string $reason, int $count);',
                'Notifications',
                __('Queued notifications were cancelled unsent. <code>$reason</code> is <code>\'expired\'</code>, <code>\'submission_deleted\'</code>, or <code>\'admin_clear\'</code>. Per-row from the worker; <strong>one aggregate action</strong> for bulk clears, with <code>$recipient</code> empty — addresses are never read back purely to emit a hook', 'convermetry'),
            ],
            [
                'convermetry_notification_retry_schedule',
                'filter',
                'apply_filters(\'convermetry_notification_retry_schedule\', int[] $delays)',
                'Notifications',
                __('The email-notification retry backoff in seconds. Default <code>[300, 900, 3600]</code>; entries clamped to a minimum of 60, non-numeric entries discarded, empty falls back. Deliberately separate from the webhook schedule — a stale lead notification is worse than none, and email has no receiver-side idempotency. The hard two-hour TTL sits above it regardless', 'convermetry'),
            ],
            [
                'convermetry_sensitive_keys',
                'filter',
                'apply_filters(\'convermetry_sensitive_keys\', string[] $patterns)',
                'Notifications',
                __('Extend the credential-looking field/header names redacted from the Activity Log <strong>and</strong> omitted from notification emails (e.g. add <code>ssn</code>). Defaults are <code>password</code>, <code>passwd</code>, <code>pwd</code>, <code>secret</code>, <code>token</code>, <code>api_key</code>, <code>apikey</code>, <code>authorization</code>, <code>credential</code>, <code>private_key</code>, <code>access_token</code>, <code>refresh_token</code>, <code>client_secret</code>, plus <code>cookie</code> for headers. Matched as substrings of a canonical form: lowercase with non-alphanumeric runs collapsed to <code>_</code>, so <code>API Key</code>, <code>x-api-key</code> and <code>API_KEY</code> all match <code>api_key</code>. The returned list <strong>is</strong> the effective list — extend it; a shorter one weakens both surfaces. Note the asymmetry: a match is <code>[REDACTED]</code> in the log, but omitted with no placeholder from an email, because a placeholder announces that a secret exists', 'convermetry'),
            ],
            [
                'convermetry_retention_cleanup_started',
                'action',
                'do_action(\'convermetry_retention_cleanup_started\', string $store, string $cutoff);',
                'Operations, settings &amp; API',
                __('One store begins deleting past the retention cutoff. Observational: a listener <strong>cannot</strong> cancel a pass, change the cutoff, or extend retention', 'convermetry'),
            ],
            [
                'convermetry_retention_cleanup_completed',
                'action',
                'do_action(\'convermetry_retention_cleanup_completed\', string $store, string $cutoff, int $deleted, bool $moreRemain, string $outcome);',
                'Operations, settings &amp; API',
                __('One store\'s pass finished. <code>$outcome</code> is <code>completed</code>/<code>truncated</code>/<code>query_failed</code>/<code>lock_lost</code>. Convermetry schedules any follow-up pass itself', 'convermetry'),
            ],
            [
                'convermetry_migration_started',
                'action',
                'do_action(\'convermetry_migration_started\', string $context);',
                'Operations, settings &amp; API',
                __('A migration pass began, with the lease held. <code>\'cli\'</code>, <code>\'cron\'</code>, or <code>\'admin\'</code>. Do not throw: the lease would be held until it expires. No SQL is passed to any migration hook', 'convermetry'),
            ],
            [
                'convermetry_migration_completed',
                'action',
                'do_action(\'convermetry_migration_completed\', string $context, bool $pending);',
                'Operations, settings &amp; API',
                __('A pass finished. Fires after the lease is released and after the pending check <strong>and</strong> reschedule decision, so <code>$pending</code> is settled. A pending migration is normal mid-migration, not an error', 'convermetry'),
            ],
            [
                'convermetry_migration_failed',
                'action',
                'do_action(\'convermetry_migration_failed\', string $context, string $error);',
                'Operations, settings &amp; API',
                __('A pass threw. <code>$error</code> is the exception <strong>class name</strong>. Fires after the lease is released and before the failure continues to the caller. A migration that merely did not land is not a failure', 'convermetry'),
            ],
            [
                'convermetry_storage_error',
                'action',
                'do_action(\'convermetry_storage_error\', string $subsystem, string $operation, string $code, array $context);',
                'Operations, settings &amp; API',
                __('A database operation Convermetry needed verifiably failed. Reserved for verified failures: a duplicate <code>INSERT IGNORE</code>, an abandoned notification, or a still-pending migration do <strong>not</strong> fire it. Never carries SQL, <code>$wpdb->last_error</code>, submitted fields, IPs, or secrets', 'convermetry'),
            ],
            [
                'convermetry_settings_saved',
                'action',
                'do_action(\'convermetry_settings_saved\', string $section, string[] $changedKeys);',
                'Operations, settings &amp; API',
                __('A settings section was written. Listens on WordPress\'s own option-write hooks, so it fires on a real write only (never for a form submitted without edits) and catches CLI and migration writers too. <strong>Key names only, never values</strong>: two sections hold signing secrets and token-bearing endpoint URLs', 'convermetry'),
            ],
            [
                'convermetry_admin_capability',
                'filter',
                'apply_filters(\'convermetry_admin_capability\', string $capability, string $scope)',
                'Operations, settings &amp; API',
                __('The capability required for one admin surface. Scopes: <code>analytics.view</code>, <code>submissions.view</code>, <code>submissions.export</code>, <code>submissions.delete</code>, <code>leads.edit</code>, <code>goals.manage</code>, <code>funnels.manage</code>, <code>forms.manage</code>, <code>notifications.manage</code>, <code>webhooks.manage</code>, <code>activity.view</code>, <code>activity.manage</code>, <code>api.manage</code>, <code>settings.manage</code>. All default to <code>manage_options</code> and are applied to menu visibility <strong>and</strong> every handler behind it. Must return a non-empty lowercase <code>[a-z0-9_]</code> name; anything else falls back, because <code>current_user_can(\'\')</code> would lock the owner out. Grant deliberately — <code>submissions.export</code> is every lead\'s name and email in one file', 'convermetry'),
            ],
            [
                'convermetry_delivery_log_api_item',
                'filter',
                'apply_filters(\'convermetry_delivery_log_api_item\', array $extensions, array $item)',
                'Operations, settings &amp; API',
                __('Add a namespaced <code>extensions</code> property to one delivery-log REST item. Runs after endpoint-URL redaction and body decoding. The core keys are <strong>immutable</strong>: a filter that could rewrite <code>success</code> would let a plugin lie to a monitoring dashboard. Bounded to 4 KB / 10 keys', 'convermetry'),
            ],
        ];
    }

    /**
     * The credential-free delivery `$context` every webhook lifecycle hook
     * receives. Written once because all twelve of them share it exactly.
     *
     * @return string
     */
    private static function deliveryContext(): string
    {
        return __('Always the same fifteen keys, always all present: <code>message_type</code> (<code>analytics_report</code>/<code>form_submission</code>), <code>kind</code> (<code>scheduled</code>/<code>immediate</code>/<code>retry</code>/<code>test</code>), <code>attempt</code>, <code>delivery_id</code>, <code>is_test</code>, <code>endpoint_key</code>, <code>endpoint_label</code>, <code>endpoint_origin</code> (scheme + host only), <code>submission_id</code>, <code>conversion_id</code>, <code>form_key</code>, <code>window_start</code>, <code>window_end</code>, <code>transport_attempted</code>, <code>disposition</code>. Never a full URL, a header value, a body, or a signing secret', 'convermetry');
    }

    /**
     * Per-hook argument notes: hook name => [argument => what it holds].
     *
     * The argument (a PHP signature fragment) is code and is not translated;
     * the note is.
     *
     * @return array<string, array<string, string>>
     */
    private static function hookArgs(): array
    {
        return [
            'convermetry_webhook_payload' => [
                'array $payload' => __('The complete payload about to be encoded — <code>schema_version</code>, <code>source</code>, <code>plugin_version</code>, <code>message_type</code>, <code>website_info</code>, <code>generated_at</code>, and then either <code>analytics</code> or <code>submission</code>. Return it modified', 'convermetry'),
                'string $messageType' => __('<code>\'analytics_report\'</code> or <code>\'form_submission\'</code>', 'convermetry'),
                'array $meta' => __('<code>[\'start\', \'end\']</code> (Y-m-d) for reports, <code>[\'submission_id\']</code> for submissions', 'convermetry'),
            ],
            'convermetry_webhook_payload_extensions' => [
                'array $extensions' => __('Namespaced blocks to publish as the payload\'s <code>extensions</code> property. Keys <strong>must</strong> be <code>vendor/thing</code>; the whole structure is bounded to 32 KB, 50 keys, and JSON primitives. Return it empty and no property is added at all', 'convermetry'),
                'string $messageType' => __('<code>\'analytics_report\'</code> or <code>\'form_submission\'</code>', 'convermetry'),
                'array $meta' => __('As above: report window, or the submission id', 'convermetry'),
            ],
            'convermetry_webhook_query_args' => [
                'array $params' => __('Query parameters to append to the endpoint URL, already merged global → page → per-form → runtime. Re-normalized after you return it to bounded scalar keys and values, order preserved', 'convermetry'),
                'array $context' => self::deliveryContext(),
            ],
            'convermetry_webhook_headers' => [
                'array $headers' => __('Non-protocol request headers, already merged global → per-form → runtime. <code>Content-Type</code>, <code>Host</code>, <code>Content-Length</code>, <code>Transfer-Encoding</code>, <code>Connection</code>, <code>User-Agent</code>, <code>Idempotency-Key</code> and <code>X-Convermetry-Signature</code> are restored to their pre-filter state afterwards, so touching them has no effect', 'convermetry'),
                'array $context' => self::deliveryContext(),
            ],
            'convermetry_webhook_timeout' => [
                'int $timeout' => __('Seconds for one attempt; default 15. A return outside 1–30 is <strong>ignored, not clamped</strong> — the default stands', 'convermetry'),
                'array $context' => self::deliveryContext(),
            ],
            'convermetry_form_delivery_queued' => [
                'array $context' => self::deliveryContext(),
            ],
            'convermetry_webhook_delivery_frozen' => [
                'array $context' => self::deliveryContext(),
                'string $storage' => __('<code>\'memory\'</code> for analytics (persisted only if a retry follows) or <code>\'queue_row\'</code> for a form delivery (verified written to the queue table)', 'convermetry'),
                'int $bodyBytes' => __('Size of the frozen body. These exact bytes are what every retry re-sends', 'convermetry'),
            ],
            'convermetry_webhook_before_send' => [
                'array $context' => self::deliveryContext(),
                'array $meta' => __('Metadata only — <code>body_bytes</code>, <code>body_sha256</code>, <code>header_names</code> (names, not values) and <code>signed</code>. Deliberately no URL, no header values, no body', 'convermetry'),
            ],
            'convermetry_webhook_delivery_attempted' => [
                'array $context' => self::deliveryContext(),
                'bool $ok' => __('Whether the transport succeeded. Not whether the delivery is finished — nothing has decided a retry or queue disposition yet', 'convermetry'),
                'int $code' => __('HTTP status, or <code>0</code> when nothing reached the wire', 'convermetry'),
                'string $message' => __('Short transport-level reason; empty on success', 'convermetry'),
            ],
            'convermetry_delivery_attempt_logged' => [
                'array $context' => self::deliveryContext(),
                'string $disposition' => __('<code>\'stored\'</code>, <code>\'suppressed\'</code> (a <code>convermetry_delivery_log_row</code> callback returned false) or <code>\'failed\'</code>', 'convermetry'),
            ],
            'convermetry_webhook_delivery_succeeded' => [
                'array $context' => self::deliveryContext(),
            ],
            'convermetry_webhook_retry_scheduled' => [
                'array $context' => self::deliveryContext(),
                'int $nextAttempt' => __('The attempt number that will run next (1-based)', 'convermetry'),
                'int $nextAttemptAt' => __('Unix timestamp it becomes due — already persisted, never speculative', 'convermetry'),
            ],
            'convermetry_webhook_retry_chain_exhausted' => [
                'array $context' => self::deliveryContext(),
            ],
            'convermetry_webhook_delivery_abandoned' => [
                'array $context' => self::deliveryContext(),
                'string $reason' => __('Why it was given up on. Terminal: the queue row is deleted', 'convermetry'),
            ],
            'convermetry_webhook_delivery_canceled' => [
                'array $context' => self::deliveryContext(),
                'string $reason' => __('Currently <code>\'submission_deleted\'</code> — the submission went away before the worker reached its row', 'convermetry'),
            ],
            'convermetry_retry_schedule' => [
                'int[] $delays' => __('Seconds to wait before each retry; default <code>[300, 1800, 7200, 21600, 57600]</code>. Each entry is clamped to a minimum of 60, and an empty or fully invalid list falls back to the default. <strong>The list length is the attempt count</strong> — a three-entry list means three retries', 'convermetry'),
            ],
            'convermetry_webhook_report_limit' => [
                'int $limit' => __('Max rows per <code>top_*</code> list in an analytics payload; default 200, clamped to a minimum of 1. Does not apply to <code>conversions.recent[]</code>', 'convermetry'),
            ],
            'convermetry_delivery_log_row' => [
                'array|false $row' => __('The Activity Log row about to be written: endpoint, status, timing, and already-redacted request/response bodies capped at 64 KB. Return an array to store it, or <code>false</code> (or anything non-array) to skip logging this attempt. Skipping affects the log only — the delivery still happens', 'convermetry'),
            ],
            'convermetry_allow_insecure_webhooks' => [
                'bool $allow' => __('Whether an <code>http://</code> endpoint may be saved. Evaluated when endpoints are <strong>saved</strong>, not at send time', 'convermetry'),
            ],
            'convermetry_allowed_hosts' => [
                'string[] $hosts' => __('Lowercase hostnames, no scheme or path. Defaults to the hosts of <code>home_url()</code> and <code>site_url()</code>. <strong>Memoized per request.</strong> This widens what the public ingestion endpoint accepts — add only hosts you control', 'convermetry'),
            ],
            'convermetry_should_enqueue_tracker' => [
                'bool $should' => __('Whether to enqueue the tracker on this request. Runs after the configured exclusions, so returning <code>true</code> cannot resurrect a suppressed load — you can only narrow', 'convermetry'),
                'array $enabled' => __('Event types currently switched on, as <code>type => true</code>', 'convermetry'),
            ],
            'convermetry_tracker_config_extensions' => [
                'array $extensions' => __('Namespaced data for <code>window.ConvermetryConfig.extensions</code>. Smallest budget in the plugin — 8 KB, 20 keys — because it is inlined into every page view. <strong>Public to every visitor</strong>: never a key, a token, or anything visitor-specific', 'convermetry'),
                'array $enabled' => __('Event types currently switched on, as <code>type => true</code>', 'convermetry'),
            ],
            'convermetry_should_track_event' => [
                'bool $should' => __('Whether to record this one event. <code>false</code> drops it and nothing else', 'convermetry'),
                'string $type' => __('Event type — <code>pageview</code>, <code>click</code>, <code>form_submit</code>, <code>form_success</code>, <code>form_view</code>, <code>form_start</code>, <code>form_error</code>, <code>hover</code>, <code>scroll_depth</code>, <code>custom_event</code>', 'convermetry'),
                'array $data' => __('The sanitized event. Runs <strong>last</strong> in sanitization, so this is already whitelisted, bounded and scalar — raw anonymous input never reaches a hook', 'convermetry'),
            ],
            'convermetry_tracked_event' => [
                'array $row' => __('The database row about to be inserted: <code>event_type</code>, <code>page_url</code>, <code>target_url</code>, <code>session_id</code>, <code>element_label</code>, <code>form_key</code>, <code>event_value</code>, the campaign columns, and <code>ip_address</code> when IP storage is on. Return <code>false</code> to drop it', 'convermetry'),
                'string $type' => __('The event type, for convenience', 'convermetry'),
            ],
            'convermetry_client_ip' => [
                'string $ip' => __('Defaults to <code>REMOTE_ADDR</code>. Must return something that validates as IPv4/IPv6 or the address stores empty. <strong>Memoized per request.</strong> A comma-joined <code>X-Forwarded-For</code> chain is not an address — pick the hop your proxy guarantees. Pseudonymize in <code>convermetry_stored_ip</code>, not here: this value is also the rate-limit identity', 'convermetry'),
            ],
            'convermetry_stored_ip' => [
                'string $ip' => __('The address about to be <strong>persisted</strong>, after the privacy gates. Truncate, hash, or return <code>\'\'</code> to store nothing. Must be a valid IPv4/IPv6 address or <code>\'\'</code>. Does not affect the rate-limit identity, which would collapse every visitor into one bucket', 'convermetry'),
            ],
            'convermetry_tracking_batch_recorded' => [
                'int $stored' => __('Rows that survived deduplication and were written', 'convermetry'),
                'int $accepted' => __('Events that survived sanitization', 'convermetry'),
                'int $offered' => __('Events the batch arrived with. <code>offered ≥ accepted ≥ stored</code>', 'convermetry'),
                '?string $batchId' => __('The client\'s batch id, or <code>null</code> when it sent none', 'convermetry'),
            ],
            'convermetry_tracking_rate_limited' => [
                'int $events' => __('How many events the rejected batch carried', 'convermetry'),
                'int $window' => __('The limiter window in seconds (60)', 'convermetry'),
            ],
            'convermetry_rate_limits' => [
                'array $defaults' => __('<code>[\'per_ip\' => 300, \'site_wide\' => 3000]</code>, in events per minute. Both clamped to a minimum of 1; a non-array return or a missing key falls back to the default. Charged <strong>per event</strong>, so one 20-event batch costs 20', 'convermetry'),
            ],
            'convermetry_source_aliases' => [
                'array $aliases' => __('Raw lowercase <code>utm_source</code> value => canonical name. <strong>Add to the map, do not replace it</strong> — a fresh array loses every default. A source with no entry is stored exactly as submitted', 'convermetry'),
            ],
            'convermetry_channel' => [
                'string $channel' => __('The channel classified at ingestion — <code>direct</code>, <code>organic</code>, <code>paid</code>, <code>social</code>, <code>email</code>, <code>referral</code>, <code>other</code>', 'convermetry'),
                'array $row' => __('The event row being classified, including its campaign columns and referrer', 'convermetry'),
                'string $type' => __('The event type being classified', 'convermetry'),
            ],
            'convermetry_analytics_sections' => [
                'array $sections' => __('<code>AnalyticsSectionInterface</code> adapters, keyed by a namespaced key. Each adds a dashboard panel <strong>and</strong> contributes to <code>analytics.extensions</code> on the wire. A typed registry, never SQL — there is deliberately no way to hand a query fragment or table name to a path that runs unattended on cron. A section that throws is dropped, not propagated', 'convermetry'),
            ],
            'convermetry_analytics_extensions' => [
                'array $extensions' => __('Extension data for this summary, pre-populated from registered sections. Bounded to 32 KB / 50 keys. Computed once per delivery at freeze time — a retry never rebuilds it', 'convermetry'),
                'string $start' => __('Window start, <code>Y-m-d</code>', 'convermetry'),
                'string $end' => __('Window end, <code>Y-m-d</code>', 'convermetry'),
                'int $limit' => __('The effective <code>top_*</code> row limit for this report', 'convermetry'),
            ],
            'convermetry_analytics_periods' => [
                'int[] $periods' => __('Reporting periods in days offered on the dashboard; default <code>[7, 30, 90]</code>. Validated, deduplicated, sorted, and <strong>clamped to the retention window</strong> — a period longer than the data would draw a chart that looks like a traffic collapse', 'convermetry'),
            ],
            'convermetry_analytics_report_failed' => [
                'string $component' => __('Which subsystem was building the report', 'convermetry'),
                'string $reportKey' => __('The specific report that failed', 'convermetry'),
                'string $start' => __('Window start, <code>Y-m-d</code>', 'convermetry'),
                'string $end' => __('Window end, <code>Y-m-d</code>', 'convermetry'),
                'string $error' => __('The exception <strong>class name</strong> — never a message, because a database error message quotes the failing statement', 'convermetry'),
            ],
            'convermetry_analytics_admin_panels' => [
                'string $start' => __('Window start of the dashboard\'s current period, <code>Y-m-d</code>', 'convermetry'),
                'string $end' => __('Window end, <code>Y-m-d</code>', 'convermetry'),
            ],
            'convermetry_should_record_submission' => [
                'bool $should' => __('Whether to record at all. <code>false</code> skips the conversion event, the row, the queue and the notifications — and the visitor still sees success, because returning a failure would make Elementor\'s synchronous mode reject a valid form', 'convermetry'),
                'string $formKey' => __('Provider-qualified form identity, e.g. <code>gravityforms:7</code>', 'convermetry'),
                'string $provider' => __('Provider key, e.g. <code>gravityforms</code>', 'convermetry'),
                'array $fields' => __('Normalized <code>[\'id\', \'label\', \'value\']</code> descriptors, so spam rules can read them. <strong>Contains PII</strong>', 'convermetry'),
            ],
            'convermetry_submission_fields' => [
                'array $fields' => __('Normalized <code>[\'id\', \'label\', \'value\']</code> descriptors. A <strong>changed</strong> result is re-normalized, so <code>cvm_*</code> stays stripped and the descriptor shape holds. <strong>Contains PII</strong>', 'convermetry'),
                'string $formKey' => __('Provider-qualified form identity', 'convermetry'),
                'string $provider' => __('Provider key', 'convermetry'),
            ],
            'convermetry_submission_context_extensions' => [
                'array $extensions' => __('Namespaced data added to the stored analytics context. Attached once before persistence, so every endpoint and every retry sees the same thing. Cannot replace conversion id, session id, attribution, timestamps or form identity', 'convermetry'),
                'string $formKey' => __('Provider-qualified form identity', 'convermetry'),
                'string $provider' => __('Provider key', 'convermetry'),
            ],
            'convermetry_submission_recorded' => [
                '$submissionId' => __('The public submission id (string)', 'convermetry'),
                '$conversionId' => __('The conversion id shared with the tracker\'s own <code>form_success</code> event, so the two can never double-count', 'convermetry'),
                '$context' => __('The stored analytics context — session id, channel, attribution, entrance referrer, landing page, device', 'convermetry'),
            ],
            'convermetry_submission_recorded_details' => [
                'int $rowId' => __('Database row id', 'convermetry'),
                'string $submissionId' => __('The public submission id', 'convermetry'),
                'array $form' => '<code>{provider, form_key, form_name, native_id}</code>',
                'array $fields' => __('The stored field descriptors. <strong>Contains PII</strong> — use <code>convermetry_submission_recorded</code> if you only need to know a submission happened', 'convermetry'),
            ],
            'convermetry_submission_duplicate' => [
                'string $submissionId' => __('The id of the submission already recorded', 'convermetry'),
                'string $conversionId' => __('Its conversion id', 'convermetry'),
                'string $formKey' => __('Provider-qualified form identity', 'convermetry'),
            ],
            'convermetry_submission_delivery_state_changed' => [
                'string $submissionId' => __('The public submission id', 'convermetry'),
                'string $state' => __('The new state — <code>pending</code>, <code>delivered</code>, <code>failed</code>, <code>abandoned</code> or <code>not_configured</code>', 'convermetry'),
                'string $previous' => __('The state it moved from. Fires only on a genuine transition; the state is recomputed several times per delivery and most recomputations are silent', 'convermetry'),
            ],
            'convermetry_submission_deleted' => [
                'int $id' => __('The database row id that was removed', 'convermetry'),
                'string $submissionId' => __('The public submission id. Ids only — the data is what is being erased', 'convermetry'),
            ],
            'convermetry_form_settings_saved' => [
                'string[] $formKeys' => __('The form keys whose settings were written. Fires from the storage layer on a real write, so WP-CLI callers raise it too', 'convermetry'),
            ],
            'convermetry_discovered_forms' => [
                'array $forms' => __('Forms found for this provider. Runs <strong>before</strong> the 5-minute cache is written, and the result is normalized back to <code>{native_id, name}</code> with empty ids dropped and duplicates collapsed', 'convermetry'),
                'string $providerKey' => __('Which provider was asked, e.g. <code>wpforms</code>', 'convermetry'),
            ],
            'convermetry_form_providers' => [
                'FormProviderInterface[] $providers' => __('Adapters keyed by <code>getKey()</code>. Entries that are not instances of the interface are silently discarded, and an adapter reusing a bundled key <strong>replaces</strong> it. <strong>Memoized on first use</strong> — register at plugin load time, not on <code>init</code>', 'convermetry'),
            ],
            'convermetry_submission_csv_columns' => [
                'array $columns' => __('Ordered <code>key => header label</code> map for the export. Paired with the values filter <strong>by key, never by position</strong>, so the two cannot drift apart', 'convermetry'),
            ],
            'convermetry_submission_csv_values' => [
                'array $values' => __('One row as <code>key => value</code>. Values must be scalar or null, and go through the same formula-injection escaping as the core ones. <strong>Contains PII</strong>', 'convermetry'),
                'array $row' => __('The full submission row being exported. Runs per row while streaming — keep it cheap', 'convermetry'),
            ],
            'convermetry_submissions_columns' => [
                'array $columns' => __('Extra cells for this list row, as <code>key => already-escaped HTML</code>. <strong>Printed verbatim — escape it yourself</strong>', 'convermetry'),
                'array $row' => __('The submission row. <strong>Contains PII</strong>', 'convermetry'),
            ],
            'convermetry_submission_detail_sections' => [
                'array $row' => __('The submission being displayed. Runs after the nonce and capability checks; <strong>escape your own output</strong>. <strong>Contains PII</strong>', 'convermetry'),
            ],
            'convermetry_submission_row_actions' => [
                'array $row' => __('The submission whose action bar is rendering. Nonce-protect anything that acts. <strong>Contains PII</strong>', 'convermetry'),
            ],
            'convermetry_form_submission' => [
                'array $formIdentifier' => __('<code>[\'form_name\' => ..., \'form_id\' => ...]</code>. <code>form_id</code> is your own stable identifier for this form', 'convermetry'),
                'array $fields' => __('Either a list of <code>[\'id\', \'label\', \'value\']</code> descriptors (preferred — <code>value</code> may be an array for multi-selects) <strong>or</strong> the historical <code>name => value</code> map', 'convermetry'),
                'array $context = []' => __('Optional. <code>url_query</code> and <code>headers</code> maps merged into this delivery only', 'convermetry'),
            ],
            'convermetry_should_record_goal_completion' => [
                'bool $should' => __('Whether to record this matched completion', 'convermetry'),
                'array $row' => __('The completion row, <strong>for inspection only</strong> — nothing you return changes it. Its completion id, definition hash, event uid, dedupe key and timestamp are identity, and a gate that could rewrite them could silently defeat once-per-session goals', 'convermetry'),
                'array $goal' => __('The goal definition that matched', 'convermetry'),
            ],
            'convermetry_goal_completion' => [
                'array $row' => __('The completion row about to be written — completion id, goal id, definition hash, session id, event uid, dedupe key, value, timestamp', 'convermetry'),
                'array $goal' => __('The goal definition that matched', 'convermetry'),
            ],
            'convermetry_goal_matched' => [
                'int $stored' => __('How many completions were actually written', 'convermetry'),
                'array $rows' => __('The completion rows that were offered', 'convermetry'),
            ],
            'convermetry_goal_completions_recorded' => [
                'int $stored' => __('Rows written after <code>INSERT IGNORE</code> against the dedupe key', 'convermetry'),
                'int $offered' => __('Rows attempted. The two differ whenever a completion was already recorded', 'convermetry'),
                'array $completionIds' => __('The ids <strong>offered</strong>, not the ids stored', 'convermetry'),
            ],
            'convermetry_goal_saved' => [
                'string $goalId' => __('Immutable goal id', 'convermetry'),
                'array $goal' => __('The definition as stored', 'convermetry'),
                '?array $previous' => __('The definition before this write, or <code>null</code> for a new goal', 'convermetry'),
            ],
            'convermetry_goal_deleted' => [
                'string $goalId' => __('Immutable goal id. The deletion is soft: completions and the name survive so historical reports keep working', 'convermetry'),
                'string $now' => __('UTC <code>Y-m-d H:i:s</code> deletion timestamp', 'convermetry'),
            ],
            'convermetry_funnel_saved' => [
                'string $funnelId' => __('Immutable funnel id', 'convermetry'),
                'array $funnel' => __('The definition as stored, including its ordered steps', 'convermetry'),
                '?array $previous' => __('The definition before this write, or <code>null</code> for a new funnel. Editing a funnel changes what every past report says, retroactively', 'convermetry'),
            ],
            'convermetry_funnel_deleted' => [
                'string $funnelId' => __('Immutable funnel id', 'convermetry'),
                'string $now' => __('UTC <code>Y-m-d H:i:s</code> deletion timestamp', 'convermetry'),
            ],
            'convermetry_lead_status_updated' => [
                '$submissionId' => __('The submission whose lead changed (string)', 'convermetry'),
                '$toStatus' => __('The new status — <code>new</code>, <code>contacted</code>, <code>qualified</code>, <code>won</code>, <code>lost</code>', 'convermetry'),
                '$fromStatus' => __('The status it moved from', 'convermetry'),
                '?string $value' => __('Exact decimal <strong>string</strong>, never a float. <code>null</code> means no value — which is not <code>\'0.00\'</code>', 'convermetry'),
                'string $currency' => __('ISO 4217 code, stamped at the time of the change and never converted', 'convermetry'),
            ],
            'convermetry_lead_updated' => [
                'string $submissionId' => __('The submission whose lead changed', 'convermetry'),
                'array $to' => __('<code>{status, value, currency}</code> after the change', 'convermetry'),
                'array $from' => __('<code>{status, value, currency}</code> before it', 'convermetry'),
                'int $userId' => __('Who made the change', 'convermetry'),
                'string $leadEventId' => __('The history row this change wrote. Fires <strong>after</strong> the transaction commits', 'convermetry'),
            ],
            'convermetry_should_queue_notification' => [
                'bool $should' => __('Whether to queue notifications for this submission. Runs after the configured rules already said yes, so it can only narrow', 'convermetry'),
                'string $formKey' => __('Provider-qualified form identity', 'convermetry'),
                'array $identity' => __('Identity columns only — <strong>never field values</strong>', 'convermetry'),
            ],
            'convermetry_notification_recipients' => [
                'array $recipients' => __('Addresses to queue. Each becomes its own row with its own retry chain. Re-validated through <code>sanitize_email()</code>/<code>is_email()</code>, deduplicated, and capped at 20. Runs once at <strong>queue</strong> time', 'convermetry'),
                'string $formKey' => __('Provider-qualified form identity', 'convermetry'),
                'array $identity' => __('Identity columns only', 'convermetry'),
            ],
            'convermetry_notification_message' => [
                'array $message' => __('<code>subject</code>, <code>html</code> and <code>headers</code>. The <strong>recipient is not changeable</strong> — one row is one address, and a per-attempt rewrite could collapse two rows onto one mailbox. Subject gets header-injection stripping and a 200-char cap, the body a 256 KB cap, and the four required headers are reinstated. <strong><code>html</code> contains PII</strong>', 'convermetry'),
                'string $submissionId' => __('The submission being notified about', 'convermetry'),
                'int $attempt' => __('Which attempt this is — the filter runs per attempt', 'convermetry'),
            ],
            'convermetry_notification_queued' => [
                'string $submissionId' => __('The submission being notified about', 'convermetry'),
                'string $recipient' => __('The address this row will mail', 'convermetry'),
                'int $attempt' => __('Attempt number, 1 on first queue', 'convermetry'),
            ],
            'convermetry_notification_before_send' => [
                'string $submissionId' => __('The submission being notified about', 'convermetry'),
                'string $recipient' => __('The address about to be mailed. No subject, no body, no fields', 'convermetry'),
                'int $attempt' => __('Which attempt is about to run', 'convermetry'),
            ],
            'convermetry_notification_accepted' => [
                'string $submissionId' => __('The submission being notified about', 'convermetry'),
                'string $recipient' => __('The address that was mailed', 'convermetry'),
                'int $attempt' => __('Which attempt succeeded. "Accepted", never "delivered" — the local transport took the message, which is not receipt', 'convermetry'),
            ],
            'convermetry_notification_retry_scheduled' => [
                'string $submissionId' => __('The submission being notified about', 'convermetry'),
                'string $recipient' => __('The address that will be retried', 'convermetry'),
                'int $nextAttempt' => __('The attempt number that will run next', 'convermetry'),
                'int $nextAttemptAt' => __('Unix timestamp it becomes due', 'convermetry'),
            ],
            'convermetry_notification_abandoned' => [
                'string $submissionId' => __('The submission that will never be notified about', 'convermetry'),
                'string $recipient' => __('The address that will never be mailed', 'convermetry'),
                'int $attempt' => __('The attempt that exhausted the chain', 'convermetry'),
                'string $error' => __('Why the last attempt failed', 'convermetry'),
            ],
            'convermetry_notification_canceled' => [
                'string $submissionId' => __('The submission whose notifications were dropped', 'convermetry'),
                'string $recipient' => __('The address — <strong>empty on a bulk clear</strong>, which fires one aggregate action rather than reading addresses back purely to emit a hook', 'convermetry'),
                'string $reason' => __('<code>\'expired\'</code>, <code>\'submission_deleted\'</code> or <code>\'admin_clear\'</code>', 'convermetry'),
                'int $count' => __('How many rows were cancelled — 1 per row from the worker, N for a bulk clear', 'convermetry'),
            ],
            'convermetry_notification_retry_schedule' => [
                'int[] $delays' => __('Seconds before each email retry; default <code>[300, 900, 3600]</code>. Entries clamped to a minimum of 60, non-numeric entries discarded, an empty list falls back. Deliberately separate from the webhook schedule, and the hard two-hour TTL sits above it regardless', 'convermetry'),
            ],
            'convermetry_sensitive_keys' => [
                'string[] $patterns' => __('The <strong>effective</strong> list of credential-looking names redacted from the Activity Log and omitted from notification emails — so extend it; a shorter list weakens both surfaces. Matched as substrings of a canonical form (lowercased, non-alphanumeric runs collapsed to <code>_</code>), so <code>API Key</code>, <code>x-api-key</code> and <code>API_KEY</code> all match <code>api_key</code>', 'convermetry'),
            ],
            'convermetry_retention_cleanup_started' => [
                'string $store' => __('Which store is being pruned', 'convermetry'),
                'string $cutoff' => __('UTC <code>Y-m-d H:i:s</code>; rows older than this go. Observational — a listener cannot cancel the pass, change the cutoff, or extend retention', 'convermetry'),
            ],
            'convermetry_retention_cleanup_completed' => [
                'string $store' => __('Which store was pruned', 'convermetry'),
                'string $cutoff' => __('The cutoff that was applied', 'convermetry'),
                'int $deleted' => __('Rows removed in this pass', 'convermetry'),
                'bool $moreRemain' => __('Whether rows past the cutoff are still there. Convermetry schedules any follow-up pass itself', 'convermetry'),
                'string $outcome' => __('<code>completed</code>, <code>truncated</code>, <code>query_failed</code> or <code>lock_lost</code>', 'convermetry'),
            ],
            'convermetry_migration_started' => [
                'string $context' => __('<code>\'cli\'</code>, <code>\'cron\'</code> or <code>\'admin\'</code>. The lease is held while this runs — <strong>do not throw</strong>, or it stays held until it expires. No SQL is passed to any migration hook', 'convermetry'),
            ],
            'convermetry_migration_completed' => [
                'string $context' => __('<code>\'cli\'</code>, <code>\'cron\'</code> or <code>\'admin\'</code>', 'convermetry'),
                'bool $pending' => __('Whether migrations remain. Fires after the lease is released and after the reschedule decision, so this is settled. Pending mid-migration is normal, not an error', 'convermetry'),
            ],
            'convermetry_migration_failed' => [
                'string $context' => __('<code>\'cli\'</code>, <code>\'cron\'</code> or <code>\'admin\'</code>', 'convermetry'),
                'string $error' => __('The exception <strong>class name</strong>. Fires after the lease is released and before the failure continues to the caller. A migration that merely did not land is not a failure', 'convermetry'),
            ],
            'convermetry_storage_error' => [
                'string $subsystem' => __('Which subsystem needed the write', 'convermetry'),
                'string $operation' => __('What it was doing', 'convermetry'),
                'string $code' => __('A stable short code for the failure', 'convermetry'),
                'array $context' => __('Identifying ids and counts. Never SQL, never <code>$wpdb->last_error</code>, never submitted fields, IPs or secrets. Reserved for <em>verified</em> failures: a duplicate <code>INSERT IGNORE</code>, an abandoned notification or a still-pending migration do not fire it', 'convermetry'),
            ],
            'convermetry_settings_saved' => [
                'string $section' => __('<code>general</code>, <code>webhooks</code>, <code>notifications</code>, <code>goals</code> or <code>funnels</code>', 'convermetry'),
                'string[] $changedKeys' => __('<strong>Key names only, never values</strong> — two of these sections hold signing secrets and token-bearing endpoint URLs. Listens on WordPress\'s own option-write hooks, so it fires on a real write only and catches CLI and migration writers too', 'convermetry'),
            ],
            'convermetry_admin_capability' => [
                'string $capability' => __('The capability required, defaulting to <code>manage_options</code>. Must be a non-empty lowercase <code>[a-z0-9_]</code> name; anything else falls back, because <code>current_user_can(\'\')</code> would lock the owner out', 'convermetry'),
                'string $scope' => __('Which surface — <code>analytics.view</code>, <code>submissions.view</code>, <code>submissions.export</code>, <code>submissions.delete</code>, <code>leads.edit</code>, <code>goals.manage</code>, <code>funnels.manage</code>, <code>forms.manage</code>, <code>notifications.manage</code>, <code>webhooks.manage</code>, <code>activity.view</code>, <code>activity.manage</code>, <code>api.manage</code>, <code>settings.manage</code>. Applied to menu visibility <strong>and</strong> every handler behind it', 'convermetry'),
            ],
            'convermetry_delivery_log_api_item' => [
                'array $extensions' => __('Namespaced additions for this REST item, bounded to 4 KB / 10 keys', 'convermetry'),
                'array $item' => __('The item as it will be returned, after endpoint-URL redaction and body decoding. Its core keys are <strong>immutable</strong> — a filter that could rewrite <code>success</code> would let a plugin lie to a monitoring dashboard', 'convermetry'),
            ],
        ];
    }

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
            HomePage::MENU_SLUG,
            __('About Convermetry', 'convermetry'),
            __('About', 'convermetry'),
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

        wp_enqueue_style(
            'cvm-about',
            CVM_PLUGIN_URL . 'assets/css/admin-about.css',
            [AdminAssets::COMMON_HANDLE],
            CVM_VERSION
        );

        wp_enqueue_script(
            'cvm-about',
            CVM_PLUGIN_URL . 'assets/js/about.js',
            ['wp-i18n'],
            CVM_VERSION,
            true
        );
        wp_set_script_translations('cvm-about', 'convermetry');
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
        <nav class="cvm-about-nav" aria-label="<?php esc_attr_e('Documentation sections', 'convermetry'); ?>">
        <div class="cvm-about-nav-inner">
        <a class="cvm-about-nav-top" href="#cvm-about-top"><span aria-hidden="true">&uarr;</span> <?php esc_html_e('Top', 'convermetry'); ?></a>
        <?php

        foreach (self::sections() as $id => $label) {
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
        <h2 class="cvm-about-section-title"><?php echo esc_html(self::sections()[$id] ?? $id); ?></h2>
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
        $args     = self::hookArgs()[$name] ?? [];
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
                aria-controls="<?php echo esc_attr($detailId); ?>"><?php esc_html_e('Learn More', 'convermetry'); ?></button>
        <div class="cvm-about-hook-detail" id="<?php echo esc_attr($detailId); ?>" hidden>
            <?php if ($args !== []) { ?>
            <p class="cvm-about-hook-detail-title"><?php esc_html_e('Arguments', 'convermetry'); ?></p>
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
            <p class="cvm-about-hook-detail-title"><?php esc_html_e('Example', 'convermetry'); ?></p>
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
        <h1 id="cvm-about-top"><?php esc_html_e('About Convermetry', 'convermetry'); ?></h1>
        <p class="cvm-about-intro"><?php
        echo esc_html(sprintf(
            /* translators: %s: plugin version number. */
            __('Convermetry %s — visitor analytics, campaign attribution, and server-confirmed form conversion tracking with reliable webhook delivery. It answers the full funnel question: where a visitor came from, what they did, which form they submitted, what they submitted, which campaign produced the lead, what that lead turned out to be worth, and whether it reached your downstream systems.', 'convermetry'),
            CVM_VERSION
        ));
        ?></p>
        <p class="cvm-about-meta"><?php echo wp_kses_post(__('Everything on this page is also in the plugin\'s <code>README.md</code>. Use the bar below to jump between sections.', 'convermetry')); ?></p>
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

        self::cardStart(__('The question Convermetry answers', 'convermetry'));
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
        <p><?php echo wp_kses_post(__('Convermetry works standalone — a full analytics dashboard, form integrations, lead outcomes, and webhook delivery inside one WordPress install — and is architected so a future Convermetry SaaS can receive <code>analytics_report</code> and <code>form_submission</code> messages from many installations, keyed by a shared, versioned payload schema.', 'convermetry')); ?></p>
        <ul class="cvm-about-requirements">
        <li><span class="cvm-about-label"><?php esc_html_e('Version', 'convermetry'); ?></span> <?php echo esc_html(CVM_VERSION); ?></li>
        <li><span class="cvm-about-label"><?php esc_html_e('WordPress', 'convermetry'); ?></span> 6.3+</li>
        <li><span class="cvm-about-label"><?php esc_html_e('PHP', 'convermetry'); ?></span> 8.3+</li>
        <li><span class="cvm-about-label"><?php esc_html_e('REST namespace', 'convermetry'); ?></span> <code>convermetry/v1</code></li>
        <li><span class="cvm-about-label"><?php esc_html_e('PHP namespace', 'convermetry'); ?></span> <code>Convermetry\</code></li>
        <li><span class="cvm-about-label"><?php esc_html_e('License', 'convermetry'); ?></span> <?php esc_html_e('GPL-2.0-or-later', 'convermetry'); ?></li></ul>
        <?php
        self::cardEnd();

        self::cardStart(__('How the pieces connect', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('A dependency-free frontend tracker records page views, clicks, form attempts, hovers, scroll depth, and confirmed conversions, with last-touch campaign attribution persisted per session (30-minute inactivity window, no cookies). When a visitor submits a form, the tracker injects hidden internal fields — a per-attempt <code>cvm_conversion_id</code> token, the <code>cvm_session_id</code>, and an attribution snapshot — into the form before any AJAX handler serializes it. The server-side form-provider integration reads those fields when the form plugin confirms the submission, strips them from the lead data, records the conversion under the same token, and queues webhook deliveries in the background. Correlation is token-based end to end; timestamps are never used to match a submission to a session.', 'convermetry')); ?></p>
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
        <div class="cvm-about-note"><?php esc_html_e('Nothing in that chain waits on a third party. A form submission is recorded and returned to the visitor before any payload is built or any HTTP request is made — an external webhook outage can never make a valid submission appear to fail.', 'convermetry'); ?></div>
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

        self::cardStart(__('Where everything lives', 'convermetry'));
        self::code('Convermetry
    Home           — what Convermetry does, this installation\'s status, the
                     setup checklist, and links to everything (top-level default)
    Analytics      — the reporting dashboard
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

        self::cardStart(__('Submissions vs. Activity Log', 'convermetry'));
        ?>
        <p><?php esc_html_e('These two are easy to confuse, and they answer different questions. Clearing one never touches the other.', 'convermetry'); ?></p>
        <table class="cvm-about-table"><thead><tr><th scope="col"></th><th scope="col"><?php esc_html_e('Submissions', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Activity Log', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><?php esc_html_e('One row is', 'convermetry'); ?></td><td><?php esc_html_e('one form submission', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('one delivery <em>attempt</em>', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Exists without webhooks', 'convermetry'); ?></td><td><strong><?php esc_html_e('Yes', 'convermetry'); ?></strong></td><td><?php esc_html_e('No', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Shows', 'convermetry'); ?></td><td><?php esc_html_e('the lead, its attribution, its answers, its outcome', 'convermetry'); ?></td><td><?php esc_html_e('the payload sent and the response returned', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Cleared by', 'convermetry'); ?></td><td><?php esc_html_e('Clear All Submissions', 'convermetry'); ?></td><td><?php esc_html_e('Clear All Logs', 'convermetry'); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('<strong>Notifications</strong> is independent of both: its own master switch, its own queue, and it works on a site with no webhook endpoints configured at all.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('The Analytics dashboard', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('For a selectable 7/30/90-day period (UTC calendar days, clamped to the retention window with an explanatory notice when clamped): summary cards, an accessible daily page-view chart (single-Tab-stop keyboard navigation, touch/mouse tooltips, visible axes, data-table fallback), and collapsible sections for Content, Engagement, Acquisition, Devices, Conversions, Goals, Lead outcomes, and Recent Activity. A <strong>Print / Save as PDF</strong> button produces a print-optimized report. Empty states and per-section database-error notices are explicit — a failed query is never rendered as a silent zero.', 'convermetry')); ?></p>
        <p><?php esc_html_e('Three form metrics are deliberately kept distinct, because merging them would hide which evidence each rests on:', 'convermetry'); ?></p>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<strong>Form Submit Attempts</strong> — frontend <code>submit</code> events; success unconfirmed.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Confirmed Conversions</strong> — unique conversions deduplicated by <code>conversion_id</code> across both detection paths.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Server-Confirmed Submissions</strong> — submissions a form plugin\'s own server-side success hook confirmed. Where a provider integration exists, this signal is authoritative.', 'convermetry')); ?></li></ul>
        <?php
        self::cardEnd();

        self::cardStart(__('Who can see what', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('Every Convermetry screen resolves its required capability through one named scope, and the scope is applied to <strong>menu visibility and every handler behind it</strong> — never to the menu alone, which would hide a screen while leaving its POST handler reachable. All fourteen default to <code>manage_options</code>, so nothing changes until you filter one.', 'convermetry')); ?></p>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Scope', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Covers', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><code>analytics.view</code></td><td><?php esc_html_e('The dashboard and this About page.', 'convermetry'); ?></td></tr>
        <tr><td><code>submissions.view</code></td><td><?php esc_html_e('The Submissions list and detail panels.', 'convermetry'); ?></td></tr>
        <tr><td><code>submissions.export</code></td><td><?php echo wp_kses_post(__('CSV export — <strong>every lead\'s name and email in one file</strong>. Grant deliberately.', 'convermetry')); ?></td></tr>
        <tr><td><code>submissions.delete</code></td><td><?php esc_html_e('Deleting one submission, or clearing them all.', 'convermetry'); ?></td></tr>
        <tr><td><code>leads.edit</code></td><td><?php esc_html_e('Setting lead status and value.', 'convermetry'); ?></td></tr>
        <tr><td><code>goals.manage</code> · <code>funnels.manage</code></td><td><?php esc_html_e('Creating and editing goal and funnel definitions.', 'convermetry'); ?></td></tr>
        <tr><td><code>forms.manage</code></td><td><?php esc_html_e('Per-form configuration and exclusions.', 'convermetry'); ?></td></tr>
        <tr><td><code>notifications.manage</code> · <code>webhooks.manage</code></td><td><?php esc_html_e('Notification and endpoint settings — both hold credentials.', 'convermetry'); ?></td></tr>
        <tr><td><code>activity.view</code> · <code>activity.manage</code></td><td><?php esc_html_e('Reading the Activity Log, versus clearing it and managing its API key.', 'convermetry'); ?></td></tr>
        <tr><td><code>api.manage</code></td><td><?php esc_html_e('The delivery-log REST API and its key.', 'convermetry'); ?></td></tr>
        <tr><td><code>settings.manage</code></td><td><?php esc_html_e('Identity, tracking, privacy, and retention.', 'convermetry'); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('Remap one with the <code>convermetry_admin_capability</code> filter. It must return a non-empty lowercase <code>[a-z0-9_]</code> capability name; anything else falls back to the default, because <code>current_user_can(\'\')</code> would lock the owner out of their own site.', 'convermetry')); ?></p>
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

        self::cardStart(__('The tracker', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('A single dependency-free script is enqueued deferred on frontend pages — never for logged-in users while exclusion is on, which is the default. It batches events and delivers them to <code>POST /wp-json/convermetry/v1/track</code>.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Tracked event types</strong>, each individually toggleable under <strong>Settings → Tracking</strong>:', 'convermetry')); ?></p>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Type', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Records', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><code>pageview</code></td><td><?php esc_html_e('One per page view, with the session\'s attribution snapshot.', 'convermetry'); ?></td></tr>
        <tr><td><code>click</code></td><td><?php esc_html_e('Clicked element label/tag and destination.', 'convermetry'); ?></td></tr>
        <tr><td><code>form_view</code></td><td><?php esc_html_e('A form scrolled into view. Fires once per visible form per page view.', 'convermetry'); ?></td></tr>
        <tr><td><code>form_start</code></td><td><?php esc_html_e('Someone began filling a form in.', 'convermetry'); ?></td></tr>
        <tr><td><code>form_error</code></td><td><?php echo wp_kses_post(__('A native browser validation failure — field id, field type, and which <code>ValidityState</code> flag failed. <strong>Never the value typed.</strong>', 'convermetry')); ?></td></tr>
        <tr><td><code>form_submit</code></td><td><?php esc_html_e('A submit press. Success unconfirmed.', 'convermetry'); ?></td></tr>
        <tr><td><code>form_success</code></td><td><?php echo wp_kses_post(__('A confirmed conversion, carrying the <code>conversion_id</code> in <code>event_value</code>.', 'convermetry')); ?></td></tr>
        <tr><td><code>hover</code></td><td><?php echo wp_kses_post(__('Configurable dwell time; opt-in per element via <code>data-cvm-hover</code>.', 'convermetry')); ?></td></tr>
        <tr><td><code>scroll_depth</code></td><td><?php esc_html_e('50% and 100% milestones.', 'convermetry'); ?></td></tr>
        <tr><td><code>custom_event</code></td><td><?php echo wp_kses_post(__('A named event from <code>Convermetry.track()</code>, kept only when a goal matches its name.', 'convermetry')); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('<strong>Delivery reliability.</strong> Batches flush every 5 seconds, at 20 events, and on page exit via <code>navigator.sendBeacon</code>. Every batch is persisted to a bounded <code>sessionStorage</code> store <em>before</em> it is sent and removed only on server acknowledgment. Failed sends back off exponentially with jitter; a 429 pauses the whole tab and honors <code>Retry-After</code>. Delivery is <strong>at-least-once</strong> and replays are <strong>idempotent</strong> — rows are stored under a unique (batch id, event ordinal) key, so a replayed batch never inflates counts.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Endpoint defenses.</strong> Whitelisted, currently-enabled event types only; tracked page URLs must be <code>http(s)</code> on this site\'s host and are canonicalized to scheme + host + path; foreign <code>Origin</code>/<code>Referer</code> rejected; bots and empty user agents ignored; DNT/GPC enforced server-side when enabled; request bodies and batch sizes capped; scalar-only field values, sanitized and truncated; rate limits charged <strong>per event</strong> — 300 per IP per minute plus 3,000 site-wide — via atomic object-cache counters, falling back (and failing <strong>closed</strong>) to an atomic single-statement database counter. The per-IP check runs first, so a flooding IP never consumes the site-wide budget.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Sessions are cookie-free.</strong> The id lives in <code>localStorage</code> and rotates after 30 minutes of inactivity.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('Campaign and channel attribution', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('All six UTM parameters (<code>utm_source</code>, <code>utm_medium</code>, <code>utm_campaign</code>, <code>utm_id</code>, <code>utm_term</code>, <code>utm_content</code>) are captured from tagged landing URLs. Ad-click identifiers (<code>gclid</code>, <code>gbraid</code>, <code>wbraid</code>, <code>fbclid</code>, <code>msclkid</code>, <code>ttclid</code>, <code>twclid</code>, <code>li_fat_id</code>) are recognized — only the parameter <strong>name</strong> is stored, never the value — and imply source/medium when no UTM tags are present.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('Attribution is <strong>last-touch within the session</strong>: the most recent tagged landing attributes the visit from that point on, and the snapshot rides on <em>every</em> event in the session. Untagged acquisition persists too — the session\'s entrance referrer travels alongside (with an explicit marker for verified direct entrances), so organic, social and referral visits keep their channel across internal navigation.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Channels</strong> — every attributed event is classified at ingestion into: Paid Search, Paid Social, Organic Search, Organic Social, Email, Display, Affiliate, SMS, Referral, Direct, Other. There is exactly <strong>one</strong> attribution engine: the dashboard, the analytics payloads, every goal completion, and every form submission\'s <code>analytics_context.channel</code> classify through the same code, so they cannot disagree. Source aliases are normalized (<code>convermetry_source_aliases</code>) and the result is overridable per event (<code>convermetry_channel</code>).', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('Session → submission → conversion correlation', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('The link between analytics and leads is <strong>token-based — never timestamps</strong>.', 'convermetry')); ?></p>
        <ol class="cvm-about-list">
        <li><?php echo wp_kses_post(__('On page load, and again at submit time in the capture phase <em>before</em> any AJAX handler serializes the form, the tracker injects three hidden fields: <code>cvm_conversion_id</code> (a fresh token per submission attempt), <code>cvm_session_id</code>, and <code>cvm_context</code> (a compact JSON snapshot of attribution, entrance referrer, landing page, and page URL).', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('The form plugin processes the submission normally. When its <strong>server-side success hook</strong> fires, Convermetry\'s adapter extracts and strictly validates those fields — every transport shape is handled, including Fluent Forms\' serialized <code>data</code> blob — and <strong>strips every <code>cvm_*</code> field</strong> from the lead data.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('The confirmed conversion is recorded as a <code>form_success</code> analytics event under that same token, together with a durable submission row.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('The tracker\'s own frontend success listeners reuse the <strong>same token</strong>, so whichever paths fire, every report deduplicates them into <strong>one</strong> conversion.', 'convermetry')); ?></li></ol>
        <p><?php echo wp_kses_post(__('AJAX forms are fully supported. When the fields are absent — tracker disabled, privacy signals honored, JavaScript blocked, server-to-server submissions — the conversion id is generated on the server and the submission still records and delivers, just with an empty <code>analytics_context</code>. No cookies are used at any point.', 'convermetry')); ?></p>
        <div class="cvm-about-note"><?php echo wp_kses_post(__('<strong>Duplicate protection at every layer:</strong> a double-fired provider callback hits the <code>UNIQUE conversion_id</code> index and records nothing twice; queue rows are unique per (submission, endpoint); reports count <code>DISTINCT conversion_id</code>; receivers deduplicate by <code>delivery_id</code>.', 'convermetry')); ?></div>
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

        self::cardStart(__('Four things beyond the conversion count', 'convermetry'));
        ?>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<strong>Goals</strong> count important actions that are not form submissions: a phone number tapped, a PDF opened, a booking link followed, a pricing page reached.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Funnels</strong> measure the ordered path to a conversion — how many sessions reached each step and how many were lost between them.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Form engagement</strong> reports views, starts, attempts, successes and abandonment per form, plus which fields fail validation most often.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Lead status and value</strong> record what a submission turned out to be worth, so campaign reporting can be measured against outcomes rather than treating every conversion as equal.', 'convermetry')); ?></li></ul>
        <?php
        self::cardEnd();

        self::cardStart(__('Goals', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('<strong>A confirmed form submission is not a goal.</strong> A submission is <em>server-confirmed</em> — the form plugin\'s own success hook said so. A goal completion is a <em>browser-observed</em> signal. They are stored in different tables and counted separately on purpose: folding submissions into goals would quietly downgrade the plugin\'s most trustworthy number to the standard of its least.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Matching happens on the server</strong>, at ingestion, against data the tracker already sends. The browser is never told what your goals are. Three consequences:', 'convermetry')); ?></p>
        <ul class="cvm-about-features">
        <li><?php esc_html_e('Your list of valuable actions is competitive information and stays on the server.', 'convermetry'); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Phone and email goals need no configuration at all.</strong> The tracker already reports click destinations and keeps <code>tel:</code> and <code>mailto:</code> URLs whole while stripping query strings from everything else — pick "on a phone number link" and you are done. No CSS selector required.', 'convermetry')); ?></li>
        <li><?php esc_html_e('A visitor cannot manufacture a conversion by claiming one. They can only report the same raw activity any visitor reports; the server decides what it means.', 'convermetry'); ?></li></ul>
        <p><?php echo wp_kses_post(__('The one exception is a <strong>CSS selector</strong>, which genuinely cannot be evaluated without the DOM. Only those selectors are sent to the tracker, and the goal ids it reports back are re-validated against your enabled selector goals before anything is recorded.', 'convermetry')); ?></p>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Goal type', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Rules', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><?php esc_html_e('Reaching a page', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('is exactly · contains · starts with · ends with. A path (<code>/thank-you/</code>) matches the URL\'s path; a full URL matches the whole URL. Trailing slashes are forgiven and matching is case-insensitive.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('A click', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('on a phone number link · on an email link · that leaves this site · where the link contains / is exactly · matching a CSS selector. A phone tap is deliberately <strong>not</strong> also counted as an external link.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('A custom event', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('Matched by name, fired from your own code with <code>Convermetry.track(\'name\')</code>.', 'convermetry')); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('<strong>Counting.</strong> Each goal counts either <em>once per visit</em> or <em>every occurrence</em>, and deduplication is enforced by a <strong>UNIQUE database constraint</strong> rather than a PHP check — so an at-least-once replay of a tracker batch collides with the original instead of double-counting.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Editing.</strong> A goal keeps its id forever. Editing its <em>matching rule</em> starts a new measurement series (and reports say the definition changed) so two different questions are never blended into one line. Renaming, pausing, or repricing a goal resets nothing. Removing one is a soft delete: past completions are kept and still appear, correctly labelled, in reports for earlier periods. Goals count from when you create them and are never applied retroactively.', 'convermetry')); ?></p>
        <div class="cvm-about-note"><?php echo wp_kses_post(__('Goals do <strong>not</strong> override the tracking toggles they depend on — a click goal cannot fire while click tracking is off. Silently re-enabling tracking you switched off would be the wrong fix, so the Goals screen names the specific setting and links to it instead.', 'convermetry')); ?></div>
        <?php
        self::cardEnd();

        self::cardStart(__('Funnels', 'convermetry'));
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
        <p><?php echo wp_kses_post(__('Step types: <strong>visited a page</strong>, <strong>completed a goal</strong>, <strong>saw a form</strong>, <strong>started filling a form</strong>, <strong>attempted to submit</strong>, and <strong>submission confirmed by the form plugin</strong>. A form step with no specific form counts any form on the site.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Ordering is real.</strong> Steps must occur in sequence — a session that reached step three without step two is not counted at step three. Each step is constrained to occur strictly after the previous one, because the naive approach gets this wrong in a way that looks right on small data:', 'convermetry')); ?></p>
        <?php
        self::code('Session did:  B at 09:00,  A at 10:00,  B at 11:00
A → B funnel: SHOULD succeed (they did A, then B)
Earliest-occurrence comparison: MIN(B)=09:00 < A, so it reports failure.');
        ?>
        <p><?php echo wp_kses_post(__('Ordering uses the event id rather than the timestamp: the events table\'s <code>created_at</code> is the moment the row was <em>inserted</em>, so the two are the same order by construction and the id is the finer, tie-free version of it. A consequence worth knowing: funnel order is <em>ingestion</em> order. Within one batch the browser\'s order is preserved; a batch that failed and was resent from a later page sorts by when it arrived.', 'convermetry')); ?></p>
        <p><?php esc_html_e('Sessions with no session id are excluded — an empty session id is not one visitor, it is every visitor whose session could not be established, and grouping on it would produce one enormous pseudo-session that appears to complete every funnel.', 'convermetry'); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Cohorts.</strong> A funnel is the set of sessions that reached step 1 during the selected period. Later steps are counted for up to <strong>24 hours past the end of the period</strong>, so a session that entered at 23:55 on the last day is not unfairly cut off. Each funnel\'s result is cached for five minutes, keyed by its definition, so editing a step invalidates the cache automatically. Limits: 8 steps per funnel, 20 funnels per site.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('Form engagement & abandonment', 'convermetry'));
        ?>
        <p><?php esc_html_e('Mixing units here would make every rate meaningless and the mix would be invisible, so each column states its unit and its evidence:', 'convermetry'); ?></p>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Column', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Unit', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Evidence', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><?php esc_html_e('Views', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<strong>sessions</strong> in which the form scrolled into view', 'convermetry')); ?></td><td><?php esc_html_e('browser-observed', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Started', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<strong>sessions</strong> in which someone began filling it in', 'convermetry')); ?></td><td><?php esc_html_e('browser-observed', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Attempts', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<strong>raw submit presses</strong> — one visitor fighting a validation error produces several, which is the point', 'convermetry')); ?></td><td><?php esc_html_e('browser-observed', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Successful', 'convermetry'); ?></td><td><strong><?php esc_html_e('distinct conversion ids', 'convermetry'); ?></strong></td><td><strong><?php esc_html_e('server-confirmed', 'convermetry'); ?></strong></td></tr>
        <tr><td><?php esc_html_e('Abandoned', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<strong>sessions</strong> that started and did not succeed', 'convermetry')); ?></td><td><?php esc_html_e('browser-observed', 'convermetry'); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('A <strong>completion rate above 100% is not an error.</strong> It means confirmed submissions outnumbered observed starts — which happens when visitors submit with JavaScript blocked. The browser-observed columns are undercounting, and clamping the number to 100 would hide the one figure that tells you so.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Abandonment has a grace period.</strong> A form started ninety seconds ago is being filled in, not abandoned. A start counts as abandoned only after <strong>30 minutes</strong> pass with no confirmed submission; anything more recent shows as <em>still in progress</em>. Without that, abandonment would spike toward 100% for the most recent hour of any window and then decay — an artifact that looks exactly like a real problem. The 30 minutes matches the tracker\'s session idle window.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Friction points.</strong> Where a provider uses native browser validation, Convermetry records which field failed and why:', 'convermetry')); ?></p>
        <?php
        self::code('Most common friction points

Field            Type     Problem                                  Errors
phone            tel      Left empty                                  218
desired-service  select   Left empty                                  164
email            email    Wrong format (e.g. not an email address)    131');
        ?>
        <div class="cvm-about-note"><?php echo wp_kses_post(__('<strong>No value a visitor typed is ever recorded.</strong> A validation event is rebuilt on the server from exactly three whitelisted pieces — the field\'s developer-chosen id, its type, and which <code>ValidityState</code> flag failed — and every other key in the request is discarded <em>by construction</em> rather than by a blocklist. Field ids are character-restricted and truncated to 64 characters, so an implementation that mistakenly sent a typed value would be stripped to something unrecognizable rather than quietly stored.', 'convermetry')); ?></div>
        <p><?php echo wp_kses_post(__('<strong>Elementor is excluded from form-level engagement.</strong> It identifies a form by its display <em>name</em> on the server while exposing a widget <em>id</em> in the browser, so the two cannot be matched reliably — and an engagement figure attributed to the wrong form is worse than none. Elementor submissions are recorded, attributed, delivered and reported normally everywhere else. The other six providers are fully supported.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('Lead status & value', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('Set both on the <strong>Submissions</strong> detail panel; both are filterable in the list.', 'convermetry')); ?></p>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Status', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Meaning', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><code>new</code></td><td><?php esc_html_e('Not yet assessed — the default for every submission.', 'convermetry'); ?></td></tr>
        <tr><td><code>qualified</code></td><td><?php esc_html_e('A real, well-matched lead.', 'convermetry'); ?></td></tr>
        <tr><td><code>unqualified</code></td><td><?php esc_html_e('A genuine person who was not a fit.', 'convermetry'); ?></td></tr>
        <tr><td><code>won</code></td><td><?php esc_html_e('Converted into business.', 'convermetry'); ?></td></tr>
        <tr><td><code>lost</code></td><td><?php esc_html_e('A real lead that did not convert.', 'convermetry'); ?></td></tr>
        <tr><td><code>spam</code></td><td><?php esc_html_e('Never a lead at all.', 'convermetry'); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('<strong>This is deliberately not a CRM.</strong> Six statuses; no assignees, pipeline stages, follow-up dates, or activity notes. Every one of those would be a worse version of a tool you already have, and none changes the answer to <em>"which marketing produced valuable leads?"</em>', 'convermetry')); ?></p>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<strong><code>won</code> counts as qualified.</strong> A lead that converted was self-evidently qualified, and requiring it to pass through <code>qualified</code> first would under-report every site that records the final outcome in one step.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Only <code>spam</code> leaves the denominator.</strong> An unqualified or lost lead was still a lead your marketing produced. Excluding those would make a channel look better the more poor-quality leads it sent — which is exactly why <code>spam</code> is separate from <code>unqualified</code>.', 'convermetry')); ?></li></ul>
        <p><?php echo wp_kses_post(__('<strong>Value and currency.</strong> Values are stored as exact <code>DECIMAL(13,2)</code> and handled as decimal <strong>strings</strong> end to end — never floating point. A lead worth 0.10 recorded ten thousand times totals exactly 1000.00. Input is forgiving about presentation and strict about value: <code>$12,500.00</code>, <code>12 500</code>, <code>&euro;1.234,56</code> and <code>1234.56 USD</code> all parse; <code>12abc</code> is <strong>rejected</strong> rather than silently read as 12. The site currency is <strong>stamped onto each lead</strong> when you first record a value, so changing the setting later never rewrites what is already recorded — and reports group by currency and <strong>never sum across codes</strong>.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>History.</strong> Every status or value change records who made it and when. The change and its history row are written in a single transaction, so a lead can never end up in a state its history disagrees with.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Reporting.</strong> <em>Analytics → Lead outcomes</em> breaks leads down by channel, campaign, landing page, and form: Lead Qualification Rate = (qualified + won) ÷ total; Lead-to-Win Rate = won ÷ total; Attributed Lead Value; Attributed Revenue (the same, restricted to <code>won</code>). <strong>Nothing is called ROI or ROAS</strong> — both are ratios against ad <em>spend</em>, Convermetry has no cost data, and a "return" computed without the investment half is not a weaker version of the metric, it is a different number wearing its name. <strong>Time to lead</strong> is measured from the first page view of the session that converted, and reported as medians rather than averages, because the distribution is heavily right-skewed.', 'convermetry')); ?></p>
        <div class="cvm-about-note"><?php echo wp_kses_post(__('Lead status and value are recorded <strong>locally only</strong> in this version. A form payload is frozen when it is first delivered and scheduled analytics windows never revisit, so a lead field on either could only ever report “new” — wrong for every lead you qualify, and a field that lies is worse than an absent one. Use the <code>convermetry_lead_status_updated</code> action to push outcomes to your own systems today. Goal completions <em>do</em> travel, in the analytics report payload, because a completion either happened in the window or it did not.', 'convermetry')); ?></div>
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

        self::cardStart(__('Supported form providers', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('Providers are feature-detected — nothing breaks when a plugin is absent, and activation never fatals on a site with no form plugin at all — and their forms are discovered automatically. Detected forms are <strong>included by default</strong>, so a new form needs no setup; exclusions and per-form configuration live on the <strong>Forms</strong> page.', 'convermetry')); ?></p>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Provider', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Server-side hook and notes', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><?php esc_html_e('Elementor Pro', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<code>elementor_pro/forms/new_record</code>. Per-form settings key by the widget id, falling back to the legacy form <strong>name</strong> key until the next save.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Elementor Pro — Atomic Forms', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('The <code>convermetry</code> action, registered on <code>elementor_pro/atomic_forms/actions/register</code>. <strong>Opt in per form</strong>: nothing is captured until <em>Convermetry</em> is added under <em>Actions after submit</em> in the Elementor editor. Settings key by <code>&lt;document id&gt;:&lt;element id&gt;</code>.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Bricks Builder', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('The <code>convermetry</code> action, dispatched on <code>bricks/form/action/convermetry</code> (Bricks 1.12.2+). <strong>Opt in per form</strong>: nothing is captured until <em>Convermetry</em> is ticked under <em>Actions after successful form submit</em> in Bricks. Settings key by the form <strong>element id</strong>. Native Bricks Form element only.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Gravity Forms', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<code>gform_after_submission</code>, via public APIs.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('WPForms', 'convermetry'); ?></td><td><code>wpforms_process_complete</code>.</td></tr>
        <tr><td><?php esc_html_e('Contact Form 7', 'convermetry'); ?></td><td><code>wpcf7_mail_sent</code>.</td></tr>
        <tr><td><?php esc_html_e('Fluent Forms', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<code>fluentform/submission_inserted</code>, plus the legacy alias, guarded against the double fire.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Ninja Forms', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<code>ninja_forms_after_submission</code>. Admin form previews are skipped, and multi-instance form ids are normalized to the numeric form id.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Formidable Forms', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<code>frm_after_create_entry</code> at priority 30. Repeater/embedded child entries and saved drafts are skipped.', 'convermetry')); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('Per-form settings key by the provider\'s own most stable form identity. <strong>Two providers are opt in per form</strong> — Elementor Atomic and Bricks Builder both run an explicit list of actions after submit, so a form of theirs captures nothing until the Convermetry action is selected in that builder\'s editor. Convermetry never edits saved builder content to add it, and cannot tell from the outside which forms have it; the <strong>Forms</strong> page says so beside those rows. Custom forms integrate through the <a href="#developer">public API</a>, and third-party adapters register with the <a href="#hook-convermetry_form_providers"><code>convermetry_form_providers</code></a> filter.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('Per-form configuration', 'convermetry'));
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Setting', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Meaning', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><?php esc_html_e('Native Form ID', 'convermetry'); ?></td><td><?php esc_html_e('The provider\'s own identity (read-only).', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Custom/External Form ID', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('Sent as <code>form_id</code> in payloads; the native id is the fallback.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Enabled / Excluded', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('Detected forms are included by default. Exclusion stops processing; configuration is <strong>preserved</strong> while excluded.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Include page URL query parameters', 'convermetry'); ?></td><td><?php esc_html_e('Per-form override of the global setting.', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('URL Query Parameters', 'convermetry'); ?></td><td><?php esc_html_e('Per-form parameters, appended to outbound webhook URLs.', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Request Headers', 'convermetry'); ?></td><td><?php esc_html_e('Per-form headers, added to outbound requests.', 'convermetry'); ?></td></tr></tbody></table>
        <p><?php esc_html_e('Merge precedence for query parameters, later overriding earlier for shared keys:', 'convermetry'); ?></p>
        <?php
        self::code('Global URL parameters → Page URL parameters → Per-form parameters → Runtime parameters

Headers: Content-Type → global → per-form → runtime
         (User-Agent, Idempotency-Key and X-Convermetry-Signature added at send time)');
        self::cardEnd();

        self::cardStart(__('Submissions', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('<strong>Convermetry → Submissions</strong> lists every server-confirmed lead with its date, form, provider, channel and campaign, delivery status, lead status and value. Filters cover date range, provider, form, channel, campaign, delivery state, lead status, and free-text search; the detail panel is loaded on demand and shows the submitted answers, the full analytics context, the delivery outcome per endpoint, and the lead\'s status history. CSV export streams in keyset-paginated chunks, so even a very large table exports in bounded memory.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('Deleting a submission also cancels anything still queued for it — webhook queue rows and email notifications alike — and cascades its lead history away, firing <code>convermetry_submission_deleted</code> once everything attached to it is gone.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('Every submission — bundled provider or custom API — passes the same two extension points before anything is written: <a href="#hook-convermetry_should_record_submission"><code>convermetry_should_record_submission</code></a> can veto the whole write (the visitor still sees success), and <a href="#hook-convermetry_submission_fields"><code>convermetry_submission_fields</code></a> sees the normalized descriptors, with any change re-normalized so the <code>cvm_*</code> strip and the descriptor shape hold.', 'convermetry')); ?></p>
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
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Identifier', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Identifies', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Scope', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><code>submission_id</code></td><td><?php esc_html_e('The form submission itself.', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<strong>Global</strong> — identical in every delivery of that submission, to every endpoint. Deduplicate by it when aggregating the same lead arriving via multiple endpoints.', 'convermetry')); ?></td></tr>
        <tr><td><code>conversion_id</code></td><td><?php esc_html_e('The analytics conversion joined to the submission, and its session.', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('Shared between the frontend <code>form_success</code> event and the server-confirmed record, so the two detection paths can never double-count. Every Convermetry conversion report deduplicates by it.', 'convermetry')); ?></td></tr>
        <tr><td><code>delivery_id</code></td><td><?php esc_html_e('One outbound webhook delivery.', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<strong>Endpoint-specific</strong>; stable across every retry; echoed as the <code>Idempotency-Key</code> header. <strong>Receivers deduplicate by this alone.</strong>', 'convermetry')); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('Supporting identifiers: a batch id plus each event\'s ordinal make tracker ingestion idempotent, <code>session_id</code> groups one visit, <code>completion_id</code> identifies one goal completion, and <code>lead_event_id</code> identifies one lead status change.', 'convermetry')); ?></p>
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

        self::cardStart(__('The two outbound message types', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('Convermetry sends two kinds of webhook message. Every endpoint on the <strong>Webhooks</strong> page chooses which it receives, and the two are fully independent — an endpoint may take either one on its own, or both.', 'convermetry')); ?></p>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<strong>Analytics Reports</strong> — <code>message_type: analytics_report</code>. Scheduled, <em>aggregated</em> reporting for a time window, sent on the site-wide schedule you pick: hourly, twice daily, daily, or weekly. This is <em>not</em> one webhook per page view or click — an entire window is summarized into a single delivery. Each endpoint tracks its own delivery window, so a payload covers the time since <em>that</em> endpoint\'s last successful delivery, and a newly added endpoint can optionally be backfilled with the retained history. <strong>Send analytics test</strong> delivers one on demand.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Form Submissions</strong> — <code>message_type: form_submission</code>. One message per server-confirmed lead, delivered immediately through the background form-delivery queue instead of on a schedule. <strong>Send form test</strong> delivers one on demand.', 'convermetry')); ?></li></ul>
        <?php
        self::code('Convermetry SaaS            Analytics ✓   Form Submissions ✓
HubSpot Middleware          Analytics ✗   Form Submissions ✓   (leads only)
Reporting Data Warehouse    Analytics ✓   Form Submissions ✗   (analytics only)');
        ?>
        <p><?php echo wp_kses_post(__('<strong>For an analytics-only endpoint</strong>, check <strong>Analytics Reports</strong> and leave <strong>Form Submissions</strong> unchecked — no submitted form field values are ever sent to that endpoint. Analytics reports do still describe individual conversions (<code>conversions.recent[]</code>: conversion id, form name and ids, provider, and the visitor\'s IP when IP storage is on), so "analytics-only" means no field values, not no lead identifiers. The reverse works the same way. Both message types appear in the <strong>Activity Log</strong>, where you can tell them apart by their <code>message_type</code>.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Internal email notifications are a third, separate path</strong> with its own master switch and its own queue — see <a href="#notifications">Notifications</a>. Notification sends do not appear in the Activity Log, which covers webhook deliveries only.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('Endpoint configuration', 'convermetry'));
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Field', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Purpose', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><?php esc_html_e('Webhook URL', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('HTTPS required. The <code>convermetry_allow_insecure_webhooks</code> filter permits <code>http://</code> for development.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Label', 'convermetry'); ?></td><td><?php esc_html_e('Optional; badges Activity Log entries and identifies endpoints in the REST API.', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Signing Secret', 'convermetry'); ?></td><td><?php esc_html_e('Optional per-endpoint HMAC key; overrides the shared secret for this endpoint only, so one receiver never learns the key that signs payloads for others.', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Delivery Types', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<strong>Analytics Reports</strong> and/or <strong>Form Submissions</strong>.', 'convermetry')); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('All requests go through one transport: <code>wp_safe_remote_post()</code> with the URL re-validated at request time (SSRF protection even if DNS changed after saving), redirects disabled, response downloads capped at 64 KB at the transport layer, and a 15-second timeout.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('Delivery, retries & idempotency', 'convermetry'));
        ?>
        <p><?php esc_html_e('Both message types share one delivery pipeline — an Analytics Report is frozen per reporting window, a Form Submission per submission, and from there the guarantees are identical.', 'convermetry'); ?></p>
        <?php
        self::code('Initial delivery → 5 min → 30 min → 2 h → 6 h → 16 h    (~24.6 h total)');
        ?>
        <p><?php echo wp_kses_post(__('<strong>Frozen requests.</strong> On the first attempt the final URL (all query-parameter layers merged), the configured headers, and the serialized JSON body are frozen and replayed byte-for-byte under the same <code>delivery_id</code>. A configuration change after a failure never mutates them, and endpoints that already acknowledged a delivery are never re-sent.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Three headers are regenerated per attempt</strong> from that frozen body: <code>Idempotency-Key</code> (always the same delivery id), <code>User-Agent</code> (carries the plugin version, so it changes if the site updates mid-chain), and <code>X-Convermetry-Signature</code>, computed with the secret <em>current at send time</em> — so rotating a secret changes a retry\'s signature, intentionally, so a rotated key still verifies.', 'convermetry')); ?></p>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<strong>Analytics reports</strong> retry through per-endpoint single-event crons. An exhausted chain — or one whose cron could not be scheduled, detected as <em>orphaned</em> — keeps its frozen delivery; the next scheduled dispatch re-sends it under the original id first, and only after acknowledgment does the endpoint\'s marker advance, exactly to the frozen window\'s end, so consecutive deliveries never overlap. Dispatch runs under a site-wide mutex (MySQL named lock, with a lease-based fallback), and each site\'s schedule is anchored at a stable random offset so fleets sharing one endpoint never stampede it.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Form submissions</strong> use one queue row per submission × endpoint. Rows are claimed atomically by a token-stamped conditional <code>UPDATE</code>, so overlapping workers cannot double-send, and rows stranded by a dead worker are reclaimed after 10 minutes. Acknowledged endpoints are deleted from the queue and never re-sent when a sibling endpoint fails.', 'convermetry')); ?></li></ul>
        <p><?php echo wp_kses_post(__('<strong>Conversion delivery inside Analytics Reports is lossless</strong> — a window holding more than 100 individual conversions is split into consecutive deliveries rather than truncated. Each <code>top_*</code> list holds up to 200 rows.', 'convermetry')); ?></p>
        <div class="cvm-about-note"><?php echo wp_kses_post(__('<strong>Delivery is at-least-once.</strong> Any duplicate a receiver can ever see carries a <code>delivery_id</code> it has already processed — deduplicating by <code>delivery_id</code> is sufficient to never double-process.', 'convermetry')); ?></div>
        <p><?php echo wp_kses_post(__('Every stage is observable and most are customizable: the URL, headers, timeout and payload are composed through filters that run <strong>once, before the freeze</strong> (<code>convermetry_webhook_query_args</code>, <code>convermetry_webhook_headers</code>, <code>convermetry_webhook_timeout</code>, <code>convermetry_webhook_payload</code>), and the lifecycle — queued, frozen, about to send, attempted, logged, succeeded, retry scheduled, chain exhausted, abandoned, canceled — is reported by ten actions that all carry the same credential-free context. See <a href="#hooks">Hooks</a>.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('HMAC signatures', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('When a signing secret is configured — shared, or per-endpoint, where the endpoint\'s own secret wins — every request carries the HMAC-SHA256 of the <strong>raw JSON body bytes</strong>:', 'convermetry')); ?></p>
        <?php
        self::code('X-Convermetry-Signature: sha256=<hex>');
        self::code('$expected = \'sha256=\' . hash_hmac(\'sha256\', $rawBody, $secret);
if (!hash_equals($expected, $_SERVER[\'HTTP_X_CONVERMETRY_SIGNATURE\'] ?? \'\')) {
    http_response_code(401);
    exit;
}');
        ?>
        <p><?php echo wp_kses_post(__('Verify by recomputing over the exact received bytes and comparing with a constant-time function. Note that the Activity Log stores a <em>redacted</em> representation of the request, not a byte-exact copy — so a stored body will not reproduce its signature. Verify against what your endpoint received, never against a log copy.', 'convermetry')); ?></p>
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

        self::cardStart(__('The shared envelope', 'convermetry'));
        ?>
        <p><?php esc_html_e('Every outbound message carries the same envelope, whatever its type.', 'convermetry'); ?></p>
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
        <p><?php echo wp_kses_post(__('The two message types version <strong>independently</strong>: analytics reports are at <code>1.1</code> (<code>1.0</code> plus an additive <code>analytics.goals</code> section), and form submissions at <code>2.0</code> — except rows recorded before the field-descriptor change, which keep emitting <code>1.0</code> forever. <strong>Branch on <code>schema_version</code>, never on <code>plugin_version</code>.</strong>', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('Analytics report payload (excerpt)', 'convermetry'));
        ?>
        <p><?php esc_html_e('Reporting data for one time window — aggregates plus the individual conversions that occurred in it. No other lead data is included.', 'convermetry'); ?></p>
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
        <li><?php echo wp_kses_post(__('<code>period</code> is the UTC window the report covers — <code>start</code> inclusive, <code>end</code> exclusive.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('The <code>analytics</code> section comes from the same reporting query layer the dashboard uses, so a payload and the admin screens cannot disagree.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<code>analytics.conversions.recent</code> lists the <em>individual</em> conversions inside the window — each with the visitor\'s <code>ip_address</code> when IP storage is on — while every other section is aggregate reporting data. <code>total</code> is deduplicated by conversion id, and <code>server_confirmed</code> counts the stored server-confirmed submissions.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('Deduplicate received deliveries by <code>delivery_id</code> (echoed as the <code>Idempotency-Key</code> header) — never by <code>period</code>.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('An <strong>analytics test</strong> covers the last 7 days, carries <code>"test": true</code>, is never retried, and does not advance the endpoint\'s normal delivery marker — so testing an endpoint never creates a gap in its scheduled reporting.', 'convermetry')); ?></li></ul>
        <?php
        self::cardEnd();

        self::cardStart(__('Form submission payload (excerpt)', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('One lead\'s own data plus the analytics context correlated to it. An Analytics Report is the mirror image: reporting data for a window, with no submitted field values — though its <code>conversions.recent[]</code> does identify individual conversions. <code>ip_address</code> is the submitter\'s address, captured during the visitor\'s own request and frozen with the record; it is always present, and empty when disabled in Settings or when no valid address could be determined.', 'convermetry')); ?></p>
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

        self::cardStart(__('submission_data: schema 2.0', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('<code>submission_data</code> is an <strong>ordered list of field descriptors</strong>, not an object. Match on <code>id</code>; show <code>label</code>.', 'convermetry')); ?></p>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Key', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Type', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Notes', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><code>id</code></td><td><?php esc_html_e('string', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('The provider-native field ID or key. Stable across renames. <strong>Never empty</strong> — an entry without one is dropped.', 'convermetry')); ?></td></tr>
        <tr><td><code>label</code></td><td><?php esc_html_e('string', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('The human-readable label captured at submission time. <strong>Falls back to <code>id</code></strong> when the provider exposes no reliable label.', 'convermetry')); ?></td></tr>
        <tr><td><code>value</code></td><td><?php esc_html_e('string | string[]', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('A sanitized string, or a list of sanitized strings for multi-value fields. <strong>Never a nested object.</strong>', 'convermetry')); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('<strong>Why a list.</strong> The pre-2.0 format was a <code>label =&gt; value</code> object, which forced every provider to discard either the stable ID (Gravity Forms, WPForms, Ninja Forms and Formidable key by label) or the human label (Elementor keys by ID). It also <strong>silently merged two fields that shared a label</strong> — two fields both called "Name" became one. A list preserves provider order, preserves duplicates, and keeps the ID for automation alongside the label for humans.', 'convermetry')); ?></p>
        <p><?php esc_html_e('Label availability differs by provider, and Convermetry does not guess:', 'convermetry'); ?></p>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Provider', 'convermetry'); ?></th><th scope="col"><code>id</code></th><th scope="col"><code>label</code></th></tr></thead><tbody>
        <tr><td><?php esc_html_e('Elementor', 'convermetry'); ?></td><td><?php esc_html_e('field ID', 'convermetry'); ?></td><td><?php esc_html_e('the field\'s title', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Elementor Atomic', 'convermetry'); ?></td><td><?php esc_html_e('field (element) ID', 'convermetry'); ?></td><td><?php esc_html_e('the field\'s editor label, else the ID', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Bricks Builder', 'convermetry'); ?></td><td><?php esc_html_e('field ID', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('the field label, else the ID. Only fields Bricks <em>defines</em> are mapped, and <strong>password</strong> fields are dropped outright — Bricks field IDs are opaque, so the field type is the only thing that can tell a credential from a comment', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Gravity Forms', 'convermetry'); ?></td><td><?php esc_html_e('field ID', 'convermetry'); ?></td><td><?php esc_html_e('the field label', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('WPForms', 'convermetry'); ?></td><td><?php esc_html_e('field ID', 'convermetry'); ?></td><td><?php esc_html_e('the field name', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Ninja Forms', 'convermetry'); ?></td><td><?php esc_html_e('field ID (or key)', 'convermetry'); ?></td><td><?php esc_html_e('the field label, else its key', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Formidable Forms', 'convermetry'); ?></td><td><?php esc_html_e('field ID', 'convermetry'); ?></td><td><?php esc_html_e('the field name, else its key', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Contact Form 7', 'convermetry'); ?></td><td><?php esc_html_e('posted field name', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<strong>same as <code>id</code></strong> — CF7 exposes no reliable label without parsing form markup', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Fluent Forms', 'convermetry'); ?></td><td><?php esc_html_e('submitted key', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<strong>same as <code>id</code></strong> — labels live in an internal JSON blob, not a public API', 'convermetry')); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('Convermetry\'s own correlation fields (<code>cvm_conversion_id</code>, <code>cvm_session_id</code>, <code>cvm_context</code>) are stripped before storage and never appear here.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Migrating from schema 1.0.</strong> Historical rows are <strong>never</strong> rewritten, in the database or on the wire — otherwise one <code>submission_id</code> could arrive in two different shapes, and a frozen retry could deliver a <code>1.0</code> body long after the upgrade. Branch on <code>schema_version</code>:', 'convermetry')); ?></p>
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
        <p><?php echo wp_kses_post(__('The <strong>Send form test</strong> button on the Webhooks page sends schema <code>2.0</code>, so you can verify a receiver against the current format before real leads arrive.', 'convermetry')); ?></p>
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

        self::cardStart(__('Internal email alerts', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('<strong>Convermetry → Notifications</strong> emails a chosen internal address when a form submission is recorded, enriched with the attribution Convermetry already captured for that visitor. It is <strong>off by default</strong> and has its own master switch — it works with no webhook endpoints configured, and disabling webhooks does not disable it. These are <strong>internal</strong> notifications: Convermetry never emails the person who submitted the form, and visitor autoresponders are out of scope.', 'convermetry')); ?></p>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<strong>Email creates a copy of lead data outside Convermetry\'s controls.</strong> Deleting a submission — or letting retention expire it — cancels anything still queued and guarantees no queued message can be rendered afterwards, because the queue stores no lead data of its own. It <strong>cannot recall a message already sent</strong>. If you are relying on Convermetry\'s retention window for a compliance story, enabling this changes that story.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Your form plugin probably already emails you.</strong> These are in addition, not a replacement.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>"Sent" means handed to your mail system.</strong> Convermetry uses <code>wp_mail()</code>; a <code>true</code> return means the local transport <em>accepted</em> the message. Nothing in the plugin claims a notification was "delivered" — that word is reserved for webhooks, where a receiver actually returned 2xx.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('Convermetry stores <strong>no mail credentials</strong> and implements no SMTP transport of its own. Any SMTP plugin you already run keeps working unchanged.', 'convermetry')); ?></li></ul>
        <?php
        self::cardEnd();

        self::cardStart(__('Settings', 'convermetry'));
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Setting', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Default', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Notes', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><?php esc_html_e('Enable notifications', 'convermetry'); ?></td><td><strong><?php esc_html_e('Off', 'convermetry'); ?></strong></td><td><?php esc_html_e('Master switch.', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Recipients', 'convermetry'); ?></td><td><?php esc_html_e('none', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('One address per line. Validated, deduplicated case-insensitively, capped at 20. Each recipient gets a <strong>separate message</strong>, so nobody sees the rest of the list. Never derived from submitted data.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Subject', 'convermetry'); ?></td><td><code>New {form_name} submission on {site_name}</code></td><td><?php esc_html_e('Token allowlist below.', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Scope', 'convermetry'); ?></td><td><?php esc_html_e('Every form', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<em>Every form</em> notifies unless a form is switched off; <em>Only selected forms</em> notifies only forms switched on. Per-form rules are inherit / always / never.', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Submitted fields', 'convermetry'); ?></td><td><?php esc_html_e('On', 'convermetry'); ?></td><td><?php esc_html_e('The visitor\'s answers, as label/value rows.', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Analytics summary', 'convermetry'); ?></td><td><?php esc_html_e('On', 'convermetry'); ?></td><td><?php esc_html_e('Channel, UTM source/medium/campaign, landing page, conversion page, device, pages viewed, session start.', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Visitor journey', 'convermetry'); ?></td><td><strong><?php esc_html_e('Off', 'convermetry'); ?></strong></td><td><?php esc_html_e('The pages this visitor viewed — browsing history for an identifiable person.', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('IP address', 'convermetry'); ?></td><td><strong><?php esc_html_e('Off', 'convermetry'); ?></strong></td><td><?php esc_html_e('Personal data in the EU/UK; only available when IP storage is on in Settings.', 'convermetry'); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('Subject tokens are a fixed allowlist, substituted literally — there is no expression language and no PHP evaluation: <code>{site_name}</code>, <code>{form_name}</code>, <code>{provider}</code>, <code>{channel}</code>, <code>{submission_id}</code>, <code>{form_id}</code>, <code>{campaign}</code>, <code>{date}</code>. Anything else stays literal text, and CR/LF and NUL are stripped <em>after</em> substitution, so a form named <code>Contact\\r\\nBcc: …</code> cannot inject a mail header.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('What is never emailed, and how sending works', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('Fields whose ID <strong>or</strong> label looks credential-bearing — passwords, tokens, API keys, secrets, authorization values — are <strong>omitted entirely</strong>, even with <em>Submitted fields</em> on. They are not shown as <code>[REDACTED]</code>: a placeholder would tell every recipient that a secret exists. This is the same policy as Activity Log redaction, so <code>convermetry_sensitive_keys</code> extends both at once. Convermetry\'s <code>cvm_*</code> fields never appear either.', 'convermetry')); ?></p>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('Notifications are <strong>queued, never sent during the visitor\'s request</strong>. No <code>wp_mail()</code>, payload build, or analytics query happens while they wait.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('One queue row per <strong>(submission, recipient)</strong>, unique — a double-fired submission cannot produce two emails to one address, and one failing address does not re-mail the others.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('The queue stores a recipient, a settings snapshot, and scheduling state — <strong>never the rendered email or the lead\'s answers</strong>. The submission is fetched fresh at send time, which is what makes deletion effective.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('Settings are <strong>snapshotted when the lead arrives</strong>. Turning the master switch off stops new notifications but does not pause queued ones — there is an explicit <strong>Discard queued notifications</strong> button for that.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('Retries are bounded and short: 5 min, 15 min, 1 h, then the row is abandoned and a wp-admin warning appears. Every row also carries a hard <strong>two-hour time-to-live</strong>, so a notification that could not be sent inside it is dropped rather than delivered days late as though it just arrived.', 'convermetry')); ?></li>
        <li><?php esc_html_e('Only a short failure reason is retained. The rendered body and the submitted values are never logged, and notification sends do not appear in the Activity Log.', 'convermetry'); ?></li></ul>
        <p><?php echo wp_kses_post(__('<strong>Send test email</strong> builds its message entirely from fabricated data — a <code>Convermetry Test Form</code>, <code>test@example.com</code>, and the RFC 5737 documentation address <code>203.0.113.42</code>. It never loads a real submission, so testing cannot expose a lead.', 'convermetry')); ?></p>
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

        self::cardStart(__('Custom form integration — two entry points', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('Any form Convermetry has no bundled provider for — a hand-rolled <code>&lt;form&gt;</code>, a headless front end, a booking widget, a server-to-server lead post — goes through one of two public entry points. Both run the <strong>same pipeline</strong>; they differ only in who handles a failed delivery.', 'convermetry')); ?></p>
        <table class="cvm-about-table"><thead><tr><th scope="col"></th><th scope="col"><code>convermetry_form_submission</code></th><th scope="col"><code>convermetry_submit_form()</code></th></tr></thead><tbody>
        <tr><td><?php esc_html_e('Semantics', 'convermetry'); ?></td><td><?php esc_html_e('Fire-and-forget', 'convermetry'); ?></td><td><?php esc_html_e('Result-aware', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Delivery', 'convermetry'); ?></td><td><?php esc_html_e('Queued, sent by the background worker', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<strong>Synchronous</strong>, inside your request', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Retries', 'convermetry'); ?></td><td><?php esc_html_e('Automatic — the full webhook retry chain', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('<strong>None</strong> — failures are handed back to you', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Returns', 'convermetry'); ?></td><td><?php esc_html_e('Nothing', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('A readonly <code>SubmissionResult</code>', 'convermetry')); ?></td></tr>
        <tr><td><?php esc_html_e('Use when', 'convermetry'); ?></td><td><?php esc_html_e('A visitor is waiting, and reliability matters more than immediacy', 'convermetry'); ?></td><td><?php esc_html_e('You must know the outcome before responding', 'convermetry'); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('<strong>Prefer the action.</strong> Synchronous delivery puts every configured endpoint\'s latency inside your request, and a failed synchronous delivery is <em>yours</em> to retry — Convermetry deliberately does not queue it behind your back, because that would deliver the same lead twice to a caller that already retried.', 'convermetry')); ?></p>
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

        self::cardStart(__('The form identifier', 'convermetry'));
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Key', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Required', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Meaning', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><code>form_name</code></td><td><strong><?php esc_html_e('Yes', 'convermetry'); ?></strong></td><td><?php echo wp_kses_post(__('The human name of the form. Travels as <code>form_submission.form_name</code>, titles notification emails, and labels the Submissions list. An empty <code>form_name</code> is rejected outright.', 'convermetry')); ?></td></tr>
        <tr><td><code>form_id</code></td><td><?php esc_html_e('No', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('Your own stable identifier. Travels as <code>native_form_id</code>, and as <code>form_id</code> unless a Custom/External Form ID is set for it on the Forms page.', 'convermetry')); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('Per-form settings — exclusion, URL parameters, headers, notification rules — key these submissions as <code>custom:&lt;form_id&gt;</code> when you pass a <code>form_id</code>, and <code>custom:&lt;form_name&gt;</code> when you do not. Passing a stable <code>form_id</code> is therefore what lets you rename the form later without resetting its configuration.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('Submission fields — id, label, value', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('<strong>Two shapes are accepted, and both are fully supported.</strong> The richer descriptor list is preferred; the historical map is not deprecated.', 'convermetry')); ?></p>
        <p class="cvm-about-subheading"><?php esc_html_e('(a) Descriptor list — preferred', 'convermetry'); ?></p>
        <p><?php echo wp_kses_post(__('An ordered list of <code>{id, label, value}</code> arrays, matching the <a href="#payloads"><code>submission_data</code> schema 2.0</a> wire format one-for-one:', 'convermetry')); ?></p>
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
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Key', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Required', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Rules', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><code>id</code></td><td><strong><?php esc_html_e('Yes', 'convermetry'); ?></strong></td><td><?php echo wp_kses_post(__('The field\'s stable, machine-readable identifier — what a receiver should match on. Passed through <code>sanitize_text_field()</code>. An entry whose <code>id</code> is empty after sanitizing is <strong>dropped</strong>, as is any <code>id</code> beginning with <code>cvm_</code> in any letter case.', 'convermetry')); ?></td></tr>
        <tr><td><code>label</code></td><td><?php esc_html_e('No', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('The human-readable label — what a person reads in the Submissions panel, a CSV export, or a notification email. Sanitized the same way, and <strong>falls back to <code>id</code></strong> when missing, blank, or not a scalar.', 'convermetry')); ?></td></tr>
        <tr><td><code>value</code></td><td><?php esc_html_e('No', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('A scalar (cast to string) or a <strong>list of scalars</strong>, each sanitized. Arrays are reindexed with <code>array_values()</code>, so a multi-select\'s own keys are not part of the contract. Anything non-scalar — an object, a nested array — becomes an empty string rather than nested data. A missing <code>value</code> is an empty string.', 'convermetry')); ?></td></tr></tbody></table>
        <p class="cvm-about-subheading"><?php esc_html_e('(b) name =&gt; value map — the long-standing shape', 'convermetry'); ?></p>
        <p><?php echo wp_kses_post(__('Every key becomes both the field\'s <code>id</code> <strong>and</strong> its <code>label</code>:', 'convermetry')); ?></p>
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
        <p><?php esc_html_e('That is the only difference between the two shapes: the map cannot express a label distinct from the id. Use it when you have no separate label to give; reach for the descriptor list the moment you do.', 'convermetry'); ?></p>
        <div class="cvm-about-note"><?php echo wp_kses_post(__('<strong>Shape detection is strict, and deliberately so.</strong> An array is treated as a descriptor list only when it is list-keyed <em>and every entry</em> is an array carrying a scalar <code>id</code>. One entry that fails sends the whole array down the map path, where nothing is lost — a permissive test that sniffed only the first entry would misread a map whose values happen to be arrays with an <code>id</code> key and silently discard your data.', 'convermetry')); ?></div>
        <p class="cvm-about-subheading"><?php esc_html_e('Rules that apply to both shapes', 'convermetry'); ?></p>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<strong><code>cvm_*</code> keys are always stripped</strong>, from either shape, in any letter case. Convermetry\'s correlation fields never reach storage, payloads, exports, emails, or the Activity Log.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Duplicate labels are preserved as separate fields.</strong> Nothing keys or deduplicates by label; two fields both labelled "Name" stay two fields. This is the whole reason the wire format is a list.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Order is preserved</strong> exactly as you passed it.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>An empty field list is valid</strong> — it records a submission with an empty schema 2.0 list, not a legacy-shaped payload.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('Values are <strong>sanitized, never validated</strong>. Convermetry is not your form\'s validator; it records what the form accepted.', 'convermetry')); ?></li></ul>
        <?php
        self::cardEnd();

        self::cardStart(__('Runtime parameters and the result object', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('Both entry points accept extra query parameters and headers for <strong>this submission only</strong>. They are scalar maps (non-scalar values are dropped) and sit at the end of the merge precedence chain, so they win over everything configured in wp-admin. The action takes them as one <code>$context</code> array; the function takes them as two arguments.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<code>convermetry_submit_form()</code> returns a readonly <code>Convermetry\\Forms\\SubmissionResult</code>:', 'convermetry')); ?></p>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Property', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Type', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Meaning', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><code>ok</code></td><td><?php esc_html_e('bool', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('True when the submission was recorded <strong>and</strong> every attempted delivery succeeded (or was queued).', 'convermetry')); ?></td></tr>
        <tr><td><code>submissionId</code></td><td><?php esc_html_e('string', 'convermetry'); ?></td><td><?php esc_html_e('The globally unique submission id; empty when nothing was recorded.', 'convermetry'); ?></td></tr>
        <tr><td><code>conversionId</code></td><td><?php esc_html_e('string', 'convermetry'); ?></td><td><?php esc_html_e('The conversion id shared with analytics; empty when nothing was recorded.', 'convermetry'); ?></td></tr>
        <tr><td><code>status</code></td><td><?php esc_html_e('int', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('HTTP status of the <strong>last</strong> synchronous delivery. <code>0</code> for early exits, transport errors, and queued deliveries.', 'convermetry')); ?></td></tr>
        <tr><td><code>msg</code></td><td><?php esc_html_e('string', 'convermetry'); ?></td><td><?php esc_html_e('User-facing failure description; empty on success.', 'convermetry'); ?></td></tr>
        <tr><td><code>data</code></td><td><?php esc_html_e('mixed', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('The last endpoint\'s response body — JSON-decoded when valid JSON, raw string otherwise; <code>null</code> for early exits and background deliveries. Diagnostic, not for public display.', 'convermetry')); ?></td></tr>
        <tr><td><code>queued</code></td><td><?php esc_html_e('bool', 'convermetry'); ?></td><td><?php esc_html_e('True when deliveries were queued rather than sent inline.', 'convermetry'); ?></td></tr>
        <tr><td><code>failedDeliveries</code></td><td><?php esc_html_e('array', 'convermetry'); ?></td><td><?php echo wp_kses_post(__('One entry per endpoint whose <strong>synchronous</strong> dispatch failed — <code>url</code>, <code>endpoint_url</code>, <code>headers</code>, <code>body</code>, <code>label</code>: the exact request that was sent, so you can implement your own retry. Always empty for early exits and queued deliveries, whose retries Convermetry owns.', 'convermetry')); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('<code>ok === false</code> covers three genuinely different situations, distinguishable by the other fields: nothing was recorded (<code>submissionId</code> empty — a missing <code>form_name</code>, an excluded form, an insert failure), or it was recorded and some delivery failed (<code>failedDeliveries</code> non-empty), or it was recorded with nothing to deliver.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('What both paths do', 'convermetry'));
        ?>
        <ol class="cvm-about-list">
        <li><?php echo wp_kses_post(__('<strong>Per-form settings are honored.</strong> An excluded <code>custom:…</code> form records nothing and reports why.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Correlation fields are read from the current request</strong>, so a submission posted from a page the tracker ran on carries the visitor\'s real session, channel, campaign, entrance referrer, and landing page. When they are absent, a conversion id is generated server-side and the submission still records and delivers, with an empty <code>analytics_context</code>.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>A <code>form_success</code> analytics event is recorded</strong> under the same conversion token, so the dashboard\'s conversion count includes it exactly once.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>The submission row is written</strong>, with the denormalized channel, campaign and landing-page columns the Submissions filters and lead reports use, and the submitter\'s IP when IP storage is on.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong><code>convermetry_submission_recorded</code> fires</strong> — notifications queue here, and listeners run even with no webhook endpoints configured.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Delivery</strong>: queued for the background worker (action), or dispatched synchronously to every form endpoint (function).', 'convermetry')); ?></li></ol>
        <p><?php echo wp_kses_post(__('Duplicate protection is the same as for bundled providers: a repeated <code>cvm_conversion_id</code> hits the <code>UNIQUE conversion_id</code> index, and the second call reports success <strong>without recording or delivering anything twice</strong>.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('Extending Convermetry — the extensions buckets', 'convermetry'));
        ?>
        <p><?php echo wp_kses_post(__('Five surfaces accept <strong>namespaced extension data</strong> from other plugins. Each is a filter that starts empty, and <strong>nothing appears until something fills it</strong> — with no callbacks registered, no <code>extensions</code> property exists anywhere.', 'convermetry')); ?></p>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Surface', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Filter', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Budget', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><?php esc_html_e('Outbound webhook payloads', 'convermetry'); ?></td><td><code>convermetry_webhook_payload_extensions</code></td><td><?php esc_html_e('32 KB · 50 keys', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('Analytics summaries (dashboard + payload)', 'convermetry'); ?></td><td><code>convermetry_analytics_extensions</code></td><td><?php esc_html_e('32 KB · 50 keys', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('A submission\'s stored analytics context', 'convermetry'); ?></td><td><code>convermetry_submission_context_extensions</code></td><td><?php esc_html_e('8 KB · 20 keys', 'convermetry'); ?></td></tr>
        <tr><td><code>window.ConvermetryConfig</code></td><td><code>convermetry_tracker_config_extensions</code></td><td><?php esc_html_e('8 KB · 20 keys', 'convermetry'); ?></td></tr>
        <tr><td><?php esc_html_e('One delivery-log REST item', 'convermetry'); ?></td><td><code>convermetry_delivery_log_api_item</code></td><td><?php esc_html_e('4 KB · 10 keys', 'convermetry'); ?></td></tr></tbody></table>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<strong>Keys must be namespaced</strong> as <code>vendor/thing</code>, so two plugins writing to the same payload cannot collide.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('Values must be <strong>JSON primitives</strong> — no objects, no resources, bounded depth. Anything over budget is dropped rather than truncated into invalid data.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Core keys are never replaceable.</strong> A filter cannot rewrite a conversion id, a session id, attribution, timestamps, form identity, or a REST item\'s <code>success</code> flag — a plugin that could would be able to lie to a monitoring dashboard.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('The tracker bucket is inlined into <strong>every page view and is public</strong>. Never put a key, a token, or anything visitor-specific there.', 'convermetry')); ?></li></ul>
        <p class="cvm-about-subheading"><?php esc_html_e('A dashboard panel that also travels on the wire', 'convermetry'); ?></p>
        <p><?php echo wp_kses_post(__('Register an <code>AnalyticsSectionInterface</code> adapter and it contributes both a panel on the Analytics screen and an entry in <code>analytics.extensions</code> — from one implementation, so the screen and the payload cannot disagree.', 'convermetry')); ?></p>
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
        <p><?php echo wp_kses_post(__('It is a <strong>typed registry, never SQL</strong>: there is deliberately no way to pass a query fragment or a table name to a path that runs unattended on cron. A section that throws is dropped and reported through <code>convermetry_analytics_report_failed</code> rather than taking the report down with it.', 'convermetry')); ?></p>
        <p class="cvm-about-subheading"><?php esc_html_e('Admin surfaces', 'convermetry'); ?></p>
        <p><?php echo wp_kses_post(__('Actions exist to render extra panels on the dashboard (<code>convermetry_analytics_admin_panels</code>), extra blocks and buttons on a submission (<code>convermetry_submission_detail_sections</code>, <code>convermetry_submission_row_actions</code>), extra content on the Forms screen (<code>convermetry_forms_admin_sections</code>), and filters to add list columns and CSV columns (<code>convermetry_submissions_columns</code>, <code>convermetry_submission_csv_columns</code> / <code>_values</code>). They run after this screen\'s capability check — but <strong>your callback must escape its own output</strong>, and CSV values go through the same formula-injection escaping as core ones.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('Helper functions and the browser API', 'convermetry'));
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Call', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Purpose', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><code>convermetry_submit_form()</code></td><td><?php esc_html_e('Result-aware, synchronous custom-form submission — see above.', 'convermetry'); ?></td></tr>
        <tr><td><code>cvm_track_event($type, $data)</code></td><td><?php echo wp_kses_post(__('Records a custom server-side analytics event. <code>$type</code> is at most 20 characters of lowercase letters, digits, dashes and underscores; recognized <code>$data</code> keys are the event row\'s own columns and unknown keys are ignored. A <code>form_success</code> event <strong>requires</strong> <code>event_value</code> to be a unique conversion id (8–100 chars of <code>A-Za-z0-9_.:-</code>), so conversion dedup stays consistent.', 'convermetry')); ?></td></tr>
        <tr><td><code>Convermetry.track(name, { value })</code></td><td><?php echo wp_kses_post(__('Reports a named custom event that <a href="#conversions">goals</a> can match. Only the name — and a numeric <code>value</code> where the matching goal accepts one — is transmitted; an event matching no configured goal is discarded and never stored.', 'convermetry')); ?></td></tr>
        <tr><td><?php echo wp_kses_post(__('<code>convermetry:conversion</code> DOM event', 'convermetry')); ?></td><td><?php echo wp_kses_post(__('The pre-existing custom frontend conversion event, unchanged. Pass a <code>conversion_id</code> in its detail to correlate it with a server-side record.', 'convermetry')); ?></td></tr></tbody></table>
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

        self::cardStart(__('How the hook API behaves', 'convermetry'));
        ?>
        <p><?php
        $hookCount = count(self::hooks());
        echo wp_kses_post(sprintf(
            /* translators: %d: number of public hooks. */
            _n(
                'Convermetry exposes a public hook API for plugins and code snippets — <strong>%d hook</strong> in all. Two rules hold across every one of them.',
                'Convermetry exposes a public hook API for plugins and code snippets — <strong>%d hooks</strong> in all. Two rules hold across every one of them.',
                $hookCount,
                'convermetry'
            ),
            $hookCount
        ));
        ?></p>
        <p><?php echo wp_kses_post(__('Each entry below lists its name, type, purpose and signature. <strong>Learn More</strong> expands what every argument actually holds — including the keys of the array ones — plus a runnable example you can paste into an mu-plugin.', 'convermetry')); ?></p>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<strong>Nothing registered means nothing changes.</strong> With no callbacks, payload bytes, request URLs and headers, delivery ids, signatures, retry schedules, analytics results, admin HTML, REST output, CSV files, and tracker configuration are all exactly what they were. No <code>extensions</code> property appears anywhere until something fills it.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Filters that customize data may see that data; observers may not.</strong> A filter whose job is to change an email body necessarily sees the email body. The observational actions deliberately carry ids, counts, and outcomes — never submitted fields, rendered emails, request or response bodies, signing secrets, credential-bearing URLs, or raw IP addresses. Where an argument does carry personal data, its entry says so.', 'convermetry')); ?></li></ul>
        <p class="cvm-about-subheading"><?php esc_html_e('Three kinds of hook', 'convermetry'); ?></p>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<strong>Decision filters</strong> (<code>convermetry_should_*</code>) answer one yes/no question. The data is passed for inspection and nothing you return from them changes it — a gate that could also rewrite a dedupe key or a completion id would be able to silently defeat the guarantees built on them.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Composition filters</strong> shape data on its way out: payloads, URLs, headers, fields, recipients, columns. They run <strong>once per logical delivery, before the request is frozen</strong> — a retry re-sends frozen bytes and re-runs none of them, so a callback added mid-chain cannot reach a delivery already in flight.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Observational actions</strong> report what happened, after it is durably true. They never fire speculatively: a retry action fires once the next attempt is persisted, a success action once the bookkeeping committed.', 'convermetry')); ?></li></ul>
        <div class="cvm-about-note"><?php echo wp_kses_post(__('<strong>Where to register them.</strong> A theme\'s <code>functions.php</code> loads after <code>plugins_loaded</code>, which is late for the ingestion path. Put anything that must be in place for <em>every</em> request — <code>convermetry_client_ip</code>, <code>convermetry_stored_ip</code>, <code>convermetry_allowed_hosts</code>, <code>convermetry_rate_limits</code>, <code>convermetry_tracked_event</code>, <code>convermetry_should_track_event</code> — in an <strong>mu-plugin</strong>, or in a plugin file that registers at load time. Three filters are <strong>memoized per request</strong> and run only on their first use: <code>convermetry_client_ip</code>, <code>convermetry_allowed_hosts</code> and <code>convermetry_form_providers</code>; registering those later has no effect for the rest of the request. And <strong>do not throw from a callback</strong> — several run while a lease is held or immediately before a network request, where an exception costs the work the hook was announcing.', 'convermetry')); ?></div>
        <?php
        self::cardEnd();

        $grouped = [];
        foreach (self::hooks() as $hook) {
            $grouped[$hook[3]][] = $hook;
        }

        foreach (self::hookGroups() as $group => [$groupLabel, $blurb]) {
            $hooks = $grouped[$group] ?? [];

            self::cardStart(sprintf(
                /* translators: 1: hook group name, 2: number of hooks in the group. */
                _n('%1$s — %2$d hook', '%1$s — %2$d hooks', count($hooks), 'convermetry'),
                $groupLabel,
                count($hooks)
            ));

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

        self::cardStart(__('Worked examples', 'convermetry'));
        ?>
        <p class="cvm-about-subheading"><?php esc_html_e('Submit a custom form', 'convermetry'); ?></p>
        <p><?php echo wp_kses_post(__('Fire-and-forget, with background delivery and automatic retries. <code>$fields</code> takes either a list of <code>[\'id\', \'label\', \'value\']</code> descriptors or the historical <code>name =&gt; value</code> map — see <a href="#developer">Developer API</a>.', 'convermetry')); ?></p>
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
        <p class="cvm-about-subheading"><?php esc_html_e('Add data to every outbound webhook payload', 'convermetry'); ?></p>
        <p><?php echo wp_kses_post(__('Runs before the payload is frozen, so retries re-send it unchanged. Keys must be namespaced <code>vendor/thing</code>, and an empty result adds no property at all.', 'convermetry')); ?></p>
        <?php
        self::code("add_filter('convermetry_webhook_payload_extensions', function (array \$extensions, string \$messageType, array \$meta): array {
    if (\$messageType === 'form_submission') {
        \$extensions['acme/crm'] = ['tenant' => get_option('acme_tenant_id'), 'source' => 'wordpress'];
    }

    return \$extensions;
}, 10, 3);");

        ?>
        <p class="cvm-about-subheading"><?php esc_html_e('Add a header to one endpoint only', 'convermetry'); ?></p>
        <p><?php echo wp_kses_post(__('The context identifies the endpoint without exposing its URL. A callback may not touch the protocol headers — <code>Content-Type</code>, <code>Host</code>, <code>Content-Length</code>, <code>Transfer-Encoding</code>, <code>Connection</code>, <code>User-Agent</code>, <code>Idempotency-Key</code>, <code>X-Convermetry-Signature</code> — which are restored to their pre-filter state.', 'convermetry')); ?></p>
        <?php
        self::code("add_filter('convermetry_webhook_headers', function (array \$headers, array \$context): array {
    if (\$context['endpoint_origin'] === 'https://hooks.acme.test') {
        \$headers['X-Acme-Tenant'] = get_option('acme_tenant_id');
    }

    return \$headers;
}, 10, 2);");

        ?>
        <p class="cvm-about-subheading"><?php esc_html_e('Skip recording a submission', 'convermetry'); ?></p>
        <p><?php echo wp_kses_post(__('Runs after normalization, so spam rules can read the fields, and before <strong>any</strong> write — the conversion event, the row, the queue, and the notifications are all skipped. The visitor still sees success: returning a failure would make Elementor\'s synchronous mode reject a valid form.', 'convermetry')); ?></p>
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
        <p class="cvm-about-subheading"><?php esc_html_e('Pseudonymize the stored IP address', 'convermetry'); ?></p>
        <p><?php echo wp_kses_post(__('<code>convermetry_stored_ip</code> runs after the privacy gates, on the address about to be persisted. It deliberately does not affect the rate-limit identity, which would collapse every visitor into one bucket.', 'convermetry')); ?></p>
        <?php
        self::code("add_filter('convermetry_stored_ip', function (string \$ip): string {
    // Keep the network, drop the host: still useful for spam review, no longer an identifier.
    return filter_var(\$ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
        ? preg_replace('/\\\\.\\\\d+\$/', '.0', \$ip)
        : '';
});");

        ?>
        <p class="cvm-about-subheading"><?php esc_html_e('Observe deliveries, and react to a lead outcome', 'convermetry'); ?></p>
        <p><?php echo wp_kses_post(__('Note which action means what: an exhausted <em>analytics</em> chain is resumable, an abandoned <em>form</em> delivery is not. Lead values are exact decimal strings, never floats.', 'convermetry')); ?></p>
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
        <p class="cvm-about-subheading"><?php esc_html_e('Scope an admin screen to a narrower capability', 'convermetry'); ?></p>
        <p><?php echo wp_kses_post(__('Applied to menu visibility <strong>and</strong> every handler behind it. Grant deliberately: <code>submissions.export</code> is every lead\'s name and email in one file.', 'convermetry')); ?></p>
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
        <p><?php echo wp_kses_post(__('<strong>Public.</strong> The tracker\'s ingestion endpoint: idempotent batches, per-IP and site-wide rate limits, same-host URL validation, Origin/Referer protection, bot filtering, and DNT/GPC enforcement. It answers <code>202</code> with <code>{"stored": n}</code>, <code>400</code>/<code>403</code>/<code>413</code>/<code>429</code> (with <code>Retry-After</code>) on rejection, and <code>503</code> when storage failed — in which case the tracker keeps the batch and retries.', 'convermetry')); ?></p>
        <p><?php esc_html_e('It accepts events from this site\'s own tracker into the local database only. It never forwards anything to a webhook receiver; everything a receiver gets is produced later by the two outbound paths.', 'convermetry'); ?></p>
        <?php
        self::cardEnd();

        self::cardStart('GET /wp-json/convermetry/v1/deliveries');
        ?>
        <p><?php echo wp_kses_post(__('<strong>API-key authenticated, read-only, and off by default</strong> — enable it and manage its key on the Activity Log page.', 'convermetry')); ?></p>
        <?php
        self::code('GET /wp-json/convermetry/v1/deliveries?page=1&per_page=25&status=error&message_type=form_submission
Authorization: <api-key>');
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Parameter', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Values', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><code>page</code> / <code>per_page</code></td><td><?php echo wp_kses_post(__('Pagination; <code>per_page</code> max 100.', 'convermetry')); ?></td></tr>
        <tr><td><code>status</code></td><td><code>success</code> | <code>error</code></td></tr>
        <tr><td><code>message_type</code></td><td><code>analytics_report</code> | <code>form_submission</code></td></tr>
        <tr><td><code>endpoint</code></td><td><?php echo wp_kses_post(__('An endpoint <strong>label</strong>, or the <code>endpoint_key</code> echoed in responses.', 'convermetry')); ?></td></tr>
        <tr><td><code>provider</code></td><td><?php esc_html_e('Form provider key.', 'convermetry'); ?></td></tr>
        <tr><td><code>form_id</code></td><td><?php esc_html_e('Exact form name.', 'convermetry'); ?></td></tr>
        <tr><td><code>after</code></td><td><?php echo wp_kses_post(__('<code>YYYY-MM</code> or <code>YYYY-MM-DD</code>.', 'convermetry')); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('Pagination metadata returns in <code>X-WP-Total</code>, <code>X-WP-TotalPages</code> and <code>X-CVM-Page</code> headers. Only a SHA-256 hash of the key is stored — the raw key is shown <strong>once</strong> at generation, and regenerating invalidates the old key immediately. Wrong keys get <code>401</code>, throttled per IP after repeated failures; a disabled API answers <code>403</code>.', 'convermetry')); ?></p>
        <div class="cvm-about-note"><?php echo wp_kses_post(__('In responses, <code>endpoint_url</code> is <strong>redacted to scheme + host</strong> — webhook URLs frequently embed bearer tokens, and this read-only key must never hand out downstream write credentials. Identify endpoints by <code>endpoint_label</code> or <code>endpoint_key</code>; full URLs stay visible to admins in wp-admin. Intended for <strong>server-to-server</strong> use — never embed the key in public frontend JavaScript.', 'convermetry')); ?></div>
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

        self::cardStart(__('Privacy posture', 'convermetry'));
        ?>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<strong>Email notifications are opt-in and leave your retention window.</strong> When enabled, each notification is a copy of lead data in a mailbox Convermetry does not control. Deleting a submission cancels anything still queued, but <strong>cannot recall a message already sent</strong>.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>No cookies — but browser storage.</strong> The tracker keeps a random visit id (<code>cvm_session</code>) and the visit\'s attribution (<code>cvm_campaign</code>) in <code>localStorage</code>, and events not yet sent (<code>cvm_pending</code>) in <code>sessionStorage</code>. The visit id rotates after 30 minutes of inactivity. In the EU and UK the rules that govern cookies also apply to this storage.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('Tracked URLs are canonicalized to scheme + host + path — <strong>query strings never reach the database</strong>. Referrers and click/form destinations are likewise stripped; whole <code>mailto:</code>/<code>tel:</code> destinations are kept, because for those links the address <em>is</em> the destination.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('Campaign values are stored after sanitization, except values containing <code>@</code>, which are dropped as likely email addresses — never put personal data in UTM parameters. Ad-click identifiers store only the parameter <strong>name</strong>; the value never leaves the browser.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Visitor IP addresses are stored by default</strong>, on both write paths: every analytics event and every server-confirmed form submission. Turn it off with <strong>Settings → Tracking → IP addresses</strong>; new rows then record an empty value while existing rows are untouched and age out with retention. User agents are never stored on either path.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>In the EU/UK an IP address is personal data.</strong> Retaining it for general visitor activity — not only for leads someone actively submitted — normally has to be disclosed in your privacy policy and rest on a lawful basis. Consider whether your consent tooling should gate the tracker.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('To <strong>anonymize rather than disable</strong>, use <a href="#hook-convermetry_stored_ip"><code>convermetry_stored_ip</code></a> — it runs after the privacy gates on the address about to be persisted, covers both write paths at once, and deliberately does not touch the rate-limit identity, which would collapse every visitor into one bucket. Behind a proxy or CDN, map the real address with <a href="#hook-convermetry_client_ip"><code>convermetry_client_ip</code></a> instead.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('When the site honors <strong>Do Not Track / Global Privacy Control</strong> and a visitor sends one, <strong>no IP is stored on either path</strong>: their analytics events are not recorded at all, and a form they submit is still recorded and delivered — they actively submitted it — but carries an empty <code>ip_address</code>. Both paths go through one gate, so the setting, the signal, and this documentation cannot drift apart. DNT/GPC is an opt-out signal, not a consent mechanism.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>No external geolocation service is ever contacted.</strong> A form submission never waits on any third party, and a stored IP is never sent anywhere except your own webhook endpoints.', 'convermetry')); ?></li>
        <li><?php esc_html_e('Logged-in users are excluded from tracking by default.', 'convermetry'); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Form abandonment records no field values, ever</strong> — only a field id, a field type, and which validity flag failed. <strong>Custom event payloads are not storage</strong> — only the event name, and a numeric value where a goal accepts one. <strong>Goal and funnel records carry no PII</strong> — only normalized URLs, the attribution snapshot, and a device bucket. <strong>Lead status and value stay on the submission record</strong> and its history table.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Goal definitions are not published to visitors.</strong> The one exception is a CSS selector goal, whose selector must reach the browser to be evaluated; the ids reported back are re-validated server-side before anything is recorded.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('The Activity Log stores a <strong>redacted</strong> copy of each delivery\'s request payload. A form payload\'s copy is replaced when <em>Store form submission data in the Activity Log</em> is off; an analytics report\'s <code>conversions.recent[].ip_address</code> is logged regardless. Log rows age out with the same retention window, and <a href="#hook-convermetry_delivery_log_row"><code>convermetry_delivery_log_row</code></a> can redact anything further.', 'convermetry')); ?></li>
        <li><?php esc_html_e('Everything is deleted after the configurable retention window (7–365 days, default 90) by bounded, chunked cleanup jobs.', 'convermetry'); ?></li></ul>
        <?php
        self::cardEnd();

        self::cardStart(__('Consent and WordPress privacy tools', 'convermetry'));
        ?>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<strong>Tracking starts on activation.</strong> Convermetry has no consent banner of its own and no consent-plugin integration. Where your site needs consent first, have your consent tool block the <code>cvm-tracker</code> script until it is given, or return <code>false</code> from <a href="#hook-convermetry_should_enqueue_tracker"><code>convermetry_should_enqueue_tracker</code></a> until your consent check passes. Server-confirmed form submissions are still recorded — without analytics context — when the tracker does not run.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Suggested policy text.</strong> Settings → Privacy → Policy Guide carries a Convermetry section generated from the current settings — IP storage, Do Not Track / Global Privacy Control, retention, webhooks and email notifications. WordPress flags the guide when that text changes, so a site that later switches IP storage on is told its policy may be stale. It is a starting point, not legal advice.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>Export and erasure.</strong> Tools → Export Personal Data and Tools → Erase Personal Data find the form submissions whose submitted values contain the requested email address <em>exactly</em> — a field that merely mentions the address inside other text is somebody else\'s lead and is left alone. The export includes each submission, its lead history, where it was delivered (destination host only — endpoint URLs often embed secrets), and the analytics of the visit it came from.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>What erasure removes.</strong> The submission, through the same path as the Delete button — its queued webhook deliveries, queued notifications and lead history go with it. Activity Log rows for it keep their audit metadata but lose the request and response bodies; logged analytics reports lose that conversion\'s IP address and session id; and the visit\'s analytics events lose their IP address while remaining as anonymous traffic.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<strong>What erasure cannot do.</strong> It cannot recall a webhook payload a receiver already accepted or an email already handed to your mail system, and it does not rewrite an analytics report frozen for retry (those bytes are replayed exactly, under one delivery id). It says so on the Erase Personal Data screen, naming the webhook destinations the data had reached. A delivery already in flight at that moment may still complete, and analytics from visits without a form submission cannot be linked to an email address at all — retention removes it.', 'convermetry')); ?></li></ul>
        <?php
        self::cardEnd();

        self::cardStart(__('What is stored, and where', 'convermetry'));
        ?>
        <table class="cvm-about-table"><thead><tr><th scope="col"><?php esc_html_e('Table', 'convermetry'); ?></th><th scope="col"><?php esc_html_e('Purpose', 'convermetry'); ?></th></tr></thead><tbody>
        <tr><td><code>cvm_events</code></td><td><?php esc_html_e('One row per visitor interaction — the analytics engine. A unique (batch id, sequence) makes tracker replays idempotent.', 'convermetry'); ?></td></tr>
        <tr><td><code>cvm_form_submissions</code></td><td><?php echo wp_kses_post(__('One row per server-confirmed submission: identifiers, form identity, page URL and query, IP, sanitized <code>submission_data</code>, the frozen analytics context, the indexed campaign/channel/landing-page columns, the lead outcome columns, and the recorded delivery state.', 'convermetry')); ?></td></tr>
        <tr><td><code>cvm_delivery_queue</code></td><td><?php esc_html_e('The background form-delivery queue: one row per submission × endpoint, holding the frozen URL, headers and body. Deleted on acknowledgment or abandonment.', 'convermetry'); ?></td></tr>
        <tr><td><code>cvm_notification_queue</code></td><td><?php echo wp_kses_post(__('The email queue: one row per submission × recipient, carrying <strong>no lead data</strong> — the submission is read at send time.', 'convermetry')); ?></td></tr>
        <tr><td><code>cvm_webhook_deliveries</code></td><td><?php esc_html_e('The Activity Log: one row per delivery attempt with redacted headers and bodies, capped at 64 KB each.', 'convermetry'); ?></td></tr>
        <tr><td><code>cvm_goal_completions</code></td><td><?php echo wp_kses_post(__('One row per goal completion. <code>dedupe_key</code> carries a UNIQUE index and is the entire deduplication mechanism.', 'convermetry')); ?></td></tr>
        <tr><td><code>cvm_lead_events</code></td><td><?php esc_html_e('Lead status-change history: one row per transition, cascaded away when the submission is deleted.', 'convermetry'); ?></td></tr></tbody></table>
        <p><?php echo wp_kses_post(__('<strong>Schema migrations never run inside a visitor\'s request.</strong> Adding an index is a table rebuild on every engine, so migrations run only in WP-Cron, WP-CLI, or a genuine admin page view, one at a time under a lease. While one is outstanding the Goals and Funnels screens say so plainly rather than querying a column that does not exist yet.', 'convermetry')); ?></p>
        <p><?php echo wp_kses_post(__('<strong>Deactivation preserves everything:</strong> tables and data are kept, analytics retry chains are suspended and resume under their original delivery ids, and queued form deliveries wait for the re-armed worker. <strong>Deleting the plugin</strong> drops all seven tables and deletes every option, transient, rate-limit counter row, and scheduled cron event — per site across a whole multisite network. No trace remains.', 'convermetry')); ?></p>
        <?php
        self::cardEnd();

        self::cardStart(__('Watching the unattended work', 'convermetry'));
        ?>
        <p><?php esc_html_e('Retention passes, schema migrations, and queue workers all run without anyone looking. Four observational actions report what they did, so a monitoring integration does not have to infer it from row counts.', 'convermetry'); ?></p>
        <ul class="cvm-about-features">
        <li><?php echo wp_kses_post(__('<code>convermetry_retention_cleanup_started</code> / <code>_completed</code> — one store begins and finishes deleting past the cutoff. The completion carries how many rows went, whether more remain, and an outcome of <code>completed</code>, <code>truncated</code>, <code>query_failed</code>, or <code>lock_lost</code>. Observational only: a listener cannot cancel a pass, change the cutoff, or extend retention, and Convermetry schedules any follow-up pass itself.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<code>convermetry_migration_started</code> / <code>_completed</code> / <code>_failed</code> — a migration pass, with its context (<code>cli</code>, <code>cron</code>, or <code>admin</code>). A failure carries the exception <strong>class name</strong>, never a message: a database error quotes the failing statement. <strong>No SQL is passed to any migration hook</strong>, and a migration that merely has not landed yet is not a failure.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<code>convermetry_storage_error</code> — a database operation Convermetry needed <em>verifiably</em> failed. Reserved for real failures: a duplicate <code>INSERT IGNORE</code>, an abandoned notification, or a still-pending migration do not fire it. It never carries SQL, the raw database error, submitted fields, IP addresses, or secrets.', 'convermetry')); ?></li>
        <li><?php echo wp_kses_post(__('<code>convermetry_settings_saved</code> — a settings section was written, listening on WordPress\'s own option-write hooks so it fires on a real write only (never for a form submitted without edits) and catches CLI and migration writers too. <strong>Key names only, never values</strong>: two sections hold signing secrets and token-bearing endpoint URLs.', 'convermetry')); ?></li></ul>
        <?php
        self::cardEnd();

        self::sectionEnd();
    }
}
