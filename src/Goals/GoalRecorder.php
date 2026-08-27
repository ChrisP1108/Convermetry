<?php
declare(strict_types=1);

namespace Convermetry\Goals;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\PreparedEvent;
use Convermetry\Settings\Options;

/**
 * Turns matched goals into stored completions.
 *
 * The ingestion seam sits in three parts around the event insert, and the order
 * is the whole design:
 *
 *   1. {@see plan()}      — BEFORE the insert. Matches goals, and reports which
 *                           events should not be stored at all.
 *   2. (events are inserted, and their ids resolved)
 *   3. {@see record()}    — AFTER the insert. Writes completions carrying the
 *                           id of the event that triggered them.
 *
 * WHY MATCHING RUNS BEFORE THE INSERT
 *
 * A custom event exists only to be matched. Site code calls
 * Convermetry.track('whatever') and, if no goal is configured for that name, the
 * event means nothing to anyone — storing it would let one typo in a theme's
 * JavaScript fill the events table with rows no report will ever read. Matching
 * first is what makes "unmatched custom events are never stored" true rather
 * than aspirational: by the time the insert runs, they are already gone.
 *
 * Every OTHER event type is stored regardless of whether it completed a goal. A
 * pageview is a pageview whether or not somebody declared it valuable.
 *
 * WHY COMPLETIONS CARRY source_event_id
 *
 * Funnels ask "did step B happen after step A?", and answer it by comparing
 * event ids (see the ordering note on
 * {@see \Convermetry\Database\DatabaseManager}). A goal step therefore needs a
 * position on that same scale.
 *
 * The obvious approach — write a marker row into the events table for each
 * completion — is WRONG, and subtly so. Markers can only be appended after the
 * batch's own rows are inserted, so a goal that fired FIRST in a batch would
 * receive a HIGHER id than the events that followed it. An A→B funnel would then
 * report failure while B→A falsely succeeded. Carrying the triggering event's
 * own id avoids the problem rather than compensating for it, and it removes the
 * second write entirely — so there is no window in which a completion exists
 * without its ordering information.
 *
 * DEDUPLICATION IS A UNIQUE INDEX, NOT A PHP CHECK. See
 * {@see GoalCompletions} for the key construction and why a session-less event
 * degrades to every-occurrence instead of collapsing.
 */
final class GoalRecorder
{
    /** Transient recording goal matches dropped by the per-event cap. */
    public const string OVERFLOW_TRANSIENT = 'cvm_goal_overflow';

    /**
     * Matches an event batch against the configured goals.
     *
     * Returns the plan for the batch: which envelope indexes matched which
     * goals, and which should be dropped before the insert.
     *
     * @param array<int, PreparedEvent> $events Prepared envelopes, keyed by batch position.
     * @return array{matches: array<int, list<array<string, mixed>>>, drop: list<int>}
     */
    public static function plan(array $events): array
    {
        $goals = GoalRepository::active();

        $matches  = [];
        $drop     = [];
        $overflow = 0;

        foreach ($events as $index => $event) {
            $isCustom = $event->type() === 'custom_event';

            // No goals configured at all: a custom event has nothing it could
            // ever mean, so it is dropped without the matcher running.
            if ($goals === []) {
                if ($isCustom) {
                    $drop[] = $index;
                }
                continue;
            }

            $result = GoalMatcher::match($event, $goals);
            $overflow += $result['overflow'];

            if ($result['matched'] !== []) {
                $matches[$index] = $result['matched'];
                continue;
            }

            if ($isCustom) {
                $drop[] = $index;
            }
        }

        if ($overflow > 0) {
            self::recordOverflow($overflow);
        }

        return ['matches' => $matches, 'drop' => $drop];
    }

