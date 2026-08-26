<?php
declare(strict_types=1);

namespace Convermetry\Tests\Integration;

use Convermetry\Analytics\FunnelReport;

/**
 * The generated funnel SQL, executed against a real MySQL server.
 *
 * THIS SUITE EXISTS BECAUSE A BUG GOT THROUGH HERE.
 *
 * The funnel statement is assembled outside-in — each step WRAPS what came
 * before, and its correlated subquery is written ahead of the nested FROM — so
 * in the finished string the LAST step's placeholders appear FIRST and step 0's
 * appear last. Parameters were appended in step order, so every one bound to the
 * wrong placeholder. Nothing errored, because they are all %s: the query ran
 * happily, compared a page URL against a timestamp, matched nothing, and
 * reported every funnel as zero. Against a real database, step 0's condition
 * came out as:
 *
 *     page = '2026-09-01 00:00:00' AND created_at >= '/b/'
 *
 * A unit test asserting the SQL's structure stayed green throughout, which is
 * the whole argument for this file.
 */
final class FunnelQueryTest extends IntegrationTestCase
{
    private const string START = '2026-08-01 00:00:00';
    private const string END   = '2026-09-01 00:00:00';

    /**
     * @param list<array<string, mixed>> $steps
     * @return array{steps: list<array<string, mixed>>, overall_rate: float, error: string}
     */
    private function compute(array $steps): array
    {
        return FunnelReport::compute(
            ['funnel_id' => 'f' . str_repeat('a', 16), 'steps' => $steps],
            self::START,
            self::END
        );
    }

    /**
     * @param string $path A path such as '/a/'.
     * @return array<string, mixed>
     */
    private function pageStep(string $path, string $label = ''): array
    {
        return ['type' => 'page', 'operator' => 'equals', 'value' => $path, 'label' => $label ?: $path];
    }

    /**
     * Records one pageview.
     */
    private function view(string $session, string $path, string $at): int
    {
        return $this->insertEvent([
            'event_type' => 'pageview',
            'page_url'   => 'https://example.com' . $path,
            'session_id' => $session,
            'created_at' => $at,
        ]);
    }

    // ── The case the whole design exists for ─────────────────────────────────

    /**
     * A session that did B, then A, then B again HAS completed an A→B funnel.
     *
     * Comparing each step's independent earliest occurrence gets this wrong:
     * MIN(B) is 09:00, before A at 10:00, so it reports failure — and the
     * mirror-image error is just as available, with B→A falsely succeeding.
     */
    public function testASessionThatRepeatsTheFirstStepStillConverts(): void
    {
        $this->view('sessA', '/b/', '2026-08-10 09:00:00');
        $this->view('sessA', '/a/', '2026-08-10 10:00:00');
        $this->view('sessA', '/b/', '2026-08-10 11:00:00');

        $result = $this->compute([$this->pageStep('/a/'), $this->pageStep('/b/')]);

        self::assertSame('', $result['error']);
        self::assertSame(1, $result['steps'][0]['sessions']);
        self::assertSame(
            1,
            $result['steps'][1]['sessions'],
            'B happened after A, so the funnel converted. Independent minimums would report 0 here.'
        );
    }

    /**
     * The mirror image: B before A and never again is NOT an A→B conversion.
     */
    public function testTheSecondStepMustFollowTheFirst(): void
    {
        $this->view('sessB', '/b/', '2026-08-12 09:00:00');
        $this->view('sessB', '/a/', '2026-08-12 10:00:00');

        $result = $this->compute([$this->pageStep('/a/'), $this->pageStep('/b/')]);

        self::assertSame(1, $result['steps'][0]['sessions']);
        self::assertSame(0, $result['steps'][1]['sessions']);
    }

    // ── Parameter binding ────────────────────────────────────────────────────

