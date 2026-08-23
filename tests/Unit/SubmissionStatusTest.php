<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Admin\SubmissionsPage;
use Convermetry\Database\FormSubmissions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Delivery-state classification and derived-column extraction for the
 * Submissions page.
 *
 * The states these rules produce are what an admin reads off the list, so the
 * two that matter most are the ones that are easy to get subtly wrong:
 *
 *  - "not sent" must stay NEUTRAL. On a site with no webhook endpoint — the
 *    free-plugin default — every submission lands here, and classifying that
 *    as "failed" would tell every such user their leads are broken.
 *  - A submission is judged against the endpoints it was ACTUALLY attempted
 *    against, never the endpoints configured right now. Adding a third
 *    endpoint today must not retroactively downgrade last month's successful
 *    two-endpoint delivery to "partial".
 */
final class SubmissionStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Endpoint labels are cosmetic here; no endpoints are configured, so
        // Options::endpointLabel() resolves to '' for every URL.
        Functions\when('get_option')->justReturn([]);
        Functions\when('apply_filters')->returnArg(2);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param array<int, array<string, mixed>> $logRows
     * @param array<int, array<string, mixed>> $queueRows
     * @return array<string, mixed>
     */
    private function classify(array $logRows, array $queueRows, int $endpointCount): array
    {
        $method = new ReflectionMethod(SubmissionsPage::class, 'deliveryStatus');

        return (array) $method->invoke(null, $logRows, $queueRows, $endpointCount);
    }

    /**
     * @param string $endpoint
     * @param int    $ok
     * @return array<string, mixed>
     */
    private function logRow(string $endpoint, int $ok, int $code = 200): array
    {
        return ['endpoint_url' => $endpoint, 'ok' => $ok, 'attempt' => 1, 'code' => $code];
    }

    // ── not_sent ─────────────────────────────────────────────────────────────

    /**
     * The free-plugin default: submissions are recorded, nothing is delivered,
     * and that is not a failure.
     */
    public function testNoEndpointsAndNoAttemptsIsNotSentNeverFailed(): void
    {
        $status = $this->classify([], [], 0);

        self::assertSame('not_sent', $status['state']);
        self::assertNotSame('failed', $status['state']);
        self::assertStringContainsString('no form webhook', $status['label']);
        self::assertSame([], $status['endpoints']);
    }

    /**
     * Endpoints exist but this submission has not been attempted yet — still
     * "not sent", but without the "no webhook configured" explanation.
     */
    public function testConfiguredEndpointsWithNoAttemptsOmitsTheNoWebhookWording(): void
    {
        $status = $this->classify([], [], 2);

        self::assertSame('not_sent', $status['state']);
        self::assertStringNotContainsString('no form webhook', $status['label']);
    }

    // ── delivered / partial / failed ─────────────────────────────────────────

    public function testSingleSuccessfulEndpointIsDelivered(): void
    {
        $status = $this->classify([$this->logRow('https://a.example/hook', 1)], [], 1);

        self::assertSame('delivered', $status['state']);
        self::assertSame('Delivered', $status['label']);
        self::assertCount(1, $status['endpoints']);
        self::assertTrue($status['endpoints'][0]['ok']);
    }

    public function testOneOfTwoEndpointsSucceededIsPartial(): void
    {
        $status = $this->classify([
            $this->logRow('https://a.example/hook', 1),
            $this->logRow('https://b.example/hook', 0, 500),
        ], [], 2);

        self::assertSame('partial', $status['state']);
        self::assertSame('Partial (1/2)', $status['label']);
    }

    public function testEveryEndpointFailedWithNothingQueuedIsFailed(): void
    {
        $status = $this->classify([
            $this->logRow('https://a.example/hook', 0, 500),
            $this->logRow('https://b.example/hook', 0, 0),
        ], [], 2);

        self::assertSame('failed', $status['state']);
        self::assertSame('Failed', $status['label']);
    }

    /**
     * The regression this rule exists to prevent: a submission delivered
     * successfully to the two endpoints that existed at the time must keep
     * reading "Delivered" after a third endpoint is added.
     */
    public function testAddingAnEndpointLaterDoesNotDowngradeAnOldDelivery(): void
    {
        $logRows = [
            $this->logRow('https://a.example/hook', 1),
            $this->logRow('https://b.example/hook', 1),
        ];

        // Three endpoints are configured today; only two were ever attempted.
        $status = $this->classify($logRows, [], 3);

        self::assertSame('delivered', $status['state']);
        self::assertSame('Delivered (2)', $status['label']);
    }

    // ── pending ──────────────────────────────────────────────────────────────

    /**
     * A queue row means the delivery is still in flight, so an earlier failed
     * attempt is not yet the outcome.
     */
    public function testQueuedDeliveryOutranksItsOwnFailedAttempts(): void
    {
        $status = $this->classify(
            [$this->logRow('https://a.example/hook', 0, 500)],
            [['endpoint_url' => 'https://a.example/hook', 'status' => 'pending', 'attempt' => 2, 'next_attempt_at' => '2026-08-23 10:00:00']],
            1
        );

        self::assertSame('pending', $status['state']);
        self::assertSame('Queued · retry 2', $status['label']);
        self::assertCount(1, $status['endpoints']);
        self::assertTrue($status['endpoints'][0]['queued']);
        self::assertFalse($status['endpoints'][0]['ok']);
    }

    /**
     * A submission queued but never attempted reports no retry count.
     */
    public function testFreshlyQueuedDeliveryHasNoRetryCount(): void
    {
        $status = $this->classify(
            [],
            [['endpoint_url' => 'https://a.example/hook', 'status' => 'pending', 'attempt' => 0]],
            1
        );

        self::assertSame('pending', $status['state']);
        self::assertSame('Queued', $status['label']);
    }

    /**
     * One endpoint acknowledged, a second is still retrying: the submission is
     * pending overall, and the detail panel still shows the endpoint that
     * succeeded as succeeded.
     */
    public function testPendingWinsOverallButPerEndpointResultsAreKept(): void
    {
        $status = $this->classify(
            [$this->logRow('https://a.example/hook', 1)],
            [['endpoint_url' => 'https://b.example/hook', 'status' => 'pending', 'attempt' => 1]],
            2
        );

        self::assertSame('pending', $status['state']);
        self::assertCount(2, $status['endpoints']);

        $byUrl = array_column($status['endpoints'], null, 'url');
        self::assertTrue($byUrl['https://a.example/hook']['ok']);
        self::assertTrue($byUrl['https://b.example/hook']['queued']);
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
