<?php
declare(strict_types=1);

namespace Convermetry\Analytics;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Leads\LeadStatus;

/**
 * Marketing performance measured against lead OUTCOMES rather than lead counts.
 *
 * This is what the release is ultimately for. Every earlier report could say
 * "Paid Search produced 211 conversions"; none could say whether those
 * conversions were worth anything. These queries group submissions by their
 * marketing dimension and count how many became qualified, how many were won,
 * and what they were worth.
 *
 * TERMINOLOGY IS DELIBERATE AND NARROW.
 *
 *   Attributed Lead Value   the sum of values across the cohort
 *   Attributed Revenue      the same, restricted to leads marked 'won'
 *
 * Nothing here is called ROI or ROAS. Both are ratios against ad SPEND, the
 * plugin has no cost data, and a "return" figure computed without the
 * investment half is not a weaker version of the metric — it is a different
 * number wearing its name.
 *
 * COHORT IS SUBMISSION DATE. A lead created on Monday and marked won on Friday
 * belongs to Monday's cohort, because the question is what Monday's marketing
 * produced. Status is read as it stands NOW, so these figures move as leads are
 * qualified — which is exactly why they are not shipped in scheduled webhook
 * payloads, whose windows advance and never revisit.
 *
 * CURRENCIES ARE GROUPED, NEVER SUMMED. A column adding 100 EUR to 100 USD and
 * showing 200 would be a fabricated number, and a confidently wrong revenue
 * figure is worse than none. Each row carries a per-currency map.
 */
final class LeadReports
{
    /**
     * Dimensions a lead breakdown may group by.
     *
     * These are real indexed columns on the submissions table, denormalized at
     * insert precisely so this report needs no JSON extraction and no join.
     *
     * @var array<string, string> label key => column
     */
    public const array DIMENSIONS = [
        'channel'      => 'channel',
        'source'       => 'utm_source',
        'medium'       => 'utm_medium',
        'campaign'     => 'utm_campaign',
        'landing_page' => 'landing_page',
        'form'         => 'form_name',
    ];

    /**
     * Maximum submissions sampled when computing time-to-lead medians.
     *
     * The median is computed in PHP because MySQL 5.7 — still supported by
     * WordPress 6.3 — has no percentile function, and the self-join tricks that
     * emulate one are quadratic. Reading two small columns for a bounded number
     * of rows is cheaper and far easier to reason about than either.
     */
    private const int LAG_SAMPLE_LIMIT = 20000;

    /**
     * Lead outcomes grouped by one marketing dimension.
     *
     * @param string $start     UTC datetime (inclusive).
     * @param string $end       UTC datetime (exclusive).
     * @param string $dimension A key of {@see self::DIMENSIONS}.
     * @param int    $limit     Maximum rows.
     * @return array<int, array<string, mixed>>
     */
    public static function byDimension(string $start, string $end, string $dimension, int $limit = 10): array
    {
        global $wpdb;

        $column = self::DIMENSIONS[$dimension] ?? null;
        if ($column === null) {
            return [];
        }

        $table = FormSubmissions::tableName();

        $qualified = self::inList(LeadStatus::QUALIFIED);
        $won       = self::inList(LeadStatus::WON);
        $excluded  = self::inList(LeadStatus::EXCLUDED_FROM_TOTALS);

        // Spam leaves the denominator; everything else stays. An unqualified
        // lead was still a lead the marketing produced, and excluding those
        // would make a channel look better the more poor-quality leads it sent.
        $rows = ReportQuery::rows($wpdb->prepare(
            "SELECT COALESCE(NULLIF({$column}, ''), '(none)') AS label,
                    COUNT(*) AS leads,
                    SUM(CASE WHEN lead_status IN ({$qualified}) THEN 1 ELSE 0 END) AS qualified,
                    SUM(CASE WHEN lead_status IN ({$won}) THEN 1 ELSE 0 END) AS won
             FROM {$table}
             WHERE created_at >= %s AND created_at < %s
               AND lead_status NOT IN ({$excluded})
             GROUP BY label
             ORDER BY leads DESC, label ASC
             LIMIT %d",
            $start,
            $end,
            $limit
        ));

        $values = self::valuesByDimension($start, $end, $column);

        return array_map(static function (array $row) use ($values): array {
            $label     = (string) $row['label'];
            $leads     = (int) $row['leads'];
            $qualified = (int) $row['qualified'];
            $wonCount  = (int) $row['won'];

            return [
                'label'             => $label,
                'leads'             => $leads,
                'qualified'         => $qualified,
                'won'               => $wonCount,
                'qualified_rate'    => self::rate($qualified, $leads),
                'win_rate'          => self::rate($wonCount, $leads),
                // Per-currency maps, never a single total — see the class docblock.
                'value'             => $values[$label]['value'] ?? [],
                'revenue'           => $values[$label]['revenue'] ?? [],
            ];
        }, $rows);
    }

