<?php
declare(strict_types=1);

namespace Convermetry\Settings;

if (!defined('ABSPATH')) exit;

use Convermetry\Support\KeyValuePairs;

/**
 * Turns the posted Webhooks form into the stored webhook settings.
 *
 * Every field is checked for the TYPE the form posts before anything else
 * happens, and then validated and sanitized for what it means: endpoint URLs
 * through esc_url_raw() and wp_http_validate_url() (HTTPS unless the
 * development filter allows HTTP), labels as display text, signing secrets
 * and header values as opaque credentials that must survive byte for byte,
 * intervals and failure modes against their fixed sets.
 *
 * Two different outcomes for two different problems:
 *
 *  - A value the form could have produced but that is not acceptable — an
 *    http:// URL, a typo'd host, a header name with a space — rejects that
 *    endpoint or row and is reported back, as structured data: the endpoint's
 *    position, a reason code, and a sanitized, length-bounded copy of what was
 *    typed for display. The raw text never leaves this class.
 *  - A request that is not the shape the form posts at all — an array where a
 *    string belongs, a missing endpoint list, an unknown interval — is
 *    MALFORMED, and nothing is stored. Sanitizing it into defaults instead
 *    would delete endpoints, pause delivery and drop headers nobody removed.
 *
 * Pure apart from the WordPress URL and text helpers it calls: no options are
 * read or written here, so the save handler decides what happens with the
 * result and the rules can be tested on their own.
 */
final class WebhookSettingsInput
{
    /** Longest endpoint label stored, in characters. */
    public const int MAX_LABEL_LEN = 100;

    /** Longest signing secret stored, in characters. */
    public const int MAX_SECRET_LEN = 190;

    /** Longest rejected-URL excerpt kept for the notice, in characters. */
    public const int MAX_DISPLAY_LEN = 80;

    /** The form-delivery failure modes the screen offers. */
    public const array FAILURE_MODES = ['background', 'show_error'];

    /** Reason code: plain HTTP while only HTTPS is allowed. */
    public const string REASON_INSECURE = 'insecure';

    /** Reason code: not a URL this site may deliver to. */
    public const string REASON_INVALID = 'invalid';

    /**
     * Validates and sanitizes one posted Webhooks form.
     *
     * @param array<string, mixed> $fields        The form's fields, each unslashed once by the caller
     *                                            or null when absent: endpoints, active, interval,
     *                                            shared_secret, backfill, global_headers, global_query,
     *                                            include_page_params, failure_mode.
     * @param array<string, true>  $knownIds      Ids of the endpoints configured before this save.
     * @param bool                 $allowInsecure Whether http:// endpoints are allowed.
     * @return array{malformed: bool, settings: array<string, mixed>, rejected: list<array{row: int, reason: string, display: string}>, rejected_pairs: int}
     */
    public static function sanitize(array $fields, array $knownIds, bool $allowInsecure): array
    {
        $malformed = ['malformed' => true, 'settings' => [], 'rejected' => [], 'rejected_pairs' => 0];

        // ── Scalar settings ─────────────────────────────────────────────
        // The interval is a <select> and always posted; the failure mode is a
        // radio pair with one always checked. Anything outside their sets did
        // not come from this form.
        $interval = $fields['interval'] ?? null;
        if (!is_string($interval) || !in_array($interval, Options::INTERVALS, true)) {
            return $malformed;
        }

        $failureMode = $fields['failure_mode'] ?? self::FAILURE_MODES[0];
        if (!is_string($failureMode) || !in_array($failureMode, self::FAILURE_MODES, true)) {
            return $malformed;
        }

        foreach (['active', 'backfill', 'include_page_params'] as $toggle) {
            if (isset($fields[$toggle]) && !is_string($fields[$toggle])) {
                return $malformed;
            }
        }

        $sharedSecret = self::secret($fields['shared_secret'] ?? '');
        if ($sharedSecret === null) {
            return $malformed;
        }

        // ── Global headers and query parameters ─────────────────────────
        $headers = KeyValuePairs::fromHeaderInput($fields['global_headers'] ?? null);
        $query   = KeyValuePairs::fromQueryInput($fields['global_query'] ?? null);

        if ($headers['malformed'] || $query['malformed']) {
            return $malformed;
        }

        // ── Endpoints ───────────────────────────────────────────────────
        // The repeater always posts its first block, even when empty, so an
        // absent or non-list value is not "no endpoints" — it is a request
        // that would delete every endpoint by accident.
        $rows = $fields['endpoints'] ?? null;
        if (!is_array($rows) || $rows === []) {
            return $malformed;
        }

        $endpoints = [];
        $rejected  = [];
        $seenUrls  = [];
        $claimed   = [];
        $position  = 0;

        foreach ($rows as $row) {
            $position++;

            if (!is_array($row)) {
                return $malformed;
            }

            foreach (['url', 'label', 'secret', 'id', 'analytics', 'forms'] as $field) {
                if (isset($row[$field]) && !is_string($row[$field])) {
                    return $malformed;
                }
            }

            $rawUrl = trim((string) ($row['url'] ?? ''));
            if ($rawUrl === '') {
                // A blank block: nothing was configured in it.
                continue;
            }

            $secret = self::secret($row['secret'] ?? '');
            if ($secret === null) {
                return $malformed;
            }

            $reason = '';
            $url    = self::url($rawUrl, $allowInsecure, $reason);

            if ($url === '') {
                $rejected[] = ['row' => $position, 'reason' => $reason, 'display' => self::display($rawUrl)];
                continue;
            }

            if (isset($seenUrls[$url])) {
                // The same URL twice is one endpoint; the first block wins.
                continue;
            }

            // Only ids that are ALREADY configured may be carried through a
            // save, and each only once. A posted id that matches nothing is
            // discarded and the row is treated as new, so a hand-crafted POST
            // cannot graft one endpoint's identity (and therefore its signing
            // secret and retry chain) onto another.
            $postedId = self::endpointId($row['id'] ?? '');
            $id       = ($postedId !== '' && isset($knownIds[$postedId]) && !isset($claimed[$postedId]))
                ? $postedId
                : '';

            if ($id !== '') {
                $claimed[$id] = true;
            }

            $seenUrls[$url] = true;
            $endpoints[]    = [
                'id'        => $id,
                'url'       => $url,
                'label'     => mb_substr(sanitize_text_field((string) ($row['label'] ?? '')), 0, self::MAX_LABEL_LEN),
                'secret'    => $secret,
                'analytics' => !empty($row['analytics']),
                'forms'     => !empty($row['forms']),
            ];
        }

        return [
            'malformed' => false,
            'settings'  => [
                'active'              => !empty($fields['active']) && $endpoints !== [],
                'endpoints'           => $endpoints,
                'interval'            => $interval,
                'shared_secret'       => $sharedSecret,
                'backfill'            => !empty($fields['backfill']),
                'global_headers'      => $headers['pairs'],
                'global_query'        => $query['pairs'],
                'include_page_params' => !empty($fields['include_page_params']),
                'failure_mode'        => $failureMode,
            ],
            'rejected'       => $rejected,
            'rejected_pairs' => $headers['rejected'] + $query['rejected'],
        ];
    }

