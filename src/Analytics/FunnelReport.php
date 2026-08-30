<?php
declare(strict_types=1);

namespace Convermetry\Analytics;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Funnels\FunnelSettings;
use Convermetry\Funnels\StepCompiler;
use Convermetry\Goals\GoalCompletions;

/**
 * Computes one funnel's step-by-step conversion in a single SQL statement.
 *
 * ORDERED PROGRESSION IS THE WHOLE PROBLEM.
 *
 * The obvious implementation — take each step's earliest occurrence per session
 * and compare timestamps — is wrong, and wrong in a way that looks right on
 * small data. For a session that did B at 09:00, A at 10:00, and B again at
 * 11:00, an A→B funnel SHOULD succeed: the visitor did A and then did B. But
 * MIN(B) is 09:00, which is before A, so an independent-minimums query reports
 * failure. The reverse error is just as available: B→A would falsely succeed.
 *
 * So each step's position is constrained to be strictly AFTER the previous
 * step's, with a correlated subquery per step:
 *
 *     p1 = MIN(id)               WHERE <step 1>
 *     p2 = MIN(id)               WHERE <step 2> AND id > p1
 *     p3 = MIN(id)               WHERE <step 3> AND id > p2
 *
 * WHY id AND NOT created_at. The events table's created_at is the moment the
 * row was INSERTED — the tracker sends no client timestamp — so created_at
 * order and id order are the same order by construction, and id is the finer,
 * tie-free version of it. created_at has one-second resolution and a whole
 * batch commonly lands inside one second, which would make ties routine rather
 * than exotic. The consequence, documented on {@see DatabaseManager}, is that
 * funnel order is INGESTION order: within one batch the browser's order is
 * preserved, but a batch that failed and was resent from a later page sorts by
 * when it arrived.
 *
 * NO WINDOW FUNCTIONS AND NO LATERAL. WordPress 6.3 still supports MySQL 5.7
 * and MariaDB 10.4, and neither offers LATERAL; the nested-derived-table chain
 * below runs everywhere the plugin claims to run.
 *
 * SESSIONS WITH NO ID ARE EXCLUDED. An empty session_id is not one visitor, it
 * is every visitor whose session could not be established — grouping on it
 * would collapse them into a single enormous pseudo-session that appears to
 * complete every funnel.
 */
final class FunnelReport
{
    /**
     * Hours after the reporting window in which a later step still counts.
     *
     * A funnel is a COHORT: the sessions that entered at step 1 during the
     * window. Requiring every subsequent step to also fall inside the window
     * would penalise a session that entered at 23:55 on the last day, and the
     * reported conversion rate would sag at every window edge for reasons that
     * have nothing to do with the site.
     */
    public const int COMPLETION_WINDOW_HOURS = 24;

    /** Seconds a computed funnel is cached. */
    private const int CACHE_TTL = 5 * MINUTE_IN_SECONDS;

    /**
     * Computes one funnel.
     *
     * @param array<string, mixed> $funnel A normalized funnel.
     * @param string               $start  UTC datetime (inclusive).
     * @param string               $end    UTC datetime (exclusive).
     * @return array{steps: list<array<string, mixed>>, overall_rate: float, error: string}
     * @throws ReportQueryException When the query itself fails.
     */
    public static function compute(array $funnel, string $start, string $end): array
    {
        $steps = is_array($funnel['steps'] ?? null) ? $funnel['steps'] : [];

        if (count($steps) < 2) {
            return ['steps' => [], 'overall_rate' => 0.0, 'error' => 'A funnel needs at least two steps.'];
        }

        $cacheKey = self::cacheKey($funnel, $start, $end);
        $cached   = self::cachedReport(get_transient($cacheKey));

        if ($cached !== null) {
            return $cached;
        }

        $built = self::buildQuery($steps, $start, $end);

        if ($built === null) {
            return [
                'steps'        => [],
                'overall_rate' => 0.0,
                'error'        => 'One of this funnel\'s steps is not fully configured, so it cannot be measured.',
            ];
        }

        global $wpdb;

        $row = ReportQuery::rows($wpdb->prepare($built['sql'], $built['params']))[0] ?? [];

        $result = self::shape($steps, $row);

        set_transient($cacheKey, $result, self::CACHE_TTL);

        return $result;
    }

