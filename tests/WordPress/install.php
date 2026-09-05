<?php
/**
 * Installs a fresh WordPress into the throwaway database and activates the
 * plugin, then exits.
 *
 * Runs as its OWN PROCESS, before PHPUnit boots. That is not incidental: a
 * process that installed WordPress has already run wp-settings.php with no
 * active plugins, so Convermetry would never have gone through the ordinary
 * plugins_loaded / init path. The suite's whole purpose is to exercise that
 * path, so the install happens here and the tests boot afterwards into a site
 * where the plugin is already active — exactly as a real request does.
 */

declare(strict_types=1);

require __DIR__ . '/wp-boot.php';

cvm_wp_boot(installing: true);

global $wpdb;

// A fresh database every run. Reusing one would let a schema left behind by an
// older build satisfy a migration this build is supposed to perform — which is
// precisely the failure this suite exists to catch.
foreach ($wpdb->get_col('SHOW TABLES') as $table) {
    $wpdb->query('DROP TABLE IF EXISTS `' . $table . '`');
}

require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$result = wp_install(
    'Convermetry E2E',
    'admin',
    'e2e@convermetry.test',
    true,
    '',
    wp_generate_password(24)
);

if (!is_array($result) || !is_blog_installed()) {
    fwrite(STDERR, "WordPress did not install.\n");
    exit(1);
}

// Installing leaves the flag set for the rest of this process; clearing it
// makes activation run under the same conditions a real admin request would.
wp_installing(false);

$plugin = 'convermetry/convermetry.php';

if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin)) {
    fwrite(STDERR, 'The plugin is not in ' . WP_PLUGIN_DIR . " — CVM_WP_DIR must point at a WordPress whose\n"
        . "wp-content/plugins contains a 'convermetry' directory (a symlink is fine).\n");
    exit(1);
}

$activated = activate_plugin($plugin);

if (is_wp_error($activated)) {
    fwrite(STDERR, 'Activation failed: ' . $activated->get_error_message() . "\n");
    exit(1);
}

if (!in_array($plugin, (array) get_option('active_plugins', []), true)) {
    fwrite(STDERR, "Activation reported success but the plugin is not active.\n");
    exit(1);
}

fwrite(STDOUT, 'WordPress ' . get_bloginfo('version') . " installed and Convermetry activated.\n");
