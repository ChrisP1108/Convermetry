<?php
declare(strict_types=1);

namespace Convermetry\Notifications;

if (!defined('ABSPATH')) exit;

use Convermetry\Analytics\SubmissionContext;
use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;

/**
 * The database-backed queue for internal email notifications.
 *
 * Modeled closely on {@see \Convermetry\Webhook\FormDeliveryQueue}: same claim
 * protocol, same bounded worker, same "no terminal status — finished rows are
 * deleted" design. The duplication is deliberate. The two queues differ in
 * payload, retry policy, pause semantics, and terminal behavior, and an
 * abstract base for exactly two implementations would trade a readable 60-line
 * claim protocol for an inheritance seam nothing else can use. This codebase
 * is uniformly final and all-static; follow the pattern rather than unify it.
 *
 * ONE ROW PER (SUBMISSION, RECIPIENT). That is what makes retries independent
 * — one bouncing address does not re-mail the other four — and it is what the
 * uniqueness index is built on, so a double-fired submission action can never
 * produce two emails to one address.
 *
 * NO LEAD DATA IS STORED HERE. The row holds a recipient, a settings snapshot,
 * and scheduling state; the submission itself is fetched fresh at send time.
 * That is what makes deletion real: the moment the submission row is gone, no
 * copy exists that a retry could render into an email.
 */
final class NotificationQueue
{
    /** Unprefixed table name. */
    private const string TABLE = 'cvm_notification_queue';

    /** Option storing the applied schema version. */
    private const string DB_VERSION_OPTION = 'cvm_notification_db_version';

    /** Current schema version. */
    private const string DB_VERSION = '1.0.0';

    /** Cron hook running the worker. */
    public const string WORKER_HOOK = 'cvm_process_notifications';

    /** Rows claimed per pass. */
    private const int BATCH_SIZE = 10;

    /** Wall-clock seconds budgeted for one pass. */
    private const int TIME_BUDGET = 45;

    /** How long a claim may be held before another worker reclaims it. */
    private const int CLAIM_TIMEOUT = 10 * MINUTE_IN_SECONDS;

    /**
     * Retry backoff, deliberately much shorter than the webhook chain
     * (~75 minutes against ~24 hours).
     *
     * Two reasons. A webhook is machine-to-machine and a late delivery is
     * still useful; a lead notification that lands 16 hours later is worse
     * than useless, because whoever needed it has long since found the lead
     * another way. And wp_mail() failures are overwhelmingly persistent
     * configuration faults — no MTA, wrong SMTP credentials — not transient
     * ones, so a long chain multiplies noise rather than eventually
     * succeeding. Email also has no receiver-side idempotency, so every extra
     * attempt is another chance at a duplicate.
     *
     * @var int[]
     */
    private const array RETRY_DELAYS = [300, 900, 3600];

    /**
     * Hard time-to-live from created_at, independent of attempt count.
     *
     * This is a SEPARATE guarantee from the retry chain and the more important
     * one. A short backoff bounds a row that is actively failing; it does
     * nothing for a row that was never attempted at all because WP-Cron was
     * disabled, the site got no traffic, or the plugin sat deactivated for a
     * week. Without a TTL that row eventually wakes and mails a days-old lead
     * as though it just arrived.
     */
    private const int MAX_AGE = 2 * HOUR_IN_SECONDS;

    /** Rows deleted per statement during the orphan sweep. */
    private const int PURGE_CHUNK = 2000;

    /**
     * The hard time-to-live, in seconds.
     *
     * Exposed so the relationship with the retry chain — the TTL must leave
     * room for every retry to run — can be asserted rather than assumed.
     *
     * @return int
     */
    public static function maxAge(): int
    {
        return self::MAX_AGE;
    }

    /** Transient recording the most recent permanent failure, for the admin. */
    public const string FAILURE_TRANSIENT = 'cvm_notification_last_failure';

    /**
     * Every column createTable() must verify before stamping the version.
     *
     * Public so the shape test can compare it against the DDL: adding a column
     * to one and not the other is the classic failure of this pattern.
     *
     * @return string[]
     */
    public static function expectedColumns(): array
    {
        return [
            'id', 'submission_id', 'recipient', 'recipient_key', 'settings_json',
            'status', 'attempt', 'next_attempt_at', 'claim', 'claimed_at',
            'last_error', 'created_at',
        ];
    }

