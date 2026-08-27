<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Support\Url;
use Convermetry\Webhook\DeliveryContext;
use PHPUnit\Framework\TestCase;

/**
 * The context every webhook lifecycle action carries.
 *
 * Two properties matter more than the rest. The first is totality: a listener
 * indexes this array without defensive checks, so every documented key is
 * present on every path even when that path cannot know the value.
 *
 * The second is what is NOT in it. These actions are exactly what people wire
 * to logging and telemetry, and a webhook endpoint URL routinely carries a
 * bearer token in its path or query — so the array identifies an endpoint by
 * key, label, and origin, and never by its URL. There is no test here that a
 * secret is redacted, because no code path puts one in.
 */
final class DeliveryContextTest extends TestCase
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $fired = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->fired = [];

        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('get_option')->justReturn([]);
        Functions\when('do_action')->alias(function (string $hook, mixed ...$args): void {
            $this->fired[] = [$hook, $args];
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ----------------------------------------------------------------- shape

    /**
     * The key set and its order are the contract. A listener that reads
     * $context['disposition'] must not have to check it exists first.
     */
    public function testEveryDocumentedKeyIsAlwaysPresentInAFixedOrder(): void
    {
        $context = DeliveryContext::build('https://hooks.example.com/x');

        self::assertSame([
            'message_type', 'kind', 'attempt', 'delivery_id', 'is_test',
            'endpoint_key', 'endpoint_label', 'endpoint_origin',
            'submission_id', 'conversion_id', 'form_key',
            'window_start', 'window_end', 'transport_attempted', 'disposition',
        ], array_keys($context));
    }

    public function testUnknownValuesAreZeroValuedRatherThanAbsent(): void
    {
        $context = DeliveryContext::build('https://hooks.example.com/x');

        self::assertSame('', $context['message_type']);
        self::assertSame(0, $context['attempt']);
        self::assertSame(0, $context['window_start']);
        self::assertFalse($context['is_test']);
        self::assertFalse($context['transport_attempted']);
    }

    /**
     * A credential in the endpoint URL must not reach the context. The path,
     * query, fragment, and userinfo are all dropped; what identifies the
     * endpoint is the same md5 key the Activity Log and REST API already use.
     */
    public function testACredentialBearingEndpointUrlIsReducedToItsOrigin(): void
    {
        $url     = 'https://user:pass@hooks.example.com:8443/ingest/abc?token=SECRET#frag';
        $context = DeliveryContext::build($url, ['endpoint_label' => 'Prod']);

        self::assertSame('https://hooks.example.com:8443', $context['endpoint_origin']);
        self::assertSame(md5($url), $context['endpoint_key']);
        self::assertSame('Prod', $context['endpoint_label']);

        $flat = (string) json_encode($context);
        self::assertStringNotContainsString('SECRET', $flat);
        self::assertStringNotContainsString('pass', $flat);
        self::assertStringNotContainsString('/ingest/abc', $flat);
    }

    public function testIsTestDefaultsFromTheDeliveryKind(): void
    {
        self::assertTrue(DeliveryContext::build('https://e.test/x', ['kind' => 'test'])['is_test']);
        self::assertFalse(DeliveryContext::build('https://e.test/x', ['kind' => 'retry'])['is_test']);
    }

    public function testWithOverridesOnlyKnownKeys(): void
    {
        $context = DeliveryContext::build('https://e.test/x', ['kind' => 'retry']);
        $updated = DeliveryContext::with($context, ['attempt' => 3, 'not_a_key' => 'ignored']);

        self::assertSame(3, $updated['attempt']);
        self::assertArrayNotHasKey('not_a_key', $updated);
        self::assertSame(array_keys($context), array_keys($updated));
    }

    // ---------------------------------------------------------------- firing

    /**
     * before_send is handed the real request so that only metadata escapes:
     * a listener sees the body's size and digest and the header NAMES, never
     * the URL, the header values, or the body.
     */
    public function testBeforeSendExposesMetadataButNeitherCredentialsNorBody(): void
    {
        $context = DeliveryContext::build('https://hooks.example.com/ingest?token=SECRET');

        DeliveryContext::beforeSend(
            $context,
            'https://hooks.example.com/ingest?token=SECRET&src=global',
            [
                'Content-Type'            => 'application/json; charset=utf-8',
                'Authorization'           => 'Bearer SECRET-TOKEN',
                'X-Convermetry-Signature' => 'sha256=abc123',
            ],
            '{"email":"visitor@example.com"}'
        );

        self::assertSame('convermetry_webhook_before_send', $this->fired[0][0]);

        [$firedContext, $meta] = $this->fired[0][1];

        self::assertSame($context, $firedContext);
        self::assertSame(31, $meta['body_bytes']);
        self::assertSame(hash('sha256', '{"email":"visitor@example.com"}'), $meta['body_sha256']);
        self::assertSame(
            ['Content-Type', 'Authorization', 'X-Convermetry-Signature'],
            $meta['header_names']
        );
        self::assertTrue($meta['signed']);

        $flat = (string) json_encode($this->fired[0][1]);
        self::assertStringNotContainsString('SECRET', $flat);
        self::assertStringNotContainsString('Bearer', $flat);
        self::assertStringNotContainsString('visitor@example.com', $flat);
        self::assertStringNotContainsString('sha256=abc123', $flat);
    }

    /**
     * The response body is not passed on either: an endpoint's error page can
     * echo back the payload it was just sent.
     */
    public function testAttemptedCarriesTheTransportResultWithoutTheResponseBody(): void
    {
        $context = DeliveryContext::build('https://e.test/x', ['kind' => 'retry']);

        $returned = DeliveryContext::attempted(
            $context,
            ['ok' => false, 'code' => 500, 'message' => 'Internal Server Error', 'body' => 'echo: visitor@example.com'],
            true
        );

        self::assertSame('convermetry_webhook_delivery_attempted', $this->fired[0][0]);
        self::assertSame([$returned, false, 500, 'Internal Server Error'], $this->fired[0][1]);
        self::assertTrue($returned['transport_attempted']);
        self::assertStringNotContainsString('visitor@example.com', (string) json_encode($this->fired[0][1]));
    }

    public function testAttemptedReportsWhenNoRequestReachedTheWire(): void
    {
        $context = DeliveryContext::build('https://e.test/x');

        $returned = DeliveryContext::attempted(
            $context,
            ['ok' => false, 'code' => 0, 'message' => 'Payload could not be JSON-encoded', 'body' => ''],
            false
        );

        self::assertFalse($returned['transport_attempted']);
        self::assertFalse($this->fired[0][1][3] === '');
    }

    public function testEachTerminalHelperFiresItsOwnDistinctlyNamedAction(): void
    {
        $context = DeliveryContext::build('https://e.test/x');

        DeliveryContext::frozen($context, 'queue_row', 512);
        DeliveryContext::attemptLogged($context, 'suppressed');
        DeliveryContext::succeeded($context);
        DeliveryContext::retryScheduled($context, 2, 1780000000);
        DeliveryContext::retryChainExhausted($context);
        DeliveryContext::abandoned($context, 'retries_exhausted');
        DeliveryContext::canceled($context, 'submission_deleted');

        self::assertSame([
            'convermetry_webhook_delivery_frozen',
            'convermetry_delivery_attempt_logged',
            'convermetry_webhook_delivery_succeeded',
            'convermetry_webhook_retry_scheduled',
            'convermetry_webhook_retry_chain_exhausted',
            'convermetry_webhook_delivery_abandoned',
            'convermetry_webhook_delivery_canceled',
        ], array_column($this->fired, 0));

        self::assertSame([$context, 'queue_row', 512], $this->fired[0][1]);
        self::assertSame([$context, 2, 1780000000], $this->fired[3][1]);
    }

    // ------------------------------------------------------------ Url::origin

    /**
     * @dataProvider origins
     */
    public function testOriginKeepsSchemeHostAndPortAndNothingElse(string $url, string $expected): void
    {
        self::assertSame($expected, Url::origin($url));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function origins(): array
    {
        return [
            'plain'      => ['https://hooks.example.com/a/b', 'https://hooks.example.com'],
            'query'      => ['https://hooks.example.com/a?key=abc', 'https://hooks.example.com'],
            'userinfo'   => ['https://u:p@hooks.example.com/a', 'https://hooks.example.com'],
            'port kept'  => ['https://hooks.example.com:8443/a', 'https://hooks.example.com:8443'],
            'fragment'   => ['https://hooks.example.com/a#f', 'https://hooks.example.com'],
            'uppercased' => ['HTTPS://hooks.example.com/a', 'https://hooks.example.com'],
            'empty'      => ['', ''],
            'garbage'    => ['not a url', ''],
        ];
    }
}
