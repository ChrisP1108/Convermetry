<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Analytics\Reports;
use Convermetry\Forms\SubmissionFields;
use Convermetry\Support\Extensions;

/**
 * Builds the JSON payloads for every outbound Convermetry webhook message.
 *
 * All messages share one versioned envelope so downstream systems can route
 * and evolve safely:
 *
 *     {
 *         "schema_version": "1.0" | "1.1" | "2.0",
 *         "source": "convermetry",
 *         "plugin_version": "0.8.0",
 *         "message_type": "analytics_report" | "form_submission",
 *         "website_info": { ... },
 *         "generated_at": "...",
 *         "delivery_id": "..."
 *     }
 *
 * followed by the message-type-specific body ('period' + 'analytics' for
 * reports; 'form_submission' + 'analytics_context' for submissions).
 *
 * THE TWO MESSAGE TYPES VERSION INDEPENDENTLY. Analytics reports are 1.1 as of
 * plugin 0.5.0 (1.0 plus an additive 'analytics.goals' section — every 1.0
 * field is unchanged). Form submissions are 2.0 when the row carries structured
 * fields and 1.0 when it carries the historical map, so several versions
 * legitimately travel at once — and a frozen retry can deliver a 1.0 body long
 * after the plugin was upgraded. Receivers must therefore branch on
 * 'schema_version', never on 'plugin_version', and must tolerate unknown keys
 * being added within a major version.
 *
 * 'form_submission.ip_address' carries the submitter's IP as captured at
 * submission time — never re-resolved at delivery time, which runs in a
 * different request with no visitor behind it. It is an empty string when the
 * Settings toggle is off, when no address could be determined, or for a
 * submission stored before the field existed.
 *
 * Identifier semantics (documented once, here, for both message types):
 *
 *  - submission_id — identifies the form submission itself; identical in
 *    every delivery of that submission, to every endpoint.
 *  - conversion_id — joins the submission to Convermetry's analytics
 *    conversion tracking (the same id appears on the form_success analytics
 *    event); deduplicate conversions by this.
 *  - delivery_id   — identifies ONE outbound delivery (endpoint-specific),
 *    echoed as the Idempotency-Key header; deduplicate webhook receipts by
 *    this.
 *
 * Every payload passes through the 'convermetry_webhook_payload' filter
 * before it is frozen/encoded.
 */
final class PayloadBuilder
{
    /**
     * The analytics report schema version.
     *
     * 1.1 adds one section — 'analytics.goals' — and changes nothing else. Every
     * 1.0 field is present, in place, with the same shape, so a receiver written
     * against 1.0 keeps working untouched. That is what makes it a minor bump
     * rather than a 2.0.
     *
     * The two message types version independently on purpose: form submissions
     * are at 2.0 and are unaffected by this, and bumping them for a change that
     * never touched them would force every submission receiver to re-certify for
     * nothing.
     *
     * WHAT IS DELIBERATELY NOT IN THIS PAYLOAD, and why — because its absence is
     * a decision, not an oversight:
     *
     *  - Funnels and form abandonment need a MATURITY PERIOD (30 minutes for an
     *    abandonment, 24 hours for a funnel's later steps). A report generated at
     *    its own window's edge cannot see them yet, and scheduled windows advance
     *    without ever revisiting, so those numbers would be permanently and
     *    invisibly low for every receiver.
     *  - Lead status and value MUTATE after the fact. A lead created on Monday
     *    and marked won on Friday would be reported 'new' forever, and a
     *    'lead' block on a form_submission payload — frozen on its first
     *    delivery attempt — would be wrong for every lead anybody ever qualifies.
     *
     * Goal completions have neither problem: a completion happened inside the
     * window or it did not, and nothing later changes that.
     *
     * All three are available in the admin screens, and the cvm_lead_events
     * table exists so a lead_status_changed message can be added once there is a
     * delivery path whose semantics can actually carry a correction.
     */
    public const string SCHEMA_VERSION = '1.1';

