<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

/**
 * One analytics endpoint's pending retry chain, as stored in the
 * 'cvm_webhook_retry_state' option.
 *
 * The option holds a map of md5(endpoint URL) => state. That map is READ BACK
 * FROM DISK, which is the whole reason this class has a
 * {@see fromStoredArray()} rather than a plain constructor call at the read
 * site: a state row can have been written by any earlier version of the
 * plugin, so every field has to be coerced and every absent field has to have
 * an answer. Doing that once, here, is what lets
 * {@see AnalyticsDispatcher::deliveryFromState()} stop asking `is_string()`
 * about a value it already read.
 *
 * {@see $frozenAt} marks when the DELIVERY first froze, not when the state row
 * was last rewritten. Later attempts in the same chain must not refresh it, or
 * the retention-window expiry would never trigger for a persistently failing
 * endpoint.
 *
 * {@see $exhausted} does not mean "discarded". An exhausted analytics chain
 * keeps its frozen body, and the next scheduled dispatch resumes it under the
 * same delivery_id, so the report window is not lost.
 */
final readonly class RetryState
{
    /**
     * @param string                $url          The endpoint URL this chain targets.
     * @param int                   $attempt      Attempt number: next to run, or the last made when exhausted.
     * @param int                   $scheduledFor Unix timestamp of the pending cron event; 0 when exhausted.
     * @param int                   $windowStart  Frozen window start.
     * @param int                   $windowEnd    Frozen window end.
     * @param string                $deliveryId   The chain's stable delivery id.
     * @param string                $body         The frozen JSON body, re-sent verbatim by every attempt.
     * @param string                $requestUrl   The frozen request URL; '' on state written before the column existed.
     * @param array<string, string> $headers      The frozen headers; empty on such state.
     * @param bool                  $exhausted    True when no cron is pending and dispatch must resume it.
     * @param int                   $frozenAt     When this delivery first froze.
     */
    public function __construct(
        public string $url,
        public int $attempt,
        public int $scheduledFor,
        public int $windowStart,
        public int $windowEnd,
        public string $deliveryId,
        public string $body,
        public string $requestUrl,
        public array $headers,
        public bool $exhausted,
        public int $frozenAt,
    ) {
    }

    /**
     * Reads one stored state row, coercing whatever an older version wrote.
     *
     * @param array<string, mixed> $state A row from the retry-state option.
     * @return self
     */
    public static function fromStoredArray(array $state): self
    {
        $headers = [];
        foreach (is_array($state['headers'] ?? null) ? $state['headers'] : [] as $name => $value) {
            if (is_string($name) && $name !== '' && is_scalar($value)) {
                $headers[$name] = (string) $value;
            }
        }

        return new self(
            url: is_string($state['url'] ?? null) ? $state['url'] : '',
            attempt: (int) ($state['attempt'] ?? 1),
            scheduledFor: (int) ($state['scheduled_for'] ?? 0),
            windowStart: (int) ($state['window_start'] ?? 0),
            windowEnd: (int) ($state['window_end'] ?? 0),
            deliveryId: is_string($state['delivery_id'] ?? null) ? $state['delivery_id'] : '',
            body: is_string($state['body'] ?? null) ? $state['body'] : '',
            requestUrl: is_string($state['request_url'] ?? null) ? $state['request_url'] : '',
            headers: $headers,
            exhausted: !empty($state['exhausted']),
            frozenAt: (int) ($state['frozen_at'] ?? 0),
        );
    }

    /**
     * The state row for a delivery that is about to be (re)scheduled.
     *
     * @param FrozenDelivery $delivery     The delivery every attempt re-sends.
     * @param string         $url          The endpoint URL this chain targets.
     * @param int            $attempt      Attempt number being recorded.
     * @param int            $scheduledFor Unix timestamp of the pending cron event; 0 when exhausted.
     * @param bool           $exhausted    Whether the chain has given up for now.
     * @param int            $frozenAt     When the delivery first froze.
     * @return self
     */
    public static function forDelivery(
        FrozenDelivery $delivery,
        string $url,
        int $attempt,
        int $scheduledFor,
        bool $exhausted,
        int $frozenAt
    ): self {
        return new self(
            url: $url,
            attempt: $attempt,
            scheduledFor: $scheduledFor,
            windowStart: $delivery->windowStart,
            windowEnd: $delivery->windowEnd,
            deliveryId: $delivery->deliveryId,
            body: $delivery->body,
            requestUrl: $delivery->url,
            headers: $delivery->headers,
            exhausted: $exhausted,
            frozenAt: $frozenAt,
        );
    }

    /**
     * A copy marked exhausted with no cron event pending.
     *
     * What deactivation leaves behind. The frozen body is deliberately KEPT:
     * discarding it would break the delivery_id guarantee, whereas kept-as-
     * exhausted means the first scheduled dispatch after reactivation re-sends
     * the same bytes under the original id, so duplicates stay deduplicable.
     *
     * @return self
     */
    public function suspended(): self
    {
        return new self(
            url: $this->url,
            attempt: $this->attempt,
            scheduledFor: 0,
            windowStart: $this->windowStart,
            windowEnd: $this->windowEnd,
            deliveryId: $this->deliveryId,
            body: $this->body,
            requestUrl: $this->requestUrl,
            headers: $this->headers,
            exhausted: true,
            frozenAt: $this->frozenAt,
        );
    }

    /**
     * Whether this state carries a frozen body a retry can re-send.
     *
     * @return bool
     */
    public function hasFrozenBody(): bool
    {
        return $this->body !== '';
    }

    /**
     * The stored form, with exactly the keys the option has always held.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'url'           => $this->url,
            'attempt'       => $this->attempt,
            'scheduled_for' => $this->scheduledFor,
            'window_start'  => $this->windowStart,
            'window_end'    => $this->windowEnd,
            'delivery_id'   => $this->deliveryId,
            'body'          => $this->body,
            'request_url'   => $this->requestUrl,
            'headers'       => $this->headers,
            'exhausted'     => $this->exhausted,
            'frozen_at'     => $this->frozenAt,
        ];
    }

    /**
     * The form the Webhooks page displays: the stored row without the frozen
     * body, its delivery id, or its headers.
     *
     * The body can be tens of kilobytes and no UI needs it; the id and headers
     * are delivery internals rather than status.
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return array_diff_key($this->toArray(), ['body' => 0, 'delivery_id' => 0, 'headers' => 0]);
    }
}
