<?php
declare(strict_types=1);

namespace Convermetry\Goals;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;

/**
 * Storage for conversion goal definitions.
 *
 * Goals live in one non-autoloaded option rather than a table. There are at most
 * {@see GoalSettings::MAX_GOALS} of them, they are read once per ingestion
 * request and otherwise only on their own admin screen, and they are edited by
 * hand a few times a year. A table would add a migration, a schema version, and
 * a set of queries to manage several dozen rows that comfortably fit in one
 * option — while the thing that actually needed a table, the COMPLETIONS, has
 * one.
 *
 * DELETION IS SOFT. A goal that is hard-deleted takes the meaning of its
 * completions with it: the rows remain (they are analytics history and are not
 * the site owner's to silently rewrite), but every report listing them would
 * show a bare id with no name. So a deleted goal keeps its definition and gains
 * a deleted_at stamp — it stops matching immediately, disappears from the
 * management list, and its historical completions stay labelled. Purging happens
 * on the retention window, with the completions themselves.
 */
final class GoalRepository
{
    /**
     * Memoized decode of the stored option, per request.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private static ?array $cache = null;

    /**
     * Every stored goal, including soft-deleted ones, in configuration order.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $stored = get_option(Options::GOALS_OPTION_KEY, []);
        $goals  = [];

        foreach (is_array($stored) ? $stored : [] as $raw) {
            $goal = GoalSettings::normalize($raw);
            if ($goal !== null) {
                $goals[] = $goal;
            }
        }

        return self::$cache = $goals;
    }

    /**
     * Goals that are currently collecting completions.
     *
     * This is what the ingestion path reads. Returning an empty array whenever
     * goals are switched off entirely means the matcher never runs and no
     * caller needs its own gate.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function active(): array
    {
        if (!Options::goalsEnabled()) {
            return [];
        }

        return array_values(array_filter(self::all(), GoalSettings::isActive(...)));
    }

    /**
     * Goals shown on the management screen (everything not soft-deleted).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function visible(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn(array $goal): bool => ($goal['deleted_at'] ?? null) === null
        ));
    }

    /**
     * One goal by id, including soft-deleted ones so reports can still name it.
     *
     * @param string $goalId Immutable goal id.
     * @return array<string, mixed>|null
     */
    public static function find(string $goalId): ?array
    {
        foreach (self::all() as $goal) {
            if ((string) $goal['goal_id'] === $goalId) {
                return $goal;
            }
        }

        return null;
    }

    /**
     * A map of goal id → display name, for labelling report rows.
     *
     * Includes soft-deleted goals, which is the entire reason it exists: a
     * completion recorded last month against a goal deleted yesterday still has
     * to render as something a human recognizes.
     *
     * @return array<string, string>
     */
    public static function names(): array
    {
        $names = [];
        foreach (self::all() as $goal) {
            $names[(string) $goal['goal_id']] = (string) $goal['name'];
        }

        return $names;
    }

