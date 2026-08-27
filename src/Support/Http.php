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
    public const int TIMEOUT = 15;

    /** Shortest timeout convermetry_webhook_timeout may select. */
    public const int MIN_TIMEOUT = 1;

    /**
     * Longest timeout convermetry_webhook_timeout may select.
     *
     * Bounded well below the form queue worker's 45-second pass budget: one
     * request must never be able to consume a whole worker pass, or a single
     * slow endpoint would stall every other site's queued deliveries behind it.
     */
    public const int MAX_TIMEOUT = 30;

    /**
     * POSTs a pre-serialized JSON body to one endpoint.
     *
     * @param string                $url     Absolute endpoint URL (may carry query parameters).
     * @param string                $body    JSON-encoded request body, sent byte-for-byte.
     * @param array<string, string> $headers Complete request headers (Content-Type included by caller).
     * @param array<string, mixed>  $context Delivery context, passed to convermetry_webhook_timeout.
     * @return array{ok: bool, code: int, message: string, body: string}
     */
    public static function postJson(string $url, string $body, array $headers, array $context = []): array
    {
        /**
         * Filters the HTTP timeout, in seconds, for one webhook request.
         *
         * Runs for every actual network attempt — so once per retry, and on
         * both test buttons — which is what lets a callback lengthen the timeout
         * for a specific slow endpoint without slowing every delivery down.
         *
         * The default is 15 and is unchanged. A returned value outside
         * MIN_TIMEOUT..MAX_TIMEOUT is IGNORED rather than clamped: silently
         * turning 0 into 1, or 600 into 30, hides the mistake, and "your filter
         * did not apply" is a more useful failure than a value you did not
         * choose. Raising the timeout has a cost — the queue worker has a fixed
         * 45-second budget per pass, so slower requests mean fewer rows drained
         * per pass.
         *
         * This is the only transport parameter that is filterable. Redirects
         * (disabled), certificate verification, blocking mode, and the response
         * size cap are not, because each of them is a safety property of the
         * request rather than a tuning knob.
         *
         * @param int                  $timeout Seconds. Default 15.
         * @param array<string, mixed> $context Credential-free delivery context.
         */
        $filtered = (int) apply_filters('convermetry_webhook_timeout', self::TIMEOUT, $context);
        $timeout  = ($filtered >= self::MIN_TIMEOUT && $filtered <= self::MAX_TIMEOUT) ? $filtered : self::TIMEOUT;

        $response = wp_safe_remote_post($url, [
            'timeout'             => $timeout,
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