    /**
     * Writes the completions for a planned batch.
     *
     * Called after the events are stored and their ids resolved, so each
     * completion can carry its triggering event's id. An envelope whose id could
     * not be resolved still records its completion — the completion is the
     * conversion, and refusing to count it because a funnel could not order it
     * would trade a real number for a hypothetical one. It simply cannot
     * participate in funnel ordering (see {@see GoalCompletions}).
     *
     * @param array<int, PreparedEvent>                   $events  Prepared envelopes.
     * @param array<int, list<array<string, mixed>>>      $matches Goals matched per envelope index.
     * @param string                                      $now     UTC 'Y-m-d H:i:s'.
     * @return int Number of NEW completions stored.
     */
    public static function record(array $events, array $matches, string $now): int
    {
        if ($matches === []) {
            return 0;
        }

        $currency = Options::leadCurrency();
        $rows     = [];

        foreach ($matches as $index => $goals) {
            $event = $events[$index] ?? null;
            if (!$event instanceof PreparedEvent) {
                continue;
            }

            foreach ($goals as $goal) {
                $row = self::buildRow($event, $goal, $currency, $now);

                /**
                 * Filters whether to record one matched goal completion.
                 *
                 * Runs once per matched goal per event, after the completion row
                 * is fully built and before it is offered to the INSERT. Return
                 * false to drop this completion; the rest of the batch is
                 * unaffected.
                 *
                 * A decision only. The row is passed so a callback can inspect
                 * the channel, campaign, page, or value it would record, but
                 * nothing returned here changes it: the completion id, goal id,
                 * definition hash, event uid, deduplication key, and timestamp
                 * are all identity, and a hook that could rewrite them could
                 * silently defeat once-per-session deduplication or attribute a
                 * completion to the wrong goal definition. Use
                 * convermetry_goal_completion, which runs just before this, to
                 * change the row's contents.
                 *
                 * Runs on the request that recorded the triggering event.
                 *
                 * @param bool                 $should Whether to record. Default true.
                 * @param array<string, mixed> $row    The completion row as it would be stored.
                 * @param array<string, mixed> $goal   The matched goal definition.
                 */
                if (apply_filters('convermetry_should_record_goal_completion', true, $row, $goal)) {
                    $rows[] = $row;
                }
            }
        }

        if ($rows === []) {
            return 0;
        }

        $stored = GoalCompletions::insertMany($rows);

        /**
         * Fires after a batch of goal completions has been recorded.
         *
         * @param int                              $stored Number of NEW completions stored.
         * @param array<int, array<string, mixed>> $rows   The completion rows that were offered.
         */
        do_action('convermetry_goal_matched', $stored, $rows);

        /**
         * Fires after a batch of goal completions has been offered to storage.
         *
         * The two counts are different numbers and the gap between them is the
         * point: completions are written with INSERT IGNORE against a unique
         * deduplication key, so $offered is how many matches were made and
         * $stored is how many were NEW. A once-per-session goal matched on a
         * visitor's fifth pageview contributes to $offered and not to $stored,
         * and that is correct rather than a failure.
         *
         * $completionIds lists the ids of the rows that were OFFERED, in the
         * same order. Convermetry does not read back which of them the INSERT
         * accepted — that would be a second query on the tracking write path —
         * so the list is not a list of stored ids and must not be treated as one.
         *
         * Fires once per batch, after the INSERT, only when at least one row was
         * offered. This is the counterpart of the older convermetry_goal_matched
         * action, which is unchanged and still fires immediately before it.
         *
         * @param int          $stored        Completions actually inserted.
         * @param int          $offered       Completion rows offered to the INSERT.
         * @param list<string> $completionIds Ids of the offered rows, in order.
         */
        do_action(
            'convermetry_goal_completions_recorded',
            $stored,
            count($rows),
            array_map(static fn(array $row): string => (string) ($row['completion_id'] ?? ''), $rows)
        );

        return $stored;
    }

    /**
     * Builds one completion row.
     *
     * The marketing dimensions are copied from the triggering event, which
     * already carries the session's attribution snapshot — so a completion is a
     * self-contained record and every breakdown reads one table.
     *
     * @param PreparedEvent        $event    The triggering event.
     * @param array<string, mixed> $goal     The matched goal.
     * @param string               $currency The site's lead currency.
     * @param string               $now      UTC 'Y-m-d H:i:s'.
     * @return array<string, mixed>
     */
    private static function buildRow(PreparedEvent $event, array $goal, string $currency, string $now): array
    {
        $goalId = (string) $goal['goal_id'];
        $hash   = (string) $goal['definition_hash'];

        $value = self::resolveValue($event, $goal);

        $row = [
            'completion_id'   => md5(wp_generate_uuid4() . wp_rand()),
            'goal_id'         => $goalId,
            'definition_hash' => $hash,
            'dedupe_key'      => GoalCompletions::dedupeKey(
                $goalId,
                $hash,
                $event->column('session_id'),
                $event->eventUid,
                !empty($goal['once_per_session'])
            ),
            'event_uid'       => $event->eventUid,
            'source_event_id' => $event->sourceEventId,
            'session_id'      => $event->column('session_id'),
            'page_url'        => $event->column('page_url'),
            'landing_page'    => $event->landingPage,
            'channel'         => $event->column('channel'),
            'utm_source'      => $event->column('utm_source'),
            'utm_medium'      => $event->column('utm_medium'),
            'utm_campaign'    => $event->column('utm_campaign'),
            'utm_id'          => $event->column('utm_id'),
            'device'          => $event->column('device'),
            'value'           => $value,
            'currency'        => $value === null ? '' : $currency,
            'created_at'      => $now,
        ];

        /**
         * Filters a goal completion just before it is written.
         *
         * Returning a non-array drops the completion.
         *
         * @param array<string, mixed>|false $row  The completion row.
         * @param array<string, mixed>       $goal The matched goal definition.
         */
        $filtered = apply_filters('convermetry_goal_completion', $row, $goal);

        return is_array($filtered) ? array_merge($row, $filtered) : $row;
    }

    /**
     * The monetary value to record against one completion.
     *
     * A goal's configured value is the default. A custom-event goal marked as
     * accepting a dynamic value may be overridden by the value the event
     * supplied — which has already been parsed to an exact decimal string by the
     * time it reaches here, so nothing arbitrary from a browser is stored.
     *
     * @param PreparedEvent        $event The triggering event.
     * @param array<string, mixed> $goal  The matched goal.
     * @return string|null A decimal string, or null for "no value".
     */
    private static function resolveValue(PreparedEvent $event, array $goal): ?string
    {
        if (!empty($goal['dynamic_value']) && $event->dynamicValue !== null) {
            return $event->dynamicValue;
        }

        $configured = $goal['goal_value'] ?? null;

        return is_string($configured) && $configured !== '' ? $configured : null;
    }

    /**
     * Records that the per-event match cap dropped some completions.
     *
     * A cap that silently discards conversions would be indistinguishable from a
     * bug, so the count is surfaced to the administrator rather than only
     * enforced. Stored as a transient because it is a nudge, not a record.
     *
     * @param int $count Matches dropped in this batch.
     * @return void
     */
    private static function recordOverflow(int $count): void
    {
        $existing = get_transient(self::OVERFLOW_TRANSIENT);
        $total    = is_array($existing) ? (int) ($existing['count'] ?? 0) : 0;

        set_transient(
            self::OVERFLOW_TRANSIENT,
            ['count' => $total + $count, 'at' => time()],
            WEEK_IN_SECONDS
        );
    }
}
