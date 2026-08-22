<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Webhook\RequestFactory;
use PHPUnit\Framework\TestCase;

/**
 * The frozen-retry contract, and the deliberate exception to it.
 *
 * The body and delivery_id are frozen; three protocol headers are regenerated
 * per attempt. This suite pins that decision down so it cannot be "fixed" back
 * into freezing the signature — which would make rotating a secret strand every
 * in-flight chain, since receivers validate against their current secret.
 */
final class FrozenRetryTest extends TestCase
{
    /** @var array<int, array{id: string, url: string, label: string, secret: string, analytics: bool, forms: bool}> */
    private array $endpoints = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->endpoints = [[
            'id'        => 'endpoint-uuid-1',
            'url'       => 'https://receiver.test/hook',
            'label'     => 'Main',
            'secret'    => 'original-secret',
            'analytics' => true,
            'forms'     => true,
        ]];

        Functions\when('get_option')->alias(fn(string $key, $default = false) => $key === 'cvm_webhook_settings'
            ? ['endpoints' => $this->endpoints, 'shared_secret' => 'shared-secret']
            : $default);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function sign(string $body): ?string
    {
        $headers = RequestFactory::withProtocolHeaders(
            ['Content-Type' => 'application/json; charset=utf-8'],
            'endpoint-uuid-1',
            $body,
            'delivery-abc'
        );

        return $headers['X-Convermetry-Signature'] ?? null;
    }

    public function testIdempotencyKeyEqualsTheFrozenDeliveryId(): void
    {
        $headers = RequestFactory::withProtocolHeaders([], 'endpoint-uuid-1', '{"a":1}', 'delivery-abc');

        self::assertSame('delivery-abc', $headers['Idempotency-Key']);
    }

    public function testSignatureIsStableAcrossAttemptsWhenNothingChanges(): void
    {
        $body = '{"report":1}';

        self::assertSame($this->sign($body), $this->sign($body));
    }

    /**
     * The documented deviation: identical bytes, new key, new signature — so a
     * rotated secret does not strand the chain.
     */
    public function testRotatingTheSecretResignsTheIdenticalBody(): void
    {
        $body   = '{"report":1}';
        $before = $this->sign($body);

        $this->endpoints[0]['secret'] = 'rotated-secret';
        $after = $this->sign($body);

        self::assertNotSame($before, $after, 'Signature must follow the current secret');
        self::assertSame(
            'sha256=' . hash_hmac('sha256', $body, 'rotated-secret'),
            $after,
            'Signature must be computed over the unchanged frozen bytes'
        );
    }

    /**
     * The regression that motivated storing an endpoint id: resolving the
     * secret by URL meant a renamed endpoint matched nothing and fell through
     * to the shared secret, producing a signature indistinguishable from a
     * forgery.
     */
    public function testEditingTheUrlStillSignsWithThatEndpointsOwnSecret(): void
    {
        $body   = '{"report":1}';
        $before = $this->sign($body);

        $this->endpoints[0]['url'] = 'https://receiver.test/v2-hook';

        self::assertSame($before, $this->sign($body));
    }

    public function testDeletedEndpointSendsNoSignatureRatherThanASharedOne(): void
    {
        $this->endpoints = [];

        self::assertNull($this->sign('{"report":1}'));
    }

    /**
     * A resolved endpoint with no secret of its own still inherits the shared
     * secret — that behaviour is unchanged and intentional.
     */
    public function testEndpointWithoutOwnSecretInheritsTheSharedSecret(): void
    {
        $this->endpoints[0]['secret'] = '';
        $body = '{"report":1}';

        self::assertSame('sha256=' . hash_hmac('sha256', $body, 'shared-secret'), $this->sign($body));
    }
}
