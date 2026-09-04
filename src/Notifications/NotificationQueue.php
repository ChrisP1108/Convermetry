<?php
declare(strict_types=1);

namespace Convermetry\Notifications;

if (!defined('ABSPATH')) exit;

use Convermetry\Analytics\SubmissionContext;
use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Support\Errors;
use Convermetry\Support\QueueOutcome;

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
        if (self::needsUpgrade()) {
            self::createTable();
        }
    }

    /**
     * Whether the recorded schema version differs from the one this build
     * ships. Read by {@see \Convermetry\Database\MigrationRunner}, which decides
     * which request is allowed to act on the answer.
     *
     * @return bool
     */
    public static function needsUpgrade(): bool
    {
        return get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION;
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
     * Whether a queue row for this (submission, recipient) pair exists.
     *
     * Impure by nature: it reads a table other requests — and the INSERT
     * immediately before the call site — are concurrently changing.
     *
     * @phpstan-impure
     *
     * @param string $submissionId  The submission's globally unique id.
     * @param string $recipientKey  Hashed recipient key.
     * @return bool
     */
    private static function rowExists(string $submissionId, string $recipientKey): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::tableName()
            . ' WHERE submission_id = %s AND recipient_key = %s',
            $submissionId,
            $recipientKey
        )) > 0;
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
     * Returns a VERIFIED outcome. INSERT IGNORE reports "0 rows affected" both
     * for a duplicate the unique index suppressed and for a row it declined to
     * write, and $wpdb->query() returns false on error, so a bare count of
     * genuine inserts could not tell a refused write from an idempotent no-op.
     * Ambiguous results are resolved by reading the row back.
     *
     * @param string               $submissionId The submission's globally unique id.
     * @param list<string>         $recipients   Validated recipient addresses.
     * @param array<string, mixed> $snapshot     Frozen settings for these messages.
     * @return QueueOutcome Verified per-recipient result.
     */
    public static function enqueue(string $submissionId, array $recipients, array $snapshot): QueueOutcome
    {
        global $wpdb;

        if ($submissionId === '' || $recipients === []) {
            return QueueOutcome::nothingToQueue();
        }

        $now        = gmdate('Y-m-d H:i:s');
        $settings   = (string) wp_json_encode($snapshot);
        $queued     = 0;
        $duplicate  = 0;
        $failedRefs = [];

        foreach ($recipients as $recipient) {
            $recipientKey = self::recipientKey($recipient);

            $inserted = $wpdb->query($wpdb->prepare(
                'INSERT IGNORE INTO ' . self::tableName()
                . ' (submission_id, recipient, recipient_key, settings_json, status, attempt,'
                . " next_attempt_at, created_at)"
                . " VALUES (%s, %s, %s, %s, 'pending', 0, %s, %s)",
                $submissionId,
                $recipient,
                $recipientKey,
                $settings,
                $now,
                $now
            ));

            if ($inserted !== 1) {
                if ($inserted !== false && self::rowExists($submissionId, $recipientKey)) {
                    $duplicate++;
                } else {
                    $failedRefs[] = $recipientKey;
                }

                continue;
            }

            $queued++;

            /**
             * Fires when a notification is genuinely queued for one
             * recipient.
             *
             * Fires once per recipient per submission, and ONLY when the
             * INSERT IGNORE actually created a row — a duplicate suppressed
             * by the unique (submission_id, recipient_key) index does not
             * fire it. Nothing has been rendered or sent at this point; the
             * subject and body are built by the worker on each attempt.
             *
             * $recipient is an administrator-configured address from the
             * Notifications settings page, never a visitor's.
             *
             * @param string $submissionId The submission being notified about.
             * @param string $recipient    Recipient address.
             * @param int    $attempt      Always 0 — no attempt has been made yet.
             */
            do_action('convermetry_notification_queued', $submissionId, (string) $recipient, 0);
        }

        $outcome = new QueueOutcome(
            expected: count($recipients),
            inserted: $queued,
            duplicate: $duplicate,
            failed: count($failedRefs),
            failedRefs: $failedRefs,
        );

        if (!$outcome->isComplete()) {
            // A recipient the site configured will not be emailed about this
            // submission unless this is surfaced; nothing else would notice.
            Errors::storage(
                'notification_queue',
                'insert',
                'queue_row_not_persisted',
                $outcome->telemetry() + ['submission_id' => $submissionId]
            );
        }

        if ($queued > 0) {
            self::scheduleWorker(time() + 1);

            if (function_exists('spawn_cron')) {
                spawn_cron();
            }
        }

        return $outcome;
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
            self::announceCancellation($submissionId, $recipient, 'expired', 1);
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
            self::announceCancellation($submissionId, $recipient, 'submission_deleted', 1);
            return;
        }

        $snapshot = NotificationSettings::normalizeSnapshot(
            SubmissionContext::decodeJson((string) ($row['settings_json'] ?? ''))
        );

        // The snapshot's site name wins: it is what the site was called when
        // the lead arrived, and this renders in a worker that may run long
        // afterwards.
        $siteInfo = SiteInfo::current()->withName(
            is_scalar($snapshot['site_name'] ?? null) ? (string) $snapshot['site_name'] : ''
        );

        $context = SubmissionContext::of($submission);
        $attempt = (int) $row['attempt'] + 1;

        $message = new NotificationMessage(
            recipient: $recipient,
            subject: EmailBuilder::subject((string) $snapshot['subject'], $submission, $context, $siteInfo),
            html: EmailBuilder::body($submission, $context, $snapshot, $siteInfo),
            headers: NotificationMailer::headers($submissionId),
        );

        /**
         * Filters one notification message immediately before it is sent.
         *
         * Runs once per attempt, so a retry re-renders and re-filters — unlike a
         * webhook, an email has no frozen body.
         *
         * A callback may change the subject, the HTML body, and additional
         * headers. It may NOT change the recipient: one queue row is one
         * address, chosen and deduplicated at queue time, and a per-attempt
         * rewrite could collapse two rows onto one mailbox or send a retry
         * somewhere its predecessor never went. Whatever is returned for
         * 'recipient' is ignored.
         *
         * The result is re-validated: the subject gets EmailBuilder's
         * header-injection strip and 200-character cap, the body gets the
         * 256 KB size cap, and Content-Type, Auto-Submitted,
         * X-Auto-Response-Suppress and X-Convermetry-Submission are reinstated
         * whatever the callback did with them.
         *
         * $message['html'] contains the visitor's submitted field values. This
         * filter exists to customize that email, so it necessarily sees them —
         * the observational notification actions deliberately do not.
         *
         * @param array{recipient: string, subject: string, html: string, headers: list<string>} $message
         * @param string $submissionId The submission being notified about.
         * @param int    $attempt      1-based attempt number.
         */
        $asArray  = $message->toArray();
        $filtered = apply_filters('convermetry_notification_message', $asArray, $submissionId, $attempt);

        if ($filtered !== $asArray) {
            $message = NotificationMailer::reconcile($message, $filtered, $submissionId);
        }

        /**
         * Fires immediately before one notification email is handed to wp_mail().
         *
         * Fires once per attempt, so once per retry. Carries no subject, no body,
         * and no submitted fields: this is the observational hook, and a lead
         * notification's body is the lead. Use convermetry_notification_message
         * to see or change the content.
         *
         * The Test Email button on the Notifications page is a manual diagnostic
         * that queues nothing, and fires none of the notification lifecycle
         * actions.
         *
         * @param string $submissionId The submission being notified about.
         * @param string $recipient    Administrator-configured recipient address.
         * @param int    $attempt      1-based attempt number.
         */
        do_action('convermetry_notification_before_send', $submissionId, $recipient, $attempt);

        $result = NotificationMailer::send(
            $message->recipient,
            $message->subject,
            $message->html,
            $submissionId,
            $message->headers
        );

        if ($result->ok) {
            // Accepted by the local transport — not confirmed delivered.
            $wpdb->delete(self::tableName(), ['id' => $rowId], ['%d']);

            /**
             * Fires after wp_mail() accepted a notification AND its queue row
             * has been removed.
             *
             * "Accepted", never "delivered": wp_mail() returning true means the
             * local transport took the message, not that a mailbox received it.
             * Bounces, greylisting, and spam filing all happen afterwards and are
             * invisible to WordPress.
             *
             * Fires once per queue row that is successfully sent — so a
             * submission notifying three recipients fires it three times.
             *
             * @param string $submissionId The submission notified about.
             * @param string $recipient    Recipient address the message was accepted for.
             * @param int    $attempt      1-based attempt that succeeded.
             */
            do_action('convermetry_notification_accepted', $submissionId, $recipient, $attempt);
            return;
        }

        self::rescheduleOrAbandon($rowId, $attempt, $recipient, $result->message, $submissionId);
    }

    /**
     * Announces one cancelled notification.
     *
     * @param string $submissionId The submission the notification belonged to.
     * @param string $recipient    Recipient address ('' for bulk cancellations).
     * @param string $reason       'expired', 'submission_deleted', or 'admin_clear'.
     * @param int    $count        Rows cancelled.
     * @return void
     */
    private static function announceCancellation(string $submissionId, string $recipient, string $reason, int $count): void
    {
        /**
         * Fires when queued notifications are cancelled without being sent.
         *
         * Cardinality is deliberate. The worker cancels one row at a time and
         * fires this once per row with $count === 1 and the recipient it knows.
         * Bulk cancellations — a deleted submission, or the admin discarding the
         * queue — fire it ONCE for the whole operation with $count set and
         * $recipient empty, because selecting every queued address purely to
         * emit hooks would read addresses the operation itself never needed.
         *
         * $reason is one of:
         *  - 'expired'            the row outlived its two-hour TTL unsent;
         *  - 'submission_deleted' the submission was deleted or aged out;
         *  - 'admin_clear'        an administrator discarded the queue.
         *
         * @param string $submissionId Submission id, '' for a site-wide clear.
         * @param string $recipient    Recipient address, '' for bulk cancellations.
         * @param string $reason       Stable reason code.
         * @param int    $count        Number of queued notifications cancelled.
         */
        do_action('convermetry_notification_canceled', $submissionId, $recipient, $reason, $count);
    }

    /**
     * Schedules the next attempt, or gives up.
     *
     * @param int    $rowId     Queue row id.
     * @param int    $attempt   1-based attempt just completed.
     * @param string $recipient Recipient address, for the failure notice.
     * @param string $error        Failure reason.
     * @param string $submissionId The submission the notification belongs to.
     * @return void
     */
    private static function rescheduleOrAbandon(
        int $rowId,
        int $attempt,
        string $recipient,
        string $error,
        string $submissionId = ''
    ): void {
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

            /**
             * Fires after a notification's retries are spent and its queue row
             * has been deleted. Terminal: this message will never be sent.
             *
             * Fires once per abandoned row, after the delete, so the queue a
             * listener inspects is already settled. $error is the transport's
             * own failure message, truncated — useful for alerting on a broken
             * MTA, which is otherwise entirely silent.
             *
             * @param string $submissionId The submission that will not be notified about.
             * @param string $recipient    Recipient address that was never reached.
             * @param int    $attempt      Number of attempts made.
             * @param string $error        Last transport failure message.
             */
            do_action('convermetry_notification_abandoned', $submissionId, $recipient, $attempt, $error);

            return;
        }

        $nextAt = time() + $delays[$attempt - 1];

        $wpdb->update(
            $table,
            [
                'status'          => 'pending',
                'claim'           => '',
                'attempt'         => $attempt,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', $nextAt),
                'last_error'      => mb_substr($error, 0, 191),
            ],
            ['id' => $rowId],
            ['%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );

        /**
         * Fires after a failed notification's next attempt has been persisted
         * to its queue row — never speculatively before that write.
         *
         * Fires once per scheduled retry. The schedule itself is
         * convermetry_notification_retry_schedule's, and is deliberately
         * separate from the webhook schedule.
         *
         * @param string $submissionId The submission being notified about.
         * @param string $recipient    Recipient address.
         * @param int    $nextAttempt  Attempt number that will run next.
         * @param int    $nextAttemptAt Unix timestamp of the next attempt.
         */
        do_action('convermetry_notification_retry_scheduled', $submissionId, $recipient, $attempt + 1, $nextAt);
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

        $deleted = $wpdb->delete(self::tableName(), ['submission_id' => $submissionId], ['%s']);

        // One aggregate announcement rather than one per row: the delete never
        // needed to know which addresses were queued, and reading them back
        // purely to emit hooks would surface addresses this operation exists to
        // forget.
        if (is_int($deleted) && $deleted > 0) {
            self::announceCancellation($submissionId, '', 'submission_deleted', $deleted);
        }
    }

    /**
     * Drains the whole queue (Clear All, or an explicit admin action).
     *
     * @return void
     */
    public static function cancelAll(): void
    {
        global $wpdb;

        $deleted = $wpdb->query('DELETE FROM ' . self::tableName());

        if (is_int($deleted) && $deleted > 0) {
            self::announceCancellation('', '', 'admin_clear', $deleted);
        }
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
