<?php
declare(strict_types=1);

namespace Convermetry\Forms;

if (!defined('ABSPATH')) exit;

use Convermetry\Analytics\SubmissionContext;
use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Database\NewSubmission;
use Convermetry\Settings\Options;
use Convermetry\Support\ClientIp;
use Convermetry\Support\Extensions;
use Convermetry\Support\Http;
use Convermetry\Support\PrivacySignal;
use Convermetry\Tracking\Correlation;
use Convermetry\Webhook\DeliveryContext;
use Convermetry\Webhook\DeliveryDetails;
use Convermetry\Webhook\DeliveryKind;
use Convermetry\Webhook\DeliveryLogEntry;
use Convermetry\Webhook\MessageType;
use Convermetry\Webhook\TransportResult;
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
     * @param array<mixed>     $fields         Raw submitted fields, in either accepted shape:
     *                                         a list of ['id' => …, 'label' => …, 'value' => …]
     *                                         descriptors (what the bundled providers build), or a
     *                                         historical 'name' => value map (what third-party
     *                                         callers still pass). {@see SubmissionFields::normalize()}
     *                                         canonicalizes both and strips Convermetry's internal
     *                                         cvm_* fields from either.
     * @param Correlation|null $correlation    Correlation data; null extracts it from the current request.
     * @param bool             $sync           Deliver synchronously and report the real result
     *                                         (no automatic retries) instead of queuing.
     * @param array<string, mixed>  $runtimeQuery   Extra query parameters for this submission only.
     * @param array<string, string> $runtimeHeaders Extra request headers for this submission only.
     * @param string           $identity       The identity per-form settings are keyed by, when it
     *                                         differs from the native id (e.g. Elementor keys by
     *                                         form NAME while the widget id travels as native_form_id).
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
        string $identity = ''
    ): SubmissionResult {
        $provider = sanitize_key($provider);
        $formName = sanitize_text_field($formName);
        $nativeId = sanitize_text_field($nativeId);
        $identity = sanitize_text_field($identity);
        $formKey  = FormProviderRegistry::formKey(
            $provider,
            $identity !== '' ? $identity : ($nativeId !== '' ? $nativeId : $formName)
        );

        if (FormSettings::isExcluded($formKey)) {
            return new SubmissionResult(ok: false, msg: 'This form is excluded from Convermetry by the current settings.');
        }

        $correlation ??= Correlation::fromCurrentRequest();

        // One normalizer owns the shape, the sanitizing, and the cvm_* strip,
        // for both the descriptor lists the providers now build and the
        // historical maps third-party callers still pass.
        $submissionData = SubmissionFields::normalize($fields);

        /**
         * Filters a submission's normalized field descriptors.
         *
         * Runs once per submission, after normalization and before anything is
         * written — so a callback can redact, relabel, or drop a field before it
         * reaches the database, the webhook payload, or a notification email.
         *
         * $fields is a list of {id, label, value} descriptors, where value is a
         * string or a list of strings. It contains the visitor's submitted data:
         * this filter exists to customize exactly that, so PII is unavoidable
         * here in a way it deliberately is not for the observational actions.
         *
         * A changed result is passed through SubmissionFields::normalize() a
         * second time, so the descriptor shape and the cvm_* strip still hold
         * however the callback reshaped things — Convermetry's own tracking
         * fields can never be reintroduced as submitted data. Returning the
         * array unchanged skips that second pass entirely.
         *
         * @param list<array{id: string, label: string, value: string|list<string>}> $fields Normalized descriptors.
         * @param string $formKey  Provider-scoped form key.
         * @param string $provider Provider key (e.g. 'elementor').
         */
        $filteredFields = apply_filters('convermetry_submission_fields', $submissionData, $formKey, $provider);

        if ($filteredFields !== $submissionData) {
            $submissionData = SubmissionFields::normalize(is_array($filteredFields) ? $filteredFields : []);
        }

        /**
         * Filters whether to record a submission at all.
         *
         * Runs after the form identity is sanitized and the fields are
         * normalized — so a spam or business rule can inspect what was actually
         * submitted — and before ANY write happens. Returning false skips the
         * analytics conversion event, the submission row, the webhook queue
         * rows, and the notifications, in that order, by never reaching them.
         *
         * The visitor sees nothing. A skipped submission returns a successful,
         * empty result with no submission id and nothing queued, because the
         * synchronous integrations (Elementor's, notably) surface a failure
         * result to the person who just filled the form in — and someone whose
         * submission a site owner chose not to track has still submitted it
         * successfully as far as they are concerned.
         *
         * $fields carries the visitor's submitted values.
         *
         * @param bool   $should   Whether to record. Default true.
         * @param string $formKey  Provider-scoped form key.
         * @param string $provider Provider key.
         * @param list<array{id: string, label: string, value: string|list<string>}> $fields Normalized descriptors.
         */
        if (!apply_filters('convermetry_should_record_submission', true, $formKey, $provider, $submissionData)) {
            return new SubmissionResult(ok: true, submissionId: '', conversionId: '', msg: '', queued: false);
        }

        $device = wp_is_mobile() ? 'mobile' : 'desktop';
        $page   = $this->pageInfo($correlation);

        // The confirmed conversion, recorded through the shared analytics
        // write path under the tracker's own conversion token — the frontend
        // form_success event (if it also fires) carries the same id, so
        // every conversion report deduplicates the two into one.
        $this->recordConversionEvent($provider, $formName, $formKey, $correlation);

        $submissionId = 's' . substr(md5(wp_generate_uuid4() . wp_rand()), 0, 20);

        $context = $correlation->toAnalyticsContext($device);

        // Attached ONCE, here, before the context is persisted — never during
        // the later SubmissionContext::enrich() the delivery worker runs. A
        // per-delivery attach would let two endpoints receive different context
        // for the same submission, and a retry receive different context again.
        $context = Extensions::attach(
            $context,
            'extensions',
            'convermetry_submission_context_extensions',
            Extensions::CONTEXT_MAX_BYTES,
            Extensions::CONTEXT_MAX_KEYS,
            $formKey,
            $provider
        );

        // Captured here, in the visitor's own request. Delivery (and every
        // retry) runs later in a background worker where REMOTE_ADDR belongs
        // to cron, not the submitter — so the address is resolved once and
        // persisted rather than looked up at send time. forStorage() applies
        // the privacy gates: the submission is still recorded and delivered
        // for a DNT/GPC visitor, it just carries no address.
        $ipAddress = ClientIp::forStorage();

        // fromContext() derives the six denormalized attribution columns from
        // $context, so the Submissions page and the campaign / landing-page
        // lead reports can filter and group without decoding every row's JSON
        // blob — and so the two copies cannot be written out of step.
        $rowId = FormSubmissions::insert(NewSubmission::fromContext(
            submissionId: $submissionId,
            conversionId: $correlation->conversionId,
            sessionId: $correlation->sessionId,
            provider: $provider,
            formKey: $formKey,
            formName: $formName,
            nativeFormId: $nativeId,
            formId: FormSettings::effectiveFormId($formKey, $nativeId),
            pageUrl: $page['url'],
            ipAddress: $ipAddress,
            pageQuery: $page['query'],
            fields: $submissionData,
            context: $context,
            runtime: [
                'query'   => $this->scalarMap($runtimeQuery),
                'headers' => $this->scalarMap($runtimeHeaders),
            ],
        ));

        if ($rowId === null) {
            // Duplicate conversion_id: this exact browser submission was
            // already recorded (a double-fired provider callback, a replayed
            // AJAX request). Its deliveries are already queued/sent — report
            // success without doing anything twice.
            $existing = $this->findByConversionId($correlation->conversionId);
            if ($existing !== null) {
                /**
                 * Fires when a submission is recognised as a duplicate of one
                 * already recorded — a double-fired provider callback, or a
                 * replayed AJAX request.
                 *
                 * Nothing is written and nothing is re-queued: the original
                 * submission's deliveries and notifications are already in
                 * flight, and this action exists precisely so an integration can
                 * observe the duplicate WITHOUT repeating side effects. Do not
                 * use it to re-send anything.
                 *
                 * Fires on the duplicate request only; the original submission
                 * fired convermetry_submission_recorded on its own request.
                 *
                 * @param string $submissionId The ORIGINAL submission's id.
                 * @param string $conversionId The conversion id both share.
                 * @param string $formKey      Provider-scoped form key.
                 */
                do_action(
                    'convermetry_submission_duplicate',
                    (string) $existing['submission_id'],
                    $correlation->conversionId,
                    $formKey
                );

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

        /**
         * Fires immediately after convermetry_submission_recorded, with the
         * details that action deliberately does not carry.
         *
         * The older action's three arguments are a fixed contract that
         * notifications and third-party listeners already depend on, so rather
         * than appending to it, everything else lives here. Both fire on every
         * recorded submission, in this order, before webhook delivery is
         * considered — so a listener runs even on a site with no endpoints.
         *
         * $fields CONTAINS PERSONAL DATA: it is the visitor's submitted values,
         * sanitized and with Convermetry's own cvm_* fields stripped, but
         * otherwise exactly what they typed. Anything a listener does with it —
         * logging, forwarding, storing — inherits the site's obligations for
         * that data. If you only need to know that a submission happened, use
         * convermetry_submission_recorded instead.
         *
         * @param int    $rowId        Submission table row id.
         * @param string $submissionId The submission's globally unique id.
         * @param array{provider: string, form_key: string, form_name: string, native_id: string} $form Form identity.
         * @param list<array{id: string, label: string, value: string|list<string>}> $fields Sanitized fields (PII).
         */
        do_action(
            'convermetry_submission_recorded_details',
            $rowId,
            $submissionId,
            [
                'provider'  => $provider,
                'form_key'  => $formKey,
                'form_name' => $formName,
                'native_id' => $nativeId,
            ],
            $submissionData
        );

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
     * @param array<mixed>          $fields         Either the long-standing map of field names to raw
     *                                              values (['email' => 'a@b.com', 'name' => 'Ada']),
     *                                              which stays fully supported, or the richer list of
     *                                              ['id' => …, 'label' => …, 'value' => …] descriptors
     *                                              when the caller can supply a distinct human label.
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

        $submission = SubmissionContext::enrich($submission);

        $payload = PayloadBuilder::formSubmission($submission);
        $runtime = $this->decodeRuntime($submission);

        $formKey   = (string) ($submission['form_key'] ?? '');
        $pageQuery = json_validate((string) ($submission['page_query'] ?? ''))
            ? (array) json_decode((string) $submission['page_query'], true)
            : [];

        $overallOk        = true;
        $lastStatus       = 0;
        $lastData         = null;
        $failedDeliveries = [];

        foreach (Options::formEndpoints() as $endpoint) {
            $deliveryId = FormDeliveryQueue::deliveryId($endpoint->url, $submissionId);

            $payload['delivery_id'] = $deliveryId;

            $encoded = wp_json_encode($payload);

            // The synchronous path freezes nothing — one attempt per endpoint,
            // no retries — so composition and sending happen together and
            // convermetry_webhook_delivery_frozen never fires here.
            $context = DeliveryDetails::for(
                $endpoint->url,
                messageType: MessageType::FormSubmission,
                kind: DeliveryKind::Immediate,
                attempt: 1,
                deliveryId: $deliveryId,
                endpointLabel: $endpoint->label,
                submissionId: $submissionId,
                conversionId: $conversionId,
                formKey: $formKey,
            );

            $requestUrl = RequestFactory::buildUrl($endpoint->url, $formKey, $pageQuery, $runtime['query'], $context);
            $headers    = RequestFactory::buildHeaders($formKey, $runtime['headers'], $context);

            if (!is_string($encoded) || $encoded === '') {
                $encoded = '';
                $result  = TransportResult::failure('Payload could not be JSON-encoded');
            } else {
                $sendHeaders = RequestFactory::withProtocolHeaders($headers, $endpoint->url, $encoded, $deliveryId);

                DeliveryContext::beforeSend($context, $requestUrl, $sendHeaders, $encoded);
                $result = Http::postJson($requestUrl, $encoded, $sendHeaders, $context);
            }

            $logged = DeliveryLog::log(new DeliveryLogEntry(
                result: $result,
                endpointUrl: $endpoint->url,
                endpointLabel: $endpoint->label,
                deliveryId: $deliveryId,
                messageType: MessageType::FormSubmission,
                kind: DeliveryKind::Immediate,
                attempt: 1,
                requestUrl: $requestUrl,
                requestHeaders: $headers,
                requestData: $encoded,
                submissionId: $submissionId,
                conversionId: $conversionId,
                formProvider: (string) ($submission['provider'] ?? ''),
                formName: (string) ($submission['form_name'] ?? ''),
            ));

            $context = DeliveryContext::attempted($context, $result, $encoded !== '');
            DeliveryContext::attemptLogged($context, $logged);

            // Nothing is queued on this path, so there is no retry state to
            // commit before announcing success — and no chain action ever
            // follows: a failed synchronous delivery is reported to the caller
            // rather than retried.
            if ($result->ok) {
                DeliveryContext::succeeded($context);
            }

            $lastStatus = $result->code;
            $lastData   = $result->decodedBody();

            if (!$result->ok) {
                $overallOk          = false;
                $failedDeliveries[] = [
                    'url'          => $requestUrl,
                    'endpoint_url' => $endpoint->url,
                    'headers'      => $headers,
                    'body'         => $encoded,
                    'label'        => $endpoint->label,
                ];
            }
        }

        // Every endpoint has been attempted and nothing is queued, so this
        // records the synchronous path's final verdict on the submission row.
        FormSubmissions::refreshDeliveryState($submissionId);

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
     * The event carries form_key as well as the display name. That is what ties
     * this server-confirmed success back to the browser-observed lifecycle
     * (form_view → form_start → form_submit) for the same form, so the
     * abandonment report can compare like with like instead of matching on a
     * display name two different forms may share.
     *
     * @param string      $provider    Provider key.
     * @param string      $formName    Form name (becomes element_label).
     * @param string      $formKey     Provider-qualified form key (becomes form_key).
     * @param Correlation $correlation Correlation data (conversion id, session, attribution).
     * @return void
     */
    private function recordConversionEvent(
        string $provider,
        string $formName,
        string $formKey,
        Correlation $correlation
    ): void {
        if (!Options::isTypeEnabled('form_success')) {
            return;
        }

        if (Options::excludeLoggedIn() && is_user_logged_in()) {
            return;
        }

        if (Options::respectDnt() && $this->requestSendsPrivacySignal()) {
            return;
        }

        DatabaseManager::insertEvent('form_success', array_merge($correlation->attribution->toArray(), [
            'event_value'      => $correlation->conversionId,
            'session_id'       => $correlation->sessionId,
            'page_url'         => $correlation->pageUrl,
            'element_tag'      => 'form',
            'element_label'    => $formName !== '' ? $formName : $provider,
            'form_key'         => $formKey,
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
        return PrivacySignal::fromCurrentRequest();
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

        if ($raw !== '' && json_validate($raw)) {
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
