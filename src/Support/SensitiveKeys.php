<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

/**
 * The plugin's single policy for "does this field name look like it carries a
 * secret?".
 *
 * Three surfaces must agree on the answer, and they used to be one private
 * const in one class:
 *
 *  - Activity Log request headers ({@see \Convermetry\Webhook\DeliveryLog::redactHeaders()}).
 *  - Activity Log request/response bodies (redactSensitiveJson()).
 *  - Internal email notifications, which must never mail a submitted password
 *    or API key to an inbox outside the site's retention controls.
 *
 * The email surface is why this moved: a value that is merely logged can be
 * purged by retention, while a value that has been emailed is gone. Duplicating
 * the pattern list across the two would guarantee they drift.
 *
 * MATCHING IS ON A CANONICAL FORM, not the raw name. Real field names and real
 * header names spell the same secret many ways — 'X-API-Key', 'api-key',
 * 'API Key' — and structured submission fields carry HUMAN labels, so the
 * space-separated spelling is the common case, not an edge case. Every run of
 * non-alphanumeric characters collapses to a single underscore before the
 * substring test, so all three spellings reduce to 'api_key'.
 *
 * The patterns are deliberately conservative. A bare 'auth' pattern would
 * redact a legitimate field named 'author', so 'authorization' is spelled out
 * in full; 'proxy_authorization' still matches it as a substring.
 */
final class SensitiveKeys
{
    /**
     * Field and header names whose values must never be stored or emailed in
     * the clear. Matched as case-insensitive substrings of the canonical form.
     *
     * @var string[]
     */
    public const array PATTERNS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'credential', 'private_key', 'access_token',
        'refresh_token', 'client_secret',
    ];

    /**
     * Additional patterns that apply to HTTP headers only.
     *
     * 'cookie' belongs here and not in the shared list: a request header named
     * Cookie or Set-Cookie carries session credentials, while a form field
     * labelled "Cookie policy" or "Do you accept cookies?" is ordinary lead
     * data that must survive into the payload and the notification email.
     *
     * @var string[]
     */
    public const array HEADER_PATTERNS = ['cookie'];

    /**
     * Reduces a field or header name to the form the patterns are written in.
     *
     * Lowercased, with every run of non-alphanumeric characters collapsed to a
     * single underscore, and no leading/trailing underscore:
     *
     *     'X-API-Key' → 'x_api_key'      (contains 'api_key')
     *     'API Key'   → 'api_key'
     *     'api-key'   → 'api_key'
     *     'Author'    → 'author'         (matches nothing)
     *
     * @param string $name Raw field or header name.
     * @return string The canonical form.
     */
    public static function canonicalize(string $name): string
    {
        $lower = strtolower($name);

        return trim((string) preg_replace('/[^a-z0-9]+/', '_', $lower), '_');
    }

    /**
     * Whether a field name looks like it carries a secret.
     *
     * @param string $name Field name, id, or human label.
     * @return bool
     */
    public static function matches(string $name): bool
    {
        return self::matchesAny($name, self::patterns());
    }

    /**
     * Whether an HTTP header name looks like it carries a credential.
     *
     * Applies the shared patterns plus {@see self::HEADER_PATTERNS}.
     *
     * @param string $name Header name.
     * @return bool
     */
    public static function matchesHeader(string $name): bool
    {
        return self::matchesAny($name, [...self::patterns(), ...self::HEADER_PATTERNS]);
    }

    /**
     * The effective field-name pattern list.
     *
     * @return string[]
     */
    public static function patterns(): array
    {
        /**
         * Filters the patterns marking a field name as secret-bearing.
         *
         * Intended to EXTEND the list for site-specific fields ('ssn', 'dob').
         * Patterns are matched as substrings of the canonical form: lowercase,
         * with non-alphanumeric runs collapsed to underscores. Returning a
         * shorter list weakens redaction in the Activity Log and allows more
         * fields into notification emails.
         *
         * @param string[] $patterns The default pattern list.
         */
        $filtered = apply_filters('convermetry_sensitive_keys', self::PATTERNS);

        if (!is_array($filtered)) {
            return self::PATTERNS;
        }

        $out = [];
        foreach ($filtered as $pattern) {
            if (!is_scalar($pattern)) {
                continue;
            }
            $clean = self::canonicalize((string) $pattern);
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return $out;
    }

    /**
     * Substring-matches a name's canonical form against a pattern list.
     *
     * @param string   $name     Raw field or header name.
     * @param string[] $patterns Canonical patterns to test.
     * @return bool
     */
    private static function matchesAny(string $name, array $patterns): bool
    {
        $canonical = self::canonicalize($name);
        if ($canonical === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($canonical, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
