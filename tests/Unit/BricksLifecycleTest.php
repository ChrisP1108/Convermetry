<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\Bricks\BricksFormsBridge;
use Convermetry\Forms\FormProviderInterface;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\Providers\BricksFormsProvider;
use Convermetry\Forms\SubmissionService;
use PHPUnit\Framework\TestCase;

/**
 * WHEN a provider gets its hooks registered, and why that had to change.
 *
 * Every other supported integration is a PLUGIN, and plugins_loaded — where
 * Convermetry initialises — is exactly the right moment to ask one whether it is
 * active. Bricks is a THEME. WordPress loads the active theme's functions.php
 * AFTER plugins_loaded has finished, so on a Bricks site the question "is Bricks
 * here?" has the answer "no" at the only moment the registry used to ask it. A
 * single-pass registry would take that as final and wire nothing, on every
 * Bricks site, forever — and the failure would be completely silent: no error,
 * no warning, just forms that never record.
 *
 * So registration runs twice: once immediately, once on after_setup_theme, with
 * a per-provider-key guard between them. These tests pin the three properties
 * that makes correct — every available provider ends up registered, none is
 * registered twice, and a provider that was already registered in the first pass
 * is not touched again in the second.
 *
 * The Bricks-specific half is awkward to test honestly: PHP cannot un-define a
 * constant, so a suite that defines BRICKS_VERSION to prove the "present" case
 * can never prove the "absent" case in the same process. The absent case is
 * therefore tested here, in a process where Bricks genuinely is not defined; the
 * present case runs in its own process; and the version rule is tested through
 * {@see BricksFormsBridge::supports()}, which takes the version as an argument
 * precisely so both sides can be covered.
 */
final class BricksLifecycleTest extends TestCase
{
    /** @var array<string, list<callable>> Hook name → registered callbacks. */
    private array $hooks = [];

    /** How many times after_setup_theme has already fired. */
    private int $themeLoaded = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->hooks       = [];
        $this->themeLoaded = 0;

