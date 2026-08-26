<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Convermetry\Database\PreparedEvent;
use Convermetry\Goals\GoalMatcher;
use Convermetry\Goals\GoalSettings;
use PHPUnit\Framework\TestCase;

/**
 * Goal rule matching.
 *
 * GoalMatcher is pure by design, so this suite is the primary evidence that
 * goals work at all — everything downstream (dedup keys, completion rows,
 * reports) is arithmetic on top of whatever this decides.
 *
 * Two classes of assertion are load-bearing beyond the obvious rule coverage:
 *
 *  - The SECURITY boundary. Selector goals are the one rule a browser
 *    participates in, and a client must not be able to use that channel to
 *    claim any other kind of goal, a disabled goal, or a goal that does not
 *    exist.
 *  - The USABILITY cases that would otherwise silently under-count: a site
 *    owner typing "/thank-you/" rather than a full URL, a page linked as
 *    "/Pricing/" in one place and "/pricing/" in another, and a trailing slash
 *    that WordPress itself treats as the same page.
 */
final class GoalMatcherTest extends TestCase
{
    /**
     * Builds a normalized goal without going through WordPress sanitization.
     *
     * @param array<string, mixed> $overrides Fields to set.
     * @return array<string, mixed>
     */
    private function goal(array $overrides): array
    {
        return array_merge(GoalSettings::blank(), [
            'goal_id'         => 'g' . str_repeat('a', 16),
            'name'            => 'Test goal',
            'enabled'         => true,
            'definition_hash' => 'abcdef123456',
        ], $overrides);
    }