    /**
     * Builds the chained statement.
     *
     * @param array<int, array<string, mixed>> $steps Normalized steps.
     * @param string                           $start UTC datetime (inclusive).
     * @param string                           $end   UTC datetime (exclusive).
     * @return array{sql: string, params: list<mixed>}|null
     */
    private static function buildQuery(array $steps, string $start, string $end): ?array
    {
        $events = DatabaseManager::tableName();
        $goals  = GoalCompletions::tableName();

        // Later steps may land after the window closes; see COMPLETION_WINDOW_HOURS.
        $completionEnd = gmdate(
            'Y-m-d H:i:s',
            (int) strtotime($end . ' UTC') + self::COMPLETION_WINDOW_HOURS * HOUR_IN_SECONDS
        );

        $first = StepCompiler::compile($steps[0], 'e0');
        if ($first === null) {
            return null;
        }

        $firstTable    = $first['source'] === 'goals' ? $goals : $events;
        $firstPosition = StepCompiler::isGoalStep($steps[0]) ? 'e0.source_event_id' : 'e0.id';

        // The innermost derived table: every session that reached step 1 inside
        // the window, with the position at which it first did.
        $sql = "SELECT e0.session_id, MIN({$firstPosition}) AS p0"
             . " FROM {$firstTable} AS e0"
             . " WHERE ({$first['sql']}) AND e0.session_id <> ''"
             . ' AND e0.created_at >= %s AND e0.created_at < %s'
             . ' GROUP BY e0.session_id';

        // PARAMETER ORDER FOLLOWS THE FINAL SQL TEXT, NOT THE STEP ORDER.
        //
        // The statement is assembled outside-in: each iteration WRAPS what came
        // before, and the new step's correlated subquery is written ahead of the
        // nested FROM. So in the finished string the LAST step's placeholders
        // appear FIRST and step 0's appear last. Appending each step's
        // parameters in step order would therefore bind them to the wrong
        // placeholders — silently, since they are all %s — and the query would
        // still run, comparing a page URL against a timestamp and quietly
        // returning zero for every funnel.
        //
        // Each iteration prepends instead. This base is the innermost group and
        // stays at the end.
        $params = array_merge($first['params'], [$start, $end]);
        $inner  = 't0';
        $sql    = "SELECT t0.session_id, t0.p0 FROM ({$sql}) AS t0";

        for ($i = 1; $i < count($steps); $i++) {
            $compiled = StepCompiler::compile($steps[$i], 'e' . $i);
            if ($compiled === null) {
                return null;
            }

            $table    = $compiled['source'] === 'goals' ? $goals : $events;
            $position = StepCompiler::isGoalStep($steps[$i])
                ? 'e' . $i . '.source_event_id'
                : 'e' . $i . '.id';

            $previous = 'p' . ($i - 1);
            $current  = 'p' . $i;
            $outer    = 't' . $i;

            // Strictly greater than the previous step's position — this is the
            // ordering guarantee, and the reason the whole query is nested
            // rather than a set of independent aggregates.
            $correlated = "(SELECT MIN({$position}) FROM {$table} AS e{$i}"
                . " WHERE e{$i}.session_id = {$inner}.session_id"
                . " AND {$position} > {$inner}.{$previous}"
                . " AND ({$compiled['sql']})"
                . " AND e{$i}.created_at < %s) AS {$current}";

            $carried = [];
            for ($c = 0; $c < $i; $c++) {
                $carried[] = "{$inner}.p{$c}";
            }

            $sql = "SELECT {$inner}.session_id, " . implode(', ', $carried) . ", {$correlated}"
                 . " FROM ({$sql}) AS {$inner}";

            // Prepended, not appended — see the note where $params is seeded.
            $params = array_merge($compiled['params'], [$completionEnd], $params);

            $inner = $outer;
        }

        // The outermost aggregate. Step 1 is every row; each later step is the
        // rows whose position for that step resolved.
        $counts = ['COUNT(*) AS s0'];
        for ($i = 1; $i < count($steps); $i++) {
            $counts[] = "SUM(CASE WHEN {$inner}.p{$i} IS NOT NULL THEN 1 ELSE 0 END) AS s{$i}";
        }

        return [
            'sql'    => 'SELECT ' . implode(', ', $counts) . " FROM ({$sql}) AS {$inner}",
            'params' => $params,
        ];
    }