    /**
     * Validates one endpoint URL against the same rules every delivery uses.
     *
     * Sanitizing alone is not enough here: esc_url_raw() only normalizes and
     * restricts the scheme, and wp_http_validate_url() is what refuses a host
     * this site must not send lead data to. Both run, in that order.
     *
     * @param string $raw           Trimmed URL as typed.
     * @param bool   $allowInsecure Whether http:// is allowed.
     * @param string $reason        Set to a REASON_* code when the URL is refused.
     * @return string The URL to store, or '' when it is refused.
     */
    private static function url(string $raw, bool $allowInsecure, string &$reason): string
    {
        if (!$allowInsecure && stripos($raw, 'http://') === 0) {
            $reason = self::REASON_INSECURE;

            return '';
        }

        $url = esc_url_raw($raw, $allowInsecure ? ['http', 'https'] : ['https']);

        if ($url === '' || wp_http_validate_url($url) === false) {
            $reason = self::REASON_INVALID;

            return '';
        }

        return $url;
    }

    /**
     * A signing secret, kept verbatim apart from surrounding whitespace.
     *
     * A secret is an HMAC key: changing one character of it breaks every
     * receiver's signature check, so it is validated rather than rewritten —
     * no control characters, valid UTF-8, bounded length. It is escaped for
     * its attribute when the screen prints it back.
     *
     * @param mixed $raw Posted value (absent is '').
     * @return string|null Null when the value is not acceptable at all.
     */
    private static function secret(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $secret = trim($raw);

        if (!mb_check_encoding($secret, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $secret) === 1) {
            return null;
        }

        return mb_substr($secret, 0, self::MAX_SECRET_LEN);
    }

    /**
     * A posted endpoint id in the shape ids are minted in, or ''.
     *
     * @param mixed $raw Posted value.
     * @return string
     */
    private static function endpointId(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }

        $id = trim($raw);

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) === 1 ? $id : '';
    }

    /**
     * A sanitized, length-bounded copy of a rejected URL, for the notice only.
     *
     * Tags, line breaks, control characters and %XX sequences are removed, so
     * what is kept is plain text that identifies the row; it is escaped again
     * when printed.
     *
     * @param string $raw The rejected URL as typed.
     * @return string
     */
    private static function display(string $raw): string
    {
        $text = sanitize_text_field((string) preg_replace('/[\x00-\x1F\x7F]/', '', $raw));

        if (mb_strlen($text) > self::MAX_DISPLAY_LEN) {
            $text = mb_substr($text, 0, self::MAX_DISPLAY_LEN - 1) . '…';
        }

        return $text;
    }
}
