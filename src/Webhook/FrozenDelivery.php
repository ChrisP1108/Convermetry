<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

/**
 * One analytics delivery, frozen: the exact bytes, the exact URL, the exact
 * headers, and the id every attempt in its chain will re-send them under.
 *
 * Freezing is what makes the byte-identical promise real. Retention cleanup,
 * settings or site-name changes, filters with dynamic output, and even plugin
 * updates between attempts can no longer alter what a given delivery_id
 * accompanies, and retries skip the aggregate queries entirely. This object is
 * that promise expressed as a type: it is immutable, and nothing that holds
 * one can re-compose it.
 *
 * An EMPTY {@see $body} is not an empty payload — it means the delivery could
 * not be built. Either the JSON encode failed or the report query did, and
 * {@see $failureReason} says which. Such a delivery must never be sent:
 * a lenient endpoint would answer 2xx and the last-sent marker would advance
 * past a window whose data was never delivered. {@see isSendable()} is the
 * check, and {@see failureMessage()} the Activity Log's wording for it.
 *
 * The form queue freezes into its own table row rather than into one of these
 * — see {@see FormDeliveryQueue} — because a queued delivery has to survive
 * the request that created it.
 */
final readonly class FrozenDelivery
{
    /** {@see $failureReason} for a report query that failed. */
    public const string REPORT_QUERY_FAILED = 'report_query_failed';

    /**
     * @param int                   $windowStart   Window start as a unix timestamp (inclusive).
     * @param int                   $windowEnd     Window end as a unix timestamp (exclusive); may have been shrunk.
     * @param string                $deliveryId    Deterministic id for this endpoint + exact window.
     * @param string                $body          Serialized JSON body; '' when the delivery could not be built.
     * @param string                $url           Final request URL, with merged query parameters.
     * @param array<string, string> $headers       Frozen delivery headers (no protocol headers yet).
     * @param string|null           $failureReason Why $body is empty, or null when it is not.
     */
    public function __construct(
        public int $windowStart,
        public int $windowEnd,
        public string $deliveryId,
        public string $body,
        public string $url,
        public array $headers,
        public ?string $failureReason = null,
    ) {
    }

    /**
     * Whether this delivery may be put on the wire.
     *
     * @return bool
     */
    public function isSendable(): bool
    {
        return $this->body !== '';
    }

    /**
     * The Activity Log message for a delivery that cannot be sent.
     *
     * @param string $reportFailureMessage Wording for a failed report query.
     * @return string
     */
    public function failureMessage(string $reportFailureMessage): string
    {
        return $this->failureReason === self::REPORT_QUERY_FAILED
            ? $reportFailureMessage
            : 'Payload could not be JSON-encoded';
    }

    /**
     * A copy carrying the composed request, once the URL and headers are
     * known.
     *
     * Composition needs a delivery context, and the context needs the
     * delivery's id and window — so the body freezes first and the request is
     * attached immediately afterwards, in {@see AnalyticsDispatcher}, before
     * anything else can see the object.
     *
     * @param string                $url     Final request URL.
     * @param array<string, string> $headers Frozen delivery headers.
     * @return self
     */
    public function withRequest(string $url, array $headers): self
    {
        return new self(
            $this->windowStart,
            $this->windowEnd,
            $this->deliveryId,
            $this->body,
            $url,
            $headers,
            $this->failureReason,
        );
    }
}
