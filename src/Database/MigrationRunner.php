<?php
declare(strict_types=1);

namespace Convermetry\Database;

if (!defined('ABSPATH')) exit;

use Convermetry\Goals\GoalCompletions;
use Convermetry\Leads\LeadEvents;
use Convermetry\Notifications\NotificationQueue;
use Convermetry\Webhook\DeliveryLog;
use Convermetry\Webhook\FormDeliveryQueue;

/**
 * Decides WHERE and WHEN schema migrations are allowed to run.
 *
 * Every table owner already knows how to migrate itself idempotently via
 * dbDelta. What none of them knew is that they were being asked to do it in the
 * wrong request. Each owner's maybeUpgrade() used to be called directly from
 * {@see \Convermetry\Plugin::init()}, which runs on plugins_loaded — on EVERY
 * request, including anonymous frontend page loads and the public tracking
 * endpoint. That was harmless while migrations only ever added a column to an
 * empty-ish table. It stopped being harmless in 0.5.0:
 *
 *  - ADD COLUMN is only an INSTANT metadata change on MySQL 8.0.12+. On MySQL
 *    5.7 and on MariaDB it is a full table rebuild.
 *  - ADD INDEX is a rebuild EVERYWHERE, and 0.5.0 adds two of them to the
 *    events table — the largest table the plugin owns, routinely hundreds of
 *    thousands of rows.
 *
 * On a large site that is a multi-second, table-locking operation. Whichever
 * unlucky visitor loaded a page first would have worn it, and every concurrent
 * request would have piled up behind it.
 *
 * So: heavy migrations run only in a request that can afford them — WP-Cron,
 * WP-CLI, or an actual admin page view — and only one at a time, under a
 * lease-based lock. A frontend request that notices a pending migration
 * schedules it and gets out of the way, touching no DDL at all.
 *
 * THE LOCK IS AN OPTION-ROW LEASE, NEVER MySQL's GET_LOCK(), for the same
 * reason {@see DatabaseManager::acquireCleanupLock()} avoids it: named locks
 * belong to a database CONNECTION, and $wpdb transparently reconnects after a
 * "server has gone away", which would silently drop the lock mid-migration with
 * no way to detect it.
 *
 * While a migration is pending, {@see \Convermetry\Settings\Options} reports the
 * dependent features as unavailable, so admin pages say "preparing" instead of
 * querying a column that does not exist yet. Nothing half-renders, and nothing
 * fatals against a partially-migrated schema.
 */
final class MigrationRunner
{
    /** Cron hook that runs pending migrations away from a visitor's request. */
    public const string CRON_HOOK = 'cvm_run_migrations';

    /** Option key holding the migration lease. */
    private const string LOCK_OPTION = 'cvm_migration_lock';

    /**
     * Seconds before a held lease is considered abandoned and may be stolen.
     *
     * Deliberately generous. This bounds a TABLE REBUILD on a table that may
     * hold millions of rows, not a bounded delete loop — stealing the lease from
     * a migration that is genuinely still running would start a second
     * concurrent ALTER on the same table, which is far worse than waiting.
     */
    private const int LOCK_TIMEOUT = 15 * MINUTE_IN_SECONDS;

    /** Seconds to wait before the scheduled catch-up attempt. */
    private const int SCHEDULE_DELAY = 30;

    /**
     * Registers the cron handler.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action(self::CRON_HOOK, [self::class, 'runNow']);
    }

    /**
     * The table owners this runner is responsible for, in dependency order.
     *
     * Order matters for one reason only: {@see FormSubmissions} reads the
     * delivery log and the delivery queue while backfilling, so those tables
     * should exist first on a fresh install.
     *
     * @return array<int, class-string> Owner classes exposing needsUpgrade()/maybeUpgrade().
     */
    public static function owners(): array
    {
        return [
            DatabaseManager::class,
            DeliveryLog::class,
            FormDeliveryQueue::class,
            NotificationQueue::class,
            FormSubmissions::class,
            GoalCompletions::class,
            LeadEvents::class,
        ];
    }

