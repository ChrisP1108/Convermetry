<?php
/**
 * PHPUnit bootstrap for the WORDPRESS end-to-end suite.
 *
 * The gap this closes, named in the unit suite's own bootstrap as what it
 * "deliberately cannot cover": dbDelta migrations, REST registration, WP-Cron,
 * activation, and end-to-end delivery. The unit suite has no WordPress and the
 * integration suite has no WordPress either — it executes the plugin's SQL
 * directly against MySQL. Everything between "the SQL is right" and "the plugin
 * works when WordPress runs it" was untested.
 *
 * WHAT THIS SUITE PROVES
 *
 *  - Activation runs the migrations, and dbDelta — not a hand-executed CREATE
 *    TABLE — produces every table the plugin needs.
 *  - The REST routes register on a real rest_api_init and answer a real
 *    WP_REST_Request.
 *  - A recorded submission produces queue rows.
 *  - The WP-Cron worker, fired as cron fires it, delivers over real HTTP to a
 *    real receiver, with the headers and signature it claims.
 *  - The submission's recorded delivery state ends up 'delivered'.
 *  - Uninstall removes every table and option.
 *
 * WHAT IT STILL DOES NOT PROVE. There are no third-party form plugins here, so
 * provider hook lifecycles stay on the manual checklist; submissions are
 * recorded through the plugin's own public API, which is the same entry point
 * every provider adapter calls.
 *
 * CONFIGURATION. The suite SKIPS cleanly when CVM_WP_DIR is unset, so
 * `composer test` on a laptop is unaffected:
 *
 *     CVM_WP_DIR       path to a WordPress core whose wp-content/plugins holds
 *                      this plugin in a directory named 'convermetry'
 *     CVM_WP_DB_NAME   default cvm_wp_test
 *     CVM_WP_DB_HOST   default 127.0.0.1
 *     CVM_WP_DB_USER   default root
 *     CVM_WP_DB_PASS   default ''
 *     CVM_WP_PORT      default 8731, the receiver's port
 *
 * THE DATABASE IS DROPPED AND REINSTALLED ON EVERY RUN. Point it at a scratch
 * database, never at a real site's.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

if ((string) getenv('CVM_WP_DIR') === '') {
    // Nothing is booted; WordPressTestCase skips every test with an explanation.
    define('CVM_WP_E2E_READY', false);

    return;
}

// The install runs as its own process on purpose: a process that installed
// WordPress has already passed plugins_loaded with no plugins active, so the
// plugin would never go through the ordinary load path this suite exists to
// exercise. Installing first and booting after means the tests run against a
// site where Convermetry is already active — exactly as a real request does.
$install = [];
$status  = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/install.php') . ' 2>&1', $install, $status);

if ($status !== 0) {
    fwrite(STDERR, implode("\n", $install) . "\n");
    exit(1);
}

require __DIR__ . '/wp-boot.php';

cvm_wp_boot();

define('CVM_WP_E2E_READY', true);
