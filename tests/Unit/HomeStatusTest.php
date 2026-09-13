<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Admin\HomeSetupStep;
use Convermetry\Admin\HomeStatus;
use Convermetry\Admin\HomeStatusLevel;
use PHPUnit\Framework\TestCase;

/**
 * What the Home page is allowed to claim about an installation.
 *
 * This is the one screen a site owner reads before they have any independent
 * way to check the plugin, which makes two mistakes expensive.
 *
 * The first is a green tick nobody earned. "Tracking is enabled" is not
 * "tracking works", and a setup checklist that ticks itself off on
 * configuration rather than on evidence tells an owner their data is being
 * collected when the tracker may never have fired. So every completed step and
 * every Active state here is pinned to something observed.
 *
 * The second is a failed read rendered as a zero. $wpdb answers a broken query
 * with null, and "0 submissions" is a perfectly plausible number for a new
 * site — so an unreadable table would look like a working, quiet one. Unknown
 * has to stay visibly unknown.
 *
 * The decision methods take plain facts and touch neither the database nor
 * WordPress, so these tests exercise the real rules rather than a mock's idea
 * of them.
 */
final class HomeStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('number_format_i18n')->alias(
            static fn(int|float $n): string => number_format((float) $n)
        );
        Functions\when('human_time_diff')->alias(
            static fn(int $from, int $to = 0): string => '5 mins'
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ------------------------------------------------------- analytics tracking

    public function testTrackingIsActiveOnlyOnceSomethingHasActuallyBeenRecorded(): void
    {
        $active = HomeStatus::analyticsTrackingState(true, true);

        self::assertSame(HomeStatusLevel::Success, $active->level);
        self::assertSame('Active', $active->label);
    }

    /**
     * The distinction this whole class exists for: configured is not working.
     */
    public function testTrackingEnabledButSilentIsNeutralRatherThanActive(): void
    {
        $awaiting = HomeStatus::analyticsTrackingState(true, false);

        self::assertSame(HomeStatusLevel::Neutral, $awaiting->level);
        self::assertSame('Awaiting Data', $awaiting->label);
        self::assertStringContainsString('No website activity has been recorded yet', $awaiting->description);
    }

    public function testTrackingWithEveryEventTypeSwitchedOffIsAWarning(): void
    {
        $off = HomeStatus::analyticsTrackingState(false, false);

        self::assertSame(HomeStatusLevel::Warning, $off->level);
        self::assertSame('Not Tracking', $off->label);
    }

    /**
     * A database that could not answer is not a site with no traffic.
     */
    public function testAnUnreadableEventsTableIsReportedAsUnknownNotAsSilence(): void
    {
        $unknown = HomeStatus::analyticsTrackingState(true, null);

        self::assertSame(HomeStatusLevel::Neutral, $unknown->level);
        self::assertSame('Unavailable', $unknown->label);
        self::assertStringNotContainsString('no website activity', strtolower($unknown->description));
    }

    // ------------------------------------------------------------ form tracking

    public function testNoFormPluginIsNeutralAndPointsAtTheDeveloperApi(): void
    {
        $none = HomeStatus::formTrackingState([]);

        self::assertSame(HomeStatusLevel::Neutral, $none->level);
        self::assertSame('No Providers Detected', $none->label);
        self::assertStringContainsString('developer API', $none->description);
    }

    public function testAvailableProvidersAreNamedSoTheOwnerCanSeeWhatWasDetected(): void
    {
        $some = HomeStatus::formTrackingState(['Gravity Forms', 'WPForms']);

        self::assertSame(HomeStatusLevel::Success, $some->level);
        self::assertSame('Active', $some->label);
        self::assertStringContainsString('Gravity Forms, WPForms', $some->description);
    }

    public function testALongProviderListIsSummarizedRatherThanRunningOffTheCard(): void
    {
        $many = HomeStatus::formTrackingState(['A Forms', 'B Forms', 'C Forms', 'D Forms', 'E Forms']);

        self::assertStringContainsString('A Forms, B Forms, C Forms and 2 more', $many->description);
    }

    // --------------------------------------------------------- webhook delivery

    public function testNoEndpointsIsNotConfiguredRatherThanFailing(): void
    {
        $none = HomeStatus::webhookDeliveryState(0, true, 0);

        self::assertSame(HomeStatusLevel::Neutral, $none->level);
        self::assertSame('Not Configured', $none->label);
    }

    /**
     * Endpoints saved with the master switch off is a real, silent trap: the
     * configuration looks complete and nothing is being sent.
     */
    public function testConfiguredEndpointsWithDeliverySwitchedOffReadAsPaused(): void
    {
        $paused = HomeStatus::webhookDeliveryState(2, false, 0);

        self::assertSame(HomeStatusLevel::Warning, $paused->level);
        self::assertSame('Paused', $paused->label);
    }

    public function testRecentFailuresRaiseAttentionAndSayHowMany(): void
    {
        $failing = HomeStatus::webhookDeliveryState(1, true, 3);

        self::assertSame(HomeStatusLevel::Warning, $failing->level);
        self::assertSame('Attention Required', $failing->label);
        self::assertStringContainsString('3 recent deliveries', $failing->description);

        $one = HomeStatus::webhookDeliveryState(1, true, 1);
        self::assertStringContainsString('1 recent delivery did not succeed', $one->description);
    }

    public function testAHealthyConfigurationReportsTheEndpointCount(): void
    {
        $ok = HomeStatus::webhookDeliveryState(2, true, 0);

        self::assertSame(HomeStatusLevel::Success, $ok->level);
        self::assertSame('Configured', $ok->label);
        self::assertStringContainsString('2 endpoints are configured', $ok->description);
    }

    /**
     * An unreadable delivery log must not be read as "no failures".
     */
    public function testAnUnknownFailureCountDoesNotSilentlyPassAsHealthy(): void
    {
        $unknown = HomeStatus::webhookDeliveryState(1, true, null);

        // It stays Success — endpoints exist and delivery is on, which is what
        // this card reports — but the copy makes no claim about outcomes.
        self::assertSame(HomeStatusLevel::Success, $unknown->level);
        self::assertStringNotContainsString('did not succeed', $unknown->description);
    }

    // ------------------------------------------------------------------- goals

    public function testNoGoalsIsNotConfiguredRatherThanFailing(): void
    {
        $none = HomeStatus::goalsState(0, 0, true);

        self::assertSame(HomeStatusLevel::Neutral, $none->level);
        self::assertSame('Not Configured', $none->label);
    }

    /**
     * Goals defined with the global matching switch off is the same silent
     * trap as an endpoint saved with delivery switched off: the screen looks
     * configured and nothing is actually being matched.
     */
    public function testGoalsDefinedWithMatchingSwitchedOffReadAsPaused(): void
    {
        $paused = HomeStatus::goalsState(2, 3, false);

        self::assertSame(HomeStatusLevel::Warning, $paused->level);
        self::assertSame('Paused', $paused->label);
        self::assertStringContainsString('goal matching is switched off', $paused->description);
    }

    /**
     * Matching is on globally, but every individual goal is switched off —
     * a different way of recording nothing that the global switch alone
     * cannot reveal.
     */
    public function testGoalsWithNoneIndividuallyEnabledReadAsPaused(): void
    {
        $paused = HomeStatus::goalsState(0, 3, true);

        self::assertSame(HomeStatusLevel::Warning, $paused->level);
        self::assertSame('Paused', $paused->label);
        self::assertStringContainsString('currently disabled', $paused->description);
    }

    public function testEnabledGoalsReportTheirCountWithoutClaimingConversions(): void
    {
        $ok = HomeStatus::goalsState(2, 3, true);

        self::assertSame(HomeStatusLevel::Success, $ok->level);
        self::assertSame('Enabled', $ok->label);
        self::assertStringContainsString('2 of 3 goals are enabled', $ok->description);

        // Configured must never be spelled as "recording" or "converting" —
        // this card cannot know that without running a report.
        self::assertStringNotContainsString('recorded', $ok->description);
        self::assertStringNotContainsString('convert', strtolower($ok->description));
    }

    public function testASingleEnabledGoalUsesSingularGrammar(): void
    {
        $one = HomeStatus::goalsState(1, 1, true);

        self::assertStringContainsString('1 of 1 goal is enabled', $one->description);
    }

    // ----------------------------------------------------------------- funnels

    public function testNoFunnelsIsNotConfiguredRatherThanFailing(): void
    {
        $none = HomeStatus::funnelsState(0, 0);

        self::assertSame(HomeStatusLevel::Neutral, $none->level);
        self::assertSame('Not Configured', $none->label);
    }

    /**
     * Unlike goals, funnels have no global matching switch — a funnel is a
     * question asked of activity already recorded, not something recorded on
     * its own — so this state can only be reached by every funnel's own
     * switch being off.
     */
    public function testFunnelsWithNoneEnabledReadAsPaused(): void
    {
        $paused = HomeStatus::funnelsState(0, 2);

        self::assertSame(HomeStatusLevel::Warning, $paused->level);
        self::assertSame('Paused', $paused->label);
        self::assertStringContainsString('currently disabled', $paused->description);
    }

    public function testEnabledFunnelsReportTheirCountWithoutClaimingResults(): void
    {
        $ok = HomeStatus::funnelsState(2, 2);

        self::assertSame(HomeStatusLevel::Success, $ok->level);
        self::assertSame('Enabled', $ok->label);
        self::assertStringContainsString('2 of 2 funnels are enabled', $ok->description);
        self::assertStringNotContainsString('convert', strtolower($ok->description));
    }

    public function testASingleEnabledFunnelUsesSingularGrammar(): void
    {
        $one = HomeStatus::funnelsState(1, 1);

        self::assertStringContainsString('1 of 1 funnel is enabled', $one->description);
    }

    // ----------------------------------------------------- background processing

    public function testEverythingScheduledIsRunningNormally(): void
    {
        $ok = HomeStatus::backgroundProcessingState(true, true, true, false);

        self::assertSame(HomeStatusLevel::Success, $ok->level);
        self::assertSame('Running Normally', $ok->label);
    }

    public function testAMissingRetentionEventIsNamedInTheDescription(): void
    {
        $broken = HomeStatus::backgroundProcessingState(false, true, true, false);

        self::assertSame(HomeStatusLevel::Warning, $broken->level);
        self::assertStringContainsString('data-retention task is not scheduled', $broken->description);
    }

    public function testAStalledQueueIsNamedInTheDescription(): void
    {
        $stalled = HomeStatus::backgroundProcessingState(true, false, true, false);

        self::assertSame(HomeStatusLevel::Warning, $stalled->level);
        self::assertStringContainsString('waiting in the delivery queue', $stalled->description);
    }

    public function testEveryProblemIsListedRatherThanOnlyTheFirst(): void
    {
        $bad = HomeStatus::backgroundProcessingState(false, false, false, false);

        self::assertStringContainsString('data-retention', $bad->description);
        self::assertStringContainsString('delivery queue', $bad->description);
        self::assertStringContainsString('analytics report delivery', $bad->description);
    }

    /**
     * Running cron from the system scheduler is a correct setup, not a fault.
     */
    public function testDisablingWpCronIsExplainedWithoutBeingTreatedAsAFailure(): void
    {
        $external = HomeStatus::backgroundProcessingState(true, true, true, true);

        self::assertSame(HomeStatusLevel::Success, $external->level);
        self::assertStringContainsString('WP-Cron is disabled', $external->description);
    }

    // --------------------------------------------------------------- the queue

    public function testTheQueueIsAMeasurementRatherThanAVerdict(): void
    {
        $queue = HomeStatus::deliveryQueueState(4);

        self::assertNull($queue->level, 'A count is not a state and must not get a coloured pill.');
        self::assertFalse($queue->isState());
        self::assertSame('4 pending', $queue->label);
    }

    public function testAnUnreadableQueueShowsADashRatherThanZeroPending(): void
    {
        self::assertSame('— pending', HomeStatus::deliveryQueueState(null)->label);
    }

    // ---------------------------------------------------------------- steps

    public function testOnlyTheFirstIncompleteStepIsMarkedAsNext(): void
    {
        $steps = HomeStatus::markNextStep([
            self::step('one', true),
            self::step('two', false),
            self::step('three', false),
            self::step('four', false),
        ]);

        self::assertSame([false, true, false, false], array_map(
            static fn(HomeSetupStep $s): bool => $s->current,
            $steps
        ));
    }

    public function testAFinishedChecklistPointsAtNothing(): void
    {
        $steps = HomeStatus::markNextStep([
            self::step('one', true),
            self::step('two', true),
        ]);

        self::assertSame([], array_filter($steps, static fn(HomeSetupStep $s): bool => $s->current));
        self::assertSame(2, HomeStatus::completedCount($steps));
    }

    public function testMarkingTheNextStepNeverChangesWhetherAStepIsDone(): void
    {
        $steps = HomeStatus::markNextStep([
            self::step('one', false),
            self::step('two', true),
        ]);

        self::assertFalse($steps[0]->complete);
        self::assertTrue($steps[1]->complete);
        self::assertSame(1, HomeStatus::completedCount($steps));
    }

    public function testAStepKeepsItsContentWhenItBecomesCurrent(): void
    {
        $original = new HomeSetupStep('Title', 'Body', 'Go', 'https://example.test', false);
        $current  = $original->asCurrent();

        self::assertSame('Title', $current->title);
        self::assertSame('Body', $current->description);
        self::assertSame('Go', $current->actionLabel);
        self::assertSame('https://example.test', $current->actionUrl);
        self::assertTrue($current->current);
        self::assertFalse($original->current, 'asCurrent() must not mutate the original.');
    }

    // ----------------------------------------------------------- formatting

    public function testAnUnknownFigureIsADashAndNotAZero(): void
    {
        self::assertSame('—', HomeStatus::figure(null));
        self::assertSame('0', HomeStatus::figure(0));
        self::assertSame('1,284', HomeStatus::figure(1284));
    }

    public function testNoSubmissionsReadsAsNoneYetRatherThanAsATimestamp(): void
    {
        self::assertSame('None yet', HomeStatus::elapsed(null));
    }

    public function testAStoredTimestampIsReadAsUtc(): void
    {
        $seen = [];

        Functions\when('human_time_diff')->alias(
            static function (int $from, int $to = 0) use (&$seen): string {
                $seen[] = $from;

                return '5 mins';
            }
        );

        self::assertSame('5 mins ago', HomeStatus::elapsed('2026-01-02 03:04:05'));
        self::assertSame([strtotime('2026-01-02 03:04:05 UTC')], $seen);
    }

    public function testAnUnparseableTimestampDoesNotProduceAnAbsurdAge(): void
    {
        self::assertSame('—', HomeStatus::elapsed('not a date'));
    }

    // ---------------------------------------------------------------- helpers

    private static function step(string $title, bool $complete): HomeSetupStep
    {
        return new HomeSetupStep($title, 'Body', 'Go', 'https://example.test', $complete);
    }
}
