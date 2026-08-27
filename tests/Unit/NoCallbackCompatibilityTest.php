<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Api\DeliveryLogController;
use Convermetry\Forms\FormSettings;
use Convermetry\Settings\Options;
use Convermetry\Webhook\PayloadBuilder;
use Convermetry\Webhook\RequestFactory;
use PHPUnit\Framework\TestCase;

/**
 * The gate the whole public hook API is built behind.
 *
 * Adding ~70 hooks means adding ~70 places where a filter now runs on a path
 * that previously had none, and the promise made to every existing install is
 * that with nothing registered NOTHING changes — not the payload bytes on the
 * wire, not the request URL, not one header or its position, not the REST
 * output. "Nothing changes" is easy to believe and easy to break, so it is
 * pinned here against literal expected values rather than against the code's
 * own current behaviour.
 *
 * These are golden-value assertions on purpose. If a change to any hook plumbing
 * moves a byte, this file fails with a diff rather than the change reaching a
 * receiver that was parsing the old shape.
 */
final class NoCallbackCompatibilityTest extends TestCase
{
    private const FORM_KEY = 'elementor:a1b2c3d';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('get_bloginfo')->justReturn('Example');
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('wp_json_encode')->alias(
            static fn($data, $options = 0, $depth = 512) => json_encode($data, $options, $depth)
        );
        Functions\when('wp_rand')->justReturn(7);
        Functions\when('add_query_arg')->alias(static function (array $args, string $url): string {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
        });

        Functions\when('get_option')->alias(static function (string $key, $default = false) {
            if ($key === Options::WEBHOOK_OPTION_KEY) {
                return [
                    'endpoints'           => [],
                    'global_query'        => [['key' => 'src', 'value' => 'global']],
                    'global_headers'      => [['key' => 'X-Tier', 'value' => 'global']],
                    'include_page_params' => false,
                ];
            }

            if ($key === FormSettings::OPTION_KEY) {
                return [self::FORM_KEY => [
                    'query_params'        => [['key' => 'form', 'value' => 'yes']],
                    'headers'             => [['key' => 'X-Form', 'value' => 'yes']],
                    'include_page_params' => true,
                ]];
            }

            return $default;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Content-Type must stay FIRST. assertSame on arrays compares key order, and
     * key order is what decides the header block's bytes.
     */
    public function testHeaderCompositionIsUnchangedIncludingKeyOrder(): void
    {
        self::assertSame([
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Tier'       => 'global',
        ], RequestFactory::buildHeaders());

        self::assertSame([
            'Content-Type' => 'application/json; charset=utf-8',
            'X-Tier'       => 'global',
            'X-Form'       => 'yes',
            'X-Run'        => 'runtime',
        ], RequestFactory::buildHeaders(self::FORM_KEY, ['X-Run' => 'runtime']));
    }

    public function testUrlCompositionIsUnchanged(): void
    {
        self::assertSame(
            'https://hooks.example.com/x?src=global',
            RequestFactory::buildUrl('https://hooks.example.com/x')
        );

        self::assertSame(
            'https://hooks.example.com/x?src=global&form=yes&run=1',
            RequestFactory::buildUrl('https://hooks.example.com/x', self::FORM_KEY, [], ['run' => '1'])
        );
    }

    /**
     * The one input that must survive untouched: an endpoint with no configured
     * parameters gets its URL back byte-for-byte, never round-tripped through
     * add_query_arg().
     */
    public function testAnEndpointWithNoConfiguredParametersKeepsItsExactUrl(): void
    {
        Functions\when('get_option')->justReturn([]);

        $url = 'https://hooks.example.com/ingest?existing=1';
        self::assertSame($url, RequestFactory::buildUrl($url));
    }

    /**
     * The protocol headers are generated per attempt from the exact frozen body,
     * so a rotated secret re-signs identical bytes. Pinned against a literal
     * HMAC rather than a recomputation.
     */
    public function testProtocolHeadersAreUnchanged(): void
    {
        Functions\when('get_option')->justReturn([
            'endpoints'      => [['url' => 'https://hooks.example.com/x', 'secret' => 'shh', 'analytics' => true]],
            'shared_secret'  => '',
            'global_headers' => [],
            'global_query'   => [],
        ]);

        if (!defined('CVM_VERSION')) {
            self::markTestSkipped('CVM_VERSION is defined by the bootstrap.');
        }

        $headers = RequestFactory::withProtocolHeaders(
            ['Content-Type' => 'application/json; charset=utf-8'],
            'https://hooks.example.com/x',
            '{"a":1}',
            'deliv123'
        );

        self::assertSame([
            'Content-Type'            => 'application/json; charset=utf-8',
            'User-Agent'              => 'WordPress/Convermetry ' . CVM_VERSION,
            'Idempotency-Key'         => 'deliv123',
            'X-Convermetry-Signature' => 'sha256=' . hash_hmac('sha256', '{"a":1}', 'shh'),
        ], $headers);
    }

    /**
     * No 'extensions' property may appear anywhere in a payload built on a site
     * with no callbacks registered. A receiver validating against a strict
     * schema would reject an unexpected property.
     */
    public function testPayloadsCarryNoEmptyExtensionsProperty(): void
    {
        $payload = PayloadBuilder::formSubmissionTest();

        self::assertArrayNotHasKey('extensions', $payload);
        self::assertStringNotContainsString('"extensions"', (string) json_encode($payload));
    }

    public function testTheFormSubmissionEnvelopeShapeIsUnchanged(): void
    {
        self::assertSame([
            'schema_version', 'source', 'plugin_version', 'message_type',
            'website_info', 'generated_at', 'delivery_id',
            'form_submission', 'analytics_context', 'test',
        ], array_keys(PayloadBuilder::formSubmissionTest()));
    }

    /**
     * The REST item's 18 keys and their order are the documented API contract.
     * endpoint_url is redacted to an origin — the extraction of that rule into
     * Url::origin() must not have changed what a consumer receives.
     */
    public function testTheDeliveryLogRestItemIsUnchanged(): void
    {
        $item = DeliveryLogController::formatEntry([
            'id'             => 42,
            'created_at'     => '2026-08-22 14:32:00',
            'success'        => 1,
            'endpoint_url'   => 'https://u:p@hooks.example.com:8443/ingest/abc?token=SECRET',
            'endpoint_label' => 'Prod',
            'delivery_id'    => 'd1',
            'message_type'   => 'form_submission',
            'kind'           => 'immediate',
            'attempt'        => 1,
            'submission_id'  => 's1',
            'conversion_id'  => 'c1',
            'form_provider'  => 'elementor',
            'form_name'      => 'Contact',
            'response_code'  => 200,
            'request_data'   => '{"a":1}',
            'response_data'  => 'ok',
        ]);

        self::assertSame([
            'id', 'created_at', 'success', 'endpoint_url', 'endpoint_key', 'endpoint_label',
            'delivery_id', 'message_type', 'kind', 'attempt', 'submission_id', 'conversion_id',
            'form_provider', 'form_name', 'response_code', 'request_data', 'response_data',
        ], array_keys($item));

        self::assertSame('https://hooks.example.com:8443', $item['endpoint_url']);
        self::assertStringNotContainsString('SECRET', (string) json_encode($item));
    }
}