    /**
     * The regression itself. With parameters bound in the wrong order, step 0's
     * pattern became a timestamp and this returns zero entrants.
     */
    public function testParametersReachTheConditionsTheyBelongTo(): void
    {
        $this->view('sessC', '/step-one/', '2026-08-10 09:00:00');
        $this->view('sessC', '/step-two/', '2026-08-10 09:05:00');
        $this->view('sessC', '/step-three/', '2026-08-10 09:10:00');

        $result = $this->compute([
            $this->pageStep('/step-one/'),
            $this->pageStep('/step-two/'),
            $this->pageStep('/step-three/'),
        ]);

        self::assertSame('', $result['error']);
        self::assertSame(
            [1, 1, 1],
            array_column($result['steps'], 'sessions'),
            'A three-step funnel every step of which was satisfied must report 1 at every step. Zeros here '
            . 'mean parameters bound to the wrong placeholders.'
        );
        self::assertSame(100.0, $result['overall_rate']);
    }

    /**
     * The window bounds must apply to the cohort, not to a page pattern.
     */
    public function testTheCohortWindowIsHonored(): void
    {
        $this->view('inside', '/a/', '2026-08-10 09:00:00');
        $this->view('inside', '/b/', '2026-08-10 09:05:00');

        // Before the window opens.
        $this->view('early', '/a/', '2026-07-01 09:00:00');
        $this->view('early', '/b/', '2026-07-01 09:05:00');

        // After it closes.
        $this->view('late', '/a/', '2026-10-01 09:00:00');
        $this->view('late', '/b/', '2026-10-01 09:05:00');

        $result = $this->compute([$this->pageStep('/a/'), $this->pageStep('/b/')]);

        self::assertSame(1, $result['steps'][0]['sessions'], 'Only the in-window session should enter.');
    }

    /**
     * A session entering near the window's edge may finish just outside it —
     * without the completion window its conversion would be discarded and
     * rates would sag at every period boundary.
     */
    public function testLaterStepsCountJustPastTheWindow(): void
    {
        $this->view('edge', '/a/', '2026-08-31 23:55:00');
        $this->view('edge', '/b/', '2026-09-01 00:30:00');

        $result = $this->compute([$this->pageStep('/a/'), $this->pageStep('/b/')]);

        self::assertSame(1, $result['steps'][0]['sessions']);
        self::assertSame(1, $result['steps'][1]['sessions']);
    }

    // ── Matching semantics, in SQL rather than PHP ───────────────────────────

    public function testTrailingSlashesAreForgivenOnBothSides(): void
    {
        $this->view('slash1', '/a', '2026-08-10 09:00:00');
        $this->view('slash1', '/b/', '2026-08-10 09:05:00');

        $result = $this->compute([$this->pageStep('/a/'), $this->pageStep('/b')]);

        self::assertSame(1, $result['steps'][0]['sessions']);
        self::assertSame(1, $result['steps'][1]['sessions']);
    }

    /**
     * Case-insensitivity comes from the column collation rather than from PHP,
     * so it needs a real server to verify.
     */
    public function testMatchingIsCaseInsensitive(): void
    {
        $this->view('case1', '/Pricing/', '2026-08-10 09:00:00');

        $result = $this->compute([
            $this->pageStep('/pricing/'),
            $this->pageStep('/anything/'),
        ]);

        self::assertSame(1, $result['steps'][0]['sessions']);
    }

    /**
     * An "equals" rule on a path must not match a deeper path that ends with it.
     */
    public function testEqualsIsNotASuffixMatch(): void
    {
        $this->view('deep', '/blog/thank-you/', '2026-08-10 09:00:00');

        $result = $this->compute([
            $this->pageStep('/thank-you/'),
            $this->pageStep('/anything/'),
        ]);

        self::assertSame(0, $result['steps'][0]['sessions']);
    }

    public function testContainsMatchesASegment(): void
    {
        $this->view('contains', '/book/schedule/now/', '2026-08-10 09:00:00');
        $this->view('contains', '/done/', '2026-08-10 09:05:00');

        $result = $this->compute([
            ['type' => 'page', 'operator' => 'contains', 'value' => '/schedule/', 'label' => 'Schedule'],
            $this->pageStep('/done/'),
        ]);

        self::assertSame(1, $result['steps'][0]['sessions']);
        self::assertSame(1, $result['steps'][1]['sessions']);
    }

    // ── Session integrity ────────────────────────────────────────────────────

