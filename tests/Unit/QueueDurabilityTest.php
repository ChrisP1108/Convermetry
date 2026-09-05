<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Support\QueueOutcome;
use Convermetry\Webhook\FormDeliveryQueue;
use PHPUnit\Framework\TestCase;

/**
 * Queue writes must be verified, not assumed.
 *
 * Regression origin: enqueue() counted only rows whose INSERT IGNORE reported
 * "1 row affected". INSERT IGNORE reports 0 both for a duplicate the unique
 * index suppressed and for a row it declined to write, and $wpdb->query()
 * returns false outright on error — so a failed write was indistinguishable
 * from an idempotent no-op. SubmissionService then discarded the count and
 * reported queued=true regardless.
 *
 * The result was a submission that was recorded, reported as delivered, and
 * never sent. A partial failure was worse: two of three destinations received
 * the lead while the third vanished silently.
 */
final class QueueDurabilityTest extends TestCase
{
    /** @var list<array{sql: string}> */
    private array $queries = [];

    /** @var list<array{hook: string, args: array<int, mixed>}> */
    private array $scheduled = [];

    /** @var list<array{subsystem: string, operation: string, code: string, context: array<string, mixed>}> */
    private array $storageErrors = [];

    /**
     * Per-endpoint INSERT results, keyed by endpoint URL.
     *
     * @var array<string, int|false>
     */
    private array $insertResults = [];

    /**
     * Whether a read-back finds a row, keyed by endpoint URL.
     *
     * @var array<string, bool>
     */
    private array $rowPresent = [];

    /** @var array<string, mixed>|null */
    private ?array $submissionRow = ['id' => 11, 'submission_id' => 'sub-1', 'delivery_state' => 'not_sent'];

    /**
     * In-memory wp_options, so the durable repair record can be asserted on
     * rather than mocked away.
     *
     * @var array<string, mixed>
     */
    private array $options = [];

    /**
     * option_id per option name, so the safety net's cursor scan is real.
     *
     * @var array<string, int>
     */
    private array $optionIds = [];

    private int $nextOptionId = 0;

    /** When true, every options write is refused — the unverifiable-write case. */
    private bool $optionWritesFail = false;

    /**
     * Endpoints the Activity Log already holds an attempt for.
     *
     * @var array<string, bool>
     */
    private array $loggedAttempts = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->queries       = [];
        $this->scheduled     = [];
        $this->storageErrors = [];
        $this->insertResults = [];
        $this->rowPresent    = [];
        $this->options          = [];
        $this->optionIds        = [];
        $this->nextOptionId     = 0;
        $this->optionWritesFail = false;
        $this->loggedAttempts   = [];
        $this->submissionRow    = ['id' => 11, 'submission_id' => 'sub-1', 'delivery_state' => 'not_sent'];

        Functions\when('get_option')->alias(
            fn(string $key, mixed $default = false): mixed => $this->options[$key] ?? $default
        );
        Functions\when('update_option')->alias(
            function (string $key, mixed $value, mixed $autoload = null): bool {
                $this->options[$key] = $value;

                return true;
            }
        );
        Functions\when('delete_option')->alias(
            function (string $key): bool {
                unset($this->options[$key]);

                return true;
            }
        );

        Functions\when('home_url')->justReturn('https://site.test');
        Functions\when('wp_parse_url')->alias(
            static fn(string $url, int $component = -1) => parse_url($url, $component)
        );
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_unschedule_event')->justReturn(true);

        Functions\when('wp_schedule_single_event')->alias(
            function (int $ts, string $hook, array $args = []): bool {
                $this->scheduled[] = ['hook' => $hook, 'args' => $args];
                return true;
            }
        );

        Monkey\Actions\expectDone('convermetry_storage_error')->zeroOrMoreTimes()
            ->whenHappen(function (string $subsystem, string $operation, string $code, array $context): void {
                $this->storageErrors[] = [
                    'subsystem' => $subsystem,
                    'operation' => $operation,
                    'code'      => $code,
                    'context'   => $context,
                ];
            });

        $test = $this;

        // A small options table, because the repair record is now one wp_options
        // ROW PER SUBMISSION written straight through $wpdb — the whole point of
        // the change, and untestable against a double that swallows writes.
        $GLOBALS['wpdb'] = new class ($test) {
            public string $prefix = 'wp_';

            public string $options = 'wp_options';

            /** @var array<int, mixed> */
            public array $lastArgs = [];

            public function __construct(private object $test)
            {
            }

            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }

