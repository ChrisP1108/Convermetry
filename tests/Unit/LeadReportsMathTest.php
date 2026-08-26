<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Convermetry\Analytics\FormEngagementReport;
use Convermetry\Analytics\LeadReports;
use PHPUnit\Framework\TestCase;

/**
 * Lead and engagement arithmetic.
 *
 * The numbers these produce are the ones a marketer reports to a client, so the
 * edge cases matter more than the happy path:
 *
 *  - A zero denominator must not become "0%", which reads as "they all failed"
 *    rather than "there were none".
 *  - A rate must not silently exceed 100% where that is impossible, and must be
 *    ALLOWED to where it is meaningful.
 *  - Time to conversion is right-skewed, so the median is the honest summary
 *    and the mean is not.
 */
final class LeadReportsMathTest extends TestCase
{
    // ── Qualification and win rates ──────────────────────────────────────────

    public function testQualificationAndWinRates(): void
    {
        // 180 leads, 102 qualified, 24 won.
        self::assertSame(56.7, LeadReports::rate(102, 180));
        self::assertSame(13.3, LeadReports::rate(24, 180));
    }

    /**
     * A channel that produced no leads has no qualification rate. Callers
     * render '—'; returning 0.0 here is only the sentinel they check.
     */
    public function testZeroDenominatorsDoNotDivide(): void
    {
        self::assertSame(0.0, LeadReports::rate(0, 0));
        self::assertSame(0.0, LeadReports::rate(5, 0));
    }

    /**
     * Every qualified lead being won is 100%, and nothing can exceed it: a
     * subset cannot be larger than the set it came from, so a value above 100
     * would be a bug rather than a signal.
     */
    public function testLeadRatesAreCappedAtOneHundred(): void
    {
        self::assertSame(100.0, LeadReports::rate(180, 180));
        self::assertSame(100.0, LeadReports::rate(200, 180));
    }

    public function testEveryDimensionMapsToARealColumn(): void
    {
        $ddl = (string) file_get_contents(__DIR__ . '/../../src/Database/FormSubmissions.php');

        foreach (LeadReports::DIMENSIONS as $key => $column) {
            self::assertMatchesRegularExpression(
                '~^\s+' . preg_quote($column, '~') . '\s+~m',
                $ddl,
                "Dimension '{$key}' groups by '{$column}', which is not a column on the submissions table. "
                . 'Grouping by a JSON path instead would make every lead report a full scan.'
            );
        }
    }

    // ── Time to conversion ───────────────────────────────────────────────────

    /**
     * @dataProvider lagBuckets
     */
    public function testLagsFallInTheRightBucket(int $seconds, string $expected): void
    {
        self::assertSame($expected, LeadReports::bucketFor($seconds));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function lagBuckets(): array
    {
        return [
            'instant'            => [0, 'Under 5 minutes'],
            'four minutes'       => [240, 'Under 5 minutes'],
            'exactly five'       => [300, '5–30 minutes'],
            'twenty minutes'     => [1200, '5–30 minutes'],
            'exactly thirty'     => [1800, '30 minutes–24 hours'],
            'six hours'          => [21600, '30 minutes–24 hours'],
            'exactly a day'      => [86400, '1–7 days'],
            'three days'         => [259200, '1–7 days'],
            'exactly a week'     => [604800, '7+ days'],
            'a month'            => [2592000, '7+ days'],
        ];
    }

    /**
     * Boundaries are exclusive at the top, so no lag falls in two buckets and
     * none falls in none.
     */
    public function testBucketBoundariesArePartition(): void
    {
        $seen = [];

        foreach ([0, 299, 300, 1799, 1800, 86399, 86400, 604799, 604800, 99999999] as $seconds) {
            $bucket = LeadReports::bucketFor($seconds);
            self::assertArrayHasKey($bucket, LeadReports::buckets());
            $seen[$bucket] = true;
        }

        self::assertCount(5, $seen, 'Every bucket should be reachable.');
    }

    public function testMedianOfAnOddCount(): void
    {
        self::assertSame(18, LeadReports::median([5, 18, 400]));
    }

    public function testMedianOfAnEvenCountAveragesTheMiddlePair(): void
    {
        self::assertSame(15, LeadReports::median([10, 10, 20, 400]));
    }

    public function testMedianOfNothingIsZero(): void
    {
        self::assertSame(0, LeadReports::median([]));
    }

    public function testMedianDoesNotDependOnInputOrder(): void
    {
        self::assertSame(
            LeadReports::median([400, 5, 18, 22, 9]),
            LeadReports::median([5, 9, 18, 22, 400])
        );
    }

    /**
     * The reason a median is used rather than a mean. One visitor who converted
     * after three weeks must not drag the reported figure past every real
     * experience of the site.
     */
    public function testTheMedianResistsAnExtremeOutlier(): void
    {
        $lags = [60, 90, 120, 150, 1814400]; // four quick, one after three weeks

        $median = LeadReports::median($lags);
        $mean   = (int) round(array_sum($lags) / count($lags));

        self::assertSame(120, $median);
        self::assertGreaterThan(300000, $mean, 'The mean is the number this avoids reporting.');
    }

    // ── Form engagement ──────────────────────────────────────────────────────

    public function testStartAndCompletionRates(): void
    {
        // 1,428 views → 682 started → 276 successful.
        self::assertSame(47.8, FormEngagementReport::rate(682, 1428));
        self::assertSame(40.5, FormEngagementReport::rate(276, 682));
    }

    public function testEngagementRatesHandleZeroDenominators(): void
    {
        self::assertSame(0.0, FormEngagementReport::rate(0, 0));
        self::assertSame(0.0, FormEngagementReport::rate(12, 0));
    }

    /**
     * Deliberately NOT capped, unlike the lead rates. Confirmed submissions
     * outnumbering observed starts is real and meaningful — it means visitors
     * are submitting with JavaScript blocked, so the browser-observed metrics
     * are undercounting. Clamping it to 100 would hide the one number that says
     * so.
     */
    public function testACompletionRateAboveOneHundredIsPreserved(): void
    {
        self::assertSame(
            150.0,
            FormEngagementReport::rate(30, 20),
            'Clamping this would hide that browser-observed starts are undercounting.'
        );
    }

    /**
     * The abandonment maturity period must match the tracker's session idle
     * window. Past that the visitor's session has rotated, so a success could
     * no longer be attributed to the same session as the start — and a longer
     * window would wait for a success that can never be matched.
     */
    public function testTheCompletionWindowMatchesTheSessionIdleWindow(): void
    {
        self::assertSame(30, FormEngagementReport::COMPLETION_WINDOW_MINUTES);

        $tracker = (string) file_get_contents(__DIR__ . '/../../assets/js/tracker.js');

        self::assertStringContainsString(
            'const SESSION_IDLE_MS = 30 * 60 * 1000;',
            $tracker,
            'The tracker session window changed without the abandonment window following it.'
        );
    }
}
