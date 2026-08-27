<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Settings\Options;
use Convermetry\Settings\SettingsEvents;
use PHPUnit\Framework\TestCase;

/**
 * The operational hooks — settings, migrations, retention, storage errors.
 *
 * Every one of these makes a claim about something that already happened, and
 * the easy version of each is wrong in the same way:
 *
 *  - "settings saved" fired from a sanitize callback announces a save that
 *    WordPress may then skip, because it does not write an unchanged value;
 *  - "migration completed" fired at the end of the try block never runs on the
 *    path that failed, and a still-pending migration is not a failure anyway;
 *  - "storage error" fired wherever a write returned falsy cries wolf, because
 *    an INSERT IGNORE returning null is a duplicate, not a broken database.
 *
 * This file pins the correct version of each.
 */
final class OperationsHookTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../';

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->fired = [];
        Functions\when('do_action')->alias(function (string $hook, mixed ...$args): void {
            $this->fired[] = [$hook, $args];
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return list<array{0: string, 1: list<mixed>}>
     */
    private function firedNamed(string $hook): array
    {
        return array_values(array_filter($this->fired, static fn(array $f): bool => $f[0] === $hook));
    }

    // ------------------------------------------------------------- settings

    /**
     * Listening on WordPress's own write hooks is what makes this true: they
     * fire after the row is written, not when a form is submitted.
     */
    public function testSavingAnnouncesTheSectionAndTheChangedKeysOnly(): void
    {
        SettingsEvents::onUpdate(
            ['retention_days' => 90, 'respect_dnt' => true],
            ['retention_days' => 30, 'respect_dnt' => true],
            Options::OPTION_KEY
        );

        $fired = $this->firedNamed('convermetry_settings_saved');

        self::assertCount(1, $fired);
        self::assertSame(['general', ['retention_days']], $fired[0][1]);
    }

    /**
     * The reason values are not passed: two of these sections hold signing
     * secrets and endpoint URLs that routinely embed bearer tokens, and this is
     * exactly the action people wire to a log file.
     */
    public function testNoSecretValueIsEverPassedToAListener(): void
    {
        SettingsEvents::onUpdate(
            ['shared_secret' => 'old-secret', 'endpoints' => []],
            ['shared_secret' => 'SUPER-SECRET-KEY', 'endpoints' => [['url' => 'https://x.test/?token=ABC']]],
            Options::WEBHOOK_OPTION_KEY
        );

        $fired = $this->firedNamed('convermetry_settings_saved');

        self::assertSame('webhooks', $fired[0][1][0]);
        self::assertSame(['shared_secret', 'endpoints'], $fired[0][1][1]);

        $flat = (string) json_encode($fired);
        self::assertStringNotContainsString('SUPER-SECRET-KEY', $flat);
        self::assertStringNotContainsString('token=ABC', $flat);
    }

    public function testAnOptionThatIsNotAConvermetrySectionIsIgnored(): void
    {
        SettingsEvents::onUpdate(['a' => 1], ['a' => 2], 'some_other_plugin_settings');

        self::assertSame([], $this->firedNamed('convermetry_settings_saved'));
    }

    public function testAFirstTimeWriteReportsEveryKeyAsChanged(): void
    {
        SettingsEvents::onAdd(Options::OPTION_KEY, ['retention_days' => 90, 'respect_dnt' => true]);

        $fired = $this->firedNamed('convermetry_settings_saved');

        self::assertSame(['general', ['retention_days', 'respect_dnt']], $fired[0][1]);
    }

    /**
     * Source-contract. A sanitize_callback runs on a value that has not been
     * stored and may never be — WordPress skips the write when the sanitized
     * value matches what is already there.
     */
    public function testTheSaveActionIsNotFiredFromASanitizeCallback(): void
    {
        self::assertStringNotContainsString(
            'convermetry_settings_saved',
            (string) file_get_contents(self::PLUGIN_DIR . 'src/Admin/SettingsPage.php'),
            'Sanitization is not persistence.'
        );
    }

    // ------------------------------------------------------------ migration

    /**
     * Source-contract. runNow() has no catch of its own; an action placed after
     * the try/finally would be unreachable whenever a migration threw — which
     * is the one case a failure hook exists for.
     */
    public function testTheFailureActionIsReachableOnTheThrowingPath(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Database/MigrationRunner.php');
        $method = substr($source, (int) strpos($source, 'public static function runNow'), 6000);

        $catch    = strpos($method, 'catch (\Throwable $e)');
        $release  = strpos($method, 'self::releaseLock($lock);');
        $failed   = strpos($method, "'convermetry_migration_failed'");
        $rethrow  = strpos($method, 'throw $failure;');

        self::assertIsInt($catch, 'the pass must capture a throwable rather than let it escape unannounced');
        self::assertIsInt($release);
        self::assertIsInt($failed);
        self::assertIsInt($rethrow);

        self::assertGreaterThan($release, $failed, 'the lease must be released before a listener runs');
        self::assertLessThan($rethrow, $failed, 'the listener must run before the failure continues to the caller');
    }

    /**
     * Source-contract. "Completed" may not be declared until the still-pending
     * check and the rescheduling decision have both been made, or $pending
     * would be a guess.
     */
    public function testCompletionIsDeclaredOnlyAfterThePendingAndRescheduleDecisions(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Database/MigrationRunner.php');
        $method = substr($source, (int) strpos($source, 'public static function runNow'), 6000);

        $pending   = strpos($method, '$pending = self::isPending();');
        $schedule  = strpos($method, 'self::schedule();', (int) $pending);
        $completed = strpos($method, "'convermetry_migration_completed'");

        self::assertIsInt($pending);
        self::assertIsInt($schedule);
        self::assertIsInt($completed);
        self::assertGreaterThan($pending, $completed);
        self::assertGreaterThan($schedule, $completed);
    }

    public function testNoMigrationActionIsFiredFromInsideTheFinallyBlock(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Database/MigrationRunner.php');

        $start = strpos($source, '} finally {');
        self::assertIsInt($start);

        $finally = substr($source, $start, 120);
        self::assertStringNotContainsString(
            'do_action',
            $finally,
            'A throwing listener inside finally would mask the lease release.'
        );
    }

    // -------------------------------------------------------- storage errors

    /**
     * Source-contract. FormSubmissions::insert() returns null for an INSERT
     * IGNORE duplicate — the ordinary case for a double-fired provider
     * callback — so treating that as a database failure would report an error
     * on a healthy site every time somebody double-clicks Submit.
     */
    public function testADuplicateInsertIsNotReportedAsAStorageError(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Database/FormSubmissions.php');
        $method = substr($source, (int) strpos($source, 'public static function insert('), 3000);

        self::assertStringContainsString('if ($inserted !== 1) {', $method);
        self::assertStringNotContainsString('Errors::storage(', $method);
    }

    /**
     * Source-contract. The rule that keeps this hook usable: no argument may
     * carry the failing statement, which quotes row values verbatim.
     */
    public function testNoStorageErrorCallPassesADatabaseErrorString(): void
    {
        foreach ($this->sourceFiles() as $file => $source) {
            $offset = 0;
            while (($pos = strpos($source, 'Errors::storage(', $offset)) !== false) {
                $offset = $pos + 1;
                $call   = substr($source, $pos, 300);

                foreach (['last_error', '$sql', '$wpdb->last', '->getMessage()'] as $forbidden) {
                    self::assertStringNotContainsString(
                        $forbidden,
                        $call,
                        "An Errors::storage() call in {$file} leaks database detail."
                    );
                }
            }
        }
    }

    // ------------------------------------------------------------ retention

    /**
     * Source-contract, and the reason the retention work was scoped the way it
     * was: these loop conditions encode the deletion behaviour, and a hook API
     * is no reason to rewrite them. Only an accumulator was added.
     *
     * @dataProvider retentionStores
     */
    public function testEveryRetentionLoopKeepsItsOriginalStoppingCondition(string $file): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . $file);
        $method = substr($source, (int) strpos($source, 'public static function purgeOld'), 2500);

        self::assertMatchesRegularExpression(
            '~\} while \(\s*is_int\(\$deleted\) && \$deleted === self::CLEANUP_CHUNK\s*'
            . '&& \+\+\$runs < self::CLEANUP_MAX_CHUNKS\s*&& microtime\(true\) < \$deadline\s*\);~',
            $method,
            'The original stopping condition must be untouched.'
        );

        self::assertStringContainsString('Retention::started(', $method);
        self::assertStringContainsString('Retention::completed(', $method);
        self::assertStringContainsString('$total += is_int($deleted) ? $deleted : 0;', $method);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function retentionStores(): array
    {
        return [
            'delivery log'     => ['src/Webhook/DeliveryLog.php'],
            'goal completions' => ['src/Goals/GoalCompletions.php'],
            'lead events'      => ['src/Leads/LeadEvents.php'],
            'form submissions' => ['src/Database/FormSubmissions.php'],
        ];
    }

    /**
     * Source-contract. Retention is a promise the site owner made about the
     * data they keep; a listener must not be able to renegotiate it.
     */
    public function testNoRetentionHookCanCancelOrExtendAPass(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Support/Retention.php');

        self::assertSame(
            0,
            preg_match('~apply_filters\(~', $source),
            'Retention exposes actions only — a filter here could keep data past its cutoff.'
        );
        self::assertSame(2, preg_match_all('~do_action\(~', $source));
    }

    /**
     * @return array<string, string>
     */
    private function sourceFiles(): array
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::PLUGIN_DIR . 'src'));
        $out   = [];

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[$file->getPathname()] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }
}
