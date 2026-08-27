<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;
use Convermetry\Support\Url;

/**
 * The lifecycle vocabulary shared by every webhook delivery path.
 *
 * Six paths send Convermetry webhooks — scheduled analytics reports, analytics
 * retries, the analytics test button, the background form queue, synchronous
 * form delivery, and the form test button — and each used to describe itself
 * only through the Activity Log row it happened to write. Integrations need the
 * same events from all six, in the same shape, so both the context array and the
 * firing helpers live here rather than being reassembled slightly differently at
 * each site.
 *
 * **What the context never contains.** No signing secret, no Authorization
 * header, no credential-bearing endpoint URL, no request body, no submitted
 * fields, no response body, no IP address. `endpoint_origin` is the URL reduced
 * to scheme://host(:port) — webhook URLs routinely embed bearer tokens in their
 * path or query, and these actions are exactly what people wire to logging. The
 * endpoint is identified by `endpoint_key` (an md5 of the configured URL, the
 * same identifier the Activity Log and REST API use) and `endpoint_label`.
 *
 * **The lifecycle**, in firing order:
 *
 *     delivery_frozen        once per newly frozen logical delivery (never on a
 *                            frozen retry). Analytics freezes in memory; the
 *                            form queue freezes into a queue row.
 *     before_send            immediately before each real network attempt,
 *                            after signing and protocol headers exist.
 *     delivery_attempted     once per attempt, carrying the TRANSPORT result
 *                            only. transport_attempted is false for encode and
 *                            report-query failures that never reached the wire.
 *     delivery_attempt_logged  once per Activity Log write attempt.
 *     — state commits —
 *     then exactly one of:
 *     delivery_succeeded     after the authoritative state is committed.
 *     retry_scheduled        after the next attempt is persisted.
 *     retry_chain_exhausted  analytics only: the chain gave up, but the delivery
 *                            is RESUMABLE by the next scheduled dispatch.
 *     delivery_abandoned     form queue only: retries exhausted, row deleted —
 *                            genuinely terminal.
 *     delivery_canceled      the submission was deleted before the row could be
 *                            sent; the queue row was removed unsent.
 *
 * `delivery_attempted` deliberately does not report the retry/queue disposition:
 * at the moment it fires, no caller has decided one yet.
 *
 * **Listeners are not isolated from each other or from core.** A callback that
 * throws inside the form queue worker leaves its row claimed until the ten
 * minute claim timeout reclaims it — self-healing, but slow. Convermetry does
 * not wrap these actions in try/catch: silently swallowing a third-party fatal
 * would hide the bug that caused it.
 */
final class DeliveryContext
{
    /** Message type for scheduled/retried/tested analytics reports. */
    public const string ANALYTICS = 'analytics_report';

    /** Message type for form-submission deliveries. */
    public const string FORM = 'form_submission';

    /**
     * Builds the canonical context array for one delivery.
     *
     * Every documented key is always present, in a fixed order, so a listener
     * can index the array without defensive checks. Values the calling path
     * cannot know are zero-valued rather than absent.
     *
     * @param string               $endpointUrl Configured endpoint URL (never exposed; used for key/label/origin).
     * @param array<string, mixed> $parts       Whatever the calling path knows.
     * @return array<string, mixed>
     */
    public static function build(string $endpointUrl, array $parts = []): array
    {
        $kind = (string) ($parts['kind'] ?? '');

        return [
            'message_type'        => (string) ($parts['message_type'] ?? ''),
            'kind'                => $kind,
            'attempt'             => (int) ($parts['attempt'] ?? 0),
            'delivery_id'         => (string) ($parts['delivery_id'] ?? ''),
            'is_test'             => array_key_exists('is_test', $parts) ? (bool) $parts['is_test'] : $kind === 'test',
            'endpoint_key'        => $endpointUrl !== '' ? md5($endpointUrl) : '',
            'endpoint_label'      => array_key_exists('endpoint_label', $parts)
                ? (string) $parts['endpoint_label']
                : Options::endpointLabel($endpointUrl),
            'endpoint_origin'     => Url::origin($endpointUrl),
            'submission_id'       => (string) ($parts['submission_id'] ?? ''),
            'conversion_id'       => (string) ($parts['conversion_id'] ?? ''),
            'form_key'            => (string) ($parts['form_key'] ?? ''),
            'window_start'        => (int) ($parts['window_start'] ?? 0),
            'window_end'          => (int) ($parts['window_end'] ?? 0),
            'transport_attempted' => (bool) ($parts['transport_attempted'] ?? false),
            'disposition'         => (string) ($parts['disposition'] ?? ''),
        ];
    }