    /**
     * The form-submission schema version for submissions stored with
     * structured fields — submission_data is an ORDERED LIST of
     * {id, label, value} descriptors rather than an object.
     *
     * This is a breaking wire change, which is exactly why it is versioned.
     * Receivers must branch on schema_version, never on plugin_version: both
     * versions travel during the transition (see below), so the plugin version
     * tells a receiver nothing about the shape of the message in front of it.
     */
    public const string FORM_SCHEMA_VERSION = '2.0';

    /**
     * The form-submission schema version for rows recorded before structured
     * fields, whose submission_data is still the historical associative map.
     *
     * Historical rows keep emitting 1.0 with their original map rather than
     * being converted on the way out. A submission that was delivered to one
     * endpoint as 1.0 before the upgrade would otherwise reach a second
     * endpoint — or a retry — as 2.0, and the same lead would arrive in two
     * shapes under one submission_id. Rows are never bulk-rewritten.
     */
    public const string LEGACY_FORM_SCHEMA_VERSION = '1.0';

    /**
     * Builds the payload for one analytics reporting window.
     *
     * The 'analytics' section is produced by {@see Reports::buildSummary()},
     * the exact query layer the dashboard uses, so webhook consumers see the
     * same numbers as the admin screens.
     *
     * Both 'generated_at' and 'period' are derived from $endTs rather than
     * the wall-clock time a retry actually runs at — a retry chain re-sends
     * a byte-identical body under the same delivery_id, which would break if
     * any field in it moved between attempts.
     *
     * The 'delivery_id' key is a placeholder here — the dispatcher fills in
     * the real value afterward. It is declared in this position (not simply
     * appended) so the field lands where the docs show it in the wire
     * format: PHP preserves array key order, and assigning to an existing
     * key updates it in place.
     *
     * @param int $startTs   Window start as a unix timestamp (inclusive).
     * @param int $endTs     Window end as a unix timestamp (exclusive).
     * @param int $rowLimit  Maximum rows per "top_*" list.
     * @return array<string, mixed>
     * @throws \Convermetry\Analytics\ReportQueryException When a report query fails.
     */
    public static function analyticsReport(int $startTs, int $endTs, int $rowLimit): array
    {
        $start = gmdate('Y-m-d H:i:s', $startTs);
        $end   = gmdate('Y-m-d H:i:s', $endTs);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'source'         => 'convermetry',
            'plugin_version' => CVM_VERSION,
            'message_type'   => 'analytics_report',
            'website_info'   => WebsiteInfo::current()->toArray(),
            'generated_at'   => gmdate('c', $endTs),
            'delivery_id'    => '',
            'period'         => [
                'start' => gmdate('c', $startTs),
                'end'   => gmdate('c', $endTs),
            ],
            'analytics'      => Reports::buildSummary($start, $end, $rowLimit),
        ];

