<?php
declare(strict_types=1);

namespace Convermetry\Analytics;

if (!defined('ABSPATH')) exit;

use Convermetry\Support\Extensions;

/**
 * Holds the third-party analytics sections registered on this site.
 *
 * The registry exists so that "nothing registered costs nothing" is structural
 * rather than a promise: {@see all()} returns an empty array, and every caller
 * checks for that before touching a section, so a site with no integrations runs
 * exactly the queries, renders exactly the HTML, and sends exactly the payload
 * bytes it did before this API existed.
 *
 * The type guard mirrors {@see \Convermetry\Forms\FormProviderRegistry::all()}
 * deliberately — same shape, same failure mode (a non-conforming entry is
 * dropped silently rather than fataling a cron pass) — with one addition: the
 * section's key must also be a valid namespaced extension key, so a section
 * cannot claim 'totals' and shadow a core report.
 */
final class AnalyticsSectionRegistry
{
    /** @var array<string, AnalyticsSectionInterface>|null Memoized per request. */
    private static ?array $sections = null;

    /**
     * Every valid registered section, keyed by its namespaced key.
     *
     * @return array<string, AnalyticsSectionInterface>
     */
    public static function all(): array
    {
        if (self::$sections !== null) {
            return self::$sections;
        }

        /**
         * Filters the registered analytics sections. Append objects
         * implementing {@see AnalyticsSectionInterface} to add reporting blocks
         * to the dashboard and to the analytics webhook payload.
         *
         * This filter takes typed adapters, never SQL: there is deliberately no
         * way to pass a query fragment, a table name, or a column list, because
         * this data is assembled on an unattended cron path.
         *
         * Entries that are not AnalyticsSectionInterface instances, and entries
         * whose getKey() is not a namespaced 'vendor/thing' key, are dropped
         * without an error — a malformed section must not fatal a webhook
         * delivery. Later duplicates of a key overwrite earlier ones.
         *
         * Runs at most once per request; the result is memoized, so registering
         * a section after the first analytics report or dashboard render in the
         * same request has no effect.
         *
         * @param AnalyticsSectionInterface[] $sections Registered sections (empty by default).
         */
        $sections = (array) apply_filters('convermetry_analytics_sections', []);

        $map = [];
        foreach ($sections as $section) {
            if ($section instanceof AnalyticsSectionInterface && Extensions::isValidKey($section->getKey())) {
                $map[$section->getKey()] = $section;
            }
        }

        return self::$sections = $map;
    }

    /**
     * Clears the memoized section list.
     *
     * Only needed where one PHP process serves more than one logical request —
     * tests, and long-running CLI workers.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$sections = null;
    }
}