    /**
     * Returns a copy of $context with $parts overridden — for the per-attempt
     * fields a composition-time context cannot know.
     *
     * @param array<string, mixed> $context Context from {@see build()}.
     * @param array<string, mixed> $parts   Fields to override.
     * @return array<string, mixed>
     */
    public static function with(array $context, array $parts): array
    {
        foreach ($parts as $key => $value) {
            if (array_key_exists($key, $context)) {
                $context[$key] = $value;
            }
        }

        return $context;
    }

    /**
     * Announces that a delivery's body, URL, and headers are now fixed.
     *
     * @param array<string, mixed> $context Delivery context.
     * @param string               $storage 'memory' (analytics) or 'queue_row' (form queue).
     * @param int                  $bodyBytes Frozen body length in bytes.
     * @return void
     */
    public static function frozen(array $context, string $storage, int $bodyBytes): void
    {
        /**
         * Fires once per newly frozen logical delivery, when its body, URL, and
         * headers stop being negotiable.
         *
         * Does NOT fire on a retry of an already-frozen delivery: the whole
         * point of freezing is that retries resend the identical bytes. It also
         * does not fire on the synchronous form path or either test button,
         * neither of which freezes anything — those compose per send and never
         * retry.
         *
         * $storage distinguishes the two freezes, which are not equivalent:
         * 'memory' means an analytics delivery was composed in memory and will
         * be persisted only if a retry is scheduled; 'queue_row' means a form
         * delivery's frozen body, URL, and headers were verified written to its
         * queue row.
         *
         * Observational only — the frozen request cannot be modified here. Use
         * convermetry_webhook_payload, _payload_extensions, _query_args or
         * _headers, all of which run before this point.
         *
         * @param array<string, mixed> $context   Credential-free delivery context.
         * @param string               $storage   'memory' or 'queue_row'.
         * @param int                  $bodyBytes Frozen body length in bytes.
         */
        do_action('convermetry_webhook_delivery_frozen', $context, $storage, $bodyBytes);
    }

    /**
     * Announces an imminent network attempt.
     *
     * The caller hands over the real request so that the metadata can be derived
     * here — a listener receives sizes, a digest, and header *names*, never the
     * URL, the header values, or the body.
     *
     * @param array<string, mixed>  $context    Delivery context.
     * @param string                $requestUrl Full request URL (not exposed).
     * @param array<string, string> $headers    Full request headers (only names are exposed).
     * @param string                $body       Exact body bytes (only length/digest are exposed).
     * @return void
     */
    public static function beforeSend(array $context, string $requestUrl, array $headers, string $body): void
    {
        $meta = [
            'body_bytes'   => strlen($body),
            'body_sha256'  => hash('sha256', $body),
            'header_names' => array_values(array_map('strval', array_keys($headers))),
            'signed'       => isset($headers['X-Convermetry-Signature']),
        ];

        /**
         * Fires immediately before a real network request, after the signature
         * and protocol headers have been generated from the exact bytes to be
         * sent.
         *
         * Fires once per network attempt — so once per retry, and on both test
         * buttons. It does NOT fire when an attempt fails before the wire: a
         * payload that could not be JSON-encoded, or an analytics report whose
         * query failed, goes straight to convermetry_webhook_delivery_attempted
         * with transport_attempted => false.
         *
         * The guarantee is source-adjacency, not "exactly once per HTTP call":
         * this action is fired on the statement immediately preceding the
         * transport call and nowhere else, but a listener that throws prevents
         * the request it was announcing. Do not throw from here.
         *
         * $meta is deliberately metadata only. The request URL can carry a
         * bearer token, the headers can carry Authorization and the HMAC
         * signature, and the body can carry submitted form fields — so this
         * action exposes the body's length and SHA-256, the header *names*, and
         * whether the request is signed, and nothing else.
         *
         * @param array<string, mixed> $context Credential-free delivery context.
         * @param array{body_bytes: int, body_sha256: string, header_names: list<string>, signed: bool} $meta
         */
        do_action('convermetry_webhook_before_send', $context, $meta);
    }

