<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

/**
 * What one webhook request attempt came back with.
 *
 * Produced by {@see \Convermetry\Support\Http::postJson()} for a real network
 * attempt, and by the two named constructors below for the failures that never
 * reach the wire — an unencodable payload, or an analytics report whose query
 * failed. Those two cases used to be hand-built as
 * `['ok' => false, 'code' => 0, 'message' => …, 'body' => '']` at five
 * separate call sites, which is how `$result['code']` came to be cast to int
 * in some places and read raw in others.
 *
 * $ok is NOT derived from $code here. A synthetic failure has code 0 and a
 * message describing what went wrong locally, and a real 204 has an empty body
 * — so "did this succeed?" and "what status came back?" are genuinely separate
 * facts and are stored as such. {@see fromResponse()} is the one place the 2xx
 * rule is applied.
 *
 * $body is the endpoint's response, capped by the transport at
 * {@see DeliveryLog::MAX_BODY_BYTES}. It is stored in the Activity Log and
 * returned to synchronous callers, and it is deliberately NOT passed to the
 * 'convermetry_webhook_delivery_attempted' action — an endpoint's error page
 * can echo back the payload it was sent.
 *
 * WHETHER TO TRY AGAIN is decided here too ({@see isFinal()}, {@see $retryAfter}),
 * so the form queue and the analytics chain cannot disagree about it.
 */
final readonly class TransportResult
{
    /**
     * 4xx answers that a later attempt can still turn into a success: the
     * receiver timed out reading (408), hit a conflict (409), refused early
     * data (425) or asked for less traffic (429). Every other 4xx is final.
     *
     * @var list<int>
     */
    public const array RETRYABLE_CLIENT_ERRORS = [408, 409, 425, 429];

    /** Statuses whose Retry-After header is honoured. */
    public const array RETRY_AFTER_STATUSES = [429, 503];

    /**
     * Longest Retry-After honoured, in seconds. A receiver asking for a week
     * would otherwise hold a lead "pending" for that long.
     */
    public const int MAX_RETRY_AFTER = DAY_IN_SECONDS;

    /**
     * @param bool     $ok         Whether the endpoint returned 2xx.
     * @param int      $code       HTTP status code; 0 when no response was received.
     * @param string   $message    Short status or transport message.
     * @param string   $body       Response body ('' when there was none).
     * @param int|null $retryAfter Seconds a 429 or 503 asked the sender to wait, capped at
     *                             {@see MAX_RETRY_AFTER}; null when it asked nothing.
     */
    public function __construct(
        public bool $ok,
        public int $code,
        public string $message,
        public string $body = '',
        public ?int $retryAfter = null,
    ) {
    }

    /**
     * The result for a real HTTP response.
     *
     * @param int    $code       HTTP status code.
     * @param string $message    Status message from the transport.
     * @param string $body       Response body.
     * @param string $retryAfter The response's Retry-After header ('' when absent).
     * @param int    $now        Unix time, for a Retry-After given as a date.
     * @return self
     */
    public static function fromResponse(int $code, string $message, string $body, string $retryAfter = '', int $now = 0): self
    {
        $ok = $code >= 200 && $code < 300;

        return new self(
            $ok,
            $code,
            $ok ? 'Delivered' : $message,
            $body,
            in_array($code, self::RETRY_AFTER_STATUSES, true)
                ? self::parseRetryAfter($retryAfter, $now > 0 ? $now : time())
                : null
        );
    }

    /**
     * Whether this answer ends the delivery: no later attempt can succeed.
     *
     * A 4xx other than {@see RETRYABLE_CLIENT_ERRORS} says the request itself
     * is wrong (400, 401, 403, 404, 410, 422 …), and the frozen request is
     * re-sent byte for byte, so it would be refused the same way five more
     * times over a day while the lead showed "pending". A 5xx, a redirect
     * (never followed, see {@see \Convermetry\Support\Http::postJson()}), a
     * network error and a local failure (code 0) are all retried: each can
     * change without the request changing.
     *
     * @return bool
     */
    public function isFinal(): bool
    {
        return !$this->ok
            && $this->code >= 400
            && $this->code < 500
            && !in_array($this->code, self::RETRYABLE_CLIENT_ERRORS, true);
    }

    /**
     * The delay before the next attempt: the schedule's, or longer when the
     * receiver asked for longer with Retry-After.
     *
     * A floor, never a ceiling. A receiver asking for 30 seconds still waits
     * the schedule's five minutes, so a misconfigured one cannot turn the
     * backoff into a tight loop.
     *
     * @param int $scheduled The retry schedule's delay, in seconds.
     * @return int
     */
    public function nextDelay(int $scheduled): int
    {
        return max($scheduled, $this->retryAfter ?? 0);
    }

    /**
     * Reads a Retry-After header: delta-seconds ("120") or an HTTP-date
     * ("Wed, 21 Oct 2026 07:28:00 GMT").
     *
     * @param string $header The header's value.
     * @param int    $now    Unix time to measure a date from.
     * @return int|null Seconds, between 0 and {@see MAX_RETRY_AFTER}; null when absent or unreadable.
     */
    public static function parseRetryAfter(string $header, int $now): ?int
    {
        $header = trim($header);

        if ($header === '') {
            return null;
        }

        if (preg_match('/^\d{1,10}$/', $header) === 1) {
            return min(self::MAX_RETRY_AFTER, (int) $header);
        }

        // Only the three HTTP-date forms RFC 9110 allows. strtotime() would
        // read "-5" or "soon" as a time too.
        $utc = new \DateTimeZone('UTC');
        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $at = \DateTimeImmutable::createFromFormat('!' . $format, (string) preg_replace('/\s+/', ' ', $header), $utc);

            if ($at !== false) {
                return min(self::MAX_RETRY_AFTER, max(0, $at->getTimestamp() - $now));
            }
        }

        return null;
    }

    /**
     * The result for an attempt that never reached the wire, or for a
     * transport error with no response.
     *
     * @param string $message What went wrong.
     * @return self
     */
    public static function failure(string $message): self
    {
        return new self(false, 0, $message, '');
    }

    /**
     * The response body decoded when it is valid JSON, the raw string when it
     * is not, and null when there was none.
     *
     * This is what {@see \Convermetry\Forms\SubmissionResult::$data} carries
     * back to a synchronous caller. Not for public display: it is whatever the
     * endpoint chose to return.
     *
     * @return mixed
     */
    public function decodedBody(): mixed
    {
        if ($this->body === '') {
            return null;
        }

        return json_validate($this->body) ? json_decode($this->body, true) : $this->body;
    }

    /**
     * The result without its response body, for the two "Send test" buttons.
     *
     * The Webhooks page reports whether the endpoint answered and what it
     * said — never what it sent back, which is unbounded and unescaped.
     *
     * @return array{ok: bool, code: int, message: string}
     */
    public function toTestSummary(): array
    {
        return ['ok' => $this->ok, 'code' => $this->code, 'message' => $this->message];
    }
}