    /**
     * Attributed value and revenue per dimension value, split by currency.
     *
     * @param string $start  UTC datetime (inclusive).
     * @param string $end    UTC datetime (exclusive).
     * @param string $column A validated column name.
     * @return array<string, array{value: array<string, string>, revenue: array<string, string>}>
     */
    private static function valuesByDimension(string $start, string $end, string $column): array
    {
        global $wpdb;

        $table    = FormSubmissions::tableName();
        $won      = self::inList(LeadStatus::WON);
        $excluded = self::inList(LeadStatus::EXCLUDED_FROM_TOTALS);

        $rows = ReportQuery::rows($wpdb->prepare(
            "SELECT COALESCE(NULLIF({$column}, ''), '(none)') AS label,
                    lead_currency,
                    SUM(lead_value) AS total_value,
                    SUM(CASE WHEN lead_status IN ({$won}) THEN lead_value ELSE 0 END) AS won_value
             FROM {$table}
             WHERE created_at >= %s AND created_at < %s
               AND lead_status NOT IN ({$excluded})
               AND lead_value IS NOT NULL
             GROUP BY label, lead_currency",
            $start,
            $end
        ));

        $out = [];

        foreach ($rows as $row) {
            $label    = (string) $row['label'];
            $currency = (string) $row['lead_currency'];

            // Values stay as the strings the DECIMAL columns produced. Casting
            // to float anywhere in this path would reintroduce the drift the
            // column type exists to prevent.
            $out[$label]['value'][$currency]   = (string) $row['total_value'];
            $out[$label]['revenue'][$currency] = (string) $row['won_value'];
        }

        return $out;
    }

    /**
     * Overall lead totals for a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return array{leads: int, qualified: int, won: int, valued: int, qualified_rate: float, win_rate: float}
     */
    public static function summary(string $start, string $end): array
    {
        global $wpdb;

        $table     = FormSubmissions::tableName();
        $qualified = self::inList(LeadStatus::QUALIFIED);
        $won       = self::inList(LeadStatus::WON);
        $excluded  = self::inList(LeadStatus::EXCLUDED_FROM_TOTALS);

        $row = ReportQuery::rows($wpdb->prepare(
            "SELECT COUNT(*) AS leads,
                    SUM(CASE WHEN lead_status IN ({$qualified}) THEN 1 ELSE 0 END) AS qualified,
                    SUM(CASE WHEN lead_status IN ({$won}) THEN 1 ELSE 0 END) AS won,
                    SUM(CASE WHEN lead_value IS NOT NULL THEN 1 ELSE 0 END) AS valued
             FROM {$table}
             WHERE created_at >= %s AND created_at < %s
               AND lead_status NOT IN ({$excluded})",
            $start,
            $end
        ))[0] ?? [];

        $leads     = (int) ($row['leads'] ?? 0);
        $qualCount = (int) ($row['qualified'] ?? 0);
        $wonCount  = (int) ($row['won'] ?? 0);

        return [
            'leads'          => $leads,
            'qualified'      => $qualCount,
            'won'            => $wonCount,
            'valued'         => (int) ($row['valued'] ?? 0),
            'qualified_rate' => self::rate($qualCount, $leads),
            'win_rate'       => self::rate($wonCount, $leads),
        ];
    }

