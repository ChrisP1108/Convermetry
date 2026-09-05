<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Analytics\SubmissionContext;
use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Settings\Options;
use Convermetry\Support\Errors;
use Convermetry\Support\Http;
use Convermetry\Support\QueueOutcome;

/**
 * Database-backed background delivery queue for form-submission webhooks.
 *
 * One row = one submission × one endpoint. Queuing rows is the only work
 * that happens during the visitor's form request; everything else — payload
 * building, analytics enrichment, HTTP delivery, retries — runs in a
 * background WP-Cron worker, so an external webhook outage can never make a
 * valid WordPress form submission appear to fail.
 *
 * Guarantees, mirroring the analytics dispatcher's:
 *
 *  - Frozen requests: on a row's FIRST delivery attempt the final URL
 *    (global + page + per-form + runtime query parameters merged), the
 *    headers, and the serialized JSON body are frozen into the row. Every
 *    retry replays those exact bytes — a configuration change after a
 *    failure never mutates an already-frozen retry.
 *  - Stable delivery_id: deterministic per (site, endpoint, submission), so
 *    every attempt for one endpoint carries the same id / Idempotency-Key,
 *    and receivers deduplicate by delivery_id alone.
 *  - Per-endpoint rows: endpoints that already acknowledged a delivery are
 *    never re-sent when a sibling endpoint fails.
 *  - Retry schedule: 5m, 30m, 2h, 6h, 16h after the initial attempt
 *    (shared with analytics retries; filterable via
 *    'convermetry_retry_schedule'). After the final failure the delivery is
 *    abandoned — every attempt remains visible in the Activity Log.
 *  - Recovery: the worker cron is re-armed by activation, by the daily
 *    cleanup, and by every analytics dispatch run, so rows stranded by a
 *    lost cron event (or a deactivate/reactivate cycle) are always picked
 *    back up. Rows stuck in 'sending' (a worker died mid-flight) are
 *    reclaimed after a timeout.
 *  - Repair record: an INSERT verified NOT to have landed is recorded
 *    durably, one option row per submission, naming the exact destinations
 *    still owed a row. Every repair path is authorised by that record and by
 *    nothing else — never by "this submission has no queue row", which is
 *    equally true of one that was delivered and cleaned up. Before re-queuing,
 *    a second and independent guard checks the Activity Log for an attempt
 *    against that endpoint, so a record that outlived its own deletion still
 *    cannot re-send a delivered lead.
 *
 * Storing the queue in its own table — not in autoloaded options — keeps
 * potentially large, PII-bearing frozen payloads out of every page load.
 */
final class FormDeliveryQueue
{
    /** Table name without the wpdb prefix. */
    private const string TABLE = 'cvm_delivery_queue';

    /** Option key storing the installed schema version. */
    private const string DB_VERSION_OPTION = 'cvm_queue_db_version';

    /** Current schema version; bump when the CREATE TABLE below changes. */
    private const string DB_VERSION = '1.0.0';

    /** Cron hook name for the queue worker. */
    public const string WORKER_HOOK = 'cvm_process_form_queue';

    /** Cron hook that repairs queue rows which failed to persist. */
    public const string RECONCILE_HOOK = 'cvm_reconcile_form_queue';

    /**
     * Backoff between repair attempts, in seconds. Bounded on purpose: a
     * destination that cannot be queued after these is reported, not retried
     * forever.
     */
    private const array RECONCILE_DELAYS = [30, 300, 1800];

    /**
     * Option-name prefix for one submission's durable repair record.
     *
     * Not autoloaded, and read only on repair paths — never on a page load.
     * These live in wp_options rather than in a column of the queue table on
     * purpose: what they record is that a write to THAT table was refused, so a
     * marker kept there would be lost to the very failure it exists to survive.
     *
     * One row per submission; see {@see repairOptionName()} for why that is
     * load-bearing rather than tidy.
     */
    private const string REPAIR_PREFIX = 'cvm_queue_repair_';

    /**
     * How long a destination stays eligible for repair after its queue write
     * failed. Bounded on purpose: a lead that could not be queued for a week is
     * stale enough that delivering it would surprise more than it would help,
     * and giving up is announced ('queue_repair_expired') rather than left to
     * happen quietly as a pruning side effect.
     */
    private const int REPAIR_TTL = 7 * DAY_IN_SECONDS;

    /** Repair records read per statement by one safety-net pass. */
    private const int REPAIR_CHUNK = 100;

    /** Maximum chunks one safety-net pass may process. */
    private const int REPAIR_MAX_CHUNKS = 10;

    /** Wall-clock seconds budgeted for one safety-net pass. */
    private const int REPAIR_TIME_BUDGET = 15;

    /** Maximum queue rows one worker pass may claim. */
    private const int BATCH_SIZE = 10;

    /** Wall-clock seconds budgeted per worker pass. */
    private const int TIME_BUDGET = 45;

    /** Seconds after which a 'sending' claim is considered dead and reclaimed. */
    private const int CLAIM_TIMEOUT = 10 * MINUTE_IN_SECONDS;

    /**
     * Returns the fully-prefixed queue table name.
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

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_row BIGINT UNSIGNED NOT NULL DEFAULT 0,
            submission_id VARCHAR(40) NOT NULL,
            endpoint_key CHAR(32) NOT NULL,
            endpoint_url TEXT NOT NULL,
            delivery_id CHAR(32) NOT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'pending',
            attempt TINYINT UNSIGNED NOT NULL DEFAULT 0,
            next_attempt_at DATETIME NOT NULL,
            claim CHAR(32) NOT NULL DEFAULT '',
            claimed_at DATETIME NULL,
            frozen_url TEXT NULL,
            frozen_headers LONGTEXT NULL,
            frozen_body LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY submission_endpoint (submission_id,endpoint_key),
            KEY status_due (status,next_attempt_at)
        ) {$charset};";

        dbDelta($sql);

        $expected = [
            'id', 'submission_row', 'submission_id', 'endpoint_key', 'endpoint_url',
            'delivery_id', 'status', 'attempt', 'next_attempt_at', 'claim',
            'claimed_at', 'frozen_url', 'frozen_headers', 'frozen_body', 'created_at',
        ];
        if (DatabaseManager::tableHasColumns($table, $expected)) {
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
     * Registers the worker cron callback.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action(self::WORKER_HOOK, [self::class, 'processDue']);
        add_action(self::RECONCILE_HOOK, [self::class, 'reconcile'], 10, 3);
    }

    /**
     * Deterministic delivery id for one endpoint + one submission.
     *
     * Stable across every attempt (and across worker restarts), so a
     * receiver that saw the delivery whose response was lost recognizes the
     * replay. Endpoint-specific by construction, while the submission_id
     * inside the payload stays global to the submission.
     *
     * @param string $endpointUrl  Configured endpoint URL.
     * @param string $submissionId Globally unique submission id.
     * @return string 32-character hex id.
     */
    public static function deliveryId(string $endpointUrl, string $submissionId): string
    {
        return md5(home_url() . '|' . $endpointUrl . '|' . $submissionId);
    }

