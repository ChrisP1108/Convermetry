<?php
declare(strict_types=1);

namespace Convermetry\Funnels;

if (!defined('ABSPATH')) exit;

/**
 * Turns one funnel step into the SQL fragment that identifies it.
 *
 * PURE. A step goes in, a condition and its bound parameters come out — no
 * $wpdb, no query execution. {@see \Convermetry\Analytics\FunnelReport} owns
 * assembling those fragments into the chained statement and running it. The
 * split exists because "does this step match the right rows?" and "does the
 * chain establish the right ORDER?" are two different bugs, and mixing them
 * into one method makes both untestable.
 *
 * TWO SOURCES, ONE ORDERING SCALE.
 *
 * Most steps read the events table and order by its `id`. A goal step reads
 * cvm_goal_completions and orders by `source_event_id` — the id of the event
 * that triggered the completion — which is a value on the SAME scale. That is
 * the entire reason completions carry it: without it a funnel could not ask
 * "did this goal happen after that pageview?" without inventing a marker row,
 * and marker rows sort wrongly (see {@see \Convermetry\Goals\GoalRecorder}).
 *
 * CASE SENSITIVITY is inherited from the column collation, which for WordPress
 * is a *_ci collation — so `=` and `LIKE` here are case-insensitive, matching
 * {@see \Convermetry\Goals\GoalMatcher}, which lowercases explicitly. A page
 * linked as "/Pricing/" in one template and "/pricing/" in another is one page
 * in both engines.
 */
final class StepCompiler
{
    /**
     * Step types.
     *
     * The form lifecycle appears in the order a visitor moves through it, which
     * is also the order the funnel editor offers them in.
     *
     * @var string[]
     */
    public const array TYPES = [
        'page',
        'goal',
        'form_view',
        'form_start',
        'form_submit',
        'form_success',
    ];

    /**
     * Step types read from the events table (everything except goals).
     *
     * @var string[]
     */
    public const array EVENT_TYPES = ['page', 'form_view', 'form_start', 'form_submit', 'form_success'];

    /**
     * Operators available to a page step.
     *
     * @var string[]
     */
    public const array PAGE_OPERATORS = ['equals', 'contains', 'starts_with', 'ends_with'];

    /**
     * SQL extracting the path from a stored URL.
     *
     * Stored page URLs are canonicalized to scheme://host[:port]/path with no
     * query string, so the path begins at the first '/' after the '://'. A URL
     * with no path at all yields '/', matching how the tracker records a site
     * root.
     *
     * This exists because a site owner types "/thank-you/", not the full URL —
     * and matching a path with a leading-wildcard LIKE against the whole URL
     * would be wrong for "equals": '%/thank-you/' also matches
     * '/blog/thank-you/'.
     */
    private const string PATH_SQL =
        "IF(LOCATE('/', {alias}.page_url, LOCATE('://', {alias}.page_url) + 3) = 0, '/',"
        . " SUBSTRING({alias}.page_url, LOCATE('/', {alias}.page_url, LOCATE('://', {alias}.page_url) + 3)))";

    /**
     * Compiles one step.
     *
     * @param array<string, mixed> $step  A normalized funnel step.
     * @param string               $alias The SQL alias the fragment refers to.
     * @return array{source: string, sql: string, params: list<mixed>}|null
     *         null when the step is malformed and should abort the funnel.
     */
    public static function compile(array $step, string $alias): ?array
    {
        $type = (string) ($step['type'] ?? '');

        if (!in_array($type, self::TYPES, true)) {
            return null;
        }

        return $type === 'goal'
            ? self::compileGoal($step, $alias)
            : self::compileEvent($step, $alias, $type);
    }

    /**
     * Whether a step reads the goal completions table rather than the events
     * table.
     *
     * @param array<string, mixed> $step A normalized funnel step.
     * @return bool
     */
    public static function isGoalStep(array $step): bool
    {
        return (string) ($step['type'] ?? '') === 'goal';
    }

