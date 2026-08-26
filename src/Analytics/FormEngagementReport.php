<?php
declare(strict_types=1);

namespace Convermetry\Analytics;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;

/**
 * The path between seeing a form and submitting it.
 *
 * WHAT EACH NUMBER COUNTS, because mixing units here would make every rate
 * meaningless and the mix would be invisible:
 *
 *   Views        SESSIONS in which the form scrolled into view
 *   Started      SESSIONS in which someone began filling it in
 *   Attempts     RAW form_submit EVENTS — one visitor fighting a validation
 *                error produces several, and that is the point of the number
 *   Successful   DISTINCT CONVERSION IDS, so the tracker's event and the
 *                server's confirmation of the same submission count once
 *   Abandoned    SESSIONS that started and did not succeed (see below)
 *
 * BROWSER-OBSERVED VERSUS SERVER-CONFIRMED. Views, starts, attempts and
 * abandonment are what a BROWSER reported. Successful submissions are what the
 * form plugin's own server-side hook CONFIRMED. Those are different grades of
 * evidence and the UI labels them as such — a visitor with JavaScript disabled
 * can submit a form successfully while contributing no view and no start, so a
 * completion rate above 100% is possible and is not a bug.
 *
 * ABANDONMENT NEEDS A MATURITY PERIOD. A form started ninety seconds ago is not
 * abandoned, it is being filled in. Counting it immediately would make
 * abandonment spike towards 100% for the most recent hour of any window and
 * then decay — an artifact that looks exactly like a real problem. So a start
 * counts as abandoned only once {@see COMPLETION_WINDOW_MINUTES} have passed
 * without a success; younger starts are reported separately as "in progress".
 */
final class FormEngagementReport
{
    /**
     * Minutes after a form_start in which a success still counts, and the
     * maturity period before a start may be called abandoned.
     *
     * Matches the tracker's 30-minute session idle window: past that the
     * visitor's session has rotated anyway, so a success could no longer be
     * attributed to the same session as the start.
     */
    public const int COMPLETION_WINDOW_MINUTES = 30;

    /** The lifecycle event types this report reads. */
    private const array LIFECYCLE = ['form_view', 'form_start', 'form_submit', 'form_success'];

    /**
     * Per-form engagement totals for a range.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @param int    $limit Maximum forms returned.
     * @return array<int, array<string, mixed>>
     */
    public static function totals(string $start, string $end, int $limit = 20): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $types = implode(', ', array_fill(0, count(self::LIFECYCLE), '%s'));

        $rows = ReportQuery::rows($wpdb->prepare(
            "SELECT form_key,
                    MAX(element_label) AS form_name,
                    COUNT(DISTINCT CASE WHEN event_type = 'form_view' THEN session_id END) AS views,
                    COUNT(DISTINCT CASE WHEN event_type = 'form_start' THEN session_id END) AS started,
                    SUM(CASE WHEN event_type = 'form_submit' THEN 1 ELSE 0 END) AS attempts,
                    COUNT(DISTINCT CASE WHEN event_type = 'form_success' THEN event_value END) AS successful
             FROM {$table}
             WHERE form_key <> '' AND event_type IN ({$types})
               AND created_at >= %s AND created_at < %s
             GROUP BY form_key
             ORDER BY views DESC, started DESC, form_key ASC
             LIMIT %d",
            array_merge(self::LIFECYCLE, [$start, $end, $limit])
        ));

        $abandoned = self::abandonment($start, $end);

