<?php
declare(strict_types=1);

namespace Convermetry\Database;

if (!defined('ABSPATH')) exit;

use Convermetry\Leads\LeadEvents;
use Convermetry\Leads\LeadStatus;
use Convermetry\Notifications\NotificationQueue;
use Convermetry\Settings\Options;
use Convermetry\Support\Errors;
use Convermetry\Support\Retention;
use Convermetry\Webhook\DeliveryLog;
use Convermetry\Webhook\DeliveryState;
use Convermetry\Webhook\EndpointOutcome;
use Convermetry\Webhook\FormDeliveryQueue;

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
 * context holds the frozen analytics context captured at submission time, and
 * ip_address the submitter's address when that capture is enabled in Settings.
 * Rows age out with the same retention window as analytics events.
 *
 * channel and utm_campaign are DERIVED columns — copies of two values that
 * also live inside the context JSON. They are promoted to real, indexed
 * columns so the Submissions admin page can filter by them and build its
 * dropdowns without scanning and decoding every row's context blob.
 *
 * delivery_state and delivery_json are the RECORDED outcome of webhook
 * delivery, written by {@see refreshDeliveryState()} whenever an outcome
 * changes. They are stored rather than re-derived because the Activity Log
 * they would be derived from is independently clearable — which would let a
 * delivered lead silently revert to "not sent" in the list, the status filter,
 * and the CSV export. The log stays diagnostic; this is the record.
 */
final class FormSubmissions
{
    /** Table name without the wpdb prefix. */
    private const string TABLE = 'cvm_form_submissions';

    /** Recognized webhook delivery states (see {@see classifyDelivery()}). */
    public const array DELIVERY_STATES = [
        DeliveryState::Delivered->value,
        DeliveryState::Partial->value,
        DeliveryState::Failed->value,
        DeliveryState::Pending->value,
        DeliveryState::NotSent->value,
    ];

    /** Option key storing the installed schema version. */
    private const string DB_VERSION_OPTION = 'cvm_submissions_db_version';

    /** Current schema version; bump when the CREATE TABLE below changes. */
    private const string DB_VERSION = '1.4.0';

    /** Rows deleted per statement during retention cleanup. */
    private const int CLEANUP_CHUNK = 2000;

    /** Maximum delete chunks per cron run. */
    private const int CLEANUP_MAX_CHUNKS = 20;

    /** Wall-clock seconds budgeted for one purgeOld() run. */
    private const int CLEANUP_TIME_BUDGET = 20;

    /** Rows whose derived columns are populated per backfill chunk. */
    private const int BACKFILL_CHUNK = 500;

    /** Maximum chunks per backfill run. */
    private const int BACKFILL_MAX_CHUNKS = 40;

    /** Wall-clock seconds budgeted for one backfillDerivedColumns() run. */
    private const int BACKFILL_TIME_BUDGET = 10;

    /** Cron hook that drains the derived-column backfill after an upgrade. */
    public const string BACKFILL_CATCHUP_HOOK = 'cvm_submissions_backfill_catchup';

    /**
     * Every column {@see createTable()} must verify before stamping the version.
     *
     * Public so a shape test can compare it against the DDL: adding a column to
     * one and not the other is the classic failure of this pattern, and it fails
     * in the silent direction — a name listed here but absent from the DDL means
     * the version is never stamped, the migration retries forever, and every
     * feature gated on it stays switched off with no error anywhere.
     *
     * @return string[]
     */
    public static function expectedColumns(): array
    {
        return [
            'id', 'submission_id', 'conversion_id', 'session_id', 'provider',
            'form_key', 'form_name', 'native_form_id', 'form_id', 'page_url',
            'ip_address', 'channel', 'utm_campaign', 'utm_source', 'utm_medium',
            'utm_id', 'landing_page', 'lead_status', 'lead_value',
            'lead_currency', 'lead_status_at', 'delivery_state',
            'delivery_json', 'page_query', 'submission_data', 'context',
            'runtime', 'created_at',
        ];
    }