    /**
     * Every index createTable() must verify before stamping the version.
     *
     * @return string[]
     */
    public static function expectedIndexes(): array
    {
        return ['submission_recipient', 'status_due'];
    }

    /**
     * The fully-prefixed table name.
     *
     * @return string
     */
    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Creates or updates the queue table (idempotent via dbDelta).
     *
     * @return void
     */
    public static function createTable(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table   = self::tableName();
        $charset = $wpdb->get_charset_collate();

        // recipient_key is a hash rather than the address itself because
        // UNIQUE(VARCHAR(40), VARCHAR(191)) under utf8mb4 is 924 bytes, over
        // the 767-byte index limit on older InnoDB row formats. dbDelta would
        // silently skip the index there and INSERT IGNORE would stop
        // deduplicating — the same reason the webhook queue hashes its
        // endpoint URL into endpoint_key.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id VARCHAR(40) NOT NULL,
            recipient VARCHAR(191) NOT NULL,
            recipient_key CHAR(32) NOT NULL,
            settings_json LONGTEXT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            attempt TINYINT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            claim CHAR(32) NOT NULL DEFAULT '',
            claimed_at DATETIME NULL,
            last_error VARCHAR(191) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY submission_recipient (submission_id,recipient_key),
            KEY status_due (status,next_attempt_at)
        ) {$charset};";

        dbDelta($sql);

