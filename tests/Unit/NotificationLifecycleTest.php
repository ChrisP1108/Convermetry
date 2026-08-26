<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Notifications\NotificationQueue;
use Convermetry\Webhook\AnalyticsDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * The notification queue's schema shape and its lifecycle wiring.
 *
 * READ THIS BEFORE TRUSTING IT. The assertions here fall into two kinds:
 *
 *  - BEHAVIORAL, where a callback can actually be observed: the worker hook
 *    registration, the submission listener, the cleanup listeners.
 *  - SOURCE-CONTRACT, where it cannot: convermetry.php's activation and
 *    deactivation bodies and uninstall.php are top-level procedural code that
 *    this harness cannot invoke. Those assertions read the file and check the
 *    wiring is PRESENT.
 *
 * The source-contract half proves only that a line was not deleted in a
 * refactor. It proves nothing about whether the SQL is correct, whether
 * dbDelta actually created the index, or whether a delete really cascaded.
 * Those need a real database and live on the manual checklist in
 * tests/bootstrap.php.
 *
 * There is deliberately no hand-rolled $wpdb mock here: a mock returns the
 * test author's model of MySQL, and a green "delete cascade" test built on one
 * would make an unverified erasure guarantee look verified, which is worse
 * than having no test at all.
 */
final class NotificationLifecycleTest extends TestCase
{
    private const string PLUGIN_DIR = __DIR__ . '/../../';

    /** @var array<string, list<mixed>> */
    private array $hooks = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->hooks = [];

        Functions\when('add_action')->alias(function (string $hook, $callback, int $priority = 10, int $args = 1): void {
            $this->hooks[$hook][] = ['callback' => $callback, 'priority' => $priority, 'args' => $args];
        });
        Functions\when('apply_filters')->returnArg(2);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function source(string $file): string
    {
        return (string) file_get_contents(self::PLUGIN_DIR . $file);
    }

    // ── Schema shape (no database needed, and non-tautological) ───────────────

    /**
     * The classic failure of the verify-then-stamp pattern: a column added to
     * the DDL but missing from the verification list leaves the version option
     * unstamped, so dbDelta re-runs on every page load — and a column in the
     * list but missing from the DDL stamps a version for an incomplete table.
     */
    public function testEveryVerifiedColumnActuallyAppearsInTheDdl(): void
    {
        $source = $this->source('src/Notifications/NotificationQueue.php');

        preg_match('/CREATE TABLE \{\$table\} \((.*?)\) \{\$charset\}/s', $source, $m);
        self::assertNotEmpty($m, 'Could not locate the CREATE TABLE statement');

        $ddl = $m[1];

        foreach (NotificationQueue::expectedColumns() as $column) {
            self::assertMatchesRegularExpression(
                '/^\s*' . preg_quote($column, '/') . '\s+[A-Z]/m',
                $ddl,
                "Column '{$column}' is verified but not defined in the DDL"
            );
        }
    }

    public function testEveryColumnInTheDdlIsVerified(): void
    {
        $source = $this->source('src/Notifications/NotificationQueue.php');

        preg_match('/CREATE TABLE \{\$table\} \((.*?)\) \{\$charset\}/s', $source, $m);
        preg_match_all('/^\s{12}([a-z_]+) [A-Z]/m', $m[1], $columns);

        self::assertSame(
            NotificationQueue::expectedColumns(),
            $columns[1],
            'The DDL and the verification list have drifted apart'
        );
    }

    /**
     * Deduplication IS the submission_recipient index. A partial dbDelta that
     * created the columns but skipped it would otherwise be stamped complete,
     * never retried, and would start sending duplicate emails silently.
     */
    public function testTheUniquenessIndexIsVerifiedBeforeStampingTheVersion(): void
    {
        self::assertContains('submission_recipient', NotificationQueue::expectedIndexes());
        self::assertContains('status_due', NotificationQueue::expectedIndexes());

        $source = $this->source('src/Notifications/NotificationQueue.php');

        self::assertStringContainsString('UNIQUE KEY submission_recipient (submission_id,recipient_key)', $source);
        self::assertStringContainsString('tableHasIndex', $source);
    }

    /**
     * The webhook queue stores submission_row; this one deliberately does not,
     * because every operation resolves by submission_id (TRUNCATE reuses
     * AUTO_INCREMENT). A stored numeric id nothing may use is a trap.
     */
    public function testTheQueueResolvesSubmissionsByIdNotByRowId(): void
    {
        $source = $this->source('src/Notifications/NotificationQueue.php');

        self::assertStringContainsString('getBySubmissionId', $source);
        self::assertStringNotContainsString('FormSubmissions::get(', $source);
        self::assertNotContains('submission_row', NotificationQueue::expectedColumns());
    }

