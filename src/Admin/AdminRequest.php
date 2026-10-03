<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

/**
 * The small, shared pieces of Convermetry's admin request handlers: the
 * request method, the refusal responses, and strict parsing of a value a
 * handler has already read.
 *
 * Deliberately NOT here: reading $_POST or $_GET, verifying a nonce, or
 * checking a capability. Every handler does those itself, as separate guards
 * in its own body — method, capability, nonce presence and type, nonce
 * verification, then input — so each one can be read top to bottom without
 * following a call into a shared authorization helper. A helper that combined
 * them is exactly how a single compound condition becomes hard to audit.
 */
final class AdminRequest
{
    /**
     * Whether the current request is a POST.
     *
     * @return bool
     */
    public static function isPost(): bool
    {
        return self::method() === 'POST';
    }

    /**
     * Whether the current request is a GET.
     *
     * @return bool
     */
    public static function isGet(): bool
    {
        return self::method() === 'GET';
    }

    /**
     * Ends an admin-post or admin screen request that failed a guard.
     *
     * Terminates before the handler reads or changes anything: every caller
     * invokes it from a guard at the top of the handler.
     *
     * @param string $message Plain-text explanation for the person who followed the link.
     * @param int    $status  HTTP status: 403 for authorization, 405 for method, 400 for input.
     * @return never
     */
    public static function deny(string $message, int $status = 403): never
    {
        wp_die(
            esc_html($message),
            esc_html__('Request refused', 'convermetry'),
            ['response' => (int) $status, 'back_link' => true]
        );
    }

    /**
     * Ends an AJAX request that failed a guard.
     *
     * Keeps the shape every admin script already reads — {success: false,
     * data: {message}} — so a refusal shows its message rather than a
     * generic failure. Only the HTTP status changes; the scripts parse the
     * JSON body whatever the status.
     *
     * @param string $message Translated message shown by the admin script.
     * @param int    $status  HTTP status: 403 for authorization, 405 for method, 400 for input.
     * @return never
     */
    public static function denyAjax(string $message, int $status = 403): never
    {
        wp_send_json_error(['message' => $message], $status);
    }

    /**
     * The message every handler uses for a missing, malformed, expired, or
     * wrong-action nonce. One message for all four: telling a caller WHICH
     * part failed only helps someone probing the check.
     *
     * @return string
     */
    public static function expiredMessage(): string
    {
        return __('This page has expired. Reload it and try again.', 'convermetry');
    }

    /**
     * The message for a user whose role lacks the required capability.
     *
     * @return string
     */
    public static function forbiddenMessage(): string
    {
        return __('Sorry, you are not allowed to do that.', 'convermetry');
    }

    /**
     * One unslashed scalar value out of a request array the caller has
     * already verified, treating anything else — an array from `?key[]=…`, or
     * a missing key — as absent.
     *
     * The caller still sanitizes the result for the field's meaning; this only
     * guarantees a string, so no sanitizer is ever handed an array and no
     * string cast ever emits "Array to string conversion".
     *
     * @param array<array-key, mixed> $src A request array, after its handler's nonce check.
     * @param string                  $key Parameter name.
     * @return string
     */
    public static function scalar(array $src, string $key): string
    {
        $value = $src[$key] ?? '';

        return is_scalar($value) ? wp_unslash((string) $value) : '';
    }

    /**
     * Parses a database row id that arrived as text.
     *
     * Digits only, no sign, no leading zero, and within PHP's integer range.
     * intval() is not enough: intval(['5']) is 1, so an array-valued field
     * used to name row 1, and intval('5abc') is 5.
     *
     * @param string $raw The already sanitized request value.
     * @return int The id, or 0 when the value is not one.
     */
    public static function positiveId(string $raw): int
    {
        if (preg_match('/^[1-9][0-9]{0,17}$/', $raw) !== 1) {
            return 0;
        }

        return (int) $raw;
    }

    /**
     * The request method, uppercased; '' when the server did not say.
     *
     * @return string
     */
    private static function method(): string
    {
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))
            : '';

        return strtoupper($method);
    }
}