    /**
     * Builds an event envelope.
     *
     * @param array<string, string> $row     Row columns.
     * @param array<string, mixed>  $extras  Envelope extras.
     * @return PreparedEvent
     */
    private function event(array $row, array $extras = []): PreparedEvent
    {
        return new PreparedEvent(
            row: array_merge([
                'event_type' => 'pageview',
                'page_url'   => 'https://example.com/',
                'target_url' => '',
            ], $row),
            seq: 0,
            batchId: 'batch1234',
            eventUid: str_repeat('u', 32),
            landingPage: (string) ($extras['landing_page'] ?? ''),
            selectorGoals: (array) ($extras['selector_goals'] ?? []),
            customEventName: (string) ($extras['custom_event_name'] ?? ''),
            dynamicValue: $extras['dynamic_value'] ?? null,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $goals
     * @return list<string>
     */
    private function matchedIds(PreparedEvent $event, array $goals): array
    {
        $result = GoalMatcher::match($event, $goals);

        return array_map(static fn(array $g): string => (string) $g['goal_id'], $result['matched']);
    }

    // ── URL goals ────────────────────────────────────────────────────────────

    /**
     * @dataProvider urlRules
     */
    public function testUrlRules(string $operator, string $pattern, string $pageUrl, bool $expected): void
    {
        $goal  = $this->goal(['type' => 'url', 'operator' => $operator, 'value' => $pattern]);
        $event = $this->event(['event_type' => 'pageview', 'page_url' => $pageUrl]);

        self::assertSame($expected, $this->matchedIds($event, [$goal]) !== []);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: bool}>
     */
    public static function urlRules(): array
    {
        return [
            'equals path'              => ['equals', '/thank-you/', 'https://example.com/thank-you/', true],
            'equals path, no slash'    => ['equals', '/thank-you', 'https://example.com/thank-you/', true],
            'equals rule has slash'    => ['equals', '/thank-you/', 'https://example.com/thank-you', true],
            'equals wrong page'        => ['equals', '/thank-you/', 'https://example.com/pricing/', false],
            'equals is not prefix'     => ['equals', '/thank-you/', 'https://example.com/thank-you/extra/', false],
            'contains'                 => ['contains', '/schedule/', 'https://example.com/book/schedule/now/', true],
            'contains misses'          => ['contains', '/schedule/', 'https://example.com/book/', false],
            'starts with'              => ['starts_with', '/services/', 'https://example.com/services/tax/', true],
            'starts with misses'       => ['starts_with', '/services/', 'https://example.com/about/services/', false],
            'ends with'                => ['ends_with', '/confirmation/', 'https://example.com/order/confirmation/', true],
            'full url equals'          => ['equals', 'https://example.com/pricing/', 'https://example.com/pricing/', true],
            'full url wrong host'      => ['equals', 'https://other.com/pricing/', 'https://example.com/pricing/', false],
            'root path'                => ['equals', '/', 'https://example.com/', true],
        ];
    }

    /**
     * A site owner types the path they see in the address bar. Requiring the
     * scheme and host would make the obvious input wrong.
     */
    public function testAPathRuleIgnoresTheHost(): void
    {
        $goal = $this->goal(['type' => 'url', 'operator' => 'equals', 'value' => '/pricing/']);

        foreach (['https://example.com/pricing/', 'http://www.example.com:8080/pricing/'] as $url) {
            self::assertNotEmpty(
                $this->matchedIds($this->event(['page_url' => $url]), [$goal]),
                "Path rule failed against {$url}"
            );
        }
    }

    /**
     * A page linked as "/Pricing/" from one template and "/pricing/" from
     * another is one page. Case-sensitive matching would count roughly half of
     * what happened and look like data loss.
     */
    public function testUrlMatchingIsCaseInsensitive(): void
    {
        $goal  = $this->goal(['type' => 'url', 'operator' => 'equals', 'value' => '/Thank-You/']);
        $event = $this->event(['page_url' => 'https://example.com/thank-you/']);

        self::assertNotEmpty($this->matchedIds($event, [$goal]));
    }

    public function testAUrlGoalIgnoresNonPageviewEvents(): void
    {
        $goal = $this->goal(['type' => 'url', 'operator' => 'contains', 'value' => '/pricing/']);

        $click = $this->event([
            'event_type' => 'click',
            'page_url'   => 'https://example.com/pricing/',
        ]);

        self::assertSame([], $this->matchedIds($click, [$goal]));
    }

    // ── Click goals ──────────────────────────────────────────────────────────

    public function testPhoneAndEmailGoalsNeedNoConfiguredValue(): void
    {
        $tel    = $this->goal(['goal_id' => 'g' . str_repeat('1', 16), 'type' => 'click', 'operator' => 'tel', 'value' => '']);
        $mailto = $this->goal(['goal_id' => 'g' . str_repeat('2', 16), 'type' => 'click', 'operator' => 'mailto', 'value' => '']);

        $telClick = $this->event(['event_type' => 'click', 'target_url' => 'tel:+15551234567']);
        $mailClick = $this->event(['event_type' => 'click', 'target_url' => 'mailto:hello@example.com']);

        self::assertSame(['g' . str_repeat('1', 16)], $this->matchedIds($telClick, [$tel, $mailto]));
        self::assertSame(['g' . str_repeat('2', 16)], $this->matchedIds($mailClick, [$tel, $mailto]));
    }

    public function testTelMatchingIsSchemeAnchoredNotASubstring(): void
    {
        $tel = $this->goal(['type' => 'click', 'operator' => 'tel']);

        // A page about telephones is not a phone tap.
        $event = $this->event(['event_type' => 'click', 'target_url' => 'https://example.com/telephone/']);

        self::assertSame([], $this->matchedIds($event, [$tel]));
    }

    /**
     * @dataProvider externalTargets
     */
    public function testExternalLinkGoal(string $target, string $pageUrl, bool $expected): void
    {
        $goal  = $this->goal(['type' => 'click', 'operator' => 'external']);
        $event = $this->event(['event_type' => 'click', 'target_url' => $target, 'page_url' => $pageUrl]);

        self::assertSame($expected, $this->matchedIds($event, [$goal]) !== []);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function externalTargets(): array
    {
        return [
            'other site'        => ['https://calendly.com/book', 'https://example.com/', true],
            'same site'         => ['https://example.com/about/', 'https://example.com/', false],
            'relative path'     => ['/about/', 'https://example.com/', false],
            'different port'    => ['https://example.com:8443/x', 'https://example.com/', false],
            'subdomain differs' => ['https://shop.example.com/', 'https://example.com/', true],
            // A phone tap is not a visit to another website. Counting it as one
            // would double-count the single most common goal on the platform.
            'tel is not external'    => ['tel:+15551234567', 'https://example.com/', false],
            'mailto is not external' => ['mailto:a@b.com', 'https://example.com/', false],
        ];
    }

    public function testClickUrlContainsGoal(): void
    {
        $goal = $this->goal(['type' => 'click', 'operator' => 'contains', 'value' => '/brochure.pdf']);

        $hit  = $this->event(['event_type' => 'click', 'target_url' => 'https://example.com/files/brochure.pdf']);
        $miss = $this->event(['event_type' => 'click', 'target_url' => 'https://example.com/files/terms.pdf']);

        self::assertNotEmpty($this->matchedIds($hit, [$goal]));
        self::assertSame([], $this->matchedIds($miss, [$goal]));
    }

    // ── Selector goals: the browser-assisted case ────────────────────────────

    public function testSelectorGoalsHonorTheBrowsersReport(): void
    {
        $id   = 'g' . str_repeat('3', 16);
        $goal = $this->goal(['goal_id' => $id, 'type' => 'click', 'operator' => 'selector', 'value' => '.cta-primary']);

        $event = $this->event(
            ['event_type' => 'click', 'target_url' => 'https://example.com/x'],
            ['selector_goals' => [$id]]
        );

        self::assertSame([$id], $this->matchedIds($event, [$goal]));
    }

    public function testAnUnreportedSelectorGoalDoesNotMatch(): void
    {
        $goal = $this->goal(['type' => 'click', 'operator' => 'selector', 'value' => '.cta-primary']);

        $event = $this->event(['event_type' => 'click', 'target_url' => 'https://example.com/x']);

        self::assertSame([], $this->matchedIds($event, [$goal]));
    }

    /**
     * The security boundary. A client can report goal ids, so it must not be
     * able to reach a goal that is not a selector goal that way — otherwise any
     * visitor could fabricate a "Requested a quote" conversion by posting its id.
     */
    public function testAClientCannotClaimANonSelectorGoal(): void
    {
        $urlGoalId = 'g' . str_repeat('4', 16);
        $telGoalId = 'g' . str_repeat('5', 16);

        $goals = [
            $this->goal(['goal_id' => $urlGoalId, 'type' => 'url', 'operator' => 'equals', 'value' => '/quote-received/']),
            $this->goal(['goal_id' => $telGoalId, 'type' => 'click', 'operator' => 'tel']),
        ];

        $event = $this->event(
            ['event_type' => 'click', 'target_url' => 'https://example.com/', 'page_url' => 'https://example.com/'],
            ['selector_goals' => [$urlGoalId, $telGoalId]]
        );

        self::assertSame([], $this->matchedIds($event, $goals));
    }

    public function testAClientCannotClaimAnUnknownGoalId(): void
    {
        $goal = $this->goal(['type' => 'click', 'operator' => 'selector', 'value' => '.cta']);

        $event = $this->event(
            ['event_type' => 'click', 'target_url' => 'https://example.com/x'],
            ['selector_goals' => ['g' . str_repeat('9', 16)]]
        );

        self::assertSame([], $this->matchedIds($event, [$goal]));
    }

    public function testAClientCannotReviveADisabledSelectorGoal(): void
    {
        $id   = 'g' . str_repeat('6', 16);
        $goal = $this->goal([
            'goal_id'  => $id,
            'type'     => 'click',
            'operator' => 'selector',
            'value'    => '.cta',
            'enabled'  => false,
        ]);

        $event = $this->event(
            ['event_type' => 'click', 'target_url' => 'https://example.com/x'],
            ['selector_goals' => [$id]]
        );

        self::assertSame([], $this->matchedIds($event, [$goal]));
    }

    // ── Custom event goals ───────────────────────────────────────────────────

    public function testCustomEventGoalMatchesOnName(): void
    {
        $goal = $this->goal(['type' => 'custom_event', 'operator' => 'name', 'value' => 'appointment_booked']);

        $hit  = $this->event(['event_type' => 'custom_event'], ['custom_event_name' => 'appointment_booked']);
        $miss = $this->event(['event_type' => 'custom_event'], ['custom_event_name' => 'newsletter_signup']);

        self::assertNotEmpty($this->matchedIds($hit, [$goal]));
        self::assertSame([], $this->matchedIds($miss, [$goal]));
    }

    public function testCustomEventNameMatchingIgnoresCaseAndSurroundingSpace(): void
    {
        $goal = $this->goal(['type' => 'custom_event', 'operator' => 'name', 'value' => 'Appointment_Booked']);

        $event = $this->event(['event_type' => 'custom_event'], ['custom_event_name' => '  appointment_booked ']);

        self::assertNotEmpty($this->matchedIds($event, [$goal]));
    }

    public function testAnEmptyCustomEventNameMatchesNothing(): void
    {
        $goal = $this->goal(['type' => 'custom_event', 'operator' => 'name', 'value' => 'anything']);

        self::assertSame([], $this->matchedIds($this->event(['event_type' => 'custom_event']), [$goal]));
    }

    // ── Disabled, deleted, and malformed ─────────────────────────────────────

    public function testDisabledGoalsNeverMatch(): void
    {
        $goal = $this->goal([
            'type' => 'url', 'operator' => 'equals', 'value' => '/pricing/', 'enabled' => false,
        ]);

        self::assertSame([], $this->matchedIds($this->event(['page_url' => 'https://example.com/pricing/']), [$goal]));
    }

    public function testSoftDeletedGoalsNeverMatch(): void
    {
        $goal = $this->goal([
            'type' => 'url', 'operator' => 'equals', 'value' => '/pricing/',
            'deleted_at' => '2026-01-01 00:00:00',
        ]);

        self::assertSame([], $this->matchedIds($this->event(['page_url' => 'https://example.com/pricing/']), [$goal]));
    }

    /**
     * @dataProvider malformedGoals
     * @param mixed $goal
     */
    public function testMalformedGoalsAreSkippedRatherThanFatal(mixed $goal): void
    {
        $event = $this->event(['page_url' => 'https://example.com/pricing/']);

        $result = GoalMatcher::match($event, [$goal]);

        self::assertSame([], $result['matched']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function malformedGoals(): array
    {
        return [
            'not an array'      => ['nonsense'],
            'null'              => [null],
            'empty array'       => [[]],
            'unknown type'      => [['type' => 'telepathy', 'operator' => 'equals', 'value' => '/x/', 'enabled' => true]],
            'missing operator'  => [['type' => 'url', 'value' => '/pricing/', 'enabled' => true]],
            'empty pattern'     => [['type' => 'url', 'operator' => 'contains', 'value' => '', 'enabled' => true]],
        ];
    }

    /**
     * An empty pattern must never behave as "matches everything" — a
     * half-configured goal that silently counts every page view would corrupt
     * every report it appears in.
     */
    public function testAnEmptyPatternMatchesNothing(): void
    {
        foreach (['equals', 'contains', 'starts_with', 'ends_with'] as $operator) {
            $goal = $this->goal(['type' => 'url', 'operator' => $operator, 'value' => '']);

            self::assertSame(
                [],
                $this->matchedIds($this->event(['page_url' => 'https://example.com/anything/']), [$goal]),
                "Operator '{$operator}' matched an empty pattern."
            );
        }
    }

    // ── The match cap ────────────────────────────────────────────────────────

    public function testTheMatchCapIsAppliedAndReported(): void
    {
        $goals = [];
        for ($i = 0; $i < GoalMatcher::MAX_MATCHES_PER_EVENT + 3; $i++) {
            $goals[] = $this->goal([
                'goal_id'  => 'g' . str_pad((string) $i, 16, '0', STR_PAD_LEFT),
                'type'     => 'url',
                'operator' => 'contains',
                'value'    => '/pricing/',
            ]);
        }

        $result = GoalMatcher::match($this->event(['page_url' => 'https://example.com/pricing/']), $goals);

        self::assertCount(GoalMatcher::MAX_MATCHES_PER_EVENT, $result['matched']);
        self::assertSame(3, $result['overflow'], 'The overflow must be reported, not silently dropped.');
    }

    /**
     * The cap must cut the same way every time. A replayed batch that recorded a
     * different five goals than the original would not deduplicate against it,
     * and the site would count some conversions twice.
     */
    public function testTheMatchCapIsDeterministic(): void
    {
        $goals = [];
        for ($i = 0; $i < 9; $i++) {
            $goals[] = $this->goal([
                'goal_id'  => 'g' . str_pad((string) $i, 16, '0', STR_PAD_LEFT),
                'type'     => 'url',
                'operator' => 'contains',
                'value'    => '/pricing/',
            ]);
        }

        $event = $this->event(['page_url' => 'https://example.com/pricing/']);

        $first  = $this->matchedIds($event, $goals);
        $second = $this->matchedIds($event, $goals);

        self::assertSame($first, $second);
        self::assertSame(
            ['g0000000000000000', 'g0000000000000001', 'g0000000000000002', 'g0000000000000003', 'g0000000000000004'],
            $first,
            'The cap must keep the first goals in configuration order.'
        );
    }

    public function testNoGoalsConfiguredMatchesNothing(): void
    {
        $result = GoalMatcher::match($this->event(['page_url' => 'https://example.com/pricing/']), []);

        self::assertSame([], $result['matched']);
        self::assertSame(0, $result['overflow']);
    }
}
