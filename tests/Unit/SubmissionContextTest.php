<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Analytics\SubmissionContext;
use Convermetry\Webhook\PayloadBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The shared analytics-context helper extracted from FormDeliveryQueue.
 *
 * Three consumers now depend on this producing the same shape — webhook
 * payload freezing, the Submissions detail panel, and notification email
 * rendering — so the pure tier is pinned here. enrich() itself needs $wpdb and
 * the report queries and is out of scope for this harness; what is testable is
 * the decision (needsEnrichment) and the shape (withDefaults), which is where
 * the drift risk actually lives.
 */
final class SubmissionContextTest extends TestCase
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

    /**
     * @dataProvider undecodableJson
     */
    public function testDecodeJsonReturnsAnArrayForAnythingUnusable(string $json): void
    {
        self::assertSame([], SubmissionContext::decodeJson($json));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function undecodableJson(): array
    {
        return [
            'empty'     => [''],
            'malformed' => ['{"a":'],
            'scalar'    => ['5'],
            'string'    => ['"hello"'],
            'null'      => ['null'],
        ];
    }

    public function testDecodeJsonReadsAnObject(): void
    {
        self::assertSame(['channel' => 'Email'], SubmissionContext::decodeJson('{"channel":"Email"}'));
    }

    public function testNeedsEnrichmentIsFalseWithoutASessionId(): void
    {
        self::assertFalse(SubmissionContext::needsEnrichment(['session_id' => '', 'context' => '{}']));
    }

    /**
     * 'pageview_count' is the idempotency sentinel — its presence is what
     * guarantees the two session queries run at most once per submission,
     * across every retry, every endpoint, and the admin detail panel.
     */
    public function testNeedsEnrichmentIsFalseOncePageviewCountIsPresent(): void
    {
        self::assertFalse(SubmissionContext::needsEnrichment([
            'session_id' => 'sess-1',
            'context'    => '{"pageview_count":0}',
        ]), 'A zero count is still an answer and must not be recomputed');
    }

    public function testNeedsEnrichmentIsTrueForAFreshContext(): void
    {
        self::assertTrue(SubmissionContext::needsEnrichment([
            'session_id' => 'sess-1',
            'context'    => '{"channel":"Paid Search"}',
        ]));
    }

    public function testMergeOverlaysTheSummaryAndKeepsUnrelatedKeys(): void
    {
        $merged = SubmissionContext::merge(
            ['channel' => 'Email', 'pageview_count' => 0],
            ['pageview_count' => 4, 'session_started_at' => '2026-08-22T14:20:11+00:00', 'recent_pages' => ['/a']]
        );

        self::assertSame('Email', $merged['channel']);
        self::assertSame(4, $merged['pageview_count']);
        self::assertSame(['/a'], $merged['recent_pages']);
    }

    /**
     * The email and the webhook payload must describe the same visit with the
     * same keys. Sharing PayloadBuilder's skeleton is what enforces that; this
     * test fails the moment the two shapes diverge.
     */
    public function testWithDefaultsFillsExactlyTheCanonicalContextKeys(): void
    {
        self::assertSame(
            array_keys(PayloadBuilder::emptyContext()),
            array_keys(SubmissionContext::withDefaults([]))
        );
    }

    /**
     * The fill is deep on purpose: a partial 'attribution' sub-array would
     * otherwise leave a consumer reading a missing index where the webhook
     * payload reads ''.
     */
    public function testWithDefaultsDeepFillsNestedAttribution(): void
    {
        $filled = SubmissionContext::withDefaults([
            'attribution' => ['utm_source' => 'google'],
        ]);

        self::assertSame('google', $filled['attribution']['utm_source']);
        self::assertSame('', $filled['attribution']['utm_term'], 'A missing nested key must be filled, not absent');
        self::assertSame(
            array_keys(PayloadBuilder::emptyContext()['attribution']),
            array_keys($filled['attribution'])
        );
    }

    public function testWithDefaultsDeepFillsLandingPage(): void
    {
        self::assertSame('', SubmissionContext::withDefaults([])['landing_page']['url']);
        self::assertSame(
            'https://example.com/a',
            SubmissionContext::withDefaults(['landing_page' => ['url' => 'https://example.com/a']])['landing_page']['url']
        );
    }

    /**
     * A stored context whose nested key holds a scalar (a hostile filter, a
     * corrupted row) must not produce a type error downstream.
     */
    public function testWithDefaultsReplacesAScalarWhereAnArrayIsExpected(): void
    {
        $filled = SubmissionContext::withDefaults(['attribution' => 'not-an-array']);

        self::assertIsArray($filled['attribution']);
        self::assertSame('', $filled['attribution']['utm_source']);
    }

    public function testWithDefaultsPreservesRealValues(): void
    {
        $filled = SubmissionContext::withDefaults([
            'channel'        => 'Paid Search',
            'pageview_count' => 4,
            'recent_pages'   => ['https://example.com/contact'],
        ]);

        self::assertSame('Paid Search', $filled['channel']);
        self::assertSame(4, $filled['pageview_count']);
        self::assertSame(['https://example.com/contact'], $filled['recent_pages']);
    }

    public function testOfDecodesAndDefaultsARowInOneStep(): void
    {
        $context = SubmissionContext::of(['context' => '{"channel":"Referral"}']);

        self::assertSame('Referral', $context['channel']);
        self::assertSame('', $context['device']);
        self::assertSame([], $context['recent_pages']);
    }

    public function testOfToleratesARowWithNoContextColumn(): void
    {
        self::assertSame(array_keys(PayloadBuilder::emptyContext()), array_keys(SubmissionContext::of([])));
    }
}
