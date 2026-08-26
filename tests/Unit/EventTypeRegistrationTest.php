<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Convermetry\Admin\SettingsPage;
use Convermetry\Settings\Options;
use PHPUnit\Framework\TestCase;

/**
 * Event types must be registered in all three places, and only the right ones.
 *
 * Regression origin: adding an event type is a three-place change that LOOKS
 * like a one-place change, and two of the three failures are silent.
 *
 *  - Missing from {@see Options::defaults()}: the type reads as disabled on
 *    every install that has ever saved settings.
 *  - Missing from the label map in SettingsPage::renderTrackingSection(): no
 *    checkbox renders, so the type is absent from the POST, so
 *    SettingsPage::sanitize() — which loops Options::EVENT_TYPES — records it as
 *    OFF the next time anybody saves the Settings screen for any reason. The
 *    feature stops collecting data and nothing on screen says so.
 *
 * The third assertion is a security boundary rather than a bug guard: every
 * type in EVENT_TYPES is accepted by the UNAUTHENTICATED tracking endpoint, so
 * a type representing a server-side DECISION must never appear there. 'goal' is
 * the standing example — goal completions are derived by GoalMatcher and stored
 * in their own table specifically so that no anonymous caller can post one.
 */
final class EventTypeRegistrationTest extends TestCase
{
    /**
     * The tracking-section label map, read out of the rendering source.
     *
     * Reading the source is deliberate. The alternative is invoking the renderer
     * with a full WordPress escaping stack stubbed, which would test the stubs
     * more than the map. What matters here is only which KEYS exist.
     *
     * @return string[]
     */
    private function renderedTypes(): array
    {
        $source = (string) file_get_contents(
            (string) (new \ReflectionClass(SettingsPage::class))->getFileName()
        );

        $start = strpos($source, 'private static function renderTrackingSection');
        self::assertNotFalse($start, 'renderTrackingSection() was renamed — update this test.');

        $end = strpos($source, 'echo \'<h2>Tracking</h2>\'', $start);
        self::assertNotFalse($end, 'The tracking label map no longer precedes the Tracking heading.');

        preg_match_all(
            "/^\s*'([a-z_]+)'\s*=>/m",
            substr($source, $start, $end - $start),
            $matches
        );

        return $matches[1];
    }

    public function testEveryTrackedTypeHasADefault(): void
    {
        $defaults = Options::defaults();

        foreach (Options::EVENT_TYPES as $type) {
            self::assertArrayHasKey(
                'track_' . $type,
                $defaults,
                "Options::defaults() has no 'track_{$type}' entry, so the type reads as disabled."
            );
        }
    }

    public function testEveryTrackedTypeRendersACheckbox(): void
    {
        $rendered = $this->renderedTypes();

        foreach (Options::EVENT_TYPES as $type) {
            self::assertContains(
                $type,
                $rendered,
                "'{$type}' has no label in renderTrackingSection(), so no checkbox renders for it "
                . 'and the next settings save will silently switch it off.'
            );
        }
    }

    public function testTheLabelMapDoesNotOfferTypesThatAreNotTracked(): void
    {
        foreach ($this->renderedTypes() as $type) {
            self::assertContains(
                $type,
                Options::EVENT_TYPES,
                "The Settings screen offers a '{$type}' checkbox for a type the tracker never records."
            );
        }
    }

    /**
     * Goal completions are DERIVED server-side, never reported by a client.
     *
     * If 'goal' were ever added to EVENT_TYPES, the public tracking endpoint
     * would accept `{"type":"goal", ...}` from anyone on the internet, and
     * fabricated conversions would be indistinguishable from real ones in every
     * report.
     */
    public function testServerDerivedTypesAreNotPubliclyPostable(): void
    {
        foreach (['goal', 'goal_completion', 'lead_status'] as $internal) {
            self::assertNotContains(
                $internal,
                Options::EVENT_TYPES,
                "'{$internal}' is a server-derived fact and must never be accepted from the public endpoint."
            );
        }
    }

    /**
     * Unknown types are always allowed through isTypeEnabled() — that is what
     * makes cvm_track_event() usable for site-specific server-side events. Only
     * the built-in list is toggleable.
     */
    public function testUnknownTypesAreNotToggleable(): void
    {
        self::assertTrue(Options::isTypeEnabled('something_bespoke'));
    }
}
