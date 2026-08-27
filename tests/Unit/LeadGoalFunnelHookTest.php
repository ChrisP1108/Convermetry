<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Leads\LeadEvents;
use PHPUnit\Framework\TestCase;

/**
 * Hooks around goals, funnels, and lead outcomes.
 *
 * The lead-history id is the interesting problem this file pins. A listener
 * that wants to correlate a status change with the row recording it needs that
 * row's id, but the id is generated inside a transaction whose shape is
 * load-bearing — the submission update and the history insert commit together
 * or not at all — and reading it back afterwards would mean another query.
 *
 * So it is minted BEFORE the transaction and passed down. Nothing about what
 * gets stored changes: the same two writes happen in the same order with the
 * same rollback conditions, and the id is simply decided one step earlier so it
 * can be reported once the commit has landed.
 *
 * The other rule here is that the repository, not the admin screen, is where
 * save and delete are announced. Both admin pages discard the repository's
 * return value and redirect with a success notice regardless, so an action
 * fired from the page would announce deletions of goals that never existed.
 */
final class LeadGoalFunnelHookTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../';

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

    // ------------------------------------------------------- lead event id

    public function testAMintedIdIsAStableThirtyTwoCharacterHexString(): void
    {
        Functions\when('wp_generate_uuid4')->justReturn('11111111-2222-3333-4444-555555555555');
        Functions\when('wp_rand')->justReturn(7);

        $id = LeadEvents::mintId();

        self::assertMatchesRegularExpression('~^[0-9a-f]{32}$~', $id);
        self::assertSame(md5('11111111-2222-3333-4444-555555555555' . '7'), $id);
    }

    /**
     * Source-contract: the id is decided before the transaction opens, so the
     * action that reports it can fire after the commit without a second query.
     */
    public function testTheHistoryIdIsMintedBeforeTheTransactionAndReportedAfterIt(): void
    {
        $service = (string) file_get_contents(self::PLUGIN_DIR . 'src/Leads/LeadService.php');
        $update  = substr($service, (int) strpos($service, 'public static function update('), 5000);

        $mint    = strpos($update, 'LeadEvents::mintId()');
        $store   = strpos($update, 'FormSubmissions::updateLead(');
        $legacy  = strpos($update, "do_action('convermetry_lead_status_updated'");
        $updated = strpos($update, "'convermetry_lead_updated'");

        self::assertIsInt($mint);
        self::assertIsInt($store);
        self::assertIsInt($legacy);
        self::assertIsInt($updated);

        self::assertLessThan($store, $mint, 'the id must be decided before the write');
        self::assertGreaterThan($store, $legacy, 'the existing action must still fire after the commit');
        self::assertGreaterThan($legacy, $updated, 'the new action must fire after the existing one');
    }

    /**
     * Source-contract. The transaction's shape is the guarantee that a lead can
     * never be half-moved; threading an id through must not have disturbed the
     * order of its four statements or either rollback.
     */
    public function testTheLeadTransactionShapeIsUnchanged(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Database/FormSubmissions.php');
        $method = substr($source, (int) strpos($source, 'public static function updateLead('), 3000);

        $begin   = strpos($method, "\$wpdb->query('START TRANSACTION')");
        $update  = strpos($method, '$wpdb->update(');
        $history = strpos($method, 'LeadEvents::record(');
        $commit  = strpos($method, "\$wpdb->query('COMMIT')");

        self::assertIsInt($begin);
        self::assertIsInt($update);
        self::assertIsInt($history);
        self::assertIsInt($commit);

        self::assertLessThan($update, $begin);
        self::assertLessThan($history, $update);
        self::assertLessThan($commit, $history);
        self::assertSame(2, substr_count($method, "\$wpdb->query('ROLLBACK')"), 'both rollback paths must remain');
    }

    /**
     * The existing action's five arguments are a fixed contract. Everything new
     * went into a second action rather than being appended to it.
     */
    public function testTheExistingLeadActionKeepsItsExactArguments(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Leads/LeadService.php');

        self::assertStringContainsString(
            "do_action('convermetry_lead_status_updated', \$submissionId, \$toStatus, \$fromStatus, \$newValue, \$currency);",
            $source
        );
    }

    // ------------------------------------------------- goals: decision filter

    /**
     * Source-contract. The decision filter is a boolean gate: whatever it
     * returns, the row it was shown is the row that gets offered. If its result
     * were merged into the row, a callback could rewrite the deduplication key
     * and silently defeat once-per-session goals.
     */
    public function testTheGoalDecisionFilterCannotRewriteTheCompletionRow(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Goals/GoalRecorder.php');
        $record = substr($source, (int) strpos($source, 'public static function record('), 5000);

        self::assertMatchesRegularExpression(
            "~if \(apply_filters\('convermetry_should_record_goal_completion', true, \\\$row, \\\$goal\)\) \{\s*\\\$rows\[\] = \\\$row;~",
            $record,
            'The filter must gate the unmodified row, never replace it.'
        );
    }

    /**
     * The existing batch action must keep firing, unchanged, immediately before
     * the new one — integrations already listen on it.
     */
    public function testTheExistingGoalActionStillFiresFirstWithItsOriginalArguments(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Goals/GoalRecorder.php');

        $existing = strpos($source, "do_action('convermetry_goal_matched', \$stored, \$rows);");
        $new      = strpos($source, "'convermetry_goal_completions_recorded'");
        $insert   = strpos($source, 'GoalCompletions::insertMany(');

        self::assertIsInt($existing);
        self::assertIsInt($new);
        self::assertIsInt($insert);
        self::assertGreaterThan($insert, $existing, 'both actions follow the INSERT');
        self::assertGreaterThan($existing, $new);
    }

    // -------------------------------------- goals/funnels: save and delete

    /**
     * Source-contract, for both repositories. The admin pages discard these
     * return values and redirect with a success notice either way, so firing
     * from the page would announce deletions of records that never existed and
     * saves that hit the cap and were refused.
     *
     * @dataProvider repositories
     */
    public function testSaveAndDeleteAreAnnouncedFromTheRepositoryAndOnlyOnSuccess(
        string $file,
        string $savedHook,
        string $deletedHook
    ): void {
        $source = (string) file_get_contents(self::PLUGIN_DIR . $file);

        $save = substr($source, (int) strpos($source, 'public static function save('), 3000);
        self::assertStringContainsString("do_action('{$savedHook}'", $save);
        self::assertMatchesRegularExpression(
            '~if \(!self::persist\(\$\w+\)\) \{\s*return false;\s*\}~',
            $save,
            'a refused save must fire nothing'
        );

        $delete = substr($source, (int) strpos($source, 'public static function softDelete('), 2500);
        self::assertStringContainsString("do_action('{$deletedHook}'", $delete);
        self::assertMatchesRegularExpression(
            '~if \(!\$found \|\| !self::persist\(\$\w+\)\) \{\s*return false;\s*\}~',
            $delete,
            'deleting a record that never existed must fire nothing'
        );
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function repositories(): array
    {
        return [
            'goals'   => [
                'src/Goals/GoalRepository.php',
                'convermetry_goal_saved',
                'convermetry_goal_deleted',
            ],
            'funnels' => [
                'src/Funnels/FunnelRepository.php',
                'convermetry_funnel_saved',
                'convermetry_funnel_deleted',
            ],
        ];
    }

    /**
     * The admin pages must not have grown their own copies of these actions —
     * two sources for one event is worse than one in the wrong place.
     *
     * @dataProvider adminPages
     */
    public function testAdminPagesDoNotFireRepositoryEventsThemselves(string $file): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . $file);

        foreach (['_saved', '_deleted'] as $suffix) {
            self::assertSame(
                0,
                preg_match("~do_action\('convermetry_(goal|funnel){$suffix}'~", $source),
                "{$file} fires a repository event the repository already fires."
            );
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function adminPages(): array
    {
        return [
            'goals'   => ['src/Admin/GoalsPage.php'],
            'funnels' => ['src/Admin/FunnelsPage.php'],
        ];
    }
}
