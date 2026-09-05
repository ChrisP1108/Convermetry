<?php
declare(strict_types=1);

namespace Convermetry\Tests\Integration;

use Convermetry\Webhook\FormDeliveryQueue;
use ReflectionMethod;

/**
 * The queue-repair record's storage, against a real MySQL server.
 *
 * A repair record is the only thing standing between a queue INSERT the
 * database refused and a lead nobody ever delivers, so how it is STORED is a
 * correctness concern rather than an implementation detail.
 *
 * Regression origin: every outstanding repair lived in one serialized option,
 * and each change rewrote the whole map — a read-modify-write with no
 * compare-and-swap. Two submissions failing to queue at the same moment each
 * built a map from a read taken before the other existed, and the second write
 * dropped the first's obligation entirely. That is the normal shape of this
 * path, not a load-test curiosity: what puts submissions on it is the queue
 * table refusing writes for everyone at once. The map also carried a hard cap,
 * so a long outage silently evicted the oldest obligations.
 *
 * The record is now one row per submission, written with INSERT ... ON
 * DUPLICATE KEY UPDATE against the unique option_name index and read back to
 * verify. That statement, the LIKE prefix scan the daily safety net pages
 * through, and its cursor are all SQL — the unit suite drives them through a
 * hand-rolled double that can only prove the plugin's own model of them.
 *
 * WHAT THIS DOES NOT COVER: the repair itself. Re-creating queue rows needs the
 * queue and Activity Log tables this harness does not install; that logic is
 * unit-covered in QueueDurabilityTest. Every path exercised here stops before a
 * queue row is touched.
 */
final class QueueRepairRecordTest extends IntegrationTestCase
{
    private const string PREFIX = 'cvm_queue_repair_';

    protected function setUp(): void
    {
        parent::setUp();

        $db = self::$db;
        if ($db === null) {
            return;
        }

        // The records are written straight into the options table, never
        // through the options API, so the harness needs one. Matches
        // WordPress's shape closely enough for the statements under test:
        // unique option_name, longtext value, autoincrement option_id.
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

        $db->query('DELETE FROM wp_options');
    }

    /**
     * @param list<string> $refs
     * @return array{refs: list<string>, at: int}|null
     */
    private function write(string $submissionId, array $refs, ?int $at = null): ?array
    {
        $method = new ReflectionMethod(FormDeliveryQueue::class, 'writeRepairRecord');

        /** @var array{refs: list<string>, at: int}|null $stored */
        $stored = $method->invoke(null, $submissionId, $refs, $at ?? time());

        return $stored;
    }

    private function rowCount(): int
    {
        $db = self::$db;
        self::assertNotNull($db);

        return (int) $db->get_var(
            "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'cvm\\_queue\\_repair\\_%'"
        );
    }

    public function testARecordIsWrittenAndReadBackVerbatim(): void
    {
        $stored = $this->write('sub-1', ['endpoint-a', 'endpoint-b']);

        self::assertNotNull($stored, 'The write must be observable, not assumed');
        self::assertSame(['endpoint-a', 'endpoint-b'], $stored['refs']);
        self::assertSame(['endpoint-a', 'endpoint-b'], FormDeliveryQueue::pendingRepairFor('sub-1'));
    }

    /**
     * The row is written non-autoloaded, so an outstanding repair never costs a
     * page load anything.
     */
    public function testTheRowIsNotAutoloaded(): void
    {
        $this->write('sub-1', ['endpoint-a']);

        $db = self::$db;
        self::assertNotNull($db);

        self::assertSame(
            'off',
            $db->get_var("SELECT autoload FROM wp_options WHERE option_name = '" . self::PREFIX . "sub-1'")
        );
    }

