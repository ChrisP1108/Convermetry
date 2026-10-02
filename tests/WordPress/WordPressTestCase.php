<?php
declare(strict_types=1);

namespace Convermetry\Tests\WordPress;

use PHPUnit\Framework\TestCase;

/**
 * Base for the end-to-end suite: a booted WordPress with the plugin active.
 */
abstract class WordPressTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('CVMTRY_WP_E2E_READY') || CVMTRY_WP_E2E_READY !== true) {
            self::markTestSkipped(
                'No WordPress available. Set CVMTRY_WP_DIR (and the CVMTRY_WP_DB_* variables) to run the '
                . 'end-to-end suite; see tests/WordPress/bootstrap.php.'
            );
        }
    }

    /**
     * Empties the plugin's own tables between tests without touching the
     * WordPress install around them.
     *
     * @return void
     */
    protected function truncatePluginTables(): void
    {
        global $wpdb;

        foreach (['cvmtry_events', 'cvmtry_form_submissions', 'cvmtry_delivery_queue', 'cvmtry_webhook_deliveries'] as $table) {
            $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . $table);
        }
    }
}