    /**
     * The selectors shipped to the tracker, as goal id → CSS selector.
     *
     * Only enabled selector goals appear. This is the ONLY goal configuration
     * that ever reaches a browser, and it is deliberately the minimum needed:
     * the selector to test and the id to report back. No name, no value, no
     * operator, and nothing about any other goal.
     *
     * @return array<string, string>
     */
    public static function browserSelectors(): array
    {
        $out = [];

        foreach (self::active() as $goal) {
            if ((string) $goal['operator'] !== GoalSettings::BROWSER_OPERATOR) {
                continue;
            }

            $selector = (string) $goal['value'];
            if ($selector === '') {
                continue;
            }

            $out[(string) $goal['goal_id']] = $selector;

            if (count($out) >= self::MAX_BROWSER_SELECTORS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Maximum selectors sent to the tracker.
     *
     * Every one of these is evaluated with Element.closest() on every click, and
     * they are shipped in the page's inline configuration. A site that somehow
     * defines fifty selector goals should not make every visitor pay for all of
     * them.
     */
    public const int MAX_BROWSER_SELECTORS = 25;

    /**
     * Whether any enabled goal needs a given event type to be tracked.
     *
     * Used by the Goals screen to warn that a goal cannot fire, and by the
     * script loader to decide whether the tracker is needed at all.
     *
     * @param string $eventType An Options::EVENT_TYPES value.
     * @return bool
     */
    public static function needsEventType(string $eventType): bool
    {
        foreach (self::active() as $goal) {
            if (GoalSettings::requiredEventType($goal) === $eventType) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replaces one goal (or appends it when new) and persists.
     *
     * @param array<string, mixed> $goal A goal from {@see GoalSettings::sanitize()}.
     * @return bool True when stored.
     */
    public static function save(array $goal): bool
    {
        $goalId = (string) ($goal['goal_id'] ?? '');
        if (!GoalSettings::isValidId($goalId)) {
            return false;
        }

        $goals    = self::all();
        $replaced = false;
        $previous = null;

        foreach ($goals as $index => $existing) {
            if ((string) $existing['goal_id'] === $goalId) {
                $previous      = $existing;
                $goals[$index] = $goal;
                $replaced      = true;
                break;
            }
        }

        if (!$replaced) {
            // The cap counts only goals a site can still use. Soft-deleted ones
            // are history and must not stop somebody creating a new goal.
            if (count(self::visible()) >= GoalSettings::MAX_GOALS) {
                return false;
            }

            $goals[] = $goal;
        }

        if (!self::persist($goals)) {
            return false;
        }

        /**
         * Fires after a goal definition is persisted.
         *
         * Fires from the repository rather than the admin screen, so a WP-CLI
         * command or a future REST endpoint that saves a goal raises the same
         * event as somebody clicking Save. Only fires when the write actually
         * succeeded — a rejected save (invalid id, or the goal cap reached)
         * fires nothing.
         *
         * $previous is null for a newly created goal, and the goal as it was
         * stored for an edit — so a listener can tell creation from edit, and
         * see exactly what changed. Note that editing a goal's matching rules
         * changes its definition hash, which is how historical completions stay
         * attributed to the rules that were in force when they happened.
         *
         * @param string                    $goalId   Immutable goal id.
         * @param array<string, mixed>      $goal     The stored goal definition.
         * @param array<string, mixed>|null $previous The definition it replaced, or null for a new goal.
         */
        do_action('convermetry_goal_saved', $goalId, $goal, $previous);

        return true;
    }

    /**
     * Soft-deletes one goal. Its completions and their labels survive.
     *
     * @param string $goalId Immutable goal id.
     * @param string $now    UTC 'Y-m-d H:i:s'.
     * @return bool True when the goal existed and was marked deleted.
     */
    public static function softDelete(string $goalId, string $now): bool
    {
        $goals = self::all();
        $found = false;

        foreach ($goals as $index => $goal) {
            if ((string) $goal['goal_id'] === $goalId) {
                $goals[$index]['deleted_at'] = $now;
                $goals[$index]['enabled']    = false;
                $found                       = true;
                break;
            }
        }

        if (!$found || !self::persist($goals)) {
            return false;
        }

        /**
         * Fires after a goal is deleted.
         *
         * Fires only when a goal with this id actually existed AND the write
         * succeeded — the admin screen redirects with a "deleted" notice either
         * way, so a listener here would otherwise be told about goals that were
         * already gone.
         *
         * The deletion is soft: the goal stops matching new events, but its
         * recorded completions and its name survive so historical reports do not
         * develop holes. Treat this as "stopped collecting", not "erased".
         *
         * @param string $goalId Immutable goal id.
         * @param string $now    UTC 'Y-m-d H:i:s' deletion timestamp.
         */
        do_action('convermetry_goal_deleted', $goalId, $now);

        return true;
    }

    /**
     * Writes the goal list and clears the per-request memo.
     *
     * @param array<int, array<string, mixed>> $goals Goals to store.
     * @return bool
     */
    private static function persist(array $goals): bool
    {
        self::$cache = null;

        // Non-autoloaded: this option is irrelevant to the vast majority of
        // requests, and autoloading it would put every site's goal list into
        // memory on every page load to serve the few that need it.
        return update_option(Options::GOALS_OPTION_KEY, array_values($goals), false);
    }

    /**
     * Discards the per-request memo. For tests and long-running processes.
     *
     * @return void
     */
    public static function flushCache(): void
    {
        self::$cache = null;
    }
}