    /**
     * The statement is an upsert against the unique index, not an INSERT that
     * fails the second time and not a DELETE-then-INSERT another request can
     * interleave with.
     */
    public function testRewritingARecordReplacesItInPlace(): void
    {
        $this->write('sub-1', ['endpoint-a']);
        $stored = $this->write('sub-1', ['endpoint-b']);

        self::assertNotNull($stored);
        self::assertSame(['endpoint-b'], $stored['refs']);
        self::assertSame(1, $this->rowCount(), 'One submission, one row');
    }

    /**
     * The defect this storage model exists to remove. With a shared map, a
     * write for one submission rewrote the value the other's obligation lived
     * in; separate rows leave nothing for two writers to race over.
     */
    public function testOneSubmissionsWriteCannotDisturbAnothers(): void
    {
        $this->write('sub-A', ['endpoint-a']);
        $this->write('sub-B', ['endpoint-b']);
        $this->write('sub-A', ['endpoint-a', 'endpoint-c']);

        self::assertSame(['endpoint-a', 'endpoint-c'], FormDeliveryQueue::pendingRepairFor('sub-A'));
        self::assertSame(['endpoint-b'], FormDeliveryQueue::pendingRepairFor('sub-B'), 'Untouched by A');
        self::assertSame(2, $this->rowCount());
    }

    /**
     * No cap. A site that loses hundreds of queue writes during an outage must
     * still owe every one of them afterwards.
     */
    public function testHundredsOfObligationsAreAllRetained(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $this->write('sub-' . $i, ['endpoint-a']);
        }

        self::assertSame(300, $this->rowCount());
        self::assertSame(['endpoint-a'], FormDeliveryQueue::pendingRepairFor('sub-0'), 'The oldest is still owed');
        self::assertSame(['endpoint-a'], FormDeliveryQueue::pendingRepairFor('sub-299'));
    }

    /**
     * An expired record is never acted on, whatever the row still says.
     */
    public function testAnExpiredRecordReadsAsNothingOwed(): void
    {
        $this->write('sub-1', ['endpoint-a'], time() - (8 * DAY_IN_SECONDS));

        self::assertSame([], FormDeliveryQueue::pendingRepairFor('sub-1'));
        self::assertSame(1, $this->rowCount(), 'Reading does not delete; the safety net reports the expiry');
    }

    /**
     * The daily pass's LIKE + cursor + LIMIT scan, executed by a real server.
     *
     * Every record here is expired, so the pass takes its terminal branch:
     * announce, then delete. That reaches the scan, the decode and the delete
     * without needing the queue tables this harness does not install — and
     * proves the cursor pages past more records than one chunk holds, which an
     * OFFSET could not do while rows are being removed underneath it.
     */
    public function testTheSafetyNetScanReachesEveryRecordAcrossChunks(): void
    {
        for ($i = 0; $i < 250; $i++) {
            $this->write('sub-' . $i, ['endpoint-a'], time() - (8 * DAY_IN_SECONDS));
        }

        self::assertSame(250, $this->rowCount());

        FormDeliveryQueue::repairPending();

        self::assertSame(0, $this->rowCount(), 'Every expired record is reached, not just the first chunk');
    }

    /**
     * The prefix scan must not reach a neighbouring option whose name merely
     * starts with the same characters — which is what esc_like() is for, since
     * '_' is a single-character wildcard in LIKE.
     */
    public function testTheScanDoesNotReachOptionsOutsideThePrefix(): void
    {
        $db = self::$db;
        self::assertNotNull($db);

        $db->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('cvm_queue_repairs', 'legacy', 'off')");
        $db->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('cvm_settings', 'keep', 'yes')");

        $this->write('sub-1', ['endpoint-a'], time() - (8 * DAY_IN_SECONDS));

        FormDeliveryQueue::repairPending();

        self::assertSame(
            '2',
            $db->get_var("SELECT COUNT(*) FROM wp_options WHERE option_name IN ('cvm_queue_repairs', 'cvm_settings')"),
            'Only names under the repair prefix are the safety net\'s business'
        );
        self::assertSame(0, $this->rowCount());
    }
}
