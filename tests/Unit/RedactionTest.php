<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Support\SensitiveKeys;
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
        // SensitiveKeys::patterns() runs the pattern list through a filter.
        Functions\when('apply_filters')->returnArg(2);
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
        $body = (string) json_encode(['API Key' => 'sk_live', 'api-key' => 'sk_live']);
        $out  = json_decode($this->redactJson($body), true);

        self::assertSame('[REDACTED]', $out['API Key']);
        self::assertSame('[REDACTED]', $out['api-key']);
    }

    /**
     * Schema 2.0 moves the sensitive NAME into the value of 'id'/'label' while
     * the secret sits under the generic key 'value'. Key-name matching alone
     * walks straight past it.
     */
    public function testStructuredFieldDescriptorsAreRedactedByIdOrLabel(): void
    {
        $body = (string) json_encode([
            'form_submission' => [
                'submission_data' => [
                    ['id' => 'email',    'label' => 'Email address', 'value' => 'a@b.com'],
                    ['id' => 'password', 'label' => 'Choose a password', 'value' => 'hunter2'],
                    ['id' => 'field_9',  'label' => 'API Key', 'value' => 'sk_live_abc'],
                ],
            ],
        ]);

        $out    = json_decode($this->redactJson($body), true);
        $fields = $out['form_submission']['submission_data'];

        self::assertSame('a@b.com', $fields[0]['value'], 'An ordinary field must survive');
        self::assertSame('[REDACTED]', $fields[1]['value'], 'Matched on the id');
        self::assertSame('[REDACTED]', $fields[2]['value'], 'Matched on the human label');

        // The descriptor itself stays intact and in order — only the value goes.
        self::assertSame('password', $fields[1]['id']);
        self::assertSame('API Key', $fields[2]['label']);
        self::assertStringNotContainsString('hunter2', $this->redactJson($body));
        self::assertStringNotContainsString('sk_live_abc', $this->redactJson($body));
    }

    /**
     * The descriptor branch must not short-circuit recursion for a descriptor
     * that was NOT redacted. This walker also runs over arbitrary endpoint
     * response bodies, and one shaped like a descriptor would otherwise
     * smuggle a nested secret through.
     */
    public function testNonSensitiveDescriptorStillHasNestedSecretsRedacted(): void
    {
        $body = (string) json_encode([
            'id'    => 'record-1',
            'label' => 'Result',
            'value' => ['client_secret' => 'shh', 'name' => 'Ada'],
        ]);

        $out = json_decode($this->redactJson($body), true);

        self::assertSame('[REDACTED]', $out['value']['client_secret']);
        self::assertSame('Ada', $out['value']['name']);
    }

    /**
     * Historical rows still hold the pre-2.0 associative map. Their redaction
     * must be exactly as it was.
     */
    public function testLegacyAssociativeMapRedactionIsUnchanged(): void
    {
        $body = (string) json_encode([
            'form_submission' => [
                'submission_data' => ['Email' => 'a@b.com', 'password' => 'hunter2'],
            ],
        ]);

        $out = json_decode($this->redactJson($body), true);

        self::assertSame('a@b.com', $out['form_submission']['submission_data']['Email']);
        self::assertSame('[REDACTED]', $out['form_submission']['submission_data']['password']);
    }

    /**
     * Moving the pattern list out of DeliveryLog must not quietly disable
     * redaction: this fails if the shared policy stops being consulted.
     */
    public function testRedactionUsesTheSharedPatternList(): void
    {
        self::assertTrue(SensitiveKeys::matches('password'));

        $body = (string) json_encode(['client_secret' => 'shh']);

        self::assertSame('[REDACTED]', json_decode($this->redactJson($body), true)['client_secret']);
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
