<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;

/**
 * The plugin's single policy for "what does a URL look like once it is safe to
 * store?".
 *
 * Four surfaces must agree on the answer, and until now three of them owned
 * private copies of the same rules:
 *
 *  - the public tracking endpoint, for every event's page_url, target_url, and
 *    referrer ({@see \Convermetry\Api\TrackingController});
 *  - the form correlation reader, for the landing page and submitting page a
 *    browser posts inside cvm_context ({@see \Convermetry\Tracking\Correlation});
 *  - goal ingestion, which stores a completion's page and landing page; and
 *  - funnel step matching, which compares a configured URL against a stored one.
 *
 * The shared rule everything here enforces: **query strings and fragments are
 * never kept.** They routinely carry reset tokens, order ids, session keys, and
 * email addresses, none of which belong in an analytics table. Campaign
 * parameters are the deliberate exception, and they travel as their own
 * validated columns rather than as part of a URL.
 *
 * Two families exist because two genuinely different rules are needed, and
 * collapsing them would be a security regression rather than a simplification:
 *
 *  - {@see pageUrl()} canonicalizes a URL claimed to be ON THIS SITE, and
 *    rejects foreign hosts outright. A non-default port survives only when this
 *    site actually runs on it, so a local dev install keeps working while
 *    ':8080' cannot be smuggled onto a production host.
 *  - {@see referrer()} accepts ANY host, because external referrers are the
 *    interesting ones.
 *
 * {@see boundedUrl()} is the correlation reader's variant: same normalization,
 * no port handling, and a hard 255-character cap applied here rather than by the
 * caller. It is kept distinct from pageUrl() on purpose — these two were written
 * separately, behave differently, and quietly merging them during extraction
 * would have changed what a form submission stores.
 */
final class Url
{
    /** Maximum characters {@see boundedUrl()} returns (the storage column width). */
    private const int MAX_BOUNDED = 255;

    /**
     * Normalizes a tracked page URL: the scheme must be http(s), the host must
     * belong to this site, and the URL is canonicalized to scheme://host/path —
     * the entire query string is discarded, so tokens and PII never reach the
     * database and one page is never fragmented across many report rows. A port
     * survives only when it matches one this site actually runs on.
     *
     * @param string $url Raw page URL from the event.
     * @return string Canonical URL, or '' when the URL is invalid or foreign.
     */
    public static function pageUrl(string $url): string
    {
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        if (!in_array(strtolower((string) $parts['host']), Options::allowedHosts(), true)) {
            return '';
        }

        $port = isset($parts['port']) && in_array((int) $parts['port'], self::sitePorts(), true)
            ? ':' . (int) $parts['port']
            : '';

        return $scheme . '://' . $parts['host'] . $port . ($parts['path'] ?? '/');
    }

    /**
     * Normalizes a click/form destination URL.
     *
     * mailto: and tel: destinations are kept whole (the address *is* the
     * destination — and it is what makes phone and email goals work without any
     * browser configuration); every other scheme except http(s) is dropped, and
     * http(s) and relative URLs lose their query string and fragment — link and
     * form-action URLs can carry reset tokens, emails, or order ids that must
     * never be stored.
     *
     * @param string $url Raw destination from the event.
     * @return string Normalized destination, or '' when empty or unsafe.
     */
    public static function targetUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (preg_match('~^(mailto|tel):~i', $url)) {
            return $url;
        }

        // Any other explicit scheme that isn't http(s) — javascript:, data:,
        // ftp:, custom app schemes — is dropped rather than stored.
        if (preg_match('~^([a-z][a-z0-9+.\-]*):~i', $url, $m) && !in_array(strtolower($m[1]), ['http', 'https'], true)) {
            return '';
        }

        $parts = wp_parse_url($url);
        if (is_array($parts) && !empty($parts['host'])) {
            return strtolower((string) ($parts['scheme'] ?? 'https')) . '://' . $parts['host']
                . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
                . ($parts['path'] ?? '/');
        }

        // Relative URL (e.g. a form action of "/contact") — keep the path only.
        return (string) preg_replace('~[?#].*$~', '', $url);
    }

    /**
     * Normalizes a referrer URL to scheme://host/path — query strings and
     * fragments can carry search terms, emails, or tokens, so they are never
     * stored. Any host is allowed (external referrers are the interesting ones),
     * but the scheme must be http(s).
     *
     * @param string $url Raw referrer from the event.
     * @return string Normalized referrer, or '' when unparsable.
     */
    public static function referrer(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        return $scheme . '://' . $parts['host'] . ($parts['path'] ?? '/');
    }

    /**
     * Normalizes a URL to scheme://host/path and caps it at the storage column
     * width. Query strings and fragments are never kept. When $sameHost is true,
     * the host must additionally belong to this site.
     *
     * This is the variant the form correlation reader uses for the landing page
     * and submitting page a browser posts inside cvm_context, and the one goal
     * ingestion uses for a completion's landing page. Unlike {@see pageUrl()} it
     * does no port handling and applies its own length bound.
     *
     * @param mixed $value    Raw URL value (anything; non-strings yield '').
     * @param bool  $sameHost Require the host to be one of this site's allowed hosts.
     * @return string Normalized URL, or '' when invalid.
     */
    public static function boundedUrl(mixed $value, bool $sameHost): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }

        $parts = wp_parse_url($value);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        $host = strtolower((string) $parts['host']);
        if ($sameHost && !in_array($host, Options::allowedHosts(), true)) {
            return '';
        }

        return mb_substr($scheme . '://' . $parts['host'] . ($parts['path'] ?? '/'), 0, self::MAX_BOUNDED);
    }

    /**
     * The non-default ports this site itself is served on (from home_url() and
     * site_url()). Usually empty; non-empty on e.g. local dev setups.
     *
     * @return int[]
     */
    public static function sitePorts(): array
    {
        static $ports = null;

        if ($ports === null) {
            $ports = array_values(array_filter(array_map(
                static fn(string $url): int => (int) wp_parse_url($url, PHP_URL_PORT),
                [home_url(), site_url()]
            )));
        }

        return $ports;
    }
}
