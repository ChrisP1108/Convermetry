<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Settings\Options;
use Convermetry\Support\Errors;
use Convermetry\Support\Retention;
use Convermetry\Support\SensitiveKeys;

/**
 * Persists and retrieves webhook delivery log entries (the Activity Log) in
 * a custom table.
 *
 * One row = one delivery attempt — scheduled analytics report, immediate
 * form submission, retry, or test — storing the JSON payload that was sent
 * and the response the endpoint returned, so the Activity Log admin page and
 * the read-only deliveries REST API can show what left the site and what
 * came back.
 *
 * Stored bodies are a REDACTED REPRESENTATION of the request, not a
 * byte-exact copy: sensitive-key values are replaced (see below), form
 * submission_data can be dropped by a setting, and both bodies are capped at
 * 64 KB so a misbehaving endpoint cannot balloon the table. A stored body
 * therefore cannot be used to re-verify the delivery's HMAC signature — that
 * signature was computed over the bytes actually sent.
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
    private const string TABLE = 'cvm_webhook_deliveries';

    /** Option key storing the installed schema version. */
    private const string DB_VERSION_OPTION = 'cvm_delivery_db_version';

    /** Current schema version; bump when the CREATE TABLE below changes. */
    private const string DB_VERSION = '1.0.0';

    /** Maximum stored bytes for the request payload and response body. */
    public const int MAX_BODY_BYTES = 65536;

    /** Valid message_type values. */
    public const array MESSAGE_TYPES = ['analytics_report', 'form_submission'];

    /** Valid kind values. */
    public const array KINDS = ['scheduled', 'immediate', 'retry', 'test'];

    /** Rows deleted per statement during retention cleanup. */
    private const int CLEANUP_CHUNK = 5000;

    /** Maximum delete chunks per cron run, so one request can't run indefinitely. */
    private const int CLEANUP_MAX_CHUNKS = 20;

    /**
     * Wall-clock seconds budgeted for one purgeOld() run, alongside the
     * chunk-count cap. These rows carry full request/response bodies
     * (LONGTEXT), so a slow DELETE is plausible.
     */
    private const int CLEANUP_TIME_BUDGET = 20;

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
            KEY submission_id (submission_id)
        ) {$charset};";

        dbDelta($sql);

        // Only record the schema version once the table verifiably carries
        // every expected column — a failed or partial dbDelta run must be
        // retried on the next load, not silently marked complete.
        $expected = [
            'id', 'success', 'endpoint_url', 'endpoint_label', 'delivery_id',
            'message_type', 'kind', 'attempt', 'submission_id', 'conversion_id',
            'form_provider', 'form_name', 'request_url', 'request_headers',
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
        if (self::needsUpgrade()) {
            self::createTable();
        }
    }

    /**
     * Alias of {@see maybeCreateTable()}, so every table owner presents the
     * same maybeUpgrade()/needsUpgrade() pair to
     * {@see \Convermetry\Database\MigrationRunner}. The original name is kept
     * because it reads better at this class's own call sites and is public API.
     *
     * @return void
     */
    public static function maybeUpgrade(): void
    {
        self::maybeCreateTable();
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
     * @return string What became of the row: 'stored', 'suppressed' (a
     *                convermetry_delivery_log_row callback returned false), or
     *                'failed' (the INSERT itself failed). Callers that only log
     *                may ignore it; convermetry_delivery_attempt_logged reports it.
     */
    public static function log(array $entry): string
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
            return 'suppressed';
        }

        $inserted = $wpdb->insert(
            self::tableName(),
            $row,
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return $inserted === false ? 'failed' : 'stored';
    }

    /**
     * Replaces a form-submission payload's submission_data — and the
     * submitter's ip_address — with a placeholder before storage, when the
     * admin has disabled storing lead data in the Activity Log.
     *
     * @param string $requestData JSON request payload.
     * @return string
     */
    private static function stripSubmissionData(string $requestData): string
    {
        if ($requestData === '' || !json_validate($requestData)) {
            return $requestData;
        }

        $decoded = json_decode($requestData, true);
        if (!is_array($decoded)) {
            return $requestData;
        }

        if (isset($decoded['form_submission']) && is_array($decoded['form_submission'])) {
            $decoded['form_submission']['submission_data'] = '[NOT STORED — disabled in Settings]';

            // The submitter's IP is lead PII in the same sense the field
            // values are, so it follows the same switch.
            if (array_key_exists('ip_address', $decoded['form_submission'])) {
                $decoded['form_submission']['ip_address'] = '[NOT STORED — disabled in Settings]';
            }
        }

        return (string) wp_json_encode($decoded);
    }

    /**
     * Masks the values of credential-bearing request headers for storage —
     * the exact headers still travel on the wire and in frozen retries, but
     * the log must never hold a configured Authorization value in the clear.
     *
     * Matching is delegated to {@see SensitiveKeys::matchesHeader()}, which
     * canonicalizes the name first, so 'X-API-Key', 'x-api-key' and 'X_Api_Key'
     * are all recognised as the same credential.
     *
     * @param array<string, string> $headers Request headers as sent.
     * @return array<string, string>
     */
    public static function redactHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $out[(string) $name] = SensitiveKeys::matchesHeader((string) $name)
                ? '[REDACTED]'
                : (string) $value;
        }

        return $out;
    }

    /**
     * Redacts values of sensitive-looking keys in a JSON body.
     *
     * Only well-formed JSON objects/arrays are rewritten — non-JSON bodies
     * are stored as-is (there is no reliable way to find secrets in free
     * text, and mangling the body would hide what the endpoint actually
     * said). Key matching is delegated to {@see SensitiveKeys::matches()} and
     * applied recursively.
     *
     * Structured submission fields need a second rule. In schema 2.0 a field
     * travels as {"id": "...", "label": "...", "value": "..."}, so the
     * sensitive NAME is a value of 'id'/'label' while the secret itself sits
     * under the generic key 'value'. Key-name matching alone would walk right
     * past it and store the password in the clear, which is exactly the
     * regression this method exists to prevent.
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
            // A field descriptor hides the sensitive name in a VALUE, so test
            // both 'id' and 'label' and mask the sibling 'value'.
            //
            // The early return fires ONLY when something was actually
            // redacted. A descriptor that is not sensitive must keep
            // recursing: this walker also runs over arbitrary endpoint
            // RESPONSE bodies, and a response that merely happens to be shaped
            // like {"id":…,"label":…,"value":{"client_secret":…}} would
            // otherwise smuggle a nested secret past redaction.
            if (self::isFieldDescriptor($data)
                && (SensitiveKeys::matches((string) $data['id'])
                    || SensitiveKeys::matches((string) $data['label']))
            ) {
                $data['value'] = '[REDACTED]';

                return $data;
            }

            foreach ($data as $key => $value) {
                if (is_string($key) && SensitiveKeys::matches($key)) {
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
     * Whether an array is one structured submission field descriptor.
     *
     * Deliberately strict: all three keys must be present, 'id' and 'label'
     * must be scalar (they are the names being tested), and 'value' may be
     * anything. A looser test would misclassify ordinary endpoint responses
     * that happen to carry an 'id'.
     *
     * @param array<mixed> $data Decoded JSON node.
     * @return bool
     */
    private static function isFieldDescriptor(array $data): bool
    {
        return array_key_exists('id', $data)
            && array_key_exists('label', $data)
            && array_key_exists('value', $data)
            && is_scalar($data['id'])
            && is_scalar($data['label']);
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
    public static function purgeOld(): int
    {
        global $wpdb;

        $cutoff   = gmdate('Y-m-d H:i:s', time() - Options::retentionDays() * DAY_IN_SECONDS);
        $table    = self::tableName();
        $deadline = microtime(true) + self::CLEANUP_TIME_BUDGET;
        $runs     = 0;
        $total    = 0;

        Retention::started('delivery_log', $cutoff);

        do {
            $deleted = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE created_at < %s LIMIT %d",
                    $cutoff,
                    self::CLEANUP_CHUNK
                )
            );

            $total += is_int($deleted) ? $deleted : 0;
        } while (
            is_int($deleted) && $deleted === self::CLEANUP_CHUNK
            && ++$runs < self::CLEANUP_MAX_CHUNKS
            && microtime(true) < $deadline
        );

        $outcome = Retention::outcome($deleted, self::CLEANUP_CHUNK, $total);
        Retention::completed('delivery_log', $cutoff, $outcome);

        if ($outcome['outcome'] === Retention::QUERY_FAILED) {
            Errors::storage('delivery_log', 'retention_delete', 'delete_failed', ['cutoff' => $cutoff]);
        }

        return $total;
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

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $values];
    }
}