    /**
     * Announces the transport result of one attempt.
     *
     * @param array<string, mixed>                                     $context            Delivery context.
     * @param array{ok: bool, code: int, message: string, body: string} $result            Transport result.
     * @param bool                                                     $transportAttempted Whether a request was made.
     * @return array<string, mixed> The context with transport_attempted applied.
     */
    public static function attempted(array $context, array $result, bool $transportAttempted): array
    {
        $context = self::with($context, ['transport_attempted' => $transportAttempted]);

        /**
         * Fires once per delivery attempt, after the transport has returned (or
         * after Convermetry determined no request could be made).
         *
         * Fires on every attempt, including retries and both test buttons. When
         * transport_attempted is false no network request happened — the payload
         * could not be encoded, or the analytics report query failed — and
         * $result carries Convermetry's synthetic failure rather than a server's.
         *
         * This action reports the TRANSPORT result only. It deliberately does
         * not say whether the delivery will be retried, succeeded, or abandoned:
         * nothing has decided that yet. Listen for
         * convermetry_webhook_delivery_succeeded, _retry_scheduled,
         * _retry_chain_exhausted, _delivery_abandoned or _delivery_canceled for
         * the disposition, each of which fires only after the corresponding
         * state has been committed.
         *
         * The response body is not passed: an endpoint's error page can echo
         * back the payload it was sent.
         *
         * @param array<string, mixed> $context Credential-free delivery context.
         * @param bool                 $ok      Whether the endpoint returned 2xx.
         * @param int                  $code    HTTP status code, 0 when no response was received.
         * @param string               $message Short status/transport message.
         */
        do_action(
            'convermetry_webhook_delivery_attempted',
            $context,
            (bool) ($result['ok'] ?? false),
            (int) ($result['code'] ?? 0),
            (string) ($result['message'] ?? '')
        );

        return $context;
    }

    /**
     * Announces the outcome of writing one Activity Log row.
     *
     * @param array<string, mixed> $context     Delivery context.
     * @param string               $disposition 'stored', 'suppressed', or 'failed'.
     * @return void
     */
    public static function attemptLogged(array $context, string $disposition): void
    {
        /**
         * Fires immediately after Convermetry writes (or declines to write) one
         * Activity Log row for a delivery attempt.
         *
         * Fires once per attempt, on every path including retries and tests.
         *
         * $disposition reports what actually happened, because a logged attempt
         * is not guaranteed to be a stored one:
         *  - 'stored'     the row was inserted;
         *  - 'suppressed' a convermetry_delivery_log_row callback returned false;
         *  - 'failed'     the INSERT itself failed.
         *
         * This is about the log row, not the delivery. A delivery can succeed
         * while its log row is suppressed, and vice versa.
         *
         * @param array<string, mixed> $context     Credential-free delivery context.
         * @param string               $disposition 'stored', 'suppressed', or 'failed'.
         */
        do_action('convermetry_delivery_attempt_logged', $context, $disposition);
    }

    /**
     * Announces a delivery that succeeded and whose bookkeeping has committed.
     *
     * @param array<string, mixed> $context Delivery context.
     * @return void
     */
    public static function succeeded(array $context): void
    {
        /**
         * Fires after an endpoint accepted a delivery AND Convermetry finished
         * committing the state that records it.
         *
         * For analytics that means the last-sent window marker has advanced and
         * any pending retry chain has been cleared; for the form queue it means
         * the queue row has been deleted and the submission's delivery state
         * recomputed. Listeners can therefore read that state and see the
         * settled values.
         *
         * Fires once per successful attempt, per endpoint — so a submission
         * fanned out to three endpoints fires it three times, with different
         * endpoint_key values. Fires on retries and on both test buttons
         * (tests advance no state; is_test distinguishes them).
         *
         * @param array<string, mixed> $context Credential-free delivery context.
         */
        do_action('convermetry_webhook_delivery_succeeded', $context);
    }

