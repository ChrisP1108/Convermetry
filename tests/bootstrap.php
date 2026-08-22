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

if (!defined('CVM_VERSION')) {
    define('CVM_VERSION', '0.1.0');
}