    public function testRecipientKeyIsStableAndCaseInsensitive(): void
    {
        self::assertSame(
            NotificationQueue::recipientKey('Sales@Example.com'),
            NotificationQueue::recipientKey('  sales@example.com  ')
        );
        self::assertNotSame(
            NotificationQueue::recipientKey('a@example.com'),
            NotificationQueue::recipientKey('b@example.com')
        );
    }

    // ── Retry / TTL policy ───────────────────────────────────────────────────

    /**
     * The divergence from the webhook schedule is deliberate: a lead
     * notification arriving 16 hours late is worse than useless, and email has
     * no receiver idempotency, so every extra attempt risks a duplicate.
     */
    public function testTheRetryChainIsShorterThanTheWebhookChain(): void
    {
        self::assertLessThan(
            array_sum(AnalyticsDispatcher::retryDelays()),
            array_sum(NotificationQueue::retryDelays())
        );
    }

    public function testEachRetryDelayHasAFloor(): void
    {
        Monkey\tearDown();
        Monkey\setUp();
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value) => $hook === 'convermetry_notification_retry_schedule'
                ? [1, 2, 'junk', -5]
                : $value
        );

        foreach (NotificationQueue::retryDelays() as $delay) {
            self::assertGreaterThanOrEqual(60, $delay);
        }
    }

    public function testAnEmptyFilterResultFallsBackToTheDefaults(): void
    {
        Monkey\tearDown();
        Monkey\setUp();
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value) => $hook === 'convermetry_notification_retry_schedule' ? [] : $value
        );

        self::assertNotSame([], NotificationQueue::retryDelays());
    }

    /**
     * The TTL is a separate guarantee from the retry chain: it bounds a row
     * that was never ATTEMPTED (cron disabled, plugin deactivated), not one
     * that is failing. It must still leave room for the full chain.
     */
    public function testTheTimeToLiveExceedsTheFullRetryChain(): void
    {
        self::assertSame(2 * HOUR_IN_SECONDS, NotificationQueue::maxAge());
        self::assertLessThan(
            NotificationQueue::maxAge(),
            array_sum(NotificationQueue::retryDelays()),
            'The TTL must not abandon rows the retry chain would still legitimately retry'
        );
    }

    /** The age check must precede the submission lookup and any send. */
    public function testTheAgeCheckHappensBeforeAnythingElseInProcessRow(): void
    {
        $source = $this->source('src/Notifications/NotificationQueue.php');

        $agePos  = strpos($source, 'time() - self::MAX_AGE');
        $sendPos = strpos($source, 'NotificationMailer::send');

        self::assertNotFalse($agePos);
        self::assertNotFalse($sendPos);
        self::assertLessThan($sendPos, $agePos);
    }

    // ── Behavioral wiring ────────────────────────────────────────────────────

    public function testTheWorkerHookIsRegistered(): void
    {
        NotificationQueue::init();

        self::assertArrayHasKey(NotificationQueue::WORKER_HOOK, $this->hooks);
        self::assertSame(
            [NotificationQueue::class, 'processDue'],
            $this->hooks[NotificationQueue::WORKER_HOOK][0]['callback']
        );
    }

    /**
     * The hook fires before SubmissionService checks for webhook endpoints,
     * which is exactly why notifications work with none configured.
     */
    public function testTheDispatcherListensOnTheSubmissionActionWithThreeArguments(): void
    {
        \Convermetry\Notifications\NotificationDispatcher::init();

        $registered = $this->hooks['convermetry_submission_recorded'][0];

        self::assertSame(
            [\Convermetry\Notifications\NotificationDispatcher::class, 'onSubmissionRecorded'],
            $registered['callback']
        );
        self::assertSame(3, $registered['args']);
    }

    public function testTheSubmissionActionFiresBeforeTheWebhookEndpointCheck(): void
    {
        $source = $this->source('src/Forms/SubmissionService.php');

        $actionPos  = strpos($source, "do_action('convermetry_submission_recorded'");
        $webhookPos = strpos($source, 'Options::webhooksActive()');

        self::assertNotFalse($actionPos);
        self::assertNotFalse($webhookPos);
        self::assertLessThan(
            $webhookPos,
            $actionPos,
            'Notifications must be reachable on a site with no webhook endpoints'
        );
    }

    // ── Source-contract wiring ───────────────────────────────────────────────

    public function testActivationCreatesTheTableAndArmsTheWorker(): void
    {
        $source = $this->source('convermetry.php');

        self::assertStringContainsString('NotificationQueue::createTable()', $source);
        self::assertStringContainsString('NotificationQueue::ensureWorkerScheduled()', $source);
    }

    public function testDeactivationClearsTheWorkerHookAndPreservesRows(): void
    {
        $source = $this->source('convermetry.php');

        self::assertStringContainsString(
            'wp_clear_scheduled_hook(Convermetry\Notifications\NotificationQueue::WORKER_HOOK)',
            $source
        );
        self::assertStringNotContainsString('NotificationQueue::cancelAll()', $source);
    }

    /**
     * uninstall.php hardcodes cron hook names as strings, so a rename of the
     * constant would silently orphan the scheduled event. Assert the VALUE.
     */
    public function testUninstallRemovesTheTableOptionsAndCronHook(): void
    {
        $source = $this->source('uninstall.php');

        self::assertStringContainsString('cvm_notification_queue', $source);
        self::assertStringContainsString("delete_option('cvm_notification_settings')", $source);
        self::assertStringContainsString("delete_option('cvm_notification_db_version')", $source);
        self::assertStringContainsString(NotificationQueue::WORKER_HOOK, $source);
    }

    public function testUninstallHandlesMultisite(): void
    {
        $source = $this->source('uninstall.php');

        self::assertStringContainsString('is_multisite()', $source);
        self::assertStringContainsString('switch_to_blog', $source);
    }

    public function testDeletingASubmissionCancelsItsQueuedNotifications(): void
    {
        $source = $this->source('src/Database/FormSubmissions.php');

        self::assertStringContainsString('NotificationQueue::cancelForSubmission($submissionId)', $source);
    }

    public function testClearingAllSubmissionsDrainsTheNotificationQueue(): void
    {
        $source = $this->source('src/Database/FormSubmissions.php');

        self::assertStringContainsString('NotificationQueue::cancelAll()', $source);
    }

    public function testTheOrphanSweepIsOnTheDailyCleanupCron(): void
    {
        $source = $this->source('src/Plugin.php');

        self::assertStringContainsString(
            "add_action('cvm_cleanup_old_events', [NotificationQueue::class, 'purgeOrphans'])",
            $source
        );
        self::assertStringContainsString(
            "add_action('cvm_cleanup_old_events', [NotificationQueue::class, 'ensureWorkerScheduled'])",
            $source
        );
    }

    /**
     * The guarantee is unchanged — the queue's schema is upgraded on load, not
     * only on activation, so a plugin update never leaves it stale. What changed
     * in 0.5.0 is WHERE that happens: Plugin::init() used to call each table
     * owner's maybeUpgrade() directly on plugins_loaded, i.e. inside every
     * anonymous frontend request. MigrationRunner now owns that decision.
     *
     * So this asserts the same property through the new mechanism, in two
     * halves: the runner is booted on load, and this queue is one of the owners
     * it migrates. Asserting only the first would let someone drop
     * NotificationQueue from the owner list and still pass.
     */
    public function testTheSchemaIsUpgradedOnLoad(): void
    {
        self::assertStringContainsString('MigrationRunner::boot()', $this->source('src/Plugin.php'));

        self::assertContains(
            \Convermetry\Notifications\NotificationQueue::class,
            \Convermetry\Database\MigrationRunner::owners(),
            'NotificationQueue is not in the migration runner\'s owner list, so its schema '
            . 'would never be upgraded on load.'
        );
    }

    /**
     * Schema DDL must never run in a visitor's page load.
     *
     * The 0.5.0 migrations add indexes to the events table, which is a locking
     * rebuild on every engine. If maybeUpgrade() calls ever migrate back into
     * Plugin::init(), the first anonymous visitor after a plugin update wears
     * that cost and every concurrent request queues behind them.
     */
    public function testTableOwnersAreNotUpgradedDirectlyFromTheCompositionRoot(): void
    {
        $source = $this->source('src/Plugin.php');

        foreach (['NotificationQueue', 'DatabaseManager', 'FormSubmissions', 'FormDeliveryQueue'] as $owner) {
            self::assertStringNotContainsString(
                $owner . '::maybeUpgrade()',
                $source,
                "{$owner}::maybeUpgrade() is called directly from Plugin::init(), which runs on "
                . 'every frontend request. Schema DDL belongs behind MigrationRunner.'
            );
        }
    }

    /**
     * Submenu position follows registration order, so this is the one bit of
     * ordering that is genuinely load-bearing.
     */
    public function testTheNotificationsMenuRegistersBetweenFormsAndWebhooks(): void
    {
        $source = $this->source('src/Plugin.php');

        $forms    = strpos($source, 'FormsPage::init($this->formRegistry)');
        $notify   = strpos($source, 'NotificationsPage::init($this->formRegistry)');
        $webhooks = strpos($source, 'WebhooksPage::init()');

        self::assertNotFalse($forms);
        self::assertNotFalse($notify);
        self::assertNotFalse($webhooks);
        self::assertLessThan($notify, $forms);
        self::assertLessThan($webhooks, $notify);
    }
}
