<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Database\PreparedEvent;
use Convermetry\Goals\GoalCompletions;
use PHPUnit\Framework\TestCase;

/**
 * Goal deduplication.
 *
 * The product promise is exactly this:
 *
 *     Once per session:   phone CTA clicked five times  → 1 completion
 *     Every occurrence:   PDF downloaded three times    → 3 completions
 *
 * Both are enforced by a UNIQUE index on dedupe_key with INSERT IGNORE, so
 * these tests are about the key construction — the one expression that decides
 * which of those two numbers a site gets.
 *
 * They also cover the three cases where a naive key would be actively wrong:
 * an event with no session id, a browser batch with no usable id, and a goal
 * whose rule was edited after completions already existed.
 */
final class GoalDedupeTest extends TestCase
{
    private const string GOAL = 'gaaaaaaaaaaaaaaaa';
    private const string HASH = 'abcdef123456';

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

    /**
     * @param array{goal?: string, hash?: string, session?: string, uid?: string, once?: bool} $o
     */
    private function key(array $o = []): string
    {
        return GoalCompletions::dedupeKey(
            $o['goal'] ?? self::GOAL,
            $o['hash'] ?? self::HASH,
            $o['session'] ?? 'session0001',
            $o['uid'] ?? 'uid0001',
            $o['once'] ?? true
        );
    }

    // ── The two configured behaviors ─────────────────────────────────────────

    /**
     * Five taps in one session, five different events — one key, so the unique
     * index collapses them to a single completion.
     */
    public function testOncePerSessionCollapsesRepeatedEventsInOneSession(): void
    {
        $keys = [];
        for ($i = 1; $i <= 5; $i++) {
            $keys[] = $this->key(['uid' => 'uid000' . $i, 'once' => true]);
        }

        self::assertCount(1, array_unique($keys), 'Five taps in one session must produce one key.');
    }

    /**
     * Three downloads, three different events — three keys, so all three are
     * stored.
     */
    public function testEveryOccurrenceKeepsEachEventDistinct(): void
    {
        $keys = [];
        for ($i = 1; $i <= 3; $i++) {
            $keys[] = $this->key(['uid' => 'uid000' . $i, 'once' => false]);
        }

        self::assertCount(3, array_unique($keys), 'Three downloads must produce three keys.');
    }

    public function testOncePerSessionStillSeparatesDifferentSessions(): void
    {
        self::assertNotSame(
            $this->key(['session' => 'sessionA']),
            $this->key(['session' => 'sessionB'])
        );
    }

    public function testDifferentGoalsNeverShareAKey(): void
    {
        self::assertNotSame(
            $this->key(['goal' => 'gaaaaaaaaaaaaaaaa']),
            $this->key(['goal' => 'gbbbbbbbbbbbbbbbb'])
        );
    }

    /**
     * The behavior prefix matters. Without it, an every-occurrence key could
     * collide with a session key and the first completion after a settings
     * change would silently disappear.
     */
    public function testSwitchingBehaviorCannotCollideWithExistingKeys(): void
    {
        $once  = $this->key(['session' => 'same', 'uid' => 'same', 'once' => true]);
        $every = $this->key(['session' => 'same', 'uid' => 'same', 'once' => false]);

        self::assertNotSame($once, $every);
    }

    // ── Session-less events ──────────────────────────────────────────────────

    /**
     * The important one. Hashing '' as the session would give EVERY session-less
     * completion for a goal the same key, so the site would record exactly one
     * of them ever — a permanent, invisible under-count.
     */
    public function testSessionLessEventsDegradeToEveryOccurrence(): void
    {
        $first  = $this->key(['session' => '', 'uid' => 'uidA', 'once' => true]);
        $second = $this->key(['session' => '', 'uid' => 'uidB', 'once' => true]);

        self::assertNotSame(
            $first,
            $second,
            'Two session-less completions collapsed onto one key — they would be counted once, forever.'
        );
    }