    /**
     * A cached funnel report, or null when the transient holds anything else.
     *
     * The shape is checked rather than assumed. A transient is shared, mutable
     * storage: the value can have been written by a previous plugin version
     * whose report had different keys, or replaced wholesale by an object cache
     * or another plugin. Returning it unchecked would hand the view a report
     * with no 'steps' to iterate, and the failure would surface as a fatal in a
     * template rather than as a cache miss here.
     *
     * @param mixed $cached Whatever get_transient() returned.
     * @return array{steps: list<array<string, mixed>>, overall_rate: float, error: string}|null
     */
    private static function cachedReport(mixed $cached): ?array
    {
        if (
            !is_array($cached)
            || !isset($cached['steps'], $cached['overall_rate'], $cached['error'])
            || !is_array($cached['steps'])
            || !array_is_list($cached['steps'])
            || !is_float($cached['overall_rate'])
            || !is_string($cached['error'])
        ) {
            return null;
        }

        foreach ($cached['steps'] as $step) {
            if (!is_array($step)) {
                return null;
            }
        }

        return $cached;
    }

    /**
     * Turns the raw counts into the per-step report.
     *
     * PURE, and separated from the SQL on purpose: drop-off arithmetic is
     * exactly the kind of thing that is wrong by one somewhere and needs to be
     * testable without a database. {@see FunnelMathTest} drives this directly.
     *
     * @param array<int, array<string, mixed>> $steps Normalized steps.
     * @param array<string, mixed>             $row   One row of s0..sN counts.
     * @return array{steps: list<array<string, mixed>>, overall_rate: float, error: string}
     */
    public static function shape(array $steps, array $row): array
    {
        $counts = [];
        foreach (array_keys(array_values($steps)) as $index) {
            $counts[] = (int) ($row['s' . $index] ?? 0);
        }

        $entered = $counts[0] ?? 0;
        $out     = [];

        foreach ($counts as $index => $sessions) {
            $previous = $index === 0 ? null : $counts[$index - 1];

            $out[] = [
                'label'        => (string) ($steps[$index]['label'] ?? ''),
                'type'         => (string) ($steps[$index]['type'] ?? ''),
                'sessions'     => $sessions,
                // Drop-off is measured against the PREVIOUS step, not the first:
                // "62% of the people who reached Services went on to start the
                // form" is the question a funnel is asked. The overall rate
                // below answers the other one.
                'dropped'      => $previous === null ? 0 : max(0, $previous - $sessions),
                'drop_rate'    => $previous === null ? 0.0 : self::rate(max(0, $previous - $sessions), $previous),
                // The first step is measured against itself, which yields 100%
                // when anyone entered and 0% when nobody did. Hardcoding 100
                // here would render a full bar for a funnel with no sessions in
                // it at all.
                'step_rate'    => self::rate($sessions, $previous ?? $sessions),
                'overall_rate' => self::rate($sessions, $entered),
            ];
        }

        return [
            'steps'        => $out,
            'overall_rate' => self::rate($counts[count($counts) - 1] ?? 0, $entered),
            'error'        => '',
        ];
    }

    /**
     * A percentage, with a zero denominator yielding 0.0.
     *
     * A funnel nobody entered has no conversion rate — not a rate of zero. The
     * distinction is preserved by the callers, which render '—' when the
     * denominator is zero rather than printing "0%" and implying that a
     * thousand people failed to convert.
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

    /**
     * The transient key for one computed funnel.
     *
     * The funnel's definition hash is part of the key, so editing a step
     * invalidates the cache by construction rather than by remembering to clear
     * it — the commonest way a cached report goes stale.
     *
     * @param array<string, mixed> $funnel A normalized funnel.
     * @param string               $start  UTC datetime.
     * @param string               $end    UTC datetime.
     * @return string
     */
    private static function cacheKey(array $funnel, string $start, string $end): string
    {
        return 'cvm_funnel_' . md5(implode('|', [
            (string) ($funnel['funnel_id'] ?? ''),
            FunnelSettings::definitionHash($funnel),
            $start,
            $end,
        ]));
    }
}
