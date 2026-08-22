<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

use Convermetry\Webhook\DeliveryLog;

/**
 * The single outbound HTTP path for every Convermetry webhook request —
 * scheduled analytics reports, immediate form submissions, retries, and
 * tests all send through {@see postJson()}, so the transport safety rules
 * live in exactly one place:
 *
 *  - wp_safe_remote_post() re-validates the URL at request time (blocking
 *    loopback/private hosts even if DNS changed after the URL was saved),
 *  - redirects are disabled — a redirect would re-target the validated URL,
 *    and HTTP clients drop POST bodies on redirect anyway,
 *  - the response download is capped at the transport layer to the Activity
 *    Log's storage limit, so a malfunctioning or hostile endpoint returning
 *    hundreds of megabytes is never buffered into PHP memory.
 */
final class Http
{
    /** HTTP timeout for webhook requests, in seconds. */
    public const TIMEOUT = 15;

    /**
     * POSTs a pre-serialized JSON body to one endpoint.
     *
     * @param string                $url     Absolute endpoint URL (may carry query parameters).
     * @param string                $body    JSON-encoded request body, sent byte-for-byte.
     * @param array<string, string> $headers Complete request headers (Content-Type included by caller).
     * @return array{ok: bool, code: int, message: string, body: string}
     */
    public static function postJson(string $url, string $body, array $headers): array
    {
        $response = wp_safe_remote_post($url, [
            'timeout'             => self::TIMEOUT,
            'redirection'         => 0,
            'limit_response_size' => DeliveryLog::MAX_BODY_BYTES,
            'headers'             => $headers,
            'body'                => $body,
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'code' => 0, 'message' => $response->get_error_message(), 'body' => ''];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $ok   = $code >= 200 && $code < 300;

        return [
            'ok'      => $ok,
            'code'    => $code,
            'message' => $ok ? 'Delivered' : wp_remote_retrieve_response_message($response),
            'body'    => (string) wp_remote_retrieve_body($response),
        ];
    }
}
