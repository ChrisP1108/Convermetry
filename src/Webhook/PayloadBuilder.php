<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Analytics\Reports;

/**
 * Builds the JSON payloads for every outbound Convermetry webhook message.
 *
 * All messages share one versioned envelope so downstream systems can route
 * and evolve safely:
 *
 *     {
 *         "schema_version": "1.0",
 *         "source": "convermetry",
 *         "plugin_version": "0.1.0",
 *         "message_type": "analytics_report" | "form_submission",
 *         "website_info": { ... },
 *         "generated_at": "...",
 *         "delivery_id": "..."
 *     }
 *
 * followed by the message-type-specific body ('period' + 'analytics' for
 * reports; 'form_submission' + 'analytics_context' for submissions).
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
    /** The payload schema version carried by every message. */
    public const SCHEMA_VERSION = '1.0';

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
            'website_info'   => WebsiteInfoBuilder::build(),
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
     * @param array<string, mixed> $submission A cvm_form_submissions row (JSON columns decoded by caller or here).
     * @return array<string, mixed>
     */
    public static function formSubmission(array $submission): array
    {
        $pageQuery = self::decodeJson((string) ($submission['page_query'] ?? ''));
        $data      = self::decodeJson((string) ($submission['submission_data'] ?? ''));
        $context   = self::decodeJson((string) ($submission['context'] ?? ''));

        $createdAt   = (string) ($submission['created_at'] ?? gmdate('Y-m-d H:i:s'));
        $generatedAt = gmdate('c', (int) strtotime($createdAt . ' UTC'));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'source'         => 'convermetry',
            'plugin_version' => CVM_VERSION,
            'message_type'   => 'form_submission',
            'website_info'   => WebsiteInfoBuilder::build([
                'url'   => (string) ($submission['page_url'] ?? ''),
                'query' => $pageQuery,
            ]),
            'generated_at'   => $generatedAt,
            'delivery_id'    => '',
            'form_submission' => [
                'submission_id'   => (string) ($submission['submission_id'] ?? ''),
                'conversion_id'   => (string) ($submission['conversion_id'] ?? ''),
                'provider'        => (string) ($submission['provider'] ?? ''),
                'form_name'       => (string) ($submission['form_name'] ?? ''),
                'form_id'         => (string) ($submission['form_id'] ?? ''),
                'native_form_id'  => (string) ($submission['native_form_id'] ?? ''),
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
            'page_query'      => (string) wp_json_encode(['utm_source' => 'convermetry-test']),
            'submission_data' => (string) wp_json_encode([
                'name'    => 'Test Person',
                'email'   => 'test@example.com',
                'message' => 'This is a Convermetry webhook test — not a real submission.',
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
     * Decodes a stored JSON column into an array, tolerating empty/invalid
     * values.
     *
     * @param string $json Stored JSON string.
     * @return array<string, mixed>
     */
    private static function decodeJson(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
