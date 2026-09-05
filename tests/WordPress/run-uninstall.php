<?php
/**
 * Runs uninstall.php the way WordPress does, in its own request.
 *
 * WP_UNINSTALL_PLUGIN is what the file checks before it will delete anything,
 * and defining it inside a test process that continues afterwards would leave a
 * constant behind that nothing else expects. A separate process is both more
 * faithful and cleaner.
 */

declare(strict_types=1);

require __DIR__ . '/wp-boot.php';

cvm_wp_boot();

require_once ABSPATH . 'wp-admin/includes/plugin.php';

deactivate_plugins('convermetry/convermetry.php', true);

define('WP_UNINSTALL_PLUGIN', 'convermetry/convermetry.php');

require WP_PLUGIN_DIR . '/convermetry/uninstall.php';