    /**
     * The column carrying a step's position on the shared ordering scale.
     *
     * @param array<string, mixed> $step  A normalized funnel step.
     * @param string               $alias The SQL alias.
     * @return string
     */
    public static function positionColumn(array $step, string $alias): string
    {
        return self::isGoalStep($step) ? "{$alias}.source_event_id" : "{$alias}.id";
    }

    /**
     * Compiles a goal-completion step.
     *
     * @param array<string, mixed> $step  A normalized funnel step.
     * @param string               $alias The SQL alias.
     * @return array{source: string, sql: string, params: list<mixed>}|null
     */
    private static function compileGoal(array $step, string $alias): ?array
    {
        $goalId = (string) ($step['value'] ?? '');

        if ($goalId === '') {
            return null;
        }

        // A completion with no source event cannot be placed in the ordering, so
        // it cannot participate in a funnel. It is still counted in the goal
        // reports — the conversion happened — but a step that silently treated
        // it as position zero would let it satisfy every "after" test.
        return [
            'source' => 'goals',
            'sql'    => "{$alias}.goal_id = %s AND {$alias}.source_event_id IS NOT NULL",
            'params' => [$goalId],
        ];
    }

    /**
     * Compiles an events-table step.
     *
     * @param array<string, mixed> $step  A normalized funnel step.
     * @param string               $alias The SQL alias.
     * @param string               $type  The step type.
     * @return array{source: string, sql: string, params: list<mixed>}|null
     */
    private static function compileEvent(array $step, string $alias, string $type): ?array
    {
        if ($type === 'page') {
            return self::compilePage($step, $alias);
        }

        // A form lifecycle step. An empty form key means "any form", which is a
        // legitimate funnel ("did they submit anything?") rather than a
        // half-configured step.
        $formKey = (string) ($step['value'] ?? '');
        $sql     = "{$alias}.event_type = %s";
        $params  = [$type];

        if ($formKey !== '') {
            $sql     .= " AND {$alias}.form_key = %s";
            $params[] = $formKey;
        }

        return ['source' => 'events', 'sql' => $sql, 'params' => $params];
    }

    /**
     * Compiles a page step.
     *
     * @param array<string, mixed> $step  A normalized funnel step.
     * @param string               $alias The SQL alias.
     * @return array{source: string, sql: string, params: list<mixed>}|null
     */
    private static function compilePage(array $step, string $alias): ?array
    {
        global $wpdb;

        $operator = (string) ($step['operator'] ?? '');
        $pattern  = (string) ($step['value'] ?? '');

        if (!in_array($operator, self::PAGE_OPERATORS, true) || $pattern === '') {
            // An empty pattern would match every page view or none, and the site
            // owner asked for neither. Aborting the whole funnel is deliberate:
            // rendering the other steps as though this one had been satisfied
            // would report a conversion rate that means nothing.
            return null;
        }

        // A rule written as a path is compared against the path; a rule naming a
        // host is compared against the whole URL.
        $subject = str_starts_with($pattern, '/')
            ? str_replace('{alias}', $alias, self::PATH_SQL)
            : "{$alias}.page_url";

        if ($operator === 'equals') {
            // Trailing slashes are forgiven on BOTH sides, matching GoalMatcher.
            // "/thank-you" and "/thank-you/" are the same page to WordPress and
            // to every visitor, and treating them as different would count
            // roughly half of what actually happened.
            return [
                'source' => 'events',
                'sql'    => "{$alias}.event_type = 'pageview'"
                    . " AND TRIM(TRAILING '/' FROM {$subject}) = TRIM(TRAILING '/' FROM %s)",
                'params' => [$pattern],
            ];
        }

        $escaped = $wpdb->esc_like($pattern);

        $like = match ($operator) {
            'contains'    => '%' . $escaped . '%',
            'starts_with' => $escaped . '%',
            default       => '%' . $escaped,
        };

        return [
            'source' => 'events',
            'sql'    => "{$alias}.event_type = 'pageview' AND {$subject} LIKE %s",
            'params' => [$like],
        ];
    }
}
