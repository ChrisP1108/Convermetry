<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Goals\GoalRepository;
use Convermetry\Goals\GoalSettings;
use Convermetry\Settings\Options;
use PHPUnit\Framework\TestCase;

/**
 * The autoloaded mirror of the browser selector map.
 *
 * The script loader asks for these selectors on every tracked frontend request,
 * but cvm_goals is deliberately non-autoloaded — so without a persistent object
 * cache that was an extra uncached SELECT plus a normalize-every-goal pass on
 * every visitor page, even on the overwhelming majority of sites that define no
 * selector goals at all. The mirror answers that question from a row WordPress
 * has already loaded.
 *
 * Two properties carry the whole design:
 *
 *  - A cached EMPTY map is a real answer ("no selector goals"), not a cache
 *    miss. Getting that wrong would reintroduce the query on exactly the sites
 *    the mirror exists to spare.
 *  - The read path never writes. It usually runs on an anonymous frontend page
 *    load, and those must not write — the same rule MigrationRunner enforces
 *    for schema work. A site upgrading in falls back to reading the goals until
 *    an admin request seeds the mirror.
 */
final class GoalSelectorMirrorTest extends TestCase
{
    /** @var array<int, array<string, mixed>> Goals the stubbed option returns. */
    private array $storedGoals = [];

    /** @var mixed What the stubbed mirror option returns (false = not built). */
    private mixed $storedMirror = false;

    /** @var bool Whether goal matching is switched on. */
    private bool $goalsEnabled = true;

    /** @var array<int, array{key: string, value: mixed, autoload: bool}> Writes seen. */
    private array $writes = [];

    /** @var int How many times the goal list itself was read. */
    private int $goalReads = 0;

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

        Functions\when('get_option')->alias(function (string $key, $default = false) {
            if ($key === Options::GOALS_OPTION_KEY) {
                $this->goalReads++;

                return $this->storedGoals;
            }
            if ($key === Options::GOAL_SELECTORS_OPTION_KEY) {
                return $this->storedMirror;
            }
            if ($key === Options::OPTION_KEY) {
                return ['goals_enabled' => $this->goalsEnabled];
            }

            return $default;
        });