        return self::filter($payload, 'analytics_report', ['start' => $startTs, 'end' => $endTs]);
    }

    /**
     * Builds the payload for one form submission.
     *
     * 'generated_at' comes from the submission's stored creation time — not
     * the moment a delivery or retry runs — so every endpoint (and every
     * retry) sees an identical timestamp for the same submission.
     *
     * The schema version is chosen per-submission, from the shape actually
     * stored: rows written with structured fields emit 2.0 with the descriptor
     * list, while rows still holding the pre-2.0 associative map emit 1.0 with
     * that map untouched. {@see self::LEGACY_FORM_SCHEMA_VERSION} explains why
     * historical rows are not converted on the way out.
     *
     * @param array<string, mixed> $submission A cvm_form_submissions row (JSON columns decoded by caller or here).
     * @return array<string, mixed>
     */
    public static function formSubmission(array $submission): array
    {
        $pageQuery = self::decodeJson((string) ($submission['page_query'] ?? ''));
        $stored    = self::decodeJson((string) ($submission['submission_data'] ?? ''));
        $context   = self::decodeJson((string) ($submission['context'] ?? ''));

        $legacy = SubmissionFields::isLegacyMap($stored);

        // An empty column is emitted as an empty 2.0 list, not as a legacy
        // map: '{}' and '[]' both decode to [], and pinning every field-less
        // submission to the old schema forever would be an accident.
        $data = $legacy ? $stored : SubmissionFields::normalize($stored);

        $createdAt   = (string) ($submission['created_at'] ?? gmdate('Y-m-d H:i:s'));
        $generatedAt = gmdate('c', (int) strtotime($createdAt . ' UTC'));

        $payload = [
            'schema_version' => $legacy ? self::LEGACY_FORM_SCHEMA_VERSION : self::FORM_SCHEMA_VERSION,
            'source'         => 'convermetry',
            'plugin_version' => CVM_VERSION,
            'message_type'   => 'form_submission',
            'website_info'   => WebsiteInfo::current(new PageInfo(
                url: (string) ($submission['page_url'] ?? ''),
                query: self::stringMap($pageQuery),
            ))->toArray(),
            'generated_at'   => $generatedAt,
            'delivery_id'    => '',
            'form_submission' => [
                'submission_id'   => (string) ($submission['submission_id'] ?? ''),
                'conversion_id'   => (string) ($submission['conversion_id'] ?? ''),
                'provider'        => (string) ($submission['provider'] ?? ''),
                'form_name'       => (string) ($submission['form_name'] ?? ''),
                'form_id'         => (string) ($submission['form_id'] ?? ''),
                'native_form_id'  => (string) ($submission['native_form_id'] ?? ''),
                'ip_address'      => (string) ($submission['ip_address'] ?? ''),
                'submission_data' => $data,
            ],
            'analytics_context' => $context !== [] ? $context : self::emptyContext(),
        ];

        return self::filter($payload, 'form_submission', ['submission_id' => (string) ($submission['submission_id'] ?? '')]);
    }

    /**
     * Builds a clearly marked sample form-submission payload for the
     * per-endpoint "Send test" action on the Webhooks page. Never touches
     * real submission data.
     *
     * @return array<string, mixed>
     */
    public static function formSubmissionTest(): array
    {
        $now = time();

        $payload = self::formSubmission([
            'submission_id'   => 'test-' . md5((string) wp_rand()),
            'conversion_id'   => 'test-conversion-' . md5((string) wp_rand()),
            'provider'        => 'test',
            'form_name'       => 'Convermetry Test Form',
            'form_id'         => 'convermetry-test',
            'native_form_id'  => 'test',
            'page_url'        => home_url('/'),
            // RFC 5737 documentation range — never a real visitor address.
            'ip_address'      => '203.0.113.42',
            'page_query'      => (string) wp_json_encode(['utm_source' => 'convermetry-test']),
            // A descriptor list, so the test send shows receivers the CURRENT
            // schema (2.0) rather than the shape they are migrating away from.
            'submission_data' => (string) wp_json_encode([
                ['id' => 'name',    'label' => 'Full name', 'value' => 'Test Person'],
                ['id' => 'email',   'label' => 'Email address', 'value' => 'test@example.com'],
                ['id' => 'message', 'label' => 'Message', 'value' => 'This is a Convermetry webhook test — not a real submission.'],
            ]),
            'context'         => (string) wp_json_encode(self::emptyContext()),
            'created_at'      => gmdate('Y-m-d H:i:s', $now),
        ]);

        $payload['test'] = true;

        return $payload;
    }

    /**
     * The always-present analytics_context skeleton, used when a submission
     * arrived without correlation data (tracker disabled, privacy signals,
     * JavaScript blocked) — downstream systems always see the same keys.
     *
     * @return array<string, mixed>
     */
    public static function emptyContext(): array
    {
        return [
            'session_id'         => '',
            'channel'            => '',
            'attribution'        => [
                'utm_source'    => '',
                'utm_medium'    => '',
                'utm_campaign'  => '',
                'utm_id'        => '',
                'utm_term'      => '',
                'utm_content'   => '',
                'click_id_type' => '',
            ],
            'entrance_referrer'  => '',
            'landing_page'       => ['url' => ''],
            'device'             => '',
            'pageview_count'     => 0,
            'session_started_at' => '',
            'recent_pages'       => [],
        ];
    }

    /**
     * Applies the shared payload filter.
     *
     * @param array<string, mixed> $payload     The payload about to be frozen/encoded.
     * @param string               $messageType 'analytics_report' or 'form_submission'.
     * @param array<string, mixed> $meta        Message-specific metadata (window timestamps or submission id).
     * @return array<string, mixed>
     */
    private static function filter(array $payload, string $messageType, array $meta): array
    {
        /**
         * Filters extension data added to an outbound webhook payload.
         *
         * A non-empty result becomes the payload's top-level 'extensions'
         * property. An empty one adds no property at all, so a site with no
         * integrations sends exactly the bytes it always did — which matters
         * here more than anywhere else in the plugin, because a receiver
         * validating against a strict schema would reject an unexpected key.
         *
         * Keys must be namespaced 'vendor/thing'. No core payload property
         * contains a '/', so a namespaced key can never collide with one, and
         * the merge cannot overwrite a core property even if it tried.
         *
         * Runs once per logical delivery, before the payload is encoded and
         * frozen: an analytics report is built once per reporting window and a
         * submission once per submission, and every retry resends the frozen
         * bytes without re-running this. It runs BEFORE
         * convermetry_webhook_payload, so that filter still sees and can strip
         * whatever is added here.
         *
         * Bounded to 32 KB and 50 top-level keys, values restricted to JSON
         * primitives and arrays; anything unencodable is dropped rather than
         * risking a payload that fails to encode and turns into a failed
         * delivery.
         *
         * $meta carries the submission id for a form payload — enough to look
         * up whatever you need without this hook having to carry the visitor's
         * data itself.
         *
         * @param array<string, mixed> $extensions  Empty array to add to.
         * @param string               $messageType 'analytics_report' or 'form_submission'.
         * @param array<string, mixed> $meta        ['start' => int, 'end' => int] for reports;
         *                                          ['submission_id' => string] for submissions.
         */
        $payload = Extensions::attach(
            $payload,
            'extensions',
            'convermetry_webhook_payload_extensions',
            Extensions::WEBHOOK_MAX_BYTES,
            Extensions::WEBHOOK_MAX_KEYS,
            $messageType,
            $meta
        );

        /**
         * Filters an outbound webhook payload before it is JSON-encoded.
         *
         * For analytics reports the payload is filtered once per reporting
         * window; for form submissions once per submission. Retries re-send
         * the frozen bytes and never re-run this filter.
         *
         * @param array<string, mixed> $payload     The payload about to be sent.
         * @param string               $messageType 'analytics_report' or 'form_submission'.
         * @param array<string, mixed> $meta        ['start' => int, 'end' => int] for reports;
         *                                          ['submission_id' => string] for submissions.
         */
        return (array) apply_filters('convermetry_webhook_payload', $payload, $messageType, $meta);
    }

    /**
     * Reduces a decoded JSON object to string keys with string values.
     *
     * The page_query column was written from already-sanitized scalars, so
     * this is a type narrowing rather than a sanitizer — but the column is
     * still JSON on disk, and a row hand-edited (or written by an older
     * version) can hold anything.
     *
     * @param array<string, mixed> $map Decoded map.
     * @return array<string, string>
     */
    private static function stringMap(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            if (is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Decodes a stored JSON column into an array, tolerating empty/invalid
     * values.
     *
     * @param string $json Stored JSON string.
     * @return array<string, mixed>
     */
    private static function decodeJson(string $json): array
    {
        if ($json === '' || !json_validate($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
