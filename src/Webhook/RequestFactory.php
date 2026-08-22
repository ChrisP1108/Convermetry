<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\FormSettings;
use Convermetry\Settings\Options;

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
 *
 * Headers (later values override earlier ones for shared keys):
 *
 *     1. Content-Type: application/json
 *     2. Global headers                (Webhooks page)
 *     3. Per-form headers              (Forms page)
 *     4. Runtime headers               (convermetry_submit_form() callers)
 *
 * Protocol headers (Idempotency-Key, X-Convermetry-Signature, User-Agent)
 * are added at SEND time by the senders, not here — the signature is always
 * computed over the exact bytes being sent, and rotating a secret between
 * retries must re-sign the identical frozen body with the new key.
 */
final class RequestFactory
{
    /**
     * Builds the delivery URL for one endpoint: the endpoint URL with the
     * merged query parameters appended.
     *
     * @param string                $endpointUrl Configured endpoint URL.
     * @param string                $formKey     Provider-scoped form key ('' for analytics deliveries).
     * @param array<string, string> $pageQuery   Query parameters from the submitting page.
     * @param array<string, mixed>  $runtime     Runtime query parameters (highest precedence).
     * @return string
     */
    public static function buildUrl(string $endpointUrl, string $formKey = '', array $pageQuery = [], array $runtime = []): string
    {
        $params = self::pairsToMap(Options::globalQueryParams());

        if ($formKey !== '') {
            $config = FormSettings::forForm($formKey);

            if ($pageQuery !== [] && (Options::includePageParams() || $config['include_page_params'])) {
                $params = array_merge($params, $pageQuery);
            }

            $params = array_merge($params, self::pairsToMap($config['query_params']));
        }

        foreach ($runtime as $key => $value) {
            if (is_scalar($value) && (string) $key !== '') {
                $params[(string) $key] = (string) $value;
            }
        }

        return $params === [] ? $endpointUrl : add_query_arg(array_map('rawurlencode', $params), $endpointUrl);
    }

    /**
     * Builds the delivery headers for one request.
     *
     * @param string                $formKey Provider-scoped form key ('' for analytics deliveries).
     * @param array<string, string> $runtime Runtime headers (highest precedence).
     * @return array<string, string>
     */
    public static function buildHeaders(string $formKey = '', array $runtime = []): array
    {
        $headers = ['Content-Type' => 'application/json; charset=utf-8'];

        foreach (Options::globalHeaders() as $header) {
            $key = trim((string) ($header['key'] ?? ''));
            if ($key !== '') {
                $headers[$key] = (string) ($header['value'] ?? '');
            }
        }

        if ($formKey !== '') {
            foreach (FormSettings::forForm($formKey)['headers'] as $header) {
                $key = trim((string) ($header['key'] ?? ''));
                if ($key !== '') {
                    $headers[$key] = (string) ($header['value'] ?? '');
                }
            }
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
     * Adds the per-send protocol headers to a frozen header set: the
     * User-Agent, the Idempotency-Key (equal to the payload's delivery_id),
     * and — when a signing secret is configured for the endpoint — the
     * X-Convermetry-Signature HMAC header computed over the exact body bytes.
     *
     * The signing secret is resolved by the endpoint's permanent id, captured
     * when the delivery was frozen — not by its URL. Resolving by URL meant an
     * endpoint renamed mid-retry matched nothing and fell through to the shared
     * secret, so the receiver got a well-formed signature that failed
     * validation: indistinguishable from a forgery, and worse than no signature
     * at all. An id that no longer resolves (the endpoint was deleted) yields
     * no signature rather than a wrong one.
     *
     * @param array<string, string> $headers     Frozen delivery headers.
     * @param string                $endpointId  Endpoint's permanent id (secret lookup key).
     * @param string                $body        Exact JSON body bytes being sent.
     * @param string                $deliveryId  The delivery's idempotency id.
     * @param string                $endpointUrl Endpoint URL, used only to resolve deliveries
     *                                           frozen before endpoint ids existed.
     * @return array<string, string>
     */
    public static function withProtocolHeaders(
        array $headers,
        string $endpointId,
        string $body,
        string $deliveryId,
        string $endpointUrl = ''
    ): array {
        $headers['User-Agent']      = 'WordPress/Convermetry ' . CVM_VERSION;
        $headers['Idempotency-Key'] = $deliveryId;

        $secret = $endpointId !== ''
            ? Options::secretForId($endpointId)
            : Options::secretFor($endpointUrl);

        if ($secret !== '') {
            $headers['X-Convermetry-Signature'] = 'sha256=' . hash_hmac('sha256', $body, $secret);
        }

        return $headers;
    }

    /**
     * Flattens a stored {key, value} pair list into an associative map,
     * skipping empty keys. Later duplicate keys override earlier ones.
     *
     * @param array<int, array{key?: string, value?: string}> $pairs Stored pair rows.
     * @return array<string, string>
     */
    private static function pairsToMap(array $pairs): array
    {
        $map = [];
        foreach ($pairs as $pair) {
            $key = trim((string) ($pair['key'] ?? ''));
            if ($key !== '') {
                $map[$key] = (string) ($pair['value'] ?? '');
            }
        }

        return $map;
    }
}
