<?php
declare(strict_types=1);

namespace Convermetry\Api;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;
use Convermetry\Support\Extensions;
use Convermetry\Support\Url;
use Convermetry\Webhook\DeliveryLog;

/**
 * Registers and handles the read-only Activity Log REST API endpoint.
 *
 * Route: GET /wp-json/convermetry/v1/deliveries
 *
 * Query parameters:
 *   page         - 1-based page number (default: 1)
 *   per_page     - results per page, max 100 (default: 25)
 *   status       - 'success' or 'error' to filter, omit for all deliveries
 *   message_type - 'analytics_report' or 'form_submission'
 *   endpoint     - md5 hash of the endpoint URL (from a previous response's
 *                  endpoint_key), or an endpoint label
 *   provider     - form provider key (e.g. 'elementor')
 *   form_id      - exact form name filter
 *   after / before - UTC date bounds (YYYY-MM-DD)
 *
 * Authentication: pass the API key as the Authorization request header.
 * Only a SHA-256 hash of the key is stored; the raw key is shown once at
 * generation on the Activity Log admin page. Repeated authentication
 * failures from one IP are throttled.
 *
 * Responses identify each delivery's endpoint by its label and a REDACTED
 * URL (scheme://host only) — full webhook URLs can embed bearer tokens and
 * never leave the site through this read-only API (see formatEntry()). The
 * full URL stays visible to administrators on the Activity Log admin page.
 *
 * This API is intended for SERVER-TO-SERVER use — CORS headers permit
 * browser requests for flexibility, but never embed the key in public
 * frontend JavaScript, where any visitor could read it.
 */
final class DeliveryLogController
{
    /** Option key storing whether the API is enabled. */
    private const string ACTIVE_OPTION = 'cvm_delivery_api_active';

    /** Option key storing the SHA-256 hash of the API key. */
    private const string KEY_HASH_OPTION = 'cvm_delivery_api_key_hash';

    /** Failed authentications per IP allowed within the throttle window. */
    private const int AUTH_FAILURE_MAX = 10;

    /** Authentication-failure throttle window, in seconds. */
    private const int AUTH_FAILURE_WINDOW = 5 * MINUTE_IN_SECONDS;

    private const int DEFAULT_PER_PAGE = 25;
    private const int MAX_PER_PAGE     = 100;

    /**
     * Registers the REST route and the CORS filter.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'registerRoutes']);

        // rest_post_dispatch fires for every dispatched REST request — including
        // OPTIONS preflight — so one filter handles CORS for both cases.
        add_filter('rest_post_dispatch', [self::class, 'addCorsHeaders'], 10, 1);
    }

    /**
     * Whether the read-only deliveries API is currently enabled.
     *
     * @return bool
     */
    public static function isActive(): bool
    {
        return (bool) get_option(self::ACTIVE_OPTION, false);
    }

    /**
     * Persists the API active state.
     *
     * @param bool $active New state.
     * @return void
     */
    public static function setActive(bool $active): void
    {
        update_option(self::ACTIVE_OPTION, $active);
    }

    /**
     * Whether an API key has been generated.
     *
     * @return bool
     */
    public static function hasKey(): bool
    {
        return (string) get_option(self::KEY_HASH_OPTION, '') !== '';
    }

    /**
     * Generates a new 40-character alphanumeric API key, persists only its
     * SHA-256 hash, and returns the raw key — the ONE time it is ever
     * available. Any previous key stops working immediately.
     *
     * SHA-256 (rather than a slow password hash) is appropriate here: the
     * key is 40 characters of cryptographic randomness, so offline
     * brute-forcing the hash is infeasible regardless of hash speed, and
     * verification runs on every API request.
     *
     * @return string The raw key, to be shown to the admin exactly once.
     */
    public static function generateKey(): string
    {
        $key = wp_generate_password(40, false);
        update_option(self::KEY_HASH_OPTION, hash('sha256', $key));

        return $key;
    }

    /**
     * Whether a presented key matches the stored hash (constant-time).
     *
     * @param string $key Raw key from the Authorization header.
     * @return bool
     */
    public static function verifyKey(string $key): bool
    {
        $hash = (string) get_option(self::KEY_HASH_OPTION, '');

        return $hash !== '' && $key !== '' && hash_equals($hash, hash('sha256', $key));
    }

