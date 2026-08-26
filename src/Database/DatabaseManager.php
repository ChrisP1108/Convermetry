<?php
declare(strict_types=1);

namespace Convermetry\Database;

if (!defined('ABSPATH')) exit;

use Convermetry\Goals\GoalRecorder;
use Convermetry\Goals\GoalRepository;
use Convermetry\Goals\GoalSettings;
use Convermetry\Leads\Money;
use Convermetry\Settings\Options;
use Convermetry\Support\ClientIp;
use Convermetry\Support\Url;
use Convermetry\Tracking\Channels;

/**
 * Owns the custom analytics events table.
 *
 * Responsible for schema creation and upgrades (via dbDelta), inserting
 * event rows, and the daily retention cleanup that keeps the table bounded
 * to the configured number of days.
 *
 * One row = one visitor interaction. The columns are deliberately flat and
 * generic (element_tag / element_label / target_url / event_value) so every
 * event type — pageviews, clicks, form submissions, hovers, scroll depth,
 * server-confirmed form conversions, and custom server-side events — fits
 * the same table and the reporting queries in
 * {@see \Convermetry\Analytics\Reports} stay simple.
 *
 * Identifier semantics for form_success rows: event_value holds the
 * conversion_id, the single identifier every conversion count and listing
 * deduplicates by. A conversion recorded by both the frontend tracker and a
 * server-side form provider hook shares one conversion_id, so the two paths
 * can never double-count.
 *
 * form_key is the form lifecycle's shared dimension (see FORM_TYPES): one
 * provider-qualified identity across form_view → form_start → form_error →
 * form_submit → form_success, so abandonment reporting is a single indexed
 * GROUP BY rather than a guess at matching display names, and a funnel step can
 * name one specific form. It is '' on every other event type.
 *
 * ORDERING: created_at is the moment the row was INSERTED, not the moment the
 * interaction happened in the browser — the tracker sends no client timestamp.
 * created_at order and id order are therefore the same order by construction,
 * and id is the finer, tie-free version of it (created_at has one-second
 * resolution, and a whole batch commonly lands inside one second). Anything that
 * needs to know which of two events came first — funnel step progression above
 * all — must chain on id. Within one batch, browser order is preserved: the
 * tracker's array order becomes batch_seq order becomes id order. Across
 * batches, the order is delivery order, so a batch that failed and was resent
 * from a later page sorts by when it arrived.
 */
final class DatabaseManager
{
    /** Table name without the wpdb prefix. */
    private const string TABLE = 'cvm_events';

    /** Option key storing the installed schema version. */
    private const string DB_VERSION_OPTION = 'cvm_db_version';

    /** Current schema version; bump when the CREATE TABLE below changes. */
    private const string DB_VERSION = '1.2.0';

    /**
     * Every index {@see createTable()} must verify before stamping the version.
     *
     * batch_event carries batch-replay dedup; form_type_date serves every
     * form-lifecycle report; session_type_id serves the funnel step chain.
     * dbDelta can add columns while silently skipping an index, and recording
     * the version anyway would mark that partial migration complete forever.
     *
     * Public so a shape test can compare it against the DDL.
     *
     * @return string[]
     */
    public static function expectedIndexes(): array
    {
        return ['batch_event', 'form_type_date', 'session_type_id'];
    }

    /**
     * Every column {@see createTable()} must verify before stamping the version.
     *
     * @return string[]
     */
    public static function expectedColumns(): array
    {
        return array_merge(['id'], self::COLUMNS, ['batch_id', 'batch_seq']);
    }