            /**
             * Records the bound arguments as well as interpolating them.
             *
             * The options statements carry JSON values full of commas, quotes
             * and braces; parsing those back out of an interpolated string
             * would be testing the parser. The arguments are kept instead, and
             * every wp_options branch below reads them rather than the SQL.
             *
             * @param array<int, mixed> $args
             */
            public function prepare(string $sql, ...$args): string
            {
                $this->lastArgs = $args;

                // Good enough for routing: the assertions care which endpoint a
                // statement names, not its exact escaping.
                foreach ($args as $arg) {
                    if (is_array($arg)) {
                        continue;
                    }
                    $sql = preg_replace('/%[dsf]/', (string) $arg, $sql, 1) ?? $sql;
                }

                return $sql;
            }

            public function query(string $sql): int|false
            {
                if (str_contains($sql, "INSERT INTO {$this->options}")) {
                    return $this->test->writeOption(
                        (string) ($this->lastArgs[0] ?? ''),
                        (string) ($this->lastArgs[1] ?? '')
                    );
                }

                if (str_contains($sql, "DELETE FROM {$this->options}")) {
                    return $this->test->deleteOption((string) ($this->lastArgs[0] ?? ''));
                }

                return $this->test->recordInsert($sql);
            }

            public function get_var(string $sql): ?string
            {
                if (str_contains($sql, "SELECT option_value FROM {$this->options}")) {
                    return $this->test->readOption((string) ($this->lastArgs[0] ?? ''));
                }

                if (str_contains($sql, "SELECT option_name FROM {$this->options}")) {
                    $name = (string) ($this->lastArgs[0] ?? '');

                    return $this->test->readOption($name) === null ? null : $name;
                }

                if (str_contains($sql, 'wp_cvm_webhook_deliveries')) {
                    return (string) $this->test->loggedAttemptCount($sql);
                }

                return (string) $this->test->recordReadBack($sql);
            }

            /** @return array<int, mixed> */
            public function get_results(string $sql, string $output = 'ARRAY_A'): array
            {
                if (str_contains($sql, "FROM {$this->options}")) {
                    return $this->test->listOptions(
                        (string) ($this->lastArgs[0] ?? ''),
                        (int) ($this->lastArgs[1] ?? 0),
                        (int) ($this->lastArgs[2] ?? 0)
                    );
                }

                return [];
            }

            /** @return array<string, mixed>|null */
            public function get_row(string $sql, string $output = 'ARRAY_A'): ?array
            {
                return $this->test->submissionRow();
            }

