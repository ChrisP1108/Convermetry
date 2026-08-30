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
    /**
     * The four outcome codes, as the constants call sites and tests use.
     *
     * Defined from {@see RetentionStatus} rather than repeated as literals, so
     * the constant a caller compares against and the value the hook publishes
     * are the same string by construction.
     */
    public const string COMPLETED = RetentionStatus::Completed->value;
    public const string TRUNCATED = RetentionStatus::Truncated->value;
    public const string QUERY_FAILED = RetentionStatus::QueryFailed->value;
    public const string LOCK_LOST = RetentionStatus::LockLost->value;

    /**
     * Derives a pass's outcome from the state its loop already holds on exit.
     *
     * @param mixed $lastDeleted  What the final $wpdb->query() delete returned (int|false|null).
     * @param int   $chunk        The pass's per-query LIMIT.
     * @param int   $deletedTotal Rows deleted across the whole pass.
     * @return RetentionOutcome
     */
    public static function outcome(mixed $lastDeleted, int $chunk, int $deletedTotal): RetentionOutcome
    {
        if (!is_int($lastDeleted)) {
            return new RetentionOutcome($deletedTotal, RetentionStatus::QueryFailed);
        }

        if ($lastDeleted < $chunk) {
            return new RetentionOutcome($deletedTotal, RetentionStatus::Completed);
        }

        return new RetentionOutcome($deletedTotal, RetentionStatus::Truncated);
    }

    /**
     * The outcome for a pass that lost its cleanup lease mid-run.
     *
     * @param int $deletedTotal Rows deleted before the lease was lost.
     * @return RetentionOutcome
     */
    public static function lockLost(int $deletedTotal): RetentionOutcome
    {
        return new RetentionOutcome($deletedTotal, RetentionStatus::LockLost);
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
     * @param string           $store   Store that was pruned.
     * @param string           $cutoff  UTC cutoff used.
     * @param RetentionOutcome $outcome Result from {@see outcome()}.
     * @return void
     */
    public static function completed(string $store, string $cutoff, RetentionOutcome $outcome): void
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
            $outcome->deleted,
            $outcome->moreRemain(),
            $outcome->status->value
        );
    }
}