    /**
     * Returns the fully-prefixed events table name.
     *
     * @return string
     */
    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Creates or updates the events table.
     *
     * Uses dbDelta so the call is idempotent: re-activating the plugin or
     * upgrading to a version with new columns/indexes is safe and lossless.
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
            event_type VARCHAR(20) NOT NULL,
            page_url VARCHAR(255) NOT NULL DEFAULT '',
            page_title VARCHAR(255) NOT NULL DEFAULT '',
            element_tag VARCHAR(32) NOT NULL DEFAULT '',
            element_label VARCHAR(191) NOT NULL DEFAULT '',
            target_url VARCHAR(255) NOT NULL DEFAULT '',
            event_value VARCHAR(100) NOT NULL DEFAULT '',
            referrer VARCHAR(255) NOT NULL DEFAULT '',
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            device VARCHAR(20) NOT NULL DEFAULT '',
            utm_source VARCHAR(100) NOT NULL DEFAULT '',
            utm_medium VARCHAR(100) NOT NULL DEFAULT '',
            utm_campaign VARCHAR(191) NOT NULL DEFAULT '',
            utm_id VARCHAR(100) NOT NULL DEFAULT '',
            utm_term VARCHAR(191) NOT NULL DEFAULT '',
            utm_content VARCHAR(191) NOT NULL DEFAULT '',
            click_id_type VARCHAR(20) NOT NULL DEFAULT '',
            channel VARCHAR(24) NOT NULL DEFAULT '',
            form_key VARCHAR(191) NOT NULL DEFAULT '',
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            batch_id VARCHAR(40) DEFAULT NULL,
            batch_seq SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY batch_event (batch_id,batch_seq),
            KEY type_date (event_type,created_at),
            KEY type_session_date (event_type,session_id,created_at),
            KEY created_at (created_at),
            KEY page_url (page_url(191)),
            KEY form_type_date (form_key(100),event_type,created_at),
            KEY session_type_id (session_id,event_type,id)
        ) {$charset};";

        dbDelta($sql);

        // Only record the schema version once the table verifiably carries
        // every expected column AND every expected index — a failed or partial
        // dbDelta run (out of disk, lost connection, a killed index build on a
        // large table) must be retried instead of being silently marked
        // complete. Indexes are checked explicitly because what lives in them is
        // not visible in the column list: batch-replay dedup (batch_event), the
        // form-lifecycle reports (form_type_date), and the funnel step chain
        // (session_type_id).
        foreach (self::expectedIndexes() as $index) {
            if (!self::tableHasIndex($table, $index)) {
                return;
            }
        }

        if (self::tableHasColumns($table, self::expectedColumns())) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }

    /**
     * Whether a table has an index with the given name.
     *
     * @param string $table Fully-prefixed table name.
     * @param string $index Index (Key_name) to look for.
     * @return bool
     */
    public static function tableHasIndex(string $table, string $index): bool
    {
        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            'SHOW INDEX FROM %i WHERE Key_name = %s',
            $table,
            $index
        ));

        return is_array($rows) && $rows !== [];
    }

    /**
     * Whether a table exists and contains every listed column.
     *
     * Used to verify a dbDelta migration actually landed before its schema
     * version is recorded (shared by every table owner in this plugin).
     *
     * @param string   $table   Fully-prefixed table name.
     * @param string[] $columns Column names that must all exist.
     * @return bool
     */
    public static function tableHasColumns(string $table, array $columns): bool
    {
        global $wpdb;

        $existing = $wpdb->get_col($wpdb->prepare('SHOW COLUMNS FROM %i', $table));
        if (!is_array($existing) || $existing === []) {
            return false;
        }

        return array_diff($columns, $existing) === [];
    }

    /**
     * Runs the table creation again when the stored schema version differs
     * from the current one, so plugin updates that change the schema are
     * applied without requiring a re-activation.
     *
     * @return void
     */
    public static function maybeUpgrade(): void
    {
        if (self::needsUpgrade()) {
            self::createTable();
        }
    }

    /**
     * Whether the recorded schema version differs from the one this build
     * ships.
     *
     * Read by {@see MigrationRunner}, which decides which request is allowed to
     * act on the answer — the 1.2.0 migration adds two indexes to what is
     * usually the largest table on the site, and that must never run inside an
     * anonymous visitor's page load.
     *
     * @return bool
     */
    public static function needsUpgrade(): bool
    {
        return get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION;
    }

    /**
     * @var string[] Event types that carry campaign attribution and therefore
     *      get a marketing channel derived at ingestion — every tracker event
     *      type, so clicks, form attempts, hovers, and scroll milestones can
     *      be segmented by channel just like pageviews and conversions.
     */
    private const array ATTRIBUTED_TYPES = [
        'pageview', 'click', 'form_submit', 'form_success', 'hover', 'scroll_depth',
        'form_view', 'form_start', 'form_error', 'custom_event',
    ];

    /** @var string[] Insertable columns, in the order bulk inserts serialize them. */
    private const array COLUMNS = [
        'event_type', 'page_url', 'page_title', 'element_tag', 'element_label',
        'target_url', 'event_value', 'referrer', 'session_id', 'device',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'utm_term',
        'utm_content', 'click_id_type', 'channel', 'form_key', 'ip_address',
        'created_at',
    ];

    /**
     * @var string[] Event types that carry a form identity in the form_key
     *      column. These are the form lifecycle: seeing a form, starting to fill
     *      it, failing its validation, attempting a submit, and the confirmed
     *      success. One indexed dimension across all five is what lets the
     *      abandonment report be a single GROUP BY instead of a heuristic match
     *      on display names, and what lets a funnel step target one specific
     *      form durably.
     */
    private const array FORM_TYPES = ['form_view', 'form_start', 'form_error', 'form_submit', 'form_success'];

    /** Rows deleted per statement during retention cleanup. */
    private const int CLEANUP_CHUNK = 5000;

    /** Maximum delete chunks per daily cron run, so one request can't run indefinitely. */
    private const int CLEANUP_MAX_CHUNKS = 40;

    /**
     * Maximum delete chunks per catch-up invocation — deliberately smaller
     * than the daily run's budget, gentler on shared/budget hosting for what
     * is, by definition, an unattended follow-up run rather than the main
     * scheduled pass.
     */
    private const int CLEANUP_CATCHUP_MAX_CHUNKS = 8;

    /**
     * Wall-clock seconds budgeted per invocation for a bounded delete loop.
     * A chunk-count cap alone doesn't bound elapsed time if individual
     * DELETE statements run slower than expected (lock contention, an
     * overloaded shared host, replication lag); this is comfortably under
     * typical PHP max_execution_time defaults.
     */
    private const int CLEANUP_TIME_BUDGET = 20;

    /** Option key for the cleanup mutex (events-table deletion only — see acquireCleanupLock()). */
    private const string CLEANUP_LOCK_OPTION = 'cvm_cleanup_lock';

    /**
     * Seconds after which the cleanup lock's lease is considered stale and
     * may be stolen. Tied to CLEANUP_TIME_BUDGET by formula (2x) so the two
     * can't drift out of sync if one is retuned later.
     */
    private const int CLEANUP_LOCK_TIMEOUT = self::CLEANUP_TIME_BUDGET * 2;

    /**
     * Cron hook for a one-shot catch-up continuation, scheduled when a
     * cleanup run's own bound is hit before the backlog is cleared (or the
     * lock could not be acquired, or was lost mid-loop, or a DELETE itself
     * failed). Self-perpetuating from there until a run reports 'completed'.
     */
    public const string CLEANUP_CATCHUP_HOOK = 'cvm_cleanup_old_events_catchup';

    /** Seconds before retrying after a failed lock acquisition. */
    private const int CLEANUP_RETRY_COOLDOWN = 5 * MINUTE_IN_SECONDS;

    /**
     * Seconds before the next catch-up attempt when the previous one
     * genuinely ran (acquired the lock) but did not finish the backlog.
     */
    private const int CLEANUP_CATCHUP_CADENCE = 20 * MINUTE_IN_SECONDS;

    /** Rate-limit-counter option rows deleted per statement. */
    private const int CLEANUP_RATE_LIMIT_CHUNK = 5000;

    /**
     * Maximum rate-limit-counter delete chunks per run — generous relative
     * to plausible per-IP-hash row volume for one day's distinct IPs.
     */
    private const int CLEANUP_RATE_LIMIT_MAX_CHUNKS = 20;

    /**
     * Inserts a single event row.
     *
     * @param string               $type Event type key (e.g. "pageview", "click").
     * @param array<string, mixed> $data Event context; see the column list in createTable().
     * @return bool True when the row was inserted.
     */
    public static function insertEvent(string $type, array $data): bool
    {
        return self::insertEvents([['type' => $type, 'data' => $data]]) === 1;
    }

    /**
     * Inserts a batch of events with a single multi-row INSERT.
     *
     * When $batchId is given (the tracker's client-generated batch id), every
     * row is written with (batch_id, batch_seq) under the table's UNIQUE
     * batch_event index, and the statement runs as INSERT IGNORE. Delivery
     * from the browser is at-least-once (a batch whose response is lost is
     * replayed), so a replayed batch's rows collide with the originals and
     * are silently skipped instead of double-counting every metric.
     * Server-side events (cvm_track_event(), form provider hooks) carry no
     * batch id: batch_id stays NULL, which the unique index never collides
     * on, and a plain INSERT is used so genuine errors are not downgraded to
     * warnings.
     *
     * GOAL MATCHING RUNS BEFORE THE INSERT, not after. Two things depend on
     * that order:
     *
     *  - A custom_event exists only to be matched. If no goal is configured for
     *    its name it means nothing to anyone, and storing it would let one typo
     *    in a theme's JavaScript fill this table with rows no report reads. It is
     *    dropped here, before it is ever written.
     *  - The matcher needs context this table has nowhere to hold — the session's
     *    landing page, the goal ids a CSS selector matched in the browser, a
     *    supplied numeric value. sanitizeRow() normalizes every row to the fixed
     *    COLUMNS list and discards the rest (it must: bulk inserts serialize by
     *    that list). So each event travels as a {@see PreparedEvent} envelope
     *    carrying both.
     *
     * @param array<int, array{type: string, data: array<string, mixed>, seq?: int}> $events  Events to insert; 'seq' is
     *                                                                                        the event's index in the
     *                                                                                        original client batch.
     * @param string|null                                                            $batchId Client batch id, or null.
     * @return int|false Number of NEW rows inserted (replayed duplicates are
     *                   not counted), or false when the INSERT itself failed —
     *                   callers must not acknowledge the batch in that case.
     */
    public static function insertEvents(array $events, ?string $batchId = null): int|false
    {
        global $wpdb;

        // Resolved once for the whole batch: every event in one request comes
        // from the same visitor, and the 'convermetry_client_ip' filter should
        // not run per row. forStorage() applies the privacy gates (setting
        // off, or an honored DNT/GPC signal) and yields '' when it must not
        // be stored.
        $ip = ClientIp::forStorage();

        /** @var array<int, PreparedEvent> $prepared Keyed by ORIGINAL batch position. */
        $prepared = [];

        foreach ($events as $index => $event) {
            $type = (string) ($event['type'] ?? '');
            $data = (array) ($event['data'] ?? []);
            $row  = self::sanitizeRow($type, $data, $ip);

            if ($row === null) {
                continue;
            }

            // The ordinal comes from the event's position in the ORIGINAL
            // request, not the surviving-row index — a settings change
            // between attempts may drop different events, and shifted
            // ordinals would let a replayed event dodge the unique index.
            $seq = (int) ($event['seq'] ?? $index);

            $prepared[$seq] = new PreparedEvent(
                row: $row,
                seq: $seq,
                batchId: $batchId,
                eventUid: PreparedEvent::mintUid($batchId, $seq),
                landingPage: self::truncate(
                    Url::boundedUrl($data['session_landing'] ?? '', true),
                    255
                ),
                selectorGoals: self::selectorGoalIds($data['selector_goals'] ?? null),
                customEventName: $row['event_type'] === 'custom_event' ? $row['element_label'] : '',
                dynamicValue: Money::parse($data['goal_value'] ?? null),
            );
        }

        if ($prepared === []) {
            return 0;
        }

        $plan = GoalRecorder::plan($prepared);

        foreach ($plan['drop'] as $seq) {
            unset($prepared[$seq]);
        }

        if ($prepared === []) {
            // Every event in this batch was an unmatched custom event. Nothing
            // was stored, and nothing went wrong — 0 is the honest answer, and
            // it is not an error the client should retry.
            return 0;
        }

        $inserted = self::writeRows($prepared, $batchId);

        // A false return means the statement itself failed (e.g. the database
        // went away) — distinct from 0, which just means every row was a
        // replayed duplicate. Callers use the difference to decide between
        // acknowledging the batch and telling the client to retry it.
        if ($inserted === false) {
            return false;
        }

        $matches = array_intersect_key($plan['matches'], $prepared);

        if ($matches !== []) {
            self::resolveSourceEventIds($prepared, array_keys($matches), $batchId, $inserted);
            GoalRecorder::record($prepared, $matches, gmdate('Y-m-d H:i:s'));
        }

        return $inserted;
    }

    /**
     * Writes the prepared rows with a single multi-row statement.
     *
     * @param array<int, PreparedEvent> $prepared Envelopes to store.
     * @param string|null               $batchId  Client batch id, or null.
     * @return int|false Rows stored, or false when the statement failed.
     */
    private static function writeRows(array $prepared, ?string $batchId): int|false
    {
        global $wpdb;

        $columns     = self::COLUMNS;
        $placeholder = array_fill(0, count(self::COLUMNS), '%s');
        if ($batchId !== null) {
            $columns[]     = 'batch_id';
            $columns[]     = 'batch_seq';
            $placeholder[] = '%s';
            $placeholder[] = '%d';
        }

        $columnSql    = '`' . implode('`, `', $columns) . '`';
        $placeholders = '(' . implode(', ', $placeholder) . ')';
        $values       = [];

        foreach ($prepared as $event) {
            foreach (self::COLUMNS as $column) {
                $values[] = $event->row[$column];
            }
            if ($batchId !== null) {
                $values[] = $batchId;
                $values[] = $event->seq;
            }
        }

        $verb = $batchId !== null ? 'INSERT IGNORE INTO ' : 'INSERT INTO ';

        $inserted = $wpdb->query($wpdb->prepare(
            $verb . self::tableName() . " ({$columnSql}) VALUES "
                . implode(', ', array_fill(0, count($prepared), $placeholders)),
            $values
        ));

        return $inserted === false ? false : (int) $inserted;
    }

    /**
     * Fills in the stored row id for the envelopes that completed a goal.
     *
     * The id is what gives a goal completion a position in the funnel ordering
     * (see the ORDERING note in the class docblock), so it is looked up
     * explicitly rather than inferred.
     *
     * insert_id arithmetic is deliberately NOT used for browser batches. A
     * multi-row INSERT IGNORE does not report which of its rows were actually
     * stored, and whether skipped rows consume auto-increment values depends on
     * the server's innodb_autoinc_lock_mode — so "first id + offset" is a guess
     * that happens to be right on one configuration. One bounded SELECT keyed by
     * the unique (batch_id, batch_seq) index is exact, and it is also correct on
     * a REPLAY, where the rows already existed and nothing was inserted at all.
     *
     * Server-side events are the one case where insert_id is exact: they carry no
     * batch id, arrive one at a time (cvm_track_event(), the provider hooks), and
     * use a plain INSERT.
     *
     * @param array<int, PreparedEvent> $prepared    Envelopes, keyed by batch position.
     * @param list<int>                 $matchedSeqs Positions that completed a goal.
     * @param string|null               $batchId     Client batch id, or null.
     * @param int                       $inserted    Rows the write actually stored.
     * @return void
     */
    private static function resolveSourceEventIds(
        array $prepared,
        array $matchedSeqs,
        ?string $batchId,
        int $inserted
    ): void {
        global $wpdb;

        if ($matchedSeqs === []) {
            return;
        }

        if ($batchId === null) {
            if (count($prepared) === 1 && $inserted === 1) {
                $only = reset($prepared);
                $only->sourceEventId = (int) $wpdb->insert_id;
            }

            return;
        }

        $placeholders = implode(', ', array_fill(0, count($matchedSeqs), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, batch_seq FROM ' . self::tableName()
                . " WHERE batch_id = %s AND batch_seq IN ({$placeholders})",
                array_merge([$batchId], $matchedSeqs)
            ),
            ARRAY_A
        );

        foreach (is_array($rows) ? $rows : [] as $row) {
            $seq = (int) ($row['batch_seq'] ?? -1);
            if (isset($prepared[$seq])) {
                $prepared[$seq]->sourceEventId = (int) $row['id'];
            }
        }
    }

    /**
     * Validates the goal ids a browser reported for CSS-selector rules.
     *
     * Shape only — these are re-checked against the actual enabled selector
     * goals by {@see \Convermetry\Goals\GoalMatcher}, which is where the real
     * authorization lives. This just refuses to carry anything that is not
     * even shaped like a goal id, and bounds the list so a crafted request
     * cannot make the matcher do unbounded work.
     *
     * @param mixed $raw Raw value from the event payload.
     * @return list<string>
     */
    private static function selectorGoalIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $candidate) {
            if (!is_string($candidate) || !GoalSettings::isValidId($candidate)) {
                continue;
            }

            $out[] = $candidate;

            if (count($out) >= GoalRepository::MAX_BROWSER_SELECTORS) {
                break;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Validates, sanitizes, and truncates one event into a storable row.
     *
     * All string fields are cut to their column widths here so every write
     * path (REST endpoint, cvm_track_event(), the form providers'
     * server-confirmed conversions) shares one source of truth for column
     * limits.
     *
     * The row passes through the 'convermetry_tracked_event' filter before
     * insertion; returning a falsy value from that filter drops the event.
     *
     * @param string               $type Event type key (e.g. "pageview", "click").
     * @param array<string, mixed> $data Event context; see the column list in createTable().
     * @param string               $ip   The visitor's IP for this request, or '' when IP
     *                                   storage is off or none could be resolved.
     * @return array<string, string>|null The row, or null when invalid or dropped.
     */
    private static function sanitizeRow(string $type, array $data, string $ip = ''): ?array
    {
        $type = sanitize_key($type);
        if ($type === '' || strlen($type) > 20) {
            return null;
        }

        // A confirmed conversion without a usable conversion id breaks the
        // dedup invariant every report relies on: COUNT(DISTINCT event_value)
        // ignores empty ids while conversion listings collapse them into one
        // record, so the numbers silently disagree. Enforced HERE — the one
        // choke point every write path shares.
        if ($type === 'form_success') {
            $conversionId = $data['event_value'] ?? '';
            if (!is_scalar($conversionId) || !preg_match('~^[A-Za-z0-9_.:\-]{8,100}$~', (string) $conversionId)) {
                return null;
            }
        }

        $row = [
            'event_type'    => $type,
            'page_url'      => self::truncate(esc_url_raw((string) ($data['page_url'] ?? '')), 255),
            'page_title'    => self::truncate(sanitize_text_field((string) ($data['page_title'] ?? '')), 255),
            'element_tag'   => self::truncate(sanitize_key((string) ($data['element_tag'] ?? '')), 32),
            'element_label' => self::truncate(sanitize_text_field((string) ($data['element_label'] ?? '')), 191),
            'target_url'    => self::truncate(sanitize_text_field((string) ($data['target_url'] ?? '')), 255),
            'event_value'   => self::truncate(sanitize_text_field((string) ($data['event_value'] ?? '')), 100),
            'referrer'      => self::truncate(esc_url_raw((string) ($data['referrer'] ?? '')), 255),
            'session_id'    => self::truncate((string) preg_replace('/[^a-f0-9]/i', '', (string) ($data['session_id'] ?? '')), 64),
            'device'        => self::truncate(sanitize_key((string) ($data['device'] ?? '')), 20),
            'utm_source'    => self::truncate(sanitize_text_field((string) ($data['utm_source'] ?? '')), 100),
            'utm_medium'    => self::truncate(sanitize_text_field((string) ($data['utm_medium'] ?? '')), 100),
            'utm_campaign'  => self::truncate(sanitize_text_field((string) ($data['utm_campaign'] ?? '')), 191),
            'utm_id'        => self::truncate(sanitize_text_field((string) ($data['utm_id'] ?? '')), 100),
            'utm_term'      => self::truncate(sanitize_text_field((string) ($data['utm_term'] ?? '')), 191),
            'utm_content'   => self::truncate(sanitize_text_field((string) ($data['utm_content'] ?? '')), 191),
            'click_id_type' => sanitize_key((string) ($data['click_id_type'] ?? '')),
            'channel'       => self::truncate(sanitize_text_field((string) ($data['channel'] ?? '')), 24),
            // Only the form lifecycle carries a form identity. Accepting one on
            // any other type would let a pageview or a click pollute the
            // form-engagement reports, which read this column alone.
            'form_key'      => in_array($type, self::FORM_TYPES, true)
                ? self::truncate(sanitize_text_field((string) ($data['form_key'] ?? '')), 191)
                : '',
            // Resolved by the caller once per request. Set here, before the
            // 'convermetry_tracked_event' filter runs, so a site can anonymize
            // or clear it exactly like any other column.
            'ip_address'    => self::truncate($ip, 45),
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ];

        // Normalize source/medium so one campaign never fragments across
        // "Facebook"/"fb"/"facebook.com" rows, keep only whitelisted ad-click
        // identifier types, and derive the marketing channel for attributed
        // event types when the caller did not supply one.
        $row['utm_source'] = Channels::normalizeSource($row['utm_source']);
        $row['utm_medium'] = strtolower($row['utm_medium']);

        if (!in_array($row['click_id_type'], Channels::CLICK_ID_TYPES, true)) {
            $row['click_id_type'] = '';
        }

        if ($row['channel'] === '' && in_array($type, self::ATTRIBUTED_TYPES, true)) {
            // session_referrer — the referrer the session entered through,
            // persisted by the tracker — feeds classification only; it is
            // not a stored column, so it never reaches the INSERT. Likewise
            // session_direct, the companion marker for a session that
            // entered with no referrer at all.
            $context = $row;
            $context['session_referrer'] = self::truncate(esc_url_raw((string) ($data['session_referrer'] ?? '')), 255);
            $context['session_direct']   = !empty($data['session_direct']);

            $row['channel'] = self::truncate(Channels::classify($context, $type), 24);
        }

        /**
         * Filters an event row just before it is written to the database.
         *
         * @param array<string, string>|false $row  The sanitized row; return false to drop the event.
         * @param string                      $type The event type key.
         */
        $row = apply_filters('convermetry_tracked_event', $row, $type);
        if (!is_array($row)) {
            return null;
        }

        // Bulk inserts serialize rows by the fixed column list, so a filter
        // that removed or renamed keys must not shift another row's values.
        $normalized = [];
        foreach (self::COLUMNS as $column) {
            $normalized[$column] = (string) ($row[$column] ?? '');
        }

        return $normalized;
    }

    /**
     * Deletes rows older than the configured retention window, and purges
     * expired rate-limit-counter option rows.
     *
     * Runs daily via the cvm_cleanup_old_events cron event. The events-table
     * deletion is bounded per invocation (chunk count AND wall-clock time)
     * and runs under a lease-based mutex shared with the catch-up hook.
     * When this run's own bound is hit before the backlog is cleared — or
     * the lock could not be acquired, or was lost mid-loop, or a DELETE
     * itself failed — this schedules the FIRST catch-up continuation;
     * {@see cleanupOldEventsCatchUp()} then reschedules itself for as long
     * as the backlog remains unresolved.
     *
     * @return void
     */
    public static function cleanupOldEvents(): void
    {
        $lock = self::acquireCleanupLock();

        if ($lock === null) {
            self::scheduleCleanupCatchUp(self::CLEANUP_RETRY_COOLDOWN);
        } else {
            try {
                $outcome = self::cleanupEventRows(self::CLEANUP_MAX_CHUNKS, $lock);
            } finally {
                self::releaseCleanupLock($lock);
            }

            if ($outcome !== 'completed') {
                self::scheduleCleanupCatchUp(self::CLEANUP_CATCHUP_CADENCE);
            }
        }

        self::purgeRateLimitCounters();
    }

    /**
     * Cron callback for one catch-up continuation of a truncated, failed, or
     * lock-losing cleanup run — see {@see CLEANUP_CATCHUP_HOOK}.
     *
     * Uses the smaller {@see CLEANUP_CATCHUP_MAX_CHUNKS} budget. Does NOT
     * purge rate-limit counters — that independent purge belongs to the
     * daily run only.
     *
     * @return void
     */
    public static function cleanupOldEventsCatchUp(): void
    {
        $lock = self::acquireCleanupLock();

        if ($lock === null) {
            self::scheduleCleanupCatchUp(self::CLEANUP_RETRY_COOLDOWN);
            return;
        }

        try {
            $outcome = self::cleanupEventRows(self::CLEANUP_CATCHUP_MAX_CHUNKS, $lock);
        } finally {
            self::releaseCleanupLock($lock);
        }

        if ($outcome !== 'completed') {
            self::scheduleCleanupCatchUp(self::CLEANUP_CATCHUP_CADENCE);
        }
    }

    /**
     * Schedules {@see CLEANUP_CATCHUP_HOOK} after $delay seconds, unless it
     * is already scheduled.
     *
     * @param int $delay Seconds from now.
     * @return void
     */
    private static function scheduleCleanupCatchUp(int $delay): void
    {
        if (!wp_next_scheduled(self::CLEANUP_CATCHUP_HOOK)) {
            wp_schedule_single_event(time() + $delay, self::CLEANUP_CATCHUP_HOOK);
        }
    }

    /**
     * Deletes event rows older than the retention cutoff in bounded chunks,
     * stopping at whichever comes first: $maxChunks statements, the
     * wall-clock time budget, or losing ownership of $lock.
     *
     * @param int    $maxChunks Maximum DELETE statements to run this invocation.
     * @param string $lock      The lock token this invocation's caller acquired.
     * @return string One of 'completed', 'truncated', 'query_failed', 'lock_lost'.
     */
    private static function cleanupEventRows(int $maxChunks, string $lock): string
    {
        global $wpdb;

        $cutoff   = gmdate('Y-m-d H:i:s', time() - Options::retentionDays() * DAY_IN_SECONDS);
        $table    = self::tableName();
        $deadline = microtime(true) + self::CLEANUP_TIME_BUDGET;

        for ($chunk = 0; $chunk < $maxChunks; $chunk++) {
            // Chunk 0 skips the renewal check: the lock was just acquired by
            // this same request. Every later chunk renews first — a prior
            // chunk's DELETE can take long enough for the lease to go stale.
            if ($chunk > 0 && !self::renewCleanupLock($lock, $chunk)) {
                return 'lock_lost';
            }

            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s LIMIT %d",
                $cutoff,
                self::CLEANUP_CHUNK
            ));

            if (!is_int($deleted)) {
                return 'query_failed';
            }

            if ($deleted < self::CLEANUP_CHUNK) {
                return 'completed';
            }

            if (microtime(true) >= $deadline) {
                return 'truncated';
            }
        }

        return 'truncated';
    }

    /**
     * Deletes expired rate-limit-counter option rows in bounded chunks.
     *
     * Rate-limit counter rows are written directly to the options table by
     * the tracking REST controller when no persistent object cache is
     * available. Each row self-resets when its minute-window rolls over, but
     * rows for IPs never seen again would otherwise linger forever.
     *
     * @return void
     */
    private static function purgeRateLimitCounters(): void
    {
        global $wpdb;

        $deadline = microtime(true) + self::CLEANUP_TIME_BUDGET;

        for ($chunk = 0; $chunk < self::CLEANUP_RATE_LIMIT_MAX_CHUNKS; $chunk++) {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cvm\\_rl\\_%%' LIMIT %d",
                self::CLEANUP_RATE_LIMIT_CHUNK
            ));

            if (!is_int($deleted) || $deleted < self::CLEANUP_RATE_LIMIT_CHUNK) {
                return;
            }

            if (microtime(true) >= $deadline) {
                return;
            }
        }
    }

    /**
     * Acquires the cleanup mutex without blocking — an option-row lease
     * only, deliberately never MySQL's GET_LOCK().
     *
     * Named locks are tied to the specific database CONNECTION that acquired
     * them, and $wpdb transparently reconnects and retries a query after a
     * "server has gone away" error entirely within one $wpdb->query() call —
     * if that happens to a DELETE inside the cleanup loop, it runs on a new
     * connection that does not hold the old connection's named lock, and no
     * between-chunk ownership check can ever catch it. For a loop whose
     * worst failure mode if unlocked is genuine concurrent deletion, that
     * blind spot is unacceptable — the option-row lease's worst case (a
     * bounded wait for a stale lease to expire) is strictly safer.
     *
     * The lease value is "token|timestamp|counter" (see
     * {@see renewCleanupLock()}) and is created via INSERT IGNORE — the
     * options table's unique key on option_name means exactly one concurrent
     * caller's insert succeeds, no read-then-write gap. A lease older than
     * CLEANUP_LOCK_TIMEOUT may be stolen, but only via compare-and-delete on
     * the exact stale value, so two would-be stealers can never both believe
     * they freed it.
     *
     * @return string|null The acquired token, or null when another process
     *                     holds the lock.
     */
    private static function acquireCleanupLock(): ?string
    {
        global $wpdb;

        $token = md5(wp_generate_uuid4() . wp_rand());
        $value = $token . '|' . time() . '|0';

        if (self::insertCleanupLockRow($value)) {
            return $token;
        }

        $held = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::CLEANUP_LOCK_OPTION
        ));

        if ($held === '') {
            return null;
        }

        $parts  = explode('|', $held, 3);
        $heldTs = (int) ($parts[1] ?? 0);

        if (time() - $heldTs < self::CLEANUP_LOCK_TIMEOUT) {
            return null;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::CLEANUP_LOCK_OPTION,
            $held
        ));
        wp_cache_delete(self::CLEANUP_LOCK_OPTION, 'options');

        return self::insertCleanupLockRow($value) ? $token : null;
    }

    /**
     * Atomically creates the cleanup lock row.
     *
     * @param string $value Lock value ("token|timestamp|counter").
     * @return bool True when this call created the row.
     */
    private static function insertCleanupLockRow(string $value): bool
    {
        global $wpdb;

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            self::CLEANUP_LOCK_OPTION,
            $value
        ));
        wp_cache_delete(self::CLEANUP_LOCK_OPTION, 'options');

        return $inserted === 1;
    }

    /**
     * Extends the cleanup lock's lease while its holder is still working,
     * reporting whether this holder still verifiably owns it.
     *
     * The stored value is "token|timestamp|counter": the counter is the
     * caller's chunk index, guaranteed unique across repeated renewals
     * within one loop's lifetime — unlike microtime(true), which is merely
     * very unlikely to collide. This matters for renewals landing within the
     * same wall-clock second.
     *
     * @param string $lock     The token acquireCleanupLock() returned.
     * @param int    $renewals Renewal counter for this call (the caller's chunk index).
     * @return bool True when the UPDATE matched this holder's own row (lock still owned).
     */
    private static function renewCleanupLock(string $lock, int $renewals): bool
    {
        global $wpdb;

        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
            $lock . '|' . time() . '|' . $renewals,
            self::CLEANUP_LOCK_OPTION,
            $wpdb->esc_like($lock) . '|%'
        ));
        wp_cache_delete(self::CLEANUP_LOCK_OPTION, 'options');

        return $updated === 1;
    }

    /**
     * Releases the cleanup lock acquired by {@see acquireCleanupLock()}.
     *
     * Compare-and-delete on the ownership token: if this run's lease lapsed
     * and another process stole the lock, the DELETE matches nothing and the
     * new holder keeps its mutex.
     *
     * @param string $lock The token acquireCleanupLock() returned.
     * @return void
     */
    private static function releaseCleanupLock(string $lock): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
            self::CLEANUP_LOCK_OPTION,
            $wpdb->esc_like($lock) . '|%'
        ));
        wp_cache_delete(self::CLEANUP_LOCK_OPTION, 'options');
    }

    /**
     * Truncates a string to a maximum length, multibyte-safe when possible.
     *
     * @param string $value  Input string.
     * @param int    $length Maximum length in characters.
     * @return string
     */
    private static function truncate(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length)
            : substr($value, 0, $length);
    }
}
