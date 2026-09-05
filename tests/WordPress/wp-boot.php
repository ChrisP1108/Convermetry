<?php
/**
 * Boots a real WordPress against a THROWAWAY database.
 *
 * Shared by the installer process and the PHPUnit bootstrap, so both agree
 * exactly on which core, which database and which server variables are in play.
 *
 * The database named here is dropped and recreated by the installer. Point it at
 * a scratch database, never at a real site's — CVM_WP_DB_NAME is deliberately
 * separate from the integration suite's CVM_TEST_DB_NAME so neither can be
 * mistaken for the other.
 *
 * @param bool $installing True to set WP_INSTALLING, which is what lets
 *                         wp-settings.php finish on a database that has no
 *                         WordPress tables yet.
 * @return void
 */

declare(strict_types=1);

function cvm_wp_boot(bool $installing = false): void
{
    $dir = (string) getenv('CVM_WP_DIR');

    if ($dir === '' || !is_file($dir . '/wp-settings.php')) {
        fwrite(STDERR, "CVM_WP_DIR does not point at a WordPress core (no wp-settings.php).\n");
        exit(1);
    }

    // WordPress reads these off $_SERVER while bootstrapping and warns without
    // them; a CLI process has neither.
    $_SERVER['HTTP_HOST']      = $_SERVER['HTTP_HOST'] ?? 'convermetry.test';
    $_SERVER['SERVER_NAME']    = $_SERVER['SERVER_NAME'] ?? 'convermetry.test';
    $_SERVER['REQUEST_URI']    = $_SERVER['REQUEST_URI'] ?? '/';
    $_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $_SERVER['SERVER_PROTOCOL'] = $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1';

    define('ABSPATH', $dir . '/');
    define('DB_NAME', (string) (getenv('CVM_WP_DB_NAME') ?: 'cvm_wp_test'));
    define('DB_USER', (string) (getenv('CVM_WP_DB_USER') ?: 'root'));
    define('DB_PASSWORD', (string) getenv('CVM_WP_DB_PASS'));
    define('DB_HOST', (string) (getenv('CVM_WP_DB_HOST') ?: '127.0.0.1'));
    define('DB_CHARSET', 'utf8mb4');
    define('DB_COLLATE', '');

    define('WP_DEBUG', true);
    define('WP_DEBUG_DISPLAY', false);
    define('WP_DEBUG_LOG', false);

    // Cron is fired explicitly by the tests. Left on, wp-settings.php would
    // spawn loopback requests to a host that does not answer, and the queue
    // worker's timing would stop being something a test controls.
    define('DISABLE_WP_CRON', true);

    if ($installing) {
        define('WP_INSTALLING', true);
    }

    $GLOBALS['table_prefix'] = 'wp_';

    require ABSPATH . 'wp-settings.php';
}
