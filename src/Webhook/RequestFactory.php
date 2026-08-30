<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\FormSettings;
use Convermetry\Settings\Options;
use Convermetry\Support\KeyValuePairs;

/**
 * Builds the final request URL and headers for one webhook delivery — the
 * single place the merge/precedence rules live.
 *
 * URL query parameters (later values override earlier ones for shared keys):
 *
 *     1. Global URL query parameters   (Webhooks page)
 *     2. Page URL query parameters     (from the submitting page, when enabled
 *                                       globally or per-form; form deliveries only)
 *     3. Per-form URL query parameters (Forms page)
 *     4. Runtime parameters            (convermetry_submit_form() callers)
 *     5. convermetry_webhook_query_args
 *
 * Headers (later values override earlier ones for shared keys):
 *
 *     1. Content-Type: application/json
 *     2. Global headers                (Webhooks page)
 *     3. Per-form headers              (Forms page)
 *     4. Runtime headers               (convermetry_submit_form() callers)
 *     5. convermetry_webhook_headers
 *
 * Because composition happens here, and every delivery path composes exactly
 * once before freezing, both public filters inherit the freeze semantics for
 * free: an analytics retry replays the URL and headers stored in its retry
 * state, and a queued form delivery replays the ones stored in its queue row,
 * so neither filter runs again for a delivery already on the wire. The one
 * exception is deliberate and handled: {@see recoverUrl()} and {@see
 * recoverHeaders()} rebuild an OLD retry state that predates those stored
 * columns, and they skip the filters precisely so a frozen delivery cannot be
 * re-composed halfway through its retry chain.
 *
 * Protocol headers (Idempotency-Key, X-Convermetry-Signature, User-Agent)
 * are added at SEND time by the senders, not here — the signature is always
 * computed over the exact bytes being sent, and rotating a secret between
 * retries must re-sign the identical frozen body with the new key.
 */
final class RequestFactory
{
    /**
     * Header names a filter may not introduce, remove, or alter.
     *
     * Two reasons, both about the request stopping being what it claims to be:
     * Host, Content-Length, Transfer-Encoding and Connection are the transport's
     * to set — a filter-supplied Host in particular would let a request reach a
     * different server than the one wp_safe_remote_post() validated — and
     * User-Agent, Idempotency-Key and X-Convermetry-Signature are Convermetry's
     * identity on the wire. A forged signature header on an endpoint with no
     * configured secret is the sharpest case: a receiver that cannot tell
     * whether this site signs would accept it.
     *
     * Compared case-insensitively, since HTTP header names are.
     */
    private const array PROTECTED_HEADERS = [
        'content-type',
        'host',
        'content-length',
        'transfer-encoding',
        'connection',
        'user-agent',
        'idempotency-key',
        'x-convermetry-signature',
    ];

    /**
     * Builds the delivery URL for one endpoint: the endpoint URL with the
     * merged query parameters appended.
     *
     * @param string                $endpointUrl Configured endpoint URL.
     * @param string                $formKey     Provider-scoped form key ('' for analytics deliveries).
     * @param array<string, string> $pageQuery   Query parameters from the submitting page.
     * @param array<string, mixed>  $runtime     Runtime query parameters (highest precedence).
     * @param DeliveryDetails|null   $context     Composition context passed to the filter; null passes an
     *                                            empty array, as the recovery paths and tests do.
     * @return string
     */
    public static function buildUrl(
        string $endpointUrl,
        string $formKey = '',
        array $pageQuery = [],
        array $runtime = [],
        ?DeliveryDetails $context = null
    ): string {
        $merged = self::mergeQueryParams($formKey, $pageQuery, $runtime);

        /**
         * Filters the query parameters appended to a webhook endpoint URL,
         * after the global, page, per-form and runtime layers have merged and
         * BEFORE the request is frozen.
         *
         * Runs once per logical delivery, not once per attempt: a retry resends
         * the URL frozen on the first attempt, so this filter cannot change
         * where an in-flight delivery goes. It does not run when Convermetry
         * rebuilds a pre-0.6 retry state, for the same reason.
         *
         * The result is re-normalized to bounded scalar keys and values, exactly
         * as the configured layers are. The final URL still passes through
         * wp_safe_remote_post(), so its SSRF protections apply regardless of
         * what is returned here; this filter cannot retarget a delivery to
         * another host, because the endpoint URL itself is not filterable.
         *
         * @param array<string, string> $params  Merged query parameters.
         * @param array<string, mixed>  $context Credential-free composition context. Has no
         *                                       'attempt' — composition happens once per delivery.
         */
        $filtered = apply_filters('convermetry_webhook_query_args', $merged, $context?->toArray() ?? []);

        // Identity fast path: with no callback registered the filter hands back
        // the exact array it was given, and the URL is composed from the same
        // values in the same order it always was.
        $params = $filtered === $merged ? $merged : self::reconcilePairs($filtered);

        return $params === [] ? $endpointUrl : add_query_arg(array_map('rawurlencode', $params), $endpointUrl);
    }

