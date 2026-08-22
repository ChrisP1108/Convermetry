<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Settings\Options;

/**
 * Persists and retrieves webhook delivery log entries (the Activity Log) in
 * a custom table.
 *
 * One row = one delivery attempt — scheduled analytics report, immediate
 * form submission, retry, or test — storing the exact JSON payload that was
 * sent and the response the endpoint returned, so the Activity Log admin
 * page and the read-only deliveries REST API can show precisely what left
 * the site and what came back. Both bodies are capped at 64 KB so a
 * misbehaving endpoint cannot balloon the table.
 *
 * Activity types are stored as NORMALIZED columns — message_type
 * ('analytics_report' / 'form_submission') and kind ('scheduled' /
 * 'immediate' / 'retry' / 'test') plus the attempt number — never encoded
 * into a display label that would need string parsing later.
 *
 * Security: values of sensitive-looking keys in JSON bodies are redacted
 * before storage, stored request headers have credential-bearing values
 * masked, and the visitor's submission_data can be excluded from the log
 * entirely via a setting. The 'convermetry_delivery_log_row' filter runs
 * before each row is written for site-specific redaction (return false to
 * skip logging an attempt).
 *
 * Rows older than the plugin's retention window are pruned by the same
 * daily cron that trims the analytics events table.
 */
final class DeliveryLog
{
    /** Table name without the wpdb prefix. */
    private const TABLE = 'cvm_webhook_deliveries';

    /** Option key storing the installed schema version. */
    private const DB_VERSION_OPTION = 'cvm_delivery_db_version';

    /** Current schema version; bump when the CREATE TABLE below changes. */
    private const DB_VERSION = '1.1.0';

    /** Progress/state of the one-time stored-row migration. Never autoloaded. */
    private const MIGRATION_OPTION = 'cvm_delivery_log_migration';

    /**
     * Migration contract version. Bumping this re-runs
     * {@see self::migrateStoredRows()} over the table from the start, so raise
     * it only when stored rows genuinely need rewriting again.
     */
    private const MIGRATION_VERSION = '1.1.0';

    /**
     * Rows rewritten per migration batch. Much smaller than CLEANUP_CHUNK
     * because each row is read, re-encoded, and written back — and carries two
     * potentially-64 KB bodies — rather than merely deleted.
     */
    private const MIGRATION_BATCH = 200;

    /** Maximum stored bytes for the request payload and response body. */
    public const MAX_BODY_BYTES = 65536;

    /** Valid message_type values. */
    public const MESSAGE_TYPES = ['analytics_report', 'form_submission'];

    /** Valid kind values. */
    public const KINDS = ['scheduled', 'immediate', 'retry', 'test'];

    /** Rows deleted per statement during retention cleanup. */
    private const CLEANUP_CHUNK = 5000;

    /** Maximum delete chunks per cron run, so one request can't run indefinitely. */
    private const CLEANUP_MAX_CHUNKS = 20;

    /**
     * Wall-clock seconds budgeted for one purgeOld() run, alongside the
     * chunk-count cap. These rows carry full request/response bodies
     * (LONGTEXT), so a slow DELETE is plausible.
     */
    private const CLEANUP_TIME_BUDGET = 20;

