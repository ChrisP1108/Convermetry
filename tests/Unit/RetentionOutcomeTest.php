<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Convermetry\Support\Retention;
use PHPUnit\Framework\TestCase;

/**
 * The retention pass outcome derivation.
 *
 * Five stores prune themselves on the daily cleanup cron, each with its own
 * chunked delete loop. Those loop CONDITIONS were deliberately not rewritten
 * when the retention hooks were added — they encode the deletion behaviour, and
 * a hook API is no reason to touch it. What the loops gained instead is this
 * pure derivation, applied after they exit, so "how many rows went, and are
 * there more?" is answered from state the loop already holds.
 *
 * The exact-chunk boundary is the case worth pinning: a final delete that
 * removed exactly one chunk means the loop stopped on its own budget, not
 * because the table was drained, so rows remain.
 */
final class RetentionOutcomeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testAPartialFinalChunkMeansTheTableIsDrained(): void
    {
        $outcome = Retention::outcome(137, 5000, 5137);

        self::assertSame(Retention::COMPLETED, $outcome['outcome']);
        self::assertFalse($outcome['more_remain']);
        self::assertSame(5137, $outcome['deleted']);
    }

    public function testAnEmptyFinalChunkMeansTheTableIsDrained(): void
    {
        $outcome = Retention::outcome(0, 5000, 0);

        self::assertSame(Retention::COMPLETED, $outcome['outcome']);
        self::assertFalse($outcome['more_remain']);
    }

    /**
     * The boundary. Deleting exactly CHUNK rows is what the loop condition
     * treats as "keep going", so seeing it on exit means the loop ran out of
     * chunk budget or wall-clock time with rows still older than the cutoff.
     */
    public function testAFullFinalChunkMeansRowsStillRemain(): void
    {
        $outcome = Retention::outcome(5000, 5000, 200000);

        self::assertSame(Retention::TRUNCATED, $outcome['outcome']);
        self::assertTrue($outcome['more_remain']);
        self::assertSame(200000, $outcome['deleted']);
    }

    /**
     * A failed DELETE tells us nothing about what is left, so the honest answer
     * is "assume more remain" — under-reporting would let a monitoring listener
     * conclude retention had finished when it had not.
     *
     * @dataProvider failedQueryReturns
     */
    public function testANonIntegerResultIsAQueryFailure(mixed $returned): void
    {
        $outcome = Retention::outcome($returned, 5000, 12);

        self::assertSame(Retention::QUERY_FAILED, $outcome['outcome']);
        self::assertTrue($outcome['more_remain']);
        self::assertSame(12, $outcome['deleted']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function failedQueryReturns(): array
    {
        return [
            'false from $wpdb->query' => [false],
            'null'                    => [null],
            'string'                  => ['0'],
        ];
    }

    public function testALostLeaseReportsWhatItManagedAndAssumesMoreRemain(): void
    {
        $outcome = Retention::lockLost(4200);

        self::assertSame(Retention::LOCK_LOST, $outcome['outcome']);
        self::assertTrue($outcome['more_remain']);
        self::assertSame(4200, $outcome['deleted']);
    }

    public function testTheStartAndCompletionActionsCarryTheStoreAndCutoff(): void
    {
        $fired = [];
        Monkey\Functions\when('do_action')->alias(
            static function (string $hook, mixed ...$args) use (&$fired): void {
                $fired[] = [$hook, $args];
            }
        );

        Retention::started('events', '2026-05-28 00:00:00');
        Retention::completed('events', '2026-05-28 00:00:00', Retention::outcome(5000, 5000, 5000));

        self::assertSame(
            ['convermetry_retention_cleanup_started', 'convermetry_retention_cleanup_completed'],
            array_column($fired, 0)
        );
        self::assertSame(['events', '2026-05-28 00:00:00'], $fired[0][1]);
        self::assertSame(
            ['events', '2026-05-28 00:00:00', 5000, true, Retention::TRUNCATED],
            $fired[1][1]
        );
    }
}
