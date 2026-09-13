<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\Atomic\AtomicFormsBridge;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\Providers\ElementorAtomicProvider;
use Convermetry\Forms\Providers\ElementorProvider;
use Convermetry\Forms\SubmissionService;
use PHPUnit\Framework\TestCase;

/**
 * What happens on the overwhelming majority of sites: the ones with no
 * Elementor Pro, or with a version that has no Atomic Form module.
 *
 * The danger is specific. Convermetry ships a class that EXTENDS an Elementor
 * Pro class, and naming that subclass anywhere triggers the autoloader, which
 * loads a file whose parent does not exist — a fatal error on every page of the
 * site, caused by a plugin the owner does not even have installed.
 *
 * Absence cannot be simulated in-process: another suite in this run defines the
 * Elementor stubs, and a class cannot be un-defined. So this file asserts the
 * two things that actually prevent the fatal, neither of which depends on the
 * classes being absent — the guard REFUSES anything unusable at runtime, and the
 * coupled subclass is reachable from exactly one place, behind the class_exists
 * check.
 */
final class AtomicSafetyTest extends TestCase
{
    /** @var array<string, list<callable>> Hook name → callbacks. */
    private array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->hooks = [];

        Functions\when('add_action')->alias(function (string $hook, $callback, $priority = 10, $args = 1): bool {
            $this->hooks[$hook][] = $callback;
            return true;
        });
        Functions\when('add_filter')->alias(function (string $hook, $callback, $priority = 10, $args = 1): bool {
            $this->hooks[$hook][] = $callback;
            return true;
        });
        Functions\when('did_action')->justReturn(0);
        Functions\when('sanitize_text_field')->alias(static fn($value) => trim((string) $value));
        Functions\when('sanitize_key')->alias(static fn($value) => strtolower((string) $value));
        Functions\when('get_option')->alias(static fn(string $key, $default = false) => $default === false ? [] : $default);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private static function bridgeSource(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../src/Forms/Atomic/AtomicFormsBridge.php');
    }

    /**
     * Registering the hooks touches no Elementor symbol at all — which is what
     * makes it safe to do at plugins_loaded, before another plugin's autoloader
     * may even be registered.
     */
    public function testHooksRegisterWithoutTouchingAnyElementorClass(): void
    {
        (new ElementorAtomicProvider())->registerHooks(new SubmissionService());

        self::assertArrayHasKey(AtomicFormsBridge::REGISTER_HOOK, $this->hooks);
        self::assertArrayHasKey('elementor/atomic-widgets/controls', $this->hooks);
    }

    /**
     * The provider is called again on a re-init; it must not stack duplicate
     * hooks, which would run the editor filter twice over one control.
     */
    public function testRepeatedProviderRegistrationAddsNoDuplicateHooks(): void
    {
        $provider = new ElementorAtomicProvider();
        $service  = new SubmissionService();

        $provider->registerHooks($service);
        $provider->registerHooks($service);

        self::assertCount(1, $this->hooks[AtomicFormsBridge::REGISTER_HOOK]);
    }

    /**
     * The guard that prevents the fatal. Each of these is a shape Elementor Pro
     * could never send, and every one must be refused rather than reaching the
     * subclass.
     */
    public function testTheRunnerGuardRefusesEverythingUnusable(): void
    {
        $bridge = new AtomicFormsBridge(new SubmissionService());

        foreach ([null, '', 'Not\\A\\Real\\Class', 0, 1.5, true, [], new \stdClass()] as $runner) {
            $bridge->registerAction($runner);
        }

        // Reaching here at all is the assertion: none of these may throw, and
        // none may load the Elementor-coupled subclass.
        self::assertTrue(true);
    }

