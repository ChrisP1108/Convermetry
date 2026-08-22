<?php
declare(strict_types=1);

namespace Convermetry\Forms;

if (!defined('ABSPATH')) exit;

use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Settings\Options;
use Convermetry\Support\Http;
use Convermetry\Tracking\Correlation;
use Convermetry\Webhook\DeliveryLog;
use Convermetry\Webhook\FormDeliveryQueue;
use Convermetry\Webhook\PayloadBuilder;
use Convermetry\Webhook\RequestFactory;

/**
 * The one pipeline every server-confirmed form submission flows through —
 * built-in providers, third-party provider adapters, the
 * 'convermetry_form_submission' action, and convermetry_submit_form() all
 * end up in {@see record()}.
 *
 * For one confirmed submission the pipeline:
 *
 *  1. Checks the form is not excluded (per-form configuration).
 *  2. Extracts the tracker's correlation fields from the request (or
     *  accepts a caller-provided {@see Correlation}), strips them from the
 *     submission data, and sanitizes every field value.
 *  3. Records the confirmed conversion as a form_success analytics event
 *     under the SAME conversion_id the frontend tracker holds for this
 *     submission attempt — so the two detection paths deduplicate into one
 *     conversion in every report.
 *  4. Inserts the durable submission record (deduplicated by conversion_id,
 *     so a double-fired provider callback records once).
 *  5. Queues one background webhook delivery per form endpoint and returns
 *     immediately (default) — or delivers synchronously when the caller
 *     needs the real result (Elementor 'show_error' mode,
 *     convermetry_submit_form()).
 *
 * The visitor's request is never delayed by payload building, analytics
 * enrichment, or external HTTP in the default background mode.
 */
final class SubmissionService
{
    /**
     * Handles a server-confirmed submission from a provider or the public API.
     *
     * @param string           $provider       Provider key (e.g. 'elementor', 'custom').
     * @param string           $nativeId       Provider-native form identity.
     * @param string           $formName       Human-readable form name.
     * @param array<string, mixed> $fields     Raw submitted fields (Convermetry's internal
     *                                         cvm_* fields may still be present; they are stripped).
     * @param Correlation|null $correlation    Correlation data; null extracts it from the current request.
     * @param bool             $sync           Deliver synchronously and report the real result
     *                                         (no automatic retries) instead of queuing.
     * @param array<string, mixed>  $runtimeQuery   Extra query parameters for this submission only.
     * @param array<string, string> $runtimeHeaders Extra request headers for this submission only.
     * @param string           $identity       The identity per-form settings are keyed by, when it
     *                                         differs from the native id.
     * @param string           $legacyIdentity A previous identity the same form's settings may still
     *                                         be stored under (Elementor moved from form NAME to
     *                                         widget id). Used only when the current key has no
     *                                         stored configuration; never written to.
     * @return SubmissionResult
     */
    public function record(
        string $provider,
        string $nativeId,
        string $formName,
        array $fields,
        ?Correlation $correlation = null,
        bool $sync = false,
        array $runtimeQuery = [],
        array $runtimeHeaders = [],
        string $identity = '',
        string $legacyIdentity = ''
    ): SubmissionResult {
        $provider       = sanitize_key($provider);
        $formName       = sanitize_text_field($formName);
        $nativeId       = sanitize_text_field($nativeId);
        $identity       = sanitize_text_field($identity);
        $legacyIdentity = sanitize_text_field($legacyIdentity);

        $formKey = FormProviderRegistry::formKey(
            $provider,
            $identity !== '' ? $identity : ($nativeId !== '' ? $nativeId : $formName)
        );

        // Resolved once, here, and then frozen onto the submission row — every
        // later reader (queue freeze, retries, payload build) works from the
        // stored key, so a settings migration mid-flight cannot change which
        // configuration an already-accepted submission was recorded under.
        if ($legacyIdentity !== '') {
            $formKey = FormSettings::resolveKey(
                $formKey,
                FormProviderRegistry::formKey($provider, $legacyIdentity)
            );
        }

        if (FormSettings::isExcluded($formKey)) {
            return new SubmissionResult(ok: false, msg: 'This form is excluded from Convermetry by the current settings.');
        }

        $correlation ??= Correlation::fromCurrentRequest();

        $submissionData = $this->sanitizeFields(Correlation::stripInternalFields($fields));
        $device         = wp_is_mobile() ? 'mobile' : 'desktop';
        $page           = $this->pageInfo($correlation);

        // The confirmed conversion, recorded through the shared analytics
        // write path under the tracker's own conversion token — the frontend
        // form_success event (if it also fires) carries the same id, so
        // every conversion report deduplicates the two into one.
        $this->recordConversionEvent($provider, $formName, $correlation);

        $submissionId = 's' . substr(md5(wp_generate_uuid4() . wp_rand()), 0, 20);

        $context = $correlation->toAnalyticsContext($device);

        $rowId = FormSubmissions::insert([
            'submission_id'   => $submissionId,
            'conversion_id'   => $correlation->conversionId,
            'session_id'      => $correlation->sessionId,
            'provider'        => $provider,
            'form_key'        => $formKey,
            'form_name'       => $formName,
            'native_form_id'  => $nativeId,
            'form_id'         => FormSettings::effectiveFormId($formKey, $nativeId),
            'page_url'        => $page['url'],
            'page_query'      => $page['query'],
            'submission_data' => $submissionData,
            'context'         => $context,
            'runtime'         => [
                'query'   => $this->scalarMap($runtimeQuery),
                'headers' => $this->scalarMap($runtimeHeaders),
            ],
        ]);

        if ($rowId === null) {
            // Duplicate conversion_id: this exact browser submission was
            // already recorded (a double-fired provider callback, a replayed
            // AJAX request). Its deliveries are already queued/sent — report
            // success without doing anything twice.
            $existing = $this->findByConversionId($correlation->conversionId);
            if ($existing !== null) {
                return new SubmissionResult(
                    ok: true,
                    submissionId: (string) $existing['submission_id'],
                    conversionId: $correlation->conversionId,
                    msg: '',
                    queued: true
                );
            }

            return new SubmissionResult(ok: false, msg: 'The submission could not be recorded.');
        }

        /**
         * Fires after a form submission has been recorded by Convermetry,
         * before webhook delivery.
         *
         * @param string               $submissionId The submission's globally unique id.
         * @param string               $conversionId The conversion id shared with analytics.
         * @param array<string, mixed> $context      The captured analytics context.
         */
        do_action('convermetry_submission_recorded', $submissionId, $correlation->conversionId, $context);

        if (!Options::webhooksActive() || Options::formEndpoints() === []) {
            // No delivery configured — the submission and conversion are
            // still recorded for analytics; nothing to send.
            return new SubmissionResult(
                ok: true,
                submissionId: $submissionId,
                conversionId: $correlation->conversionId,
                queued: false
            );
        }

        if ($sync) {
            return $this->dispatchSync($rowId, $submissionId, $correlation->conversionId);
        }

        FormDeliveryQueue::enqueue($rowId, $submissionId);

        return new SubmissionResult(
            ok: true,
            submissionId: $submissionId,
            conversionId: $correlation->conversionId,
            queued: true
        );
    }

