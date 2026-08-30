<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Database\NewSubmission;
use Convermetry\Forms\SubmissionField;
use Convermetry\Forms\SubmissionFields;
use Convermetry\Notifications\SiteInfo;
use Convermetry\Settings\WebhookEndpoint;
use Convermetry\Support\KeyValuePairs;
use Convermetry\Tracking\Attribution;
use Convermetry\Webhook\ClientInfo;
use Convermetry\Webhook\DeliveryKind;
use Convermetry\Webhook\DeliveryLogEntry;
use Convermetry\Webhook\DeliveryState;
use Convermetry\Webhook\EndpointOutcome;
use Convermetry\Webhook\FrozenDelivery;
use Convermetry\Webhook\LogOutcome;
use Convermetry\Webhook\MessageType;
use Convermetry\Webhook\PageInfo;
use Convermetry\Webhook\RetryState;
use Convermetry\Webhook\TransportResult;
use Convermetry\Webhook\WebsiteInfo;
use PHPUnit\Framework\TestCase;

/**
 * The typed domain objects, at their two dangerous edges.
 *
 * These objects exist so the plugin stops indexing untyped arrays by string.
 * That is only safe if the two BOUNDARIES still produce and accept exactly
 * what they always did, and those boundaries are where the risk lives:
 *
 *  - SERIALIZATION. toArray() output is not an implementation detail. It is
 *    the webhook wire schema, an option row, a database column, or a public
 *    hook argument, and a renamed or reordered key is a break a receiver sees
 *    before anybody here does.
 *  - HYDRATION. fromStoredArray() reads values written by ANY earlier version
 *    of the plugin, and administrator-editable options that WP-CLI or a filter
 *    can have touched. It has to answer for a missing key, a wrong type, and a
 *    shape nobody has written since 1.2.0.
 *
 * What is deliberately not tested here: the constructors. A readonly object's
 * property assignment does not need a test, and asserting it would be noise
 * that has to be maintained alongside the real assertions.
 */
