<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Webhook\PayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The exact bytes a receiver gets.
 *
 * PayloadSchemaTest covers the VERSIONING rules — which schema_version a row
 * emits and why. This covers the shape underneath them: the full key set of a
 * form-submission payload, in order, with website_info and analytics_context
 * spelled out.
 *
 * It exists because the identity and context blocks are now assembled from
 * typed objects rather than written inline as array literals, and the whole
 * safety argument for that change is that the serialized result did not move.
 * A receiver validating against a strict schema would reject a renamed key, a
 * reordered one, or a null where an empty string used to be — and would do it
 * in production, long after the refactor that caused it.
 */
final class WirePayloadSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('apply_filters')->returnArg(2);
        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('home_url')->justReturn('https://www.example.com');
        Functions\when('get_bloginfo')->justReturn('Example Co');
        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('wp_json_encode')->alias(
            static fn($data, $options = 0, $depth = 512) => json_encode($data, $options, $depth)
        );
        Functions\when('get_option')->alias(static fn(string $key, mixed $default = false): mixed => match ($key) {
            'cvm_settings' => [
                'website_id'        => 'site-42',
                'client_first_name' => 'Ada',
                'client_last_name'  => 'Lovelace',
                'client_id'         => 'client-7',
            ],
            default => $default,
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testTheFormSubmissionEnvelopeKeepsItsKeysAndOrder(): void
    {
        $payload = PayloadBuilder::formSubmission([
            'submission_id'   => 's1',
            'conversion_id'   => 'c1',
            'provider'        => 'gravityforms',
            'form_name'       => 'Contact',
            'form_id'         => 'contact',
            'native_form_id'  => '7',
            'page_url'        => 'https://www.example.com/contact',
            'ip_address'      => '203.0.113.42',
            'page_query'      => '{"utm_source":"newsletter"}',
            'submission_data' => '[{"id":"email","label":"Email address","value":"john@example.com"}]',
            'context'         => '{}',
            'created_at'      => '2026-08-22 14:32:00',
        ]);

        self::assertSame([
            'schema_version', 'source', 'plugin_version', 'message_type',
            'website_info', 'generated_at', 'delivery_id',
            'form_submission', 'analytics_context',
        ], array_keys($payload));

        self::assertSame('convermetry', $payload['source']);
        self::assertSame('form_submission', $payload['message_type']);
    }

    /**
     * website_info is the one block both message types share, so a drift here
     * breaks every receiver at once. Every key is present and empty rather than
     * absent when unconfigured — that is what lets a receiver index it without
     * null checks.
     */
    public function testWebsiteInfoIsSpelledExactlyAsDocumented(): void
    {
        $payload = PayloadBuilder::formSubmission([
            'submission_id' => 's1',
            'page_url'      => 'https://www.example.com/contact',
            'page_query'    => '{"utm_source":"newsletter"}',
            'created_at'    => '2026-08-22 14:32:00',
        ]);

        self::assertSame([
            'name'   => 'Example Co',
            'url'    => 'https://www.example.com',
            // "www." stripped, so a fleet of sites keys by bare domain.
            'domain' => 'example.com',
            'id'     => 'site-42',
            'client' => [
                'first_name' => 'Ada',
                'last_name'  => 'Lovelace',
                'id'         => 'client-7',
            ],
            'page'   => [
                'url'   => 'https://www.example.com/contact',
                'query' => ['utm_source' => 'newsletter'],
            ],
        ], $payload['website_info']);
    }

    /**
     * An analytics report has no single page, so it carries no page block at
     * all — not an empty one.
     */
    public function testAnAnalyticsReportCarriesNoPageBlock(): void
    {
        $payload = PayloadBuilder::formSubmission([
            'submission_id' => 's1',
            'created_at'    => '2026-08-22 14:32:00',
        ]);

        // The submission path always has one; assert the report path's producer
        // omits it by building the same block without a page.
        self::assertArrayHasKey('page', $payload['website_info']);
        self::assertArrayNotHasKey('page', \Convermetry\Webhook\WebsiteInfo::current()->toArray());
    }

    /**
     * A submission that arrived with no correlation data (tracker disabled,
     * privacy signals, JavaScript blocked) still gets the full context
     * skeleton, so downstream systems always see the same keys.
     */
    public function testTheEmptyAnalyticsContextSkeletonIsUnchanged(): void
    {
        $payload = PayloadBuilder::formSubmission([
            'submission_id' => 's1',
            'created_at'    => '2026-08-22 14:32:00',
            'context'       => '',
        ]);

        self::assertSame([
            'session_id'         => '',
            'channel'            => '',
            'attribution'        => [
                'utm_source'    => '',
                'utm_medium'    => '',
                'utm_campaign'  => '',
                'utm_id'        => '',
                'utm_term'      => '',
                'utm_content'   => '',
                'click_id_type' => '',
            ],
            'entrance_referrer'  => '',
            'landing_page'       => ['url' => ''],
            'device'             => '',
            'pageview_count'     => 0,
            'session_started_at' => '',
            'recent_pages'       => [],
        ], $payload['analytics_context']);
    }

    public function testTheFormSubmissionBlockKeepsItsFieldNames(): void
    {
        $payload = PayloadBuilder::formSubmission([
            'submission_id'   => 's1',
            'conversion_id'   => 'c1',
            'provider'        => 'gravityforms',
            'form_name'       => 'Contact',
            'form_id'         => 'contact',
            'native_form_id'  => '7',
            'ip_address'      => '203.0.113.42',
            'submission_data' => '[{"id":"email","label":"Email address","value":"john@example.com"}]',
            'created_at'      => '2026-08-22 14:32:00',
        ]);

        self::assertSame([
            'submission_id', 'conversion_id', 'provider', 'form_name',
            'form_id', 'native_form_id', 'ip_address', 'submission_data',
        ], array_keys($payload['form_submission']));

        self::assertSame('203.0.113.42', $payload['form_submission']['ip_address']);
        self::assertSame(
            [['id' => 'email', 'label' => 'Email address', 'value' => 'john@example.com']],
            $payload['form_submission']['submission_data']
        );
    }
}
