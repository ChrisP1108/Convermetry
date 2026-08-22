<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Analytics\ReportQueryException;
use Convermetry\Analytics\Reports;
use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Settings\Options;
use Convermetry\Support\Http;

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
    private const TABLE = 'cvm_delivery_queue';

    /** Option key storing the installed schema version. */
    private const DB_VERSION_OPTION = 'cvm_queue_db_version';

    /** Current schema version; bump when the CREATE TABLE below changes. */
    private const DB_VERSION = '1.1.0';

    /** Cron hook name for the queue worker. */
    public const WORKER_HOOK = 'cvm_process_form_queue';

    /** Maximum queue rows one worker pass may claim. */
    private const BATCH_SIZE = 10;

    /** Wall-clock seconds budgeted per worker pass. */
    private const TIME_BUDGET = 45;

    /** Seconds after which a 'sending' claim is considered dead and reclaimed. */
    private const CLAIM_TIMEOUT = 10 * MINUTE_IN_SECONDS;

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
            endpoint_id VARCHAR(36) NOT NULL DEFAULT '',
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
            'id', 'submission_row', 'submission_id', 'endpoint_key', 'endpoint_id', 'endpoint_url',
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
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::createTable();
        }
    }

    /**
     * Registers the worker cron callback.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action(self::WORKER_HOOK, [self::class, 'processDue']);
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
     * @param int    $submissionRow Row id in the form submissions table.
     * @param string $submissionId  Globally unique submission id.
     * @return int Number of endpoint deliveries queued.
     */
    public static function enqueue(int $submissionRow, string $submissionId): int
    {
        global $wpdb;

        $endpoints = Options::formEndpoints();
        if ($endpoints === []) {
            return 0;
        }

        $now    = gmdate('Y-m-d H:i:s');
        $queued = 0;

        foreach ($endpoints as $endpoint) {
            $inserted = $wpdb->query($wpdb->prepare(
                'INSERT IGNORE INTO ' . self::tableName()
                . ' (submission_row, submission_id, endpoint_key, endpoint_id, endpoint_url, delivery_id,'
                . " status, attempt, next_attempt_at, created_at)"
                . " VALUES (%d, %s, %s, %s, %s, %s, 'pending', 0, %s, %s)",
                $submissionRow,
                $submissionId,
                md5($endpoint['url']),
                // The endpoint's permanent identity, captured at enqueue. The
                // signature is resolved from this rather than from the URL, so
                // editing the URL mid-retry still signs with this endpoint's
                // own secret instead of falling through to the shared one.
                $endpoint['id'],
                $endpoint['url'],
                self::deliveryId($endpoint['url'], $submissionId),
                $now,
                $now
            ));

            if ($inserted === 1) {
                $queued++;
            }
        }

        if ($queued > 0) {
            self::scheduleWorker(time() + 1);

            // Fire WP-Cron in the background right now (non-blocking loopback
            // request) so the delivery typically leaves within seconds instead
            // of waiting for the next organic page load.
            if (function_exists('spawn_cron')) {
                spawn_cron();
            }
        }

        return $queued;
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

        $submission = FormSubmissions::get((int) $row['submission_row'])
            ?? FormSubmissions::getBySubmissionId($submissionId);

        // The submission row is gone (aged out past retention, or the plugin
        // data was manually cleared) and nothing was frozen yet — there is
        // nothing left to deliver.
        if ($submission === null && empty($row['frozen_body'])) {
            $wpdb->delete($table, ['id' => $rowId], ['%d']);
            return;
        }

        $frozenBody    = (string) ($row['frozen_body'] ?? '');
        $frozenUrl     = (string) ($row['frozen_url'] ?? '');
        $frozenHeaders = [];

        if ($frozenBody === '' && $submission !== null) {
            // First attempt: build and freeze the exact request this row
            // will (re-)send until it is acknowledged or abandoned.
            if (!isset($payloadCache[$submissionId])) {
                $submission                  = self::enrichContext($submission);
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
                self::logAttempt($row, $submission, $attempt, $endpointUrl, [], '', [
                    'ok' => false, 'code' => 0, 'message' => 'Payload could not be JSON-encoded', 'body' => '',
                ]);
                self::rescheduleOrAbandon($rowId, $attempt);
                return;
            }

            $frozenUrl     = RequestFactory::buildUrl($endpointUrl, $formKey, $pageQuery, $runtimeQuery);
            $frozenHeaders = RequestFactory::buildHeaders($formKey, $runtimeHeaders);
            $frozenBody    = $encoded;

            $wpdb->update(
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
        } else {
            $frozenHeaders = self::decodeJson((string) ($row['frozen_headers'] ?? ''));
            if ($frozenUrl === '') {
                $frozenUrl = $endpointUrl;
            }
        }

        // Built once and used for BOTH the request and the log, so the Activity
        // Log shows the headers actually sent — including User-Agent,
        // Idempotency-Key, and the signature — rather than the pre-protocol set.
        $sentHeaders = RequestFactory::withProtocolHeaders(
            $frozenHeaders,
            (string) ($row['endpoint_id'] ?? ''),
            $frozenBody,
            (string) $row['delivery_id'],
            $endpointUrl
        );

        $result = Http::postJson($frozenUrl, $frozenBody, $sentHeaders);

        self::logAttempt($row, $submission, $attempt, $frozenUrl, $sentHeaders, $frozenBody, $result);

        if ($result['ok']) {
            $wpdb->delete($table, ['id' => $rowId], ['%d']);
            return;
        }

        self::rescheduleOrAbandon($rowId, $attempt);
    }

    /**
     * Enriches a submission's analytics context with the lightweight session
     * summary (pageview count, session start, recent pages) — computed in
     * the background worker or a synchronous dispatch, and persisted so a
     * retry (or a second endpoint's freeze in a later pass) sees identical
     * context even after the underlying events age out.
     *
     * @param array<string, mixed> $submission Submission row.
     * @return array<string, mixed> The submission row, with 'context' enriched.
     */
    public static function enrichContext(array $submission): array
    {
        $context   = self::decodeJson((string) ($submission['context'] ?? ''));
        $sessionId = (string) ($submission['session_id'] ?? '');

        if ($sessionId !== '' && !isset($context['pageview_count'])) {
            try {
                $context = array_merge($context, Reports::sessionSummary($sessionId));
                $submission['context'] = (string) wp_json_encode($context);

                global $wpdb;
                $wpdb->update(
                    FormSubmissions::tableName(),
                    ['context' => $submission['context']],
                    ['id' => (int) $submission['id']],
                    ['%s'],
                    ['%d']
                );
            } catch (ReportQueryException) {
                // Enrichment is best-effort: a failed summary query must not
                // block delivering the lead itself.
            }
        }

        return $submission;
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
     * @param array<string, mixed>      $result     Http::postJson() result.
     * @return void
     */
    private static function logAttempt(
        array $row,
        ?array $submission,
        int $attempt,
        string $requestUrl,
        array $headers,
        string $body,
        array $result
    ): void {
        $endpointUrl = (string) $row['endpoint_url'];

        DeliveryLog::log([
            'ok'              => !empty($result['ok']),
            'endpoint_url'    => $endpointUrl,
            'endpoint_label'  => Options::endpointLabel($endpointUrl),
            'delivery_id'     => (string) $row['delivery_id'],
            'message_type'    => 'form_submission',
            'kind'            => $attempt > 1 ? 'retry' : 'immediate',
            'attempt'         => $attempt,
            'submission_id'   => (string) $row['submission_id'],
            'conversion_id'   => (string) ($submission['conversion_id'] ?? ''),
            'form_provider'   => (string) ($submission['provider'] ?? ''),
            'form_name'       => (string) ($submission['form_name'] ?? ''),
            // Frozen on the submission row at insert time — a retry hours
            // later must log the id that was actually delivered, not what
            // per-form settings happen to say now.
            'form_id'         => (string) ($submission['form_id'] ?? ''),
            'request_url'     => $requestUrl,
            'request_headers' => $headers,
            'request_data'    => $body,
            'response_code'   => (int) ($result['code'] ?? 0),
            'response_data'   => (string) ($result['body'] ?? ''),
            'message'         => (string) ($result['message'] ?? ''),
        ]);
    }

    /**
     * After a failed attempt: schedules the next retry per the backoff
     * schedule, or abandons the delivery once every attempt is spent (each
     * attempt is already in the Activity Log, so nothing is silently lost).
     *
     * @param int $rowId   Queue row id.
     * @param int $attempt The attempt number that just failed (1-based).
     * @return void
     */
    private static function rescheduleOrAbandon(int $rowId, int $attempt): void
    {
        global $wpdb;

        $table  = self::tableName();
        $delays = AnalyticsDispatcher::retryDelays();

        // Attempt 1 is the initial send; delays[0] gates attempt 2, etc.
        if ($attempt > count($delays)) {
            $wpdb->delete($table, ['id' => $rowId], ['%d']);
            return;
        }

        $next = gmdate('Y-m-d H:i:s', time() + $delays[$attempt - 1]);

        $wpdb->update(
            $table,
            ['status' => 'pending', 'claim' => '', 'attempt' => $attempt, 'next_attempt_at' => $next],
            ['id' => $rowId],
            ['%s', '%s', '%d', '%s'],
            ['%d']
        );
    }

    /**
     * Number of rows still waiting for delivery.
     *
     * @return int
     */
    /**
     * Pending/sending deliveries queued for one endpoint.
     *
     * Counted by the endpoint's permanent id, so an endpoint whose URL was
     * edited still reports its own backlog. Rows queued before endpoint ids
     * existed carry an empty endpoint_id and fall back to the URL.
     *
     * @param string $endpointId  Endpoint's permanent id.
     * @param string $endpointUrl Endpoint URL, for pre-id rows.
     * @return int
     */
    public static function pendingCountForEndpoint(string $endpointId, string $endpointUrl): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::tableName()
            . " WHERE status IN ('pending', 'sending')"
            . ' AND (endpoint_id = %s OR (endpoint_id = %s AND endpoint_url = %s))',
            $endpointId,
            '',
            $endpointUrl
        ));
    }

    /**
     * Deletes every queued delivery belonging to one endpoint.
     *
     * Called when an administrator removes an endpoint: the destination is
     * gone, so its undelivered leads cannot be sent anywhere. Destructive, and
     * therefore only ever invoked behind an explicit confirmation.
     *
     * @param string $endpointId  Endpoint's permanent id.
     * @param string $endpointUrl Endpoint URL, for pre-id rows.
     * @return int Rows discarded.
     */
    public static function discardForEndpoint(string $endpointId, string $endpointUrl): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::tableName()
            . ' WHERE endpoint_id = %s OR (endpoint_id = %s AND endpoint_url = %s)',
            $endpointId,
            '',
            $endpointUrl
        ));
    }

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
        $payload = PayloadBuilder::formSubmissionTest();
        $payload['delivery_id'] = md5($url . '|form-test|' . time() . '|' . wp_rand());

        $encoded = wp_json_encode($payload);

        $requestUrl = RequestFactory::buildUrl($url);
        $headers    = RequestFactory::buildHeaders();

        if (!is_string($encoded) || $encoded === '') {
            $encoded = '';
            $result  = ['ok' => false, 'code' => 0, 'message' => 'Payload could not be JSON-encoded', 'body' => ''];
        } else {
            $result = Http::postJson(
                $requestUrl,
                $encoded,
                RequestFactory::withProtocolHeaders($headers, (string) (Options::endpointByUrl($url)['id'] ?? ''), $encoded, (string) $payload['delivery_id'], $url)
            );
        }

        DeliveryLog::log([
            'ok'              => $result['ok'],
            'endpoint_url'    => $url,
            'endpoint_label'  => Options::endpointLabel($url),
            'delivery_id'     => (string) $payload['delivery_id'],
            'message_type'    => 'form_submission',
            'kind'            => 'test',
            'form_provider'   => 'test',
            'form_name'       => 'Convermetry Test Form',
            'request_url'     => $requestUrl,
            'request_headers' => $headers,
            'request_data'    => $encoded,
            'response_code'   => $result['code'],
            'response_data'   => $result['body'],
            'message'         => $result['message'],
        ]);

        return ['ok' => $result['ok'], 'code' => $result['code'], 'message' => $result['message']];
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
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
