<?php
declare(strict_types=1);

namespace Convermetry\Tests\Integration;

use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Goals\GoalCompletions;
use Convermetry\Leads\LeadEvents;

/**
 * The schema, as a real server actually builds it.
 *
 * SchemaShapeTest already checks that each table's DDL and its
 * migration-verification list describe the same table — but it does that by
 * reading the source. This runs the DDL and asks the server what it got, which
 * is the only way to catch a statement that parses in review and not in MySQL.
 *
 * It also proves the UNIQUE constraints genuinely deduplicate under INSERT
 * IGNORE. That property is the entire deduplication mechanism for goals,
 * submissions, and tracker batch replays, and its absence is completely silent:
 * INSERT IGNORE simply stops ignoring anything and every count inflates.
 */
final class SchemaTest extends IntegrationTestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function owners(): array
    {
        return [
            'events'           => [DatabaseManager::class, 'wp_cvm_events'],
            'form submissions' => [FormSubmissions::class, 'wp_cvm_form_submissions'],
            'goal completions' => [GoalCompletions::class, 'wp_cvm_goal_completions'],
            'lead events'      => [LeadEvents::class, 'wp_cvm_lead_events'],
        ];
    }

    /**
     * @dataProvider owners
     * @param class-string $owner
     */
    public function testEveryVerifiedColumnIsActuallyCreated(string $owner, string $table): void
    {
        $actual = $this->columnsOn($table);

        foreach ($owner::expectedColumns() as $column) {
            self::assertContains(
                $column,
                $actual,
                "{$table}: the migration verifies '{$column}' but the server did not create it, so the schema "
                . 'version could never be stamped and the migration would retry on every load forever.'
            );
        }
    }

    /**
     * @dataProvider owners
     * @param class-string $owner
     */
    public function testEveryVerifiedIndexIsActuallyCreated(string $owner, string $table): void
    {
        $actual = $this->indexesOn($table);

        foreach ($owner::expectedIndexes() as $index) {
            self::assertContains($index, $actual, "{$table}: index '{$index}' was not created.");
        }
    }

    // ── The constraints that carry correctness guarantees ────────────────────

    /**
     * Goal deduplication IS this index. Without it, every once-per-session goal
     * silently starts counting every occurrence.
     */
    public function testGoalCompletionsDeduplicateOnTheUniqueKey(): void
    {
        $insert = function (string $dedupe, string $completion): int|false {
            return self::$db->query(self::$db->prepare(
                'INSERT IGNORE INTO wp_cvm_goal_completions
                 (completion_id, goal_id, definition_hash, dedupe_key, event_uid, session_id, created_at)
                 VALUES (%s, %s, %s, %s, %s, %s, %s)',
                $completion,
                'g' . str_repeat('a', 16),
                'abcdef123456',
                $dedupe,
                str_repeat('e', 32),
                'sess0001',
                '2026-08-10 09:00:00'
            ));
        };

        self::assertSame(1, $insert(str_repeat('1', 32), str_repeat('a', 32)), 'The first completion stores.');
        self::assertSame(
            0,
            $insert(str_repeat('1', 32), str_repeat('b', 32)),
            'A second completion with the same dedupe key must be ignored — this is what makes five phone '
            . 'taps in one session count once.'
        );
        self::assertSame(1, $insert(str_repeat('2', 32), str_repeat('c', 32)), 'A different key stores.');

        self::assertSame('2', self::$db->get_var('SELECT COUNT(*) FROM wp_cvm_goal_completions'));
    }

    /**
     * A completion_id is a public identifier and must be unique independently of
     * the dedup key.
     */
    public function testCompletionIdsAreUnique(): void
    {
        $insert = fn(string $dedupe): int|false => self::$db->query(self::$db->prepare(
            'INSERT IGNORE INTO wp_cvm_goal_completions
             (completion_id, goal_id, definition_hash, dedupe_key, event_uid, session_id, created_at)
             VALUES (%s, %s, %s, %s, %s, %s, %s)',
            str_repeat('f', 32),
            'g' . str_repeat('a', 16),
            'abcdef123456',
            $dedupe,
            str_repeat('e', 32),
            'sess0001',
            '2026-08-10 09:00:00'
        ));

        self::assertSame(1, $insert(str_repeat('1', 32)));
        self::assertSame(0, $insert(str_repeat('2', 32)), 'A duplicate completion_id must be refused.');
    }

    /**
     * A double-fired provider callback for the same browser submission must
     * record once — the plugin's oldest deduplication guarantee.
     */
    public function testSubmissionsDeduplicateOnConversionId(): void
    {
        $this->insertSubmission(['submission_id' => 'subA', 'conversion_id' => 'convSHARED']);

        $stored = self::$db->query(self::$db->prepare(
            'INSERT IGNORE INTO wp_cvm_form_submissions (submission_id, conversion_id, created_at)
             VALUES (%s, %s, %s)',
            'subB',
            'convSHARED',
            '2026-08-10 09:00:00'
        ));

        self::assertSame(0, $stored);
        self::assertSame('1', self::$db->get_var('SELECT COUNT(*) FROM wp_cvm_form_submissions'));
    }

    /**
     * A replayed tracker batch must not double-count. Server-side events carry
     * a NULL batch_id, which a UNIQUE index never collides on — so they are
     * still inserted individually.
     */
    public function testEventBatchesDeduplicateButNullBatchIdsDoNot(): void
    {
        $insertBatched = fn(): int|false => self::$db->query(self::$db->prepare(
            'INSERT IGNORE INTO wp_cvm_events (event_type, page_url, session_id, batch_id, batch_seq, created_at)
             VALUES (%s, %s, %s, %s, %d, %s)',
            'pageview',
            'https://example.com/',
            'sess0001',
            'batch1234',
            0,
            '2026-08-10 09:00:00'
        ));

        self::assertSame(1, $insertBatched());
        self::assertSame(0, $insertBatched(), 'A replayed batch row must be ignored.');

        $insertServerSide = fn(): int|false => self::$db->query(self::$db->prepare(
            'INSERT INTO wp_cvm_events (event_type, page_url, session_id, batch_seq, created_at)
             VALUES (%s, %s, %s, %d, %s)',
            'form_success',
            'https://example.com/',
            'sess0001',
            0,
            '2026-08-10 09:00:00'
        ));

        self::assertSame(1, $insertServerSide());
        self::assertSame(
            1,
            $insertServerSide(),
            'Server-side events carry a NULL batch_id, which never collides — they must both store.'
        );
    }

    // ── Column semantics ─────────────────────────────────────────────────────

    /**
     * Every pre-existing submission must read as `new` the instant the column
     * lands, with no backfill pass at all.
     */
    public function testLeadStatusDefaultsToNewWithoutABackfill(): void
    {
        self::$db->query(self::$db->prepare(
            'INSERT INTO wp_cvm_form_submissions (submission_id, conversion_id, created_at) VALUES (%s, %s, %s)',
            'subDefault',
            'convDefault',
            '2026-08-10 09:00:00'
        ));

        self::assertSame(
            'new',
            self::$db->get_var("SELECT lead_status FROM wp_cvm_form_submissions WHERE submission_id = 'subDefault'")
        );
    }

    /**
     * "No value recorded" and "worth zero" are different answers, and the lead
     * reports read them differently. Only a nullable column keeps them apart.
     */
    public function testAnUnsetLeadValueIsNullNotZero(): void
    {
        self::$db->query(self::$db->prepare(
            'INSERT INTO wp_cvm_form_submissions (submission_id, conversion_id, created_at) VALUES (%s, %s, %s)',
            'subNull',
            'convNull',
            '2026-08-10 09:00:00'
        ));

        self::assertNull(
            self::$db->get_var("SELECT lead_value FROM wp_cvm_form_submissions WHERE submission_id = 'subNull'")
        );
    }

    /**
     * Money is DECIMAL, and the server must return it as an exact scaled value
     * rather than something that has been through a float.
     */
    public function testMonetaryValuesRoundTripExactly(): void
    {
        $this->insertSubmission(['submission_id' => 'subMoney', 'conversion_id' => 'convMoney', 'lead_value' => '12500.50']);

        self::assertSame(
            '12500.50',
            self::$db->get_var("SELECT lead_value FROM wp_cvm_form_submissions WHERE submission_id = 'subMoney'")
        );
    }

    /**
     * The classic float failure, demonstrated to be absent: summing 0.10 ten
     * thousand times over a DECIMAL column is exactly 1000.00.
     */
    public function testSummingSmallDecimalsDoesNotDrift(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $this->insertSubmission([
                'submission_id' => 'drift' . $i,
                'conversion_id' => 'driftc' . $i,
                'lead_value'    => '0.10',
            ]);
        }

        self::assertSame(
            '20.00',
            self::$db->get_var('SELECT SUM(lead_value) FROM wp_cvm_form_submissions'),
            'A DECIMAL sum must be exact; a float column would drift here.'
        );
    }

    /**
     * form_key is what ties browser-observed engagement to server-confirmed
     * submissions, so it must exist and be indexed on the events table.
     */
    public function testFormKeyIsPresentAndIndexed(): void
    {
        self::assertContains('form_key', $this->columnsOn('wp_cvm_events'));
        self::assertContains('form_type_date', $this->indexesOn('wp_cvm_events'));
        self::assertContains('session_type_id', $this->indexesOn('wp_cvm_events'));
    }
}
