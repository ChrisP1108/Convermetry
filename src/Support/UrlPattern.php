<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

/**
 * The one rule for a URL pattern a site owner types into a URL goal, a link
 * click goal, or a funnel page step: how it is cleaned when saved, which form
 * of it is compared, and when a stored one can no longer mean what it was
 * written for.
 *
 * PURE. No WordPress functions, so {@see \Convermetry\Goals\GoalMatcher} (which
 * is pure by design) and the unit suite can use it as it is.
 *
 * WHY THIS EXISTS. WordPress stores a slug in Cyrillic, Greek, Arabic, Hebrew or
 * CJK percent-encoded ("/%d0%ba%d0%be%d0%bd%d1%82%d0%b0%d0%ba%d1%82%d1%8b/" for
 * "контакты"), and the tracker stores page_url in that form. Until 1.1.6 a
 * pattern went through sanitize_text_field(), which deletes every %XX octet, so
 * the address pasted from Chrome was saved as "//". Compared with the trailing
 * slash forgiven, "//" and the home page's "/" both become "", and the goal
 * completed on every home-page view. The same address typed as the address bar
 * shows it ("/контакты/") was kept, but compared against the encoded page_url
 * without decoding either side, so it never matched at all.
 *
 * So a pattern now keeps its %XX sequences ({@see clean()}), both sides are
 * compared decoded and lowercased ({@see canonical()}), the SQL a funnel runs
 * gets the pattern in the encoding WordPress stores ({@see encoded()}), and a
 * pattern that the old sanitizer damaged is refused rather than matched
 * ({@see isUsable()}).
 */
final class UrlPattern
{
    /**
     * Cleans a URL pattern as typed or pasted, keeping its %XX sequences.
     *
     * Not sanitize_text_field(), which deletes them (see the class docblock).
     * What it removes here is what no URL contains: invalid UTF-8 (the whole
     * value, as sanitize_text_field() does), tags, and control characters. A
     * space is kept, because "/price list/" is how the address bar shows
     * "/price%20list/" and {@see canonical()} treats the two as one.
     *
     * @param mixed $value Raw submitted or stored value.
     * @param int   $max   Maximum length in characters.
     * @return string
     */
    public static function clean(mixed $value, int $max): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = (string) $value;

        if (preg_match('//u', $value) !== 1) {
            return '';
        }

        $value = (string) preg_replace('/[\x00-\x1F\x7F]+/', '', strip_tags($value));

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * The form both sides of a comparison are reduced to: percent-decoded,
     * then lowercased.
     *
     * Decoding is what lets "/контакты/", "/%D0%BA…/" (Chrome's copy) and
     * "/%d0%ba…/" (WordPress's own) all be one page. A value whose decoding is
     * not valid UTF-8 ("%ff") is compared as written instead, so two different
     * invalid sequences cannot both decode to the same replacement character.
     *
     * Lowercasing folds non-Latin letters too when mbstring is present, as it
     * is on every host WordPress supports in practice; without it only A-Z are
     * folded, which is what 1.1.5 did.
     *
     * @param string $value A pattern or a stored URL.
     * @return string
     */
    public static function canonical(string $value): string
    {
        $decoded = rawurldecode($value);

        return self::lower(preg_match('//u', $decoded) === 1 ? $decoded : $value);
    }

    /**
     * The pattern in the form WordPress stores a URL, for SQL comparison with
     * the page_url column.
     *
     * MySQL cannot percent-decode a column, so the pattern is brought to the
     * column instead: decoded and lowercased as {@see canonical()} does, then
     * every byte a browser percent-encodes in a path (anything outside ASCII,
     * controls, space, '"', '#', '%', '<', '>', '?', '`', '{', '}') encoded with
     * lowercase hex, as WordPress writes a slug. The *_ci collation then ignores
     * hex case, so a page_url stored from an address typed into the browser
     * ("%D0%BA") matches as well.
     *
     * @param string $pattern A cleaned pattern.
     * @return string
     */
    public static function encoded(string $pattern): string
    {
        $decoded = rawurldecode($pattern);

        // Not decodable to text ("%ff"): already in the only form it can be
        // stored in, and re-encoding its '%' would make it "%25ff".
        if (preg_match('//u', $decoded) !== 1) {
            return strtolower($pattern);
        }

        return (string) preg_replace_callback(
            '/[^\x21\x24\x26-\x3B\x3D\x40-\x5F\x61-\x7A\x7C\x7E]/',
            static fn(array $byte): string => '%' . bin2hex($byte[0]),
            self::lower($decoded)
        );
    }

    /**
     * Lowercases text, folding non-Latin letters too when mbstring is present.
     *
     * @param string $value Valid UTF-8 text, or bytes compared as written.
     * @return string
     */
    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower') && preg_match('//u', $value) === 1
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    /**
     * Whether a stored pattern can still match what it was written for.
     *
     * A path with an empty segment ("//", "/catalog//", "https://example.com//")
     * is what sanitize_text_field() left of an address whose segment was made
     * only of %XX octets. No WordPress page has one, and the first of these
     * forgave its slashes down to "", which is the home page. Such a pattern
     * is refused when saved and never matched when it was saved before 1.1.6;
     * the site owner has to enter the address again.
     *
     * The empty pattern is unusable too: 'contains' of nothing would match
     * every page.
     *
     * What this cannot catch is an address made only of non-ASCII characters
     * and pasted WITHOUT a trailing slash, which 1.1.5 stored as "/": that is
     * also exactly how a real home-page goal looks.
     *
     * @param string $pattern A cleaned pattern.
     * @return bool
     */
    public static function isUsable(string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        $path = (string) preg_replace('~^[a-z][a-z0-9+.\-]*://[^/]*~i', '', $pattern);

        return !str_contains($path, '//');
    }
}
