<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Goals\GoalCompletions;
use Convermetry\Leads\LeadEvents;
use Convermetry\Notifications\NotificationQueue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Each table's DDL and its migration-verification list must describe the same
 * table.
 *
 * Every table owner in this plugin follows one pattern: run dbDelta, then verify
 * the columns and indexes actually landed, and only THEN record the schema
 * version — so a partial migration (out of disk, lost connection, a killed index
 * build on a large table) is retried instead of being marked complete.
 *
 * That pattern has one classic failure, and this suite exists for it: the DDL
 * and the verification list are two lists of the same thing, edited by hand, in
 * different parts of the file. Both directions of drift are silent and both are
 * bad:
 *
 *  - A name in the verification list that the DDL never creates means the check
 *    can NEVER pass. The version is never stamped, the migration runs again on
 *    every load forever, and every feature gated on that schema stays switched
 *    off with nothing reported anywhere.
 *  - An index in the DDL that the verification list omits means dbDelta may
 *    silently skip creating it and the version is stamped anyway. The table then
 *    permanently lacks an index something depends on — for the dedup indexes
 *    that means duplicate counting; for the reporting indexes it means a table
 *    scan on every dashboard load.
 *
 * These tests read the DDL out of the source rather than executing it. Nothing
 * in this suite has a database (see tests/bootstrap.php), and asserting against
 * a hand-rolled $wpdb mock would only prove the test author's model of MySQL.
 * Whether dbDelta genuinely produces this schema is an integration question and
 * is verified against a real server, not here.
 */
final class SchemaShapeTest extends TestCase
{
    /**
     * The owners that expose the standard verification pair.
     *
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function tableOwners(): array
    {
        return [
            'events'             => [DatabaseManager::class, 'cvm_events'],
            'form submissions'   => [FormSubmissions::class, 'cvm_form_submissions'],
            'goal completions'   => [GoalCompletions::class, 'cvm_goal_completions'],
            'lead events'        => [LeadEvents::class, 'cvm_lead_events'],
            'notification queue' => [NotificationQueue::class, 'cvm_notification_queue'],
        ];
    }

    /**
     * The CREATE TABLE body from a table owner's source.
     *
     * @param class-string $owner Table owner class.
     * @return string
     */
    private function ddl(string $owner): string
    {
        $source = (string) file_get_contents((string) (new ReflectionClass($owner))->getFileName());

        // Anchored on the assignment, not on the bare words "CREATE TABLE" —
        // every owner has a comment above its DB_VERSION constant reading "bump
        // when the CREATE TABLE below changes", and matching that instead
        // silently swallowed the whole class body, comments included. That made
        // the FLOAT check fail on a comment that says the word FLOAT.
        $start = strpos($source, '$sql = "CREATE TABLE');
        self::assertNotFalse($start, "{$owner} has no recognizable CREATE TABLE assignment.");

        $end = strpos($source, '{$charset};"', $start);
        self::assertNotFalse($end, "{$owner}'s CREATE TABLE has no recognizable end.");

        return substr($source, $start, $end - $start);
    }

    /**
     * Column names declared in a DDL body.
     *
     * @param string $ddl CREATE TABLE body.
     * @return string[]
     */
    private function ddlColumns(string $ddl): array
    {
        // A column line is "name TYPE ..."; index lines start with a keyword, so
        // they are excluded by the keyword filter below rather than by trying to
        // write one regex that understands both.
        preg_match_all('/^\s{2,}([a-z_]+)\s+[A-Z]/m', $ddl, $matches);

        return array_values(array_diff(
            $matches[1],
            ['primary', 'unique', 'key', 'index', 'create']
        ));
    }

    /**
     * Index names declared in a DDL body (excluding the primary key).
     *
     * @param string $ddl CREATE TABLE body.
     * @return string[]
     */
    private function ddlIndexes(string $ddl): array
    {
        preg_match_all('/(?:UNIQUE\s+)?KEY\s+([a-z_]+)\s*\(/i', $ddl, $matches);

        return $matches[1];
    }

    /**
     * @dataProvider tableOwners
     * @param class-string $owner
     */
    public function testEveryVerifiedColumnExistsInTheDdl(string $owner, string $table): void
    {
        $declared = $this->ddlColumns($this->ddl($owner));

        foreach ($owner::expectedColumns() as $column) {
            self::assertContains(
                $column,
                $declared,
                "{$table}: '{$column}' is verified after migration but never created by the DDL, "
                . 'so the schema version can never be stamped and the migration would retry forever.'
            );
        }
    }