    /**
     * Queues one submission for delivery to every endpoint that accepts form
     * submissions, then kicks the background worker.
     *
     * Cheap by design — a handful of INSERTs and one cron schedule; no
     * payload building, no analytics queries, no HTTP. INSERT IGNORE against
     * the UNIQUE (submission_id, endpoint_key) index makes double-enqueues
     * (a duplicate provider callback that slipped past submission dedup)
     * harmless.
     *
     * Returns a VERIFIED outcome rather than a bare count. INSERT IGNORE
     * reports "0 rows affected" both for a row the unique index suppressed
     * (already queued, which is fine) and for a row it declined to write
     * (not fine), and $wpdb->query() returns false outright on error. The old
     * count incremented only on a genuine insert, so a failed write was
     * indistinguishable from a duplicate — and the caller reported the
     * submission as queued either way, losing the lead in silence.
     *
     * Every ambiguous result is now resolved by reading the row back, so
     * "durable" means the row was observed to exist, not assumed to.
     *
     * @param int    $submissionRow Row id in the form submissions table.
     * @param string $submissionId  Globally unique submission id.
     * @return QueueOutcome Verified per-endpoint result.
     */
    public static function enqueue(int $submissionRow, string $submissionId): QueueOutcome
    {
        global $wpdb;

        $endpoints = Options::formEndpoints();
        if ($endpoints === []) {
            return QueueOutcome::nothingToQueue();
        }

        $now        = gmdate('Y-m-d H:i:s');
        $queued     = 0;
        $duplicate  = 0;
        $failedRefs = [];

        foreach ($endpoints as $endpoint) {
            $endpointKey = md5($endpoint->url);

            $inserted = $wpdb->query($wpdb->prepare(
                'INSERT IGNORE INTO ' . self::tableName()
                . ' (submission_row, submission_id, endpoint_key, endpoint_url, delivery_id,'
                . " status, attempt, next_attempt_at, created_at)"
                . " VALUES (%d, %s, %s, %s, %s, 'pending', 0, %s, %s)",
                $submissionRow,
                $submissionId,
                $endpointKey,
                $endpoint->url,
                self::deliveryId($endpoint->url, $submissionId),
                $now,
                $now
            ));

            if ($inserted !== 1) {
                // Ambiguous or failed. A read-back is the only thing that can
                // tell "already queued" apart from "never written".
                if ($inserted !== false && self::rowExists($submissionId, $endpointKey)) {
                    $duplicate++;
                } else {
                    $failedRefs[] = self::endpointRef($endpoint);
                }

                continue;
            }

            $queued++;

            /**
             * Fires when a form submission is genuinely queued for delivery
             * to one endpoint.
             *
             * Fires once per endpoint per submission, and ONLY when the
             * INSERT IGNORE actually created a row. A re-enqueue that the
             * unique (submission_id, endpoint_key) index suppressed does not
             * fire it, so a listener can count these as real work rather
             * than as attempts at work.
             *
             * Nothing has been sent at this point and nothing is frozen: the
             * payload is built on the worker's first attempt. Never fires on
             * the synchronous delivery path, which sends without queuing.
             *
             * @param array<string, mixed> $context Credential-free delivery context.
             */
            do_action('convermetry_form_delivery_queued', DeliveryDetails::for(
                $endpoint->url,
                messageType: MessageType::FormSubmission,
                kind: DeliveryKind::Immediate,
                attempt: 0,
                deliveryId: self::deliveryId($endpoint->url, $submissionId),
                endpointLabel: $endpoint->label,
                submissionId: $submissionId,
            )->toArray());
        }

        $outcome = new QueueOutcome(
            expected: count($endpoints),
            inserted: $queued,
            duplicate: $duplicate,
            failed: count($failedRefs),
            failedRefs: $failedRefs,
        );

        if (!$outcome->isComplete()) {
            // Verified evidence that a row the plugin needed does not exist.
            Errors::storage(
                'form_delivery_queue',
                'insert',
                'queue_row_not_persisted',
                $outcome->telemetry() + ['submission_id' => $submissionId]
            );

            // Recorded BEFORE the cron is scheduled, because scheduling is
            // itself a write that can fail — and a repair nobody remembers is
            // owed is a lead nobody delivers. This record, not any inference
            // from the submission's delivery state, is what later authorises a
            // repair; see {@see repairIfNeverQueued()}.
            self::recordRepairIntent($submissionId, $outcome->failedRefs);

            // Repair exactly the endpoints whose rows are known to be absent.
            // Re-queuing anything broader could re-send a delivery a worker had
            // already completed and deleted.
            self::scheduleReconciliation($submissionId, $outcome->failedRefs);
        }

        if ($queued > 0) {
            // The submission now reads "Queued" in the admin list, immediately,
            // rather than "Not sent" until the worker's first attempt lands.
            FormSubmissions::refreshDeliveryState($submissionId);

            self::scheduleWorker(time() + 1);

            // Fire WP-Cron in the background right now (non-blocking loopback
            // request) so the delivery typically leaves within seconds instead
            // of waiting for the next organic page load.
            if (function_exists('spawn_cron')) {
                spawn_cron();
            }
        }

        return $outcome;
    }

