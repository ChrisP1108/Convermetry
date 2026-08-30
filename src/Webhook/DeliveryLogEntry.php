<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

/**
 * One delivery attempt, as the Activity Log is asked to record it.
 *
 * {@see DeliveryLog::log()} used to take a sixteen-key associative array
 * assembled by hand at six call sites, each of which had to remember which
 * keys existed, which were optional, and that 'ok' meant success while
 * 'success' was the column. Two of them omitted 'attempt' entirely and got the
 * default; one wrote the response code as a string. This object is the fix:
 * the required facts are constructor parameters, the optional ones have
 * defaults, and the call sites are named-argument lists a reader can check
 * against the column list.
 *
 * It is NOT the row. {@see DeliveryLog::log()} still owns redaction, body
 * capping, column widths, and the 'convermetry_delivery_log_row' filter, which
 * receives — and may still veto — the same flat row array it always has. This
 * object only carries what happened; that method decides what is stored.
 *
 * $requestHeaders are the headers AS SENT. They are redacted by name on the
 * way into storage, never here: a frozen retry has to replay the real values,
 * so the object a delivery path holds must carry them intact.
 */
final readonly class DeliveryLogEntry
{
    /**
     * @param TransportResult       $result         What the attempt came back with.
     * @param string                $endpointUrl    Configured endpoint URL.
     * @param string                $endpointLabel  Endpoint label, or ''.
     * @param string                $deliveryId     Idempotency id for this delivery.
     * @param MessageType           $messageType    Which message type was sent.
     * @param DeliveryKind          $kind           Why this attempt happened.
     * @param int                   $attempt        Attempt number; 0 for a scheduled first send.
     * @param string                $requestUrl     Final request URL; '' falls back to $endpointUrl.
     * @param array<string, string> $requestHeaders Headers as sent (redacted on storage).
     * @param string                $requestData    Exact JSON body sent ('' when encoding failed).
     * @param string                $submissionId   Form deliveries only.
     * @param string                $conversionId   Form deliveries only.
     * @param string                $formProvider   Form deliveries only.
     * @param string                $formName       Form deliveries only.
     */
    public function __construct(
        public TransportResult $result,
        public string $endpointUrl,
        public string $endpointLabel,
        public string $deliveryId,
        public MessageType $messageType,
        public DeliveryKind $kind,
        public int $attempt = 0,
        public string $requestUrl = '',
        public array $requestHeaders = [],
        public string $requestData = '',
        public string $submissionId = '',
        public string $conversionId = '',
        public string $formProvider = '',
        public string $formName = '',
    ) {
    }

    /**
     * The response body to store.
     *
     * A transport error produces no HTTP response at all, so there is nothing
     * to store and nothing that would tell the Activity Log why. In that one
     * case the failure message is stored as a JSON object instead, which is
     * what lets the UI and the REST API distinguish "endpoint said no" from
     * "endpoint unreachable".
     *
     * @return string
     */
    public function responseData(): string
    {
        if ($this->result->code === 0 && $this->result->body === '') {
            return (string) wp_json_encode(['error' => $this->result->message]);
        }

        return $this->result->body;
    }
}
