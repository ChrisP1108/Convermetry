<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Version surfaces must agree.
 *
 * The WordPress plugin header has to stay a literal string for WordPress to
 * parse it, so the version cannot be interpolated from one source at runtime.
 * Every other copy is therefore a duplicate that can rot independently — and
 * has: the header once said 0.2.0 while the README and the PayloadBuilder
 * docblock still said 0.1.0.
 *
 * bin/build-zip.sh enforces the same agreement at build time. This suite
 * enforces it in CI, where it fails on the commit that introduced the drift
 * rather than at release.
 */
final class VersionConsistencyTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        $path = self::root() . '/' . $relative;
        self::assertFileExists($path, "{$relative} is missing");

        $contents = file_get_contents($path);
        self::assertIsString($contents, "{$relative} could not be read");

        return $contents;
    }

    /**
     * The plugin header is the single source of truth every other surface is
     * compared against.
     */
    public static function headerVersion(): string
    {
        $matched = preg_match(
            '~^\s*\*\s*Version:\s*(\S+)~m',
            self::read('convermetry.php'),
            $m
        );

        self::assertSame(1, $matched, 'convermetry.php has no parsable Version header');

        return $m[1];
    }

    public function testHeaderVersionIsSemver(): void
    {
        self::assertMatchesRegularExpression(
            '~^\d+\.\d+\.\d+$~',
            self::headerVersion(),
            'The plugin header version must be MAJOR.MINOR.PATCH'
        );
    }

    public function testCvmVersionConstantMatchesTheHeader(): void
    {
        $matched = preg_match(
            "~define\(\s*'CVM_VERSION'\s*,\s*'([^']+)'~",
            self::read('convermetry.php'),
            $m
        );

        self::assertSame(1, $matched, 'CVM_VERSION is not defined as a literal');
        self::assertSame(self::headerVersion(), $m[1], 'CVM_VERSION disagrees with the plugin header');
    }

    public function testReadmeVersionLineMatchesTheHeader(): void
    {
        $matched = preg_match(
            '~^- \*\*Version:\*\* (\S+)~m',
            self::read('README.md'),
            $m
        );

        self::assertSame(1, $matched, 'README.md has no "- **Version:**" line');
        self::assertSame(self::headerVersion(), $m[1], 'README.md version disagrees with the plugin header');
    }

    /**
     * The live payload builds plugin_version from CVM_VERSION, so only the
     * prose copies in the README and the PayloadBuilder docblock can rot.
     */
    public function testEveryPayloadExampleMatchesTheHeader(): void
    {
        $expected = self::headerVersion();
        $found    = 0;

        foreach (['README.md', 'src/Webhook/PayloadBuilder.php'] as $relative) {
            preg_match_all('~"plugin_version":\s*"([^"]+)"~', self::read($relative), $matches);

            foreach ($matches[1] as $version) {
                $found++;
                self::assertSame(
                    $expected,
                    $version,
                    "A \"plugin_version\" example in {$relative} disagrees with the plugin header"
                );
            }
        }

        self::assertGreaterThan(0, $found, 'No payload examples were found to check');
    }

    /**
     * The changelog has to carry the version actually being shipped, or the
     * release notes describe something else.
     */
    public function testChangelogDocumentsTheCurrentVersion(): void
    {
        self::assertStringContainsString(
            '## ' . self::headerVersion(),
            self::read('CHANGELOG.md'),
            'CHANGELOG.md has no section for the version in the plugin header'
        );
    }

    /**
     * No stray copy of an older version may linger in a shipped surface.
     */
    public function testNoTrackedSurfaceStillCarriesAnOlderVersion(): void
    {
        $current = self::headerVersion();

        foreach (['convermetry.php', 'README.md', 'src/Webhook/PayloadBuilder.php'] as $relative) {
            $contents = self::read($relative);

            preg_match_all('~\b(\d+\.\d+\.\d+)\b~', $contents, $matches);

            foreach (array_unique($matches[1]) as $candidate) {
                // Schema versions and historical references are legitimately
                // different numbers; only a version-labelled surface is checked
                // by the assertions above. This guards the specific strings that
                // are meant to track the release.
                if ($candidate === $current) {
                    continue;
                }

                self::assertStringNotContainsString(
                    "Version:     {$candidate}",
                    $contents,
                    "{$relative} still carries a stale plugin header version"
                );
                self::assertStringNotContainsString(
                    "\"plugin_version\": \"{$candidate}\"",
                    $contents,
                    "{$relative} still carries a stale plugin_version example"
                );
            }
        }
    }
}
