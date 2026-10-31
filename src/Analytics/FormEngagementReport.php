<?php
declare(strict_types=1);

namespace Convermetry\Analytics;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Database\EventOrder;
use Convermetry\Database\FormSubmissions;

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

        $rows = ReportQuery::rows($wpdb->prepare(
            "SELECT form_key,
                    COUNT(DISTINCT CASE WHEN event_type = 'form_view' THEN session_id END) AS views,
                    COUNT(DISTINCT CASE WHEN event_type = 'form_start' THEN session_id END) AS started,
                    SUM(CASE WHEN event_type = 'form_submit' THEN 1 ELSE 0 END) AS attempts,
                    COUNT(DISTINCT CASE WHEN event_type = 'form_success' THEN event_value END) AS successful
             FROM %i
             WHERE form_key <> '' AND event_type IN (" . implode(', ', array_fill(0, count(self::LIFECYCLE), '%s')) . ")
               AND created_at >= %s AND created_at < %s
             GROUP BY form_key
             ORDER BY views DESC, started DESC, form_key ASC
             LIMIT %d",
            array_merge([$table], self::LIFECYCLE, [$start, $end, $limit])
        ));

        $abandoned = self::abandonment($start, $end);
        $names     = self::displayNames(
            array_values(array_map(static fn(array $row): string => (string) $row['form_key'], $rows)),
            $start,
            $end
        );

        return array_map(static function (array $row) use ($abandoned, $names): array {
            $formKey = (string) $row['form_key'];
            $views   = (int) $row['views'];
            $started = (int) $row['started'];
            $counts  = $abandoned[$formKey] ?? ['abandoned' => 0, 'in_progress' => 0];

            return [
                'form_key'        => $formKey,
                'form_name'       => $names[$formKey] ?? '',
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
     * Where a label came from, most authoritative first. {@see preferredName()}
     *
     * SOURCE_SUBMISSION: the form plugin's own title, from the latest
     *                    server-confirmed submission row.
     * SOURCE_CONFIRMED:  a label seen on a server-confirmed form_success event.
     * SOURCE_BROWSER:    a label the tracker read from the form's markup.
     */
    public const int SOURCE_SUBMISSION = 0;
    public const int SOURCE_CONFIRMED  = 1;
    public const int SOURCE_BROWSER    = 2;

    /**
     * The name each form is listed under.
     *
     * One form collects several labels: the form plugin's own title, written
     * by the server on every confirmed submission and success, and whatever the
     * browser could read from the markup on views, starts and attempts — the
     * site's data-cvmtry-form-name, the form's name or id attribute, or the
     * generic 'form'. History mixes all of them, including rows from before the
     * tracker gave every event the same label. MAX(element_label) used to pick
     * among them by collation order, so "form" beat "Footer Feedback" and
     * "demo-quote-request" beat "Demo Quote Request": alphabet, not meaning.
     *
     * Candidates come from two places, and {@see preferredName()} ranks them:
     *
     *  - the form name on each form's MOST RECENT submission row — what the form
     *    plugin calls the form now, recorded server-side, and present even when
     *    analytics tracking skipped the success (an excluded logged-in user, a
     *    privacy signal, form_success tracking switched off);
     *  - every distinct label on the form's lifecycle events in the range, with
     *    how often it appeared, how often on a confirmed success, and its
     *    newest row. Labels are grouped byte for byte, so two spellings that
     *    differ only by case stay two candidates instead of one picked by the
     *    collation.
     *
     * Nothing is rewritten: historical rows keep their labels, and only the
     * choice among them changes.
     *
     * @param list<string> $formKeys Forms to name.
     * @param string       $start    UTC datetime (inclusive).
     * @param string       $end      UTC datetime (exclusive).
     * @return array<string, string> Name per form key; absent when it has none.
     */
    private static function displayNames(array $formKeys, string $start, string $end): array
    {
        global $wpdb;

        $formKeys = array_values(array_unique(array_filter($formKeys, static fn(string $key): bool => $key !== '')));
        if ($formKeys === []) {
            return [];
        }

        $candidates = array_fill_keys($formKeys, []);

        // One indexed lookup per form (provider_form, newest id first), rather
        // than a MAX(id) over every submission each form has ever received.
        $lookups = [];
        $params  = [];
        foreach ($formKeys as $formKey) {
            $provider = strstr($formKey, ':', true);
            if ($provider === false || $provider === '') {
                continue;
            }
            $lookups[] = "(SELECT form_key, form_name FROM %i WHERE provider = %s AND form_key = %s AND form_name <> '' ORDER BY id DESC LIMIT 1)";
            array_push($params, FormSubmissions::tableName(), $provider, $formKey);
        }

        if ($lookups !== []) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- one fixed, fully-placeholdered SELECT per form key, joined by UNION ALL; every table name and value is bound.
            $rows = ReportQuery::rows($wpdb->prepare(implode(' UNION ALL ', $lookups), $params));

            foreach ($rows as $row) {
                $formKey = (string) $row['form_key'];
                if (isset($candidates[$formKey])) {
                    $candidates[$formKey][] = [
                        'label'     => (string) $row['form_name'],
                        'source'    => self::SOURCE_SUBMISSION,
                        'confirmed' => 0,
                        'seen'      => 0,
                        'latest'    => 0,
                    ];
                }
            }
        }

        $rows = ReportQuery::rows($wpdb->prepare(
            "SELECT form_key, CAST(element_label AS BINARY) AS label,
                    COUNT(*) AS seen,
                    SUM(CASE WHEN event_type = 'form_success' THEN 1 ELSE 0 END) AS confirmed,
                    MAX(id) AS latest
             FROM %i
             WHERE form_key IN (" . implode(', ', array_fill(0, count($formKeys), '%s')) . ")
               AND event_type IN (" . implode(', ', array_fill(0, count(self::LIFECYCLE), '%s')) . ")
               AND element_label <> ''
               AND created_at >= %s AND created_at < %s
             GROUP BY form_key, label",
            array_merge([DatabaseManager::tableName()], $formKeys, self::LIFECYCLE, [$start, $end])
        ));

        foreach ($rows as $row) {
            $formKey = (string) $row['form_key'];
            if (isset($candidates[$formKey])) {
                $candidates[$formKey][] = [
                    'label'     => (string) $row['label'],
                    'source'    => (int) $row['confirmed'] > 0 ? self::SOURCE_CONFIRMED : self::SOURCE_BROWSER,
                    'confirmed' => (int) $row['confirmed'],
                    'seen'      => (int) $row['seen'],
                    'latest'    => (int) $row['latest'],
                ];
            }
        }

        $names = [];
        foreach ($candidates as $formKey => $forForm) {
            $name = self::preferredName((string) $formKey, $forForm);
            if ($name !== '') {
                $names[(string) $formKey] = $name;
            }
        }

        return $names;
    }

    /**
     * Chooses the name a form is shown under. Pure and deterministic.
     *
     * Each rule only breaks ties left by the ones before it:
     *
     *  1. A real name beats a placeholder. 'form' is the tracker's fallback when
     *     the markup names nothing. The provider key, the bare native id and
     *     "Form <id>" are the server's fallbacks when the form plugin has no
     *     title. A placeholder is still shown when it is all there is.
     *  2. Authority, {@see SOURCE_SUBMISSION} first: the form plugin's own
     *     title is the form's name; markup is only evidence of it.
     *  3. A label that reads as a name ("Demo Quote Request", "Contact") beats
     *     one that reads as an identifier ("demo-quote-request", "gform_7").
     *  4. More confirmed successes, then more appearances, then the newest.
     *  5. Byte order, so equal candidates always resolve the same way.
     *
     * @param string $formKey    The form's key, provider:identity.
     * @param list<array{label: string, source: int, confirmed: int, seen: int, latest: int}> $candidates
     * @return string '' when there is no usable candidate.
     */
    public static function preferredName(string $formKey, array $candidates): string
    {
        $candidates = array_values(array_filter(
            $candidates,
            static fn(array $candidate): bool => trim($candidate['label']) !== ''
        ));

        if ($candidates === []) {
            return '';
        }

        $rank = static fn(array $candidate): array => [
            self::isPlaceholderName($candidate['label'], $formKey) ? 1 : 0,
            $candidate['source'],
            preg_match('~^[a-z0-9_.:-]+$~', $candidate['label']) === 1 ? 1 : 0,
            -$candidate['confirmed'],
            -$candidate['seen'],
            -$candidate['latest'],
        ];

        usort(
            $candidates,
            static fn(array $a, array $b): int => ($rank($a) <=> $rank($b)) ?: strcmp($a['label'], $b['label'])
        );

        return $candidates[0]['label'];
    }

    /**
     * Whether a label is a fallback some code produced because the form had
     * no name — never a name anyone chose.
     *
     * @param string $label   Candidate label.
     * @param string $formKey The form's key, provider:identity.
     * @return bool
     */
    private static function isPlaceholderName(string $label, string $formKey): bool
    {
        [$provider, $identity] = array_pad(explode(':', strtolower($formKey), 2), 2, '');

        $placeholders = ['form', $provider];
        if ($identity !== '') {
            $placeholders[] = $identity;
            $placeholders[] = 'form ' . $identity;
        }

        return in_array(strtolower(trim($label)), $placeholders, true);
    }

    /**
     * Abandoned and in-progress session counts per form.
     *
     * A start is abandoned when no confirmed success followed it for the same
     * session and form within the completion window. The success must come
     * AFTER the start — a visitor who submits a form, sees the thank-you page,
     * then comes back and starts filling it in again has genuinely abandoned
     * the second attempt, and matching the earlier success would hide that.
     *
     * "After" is the success's causal position, not merely its row id: the
     * server's confirmation can be stored before the browser batch holding the
     * start and the attempt, and a success is never placed before the attempt
     * that caused it ({@see EventOrder}). Ordered by id alone, that race made a
     * converted start count as abandoned.
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

        // EventOrder's fixed fragments over literal aliases. The attempt join's
        // table is a %i placeholder, bound below like every other table.
        $sql = "SELECT s.form_key,
                    SUM(CASE WHEN s.created_at < %s THEN 1 ELSE 0 END) AS abandoned,
                    SUM(CASE WHEN s.created_at >= %s THEN 1 ELSE 0 END) AS in_progress
             FROM (
                 SELECT DISTINCT e.form_key, e.session_id, MIN(e.id) AS id, MIN(e.created_at) AS created_at
                 FROM %i AS e
                 WHERE e.event_type = 'form_start' AND e.form_key <> '' AND e.session_id <> ''
                   AND e.created_at >= %s AND e.created_at < %s
                 GROUP BY e.form_key, e.session_id
             ) AS s
             WHERE NOT EXISTS (
                 SELECT 1 FROM %i AS x" . EventOrder::attemptJoin('%i', 'x', 'x_attempt') . "
                 WHERE x.event_type = 'form_success'
                   AND x.session_id = s.session_id
                   AND x.form_key = s.form_key
                   AND " . EventOrder::successPosition('x', 'x_attempt') . " > s.id
                   AND x.created_at <= DATE_ADD(s.created_at, INTERVAL %d MINUTE)
             )
             GROUP BY s.form_key";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is fixed SQL plus EventOrder's fixed fragments; every table and value is a placeholder bound right here.
        $rows = ReportQuery::rows($wpdb->prepare($sql, $mature, $mature, $table, $start, $end, $table, $table, self::COMPLETION_WINDOW_MINUTES));

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

        // An empty $formKey means every form. MySQL folds the constant
        // comparison away before planning, so a one-form query still filters
        // on form_key.
        $rows = ReportQuery::rows($wpdb->prepare(
            "SELECT form_key, element_label AS field_id, element_tag AS field_type,
                    event_value AS error_type,
                    COUNT(*) AS errors,
                    COUNT(DISTINCT NULLIF(session_id, '')) AS sessions
             FROM %i
             WHERE event_type = 'form_error' AND created_at >= %s AND created_at < %s
               AND (%s = '' OR form_key = %s)
             GROUP BY form_key, element_label, element_tag, event_value
             ORDER BY errors DESC, field_id ASC
             LIMIT %d",
            $table,
            $start,
            $end,
            $formKey,
            $formKey,
            $limit
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
