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
 */
final readonly class TransportResult
{
    /**
     * @param bool   $ok      Whether the endpoint returned 2xx.
     * @param int    $code    HTTP status code; 0 when no response was received.
     * @param string $message Short status or transport message.
     * @param string $body    Response body ('' when there was none).
     */
    public function __construct(
        public bool $ok,
        public int $code,
        public string $message,
        public string $body = '',
    ) {
    }

    /**
     * The result for a real HTTP response.
     *
     * @param int    $code    HTTP status code.
     * @param string $message Status message from the transport.
     * @param string $body    Response body.
     * @return self
     */
    public static function fromResponse(int $code, string $message, string $body): self
    {
        $ok = $code >= 200 && $code < 300;

        return new self($ok, $code, $ok ? 'Delivered' : $message, $body);
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