        // Verify the INDEXES as well as the columns before recording the
        // version. Deduplication IS the submission_recipient index: a partial
        // dbDelta that created the columns but skipped it would be stamped
        // complete, never retried, and would start sending duplicate emails
        // silently. FormSubmissions::createTable() sets this precedent.
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
     * Registers the worker.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action(self::WORKER_HOOK, [self::class, 'processDue']);
    }

    /**
     * The uniqueness half of the idempotency key.
     *
     * @param string $recipient Recipient address.
     * @return string
     */
    public static function recipientKey(string $recipient): string
    {
        return md5(strtolower(trim($recipient)));
    }

    /**
     * The effective retry backoff.
     *
     * @return int[]
     */
    public static function retryDelays(): array
    {
        /**
         * Filters the notification retry backoff, in seconds per attempt.
         *
         * Kept separate from 'convermetry_retry_schedule' (webhooks): the two
         * have genuinely different requirements, and a chain long enough for a
         * webhook produces stale, duplicated email.
         *
         * @param int[] $delays Seconds to wait before each retry.
         */
        $filtered = apply_filters('convermetry_notification_retry_schedule', self::RETRY_DELAYS);

        $out = [];
        foreach ((array) $filtered as $delay) {
            if (is_numeric($delay)) {
                $out[] = max(60, (int) $delay);
            }
        }

        return $out !== [] ? $out : self::RETRY_DELAYS;
    }

    /**
     * Queues one notification per recipient for a submission.
     *
     * INSERT IGNORE against UNIQUE(submission_id, recipient_key) is the
     * idempotency guarantee: a re-fired submission action, or a provider that
     * double-reports, can never produce two emails to one address for one
     * submission.
     *
     * @param string               $submissionId The submission's globally unique id.
     * @param list<string>         $recipients   Validated recipient addresses.
     * @param array<string, mixed> $snapshot     Frozen settings for these messages.
     * @return int Rows actually queued.
     */
    public static function enqueue(string $submissionId, array $recipients, array $snapshot): int
    {
        global $wpdb;

        if ($submissionId === '' || $recipients === []) {
            return 0;
        }

        $now      = gmdate('Y-m-d H:i:s');
        $settings = (string) wp_json_encode($snapshot);
        $queued   = 0;

        foreach ($recipients as $recipient) {
            $inserted = $wpdb->query($wpdb->prepare(
                'INSERT IGNORE INTO ' . self::tableName()
                . ' (submission_id, recipient, recipient_key, settings_json, status, attempt,'
                . " next_attempt_at, created_at)"
                . " VALUES (%s, %s, %s, %s, 'pending', 0, %s, %s)",
                $submissionId,
                $recipient,
                self::recipientKey($recipient),
                $settings,
                $now,
                $now
            ));

            if ($inserted === 1) {
                $queued++;
            }
        }

        if ($queued > 0) {
            self::scheduleWorker(time() + 1);

            if (function_exists('spawn_cron')) {
                spawn_cron();
            }
        }

        return $queued;
    }

    /**
     * Sends every notification that is due, within a bounded budget.
     *
     * @return void
     */
    public static function processDue(): void
    {
        global $wpdb;

        $table = self::tableName();
        $now   = gmdate('Y-m-d H:i:s');

        // Reclaim rows stranded in 'sending' by a worker that died mid-pass.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'pending', claim = '' WHERE status = 'sending' AND claimed_at < %s",
            gmdate('Y-m-d H:i:s', time() - self::CLAIM_TIMEOUT)
        ));

        // NOTE: unlike FormDeliveryQueue, there is no pause-and-rearm on the
        // master toggle. Switching notifications off stops NEW ones being
        // queued (the dispatcher's first guard); rows already queued send
        // under the settings that were frozen when the lead arrived, bounded
        // by MAX_AGE. The admin page offers an explicit "cancel queued
        // notifications" action for anyone who wants them dropped instead.

        $token = md5(wp_generate_uuid4() . wp_rand());

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'sending', claim = %s, claimed_at = %s
             WHERE status = 'pending' AND next_attempt_at <= %s
             ORDER BY next_attempt_at ASC
             LIMIT %d",
            $token,
            $now,
            $now,
            self::BATCH_SIZE
        ));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE claim = %s AND status = 'sending' ORDER BY next_attempt_at ASC",
            $token
        ), ARRAY_A);

        $rows     = is_array($rows) ? $rows : [];
        $deadline = microtime(true) + self::TIME_BUDGET;

        // One submission fanning out to several recipients is fetched and
        // enriched once per pass, not once per recipient.
        $submissionCache = [];

        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status = 'pending', claim = '' WHERE id = %d AND claim = %s",
                    (int) $row['id'],
                    $token
                ));
                continue;
            }

            self::processRow($row, $submissionCache);
        }

        self::ensureWorkerScheduled();
    }

    /**
     * Renders and sends one claimed row.
     *
     * @param array<string, mixed>                    $row             Queue row.
     * @param array<string, array<string, mixed>|null> $submissionCache Per-pass submission memo.
     * @return void
     */
    private static function processRow(array $row, array &$submissionCache): void
    {
        global $wpdb;

        $rowId        = (int) $row['id'];
        $submissionId = (string) $row['submission_id'];
        $recipient    = (string) $row['recipient'];

        // Age check FIRST, before the lookup and before any send: a row that
        // outlived its TTL must not mail a stale lead no matter why it was
        // delayed.
        $createdAt = (int) strtotime(((string) $row['created_at']) . ' UTC');
        if ($createdAt > 0 && $createdAt < time() - self::MAX_AGE) {
            $wpdb->delete(self::tableName(), ['id' => $rowId], ['%d']);
            return;
        }

        if (!array_key_exists($submissionId, $submissionCache)) {
            // Resolved by submission_id, never by a numeric row id: TRUNCATE
            // resets AUTO_INCREMENT, so a stale numeric id could match a
            // completely different lead.
            $submission = FormSubmissions::getBySubmissionId($submissionId);
            $submissionCache[$submissionId] = $submission !== null
                ? SubmissionContext::enrich($submission)
                : null;
        }

        $submission = $submissionCache[$submissionId];

        // The submission was deleted, or retention expired it, between queuing
        // and sending. Cancel rather than send: the email body IS the erased
        // lead, so this is the point where deletion has to win.
        if ($submission === null) {
            $wpdb->delete(self::tableName(), ['id' => $rowId], ['%d']);
            return;
        }

        $snapshot = NotificationSettings::normalizeSnapshot(
            SubmissionContext::decodeJson((string) ($row['settings_json'] ?? ''))
        );

        $siteInfo = EmailBuilder::siteInfo();
        if (($snapshot['site_name'] ?? '') !== '') {
            $siteInfo['site_name'] = (string) $snapshot['site_name'];
        }

        $context = SubmissionContext::of($submission);

        $result = NotificationMailer::send(
            $recipient,
            EmailBuilder::subject((string) $snapshot['subject'], $submission, $context, $siteInfo),
            EmailBuilder::body($submission, $context, $snapshot, $siteInfo),
            $submissionId
        );

        if ($result['ok']) {
            // Accepted by the local transport — not confirmed delivered.
            $wpdb->delete(self::tableName(), ['id' => $rowId], ['%d']);
            return;
        }

        self::rescheduleOrAbandon($rowId, (int) $row['attempt'] + 1, $recipient, $result['message']);
    }

    /**
     * Schedules the next attempt, or gives up.
     *
     * @param int    $rowId     Queue row id.
     * @param int    $attempt   1-based attempt just completed.
     * @param string $recipient Recipient address, for the failure notice.
     * @param string $error     Failure reason.
     * @return void
     */
    private static function rescheduleOrAbandon(int $rowId, int $attempt, string $recipient, string $error): void
    {
        global $wpdb;

        $delays = self::retryDelays();
        $table  = self::tableName();

        if ($attempt > count($delays)) {
            $wpdb->delete($table, ['id' => $rowId], ['%d']);

            // Abandoning silently is the failure mode that matters here. The
            // webhook queue can afford it because every attempt is in the
            // Activity Log; notifications have no such record, so a site with
            // a broken MTA would send nothing, forever, with no signal. This
            // transient is what the Notifications page surfaces as a warning.
            set_transient(self::FAILURE_TRANSIENT, [
                'at'        => time(),
                'recipient' => $recipient,
                'error'     => $error,
            ], WEEK_IN_SECONDS);

            return;
        }

        $wpdb->update(
            $table,
            [
                'status'          => 'pending',
                'claim'           => '',
                'attempt'         => $attempt,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $delays[$attempt - 1]),
                'last_error'      => mb_substr($error, 0, 191),
            ],
            ['id' => $rowId],
            ['%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Cancels every queued notification for one submission.
     *
     * Called when a submission is deleted, so no queue row survives that could
     * later be rendered into an email about an erased lead.
     *
     * @param string $submissionId The submission's globally unique id.
     * @return void
     */
    public static function cancelForSubmission(string $submissionId): void
    {
        global $wpdb;

        if ($submissionId === '') {
            return;
        }

        $wpdb->delete(self::tableName(), ['submission_id' => $submissionId], ['%s']);
    }

    /**
     * Drains the whole queue (Clear All, or an explicit admin action).
     *
     * @return void
     */
    public static function cancelAll(): void
    {
        global $wpdb;

        $wpdb->query('DELETE FROM ' . self::tableName());
    }

    /**
     * Daily sweep for rows past their TTL.
     *
     * A flat age cutoff rather than a join against submissions: the maximum
     * live chain is ~75 minutes, so anything older is either orphaned or
     * stranded, and both should go. No join, no scan of the submissions table.
     *
     * FormSubmissions::purgeOld() deliberately does NOT cascade into this
     * table: retention is at least 7 days and the chain is minutes, so an
     * expired submission cannot have a live row — and if one somehow does,
     * processRow() deletes it without sending.
     *
     * @return void
     */
    public static function purgeOrphans(): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::tableName() . ' WHERE created_at < %s LIMIT %d',
            gmdate('Y-m-d H:i:s', time() - self::MAX_AGE),
            self::PURGE_CHUNK
        ));
    }

    /**
     * How many notifications are waiting.
     *
     * @return int
     */
    public static function pendingCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . self::tableName() . " WHERE status IN ('pending','sending')"
        );
    }

    /**
     * Schedules the worker for whatever is still pending.
     *
     * @return void
     */
    public static function ensureWorkerScheduled(): void
    {
        global $wpdb;

        if (wp_next_scheduled(self::WORKER_HOOK) !== false) {
            return;
        }

        $next = (string) $wpdb->get_var(
            'SELECT MIN(next_attempt_at) FROM ' . self::tableName()
            . " WHERE status IN ('pending','sending')"
        );

        if ($next === '') {
            return;
        }

        self::scheduleWorker(max(time() + 5, (int) strtotime($next . ' UTC')));
    }

    /**
     * Schedules the worker unless one is already due sooner.
     *
     * @param int $timestamp Unix timestamp to run at.
     * @return void
     */
    private static function scheduleWorker(int $timestamp): void
    {
        $existing = wp_next_scheduled(self::WORKER_HOOK);

        if ($existing !== false && $existing <= $timestamp) {
            return;
        }

        if ($existing !== false) {
            wp_unschedule_event($existing, self::WORKER_HOOK);
        }

        wp_schedule_single_event($timestamp, self::WORKER_HOOK);
    }
}
