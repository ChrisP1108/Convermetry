<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

/**
 * The shared vocabulary and announcement points for retention passes.
 *
 * Five stores prune themselves on the daily cleanup cron — events, delivery
 * log, form submissions, goal completions, and lead history — each with its own
 * chunked delete loop, its own chunk size, and its own time budget. What they
 * had in common was left implicit: four returned nothing at all, and the fifth
 * returned a status string only it understood.
 *
 * This class supplies one outcome shape for all of them without touching a
 * single loop condition. {@see outcome()} is a pure derivation from state the
 * loops already hold when they exit — the last chunk's affected-row count and
 * the running total — so the deletion behaviour those loops encode is byte-for
 * byte what it was, and "did more rows remain?" is answered rather than guessed:
 *
 *  - the last delete returned a non-int  → the query failed; assume rows remain,
 *  - it deleted fewer rows than a chunk  → the table is drained,
 *  - it deleted a full chunk             → the loop stopped on its chunk budget
 *                                          or its deadline, so rows remain.
 *
 * The hooks are strictly observational. A listener cannot cancel a pass, extend
 * a retention period, or keep a row alive past the administrator's setting —
 * retention is a privacy promise the site owner makes, not a negotiation.
 */
final class Retention
{
    /** The pruned table is fully drained down to the cutoff. */
    public const string COMPLETED = 'completed';

    /** The pass stopped on its chunk or time budget with rows still older than the cutoff. */
    public const string TRUNCATED = 'truncated';

    /** A delete query failed; how many rows remain is unknown. */
    public const string QUERY_FAILED = 'query_failed';

    /** The pass lost its cleanup lease to another worker and stopped early. */
    public const string LOCK_LOST = 'lock_lost';

    /**
     * Derives a pass's outcome from the state its loop already holds on exit.
     *
     * @param mixed $lastDeleted  What the final $wpdb->query() delete returned (int|false|null).
     * @param int   $chunk        The pass's per-query LIMIT.
     * @param int   $deletedTotal Rows deleted across the whole pass.
     * @return array{deleted: int, outcome: string, more_remain: bool}
     */
    public static function outcome(mixed $lastDeleted, int $chunk, int $deletedTotal): array
    {
        if (!is_int($lastDeleted)) {
            return ['deleted' => $deletedTotal, 'outcome' => self::QUERY_FAILED, 'more_remain' => true];
        }

        if ($lastDeleted < $chunk) {
            return ['deleted' => $deletedTotal, 'outcome' => self::COMPLETED, 'more_remain' => false];
        }

        return ['deleted' => $deletedTotal, 'outcome' => self::TRUNCATED, 'more_remain' => true];
    }

    /**
     * The outcome for a pass that lost its cleanup lease mid-run.
     *
     * @param int $deletedTotal Rows deleted before the lease was lost.
     * @return array{deleted: int, outcome: string, more_remain: bool}
     */
    public static function lockLost(int $deletedTotal): array
    {
        return ['deleted' => $deletedTotal, 'outcome' => self::LOCK_LOST, 'more_remain' => true];
    }

    /**
     * Announces the start of one store's retention pass.
     *
     * @param string $store  Store being pruned ('events', 'delivery_log', 'form_submissions', …).
     * @param string $cutoff UTC 'Y-m-d H:i:s' cutoff; everything older is deleted.
     * @return void
     */
    public static function started(string $store, string $cutoff): void
    {
        /**
         * Fires immediately before one store begins deleting rows past the
         * administrator's retention period.
         *
         * Observational only. Returning a value does nothing: a listener cannot
         * cancel the pass, change the cutoff, or extend the retention period —
         * that setting is a privacy promise the site owner made, and this hook
         * is not a way to renege on it.
         *
         * Fires once per store per cleanup pass, including catch-up passes.
         *
         * @param string $store  Store being pruned.
         * @param string $cutoff UTC 'Y-m-d H:i:s' cutoff.
         */
        do_action('convermetry_retention_cleanup_started', $store, $cutoff);
    }

    /**
     * Announces the end of one store's retention pass.
     *
     * @param string                                                  $store   Store that was pruned.
     * @param string                                                  $cutoff  UTC cutoff used.
     * @param array{deleted: int, outcome: string, more_remain: bool} $outcome Result from {@see outcome()}.
     * @return void
     */
    public static function completed(string $store, string $cutoff, array $outcome): void
    {
        /**
         * Fires after one store's retention pass has finished, whether it
         * drained the table or stopped on a budget.
         *
         * Observational only. $outcome['more_remain'] being true means another
         * pass is needed; Convermetry schedules that itself, and a listener must
         * not attempt to drive cleanup on its own.
         *
         * Fires once per store per cleanup pass, including catch-up passes.
         *
         * @param string $store       Store that was pruned.
         * @param string $cutoff      UTC 'Y-m-d H:i:s' cutoff used.
         * @param int    $deleted     Rows deleted in this pass.
         * @param bool   $moreRemain  Whether rows older than the cutoff remain.
         * @param string $outcomeCode One of 'completed', 'truncated', 'query_failed', 'lock_lost'.
         */
        do_action(
            'convermetry_retention_cleanup_completed',
            $store,
            $cutoff,
            (int) $outcome['deleted'],
            (bool) $outcome['more_remain'],
            (string) $outcome['outcome']
        );
    }
}