        Functions\when('add_action')->alias(function (string $hook, $callback, $priority = 10, $args = 1): bool {
            $this->hooks[$hook][] = $callback;
            return true;
        });
        Functions\when('add_filter')->alias(function (string $hook, $callback, $priority = 10, $args = 1): bool {
            $this->hooks[$hook][] = $callback;
            return true;
        });
        Functions\when('did_action')->alias(fn(string $hook): int => $hook === 'after_setup_theme'
            ? $this->themeLoaded
            : 0);
        Functions\when('sanitize_text_field')->alias(static fn($value) => trim((string) $value));
        Functions\when('sanitize_key')->alias(static fn($value) => strtolower((string) $value));
        Functions\when('get_option')->alias(static fn(string $key, $default = false) => $default === false ? [] : $default);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Replaces the registry's provider list with the given fakes, through the
     * same public filter a third-party integration would use.
     *
     * @param list<FormProviderInterface> $providers
     * @return void
     */
    private function useProviders(array $providers): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value = null, mixed ...$rest): mixed
                => $hook === 'convermetry_form_providers' ? $providers : $value
        );
    }

    /** Runs every callback registered on after_setup_theme, as WordPress would. */
    private function fireAfterSetupTheme(): void
    {
        $this->themeLoaded++;

        foreach ($this->hooks['after_setup_theme'] ?? [] as $callback) {
            $callback();
        }
    }

    /**
     * A provider whose availability the test controls, counting its own
     * registrations.
     *
     * @param string $key       Provider key.
     * @param bool   $available Initial availability.
     * @return FormProviderInterface&object{registrations: int, available: bool}
     */
    private static function fakeProvider(string $key, bool $available): object
    {
        return new class ($key, $available) implements FormProviderInterface {
            public int $registrations = 0;

            public function __construct(private readonly string $key, public bool $available)
            {
            }

            public function getKey(): string
            {
                return $this->key;
            }

            public function getLabel(): string
            {
                return ucfirst($this->key);
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            /** @return array<int, array{native_id: string, name: string}> */
            public function getForms(): array
            {
                return [];
            }

            public function registerHooks(SubmissionService $service): void
            {
                $this->registrations++;
            }
        };
    }

    // ------------------------------------------------------- the deferred pass

    /**
     * The whole point. A theme-based provider is unavailable when Convermetry
     * boots and available once the theme is loaded, and it must end up
     * registered — exactly once.
     */
    public function testAProviderThatOnlyBecomesAvailableWithTheThemeIsStillRegistered(): void
    {
        $theme = self::fakeProvider('bricks', false);
        $this->useProviders([$theme]);

        $registry = new FormProviderRegistry();
        $registry->registerHooks(new SubmissionService());

        self::assertSame(0, $theme->registrations, 'nothing registers while the theme is not loaded');
        self::assertArrayHasKey('after_setup_theme', $this->hooks, 'a second pass is scheduled');

        $theme->available = true;
        $this->fireAfterSetupTheme();

        self::assertSame(1, $theme->registrations, 'the theme pass registers it, once');
    }

    /**
     * A plugin-based provider keeps its existing timing: it registers in the
     * first pass, on plugins_loaded, and the theme pass leaves it alone.
     */
    public function testAProviderAvailableAtBootRegistersImmediatelyAndIsNotTouchedAgain(): void
    {
        $plugin = self::fakeProvider('gravityforms', true);
        $this->useProviders([$plugin]);

        $registry = new FormProviderRegistry();
        $registry->registerHooks(new SubmissionService());

        self::assertSame(1, $plugin->registrations, 'existing provider timing is unchanged');

        $this->fireAfterSetupTheme();

        self::assertSame(1, $plugin->registrations, 'the theme pass must not re-register it');
    }

    /** A provider available in neither pass registers nothing at all. */
    public function testAProviderThatNeverBecomesAvailableRegistersNothing(): void
    {
        $absent = self::fakeProvider('bricks', false);
        $this->useProviders([$absent]);

        (new FormProviderRegistry())->registerHooks(new SubmissionService());
        $this->fireAfterSetupTheme();

        self::assertSame(0, $absent->registrations);
    }

    /**
     * The guard is by provider KEY, so however many times either pass runs — a
     * re-init, a second Plugin::init(), a theme hook fired twice — a provider's
     * hooks are wired once.
     */
    public function testRepeatedPassesNeverRegisterTheSameProviderTwice(): void
    {
        $plugin = self::fakeProvider('gravityforms', true);
        $theme  = self::fakeProvider('bricks', false);
        $this->useProviders([$plugin, $theme]);

        $registry = new FormProviderRegistry();
        $service  = new SubmissionService();

        $registry->registerHooks($service);
        $registry->registerHooks($service);

        $theme->available = true;
        $this->fireAfterSetupTheme();
        $this->fireAfterSetupTheme();
        $registry->registerHooks($service);

        self::assertSame(1, $plugin->registrations);
        self::assertSame(1, $theme->registrations);
    }

    /** And a second registerHooks() must not queue a second deferred pass. */
    public function testOnlyOneDeferredPassIsEverScheduled(): void
    {
        $this->useProviders([self::fakeProvider('bricks', false)]);

        $registry = new FormProviderRegistry();
        $service  = new SubmissionService();

        $registry->registerHooks($service);
        $registry->registerHooks($service);

        self::assertCount(1, $this->hooks['after_setup_theme']);
    }

    /**
     * Convermetry can legitimately be initialised after the theme is already
     * loaded — a late manual boot, WP-CLI, a test harness. Deferring then would
     * wait for a hook that has already fired and register nothing, ever.
     */
    public function testALateBootRegistersImmediatelyInsteadOfWaitingForAHookThatHasFired(): void
    {
        $theme = self::fakeProvider('bricks', true);
        $this->useProviders([$theme]);
        $this->themeLoaded = 1;

        (new FormProviderRegistry())->registerHooks(new SubmissionService());

        self::assertSame(1, $theme->registrations);
    }

    /**
     * Third-party providers keep working exactly as documented: the filter runs
     * once, when the provider list is first built, and an adapter that is
     * available gets registered. One that is not yet available now gets the same
     * second chance the built-in theme provider does — strictly more than the
     * nothing it used to get.
     */
    public function testThirdPartyProvidersKeepTheirFilterBehaviourAndGainTheSecondChance(): void
    {
        $ready = self::fakeProvider('acme_forms', true);
        $later = self::fakeProvider('acme_theme_forms', false);

        $filterCalls = 0;
        Functions\when('apply_filters')->alias(
            function (string $hook, mixed $value = null, mixed ...$rest) use (&$filterCalls, $ready, $later): mixed {
                if ($hook !== 'convermetry_form_providers') {
                    return $value;
                }

                $filterCalls++;

                return [$ready, $later];
            }
        );

        $registry = new FormProviderRegistry();
        $registry->registerHooks(new SubmissionService());

        self::assertSame(1, $ready->registrations);
        self::assertSame(0, $later->registrations);

        $later->available = true;
        $this->fireAfterSetupTheme();

        self::assertSame(1, $ready->registrations, 'still exactly once');
        self::assertSame(1, $later->registrations);
        self::assertSame(1, $filterCalls, 'the provider list is built, and filtered, once');
    }

    public function testTheBricksProviderIsRegisteredAsABuiltIn(): void
    {
        $registry = new FormProviderRegistry();

        self::assertInstanceOf(BricksFormsProvider::class, $registry->get('bricks'));
        self::assertSame('Bricks Builder', $registry->get('bricks')?->getLabel());
    }

    // ------------------------------------------------------------- absent Bricks

    /**
     * This process has no Bricks, which is the state of the overwhelming
     * majority of sites — and the one where a misplaced constant reference or an
     * eager class lookup would fatal.
     */
    public function testWithoutBricksTheProviderIsUnavailableAndRegistersNothing(): void
    {
        self::assertFalse(defined('BRICKS_VERSION'), 'this process must genuinely not have Bricks');

        $provider = new BricksFormsProvider();

        self::assertFalse(BricksFormsBridge::isInstalled());
        self::assertFalse(BricksFormsBridge::isSupported());
        self::assertSame('', BricksFormsBridge::installedVersion());
        self::assertFalse($provider->isAvailable());

        (new FormProviderRegistry())->registerHooks(new SubmissionService());
        $this->fireAfterSetupTheme();

        self::assertArrayNotHasKey(BricksFormsBridge::ACTION_HOOK, $this->hooks);
        self::assertArrayNotHasKey(BricksFormsBridge::CONTROLS_FILTER, $this->hooks);
    }

    /**
     * Registering the Bricks hooks touches no Bricks symbol at all, which is
     * what makes the deferred pass safe whatever state the theme is in.
     */
    public function testRegisteringTheBricksHooksTouchesNoBricksSymbol(): void
    {
        (new BricksFormsProvider())->registerHooks(new SubmissionService());

        self::assertArrayHasKey(BricksFormsBridge::ACTION_HOOK, $this->hooks);
        self::assertArrayHasKey(BricksFormsBridge::CONTROLS_FILTER, $this->hooks);
    }

    // ------------------------------------------------------------ version gate

    /**
     * Named custom form actions arrived in Bricks 1.12.2. Below that, selecting
     * "Convermetry" in the editor would save an action Bricks never dispatches —
     * an option that looks like it works and captures nothing — so an older
     * Bricks is reported unavailable and nothing registers.
     */
    public function testOnlyBricksWithNamedCustomActionsIsSupported(): void
    {
        self::assertSame('1.12.2', BricksFormsBridge::MIN_VERSION);

        self::assertTrue(BricksFormsBridge::supports('1.12.2'));
        self::assertTrue(BricksFormsBridge::supports('1.12.3'));
        self::assertTrue(BricksFormsBridge::supports('2.0'));
        self::assertTrue(BricksFormsBridge::supports('2.4.1'));

        self::assertFalse(BricksFormsBridge::supports('1.12.1'));
        self::assertFalse(BricksFormsBridge::supports('1.9.2'), 'a two-digit minor is not older than 1.12');
        self::assertFalse(BricksFormsBridge::supports('1.5'));
        self::assertFalse(BricksFormsBridge::supports(''), 'no readable version is not a supported Bricks');
    }

    /**
     * A Bricks CHILD THEME is the normal way to run Bricks, and the child's own
     * stylesheet is not called "bricks". Availability therefore has to key off
     * the version constant the parent's functions.php defines — which a child
     * theme still loads — and never off the active stylesheet.
     *
     * Asserted against the source because the alternative is installing two
     * themes: what matters is that the decision does not consult the stylesheet
     * at all, which is a property of this code rather than of one run through it.
     */
    public function testAvailabilityNeverConsultsTheActiveStylesheet(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Forms/Bricks/BricksFormsBridge.php');
        $code   = (string) preg_replace('~/\*\*.*?\*/~s', '', $source);

        foreach (['get_stylesheet', 'get_template', 'wp_get_theme', 'get_option'] as $themeLookup) {
            self::assertStringNotContainsString(
                $themeLookup,
                $code,
                'a Bricks child theme must be detected exactly like the parent'
            );
        }

        self::assertStringContainsString("defined(self::VERSION_CONSTANT)", $code);
        self::assertStringContainsString("'BRICKS_VERSION'", $code);
    }

    // ------------------------------------------------------------ present Bricks

    /**
     * The positive case, in its own process because PHP cannot un-define a
     * constant and every other test in this file needs Bricks genuinely absent.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testWithASupportedBricksLoadedTheProviderBecomesAvailable(): void
    {
        define('BRICKS_VERSION', '1.12.2');

        self::assertTrue(BricksFormsBridge::isInstalled());
        self::assertSame('1.12.2', BricksFormsBridge::installedVersion());
        self::assertTrue(BricksFormsBridge::isSupported());
        self::assertTrue((new BricksFormsProvider())->isAvailable());

        (new FormProviderRegistry())->registerHooks(new SubmissionService());

        self::assertArrayHasKey(BricksFormsBridge::ACTION_HOOK, $this->hooks, 'the action is wired on a Bricks site');
        self::assertArrayHasKey(BricksFormsBridge::CONTROLS_FILTER, $this->hooks);
        self::assertArrayHasKey(BricksFormsBridge::RENDER_ATTRIBUTES_FILTER, $this->hooks);
    }

    /**
     * An installed-but-too-old Bricks: reported as installed, so the Forms
     * screen can explain itself, and still unavailable, so nothing registers.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAnOlderBricksIsReportedInstalledButNotSupported(): void
    {
        define('BRICKS_VERSION', '1.9.2');

        self::assertTrue(BricksFormsBridge::isInstalled());
        self::assertSame('1.9.2', BricksFormsBridge::installedVersion());
        self::assertFalse(BricksFormsBridge::isSupported());
        self::assertFalse((new BricksFormsProvider())->isAvailable());

        (new FormProviderRegistry())->registerHooks(new SubmissionService());

        self::assertArrayNotHasKey(BricksFormsBridge::ACTION_HOOK, $this->hooks);
    }
}
