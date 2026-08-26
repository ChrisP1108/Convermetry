<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Analytics\FunnelReport;
use Convermetry\Funnels\StepCompiler;
use PHPUnit\Framework\TestCase;

/**
 * Funnel arithmetic and step compilation.
 *
 * Two separable things are tested here, both without a database:
 *
 *  - {@see FunnelReport::shape()} — the drop-off arithmetic, which is the kind
 *    of code that is off by one somewhere and needs to be exercised directly.
 *  - {@see StepCompiler} — that each step type produces the right condition,
 *    and in particular that the ORDERING constraint is present in the generated
 *    SQL. Whether that SQL returns the right rows against a real optimizer is
 *    an integration question and is verified separately.
 *
 * The B@09 / A@10 / B@11 case has its own test. It is the reason the report
 * chains correlated subqueries instead of taking each step's independent
 * minimum, and it is the failure that looks correct on small data.
 */
final class FunnelMathTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_key')->alias(
            static fn(string $k): string => strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $k))
        );
        Functions\when('sanitize_text_field')->alias(static fn(string $v): string => trim(strip_tags($v)));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param list<string> $labels
     * @return list<array<string, mixed>>
     */
    private function steps(array $labels): array
    {
        return array_map(
            static fn(string $label): array => ['type' => 'page', 'label' => $label, 'value' => '/x/'],
            $labels
        );
    }

    /**
     * @param list<int> $counts
     * @return array<string, int>
     */
    private function counts(array $counts): array
    {
        $row = [];
        foreach ($counts as $index => $value) {
            $row['s' . $index] = $value;
        }

        return $row;
    }

    // ── Drop-off arithmetic ──────────────────────────────────────────────────

    /**
     * The worked example from the specification.
     */
    public function testTheWorkedExample(): void
    {
        $result = FunnelReport::shape(
            $this->steps(['Landing Page', 'Services', 'Form Started', 'Submission Attempted', 'Confirmed Submission']),
            $this->counts([1242, 771, 291, 128, 104])
        );

        $steps = $result['steps'];

        self::assertSame(1242, $steps[0]['sessions']);
        self::assertSame(0, $steps[0]['dropped'], 'The first step cannot have dropped anyone.');
        self::assertSame(100.0, $steps[0]['step_rate']);

        self::assertSame(771, $steps[1]['sessions']);
        self::assertSame(471, $steps[1]['dropped']);
        self::assertSame(62.08, $steps[1]['step_rate']);

        self::assertSame(291, $steps[2]['sessions']);
        self::assertSame(480, $steps[2]['dropped']);
        self::assertSame(37.74, $steps[2]['step_rate']);

        self::assertSame(104, $steps[4]['sessions']);

        // Overall is against the FIRST step, not the previous one.
        self::assertSame(8.37, $result['overall_rate']);
        self::assertSame(8.37, $steps[4]['overall_rate']);
    }

    /**
     * Step rate is measured against the PREVIOUS step, because "62% of the
     * people who reached Services went on to start the form" is the question a
     * funnel is asked. Overall rate answers the other one.
     */
    public function testStepRateIsRelativeToThePreviousStepNotTheFirst(): void
    {
        $result = FunnelReport::shape(
            $this->steps(['A', 'B', 'C']),
            $this->counts([100, 50, 25])
        );

        self::assertSame(50.0, $result['steps'][1]['step_rate']);
        self::assertSame(50.0, $result['steps'][2]['step_rate'], 'C is half of B.');
        self::assertSame(25.0, $result['steps'][2]['overall_rate'], 'C is a quarter of A.');
    }

    public function testDropOffRatesComplementStepRates(): void
    {
        $result = FunnelReport::shape($this->steps(['A', 'B']), $this->counts([200, 150]));

        self::assertSame(50, $result['steps'][1]['dropped']);
        self::assertSame(25.0, $result['steps'][1]['drop_rate']);
        self::assertSame(75.0, $result['steps'][1]['step_rate']);
    }

    /**
     * A funnel nobody entered has no conversion rate. It must not report 0%,
     * which reads as "a thousand people failed to convert".
     */
    public function testZeroDenominatorsDoNotDivide(): void
    {
        $result = FunnelReport::shape($this->steps(['A', 'B', 'C']), $this->counts([0, 0, 0]));

        foreach ($result['steps'] as $step) {
            self::assertSame(0.0, $step['step_rate']);
            self::assertSame(0.0, $step['overall_rate']);
            self::assertSame(0, $step['dropped']);
        }

        self::assertSame(0.0, $result['overall_rate']);
    }

    public function testAbsentCountsAreTreatedAsZero(): void
    {
        // A malformed row must not produce warnings or a fatal — the report
        // renders empty rather than taking the page down.
        $result = FunnelReport::shape($this->steps(['A', 'B', 'C']), []);

        self::assertCount(3, $result['steps']);
        self::assertSame(0, $result['steps'][0]['sessions']);
    }

    /**
     * Counts can never rise between steps — a session cannot reach step 3
     * without having reached step 2, because the query constrains it. If a
     * malformed row said otherwise, the drop-off must clamp at zero rather than
     * reporting a negative number of people.
     */
    public function testAnImpossibleIncreaseClampsRatherThanGoingNegative(): void
    {
        $result = FunnelReport::shape($this->steps(['A', 'B']), $this->counts([100, 150]));

        self::assertSame(0, $result['steps'][1]['dropped']);
        self::assertSame(0.0, $result['steps'][1]['drop_rate']);
        self::assertSame(100.0, $result['steps'][1]['step_rate'], 'Rates are capped at 100%.');
    }

    public function testAPerfectFunnelReportsFullConversion(): void
    {
        $result = FunnelReport::shape($this->steps(['A', 'B', 'C']), $this->counts([40, 40, 40]));

        self::assertSame(100.0, $result['overall_rate']);
        self::assertSame(0, $result['steps'][2]['dropped']);
    }

    public function testTotalLossAtOneStepEndsTheFunnel(): void
    {
        $result = FunnelReport::shape($this->steps(['A', 'B', 'C']), $this->counts([500, 0, 0]));

        self::assertSame(500, $result['steps'][1]['dropped']);
        self::assertSame(100.0, $result['steps'][1]['drop_rate']);
        self::assertSame(0.0, $result['overall_rate']);
    }

    public function testStepLabelsAndTypesAreCarriedThrough(): void
    {
        $steps = [
            ['type' => 'page', 'label' => 'Landing', 'value' => '/land/'],
            ['type' => 'form_success', 'label' => 'Converted', 'value' => ''],
        ];

        $result = FunnelReport::shape($steps, $this->counts([10, 5]));

        self::assertSame('Landing', $result['steps'][0]['label']);
        self::assertSame('form_success', $result['steps'][1]['type']);
    }

    // ── Step compilation ─────────────────────────────────────────────────────

    public function testFormStepsMatchTheirEventType(): void
    {
        foreach (['form_view', 'form_start', 'form_submit', 'form_success'] as $type) {
            $compiled = StepCompiler::compile(['type' => $type, 'value' => ''], 'e1');

            self::assertNotNull($compiled);
            self::assertSame('events', $compiled['source']);
            self::assertStringContainsString('e1.event_type = %s', $compiled['sql']);
            self::assertSame([$type], $compiled['params']);
        }
    }

    /**
     * An empty form key legitimately means "any form on the site" — a funnel
     * ending in "did they submit anything?" is a real question.
     */
    public function testAFormStepWithoutAKeyMatchesAnyForm(): void
    {
        $compiled = StepCompiler::compile(['type' => 'form_success', 'value' => ''], 'e1');

        self::assertNotNull($compiled);
        self::assertStringNotContainsString('form_key', $compiled['sql']);
    }

    public function testAFormStepWithAKeyNarrowsToThatForm(): void
    {
        $compiled = StepCompiler::compile(['type' => 'form_success', 'value' => 'gravityforms:7'], 'e1');

        self::assertNotNull($compiled);
        self::assertStringContainsString('e1.form_key = %s', $compiled['sql']);
        self::assertSame(['form_success', 'gravityforms:7'], $compiled['params']);
    }

    /**
     * A goal step reads the completions table and orders by source_event_id —
     * the id of the event that triggered it, which is on the same scale as an
     * event's own id. Without that, a goal step could not be ordered against a
     * page step at all.
     */
    public function testAGoalStepReadsCompletionsAndUsesTheSharedOrderingScale(): void
    {
        $goalId   = 'g' . str_repeat('a', 16);
        $compiled = StepCompiler::compile(['type' => 'goal', 'value' => $goalId], 'e2');

        self::assertNotNull($compiled);
        self::assertSame('goals', $compiled['source']);
        self::assertStringContainsString('e2.goal_id = %s', $compiled['sql']);
        self::assertSame([$goalId], $compiled['params']);
        self::assertSame('e2.source_event_id', StepCompiler::positionColumn(['type' => 'goal'], 'e2'));
    }

    /**
     * A completion with no source event cannot be placed in the ordering. It is
     * still counted in the goal reports — the conversion happened — but treating
     * it as position zero here would let it satisfy every "after" test.
     */
    public function testAGoalStepExcludesUnorderableCompletions(): void
    {
        $compiled = StepCompiler::compile(['type' => 'goal', 'value' => 'g' . str_repeat('a', 16)], 'e1');

        self::assertNotNull($compiled);
        self::assertStringContainsString('source_event_id IS NOT NULL', $compiled['sql']);
    }

    public function testEventStepsUseTheEventId(): void
    {
        self::assertSame('e1.id', StepCompiler::positionColumn(['type' => 'page'], 'e1'));
        self::assertSame('e1.id', StepCompiler::positionColumn(['type' => 'form_start'], 'e1'));
    }

    /**
     * @dataProvider pageOperators
     */
    public function testPageOperatorsCompile(string $operator, string $expectedFragment): void
    {
        global $wpdb;
        $wpdb = new class {
            public function esc_like(string $text): string
            {
                return addcslashes($text, '_%\\');
            }
        };

        $compiled = StepCompiler::compile(
            ['type' => 'page', 'operator' => $operator, 'value' => '/thank-you/'],
            'e1'
        );

        self::assertNotNull($compiled);
        self::assertStringContainsString("e1.event_type = 'pageview'", $compiled['sql']);
        self::assertStringContainsString($expectedFragment, $compiled['sql']);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pageOperators(): array
    {
        return [
            'equals'      => ['equals', "TRIM(TRAILING '/' FROM"],
            'contains'    => ['contains', 'LIKE %s'],
            'starts with' => ['starts_with', 'LIKE %s'],
            'ends with'   => ['ends_with', 'LIKE %s'],
        ];
    }

    /**
     * A rule written as a path compares against the URL's PATH. Comparing
     * '%/thank-you/' against the whole URL would also match '/blog/thank-you/',
     * which is not what "equals" means.
     */
    public function testAPathRuleComparesAgainstThePath(): void
    {
        global $wpdb;
        $wpdb = new class {
            public function esc_like(string $text): string
            {
                return $text;
            }
        };

        $path = StepCompiler::compile(['type' => 'page', 'operator' => 'equals', 'value' => '/pricing/'], 'e1');
        $full = StepCompiler::compile(
            ['type' => 'page', 'operator' => 'equals', 'value' => 'https://example.com/pricing/'],
            'e1'
        );

        self::assertNotNull($path);
        self::assertNotNull($full);

        self::assertStringContainsString('SUBSTRING(', $path['sql'], 'A path rule must extract the path.');
        self::assertStringNotContainsString('SUBSTRING(', $full['sql'], 'A full-URL rule compares the whole URL.');
    }

    /**
     * @dataProvider unusableSteps
     * @param array<string, mixed> $step
     */
    public function testUnusableStepsAbortTheFunnel(array $step): void
    {
        global $wpdb;
        $wpdb = new class {
            public function esc_like(string $text): string
            {
                return $text;
            }
        };

        self::assertNull(
            StepCompiler::compile($step, 'e1'),
            'A step that cannot be measured must abort the funnel rather than being skipped — rendering the '
            . 'other steps as though this one were satisfied would report a conversion rate that means nothing.'
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function unusableSteps(): array
    {
        return [
            'unknown type'        => [['type' => 'telepathy', 'value' => 'x']],
            'no type'             => [['value' => 'x']],
            'page with no value'  => [['type' => 'page', 'operator' => 'equals', 'value' => '']],
            'page bad operator'   => [['type' => 'page', 'operator' => 'regex', 'value' => '/x/']],
            'goal with no id'     => [['type' => 'goal', 'value' => '']],
        ];
    }

    /**
     * The generated chain must constrain each step's position to be strictly
     * after the previous one. This is the ordering guarantee itself; the
     * B@09/A@10/B@11 case is exactly what it exists to get right.
     */
    public function testTheGeneratedChainConstrainsOrdering(): void
    {
        $method = new \ReflectionMethod(FunnelReport::class, 'buildQuery');

        global $wpdb;
        $wpdb = new class {
            public string $prefix = 'wp_';
            public function esc_like(string $text): string
            {
                return $text;
            }
        };

        $built = $method->invoke(
            null,
            [
                ['type' => 'page', 'operator' => 'equals', 'value' => '/a/'],
                ['type' => 'page', 'operator' => 'equals', 'value' => '/b/'],
            ],
            '2026-08-01 00:00:00',
            '2026-08-31 00:00:00'
        );

        self::assertNotNull($built);

        // Step 2's minimum is taken only over rows positioned after step 1's.
        self::assertMatchesRegularExpression(
            '~MIN\(e1\.id\).*e1\.id > t0\.p0~s',
            $built['sql'],
            'The chain does not constrain step 2 to occur after step 1 — independent minimums would report '
            . 'the wrong answer for a session that did B, then A, then B again.'
        );

        // Sessions with no id would otherwise collapse into one pseudo-session
        // that appears to complete every funnel.
        self::assertStringContainsString("e0.session_id <> ''", $built['sql']);
    }
}
