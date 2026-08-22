<?php
declare(strict_types=1);

namespace Convermetry\Database;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;

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
 * context holds the frozen analytics context captured at submission time.
 * Rows age out with the same retention window as analytics events.
 */
final class FormSubmissions
{
    /** Table name without the wpdb prefix. */
    private const TABLE = 'cvm_form_submissions';

    /** Option key storing the installed schema version. */
    private const DB_VERSION_OPTION = 'cvm_submissions_db_version';

    /** Current schema version; bump when the CREATE TABLE below changes. */
    private const DB_VERSION = '1.0.0';

    /** Rows deleted per statement during retention cleanup. */
    private const CLEANUP_CHUNK = 2000;

    /** Maximum delete chunks per cron run. */
    private const CLEANUP_MAX_CHUNKS = 20;

    /** Wall-clock seconds budgeted for one purgeOld() run. */
    private const CLEANUP_TIME_BUDGET = 20;

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
            KEY provider_form (provider,form_key(100))
        ) {$charset};";

        dbDelta($sql);

        $expected = [
            'id', 'submission_id', 'conversion_id', 'session_id', 'provider',
            'form_key', 'form_name', 'native_form_id', 'form_id', 'page_url',
            'page_query', 'submission_data', 'context', 'runtime', 'created_at',
        ];
        if (
            DatabaseManager::tableHasColumns($table, $expected)
            && DatabaseManager::tableHasIndex($table, 'conversion_id')
        ) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
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
            . ' native_form_id, form_id, page_url, page_query, submission_data, context, runtime, created_at)'
            . ' VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
            $submission['submission_id'],
            $submission['conversion_id'],
            $submission['session_id'],
            $submission['provider'],
            $submission['form_key'],
            $submission['form_name'],
            $submission['native_form_id'],
            $submission['form_id'],
            $submission['page_url'],
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