    /**
     * Substrings of keys whose values are redacted from stored JSON bodies
     * and stored request headers. Endpoint responses sometimes echo
     * debugging data or secrets, request headers carry configured
     * Authorization values, and form payloads can contain credential-like
     * fields — the log must not become a credential store.
     *
     * Keys are canonicalized by {@see self::isSensitiveKey()} before matching
     * (lowercased, with '-' and spaces folded to '_'), so one underscored
     * pattern covers every spelling a header or a form-field LABEL can take:
     * 'api_key' matches X-API-Key, "API Key", and api-key alike. That folding
     * is why several obvious names are absent here rather than missing —
     * 'proxy_authorization' contains 'authorization', 'set_cookie' contains
     * 'cookie', and 'x_api_key' contains 'api_key'.
     *
     * Deliberately NOT included: a bare 'auth' pattern, which would redact
     * legitimate fields named 'author'.
     *
     * @var string[]
     */
    private const SENSITIVE_KEY_PATTERNS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'credential', 'private_key', 'access_token',
        'refresh_token', 'client_secret', 'cookie',
    ];

    /**
     * Returns the fully-prefixed deliveries table name.
     *
     * @return string
     */
    public static function tableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE;
    }

    /**
     * Creates or updates the deliveries table (idempotent via dbDelta).
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
            success TINYINT(1) NOT NULL DEFAULT 0,
            endpoint_url TEXT NOT NULL,
            endpoint_label VARCHAR(100) NOT NULL DEFAULT '',
            delivery_id VARCHAR(40) NOT NULL DEFAULT '',
            message_type VARCHAR(20) NOT NULL DEFAULT '',
            kind VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            attempt TINYINT UNSIGNED NOT NULL DEFAULT 0,
            submission_id VARCHAR(40) NOT NULL DEFAULT '',
            conversion_id VARCHAR(100) NOT NULL DEFAULT '',
            form_provider VARCHAR(32) NOT NULL DEFAULT '',
            form_name VARCHAR(191) NOT NULL DEFAULT '',
            form_id VARCHAR(191) NOT NULL DEFAULT '',
            request_url TEXT NOT NULL,
            request_headers LONGTEXT NOT NULL,
            request_data LONGTEXT NOT NULL,
            response_code INT NOT NULL DEFAULT 0,
            response_data LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY success (success),
            KEY created_at (created_at),
            KEY endpoint_url (endpoint_url(100)),
            KEY message_type (message_type),
            KEY form_provider (form_provider),
            KEY form_id (form_id),
            KEY submission_id (submission_id)
        ) {$charset};";

        dbDelta($sql);

        // Only record the schema version once the table verifiably carries
        // every expected column — a failed or partial dbDelta run must be
        // retried on the next load, not silently marked complete.
        $expected = [
            'id', 'success', 'endpoint_url', 'endpoint_label', 'delivery_id',
            'message_type', 'kind', 'attempt', 'submission_id', 'conversion_id',
            'form_provider', 'form_name', 'form_id', 'request_url', 'request_headers',
            'request_data', 'response_code', 'response_data', 'created_at',
        ];
        if (DatabaseManager::tableHasColumns($table, $expected)) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }

    /**
     * Creates the table when the stored schema version differs, so plugin
     * updates apply the schema without a re-activation.
     *
     * @return void
     */
    public static function maybeCreateTable(): void
    {
        if (get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION) {
            self::createTable();
        }
    }

    /**
     * Inserts one delivery-attempt row.
     *
     * Transport errors (no HTTP response at all) are stored as a JSON object
     * {"error": "..."} in response_data, so the UI and API can distinguish
     * "endpoint said no" from "endpoint unreachable".
     *
     * Expected $entry keys (missing keys default sensibly):
     *  - endpoint_url, endpoint_label, delivery_id, message_type, kind,
     *    attempt, submission_id, conversion_id, form_provider, form_name,
     *    request_url, request_headers (array), request_data (string JSON),
     *    ok (bool), response_code (int), response_data (string),
     *    message (string, used for transport errors).
     *
     * @param array<string, mixed> $entry Delivery attempt details.
     * @return void
     */
    public static function log(array $entry): void
    {
        global $wpdb;

        $ok           = !empty($entry['ok']);
        $code         = (int) ($entry['response_code'] ?? 0);
        $responseBody = (string) ($entry['response_data'] ?? '');
        $message      = (string) ($entry['message'] ?? '');
        $messageType  = (string) ($entry['message_type'] ?? '');
        $kind         = (string) ($entry['kind'] ?? 'scheduled');

        if ($code === 0 && $responseBody === '') {
            $responseBody = (string) wp_json_encode(['error' => $message]);
        }

        if (!in_array($messageType, self::MESSAGE_TYPES, true)) {
            $messageType = '';
        }
        if (!in_array($kind, self::KINDS, true)) {
            $kind = 'scheduled';
        }

        $requestData = (string) ($entry['request_data'] ?? '');

        // Optionally strip the visitor's field values from the stored copy
        // of a form-submission payload — delivery metadata remains, the
        // second copy of the lead data does not. The payload actually SENT
        // is unaffected.
        if ($messageType === 'form_submission' && !Options::logSubmissionData()) {
            $requestData = self::stripSubmissionData($requestData);
        }

        $row = [
            'success'         => $ok ? 1 : 0,
            'endpoint_url'    => (string) ($entry['endpoint_url'] ?? ''),
            'endpoint_label'  => mb_substr((string) ($entry['endpoint_label'] ?? ''), 0, 100),
            'delivery_id'     => (string) ($entry['delivery_id'] ?? ''),
            'message_type'    => $messageType,
            'kind'            => $kind,
            'attempt'         => max(0, min(255, (int) ($entry['attempt'] ?? 0))),
            'submission_id'   => (string) ($entry['submission_id'] ?? ''),
            'conversion_id'   => (string) ($entry['conversion_id'] ?? ''),
            'form_provider'   => mb_substr((string) ($entry['form_provider'] ?? ''), 0, 32),
            'form_name'       => mb_substr((string) ($entry['form_name'] ?? ''), 0, 191),
            'form_id'         => mb_substr((string) ($entry['form_id'] ?? ''), 0, 191),
            'request_url'     => (string) ($entry['request_url'] ?? ($entry['endpoint_url'] ?? '')),
            'request_headers' => (string) wp_json_encode(self::redactHeaders((array) ($entry['request_headers'] ?? []))),
            'request_data'    => self::capBody(self::redactSensitiveJson($requestData)),
            'response_code'   => $code,
            'response_data'   => self::capBody(self::redactSensitiveJson($responseBody)),
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ];

        /**
         * Filters a delivery-log row just before it is written — e.g. for
         * site-specific redaction of additional fields in the stored payload.
         *
         * @param array<string, mixed>|false $row The row about to be stored;
         *                                        return false to skip logging
         *                                        this attempt.
         */
        $row = apply_filters('convermetry_delivery_log_row', $row);
        if (!is_array($row)) {
            return;
        }

        $wpdb->insert(
            self::tableName(),
            $row,
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );
    }

    /**
     * Replaces a form-submission payload's submission_data with a
     * placeholder before storage, when the admin has disabled storing lead
     * data in the Activity Log.
     *
     * @param string $requestData JSON request payload.
     * @return string
     */
    private static function stripSubmissionData(string $requestData): string
    {
        if ($requestData === '') {
            return $requestData;
        }

        $decoded = json_decode($requestData, true);
        if (!is_array($decoded)) {
            return $requestData;
        }

        if (isset($decoded['form_submission']) && is_array($decoded['form_submission'])) {
            $decoded['form_submission']['submission_data'] = '[NOT STORED — disabled in Settings]';
        }

        return (string) wp_json_encode($decoded);
    }

    /**
     * Masks the values of credential-bearing request headers for storage —
     * the exact headers still travel on the wire and in frozen retries, but
     * the log must never hold a configured Authorization value in the clear.
     *
     * @param array<string, string> $headers Request headers as sent.
     * @return array<string, string>
     */
    public static function redactHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[(string) $name] = self::isSensitiveKey((string) $name)
                ? '[REDACTED]'
                : (string) $value;
        }

        return $out;
    }

    /**
     * Whether a header name or JSON key names a value that must not be stored.
     *
     * The key is canonicalized before matching: lowercased, with '-' and
     * spaces folded to '_'. Without that folding 'X-API-Key' matches neither
     * 'api_key' nor 'apikey' and a common credential header is stored in the
     * clear; the same applies to form-field labels like "API Key", which
     * providers such as Gravity Forms and Elementor use verbatim as payload
     * keys.
     *
     * @param string $key Header name or JSON object key.
     * @return bool
     */
    private static function isSensitiveKey(string $key): bool
    {
        $canonical = str_replace(['-', ' '], '_', strtolower($key));

        foreach (self::SENSITIVE_KEY_PATTERNS as $pattern) {
            if (str_contains($canonical, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redacts values of sensitive-looking keys in a JSON body.
     *
     * Only well-formed JSON objects/arrays are rewritten — non-JSON bodies
     * are stored as-is (there is no reliable way to find secrets in free
     * text, and mangling the body would hide what the endpoint actually
     * said). Key matching is case-insensitive substring matching against
     * {@see self::SENSITIVE_KEY_PATTERNS}, applied recursively.
     *
     * @param string $body Raw body.
     * @return string The body with sensitive values replaced by [REDACTED].
     */
    private static function redactSensitiveJson(string $body): string
    {
        // Strip a UTF-8 BOM and leading whitespace before sniffing — valid
        // JSON is allowed to start with either, and skipping redaction just
        // because of a leading newline would store secrets verbatim.
        $json = ltrim($body, " \t\n\r\0\x0B");
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = ltrim(substr($json, 3), " \t\n\r\0\x0B");
        }

        if ($json === '' || ($json[0] !== '{' && $json[0] !== '[')) {
            return $body;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $body;
        }

        $redact = static function (array $data) use (&$redact): array {
            foreach ($data as $key => $value) {
                if (is_string($key) && self::isSensitiveKey($key)) {
                    $data[$key] = '[REDACTED]';
                    continue;
                }
                if (is_array($value)) {
                    $data[$key] = $redact($value);
                }
            }

            return $data;
        };

        return (string) wp_json_encode($redact($decoded));
    }

    /**
     * Returns a single page of delivery rows, newest-first, with optional filters.
     *
     * @param int                   $page    1-based page number.
     * @param int                   $perPage Rows per page (clamp in callers).
     * @param array<string, string> $filters status, year, month, search, endpoint,
     *                                       message_type, provider, form_name.
     * @return array<int, array<string, mixed>>
     */
    public static function getLogsPaginated(int $page, int $perPage, array $filters = []): array
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
     * @param array<string, string> $filters Same keys as {@see getLogsPaginated()}.
     * @return int
     */
    public static function getLogCount(array $filters = []): int
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
     * Exports iterate: start with $beforeId = PHP_INT_MAX, then pass the
     * last row's id back in until fewer than $limit rows return. Keyset
     * pagination (WHERE id < x) stays fast on any table size — and loading
     * everything at once, with two LONGTEXT bodies per row, could exhaust
     * PHP memory on a busy site.
     *
     * @param int $beforeId Only rows with an id strictly below this value.
     * @param int $limit    Maximum rows to return.
     * @return array<int, array<string, mixed>>
     */
    public static function getLogsChunk(int $beforeId, int $limit): array
    {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::tableName() . ' WHERE id < %d ORDER BY id DESC LIMIT %d',
                $beforeId,
                $limit
            ),
            ARRAY_A
        );

        return is_array($rows) ? $rows : [];
    }

    /**
     * Returns the distinct calendar years and months that have log entries,
     * for the Activity Log page's filter dropdowns.
     *
     * @param array<string, string> $filters Active filters (status, endpoint, message_type, provider).
     * @return array{years: list<string>, months: list<string>}
     */
    public static function getDistinctDates(array $filters = []): array
    {
        global $wpdb;

        [$where, $values] = self::buildWhereClause(array_diff_key($filters, ['year' => 0, 'month' => 0, 'search' => 0]));

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
     * Returns the distinct endpoint URLs present in the log, for the endpoint
     * filter dropdown.
     *
     * @return list<string>
     */
    public static function getDistinctEndpoints(): array
    {
        global $wpdb;

        $rows = $wpdb->get_col(
            'SELECT DISTINCT endpoint_url FROM ' . self::tableName() . " WHERE endpoint_url <> '' ORDER BY endpoint_url ASC"
        );

        return is_array($rows) ? array_values(array_filter($rows, 'is_string')) : [];
    }

    /**
     * Returns the distinct form providers present in the log, for the
     * provider filter dropdown.
     *
     * @return list<string>
     */
    public static function getDistinctProviders(): array
    {
        global $wpdb;

        $rows = $wpdb->get_col(
            'SELECT DISTINCT form_provider FROM ' . self::tableName() . " WHERE form_provider <> '' ORDER BY form_provider ASC"
        );

        return is_array($rows) ? array_values(array_filter($rows, 'is_string')) : [];
    }

    /**
     * Returns the distinct form names present in the log, for the form
     * filter dropdown.
     *
     * @return list<string>
     */
    public static function getDistinctFormNames(): array
    {
        global $wpdb;

        $rows = $wpdb->get_col(
            'SELECT DISTINCT form_name FROM ' . self::tableName() . " WHERE form_name <> '' ORDER BY form_name ASC LIMIT 200"
        );

        return is_array($rows) ? array_values(array_filter($rows, 'is_string')) : [];
    }

    /**
     * Deletes a single log row by its primary key.
     *
     * @param int $id The row ID to delete.
     * @return void
     */
    public static function deleteLog(int $id): void
    {
        global $wpdb;

        $wpdb->delete(self::tableName(), ['id' => $id], ['%d']);
    }

    /**
     * Removes all rows (TRUNCATE also resets the auto-increment counter).
     *
     * @return void
     */
    public static function clearLogs(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::tableName());
    }

    /**
     * Deletes rows older than the plugin's retention window. Runs on the same
     * daily cron as the events-table cleanup, in bounded chunks.
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
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE created_at < %s LIMIT %d",
                    $cutoff,
                    self::CLEANUP_CHUNK
                )
            );
        } while (
            is_int($deleted) && $deleted === self::CLEANUP_CHUNK
            && ++$runs < self::CLEANUP_MAX_CHUNKS
            && microtime(true) < $deadline
        );
    }

    /**
     * Brings rows written before this release up to the current storage
     * contract, in bounded batches on the daily cleanup cron.
     *
     * Two things are repaired per row:
     *
     *  1. Redaction. Earlier releases redacted only response_data, and matched
     *     header names without folding '-'/' ' to '_' — so stored payloads can
     *     hold form fields named 'password' or 'API Key', and stored headers
     *     can hold an X-API-Key value in the clear. Those rows remain readable
     *     through the admin view, the CSV and JSON exports, and the REST API,
     *     so fixing log() forward is not sufficient.
     *  2. form_id. The column is new; the value is recovered from the stored
     *     payload rather than from current settings, so it matches what was
     *     actually delivered. It survives in request_data even when submission
     *     logging is disabled, because stripSubmissionData() replaces only the
     *     submission_data branch.
     *
     * Bounded like {@see self::purgeOld()} (batch cap plus wall-clock budget)
     * and resumable via a stored cursor, since one request cannot rewrite a
     * long-retention table carrying two LONGTEXT bodies per row. The id
     * high-water mark is pinned when the run starts: rows written afterwards
     * are already correct, and without the pin a busy site could keep the
     * migration from ever reaching the end. Re-running is a no-op — each row
     * is only written when redaction or backfill actually changes it.
     *
     * @return void
     */
    public static function migrateStoredRows(): void
    {
        global $wpdb;

        $state = get_option(self::MIGRATION_OPTION, []);
        $state = is_array($state) ? $state : [];

        if (($state['version'] ?? '') === self::MIGRATION_VERSION && !empty($state['done'])) {
            return;
        }

        $table = self::tableName();

        // Backfill reads and writes form_id, so the schema change must have
        // landed first; retry on the next cron run if it has not.
        if (!DatabaseManager::tableHasColumns($table, ['form_id'])) {
            return;
        }

        if (($state['version'] ?? '') !== self::MIGRATION_VERSION) {
            $state = [
                'version'    => self::MIGRATION_VERSION,
                'high_water' => (int) $wpdb->get_var("SELECT MAX(id) FROM {$table}"),
                'cursor'     => 0,
                'done'       => false,
            ];
            update_option(self::MIGRATION_OPTION, $state, false);
        }

        $highWater = (int) ($state['high_water'] ?? 0);
        $cursor    = (int) ($state['cursor'] ?? 0);

        if ($highWater <= 0 || $cursor >= $highWater) {
            $state['done'] = true;
            update_option(self::MIGRATION_OPTION, $state, false);
            return;
        }

        $deadline = microtime(true) + self::CLEANUP_TIME_BUDGET;
        $runs     = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, form_id, request_headers, request_data FROM {$table}"
                    . ' WHERE id > %d AND id <= %d ORDER BY id ASC LIMIT %d',
                    $cursor,
                    $highWater,
                    self::MIGRATION_BATCH
                ),
                ARRAY_A
            );

            if (!is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $cursor = max($cursor, (int) $row['id']);
                $update = [];

                $data     = (string) $row['request_data'];
                $redacted = self::capBody(self::redactSensitiveJson($data));
                if ($redacted !== $data) {
                    $update['request_data'] = $redacted;
                }

                $headers = json_decode((string) $row['request_headers'], true);
                if (is_array($headers)) {
                    $reHeaders = (string) wp_json_encode(self::redactHeaders($headers));
                    if ($reHeaders !== (string) $row['request_headers']) {
                        $update['request_headers'] = $reHeaders;
                    }
                }

                if ((string) $row['form_id'] === '') {
                    $formId = self::formIdFromPayload($data);
                    if ($formId !== '') {
                        $update['form_id'] = mb_substr($formId, 0, 191);
                    }
                }

                if ($update !== []) {
                    $wpdb->update(
                        $table,
                        $update,
                        ['id' => (int) $row['id']],
                        array_fill(0, count($update), '%s'),
                        ['%d']
                    );
                }
            }

            // Persist progress after each batch, so an interrupted run resumes
            // where it stopped instead of rewriting rows it already fixed.
            $state['cursor'] = $cursor;
            update_option(self::MIGRATION_OPTION, $state, false);
        } while (
            count($rows) === self::MIGRATION_BATCH
            && $cursor < $highWater
            && ++$runs < self::CLEANUP_MAX_CHUNKS
            && microtime(true) < $deadline
        );

        // Completion is recorded only once the cursor has actually crossed the
        // pinned high-water mark — never merely because a batch ended.
        if ($cursor >= $highWater) {
            $state['done'] = true;
            update_option(self::MIGRATION_OPTION, $state, false);
        }
    }

    /**
     * Recovers the delivered form_id from a stored form-submission payload.
     *
     * @param string $requestData Stored request payload.
     * @return string The form id, or '' when the payload holds none.
     */
    private static function formIdFromPayload(string $requestData): string
    {
        if ($requestData === '') {
            return '';
        }

        $decoded = json_decode($requestData, true);
        if (!is_array($decoded) || !isset($decoded['form_submission']['form_id'])) {
            return '';
        }

        $formId = $decoded['form_submission']['form_id'];

        return is_scalar($formId) ? (string) $formId : '';
    }

    /**
     * Truncates a body to the storage cap, marking the cut.
     *
     * The limit is enforced in BYTES (plain substr), not characters — the
     * cap exists to bound storage. A multibyte character sliced at the
     * boundary may be left incomplete; acceptable for a diagnostic log.
     *
     * @param string $body Raw body.
     * @return string At most MAX_BODY_BYTES bytes.
     */
    private static function capBody(string $body): string
    {
        if (strlen($body) <= self::MAX_BODY_BYTES) {
            return $body;
        }

        $marker = ' [TRUNCATED]';

        return substr($body, 0, self::MAX_BODY_BYTES - strlen($marker)) . $marker;
    }

    /**
     * Builds a SQL WHERE clause and its ordered values from the filters.
     *
     * @param array<string, string> $filters status ('success'|'error'), year,
     *                                       month, search, endpoint,
     *                                       message_type, provider, form_name.
     * @return array{0: string, 1: list<mixed>}
     */
    private static function buildWhereClause(array $filters): array
    {
        global $wpdb;

        $status      = (string) ($filters['status'] ?? '');
        $filterYear  = (string) ($filters['year'] ?? '');
        $filterMonth = (string) ($filters['month'] ?? '');
        $search      = (string) ($filters['search'] ?? '');
        $endpoint    = (string) ($filters['endpoint'] ?? '');
        $messageType = (string) ($filters['message_type'] ?? '');
        $provider    = (string) ($filters['provider'] ?? '');
        $formName    = (string) ($filters['form_name'] ?? '');
        $formId      = (string) ($filters['form_id'] ?? '');
        $after       = (string) ($filters['after'] ?? '');
        $before      = (string) ($filters['before'] ?? '');

        $conditions = [];
        $values     = [];

        if ($status === 'success' || $status === 'error') {
            $conditions[] = 'success = ' . ($status === 'success' ? '1' : '0');
        }

        if ($filterYear !== '' && ctype_digit($filterYear) && strlen($filterYear) === 4) {
            $conditions[] = 'YEAR(created_at) = %d';
            $values[]     = (int) $filterYear;
        }

        if ($filterMonth !== '' && ctype_digit($filterMonth) && (int) $filterMonth >= 1 && (int) $filterMonth <= 12) {
            $conditions[] = 'MONTH(created_at) = %d';
            $values[]     = (int) $filterMonth;
        }

        if ($search !== '') {
            $conditions[] = 'request_data LIKE %s';
            $values[]     = '%' . $wpdb->esc_like($search) . '%';
        }

        if ($endpoint !== '') {
            $conditions[] = 'endpoint_url = %s';
            $values[]     = $endpoint;
        }

        if (in_array($messageType, self::MESSAGE_TYPES, true)) {
            $conditions[] = 'message_type = %s';
            $values[]     = $messageType;
        }

        if ($provider !== '') {
            $conditions[] = 'form_provider = %s';
            $values[]     = $provider;
        }

        if ($formName !== '') {
            $conditions[] = 'form_name = %s';
            $values[]     = $formName;
        }

        if ($formId !== '') {
            $conditions[] = 'form_id = %s';
            $values[]     = $formId;
        }

        // Half-open UTC range on the indexed created_at column. Callers pass
        // pre-validated 'YYYY-MM-DD HH:MM:SS' bounds (see the REST layer's
        // validate_callback); the YEAR()/MONTH() filters above stay for the
        // admin month dropdown, but a plain comparison is what actually uses
        // KEY created_at — wrapping the column in a function cannot.
        if ($after !== '') {
            $conditions[] = 'created_at >= %s';
            $values[]     = $after;
        }

        if ($before !== '') {
            $conditions[] = 'created_at < %s';
            $values[]     = $before;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $values];
    }
}
