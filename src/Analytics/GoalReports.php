<?php
declare(strict_types=1);

namespace Convermetry\Analytics;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Goals\GoalCompletions;

/**
 * Aggregate queries over goal completions.
 *
 * Every method takes a UTC datetime range and returns plain arrays, matching
 * {@see Reports}. All reads go through {@see ReportQuery} so a failed query
 * throws instead of returning an empty result a caller would render as zero.
 *
 * NO JOINS. Every dimension a breakdown needs — channel, source, medium,
 * campaign, landing page, device — is denormalized onto the completion row at
 * ingestion. That is the reason for those columns: a "completions by campaign
 * over time" query on a table that grows with traffic stays a single indexed
 * scan rather than a join against the events table.
 *
 * THE CONVERSION-RATE DENOMINATOR IS SESSIONS, and it comes from the events
 * table because that is the only place sessions are counted. A goal's
 * conversion rate is "sessions that completed this goal ÷ sessions in the
 * window", which is the number a marketer means. Dividing completions by
 * sessions instead would let an every-occurrence goal report above 100%.
 */
final class GoalReports
{
    /**
     * Per-goal totals for a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return array<int, array{goal_id: string, completions: int, sessions: int, value: string, currency: string}>
     */
    public static function totals(string $start, string $end): array
    {
        global $wpdb;
        $table = GoalCompletions::tableName();

        $rows = ReportQuery::rows($wpdb->prepare(
            "SELECT goal_id,
                    COUNT(*) AS completions,
                    COUNT(DISTINCT NULLIF(session_id, '')) AS sessions,
                    COALESCE(SUM(value), 0) AS total_value,
                    MAX(currency) AS currency
             FROM {$table}
             WHERE created_at >= %s AND created_at < %s
             GROUP BY goal_id
             ORDER BY completions DESC",
            $start,
            $end
        ));

        return array_map(static fn(array $row): array => [
            'goal_id'     => (string) $row['goal_id'],
            'completions' => (int) $row['completions'],
            'sessions'    => (int) $row['sessions'],
            // Kept as the string the DECIMAL column produced. Casting to float
            // here would reintroduce exactly the drift the column exists to
            // prevent, one line before the number is displayed.
            'value'       => (string) $row['total_value'],
            'currency'    => (string) ($row['currency'] ?? ''),
        ], $rows);
    }

