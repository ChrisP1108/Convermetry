<?php
declare(strict_types=1);

namespace Convermetry\Analytics;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Settings\Options;

/**
 * Read-only aggregate queries over the events table (and the form
 * submissions table, for server-confirmed counts).
 *
 * Every method takes a UTC datetime range ('Y-m-d H:i:s') and returns plain
 * arrays ready for the dashboard tables and the analytics webhook JSON
 * payload — the two consumers share this single query layer, so they always
 * report identical numbers.
 *
 * Every query in this file runs through {@see queryRows()} or
 * {@see queryValue()}, which check $wpdb->last_error immediately after
 * execution and throw {@see ReportQueryException} on failure — $wpdb
 * otherwise turns a failed query into an empty array or null
 * indistinguishable from a legitimate zero, and last_error resets on every
 * subsequent query, so the check has to happen right after each individual
 * call, not once at the end of a longer chain.
 */
final class Reports
{
    /**
     * Runs a SELECT expected to return multiple rows.
     *
     * @param string $sql Fully-prepared SQL (already passed through $wpdb->prepare()).
     * @return array<int, array<string, mixed>>
     * @throws ReportQueryException When the query itself failed.
     */
    private static function queryRows(string $sql): array
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
    private static function queryValue(string $sql): ?string
    {
        global $wpdb;

        $value = $wpdb->get_var($sql);
        if ($wpdb->last_error !== '') {
            throw new ReportQueryException($wpdb->last_error);
        }

        return $value;
    }

