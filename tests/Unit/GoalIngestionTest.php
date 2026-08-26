<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Database\PreparedEvent;
use Convermetry\Goals\GoalRecorder;
use Convermetry\Goals\GoalRepository;
use Convermetry\Goals\GoalSettings;
use Convermetry\Settings\Options;
use PHPUnit\Framework\TestCase;

/**
 * The ingestion plan: which events are stored, and which complete a goal.
 *
 * Goal matching sits in the middle of the write path every tracked event goes
 * through, so the property that matters most is not "goals work" but "goals
 * cannot break anything else". A site with goals switched off, or with none
 * configured, or with a goal whose rule matches nothing, must record page views
 * and clicks and conversions exactly as it did before the feature existed.
 *
 * The one event type that IS conditional is `custom_event`, and deliberately:
 * it exists only to be matched, so an unmatched one is dropped before insertion
 * rather than filling the events table with rows no report will ever read.
 */
final class GoalIngestionTest extends TestCase
{
    /** @var array<int, array<string, mixed>> Goals the stubbed option returns. */
    private array $storedGoals = [];

    /** @var bool Whether goal matching is switched on. */
    private bool $goalsEnabled = true;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_key')->alias(
            static fn(string $k): string => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $k))
        );
        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_transient')->justReturn(false);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('wp_generate_uuid4')->alias(static fn(): string => uniqid('uuid', true));
        Functions\when('wp_rand')->alias(static fn(): int => random_int(0, PHP_INT_MAX));

        Functions\when('get_option')->alias(function (string $key, $default = false) {
            if ($key === Options::GOALS_OPTION_KEY) {
                return $this->storedGoals;
            }
            if ($key === Options::OPTION_KEY) {
                return ['goals_enabled' => $this->goalsEnabled];
            }

            return $default;
        });

        GoalRepository::flushCache();
    }

    protected function tearDown(): void
    {
        GoalRepository::flushCache();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function goal(array $overrides = []): array
    {
        return array_merge(GoalSettings::blank(), [
            'goal_id'         => 'g' . str_repeat('a', 16),
            'name'            => 'Pricing page',
            'enabled'         => true,
            'type'            => 'url',
            'operator'        => 'contains',
            'value'           => '/pricing/',
            'definition_hash' => 'abcdef123456',
        ], $overrides);
    }

    /**
     * @param array<string, string> $row
     */
    private function event(array $row, int $seq = 0, string $customName = ''): PreparedEvent
    {
        return new PreparedEvent(
            row: array_merge(['event_type' => 'pageview', 'page_url' => 'https://example.com/', 'target_url' => ''], $row),
            seq: $seq,
            batchId: 'batch1234',
            eventUid: md5('batch1234|' . $seq),
            customEventName: $customName,
        );
    }

    // ── Goals must never break ordinary ingestion ────────────────────────────

    /**
     * @dataProvider inertConfigurations
     */
    public function testOrdinaryEventsAreNeverDroppedWhateverTheGoalConfiguration(
        bool $enabled,
        array $goals
    ): void {
        $this->goalsEnabled = $enabled;
        $this->storedGoals  = $goals;

        $events = [
            0 => $this->event(['event_type' => 'pageview', 'page_url' => 'https://example.com/about/']),
            1 => $this->event(['event_type' => 'click', 'target_url' => 'https://example.com/x'], 1),
            2 => $this->event(['event_type' => 'form_success'], 2),
            3 => $this->event(['event_type' => 'scroll_depth'], 3),
        ];

        $plan = GoalRecorder::plan($events);

        self::assertSame(
            [],
            $plan['drop'],
            'A goal configuration must never prevent an ordinary tracked event from being stored.'
        );
    }

    /**
     * @return array<string, array{0: bool, 1: array<int, array<string, mixed>>}>
     */
    public static function inertConfigurations(): array
    {
        return [
            'goals switched off'   => [false, []],
            'no goals configured'  => [true, []],
            'goal matching nothing' => [true, [array_merge(GoalSettings::blank(), [
                'goal_id'         => 'g' . str_repeat('b', 16),
                'name'            => 'Never fires',
                'enabled'         => true,
                'type'            => 'url',
                'operator'        => 'equals',
                'value'           => '/no-such-page/',
                'definition_hash' => 'aaaaaaaaaaaa',
            ])]],
            'goal disabled'        => [true, [array_merge(GoalSettings::blank(), [
                'goal_id'         => 'g' . str_repeat('c', 16),
                'name'            => 'Paused',
                'enabled'         => false,
                'type'            => 'url',
                'operator'        => 'contains',
                'value'           => '/',
                'definition_hash' => 'bbbbbbbbbbbb',
            ])]],
        ];
    }

    public function testSwitchingGoalsOffStopsAllMatching(): void
    {
        $this->goalsEnabled = false;
        $this->storedGoals  = [$this->goal()];

        $plan = GoalRecorder::plan([
            0 => $this->event(['page_url' => 'https://example.com/pricing/']),
        ]);

        self::assertSame([], $plan['matches'], 'Matching must not run when goals are switched off.');
        self::assertSame([], $plan['drop']);
    }

    public function testAMatchingEventIsPlannedAndStillStored(): void
    {
        $this->storedGoals = [$this->goal()];

        $plan = GoalRecorder::plan([
            0 => $this->event(['page_url' => 'https://example.com/pricing/plans/']),
        ]);

        self::assertArrayHasKey(0, $plan['matches']);
        self::assertCount(1, $plan['matches'][0]);
        self::assertSame([], $plan['drop'], 'A pageview that completed a goal is still a pageview.');
    }

    // ── Custom events are the one conditional type ───────────────────────────

    /**
     * The property that keeps one typo in a theme's JavaScript from filling the
     * events table with rows nothing will ever read.
     */
    public function testUnmatchedCustomEventsAreDroppedBeforeInsertion(): void
    {
        $this->storedGoals = [$this->goal([
            'type' => 'custom_event', 'operator' => 'name', 'value' => 'appointment_booked',
        ])];

        $plan = GoalRecorder::plan([
            0 => $this->event(['event_type' => 'custom_event'], 0, 'appointment_booked'),
            1 => $this->event(['event_type' => 'custom_event'], 1, 'typo_evnet_name'),
        ]);

        self::assertArrayHasKey(0, $plan['matches'], 'The matching custom event should be kept.');
        self::assertSame([1], $plan['drop'], 'The unmatched custom event should never be stored.');
    }

    public function testCustomEventsAreDroppedWhenNoGoalsExistAtAll(): void
    {
        $this->storedGoals = [];

        $plan = GoalRecorder::plan([
            0 => $this->event(['event_type' => 'custom_event'], 0, 'anything'),
            1 => $this->event(['event_type' => 'pageview'], 1),
        ]);

        self::assertSame([0], $plan['drop']);
    }

    public function testCustomEventsAreDroppedWhenGoalsAreSwitchedOff(): void
    {
        $this->goalsEnabled = false;
        $this->storedGoals  = [$this->goal([
            'type' => 'custom_event', 'operator' => 'name', 'value' => 'anything',
        ])];

        $plan = GoalRecorder::plan([
            0 => $this->event(['event_type' => 'custom_event'], 0, 'anything'),
        ]);

        self::assertSame([0], $plan['drop'], 'With matching off, a custom event can never mean anything.');
    }

    // ── Selectors reaching the browser ───────────────────────────────────────

    /**
     * The browser is told about selector goals and NOTHING else — not the names,
     * not the values, not goals of any other type.
     */
    public function testOnlySelectorGoalsReachTheBrowser(): void
    {
        $selectorId = 'g' . str_repeat('d', 16);

        $this->storedGoals = [
            $this->goal(),
            $this->goal([
                'goal_id'         => $selectorId,
                'name'            => 'Book now button',
                'type'            => 'click',
                'operator'        => 'selector',
                'value'           => '.book-now',
                'definition_hash' => 'cccccccccccc',
            ]),
            $this->goal([
                'goal_id'         => 'g' . str_repeat('e', 16),
                'name'            => 'Phone tapped',
                'type'            => 'click',
                'operator'        => 'tel',
                'value'           => '',
                'definition_hash' => 'dddddddddddd',
            ]),
        ];

        $shipped = GoalRepository::browserSelectors();

        self::assertSame([$selectorId => '.book-now'], $shipped);
    }

    public function testNoSelectorsAreShippedWhenGoalsAreOff(): void
    {
        $this->goalsEnabled = false;
        $this->storedGoals  = [$this->goal([
            'type' => 'click', 'operator' => 'selector', 'value' => '.book-now',
        ])];

        self::assertSame([], GoalRepository::browserSelectors());
    }

    // ── Tracking dependencies ────────────────────────────────────────────────

    /**
     * Drives the Goals screen's warning that a goal cannot fire because the
     * activity it is built on is not being tracked.
     */
    public function testGoalsReportTheEventTypeTheyDependOn(): void
    {
        $this->storedGoals = [
            $this->goal(),
            $this->goal([
                'goal_id'         => 'g' . str_repeat('f', 16),
                'type'            => 'click',
                'operator'        => 'tel',
                'value'           => '',
                'definition_hash' => 'eeeeeeeeeeee',
            ]),
        ];

        self::assertTrue(GoalRepository::needsEventType('pageview'));
        self::assertTrue(GoalRepository::needsEventType('click'));
        self::assertFalse(GoalRepository::needsEventType('custom_event'));
    }

    /**
     * A soft-deleted goal stops matching immediately but keeps its name, so
     * historical completions still render as something a human recognizes.
     */
    public function testSoftDeletedGoalsStopMatchingButKeepTheirName(): void
    {
        $this->storedGoals = [$this->goal(['deleted_at' => '2026-08-01 00:00:00'])];

        $plan = GoalRecorder::plan([
            0 => $this->event(['page_url' => 'https://example.com/pricing/']),
        ]);

        self::assertSame([], $plan['matches']);
        self::assertSame([], GoalRepository::visible());
        self::assertArrayHasKey('g' . str_repeat('a', 16), GoalRepository::names());
    }
}