    /**
     * Builds the delivery headers for one request.
     *
     * @param string                $formKey Provider-scoped form key ('' for analytics deliveries).
     * @param array<string, string> $runtime Runtime headers (highest precedence).
     * @param DeliveryDetails|null  $context Composition context passed to the filter; null passes an
     *                                       empty array, as the recovery paths and tests do.
     * @return array<string, string>
     */
    public static function buildHeaders(
        string $formKey = '',
        array $runtime = [],
        ?DeliveryDetails $context = null
    ): array {
        $merged = self::mergeHeaders($formKey, $runtime);

        /**
         * Filters the non-protocol headers sent with a webhook request, after
         * the global, per-form and runtime layers have merged and BEFORE the
         * request is frozen.
         *
         * Runs once per logical delivery, not once per attempt: a retry resends
         * the headers frozen on the first attempt. It does not run when
         * Convermetry rebuilds a pre-0.6 retry state.
         *
         * Names are trimmed and values cast to string, exactly as the configured
         * layers are. A callback may NOT add, alter, or remove any of
         * Content-Type, Host, Content-Length, Transfer-Encoding, Connection,
         * User-Agent, Idempotency-Key, or X-Convermetry-Signature — those are
         * restored to their pre-filter state afterwards. (A header an
         * administrator configured on the Webhooks or Forms page keeps behaving
         * exactly as it did; the restriction is on what this filter can change.)
         * The signature and Idempotency-Key are generated after this filter,
         * from the exact frozen body, on every attempt.
         *
         * Values are sent as-is: do not put a credential here that you would not
         * want written to the Activity Log, which redacts by NAME — call it
         * Authorization or X-Api-Key and it is redacted, call it X-My-Thing and
         * it is stored.
         *
         * @param array<string, string> $headers Merged non-protocol headers.
         * @param array<string, mixed>  $context Credential-free composition context. Has no
         *                                       'attempt' — composition happens once per delivery.
         */
        $filtered = apply_filters('convermetry_webhook_headers', $merged, $context?->toArray() ?? []);

        if ($filtered === $merged) {
            return $merged;
        }

        return self::reconcileHeaders($merged, $filtered);
    }

    /**
     * Rebuilds a URL for a retry state written before frozen request columns
     * existed, without running the composition filter.
     *
     * A delivery whose body was already frozen must not have its destination
     * re-composed on attempt four; recovering the best available approximation
     * of what attempt one would have sent is the closest thing to correct.
     *
     * @param string $endpointUrl Configured endpoint URL.
     * @return string
     */
    public static function recoverUrl(string $endpointUrl): string
    {
        $params = self::mergeQueryParams();

        return $params === [] ? $endpointUrl : add_query_arg(array_map('rawurlencode', $params), $endpointUrl);
    }

    /**
     * Rebuilds headers for a retry state written before frozen request columns
     * existed, without running the composition filter.
     *
     * @return array<string, string>
     */
    public static function recoverHeaders(): array
    {
        return self::mergeHeaders();
    }

