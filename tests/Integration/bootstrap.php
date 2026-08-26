<?php
/**
 * PHPUnit bootstrap for the INTEGRATION suite.
 *
 * These tests run the plugin's real queries against a real MySQL server. They
 * exist because the unit suite provably cannot cover the thing that actually
 * broke: a query can be structurally correct, pass every assertion about its
 * shape, and still be wrong once a database executes it.
 *
 * The funnel report is the worked example. Its statement is assembled
 * outside-in, so the LAST step's placeholders appear FIRST in the finished SQL
 * while step 0's appear last. Parameters were appended in step order, so every
 * one bound to the wrong placeholder — and nothing errored, because they are all
 * %s. The query ran, compared a page URL against a timestamp, matched nothing,
 * and reported every funnel as zero. A unit test asserting the SQL's structure
 * stayed green throughout.
 *
 * WHAT THIS SUITE PROVES
 *
 *  - The schema DDL, executed by a real server, produces exactly the columns and
 *    indexes each table owner verifies before stamping its version.
 *  - The UNIQUE constraints that carry correctness guarantees genuinely
 *    deduplicate under INSERT IGNORE.
 *  - The generated funnel SQL parses, executes, and returns the right sessions —
 *    including the ordering case that motivated the whole design.
 *  - The abandonment, engagement, and lead-report queries return the numbers
 *    their unit-tested arithmetic expects to be fed.
 *  - The backfill sentinel selects rows an already-upgraded install would leave
 *    behind.
 *
 * WHAT IT DOES NOT PROVE. There is no WordPress here — no dbDelta, no cron, no
 * REST layer, no provider hooks. The DDL is executed directly rather than
 * through dbDelta, so this covers "does this schema do what we claim" and not
 * "does dbDelta apply it to an existing table correctly". That second question
 * needs the wordpress-develop harness and stays on the manual checklist.
 *
 * CONNECTION. Configure with environment variables; the whole suite skips
 * cleanly when none is reachable, so `composer test` on a laptop is unaffected:
 *
 *     CVM_TEST_DB_HOST    default 127.0.0.1
 *     CVM_TEST_DB_PORT    default 3306
 *     CVM_TEST_DB_SOCKET  optional, overrides host/port
 *     CVM_TEST_DB_NAME    default cvm_test
 *     CVM_TEST_DB_USER    default root
 *     CVM_TEST_DB_PASS    default ''
 *
 * THE DATABASE IS TRUNCATED BETWEEN TESTS. Point these at a throwaway database,
 * never at a real site's.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

foreach ([
    'MINUTE_IN_SECONDS' => 60,
    'HOUR_IN_SECONDS'   => 3600,
    'DAY_IN_SECONDS'    => 86400,
    'WEEK_IN_SECONDS'   => 604800,
] as $constant => $value) {
    if (!defined($constant)) {
        define($constant, $value);
    }
}

if (!defined('CVM_VERSION')) {
    preg_match(
        "/define\('CVM_VERSION',\s*'([^']+)'\)/",
        (string) file_get_contents(__DIR__ . '/../../convermetry.php'),
        $match
    );

    define('CVM_VERSION', $match[1] ?? '0.0.0');
}

require_once __DIR__ . '/../../src/Autoloader.php';
Convermetry\Autoloader::boot(dirname(__DIR__, 2) . '/');

require_once __DIR__ . '/TestWpdb.php';

/*
 * The WordPress functions the query layer under test touches. Everything here
 * is either a pure string helper or a value the queries do not depend on — a
 * deliberately small surface, because a large one would mean this suite was
 * testing the stubs rather than the SQL.
 */

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $key));
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value): string
    {
        return trim(strip_tags((string) $value));
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook, $value, ...$args)
    {
        return $value;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook, ...$args): void
    {
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return $GLOBALS['cvm_test_options'][$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, $value, $autoload = null): bool
    {
        $GLOBALS['cvm_test_options'][$key] = $value;

        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key)
    {
        return $GLOBALS['cvm_test_transients'][$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, $value, int $ttl = 0): bool
    {
        $GLOBALS['cvm_test_transients'][$key] = $value;

        return true;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0xffff)
        );
    }
}

if (!function_exists('wp_rand')) {
    function wp_rand(int $min = 0, int $max = PHP_INT_MAX): int
    {
        return random_int($min, min($max, PHP_INT_MAX));
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512)
    {
        return json_encode($data, $options, $depth);
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.com' . $path;
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = ''): string
    {
        return 'https://example.com' . $path;
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }
}

$GLOBALS['cvm_test_options']    = [];
$GLOBALS['cvm_test_transients'] = [];
