<?php
declare(strict_types=1);

namespace Convermetry\Analytics;

if (!defined('ABSPATH')) exit;

/**
 * The read path every Convermetry report goes through.
 *
 * $wpdb turns a FAILED query into an empty array or a null — values a caller
 * cannot tell apart from a legitimate "nothing happened in this window". A
 * dashboard that silently renders zeros because the database rejected a query is
 * worse than one that says it could not answer, and a webhook that ships those
 * zeros to a downstream system is worse still. So every read is checked against
 * $wpdb->last_error and throws {@see ReportQueryException} on failure.
 *
 * The check has to happen IMMEDIATELY after each individual call: last_error is
 * reset by the next query, so one check at the end of a chain would only ever
 * report on the final statement.
 *
 * This started as two private methods on {@see Reports}. It moved here when the
 * goal, funnel, engagement, and lead reports arrived — five classes sharing one
 * error-handling contract, rather than five copies of it that drift apart.
 * {@see Reports} keeps its own thin wrappers and delegates, so its call sites
 * are unchanged.
 */
final class ReportQuery
{
    /**
     * Runs a SELECT expected to return multiple rows.
     *
     * @param string $sql Fully-prepared SQL (already passed through $wpdb->prepare()).
     * @return array<int, array<string, mixed>>
     * @throws ReportQueryException When the query itself failed.
     */
    public static function rows(string $sql): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error !== '') {
            throw new ReportQueryException($wpdb->last_error);
        }

        return (array) $rows;
    }

    /**
     * Runs a SELECT expected to return a single scalar value.
     *
     * @param string $sql Fully-prepared SQL (already passed through $wpdb->prepare()).
     * @return string|null
     * @throws ReportQueryException When the query itself failed.
     */
    public static function value(string $sql): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var($sql);
        if ($wpdb->last_error !== '') {
            throw new ReportQueryException($wpdb->last_error);
        }

        return $value;
    }
}
