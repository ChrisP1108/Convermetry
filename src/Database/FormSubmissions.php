<?php
declare(strict_types=1);

namespace Convermetry\Database;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;
use Convermetry\Webhook\DeliveryLog;
use Convermetry\Webhook\FormDeliveryQueue;

/**
 * Owns the server-confirmed form submissions table.
 *
 * One row = one form submission a provider's server-side success hook
 * confirmed (or one submission recorded through the public custom-form API).
 * The row is the durable record the webhook delivery queue builds payloads
 * from, and the join point between analytics conversions and lead data:
 *
 *  - submission_id  — identifies THIS submission, globally, across every
 *                     endpoint it is delivered to.
 *  - conversion_id  — joins the submission to its analytics conversion
 *                     (form_success event) and session. UNIQUE, so a
 *                     duplicate provider callback for the same browser
 *                     submission is dropped at insert time.
 *  - session_id     — the visitor's analytics session, when the tracker's
 *                     correlation fields reached the server.
 *
 * submission_data holds the visitor's sanitized field values (PII) and
 * context holds the frozen analytics context captured at submission time, and
 * ip_address the submitter's address when that capture is enabled in Settings.
 * Rows age out with the same retention window as analytics events.
 *
 * channel and utm_campaign are DERIVED columns — copies of two values that
 * also live inside the context JSON. They are promoted to real, indexed
 * columns so the Submissions admin page can filter by them and build its
 * dropdowns without scanning and decoding every row's context blob.
 */
final class FormSubmissions
{
    /** Table name without the wpdb prefix. */
    private const string TABLE = 'cvm_form_submissions';

    /** Option key storing the installed schema version. */
    private const string DB_VERSION_OPTION = 'cvm_submissions_db_version';

    /** Current schema version; bump when the CREATE TABLE below changes. */
    private const string DB_VERSION = '1.2.0';

    /** Rows deleted per statement during retention cleanup. */
    private const int CLEANUP_CHUNK = 2000;

    /** Maximum delete chunks per cron run. */
    private const int CLEANUP_MAX_CHUNKS = 20;

    /** Wall-clock seconds budgeted for one purgeOld() run. */
    private const int CLEANUP_TIME_BUDGET = 20;

    /** Rows whose derived columns are populated per backfill pass. */
    private const int BACKFILL_CHUNK = 500;

    /**
     * Returns the fully-prefixed submissions table name.
     *
     * @return string
     */
    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Creates or updates the submissions table (idempotent via dbDelta).
     *
     * @return void
     */
    public static function createTable(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id VARCHAR(40) NOT NULL,
            conversion_id VARCHAR(100) NOT NULL,
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            provider VARCHAR(32) NOT NULL DEFAULT '',
            form_key VARCHAR(191) NOT NULL DEFAULT '',
            form_name VARCHAR(191) NOT NULL DEFAULT '',
            native_form_id VARCHAR(191) NOT NULL DEFAULT '',
            form_id VARCHAR(191) NOT NULL DEFAULT '',
            page_url VARCHAR(255) NOT NULL DEFAULT '',
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            channel VARCHAR(32) NULL,
            utm_campaign VARCHAR(191) NULL,
            page_query LONGTEXT NULL,
            submission_data LONGTEXT NULL,
            context LONGTEXT NULL,
            runtime LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY submission_id (submission_id),
            UNIQUE KEY conversion_id (conversion_id),
            KEY session_id (session_id),
            KEY created_at (created_at),
            KEY provider_form (provider,form_key(100)),
            KEY channel (channel),
            KEY utm_campaign (utm_campaign(100))
        ) {$charset};";

        dbDelta($sql);

