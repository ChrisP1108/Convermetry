<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Convermetry\Api\DeliveryLogController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Delivery Log date-range validation.
 *
 * Regression origin: 'before' was registered but never read, and 'after' was
 * reduced to year+month — so after=2026-08-15 returned all of August. Format
 * matching alone is not enough either: '2026-99-99' matches ^\d{4}-\d{2}-\d{2}$
 * but is not a date, and a lenient parser rolls it years forward.
 */
final class DateRangeTest extends TestCase
{
    private function parse(string $value): ?\DateTimeImmutable
    {
        $method = new ReflectionMethod(DeliveryLogController::class, 'parseDateParam');

        return $method->invoke(null, $value);
    }

    /**
     * @dataProvider validDates
     */
    public function testValidDatesParseAsMidnightUtc(string $input): void
    {
        $parsed = $this->parse($input);

        self::assertNotNull($parsed);
        self::assertSame($input . ' 00:00:00', $parsed->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $parsed->getTimezone()->getName());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validDates(): array
    {
        return [
            'ordinary'     => ['2026-08-15'],
            'leap day'     => ['2024-02-29'],
            'year start'   => ['2026-01-01'],
            'year end'     => ['2026-12-31'],
        ];
    }

    /**
     * @dataProvider invalidDates
     */
    public function testInvalidDatesAreRejected(string $input): void
    {
        self::assertNull($this->parse($input), "'{$input}' should not be accepted");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDates(): array
    {
        return [
            'impossible month and day' => ['2026-99-99'],
            'day past month end'       => ['2026-02-30'],
            'month 13'                 => ['2026-13-01'],
            'month 00'                 => ['2026-00-10'],
            'unpadded'                 => ['2026-1-5'],
            'datetime'                 => ['2026-08-15T00:00'],
            'words'                    => ['yesterday'],
            'garbage'                  => ['not-a-date'],
            'non-leap Feb 29'          => ['2026-02-29'],
        ];
    }

    /**
     * 'before' is documented as an inclusive day bound, so a single-day query
     * must cover exactly that UTC day.
     */
    public function testInclusiveBeforeBoundCoversExactlyOneDay(): void
    {
        $after  = $this->parse('2026-08-15');
        $before = $this->parse('2026-08-15');

        self::assertNotNull($after);
        self::assertNotNull($before);
        self::assertSame('2026-08-15 00:00:00', $after->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-16 00:00:00', $before->modify('+1 day')->format('Y-m-d H:i:s'));
    }

    /**
     * @dataProvider boundaryRollovers
     */
    public function testInclusiveBoundRollsOverMonthAndYearEnds(string $input, string $expected): void
    {
        $parsed = $this->parse($input);

        self::assertNotNull($parsed);
        self::assertSame($expected, $parsed->modify('+1 day')->format('Y-m-d H:i:s'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function boundaryRollovers(): array
    {
        return [
            'month end' => ['2026-08-31', '2026-09-01 00:00:00'],
            'year end'  => ['2026-12-31', '2027-01-01 00:00:00'],
            'leap day'  => ['2024-02-29', '2024-03-01 00:00:00'],
        ];
    }
}