final class DomainObjectsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('home_url')->justReturn('https://www.example.com');
        Functions\when('get_bloginfo')->justReturn('Example Co');
        Functions\when('admin_url')->justReturn('https://www.example.com/wp-admin/admin.php');
        Functions\when('get_option')->justReturn([]);
        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('wp_json_encode')->alias(
            static fn($data, $options = 0, $depth = 512) => json_encode($data, $options, $depth)
        );
        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('apply_filters')->returnArg(2);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Wire values ──────────────────────────────────────────────────────────

    /**
     * These strings are on the wire, in the Activity Log's columns, and in the
     * REST API. Renaming a case must not silently rename them.
     */
    public function testTheEnumsCarryTheirPublishedWireValues(): void
    {
        self::assertSame('analytics_report', MessageType::AnalyticsReport->value);
        self::assertSame('form_submission', MessageType::FormSubmission->value);

        self::assertSame(
            ['scheduled', 'immediate', 'retry', 'test'],
            DeliveryKind::values()
        );

        self::assertSame('stored', LogOutcome::Stored->value);
        self::assertSame('suppressed', LogOutcome::Suppressed->value);
        self::assertSame('failed', LogOutcome::Failed->value);

        self::assertSame(
            ['not_sent', 'pending', 'partial', 'delivered', 'failed'],
            array_map(static fn(DeliveryState $s): string => $s->value, DeliveryState::cases())
        );
    }

    public function testUnrecognisedStoredValuesHydrateToNullRatherThanADefault(): void
    {
        self::assertNull(MessageType::tryFromMixed('email'));
        self::assertNull(MessageType::tryFromMixed(null));
        self::assertNull(DeliveryKind::tryFromMixed(['retry']));
        self::assertNull(DeliveryState::tryFromMixed(''));
        self::assertSame(DeliveryState::Partial, DeliveryState::tryFromMixed('partial'));
    }

    // ── website_info ─────────────────────────────────────────────────────────

    /**
     * The identity block every payload carries. Its key set, its nesting, and
     * the "always present, empty when unconfigured" promise are all published
     * schema — a receiver validating strictly would reject a changed shape.
     */
    public function testWebsiteInfoSerializesToThePublishedBlock(): void
    {
        $info = new WebsiteInfo(
            name: 'Example Co',
            url: 'https://www.example.com',
            domain: 'example.com',
            id: 'site-42',
            client: new ClientInfo('Ada', 'Lovelace', 'client-7'),
        );

        self::assertSame([
            'name'   => 'Example Co',
            'url'    => 'https://www.example.com',
            'domain' => 'example.com',
            'id'     => 'site-42',
            'client' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'id' => 'client-7'],
        ], $info->toArray());
    }

    /**
     * 'page' is present on form submissions and absent — not null, not empty —
     * on analytics reports.
     */
    public function testThePageBlockIsAppendedOnlyForASubmission(): void
    {
        $report = new WebsiteInfo('Example Co', 'https://e.test', 'e.test', '', new ClientInfo());
        self::assertArrayNotHasKey('page', $report->toArray());

        $submission = new WebsiteInfo(
            'Example Co',
            'https://e.test',
            'e.test',
            '',
            new ClientInfo(),
            new PageInfo('https://e.test/contact', ['utm_source' => 'newsletter']),
        );

        self::assertSame(
            ['url' => 'https://e.test/contact', 'query' => ['utm_source' => 'newsletter']],
            $submission->toArray()['page']
        );
        self::assertSame('page', array_key_last($submission->toArray()));
    }

    /**
     * The keying rule a fleet of sites reporting into one SaaS depends on.
     */
    public function testTheDomainDropsWwwAndLowercases(): void
    {
        Functions\when('home_url')->justReturn('https://WWW.Example.COM/blog');

        self::assertSame('example.com', WebsiteInfo::domain());
    }

    // ── Transport results ────────────────────────────────────────────────────

    /**
     * "Did it succeed?" and "what status came back?" are separate facts. Only
     * fromResponse() applies the 2xx rule, and a synthetic failure keeps code
     * 0 and its own message.
     */
    public function testTransportResultsDistinguishSuccessFromStatus(): void
    {
        $ok = TransportResult::fromResponse(204, 'No Content', '');
        self::assertTrue($ok->ok);
        self::assertSame(204, $ok->code);
        self::assertSame('Delivered', $ok->message, 'a 2xx reports Convermetry\'s own wording');

        $refused = TransportResult::fromResponse(500, 'Internal Server Error', 'oops');
        self::assertFalse($refused->ok);
        self::assertSame('Internal Server Error', $refused->message);

        $never = TransportResult::failure('Payload could not be JSON-encoded');
        self::assertFalse($never->ok);
        self::assertSame(0, $never->code);
        self::assertSame('', $never->body);
    }

    public function testTheResponseBodyIsDecodedOnlyWhenItIsJson(): void
    {
        // json_validate() is a real PHP 8.3 internal and is used unstubbed.
        self::assertNull(TransportResult::fromResponse(204, '', '')->decodedBody());
        self::assertSame(['id' => 9], TransportResult::fromResponse(200, '', '{"id":9}')->decodedBody());
        self::assertSame('<html>', TransportResult::fromResponse(500, 'x', '<html>')->decodedBody());
    }

    /**
     * The "Send test" buttons report whether the endpoint answered and what it
     * said — never what it sent back, which is unbounded and unescaped.
     */
    public function testTheTestSummaryOmitsTheResponseBody(): void
    {
        $summary = TransportResult::fromResponse(200, 'OK', 'echo: visitor@example.com')->toTestSummary();

        self::assertSame(['ok', 'code', 'message'], array_keys($summary));
        self::assertStringNotContainsString('visitor@example.com', (string) json_encode($summary));
    }

    // ── Activity Log entries ─────────────────────────────────────────────────

    /**
     * A transport error produces no HTTP response at all, so the failure
     * message is stored as {"error": "..."} — that is what lets the UI and the
     * REST API tell "endpoint said no" from "endpoint unreachable".
     */
    public function testATransportErrorIsStoredAsAJsonErrorObject(): void
    {
        $entry = new DeliveryLogEntry(
            result: TransportResult::failure('cURL error 28'),
            endpointUrl: 'https://e.test/hook',
            endpointLabel: 'Prod',
            deliveryId: 'd1',
            messageType: MessageType::FormSubmission,
            kind: DeliveryKind::Immediate,
        );

        self::assertSame('{"error":"cURL error 28"}', $entry->responseData());
    }

    public function testARealResponseBodyIsStoredVerbatim(): void
    {
        $entry = new DeliveryLogEntry(
            result: TransportResult::fromResponse(422, 'Unprocessable', '{"reason":"bad email"}'),
            endpointUrl: 'https://e.test/hook',
            endpointLabel: '',
            deliveryId: 'd1',
            messageType: MessageType::FormSubmission,
            kind: DeliveryKind::Retry,
        );

        self::assertSame('{"reason":"bad email"}', $entry->responseData());
    }

    // ── Frozen analytics deliveries ──────────────────────────────────────────

    /**
     * An empty body is not an empty payload — it means the delivery could not
     * be built, and sending it could earn a 2xx that advances the window
     * marker past data nobody received.
     */
    public function testAnUnbuildableDeliveryIsNotSendableAndSaysWhy(): void
    {
        $failed = new FrozenDelivery(
            windowStart: 100,
            windowEnd: 200,
            deliveryId: 'd1',
            body: '',
            url: 'https://e.test/hook',
            headers: [],
            failureReason: FrozenDelivery::REPORT_QUERY_FAILED,
        );

        self::assertFalse($failed->isSendable());
        self::assertSame('Report data could not be generated', $failed->failureMessage('Report data could not be generated'));

        $unencodable = new FrozenDelivery(100, 200, 'd1', '', 'https://e.test/hook', []);
        self::assertSame('Payload could not be JSON-encoded', $unencodable->failureMessage('irrelevant'));

        $good = new FrozenDelivery(100, 200, 'd1', '{"a":1}', 'https://e.test/hook', []);
        self::assertTrue($good->isSendable());
    }

    /**
     * The request is attached after the body freezes — composition needs the
     * delivery's id and window — and attaching it must not disturb the frozen
     * bytes or the id every attempt re-sends them under.
     */
    public function testAttachingTheRequestPreservesTheFrozenBytesAndId(): void
    {
        $frozen = (new FrozenDelivery(100, 200, 'd1', '{"a":1}', '', [], 'report_query_failed'))
            ->withRequest('https://e.test/hook?src=global', ['X-Tier' => 'gold']);

        self::assertSame('{"a":1}', $frozen->body);
        self::assertSame('d1', $frozen->deliveryId);
        self::assertSame(100, $frozen->windowStart);
        self::assertSame('https://e.test/hook?src=global', $frozen->url);
        self::assertSame(['X-Tier' => 'gold'], $frozen->headers);
        self::assertSame('report_query_failed', $frozen->failureReason);
    }

    // ── Retry state (read back off disk) ─────────────────────────────────────

    public function testRetryStateSurvivesARoundTripThroughTheOption(): void
    {
        $state = RetryState::forDelivery(
            new FrozenDelivery(100, 200, 'd1', '{"a":1}', 'https://e.test/hook?x=1', ['X-Tier' => 'gold']),
            'https://e.test/hook',
            attempt: 3,
            scheduledFor: 1780000000,
            exhausted: false,
            frozenAt: 1770000000,
        );

        $reread = RetryState::fromStoredArray($state->toArray());

        self::assertEquals($state, $reread);
        self::assertSame(
            ['url', 'attempt', 'scheduled_for', 'window_start', 'window_end',
             'delivery_id', 'body', 'request_url', 'headers', 'exhausted', 'frozen_at'],
            array_keys($state->toArray())
        );
    }

    /**
     * State written before the frozen request columns existed. It has to come
     * back usable rather than blowing up — the frozen BODY is the part that
     * cannot be rebuilt, and it is present.
     */
    public function testStateFromAnOlderVersionHydratesWithoutItsMissingColumns(): void
    {
        $state = RetryState::fromStoredArray([
            'url'           => 'https://e.test/hook',
            'attempt'       => 2,
            'scheduled_for' => 1760000000,
            'delivery_id'   => 'abc',
            'body'          => '{"a":1}',
            'exhausted'     => false,
            'frozen_at'     => 1750000000,
        ]);

        self::assertTrue($state->hasFrozenBody());
        self::assertSame('', $state->requestUrl, 'no frozen URL was ever stored');
        self::assertSame([], $state->headers);
        self::assertSame(0, $state->windowStart, 'the caller substitutes a window when this is 0');
    }

    public function testJunkInAStoredStateIsCoercedRatherThanTrusted(): void
    {
        $state = RetryState::fromStoredArray([
            'url'     => ['not', 'a', 'url'],
            'attempt' => '4',
            'body'    => null,
            'headers' => ['X-Ok' => 'yes', 7 => 'dropped: numeric key', 'X-Bad' => ['nested']],
        ]);

        self::assertSame('', $state->url);
        self::assertSame(4, $state->attempt);
        self::assertSame('', $state->body);
        self::assertFalse($state->hasFrozenBody());
        self::assertSame(['X-Ok' => 'yes'], $state->headers);
    }

    /**
     * The Webhooks page shows pending retries. The frozen body can be tens of
     * kilobytes, and no UI needs it, its id, or its headers.
     */
    public function testTheSummaryFormDropsTheBodyIdAndHeaders(): void
    {
        $summary = RetryState::forDelivery(
            new FrozenDelivery(100, 200, 'd1', str_repeat('x', 50000), 'https://e.test/h', ['A' => 'b']),
            'https://e.test/hook',
            1,
            1780000000,
            false,
            1770000000,
        )->toSummaryArray();

        self::assertArrayNotHasKey('body', $summary);
        self::assertArrayNotHasKey('delivery_id', $summary);
        self::assertArrayNotHasKey('headers', $summary);
        self::assertSame('https://e.test/hook', $summary['url']);
        self::assertSame(1, $summary['attempt']);
    }

    // ── Per-endpoint outcomes (a JSON column) ────────────────────────────────

    public function testEndpointOutcomesSurviveARoundTripThroughDeliveryJson(): void
    {
        $outcome = new EndpointOutcome(
            url: 'https://a.example/hook',
            label: 'Prod',
            ok: true,
            code: 200,
            attempt: 2,
            queued: false,
            at: '2026-08-23 10:00:00',
        );

        self::assertEquals($outcome, EndpointOutcome::fromStoredArray($outcome->toArray()));
        self::assertSame(
            ['url', 'label', 'ok', 'code', 'attempt', 'queued', 'at'],
            array_keys($outcome->toArray())
        );
    }

    public function testAnOutcomeRowMissingEveryKeyHydratesToAnEmptyFailure(): void
    {
        $outcome = EndpointOutcome::fromStoredArray([]);

        self::assertSame('', $outcome->url);
        self::assertFalse($outcome->ok);
        self::assertFalse($outcome->queued);
        self::assertSame(0, $outcome->code);
    }

    // ── Submission fields ────────────────────────────────────────────────────

    /**
     * The list format exists because the historical map keyed by LABEL, so two
     * fields called "Name" collapsed into one. Nothing in the collection may
     * reintroduce that.
     */
    public function testDuplicateLabelsStayDistinctFields(): void
    {
        $fields = SubmissionFields::parse([
            ['id' => 'first', 'label' => 'Name', 'value' => 'Ada'],
            ['id' => 'last',  'label' => 'Name', 'value' => 'Lovelace'],
        ]);

        self::assertCount(2, $fields);
        self::assertSame('Ada', $fields->byId('first')?->displayValue());
        self::assertSame('Lovelace', $fields->byId('last')?->displayValue());
    }

    public function testTheCollectionIsIterableAndCountable(): void
    {
        $fields = SubmissionFields::parse(['email' => 'a@b.com', 'phone' => '555']);

        $ids = [];
        foreach ($fields as $field) {
            $ids[] = $field->id;
        }

        self::assertSame(['email', 'phone'], $ids);
        self::assertSame(2, count($fields));
        self::assertFalse($fields->isEmpty());
        self::assertTrue(SubmissionFields::parse([])->isEmpty());
    }

    public function testAFieldSerializesToTheSchemaTwoDescriptor(): void
    {
        $field = new SubmissionField('interests', 'Services of interest', ['Tax planning', 'Retirement']);

        self::assertSame(
            ['id' => 'interests', 'label' => 'Services of interest', 'value' => ['Tax planning', 'Retirement']],
            $field->toArray()
        );
        self::assertSame('Tax planning, Retirement', $field->displayValue());
    }

    public function testByIdReturnsNullForAFieldTheFormNeverSent(): void
    {
        self::assertNull(SubmissionFields::parse(['email' => 'a@b.com'])->byId('phone'));
    }

    // ── Attribution ──────────────────────────────────────────────────────────

    public function testAttributionSerializesToTheSevenPublishedKeys(): void
    {
        $attribution = new Attribution('google', 'cpc', 'spring', 'c-1', 'shoes', 'hero', 'gclid');

        self::assertSame([
            'utm_source'    => 'google',
            'utm_medium'    => 'cpc',
            'utm_campaign'  => 'spring',
            'utm_id'        => 'c-1',
            'utm_term'      => 'shoes',
            'utm_content'   => 'hero',
            'click_id_type' => 'gclid',
        ], $attribution->toArray());
    }

    public function testAttributionHydratesFromAStoredContextAndNarrowsTypes(): void
    {
        $attribution = Attribution::fromArray([
            'utm_source'    => 'google',
            'utm_id'        => 42,
            'utm_campaign'  => ['not', 'scalar'],
            'click_id_type' => 'gclid',
        ]);

        self::assertSame('google', $attribution->utmSource);
        self::assertSame('42', $attribution->utmId, 'a numeric id from JSON becomes a string');
        self::assertSame('', $attribution->utmCampaign, 'a non-scalar is dropped, not stringified');
        self::assertSame('', $attribution->utmMedium);
    }

    /**
     * A click id alone is not "tagged": an ad click is unambiguous and is
     * classified before the tagged/untagged question is ever asked.
     */
    public function testOnlyUtmValuesMakeAVisitTagged(): void
    {
        self::assertFalse((new Attribution())->isTagged());
        self::assertFalse((new Attribution(clickIdType: 'gclid'))->isTagged());
        self::assertTrue((new Attribution(utmMedium: 'cpc'))->isTagged());
        self::assertTrue((new Attribution(utmContent: 'hero'))->isTagged());
    }

    // ── Configured endpoints ─────────────────────────────────────────────────

    public function testAnEndpointRowWithoutAUrlIsNotAnEndpoint(): void
    {
        self::assertNull(WebhookEndpoint::fromStoredArray([]));
        self::assertNull(WebhookEndpoint::fromStoredArray(['url' => '   ', 'label' => 'Half-filled']));
    }

    public function testEndpointFlagsAreIndependentAndValuesAreTrimmed(): void
    {
        $endpoint = WebhookEndpoint::fromStoredArray([
            'url'       => '  https://e.test/hook  ',
            'label'     => '  Prod  ',
            'secret'    => '  s3cret  ',
            'analytics' => '1',
            'forms'     => '',
        ]);

        self::assertNotNull($endpoint);
        self::assertSame('https://e.test/hook', $endpoint->url);
        self::assertSame('Prod', $endpoint->label);
        self::assertSame('s3cret', $endpoint->secret);
        self::assertTrue($endpoint->analytics);
        self::assertFalse($endpoint->forms);
    }

    // ── Header / query pairs (arrays on purpose) ─────────────────────────────

    /**
     * A blank key is kept by normalize() — the admin pages render this list
     * back into their editors, and deleting a row somebody is still typing
     * into would be its own bug — and skipped by toMap(), which composes a
     * real request.
     */
    public function testABlankPairKeySurvivesNormalizationAndIsSkippedWhenComposing(): void
    {
        $pairs = KeyValuePairs::normalize([
            ['key' => ' X-Tier ', 'value' => 'gold'],
            ['key' => '', 'value' => 'orphan'],
            ['key' => 'X-Num', 'value' => 7],
            'not an array',
        ]);

        self::assertSame([
            ['key' => 'X-Tier', 'value' => 'gold'],
            ['key' => '', 'value' => 'orphan'],
            ['key' => 'X-Num', 'value' => '7'],
        ], $pairs);

        self::assertSame(['X-Tier' => 'gold', 'X-Num' => '7'], KeyValuePairs::toMap($pairs));
    }

    public function testALaterPairOverridesAnEarlierOneWithTheSameKey(): void
    {
        self::assertSame(
            ['X-Tier' => 'platinum'],
            KeyValuePairs::toMap(KeyValuePairs::normalize([
                ['key' => 'X-Tier', 'value' => 'gold'],
                ['key' => 'X-Tier', 'value' => 'platinum'],
            ]))
        );
    }

    public function testNonArrayStoredPairsAreEmpty(): void
    {
        self::assertSame([], KeyValuePairs::normalize(null));
        self::assertSame([], KeyValuePairs::normalize('nonsense'));
    }

    // ── Site info for notifications ──────────────────────────────────────────

    /**
     * A notification renders in a worker that may run long after the lead
     * arrived, so the snapshot's site name wins — but a blank snapshot name
     * must not blank the email's heading.
     */
    public function testTheSnapshotSiteNameWinsUnlessItIsBlank(): void
    {
        $live = SiteInfo::current();

        self::assertSame('Example Co', $live->siteName);
        self::assertSame('Renamed Ltd', $live->withName('Renamed Ltd')->siteName);
        self::assertSame($live, $live->withName(''), 'a blank name changes nothing at all');
        self::assertSame($live->adminUrl, $live->withName('Renamed Ltd')->adminUrl);
    }

    // ── The submission record ────────────────────────────────────────────────

    /**
     * The six denormalized columns are copies of values inside the context.
     * Deriving them here is what stops the copy and the original from being
     * written out of step.
     */
    public function testTheDenormalizedColumnsAreDerivedFromTheContext(): void
    {
        $record = NewSubmission::fromContext(
            submissionId: 's1',
            conversionId: 'c1',
            sessionId: 'sess',
            provider: 'gravityforms',
            formKey: 'gravityforms:7',
            formName: 'Contact',
            nativeFormId: '7',
            formId: 'contact',
            pageUrl: 'https://e.test/contact',
            ipAddress: '',
            pageQuery: [],
            fields: [['id' => 'email', 'label' => 'Email', 'value' => 'a@b.com']],
            context: [
                'channel'      => 'Paid Search',
                'attribution'  => ['utm_campaign' => 'spring', 'utm_source' => 'google', 'utm_id' => 42],
                'landing_page' => ['url' => 'https://e.test/'],
            ],
            runtime: ['query' => [], 'headers' => []],
        );

        self::assertSame('Paid Search', $record->channel);
        self::assertSame('spring', $record->utmCampaign);
        self::assertSame('google', $record->utmSource);
        self::assertSame('42', $record->utmId);
        self::assertSame('https://e.test/', $record->landingPage);
        self::assertSame('', $record->utmMedium, 'a value the context never carried is empty, not missing');
    }

    /**
     * A submission recorded with the tracker disabled has no attribution at
     * all. Every derived column must still be a string: the backfill worker
     * selects rows by NULL-ness, and a null here would make the row eligible
     * forever.
     */
    public function testAContextWithNoAttributionStillYieldsStringColumns(): void
    {
        $record = NewSubmission::fromContext(
            's1', 'c1', '', 'custom', 'custom:x', 'X', '', 'x',
            '', '', [], [], [], ['query' => [], 'headers' => []],
        );

        self::assertSame('', $record->channel);
        self::assertSame('', $record->utmCampaign);
        self::assertSame('', $record->landingPage);
    }
}
