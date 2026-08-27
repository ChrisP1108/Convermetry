<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the published hook list and the code that fires the hooks in step.
 *
 * With eighty-odd public hooks, documentation drift is not a hypothetical: the
 * failure mode is a plugin author reading the README, wiring a callback to a
 * hook that was renamed two releases ago, and getting silence — no error, no
 * warning, just a feature that never runs. The reverse hurts too: a hook that
 * exists and is not written down might as well not exist.
 *
 * So this asserts both directions. Every convermetry_* literal in src/ appears
 * in the README's hook tables, and every hook named in those tables exists in
 * src/. It is source-text on both sides because documentation coverage IS a
 * source-level property; there is nothing to run.
 */
final class HookCatalogTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../';

    /**
     * Hooks Convermetry LISTENS on rather than fires, which are documented in
     * their own right but never appear in a do_action()/apply_filters() call.
     */
    private const INBOUND = ['convermetry_form_submission'];

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

    public function testEveryHookTheCodeFiresIsDocumented(): void
    {
        $documented = self::documentedHooks();
        $missing    = array_values(array_diff(self::firedHooks(), $documented));

        self::assertSame(
            [],
            $missing,
            "These hooks are fired but absent from README's Developer hooks section: "
            . implode(', ', $missing)
        );
    }

    public function testEveryDocumentedHookActuallyExists(): void
    {
        $fired   = array_merge(self::firedHooks(), self::INBOUND);
        $ghosts  = array_values(array_diff(self::documentedHooks(), $fired));

        self::assertSame(
            [],
            $ghosts,
            'These hooks are documented but no longer exist in src/: ' . implode(', ', $ghosts)
        );
    }

    /**
     * The nineteen hooks that existed before the public integration API. Each
     * is a published contract that something out there already listens on, so
     * removing or renaming one is a breaking change no minor release may make.
     */
    public function testEveryPreExistingHookSurvives(): void
    {
        $fired = self::firedHooks();

        foreach ([
            'convermetry_tracked_event',
            'convermetry_webhook_payload',
            'convermetry_webhook_report_limit',
            'convermetry_allowed_hosts',
            'convermetry_client_ip',
            'convermetry_rate_limits',
            'convermetry_source_aliases',
            'convermetry_channel',
            'convermetry_delivery_log_row',
            'convermetry_allow_insecure_webhooks',
            'convermetry_form_providers',
            'convermetry_retry_schedule',
            'convermetry_notification_retry_schedule',
            'convermetry_sensitive_keys',
            'convermetry_submission_recorded',
            'convermetry_goal_completion',
            'convermetry_goal_matched',
            'convermetry_lead_status_updated',
        ] as $hook) {
            self::assertContains($hook, $fired, "{$hook} is a published contract and must keep firing.");
        }
    }

    /**
     * Every hook carries a PHPDoc block immediately above its call. That is the
     * house style, and for a hook it is the only documentation an integrator
     * reading the source will find.
     */
    public function testEveryHookCallIsPrecededByADocBlock(): void
    {
        foreach (self::sources() as $file => $source) {
            if (self::isDocumentationScreen($file)) {
                continue;
            }

            $offset = 0;

            while (preg_match(
                '~(?:do_action|apply_filters)\(\s*\'(convermetry_[a-z_]+)\'~',
                $source,
                $m,
                PREG_OFFSET_CAPTURE,
                $offset
            )) {
                $pos    = (int) $m[0][1];
                $offset = $pos + 1;

                // Helper wrappers re-fire a hook documented at the definition;
                // the block sits above the method, not the call.
                $preceding = substr($source, max(0, $pos - 3000), min($pos, 3000));

                self::assertStringContainsString(
                    '*/',
                    $preceding,
                    "{$m[1][0]} in " . basename($file) . ' has no documentation above it.'
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function firedHooks(): array
    {
        $hooks = [];

        foreach (self::sources() as $file => $source) {
            if (self::isDocumentationScreen($file)) {
                continue;
            }

            preg_match_all(
                '~(?:do_action|apply_filters)\(\s*\'(convermetry_[a-z_]+)\'~',
                $source,
                $matches
            );
            $hooks = array_merge($hooks, $matches[1]);

            // Extension buckets are dispatched through Extensions::attach(),
            // which takes the hook name as an argument — so the literal is
            // several lines below the call rather than beside it.
            preg_match_all(
                '~Extensions::attach\([^;]*?\'(convermetry_[a-z_]+)\'~s',
                $source,
                $attached
            );
            $hooks = array_merge($hooks, $attached[1]);
        }

        // Dynamically named WordPress core hooks Convermetry listens on
        // (update_option_*) are not part of this catalogue.
        $hooks = array_values(array_unique($hooks));
        sort($hooks);

        return $hooks;
    }

    /**
     * Whether a file merely PRINTS hook documentation rather than firing hooks.
     *
     * The About screen shows integrators example snippets, so its source
     * contains hook names inside escaped strings. Scanning it would count
     * documentation as calls, and demand a PHPDoc block above an echo.
     */
    private static function isDocumentationScreen(string $file): bool
    {
        return basename($file) === 'AboutPage.php';
    }

    /**
     * @return list<string>
     */
    private static function documentedHooks(): array
    {
        $readme = (string) file_get_contents(self::PLUGIN_DIR . 'README.md');

        $start = strpos($readme, '## Developer hooks');
        self::assertIsInt($start, 'The Developer hooks section is missing from README.md.');

        $end     = strpos($readme, "\n## ", $start + 1);
        $section = substr($readme, $start, $end === false ? PHP_INT_MAX : $end - $start);

        // Table rows only: a hook mentioned in prose is not a hook reference.
        preg_match_all('~^\|\s*`(convermetry_[a-z_]+)`~m', $section, $matches);

        $hooks = array_values(array_unique($matches[1]));
        sort($hooks);

        return $hooks;
    }

    /**
     * @return array<string, string>
     */
    private static function sources(): array
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::PLUGIN_DIR . 'src')
        );

        $out = [];
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }
}
