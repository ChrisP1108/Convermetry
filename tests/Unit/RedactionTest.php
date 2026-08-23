<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Webhook\DeliveryLog;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Redaction of stored delivery bodies and headers.
 *
 * Regression origin: request_data was only size-capped while response_data was
 * redacted, so a form field named 'password' was stored in the clear and
 * exported. Header matching also compared raw names, so 'X-API-Key' matched
 * neither 'api_key' nor 'apikey'.
 */
final class RedactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('wp_json_encode')->alias(
            static fn($data, $options = 0, $depth = 512) => json_encode($data, $options, $depth)
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function redactJson(string $body): string
    {
        $method = new ReflectionMethod(DeliveryLog::class, 'redactSensitiveJson');

        return (string) $method->invoke(null, $body);
    }

    /**
     * The header spelling that motivated the fix. 'X-API-Key' canonicalizes to
     * 'x_api_key', which contains the existing 'api_key' pattern.
     */
    public function testApiKeyHeaderIsRedactedRegardlessOfSpelling(): void
    {
        // Fails on main — see plan Phase 1g. Header redaction misses some
        // spellings of the same key, so a live credential is stored in the clear
        // in the Delivery Log. Remove this skip as part of the fix.
        self::markTestSkipped(
            'Phase 1g: DeliveryLog::redactHeaders() does not match every spelling of an API-key header.'
        );

        $redacted = DeliveryLog::redactHeaders([
            'X-API-Key'           => 'live_secret',
            'x-api-key'           => 'live_secret',
            'X_Api_Key'           => 'live_secret',
            'Authorization'       => 'Bearer tok',
            'Proxy-Authorization' => 'Basic abc',
            'Cookie'              => 'sid=1',
            'Set-Cookie'          => 'sid=1',
        ]);

        foreach ($redacted as $name => $value) {
            self::assertSame('[REDACTED]', $value, "Header {$name} was stored in the clear");
        }
    }

    public function testNonCredentialHeadersAreLeftIntact(): void
    {
        $redacted = DeliveryLog::redactHeaders([
            'Content-Type'            => 'application/json',
            'X-Request-Id'            => 'req-1',
            'X-Convermetry-Signature' => 'sha256=abc',
        ]);

        self::assertSame('application/json', $redacted['Content-Type']);
        self::assertSame('req-1', $redacted['X-Request-Id']);
        // A public verification value, needed to debug receiver-side 401s.
        self::assertSame('sha256=abc', $redacted['X-Convermetry-Signature']);
    }

    public function testSensitivePayloadFieldsAreRedactedIncludingNested(): void
    {
        $body = (string) json_encode([
            'form_submission' => [
                'submission_data' => ['Email' => 'a@b.com', 'password' => 'hunter2'],
                'nested'          => ['deep' => ['client_secret' => 'shh']],
            ],
        ]);

        $out = json_decode($this->redactJson($body), true);

        self::assertSame('a@b.com', $out['form_submission']['submission_data']['Email']);
        self::assertSame('[REDACTED]', $out['form_submission']['submission_data']['password']);
        self::assertSame('[REDACTED]', $out['form_submission']['nested']['deep']['client_secret']);
    }

    /**
     * Providers such as Gravity Forms and Elementor use the field LABEL as the
     * payload key, so human spellings must match too.
     */
    public function testHumanReadableFieldLabelsAreMatched(): void
    {
        // Fails on main — see plan Phase 1g. Space- and hyphen-separated label
        // forms ("API Key") slip past JSON body redaction, so a secret submitted
        // under a human-readable field label is logged in the clear.
        self::markTestSkipped(
            'Phase 1g: JSON body redaction does not match human-readable field labels such as "API Key".'
        );

        $body = (string) json_encode(['API Key' => 'sk_live', 'api-key' => 'sk_live']);
        $out  = json_decode($this->redactJson($body), true);

        self::assertSame('[REDACTED]', $out['API Key']);
        self::assertSame('[REDACTED]', $out['api-key']);
    }

    /**
     * Guards the decision to reject a bare 'auth' pattern: it would redact
     * legitimate content in a field named 'author'.
     */
    public function testAuthorFieldIsNotMistakenForACredential(): void
    {
        $body = (string) json_encode(['Author' => 'Jane Doe', 'author_bio' => 'writes things']);
        $out  = json_decode($this->redactJson($body), true);

        self::assertSame('Jane Doe', $out['Author']);
        self::assertSame('writes things', $out['author_bio']);
    }

    /**
     * @dataProvider nonJsonBodies
     */
    public function testNonJsonBodiesArePassedThroughUnchanged(string $body): void
    {
        self::assertSame($body, $this->redactJson($body));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonJsonBodies(): array
    {
        return [
            'empty'       => [''],
            'plain text'  => ['upstream error: connection reset'],
            'html'        => ['<html><body>502</body></html>'],
            'json scalar' => ['null'],
            'truncated'   => ['{"a":1 [TRUNCATED]'],
        ];
    }
}
