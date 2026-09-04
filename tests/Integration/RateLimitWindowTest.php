<?php
declare(strict_types=1);

namespace Convermetry\Tests\Integration;

use Convermetry\Api\TrackingController;
use ReflectionMethod;

/**
 * The rate-limit counter's window arithmetic, against a real MySQL server.
 *
 * The unit suite mocks $wpdb, so it can prove the fail-closed BRANCHES are
 * taken but not that the atomic statement behaves correctly — the defect here
 * lived entirely inside the SQL and no mock could have caught it.
 *
 * Regression origin: the window was computed in PHP and interpolated into the
 * statement, and the "different window" branch replaced the row with that
 * PHP-side window unconditionally. A request that computed window N but reached
 * the server after one that had already written N+1 therefore reset the row
 * BACKWARDS to N, discarding the newer window's accumulated count. Two clients
 * straddling a minute boundary could flip the row back and forth, resetting the
 * total each time — under-counting a flood at exactly the moment the limit
 * matters most.
 *
 * The window is now computed by MySQL from its own clock inside the statement,
 * and the comparison is >= so a stored window at or ahead of the current one is
 * charged into rather than replaced.
 */
final class RateLimitWindowTest extends IntegrationTestCase
{
    private const string KEY = 'cvm_rl_integration_probe';

    protected function setUp(): void
    {
        parent::setUp();

        $db = self::$db;
        if ($db === null) {
            return;
        }

        // The plugin writes its counters straight into the options table, so
        // the harness needs one. Matches WordPress's shape closely enough for
        // the statement under test (unique option_name, longtext value).
        $db->query(
            'CREATE TABLE IF NOT EXISTS wp_options ('
            . ' option_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' option_name VARCHAR(191) NOT NULL DEFAULT "",'
            . ' option_value LONGTEXT NOT NULL,'
            . ' autoload VARCHAR(20) NOT NULL DEFAULT "yes",'
            . ' PRIMARY KEY (option_id),'
            . ' UNIQUE KEY option_name (option_name)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $db->query('DELETE FROM wp_options WHERE option_name = "' . self::KEY . '"');
    }

    private function charge(int $events, int $max): bool
    {
        $method = new ReflectionMethod(TrackingController::class, 'chargeBucket');

        return (bool) $method->invoke(null, self::KEY, $events, $max);
    }

    /** The window MySQL itself is currently in. */
    private function databaseWindow(): int
    {
        $db = self::$db;
        self::assertNotNull($db);

        return (int) $db->get_var('SELECT FLOOR(UNIX_TIMESTAMP() / 60)');
    }

    /** @return array{window: int, count: int} */
    private function storedCounter(): array
    {
        $db = self::$db;
        self::assertNotNull($db);

        $value = (string) $db->get_var(
            'SELECT option_value FROM wp_options WHERE option_name = "' . self::KEY . '"'
        );

        $parts = explode('|', $value);
        self::assertCount(2, $parts, "Counter value '{$value}' is not window|count");

        return ['window' => (int) $parts[0], 'count' => (int) $parts[1]];
    }

    private function seedCounter(int $window, int $count): void
    {
        $db = self::$db;
        self::assertNotNull($db);

        $db->query(
            'INSERT INTO wp_options (option_name, option_value, autoload) VALUES ("'
            . self::KEY . '", "' . $window . '|' . $count . '", "off")'
            . ' ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)'
        );
    }

    public function testAFirstChargeCreatesTheRowInTheDatabaseWindow(): void
    {
        self::assertTrue($this->charge(5, 300));

        $stored = $this->storedCounter();
        self::assertSame($this->databaseWindow(), $stored['window']);
        self::assertSame(5, $stored['count']);
    }

    public function testChargesAccumulateWithinOneWindow(): void
    {
        $this->charge(5, 300);
        $this->charge(7, 300);
        $this->charge(3, 300);

        self::assertSame(15, $this->storedCounter()['count'], 'Charges must add, not overwrite');
    }

    /**
     * The regression. A window AHEAD of the database clock must be charged
     * into, never replaced — replacing it is what discarded accumulated counts
     * at a minute boundary.
     */
    public function testAWindowAheadOfTheClockIsChargedIntoNotReset(): void
    {
        $ahead = $this->databaseWindow() + 1;
        $this->seedCounter($ahead, 100);

        $this->charge(5, 300);

        $stored = $this->storedCounter();
        self::assertSame($ahead, $stored['window'], 'The window must never move backwards');
        self::assertSame(105, $stored['count'], 'The accumulated count must survive');
    }

    /**
     * The same property stated as the invariant that matters: the stored window
     * is never behind where a previous charge left it.
     */
    public function testTheWindowIsMonotonicAcrossManyCharges(): void
    {
        $ahead = $this->databaseWindow() + 2;
        $this->seedCounter($ahead, 1);

        $previous = $ahead;
        for ($i = 0; $i < 5; $i++) {
            $this->charge(1, 300);
            $current = $this->storedCounter()['window'];

            self::assertGreaterThanOrEqual($previous, $current, 'Window went backwards');
            $previous = $current;
        }
    }

    /**
     * A genuinely older window is a closed window: the row moves forward and
     * the count restarts from this charge.
     */
    public function testAnOlderWindowResetsTheCounter(): void
    {
        $this->seedCounter($this->databaseWindow() - 5, 999);

        self::assertTrue($this->charge(4, 300), 'A closed window must not keep rejecting');

        $stored = $this->storedCounter();
        self::assertSame($this->databaseWindow(), $stored['window']);
        self::assertSame(4, $stored['count'], 'The stale count must not carry forward');
    }

    /**
     * The cap is enforced against genuinely accumulated volume.
     */
    public function testTheCapIsEnforcedOnceAccumulationExceedsIt(): void
    {
        self::assertTrue($this->charge(10, 25));
        self::assertTrue($this->charge(10, 25));
        self::assertFalse($this->charge(10, 25), 'Thirty events against a cap of 25 must reject');
    }

    /**
     * A corrupted value must not wedge the limiter permanently.
     */
    public function testAMalformedCounterSelfHeals(): void
    {
        $db = self::$db;
        self::assertNotNull($db);

        $db->query(
            'INSERT INTO wp_options (option_name, option_value, autoload) VALUES ("'
            . self::KEY . '", "not-a-counter", "off")'
            . ' ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)'
        );

        $this->charge(5, 300);

        $stored = $this->storedCounter();
        self::assertSame($this->databaseWindow(), $stored['window'], 'A bad value must reset to a good one');
        self::assertSame(5, $stored['count']);
    }
}
