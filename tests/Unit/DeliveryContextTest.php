<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Support\Url;
use Convermetry\Webhook\DeliveryContext;
use Convermetry\Webhook\DeliveryDetails;
use Convermetry\Webhook\DeliveryKind;
use Convermetry\Webhook\LogOutcome;
use Convermetry\Webhook\MessageType;
use Convermetry\Webhook\TransportResult;
use PHPUnit\Framework\TestCase;

/**
 * The context every webhook lifecycle action carries.
 *
 * The facts are a typed {@see DeliveryDetails} internally, but what listeners
 * receive is an ARRAY, and that array is the public contract — so these tests
 * assert on what comes out of do_action(), not on the object.
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

    /**
     * The details a delivery path would build, with the two facts every path
     * knows filled in.
     */
    private function details(
        string $url,
        DeliveryKind $kind = DeliveryKind::Scheduled,
        ?string $label = null
    ): DeliveryDetails {
        return DeliveryDetails::for(
            $url,
            messageType: MessageType::AnalyticsReport,
            kind: $kind,
            endpointLabel: $label,
        );
    }

    // ----------------------------------------------------------------- shape

    /**
     * The key set and its order are the contract. A listener that reads
     * $context['disposition'] must not have to check it exists first.
     */
    public function testEveryDocumentedKeyIsAlwaysPresentInAFixedOrder(): void
    {
        $context = $this->details('https://hooks.example.com/x')->toArray();

        self::assertSame([
            'message_type', 'kind', 'attempt', 'delivery_id', 'is_test',
            'endpoint_key', 'endpoint_label', 'endpoint_origin',
            'submission_id', 'conversion_id', 'form_key',
            'window_start', 'window_end', 'transport_attempted', 'disposition',
        ], array_keys($context));
    }

    public function testUnknownValuesAreZeroValuedRatherThanAbsent(): void
    {
        $context = $this->details('https://hooks.example.com/x')->toArray();

        self::assertSame('', $context['delivery_id']);
        self::assertSame('', $context['submission_id']);
        self::assertSame('', $context['disposition']);
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
        $context = $this->details($url, label: 'Prod')->toArray();

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
        self::assertTrue($this->details('https://e.test/x', DeliveryKind::Test)->isTest);
        self::assertFalse($this->details('https://e.test/x', DeliveryKind::Retry)->isTest);
    }

    /**
     * The withers copy rather than mutate, and leave every other fact — and
     * the serialized key set — exactly as it was.
     */
    public function testTheWithersReturnCopiesAndChangeNothingElse(): void
    {
        $context = $this->details('https://e.test/x', DeliveryKind::Retry);
        $updated = $context->withAttempt(3)->withTransportAttempted(true);

        self::assertSame(0, $context->attempt);
        self::assertFalse($context->transportAttempted);
        self::assertSame(3, $updated->attempt);
        self::assertTrue($updated->transportAttempted);
        self::assertSame(array_keys($context->toArray()), array_keys($updated->toArray()));
        self::assertSame($context->endpointKey, $updated->endpointKey);
    }

    /**
     * The two enum-backed fields still serialize to the strings listeners and
     * the Activity Log have always seen.
     */
    public function testTheEnumFieldsSerializeToTheirWireStrings(): void
    {
        $context = $this->details('https://e.test/x', DeliveryKind::Retry)->toArray();

        self::assertSame('analytics_report', $context['message_type']);
        self::assertSame('retry', $context['kind']);
    }

    // ---------------------------------------------------------------- firing

    /**
     * before_send is handed the real request so that only metadata escapes:
     * a listener sees the body's size and digest and the header NAMES, never
     * the URL, the header values, or the body.
     */
    public function testBeforeSendExposesMetadataButNeitherCredentialsNorBody(): void
    {
        $context = $this->details('https://hooks.example.com/ingest?token=SECRET');

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

        self::assertSame($context->toArray(), $firedContext);
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
        $context = $this->details('https://e.test/x', DeliveryKind::Retry);

        $returned = DeliveryContext::attempted(
            $context,
            new TransportResult(false, 500, 'Internal Server Error', 'echo: visitor@example.com'),
            true
        );

        self::assertSame('convermetry_webhook_delivery_attempted', $this->fired[0][0]);
        self::assertSame([$returned->toArray(), false, 500, 'Internal Server Error'], $this->fired[0][1]);
        self::assertTrue($returned->transportAttempted);
        self::assertStringNotContainsString('visitor@example.com', (string) json_encode($this->fired[0][1]));
    }

    public function testAttemptedReportsWhenNoRequestReachedTheWire(): void
    {
        $context = $this->details('https://e.test/x');

        $returned = DeliveryContext::attempted(
            $context,
            TransportResult::failure('Payload could not be JSON-encoded'),
            false
        );

        self::assertFalse($returned->transportAttempted);
        self::assertFalse($this->fired[0][1][3] === '');
    }

    public function testEachTerminalHelperFiresItsOwnDistinctlyNamedAction(): void
    {
        $context = $this->details('https://e.test/x');

        DeliveryContext::frozen($context, 'queue_row', 512);
        DeliveryContext::attemptLogged($context, LogOutcome::Suppressed);
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

        self::assertSame([$context->toArray(), 'queue_row', 512], $this->fired[0][1]);
        self::assertSame([$context->toArray(), 2, 1780000000], $this->fired[3][1]);

        // The log disposition still crosses the hook boundary as a string.
        self::assertSame('suppressed', $this->fired[1][1][1]);
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
