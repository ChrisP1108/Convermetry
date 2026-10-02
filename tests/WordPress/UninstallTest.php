<?php
declare(strict_types=1);

namespace Convermetry\Tests\WordPress;

/**
 * "Removed permanently" has to mean it.
 *
 * uninstall.php is the one file that can never be exercised by the unit or
 * integration suites: it runs only when WordPress deletes a plugin, it expects
 * WP_UNINSTALL_PLUGIN to be defined, and what it must remove are real tables
 * and real option rows. Reading it is not evidence — a DROP TABLE naming a
 * table that was renamed, or a LIKE pattern that misses a prefix, looks correct
 * and leaves data behind.
 *
 * The site is REINSTALLED afterwards rather than left uninstalled, so this test
 * is safe to run in any order relative to the rest of the suite.
 */
final class UninstallTest extends WordPressTestCase
{
    protected function tearDown(): void
    {
        // Puts the site back for whatever runs next, regardless of order.
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/install.php') . ' 2>&1',
            $output,
            $status
        );

        self::assertSame(0, $status, 'The site must be reinstallable: ' . implode("\n", $output));

        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    private function pluginTables(): array
    {
        global $wpdb;

        $found = $wpdb->get_col(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . 'cvmtry_') . '%')
        );

        return is_array($found) ? array_values(array_map('strval', $found)) : [];
    }

    public function testUninstallRemovesEveryTableOptionAndCronEvent(): void
    {
        global $wpdb;

        // State the uninstaller has to find and remove, including the
        // per-submission repair records, which are direct option rows rather
        // than anything the options API would enumerate.
        update_option('cvmtry_settings', ['probe' => true]);
        update_option('cvmtry_webhook_settings', ['probe' => true]);
        update_option('cvmtry_webhook_state_version', 2);
        set_transient('cvmtry_privacy_erase_' . md5('probe'), ['cursor' => 1], HOUR_IN_SECONDS);
        $wpdb->query(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)"
            . " VALUES ('cvmtry_queue_repair_probe1', '{\"at\":1,\"refs\":[\"e\"]}', 'off')"
        );
        $wpdb->query(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)"
            . " VALUES ('cvmtry_rl_probe1', '1|1', 'off')"
        );

        self::assertNotSame([], $this->pluginTables(), 'The tables must exist before uninstall');

        // Exactly how WordPress runs it: a separate request, with the constant
        // defined, after the plugin has been deactivated.
        $script = escapeshellarg(__DIR__ . '/run-uninstall.php');
        exec(escapeshellarg(PHP_BINARY) . ' ' . $script . ' 2>&1', $output, $status);

        self::assertSame(0, $status, 'The uninstaller must run cleanly: ' . implode("\n", $output));

        self::assertSame([], $this->pluginTables(), 'Every plugin table must be dropped');

        foreach (['cvmtry_settings', 'cvmtry_webhook_settings'] as $option) {
            self::assertSame(
                null,
                $wpdb->get_var($wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name = %s",
                    $option
                )),
                $option . ' survived uninstall'
            );
        }

        self::assertSame(
            '0',
            $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'cvmtry\\_queue\\_repair\\_%'"
            ),
            'Queue-repair records survived uninstall'
        );

        self::assertSame(
            '0',
            $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'cvmtry\\_rl\\_%'"),
            'Rate-limit counters survived uninstall'
        );

        // The promise is "no trace remains", so the check is every option the
        // plugin can write — not a sample. cvmtry_webhook_state_version, written
        // by the analytics dispatcher's state migration, used to survive
        // because uninstall.php's list was written by hand and missed it.
        $leftover = $wpdb->get_col(
            "SELECT option_name FROM {$wpdb->options}"
            . " WHERE option_name LIKE 'cvmtry\\_%' OR option_name LIKE '\\_transient\\_cvmtry\\_%'"
            . " OR option_name LIKE '\\_transient\\_timeout\\_cvmtry\\_%'"
        );
        self::assertSame([], $leftover, 'Options survived uninstall: ' . implode(', ', (array) $leftover));

        // Read from the DATABASE, not through wp_next_scheduled(). The
        // uninstaller ran in its own process, so this one still holds the cron
        // array it cached at boot — and asserting against that would be
        // asserting on a stale copy rather than on what uninstall did.
        $cron = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'cron'");
        $hooks = [];

        foreach ((array) maybe_unserialize((string) $cron) as $events) {
            if (is_array($events)) {
                $hooks = array_merge($hooks, array_keys($events));
            }
        }

        foreach (['cvmtry_cleanup_old_events', 'cvmtry_dispatch_webhooks', 'cvmtry_process_form_queue'] as $hook) {
            self::assertNotContains($hook, $hooks, $hook . ' is still scheduled after uninstall');
        }
    }
}