    /**
     * An empty session id is not one visitor — it is every visitor whose session
     * could not be established. Grouping on it would produce a single enormous
     * pseudo-session that appears to complete every funnel.
     */
    public function testSessionsWithNoIdAreExcluded(): void
    {
        $this->view('', '/a/', '2026-08-10 09:00:00');
        $this->view('', '/b/', '2026-08-10 09:05:00');
        $this->view('real', '/a/', '2026-08-11 09:00:00');

        $result = $this->compute([$this->pageStep('/a/'), $this->pageStep('/b/')]);

        self::assertSame(1, $result['steps'][0]['sessions'], 'Only the real session should be counted.');
        self::assertSame(0, $result['steps'][1]['sessions']);
    }

    /**
     * One session's activity must not satisfy another's step.
     */
    public function testStepsAreScopedToOneSession(): void
    {
        $this->view('one', '/a/', '2026-08-10 09:00:00');
        $this->view('two', '/b/', '2026-08-10 09:05:00');

        $result = $this->compute([$this->pageStep('/a/'), $this->pageStep('/b/')]);

        self::assertSame(1, $result['steps'][0]['sessions']);
        self::assertSame(0, $result['steps'][1]['sessions']);
    }

    // ── Mixed step types ─────────────────────────────────────────────────────

    /**
     * A form step reads the same table by event type, and narrows to one form
     * when given a key.
     */
    public function testAFormStepConvertsOnAConfirmedSubmission(): void
    {
        $this->view('form1', '/contact/', '2026-08-10 09:00:00');
        $this->insertEvent([
            'event_type' => 'form_success',
            'page_url'   => 'https://example.com/contact/',
            'session_id' => 'form1',
            'form_key'   => 'gravityforms:7',
            'event_value' => 'conv0000000000001',
            'created_at' => '2026-08-10 09:05:00',
        ]);

        $result = $this->compute([
            $this->pageStep('/contact/'),
            ['type' => 'form_success', 'value' => 'gravityforms:7', 'label' => 'Converted'],
        ]);

        self::assertSame(1, $result['steps'][1]['sessions']);
    }

    public function testAFormStepWithADifferentKeyDoesNotConvert(): void
    {
        $this->view('form2', '/contact/', '2026-08-10 09:00:00');
        $this->insertEvent([
            'event_type'  => 'form_success',
            'session_id'  => 'form2',
            'form_key'    => 'wpforms:3',
            'event_value' => 'conv0000000000002',
            'created_at'  => '2026-08-10 09:05:00',
        ]);

        $result = $this->compute([
            $this->pageStep('/contact/'),
            ['type' => 'form_success', 'value' => 'gravityforms:7', 'label' => 'Converted'],
        ]);

        self::assertSame(0, $result['steps'][1]['sessions']);
    }

    /**
     * A goal step reads a DIFFERENT TABLE and orders by source_event_id, which
     * lives on the same scale as an event id. That cross-table ordering is
     * exactly the thing that cannot be verified without a database.
     */
    public function testAGoalStepOrdersAgainstEventsAcrossTables(): void
    {
        $goalId = 'g' . str_repeat('a', 16);

        $entry     = $this->view('goal1', '/land/', '2026-08-10 09:00:00');
        $triggerId = $this->insertEvent([
            'event_type' => 'click',
            'session_id' => 'goal1',
            'target_url' => 'tel:+15551234567',
            'created_at' => '2026-08-10 09:05:00',
        ]);

        self::$db->query(self::$db->prepare(
            'INSERT INTO wp_cvm_goal_completions
             (completion_id, goal_id, definition_hash, dedupe_key, event_uid, source_event_id,
              session_id, page_url, created_at)
             VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %s)',
            str_repeat('c', 32),
            $goalId,
            'abcdef123456',
            str_repeat('d', 32),
            str_repeat('e', 32),
            $triggerId,
            'goal1',
            'https://example.com/land/',
            '2026-08-10 09:05:00'
        ));

        self::assertGreaterThan($entry, $triggerId, 'The goal must have been triggered after the landing view.');

