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

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->queries       = [];
        $this->scheduled     = [];
        $this->storageErrors = [];
        $this->insertResults = [];
        $this->rowPresent    = [];

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

        $GLOBALS['wpdb'] = new class ($test) {
            public string $prefix = 'wp_';

            public function __construct(private object $test)
            {
            }

            /** @param array<int, mixed> $args */
            public function prepare(string $sql, ...$args): string
            {
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
                return $this->test->recordInsert($sql);
            }

            public function get_var(string $sql): string
            {
                return (string) $this->test->recordReadBack($sql);
            }

            /** @return array<int, mixed> */
            public function get_results(string $sql, string $output = 'ARRAY_A'): array
            {
                return [];
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

        Functions\when('get_option')->alias(
            static fn(string $key, $default = false) => $key === 'cvm_webhook_settings'
                ? ['endpoints' => $endpoints, 'shared_secret' => '']
                : $default
        );
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
        $outcome = new QueueOutcome(expected: 3, inserted: 2, failed: 1, failedKeys: ['k']);

        self::assertFalse($outcome->isComplete(), 'A dropped destination is not "complete"');
        self::assertTrue($outcome->queuedAnything(), 'Two destinations did receive it');
        self::assertSame(2, $outcome->durable());
    }

    public function testTelemetryCarriesCountsButNoEndpointDetail(): void
    {
        $outcome = new QueueOutcome(expected: 2, inserted: 1, failed: 1, failedKeys: ['abc']);
        $telemetry = $outcome->telemetry();

        self::assertSame(['expected' => 2, 'inserted' => 1, 'duplicate' => 0, 'failed' => 1], $telemetry);

        $encoded = (string) json_encode($telemetry);
        self::assertStringNotContainsString('http', $encoded, 'Telemetry must never carry an endpoint URL');
        self::assertStringNotContainsString('abc', $encoded, 'Telemetry must not leak endpoint keys');
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
        self::assertSame([md5('https://cloud.test/hook')], $outcome->failedKeys);
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
            [md5('https://b.test/hook')],
            $repair[0]['args'][1],
            'Repair must target only the endpoint that failed, never re-queue delivered ones'
        );
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

    public function testNoConfiguredEndpointsQueuesNothingAndReportsNoError(): void
    {
        $this->configureEndpoints([]);

        $outcome = FormDeliveryQueue::enqueue(11, 'sub-1');

        self::assertSame(0, $outcome->expected);
        self::assertTrue($outcome->isComplete());
        self::assertFalse($outcome->queuedAnything());
        self::assertSame([], $this->storageErrors);
    }
}
