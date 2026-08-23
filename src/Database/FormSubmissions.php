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
 *
 * delivery_state and delivery_json are the RECORDED outcome of webhook
 * delivery, written by {@see refreshDeliveryState()} whenever an outcome
 * changes. They are stored rather than re-derived because the Activity Log
 * they would be derived from is independently clearable — which would let a
 * delivered lead silently revert to "not sent" in the list, the status filter,
 * and the CSV export. The log stays diagnostic; this is the record.
 */
final class FormSubmissions
{
    /** Table name without the wpdb prefix. */
    private const string TABLE = 'cvm_form_submissions';

    /** Recognized webhook delivery states (see {@see classifyDelivery()}). */
    public const array DELIVERY_STATES = ['delivered', 'partial', 'failed', 'pending', 'not_sent'];

    /** Option key storing the installed schema version. */
    private const string DB_VERSION_OPTION = 'cvm_submissions_db_version';

    /** Current schema version; bump when the CREATE TABLE below changes. */
    private const string DB_VERSION = '1.3.0';

    /** Rows deleted per statement during retention cleanup. */
    private const int CLEANUP_CHUNK = 2000;

    /** Maximum delete chunks per cron run. */
    private const int CLEANUP_MAX_CHUNKS = 20;

    /** Wall-clock seconds budgeted for one purgeOld() run. */
    private const int CLEANUP_TIME_BUDGET = 20;

    /** Rows whose derived columns are populated per backfill chunk. */
    private const int BACKFILL_CHUNK = 500;

    /** Maximum chunks per backfill run. */
    private const int BACKFILL_MAX_CHUNKS = 40;

    /** Wall-clock seconds budgeted for one backfillDerivedColumns() run. */
    private const int BACKFILL_TIME_BUDGET = 10;

