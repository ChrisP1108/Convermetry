<?php
declare(strict_types=1);

namespace Convermetry\Leads;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Settings\Options;

/**
 * Owns the lead status-change history table.
 *
 * The submission row carries a lead's CURRENT status and value. This table
 * carries how it got there: who changed it, from what, to what, and when.
 *
 * That exists for two reasons, and only the first is about auditing:
 *
 *  1. "Qualified" is a human judgement recorded after the fact, often by
 *     someone other than the person reading the report. Without a history,
 *     nobody can answer "who marked this won, and when?" — and a mis-click that
 *     turns a £40,000 lead into 'spam' is unrecoverable and invisible.
 *  2. It is the OUTBOX a future lead_status_changed webhook will read.
 *     Convermetry 0.5.0 deliberately keeps lead outcomes local: form payloads
 *     freeze on first delivery attempt and scheduled analytics windows advance
 *     without ever revisiting, so a lead marked won on Friday can never reach a
 *     receiver through either existing path — a `lead` block on the submission
 *     payload would read "new"/null forever and actively mislead. Recording the
 *     transitions now means that delivery becomes a reader over this table
 *     rather than a schema migration later.
 *
 * lead_event_id is an immutable public identifier minted per row, so a future
 * sync has something stable to deduplicate on that is not an auto-increment id.
 *
 * ROWS ARE CASCADED, NOT ORPHANED. When a submission is deleted, cleared, or
 * ages past retention, its history goes with it — see the three call sites in
 * {@see \Convermetry\Database\FormSubmissions}. A lead's status history is data
 * about that lead, and
 * an "erase this lead" action that left a trail of status changes behind would
 * be a broken promise.
 */
final class LeadEvents
{
    /** Table name without the wpdb prefix. */
    private const string TABLE = 'cvm_lead_events';

    /** Option key storing the installed schema version. */
    private const string DB_VERSION_OPTION = 'cvm_leads_db_version';

    /** Current schema version; bump when the CREATE TABLE below changes. */
    private const string DB_VERSION = '1.0.0';

    /** Rows deleted per statement during retention cleanup. */
    private const int CLEANUP_CHUNK = 2000;

    /** Maximum delete chunks per cron run. */
    private const int CLEANUP_MAX_CHUNKS = 20;

    /** Wall-clock seconds budgeted for one purgeOld() run. */
    private const int CLEANUP_TIME_BUDGET = 10;

    /**
     * Every column {@see createTable()} must verify before stamping the version.
     *
     * @return string[]
     */
    public static function expectedColumns(): array
    {
        return [
            'id', 'lead_event_id', 'submission_id', 'from_status', 'to_status',
            'value', 'currency', 'user_id', 'created_at',
        ];
    }

    /**
     * Every index {@see createTable()} must verify before stamping the version.
     *
     * @return string[]
     */
    public static function expectedIndexes(): array
    {
        return ['lead_event_id', 'submission'];
    }

    /**
     * Returns the fully-prefixed history table name.
     *
     * @return string
     */
    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Creates or updates the history table (idempotent via dbDelta).
     *
     * @return void
     */
    public static function createTable(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        // value is the value AS AT this transition, so the history reads
        // correctly even after the current value is edited again. DECIMAL for
        // the same reason as everywhere else in this plugin: it is money.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lead_event_id CHAR(32) NOT NULL,
            submission_id VARCHAR(40) NOT NULL,
            from_status VARCHAR(16) NOT NULL DEFAULT '',
            to_status VARCHAR(16) NOT NULL DEFAULT '',
            value DECIMAL(13,2) NULL,
            currency CHAR(3) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY lead_event_id (lead_event_id),
            KEY submission (submission_id,id),
            KEY created_at (created_at)
        ) {$charset};";

        dbDelta($sql);

        foreach (self::expectedIndexes() as $index) {
            if (!DatabaseManager::tableHasIndex($table, $index)) {
                return;
            }
        }

        if (DatabaseManager::tableHasColumns($table, self::expectedColumns())) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }

    /**
     * Creates the table when the stored schema version differs.
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
     * @return bool
     */
    public static function needsUpgrade(): bool
    {
        return get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION;
    }

    /**
     * Records one status/value transition.
     *
     * Called by {@see LeadService} inside the same transaction as the
     * submission update, so a status change can never be half-recorded — either
     * the lead moved and the history says so, or neither happened.
     *
     * @param string      $submissionId The submission's globally unique id.
     * @param string      $fromStatus   Status before the change.
     * @param string      $toStatus     Status after the change.
     * @param string|null $value        Value after the change, as a decimal string, or null.
     * @param string      $currency     Currency code for $value, or ''.
     * @param int         $userId       WordPress user id that made the change (0 when unknown).
     * @return bool True when the row was stored.
     */
    public static function record(
        string $submissionId,
        string $fromStatus,
        string $toStatus,
        ?string $value,
        string $currency,
        int $userId
    ): bool {
        global $wpdb;

        if ($submissionId === '') {
            return false;
        }

        // A null value is written as a literal NULL rather than bound, because
        // %s would store the empty string and "" is not "no value recorded".
        // The bound parameters are assembled in the same branch that chooses the
        // placeholder, so the two can never fall out of step.
        $params = [
            md5(wp_generate_uuid4() . wp_rand()),
            $submissionId,
            $fromStatus,
            $toStatus,
        ];

        if ($value === null) {
            $valuePlaceholder = 'NULL';
        } else {
            $valuePlaceholder = '%s';
            $params[]         = $value;
        }

        $params[] = $currency;
        $params[] = $userId;
        $params[] = gmdate('Y-m-d H:i:s');

        $inserted = $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::tableName()
            . ' (lead_event_id, submission_id, from_status, to_status, value, currency, user_id, created_at)'
            . ' VALUES (%s, %s, %s, %s, ' . $valuePlaceholder . ', %s, %d, %s)',
            $params
        ));

        return $inserted === 1;
    }

    /**
     * Returns one submission's transitions, newest first.
     *
     * @param string $submissionId The submission's globally unique id.
     * @param int    $limit        Maximum rows to return.
     * @return array<int, array<string, mixed>>
     */
    public static function forSubmission(string $submissionId, int $limit = 50): array
    {
        global $wpdb;

        if ($submissionId === '') {
            return [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT lead_event_id, from_status, to_status, value, currency, user_id, created_at'
                . ' FROM ' . self::tableName()
                . ' WHERE submission_id = %s ORDER BY id DESC LIMIT %d',
                $submissionId,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Deletes every history row for one submission.
     *
     * @param string $submissionId The submission's globally unique id.
     * @return void
     */
    public static function deleteForSubmission(string $submissionId): void
    {
        global $wpdb;

        if ($submissionId !== '') {
            $wpdb->delete(self::tableName(), ['submission_id' => $submissionId], ['%s']);
        }
    }

    /**
     * Removes every history row (the companion to clearing all submissions).
     *
     * @return void
     */
    public static function clearAll(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::tableName());
    }

    /**
     * Deletes rows older than the plugin's retention window, in bounded chunks.
     *
     * A submission's own retention purge is a bulk DELETE that cannot cascade
     * row-by-row, so history is aged out on the same window independently. The
     * two use the same cutoff, so history never outlives the lead it describes.
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
