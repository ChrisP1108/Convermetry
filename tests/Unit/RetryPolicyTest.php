<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Convermetry\Webhook\TransportResult;
use PHPUnit\Framework\TestCase;

/**
 * Which answers end a delivery, and how long a receiver may ask to be left
 * alone.
 *
 * Until 1.1.6 every non-2xx answer was retried on the full schedule, about 24.6
 * hours and six attempts: a 400, 410 or 422 that no retry can change cost five
 * more requests per lead and held the lead "pending" for a day, and a
 * Retry-After was ignored. The form queue and the analytics chain both ask
 * {@see TransportResult}, so they cannot disagree; the WordPress suite drives
 * both against a real receiver.
 */
final class RetryPolicyTest extends TestCase
{
    /**
     * @dataProvider answers
     */
    public function testWhetherAnAnswerIsFinal(int $code, bool $final): void
    {
        self::assertSame($final, TransportResult::fromResponse($code, 'x', '')->isFinal(), "HTTP {$code}");
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function answers(): array
    {
        return [
            '200 delivered'          => [200, false],
            '204 delivered'          => [204, false],
            '301 not followed'       => [301, false],
            '308 not followed'       => [308, false],
            '400 bad request'        => [400, true],
            '401 unauthorized'       => [401, true],
            '403 forbidden'          => [403, true],
            '404 not found'          => [404, true],
            '410 gone'               => [410, true],
            '422 unprocessable'      => [422, true],
            '408 request timeout'    => [408, false],
            '409 conflict'           => [409, false],
            '425 too early'          => [425, false],
            '429 too many requests'  => [429, false],
            '500 server error'       => [500, false],
            '503 unavailable'        => [503, false],
        ];
    }

    public function testATransportFailureIsNeverFinal(): void
    {
        self::assertFalse(TransportResult::failure('cURL error 28: Operation timed out')->isFinal());
    }

    /**
     * @dataProvider retryAfterHeaders
     */
    public function testRetryAfterIsReadAsSecondsOrADate(string $header, ?int $seconds): void
    {
        $now = (int) strtotime('Sat, 14 Nov 2026 12:00:00 GMT');

        self::assertSame($seconds, TransportResult::parseRetryAfter($header, $now));
    }

    /**
     * @return array<string, array{string, int|null}>
     */
    public static function retryAfterHeaders(): array
    {
        return [
            'absent'           => ['', null],
            'seconds'          => ['7200', 7200],
            'padded seconds'   => [' 120 ', 120],
            'a date'           => ['Sat, 14 Nov 2026 14:00:00 GMT', 7200],
            'an RFC 850 date'  => ['Saturday, 14-Nov-26 14:00:00 GMT', 7200],
            'an asctime date'  => ['Sat Nov 14 14:00:00 2026', 7200],
            'a date passed'    => ['Sat, 14 Nov 2026 11:00:00 GMT', 0],
            'over a day'       => ['604800', TransportResult::MAX_RETRY_AFTER],
            'a date next week' => ['Sat, 21 Nov 2026 12:00:00 GMT', TransportResult::MAX_RETRY_AFTER],
            'negative'         => ['-5', null],
            'garbage'          => ['soon', null],
        ];
    }

    public function testRetryAfterIsKeptOnlyFor429And503(): void
    {
        self::assertSame(7200, TransportResult::fromResponse(429, 'x', '', '7200')->retryAfter);
        self::assertSame(7200, TransportResult::fromResponse(503, 'x', '', '7200')->retryAfter);
        self::assertNull(TransportResult::fromResponse(500, 'x', '', '7200')->retryAfter);
        self::assertNull(TransportResult::fromResponse(200, 'x', '', '7200')->retryAfter);
    }

    /**
     * A floor, never a ceiling: asking for 30 seconds does not shorten the
     * schedule's five minutes, and asking for two hours lengthens it.
     */
    public function testRetryAfterOnlyEverLengthensTheWait(): void
    {
        self::assertSame(300, TransportResult::fromResponse(429, 'x', '', '30')->nextDelay(300));
        self::assertSame(7200, TransportResult::fromResponse(429, 'x', '', '7200')->nextDelay(300));
        self::assertSame(300, TransportResult::fromResponse(500, 'x', '')->nextDelay(300));
        self::assertSame(300, TransportResult::failure('timeout')->nextDelay(300));
    }
}
