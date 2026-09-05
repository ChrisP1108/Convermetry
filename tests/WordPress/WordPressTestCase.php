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

        if (!defined('CVM_WP_E2E_READY') || CVM_WP_E2E_READY !== true) {
            self::markTestSkipped(
                'No WordPress available. Set CVM_WP_DIR (and the CVM_WP_DB_* variables) to run the '
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

        foreach (['cvm_events', 'cvm_form_submissions', 'cvm_delivery_queue', 'cvm_webhook_deliveries'] as $table) {
            $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . $table);
        }
    }
}
