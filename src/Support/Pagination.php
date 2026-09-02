<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

/**
 * The paging arithmetic shared by the paginated admin list screens.
 *
 * All of it exists for one failure the screens kept reinventing: a requested
 * page that has fallen off the end of the result set. That happens constantly
 * and for unrelated reasons — the last row on the page was just deleted, a
 * filter narrowed the set, retention pruned it, a bookmarked page number went
 * stale — and an unclamped request answers with an empty list, a nonsensical
 * "Showing 11-10 of 10", and no navigation to get back.
 *
 * Clamping in ONE place before the query covers every cause at once. The client
 * then syncs to the page it is told it actually got, rather than holding onto a
 * number the server already rejected and re-asking for it forever.
 *
 * Deliberately pure: the list screens' AJAX handlers cannot be unit-tested
 * (they authorize through static capability checks and query through static
 * repositories, then terminate in wp_send_json_success), so the part that has
 * the actual off-by-one risk lives here where it can be.
 */
final class Pagination
{
    /** Rows per page when a request names none. */
    public const int DEFAULT_PER_PAGE = 10;

    /** Smallest page size a request may ask for. */
    public const int MIN_PER_PAGE = 5;

    /** Largest page size a request may ask for. */
    public const int MAX_PER_PAGE = 100;

    /**
     * Normalizes a requested page size into the allowed range.
     *
     * @param mixed $requested Raw per-page value from the request.
     * @return int
     */
    public static function perPage(mixed $requested): int
    {
        return min(self::MAX_PER_PAGE, max(self::MIN_PER_PAGE, (int) $requested));
    }

    /**
     * Resolves the page to actually query, and the page count to report.
     *
     * @param mixed $requested Raw page value from the request.
     * @param int   $perPage   Rows per page, already normalized.
     * @param int   $total     Total matching rows.
     * @return array{page: int, totalPages: int} The clamped page and the page count.
     */
    public static function resolve(mixed $requested, int $perPage, int $total): array
    {
        // A page size of zero would divide by zero; a negative one is nonsense.
        $perPage = max(1, $perPage);

        // One page always exists, even with no rows at all — otherwise an empty
        // list reports "page 1 of 0" and the clamp below drives page to zero.
        $totalPages = max(1, (int) ceil(max(0, $total) / $perPage));

        $page = max(1, (int) $requested);

        return [
            'page'       => min($page, $totalPages),
            'totalPages' => $totalPages,
        ];
    }
}