    /**
     * The public custom-form entry point behind convermetry_submit_form()
     * and the 'convermetry_form_submission' action.
     *
     * @param array<string, mixed>  $formIdentifier ['form_name' => string, 'form_id' => string (optional native id)].
     * @param array<string, mixed>  $fields         Field names/IDs to raw values.
     * @param array<string, mixed>  $urlQuery       Extra query parameters for this call only.
     * @param array<string, string> $requestHeaders Extra headers for this call only.
     * @param bool                  $sync           True for a result-aware synchronous run.
     * @return SubmissionResult
     */
    public function submitCustom(
        array $formIdentifier,
        array $fields,
        array $urlQuery = [],
        array $requestHeaders = [],
        bool $sync = false
    ): SubmissionResult {
        $formName = (string) ($formIdentifier['form_name'] ?? '');
        if ($formName === '') {
            return new SubmissionResult(ok: false, msg: 'A form_name is required.');
        }

        return $this->record(
            provider: 'custom',
            nativeId: (string) ($formIdentifier['form_id'] ?? ''),
            formName: $formName,
            fields: $fields,
            correlation: null,
            sync: $sync,
            runtimeQuery: $urlQuery,
            runtimeHeaders: $requestHeaders
        );
    }

    /**
     * Delivers one recorded submission synchronously to every form endpoint
     * and returns the real result.
     *
     * Used by the 'show_error' failure mode and convermetry_submit_form().
     * Failed synchronous deliveries are NOT queued for automatic retry — the
     * caller receives them in the result and decides (mirroring the legacy
     * result-aware contract). Every attempt is logged in the Activity Log.
     *
     * @param int    $rowId        Submission row id.
     * @param string $submissionId The submission's globally unique id.
     * @param string $conversionId The conversion id shared with analytics.
     * @return SubmissionResult
     */
    private function dispatchSync(int $rowId, string $submissionId, string $conversionId): SubmissionResult
    {
        $submission = FormSubmissions::get($rowId);
        if ($submission === null) {
            return new SubmissionResult(ok: false, submissionId: $submissionId, conversionId: $conversionId, msg: 'The submission record could not be loaded.');
        }

        $submission = FormDeliveryQueue::enrichContext($submission);

        $payload = PayloadBuilder::formSubmission($submission);
        $runtime = $this->decodeRuntime($submission);

        $formKey      = (string) ($submission['form_key'] ?? '');
        $decodedQuery = json_decode((string) ($submission['page_query'] ?? ''), true);
        $pageQuery    = is_array($decodedQuery) ? $decodedQuery : [];

        $overallOk        = true;
        $lastStatus       = 0;
        $lastData         = null;
        $failedDeliveries = [];

        foreach (Options::formEndpoints() as $endpoint) {
            $deliveryId = FormDeliveryQueue::deliveryId($endpoint['url'], $submissionId);

            $payload['delivery_id'] = $deliveryId;

            $encoded = wp_json_encode($payload);

            $requestUrl = RequestFactory::buildUrl($endpoint['url'], $formKey, $pageQuery, $runtime['query']);
            $headers    = RequestFactory::buildHeaders($formKey, $runtime['headers']);

            if (!is_string($encoded) || $encoded === '') {
                $result = ['ok' => false, 'code' => 0, 'message' => 'Payload could not be JSON-encoded', 'body' => ''];
                $encoded = '';
            } else {
                // Built once and reused below for the log, so the Activity Log
                // records the headers actually sent rather than the base set.
                $headers = RequestFactory::withProtocolHeaders(
                    $headers,
                    (string) ($endpoint['id'] ?? ''),
                    $encoded,
                    $deliveryId,
                    $endpoint['url']
                );

                $result = Http::postJson($requestUrl, $encoded, $headers);
            }

            DeliveryLog::log([
                'ok'              => $result['ok'],
                'endpoint_url'    => $endpoint['url'],
                'endpoint_label'  => $endpoint['label'],
                'delivery_id'     => $deliveryId,
                'message_type'    => 'form_submission',
                'kind'            => 'immediate',
                'attempt'         => 1,
                'submission_id'   => $submissionId,
                'conversion_id'   => $conversionId,
                'form_provider'   => (string) ($submission['provider'] ?? ''),
                'form_name'       => (string) ($submission['form_name'] ?? ''),
                'form_id'         => (string) ($submission['form_id'] ?? ''),
                'request_url'     => $requestUrl,
                'request_headers' => $headers,
                'request_data'    => $encoded,
                'response_code'   => (int) $result['code'],
                'response_data'   => (string) $result['body'],
                'message'         => (string) $result['message'],
            ]);

            $lastStatus = (int) $result['code'];

            // An empty body yields null; a valid JSON body is decoded (including
            // a literal "null"); anything else is kept as the raw string.
            $responseBody = (string) $result['body'];
            $decodedBody  = $responseBody !== '' ? json_decode($responseBody, true) : null;
            $lastData     = $responseBody === ''
                ? null
                : (json_last_error() === JSON_ERROR_NONE ? $decodedBody : $responseBody);

            if (!$result['ok']) {
                $overallOk          = false;
                $failedDeliveries[] = [
                    'url'          => $requestUrl,
                    'endpoint_url' => $endpoint['url'],
                    'headers'      => $headers,
                    'body'         => $encoded,
                    'label'        => $endpoint['label'],
                ];
            }
        }

        return new SubmissionResult(
            ok: $overallOk,
            submissionId: $submissionId,
            conversionId: $conversionId,
            status: $lastStatus,
            msg: $overallOk ? '' : 'There was an issue submitting the form data through the webhook.',
            data: $lastData,
            queued: false,
            failedDeliveries: $failedDeliveries
        );
    }

