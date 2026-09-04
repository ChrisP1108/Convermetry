<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Api\TrackingController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The database-backed rate-limit counter must fail CLOSED.
 *
 * Regression origin: the counter's UPDATE result was never checked, and when
 * the read-back did not produce the expected "window|count" value the code fell
 * back to treating THIS REQUEST'S OWN event count as the counter. That number is
 * far below any sane cap, so a counter that could not be written or read waved
 * every request through — silently, and precisely when a limiter matters most.
 *
 * The narrow case that made this reachable: the options-table counter failing
 * while the events table stays healthy. A broad outage takes the later insert
 * down too, but a write that fails only on the counter row does not.
 */
final class RateLimitFailClosedTest extends TestCase
{
    /** @var list<array{code: string}> */
    private array $storageErrors = [];

    private int|false $writeResult = 1;

    private string|null $storedValue = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->storageErrors = [];
        $this->writeResult   = 1;
        $this->storedValue   = null;

        // Force the database path rather than the object-cache path.
        Functions\when('wp_using_ext_object_cache')->justReturn(false);

        Monkey\Actions\expectDone('convermetry_storage_error')->zeroOrMoreTimes()
            ->whenHappen(function (string $subsystem, string $operation, string $code, array $context): void {
                $this->storageErrors[] = ['code' => $code];
            });

        $test = $this;

        $GLOBALS['wpdb'] = new class ($test) {
            public string $options = 'wp_options';

            public function __construct(private object $test)
            {
            }

            /** @param array<int, mixed> $args */
            public function prepare(string $sql, ...$args): string
            {
                return $sql;
            }

            public function query(string $sql): int|false
            {
                return $this->test->writeResult();
            }

            public function get_var(string $sql): ?string
            {
                return $this->test->storedValue();
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function writeResult(): int|false
    {
        return $this->writeResult;
    }

    public function storedValue(): ?string
    {
        return $this->storedValue;
    }

    private function charge(int $events, int $max): bool
    {
        $method = new ReflectionMethod(TrackingController::class, 'chargeBucket');

        return (bool) $method->invoke(null, 'cvm_rl_test', $events, $max);
    }

    /** The window the implementation will compute for "now". */
    private function currentWindow(): int
    {
        return intdiv(time(), 60);
    }

    public function testAcceptsWhenTheCounterIsUnderTheCap(): void
    {
        $this->storedValue = $this->currentWindow() . '|10';

        self::assertTrue($this->charge(5, 300));
        self::assertSame([], $this->storageErrors);
    }

    public function testRejectsWhenTheCounterExceedsTheCap(): void
    {
        $this->storedValue = $this->currentWindow() . '|301';

        self::assertFalse($this->charge(5, 300));
        self::assertSame([], $this->storageErrors, 'An ordinary rejection is not a storage failure');
    }

    /**
     * The core fix: a refused counter write must not let the request through.
     */
    public function testFailsClosedWhenTheCounterWriteIsRefused(): void
    {
        $this->writeResult = false;
        $this->storedValue = $this->currentWindow() . '|1';

        self::assertFalse($this->charge(5, 300), 'A refused charge must reject, not accept');
        self::assertSame(['counter_write_failed'], array_column($this->storageErrors, 'code'));
    }

    public function testFailsClosedWhenTheCounterCannotBeReadBack(): void
    {
        $this->storedValue = null;

        self::assertFalse($this->charge(5, 300));
        self::assertSame(['counter_unreadable'], array_column($this->storageErrors, 'code'));
    }

    public function testFailsClosedOnAnEmptyCounterValue(): void
    {
        $this->storedValue = '';

        self::assertFalse($this->charge(5, 300));
        self::assertSame(['counter_unreadable'], array_column($this->storageErrors, 'code'));
    }

    /**
     * @dataProvider malformedValues
     */
    public function testFailsClosedOnAMalformedCounterValue(string $value): void
    {
        $this->storedValue = $value;

        self::assertFalse($this->charge(5, 300), "'{$value}' must not be accepted as a counter");
        self::assertSame(['counter_malformed'], array_column($this->storageErrors, 'code'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedValues(): array
    {
        return [
            'no separator'    => ['12345'],
            'too many parts'  => ['1|2|3'],
            'non-numeric'     => ['abc|def'],
            'partial numeric' => ['12345|abc'],
        ];
    }

    /**
     * The window the row names does not decide anything.
     *
     * The window is computed by MySQL inside the charge statement, so PHP has
     * no clock to compare against and comparing anyway was itself a bug: a
     * request whose PHP-side window differed from the stored one was rejected
     * with a phantom 'counter_stale', which under concurrency happened on every
     * minute boundary. Whatever window the row holds, its count is the one this
     * request was added to.
     *
     * @dataProvider windowOffsets
     */
    public function testTheStoredWindowDoesNotAffectTheDecision(int $offset): void
    {
        $this->storedValue = ($this->currentWindow() + $offset) . '|3';

        self::assertTrue($this->charge(5, 300), 'A count under the cap is accepted in any window');
        self::assertSame([], $this->storageErrors, 'A differing window is not a storage failure');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function windowOffsets(): array
    {
        return [
            'same window'   => [0],
            'rolled over'   => [1],
            'older window'  => [-1],
        ];
    }

    /**
     * The cap is still enforced whichever window the row names.
     *
     * @dataProvider windowOffsets
     */
    public function testTheCapIsEnforcedInAnyWindow(int $offset): void
    {
        $this->storedValue = ($this->currentWindow() + $offset) . '|900';

        self::assertFalse($this->charge(5, 300));
    }

    /**
     * The specific shape of the old bug: a small event count must never be
     * mistaken for the counter and accepted.
     */
    public function testASmallChargeIsNotMistakenForTheCounter(): void
    {
        $this->writeResult = false;
        $this->storedValue = null;

        self::assertFalse($this->charge(1, 3000), 'One event against a 3000 cap must still fail closed');
    }
}