    /** Cron hook that drains the derived-column backfill after an upgrade. */
    public const string BACKFILL_CATCHUP_HOOK = 'cvm_submissions_backfill_catchup';

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
            delivery_state VARCHAR(16) NULL,
            delivery_json TEXT NULL,
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
            KEY utm_campaign (utm_campaign(100)),
            KEY delivery_state (delivery_state)
        ) {$charset};";

        dbDelta($sql);

        $expected = [
            'id', 'submission_id', 'conversion_id', 'session_id', 'provider',
            'form_key', 'form_name', 'native_form_id', 'form_id', 'page_url',
            'ip_address', 'channel', 'utm_campaign', 'delivery_state',
            'delivery_json', 'page_query', 'submission_data', 'context',
            'runtime', 'created_at',
        ];

        // Every index the admin page FILTERS on has to be verified, not just
        // the dedup one. dbDelta can add columns while silently skipping an
        // index; recording the schema version anyway would mark that partial
        // migration complete and it would never be retried.
        $indexes = ['conversion_id', 'channel', 'utm_campaign', 'delivery_state'];

        foreach ($indexes as $index) {
            if (!DatabaseManager::tableHasIndex($table, $index)) {
                return;
            }
        }

        if (DatabaseManager::tableHasColumns($table, $expected)) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);

            // Rows written before 1.2.0/1.3.0 have NULL derived columns. Run a
            // budgeted pass now and schedule a catch-up; the daily cron picks
            // up anything still left.
            self::backfillDerivedColumns();
            self::scheduleBackfillCatchUp();
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
            self::encodeJson($submission['page_query']),
            self::encodeJson($submission['submission_data']),
            self::encodeJson($submission['context']),
            self::encodeJson($submission['runtime'] ?? ['query' => [], 'headers' => []]),
            gmdate('Y-m-d H:i:s')
        ));

        if ($inserted !== 1) {
            return null;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Encodes a stored JSON column, keeping non-ASCII text readable.
     *
     * PHP's default escapes "José" to "José", which the Submissions
     * page's LIKE search over submission_data could never match — a lead
     * called José was simply unfindable by name. Storing the characters
     * verbatim fixes that at the source.
     *
     * This is a STORAGE format only. PayloadBuilder decodes these columns and
     * re-encodes the whole payload before delivery, so the webhook wire format
     * and its HMAC signature are unaffected.
     *
     * @param mixed $value Value to encode.
     * @return string
     */
    private static function encodeJson(mixed $value): string
    {
        return (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
     * Recomputes and stores one submission's webhook delivery state.
     *
     * Called at every point an outcome changes (queued, attempted, abandoned),
     * so the submission row itself carries the answer. Delivery state used to
     * be re-derived from the Activity Log at display time, which meant
     * "Clear All Logs" could turn a delivered lead back into "Not sent" — and
     * the status filter and CSV export inherited that false value. The log is
     * now purely diagnostic; this snapshot is the record.
     *
     * Recomputing from the authoritative tables (rather than merging a single
     * outcome into the stored JSON) keeps concurrent workers from clobbering
     * each other: whoever writes last writes the current truth.
     *
     * @param string $submissionId The submission's globally unique id.
     * @return void
     */
    public static function refreshDeliveryState(string $submissionId): void
    {
        global $wpdb;

        if ($submissionId === '') {
            return;
        }

        // Ordered ascending so the LAST attempt per endpoint wins — see
        // buildEndpointOutcomes().
        $logRows = $wpdb->get_results($wpdb->prepare(
            'SELECT endpoint_url, endpoint_label, success, response_code, attempt, created_at'
            . ' FROM ' . DeliveryLog::tableName()
            . " WHERE submission_id = %s AND message_type = 'form_submission'"
            . ' ORDER BY id ASC',
            $submissionId
        ), ARRAY_A);

        $queueRows = $wpdb->get_results($wpdb->prepare(
            'SELECT endpoint_url, status, attempt, next_attempt_at'
            . ' FROM ' . FormDeliveryQueue::tableName()
            . ' WHERE submission_id = %s',
            $submissionId
        ), ARRAY_A);

        $endpoints = self::buildEndpointOutcomes(
            is_array($logRows) ? $logRows : [],
            is_array($queueRows) ? $queueRows : []
        );

        $wpdb->update(
            self::tableName(),
            [
                'delivery_state' => self::classifyDelivery($endpoints),
                'delivery_json'  => (string) wp_json_encode(
                    $endpoints,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ],
            ['submission_id' => $submissionId],
            ['%s', '%s'],
            ['%s']
        );
    }

    /**
     * Reduces a submission's delivery history to one outcome per endpoint.
     *
     * Two rules, both of which the first implementation got wrong:
     *
     *  - The LAST attempt against an endpoint is that endpoint's outcome. The
     *    original query took MAX(success) and MAX(response_code) as
     *    independent aggregates, so a 500 followed by a successful 200 retry
     *    reported "Delivered (500)" — a success paired with the failure's
     *    status code.
     *  - A queue row outranks any log row for the same endpoint: the delivery
     *    is still in flight, so its last failed attempt is not yet the verdict.
     *
     * Pure: no database, no WordPress state.
     *
     * @param array<int, array<string, mixed>> $logRows   Delivery-log rows, oldest first.
     * @param array<int, array<string, mixed>> $queueRows Undelivered queue rows.
     * @return list<array<string, mixed>>
     */
    public static function buildEndpointOutcomes(array $logRows, array $queueRows): array
    {
        $endpoints = [];

        foreach ($logRows as $row) {
            $url = (string) ($row['endpoint_url'] ?? '');

            // Overwrites any earlier attempt against this endpoint; callers
            // pass rows oldest-first, so the newest verdict survives.
            $endpoints[$url] = [
                'url'     => $url,
                'label'   => (string) ($row['endpoint_label'] ?? ''),
                'ok'      => (int) ($row['success'] ?? 0) === 1,
                'code'    => (int) ($row['response_code'] ?? 0),
                'attempt' => (int) ($row['attempt'] ?? 0),
                'queued'  => false,
                'at'      => (string) ($row['created_at'] ?? ''),
            ];
        }

        foreach ($queueRows as $row) {
            $url = (string) ($row['endpoint_url'] ?? '');

            $endpoints[$url] = [
                'url'     => $url,
                'label'   => (string) ($endpoints[$url]['label'] ?? ''),
                'ok'      => false,
                'code'    => 0,
                'attempt' => (int) ($row['attempt'] ?? 0),
                'queued'  => true,
                'at'      => (string) ($row['next_attempt_at'] ?? ''),
            ];
        }

        return array_values($endpoints);
    }

    /**
     * The delivery state implied by a submission's per-endpoint outcomes.
     *
     * A submission is judged against the endpoints it was ACTUALLY attempted
     * against, never against the endpoints configured right now: adding a third
     * endpoint today must not retroactively downgrade last month's successful
     * two-endpoint delivery to "partial".
     *
     * 'not_sent' is a NEUTRAL state, never a failure — it is the ordinary
     * condition of a site that uses the plugin without webhooks at all. How it
     * is worded (paused vs. never configured) is a display concern and lives
     * in the admin page.
     *
     * Pure: no database, no WordPress state.
     *
     * @param list<array<string, mixed>> $endpoints Per-endpoint outcomes.
     * @return string delivered, partial, failed, pending, or not_sent.
     */
    public static function classifyDelivery(array $endpoints): string
    {
        if ($endpoints === []) {
            return 'not_sent';
        }

        foreach ($endpoints as $endpoint) {
            if (!empty($endpoint['queued'])) {
                return 'pending';
            }
        }

        $ok = 0;
        foreach ($endpoints as $endpoint) {
            if (!empty($endpoint['ok'])) {
                $ok++;
            }
        }

        return match (true) {
            $ok === count($endpoints) => 'delivered',
            $ok > 0                   => 'partial',
            default                   => 'failed',
        };
    }

    /**
     * Populates channel / utm_campaign on rows written before schema 1.2.0.
     *
     * Self-terminating with no progress option to track: an un-backfilled row
     * is exactly one whose channel IS NULL, and every row this touches is
     * written as a string ('' when the context carries no value), so it can
     * never be selected twice.
     *
     * Runs as many chunks as fit in a wall-clock budget, mirroring
     * {@see purgeOld()}. A single 500-row pass per day meant ten thousand
     * legacy rows took roughly three weeks to become filterable — and never
     * finished at all on a site whose WP-Cron is broken. A budgeted loop
     * clears that same table in one run while still refusing to block a
     * request indefinitely.
     *
     * @return int Rows updated by this pass.
     */
    public static function backfillDerivedColumns(): int
    {
        global $wpdb;

        $table    = self::tableName();
        $deadline = microtime(true) + self::BACKFILL_TIME_BUDGET;
        $updated  = 0;
        $passes   = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, submission_id, context, channel, delivery_state FROM {$table}"
                    . ' WHERE channel IS NULL OR delivery_state IS NULL'
                    . ' ORDER BY id DESC LIMIT %d',
                    self::BACKFILL_CHUNK
                ),
                ARRAY_A
            );

            if (!is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                // The two halves are filled independently. A row can be missing
                // one and not the other (it predates only 1.3.0), and rewriting
                // a column that is already populated would be a pointless write
                // at best — and would clobber a value some other code path set
                // at worst.
                if ($row['channel'] === null) {
                    $derived = self::deriveColumns((string) ($row['context'] ?? ''));

                    $wpdb->update(
                        $table,
                        ['channel' => $derived['channel'], 'utm_campaign' => $derived['utm_campaign']],
                        ['id' => (int) $row['id']],
                        ['%s', '%s'],
                        ['%d']
                    );
                }

                // Reconstructs delivery_state/delivery_json from whatever the
                // log and queue still hold. Rows whose log entries have already
                // aged out settle on 'not_sent', which is the honest answer:
                // there is no longer any evidence a delivery happened.
                if ($row['delivery_state'] === null) {
                    self::refreshDeliveryState((string) ($row['submission_id'] ?? ''));
                }

                $updated++;
            }
        } while (
            count($rows) === self::BACKFILL_CHUNK
            && ++$passes < self::BACKFILL_MAX_CHUNKS
            && microtime(true) < $deadline
        );

        return $updated;
    }

    /**
     * Whether any row still needs its derived columns populated.
     *
     * Lets the admin page nudge the migration along on sites where WP-Cron
     * never fires — without that, those sites would show blank attribution and
     * a broken status filter forever.
     *
     * @return bool
     */
    public static function needsBackfill(): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var(
            'SELECT 1 FROM ' . self::tableName()
            . ' WHERE channel IS NULL OR delivery_state IS NULL LIMIT 1'
        );
    }

    /**
     * Schedules a one-off catch-up run shortly after an upgrade, so a table
     * too large for the upgrade's own budgeted pass keeps draining without
     * waiting for the next daily cleanup.
     *
     * Mirrors {@see DatabaseManager::scheduleCleanupCatchUp()}.
     *
     * @return void
     */
    public static function scheduleBackfillCatchUp(): void
    {
        if (self::needsBackfill() && !wp_next_scheduled(self::BACKFILL_CATCHUP_HOOK)) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, self::BACKFILL_CATCHUP_HOOK);
        }
    }

    /**
     * Cron callback: runs another budgeted backfill pass and re-arms itself
     * while work remains.
     *
     * @return void
     */
    public static function backfillCatchUp(): void
    {
        self::backfillDerivedColumns();
        self::scheduleBackfillCatchUp();
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
     * Deletes a single submission row and cancels its pending deliveries.
     *
     * Cancelling the queue rows is the whole point, not housekeeping. Once a
     * delivery has made its first attempt the queue row holds a FROZEN COPY of
     * the payload — the visitor's field values included — and the worker will
     * keep replaying those bytes on the retry schedule. Deleting only the
     * submission would leave the admin's "removed permanently" action quietly
     * transmitting the erased lead for hours afterwards.
     *
     * Activity Log rows are deliberately untouched: a delivery attempt is a
     * record of something the site did, and erasing a lead must not silently
     * destroy the outbound audit trail.
     *
     * @param int $id The row ID to delete.
     * @return void
     */
    public static function deleteSubmission(int $id): void
    {
        global $wpdb;

        // Read the submission's globally unique id before the row goes away —
        // the queue is keyed by that, not by the numeric row id.
        $submissionId = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT submission_id FROM ' . self::tableName() . ' WHERE id = %d',
            $id
        ));

        $wpdb->delete(self::tableName(), ['id' => $id], ['%d']);

        if ($submissionId !== '') {
            $wpdb->delete(FormDeliveryQueue::tableName(), ['submission_id' => $submissionId], ['%s']);
        }
    }

    /**
     * Removes every submission row and cancels every pending delivery.
     *
     * Both halves are required. Beyond the frozen-payload problem described on
     * {@see deleteSubmission()}, TRUNCATE resets AUTO_INCREMENT: a queue row
     * left pointing at row id 5 would otherwise be matched against whatever
     * NEW submission next takes id 5, delivering an unrelated visitor's lead
     * under the old delivery record's identity. Draining the queue here — and
     * resolving by submission_id in the worker — closes that off from both
     * ends.
     *
     * Activity Log rows are deliberately untouched, as above.
     *
     * @return void
     */
    public static function clearAll(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::tableName());

        // The queue carries form-submission deliveries only, so every row in
        // it belongs to a submission that no longer exists.
        $wpdb->query('DELETE FROM ' . FormDeliveryQueue::tableName());
    }

    /**
     * Builds a SQL WHERE clause and its ordered values from the filters.
     *
     * @param array<string, string> $filters year, month, provider, form_name,
     *                                       channel, campaign, search, and
     *                                       delivery_status (see
     *                                       delivered/partial/failed/pending/not_sent).
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
            $like  = '%' . $wpdb->esc_like($search) . '%';
            $clause = 'submission_data LIKE %s OR form_name LIKE %s OR page_url LIKE %s'
                    . ' OR submission_id = %s OR conversion_id = %s';

            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
            $values[] = $search;
            $values[] = $search;

            // Rows written before the encoder switched to JSON_UNESCAPED_UNICODE
            // hold "José" as "José", which a literal LIKE can never match.
            // Search the escaped spelling too so historical leads stay findable
            // without rewriting every stored row.
            $escaped = self::jsonEscapedTerm($search);
            if ($escaped !== null) {
                $clause  .= ' OR submission_data LIKE %s';
                $values[] = '%' . $wpdb->esc_like($escaped) . '%';
            }

            $conditions[] = '(' . $clause . ')';
        }

        if (in_array($status, self::DELIVERY_STATES, true)) {
            // A plain indexed comparison now that the state is stored on the
            // row. This used to be a pair of correlated EXISTS subqueries plus
            // a GROUP BY ... HAVING over the whole delivery log.
            $conditions[] = 'delivery_state = %s';
            $values[]     = $status;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $values];
    }

    /**
     * A search term rewritten the way PHP's default JSON encoder would have
     * stored it, or null when the term is pure ASCII and needs no second form.
     *
     * @param string $term Raw search term.
     * @return string|null
     */
    private static function jsonEscapedTerm(string $term): ?string
    {
        $encoded = json_encode($term);
        if (!is_string($encoded)) {
            return null;
        }

        $escaped = trim($encoded, '"');

        return $escaped === $term ? null : $escaped;
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