        $expected = [
            'id', 'submission_id', 'conversion_id', 'session_id', 'provider',
            'form_key', 'form_name', 'native_form_id', 'form_id', 'page_url',
            'ip_address', 'channel', 'utm_campaign', 'page_query',
            'submission_data', 'context', 'runtime', 'created_at',
        ];
        if (
            DatabaseManager::tableHasColumns($table, $expected)
            && DatabaseManager::tableHasIndex($table, 'conversion_id')
        ) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);

            // Rows written before 1.2.0 have NULL derived columns. Fill one
            // chunk now; the daily cron finishes the rest.
            self::backfillDerivedColumns();
        }
    }

    /**
     * Creates the table when the stored schema version differs, so plugin
     * updates apply the schema without a re-activation.
     *
     * @return void
     */
    public static function maybeUpgrade(): void
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::createTable();
        }
    }

    /**
     * Inserts one submission row, deduplicating by conversion_id.
     *
     * INSERT IGNORE against the UNIQUE conversion_id index is the dedup
     * mechanism for duplicate provider callbacks (a double-fired AJAX hook,
     * a replayed request): the second insert for the same conversion is
     * silently dropped and the caller is told nothing new was stored, so no
     * duplicate webhook deliveries are ever queued.
     *
     * @param array{
     *     submission_id: string,
     *     conversion_id: string,
     *     session_id: string,
     *     provider: string,
     *     form_key: string,
     *     form_name: string,
     *     native_form_id: string,
     *     form_id: string,
     *     page_url: string,
     *     ip_address: string,
     *     channel: string,
     *     utm_campaign: string,
     *     page_query: array<string, string>,
     *     submission_data: array<string, mixed>,
     *     context: array<string, mixed>,
     *     runtime: array<string, array<string, string>>
     * } $submission Fully sanitized submission record.
     * @return int|null The new row id, or null when the insert stored nothing
     *                  (duplicate conversion_id, or a database failure).
     */
    public static function insert(array $submission): ?int
    {
        global $wpdb;

        $inserted = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::tableName()
            . ' (submission_id, conversion_id, session_id, provider, form_key, form_name,'
            . ' native_form_id, form_id, page_url, ip_address, channel, utm_campaign,'
            . ' page_query, submission_data, context, runtime, created_at)'
            . ' VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
            $submission['submission_id'],
            $submission['conversion_id'],
            $submission['session_id'],
            $submission['provider'],
            $submission['form_key'],
            $submission['form_name'],
            $submission['native_form_id'],
            $submission['form_id'],
            $submission['page_url'],
            (string) ($submission['ip_address'] ?? ''),
            mb_substr((string) ($submission['channel'] ?? ''), 0, 32),
            mb_substr((string) ($submission['utm_campaign'] ?? ''), 0, 191),
            (string) wp_json_encode($submission['page_query']),
            (string) wp_json_encode($submission['submission_data']),
            (string) wp_json_encode($submission['context']),
            (string) wp_json_encode($submission['runtime'] ?? ['query' => [], 'headers' => []]),
            gmdate('Y-m-d H:i:s')
        ));

        if ($inserted !== 1) {
            return null;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Fetches one submission row by primary key.
     *
     * @param int $id Row id.
     * @return array<string, mixed>|null
     */
    public static function get(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::tableName() . ' WHERE id = %d', $id),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Fetches one submission row by its globally unique submission id.
     *
     * @param string $submissionId Submission id.
     * @return array<string, mixed>|null
     */
    public static function getBySubmissionId(string $submissionId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::tableName() . ' WHERE submission_id = %s', $submissionId),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Populates channel / utm_campaign on rows written before schema 1.2.0.
     *
     * Self-terminating with no progress option to track: an un-backfilled row
     * is exactly one whose channel IS NULL, and every row this touches is
     * written as a string ('' when the context carries no value), so it can
     * never be selected twice. One bounded chunk per call — activation runs
     * one, the daily cleanup cron runs the rest, so a table with a year of
     * leads finishes over a few days without ever blocking a request.
     *
     * @return int Rows updated by this pass.
     */
    public static function backfillDerivedColumns(): int
    {
        global $wpdb;

        $table = self::tableName();

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, context FROM {$table} WHERE channel IS NULL ORDER BY id DESC LIMIT %d",
                self::BACKFILL_CHUNK
            ),
            ARRAY_A
        );

        if (!is_array($rows) || $rows === []) {
            return 0;
        }

        $updated = 0;

        foreach ($rows as $row) {
            $derived = self::deriveColumns((string) ($row['context'] ?? ''));

            $wpdb->update(
                $table,
                ['channel' => $derived['channel'], 'utm_campaign' => $derived['utm_campaign']],
                ['id' => (int) $row['id']],
                ['%s', '%s'],
                ['%d']
            );

            $updated++;
        }

        return $updated;
    }

    /**
     * Extracts the derived column values from a stored analytics context blob.
     *
     * Pure: no database, no WordPress state — the backfill's decision logic
     * kept separate from its SQL so it can be unit-tested directly.
     *
     * @param string $contextJson The row's stored context column.
     * @return array{channel: string, utm_campaign: string}
     */
    private static function deriveColumns(string $contextJson): array
    {
        $empty = ['channel' => '', 'utm_campaign' => ''];

        if ($contextJson === '' || !json_validate($contextJson)) {
            return $empty;
        }

        $context = json_decode($contextJson, true);
        if (!is_array($context)) {
            return $empty;
        }

        $channel = $context['channel'] ?? '';
        $campaign = is_array($context['attribution'] ?? null)
            ? ($context['attribution']['utm_campaign'] ?? '')
            : '';

        return [
            'channel'      => is_scalar($channel) ? mb_substr((string) $channel, 0, 32) : '',
            'utm_campaign' => is_scalar($campaign) ? mb_substr((string) $campaign, 0, 191) : '',
        ];
    }

    /**
     * Returns a single page of submission rows, newest-first.
     *
     * @param int                        $page    1-based page number.
     * @param int                        $perPage Rows per page (clamp in callers).
     * @param array<string, string|array> $filters See {@see buildWhereClause()}.
     * @return array<int, array<string, mixed>>
     */
    public static function getPaginated(int $page, int $perPage, array $filters = []): array
    {
        global $wpdb;

        [$where, $values] = self::buildWhereClause($filters);

        $values[] = $perPage;
        $values[] = ($page - 1) * $perPage;

        $sql = 'SELECT * FROM ' . self::tableName() . " {$where} ORDER BY id DESC LIMIT %d OFFSET %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Returns the total number of rows matching the given filters.
     *
     * @param array<string, string|array> $filters Same keys as {@see getPaginated()}.
     * @return int
     */
    public static function getCount(array $filters = []): int
    {
        global $wpdb;

        [$where, $values] = self::buildWhereClause($filters);

        $sql = 'SELECT COUNT(*) FROM ' . self::tableName() . " {$where}";

        return (int) ($values ? $wpdb->get_var($wpdb->prepare($sql, $values)) : $wpdb->get_var($sql));
    }

    /**
     * Returns one keyset-paginated chunk of rows for streaming exports,
     * newest-first.
     *
     * Iterate with $beforeId = PHP_INT_MAX, then pass the last row's id back
     * in until fewer than $limit rows return. Submission rows carry the
     * visitor's full field values, so loading a whole table at once could
     * exhaust PHP memory on a busy site.
     *
     * @param int                        $beforeId Only rows with an id strictly below this value.
     * @param int                        $limit    Maximum rows to return.
     * @param array<string, string|array> $filters  Optional filters (the export honors them).
     * @return array<int, array<string, mixed>>
     */
    public static function getChunk(int $beforeId, int $limit, array $filters = []): array
    {
        global $wpdb;

        [$where, $values] = self::buildWhereClause($filters);

        // buildWhereClause() returns either 'WHERE …' or ''; splice the keyset
        // bound into whichever shape came back.
        $where    = $where === '' ? 'WHERE id < %d' : $where . ' AND id < %d';
        $values[] = $beforeId;
        $values[] = $limit;

        $sql = 'SELECT * FROM ' . self::tableName() . " {$where} ORDER BY id DESC LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Returns the distinct calendar years and months that have submissions,
     * for the Submissions page's filter dropdowns.
     *
     * @param array<string, string|array> $filters Active filters (year/month/search excluded).
     * @return array{years: list<string>, months: list<string>}
     */
    public static function getDistinctDates(array $filters = []): array
    {
        global $wpdb;

        [$where, $values] = self::buildWhereClause(
            array_diff_key($filters, ['year' => 0, 'month' => 0, 'search' => 0])
        );

        $sql = "SELECT DISTINCT DATE_FORMAT(created_at, '%%Y-%%m') FROM " . self::tableName() . " {$where} ORDER BY 1 DESC";

        // prepare() is required even without filter values to unescape the %%.
        $rows = $wpdb->get_col($values ? $wpdb->prepare($sql, $values) : $wpdb->prepare($sql));

        $years  = [];
        $months = [];

        foreach ((array) $rows as $ym) {
            if (!is_string($ym) || strlen($ym) < 7) {
                continue;
            }
            $y = substr($ym, 0, 4);
            $m = substr($ym, 5, 2);
            if (!in_array($y, $years, true)) {
                $years[] = $y;
            }
            if (!in_array($m, $months, true)) {
                $months[] = $m;
            }
        }

        sort($months);

        return ['years' => $years, 'months' => $months];
    }

    /**
     * Returns the distinct non-empty values of one filterable column.
     *
     * The column name is never interpolated from caller input — only the four
     * names below are accepted, and anything else returns nothing.
     *
     * @param string $column One of provider, form_name, channel, utm_campaign.
     * @return list<string>
     */
    public static function getDistinctValues(string $column): array
    {
        global $wpdb;

        if (!in_array($column, ['provider', 'form_name', 'channel', 'utm_campaign'], true)) {
            return [];
        }

        $rows = $wpdb->get_col(
            "SELECT DISTINCT {$column} FROM " . self::tableName()
            . " WHERE {$column} IS NOT NULL AND {$column} <> '' ORDER BY {$column} ASC LIMIT 200"
        );

        return is_array($rows) ? array_values(array_filter($rows, 'is_string')) : [];
    }

    /**
     * Deletes a single submission row by its primary key.
     *
     * Queued deliveries for the row are left alone: the queue carries its own
     * frozen copy of the payload once a first attempt has been made, and the
     * worker already drops rows whose submission has vanished with nothing
     * frozen ({@see FormDeliveryQueue::processRow()}).
     *
     * @param int $id The row ID to delete.
     * @return void
     */
    public static function deleteSubmission(int $id): void
    {
        global $wpdb;

        $wpdb->delete(self::tableName(), ['id' => $id], ['%d']);
    }

    /**
     * Removes all submission rows (TRUNCATE also resets auto-increment).
     *
     * Activity Log rows are deliberately untouched — a delivery attempt is a
     * separate record of something the site did, and clearing leads must not
     * silently erase the outbound audit trail.
     *
     * @return void
     */
    public static function clearAll(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::tableName());
    }

    /**
     * Builds a SQL WHERE clause and its ordered values from the filters.
     *
     * @param array<string, string> $filters year, month, provider, form_name,
     *                                       channel, campaign, search, and
     *                                       delivery_status (see
     *                                       {@see deliveryStatusCondition()}).
     * @return array{0: string, 1: list<mixed>}
     */
    private static function buildWhereClause(array $filters): array
    {
        global $wpdb;

        $filterYear  = (string) ($filters['year'] ?? '');
        $filterMonth = (string) ($filters['month'] ?? '');
        $provider    = (string) ($filters['provider'] ?? '');
        $formName    = (string) ($filters['form_name'] ?? '');
        $channel     = (string) ($filters['channel'] ?? '');
        $campaign    = (string) ($filters['campaign'] ?? '');
        $search      = (string) ($filters['search'] ?? '');
        $status      = (string) ($filters['delivery_status'] ?? '');

        $conditions = [];
        $values     = [];

        if ($filterYear !== '' && ctype_digit($filterYear) && strlen($filterYear) === 4) {
            $conditions[] = 'YEAR(created_at) = %d';
            $values[]     = (int) $filterYear;
        }

        if ($filterMonth !== '' && ctype_digit($filterMonth) && (int) $filterMonth >= 1 && (int) $filterMonth <= 12) {
            $conditions[] = 'MONTH(created_at) = %d';
            $values[]     = (int) $filterMonth;
        }

        if ($provider !== '') {
            $conditions[] = 'provider = %s';
            $values[]     = $provider;
        }

        if ($formName !== '') {
            $conditions[] = 'form_name = %s';
            $values[]     = $formName;
        }

        if ($channel !== '') {
            $conditions[] = 'channel = %s';
            $values[]     = $channel;
        }

        if ($campaign !== '') {
            $conditions[] = 'utm_campaign = %s';
            $values[]     = $campaign;
        }

        if ($search !== '') {
            // The two id columns match exactly: pasting a submission_id or
            // conversion_id should find that one row, not LIKE-scan for it.
            $like         = '%' . $wpdb->esc_like($search) . '%';
            $conditions[] = '(submission_data LIKE %s OR form_name LIKE %s OR page_url LIKE %s'
                          . ' OR submission_id = %s OR conversion_id = %s)';
            $values[]     = $like;
            $values[]     = $like;
            $values[]     = $like;
            $values[]     = $search;
            $values[]     = $search;
        }

        $statusCondition = self::deliveryStatusCondition($status);
        if ($statusCondition !== '') {
            $conditions[] = $statusCondition;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $values];
    }

    /**
     * The SQL condition selecting submissions in one webhook delivery state.
     *
     * Delivery state is not stored on the submission — it is whatever the
     * delivery log and the queue currently say, so it is derived here rather
     * than denormalized into a column that retries would have to keep in sync.
     *
     * The definitions deliberately compare a submission against the endpoints
     * it was ACTUALLY attempted against, never against the endpoints
     * configured right now: adding a third endpoint today must not retroactively
     * turn last month's successful two-endpoint delivery into "partial".
     *
     * Contains no caller input — the state name is matched against a fixed
     * list and the table names come from constants — so nothing here needs
     * prepare() placeholders.
     *
     * @param string $state delivered, partial, failed, pending, or not_sent.
     * @return string A SQL condition, or '' when the state is unrecognized.
     */
    private static function deliveryStatusCondition(string $state): string
    {
        $queue      = FormDeliveryQueue::tableName();
        $deliveries = DeliveryLog::tableName();
        $table      = self::tableName();

        // Correlated existence checks; both tables index submission_id.
        $inQueue = "EXISTS (SELECT 1 FROM {$queue} q WHERE q.submission_id = {$table}.submission_id)";
        $inLog   = "EXISTS (SELECT 1 FROM {$deliveries} d WHERE d.submission_id = {$table}.submission_id"
                 . " AND d.message_type = 'form_submission')";

        // Per-submission tallies of endpoints attempted vs. endpoints that
        // eventually acknowledged, as a HAVING over the delivery log.
        $grouped = static fn(string $having): string =>
            "{$table}.submission_id IN ("
            . "SELECT g.submission_id FROM {$deliveries} g"
            . " WHERE g.message_type = 'form_submission' AND g.submission_id <> ''"
            . ' GROUP BY g.submission_id'
            . " HAVING {$having})";

        $attempted = 'COUNT(DISTINCT g.endpoint_url)';
        $ok        = 'COUNT(DISTINCT CASE WHEN g.success = 1 THEN g.endpoint_url END)';

        return match ($state) {
            // Still queued or mid-retry: the queue row is the live one.
            'pending'   => $inQueue,
            // Nothing was ever queued and nothing was ever sent — the normal
            // state when no form webhook endpoint is configured.
            'not_sent'  => "NOT {$inQueue} AND NOT {$inLog}",
            'delivered' => "NOT {$inQueue} AND " . $grouped("{$attempted} = {$ok}"),
            'partial'   => "NOT {$inQueue} AND " . $grouped("{$ok} > 0 AND {$ok} < {$attempted}"),
            // Every attempt against every endpoint failed and the retry chain
            // is spent.
            'failed'    => "NOT {$inQueue} AND " . $grouped("{$ok} = 0"),
            default     => '',
        };
    }

    /**
     * Deletes rows older than the plugin's retention window. Runs on the
     * same daily cron as the events-table cleanup, in bounded chunks.
     *
     * @return void
     */
    public static function purgeOld(): void
    {
        global $wpdb;

        $cutoff   = gmdate('Y-m-d H:i:s', time() - Options::retentionDays() * DAY_IN_SECONDS);
        $table    = self::tableName();
        $deadline = microtime(true) + self::CLEANUP_TIME_BUDGET;
        $runs     = 0;

        do {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s LIMIT %d",
                $cutoff,
                self::CLEANUP_CHUNK
            ));
        } while (
            is_int($deleted) && $deleted === self::CLEANUP_CHUNK
            && ++$runs < self::CLEANUP_MAX_CHUNKS
            && microtime(true) < $deadline
        );
    }
}
