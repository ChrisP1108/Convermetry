<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Database\PreparedEvent;
use Convermetry\Goals\GoalRecorder;
use Convermetry\Goals\GoalSettings;
use Convermetry\Leads\LeadService;
use Convermetry\Leads\Money;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The amounts Convermetry records against a goal or a lead are never below zero.
 *
 * Regression origin: Convermetry.track('booking', { value: -5 }) stored a
 * completion worth -5.00 on a goal that accepts a dynamic value, lowering the
 * goal's attributed value. That number comes from page JavaScript, so a
 * negative one is a bug in the site's code or someone tampering. Staff could
 * type a negative lead value too (-50 → -50.00), which was subtracted from
 * Attributed Lead Value and Revenue for the whole cohort.
 *
 * Money::parse() keeps the sign — it is a parser, not a policy — so each place
 * that records an amount refuses a negative one itself, through
 * Money::isNegative().
 */
final class GoalValueTest extends TestCase
{
    /** Every write the fake database was asked to make. */
    private array $writes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->writes = [];

        Functions\when('sanitize_text_field')->alias(static fn(string $v): string => trim(strip_tags($v)));
        Functions\when('sanitize_key')->alias(
            static fn(string $k): string => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $k))
        );
        Functions\when('wp_generate_uuid4')->alias(static fn(): string => uniqid('uuid', true));
        Functions\when('wp_rand')->alias(static fn(): int => random_int(0, PHP_INT_MAX));
        Functions\when('get_option')->justReturn([]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── A value supplied by page JavaScript ─────────────────────────────────

    /**
     * The value a completion records, through the recorder's own resolution.
     *
     * @param array<string, mixed> $goal
     */
    private function resolve(?string $supplied, array $goal): ?string
    {
        $event = new PreparedEvent(
            row: ['event_type' => 'custom_event', 'page_url' => 'https://example.com/', 'target_url' => ''],
            seq: 0,
            batchId: 'batch1234',
            eventUid: str_repeat('u', 32),
            customEventName: 'booking',
            dynamicValue: $supplied,
        );

        $method = new ReflectionMethod(GoalRecorder::class, 'resolveValue');

        /** @var string|null $value */
        $value = $method->invoke(null, $event, $goal);

        return $value;
    }

    /** A custom-event goal worth 100.00 that accepts a value from the page. */
    private function dynamicGoal(): array
    {
        return array_merge(GoalSettings::blank(), [
            'type'          => 'custom_event',
            'operator'      => 'name',
            'value'         => 'booking',
            'goal_value'    => '100.00',
            'dynamic_value' => true,
        ]);
    }

    /**
     * @dataProvider suppliedValues
     */
    public function testANegativeSuppliedValueFallsBackToTheConfiguredOne(mixed $sent, ?string $expected): void
    {
        // The exact path a browser value takes: the endpoint passes it on as a
        // string, DatabaseManager parses it with Money, the recorder decides.
        $parsed = Money::parse($sent);

        self::assertSame($expected, $this->resolve($parsed, $this->dynamicGoal()));
    }

    /**
     * @return array<string, array{0: mixed, 1: string|null}>
     */
    public static function suppliedValues(): array
    {
        return [
            'positive is used'           => ['250', '250.00'],
            'zero is a free booking'     => ['0', '0.00'],
            'negative is ignored'        => ['-5', '100.00'],
            'a negative cent is ignored' => ['-0.01', '100.00'],
            'negative zero is zero'      => ['-0', '0.00'],
            'unreadable is ignored'      => ['abc', '100.00'],
            'nothing supplied'           => [null, '100.00'],
        ];
    }

    public function testAGoalThatDoesNotAcceptAValueIgnoresOneEitherWay(): void
    {
        $goal = array_merge($this->dynamicGoal(), ['dynamic_value' => false]);

        self::assertSame('100.00', $this->resolve('250.00', $goal));
        self::assertSame('100.00', $this->resolve('-5.00', $goal));
    }

    public function testANegativeValueOnAGoalWithNoConfiguredValueRecordsNoValue(): void
    {
        $goal = array_merge($this->dynamicGoal(), ['goal_value' => null]);

        self::assertNull($this->resolve('-5.00', $goal), 'no value, never a negative one');
    }

    // ── A goal's configured value ───────────────────────────────────────────

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function submittedGoal(array $overrides): array
    {
        return array_merge([
            'name'     => 'Booking',
            'type'     => 'custom_event',
            'operator' => 'name',
            'value'    => 'booking',
            'enabled'  => '1',
        ], $overrides);
    }

    public function testTheGoalEditorRefusesANegativeValue(): void
    {
        self::assertNull(GoalSettings::sanitize($this->submittedGoal(['goal_value' => '-50']), null, '2026-01-01 00:00:00'));
    }

    public function testTheGoalEditorAcceptsZeroAndPositiveValues(): void
    {
        $free = GoalSettings::sanitize($this->submittedGoal(['goal_value' => '0']), null, '2026-01-01 00:00:00');
        $paid = GoalSettings::sanitize($this->submittedGoal(['goal_value' => '$1,250.50']), null, '2026-01-01 00:00:00');

        self::assertNotNull($free);
        self::assertSame('0.00', $free['goal_value']);
        self::assertNotNull($paid);
        self::assertSame('1250.50', $paid['goal_value']);
    }

    /**
     * Refusing on SAVE must not reach back into goals stored before the rule
     * existed: reading one keeps the value it was saved with.
     */
    public function testAStoredNegativeGoalValueIsStillReadAsStored(): void
    {
        $stored = GoalSettings::normalize(array_merge(GoalSettings::blank(), [
            'goal_id'  => 'g' . str_repeat('a', 16),
            'name'     => 'Legacy',
            'type'     => 'custom_event',
            'operator' => 'name',
            'value'    => 'booking',
            'goal_value' => '-20.00',
        ]));

        self::assertNotNull($stored);
        self::assertSame('-20.00', $stored['goal_value']);
    }

    /**
     * Source-contract. The Goals screen checks before sanitize() so it can say
     * what was wrong instead of the generic "could not be saved" message.
     */
    public function testTheGoalsScreenExplainsANegativeValue(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Admin/Pages/GoalsPage.php');

        $check    = strpos($source, "self::redirect(['cvmtry_goal_error' => 'negative_value'])");
        $sanitize = strpos($source, 'GoalSettings::sanitize($submitted');

        self::assertIsInt($check);
        self::assertIsInt($sanitize);
        self::assertLessThan($sanitize, $check);
        self::assertStringContainsString("'negative_value' => __(", $source, 'the error has its own message');
    }

    // ── A lead's value ──────────────────────────────────────────────────────

    /** Installs a database holding one lead, recording every write. */
    private function databaseWithLead(): void
    {
        $test = $this;

        $GLOBALS['wpdb'] = new class ($test) {
            public string $prefix = 'wp_';

            public function __construct(private GoalValueTest $test)
            {
            }

            public function prepare(string $sql, mixed ...$args): string
            {
                return $sql;
            }

            /** @return array<string, mixed>|null */
            public function get_row(string $sql, string $output = 'ARRAY_A'): ?array
            {
                return ['lead_status' => 'new', 'lead_value' => null, 'lead_currency' => ''];
            }

            public function query(string $sql): int
            {
                $this->test->recordWrite($sql);

                return 1;
            }

            /** @param array<string, mixed> $data @param array<string, mixed> $where */
            public function update(string $table, array $data, array $where): int
            {
                $this->test->recordWrite('UPDATE ' . $table);

                return 1;
            }
        };
    }

    public function recordWrite(string $sql): void
    {
        $this->writes[] = $sql;
    }

    /**
     * @dataProvider negativeLeadValues
     */
    public function testANegativeLeadValueIsRefusedAndNothingIsWritten(string $typed): void
    {
        $this->databaseWithLead();

        $result = LeadService::update('s1', null, $typed, 1);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('cannot be negative', $result['message']);
        self::assertSame([], $this->writes, 'neither the lead nor its history may change');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function negativeLeadValues(): array
    {
        return [
            'plain'           => ['-50'],
            'with a currency' => ['-$1,200.00'],
            'a cent'          => ['-0.01'],
        ];
    }

    public function testAZeroLeadValueIsStillAccepted(): void
    {
        $this->databaseWithLead();

        $result = LeadService::update('s1', null, '0', 1);

        self::assertTrue($result['ok'], $result['message']);
        self::assertSame('0.00', $result['value']);
    }
}