    /**
     * Every index {@see createTable()} must verify before stamping the version.
     *
     * Every index the admin page FILTERS or GROUPS on has to be verified, not
     * just the dedup one. dbDelta can add columns while silently skipping an
     * index; recording the schema version anyway would mark that partial
     * migration complete and it would never be retried.
     *
     * @return string[]
     */
    public static function expectedIndexes(): array
    {
        return [
            'conversion_id', 'channel', 'utm_campaign', 'delivery_state',
            'lead_status_created', 'lead_value', 'landing_page', 'utm_source_medium',
        ];
    }

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
            ip_address VARCHAR(45) NOT NULL DEFAULT '',
            channel VARCHAR(32) NULL,
            utm_campaign VARCHAR(191) NULL,
            utm_source VARCHAR(100) NULL,
            utm_medium VARCHAR(100) NULL,
            utm_id VARCHAR(100) NULL,
            landing_page VARCHAR(255) NULL,
            lead_status VARCHAR(16) NOT NULL DEFAULT 'new',
            lead_value DECIMAL(13,2) NULL,
            lead_currency CHAR(3) NOT NULL DEFAULT '',
            lead_status_at DATETIME NULL,
            delivery_state VARCHAR(16) NULL,
            delivery_json TEXT NULL,
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
            KEY provider_form (provider,form_key(100)),
            KEY channel (channel),
            KEY utm_campaign (utm_campaign(100)),
            KEY delivery_state (delivery_state),
            KEY lead_status_created (lead_status,created_at),
            KEY lead_value (lead_value),
            KEY landing_page (landing_page(100)),
            KEY utm_source_medium (utm_source,utm_medium)
        ) {$charset};";

        dbDelta($sql);

        foreach (self::expectedIndexes() as $index) {
            if (!DatabaseManager::tableHasIndex($table, $index)) {
                return;
            }
        }

        if (DatabaseManager::tableHasColumns($table, self::expectedColumns())) {
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);

            // Rows written before 1.2.0/1.3.0/1.4.0 have NULL derived columns.
            // Run a budgeted pass now and schedule a catch-up; the daily cron
            // picks up anything still left.
            self::backfillDerivedColumns();
            self::scheduleBackfillCatchUp();
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
     * ships. Read by {@see MigrationRunner}, which decides which request is
     * allowed to act on the answer.
     *
     * @return bool
     */
    public static function needsUpgrade(): bool
    {
        return get_option(self::DB_VERSION_OPTION) !== self::DB_VERSION;
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
     * @param NewSubmission $submission Fully sanitized submission record.
     * @return int|null The new row id, or null when the insert stored nothing
     *                  (duplicate conversion_id, or a database failure).
     */
    public static function insert(NewSubmission $submission): ?int
    {
        global $wpdb;

        // Every derived column is written HERE, at insert, rather than being
        // left NULL for the backfill worker to fill in later. A new row must
        // never enter the backfill queue: the worker exists to drain history
        // after an upgrade, and a site that creates rows faster than the daily
        // budgeted pass drains them would never converge.
        $inserted = $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::tableName()
            . ' (submission_id, conversion_id, session_id, provider, form_key, form_name,'
            . ' native_form_id, form_id, page_url, ip_address, channel, utm_campaign,'
            . ' utm_source, utm_medium, utm_id, landing_page,'
            . ' page_query, submission_data, context, runtime, delivery_state, created_at)'
            . ' VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
            $submission->submissionId,
            $submission->conversionId,
            $submission->sessionId,
            $submission->provider,
            $submission->formKey,
            $submission->formName,
            $submission->nativeFormId,
            $submission->formId,
            $submission->pageUrl,
            $submission->ipAddress,
            mb_substr($submission->channel, 0, 32),
            mb_substr($submission->utmCampaign, 0, 191),
            mb_substr($submission->utmSource, 0, 100),
            mb_substr($submission->utmMedium, 0, 100),
            mb_substr($submission->utmId, 0, 100),
            mb_substr($submission->landingPage, 0, 255),
            self::encodeJson($submission->pageQuery),
            self::encodeJson($submission->fields),
            self::encodeJson($submission->context),
            self::encodeJson($submission->runtime),
            // Nothing has been attempted yet, which is precisely what NotSent
            // means. Leaving it NULL made every row on a site that does not use
            // webhooks match BACKFILL_PREDICATE, so the daily worker re-derived
            // a state it could have been told — the exact "a new row must never
            // enter the backfill queue" rule the comment above states.
            DeliveryState::NotSent->value,
            gmdate('Y-m-d H:i:s')
        ));

        if ($inserted !== 1) {
            return null;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Encodes a stored JSON column, keeping non-ASCII text readable.
     *
     * PHP's default escapes "José" to "José", which the Submissions
     * page's LIKE search over submission_data could never match — a lead
     * called José was simply unfindable by name. Storing the characters
     * verbatim fixes that at the source.
     *
     * This is a STORAGE format only. PayloadBuilder decodes these columns and
     * re-encodes the whole payload before delivery, so the webhook wire format
     * and its HMAC signature are unaffected.
     *
     * @param mixed $value Value to encode.
     * @return string
     */
    private static function encodeJson(mixed $value): string
    {
        return (string) wp_json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
     * Fetches only the identity columns for one submission.
     *
     * The cheap lookup the notification listener needs, running inside the
     * visitor's own request. It exists so that path never calls
     * {@see getBySubmissionId()}, which does SELECT * and would drag three
     * LONGTEXT columns (submission_data, context, runtime) into memory just to
     * read a form key. Do not "simplify" the caller back to the full fetch.
     *
     * @param string $submissionId Submission id.
     * @return array{id: int, form_key: string, provider: string, form_name: string}|null
     */
    public static function getIdentity(string $submissionId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, form_key, provider, form_name FROM ' . self::tableName()
                . ' WHERE submission_id = %s',
                $submissionId
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        return [
            'id'        => (int) $row['id'],
            'form_key'  => (string) $row['form_key'],
            'provider'  => (string) $row['provider'],
            'form_name' => (string) $row['form_name'],
        ];
    }

    /**
     * Fetches one submission's lead columns.
     *
     * A narrow read on purpose: the caller needs four small values and
     * {@see get()} would drag three LONGTEXT columns into memory to supply them.
     *
     * @param string $submissionId The submission's globally unique id.
     * @return array{lead_status: string, lead_value: string|null, lead_currency: string}|null
     */
    public static function getLead(string $submissionId): ?array
    {
        global $wpdb;

        if ($submissionId === '') {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT lead_status, lead_value, lead_currency FROM ' . self::tableName()
                . ' WHERE submission_id = %s',
                $submissionId
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        return [
            'lead_status'   => (string) $row['lead_status'],
            // Kept as the string the DECIMAL column produced, or null. Casting
            // to float here would undo the whole point of the column type.
            'lead_value'    => $row['lead_value'] === null ? null : (string) $row['lead_value'],
            'lead_currency' => (string) $row['lead_currency'],
        ];
    }

    /**
     * Applies a lead status/value change and records it in one transaction.
     *
     * The two writes are inseparable. A change that applied without being
     * recorded leaves a lead whose history claims it is still 'new'; a history
     * row without the change claims a lead was won when it was not. Neither is
     * detectable from the other, so both commit or neither does.
     *
     * @param string      $submissionId The submission's globally unique id.
     * @param string      $status       The new status (already validated).
     * @param string|null $value        The new value as a decimal string, or null.
     * @param string      $currency     Currency code for $value, or ''.
     * @param int         $userId       The user making the change.
     * @param string      $fromStatus   The previous status, for the history row.
     * @param string      $eventId      Pre-minted history event id; '' mints one downstream.
     * @return bool True when both writes committed.
     */
    public static function updateLead(
        string $submissionId,
        string $status,
        ?string $value,
        string $currency,
        int $userId,
        string $fromStatus,
        string $eventId = ''
    ): bool {
        global $wpdb;

        if ($submissionId === '') {
            return false;
        }

        $wpdb->query('START TRANSACTION');

        $updated = $wpdb->update(
            self::tableName(),
            [
                'lead_status'    => $status,
                'lead_value'     => $value,
                'lead_currency'  => $currency,
                'lead_status_at' => gmdate('Y-m-d H:i:s'),
            ],
            ['submission_id' => $submissionId],
            // %s for a null value still binds NULL through $wpdb::update(),
            // which is what distinguishes "no value recorded" from "0.00".
            ['%s', '%s', '%s', '%s'],
            ['%s']
        );

        if ($updated === false) {
            $wpdb->query('ROLLBACK');
            Errors::storage('leads', 'update', 'lead_update_failed', ['submission_id' => $submissionId]);

            return false;
        }

        if (!LeadEvents::record($submissionId, $fromStatus, $status, $value, $currency, $userId, $eventId)) {
            $wpdb->query('ROLLBACK');
            Errors::storage('leads', 'history_insert', 'lead_history_insert_failed', [
                'submission_id' => $submissionId,
            ]);

            return false;
        }

        $wpdb->query('COMMIT');

        return true;
    }

    /**
     * Recomputes and stores one submission's webhook delivery state.
     *
     * Called at every point an outcome changes (queued, attempted, abandoned),
     * so the submission row itself carries the answer. Delivery state used to
     * be re-derived from the Activity Log at display time, which meant
     * "Clear All Logs" could turn a delivered lead back into "Not sent" — and
     * the status filter and CSV export inherited that false value. The log is
     * now purely diagnostic; this snapshot is the record.
     *
     * Recomputing from the authoritative tables (rather than merging a single
     * outcome into the stored JSON) keeps concurrent workers from clobbering
     * each other: whoever writes last writes the current truth.
     *
     * @param string $submissionId The submission's globally unique id.
     * @return void
     */
    public static function refreshDeliveryState(string $submissionId): void
    {
        global $wpdb;

        if ($submissionId === '') {
            return;
        }

        // Ordered ascending so the LAST attempt per endpoint wins — see
        // buildEndpointOutcomes().
        $logRows = $wpdb->get_results($wpdb->prepare(
            'SELECT endpoint_url, endpoint_label, success, response_code, attempt, created_at'
            . ' FROM ' . DeliveryLog::tableName()
            . " WHERE submission_id = %s AND message_type = 'form_submission'"
            . ' ORDER BY id ASC',
            $submissionId
        ), ARRAY_A);

        $queueRows = $wpdb->get_results($wpdb->prepare(
            'SELECT endpoint_url, status, attempt, next_attempt_at'
            . ' FROM ' . FormDeliveryQueue::tableName()
            . ' WHERE submission_id = %s',
            $submissionId
        ), ARRAY_A);

        $endpoints = self::buildEndpointOutcomes(
            is_array($logRows) ? $logRows : [],
            is_array($queueRows) ? $queueRows : []
        );

        // Read before the write so the action can report a genuine transition
        // rather than firing on every recomputation. This method runs several
        // times per delivery — on enqueue, after each attempt, after each retry
        // is scheduled — and most of those leave the state exactly as it was.
        $previous = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT delivery_state FROM ' . self::tableName() . ' WHERE submission_id = %s',
            $submissionId
        ));

        $state = self::classifyDelivery($endpoints);

        $wpdb->update(
            self::tableName(),
            [
                'delivery_state' => $state->value,
                'delivery_json'  => (string) wp_json_encode(
                    array_map(static fn(EndpointOutcome $e): array => $e->toArray(), $endpoints),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ],
            ['submission_id' => $submissionId],
            ['%s', '%s'],
            ['%s']
        );

        if ($state->value === $previous) {
            return;
        }

        /**
         * Fires when a submission's recorded delivery state genuinely changes.
         *
         * Fires only on a transition — not on every recomputation. The state is
         * recomputed several times per delivery (on enqueue, after each attempt,
         * after each retry is scheduled) and most of those leave it unchanged;
         * those are silent.
         *
         * States are 'not_sent', 'pending', 'partial', 'delivered', and
         * 'failed'. 'partial' means some configured endpoints accepted the
         * submission and others did not.
         *
         * Fires after the row is updated, so a listener that reads the
         * submission sees the new state.
         *
         * @param string $submissionId The submission's globally unique id.
         * @param string $state        The new delivery state.
         * @param string $previous     The state it replaced ('' for a row with none yet).
         */
        do_action('convermetry_submission_delivery_state_changed', $submissionId, $state->value, $previous);
    }

    /**
     * Reduces a submission's delivery history to one outcome per endpoint.
     *
     * Two rules, both of which the first implementation got wrong:
     *
     *  - The LAST attempt against an endpoint is that endpoint's outcome. The
     *    original query took MAX(success) and MAX(response_code) as
     *    independent aggregates, so a 500 followed by a successful 200 retry
     *    reported "Delivered (500)" — a success paired with the failure's
     *    status code.
     *  - A queue row outranks any log row for the same endpoint: the delivery
     *    is still in flight, so its last failed attempt is not yet the verdict.
     *
     * Pure: no database, no WordPress state.
     *
     * @param array<int, array<string, mixed>> $logRows   Delivery-log rows, oldest first.
     * @param array<int, array<string, mixed>> $queueRows Undelivered queue rows.
     * @return list<EndpointOutcome>
     */
    public static function buildEndpointOutcomes(array $logRows, array $queueRows): array
    {
        $endpoints = [];

        foreach ($logRows as $row) {
            $url = (string) ($row['endpoint_url'] ?? '');

            // Overwrites any earlier attempt against this endpoint; callers
            // pass rows oldest-first, so the newest verdict survives.
            $endpoints[$url] = new EndpointOutcome(
                url: $url,
                label: (string) ($row['endpoint_label'] ?? ''),
                ok: (int) ($row['success'] ?? 0) === 1,
                code: (int) ($row['response_code'] ?? 0),
                attempt: (int) ($row['attempt'] ?? 0),
                queued: false,
                at: (string) ($row['created_at'] ?? ''),
            );
        }

        foreach ($queueRows as $row) {
            $url = (string) ($row['endpoint_url'] ?? '');

            $endpoints[$url] = new EndpointOutcome(
                url: $url,
                // The label the log row recorded, when there is one — a queue
                // row does not carry it.
                label: $endpoints[$url]->label ?? '',
                ok: false,
                code: 0,
                attempt: (int) ($row['attempt'] ?? 0),
                queued: true,
                at: (string) ($row['next_attempt_at'] ?? ''),
            );
        }

        return array_values($endpoints);
    }

    /**
     * The delivery state implied by a submission's per-endpoint outcomes.
     *
     * A submission is judged against the endpoints it was ACTUALLY attempted
     * against, never against the endpoints configured right now: adding a third
     * endpoint today must not retroactively downgrade last month's successful
     * two-endpoint delivery to "partial".
     *
     * NotSent is a NEUTRAL state, never a failure — it is the ordinary
     * condition of a site that uses the plugin without webhooks at all. How it
     * is worded (paused vs. never configured) is a display concern and lives
     * in the admin page.
     *
     * Pure: no database, no WordPress state.
     *
     * @param list<EndpointOutcome> $endpoints Per-endpoint outcomes.
     * @return DeliveryState
     */
    public static function classifyDelivery(array $endpoints): DeliveryState
    {
        if ($endpoints === []) {
            return DeliveryState::NotSent;
        }

        foreach ($endpoints as $endpoint) {
            if ($endpoint->queued) {
                return DeliveryState::Pending;
            }
        }

        $ok = 0;
        foreach ($endpoints as $endpoint) {
            if ($endpoint->ok) {
                $ok++;
            }
        }

        return match (true) {
            $ok === count($endpoints) => DeliveryState::Delivered,
            $ok > 0                   => DeliveryState::Partial,
            default                   => DeliveryState::Failed,
        };
    }

    /**
     * SQL predicate identifying a row whose derived columns are not yet filled.
     *
     * ONE PLACE, because the backfill loop and {@see needsBackfill()} must agree
     * exactly — and because this predicate has to be EXTENDED, never reused
     * as-is, whenever a new derived column is added. That is not a style
     * preference: 1.4.0 added four attribution columns, and every install that
     * had already run the 1.2.0 backfill has a non-NULL channel. Had this stayed
     * "channel IS NULL OR delivery_state IS NULL", those rows would have been
     * invisible to the worker and their new columns would have stayed NULL
     * forever — the campaign and landing-page lead reports would simply have
     * been blank on every existing site, with nothing to indicate why.
     */
    private const string BACKFILL_PREDICATE =
        'channel IS NULL OR delivery_state IS NULL OR landing_page IS NULL';

    /**
     * Populates the derived columns on rows written before schema 1.2.0 /
     * 1.3.0 / 1.4.0.
     *
     * Self-terminating with no progress option to track: an un-backfilled row is
     * exactly one matching {@see BACKFILL_PREDICATE}, and every row this touches
     * is written as a string ('' when the context carries no value), so it can
     * never be selected twice.
     *
     * Only HISTORY is drained here. {@see \Convermetry\Forms\SubmissionService}
     * writes all of these columns at insert time, so a row created by this
     * version never enters the queue in the first place.
     *
     * Runs as many chunks as fit in a wall-clock budget, mirroring
     * {@see purgeOld()}. A single 500-row pass per day meant ten thousand
     * legacy rows took roughly three weeks to become filterable — and never
     * finished at all on a site whose WP-Cron is broken. A budgeted loop
     * clears that same table in one run while still refusing to block a
     * request indefinitely.
     *
     * @return int Rows updated by this pass.
     */
    public static function backfillDerivedColumns(): int
    {
        global $wpdb;

        $table    = self::tableName();
        $deadline = microtime(true) + self::BACKFILL_TIME_BUDGET;
        $updated  = 0;
        $passes   = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT id, submission_id, context, channel, landing_page, delivery_state FROM {$table}"
                    . ' WHERE ' . self::BACKFILL_PREDICATE
                    . ' ORDER BY id DESC LIMIT %d',
                    self::BACKFILL_CHUNK
                ),
                ARRAY_A
            );

            if (!is_array($rows) || $rows === []) {
                break;
            }

            foreach ($rows as $row) {
                // The three groups are filled independently. A row can be
                // missing one and not the others (it predates only 1.3.0, or
                // only 1.4.0), and rewriting a column that is already populated
                // would be a pointless write at best — and would clobber a value
                // some other code path set at worst.
                //
                // channel and landing_page are tested separately because they
                // arrived in different versions: an install upgraded at 1.2.0
                // has a non-NULL channel and a NULL landing_page, and treating
                // channel as the sentinel for both would skip it forever.
                $derived = null;

                if ($row['channel'] === null) {
                    $derived = self::deriveColumns((string) ($row['context'] ?? ''));

                    $wpdb->update(
                        $table,
                        ['channel' => $derived['channel'], 'utm_campaign' => $derived['utm_campaign']],
                        ['id' => (int) $row['id']],
                        ['%s', '%s'],
                        ['%d']
                    );
                }

                if ($row['landing_page'] === null) {
                    $derived ??= self::deriveColumns((string) ($row['context'] ?? ''));

                    $wpdb->update(
                        $table,
                        [
                            'landing_page' => $derived['landing_page'],
                            'utm_source'   => $derived['utm_source'],
                            'utm_medium'   => $derived['utm_medium'],
                            'utm_id'       => $derived['utm_id'],
                        ],
                        ['id' => (int) $row['id']],
                        ['%s', '%s', '%s', '%s'],
                        ['%d']
                    );
                }

                // Reconstructs delivery_state/delivery_json from whatever the
                // log and queue still hold. Rows whose log entries have already
                // aged out settle on 'not_sent', which is the honest answer:
                // there is no longer any evidence a delivery happened.
                if ($row['delivery_state'] === null) {
                    self::refreshDeliveryState((string) ($row['submission_id'] ?? ''));
                }

                $updated++;
            }
        } while (
            count($rows) === self::BACKFILL_CHUNK
            && ++$passes < self::BACKFILL_MAX_CHUNKS
            && microtime(true) < $deadline
        );

        return $updated;
    }

    /**
     * Whether any row still needs its derived columns populated.
     *
     * Lets the admin page nudge the migration along on sites where WP-Cron
     * never fires — without that, those sites would show blank attribution and
     * a broken status filter forever.
     *
     * @return bool
     */
    public static function needsBackfill(): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var(
            'SELECT 1 FROM ' . self::tableName()
            . ' WHERE ' . self::BACKFILL_PREDICATE . ' LIMIT 1'
        );
    }

    /**
     * Schedules a one-off catch-up run shortly after an upgrade, so a table
     * too large for the upgrade's own budgeted pass keeps draining without
     * waiting for the next daily cleanup.
     *
     * Mirrors {@see DatabaseManager::scheduleCleanupCatchUp()}.
     *
     * @return void
     */
    public static function scheduleBackfillCatchUp(): void
    {
        if (self::needsBackfill() && !wp_next_scheduled(self::BACKFILL_CATCHUP_HOOK)) {
            wp_schedule_single_event(time() + 5 * MINUTE_IN_SECONDS, self::BACKFILL_CATCHUP_HOOK);
        }
    }

    /**
     * Cron callback for the daily cleanup: one budgeted backfill pass, without
     * re-arming.
     *
     * Separate from {@see backfillDerivedColumns()} because that method
     * reports how many rows it updated and this is a do_action() callback whose
     * return value WordPress discards. {@see backfillCatchUp()} is the
     * self-re-arming variant, on its own hook.
     *
     * @return void
     */
    public static function backfillOnCleanup(): void
    {
        self::backfillDerivedColumns();
    }

    /**
     * Cron callback: runs another budgeted backfill pass and re-arms itself
     * while work remains.
     *
     * @return void
     */
    public static function backfillCatchUp(): void
    {
        self::backfillDerivedColumns();
        self::scheduleBackfillCatchUp();
    }

    /**
     * Extracts the derived column values from a stored analytics context blob.
     *
     * Pure: no database, no WordPress state — the backfill's decision logic
     * kept separate from its SQL so it can be unit-tested directly.
     *
     * EVERY value returned here is a string, never null — the backfill selects
     * rows by NULL-ness, so returning null for a context that simply carries no
     * value would leave the row selectable forever and the backfill would never
     * terminate.
     *
     * @param string $contextJson The row's stored context column.
     * @return array{channel: string, utm_campaign: string, utm_source: string, utm_medium: string, utm_id: string, landing_page: string}
     */
    private static function deriveColumns(string $contextJson): array
    {
        $empty = [
            'channel'      => '',
            'utm_campaign' => '',
            'utm_source'   => '',
            'utm_medium'   => '',
            'utm_id'       => '',
            'landing_page' => '',
        ];

        if ($contextJson === '' || !json_validate($contextJson)) {
            return $empty;
        }

        $context = json_decode($contextJson, true);
        if (!is_array($context)) {
            return $empty;
        }

        $attribution = is_array($context['attribution'] ?? null) ? $context['attribution'] : [];

        // landing_page is stored as {"url": "..."} inside the context, not as a
        // bare string — see PayloadBuilder::emptyContext(), which is the shape
        // every stored context follows.
        $landing = is_array($context['landing_page'] ?? null)
            ? ($context['landing_page']['url'] ?? '')
            : '';

        return [
            'channel'      => self::derivedValue($context['channel'] ?? '', 32),
            'utm_campaign' => self::derivedValue($attribution['utm_campaign'] ?? '', 191),
            'utm_source'   => self::derivedValue($attribution['utm_source'] ?? '', 100),
            'utm_medium'   => self::derivedValue($attribution['utm_medium'] ?? '', 100),
            'utm_id'       => self::derivedValue($attribution['utm_id'] ?? '', 100),
            'landing_page' => self::derivedValue($landing, 255),
        ];
    }

    /**
     * One derived value, coerced to a bounded string.
     *
     * Non-scalars become '' rather than being cast: a filter that rewrote a
     * context value to an array must not produce "Array" in a filter dropdown.
     * The width bound matters too — a value longer than its column would be
     * truncated by MySQL at a different point than the dropdown expects, and the
     * two would then never match.
     *
     * @param mixed $value Raw value from the stored context.
     * @param int   $width The destination column's width in characters.
     * @return string
     */
    private static function derivedValue(mixed $value, int $width): string
    {
        return is_scalar($value) ? mb_substr((string) $value, 0, $width) : '';
    }

    /**
     * Returns a single page of submission rows, newest-first.
     *
     * @param int                        $page    1-based page number.
     * @param int                        $perPage Rows per page (clamp in callers).
     * @param array<string, string>       $filters See {@see buildWhereClause()}.
     * @return array<int, array<string, mixed>>
     */
    public static function getPaginated(int $page, int $perPage, array $filters = []): array
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
     * @param array<string, string>       $filters Same keys as {@see getPaginated()}.
     * @return int
     */
    public static function getCount(array $filters = []): int
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
     * Iterate with $beforeId = PHP_INT_MAX, then pass the last row's id back
     * in until fewer than $limit rows return. Submission rows carry the
     * visitor's full field values, so loading a whole table at once could
     * exhaust PHP memory on a busy site.
     *
     * @param int                        $beforeId Only rows with an id strictly below this value.
     * @param int                        $limit    Maximum rows to return.
     * @param array<string, string>       $filters  Optional filters (the export honors them).
     * @return array<int, array<string, mixed>>
     */
    public static function getChunk(int $beforeId, int $limit, array $filters = []): array
    {
        global $wpdb;

        [$where, $values] = self::buildWhereClause($filters);

        // buildWhereClause() returns either 'WHERE …' or ''; splice the keyset
        // bound into whichever shape came back.
        $where    = $where === '' ? 'WHERE id < %d' : $where . ' AND id < %d';
        $values[] = $beforeId;
        $values[] = $limit;

        $sql = 'SELECT * FROM ' . self::tableName() . " {$where} ORDER BY id DESC LIMIT %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, $values), ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    /**
     * Returns the distinct calendar years and months that have submissions,
     * for the Submissions page's filter dropdowns.
     *
     * @param array<string, string>       $filters Active filters (year/month/search excluded).
     * @return array{years: list<string>, months: list<string>}
     */
    public static function getDistinctDates(array $filters = []): array
    {
        global $wpdb;

        [$where, $values] = self::buildWhereClause(
            array_diff_key($filters, ['year' => 0, 'month' => 0, 'search' => 0])
        );

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
     * Returns the distinct non-empty values of one filterable column.
     *
     * The column name is never interpolated from caller input — only the four
     * names below are accepted, and anything else returns nothing.
     *
     * @param string $column One of provider, form_name, channel, utm_campaign.
     * @return list<string>
     */
    public static function getDistinctValues(string $column): array
    {
        global $wpdb;

        if (!in_array($column, ['provider', 'form_name', 'channel', 'utm_campaign'], true)) {
            return [];
        }

        $rows = $wpdb->get_col(
            "SELECT DISTINCT {$column} FROM " . self::tableName()
            . " WHERE {$column} IS NOT NULL AND {$column} <> '' ORDER BY {$column} ASC LIMIT 200"
        );

        return is_array($rows) ? array_values(array_filter($rows, 'is_string')) : [];
    }

    /**
     * Deletes a single submission row and cancels its pending deliveries.
     *
     * Cancelling the queue rows is the whole point, not housekeeping. Once a
     * delivery has made its first attempt the queue row holds a FROZEN COPY of
     * the payload — the visitor's field values included — and the worker will
     * keep replaying those bytes on the retry schedule. Deleting only the
     * submission would leave the admin's "removed permanently" action quietly
     * transmitting the erased lead for hours afterwards.
     *
     * Activity Log rows are deliberately untouched: a delivery attempt is a
     * record of something the site did, and erasing a lead must not silently
     * destroy the outbound audit trail.
     *
     * @param int $id The row ID to delete.
     * @return void
     */
    public static function deleteSubmission(int $id): void
    {
        global $wpdb;

        // Read the submission's globally unique id before the row goes away —
        // the queue is keyed by that, not by the numeric row id.
        $submissionId = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT submission_id FROM ' . self::tableName() . ' WHERE id = %d',
            $id
        ));

        $wpdb->delete(self::tableName(), ['id' => $id], ['%d']);

        if ($submissionId !== '') {
            $wpdb->delete(FormDeliveryQueue::tableName(), ['submission_id' => $submissionId], ['%s']);

            // Queued email notifications go the same way, and the reasoning is
            // stronger: a webhook queue row holds a frozen payload, but a
            // notification row would be rendered from the submission at send
            // time — so cancelling here is what guarantees an erased lead can
            // never be mailed. (Deleting cannot recall a message already sent.)
            NotificationQueue::cancelForSubmission($submissionId);

            // The lead's status history is data ABOUT this lead. "Removed
            // permanently" that left behind a trail of who qualified them and
            // what they were valued at would be a broken promise.
            LeadEvents::deleteForSubmission($submissionId);
        }

        /**
         * Fires after a submission and everything attached to it are gone.
         *
         * Deliberately last: by the time this runs the submission row, its
         * pending webhook queue rows, its queued notifications, and its lead
         * status history have all been removed. A listener can therefore treat
         * this as "the erasure is complete" rather than "an erasure has begun",
         * and anything it queries will agree.
         *
         * Only the Activity Log survives, by design — it records that deliveries
         * were attempted, not what they contained.
         *
         * Carries ids only. The submitted fields are the thing being erased;
         * handing them to a listener at the moment of deletion would defeat the
         * point. Read them before deletion if you need them.
         *
         * @param int    $id           Submission table row id (now gone).
         * @param string $submissionId The submission's globally unique id ('' if it could not be read).
         */
        do_action('convermetry_submission_deleted', $id, $submissionId);
    }

    /**
     * Removes every submission row and cancels every pending delivery.
     *
     * Both halves are required. Beyond the frozen-payload problem described on
     * {@see deleteSubmission()}, TRUNCATE resets AUTO_INCREMENT: a queue row
     * left pointing at row id 5 would otherwise be matched against whatever
     * NEW submission next takes id 5, delivering an unrelated visitor's lead
     * under the old delivery record's identity. Draining the queue here — and
     * resolving by submission_id in the worker — closes that off from both
     * ends.
     *
     * Activity Log rows are deliberately untouched, as above.
     *
     * @return void
     */
    public static function clearAll(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . self::tableName());

        // The queue carries form-submission deliveries only, so every row in
        // it belongs to a submission that no longer exists.
        $wpdb->query('DELETE FROM ' . FormDeliveryQueue::tableName());

        // Same argument for queued notifications: every one of them refers to
        // a submission that has just been erased.
        NotificationQueue::cancelAll();

        // And for lead history: every row describes a submission that is gone.
        LeadEvents::clearAll();

        /**
         * Fires after every submission, queued delivery, queued notification,
         * and lead history row has been removed.
         *
         * Fires once for the whole operation, after all four tables are drained
         * — never once per submission. The rows are removed with TRUNCATE and
         * bulk DELETEs that never load a single submission, and reading them all
         * back purely to emit hooks would both defeat the erasure and be
         * unbounded work.
         *
         * No count is passed for the same reason: TRUNCATE does not report one.
         *
         * @return void
         */
        do_action('convermetry_submissions_cleared');
    }

    /**
     * Builds a SQL WHERE clause and its ordered values from the filters.
     *
     * @param array<string, string> $filters year, month, provider, form_name,
     *                                       channel, campaign, search,
     *                                       delivery_status (see
     *                                       delivered/partial/failed/pending/not_sent),
     *                                       lead_status, and has_value ('yes'/'no').
     * @return array{0: string, 1: list<mixed>}
     */
    private static function buildWhereClause(array $filters): array
    {
        global $wpdb;

        $filterYear  = (string) ($filters['year'] ?? '');
        $filterMonth = (string) ($filters['month'] ?? '');
        $provider    = (string) ($filters['provider'] ?? '');
        $formName    = (string) ($filters['form_name'] ?? '');
        $channel     = (string) ($filters['channel'] ?? '');
        $campaign    = (string) ($filters['campaign'] ?? '');
        $search      = (string) ($filters['search'] ?? '');
        $status      = (string) ($filters['delivery_status'] ?? '');

        $conditions = [];
        $values     = [];

        if ($filterYear !== '' && ctype_digit($filterYear) && strlen($filterYear) === 4) {
            $conditions[] = 'YEAR(created_at) = %d';
            $values[]     = (int) $filterYear;
        }

        if ($filterMonth !== '' && ctype_digit($filterMonth) && (int) $filterMonth >= 1 && (int) $filterMonth <= 12) {
            $conditions[] = 'MONTH(created_at) = %d';
            $values[]     = (int) $filterMonth;
        }

        if ($provider !== '') {
            $conditions[] = 'provider = %s';
            $values[]     = $provider;
        }

        if ($formName !== '') {
            $conditions[] = 'form_name = %s';
            $values[]     = $formName;
        }

        if ($channel !== '') {
            $conditions[] = 'channel = %s';
            $values[]     = $channel;
        }

        if ($campaign !== '') {
            $conditions[] = 'utm_campaign = %s';
            $values[]     = $campaign;
        }

        if ($search !== '') {
            // The two id columns match exactly: pasting a submission_id or
            // conversion_id should find that one row, not LIKE-scan for it.
            //
            // The submission_data LIKE runs over the raw stored JSON, so it
            // matches whichever shape the row holds: a pre-2.0 map's keys and
            // values, or a 2.0 descriptor list's ids, labels, and values. The
            // structural key names ("id", "label", "value") are also in that
            // text, which is why a one- or two-character search is noisy — the
            // UI debounces rather than restricting the term length.
            $like  = '%' . $wpdb->esc_like($search) . '%';
            $clause = 'submission_data LIKE %s OR form_name LIKE %s OR page_url LIKE %s'
                    . ' OR submission_id = %s OR conversion_id = %s';

            $values[] = $like;
            $values[] = $like;
            $values[] = $like;
            $values[] = $search;
            $values[] = $search;

            // Rows written before the encoder switched to JSON_UNESCAPED_UNICODE
            // hold "José" as "José", which a literal LIKE can never match.
            // Search the escaped spelling too so historical leads stay findable
            // without rewriting every stored row.
            $escaped = self::jsonEscapedTerm($search);
            if ($escaped !== null) {
                $clause  .= ' OR submission_data LIKE %s';
                $values[] = '%' . $wpdb->esc_like($escaped) . '%';
            }

            $conditions[] = '(' . $clause . ')';
        }

        if (in_array($status, self::DELIVERY_STATES, true)) {
            // A plain indexed comparison now that the state is stored on the
            // row. This used to be a pair of correlated EXISTS subqueries plus
            // a GROUP BY ... HAVING over the whole delivery log.
            $conditions[] = 'delivery_state = %s';
            $values[]     = $status;
        }

        $leadStatus = (string) ($filters['lead_status'] ?? '');
        if (LeadStatus::isValid($leadStatus)) {
            $conditions[] = 'lead_status = %s';
            $values[]     = $leadStatus;
        }

        // Tested for NULL, not for zero. A lead explicitly recorded as worth
        // 0.00 HAS a value — someone assessed it and decided — and hiding it
        // under "no value" would misrepresent that judgement as an omission.
        $hasValue = (string) ($filters['has_value'] ?? '');
        if ($hasValue === 'yes') {
            $conditions[] = 'lead_value IS NOT NULL';
        } elseif ($hasValue === 'no') {
            $conditions[] = 'lead_value IS NULL';
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return [$where, $values];
    }

    /**
     * A search term rewritten the way PHP's default JSON encoder would have
     * stored it, or null when the term is pure ASCII and needs no second form.
     *
     * @param string $term Raw search term.
     * @return string|null
     */
    private static function jsonEscapedTerm(string $term): ?string
    {
        $encoded = json_encode($term);
        if (!is_string($encoded)) {
            return null;
        }

        $escaped = trim($encoded, '"');

        return $escaped === $term ? null : $escaped;
    }
    /**
     * Deletes rows older than the plugin's retention window. Runs on the
     * same daily cron as the events-table cleanup, in bounded chunks.
     *
     * The deleted row count is NOT returned. It reaches listeners through
     * 'convermetry_retention_cleanup_completed', which is the one place it is
     * published, and this runs as a do_action() callback whose return value
     * WordPress discards — so a return here would only invite a reader to
     * think it went somewhere.
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
        $total    = 0;

        Retention::started('form_submissions', $cutoff);

        do {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE created_at < %s LIMIT %d",
                $cutoff,
                self::CLEANUP_CHUNK
            ));

            $total += is_int($deleted) ? $deleted : 0;
        } while (
            is_int($deleted) && $deleted === self::CLEANUP_CHUNK
            && ++$runs < self::CLEANUP_MAX_CHUNKS
            && microtime(true) < $deadline
        );

        $outcome = Retention::outcome($deleted, self::CLEANUP_CHUNK, $total);
        Retention::completed('form_submissions', $cutoff, $outcome);

        if ($outcome->queryFailed()) {
            Errors::storage('form_submissions', 'retention_delete', 'delete_failed', ['cutoff' => $cutoff]);
        }
    }
}