            /**
             * @param array<string, mixed> $data
             * @param array<string, mixed> $where
             */
            public function update(string $table, array $data, array $where): int
            {
                return 1;
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Routes an INSERT to the configured result for whichever endpoint it names.
     */
    public function recordInsert(string $sql): int|false
    {
        $this->queries[] = ['sql' => $sql];

        foreach ($this->insertResults as $url => $result) {
            if (str_contains($sql, $url)) {
                return $result;
            }
        }

        return 1;
    }

    /**
     * Routes a read-back to the configured presence for whichever endpoint's
     * md5 key the statement names.
     */
    /** @return array<string, mixed>|null */
    public function submissionRow(): ?array
    {
        return $this->submissionRow;
    }

    public function recordReadBack(string $sql): int
    {
        foreach ($this->rowPresent as $url => $present) {
            if (str_contains($sql, md5($url))) {
                return $present ? 1 : 0;
            }
        }

        return 0;
    }

    /**
     * @param list<string> $urls
     */
    private function configureEndpoints(array $urls): void
    {
        $endpoints = [];
        foreach ($urls as $i => $url) {
            $endpoints[] = [
                'id'        => 'endpoint-' . $i,
                'url'       => $url,
                'label'     => 'E' . $i,
                'secret'    => '',
                'analytics' => false,
                'forms'     => true,
            ];
        }

        $this->options['cvm_webhook_settings'] = ['endpoints' => $endpoints, 'shared_secret' => ''];
    }


    // ── the in-memory options table ──────────────────────────────────────────

    /**
     * Writes one option row, honouring the failure switch.
     *
     * @return int|false
     */
    public function writeOption(string $name, string $value): int|false
    {
        if ($this->optionWritesFail) {
            return false;
        }

        if (!isset($this->optionIds[$name])) {
            $this->optionIds[$name] = ++$this->nextOptionId;
        }

        $this->options[$name] = $value;

        return 1;
    }

    /** @return int|false */
    public function deleteOption(string $name): int|false
    {
        if ($this->optionWritesFail) {
            return false;
        }

        unset($this->options[$name], $this->optionIds[$name]);

        return 1;
    }

    public function readOption(string $name): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The LIKE + cursor + LIMIT scan repairPending() pages through.
     *
     * @return list<array{option_id: int, option_name: string, option_value: string}>
     */
    public function listOptions(string $like, int $after, int $limit): array
    {
        $prefix = str_replace('\\', '', rtrim($like, '%'));
        $rows   = [];

        foreach ($this->options as $name => $value) {
            $id = $this->optionIds[$name] ?? 0;

            if (!str_starts_with((string) $name, $prefix) || $id <= $after || !is_string($value)) {
                continue;
            }

            $rows[] = ['option_id' => $id, 'option_name' => (string) $name, 'option_value' => $value];
        }

        usort($rows, static fn(array $a, array $b): int => $a['option_id'] <=> $b['option_id']);

        return array_slice($rows, 0, max($limit, 1));
    }

    /**
     * How many Activity Log attempts exist for the endpoint a statement names.
     */
    public function loggedAttemptCount(string $sql): int
    {
        foreach ($this->loggedAttempts as $url => $attempted) {
            if ($attempted && str_contains($sql, $url)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Writes one repair record straight into the fake options table.
     *
     * @param list<string> $refs
     */
    private function seedRecord(string $submissionId, array $refs, int $at): void
    {
        $name = 'cvm_queue_repair_' . $submissionId;

        $this->optionIds[$name] = ++$this->nextOptionId;
        $this->options[$name]   = (string) json_encode(['at' => $at, 'refs' => $refs]);
    }

    /**
     * Every repair record currently stored, by submission id.
     *
     * @return list<string>
     */
    private function recordedSubmissions(): array
    {
        $out = [];

        foreach (array_keys($this->options) as $name) {
            if (str_starts_with((string) $name, 'cvm_queue_repair_')) {
                $out[] = substr((string) $name, strlen('cvm_queue_repair_'));
            }
        }

        sort($out);

        return $out;
    }

    /**
     * @return list<array{sql: string}>
     */
    private function inserts(): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn(array $q): bool => str_contains($q['sql'], 'INSERT IGNORE')
        ));
    }

    // ── QueueOutcome semantics ───────────────────────────────────────────────

    public function testNothingToQueueIsCompleteAndQueuesNothing(): void
    {
        $outcome = QueueOutcome::nothingToQueue();

        self::assertTrue($outcome->isComplete(), 'Nothing expected means nothing outstanding');
        self::assertFalse($outcome->queuedAnything());
        self::assertSame(0, $outcome->durable());
    }

    public function testDurableCountsInsertsAndVerifiedDuplicates(): void
    {
        $outcome = new QueueOutcome(expected: 3, inserted: 1, duplicate: 2);

        self::assertSame(3, $outcome->durable());
        self::assertTrue($outcome->isComplete());
        self::assertTrue($outcome->queuedAnything());
    }

    public function testAnyFailureMakesTheOutcomeIncomplete(): void
    {
        $outcome = new QueueOutcome(expected: 3, inserted: 2, failed: 1, failedRefs: ['k']);

        self::assertFalse($outcome->isComplete(), 'A dropped destination is not "complete"');
        self::assertTrue($outcome->queuedAnything(), 'Two destinations did receive it');
        self::assertSame(2, $outcome->durable());
    }

    public function testTelemetryCarriesCountsButNoEndpointDetail(): void
    {
        $outcome = new QueueOutcome(expected: 2, inserted: 1, failed: 1, failedRefs: ['abc']);
        $telemetry = $outcome->telemetry();

        self::assertSame(['expected' => 2, 'inserted' => 1, 'duplicate' => 0, 'failed' => 1], $telemetry);

        $encoded = (string) json_encode($telemetry);
        self::assertStringNotContainsString('http', $encoded, 'Telemetry must never carry an endpoint URL');
        self::assertStringNotContainsString('abc', $encoded, 'Telemetry must not leak endpoint references');
    }

    // ── enqueue() ────────────────────────────────────────────────────────────

    public function testAllInsertsSucceedingIsComplete(): void
    {
        $this->configureEndpoints(['https://a.test/hook', 'https://b.test/hook']);

        $outcome = FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertSame(2, $outcome->expected);
        self::assertSame(2, $outcome->inserted);
        self::assertSame(0, $outcome->failed);
        self::assertTrue($outcome->isComplete());
        self::assertSame([], $this->storageErrors, 'A clean enqueue must not report a storage error');
    }

    /**
     * The exact scenario that silently lost a lead.
     */
    public function testARefusedInsertIsReportedAsFailedNotQueued(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;
        $this->rowPresent['https://a.test/hook']    = false;

        $outcome = FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertSame(1, $outcome->failed);
        self::assertSame(0, $outcome->durable());
        self::assertFalse($outcome->queuedAnything(), 'Nothing durable means nothing may be reported as queued');
        self::assertFalse($outcome->isComplete());
    }

    /**
     * INSERT IGNORE reporting 0 is ambiguous; only a read-back resolves it.
     */
    public function testSuppressedInsertWithAnExistingRowCountsAsDuplicate(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = 0;
        $this->rowPresent['https://a.test/hook']    = true;

        $outcome = FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertSame(1, $outcome->duplicate);
        self::assertSame(0, $outcome->failed);
        self::assertTrue($outcome->isComplete(), 'Already queued is a success, not a failure');
        self::assertSame([], $this->storageErrors);
    }

    public function testSuppressedInsertWithNoRowCountsAsFailure(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = 0;
        $this->rowPresent['https://a.test/hook']    = false;

        $outcome = FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertSame(0, $outcome->duplicate);
        self::assertSame(1, $outcome->failed);
        self::assertFalse($outcome->isComplete());
    }

    /**
     * The partial failure: some destinations receive the lead, one disappears.
     */
    public function testPartialFailureIsIncompleteEvenThoughSomeRowsLanded(): void
    {
        $this->configureEndpoints([
            'https://crm.test/hook',
            'https://cloud.test/hook',
            'https://zapier.test/hook',
        ]);
        $this->insertResults['https://cloud.test/hook'] = false;
        $this->rowPresent['https://cloud.test/hook']    = false;

        $outcome = FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertSame(3, $outcome->expected);
        self::assertSame(2, $outcome->inserted);
        self::assertSame(1, $outcome->failed);
        self::assertTrue($outcome->queuedAnything());
        self::assertFalse($outcome->isComplete(), 'One dropped destination must not read as success');

        // The DURABLE endpoint id, not md5(url): a URL edited between the failed
        // enqueue and the repair pass would leave a url hash matching nothing.
        self::assertSame(['endpoint-1'], $outcome->failedRefs);
    }

    public function testAFailedEnqueueReportsSanitizedTelemetry(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;

        FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertCount(1, $this->storageErrors);
        $error = $this->storageErrors[0];

        self::assertSame('form_delivery_queue', $error['subsystem']);
        self::assertSame('queue_row_not_persisted', $error['code']);
        self::assertSame(1, $error['context']['failed']);

        $encoded = (string) json_encode($error['context']);
        self::assertStringNotContainsString('a.test', $encoded, 'Telemetry must not carry endpoint URLs');
    }

    /**
     * Repair is scheduled for exactly the endpoints known to be missing.
     */
    public function testAFailedEnqueueSchedulesTargetedReconciliation(): void
    {
        $this->configureEndpoints(['https://a.test/hook', 'https://b.test/hook']);
        $this->insertResults['https://b.test/hook'] = false;

        FormDeliveryQueue::enqueue(11, 'sub-1');

        $repair = array_values(array_filter(
            $this->scheduled,
            static fn(array $e): bool => $e['hook'] === FormDeliveryQueue::RECONCILE_HOOK
        ));

        self::assertCount(1, $repair, 'A dropped row must schedule exactly one repair pass');
        self::assertSame('sub-1', $repair[0]['args'][0]);
        self::assertSame(
            ['endpoint-1'],
            $repair[0]['args'][1],
            'Repair must target only the endpoint that failed, never re-queue delivered ones'
        );
        self::assertSame(1, $repair[0]['args'][2], 'The first repair pass is attempt 1');
    }

    public function testASuccessfulEnqueueSchedulesNoReconciliation(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);

        FormDeliveryQueue::enqueue(11, 'sub-1');

        $repair = array_filter(
            $this->scheduled,
            static fn(array $e): bool => $e['hook'] === FormDeliveryQueue::RECONCILE_HOOK
        );

        self::assertSame([], $repair);
    }

