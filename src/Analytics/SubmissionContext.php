<?php
declare(strict_types=1);

namespace Convermetry\Analytics;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\FormSubmissions;
use Convermetry\Webhook\PayloadBuilder;

/**
 * The analytics context attached to one form submission — decoding, default
 * filling, and the deferred session-summary enrichment.
 *
 * A submission's context arrives in two halves. The request-time half
 * ({@see \Convermetry\Tracking\Correlation::toAnalyticsContext()}) is captured
 * in the visitor's own request and needs no queries: session id, channel,
 * attribution, entrance referrer, landing page, device. The session-summary
 * half — pageview_count, session_started_at, recent_pages — needs two indexed
 * queries and is therefore computed later, in a background worker, never in
 * the visitor's request.
 *
 * This class was extracted from FormDeliveryQueue because three consumers now
 * need the same context and must not disagree about it: webhook payload
 * freezing, the Submissions detail panel, and notification email rendering.
 * Duplicating the enrichment would mean duplicating the analytics queries and
 * risking a fourth caller running them inline in a form request.
 *
 * The class is split deliberately: everything except enrich()/persist() is
 * pure, takes arrays and returns arrays, and touches neither $wpdb nor the
 * report queries.
 */
final class SubmissionContext
{
    /**
     * Decodes a stored JSON column into an array, tolerating empty and
     * invalid values.
     *
     * @param string $json Stored JSON string.
     * @return array<string, mixed>
     */
    public static function decodeJson(string $json): array
    {
        if ($json === '' || !json_validate($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether this submission still needs its session summary computed.
     *
     * 'pageview_count' is the sentinel: it is absent until enrichment runs and
     * always present afterwards, so enrichment happens at most once per
     * submission, ever — a retry, a second endpoint, and the admin detail
     * panel all reuse the persisted result rather than re-querying.
     *
     * @param array<string, mixed> $submission Submission row.
     * @return bool
     */
    public static function needsEnrichment(array $submission): bool
    {
        $sessionId = (string) ($submission['session_id'] ?? '');
        if ($sessionId === '') {
            return false;
        }

        return !isset(self::decodeJson((string) ($submission['context'] ?? ''))['pageview_count']);
    }

    /**
     * Overlays a session summary onto a decoded context.
     *
     * @param array<string, mixed> $context Decoded analytics context.
     * @param array<string, mixed> $summary {@see Reports::sessionSummary()} result.
     * @return array<string, mixed>
     */
    public static function merge(array $context, array $summary): array
    {
        return array_merge($context, $summary);
    }

    /**
     * Fills every key of the canonical context skeleton, including the nested
     * 'attribution' and 'landing_page' sub-arrays.
     *
     * The fill is deep on purpose. A row whose stored context predates a key
     * (or was written by a filter) can hold a partial 'attribution' array; a
     * shallow merge would leave that sub-array short, and a consumer reading
     * $context['attribution']['utm_term'] would hit a missing index where the
     * webhook payload reads ''. Sharing the skeleton with
     * {@see PayloadBuilder::emptyContext()} is what keeps the email and the
     * webhook describing the same visit the same way.
     *
     * @param array<string, mixed> $context Decoded analytics context.
     * @return array<string, mixed>
     */
    public static function withDefaults(array $context): array
    {
        $defaults = PayloadBuilder::emptyContext();

        $out = array_merge($defaults, $context);

        foreach ($defaults as $key => $default) {
            if (!is_array($default)) {
                continue;
            }
            $out[$key] = is_array($out[$key] ?? null)
                ? array_merge($default, $out[$key])
                : $default;
        }

        return $out;
    }

    /**
     * The decoded, fully-defaulted context for a submission row.
     *
     * The read-only counterpart to {@see self::enrich()}: no queries, no
     * writes. Presenters use this; delivery paths use enrich() first.
     *
     * @param array<string, mixed> $submission Submission row.
     * @return array<string, mixed>
     */
    public static function of(array $submission): array
    {
        return self::withDefaults(self::decodeJson((string) ($submission['context'] ?? '')));
    }

    /**
     * Enriches a submission's analytics context with the lightweight session
     * summary (pageview count, session start, recent pages) — computed in a
     * background worker or a synchronous dispatch, and persisted so a retry
     * (or a second endpoint's freeze in a later pass) sees identical context
     * even after the underlying events age out.
     *
     * @param array<string, mixed> $submission Submission row.
     * @return array<string, mixed> The submission row, with 'context' enriched.
     */
    public static function enrich(array $submission): array
    {
        if (!self::needsEnrichment($submission)) {
            return $submission;
        }

        try {
            $context = self::merge(
                self::decodeJson((string) ($submission['context'] ?? '')),
                Reports::sessionSummary((string) $submission['session_id'])
            );

            $submission['context'] = (string) wp_json_encode($context);

            self::persist((int) ($submission['id'] ?? 0), $submission['context']);
        } catch (ReportQueryException) {
            // Enrichment is best-effort: a failed summary query must not
            // block delivering the lead itself.
        }

        return $submission;
    }

    /**
     * Writes the enriched context back to the submission row.
     *
     * Isolated so nothing else in this class sees $wpdb. Addressed by the
     * numeric row id because this is an update of a row already in hand, not
     * a lookup.
     *
     * @param int    $rowId       Submission row id.
     * @param string $contextJson Encoded context.
     * @return void
     */
    private static function persist(int $rowId, string $contextJson): void
    {
        if ($rowId <= 0) {
            return;
        }

        global $wpdb;

        $wpdb->update(
            FormSubmissions::tableName(),
            ['context' => $contextJson],
            ['id' => $rowId],
            ['%s'],
            ['%d']
        );
    }
}