    /**
     * How many delivery rows are currently queued for a submission.
     *
     * @param string $submissionId Globally unique submission id.
     * @return int
     */
    public static function pendingCountFor(string $submissionId): int
    {
        global $wpdb;

        if ($submissionId === '') {
            return 0;
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::tableName() . ' WHERE submission_id = %s',
            $submissionId
        ));
    }

    /**
     * Repairs a submission whose queue rows are RECORDED as never having landed.
     *
     * Called from the duplicate-submission path, where a re-fired provider
     * callback is the first thing to notice that the original request's enqueue
     * failed and its repair passes did too.
     *
     * The gate is the durable repair record and NOTHING else. The first version
     * of this method inferred the intent instead — it repaired whenever there
     * was no queue row and the submission's recorded delivery state was
     * 'not_sent' — and 'not_sent' does not mean "the enqueue failed". Two
     * ordinary situations produce it:
     *
     *  - A site that records submissions without using webhooks at all. That is
     *    exactly what {@see DeliveryState::NotSent} is for. Enable webhooks and
     *    add an endpoint months later, and a replayed provider callback for an
     *    old submission would have delivered a lead that was never meant to be
     *    sent anywhere.
     *  - A delivery that SUCCEEDED and left no evidence. The worker deletes the
     *    queue row on a 2xx, and 'convermetry_delivery_log_row' is allowed to
     *    suppress the log row that would otherwise remember it (as is a failed
     *    log INSERT, {@see LogOutcome::Failed}). With neither row left,
     *    {@see FormSubmissions::refreshDeliveryState()} settles the submission
     *    back on 'not_sent' — and the receiver would have been sent a lead it
     *    had already processed.
     *
     * What actually proves a queue write failed is the record {@see enqueue()}
     * wrote when it read the table back and verified the row was absent. No
     * record, no repair.
     *
     * @param string $submissionId Globally unique submission id.
     * @return void
     */
    public static function repairIfNeverQueued(string $submissionId): void
    {
        $refs = self::pendingRepairFor($submissionId);

        if ($refs === []) {
            return;
        }

        Errors::storage('form_delivery_queue', 'duplicate', 'queue_repair_on_duplicate', [
            'submission_id' => $submissionId,
            'endpoints'     => count($refs),
        ]);

        // Repaired on THIS request rather than scheduled for later. The cron
        // chain that was supposed to do this has already spent its attempts,
        // and on a site where a lost cron event is the actual fault, this
        // request is the only thing that will run.
        self::repairDestinations($submissionId, $refs, 0, resumeChain: false);
    }

    /**
     * Daily safety net: retries every destination still recorded as unqueued.
     *
     * The bounded cron chain can end with the row still unwritten — the
     * database problem outlasted all three attempts, or
     * wp_schedule_single_event() failed and no repair pass was ever queued at
     * all. The record outlives both, so this pass picks up exactly the
     * destinations that are still outstanding.
     *
     * "Exactly" is the point. A scan for submissions with no queue row would
     * also match every submission that was delivered and had its row deleted,
     * and re-queuing those would re-send leads the receiver already has. Only
     * a destination a verified read-back found missing is ever repaired.
     *
     * Runs on the daily cleanup cron. The WORK is bounded — a chunk cursor, a
     * chunk cap and a wall-clock budget, mirroring the retention and backfill
     * passes — while the number of outstanding obligations is not. A site that
     * owes more repairs than one run can process keeps every one of them and
     * resumes next run; nothing is evicted to keep a pass short.
     *
     * @return void
     */
    public static function repairPending(): void
    {
        global $wpdb;

        $deadline = microtime(true) + self::REPAIR_TIME_BUDGET;
        $after    = 0;

        for ($chunk = 0; $chunk < self::REPAIR_MAX_CHUNKS; $chunk++) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT option_id, option_name, option_value FROM {$wpdb->options}"
                . ' WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT %d',
                $wpdb->esc_like(self::REPAIR_PREFIX) . '%',
                $after,
                self::REPAIR_CHUNK
            ), ARRAY_A);

            if (!is_array($rows) || $rows === []) {
                return;
            }

            foreach ($rows as $row) {
                // A cursor, not an offset: a record this pass settles is
                // deleted, which would shift every later row under an OFFSET
                // and skip one per removal.
                $after = (int) ($row['option_id'] ?? 0);

                $submissionId = substr((string) ($row['option_name'] ?? ''), strlen(self::REPAIR_PREFIX));
                if ($submissionId === '') {
                    continue;
                }

                $record = self::decodeRepairRecord((string) ($row['option_value'] ?? ''));

                if ($record === null) {
                    // Expired or unreadable. Announced as its own terminal code
                    // BEFORE the row goes: "delivery of this lead was given up
                    // on" is worth saying out loud, not something an operator
                    // should have to infer from a record that stopped existing.
                    Errors::storage('form_delivery_queue', 'repair_record', 'queue_repair_expired', [
                        'submission_id' => $submissionId,
                    ]);

                    self::forgetRepairIntent($submissionId);

                    continue;
                }

                self::repairDestinations($submissionId, $record['refs'], 0, resumeChain: false);
            }

            if (count($rows) < self::REPAIR_CHUNK || microtime(true) >= $deadline) {
                return;
            }
        }
    }

    /**
     * The destinations still recorded as never queued for one submission.
     *
     * @param string $submissionId Globally unique submission id.
     * @return list<string> Durable endpoint references, empty when nothing is owed.
     */
    public static function pendingRepairFor(string $submissionId): array
    {
        $record = self::readRepairRecord($submissionId);

        return $record === null ? [] : $record['refs'];
    }

    /**
     * Records that specific destinations were owed a queue row and did not get one.
     *
     * 'at' is stamped once per submission and never refreshed, so a destination
     * that keeps failing expires on the schedule its FIRST failure started
     * rather than renewing its own deadline on every attempt.
     *
     * The write is VERIFIED. update_option() returns false both for a failed
     * write and for a value that did not change, so its result cannot answer
     * "did this land?" — and this is the one record standing between a refused
     * queue INSERT and a silently lost lead. It is read back instead, and a
     * record that does not name everything it was asked to is written once more
     * before the failure is announced.
     *
     * @param string       $submissionId Globally unique submission id.
     * @param list<string> $endpointRefs Durable references verified to have no row.
     * @return void
     */
    private static function recordRepairIntent(string $submissionId, array $endpointRefs): void
    {
        if ($submissionId === '' || $endpointRefs === []) {
            return;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $existing = self::readRepairRecord($submissionId);

            $stored = self::writeRepairRecord(
                $submissionId,
                $existing === null ? $endpointRefs : array_merge($existing['refs'], $endpointRefs),
                $existing === null ? time() : $existing['at']
            );

            // A second pass covers the one interleaving a per-submission row
            // still allows: two writers merging onto the same record at once,
            // where the loser's references would otherwise be dropped.
            if ($stored !== null && array_diff($endpointRefs, $stored['refs']) === []) {
                return;
            }
        }

        Errors::storage('form_delivery_queue', 'repair_record', 'queue_repair_not_recorded', [
            'submission_id' => $submissionId,
            'endpoints'     => count($endpointRefs),
        ]);
    }

    /**
     * Updates one submission's record after a repair pass.
     *
     * Only the references this pass attempted are settled; anything still
     * failing is written back so the next pass finds it. A reference the pass
     * could not match to a configured endpoint settles too — the operator
     * deleted the endpoint or turned form delivery off for it, so nothing is
     * owed to it any more and keeping the record would only make it expire
     * slowly instead of now.
     *
     * @param string       $submissionId Globally unique submission id.
     * @param list<string> $attempted    Every reference this pass considered.
     * @param list<string> $outstanding  References that still have no row.
     * @return void
     */
    private static function settleRepairIntent(string $submissionId, array $attempted, array $outstanding): void
    {
        $existing = self::readRepairRecord($submissionId);

        if ($existing === null) {
            return;
        }

        $refs = array_values(array_unique(array_merge(
            array_diff($existing['refs'], $attempted),
            $outstanding
        )));

        if ($refs === []) {
            self::forgetRepairIntent($submissionId);

            return;
        }

        if ($refs === $existing['refs']) {
            return;
        }

        $stored = self::writeRepairRecord($submissionId, $refs, $existing['at']);

        if ($stored === null || array_diff($stored['refs'], $refs) !== []) {
            // The shrink did not land, so the record still names destinations
            // this pass settled. Announced rather than assumed: the next pass
            // will re-check each of them against the queue and the delivery log
            // before it re-queues anything, but an operator should know the
            // options table refused a write.
            Errors::storage('form_delivery_queue', 'repair_record', 'queue_repair_not_cleared', [
                'submission_id' => $submissionId,
                'endpoints'     => count($refs),
            ]);
        }
    }

    /**
     * Removes one submission's record, and verifies the row is gone.
     *
     * Verified because a record that survives its own deletion is the one way
     * this mechanism could cause the duplicate delivery it exists to prevent:
     * the repaired row lands, the worker delivers it and deletes the row, and a
     * stale record then authorises a second send. {@see deliveryAttempted()} is
     * the second guard against exactly that; this is the first.
     *
     * @param string $submissionId Globally unique submission id.
     * @return void
     */
    private static function forgetRepairIntent(string $submissionId): void
    {
        global $wpdb;

        if ($submissionId === '') {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s",
            self::repairOptionName($submissionId)
        ));

        if (!self::repairRecordExists($submissionId)) {
            return;
        }

        Errors::storage('form_delivery_queue', 'repair_record', 'queue_repair_not_cleared', [
            'submission_id' => $submissionId,
            'endpoints'     => 0,
        ]);
    }

    /**
     * The option name holding one submission's repair record.
     *
     * ONE RECORD PER SUBMISSION, deliberately. The first implementation kept
     * every outstanding repair in a single serialized option and rewrote the
     * whole map on each change — a read-modify-write with no compare-and-swap,
     * so two submissions failing to queue at the same moment each wrote back a
     * map built before the other existed and one obligation was silently lost.
     * That is not a load-test curiosity: concurrent failures are the NORMAL
     * shape of this path, because what puts a submission on it is the queue
     * table refusing writes for everyone at once.
     *
     * Separate rows also remove the cap the shared map needed. Nothing is
     * evicted to keep one option from growing without bound; a pass is bounded
     * instead ({@see repairPending()}), so a site can owe more repairs than one
     * cron run will process without any of them being forgotten.
     *
     * Mirrors the rate limiter's per-key counters, down to being read and
     * written straight through $wpdb so the values never touch — or pollute —
     * WordPress's option caches.
     *
     * @param string $submissionId Globally unique submission id.
     * @return string
     */
    private static function repairOptionName(string $submissionId): string
    {
        return self::REPAIR_PREFIX . $submissionId;
    }

    /**
     * One submission's repair record, or null when there is nothing owed.
     *
     * An expired or unreadable record reads as nothing owed, so no repair path
     * can act on one. Removing it is {@see repairPending()}'s business, because
     * only that pass reports the expiry.
     *
     * @param string $submissionId Globally unique submission id.
     * @return array{refs: list<string>, at: int}|null
     */
    private static function readRepairRecord(string $submissionId): ?array
    {
        global $wpdb;

        if ($submissionId === '') {
            return null;
        }

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::repairOptionName($submissionId)
        ));

        return is_string($value) ? self::decodeRepairRecord($value) : null;
    }

    /**
     * Whether the row exists at all, expired or not.
     *
     * @param string $submissionId Globally unique submission id.
     * @return bool
     */
    private static function repairRecordExists(string $submissionId): bool
    {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s",
            self::repairOptionName($submissionId)
        )) !== null;
    }

    /**
     * Coerces one stored record, treating an expired or malformed one as absent.
     *
     * The row is ordinary site data — a filter, a partial write, or a hand edit
     * can put anything in it — so every field is coerced rather than trusted.
     *
     * Pure: no database, no WordPress state.
     *
     * @param string $value Raw option value.
     * @return array{refs: list<string>, at: int}|null
     */
    private static function decodeRepairRecord(string $value): ?array
    {
        $decoded = self::decodeJson($value);
        $at      = (int) ($decoded['at'] ?? 0);

        if ($at <= time() - self::REPAIR_TTL) {
            return null;
        }

        $refs = [];
        foreach ((array) ($decoded['refs'] ?? []) as $ref) {
            if (is_string($ref) && $ref !== '') {
                $refs[] = $ref;
            }
        }

        return $refs === [] ? null : ['refs' => array_values(array_unique($refs)), 'at' => $at];
    }

    /**
     * Writes one submission's record and hands back what is actually stored.
     *
     * Atomic per row: INSERT ... ON DUPLICATE KEY UPDATE against the unique
     * option_name index, so the row is created or replaced in one statement
     * rather than in a read-then-write the next request can interleave with.
     *
     * The affected-row count is not consulted, because it is ambiguous — the
     * statement reports 0 when the row already held this exact value, which is
     * a success. The row is read back instead.
     *
     * @param string       $submissionId Globally unique submission id.
     * @param list<string> $refs         Durable endpoint references still owed a row.
     * @param int          $at           When the FIRST failure for this submission happened.
     * @return array{refs: list<string>, at: int}|null The stored record, or null when the write did not land.
     */
    private static function writeRepairRecord(string $submissionId, array $refs, int $at): ?array
    {
        global $wpdb;

        $value = (string) wp_json_encode(
            ['at' => $at, 'refs' => array_values(array_unique($refs))],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')"
            . ' ON DUPLICATE KEY UPDATE option_value = %s',
            self::repairOptionName($submissionId),
            $value,
            $value
        ));

        return self::readRepairRecord($submissionId);
    }

    /**
     * The durable reference used to address an endpoint across requests.
     *
     * The queue ROW is still keyed by md5(url), which is what the unique index
     * and every existing row use. Repair scheduling is different: it has to
     * survive a URL edit between the failed enqueue and the repair pass, and
     * md5(url) would stop matching the moment an operator changed the URL.
     *
     * @param \Convermetry\Settings\WebhookEndpoint $endpoint Configured endpoint.
     * @return string Durable id, or the legacy url hash when none is assigned.
     */
    private static function endpointRef(\Convermetry\Settings\WebhookEndpoint $endpoint): string
    {
        return $endpoint->id !== '' ? $endpoint->id : md5($endpoint->url);
    }

    /**
     * Whether the Activity Log already holds an attempt for this pair.
     *
     * Repair's second guard. A form-submission log row naming this submission
     * and this endpoint means a worker (or the synchronous path) got as far as
     * sending, so the delivery is not one that "never landed" — whatever the
     * repair record still says. Test deliveries carry no submission id and can
     * never match.
     *
     * The gate is one-way: it only ever SUPPRESSES a repair. An endpoint whose
     * URL was edited since the attempt will not match its old log row, which
     * leaves the record's own verdict in charge — the behaviour without this
     * check at all.
     *
     * @phpstan-impure
     *
     * @param string $submissionId Globally unique submission id.
     * @param string $endpointUrl  Endpoint URL as currently configured.
     * @return bool
     */
    private static function deliveryAttempted(string $submissionId, string $endpointUrl): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . DeliveryLog::tableName()
            . " WHERE submission_id = %s AND message_type = 'form_submission' AND endpoint_url = %s",
            $submissionId,
            $endpointUrl
        )) > 0;
    }

    /**
     * Whether a queue row for this (submission, endpoint) pair exists.
     *
     * Impure by nature: it reads a table other requests — and the INSERT
     * immediately before each call site — are concurrently changing, so two
     * calls with the same arguments legitimately return different answers.
     *
     * @phpstan-impure
     *
     * @param string $submissionId Globally unique submission id.
     * @param string $endpointKey  md5 of the endpoint URL.
     * @return bool
     */
    private static function rowExists(string $submissionId, string $endpointKey): bool
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::tableName()
            . ' WHERE submission_id = %s AND endpoint_key = %s',
            $submissionId,
            $endpointKey
        )) > 0;
    }

    /**
     * Schedules the next repair pass for queue rows that failed to persist.
     *
     * BOUNDED. A single repair attempt was too thin: the database problem that
     * refused the original insert is frequently still present 30 seconds later,
     * and one more failure left the destination undelivered. The backoff gives
     * a transient outage three chances across roughly half an hour, then stops
     * and says so rather than retrying forever.
     *
     * The scheduling call's own result is checked, because a cron write that
     * fails is exactly as lost as the queue row was.
     *
     * @param string       $submissionId Globally unique submission id.
     * @param list<string> $endpointRefs Durable references known to have no row.
     * @param int          $attempt      Repair attempts already made.
     * @return void
     */
    private static function scheduleReconciliation(
        string $submissionId,
        array $endpointRefs,
        int $attempt = 0
    ): void {
        if ($submissionId === '' || $endpointRefs === []) {
            return;
        }

        if ($attempt >= count(self::RECONCILE_DELAYS)) {
            // The CRON CHAIN is out of retries — the durable record is not.
            // Announced as its own code so an operator can alert on "half an
            // hour of repair attempts did not queue this destination"
            // distinctly from "one attempt failed and another is scheduled".
            // {@see repairPending()} keeps trying daily until the record
            // expires, and a duplicate callback repairs it on sight.
            Errors::storage('form_delivery_queue', 'reconcile', 'queue_repair_abandoned', [
                'submission_id' => $submissionId,
                'attempts'      => $attempt,
                'endpoints'     => count($endpointRefs),
            ]);

            return;
        }

        $scheduled = wp_schedule_single_event(
            time() + self::RECONCILE_DELAYS[$attempt],
            self::RECONCILE_HOOK,
            [$submissionId, $endpointRefs, $attempt + 1]
        );

        if ($scheduled === false) {
            Errors::storage('form_delivery_queue', 'reconcile', 'queue_repair_not_scheduled', [
                'submission_id' => $submissionId,
                'attempt'       => $attempt,
                'endpoints'     => count($endpointRefs),
            ]);

            return;
        }

        if (function_exists('spawn_cron')) {
            spawn_cron();
        }
    }

    /**
     * Cron callback: re-creates queue rows that failed to persist.
     *
     * Deliberately narrow. It re-inserts ONLY the endpoint keys the failing
     * enqueue recorded as absent, and only for endpoints that are still
     * configured to receive form submissions. It never scans for "missing"
     * rows generally, because a row that is absent because the worker
     * delivered it and deleted it is indistinguishable from one that was never
     * written — and re-queuing the former would re-send a delivered webhook.
     *
     * Idempotent: INSERT IGNORE against the UNIQUE (submission_id,
     * endpoint_key) index, and the delivery id is a pure function of the
     * endpoint and submission, so even a re-created row carries the original
     * Idempotency-Key.
     *
     * @param string       $submissionId Globally unique submission id.
     * @param list<string> $endpointRefs Durable endpoint references to repair.
     * @param int          $attempt      Which repair attempt this is (1-based).
     * @return void
     */
    public static function reconcile(string $submissionId, array $endpointRefs, int $attempt = 1): void
    {
        self::repairDestinations($submissionId, $endpointRefs, $attempt, resumeChain: true);
    }

    /**
     * Re-creates the missing queue rows for one submission.
     *
     * Shared by all three ways a repair can be reached: the cron chain
     * {@see reconcile()}, the duplicate-callback path, and the daily safety net.
     * Only the cron chain resumes itself on failure — an out-of-band pass
     * reports what happened and leaves the durable record for the next one,
     * rather than starting a second backoff chain alongside the first.
     *
     * @param string       $submissionId Globally unique submission id.
     * @param list<string> $endpointRefs Durable endpoint references to repair.
     * @param int          $attempt      Cron attempt number, or 0 for an out-of-band pass.
     * @param bool         $resumeChain  Whether a failure schedules the next cron attempt.
     * @return void
     */
    private static function repairDestinations(
        string $submissionId,
        array $endpointRefs,
        int $attempt,
        bool $resumeChain
    ): void {
        global $wpdb;

        if ($submissionId === '' || $endpointRefs === []) {
            return;
        }

        $submission = FormSubmissions::getBySubmissionId($submissionId);

        // The submission was deleted (retention, or an erasure request) between
        // the failed enqueue and this pass. Erasure wins: nothing is re-created,
        // and the record goes with it rather than naming a deleted submission
        // until it expires.
        if ($submission === null) {
            self::forgetRepairIntent($submissionId);

            return;
        }

        $wanted     = array_flip($endpointRefs);
        $now        = gmdate('Y-m-d H:i:s');
        $repaired   = 0;
        $failedRefs = [];

        foreach (Options::formEndpoints() as $endpoint) {
            $ref = self::endpointRef($endpoint);

            if (!isset($wanted[$ref])) {
                continue;
            }

            // Resolved from the CURRENT configuration, so an endpoint whose URL
            // was edited since the failed enqueue is queued at its new address
            // rather than silently skipped.
            $endpointKey = md5($endpoint->url);

            if (self::rowExists($submissionId, $endpointKey)) {
                continue;
            }

            // Independent of the record, and the reason a stale record cannot
            // by itself cause a duplicate delivery. An attempt logged against
            // this endpoint means the delivery WAS made: the missing queue row
            // is the worker having finished with it, not a row that never
            // existed. Both this and the record's own deletion would have to
            // fail for a lead to be sent twice.
            if (self::deliveryAttempted($submissionId, $endpoint->url)) {
                continue;
            }

            $inserted = $wpdb->query($wpdb->prepare(
                'INSERT IGNORE INTO ' . self::tableName()
                . ' (submission_row, submission_id, endpoint_key, endpoint_url, delivery_id,'
                . " status, attempt, next_attempt_at, created_at)"
                . " VALUES (%d, %s, %s, %s, %s, 'pending', 0, %s, %s)",
                (int) $submission['id'],
                $submissionId,
                $endpointKey,
                $endpoint->url,
                self::deliveryId($endpoint->url, $submissionId),
                $now,
                $now
            ));

            if ($inserted === 1 || self::rowExists($submissionId, $endpointKey)) {
                $repaired++;
                continue;
            }

            $failedRefs[] = $ref;
        }

        // Settled before anything else can fail: a destination that now has a
        // row, and one whose endpoint is no longer configured, are both done —
        // and leaving either in the record would have the safety net re-queue
        // it every day until it expired.
        self::settleRepairIntent($submissionId, $endpointRefs, $failedRefs);

        if ($failedRefs !== []) {
            Errors::storage('form_delivery_queue', 'reconcile', 'queue_repair_failed', [
                'submission_id' => $submissionId,
                'attempt'       => $attempt,
                'repaired'      => $repaired,
                'failed'        => count($failedRefs),
            ]);

            if ($resumeChain) {
                // The condition that refused the original insert is often still
                // present. Try again on the next backoff step rather than
                // leaving the destination undelivered after one attempt.
                self::scheduleReconciliation($submissionId, $failedRefs, $attempt);
            }
        }

        if ($repaired > 0) {
            FormSubmissions::refreshDeliveryState($submissionId);
            self::scheduleWorker(time() + 1);

            if (function_exists('spawn_cron')) {
                spawn_cron();
            }
        }
    }

    /**
     * Cron callback: processes every due queue row within this pass's
     * budget.
     *
     * Rows are CLAIMED first via an atomic conditional UPDATE stamped with a
     * per-pass token, so two overlapping worker processes can never send the
     * same row twice. Rows this pass claims but runs out of time for are
     * released back to 'pending'.
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

        // A paused Webhook Status toggle pauses queued deliveries too — the
        // rows are kept, and the worker re-checks later so nothing is lost.
        if (!Options::webhooksActive()) {
            if (self::pendingCount() > 0) {
                self::scheduleWorker(time() + 15 * MINUTE_IN_SECONDS);
            }
            return;
        }

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

        // Frozen base payloads memoized per submission for this pass, so a
        // submission fanning out to several endpoints builds (and enriches)
        // its payload once instead of once per endpoint.
        $payloadCache = [];

        foreach ($rows as $row) {
            if (microtime(true) >= $deadline) {
                // Out of budget — release the remainder untouched.
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$table} SET status = 'pending', claim = '' WHERE id = %d AND claim = %s",
                    (int) $row['id'],
                    $token
                ));
                continue;
            }

            self::processRow($row, $payloadCache);
        }

        // Re-arm for whatever is still pending (failed rows just rescheduled,
        // rows released above, or rows that became due mid-pass).
        self::ensureWorkerScheduled();
    }

    /**
     * Processes one claimed queue row: freezes the request on the first
     * attempt, sends it, logs the outcome, and reschedules or finishes.
     *
     * @param array<string, mixed>              $row          Claimed queue row.
     * @param array<string, array<string,mixed>|null> $payloadCache Per-pass base payload memo (by submission_id).
     * @return void
     */
    private static function processRow(array $row, array &$payloadCache): void
    {
        global $wpdb;

        $table        = self::tableName();
        $rowId        = (int) $row['id'];
        $endpointUrl  = (string) $row['endpoint_url'];
        $submissionId = (string) $row['submission_id'];
        $attempt      = (int) $row['attempt'] + 1;

        // Resolved by submission_id ONLY — never by the numeric submission_row.
        // submission_id is globally unique and never reused; row ids are reused
        // the moment TRUNCATE resets AUTO_INCREMENT, and a numeric-first lookup
        // would then bind this queue row to an unrelated new submission and
        // deliver that visitor's lead under this row's delivery_id.
        $submission = FormSubmissions::getBySubmissionId($submissionId);

        // The submission is gone — aged out past retention, or deleted by an
        // admin. Stop, whether or not a payload was already frozen.
        //
        // This deliberately overrides the frozen-request guarantee that governs
        // every other case. A frozen body is a verbatim copy of the visitor's
        // field values, so replaying it after an erasure would keep sending
        // exactly the data the admin just deleted. Retention windows (30 days
        // and up) dwarf the retry chain (under a day), so in practice this only
        // ever fires for a deliberate deletion.
        if ($submission === null) {
            $wpdb->delete($table, ['id' => $rowId], ['%d']);

            // Cancelled, not abandoned: no attempt was ever made and no
            // Activity Log row is written for this. Announced after the delete,
            // so the queue a listener inspects is already settled.
            DeliveryContext::canceled(self::contextFor($row, null, $attempt), 'submission_deleted');
            return;
        }

        $frozenBody    = (string) ($row['frozen_body'] ?? '');
        $frozenUrl     = (string) ($row['frozen_url'] ?? '');
        $frozenHeaders = [];

        if ($frozenBody === '') {
            // First attempt: build and freeze the exact request this row
            // will (re-)send until it is acknowledged or abandoned.
            // ($submission is non-null: the guard above returned otherwise.)
            if (!isset($payloadCache[$submissionId])) {
                $submission                  = SubmissionContext::enrich($submission);
                $payloadCache[$submissionId] = PayloadBuilder::formSubmission($submission);
            }

            $payload = $payloadCache[$submissionId];

            $formKey   = (string) ($submission['form_key'] ?? '');
            $pageQuery = self::decodeJson((string) ($submission['page_query'] ?? ''));
            $runtime   = self::decodeJson((string) ($submission['runtime'] ?? ''));
            $runtimeQuery   = is_array($runtime['query'] ?? null) ? $runtime['query'] : [];
            $runtimeHeaders = is_array($runtime['headers'] ?? null) ? $runtime['headers'] : [];

            $payload['delivery_id'] = (string) $row['delivery_id'];

            $encoded = wp_json_encode($payload);
            if (!is_string($encoded) || $encoded === '') {
                // Unencodable payload (a filter introduced a bad value) — a
                // failed attempt that enters the normal retry chain; the
                // payload is rebuilt (and the filter re-run) next attempt.
                $context = self::logAttempt(
                    $row,
                    $submission,
                    $attempt,
                    $endpointUrl,
                    [],
                    '',
                    TransportResult::failure('Payload could not be JSON-encoded'),
                    false
                );
                self::rescheduleOrAbandon($rowId, $attempt, $submissionId, $context);
                return;
            }

            $composition   = self::contextFor($row, $submission, $attempt);
            $frozenUrl     = RequestFactory::buildUrl($endpointUrl, $formKey, $pageQuery, $runtimeQuery, $composition);
            $frozenHeaders = RequestFactory::buildHeaders($formKey, $runtimeHeaders, $composition);
            $frozenBody    = $encoded;

            $frozen = $wpdb->update(
                $table,
                [
                    'frozen_url'     => $frozenUrl,
                    'frozen_headers' => (string) wp_json_encode($frozenHeaders),
                    'frozen_body'    => $frozenBody,
                ],
                ['id' => $rowId],
                ['%s', '%s', '%s'],
                ['%d']
            );

            // 'queue_row' claims persistence, so it is only announced when the
            // UPDATE actually reported success. A failed freeze still sends this
            // attempt — the bytes are in hand — but the row is not yet frozen,
            // and saying otherwise would be a lie a listener could act on.
            if ($frozen !== false) {
                DeliveryContext::frozen($composition, 'queue_row', strlen($frozenBody));
            }
        } else {
            $frozenHeaders = self::decodeJson((string) ($row['frozen_headers'] ?? ''));
            if ($frozenUrl === '') {
                $frozenUrl = $endpointUrl;
            }
        }

        $sendContext = self::contextFor($row, $submission, $attempt);
        $sendHeaders = RequestFactory::withProtocolHeaders(
            $frozenHeaders,
            $endpointUrl,
            $frozenBody,
            (string) $row['delivery_id']
        );

        DeliveryContext::beforeSend($sendContext, $frozenUrl, $sendHeaders, $frozenBody);
        $result = Http::postJson($frozenUrl, $frozenBody, $sendHeaders, $sendContext);

        $context = self::logAttempt(
            $row,
            $submission,
            $attempt,
            $frozenUrl,
            $frozenHeaders,
            $frozenBody,
            $result,
            true
        );

        if ($result->ok) {
            $wpdb->delete($table, ['id' => $rowId], ['%d']);

            // Recorded only after the queue row is gone: while it still exists
            // the submission is legitimately "pending", and refreshing any
            // earlier would freeze that state in over the success.
            FormSubmissions::refreshDeliveryState($submissionId);

            // Announced last, so a listener that reads the submission sees the
            // recomputed delivery state rather than the pending one.
            DeliveryContext::succeeded($context);
            return;
        }

        self::rescheduleOrAbandon($rowId, $attempt, $submissionId, $context);
    }

    /**
     * Builds the public lifecycle context for one queued delivery.
     *
     * @param array<string, mixed>      $row        Queue row.
     * @param array<string, mixed>|null $submission Submission row, when loaded.
     * @param int                       $attempt    1-based attempt number.
     * @return DeliveryDetails
     */
    private static function contextFor(array $row, ?array $submission, int $attempt): DeliveryDetails
    {
        $endpointUrl = (string) $row['endpoint_url'];

        return DeliveryDetails::for(
            $endpointUrl,
            messageType: MessageType::FormSubmission,
            kind: self::kindFor($attempt),
            attempt: $attempt,
            deliveryId: (string) $row['delivery_id'],
            submissionId: (string) $row['submission_id'],
            conversionId: (string) ($submission['conversion_id'] ?? ''),
            formKey: (string) ($submission['form_key'] ?? ''),
        );
    }

    /**
     * The delivery kind for an attempt number.
     *
     * Attempt 1 is the queue's first send, which is 'immediate' — the queue
     * exists so the visitor's request does not wait, not because the delivery
     * is a retry of anything. Everything after it is.
     *
     * @param int $attempt 1-based attempt number.
     * @return DeliveryKind
     */
    private static function kindFor(int $attempt): DeliveryKind
    {
        return $attempt > 1 ? DeliveryKind::Retry : DeliveryKind::Immediate;
    }

    /**
     * Records one delivery attempt in the Activity Log.
     *
     * @param array<string, mixed>      $row        Queue row.
     * @param array<string, mixed>|null $submission Submission row (for provider/form metadata).
     * @param int                       $attempt    1-based attempt number.
     * @param string                    $requestUrl Final request URL.
     * @param array<string, string>     $headers    Frozen delivery headers.
     * @param string                    $body       Exact JSON body sent ('' when encoding failed).
     * @param TransportResult           $result     What the attempt came back with.
     * @param bool                      $transportAttempted Whether a request actually reached the wire.
     * @return DeliveryDetails The delivery details, for the caller's terminal action.
     */
    private static function logAttempt(
        array $row,
        ?array $submission,
        int $attempt,
        string $requestUrl,
        array $headers,
        string $body,
        TransportResult $result,
        bool $transportAttempted
    ): DeliveryDetails {
        $endpointUrl = (string) $row['endpoint_url'];

        $logged = DeliveryLog::log(new DeliveryLogEntry(
            result: $result,
            endpointUrl: $endpointUrl,
            endpointLabel: Options::endpointLabel($endpointUrl),
            deliveryId: (string) $row['delivery_id'],
            messageType: MessageType::FormSubmission,
            kind: self::kindFor($attempt),
            attempt: $attempt,
            requestUrl: $requestUrl,
            requestHeaders: $headers,
            requestData: $body,
            submissionId: (string) $row['submission_id'],
            conversionId: (string) ($submission['conversion_id'] ?? ''),
            formProvider: (string) ($submission['provider'] ?? ''),
            formName: (string) ($submission['form_name'] ?? ''),
        ));

        // Both attempt actions are fired here rather than at the call sites:
        // this method wraps exactly one DeliveryLog::log() and is reached from
        // every attempt the queue makes, so "one pair of actions per attempt"
        // holds by construction instead of by discipline.
        $context = DeliveryContext::attempted(
            self::contextFor($row, $submission, $attempt),
            $result,
            $transportAttempted
        );
        DeliveryContext::attemptLogged($context, $logged);

        return $context;
    }

    /**
     * After a failed attempt: schedules the next retry per the backoff
     * schedule, or abandons the delivery once every attempt is spent (each
     * attempt is already in the Activity Log, so nothing is silently lost).
     *
     * @param int                  $rowId        Queue row id.
     * @param int                  $attempt      The attempt number that just failed (1-based).
     * @param string               $submissionId The submission whose recorded delivery state to refresh.
     * @param DeliveryDetails|null $context      Delivery details for the terminal lifecycle action.
     * @return void
     */
    private static function rescheduleOrAbandon(
        int $rowId,
        int $attempt,
        string $submissionId = '',
        ?DeliveryDetails $context = null
    ): void {
        global $wpdb;

        $table  = self::tableName();
        $delays = AnalyticsDispatcher::retryDelays();

        // Attempt 1 is the initial send; delays[0] gates attempt 2, etc.
        if ($attempt > count($delays)) {
            $wpdb->delete($table, ['id' => $rowId], ['%d']);

            // The retry chain is spent and the queue row is gone, so the
            // submission settles out of "pending" into its final verdict.
            FormSubmissions::refreshDeliveryState($submissionId);

            // Genuinely terminal, unlike the analytics chain: this row will
            // never be retried, and only the Activity Log remembers it.
            if ($context !== null) {
                DeliveryContext::abandoned($context, 'retries_exhausted');
            }
            return;
        }

        $nextAt = time() + $delays[$attempt - 1];
        $next   = gmdate('Y-m-d H:i:s', $nextAt);

        $wpdb->update(
            $table,
            ['status' => 'pending', 'claim' => '', 'attempt' => $attempt, 'next_attempt_at' => $next],
            ['id' => $rowId],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );

        // Still pending, but the attempt counter the chip shows has moved.
        FormSubmissions::refreshDeliveryState($submissionId);

        if ($context !== null) {
            DeliveryContext::retryScheduled($context, $attempt + 1, $nextAt);
        }
    }

    /**
     * Number of rows still waiting for delivery.
     *
     * @return int
     */
    public static function pendingCount(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM " . self::tableName() . " WHERE status IN ('pending', 'sending')"
        );
    }

    /**
     * Pending queue rows for display on the Webhooks page (frozen bodies
     * excluded — no UI needs them).
     *
     * @param int $limit Maximum rows.
     * @return array<int, array<string, mixed>>
     */
    public static function pendingRows(int $limit = 20): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT id, submission_id, endpoint_url, delivery_id, status, attempt, next_attempt_at, created_at'
            . ' FROM ' . self::tableName()
            . " WHERE status IN ('pending', 'sending') ORDER BY next_attempt_at ASC LIMIT %d",
            $limit
        ), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Makes sure a worker cron event exists whenever queue rows are waiting.
     *
     * Called from enqueue-time scheduling failures' safety nets: plugin
     * activation, the daily cleanup, and every analytics dispatch run. This
     * is the recovery path for "the cron event was lost" and "scheduling
     * failed at enqueue time" — queued form leads must never be stranded.
     *
     * @return void
     */
    public static function ensureWorkerScheduled(): void
    {
        if (wp_next_scheduled(self::WORKER_HOOK) !== false) {
            return;
        }

        global $wpdb;

        $next = $wpdb->get_var(
            "SELECT MIN(next_attempt_at) FROM " . self::tableName() . " WHERE status IN ('pending', 'sending')"
        );

        if (!is_string($next) || $next === '') {
            return;
        }

        $due = (int) strtotime($next . ' UTC');
        self::scheduleWorker(max(time() + 5, $due));
    }

    /**
     * Schedules one worker run at $timestamp unless one is already
     * scheduled sooner.
     *
     * @param int $timestamp Unix timestamp for the run.
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

    /**
     * Sends an immediate form-submission TEST payload to one endpoint.
     *
     * Marked "test": true, never queued, never retried, and clearly
     * distinguishable in the Activity Log (kind 'test'). Uses the same
     * request pipeline as real deliveries — global headers and query
     * parameters included — so the test exercises what production will do.
     *
     * @param string $url Endpoint URL to test.
     * @return array{ok: bool, code: int, message: string}
     */
    public static function testEndpoint(string $url): array
    {
        $payload    = PayloadBuilder::formSubmissionTest();
        $deliveryId = md5($url . '|form-test|' . time() . '|' . wp_rand());
        $label      = Options::endpointLabel($url);

        $payload['delivery_id'] = $deliveryId;

        $encoded = wp_json_encode($payload);

        $context = DeliveryDetails::for(
            $url,
            messageType: MessageType::FormSubmission,
            kind: DeliveryKind::Test,
            attempt: 1,
            deliveryId: $deliveryId,
            endpointLabel: $label,
        );

        $requestUrl = RequestFactory::buildUrl($url, '', [], [], $context);
        $headers    = RequestFactory::buildHeaders('', [], $context);

        if (!is_string($encoded) || $encoded === '') {
            $encoded = '';
            $result  = TransportResult::failure('Payload could not be JSON-encoded');
        } else {
            $sendHeaders = RequestFactory::withProtocolHeaders($headers, $url, $encoded, $deliveryId);

            DeliveryContext::beforeSend($context, $requestUrl, $sendHeaders, $encoded);
            $result = Http::postJson($requestUrl, $encoded, $sendHeaders, $context);
        }

        $logged = DeliveryLog::log(new DeliveryLogEntry(
            result: $result,
            endpointUrl: $url,
            endpointLabel: $label,
            deliveryId: $deliveryId,
            messageType: MessageType::FormSubmission,
            kind: DeliveryKind::Test,
            requestUrl: $requestUrl,
            requestHeaders: $headers,
            requestData: $encoded,
            formProvider: 'test',
            formName: 'Convermetry Test Form',
        ));

        $context = DeliveryContext::attempted($context, $result, $encoded !== '');
        DeliveryContext::attemptLogged($context, $logged);

        // A test queues nothing and retries never, so there is no state to
        // commit first and no chain action to follow.
        if ($result->ok) {
            DeliveryContext::succeeded($context);
        }

        return $result->toTestSummary();
    }

    /**
     * Decodes a stored JSON column into an array, tolerating empty/invalid
     * values.
     *
     * @param string $json Stored JSON string.
     * @return array<string, mixed>
     */
    private static function decodeJson(string $json): array
    {
        if ($json === '' || !json_validate($json)) {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
