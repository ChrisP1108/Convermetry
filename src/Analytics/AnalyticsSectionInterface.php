<?php
declare(strict_types=1);

namespace Convermetry\Analytics;

if (!defined('ABSPATH')) exit;

/**
 * A third-party analytics section: one extra block of reporting that appears in
 * the dashboard and, when it produces data, in the analytics webhook payload.
 *
 * This is a typed adapter rather than a filter that accepts SQL, for the same
 * reason {@see \Convermetry\Forms\FormProviderInterface} is: a hook that took a
 * query fragment, a table name, or a column list would hand an unvalidated
 * string to the database on a path that already runs unattended on cron. An
 * implementation runs its own queries, through whatever it likes, and hands back
 * a plain array.
 *
 * Register one with the convermetry_analytics_sections filter:
 *
 *     add_filter('convermetry_analytics_sections', function (array $sections): array {
 *         $sections[] = new My_Subscriptions_Section();
 *         return $sections;
 *     });
 *
 * Contract notes an implementation must respect:
 *
 *  - {@see getKey()} must be namespaced 'vendor/thing'. Core report keys never
 *    contain a '/', so a namespaced key can never shadow one.
 *  - {@see summarize()} must not throw. If it does, Convermetry catches it,
 *    announces convermetry_analytics_report_failed, drops that section, and
 *    carries on — a broken section must never take down a webhook delivery or
 *    the dashboard. Do not rely on that: catch your own errors.
 *  - {@see summarize()}'s return value must be JSON-safe (arrays and scalars,
 *    no objects) and small. It is bounded before it reaches the wire.
 *  - {@see render()} writes to the output buffer and MUST escape everything it
 *    prints. Convermetry escapes none of it for you.
 */
interface AnalyticsSectionInterface
{
    /**
     * Stable namespaced identifier, e.g. 'acme/subscriptions'.
     *
     * Used as the section's key inside analytics.extensions and as its dashboard
     * panel id, so it must not change between requests or releases.
     *
     * @return string
     */
    public function getKey(): string;

    /**
     * Human-readable panel title.
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * One-sentence description shown under the panel title.
     *
     * @return string
     */
    public function getDescription(): string;

    /**
     * Summarizes this section for a reporting window.
     *
     * Called once per analytics summary build — once per scheduled webhook
     * delivery's freeze, and once per dashboard render. Never called again for a
     * frozen retry: retries resend the bytes frozen on the first attempt.
     *
     * @param string $start UTC 'Y-m-d H:i:s' window start (inclusive).
     * @param string $end   UTC 'Y-m-d H:i:s' window end (inclusive).
     * @param int    $limit Row limit for top-N style lists (10 on the dashboard,
     *                      the convermetry_webhook_report_limit value on the wire).
     * @return array<string, mixed> JSON-safe summary; [] to contribute nothing.
     */
    public function summarize(string $start, string $end, int $limit): array;

    /**
     * Renders this section's dashboard panel body.
     *
     * Echoes HTML directly. Everything printed must already be escaped — this
     * output goes into wp-admin verbatim.
     *
     * @param array<string, mixed> $summary The value {@see summarize()} returned.
     * @return void
     */
    public function render(array $summary): void;
}
