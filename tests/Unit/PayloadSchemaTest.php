<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Webhook\PayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Wire-schema versioning across the v1 → v2 transition.
 *
 * Moving submission_data from an object to an array is a breaking change for
 * every receiver, so it is versioned rather than sprung. Three rules have to
 * hold simultaneously, and they pull against each other:
 *
 *  1. New submissions emit 2.0 with the descriptor list.
 *  2. Historical rows keep emitting 1.0 with their original map — otherwise
 *     one submission_id could arrive at two endpoints in two different shapes.
 *  3. Analytics reports were untouched and stay 1.0.
 */
final class PayloadSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('apply_filters')->returnArg(2);
        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('get_bloginfo')->justReturn('Example');
        Functions\when('get_option')->justReturn([]);
        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('wp_json_encode')->alias(
            static fn($data, $options = 0, $depth = 512) => json_encode($data, $options, $depth)
        );
        Functions\when('wp_rand')->justReturn(7);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'submission_id'   => 's1',
            'conversion_id'   => 'c1',
            'provider'        => 'gravityforms',
            'form_name'       => 'Contact',
            'form_id'         => 'contact',
            'native_form_id'  => '7',
            'page_url'        => 'https://example.com/contact',
            'ip_address'      => '',
            'page_query'      => '{}',
            'context'         => '{}',
            'created_at'      => '2026-08-22 14:32:00',
        ], $overrides);
    }

    public function testStructuredSubmissionEmitsSchemaTwo(): void
    {
        $payload = PayloadBuilder::formSubmission($this->row([
            'submission_data' => '[{"id":"email","label":"Email address","value":"john@example.com"}]',
        ]));

        self::assertSame('2.0', $payload['schema_version']);
        self::assertSame(
            [['id' => 'email', 'label' => 'Email address', 'value' => 'john@example.com']],
            $payload['form_submission']['submission_data']
        );
    }

    /**
     * The compatibility rule that keeps one submission consistent across
     * endpoints and retries: a pre-2.0 row is never converted on the way out.
     */
    public function testHistoricalMapRowStillEmitsSchemaOneWithItsOriginalMap(): void
    {
        $payload = PayloadBuilder::formSubmission($this->row([
            'submission_data' => '{"Email":"john@example.com","Phone":"555"}',
        ]));

        self::assertSame('1.0', $payload['schema_version']);
        self::assertSame(
            ['Email' => 'john@example.com', 'Phone' => '555'],
            $payload['form_submission']['submission_data'],
            'A historical map must travel exactly as stored, not normalized'
        );
    }

    /**
     * '{}' and '[]' both decode to []. Treating that as a legacy map would pin
     * every field-less submission to 1.0 for the rest of the site's life.
     *
     * @dataProvider emptySubmissionData
     */
    public function testFieldlessSubmissionEmitsAnEmptyTwoPointZeroList(string $stored): void
    {
        $payload = PayloadBuilder::formSubmission($this->row(['submission_data' => $stored]));

        self::assertSame('2.0', $payload['schema_version']);
        self::assertSame([], $payload['form_submission']['submission_data']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptySubmissionData(): array
    {
        return [
            'empty object' => ['{}'],
            'empty list'   => ['[]'],
            'empty column' => [''],
            'malformed'    => ['{"a":'],
        ];
    }

    public function testTheTestPayloadUsesTheCurrentSchema(): void
    {
        $payload = PayloadBuilder::formSubmissionTest();

        self::assertSame('2.0', $payload['schema_version']);
        self::assertTrue($payload['test']);
        self::assertTrue(
            array_is_list($payload['form_submission']['submission_data']),
            'A test send must show receivers the shape they will actually get'
        );
        self::assertSame('Email address', $payload['form_submission']['submission_data'][1]['label']);
    }

    /** Reports were not touched by structured fields and must not move. */
    public function testAnalyticsSchemaVersionIsUnchanged(): void
    {
        self::assertSame('1.0', PayloadBuilder::SCHEMA_VERSION);
    }

    public function testTheTwoMessageTypesVersionIndependently(): void
    {
        self::assertNotSame(
            PayloadBuilder::SCHEMA_VERSION,
            PayloadBuilder::FORM_SCHEMA_VERSION,
            'The split constant is the whole point: reports and submissions evolve separately'
        );
        self::assertSame(PayloadBuilder::SCHEMA_VERSION, PayloadBuilder::LEGACY_FORM_SCHEMA_VERSION);
    }

    /**
     * The envelope keys and their order are part of the contract; adding a
     * schema branch must not disturb them.
     */
    public function testEnvelopeShapeIsUnchanged(): void
    {
        $payload = PayloadBuilder::formSubmission($this->row(['submission_data' => '[]']));

        self::assertSame([
            'schema_version', 'source', 'plugin_version', 'message_type',
            'website_info', 'generated_at', 'delivery_id',
            'form_submission', 'analytics_context',
        ], array_keys($payload));
    }

    /**
     * generated_at comes from the stored creation time, so every endpoint and
     * every retry of one submission agrees on it.
     */
    public function testGeneratedAtComesFromTheStoredCreationTime(): void
    {
        $payload = PayloadBuilder::formSubmission($this->row(['submission_data' => '[]']));

        self::assertSame('2026-08-22T14:32:00+00:00', $payload['generated_at']);
    }
}
