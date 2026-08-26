<?php
declare(strict_types=1);

namespace Convermetry\Goals;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Settings\Options;

/**
 * Owns the goal completions table.
 *
 * One row = one time a visitor did something the site owner declared important
 * and that is NOT a server-confirmed form submission: tapping a phone number,
 * opening a PDF, clicking through to an external booking system, reaching the
 * pricing page, or firing a developer-defined custom event.
 *
 * Form submissions deliberately do NOT live here. A submission is confirmed by
 * the form plugin's own server-side success hook — Convermetry knows it really
 * happened. A goal completion is a browser-observed signal. Collapsing the two
 * would quietly downgrade the plugin's most trustworthy number into its least,
 * so they stay in separate tables and are counted separately everywhere.
 *
 * DEDUPLICATION IS A DATABASE CONSTRAINT, NOT A PHP CHECK. Both configured
 * behaviors reduce to one UNIQUE index on dedupe_key, and INSERT IGNORE does the
 * work:
 *
 *   once per session   dedupe_key = md5('s|goal|definition|session')
 *                      → a phone CTA tapped five times is 1 completion
 *   every occurrence   dedupe_key = md5('u|goal|definition|event_uid')
 *                      → a PDF downloaded three times is 3 completions
 *
 * event_uid is the DURABLE identity of the event that triggered the completion
 * (see {@see \Convermetry\Database\PreparedEvent}), so an at-least-once replay of
 * a browser batch collides with the original rather than double-counting. A
 * goal whose matching rule is later edited gets a new definition_hash, which
 * starts a clean once-per-session series rather than silently blending two
 * different definitions into one historical metric.
 *
 * source_event_id is the id of the cvm_events row that triggered this
 * completion, and it exists for exactly one reason: FUNNEL ORDERING. Funnel
 * steps establish "did B happen after A?" by comparing event ids, and a goal
 * step has to be comparable to a pageview step on the same scale. Writing a
 * marker row into the events table instead would have been wrong — markers are
 * appended after every base row in a batch, so a goal that fired FIRST would
 * sort LAST and the funnel would report the opposite of what happened.
 *
 * The marketing dimensions (channel, source/medium/campaign, landing page,
 * device) are denormalized onto every row deliberately. Every breakdown the
 * Goals report offers then needs no join at all, which is what keeps a
 * completions-by-campaign-over-time query a single indexed scan on a table that
 * grows with traffic.
 */
final class GoalCompletions
{
    /** Table name without the wpdb prefix. */
    private const string TABLE = 'cvm_goal_completions';

    /** Option key storing the installed schema version. */
    private const string DB_VERSION_OPTION = 'cvm_goals_db_version';

    /** Current schema version; bump when the CREATE TABLE below changes. */
    private const string DB_VERSION = '1.0.0';

    /** Rows deleted per statement during retention cleanup. */
    private const int CLEANUP_CHUNK = 5000;

    /** Maximum delete chunks per cron run. */
    private const int CLEANUP_MAX_CHUNKS = 20;

    /** Wall-clock seconds budgeted for one purgeOld() run. */
    private const int CLEANUP_TIME_BUDGET = 20;

    /**
     * Every column {@see createTable()} must verify before stamping the version.
     *
     * Public so a shape test can compare it against the DDL: adding a column to
     * one and not the other is the classic failure of this pattern.
     *
     * @return string[]
     */
    public static function expectedColumns(): array
    {
        return [
            'id', 'completion_id', 'goal_id', 'definition_hash', 'dedupe_key',
            'event_uid', 'source_event_id', 'session_id', 'page_url',
            'landing_page', 'channel', 'utm_source', 'utm_medium',
            'utm_campaign', 'utm_id', 'device', 'value', 'currency', 'created_at',
        ];
    }

    /**
     * Every index {@see createTable()} must verify before stamping the version.
     *
     * `dedupe` is the load-bearing one: without it INSERT IGNORE silently stops
     * deduplicating and every once-per-session goal starts counting every
     * occurrence, with nothing in the UI to indicate anything is wrong.
     *
     * @return string[]
     */
    public static function expectedIndexes(): array
    {
        return ['dedupe', 'completion_id', 'goal_date', 'session_source'];
    }

