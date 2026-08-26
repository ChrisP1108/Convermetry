<?php
/**
 * PHPUnit bootstrap.
 *
 * These are unit tests: no WordPress install, no database. WordPress constants
 * and the handful of global functions the units under test touch are defined
 * here; per-test WP function behaviour is stubbed with Brain Monkey.
 *
 * What this suite deliberately cannot cover — and what therefore stays on the
 * manual/live checklist — is anything that needs a real WordPress runtime:
 * dbDelta() migrations, provider hook lifecycles inside the real form plugins,
 * WP-Cron scheduling, REST authentication, and end-to-end delivery.
 *
 * For the notification subsystem specifically, that checklist is:
 *
 *  - the cvm_notification_queue migration, including whether dbDelta actually
 *    created the UNIQUE submission_recipient index (deduplication depends on
 *    it, and its absence would be silent);
 *  - the cron worker firing, claiming rows, and re-arming;
 *  - INSERT IGNORE idempotency under a real unique index;
 *  - the delete-submission and clear-all cascades, and retention interaction;
 *  - activation, deactivation, and uninstall (including multisite);
 *  - whether an email actually arrives, which no code can assert —
 *    wp_mail() returning true means the local transport accepted the message.
 *
 * NotificationLifecycleTest covers the wiring for these behaviorally where a
 * callback is observable and by source-contract assertions where it is not; it
 * makes no claim about the SQL itself. There is intentionally no hand-rolled
 * $wpdb mock anywhere in this suite: a mock would only prove the test author's
 * model of MySQL, and a green "delete cascade" built on one would make an
 * unverified erasure guarantee look verified.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

if (!defined('WEEK_IN_SECONDS')) {
    define('WEEK_IN_SECONDS', 604800);
}

if (!defined('CVM_VERSION')) {
    // Read from the plugin file rather than hardcoding it. This stub had already
    // drifted once — it said 0.1.0 while the plugin was 0.2.0 — and a test that
    // asserts on the version is worthless if the stub is the thing that is wrong.
    preg_match(
        "/define\('CVM_VERSION',\s*'([^']+)'\)/",
        (string) file_get_contents(__DIR__ . '/../convermetry.php'),
        $cvmVersionMatch
    );

    if (!isset($cvmVersionMatch[1])) {
        fwrite(STDERR, "bootstrap: could not read CVM_VERSION from convermetry.php\n");
        exit(1);
    }

    define('CVM_VERSION', $cvmVersionMatch[1]);
    unset($cvmVersionMatch);
}