    /**
     * How long visitors took to become confirmed leads, in buckets.
     *
     * WITHIN-SESSION ONLY. The lag is measured from the first pageview of the
     * session that produced the submission, so a visitor who researched for a
     * week and returned in a fresh session is measured from that final visit.
     * The plugin has no privacy-safe persistent visitor identity and this
     * release does not manufacture one — reporting a cross-visit figure derived
     * from an identity that does not exist would be worse than reporting the
     * narrower truth.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return array{buckets: array<string, int>, medians: array<string, int>, sampled: int}
     */
    public static function timeToLead(string $start, string $end): array
    {
        global $wpdb;

        $submissions = FormSubmissions::tableName();
        $events      = DatabaseManager::tableName();

        $rows = ReportQuery::rows($wpdb->prepare(
            "SELECT s.channel,
                    TIMESTAMPDIFF(
                        SECOND,
                        (SELECT MIN(e.created_at) FROM {$events} AS e
                         WHERE e.event_type = 'pageview' AND e.session_id = s.session_id),
                        s.created_at
                    ) AS lag_seconds
             FROM {$submissions} AS s
             WHERE s.session_id <> ''
               AND s.created_at >= %s AND s.created_at < %s
             ORDER BY s.id DESC
             LIMIT %d",
            $start,
            $end,
            self::LAG_SAMPLE_LIMIT
        ));

        $buckets  = array_fill_keys(array_keys(self::buckets()), 0);
        $byChannel = [];
        $sampled  = 0;

        foreach ($rows as $row) {
            $lag = $row['lag_seconds'];

            // NULL means the session had no recorded pageview — a server-to-server
            // submission, or one whose analytics were never captured. Negative
            // would mean the submission preceded the session's first pageview,
            // which can only be a data anomaly. Neither is a time to conversion.
            if ($lag === null || (int) $lag < 0) {
                continue;
            }

            $lag = (int) $lag;
            $sampled++;
            $buckets[self::bucketFor($lag)]++;

            $channel = (string) ($row['channel'] ?? '');
            if ($channel !== '') {
                $byChannel[$channel][] = $lag;
            }
        }

        $medians = [];
        foreach ($byChannel as $channel => $lags) {
            $medians[$channel] = self::median($lags);
        }

        arsort($medians);

        return ['buckets' => $buckets, 'medians' => $medians, 'sampled' => $sampled];
    }

    /**
     * The time-to-lead buckets, as key => upper bound in seconds (null = open).
     *
     * @return non-empty-array<string, int|null> Ordered, and the last entry's
     *         ceiling is always null — "everything above" — which is what makes
     *         {@see bucketFor()} total.
     */
    public static function buckets(): array
    {
        return [
            'Under 5 minutes'      => 5 * MINUTE_IN_SECONDS,
            '5–30 minutes'         => 30 * MINUTE_IN_SECONDS,
            '30 minutes–24 hours'  => DAY_IN_SECONDS,
            '1–7 days'             => WEEK_IN_SECONDS,
            '7+ days'              => null,
        ];
    }

    /**
     * The bucket a lag falls into.
     *
     * @param int $seconds Lag in seconds.
     * @return string
     */
    public static function bucketFor(int $seconds): string
    {
        foreach (self::buckets() as $label => $ceiling) {
            if ($ceiling === null || $seconds < $ceiling) {
                return $label;
            }
        }

        return array_key_last(self::buckets());
    }

    /**
     * The median of a list of integers.
     *
     * PURE. The median rather than the mean, because time-to-conversion is
     * heavily right-skewed: one visitor who converted after three weeks would
     * drag a mean past every real experience of the site.
     *
     * @param list<int> $values Unsorted values.
     * @return int
     */
    public static function median(array $values): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $count  = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * A SQL quoted list from a fixed status set.
     *
     * Safe by construction: values come from {@see LeadStatus}'s own constants,
     * never from a request. Building them as literals keeps the surrounding
     * queries readable and their parameter lists short.
     *
     * @param string[] $statuses Status values.
     * @return string
     */
    private static function inList(array $statuses): string
    {
        return "'" . implode("', '", array_map('sanitize_key', $statuses)) . "'";
    }

    /**
     * A percentage, with a zero denominator yielding 0.0.
     *
     * Callers render '—' rather than '0%' when the denominator is zero: a
     * channel that produced no leads has no qualification rate, and printing 0%
     * implies it produced leads that all failed.
     *
     * @param int $part  Numerator.
     * @param int $whole Denominator.
     * @return float
     */
    public static function rate(int $part, int $whole): float
    {
        if ($whole <= 0) {
            return 0.0;
        }

        return min(100.0, round($part / $whole * 100, 1));
    }
}