    /**
     * Records the server-confirmed conversion as a form_success analytics
     * event, honoring the same tracking gates as the frontend tracker:
     * the event type must be enabled, logged-in users are skipped when
     * excluded, and privacy signals are honored when the site opted in
     * (webhook delivery is unaffected — those gates govern analytics only).
     *
     * @param string      $provider    Provider key.
     * @param string      $formName    Form name (becomes element_label).
     * @param Correlation $correlation Correlation data (conversion id, session, attribution).
     * @return void
     */
    private function recordConversionEvent(string $provider, string $formName, Correlation $correlation): void
    {
        if (!Options::isTypeEnabled('form_success')) {
            return;
        }

        if (Options::excludeLoggedIn() && is_user_logged_in()) {
            return;
        }

        if (Options::respectDnt() && $this->requestSendsPrivacySignal()) {
            return;
        }

        DatabaseManager::insertEvent('form_success', array_merge($correlation->attribution, [
            'event_value'      => $correlation->conversionId,
            'session_id'       => $correlation->sessionId,
            'page_url'         => $correlation->pageUrl,
            'element_tag'      => 'form',
            'element_label'    => $formName !== '' ? $formName : $provider,
            'device'           => wp_is_mobile() ? 'mobile' : 'desktop',
            'session_referrer' => $correlation->sessionReferrer,
            'session_direct'   => $correlation->sessionDirect,
        ]));
    }