    // ── reconcile() ──────────────────────────────────────────────────────────

    /**
     * @return list<array{hook: string, args: array<int, mixed>}>
     */
    private function repairPasses(): array
    {
        return array_values(array_filter(
            $this->scheduled,
            static fn(array $e): bool => $e['hook'] === FormDeliveryQueue::RECONCILE_HOOK
        ));
    }

    public function testRepairReinsertsAMissingRow(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);

        FormDeliveryQueue::reconcile('sub-1', ['endpoint-0'], 1);

        self::assertSame([], $this->storageErrors, 'A successful repair reports nothing');
        self::assertSame([], $this->repairPasses(), 'A successful repair schedules no follow-up');
    }

    /**
     * One repair attempt was too thin: the condition that refused the original
     * insert is often still present when it runs.
     */
    public function testAFailedRepairRetriesOnTheNextBackoffStep(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;

        FormDeliveryQueue::reconcile('sub-1', ['endpoint-0'], 1);

        self::assertSame(['queue_repair_failed'], array_column($this->storageErrors, 'code'));

        $passes = $this->repairPasses();
        self::assertCount(1, $passes, 'A failed repair must schedule another attempt');
        self::assertSame(['endpoint-0'], $passes[0]['args'][1]);
        self::assertSame(2, $passes[0]['args'][2], 'The retry is attempt 2');
    }

    /**
     * Bounded: it must not retry forever.
     */
    public function testRepairIsAbandonedAfterTheBoundedRetries(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;

        FormDeliveryQueue::reconcile('sub-1', ['endpoint-0'], 3);

        self::assertSame(
            ['queue_repair_failed', 'queue_repair_abandoned'],
            array_column($this->storageErrors, 'code'),
            'The final failure must be announced as abandonment, not another retry'
        );
        self::assertSame([], $this->repairPasses(), 'Nothing may be scheduled after abandonment');
    }

    /**
     * A cron write that fails is exactly as lost as the queue row was.
     */
    public function testAFailureToScheduleRepairIsReported(): void
    {
        Functions\when('wp_schedule_single_event')->justReturn(false);

        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;

        FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertSame(
            ['queue_row_not_persisted', 'queue_repair_not_scheduled'],
            array_column($this->storageErrors, 'code')
        );
    }

    /**
     * The reason repair is addressed by durable id: an operator editing the URL
     * between the failed enqueue and the repair pass must not orphan it.
     */
    public function testRepairFollowsAnEndpointWhoseUrlChanged(): void
    {
        // Failure recorded against endpoint-0 while it pointed at the old URL.
        $this->configureEndpoints(['https://old.test/hook']);
        $this->insertResults['https://old.test/hook'] = false;
        FormDeliveryQueue::enqueue(11, 'sub-1');

        $refs = $this->repairPasses()[0]['args'][1];
        self::assertSame(['endpoint-0'], $refs);

        // The endpoint is re-pointed before the repair pass runs.
        $this->scheduled     = [];
        $this->storageErrors = [];
        $this->insertResults = [];
        $this->configureEndpoints(['https://new.test/hook']);

        FormDeliveryQueue::reconcile('sub-1', $refs, 1);

        self::assertSame([], $this->storageErrors, 'The endpoint must still be found by its durable id');

        $inserts = array_filter(
            $this->queries,
            static fn(array $q): bool => str_contains($q['sql'], 'https://new.test/hook')
        );
        self::assertNotSame([], $inserts, 'Repair must queue to the endpoint\'s CURRENT url');
    }

    /**
     * Erasure wins: a submission deleted between the failure and the repair is
     * never resurrected.
     */
    public function testRepairStopsWhenTheSubmissionWasDeleted(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->submissionRow = null;

        FormDeliveryQueue::reconcile('sub-1', ['endpoint-0'], 1);

        self::assertSame([], $this->inserts(), 'A deleted submission must not be re-queued');
    }

    public function testNoConfiguredEndpointsQueuesNothingAndReportsNoError(): void
    {
        $this->configureEndpoints([]);

        $outcome = FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertSame(0, $outcome->expected);
        self::assertTrue($outcome->isComplete());
        self::assertFalse($outcome->queuedAnything());
        self::assertSame([], $this->storageErrors);
    }

    // ── the durable repair record ────────────────────────────────────────────

    public function testAFailedEnqueueRecordsTheOwedDestinationsDurably(): void
    {
        $this->configureEndpoints(['https://a.test/hook', 'https://b.test/hook']);
        $this->insertResults['https://b.test/hook'] = false;

        FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertSame(
            ['endpoint-1'],
            FormDeliveryQueue::pendingRepairFor('sub-1'),
            'The record names exactly the destination whose row was verified absent'
        );
    }

    public function testACleanEnqueueRecordsNothing(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);

        FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertSame([], FormDeliveryQueue::pendingRepairFor('sub-1'));
        self::assertSame(
            [],
            $this->recordedSubmissions(),
            'A site with nothing outstanding must not carry a record at all'
        );
    }

    /**
     * The record is written BEFORE the cron is scheduled, because this is the
     * case where nothing else remembers the delivery is owed.
     */
    public function testTheRecordSurvivesAFailureToScheduleTheRepairCron(): void
    {
        Functions\when('wp_schedule_single_event')->justReturn(false);

        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;

        FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertSame(['endpoint-0'], FormDeliveryQueue::pendingRepairFor('sub-1'));
    }

    public function testASuccessfulRepairClearsTheRecord(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;
        FormDeliveryQueue::enqueue(11, 'sub-1');

        $this->insertResults = [];
        FormDeliveryQueue::reconcile('sub-1', ['endpoint-0'], 1);

        self::assertSame([], FormDeliveryQueue::pendingRepairFor('sub-1'));
    }

    public function testAFailedRepairKeepsTheRecord(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;
        FormDeliveryQueue::enqueue(11, 'sub-1');

        FormDeliveryQueue::reconcile('sub-1', ['endpoint-0'], 1);

        self::assertSame(['endpoint-0'], FormDeliveryQueue::pendingRepairFor('sub-1'));
    }

    /**
     * Abandonment ends the CRON CHAIN, not the obligation.
     */
    public function testAbandonmentKeepsTheRecordForTheSafetyNet(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;
        FormDeliveryQueue::enqueue(11, 'sub-1');

        FormDeliveryQueue::reconcile('sub-1', ['endpoint-0'], 3);

        self::assertContains('queue_repair_abandoned', array_column($this->storageErrors, 'code'));
        self::assertSame(
            ['endpoint-0'],
            FormDeliveryQueue::pendingRepairFor('sub-1'),
            'The retry chain is spent; the lead is still owed'
        );
    }

    /**
     * A destination that no longer exists is settled, not left to expire — and
     * certainly not re-queued daily against an endpoint nobody configured.
     */
    public function testARemovedEndpointSettlesTheRecord(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;
        FormDeliveryQueue::enqueue(11, 'sub-1');

        $this->configureEndpoints([]);
        FormDeliveryQueue::repairPending();

        self::assertSame([], FormDeliveryQueue::pendingRepairFor('sub-1'));
    }

    /**
     * Erasure wins over recovery, and takes the record with it.
     */
    public function testADeletedSubmissionForgetsItsRecord(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;
        FormDeliveryQueue::enqueue(11, 'sub-1');

        $this->submissionRow = null;
        FormDeliveryQueue::repairPending();

        self::assertSame([], FormDeliveryQueue::pendingRepairFor('sub-1'));
        self::assertSame([], $this->recordedSubmissions());
    }

    /**
     * The row is ordinary site data; a filter or a hand edit can put anything
     * in it.
     */
    public function testAMalformedRecordIsIgnoredRatherThanTrusted(): void
    {
        $this->options['cvm_queue_repair_sub-1'] = 'not-json-at-all';
        $this->options['cvm_queue_repair_sub-2'] = (string) json_encode(['at' => time(), 'refs' => [123, '']]);
        $this->options['cvm_queue_repair_sub-3'] = (string) json_encode(['refs' => ['endpoint-0']]);

        self::assertSame([], FormDeliveryQueue::pendingRepairFor('sub-1'));
        self::assertSame([], FormDeliveryQueue::pendingRepairFor('sub-2'), 'Non-string references are dropped');
        self::assertSame([], FormDeliveryQueue::pendingRepairFor('sub-3'), 'A record with no timestamp has expired');
    }

    /**
     * Each submission owns its own row.
     *
     * This pins the STRUCTURE, which is the part a test in a single-threaded
     * process can prove; it does not reproduce an interleaving. The interleaving
     * is why the structure matters: the first implementation kept every
     * obligation in one serialized option and rewrote the whole map on each
     * change, so two requests that each read the map before either wrote it
     * back — the normal shape of this path, since what puts submissions on it is
     * the queue table refusing writes for everyone at once — left only the
     * second one's obligation stored. Separate rows leave the two writers with
     * no shared value to race over, and the atomic INSERT ... ON DUPLICATE KEY
     * UPDATE against the unique option_name index is verified against real
     * MySQL in QueueRepairRecordTest.
     */
    public function testEachSubmissionOwnsItsOwnRecordRow(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;

        FormDeliveryQueue::enqueue(11, 'sub-A');
        FormDeliveryQueue::enqueue(12, 'sub-B');

        self::assertSame(['endpoint-0'], FormDeliveryQueue::pendingRepairFor('sub-A'));
        self::assertSame(['endpoint-0'], FormDeliveryQueue::pendingRepairFor('sub-B'));
        self::assertSame(
            ['sub-A', 'sub-B'],
            $this->recordedSubmissions(),
            'Two rows, not one map both requests rewrite'
        );
    }

    /**
     * No cap: an outage that costs a site hundreds of queue writes must not
     * quietly forget the older ones. The WORK is bounded, not the record set.
     */
    public function testEveryOutstandingObligationSurvivesRegardlessOfCount(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;

        for ($i = 0; $i < 250; $i++) {
            FormDeliveryQueue::enqueue(11, 'sub-' . $i);
        }

        self::assertCount(250, $this->recordedSubmissions());
        self::assertSame(['endpoint-0'], FormDeliveryQueue::pendingRepairFor('sub-0'), 'The oldest is still owed');
        self::assertSame(['endpoint-0'], FormDeliveryQueue::pendingRepairFor('sub-249'));
    }

    /**
     * update_option() returns false both for a failed write and for a value
     * that did not change, so its result cannot answer "did this land?". The
     * record is read back instead.
     */
    public function testARecordThatCannotBePersistedIsReportedNotAssumed(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;
        $this->optionWritesFail = true;

        FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertContains(
            'queue_repair_not_recorded',
            array_column($this->storageErrors, 'code'),
            'A repair obligation that could not be stored must be announced'
        );
    }

    /**
     * The failure mode that could re-send a delivered lead: the repair lands,
     * the worker delivers it and deletes the queue row, and the record cannot
     * be removed.
     */
    public function testARecordThatCannotBeClearedIsReported(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->seedRecord('sub-1', ['endpoint-0'], time());
        $this->optionWritesFail = true;

        FormDeliveryQueue::reconcile('sub-1', ['endpoint-0'], 1);

        self::assertContains('queue_repair_not_cleared', array_column($this->storageErrors, 'code'));
    }

    /**
     * The second, independent guard. Even with a record that outlived its own
     * deletion, an endpoint the Activity Log says was already attempted is
     * never re-queued — both guards would have to fail to send a lead twice.
     */
    public function testAnAlreadyAttemptedEndpointIsNeverRequeued(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->seedRecord('sub-1', ['endpoint-0'], time());
        $this->loggedAttempts['https://a.test/hook'] = true;

        FormDeliveryQueue::reconcile('sub-1', ['endpoint-0'], 1);

        self::assertSame([], $this->inserts(), 'A logged attempt means the delivery was made');
        self::assertSame([], FormDeliveryQueue::pendingRepairFor('sub-1'), 'and the obligation is settled');
    }

    // ── repairIfNeverQueued() ────────────────────────────────────────────────

    /**
     * The regression this record exists to prevent, first form.
     *
     * The submission was recorded on a site with webhooks switched off, so
     * nothing was ever queued and nothing was supposed to be. Endpoints are
     * added months later and the provider replays the original callback. The
     * previous gate — no queue row plus delivery_state 'not_sent' — matched
     * exactly this, and delivered a lead that was never meant to be sent.
     */
    public function testASubmissionRecordedBeforeWebhooksExistedIsNeverDelivered(): void
    {
        $this->configureEndpoints(['https://crm.test/hook']);
        $this->submissionRow = ['id' => 11, 'submission_id' => 'sub-1', 'delivery_state' => 'not_sent'];

        FormDeliveryQueue::repairIfNeverQueued('sub-1');

        self::assertSame([], $this->inserts(), 'Nothing was ever owed, so nothing may be queued');
        self::assertSame([], $this->scheduled);
        self::assertSame([], $this->storageErrors);
    }

    /**
     * The regression this record exists to prevent, second form.
     *
     * The webhook was DELIVERED: the worker deleted the queue row on the 2xx,
     * and the log row that would have remembered it was suppressed by a
     * 'convermetry_delivery_log_row' filter (a failed log INSERT does the
     * same). refreshDeliveryState() then settles the submission back on
     * 'not_sent' with no evidence either way — and the old gate would have
     * re-sent a lead the receiver had already processed.
     */
    public function testADeliveredSubmissionWithNoSurvivingEvidenceIsNeverResent(): void
    {
        $this->configureEndpoints(['https://crm.test/hook']);
        $this->submissionRow = ['id' => 11, 'submission_id' => 'sub-1', 'delivery_state' => 'not_sent'];
        $this->rowPresent['https://crm.test/hook'] = false;

        FormDeliveryQueue::repairIfNeverQueued('sub-1');

        self::assertSame([], $this->inserts(), 'Absence of evidence is not evidence the enqueue failed');
        self::assertSame([], $this->scheduled, 'Nor may it be repaired later');
        self::assertSame([], $this->storageErrors);
    }

    public function testARecordedFailureIsRepairedOnADuplicateCallback(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;
        FormDeliveryQueue::enqueue(11, 'sub-1');

        // The database recovers before the replayed callback arrives.
        $this->insertResults = [];
        $this->queries       = [];
        $this->scheduled     = [];
        $this->storageErrors = [];

        FormDeliveryQueue::repairIfNeverQueued('sub-1');

        self::assertSame(['queue_repair_on_duplicate'], array_column($this->storageErrors, 'code'));
        self::assertNotSame([], $this->inserts(), 'A destination verifiably owed a row gets one');
        self::assertSame([], FormDeliveryQueue::pendingRepairFor('sub-1'));
    }

    /**
     * An out-of-band repair reports and records; it does not open a second
     * backoff chain alongside the one already running.
     */
    public function testTheDuplicatePathDoesNotStartASecondCronChain(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;
        FormDeliveryQueue::enqueue(11, 'sub-1');

        $this->scheduled     = [];
        $this->storageErrors = [];

        FormDeliveryQueue::repairIfNeverQueued('sub-1');

        self::assertSame(
            ['queue_repair_on_duplicate', 'queue_repair_failed'],
            array_column($this->storageErrors, 'code')
        );
        self::assertSame([], $this->repairPasses(), 'One chain at a time');
        self::assertSame(['endpoint-0'], FormDeliveryQueue::pendingRepairFor('sub-1'));
    }

    // ── repairPending() ──────────────────────────────────────────────────────

    /**
     * The hole the record closes: the chain ends, or is never scheduled at all,
     * and the destination is still owed a row.
     */
    public function testTheSafetyNetQueuesWhatTheCronChainCouldNot(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->insertResults['https://a.test/hook'] = false;
        FormDeliveryQueue::enqueue(11, 'sub-1');
        FormDeliveryQueue::reconcile('sub-1', ['endpoint-0'], 3);

        self::assertSame(['endpoint-0'], FormDeliveryQueue::pendingRepairFor('sub-1'));

        $this->insertResults = [];
        $this->queries       = [];

        FormDeliveryQueue::repairPending();

        self::assertNotSame([], $this->inserts());
        self::assertSame([], FormDeliveryQueue::pendingRepairFor('sub-1'));
    }

    public function testTheSafetyNetDropsRecordsPastTheirWindow(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);
        $this->seedRecord('sub-old', ['endpoint-0'], time() - (8 * DAY_IN_SECONDS));

        FormDeliveryQueue::repairPending();

        self::assertSame([], FormDeliveryQueue::pendingRepairFor('sub-old'));
        self::assertSame([], $this->inserts(), 'A week-old lead is stale, not owed');
        self::assertSame([], $this->recordedSubmissions());

        // Giving up permanently is a terminal event, not a pruning side effect.
        self::assertSame(['queue_repair_expired'], array_column($this->storageErrors, 'code'));
    }

    /**
     * The pass is bounded by chunks and a wall clock, and pages with a CURSOR:
     * a record it settles is deleted, which under an OFFSET would shift every
     * later row and skip one per removal.
     */
    public function testTheSafetyNetPagesPastMoreRecordsThanOneChunkHolds(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);

        for ($i = 0; $i < 250; $i++) {
            $this->seedRecord('sub-' . $i, ['endpoint-0'], time());
        }

        FormDeliveryQueue::repairPending();

        self::assertSame([], $this->recordedSubmissions(), 'Every record is reached, not just the first chunk');
    }

    public function testTheSafetyNetIsAnInexpensiveNoOpWithNothingOutstanding(): void
    {
        $this->configureEndpoints(['https://a.test/hook']);

        FormDeliveryQueue::repairPending();

        self::assertSame([], $this->inserts());
        self::assertSame([], $this->recordedSubmissions());
    }
}
