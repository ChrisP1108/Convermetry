<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Database\FormSubmissions;
use Convermetry\Webhook\DeliveryState;
use Convermetry\Webhook\EndpointOutcome;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Delivery-state classification and derived-column extraction for the
 * Submissions page.
 *
 * The states these rules produce are what an admin reads off the list, and the
 * ones that are easy to get subtly wrong:
 *
 *  - "not sent" must stay NEUTRAL. On a site with no webhook endpoint — the
 *    free-plugin default — every submission lands here, and classifying that
 *    as "failed" would tell every such user their leads are broken.
 *  - A submission is judged against the endpoints it was ACTUALLY attempted
 *    against, never the endpoints configured right now. Adding a third
 *    endpoint today must not retroactively downgrade last month's successful
 *    two-endpoint delivery to "partial".
 *  - The LAST attempt against an endpoint is that endpoint's verdict. The
 *    original implementation took MAX(success) and MAX(response_code) as
 *    independent aggregates, so a 500 followed by a successful 200 retry read
 *    as "Delivered (500)".
 */
final class SubmissionStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('apply_filters')->returnArg(2);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Builds the per-endpoint outcomes and classifies them, exactly as
     * FormSubmissions::refreshDeliveryState() does after a real delivery.
     *
     * @param array<int, array<string, mixed>> $logRows   Oldest first.
     * @param array<int, array<string, mixed>> $queueRows
     * @return array{state: DeliveryState, endpoints: list<EndpointOutcome>}
     */
    private function classify(array $logRows, array $queueRows): array
    {
        $endpoints = FormSubmissions::buildEndpointOutcomes($logRows, $queueRows);

        return ['state' => FormSubmissions::classifyDelivery($endpoints), 'endpoints' => $endpoints];
    }

    /**
     * One delivery-log row as stored: success is 0/1, not a boolean.
     *
     * @return array<string, mixed>
     */
    private function logRow(string $endpoint, int $success, int $code = 200, int $attempt = 1): array
    {
        return [
            'endpoint_url'   => $endpoint,
            'endpoint_label' => '',
            'success'        => $success,
            'response_code'  => $code,
            'attempt'        => $attempt,
            'created_at'     => '2026-08-23 10:00:00',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queueRow(string $endpoint, int $attempt = 0): array
    {
        return [
            'endpoint_url'    => $endpoint,
            'status'          => 'pending',
            'attempt'         => $attempt,
            'next_attempt_at' => '2026-08-23 10:05:00',
        ];
    }

    // ── not_sent ─────────────────────────────────────────────────────────────

    /**
     * The free-plugin default: submissions are recorded, nothing is delivered,
     * and that is not a failure.
     */
    public function testNothingAttemptedIsNotSentNeverFailed(): void
    {
        $status = $this->classify([], []);

        self::assertSame(DeliveryState::NotSent, $status['state']);
        self::assertNotSame(DeliveryState::Failed, $status['state']);
        self::assertSame([], $status['endpoints']);
    }

    // ── delivered / partial / failed ─────────────────────────────────────────

    public function testSingleSuccessfulEndpointIsDelivered(): void
    {
        $status = $this->classify([$this->logRow('https://a.example/hook', 1)], []);

        self::assertSame(DeliveryState::Delivered, $status['state']);
        self::assertCount(1, $status['endpoints']);
        self::assertTrue($status['endpoints'][0]->ok);
    }

    public function testOneOfTwoEndpointsSucceededIsPartial(): void
    {
        $status = $this->classify([
            $this->logRow('https://a.example/hook', 1),
            $this->logRow('https://b.example/hook', 0, 500),
        ], []);

        self::assertSame(DeliveryState::Partial, $status['state']);
    }

    public function testEveryEndpointFailedWithNothingQueuedIsFailed(): void
    {
        $status = $this->classify([
            $this->logRow('https://a.example/hook', 0, 500),
            $this->logRow('https://b.example/hook', 0, 0),
        ], []);

        self::assertSame(DeliveryState::Failed, $status['state']);
    }

    /**
     * A submission delivered successfully to the two endpoints that existed at
     * the time must keep reading "delivered" after a third is added — the
     * classifier is deliberately given no knowledge of current configuration.
     */
    public function testAddingAnEndpointLaterDoesNotDowngradeAnOldDelivery(): void
    {
        $status = $this->classify([
            $this->logRow('https://a.example/hook', 1),
            $this->logRow('https://b.example/hook', 1),
        ], []);

        self::assertSame(DeliveryState::Delivered, $status['state']);
        self::assertCount(2, $status['endpoints']);
    }

    // ── Latest attempt wins (the "Delivered (500)" regression) ───────────────

    /**
     * The exact sequence that used to render "Delivered (500)": a failed 500
     * and a successful 200 retry were max-ed into a success paired with the
     * failure's status code.
     */
    public function testSuccessfulRetryReplacesTheEarlierFailureAndItsCode(): void
    {
        $status = $this->classify([
            $this->logRow('https://a.example/hook', 0, 500, 1),
            $this->logRow('https://a.example/hook', 1, 200, 2),
        ], []);

        self::assertSame(DeliveryState::Delivered, $status['state']);
        self::assertCount(1, $status['endpoints'], 'retries collapse into one entry per endpoint');
        self::assertTrue($status['endpoints'][0]->ok);
        self::assertSame(200, $status['endpoints'][0]->code, 'the code must come from the winning attempt');
        self::assertSame(2, $status['endpoints'][0]->attempt);
    }

    /**
     * The mirror case: a later failure must not be masked by an earlier
     * success, or a broken endpoint would read as delivered forever.
     */
    public function testLaterFailureOverridesAnEarlierSuccess(): void
    {
        $status = $this->classify([
            $this->logRow('https://a.example/hook', 1, 200, 1),
            $this->logRow('https://a.example/hook', 0, 503, 2),
        ], []);

        self::assertSame(DeliveryState::Failed, $status['state']);
        self::assertSame(503, $status['endpoints'][0]->code);
    }

    // ── pending ──────────────────────────────────────────────────────────────

    /**
     * A queue row means the delivery is still in flight, so an earlier failed
     * attempt is not yet the verdict.
     */
    public function testQueuedDeliveryOutranksItsOwnFailedAttempts(): void
    {
        $status = $this->classify(
            [$this->logRow('https://a.example/hook', 0, 500)],
            [$this->queueRow('https://a.example/hook', 2)]
        );

        self::assertSame(DeliveryState::Pending, $status['state']);
        self::assertCount(1, $status['endpoints']);
        self::assertTrue($status['endpoints'][0]->queued);
        self::assertFalse($status['endpoints'][0]->ok);
    }

    /**
     * One endpoint acknowledged, a second still retrying: pending overall,
     * while the detail panel still shows the endpoint that succeeded.
     */
    public function testPendingWinsOverallButPerEndpointResultsAreKept(): void
    {
        $status = $this->classify(
            [$this->logRow('https://a.example/hook', 1)],
            [$this->queueRow('https://b.example/hook', 1)]
        );

        self::assertSame(DeliveryState::Pending, $status['state']);
        self::assertCount(2, $status['endpoints']);

        $byUrl = array_column(
            array_map(static fn(EndpointOutcome $e): array => $e->toArray(), $status['endpoints']),
            null,
            'url'
        );
        self::assertTrue($byUrl['https://a.example/hook']['ok']);
        self::assertTrue($byUrl['https://b.example/hook']['queued']);
    }

    // ── Unicode search terms ─────────────────────────────────────────────────

    private function escapedTerm(string $term): ?string
    {
        $method = new ReflectionMethod(FormSubmissions::class, 'jsonEscapedTerm');

        /** @var string|null $result */
        $result = $method->invoke(null, $term);

        return $result;
    }

    /**
     * Rows written before the encoder switched to JSON_UNESCAPED_UNICODE hold
     * "José" as "José", so the search has to look for that spelling too.
     */
    public function testNonAsciiSearchTermGetsItsEscapedSpelling(): void
    {
        // Single-quoted: these are the literal backslash-u sequences PHP's
        // default JSON encoder wrote into the column, not the characters.
        self::assertSame('Jos\\u00e9', $this->escapedTerm('José'));
        self::assertSame('\\u00d1u\\u00f1ez', $this->escapedTerm('Ñuñez'));
    }

    /**
     * An ASCII term needs no second LIKE — returning one would double the
     * search cost of every ordinary query for nothing.
     */
    public function testAsciiSearchTermNeedsNoSecondForm(): void
    {
        self::assertNull($this->escapedTerm('jane@example.com'));
        self::assertNull($this->escapedTerm('Consultation Request'));
    }

    // ── Derived columns ──────────────────────────────────────────────────────

    /**
     * @return array<string, string>
     */
    private function derive(string $contextJson): array
    {
        $method = new ReflectionMethod(FormSubmissions::class, 'deriveColumns');

        return (array) $method->invoke(null, $contextJson);
    }

    public function testDerivedColumnsAreReadFromTheStoredContext(): void
    {
        $derived = $this->derive((string) json_encode([
            'channel'     => 'Paid Search',
            'attribution' => ['utm_source' => 'google', 'utm_campaign' => 'Phoenix PPC'],
        ]));

        self::assertSame('Paid Search', $derived['channel']);
        self::assertSame('Phoenix PPC', $derived['utm_campaign']);
    }

    /**
     * The backfill selects rows WHERE channel IS NULL, so it must always write
     * a string — returning null for a context without a channel would leave
     * the row selectable forever and the backfill would never terminate.
     */
    public function testMissingValuesBecomeEmptyStringsNotNull(): void
    {
        foreach (['', 'not json at all', '{}', '[]', '{"channel":null}'] as $context) {
            $derived = $this->derive($context);

            self::assertSame('', $derived['channel'], "context: {$context}");
            self::assertSame('', $derived['utm_campaign'], "context: {$context}");
        }
    }

    /**
     * The columns are VARCHAR(32) and VARCHAR(191); a filter that rewrote the
     * channel to something longer must not produce a value MySQL would
     * silently truncate differently than the filter dropdown expects.
     */
    public function testOversizedValuesAreTruncatedToTheColumnWidths(): void
    {
        $derived = $this->derive((string) json_encode([
            'channel'     => str_repeat('x', 100),
            'attribution' => ['utm_campaign' => str_repeat('y', 400)],
        ]));

        self::assertSame(32, mb_strlen($derived['channel']));
        self::assertSame(191, mb_strlen($derived['utm_campaign']));
    }

    public function testNonScalarValuesAreRejected(): void
    {
        $derived = $this->derive((string) json_encode([
            'channel'     => ['Paid Search'],
            'attribution' => ['utm_campaign' => ['a', 'b']],
        ]));

        self::assertSame('', $derived['channel']);
        self::assertSame('', $derived['utm_campaign']);
    }
}