    /**
     * Whether the current request carries a Do Not Track / Global Privacy
     * Control header.
     *
     * @return bool
     */
    private function requestSendsPrivacySignal(): bool
    {
        return ($_SERVER['HTTP_DNT'] ?? '') === '1' || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1';
    }

    /**
     * Sanitizes submitted field values, preserving array structure for
     * multi-value fields such as checkbox groups.
     *
     * @param array<string, mixed> $fields Raw fields.
     * @return array<string, mixed>
     */
    private function sanitizeFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $key => $value) {
            $cleanKey = sanitize_text_field((string) $key);
            if ($cleanKey === '') {
                continue;
            }

            if (is_array($value)) {
                $out[$cleanKey] = array_map(
                    static fn(mixed $item): string => sanitize_text_field(is_scalar($item) ? (string) $item : ''),
                    array_values($value)
                );
            } else {
                $out[$cleanKey] = sanitize_text_field(is_scalar($value) ? (string) $value : '');
            }
        }

        return $out;
    }

    /**
     * The submitting page's URL and query parameters.
     *
     * The tracker-supplied page URL (same-host validated) wins; the HTTP
     * referrer fills the gaps — AJAX form plugins submit from the page the
     * form is on, so the referrer reliably carries the originating URL and
     * its query string (the legacy behavior this preserves).
     *
     * @param Correlation $correlation Correlation data.
     * @return array{url: string, query: array<string, string>}
     */
    private function pageInfo(Correlation $correlation): array
    {
        $referer = isset($_SERVER['HTTP_REFERER'])
            ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_REFERER']))
            : '';

        $url   = $correlation->pageUrl;
        $query = [];

        if ($referer !== '') {
            $parts = wp_parse_url($referer);

            if ($url === '' && is_array($parts) && !empty($parts['host'])) {
                $scheme = strtolower((string) ($parts['scheme'] ?? ''));
                if ($scheme === 'http' || $scheme === 'https') {
                    $url = esc_url_raw(
                        $scheme . '://' . $parts['host']
                        . (isset($parts['port']) ? ':' . (int) $parts['port'] : '')
                        . ($parts['path'] ?? '')
                    );
                }
            }

            if (is_array($parts) && !empty($parts['query'])) {
                $rawParams = [];
                wp_parse_str((string) $parts['query'], $rawParams);
                foreach ($rawParams as $key => $value) {
                    $cleanKey = sanitize_text_field((string) $key);
                    if ($cleanKey !== '' && is_scalar($value)) {
                        $query[$cleanKey] = sanitize_text_field((string) $value);
                    }
                }
            }
        }

        return ['url' => $url, 'query' => $query];
    }

    /**
     * Reduces a caller-supplied map to scalar string values.
     *
     * @param array<string, mixed> $map Raw map.
     * @return array<string, string>
     */
    private function scalarMap(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            if (is_scalar($value) && (string) $key !== '') {
                $out[sanitize_text_field((string) $key)] = sanitize_text_field((string) $value);
            }
        }

        return $out;
    }

    /**
     * Decodes a submission row's stored runtime overrides.
     *
     * @param array<string, mixed> $submission Submission row.
     * @return array{query: array<string, string>, headers: array<string, string>}
     */
    private function decodeRuntime(array $submission): array
    {
        $raw = (string) ($submission['runtime'] ?? '');

        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return [
                    'query'   => is_array($decoded['query'] ?? null) ? $decoded['query'] : [],
                    'headers' => is_array($decoded['headers'] ?? null) ? $decoded['headers'] : [],
                ];
            }
        }

        return ['query' => [], 'headers' => []];
    }

    /**
     * Looks up an existing submission row by conversion id (the dedup path).
     *
     * @param string $conversionId Conversion id.
     * @return array<string, mixed>|null
     */
    private function findByConversionId(string $conversionId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT submission_id FROM ' . FormSubmissions::tableName() . ' WHERE conversion_id = %s',
                $conversionId
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }
}