    public function testASessionLessKeyMatchesItsEveryOccurrenceForm(): void
    {
        // Degrading means using the every-occurrence key, not inventing a third
        // scheme — otherwise the same event recorded via two paths would produce
        // two rows.
        self::assertSame(
            $this->key(['session' => '', 'uid' => 'uidA', 'once' => true]),
            $this->key(['session' => '', 'uid' => 'uidA', 'once' => false])
        );
    }

    // ── Definition changes ───────────────────────────────────────────────────

    /**
     * Editing a goal's matching rule must start a clean series. Otherwise a
     * visitor who completed the OLD rule earlier in this session could never
     * complete the new one — the old key would still occupy the index.
     */
    public function testEditingTheRuleStartsANewSeries(): void
    {
        self::assertNotSame(
            $this->key(['hash' => 'aaaaaaaaaaaa']),
            $this->key(['hash' => 'bbbbbbbbbbbb'])
        );
    }

    // ── Event identity ───────────────────────────────────────────────────────

    /**
     * A replayed browser batch derives the same uid for the same event, so its
     * every-occurrence completion collides with the original instead of
     * counting twice. This is what makes at-least-once delivery safe for goals.
     */
    public function testAReplayedBatchDerivesTheSameEventIdentity(): void
    {
        $first  = PreparedEvent::mintUid('batch-abc123', 3);
        $second = PreparedEvent::mintUid('batch-abc123', 3);

        self::assertSame($first, $second);
        self::assertSame(
            $this->key(['uid' => $first, 'once' => false]),
            $this->key(['uid' => $second, 'once' => false])
        );
    }

    public function testDifferentPositionsInABatchAreDistinctEvents(): void
    {
        self::assertNotSame(
            PreparedEvent::mintUid('batch-abc123', 3),
            PreparedEvent::mintUid('batch-abc123', 4)
        );
    }

    public function testDifferentBatchesAreDistinctEvents(): void
    {
        self::assertNotSame(
            PreparedEvent::mintUid('batch-abc123', 0),
            PreparedEvent::mintUid('batch-def456', 0)
        );
    }

    /**
     * A batch with no usable id cannot be deduplicated by any means. Minting a
     * random uid means those events are treated as genuinely distinct
     * occurrences — over-counting on replay, which is visible and recoverable,
     * rather than collapsing unrelated events, which is neither.
     */
    public function testEventsWithoutABatchIdGetDistinctIdentities(): void
    {
        Functions\when('wp_generate_uuid4')->justReturn('uuid-' . uniqid('', true));
        Functions\when('wp_rand')->alias(static fn(): int => random_int(0, PHP_INT_MAX));

        $ids = [];
        for ($i = 0; $i < 20; $i++) {
            $ids[] = PreparedEvent::mintUid(null, 0);
        }

        self::assertCount(20, array_unique($ids));
    }

    public function testServerSideEventsGetDistinctIdentities(): void
    {
        Functions\when('wp_generate_uuid4')->justReturn('uuid-' . uniqid('', true));
        Functions\when('wp_rand')->alias(static fn(): int => random_int(0, PHP_INT_MAX));

        // An empty-string batch id is treated the same as null: cvm_track_event()
        // and the provider hooks never set one.
        self::assertNotSame(
            PreparedEvent::mintUid('', 0),
            PreparedEvent::mintUid('', 0)
        );
    }

    // ── Shape ────────────────────────────────────────────────────────────────

    /**
     * The column is CHAR(32) and carries a UNIQUE index. A key of any other
     * length would be silently padded or truncated by MySQL, and truncation
     * would make unrelated completions collide.
     */
    public function testKeysAreExactlyTheColumnWidth(): void
    {
        foreach ([true, false] as $once) {
            foreach (['session0001', ''] as $session) {
                $key = $this->key(['session' => $session, 'once' => $once]);

                self::assertSame(32, strlen($key));
                self::assertMatchesRegularExpression('~^[a-f0-9]{32}$~', $key);
            }
        }
    }
}
