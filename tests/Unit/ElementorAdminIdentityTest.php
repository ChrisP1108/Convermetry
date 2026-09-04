<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\FormSettings;
use PHPUnit\Framework\TestCase;

/**
 * The Forms screen's read/write asymmetry for re-keyed providers.
 *
 * The admin block READS a form's configuration through the legacy fallback but
 * WRITES it under the form's current key. Both halves matter, and getting
 * either wrong is silently destructive:
 *
 *  - Reading the current key directly would show blank defaults to a site
 *    upgrading from name-keyed Elementor settings. Saving that screen would then
 *    store those defaults under the widget key, and because resolveKey() stops
 *    falling back once the current key exists, the site's real configuration —
 *    including an 'excluded' flag holding a form back from delivery — would be
 *    discarded without a word.
 *
 *  - Writing the legacy key back would leave two same-named widgets sharing one
 *    entry forever, which is the defect the widget-id change exists to fix.
 */
final class ElementorAdminIdentityTest extends TestCase
{
    /** @var array<string, array<string, mixed>> */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(fn(string $key, $default = false) => $key === FormSettings::OPTION_KEY
            ? $this->stored
            : $default);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function key(string $identity): string
    {
        return FormProviderRegistry::formKey('elementor', $identity);
    }

    /**
     * Mirrors what the Forms screen computes for one discovered form.
     *
     * @return array{read: string, write: string}
     */
    private function adminKeys(string $widgetId, string $name): array
    {
        $current = FormProviderRegistry::formKey('elementor', $widgetId);

        return [
            'read'  => FormSettings::resolveKey(
                $current,
                FormProviderRegistry::legacyFormKey('elementor', $name)
            ),
            'write' => $current,
        ];
    }

    public function testLegacyKeyIsOnlyDeclaredForElementor(): void
    {
        self::assertSame(
            $this->key('New Form'),
            FormProviderRegistry::legacyFormKey('elementor', 'New Form')
        );

        foreach (['gravityforms', 'wpforms', 'cf7', 'fluentform', 'ninjaforms', 'formidable'] as $provider) {
            self::assertSame(
                '',
                FormProviderRegistry::legacyFormKey($provider, 'New Form'),
                "{$provider} never re-keyed and must declare no legacy key"
            );
        }
    }

    public function testLegacyKeyIsEmptyForAnUnnamedForm(): void
    {
        self::assertSame('', FormProviderRegistry::legacyFormKey('elementor', ''));
    }

    /**
     * The upgrade case: settings exist only under the name key.
     */
    public function testAdminReadsLegacyConfigButWritesTheWidgetKey(): void
    {
        $this->stored = [$this->key('New Form') => ['form_id' => 'legacy', 'excluded' => true]];

        $keys = $this->adminKeys('a1b2c3d', 'New Form');

        self::assertSame($this->key('New Form'), $keys['read'], 'Existing settings must be shown, not defaults');
        self::assertSame($this->key('a1b2c3d'), $keys['write'], 'A save must migrate onto the widget key');

        $config = FormSettings::forForm($keys['read']);
        self::assertSame('legacy', $config['form_id']);
        self::assertTrue($config['excluded'], 'An excluded form must not silently start delivering');
    }

    /**
     * Two widgets sharing a name write to different keys even before either has
     * been saved, so the first save separates them.
     */
    public function testSameNamedWidgetsWriteToDistinctKeys(): void
    {
        $this->stored = [$this->key('New Form') => ['form_id' => 'shared']];

        $first  = $this->adminKeys('a1b2c3d', 'New Form');
        $second = $this->adminKeys('e4f5g6h', 'New Form');

        self::assertNotSame($first['write'], $second['write']);
        self::assertSame($this->key('New Form'), $first['read']);
        self::assertSame($this->key('New Form'), $second['read']);
    }

    /**
     * Once migrated, the widget key wins and the legacy entry stops being read
     * for that widget — but is still resolvable for in-flight queued rows.
     */
    public function testMigratedWidgetReadsItsOwnEntryWhileLegacyRemains(): void
    {
        $this->stored = [
            $this->key('a1b2c3d')  => ['form_id' => 'migrated'],
            $this->key('New Form') => ['form_id' => 'legacy'],
        ];

        $keys = $this->adminKeys('a1b2c3d', 'New Form');

        self::assertSame($this->key('a1b2c3d'), $keys['read']);
        self::assertSame($this->key('a1b2c3d'), $keys['write']);
        self::assertSame('migrated', FormSettings::forForm($keys['read'])['form_id']);
        self::assertSame('legacy', FormSettings::forForm($this->key('New Form'))['form_id']);
    }

    /**
     * A renamed form keeps its settings, because identity no longer follows the
     * name at all.
     */
    public function testRenamingAFormDoesNotOrphanMigratedSettings(): void
    {
        $this->stored = [$this->key('a1b2c3d') => ['form_id' => 'kept']];

        $keys = $this->adminKeys('a1b2c3d', 'Renamed Later');

        self::assertSame($this->key('a1b2c3d'), $keys['read']);
        self::assertSame('kept', FormSettings::forForm($keys['read'])['form_id']);
    }
}