    /**
     * Whether any owned table's recorded schema version differs from the
     * version this build of the plugin ships.
     *
     * Cheap: one autoloaded option read per owner, and on the overwhelmingly
     * common "nothing to do" path it is the entire cost of this class.
     *
     * @return bool
     */
    public static function isPending(): bool
    {
        foreach (self::owners() as $owner) {
            if ($owner::needsUpgrade()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Called once per request from the composition root.
     *
     * Runs pending migrations when the current request can afford the DDL;
     * otherwise schedules them and returns without touching the schema.
     *
     * @return void
     */
    public static function boot(): void
    {
        if (!self::isPending()) {
            return;
        }

        if (self::isSafeContext()) {
            self::runNow();
            return;
        }

        self::schedule();
    }

    /**
     * Whether the CURRENT request is one that may run schema DDL.
     *
     * Cron and WP-CLI are the ideal homes for it. A genuine admin page view is
     * also acceptable — an administrator waiting a few seconds after a plugin
     * update is a reasonable trade for the migration completing promptly, and
     * they get the feature-gating notice while it happens.
     *
     * Explicitly excluded:
     *  - admin-ajax and REST requests. Both report is_admin() inconsistently or
     *    not at all, both are latency-sensitive, and the public tracking
     *    endpoint is a REST route hit by every visitor on the site.
     *  - every frontend page load.
     *
     * @return bool
     */
    private static function isSafeContext(): bool
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        if (wp_doing_cron()) {
            return true;
        }

        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }

        return is_admin() && !wp_doing_ajax();
    }

    /**
     * Schedules a one-off catch-up run, unless one is already queued.
     *
     * @return void
     */
    private static function schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + self::SCHEDULE_DELAY, self::CRON_HOOK);
        }
    }

    /**
     * Runs every pending migration under the lease, then releases it.
     *
     * Public because it is the cron callback, and because activation and WP-CLI
     * may legitimately drive it directly.
     *
     * A failure to acquire the lease is NOT an error: it means another request
     * is already migrating. This one re-schedules so the features un-gate
     * promptly once that finishes, rather than waiting for incidental traffic.
     *
     * @return void
     */
    public static function runNow(): void
    {
        $lock = self::acquireLock();

        if ($lock === null) {
            self::schedule();
            return;
        }

        /**
         * Fires when a migration pass begins, with the lease held.
         *
         * Fires once per pass, on whichever request won the lease — an admin
         * page load, a cron run, or WP-CLI. A pass runs only when at least one
         * table owner reports a version mismatch, so this is not a per-request
         * event.
         *
         * Observational only: a listener cannot skip, reorder, or cancel a
         * migration. Returning a value does nothing, and throwing aborts the
         * pass with the lease still held until it expires — do not throw.
         *
         * No SQL is passed here or to any migration action. Convermetry's schema
         * changes run through dbDelta and the statements are an implementation
         * detail, not an integration surface.
         *
         * @param string $context Where the pass is running: 'cli', 'cron', or 'admin'.
         */
        do_action('convermetry_migration_started', self::context());

        $failure = null;

        try {
            foreach (self::owners() as $owner) {
                if ($owner::needsUpgrade()) {
                    $owner::maybeUpgrade();
                }
            }
        } catch (\Throwable $e) {
            // Captured rather than caught-and-swallowed: the lease still has to
            // be released by the finally below, and the failure still has to
            // reach the caller — but a listener has to be told BEFORE it does,
            // or the action would be unreachable on the throwing path.
            $failure = $e;
        } finally {
            self::releaseLock($lock);
        }

        if ($failure !== null) {
            /**
             * Fires when a migration pass throws.
             *
             * Fires AFTER the migration lease is released and BEFORE the
             * exception continues to the caller, so a listener neither blocks
             * the next pass nor changes the fact that this one failed.
             *
             * A migration that merely did not land is NOT a failure and does not
             * fire this: an owner leaves its version unstamped by design when a
             * dbDelta is partial, and the next pass simply asks it again. Only a
             * thrown error reaches here.
             *
             * $error is the exception's class name, never its message: a
             * database error message quotes the failing statement verbatim.
             *
             * @param string $context Where the pass was running: 'cli', 'cron', or 'admin'.
             * @param string $error   The exception class name.
             */
            do_action('convermetry_migration_failed', self::context(), $failure::class);

            throw $failure;
        }

        // A migration that did not land (a partial dbDelta, an interrupted
        // rebuild) leaves its version unstamped by design, so the owner will be
        // asked again. Re-arm rather than waiting for the next admin visit.
        $pending = self::isPending();

        if ($pending) {
            self::schedule();
        }

        /**
         * Fires when a migration pass finishes.
         *
         * Fires after the lease is released AND after the still-pending check
         * and any rescheduling have been decided — so $pending is the settled
         * answer, not a guess made mid-pass.
         *
         * $pending true means at least one owner's schema version is still
         * unstamped and another pass has been scheduled. That is normal for a
         * large table mid-migration and is not an error; it becomes one only if
         * it never becomes false.
         *
         * @param string $context Where the pass ran: 'cli', 'cron', or 'admin'.
         * @param bool   $pending Whether migrations remain outstanding.
         */
        do_action('convermetry_migration_completed', self::context(), $pending);
    }

    /**
     * A safe label for where the current migration pass is running.
     *
     * @return string 'cli', 'cron', or 'admin'.
     */
    private static function context(): string
    {
        if (defined('WP_CLI') && WP_CLI) {
            return 'cli';
        }

        return wp_doing_cron() ? 'cron' : 'admin';
    }

    /**
     * Acquires the migration lease without blocking.
     *
     * Created via INSERT IGNORE, so the options table's unique key on
     * option_name settles the race — exactly one concurrent caller wins, with no
     * read-then-write gap. A lease older than {@see LOCK_TIMEOUT} may be stolen,
     * but only by compare-and-delete on the exact stale value, so two would-be
     * stealers can never both believe they freed it.
     *
     * @return string|null The acquired token, or null when another process holds it.
     */
    private static function acquireLock(): ?string
    {
        global $wpdb;

        $token = md5(wp_generate_uuid4() . wp_rand());
        $value = $token . '|' . time();

        if (self::insertLockRow($value)) {
            return $token;
        }

        $held = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            self::LOCK_OPTION
        ));

        if ($held === '') {
            return null;
        }

        $parts  = explode('|', $held, 2);
        $heldTs = (int) ($parts[1] ?? 0);

        if (time() - $heldTs < self::LOCK_TIMEOUT) {
            return null;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
            self::LOCK_OPTION,
            $held
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');

        return self::insertLockRow($value) ? $token : null;
    }

    /**
     * Atomically creates the lease row.
     *
     * @param string $value Lock value ("token|timestamp").
     * @return bool True when this call created the row.
     *
     * @phpstan-impure Each call attempts a real INSERT IGNORE, and its result
     *                 depends on whether the row exists AT THAT MOMENT — the
     *                 caller retries this after deleting a stale lock row, so
     *                 two calls with identical arguments legitimately differ.
     */
    private static function insertLockRow(string $value): bool
    {
        global $wpdb;

        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
            self::LOCK_OPTION,
            $value
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');

        return $inserted === 1;
    }

    /**
     * Releases the lease, by compare-and-delete on the ownership token — if this
     * run's lease lapsed and another process stole it, the DELETE matches
     * nothing and the new holder keeps its mutex.
     *
     * @param string $lock The token acquireLock() returned.
     * @return void
     */
    private static function releaseLock(string $lock): void
    {
        global $wpdb;

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s",
            self::LOCK_OPTION,
            $wpdb->esc_like($lock) . '|%'
        ));
        wp_cache_delete(self::LOCK_OPTION, 'options');
    }
}
