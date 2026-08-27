<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;

/**
 * Resolves the IP address of the visitor making the CURRENT request.
 *
 * Only meaningful while a visitor's own request is being handled — which is
 * exactly when a form provider's success hook fires. A background worker
 * (the form-delivery queue, a retry, the analytics cron) runs in an entirely
 * different request, so the submitter's address is captured once at
 * submission time and persisted with the row; nothing downstream ever
 * re-resolves it.
 *
 * REMOTE_ADDR is the only value a sender cannot spoof, so it is the sole
 * source. Behind a reverse proxy or CDN it is the proxy's address, and sites
 * that trust a forwarded header can substitute it with the
 * 'convermetry_client_ip' filter — the same filter the tracking endpoint's
 * rate limiter uses to key its per-visitor buckets.
 *
 * The result is validated as a real IPv4/IPv6 address; anything else (a
 * missing REMOTE_ADDR on CLI/cron, a filter returning a hostname or a
 * comma-joined forwarding chain) resolves to an empty string rather than
 * storing a junk value.
 *
 * Resolution is memoized for the request, so 'convermetry_client_ip' runs
 * once no matter how many callers ask — the rate limiter and the event
 * writer both see the same answer even if the filter is stateful.
 */
final class ClientIp
{
    /**
     * Memoized resolution for this request. A visitor's address cannot change
     * mid-request, and 'convermetry_client_ip' must not be given the chance
     * to answer differently for the rate-limit key and the stored value — a
     * stateful or nondeterministic filter would otherwise split one visitor
     * across two identities. null means "not yet resolved".
     *
     * @var string|null
     */
    private static ?string $resolved = null;

    /**
     * The IP to STORE for the current request, after the plugin's privacy
     * policy is applied — the single gate both write paths (analytics events
     * and form submissions) go through, so storage can never disagree with
     * what Settings and the docs promise.
     *
     * Returns '' when IP storage is switched off, and when the site honors
     * Do Not Track / Global Privacy Control and this request carries one.
     * That second gate matters most for form submissions: an opted-out
     * visitor's submission is still delivered — they actively submitted the
     * form and delivery is the point — but no address is retained for them.
     *
     * @return string A valid IPv4/IPv6 address, or '' when it must not be stored.
     */
    public static function forStorage(): string
    {
        if (!Options::storeIpAddress()) {
            return '';
        }

        if (Options::respectDnt() && PrivacySignal::fromCurrentRequest()) {
            return '';
        }

        $ip = self::get();

        /**
         * Filters the IP address about to be PERSISTED with an event or a
         * submission.
         *
         * Runs after the configured privacy gates, so it never sees an address
         * the site already decided not to store — it receives '' in that case,
         * and a callback must not turn that back into an address.
         *
         * This is the pseudonymization hook. Truncating the last octet, hashing
         * with a rotating salt, or returning '' for visitors from a particular
         * region are all reasonable here, and none of them affect how the site
         * functions, because the stored address is used for display and export
         * only.
         *
         * It deliberately does NOT affect the address used for rate limiting.
         * That identity comes from {@see get()}, is applied before these gates,
         * and is what stops one client flooding the tracking endpoint —
         * anonymizing it would collapse every visitor into one bucket and let
         * anyone exhaust the site-wide limit. Use convermetry_client_ip if you
         * genuinely need to change the address for both purposes (e.g. behind a
         * trusted proxy).
         *
         * The result must be a valid IPv4 address, a valid IPv6 address, or an
         * empty string; anything else is discarded in favour of ''. Storing a
         * non-address in the ip_address column would corrupt exports and any
         * downstream geo lookup.
         *
         * @param string $ip The address about to be stored, or '' when the
         *                   privacy gates already suppressed it.
         */
        $filtered = (string) apply_filters('convermetry_stored_ip', $ip);

        if ($filtered === $ip) {
            return $ip;
        }

        return $filtered !== '' && filter_var($filtered, FILTER_VALIDATE_IP) !== false ? $filtered : '';
    }

    /**
     * The current request's client IP, or '' when one cannot be determined.
     *
     * @return string A valid IPv4/IPv6 address, or '' when unavailable.
     */
    public static function get(): string
    {
        if (self::$resolved !== null) {
            return self::$resolved;
        }

        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        /**
         * Filters the resolved client IP address.
         *
         * Applies both to the IP stored with a form submission and to the
         * tracking endpoint's rate-limit key. Return a forwarded address
         * (e.g. from CF-Connecting-IP or a trusted X-Forwarded-For hop) only
         * when the proxy in front of WordPress is actually trusted — a
         * spoofable header would let a visitor choose their own identity.
         *
         * @param string $ip The REMOTE_ADDR value.
         */
        $ip = (string) apply_filters('convermetry_client_ip', $ip);

        return self::$resolved = (filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '');
    }

    /**
     * Clears the memoized address.
     *
     * Only needed where one PHP process serves more than one logical request
     * — tests, and long-running CLI/queue workers. Normal web requests never
     * need it.
     *
     * @return void
     */
    public static function resetCache(): void
    {
        self::$resolved = null;
    }
}