    /**
     * Total event counts per event type within a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return array<string, int> Map of event_type → count.
     */
    public static function totalsByType(string $start, string $end): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = self::queryRows($wpdb->prepare(
            "SELECT event_type, COUNT(*) AS total
             FROM {$table}
             WHERE created_at >= %s AND created_at < %s
             GROUP BY event_type",
            $start,
            $end
        ));

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row['event_type']] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * Daily counts of one event type across a range, with zero-filled gaps so
     * charts and payloads always contain one entry per calendar day.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param string $type  Event type to count (e.g. "pageview").
     * @return array<int, array{date: string, count: int}>
     */
    public static function dailyCounts(string $start, string $end, string $type): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = self::queryRows($wpdb->prepare(
            "SELECT DATE(created_at) AS day, COUNT(*) AS total
             FROM {$table}
             WHERE event_type = %s AND created_at >= %s AND created_at < %s
             GROUP BY day
             ORDER BY day ASC",
            $type,
            $start,
            $end
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
     * Most-viewed pages within a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{page_url: string, page_title: string, views: int, sessions: int}>
     */
    public static function topPages(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = self::queryRows($wpdb->prepare(
            "SELECT page_url, MAX(page_title) AS page_title,
                    COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions
             FROM {$table}
             WHERE event_type = 'pageview' AND created_at >= %s AND created_at < %s
             GROUP BY page_url
             ORDER BY views DESC
             LIMIT %d",
            $start,
            $end,
            $limit
        ));

        return array_map(static fn(array $row): array => [
            'page_url'   => (string) $row['page_url'],
            'page_title' => (string) $row['page_title'],
            'views'      => (int) $row['views'],
            'sessions'   => (int) $row['sessions'],
        ], $rows);
    }

    /**
     * Most-clicked links and buttons within a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{element_label: string, element_tag: string, target_url: string, clicks: int}>
     */
    public static function topClicks(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = self::queryRows($wpdb->prepare(
            "SELECT element_label, MAX(element_tag) AS element_tag,
                    target_url, COUNT(*) AS clicks
             FROM {$table}
             WHERE event_type = 'click' AND created_at >= %s AND created_at < %s
             GROUP BY element_label, target_url
             ORDER BY clicks DESC
             LIMIT %d",
            $start,
            $end,
            $limit
        ));

        return array_map(static fn(array $row): array => [
            'element_label' => (string) $row['element_label'],
            'element_tag'   => (string) $row['element_tag'],
            'target_url'    => (string) $row['target_url'],
            'clicks'        => (int) $row['clicks'],
        ], $rows);
    }

    /**
     * Most-submitted forms (attempts) within a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{element_label: string, page_url: string, submissions: int}>
     */
    public static function topForms(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = self::queryRows($wpdb->prepare(
            "SELECT element_label, page_url, COUNT(*) AS submissions
             FROM {$table}
             WHERE event_type = 'form_submit' AND created_at >= %s AND created_at < %s
             GROUP BY element_label, page_url
             ORDER BY submissions DESC
             LIMIT %d",
            $start,
            $end,
            $limit
        ));

        return array_map(static fn(array $row): array => [
            'element_label' => (string) $row['element_label'],
            'page_url'      => (string) $row['page_url'],
            'submissions'   => (int) $row['submissions'],
        ], $rows);
    }

    /**
     * Most-hovered elements within a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{element_label: string, element_tag: string, hovers: int}>
     */
    public static function topHovers(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = self::queryRows($wpdb->prepare(
            "SELECT element_label, element_tag, COUNT(*) AS hovers
             FROM {$table}
             WHERE event_type = 'hover' AND created_at >= %s AND created_at < %s
             GROUP BY element_label, element_tag
             ORDER BY hovers DESC
             LIMIT %d",
            $start,
            $end,
            $limit
        ));

        return array_map(static fn(array $row): array => [
            'element_label' => (string) $row['element_label'],
            'element_tag'   => (string) $row['element_tag'],
            'hovers'        => (int) $row['hovers'],
        ], $rows);
    }

    /**
     * Top external referrers within a range.
     *
     * Internal navigation (referrers on this site's own host) is excluded so
     * the table answers "who sends us traffic?", not "how do visitors move
     * around?".
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{referrer: string, visits: int}>
     */
    public static function topReferrers(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        // Referrers are stored as scheme://host/path, so the expression below
        // extracts exactly the host. Exact comparison against the allowed-host
        // list can't be fooled by lookalike hosts the way a substring LIKE could.
        $hosts        = Options::allowedHosts();
        $placeholders = implode(', ', array_fill(0, count($hosts), '%s'));

        $rows = self::queryRows($wpdb->prepare(
            "SELECT referrer, COUNT(*) AS visits
             FROM {$table}
             WHERE event_type = 'pageview' AND referrer <> ''
               AND SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, '://', -1), '/', 1) NOT IN ({$placeholders})
               AND created_at >= %s AND created_at < %s
             GROUP BY referrer
             ORDER BY visits DESC
             LIMIT %d",
            array_merge($hosts, [$start, $end, $limit])
        ));

        return array_map(static fn(array $row): array => [
            'referrer' => (string) $row['referrer'],
            'visits'   => (int) $row['visits'],
        ], $rows);
    }

    /**
     * Top campaigns within a range, from the utm_* fields captured on
     * pageview events, joined with the confirmed conversions (form_success)
     * attributed to the same campaign. The tracker attributes a whole session
     * to its landing campaign, so these are session-attributed numbers, not
     * just tagged landings.
     *
     * A row qualifies when *any* of the six campaign fields is set. Rows are
     * grouped by source/medium/campaign/utm_id, so two campaign IDs sharing a
     * name never merge into one row. Conversions are counted by DISTINCT
     * conversion id, so an at-least-once redelivery of the same conversion
     * never double-counts; conversion_rate is a session conversion rate.
     *
     * Campaigns that converted in-window but have no matching pageview
     * anywhere in the window are appended afterward, up to a small reserved
     * budget.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{utm_source: string, utm_medium: string, utm_campaign: string, utm_id: string, channel: string, views: int, sessions: int, conversions: int, converting_sessions: int, conversion_rate: float}>
     */
    public static function topCampaigns(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table  = DatabaseManager::tableName();
        $tagged = self::TAGGED_SQL;

        $rows = self::queryRows($wpdb->prepare(
            "SELECT utm_source, utm_medium, utm_campaign, utm_id,
                    MAX(channel) AS channel,
                    COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions
             FROM {$table}
             WHERE event_type = 'pageview' AND {$tagged}
               AND created_at >= %s AND created_at < %s
             GROUP BY utm_source, utm_medium, utm_campaign, utm_id
             ORDER BY views DESC, utm_source, utm_medium, utm_campaign, utm_id
             LIMIT %d",
            $start,
            $end,
            $limit
        ));

        $conversionRows = self::queryRows($wpdb->prepare(
            "SELECT utm_source, utm_medium, utm_campaign, utm_id,
                    MAX(channel) AS channel,
                    COUNT(DISTINCT event_value) AS conversions,
                    COUNT(DISTINCT session_id) AS converting_sessions
             FROM {$table}
             WHERE event_type = 'form_success' AND {$tagged}
               AND created_at >= %s AND created_at < %s
             GROUP BY utm_source, utm_medium, utm_campaign, utm_id",
            $start,
            $end
        ));

        $conversions = [];
        foreach ($conversionRows as $row) {
            $key = self::campaignKey($row['utm_source'], $row['utm_medium'], $row['utm_campaign'], $row['utm_id']);
            $conversions[$key] = [
                'channel'             => (string) $row['channel'],
                'conversions'         => (int) $row['conversions'],
                'converting_sessions' => (int) $row['converting_sessions'],
            ];
        }

        $out = [];
        foreach ($rows as $row) {
            $key      = self::campaignKey($row['utm_source'], $row['utm_medium'], $row['utm_campaign'], $row['utm_id']);
            $sessions = (int) $row['sessions'];
            $counts   = $conversions[$key] ?? ['conversions' => 0, 'converting_sessions' => 0];
            // Matched keys are removed so anything left in $conversions is a
            // genuine candidate for the orphan check below.
            unset($conversions[$key]);

            $out[] = [
                'utm_source'          => (string) $row['utm_source'],
                'utm_medium'          => (string) $row['utm_medium'],
                'utm_campaign'        => (string) $row['utm_campaign'],
                'utm_id'              => (string) $row['utm_id'],
                'channel'             => (string) $row['channel'],
                'views'               => (int) $row['views'],
                'sessions'            => $sessions,
                'conversions'         => $counts['conversions'],
                'converting_sessions' => $counts['converting_sessions'],
                'conversion_rate'     => self::sessionRate($counts['converting_sessions'], $sessions),
            ];
        }

        // Reserve room for genuinely-orphaned conversions (converted, but no
        // matching pageview anywhere in the window) — but only take a slot
        // away from real traffic rows when there's actually a traffic row to
        // protect, and only when unmatched candidates remain.
        $preserveTraffic = ($out !== []) ? 1 : 0;
        $orphanLimit     = min(3, max(0, $limit - $preserveTraffic));

        if ($orphanLimit > 0 && $conversions !== []) {
            $orphanRows = self::queryRows($wpdb->prepare(
                "SELECT c.utm_source, c.utm_medium, c.utm_campaign, c.utm_id, c.channel,
                        c.conversions, c.converting_sessions
                 FROM (
                     SELECT utm_source, utm_medium, utm_campaign, utm_id, MAX(channel) AS channel,
                            COUNT(DISTINCT event_value) AS conversions,
                            COUNT(DISTINCT session_id) AS converting_sessions
                     FROM {$table}
                     WHERE event_type = 'form_success' AND {$tagged}
                       AND created_at >= %s AND created_at < %s
                     GROUP BY utm_source, utm_medium, utm_campaign, utm_id
                 ) AS c
                 WHERE NOT EXISTS (
                     SELECT 1 FROM {$table} AS p
                     WHERE p.event_type = 'pageview' AND {$tagged}
                       AND p.created_at >= %s AND p.created_at < %s
                       AND p.utm_source = c.utm_source AND p.utm_medium = c.utm_medium
                       AND p.utm_campaign = c.utm_campaign AND p.utm_id = c.utm_id
                 )
                 ORDER BY c.conversions DESC, c.utm_source, c.utm_medium, c.utm_campaign, c.utm_id
                 LIMIT %d",
                $start,
                $end,
                $start,
                $end,
                $orphanLimit
            ));

            $orphanCount = min($orphanLimit, count($orphanRows));
            if ($orphanCount > 0) {
                $out = array_slice($out, 0, max(0, $limit - $orphanCount));

                foreach (array_slice($orphanRows, 0, $orphanCount) as $row) {
                    $out[] = [
                        'utm_source'          => (string) $row['utm_source'],
                        'utm_medium'          => (string) $row['utm_medium'],
                        'utm_campaign'        => (string) $row['utm_campaign'],
                        'utm_id'              => (string) $row['utm_id'],
                        'channel'             => (string) $row['channel'],
                        'views'               => 0,
                        'sessions'            => 0,
                        'conversions'         => (int) $row['conversions'],
                        'converting_sessions' => (int) $row['converting_sessions'],
                        'conversion_rate'     => 0.0,
                    ];
                }
            }
        }

        return $out;
    }

    /**
     * Collision-safe composite key for a four-field UTM combination, joined
     * with a delimiter (ASCII unit separator) unlikely to appear in real UTM
     * values.
     *
     * @param string $source   utm_source.
     * @param string $medium   utm_medium.
     * @param string $campaign utm_campaign.
     * @param string $id       utm_id.
     * @return string
     */
    private static function campaignKey(string $source, string $medium, string $campaign, string $id): string
    {
        return implode("\x1f", [$source, $medium, $campaign, $id]);
    }

    /**
     * SQL predicate marking a row as campaign-tagged: any of the six utm
     * fields is set.
     */
    private const string TAGGED_SQL = "(utm_source <> '' OR utm_medium <> '' OR utm_campaign <> ''
        OR utm_id <> '' OR utm_term <> '' OR utm_content <> '')";

    /**
     * Session conversion rate as a percentage: sessions with at least one
     * conversion ÷ sessions, capped at 100.
     *
     * @param int $convertingSessions Sessions with at least one conversion.
     * @param int $sessions           Sessions in the window.
     * @return float
     */
    private static function sessionRate(int $convertingSessions, int $sessions): float
    {
        if ($sessions <= 0) {
            return 0.0;
        }

        return min(100.0, round($convertingSessions / $sessions * 100, 2));
    }

    /**
     * Keyword/creative drilldown: performance per utm_term / utm_content
     * combination, with campaign context. Only rows where at least one of
     * the two is set qualify.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{utm_source: string, utm_medium: string, utm_campaign: string, utm_id: string, utm_term: string, utm_content: string, views: int, sessions: int, conversions: int}>
     */
    public static function topCampaignContent(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table    = DatabaseManager::tableName();
        $detailed = "(utm_term <> '' OR utm_content <> '')";

        $rows = self::queryRows($wpdb->prepare(
            "SELECT utm_source, utm_medium, utm_campaign, utm_id, utm_term, utm_content,
                    COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions
             FROM {$table}
             WHERE event_type = 'pageview' AND {$detailed}
               AND created_at >= %s AND created_at < %s
             GROUP BY utm_source, utm_medium, utm_campaign, utm_id, utm_term, utm_content
             ORDER BY views DESC
             LIMIT %d",
            $start,
            $end,
            $limit
        ));

        $conversions = [];

        // Bounded to the same (at most $limit) combinations already selected
        // above — this drilldown never surfaces conversion-only combinations,
        // so aggregating the full universe of converting combinations just to
        // discard almost all of it would be wasted work.
        if ($rows !== []) {
            $placeholders = [];
            $params       = [];
            foreach ($rows as $row) {
                $placeholders[] = '(%s,%s,%s,%s,%s,%s)';
                $params[] = $row['utm_source'];
                $params[] = $row['utm_medium'];
                $params[] = $row['utm_campaign'];
                $params[] = $row['utm_id'];
                $params[] = $row['utm_term'];
                $params[] = $row['utm_content'];
            }
            $params[] = $start;
            $params[] = $end;

            $conversionRows = self::queryRows($wpdb->prepare(
                "SELECT utm_source, utm_medium, utm_campaign, utm_id, utm_term, utm_content,
                        COUNT(DISTINCT event_value) AS conversions
                 FROM {$table}
                 WHERE event_type = 'form_success'
                   AND (utm_source, utm_medium, utm_campaign, utm_id, utm_term, utm_content) IN ("
                    . implode(', ', $placeholders) . ")
                   AND created_at >= %s AND created_at < %s
                 GROUP BY utm_source, utm_medium, utm_campaign, utm_id, utm_term, utm_content",
                $params
            ));

            foreach ($conversionRows as $row) {
                $key = self::campaignContentKey(
                    $row['utm_source'],
                    $row['utm_medium'],
                    $row['utm_campaign'],
                    $row['utm_id'],
                    $row['utm_term'],
                    $row['utm_content']
                );
                $conversions[$key] = (int) $row['conversions'];
            }
        }

        return array_map(static function (array $row) use ($conversions): array {
            $key = self::campaignContentKey(
                $row['utm_source'],
                $row['utm_medium'],
                $row['utm_campaign'],
                $row['utm_id'],
                $row['utm_term'],
                $row['utm_content']
            );

            return [
                'utm_source'   => (string) $row['utm_source'],
                'utm_medium'   => (string) $row['utm_medium'],
                'utm_campaign' => (string) $row['utm_campaign'],
                'utm_id'       => (string) $row['utm_id'],
                'utm_term'     => (string) $row['utm_term'],
                'utm_content'  => (string) $row['utm_content'],
                'views'        => (int) $row['views'],
                'sessions'     => (int) $row['sessions'],
                'conversions'  => $conversions[$key] ?? 0,
            ];
        }, $rows);
    }

    /**
     * Collision-safe composite key for a six-field UTM combination.
     *
     * @param string $source   utm_source.
     * @param string $medium   utm_medium.
     * @param string $campaign utm_campaign.
     * @param string $id       utm_id.
     * @param string $term     utm_term.
     * @param string $content  utm_content.
     * @return string
     */
    private static function campaignContentKey(
        string $source,
        string $medium,
        string $campaign,
        string $id,
        string $term,
        string $content
    ): string {
        return implode("\x1f", [$source, $medium, $campaign, $id, $term, $content]);
    }

    /**
     * Sessions and confirmed conversions per marketing channel within a range.
     *
     * The channel is classified at ingestion (see
     * {@see \Convermetry\Tracking\Channels}). conversion_rate is a session
     * conversion rate: sessions with at least one conversion ÷ sessions
     * (capped at 100). The raw conversion count rides alongside.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return array<int, array{channel: string, views: int, sessions: int, conversions: int, converting_sessions: int, conversion_rate: float}>
     */
    public static function channelBreakdown(string $start, string $end): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = self::queryRows($wpdb->prepare(
            "SELECT channel, COUNT(*) AS views, COUNT(DISTINCT session_id) AS sessions
             FROM {$table}
             WHERE event_type = 'pageview' AND channel <> ''
               AND created_at >= %s AND created_at < %s
             GROUP BY channel
             ORDER BY sessions DESC",
            $start,
            $end
        ));

        $conversionRows = self::queryRows($wpdb->prepare(
            "SELECT channel, COUNT(DISTINCT event_value) AS conversions,
                    COUNT(DISTINCT session_id) AS converting_sessions
             FROM {$table}
             WHERE event_type = 'form_success'
               AND created_at >= %s AND created_at < %s
             GROUP BY channel",
            $start,
            $end
        ));

        $conversions = [];
        foreach ($conversionRows as $row) {
            $conversions[(string) $row['channel']] = [
                'conversions'         => (int) $row['conversions'],
                'converting_sessions' => (int) $row['converting_sessions'],
            ];
        }

        $out = [];
        foreach ($rows as $row) {
            $channel  = (string) $row['channel'];
            $sessions = (int) $row['sessions'];
            $counts   = $conversions[$channel] ?? ['conversions' => 0, 'converting_sessions' => 0];
            unset($conversions[$channel]);

            $out[] = [
                'channel'             => $channel,
                'views'               => (int) $row['views'],
                'sessions'            => $sessions,
                'conversions'         => $counts['conversions'],
                'converting_sessions' => $counts['converting_sessions'],
                'conversion_rate'     => self::sessionRate($counts['converting_sessions'], $sessions),
            ];
        }

        // Channels that converted without a pageview in the window (e.g. a
        // session that landed just before the window started) still surface.
        foreach ($conversions as $channel => $counts) {
            if ($channel !== '') {
                $out[] = [
                    'channel'             => $channel,
                    'views'               => 0,
                    'sessions'            => 0,
                    'conversions'         => $counts['conversions'],
                    'converting_sessions' => $counts['converting_sessions'],
                    'conversion_rate'     => 0.0,
                ];
            }
        }

        return $out;
    }

    /**
     * Most common landing pages within a range — the first pageview of each
     * session that TRULY started in the window.
     *
     * The inner derived table finds each session's first pageview by MIN(id)
     * over every pageview up to the window's end, then keeps only sessions
     * whose first pageview falls inside the requested range. The scan is
     * served by the type_session_date index — a covering, pre-grouped read.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows to return.
     * @return array<int, array{page_url: string, page_title: string, sessions: int}>
     */
    public static function topLandingPages(string $start, string $end, int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = self::queryRows($wpdb->prepare(
            "SELECT e.page_url, MAX(e.page_title) AS page_title, COUNT(*) AS sessions
             FROM (
                 SELECT MIN(id) AS first_id
                 FROM {$table}
                 WHERE event_type = 'pageview' AND session_id <> ''
                   AND created_at < %s
                 GROUP BY session_id
                 HAVING MIN(created_at) >= %s
             ) AS f
             INNER JOIN {$table} AS e ON e.id = f.first_id
             GROUP BY e.page_url
             ORDER BY sessions DESC
             LIMIT %d",
            $end,
            $start,
            $limit
        ));

        return array_map(static fn(array $row): array => [
            'page_url'   => (string) $row['page_url'],
            'page_title' => (string) $row['page_title'],
            'sessions'   => (int) $row['sessions'],
        ], $rows);
    }

    /**
     * Total confirmed conversions within a range, deduplicated by conversion
     * id (event_value) — at-least-once delivery can store the same conversion
     * twice, and a conversion recorded by both the frontend tracker and a
     * server-side provider hook shares one id, so duplicates never inflate
     * this number.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return int
     */
    public static function conversionCount(string $start, string $end): int
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        return (int) self::queryValue($wpdb->prepare(
            "SELECT COUNT(DISTINCT event_value)
             FROM {$table}
             WHERE event_type = 'form_success'
               AND created_at >= %s AND created_at < %s",
            $start,
            $end
        ));
    }

    /**
     * Total server-confirmed form submissions within a range — rows in the
     * form submissions table, written only when a form provider's server-side
     * success hook fired. This is the authoritative "the form plugin accepted
     * it" count, distinct from frontend submit attempts (form_submit events)
     * and from the merged conversion count (which also includes frontend-only
     * conversions for unsupported providers).
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return int
     */
    public static function serverSubmissionCount(string $start, string $end): int
    {
        global $wpdb;
        $table = FormSubmissions::tableName();

        return (int) self::queryValue($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND created_at < %s",
            $start,
            $end
        ));
    }

    /**
     * The effective end of a reporting window such that it contains at most
     * $max conversion rows — used by the analytics webhook dispatcher to
     * split a conversion-heavy window into consecutive, non-overlapping
     * deliveries instead of silently dropping conversions past a listing cap.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $max   Maximum conversion rows per window.
     * @return string UTC datetime ('Y-m-d H:i:s'), always > $start and <= $end.
     */
    public static function conversionWindowEnd(string $start, string $end, int $max): string
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $boundary = self::queryValue($wpdb->prepare(
            "SELECT created_at
             FROM {$table}
             WHERE event_type = 'form_success'
               AND created_at >= %s AND created_at < %s
             ORDER BY created_at ASC
             LIMIT 1 OFFSET %d",
            $start,
            $end,
            max(1, $max)
        ));

        if ($boundary === null) {
            return $end;
        }

        if ($boundary <= $start) {
            return gmdate('Y-m-d H:i:s', (int) strtotime($start . ' UTC') + 1);
        }

        return $boundary;
    }

    /**
     * Individual confirmed conversions within a range, newest first, each
     * with the attribution snapshot taken when it occurred — self-contained
     * records ready for a CRM or automation platform. When a conversion has
     * a matching server-confirmed form submission, its provider, form ids,
     * submission_id, and stored IP address are merged in.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum conversions to return.
     * @return array<int, array<string, mixed>>
     */
    public static function recentConversions(string $start, string $end, int $limit = 100): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = self::queryRows($wpdb->prepare(
            "SELECT event_value, element_label, page_url, referrer, device, channel,
                    utm_source, utm_medium, utm_campaign, utm_id, utm_term, utm_content,
                    click_id_type, created_at, session_id, ip_address
             FROM {$table}
             WHERE event_type = 'form_success'
               AND created_at >= %s AND created_at < %s
             ORDER BY id DESC
             LIMIT %d",
            $start,
            $end,
            $limit
        ));

        $out  = [];
        $seen = [];
        foreach ($rows as $row) {
            $id = (string) $row['event_value'];
            if ($id !== '' && isset($seen[$id])) {
                continue; // At-least-once duplicate of a conversion already listed.
            }
            $seen[$id] = true;

            $out[] = [
                'conversion_id' => $id,
                'form'          => (string) $row['element_label'],
                'page_url'      => (string) $row['page_url'],
                'referrer'      => (string) $row['referrer'],
                'device'        => (string) $row['device'],
                'ip_address'    => (string) ($row['ip_address'] ?? ''),
                'session_id'    => (string) $row['session_id'],
                'occurred_at'   => (string) $row['created_at'],
                'attribution'   => [
                    'channel'       => (string) $row['channel'],
                    'utm_source'    => (string) $row['utm_source'],
                    'utm_medium'    => (string) $row['utm_medium'],
                    'utm_campaign'  => (string) $row['utm_campaign'],
                    'utm_id'        => (string) $row['utm_id'],
                    'utm_term'      => (string) $row['utm_term'],
                    'utm_content'   => (string) $row['utm_content'],
                    'click_id_type' => (string) $row['click_id_type'],
                ],
            ];
        }

        // Merge server-confirmed submission identity (provider, form ids,
        // submission_id) into conversions that have one — a single bounded
        // lookup keyed by the unique conversion_id index.
        if ($out !== []) {
            $ids          = array_column($out, 'conversion_id');
            $placeholders = implode(', ', array_fill(0, count($ids), '%s'));
            $subTable     = FormSubmissions::tableName();

            $subs = self::queryRows($wpdb->prepare(
                "SELECT conversion_id, submission_id, provider, form_name, form_id, native_form_id, ip_address
                 FROM {$subTable}
                 WHERE conversion_id IN ({$placeholders})",
                $ids
            ));

            $byConversion = [];
            foreach ($subs as $sub) {
                $byConversion[(string) $sub['conversion_id']] = $sub;
            }

            foreach ($out as $i => $conversion) {
                $sub = $byConversion[$conversion['conversion_id']] ?? null;
                $out[$i]['server_confirmed'] = $sub !== null;
                if ($sub !== null) {
                    $out[$i]['submission_id']  = (string) $sub['submission_id'];
                    $out[$i]['provider']       = (string) $sub['provider'];
                    $out[$i]['form']           = (string) $sub['form_name'] !== '' ? (string) $sub['form_name'] : $conversion['form'];
                    $out[$i]['form_id']        = (string) $sub['form_id'];
                    $out[$i]['native_form_id'] = (string) $sub['native_form_id'];

                    // The submission row's address was captured in the form
                    // POST the provider confirmed; prefer it over the
                    // analytics event's when both exist.
                    if ((string) $sub['ip_address'] !== '') {
                        $out[$i]['ip_address'] = (string) $sub['ip_address'];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Page views per device bucket within a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return array<string, int> Map of device (e.g. "desktop", "mobile") → page views.
     */
    public static function deviceBreakdown(string $start, string $end): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $rows = self::queryRows($wpdb->prepare(
            "SELECT device, COUNT(*) AS views
             FROM {$table}
             WHERE event_type = 'pageview' AND created_at >= %s AND created_at < %s
             GROUP BY device
             ORDER BY views DESC",
            $start,
            $end
        ));

        $breakdown = [];
        foreach ($rows as $row) {
            $device = (string) $row['device'];
            $breakdown[$device !== '' ? $device : 'unknown'] = (int) $row['views'];
        }

        return $breakdown;
    }

    /**
     * The most recent raw events, newest first. Used for the dashboard's
     * "Recent activity" table.
     *
     * @param int $limit Maximum rows to return.
     * @return array<int, array<string, string>>
     */
    public static function recentEvents(int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        return self::queryRows($wpdb->prepare(
            "SELECT event_type, page_url, page_title, element_label, target_url,
                    event_value, device, created_at
             FROM {$table}
             ORDER BY id DESC
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Lightweight session summary used to enrich a form submission's
     * analytics_context: how many pages the session viewed, when it started,
     * and the most recent pages visited. Two small queries, both served by
     * the type_session_date index — cheap enough for the background webhook
     * worker, and never run during the visitor's own submission request.
     *
     * @param string $sessionId Analytics session id.
     * @return array{pageview_count: int, session_started_at: string, recent_pages: string[]}
     */
    public static function sessionSummary(string $sessionId): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $empty = ['pageview_count' => 0, 'session_started_at' => '', 'recent_pages' => []];

        if ($sessionId === '') {
            return $empty;
        }

        $row = self::queryRows($wpdb->prepare(
            "SELECT COUNT(*) AS views, MIN(created_at) AS started
             FROM {$table}
             WHERE event_type = 'pageview' AND session_id = %s",
            $sessionId
        ))[0] ?? null;

        if ($row === null) {
            return $empty;
        }

        $pages = self::queryRows($wpdb->prepare(
            "SELECT page_url
             FROM {$table}
             WHERE event_type = 'pageview' AND session_id = %s
             ORDER BY id DESC
             LIMIT 5",
            $sessionId
        ));

        $started = (string) ($row['started'] ?? '');

        return [
            'pageview_count'     => (int) ($row['views'] ?? 0),
            'session_started_at' => $started !== '' ? gmdate('c', (int) strtotime($started . ' UTC')) : '',
            'recent_pages'       => array_values(array_map(
                static fn(array $page): string => (string) $page['page_url'],
                $pages
            )),
        ];
    }

    /**
     * Full aggregate summary for a range — the shape shared by the dashboard
     * and the analytics webhook payload's "analytics" section.
     *
     * $limit caps every "top_*" list. The dashboard uses the default 10; the
     * webhook dispatcher passes a much deeper limit so downstream systems
     * aggregating deliveries long-term see (near-)complete dimension rankings.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum rows per "top_*" list.
     * @return array<string, mixed>
     * @throws ReportQueryException When any underlying query fails — callers
     *         (the dashboard, the webhook dispatcher) must not treat a caught
     *         exception's fallback as a legitimate empty/zero result.
     */
    public static function buildSummary(string $start, string $end, int $limit = 10): array
    {
        $totals = self::totalsByType($start, $end);

        // The distinct conversion count is already computed below for
        // conversions.total — reuse it here too so totals.form_success agrees
        // with the Campaigns/Channels reports (which also dedupe by
        // conversion id) instead of the raw, potentially-redelivered row
        // count.
        $conversionTotal = self::conversionCount($start, $end);
        if (array_key_exists('form_success', $totals)) {
            $totals['form_success'] = $conversionTotal;
        }

        return [
            'totals'               => $totals,
            'daily_pageviews'      => self::dailyCounts($start, $end, 'pageview'),
            'top_pages'            => self::topPages($start, $end, $limit),
            'top_landing_pages'    => self::topLandingPages($start, $end, $limit),
            'top_clicks'           => self::topClicks($start, $end, $limit),
            'top_forms'            => self::topForms($start, $end, $limit),
            'top_hovers'           => self::topHovers($start, $end, $limit),
            'top_referrers'        => self::topReferrers($start, $end, $limit),
            'top_campaigns'        => self::topCampaigns($start, $end, $limit),
            'top_campaign_content' => self::topCampaignContent($start, $end, $limit),
            'channels'             => self::channelBreakdown($start, $end),
            'conversions'          => [
                'total'            => $conversionTotal,
                'server_confirmed' => self::serverSubmissionCount($start, $end),
                'recent'           => self::recentConversions($start, $end, 500),
            ],
            'devices'              => self::deviceBreakdown($start, $end),
        ];
    }
}