    /**
     * Sessions in a range — the denominator for every goal conversion rate.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return int
     */
    public static function sessionCount(string $start, string $end): int
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        return (int) ReportQuery::value($wpdb->prepare(
            "SELECT COUNT(DISTINCT session_id)
             FROM {$table}
             WHERE event_type = 'pageview' AND session_id <> ''
               AND created_at >= %s AND created_at < %s",
            $start,
            $end
        ));
    }

    /**
     * One goal's completions broken down by a marketing dimension.
     *
     * The column name is never interpolated from caller input — only the five
     * names below are accepted, and anything else returns nothing.
     *
     * @param string $start     UTC datetime (inclusive).
     * @param string $end       UTC datetime (exclusive).
     * @param string $goalId    Immutable goal id, or '' for all goals combined.
     * @param string $dimension channel, utm_source, utm_medium, utm_campaign, landing_page, or page_url.
     * @param int    $limit     Maximum rows.
     * @return array<int, array{label: string, completions: int, sessions: int, value: string}>
     */
    public static function breakdown(
        string $start,
        string $end,
        string $goalId,
        string $dimension,
        int $limit = 10
    ): array {
        global $wpdb;

        $allowed = ['channel', 'utm_source', 'utm_medium', 'utm_campaign', 'landing_page', 'page_url'];
        if (!in_array($dimension, $allowed, true)) {
            return [];
        }

        $table  = GoalCompletions::tableName();
        $where  = 'created_at >= %s AND created_at < %s';
        $params = [$start, $end];

        if ($goalId !== '') {
            $where   .= ' AND goal_id = %s';
            $params[] = $goalId;
        }

        $params[] = $limit;

        $rows = ReportQuery::rows($wpdb->prepare(
            "SELECT {$dimension} AS label,
                    COUNT(*) AS completions,
                    COUNT(DISTINCT NULLIF(session_id, '')) AS sessions,
                    COALESCE(SUM(value), 0) AS total_value
             FROM {$table}
             WHERE {$where}
             GROUP BY {$dimension}
             ORDER BY completions DESC
             LIMIT %d",
            $params
        ));

        return array_map(static fn(array $row): array => [
            'label'       => (string) $row['label'],
            'completions' => (int) $row['completions'],
            'sessions'    => (int) $row['sessions'],
            'value'       => (string) $row['total_value'],
        ], $rows);
    }

    /**
     * Daily completion counts, zero-filled so charts always have one entry per
     * calendar day.
     *
     * @param string $start  UTC datetime (inclusive).
     * @param string $end    UTC datetime (exclusive).
     * @param string $goalId Immutable goal id, or '' for all goals.
     * @return array<int, array{date: string, count: int}>
     */
    public static function daily(string $start, string $end, string $goalId = ''): array
    {
        global $wpdb;
        $table = GoalCompletions::tableName();

        $where  = 'created_at >= %s AND created_at < %s';
        $params = [$start, $end];

        if ($goalId !== '') {
            $where   .= ' AND goal_id = %s';
            $params[] = $goalId;
        }

        $rows = ReportQuery::rows($wpdb->prepare(
            "SELECT DATE(created_at) AS day, COUNT(*) AS total
             FROM {$table}
             WHERE {$where}
             GROUP BY day
             ORDER BY day ASC",
            $params
        ));

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[(string) $row['day']] = (int) $row['total'];
        }

        $series  = [];
        $current = strtotime(substr($start, 0, 10) . ' 00:00:00 UTC');
        $last    = strtotime(substr($end, 0, 10) . ' 00:00:00 UTC');

        while ($current !== false && $last !== false && $current <= $last) {
            $day      = gmdate('Y-m-d', $current);
            $series[] = ['date' => $day, 'count' => $byDay[$day] ?? 0];
            $current += DAY_IN_SECONDS;
        }

        return $series;
    }

    /**
     * The most recent completion timestamp for each goal.
     *
     * Used by the Goals screen to distinguish "configured and collecting" from
     * "configured and silent" — a goal that has never fired is almost always a
     * rule that does not match anything, and saying so is more useful than
     * showing a zero.
     *
     * @return array<string, string> goal_id → 'Y-m-d H:i:s'.
     */
    public static function lastSeen(): array
    {
        global $wpdb;
        $table = GoalCompletions::tableName();

        $rows = ReportQuery::rows(
            "SELECT goal_id, MAX(created_at) AS last_at FROM {$table} GROUP BY goal_id"
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['goal_id']] = (string) $row['last_at'];
        }

        return $out;
    }

    /**
     * The per-goal summary shared by the Goals screen and the analytics webhook
     * payload, with conversion rates already computed.
     *
     * @param string                $start UTC datetime (inclusive).
     * @param string                $end   UTC datetime (exclusive).
     * @param array<string, string> $names goal_id → display name.
     * @param int                   $limit Maximum goals returned.
     * @return array{goals: list<array<string, mixed>>, sessions: int, truncated: bool, total: int}
     */
    public static function summary(string $start, string $end, array $names, int $limit = 100): array
    {
        $totals   = self::totals($start, $end);
        $sessions = self::sessionCount($start, $end);

        $goals = [];
        foreach ($totals as $row) {
            $goalId  = $row['goal_id'];
            $goals[] = [
                'goal_id'         => $goalId,
                'name'            => $names[$goalId] ?? $goalId,
                'completions'     => $row['completions'],
                'sessions'        => $row['sessions'],
                'conversion_rate' => self::rate($row['sessions'], $sessions),
                'value'           => $row['value'],
                'currency'        => $row['currency'],
            ];
        }

        // Deterministic ordering before the cut, so a truncated payload always
        // contains the same goals rather than whatever the optimizer returned
        // first. totals() already orders by completions; goal_id breaks ties.
        usort($goals, static function (array $a, array $b): int {
            return [$b['completions'], $a['goal_id']] <=> [$a['completions'], $b['goal_id']];
        });

        $total = count($goals);

        return [
            'goals'     => array_slice($goals, 0, $limit),
            'sessions'  => $sessions,
            'truncated' => $total > $limit,
            'total'     => $total,
        ];
    }

    /**
     * A percentage, capped at 100, with a zero denominator yielding 0.0.
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

        return min(100.0, round($part / $whole * 100, 2));
    }
}
