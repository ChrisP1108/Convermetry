<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

/**
 * What happened to one submission at one endpoint.
 *
 * A submission fans out to every configured form endpoint, and each of them
 * ends up in exactly one of these. The list is JSON-encoded into the
 * submission row's delivery_json column and read back by the Submissions page,
 * so {@see toArray()} and {@see fromStoredArray()} are a matched pair and the
 * key names are on-disk format.
 *
 * Two rules the first implementation got wrong, and which
 * {@see \Convermetry\Database\FormSubmissions::buildEndpointOutcomes()} now
 * enforces:
 *
 *  - The LAST attempt against an endpoint is that endpoint's outcome. Taking
 *    MAX(success) and MAX(response_code) as independent aggregates reported
 *    "Delivered (500)" for a 500 followed by a successful 200 retry — a
 *    success paired with the failure's status code. {@see $ok} and
 *    {@see $code} come from the same attempt because they are constructed
 *    together.
 *  - A queue row outranks any log row for the same endpoint: the delivery is
 *    still in flight, so its last failed attempt is not yet the verdict. That
 *    is what {@see $queued} means, and why it is not the same thing as
 *    "!$ok".
 */
final readonly class EndpointOutcome
{
    /**
     * @param string $url     The endpoint's configured URL.
     * @param string $label   The endpoint's label at the time, or ''.
     * @param bool   $ok      Whether the last attempt was accepted.
     * @param int    $code    That attempt's HTTP status; 0 when none was received.
     * @param int    $attempt Which attempt it was.
     * @param bool   $queued  Whether a delivery to this endpoint is still pending.
     * @param string $at      When: the attempt's time, or the next attempt's time when queued.
     */
    public function __construct(
        public string $url,
        public string $label,
        public bool $ok,
        public int $code,
        public int $attempt,
        public bool $queued,
        public string $at,
    ) {
    }

    /**
     * Reads one outcome back out of a submission's delivery_json column.
     *
     * @param array<string, mixed> $stored One stored outcome.
     * @return self
     */
    public static function fromStoredArray(array $stored): self
    {
        return new self(
            url: (string) ($stored['url'] ?? ''),
            label: (string) ($stored['label'] ?? ''),
            ok: !empty($stored['ok']),
            code: (int) ($stored['code'] ?? 0),
            attempt: (int) ($stored['attempt'] ?? 0),
            queued: !empty($stored['queued']),
            at: (string) ($stored['at'] ?? ''),
        );
    }

    /**
     * The stored form. These key names are on disk in every submission row.
     *
     * @return array{url: string, label: string, ok: bool, code: int, attempt: int, queued: bool, at: string}
     */
    public function toArray(): array
    {
        return [
            'url'     => $this->url,
            'label'   => $this->label,
            'ok'      => $this->ok,
            'code'    => $this->code,
            'attempt' => $this->attempt,
            'queued'  => $this->queued,
            'at'      => $this->at,
        ];
    }
}