    /**
     * Returns the fully-prefixed completions table name.
     *
     * @return string
     */
    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Creates or updates the completions table (idempotent via dbDelta).
     *
     * @return void
     */
    public static function createTable(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        // value is DECIMAL, never FLOAT: this column is money. A goal worth
        // 0.10 recorded ten thousand times must total exactly 1000.00, and
        // binary floating point cannot promise that. NULL means "no value
        // configured", which is a different fact from 0.00.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            completion_id CHAR(32) NOT NULL,
            goal_id VARCHAR(32) NOT NULL,
            definition_hash CHAR(12) NOT NULL DEFAULT '',
            dedupe_key CHAR(32) NOT NULL,
            event_uid CHAR(32) NOT NULL DEFAULT '',
            source_event_id BIGINT UNSIGNED NULL,
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            page_url VARCHAR(255) NOT NULL DEFAULT '',
            landing_page VARCHAR(255) NOT NULL DEFAULT '',
            channel VARCHAR(24) NOT NULL DEFAULT '',
            utm_source VARCHAR(100) NOT NULL DEFAULT '',
            utm_medium VARCHAR(100) NOT NULL DEFAULT '',
            utm_campaign VARCHAR(191) NOT NULL DEFAULT '',
            utm_id VARCHAR(100) NOT NULL DEFAULT '',
            device VARCHAR(20) NOT NULL DEFAULT '',
            value DECIMAL(13,2) NULL,
            currency CHAR(3) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY dedupe (dedupe_key),
            UNIQUE KEY completion_id (completion_id),
            KEY goal_date (goal_id,created_at),
            KEY created_at (created_at),
            KEY session_source (session_id,source_event_id),
            KEY channel_date (channel,created_at),
            KEY campaign_date (utm_campaign(100),created_at)
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
     * Inserts a batch of completions with a single multi-row INSERT IGNORE.
     *
     * IGNORE against the UNIQUE dedupe_key index is the whole deduplication
     * mechanism — see the class docblock. A row already present (the same
     * session completing a once-per-session goal again, or an at-least-once
     * replay of the same browser batch) is silently skipped.
     *
     * @param array<int, array<string, mixed>> $completions Sanitized completion rows.
     * @return int Number of NEW rows stored (skipped duplicates are not counted).
     */
    public static function insertMany(array $completions): int
    {
        global $wpdb;

        if ($completions === []) {
            return 0;
        }

        $columns = [
            'completion_id', 'goal_id', 'definition_hash', 'dedupe_key', 'event_uid',
            'source_event_id', 'session_id', 'page_url', 'landing_page', 'channel',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_id', 'device',
            'value', 'currency', 'created_at',
        ];

        // source_event_id is %d but nullable, and value is a decimal STRING
        // (never a float) — both are bound through a placeholder that preserves
        // NULL rather than coercing it to 0 / 0.00, because "no value" and
        // "zero" are different answers in every report that reads them.
        $placeholders = [];
        $values       = [];

        foreach ($completions as $row) {
            $cells = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;

                if ($value === null && in_array($column, ['source_event_id', 'value'], true)) {
                    $cells[] = 'NULL';
                    continue;
                }

                $cells[]  = $column === 'source_event_id' ? '%d' : '%s';
                $values[] = $column === 'source_event_id' ? (int) $value : (string) $value;
            }
            $placeholders[] = '(' . implode(', ', $cells) . ')';
        }

        $sql = 'INSERT IGNORE INTO ' . self::tableName()
             . ' (`' . implode('`, `', $columns) . '`) VALUES ' . implode(', ', $placeholders);

        $inserted = $values === []
            ? $wpdb->query($sql)
            : $wpdb->query($wpdb->prepare($sql, $values));

        return is_int($inserted) ? $inserted : 0;
    }

    /**
     * Deletes rows older than the plugin's retention window, in bounded chunks.
     *
     * Runs on the same daily cron and honors the same retention setting as
     * analytics events and submissions — goal completions are analytics data and
     * must not outlive the window a site owner configured.
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

    /**
     * Removes every stored completion.
     *
     * @return void
     */
    public static function clearAll(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::tableName());
    }
}
