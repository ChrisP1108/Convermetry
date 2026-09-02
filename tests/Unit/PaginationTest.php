<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Convermetry\Support\Pagination;
use PHPUnit\Framework\TestCase;

/**
 * Paging arithmetic for the admin list screens.
 *
 * Regression origin: the Activity Log passed the requested page straight to the
 * query and echoed it back unchanged. Deleting the only row on the last page
 * therefore left the screen on a page that no longer existed — an empty list
 * reading "Showing 11-10 of 10", with the pagination control rendering no way
 * back. The Submissions screen already clamped; this is the shared rule both
 * now use, tested here because the handlers themselves cannot be (static
 * capability checks, static repositories, and a wp_send_json_success that
 * terminates the request).
 */
final class PaginationTest extends TestCase
{
    // ── The regression ───────────────────────────────────────────────────────

    /**
     * 11 rows at 10 per page is two pages; delete the only row on page 2 and
     * the next request must land on page 1, not on an empty page 2.
     */
    public function testAPageThatFellOffTheEndIsClampedToTheLast(): void
    {
        $paging = Pagination::resolve(2, 10, 10);

        self::assertSame(1, $paging['page']);
        self::assertSame(1, $paging['totalPages']);
    }

    public function testAPageWellPastTheEndIsClampedToTheLast(): void
    {
        $paging = Pagination::resolve(99, 10, 25);

        self::assertSame(3, $paging['page']);
        self::assertSame(3, $paging['totalPages']);
    }

    public function testAPageInsideTheRangeIsLeftAlone(): void
    {
        $paging = Pagination::resolve(2, 10, 25);

        self::assertSame(2, $paging['page']);
        self::assertSame(3, $paging['totalPages']);
    }

    // ── Degenerate inputs ────────────────────────────────────────────────────

    /**
     * One page always exists. Reporting zero would render "page 1 of 0" and
     * clamp the page itself to zero, which is not a page any query can serve.
     */
    public function testAnEmptyResultSetStillHasOnePage(): void
    {
        $paging = Pagination::resolve(1, 10, 0);

        self::assertSame(1, $paging['page']);
        self::assertSame(1, $paging['totalPages']);
    }

    /**
     * @dataProvider nonPositivePages
     */
    public function testANonPositivePageBecomesTheFirst(mixed $requested): void
    {
        self::assertSame(1, Pagination::resolve($requested, 10, 50)['page']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function nonPositivePages(): array
    {
        return [
            'zero'        => [0],
            'negative'    => [-5],
            'non-numeric' => ['abc'],
            'empty'       => [''],
        ];
    }

    public function testAZeroPerPageCannotDivideByZero(): void
    {
        $paging = Pagination::resolve(1, 0, 50);

        self::assertSame(1, $paging['page']);
        self::assertSame(50, $paging['totalPages']);
    }

    public function testANegativeTotalIsTreatedAsEmpty(): void
    {
        self::assertSame(1, Pagination::resolve(1, 10, -3)['totalPages']);
    }

    public function testAPartialLastPageCounts(): void
    {
        self::assertSame(3, Pagination::resolve(1, 10, 21)['totalPages']);
    }

    public function testAnExactlyFullLastPageIsNotOvercounted(): void
    {
        self::assertSame(2, Pagination::resolve(1, 10, 20)['totalPages']);
    }

    // ── Page size ────────────────────────────────────────────────────────────

    /**
     * @dataProvider perPageCases
     */
    public function testPerPageIsClampedIntoTheAllowedRange(mixed $requested, int $expected): void
    {
        self::assertSame($expected, Pagination::perPage($requested));
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function perPageCases(): array
    {
        return [
            'inside the range'   => [25, 25],
            'at the floor'       => [Pagination::MIN_PER_PAGE, Pagination::MIN_PER_PAGE],
            'at the ceiling'     => [Pagination::MAX_PER_PAGE, Pagination::MAX_PER_PAGE],
            'below the floor'    => [1, Pagination::MIN_PER_PAGE],
            'zero'               => [0, Pagination::MIN_PER_PAGE],
            'negative'           => [-10, Pagination::MIN_PER_PAGE],
            'above the ceiling'  => [5000, Pagination::MAX_PER_PAGE],
            'non-numeric string' => ['lots', Pagination::MIN_PER_PAGE],
            'numeric string'     => ['20', 20],
        ];
    }
}