        Functions\when('update_option')->alias(function (string $key, $value, $autoload = null): bool {
            $this->writes[] = ['key' => $key, 'value' => $value, 'autoload' => (bool) $autoload];
            if ($key === Options::GOAL_SELECTORS_OPTION_KEY) {
                $this->storedMirror = $value;
            }

            return true;
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
     * @return array<string, mixed>
     */
    private function selectorGoal(array $overrides = []): array
    {
        return array_merge(GoalSettings::blank(), [
            'goal_id'         => 'g' . str_repeat('a', 16),
            'name'            => 'Book now button',
            'enabled'         => true,
            'type'            => 'click',
            'operator'        => 'selector',
            'value'           => '.book-now',
            'definition_hash' => 'abcdef123456',
        ], $overrides);
    }

    // ── Serving from the mirror ──────────────────────────────────────────────

    public function testAStoredMirrorIsServedWithoutReadingTheGoalList(): void
    {
        $this->storedMirror = ['gaaaaaaaaaaaaaaaa' => '.book-now'];

        self::assertSame(['gaaaaaaaaaaaaaaaa' => '.book-now'], GoalRepository::browserSelectors());
        self::assertSame(0, $this->goalReads, 'The mirror exists precisely so cvm_goals is not read here.');
    }

    /**
     * The case the whole optimization is for: a site with no selector goals.
     * An empty map must be treated as a stored answer, not as "not built yet".
     */
    public function testACachedEmptyMapStillAvoidsTheGoalRead(): void
    {
        $this->storedMirror = [];

        self::assertSame([], GoalRepository::browserSelectors());
        self::assertSame(0, $this->goalReads);
    }

    // ── Falling back before the mirror exists ────────────────────────────────

    public function testAnAbsentMirrorFallsBackToTheGoalListWithoutWriting(): void
    {
        $this->storedMirror = false;
        $this->storedGoals  = [$this->selectorGoal()];

        self::assertSame(['gaaaaaaaaaaaaaaaa' => '.book-now'], GoalRepository::browserSelectors());
        self::assertSame(1, $this->goalReads, 'The fallback must still return the right answer.');
        self::assertSame([], $this->writes, 'A frontend read path must never write an option.');
    }

    // ── What belongs in the map ──────────────────────────────────────────────

    public function testOnlyEnabledSelectorGoalsAreMirrored(): void
    {
        $this->storedGoals = [
            $this->selectorGoal(),
            $this->selectorGoal([
                'goal_id'  => 'g' . str_repeat('b', 16),
                'operator' => 'contains',
                'type'     => 'url',
                'value'    => '/pricing/',
            ]),
            $this->selectorGoal([
                'goal_id' => 'g' . str_repeat('c', 16),
                'enabled' => false,
                'value'   => '.disabled',
            ]),
            $this->selectorGoal(['goal_id' => 'g' . str_repeat('d', 16), 'value' => '']),
        ];

        GoalRepository::refreshSelectorMirror();

        self::assertSame(['gaaaaaaaaaaaaaaaa' => '.book-now'], $this->storedMirror);
    }

    public function testTheMirrorIsCappedAtTheBrowserSelectorLimit(): void
    {
        $goals = [];
        for ($i = 0; $i < GoalRepository::MAX_BROWSER_SELECTORS + 10; $i++) {
            $goals[] = $this->selectorGoal([
                'goal_id' => 'g' . str_pad((string) $i, 16, '0', STR_PAD_LEFT),
                'value'   => '.sel-' . $i,
            ]);
        }
        $this->storedGoals = $goals;

        GoalRepository::refreshSelectorMirror();

        self::assertCount(GoalRepository::MAX_BROWSER_SELECTORS, $this->storedMirror);
    }

    public function testTheMirrorIsStoredAutoloaded(): void
    {
        $this->storedGoals = [$this->selectorGoal()];

        GoalRepository::refreshSelectorMirror();

        self::assertSame(Options::GOAL_SELECTORS_OPTION_KEY, $this->writes[0]['key']);
        self::assertTrue($this->writes[0]['autoload'], 'The point of the mirror is that it is autoloaded.');
    }

    /**
     * The mirror describes the GOALS, not the goals-enabled setting — which is
     * applied when it is read. Building it while goals are switched off must
     * not bake an empty map in that survives switching them back on.
     */
    public function testTheMirrorIgnoresTheGoalsEnabledSettingWhenBuilt(): void
    {
        $this->goalsEnabled = false;
        $this->storedGoals  = [$this->selectorGoal()];

        GoalRepository::refreshSelectorMirror();
        self::assertSame(['gaaaaaaaaaaaaaaaa' => '.book-now'], $this->storedMirror);

        // Still nothing shipped while the feature is off...
        self::assertSame([], GoalRepository::browserSelectors());

        // ...and switching it on needs no rebuild.
        $this->goalsEnabled = true;
        self::assertSame(['gaaaaaaaaaaaaaaaa' => '.book-now'], GoalRepository::browserSelectors());
    }

    public function testNoSelectorsAreShippedWhenGoalsAreOff(): void
    {
        $this->goalsEnabled = false;
        $this->storedMirror = ['gaaaaaaaaaaaaaaaa' => '.book-now'];

        self::assertSame([], GoalRepository::browserSelectors());
    }

    // ── Seeding ──────────────────────────────────────────────────────────────

    public function testEnsureBuildsTheMirrorOnlyWhenItIsMissing(): void
    {
        $this->storedGoals = [$this->selectorGoal()];

        GoalRepository::ensureSelectorMirror();
        self::assertCount(1, $this->writes, 'An absent mirror should be seeded.');

        GoalRepository::ensureSelectorMirror();
        self::assertCount(1, $this->writes, 'An existing mirror must not be rewritten on every admin request.');
    }

    public function testEnsureTreatsAnEmptyMirrorAsAlreadyBuilt(): void
    {
        $this->storedMirror = [];

        GoalRepository::ensureSelectorMirror();

        self::assertSame([], $this->writes);
    }
}
