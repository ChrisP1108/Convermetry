<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Goals\GoalSettings;
use PHPUnit\Framework\TestCase;

/**
 * Goal definition sanitization and versioning.
 *
 * Two properties here are correctness rather than hygiene:
 *
 *  - A goal's id is IMMUTABLE and never taken from submitted input. It is the
 *    join key for every completion ever recorded against that goal, so a form
 *    that could set it could re-point one goal at another's history.
 *  - Editing a goal's MATCHING RULE starts a new measurement series, while
 *    renaming or repricing it does not. Get that backwards in either direction
 *    and you either blend two different metrics into one chart, or reset a
 *    site's history because somebody fixed a typo in a label.
 */
final class GoalSettingsTest extends TestCase
{
    private const string NOW = '2026-08-25 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_text_field')->alias(static fn(string $v): string => trim(strip_tags($v)));
        Functions\when('sanitize_key')->alias(
            static fn(string $k): string => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $k))
        );
        Functions\when('wp_generate_uuid4')->alias(static fn(): string => uniqid('uuid', true));
        Functions\when('wp_rand')->alias(static fn(): int => random_int(0, PHP_INT_MAX));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function submitted(array $overrides = []): array
    {
        return array_merge([
            'name'             => 'Phone tapped',
            'type'             => 'click',
            'operator'         => 'tel',
            'value'            => '',
            'enabled'          => '1',
            'once_per_session' => '1',
        ], $overrides);
    }

    // ── Basic validation ─────────────────────────────────────────────────────

    public function testAValidGoalIsStored(): void
    {
        $goal = GoalSettings::sanitize($this->submitted(), null, self::NOW);

        self::assertNotNull($goal);
        self::assertSame('Phone tapped', $goal['name']);
        self::assertSame('click', $goal['type']);
        self::assertSame('tel', $goal['operator']);
        self::assertTrue($goal['enabled']);
        self::assertTrue($goal['once_per_session']);
        self::assertSame(self::NOW, $goal['created_at']);
        self::assertSame(1, $goal['version']);
        self::assertTrue(GoalSettings::isValidId($goal['goal_id']));
    }

    public function testAGoalWithoutANameIsRejected(): void
    {
        self::assertNull(GoalSettings::sanitize($this->submitted(['name' => '']), null, self::NOW));
        self::assertNull(GoalSettings::sanitize($this->submitted(['name' => '   ']), null, self::NOW));
    }

    public function testAnUnknownTypeOrOperatorIsRejected(): void
    {
        self::assertNull(GoalSettings::sanitize($this->submitted(['type' => 'telepathy']), null, self::NOW));
        self::assertNull(GoalSettings::sanitize($this->submitted(['operator' => 'sorcery']), null, self::NOW));
    }

    /**
     * An operator belonging to a different type must not be accepted — a "url"
     * goal with the "tel" operator would never match anything and would look
     * configured.
     */
    public function testAnOperatorFromAnotherTypeIsRejected(): void
    {
        self::assertNull(GoalSettings::sanitize(
            $this->submitted(['type' => 'url', 'operator' => 'tel']),
            null,
            self::NOW
        ));
    }

    /**
     * An empty pattern would match everything or nothing depending on the
     * operator, and the site owner asked for neither.
     */
    public function testARuleThatNeedsAValueIsRejectedWithoutOne(): void
    {
        foreach (['contains', 'equals', 'starts_with', 'ends_with'] as $operator) {
            self::assertNull(
                GoalSettings::sanitize(
                    $this->submitted(['type' => 'url', 'operator' => $operator, 'value' => '']),
                    null,
                    self::NOW
                ),
                "Operator '{$operator}' was accepted with no value."
            );
        }
    }

    public function testValuelessOperatorsAreAcceptedWithoutAValue(): void
    {
        foreach (GoalSettings::VALUELESS_OPERATORS as $operator) {
            $goal = GoalSettings::sanitize(
                $this->submitted(['type' => 'click', 'operator' => $operator, 'value' => 'ignored']),
                null,
                self::NOW
            );

            self::assertNotNull($goal);
            self::assertSame('', $goal['value'], "Operator '{$operator}' should discard any value.");
        }
    }

    // ── Immutable identity ───────────────────────────────────────────────────

    /**
     * The security-relevant one. A goal id submitted in a form must be ignored
     * entirely — otherwise editing goal A could be made to overwrite goal B's
     * identity, and B's whole completion history would silently re-attribute.
     */
    public function testASubmittedGoalIdIsNeverTrusted(): void
    {
        $existing = GoalSettings::sanitize($this->submitted(), null, self::NOW);
        self::assertNotNull($existing);

        $attacker = 'g' . str_repeat('f', 16);

        $edited = GoalSettings::sanitize(
            $this->submitted(['goal_id' => $attacker, 'name' => 'Renamed']),
            $existing,
            '2026-08-26 12:00:00'
        );

        self::assertNotNull($edited);
        self::assertSame(
            $existing['goal_id'],
            $edited['goal_id'],
            'A submitted goal id overwrote the stored one.'
        );
        self::assertNotSame($attacker, $edited['goal_id']);
    }

    public function testANewGoalGetsAFreshId(): void
    {
        $first  = GoalSettings::sanitize($this->submitted(), null, self::NOW);
        $second = GoalSettings::sanitize($this->submitted(), null, self::NOW);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first['goal_id'], $second['goal_id']);
    }

    public function testCreatedAtIsPreservedAcrossEdits(): void
    {
        $existing = GoalSettings::sanitize($this->submitted(), null, self::NOW);
        self::assertNotNull($existing);

        $edited = GoalSettings::sanitize($this->submitted(['name' => 'Renamed']), $existing, '2026-09-01 09:00:00');

        self::assertNotNull($edited);
        self::assertSame(self::NOW, $edited['created_at']);
        self::assertSame('2026-09-01 09:00:00', $edited['updated_at']);
    }

    // ── Definition versioning ────────────────────────────────────────────────

    /**
     * @dataProvider cosmeticEdits
     * @param array<string, mixed> $change
     */
    public function testCosmeticEditsDoNotStartANewSeries(array $change): void
    {
        $existing = GoalSettings::sanitize(
            $this->submitted(['type' => 'url', 'operator' => 'equals', 'value' => '/thank-you/']),
            null,
            self::NOW
        );
        self::assertNotNull($existing);

        $edited = GoalSettings::sanitize(
            $this->submitted(array_merge(
                ['type' => 'url', 'operator' => 'equals', 'value' => '/thank-you/'],
                $change
            )),
            $existing,
            self::NOW
        );

        self::assertNotNull($edited);
        self::assertSame($existing['definition_hash'], $edited['definition_hash']);
        self::assertSame($existing['version'], $edited['version']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cosmeticEdits(): array
    {
        return [
            'rename'            => [['name' => 'A better label']],
            'pause'             => [['enabled' => '']],
            'reprice'           => [['goal_value' => '500']],
            'counting behavior' => [['once_per_session' => '']],
        ];
    }

    /**
     * @dataProvider ruleEdits
     * @param array<string, mixed> $change
     */
    public function testChangingTheRuleStartsANewSeries(array $change): void
    {
        $existing = GoalSettings::sanitize(
            $this->submitted(['type' => 'url', 'operator' => 'equals', 'value' => '/thank-you/']),
            null,
            self::NOW
        );
        self::assertNotNull($existing);

        $edited = GoalSettings::sanitize(
            $this->submitted(array_merge(
                ['type' => 'url', 'operator' => 'equals', 'value' => '/thank-you/'],
                $change
            )),
            $existing,
            self::NOW
        );

        self::assertNotNull($edited);
        self::assertNotSame($existing['definition_hash'], $edited['definition_hash']);
        self::assertSame($existing['version'] + 1, $edited['version']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function ruleEdits(): array
    {
        return [
            'different page'     => [['value' => '/plans/']],
            'different operator' => [['operator' => 'contains']],
            'different type'     => [['type' => 'click', 'operator' => 'tel']],
        ];
    }

    // ── Value handling ───────────────────────────────────────────────────────

    public function testGoalValuesAreParsedAsExactDecimals(): void
    {
        $goal = GoalSettings::sanitize($this->submitted(['goal_value' => '$1,250.5']), null, self::NOW);

        self::assertNotNull($goal);
        self::assertSame('1250.50', $goal['goal_value']);
    }

    public function testAnAbsentValueStaysNullRatherThanBecomingZero(): void
    {
        $goal = GoalSettings::sanitize($this->submitted(['goal_value' => '']), null, self::NOW);

        self::assertNotNull($goal);
        self::assertNull(
            $goal['goal_value'],
            '"No value set" and "worth nothing" are different facts and reports read them differently.'
        );
    }

    /**
     * A dynamic value can only arrive on a custom event — a URL or a click has
     * nowhere to carry one, so offering the option elsewhere would be a control
     * that can never do anything.
     */
    public function testDynamicValuesAreOnlyMeaningfulForCustomEvents(): void
    {
        $click = GoalSettings::sanitize($this->submitted(['dynamic_value' => '1']), null, self::NOW);
        self::assertNotNull($click);
        self::assertFalse($click['dynamic_value']);

        $custom = GoalSettings::sanitize(
            $this->submitted([
                'type' => 'custom_event', 'operator' => 'name',
                'value' => 'booked', 'dynamic_value' => '1',
            ]),
            null,
            self::NOW
        );
        self::assertNotNull($custom);
        self::assertTrue($custom['dynamic_value']);
    }

    // ── Normalization of stored goals ────────────────────────────────────────

    public function testAStoredGoalMissingNewFieldsIsFilledIn(): void
    {
        // A goal written before a later version added fields to the shape.
        $normalized = GoalSettings::normalize([
            'goal_id'  => 'g' . str_repeat('a', 16),
            'name'     => 'Legacy goal',
            'type'     => 'url',
            'operator' => 'contains',
            'value'    => '/pricing/',
            'enabled'  => true,
        ]);

        self::assertNotNull($normalized);
        self::assertSame(1, $normalized['version']);
        self::assertSame(
            GoalSettings::definitionHash($normalized),
            $normalized['definition_hash'],
            'A goal stored before hashing existed must get the hash of its CURRENT rule — nothing changed, '
            . 'so this IS its original definition.'
        );
        self::assertNull($normalized['deleted_at']);
    }

    /**
     * @dataProvider unusableStoredGoals
     * @param mixed $stored
     */
    public function testUnusableStoredGoalsAreDiscarded(mixed $stored): void
    {
        self::assertNull(GoalSettings::normalize($stored));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableStoredGoals(): array
    {
        return [
            'not an array'   => ['nonsense'],
            'no id'          => [['name' => 'x', 'type' => 'url', 'operator' => 'equals', 'value' => '/x/']],
            'malformed id'   => [['goal_id' => 'not-an-id', 'type' => 'url', 'operator' => 'equals']],
            'unknown type'   => [['goal_id' => 'g' . str_repeat('a', 16), 'type' => 'nope', 'operator' => 'equals']],
            'wrong operator' => [['goal_id' => 'g' . str_repeat('a', 16), 'type' => 'url', 'operator' => 'tel']],
        ];
    }

    public function testActivityRequiresBothEnabledAndNotDeleted(): void
    {
        $base = GoalSettings::blank();

        self::assertTrue(GoalSettings::isActive(array_merge($base, ['enabled' => true, 'deleted_at' => null])));
        self::assertFalse(GoalSettings::isActive(array_merge($base, ['enabled' => false, 'deleted_at' => null])));
        self::assertFalse(GoalSettings::isActive(array_merge($base, ['enabled' => true, 'deleted_at' => self::NOW])));
    }

    /**
     * Each goal type is matched against exactly one event type. This mapping is
     * what the Goals screen uses to warn that a goal cannot fire because the
     * underlying tracking is switched off.
     */
    public function testEachTypeNamesTheActivityItNeeds(): void
    {
        self::assertSame('pageview', GoalSettings::requiredEventType(['type' => 'url']));
        self::assertSame('click', GoalSettings::requiredEventType(['type' => 'click']));
        self::assertSame('custom_event', GoalSettings::requiredEventType(['type' => 'custom_event']));
        self::assertSame('', GoalSettings::requiredEventType(['type' => 'nonsense']));
        self::assertSame('', GoalSettings::requiredEventType([]));
    }

    public function testNamesAndValuesAreBounded(): void
    {
        $goal = GoalSettings::sanitize(
            $this->submitted([
                'name'     => str_repeat('n', 500),
                'type'     => 'url',
                'operator' => 'contains',
                'value'    => str_repeat('v', 500),
            ]),
            null,
            self::NOW
        );

        self::assertNotNull($goal);
        self::assertSame(GoalSettings::MAX_NAME_LEN, mb_strlen($goal['name']));
        self::assertSame(GoalSettings::MAX_VALUE_LEN, mb_strlen($goal['value']));
    }
}
