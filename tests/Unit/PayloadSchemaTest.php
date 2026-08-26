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
 *  3. Analytics reports version SEPARATELY from submissions. They moved to 1.1
 *     in 0.5.0 to add a goals section; submissions did not move, and must not.
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

    /**
     * 0.5.0 moved reports from 1.0 to 1.1 to add 'analytics.goals'.
     *
     * A MINOR bump, and the distinction is the contract: every 1.0 field is
     * still present, in place, with the same shape, so a receiver written
     * against 1.0 keeps working untouched. Anything that removed or reshaped an
     * existing field would have to be 2.0 instead.
     */
    public function testAnalyticsSchemaVersionIsAMinorBump(): void
    {
        self::assertSame('1.1', PayloadBuilder::SCHEMA_VERSION);

        [$major] = explode('.', PayloadBuilder::SCHEMA_VERSION, 2);

        self::assertSame(
            '1',
            $major,
            'Adding a section is additive and stays within major version 1. Removing or reshaping an '
            . 'existing field would break every 1.0 receiver and must be a major bump instead.'
        );
    }

    /**
     * Adding the goals section must not have touched anything a 1.0 receiver
     * reads. Asserted against the source because buildSummary() needs a
     * database, and this suite deliberately has none — what matters here is
     * that no existing key was renamed or removed.
     */
    public function testEveryAnalyticsSectionFromTenIsStillEmitted(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Analytics/Reports.php');

        $start = strpos($source, 'public static function buildSummary');
        self::assertNotFalse($start);

        $summary = substr($source, $start);

        foreach ([
            'totals', 'daily_pageviews', 'top_pages', 'top_landing_pages', 'top_clicks',
            'top_forms', 'top_hovers', 'top_referrers', 'top_campaigns',
            'top_campaign_content', 'channels', 'conversions', 'devices',
        ] as $key) {
            self::assertMatchesRegularExpression(
                "~'" . preg_quote($key, '~') . "'\s*=>~",
                $summary,
                "The 1.0 analytics section '{$key}' is no longer emitted — that is a breaking change for "
                . 'every existing receiver and cannot ship as a minor bump.'
            );
        }

        self::assertMatchesRegularExpression("~'goals'\s*=>~", $summary, 'The 1.1 goals section is missing.');
    }

    public function testTheTwoMessageTypesVersionIndependently(): void
    {
        self::assertNotSame(
            PayloadBuilder::SCHEMA_VERSION,
            PayloadBuilder::FORM_SCHEMA_VERSION,
            'The split constant is the whole point: reports and submissions evolve separately'
        );

        // Form submissions did NOT move in 0.5.0. Lead status and value are
        // recorded locally and deliberately absent from the wire, precisely so
        // that this constant did not have to move — a payload frozen on its
        // first delivery attempt could only ever report a lead as 'new'.
        self::assertSame('2.0', PayloadBuilder::FORM_SCHEMA_VERSION);
        self::assertSame('1.0', PayloadBuilder::LEGACY_FORM_SCHEMA_VERSION);
    }

    /**
     * A form_submission payload carries no lead block. A field reporting every
     * qualified lead as 'new' forever would be worse than its absence.
     */
    public function testFormSubmissionsCarryNoLeadOutcome(): void
    {
        $payload = PayloadBuilder::formSubmissionTest();

        self::assertArrayNotHasKey('lead', $payload['form_submission']);
        self::assertArrayNotHasKey('lead_status', $payload['form_submission']);
        self::assertArrayNotHasKey('lead_value', $payload['form_submission']);
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
