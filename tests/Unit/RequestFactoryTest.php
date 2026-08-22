<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\FormSettings;
use Convermetry\Settings\Options;
use Convermetry\Webhook\RequestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Query-parameter and header merge precedence.
 *
 * Four layers, each overriding the previous one for a shared key:
 * global → page → per-form → runtime.
 */
final class RequestFactoryTest extends TestCase
{
    private const FORM_KEY = 'elementor:a1b2c3d';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(static function (string $key, $default = false) {
            if ($key === Options::WEBHOOK_OPTION_KEY) {
                return [
                    'endpoints'           => [],
                    'global_query'        => [['key' => 'src', 'value' => 'global'], ['key' => 'g', 'value' => '1']],
                    'global_headers'      => [['key' => 'X-Tier', 'value' => 'global'], ['key' => 'X-G', 'value' => '1']],
                    'include_page_params' => false,
                ];
            }

            if ($key === FormSettings::OPTION_KEY) {
                return [self::FORM_KEY => [
                    'query_params'        => [['key' => 'src', 'value' => 'form']],
                    'headers'             => [['key' => 'X-Tier', 'value' => 'form']],
                    'include_page_params' => true,
                ]];
            }

            return $default;
        });

        Functions\when('add_query_arg')->alias(static function (array $args, string $url): string {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testHeaderPrecedenceRunsGlobalThenFormThenRuntime(): void
    {
        $headers = RequestFactory::buildHeaders(self::FORM_KEY, ['X-Tier' => 'runtime']);

        self::assertSame('runtime', $headers['X-Tier'], 'Runtime must win');
        self::assertSame('1', $headers['X-G'], 'Non-conflicting global headers survive');
        self::assertSame('application/json; charset=utf-8', $headers['Content-Type']);
    }

    public function testFormHeadersOverrideGlobalWhenNoRuntimeValue(): void
    {
        $headers = RequestFactory::buildHeaders(self::FORM_KEY);

        self::assertSame('form', $headers['X-Tier']);
    }

    public function testAnalyticsDeliveriesGetGlobalHeadersOnly(): void
    {
        $headers = RequestFactory::buildHeaders();

        self::assertSame('global', $headers['X-Tier']);
    }

    public function testQueryPrecedenceRunsGlobalThenPageThenFormThenRuntime(): void
    {
        $url = RequestFactory::buildUrl(
            'https://receiver.test/hook',
            self::FORM_KEY,
            ['src' => 'page', 'p' => 'page'],
            ['src' => 'runtime']
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('runtime', $query['src'], 'Runtime must win');
        self::assertSame('page', $query['p'], 'Page params pass through when enabled for the form');
        self::assertSame('1', $query['g'], 'Non-conflicting global params survive');
    }

    public function testPageParamsAreExcludedForAnalyticsDeliveries(): void
    {
        $url = RequestFactory::buildUrl('https://receiver.test/hook', '', ['p' => 'page']);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertArrayNotHasKey('p', $query);
        self::assertSame('global', $query['src']);
    }

    public function testEndpointUrlIsReturnedUnchangedWhenNothingToMerge(): void
    {
        Functions\when('get_option')->alias(static fn(string $key, $default = false) => $key === Options::WEBHOOK_OPTION_KEY
            ? ['endpoints' => [], 'global_query' => [], 'global_headers' => [], 'include_page_params' => false]
            : $default);

        self::assertSame('https://receiver.test/hook', RequestFactory::buildUrl('https://receiver.test/hook'));
    }
}
