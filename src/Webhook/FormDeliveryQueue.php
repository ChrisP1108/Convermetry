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
        add_action(self::RECONCILE_HOOK, [self::class, 'reconcile'], 10, 2);
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
        $failedKeys = [];

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
                    $failedKeys[] = $endpointKey;
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
            failed: count($failedKeys),
            failedKeys: $failedKeys,
        );

        if (!$outcome->isComplete()) {
            // Verified evidence that a row the plugin needed does not exist.
            Errors::storage(
                'form_delivery_queue',
                'insert',
                'queue_row_not_persisted',
                $outcome->telemetry() + ['submission_id' => $submissionId]
            );

            // Repair exactly the endpoints whose rows are known to be absent.
            // Re-queuing anything broader could re-send a delivery a worker had
            // already completed and deleted.
            self::scheduleReconciliation($submissionId, $outcome->failedKeys);
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
     * Schedules a repair pass for queue rows that failed to persist.
     *
     * @param string       $submissionId Globally unique submission id.
     * @param list<string> $endpointKeys Endpoint keys known to have no row.
     * @return void
     */
    private static function scheduleReconciliation(string $submissionId, array $endpointKeys): void
    {
        if ($submissionId === '' || $endpointKeys === []) {
            return;
        }

        wp_schedule_single_event(time() + 30, self::RECONCILE_HOOK, [$submissionId, $endpointKeys]);

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
     * @param list<string> $endpointKeys Endpoint keys to repair.
     * @return void
     */
    public static function reconcile(string $submissionId, array $endpointKeys): void
    {
        global $wpdb;

        if ($submissionId === '' || $endpointKeys === []) {
            return;
        }

        $submission = FormSubmissions::getBySubmissionId($submissionId);

        // The submission was deleted (retention, or an erasure request) between
        // the failed enqueue and this pass. Erasure wins: nothing is re-created.
        if ($submission === null) {
            return;
        }

        $wanted   = array_flip($endpointKeys);
        $now      = gmdate('Y-m-d H:i:s');
        $repaired = 0;
        $stillBad = 0;

        foreach (Options::formEndpoints() as $endpoint) {
            $endpointKey = md5($endpoint->url);

            if (!isset($wanted[$endpointKey]) || self::rowExists($submissionId, $endpointKey)) {
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

            $stillBad++;
        }

        if ($stillBad > 0) {
            Errors::storage('form_delivery_queue', 'reconcile', 'queue_repair_failed', [
                'submission_id' => $submissionId,
                'repaired'      => $repaired,
                'failed'        => $stillBad,
            ]);
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