    /**
     * Announces a retry whose next attempt has been persisted.
     *
     * @param array<string, mixed> $context       Delivery context.
     * @param int                  $nextAttempt   Attempt number that will run next.
     * @param int                  $nextAttemptAt Unix timestamp of the next attempt.
     * @return void
     */
    public static function retryScheduled(array $context, int $nextAttempt, int $nextAttemptAt): void
    {
        /**
         * Fires after a failed delivery's next attempt has been persisted —
         * the analytics retry state was written and its cron event scheduled, or
         * the form queue row was returned to 'pending' with its next attempt
         * time. It never fires speculatively before that write.
         *
         * Fires once per scheduled retry. Never fires on a test button, which
         * has no retry chain, and never on the synchronous form path, which
         * makes exactly one attempt per endpoint.
         *
         * @param array<string, mixed> $context       Credential-free delivery context.
         * @param int                  $nextAttempt   Attempt number that will run next.
         * @param int                  $nextAttemptAt Unix timestamp of the next attempt.
         */
        do_action('convermetry_webhook_retry_scheduled', $context, $nextAttempt, $nextAttemptAt);
    }

    /**
     * Announces an analytics retry chain that has given up but is resumable.
     *
     * @param array<string, mixed> $context Delivery context.
     * @return void
     */
    public static function retryChainExhausted(array $context): void
    {
        /**
         * Fires after an ANALYTICS delivery's retry chain has been exhausted and
         * that terminal state has been persisted.
         *
         * This is deliberately not called "abandoned". An exhausted analytics
         * delivery is not discarded: its frozen body stays in the retry state,
         * and the next scheduled dispatch picks it up and tries again, so the
         * report window is not lost. It is only dropped much later, by retention
         * expiry. Treat this as "this endpoint is failing", not "this data is
         * gone".
         *
         * Fires once per exhausted chain, analytics deliveries only. The form
         * queue's equivalent really is terminal and fires
         * convermetry_webhook_delivery_abandoned instead.
         *
         * @param array<string, mixed> $context Credential-free delivery context.
         */
        do_action('convermetry_webhook_retry_chain_exhausted', $context);
    }

    /**
     * Announces a form delivery that will never be attempted again.
     *
     * @param array<string, mixed> $context Delivery context.
     * @param string               $reason  Stable reason code.
     * @return void
     */
    public static function abandoned(array $context, string $reason): void
    {
        /**
         * Fires after a queued FORM delivery has been given up on permanently
         * and its queue row deleted. Unlike the analytics chain, this one does
         * not come back: the delivery is gone, and the only record left is the
         * Activity Log.
         *
         * Fires once per abandoned queue row, after the deletion has been
         * executed — never before, so a listener that inspects the queue sees
         * the settled state.
         *
         * @param array<string, mixed> $context Credential-free delivery context.
         * @param string               $reason  Stable reason code, currently 'retries_exhausted'.
         */
        do_action('convermetry_webhook_delivery_abandoned', $context, $reason);
    }

    /**
     * Announces a queued delivery removed before it could be sent.
     *
     * @param array<string, mixed> $context Delivery context.
     * @param string               $reason  Stable reason code.
     * @return void
     */
    public static function canceled(array $context, string $reason): void
    {
        /**
         * Fires after a queued delivery was removed without ever being sent —
         * distinct from abandonment, which follows failed attempts.
         *
         * Currently fires with reason 'submission_deleted': the submission a
         * queued delivery referenced was deleted before the worker reached the
         * row, so there is nothing left to deliver. No attempt was made and no
         * Activity Log row is written for it.
         *
         * @param array<string, mixed> $context Credential-free delivery context.
         * @param string               $reason  Stable reason code, currently 'submission_deleted'.
         */
        do_action('convermetry_webhook_delivery_canceled', $context, $reason);
    }
}