    /**
     * Adds the per-send protocol headers to a frozen header set: the
     * User-Agent, the Idempotency-Key (equal to the payload's delivery_id),
     * and — when a signing secret is configured for the endpoint — the
     * X-Convermetry-Signature HMAC header computed over the exact body bytes.
     *
     * @param array<string, string> $headers     Frozen delivery headers.
     * @param string                $endpointUrl Configured endpoint URL (secret lookup key).
     * @param string                $body        Exact JSON body bytes being sent.
     * @param string                $deliveryId  The delivery's idempotency id.
     * @return array<string, string>
     */
    public static function withProtocolHeaders(array $headers, string $endpointUrl, string $body, string $deliveryId): array
    {
        $headers['User-Agent']      = 'WordPress/Convermetry ' . CVM_VERSION;
        $headers['Idempotency-Key'] = $deliveryId;

        $secret = Options::secretFor($endpointUrl);
        if ($secret !== '') {
            $headers['X-Convermetry-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        return $headers;
    }

    /**
     * Merges the configured query-parameter layers.
     *
     * @param string                $formKey   Provider-scoped form key ('' for analytics deliveries).
     * @param array<string, string> $pageQuery Query parameters from the submitting page.
     * @param array<string, mixed>  $runtime   Runtime query parameters (highest precedence).
     * @return array<string, string>
     */
    private static function mergeQueryParams(string $formKey = '', array $pageQuery = [], array $runtime = []): array
    {
        $params = KeyValuePairs::toMap(Options::globalQueryParams());

        if ($formKey !== '') {
            $config = FormSettings::forForm($formKey);

            if ($pageQuery !== [] && (Options::includePageParams() || $config['include_page_params'])) {
                $params = array_merge($params, $pageQuery);
            }

            $params = array_merge($params, KeyValuePairs::toMap($config['query_params']));
        }

        foreach ($runtime as $key => $value) {
            if (is_scalar($value) && (string) $key !== '') {
                $params[(string) $key] = (string) $value;
            }
        }

        return $params;
    }

    /**
     * Merges the configured header layers.
     *
     * @param string                $formKey Provider-scoped form key ('' for analytics deliveries).
     * @param array<string, string> $runtime Runtime headers (highest precedence).
     * @return array<string, string>
     */
    private static function mergeHeaders(string $formKey = '', array $runtime = []): array
    {
        $headers = ['Content-Type' => 'application/json; charset=utf-8'];

        $headers = array_merge($headers, KeyValuePairs::toMap(Options::globalHeaders()));

        if ($formKey !== '') {
            $headers = array_merge($headers, KeyValuePairs::toMap(FormSettings::forForm($formKey)['headers']));
        }

        foreach ($runtime as $key => $value) {
            $key = trim((string) $key);
            if ($key !== '') {
                $headers[$key] = (string) $value;
            }
        }

        return $headers;
    }

    /**
     * Re-normalizes a filtered key/value map to the same shape the configured
     * layers produce: non-empty trimmed string keys, scalar values as strings.
     *
     * Insertion order is preserved rather than sorted — add_query_arg() output
     * is order-sensitive, and a receiver comparing raw URLs would see a
     * difference that is not one.
     *
     * @param mixed $filtered Whatever the filter returned.
     * @return array<string, string>
     */
    private static function reconcilePairs(mixed $filtered): array
    {
        if (!is_array($filtered)) {
            return [];
        }

        $out = [];
        foreach ($filtered as $key => $value) {
            $key = trim((string) $key);
            if ($key !== '' && is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * Re-normalizes filtered headers and restores every protected header to
     * exactly what the merge produced — present with the same value and in the
     * same position, or absent.
     *
     * @param array<string, string> $merged   Pre-filter merged headers.
     * @param mixed                 $filtered Whatever the filter returned.
     * @return array<string, string>
     */
    private static function reconcileHeaders(array $merged, mixed $filtered): array
    {
        $out = [];
        foreach (is_array($filtered) ? $filtered : [] as $key => $value) {
            $key = trim((string) $key);
            if ($key !== '' && is_scalar($value) && !self::isProtected($key)) {
                $out[$key] = (string) $value;
            }
        }

        // Reinstate the protected headers the merge produced, in their original
        // positions. Content-Type is first in every merged set, so prepending
        // the protected slice reproduces the original ordering exactly.
        $restore = [];
        foreach ($merged as $key => $value) {
            if (self::isProtected((string) $key)) {
                $restore[$key] = $value;
            }
        }

        return $restore + $out;
    }

    /**
     * Whether a header name is one a filter may not touch.
     *
     * @param string $name Header name as written.
     * @return bool
     */
    private static function isProtected(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::PROTECTED_HEADERS, true);
    }
}