    /**
     * @dataProvider tableOwners
     * @param class-string $owner
     */
    public function testEveryDdlColumnIsVerified(string $owner, string $table): void
    {
        $expected = $owner::expectedColumns();

        foreach ($this->ddlColumns($this->ddl($owner)) as $column) {
            self::assertContains(
                $column,
                $expected,
                "{$table}: the DDL creates '{$column}' but the migration never checks for it, "
                . 'so a dbDelta run that skipped it would still be recorded as complete.'
            );
        }
    }

    /**
     * @dataProvider tableOwners
     * @param class-string $owner
     */
    public function testEveryVerifiedIndexExistsInTheDdl(string $owner, string $table): void
    {
        $declared = $this->ddlIndexes($this->ddl($owner));

        foreach ($owner::expectedIndexes() as $index) {
            self::assertContains(
                $index,
                $declared,
                "{$table}: index '{$index}' is verified after migration but never created by the DDL."
            );
        }
    }

    /**
     * The uniqueness constraints that carry a correctness guarantee must be
     * verified explicitly, because their absence is completely silent.
     *
     * Without the index, INSERT IGNORE simply stops ignoring anything: goal
     * completions stop deduplicating (every once-per-session goal starts
     * counting every occurrence), submissions stop deduplicating (a double-fired
     * provider callback records the lead twice and delivers it twice), and
     * batch replays stop deduplicating (every metric inflates). Nothing errors.
     */
    public function testDedupIndexesAreVerifiedNotAssumed(): void
    {
        self::assertContains('batch_event', DatabaseManager::expectedIndexes());
        self::assertContains('conversion_id', FormSubmissions::expectedIndexes());
        self::assertContains('dedupe', GoalCompletions::expectedIndexes());
        self::assertContains('submission_recipient', NotificationQueue::expectedIndexes());
    }

    /**
     * The funnel step chain compares event ids within a session, and the goal
     * step reads completions the same way. Both need their composite index or
     * every funnel render becomes a table scan.
     */
    public function testFunnelOrderingIndexesAreVerified(): void
    {
        self::assertContains('session_type_id', DatabaseManager::expectedIndexes());
        self::assertContains('session_source', GoalCompletions::expectedIndexes());
    }

    /**
     * Money is DECIMAL, never FLOAT, in every table that stores it.
     *
     * A lead worth 0.10 recorded ten thousand times must total exactly 1000.00.
     * Binary floating point cannot promise that, and the resulting drift would
     * appear in a revenue figure someone reports to a client.
     */
    public function testMonetaryColumnsAreDecimal(): void
    {
        foreach ([GoalCompletions::class, LeadEvents::class, FormSubmissions::class] as $owner) {
            $ddl = $this->ddl($owner);

            self::assertDoesNotMatchRegularExpression(
                '/\b(FLOAT|DOUBLE|REAL)\b/i',
                $ddl,
                $owner . ' stores a numeric column as binary floating point.'
            );
        }

        self::assertMatchesRegularExpression('/value DECIMAL\(13,2\)/', $this->ddl(GoalCompletions::class));
        self::assertMatchesRegularExpression('/value DECIMAL\(13,2\)/', $this->ddl(LeadEvents::class));
        self::assertMatchesRegularExpression('/lead_value DECIMAL\(13,2\)/', $this->ddl(FormSubmissions::class));
    }

    /**
     * "No value recorded" and "worth zero" are different answers, and reports
     * read them differently — a nullable column is the only way to keep them
     * apart. lead_status is the opposite case: NOT NULL DEFAULT 'new' is what
     * makes every pre-existing row correct the instant the ALTER lands, with no
     * backfill pass required at all.
     */
    public function testLeadColumnsDistinguishUnsetFromZero(): void
    {
        $ddl = $this->ddl(FormSubmissions::class);

        self::assertMatchesRegularExpression('/lead_value DECIMAL\(13,2\) NULL/', $ddl);
        self::assertMatchesRegularExpression("/lead_status VARCHAR\(16\) NOT NULL DEFAULT 'new'/", $ddl);
    }
}