        $result = $this->compute([
            $this->pageStep('/land/'),
            ['type' => 'goal', 'value' => $goalId, 'label' => 'Phone tapped'],
        ]);

        self::assertSame('', $result['error']);
        self::assertSame(1, $result['steps'][0]['sessions']);
        self::assertSame(1, $result['steps'][1]['sessions']);
    }

    /**
     * A goal completed BEFORE the funnel's first step must not satisfy a later
     * goal step — the cross-table comparison has to respect ordering too.
     */
    public function testAGoalCompletedBeforeTheFirstStepDoesNotCount(): void
    {
        $goalId = 'g' . str_repeat('b', 16);

        $triggerId = $this->insertEvent([
            'event_type' => 'click',
            'session_id' => 'goal2',
            'target_url' => 'tel:+15551234567',
            'created_at' => '2026-08-10 08:00:00',
        ]);
        $this->view('goal2', '/land/', '2026-08-10 09:00:00');

        self::$db->query(self::$db->prepare(
            'INSERT INTO wp_cvm_goal_completions
             (completion_id, goal_id, definition_hash, dedupe_key, event_uid, source_event_id,
              session_id, page_url, created_at)
             VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %s)',
            str_repeat('1', 32),
            $goalId,
            'abcdef123456',
            str_repeat('2', 32),
            str_repeat('3', 32),
            $triggerId,
            'goal2',
            'https://example.com/land/',
            '2026-08-10 08:00:00'
        ));

        $result = $this->compute([
            $this->pageStep('/land/'),
            ['type' => 'goal', 'value' => $goalId, 'label' => 'Phone tapped'],
        ]);

        self::assertSame(1, $result['steps'][0]['sessions']);
        self::assertSame(0, $result['steps'][1]['sessions']);
    }

    /**
     * A completion with no source event cannot be ordered, so it cannot satisfy
     * a funnel step. Treating NULL as position zero would let it satisfy every
     * "after" test.
     */
    public function testACompletionWithNoSourceEventCannotSatisfyAStep(): void
    {
        $goalId = 'g' . str_repeat('c', 16);

        $this->view('goal3', '/land/', '2026-08-10 09:00:00');

        self::$db->query(self::$db->prepare(
            'INSERT INTO wp_cvm_goal_completions
             (completion_id, goal_id, definition_hash, dedupe_key, event_uid, source_event_id,
              session_id, page_url, created_at)
             VALUES (%s, %s, %s, %s, %s, NULL, %s, %s, %s)',
            str_repeat('4', 32),
            $goalId,
            'abcdef123456',
            str_repeat('5', 32),
            str_repeat('6', 32),
            'goal3',
            'https://example.com/land/',
            '2026-08-10 09:05:00'
        ));

        $result = $this->compute([
            $this->pageStep('/land/'),
            ['type' => 'goal', 'value' => $goalId, 'label' => 'Phone tapped'],
        ]);

        self::assertSame(0, $result['steps'][1]['sessions']);
    }

    // ── Shape ────────────────────────────────────────────────────────────────

    /**
     * Eight steps is the cap, and the generated statement must still parse and
     * execute at that depth — each step adds a nesting level.
     */
    public function testTheDeepestAllowedFunnelStillExecutes(): void
    {
        $steps = [];
        for ($i = 1; $i <= 8; $i++) {
            $this->view('deep', '/s' . $i . '/', sprintf('2026-08-10 09:%02d:00', $i));
            $steps[] = $this->pageStep('/s' . $i . '/');
        }

        $result = $this->compute($steps);

        self::assertSame('', $result['error']);
        self::assertCount(8, $result['steps']);
        self::assertSame(1, $result['steps'][7]['sessions'], 'All eight steps were satisfied in order.');
        self::assertSame(100.0, $result['overall_rate']);
    }

    public function testAnEmptyWindowReportsZeroWithoutDividing(): void
    {
        $result = $this->compute([$this->pageStep('/a/'), $this->pageStep('/b/')]);

        self::assertSame('', $result['error']);
        self::assertSame(0, $result['steps'][0]['sessions']);
        self::assertSame(0.0, $result['overall_rate']);
    }
}