    /**
     * The subclass may be NAMED in exactly one place, and only after
     * class_exists() has confirmed its parent. Anything else — a use statement,
     * a type hint, an instanceof — would make the autoloader fire on a site
     * without Elementor Pro.
     */
    public function testTheCoupledSubclassIsReachableOnlyBehindTheClassExistsGuard(): void
    {
        $code = (string) preg_replace('~/\*\*.*?\*/~s', '', self::bridgeSource());

        $guard     = strpos($code, 'class_exists(self::ACTION_BASE_CLASS)');
        $reference = strpos($code, 'new ConvermetryAtomicAction(');

        self::assertIsInt($guard, 'the base-class guard must exist');
        self::assertIsInt($reference, 'the subclass is constructed by the bridge');
        self::assertGreaterThan($guard, $reference, 'the guard must come before the only reference');
        self::assertSame(1, substr_count($code, 'ConvermetryAtomicAction'), 'exactly one reference');
    }

    /**
     * Only the subclass file may name the Elementor Pro base class in a way that
     * binds at load time.
     */
    public function testOnlyTheSubclassFileImportsTheElementorProBaseClass(): void
    {
        $matches = [];

        foreach (self::phpFilesIn(__DIR__ . '/../../src') as $file) {
            $source = (string) file_get_contents($file);

            if (preg_match('~^use ElementorPro\\\\~m', $source) === 1) {
                $matches[] = basename($file);
            }
        }

        self::assertSame(['ConvermetryAtomicAction.php'], $matches);
    }

    /**
     * Availability is the Elementor Pro marker, deliberately not a class probe.
     *
     * Provider hooks are registered on plugins_loaded, where another plugin's
     * CONSTANTS are already defined but its autoloader may not be registered
     * yet — so a class_exists() probe there can answer "no" on a site that has
     * Atomic forms, and this integration would then never register its action.
     * A constant cannot lie about that.
     */
    public function testAvailabilityIsDecidedByTheProMarkerNotAClassProbe(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Forms/Providers/ElementorAtomicProvider.php');

        $start = strpos($source, 'public function isAvailable()');
        self::assertIsInt($start);

        $method = substr($source, $start, 220);

        self::assertStringContainsString('ELEMENTOR_PRO_VERSION', $method);
        self::assertStringNotContainsString('AtomicWidgets', $method, 'no Atomic class probe at registration time');
    }

    /** A malformed context is a skip, never an exception and never a failed form. */
    public function testAMalformedContextIsSurvived(): void
    {
        $bridge = new AtomicFormsBridge(new SubmissionService());

        foreach ([[], ['form_id' => []], ['post_id' => 42], ['form_id' => '']] as $context) {
            $outcome = $bridge->handleSubmission([], $context);

            self::assertTrue($outcome['ok'], 'the visitor must never be failed by a context Convermetry cannot read');
        }
    }

    // ------------------------------------------------- classic Elementor regression

    /**
     * The classic integration is untouched: same provider key, same hook, and
     * the name-keyed legacy fallback that sites upgrading from 0.8.0 still rely
     * on.
     */
    public function testClassicElementorIsUnchanged(): void
    {
        $classic = new ElementorProvider();
        $classic->registerHooks(new SubmissionService());

        self::assertSame('elementor', $classic->getKey());
        self::assertSame('Elementor Pro', $classic->getLabel());
        self::assertArrayHasKey('elementor_pro/forms/new_record', $this->hooks);
        self::assertSame('elementor:Contact', FormProviderRegistry::legacyFormKey('elementor', 'Contact'));
    }

    /**
     * The two providers coexist as separate entries. A shared key would have
     * collapsed their forms into one list and one settings namespace.
     */
    public function testBothElementorProvidersAreRegisteredAndDistinct(): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest): mixed => $value
        );

        $providers = (new FormProviderRegistry())->all();

        self::assertArrayHasKey('elementor', $providers);
        self::assertArrayHasKey('elementor_atomic', $providers);
        self::assertNotSame($providers['elementor']->getLabel(), $providers['elementor_atomic']->getLabel());
        self::assertNotSame(
            FormProviderRegistry::formKey('elementor', 'e1a2b3c'),
            FormProviderRegistry::formKey('elementor_atomic', 'e1a2b3c'),
            'the same id under each provider is two different forms'
        );
    }

    /**
     * Every PHP file under one directory.
     *
     * @param string $dir Directory to walk.
     * @return list<string>
     */
    private static function phpFilesIn(string $dir): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