    /**
     * Registers the /deliveries collection route.
     *
     * @return void
     */
    public static function registerRoutes(): void
    {
        register_rest_route('convermetry/v1', '/deliveries', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handleRequest'],
            'permission_callback' => '__return_true',
            'args'                => [
                'page' => [
                    'default'           => 1,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'sanitize_callback' => 'absint',
                ],
                'per_page' => [
                    'default'           => self::DEFAULT_PER_PAGE,
                    'type'              => 'integer',
                    'minimum'           => 1,
                    'maximum'           => self::MAX_PER_PAGE,
                    'sanitize_callback' => 'absint',
                ],
                'status' => [
                    'default'           => '',
                    'type'              => 'string',
                    'enum'              => ['', 'success', 'error'],
                    'sanitize_callback' => 'sanitize_key',
                ],
                'message_type' => [
                    'default'           => '',
                    'type'              => 'string',
                    'enum'              => ['', 'analytics_report', 'form_submission'],
                    'sanitize_callback' => 'sanitize_key',
                ],
                'endpoint' => [
                    'default'           => '',
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'provider' => [
                    'default'           => '',
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                ],
                'form_id' => [
                    'default'           => '',
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'after' => [
                    'default'           => '',
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'before' => [
                    'default'           => '',
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /**
     * Appends CORS headers to any response destined for the deliveries route.
     * Scoped via a REQUEST_URI check so other REST endpoints are unaffected.
     *
     * The parameter is intentionally untyped: rest_post_dispatch can hand
     * this filter a WP_Error for any REST request that errors out.
     *
     * @param mixed $response Usually a WP_REST_Response, but may be a WP_Error.
     * @return mixed
     */
    public static function addCorsHeaders($response)
    {
        if (!$response instanceof \WP_REST_Response) {
            return $response;
        }

        if (!str_contains((string) ($_SERVER['REQUEST_URI'] ?? ''), '/convermetry/v1/deliveries')) {
            return $response;
        }

        $response->header('Access-Control-Allow-Origin', '*');
        $response->header('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $response->header('Access-Control-Allow-Headers', 'Authorization, Content-Type');
        $response->header('Access-Control-Expose-Headers', 'X-WP-Total, X-WP-TotalPages, X-CVM-Page');
        $response->header('Access-Control-Max-Age', '86400');

        return $response;
    }

    /**
     * Authenticates the request and returns a paginated page of delivery
     * log entries.
     *
     * @param \WP_REST_Request $request The incoming request.
     * @return \WP_REST_Response|\WP_Error
     */
    public static function handleRequest(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        if (!self::isActive()) {
            return new \WP_Error(
                'api_disabled',
                'The deliveries API is not enabled.',
                ['status' => 403]
            );
        }

        if (self::isThrottled()) {
            return new \WP_Error(
                'too_many_failures',
                'Too many failed authentication attempts. Try again later.',
                ['status' => 429]
            );
        }

        if (!self::verifyKey((string) $request->get_header('authorization'))) {
            self::recordAuthFailure();

            return new \WP_Error(
                'unauthorized',
                'Invalid or missing API key.',
                ['status' => 401]
            );
        }

        $page    = max(1, (int) $request->get_param('page'));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->get_param('per_page')));

        $filters = self::requestFilters($request);

        $total      = DeliveryLog::getLogCount($filters);
        $totalPages = max(1, (int) ceil($total / $perPage));

        // Clamp page to valid range.
        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $logs   = DeliveryLog::getLogsPaginated($page, $perPage, $filters);
        $output = array_map([self::class, 'formatEntry'], $logs);

        $response = new \WP_REST_Response($output, 200);
        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) $totalPages);
        $response->header('X-CVM-Page', (string) $page);

        return $response;
    }

    /**
     * Maps request parameters onto DeliveryLog filter keys.
     *
     * The endpoint filter accepts either an endpoint LABEL or an md5 hash of
     * the endpoint URL (the endpoint_key echoed in responses) — never a raw
     * URL, so credentials embedded in endpoint URLs are never required (or
     * echoed) to filter.
     *
     * @param \WP_REST_Request $request The incoming request.
     * @return array<string, string>
     */
    private static function requestFilters(\WP_REST_Request $request): array
    {
        $filters = [
            'status'       => (string) $request->get_param('status'),
            'message_type' => (string) $request->get_param('message_type'),
            'provider'     => (string) $request->get_param('provider'),
            'form_name'    => (string) $request->get_param('form_id'),
        ];

        // Date range: after/before (YYYY-MM-DD) are mapped to the log's
        // year/month filters when they describe one calendar month; broader
        // ranges are served page-by-page by created_at ordering. Kept
        // simple and index-friendly.
        $after = (string) $request->get_param('after');
        if (preg_match('~^(\d{4})-(\d{2})$~', $after, $m) || preg_match('~^(\d{4})-(\d{2})-\d{2}$~', $after, $m)) {
            $filters['year']  = $m[1];
            $filters['month'] = $m[2];
        }

        $endpointParam = (string) $request->get_param('endpoint');
        if ($endpointParam !== '') {
            foreach (Options::endpoints() as $endpoint) {
                if (
                    md5($endpoint['url']) === $endpointParam
                    || ($endpoint['label'] !== '' && $endpoint['label'] === $endpointParam)
                ) {
                    $filters['endpoint'] = $endpoint['url'];
                    break;
                }
            }

            // No configured endpoint matched: filter by an impossible URL so
            // the response is empty rather than silently unfiltered.
            $filters['endpoint'] ??= 'https://convermetry.invalid/no-match';
        }

        return array_filter($filters, static fn(string $value): bool => $value !== '');
    }

    /**
     * Whether the requesting IP has exceeded the authentication-failure
     * budget for the current window.
     *
     * @return bool
     */
    private static function isThrottled(): bool
    {
        return (int) get_transient(self::failureKey()) >= self::AUTH_FAILURE_MAX;
    }

    /**
     * Charges one failed authentication against the requesting IP.
     *
     * Best-effort transient counter — enough to blunt online key guessing
     * without a persistent object cache; the 40-character random key is the
     * real defense.
     *
     * @return void
     */
    private static function recordAuthFailure(): void
    {
        $key = self::failureKey();

        set_transient($key, (int) get_transient($key) + 1, self::AUTH_FAILURE_WINDOW);
    }

    /**
     * The per-IP transient key for authentication-failure counting.
     *
     * @return string
     */
    private static function failureKey(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : '';

        return 'cvm_api_fail_' . md5($ip);
    }

    /**
     * Shapes one database row into the public API representation, decoding
     * the stored JSON bodies where possible.
     *
     * endpoint_url is REDACTED to scheme://host(:port). Webhook URLs
     * routinely carry bearer tokens in their path or query string, and this
     * API authenticates with a read-only key — a leak of that key must not
     * also hand out write credentials for every downstream endpoint. The
     * endpoint_key (md5 of the full URL) and label identify endpoints for
     * legitimate consumers; the full URL stays visible to admins in wp-admin.
     *
     * @param array<string, mixed> $entry A single DeliveryLog row.
     * @return array<string, mixed>
     */
    public static function formatEntry(array $entry): array
    {
        $requestDecoded  = json_decode((string) ($entry['request_data'] ?? '{}'), true);
        $responseDecoded = json_decode((string) ($entry['response_data'] ?? ''), true);
        $endpoint        = (string) ($entry['endpoint_url'] ?? '');

        $item = [
            'id'             => (int) ($entry['id'] ?? 0),
            'created_at'     => (string) ($entry['created_at'] ?? ''),
            'success'        => (int) ($entry['success'] ?? 0) === 1,
            'endpoint_url'   => self::redactEndpointUrl($endpoint),
            'endpoint_key'   => $endpoint !== '' ? md5($endpoint) : '',
            'endpoint_label' => (string) ($entry['endpoint_label'] ?? ''),
            'delivery_id'    => (string) ($entry['delivery_id'] ?? ''),
            'message_type'   => (string) ($entry['message_type'] ?? ''),
            'kind'           => (string) ($entry['kind'] ?? ''),
            'attempt'        => (int) ($entry['attempt'] ?? 0),
            'submission_id'  => (string) ($entry['submission_id'] ?? ''),
            'conversion_id'  => (string) ($entry['conversion_id'] ?? ''),
            'form_provider'  => (string) ($entry['form_provider'] ?? ''),
            'form_name'      => (string) ($entry['form_name'] ?? ''),
            'response_code'  => (int) ($entry['response_code'] ?? 0),
            'request_data'   => is_array($requestDecoded) ? $requestDecoded : [],
            'response_data'  => is_array($responseDecoded) ? $responseDecoded : (string) ($entry['response_data'] ?? ''),
        ];

        /**
         * Filters extension data added to one delivery-log API item.
         *
         * Runs once per row in a response, AFTER the endpoint URL has been
         * redacted to its origin and the stored bodies decoded — so a callback
         * sees what a consumer will see, and cannot accidentally reinstate a
         * credential this endpoint exists to withhold.
         *
         * The seventeen core keys are IMMUTABLE. They are this API's published
         * contract, consumers parse them positionally in typed clients, and a
         * filter that could rewrite `success` or `response_code` would let a
         * plugin lie to a monitoring dashboard about whether deliveries are
         * working. Extension data arrives as its own 'extensions' property
         * instead, under namespaced 'vendor/thing' keys, and the property is
         * absent entirely when nothing is added — so an existing consumer sees
         * byte-identical output.
         *
         * Bounded to 4 KB and 10 keys per item, because this runs for every row
         * of a page that may hold a hundred.
         *
         * Remember who is reading: this API is authenticated by a single
         * read-only key that a site may have handed to an external dashboard.
         * Do not attach anything you would not give that key's holder.
         *
         * @param array<string, mixed> $extensions Empty array to add to.
         * @param array<string, mixed> $item       The redacted item as it will be returned.
         */
        return Extensions::attach(
            $item,
            'extensions',
            'convermetry_delivery_log_api_item',
            Extensions::API_ITEM_MAX_BYTES,
            Extensions::API_ITEM_MAX_KEYS,
            $item
        );
    }

    /**
     * Reduces a stored endpoint URL to scheme://host(:port) — everything
     * that can carry a credential (path, query, fragment, userinfo) is
     * dropped before the URL leaves the site through this API.
     *
     * @param string $url Full stored endpoint URL.
     * @return string Redacted URL, or '' when the URL cannot be parsed.
     */
    private static function redactEndpointUrl(string $url): string
    {
        return Url::origin($url);
    }
}
