<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\FormSettings;
use PHPUnit\Framework\TestCase;

/**
 * Elementor per-form identity, and the migration off name-based keys.
 *
 * Regression origin: settings were keyed by form NAME, so two widgets both
 * called "New Form" collapsed into one shared configuration. Keying by widget
 * id fixes that, but switching outright would orphan every existing site's
 * settings — hence the fallback, and hence the legacy entry being kept rather
 * than deleted while queued deliveries may still reference it.
 */
final class ElementorIdentityTest extends TestCase
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

    public function testWidgetKeyIsUsedWhenItHasStoredSettings(): void
    {
        $this->stored = [$this->key('a1b2c3d') => ['form_id' => 'new'], $this->key('New Form') => ['form_id' => 'legacy']];

        self::assertSame(
            $this->key('a1b2c3d'),
            FormSettings::resolveKey($this->key('a1b2c3d'), $this->key('New Form'))
        );
    }

    /** A site upgrading with existing name-keyed settings must keep using them. */
    public function testFallsBackToTheLegacyNameKeyBeforeAnyReSave(): void
    {
        $this->stored = [$this->key('New Form') => ['form_id' => 'legacy']];

        $resolved = FormSettings::resolveKey($this->key('a1b2c3d'), $this->key('New Form'));

        self::assertSame($this->key('New Form'), $resolved);
        self::assertSame('legacy', FormSettings::forForm($resolved)['form_id']);
    }

    /** A brand-new form is configured under the current identity. */
    public function testUnknownFormResolvesToTheWidgetKey(): void
    {
        $this->stored = [];

        self::assertSame(
            $this->key('a1b2c3d'),
            FormSettings::resolveKey($this->key('a1b2c3d'), $this->key('New Form'))
        );
    }

    public function testResolutionIsANoOpWithoutALegacyKey(): void
    {
        $this->stored = [];

        self::assertSame($this->key('a1b2c3d'), FormSettings::resolveKey($this->key('a1b2c3d'), ''));
    }

    /**
     * The point of the whole change: two widgets sharing a name no longer share
     * a configuration.
     */
    public function testSameNamedWidgetsResolveToIndependentKeys(): void
    {
        $this->stored = [
            $this->key('a1b2c3d') => ['form_id' => 'first'],
            $this->key('e4f5g6h') => ['form_id' => 'second'],
        ];

        $first  = FormSettings::resolveKey($this->key('a1b2c3d'), $this->key('New Form'));
        $second = FormSettings::resolveKey($this->key('e4f5g6h'), $this->key('New Form'));

        self::assertNotSame($first, $second);
        self::assertSame('first', FormSettings::forForm($first)['form_id']);
        self::assertSame('second', FormSettings::forForm($second)['form_id']);
    }

    /**
     * Queued rows store the legacy form key and read it when their delivery is
     * first frozen, which can be long after the row was written — so the legacy
     * entry must still resolve after the new key exists.
     */
    public function testLegacyKeyStillResolvesForInFlightDeliveriesAfterMigration(): void
    {
        $this->stored = [
            $this->key('a1b2c3d')  => ['form_id' => 'migrated'],
            $this->key('New Form') => ['form_id' => 'legacy'],
        ];

        self::assertSame('legacy', FormSettings::forForm($this->key('New Form'))['form_id']);
    }
}