        return array_map(static function (array $row) use ($abandoned): array {
            $formKey = (string) $row['form_key'];
            $views   = (int) $row['views'];
            $started = (int) $row['started'];
            $counts  = $abandoned[$formKey] ?? ['abandoned' => 0, 'in_progress' => 0];

            return [
                'form_key'        => $formKey,
                'form_name'       => (string) $row['form_name'],
                'views'           => $views,
                'started'         => $started,
                'attempts'        => (int) $row['attempts'],
                'successful'      => (int) $row['successful'],
                'abandoned'       => $counts['abandoned'],
                'in_progress'     => $counts['in_progress'],
                'start_rate'      => self::rate($started, $views),
                'completion_rate' => self::rate((int) $row['successful'], $started),
            ];
        }, $rows);
    }

    /**
     * Abandoned and in-progress session counts per form.
     *
     * A start is abandoned when no confirmed success followed it for the same
     * session and form within the completion window. The success must come
     * AFTER the start (`x.id > s.id`) — a visitor who submits a form, sees the
     * thank-you page, then comes back and starts filling it in again has
     * genuinely abandoned the second attempt, and matching the earlier success
     * would hide that.
     *
     * @param string $start UTC datetime (inclusive).
     * @param string $end   UTC datetime (exclusive).
     * @return array<string, array{abandoned: int, in_progress: int}>
     */
    private static function abandonment(string $start, string $end): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        // Starts more recent than this are still in play, not abandoned.
        $mature = gmdate('Y-m-d H:i:s', time() - self::COMPLETION_WINDOW_MINUTES * MINUTE_IN_SECONDS);

        $rows = ReportQuery::rows($wpdb->prepare(
            "SELECT s.form_key,
                    SUM(CASE WHEN s.created_at < %s THEN 1 ELSE 0 END) AS abandoned,
                    SUM(CASE WHEN s.created_at >= %s THEN 1 ELSE 0 END) AS in_progress
             FROM (
                 SELECT DISTINCT e.form_key, e.session_id, MIN(e.id) AS id, MIN(e.created_at) AS created_at
                 FROM {$table} AS e
                 WHERE e.event_type = 'form_start' AND e.form_key <> '' AND e.session_id <> ''
                   AND e.created_at >= %s AND e.created_at < %s
                 GROUP BY e.form_key, e.session_id
             ) AS s
             WHERE NOT EXISTS (
                 SELECT 1 FROM {$table} AS x
                 WHERE x.event_type = 'form_success'
                   AND x.session_id = s.session_id
                   AND x.form_key = s.form_key
                   AND x.id > s.id
                   AND x.created_at <= DATE_ADD(s.created_at, INTERVAL %d MINUTE)
             )
             GROUP BY s.form_key",
            $mature,
            $mature,
            $start,
            $end,
            self::COMPLETION_WINDOW_MINUTES
        ));

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['form_key']] = [
                'abandoned'   => (int) $row['abandoned'],
                'in_progress' => (int) $row['in_progress'],
            ];
        }

        return $out;
    }

    /**
     * The fields visitors most often fail validation on.
     *
     * Reads only the metadata a form_error event carries: the field's
     * developer-chosen id, its type, and which ValidityState flag failed. No
     * value a visitor typed is stored anywhere in this table, so none can
     * appear here.
     *
     * @param string $start   UTC datetime (inclusive).
     * @param string $end     UTC datetime (exclusive).
     * @param string $formKey Restrict to one form, or '' for all.
     * @param int    $limit   Maximum rows.
     * @return array<int, array{form_key: string, field_id: string, field_type: string, error_type: string, errors: int, sessions: int}>
     */
    public static function frictionPoints(string $start, string $end, string $formKey = '', int $limit = 10): array
    {
        global $wpdb;
        $table = DatabaseManager::tableName();

        $where  = "event_type = 'form_error' AND created_at >= %s AND created_at < %s";
        $params = [$start, $end];

        if ($formKey !== '') {
            $where   .= ' AND form_key = %s';
            $params[] = $formKey;
        }

        $params[] = $limit;

        $rows = ReportQuery::rows($wpdb->prepare(
            "SELECT form_key, element_label AS field_id, element_tag AS field_type,
                    event_value AS error_type,
                    COUNT(*) AS errors,
                    COUNT(DISTINCT NULLIF(session_id, '')) AS sessions
             FROM {$table}
             WHERE {$where}
             GROUP BY form_key, element_label, element_tag, event_value
             ORDER BY errors DESC, field_id ASC
             LIMIT %d",
            $params
        ));

        return array_map(static fn(array $row): array => [
            'form_key'   => (string) $row['form_key'],
            'field_id'   => (string) $row['field_id'],
            'field_type' => (string) $row['field_type'],
            'error_type' => (string) $row['error_type'],
            'errors'     => (int) $row['errors'],
            'sessions'   => (int) $row['sessions'],
        ], $rows);
    }

    /**
     * A percentage, with a zero denominator yielding 0.0.
     *
     * NOT capped at 100. A completion rate above 100% is a real and meaningful
     * signal here: it means confirmed submissions outnumbered observed starts,
     * which happens when visitors submit with JavaScript disabled or blocked.
     * Silently clamping it to 100 would hide the one number that tells a site
     * owner their browser-observed metrics are undercounting.
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

        return round($part / $whole * 100, 1);
    }
}
