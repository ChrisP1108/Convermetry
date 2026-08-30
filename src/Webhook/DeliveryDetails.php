<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

use Convermetry\Settings\Options;
use Convermetry\Support\Url;

/**
 * Everything the six delivery paths know about one delivery, in typed form.
 *
 * Scheduled analytics reports, analytics retries, the analytics test button,
 * the background form queue, synchronous form delivery, and the form test
 * button all describe themselves with one of these, so an integration gets the
 * same facts from all six.
 *
 * **This object is internal; {@see toArray()} is the public contract.** Every
 * delivery lifecycle action still receives the same flat array of the same
 * fifteen keys, in the same fixed order, that it always has — see
 * {@see DeliveryContext}, which owns the announcements. The object exists so
 * that the plugin's own code stops indexing an untyped array by string in
 * thirty places, not to change what listeners receive.
 *
 * **What it never contains.** No signing secret, no Authorization header, no
 * credential-bearing endpoint URL, no request body, no submitted fields, no
 * response body, no IP address. The endpoint URL is accepted by the
 * constructor and immediately reduced: {@see $endpointKey} is its md5 (the
 * same identifier the Activity Log and REST API use), and
 * {@see $endpointOrigin} is scheme://host(:port). Webhook URLs routinely embed
 * bearer tokens in their path or query, and these actions are exactly what
 * people wire to logging — so the full URL is not a property of this object at
 * all, rather than being a property callers are asked not to read.
 *
 * Immutable. The per-attempt fields a composition-time context cannot know are
 * applied with {@see withTransportAttempted()} and {@see withAttempt()}, which
 * return copies.
 */
final readonly class DeliveryDetails
{
    /**
     * @param MessageType       $messageType        Which message type this delivery carries.
     * @param DeliveryKind      $kind               Why this attempt is happening.
     * @param int               $attempt            Attempt number; 0 before the first send.
     * @param string            $deliveryId         The delivery's idempotency id, or ''.
     * @param bool              $isTest             Whether this is a "Send test" delivery.
     * @param string            $endpointKey        md5 of the configured endpoint URL, or '' when there is none.
     * @param string            $endpointLabel      The endpoint's configured label, or ''.
     * @param string            $endpointOrigin     scheme://host(:port) of the endpoint URL.
     * @param string            $submissionId       Form deliveries only; '' otherwise.
     * @param string            $conversionId       Form deliveries only; '' otherwise.
     * @param string            $formKey            Form deliveries only; '' otherwise.
     * @param int               $windowStart        Analytics deliveries only; 0 otherwise.
     * @param int               $windowEnd          Analytics deliveries only; 0 otherwise.
     * @param bool              $transportAttempted Whether a network request was actually made.
     * @param string            $disposition        Reserved for terminal reason codes; '' unless set.
     */
    private function __construct(
        public MessageType $messageType,
        public DeliveryKind $kind,
        public int $attempt,
        public string $deliveryId,
        public bool $isTest,
        public string $endpointKey,
        public string $endpointLabel,
        public string $endpointOrigin,
        public string $submissionId,
        public string $conversionId,
        public string $formKey,
        public int $windowStart,
        public int $windowEnd,
        public bool $transportAttempted,
        public string $disposition,
    ) {
    }

    /**
     * Builds the details for one delivery, reducing the endpoint URL to the
     * two credential-free identifiers listeners are given.
     *
     * $isTest defaults to "the kind is Test", which is what every real call
     * site means; it is a separate parameter only because the array-shaped
     * predecessor allowed the two to be set independently.
     *
     * @param string            $endpointUrl    Configured endpoint URL (reduced immediately; never stored).
     * @param MessageType       $messageType    Which message type.
     * @param DeliveryKind      $kind           Why this attempt is happening.
     * @param int               $attempt        Attempt number.
     * @param string            $deliveryId     The delivery's idempotency id.
     * @param string|null       $endpointLabel  Label, when the caller already resolved it; null looks it up.
     * @param string            $submissionId   Form deliveries only.
     * @param string            $conversionId   Form deliveries only.
     * @param string            $formKey        Form deliveries only.
     * @param int               $windowStart    Analytics deliveries only.
     * @param int               $windowEnd      Analytics deliveries only.
     * @param bool|null         $isTest         Overrides the default derived from $kind.
     * @return self
     */
    public static function for(
        string $endpointUrl,
        MessageType $messageType,
        DeliveryKind $kind,
        int $attempt = 0,
        string $deliveryId = '',
        ?string $endpointLabel = null,
        string $submissionId = '',
        string $conversionId = '',
        string $formKey = '',
        int $windowStart = 0,
        int $windowEnd = 0,
        ?bool $isTest = null,
    ): self {
        return new self(
            messageType: $messageType,
            kind: $kind,
            attempt: $attempt,
            deliveryId: $deliveryId,
            isTest: $isTest ?? ($kind === DeliveryKind::Test),
            endpointKey: $endpointUrl !== '' ? md5($endpointUrl) : '',
            endpointLabel: $endpointLabel ?? Options::endpointLabel($endpointUrl),
            endpointOrigin: Url::origin($endpointUrl),
            submissionId: $submissionId,
            conversionId: $conversionId,
            formKey: $formKey,
            windowStart: $windowStart,
            windowEnd: $windowEnd,
            transportAttempted: false,
            disposition: '',
        );
    }

    /**
     * A copy that records whether a network request was actually made.
     *
     * @param bool $attempted True when the request reached the wire.
     * @return self
     */
    public function withTransportAttempted(bool $attempted): self
    {
        return new self(
            $this->messageType,
            $this->kind,
            $this->attempt,
            $this->deliveryId,
            $this->isTest,
            $this->endpointKey,
            $this->endpointLabel,
            $this->endpointOrigin,
            $this->submissionId,
            $this->conversionId,
            $this->formKey,
            $this->windowStart,
            $this->windowEnd,
            $attempted,
            $this->disposition,
        );
    }

    /**
     * A copy with a different attempt number.
     *
     * @param int $attempt Attempt number.
     * @return self
     */
    public function withAttempt(int $attempt): self
    {
        return new self(
            $this->messageType,
            $this->kind,
            $attempt,
            $this->deliveryId,
            $this->isTest,
            $this->endpointKey,
            $this->endpointLabel,
            $this->endpointOrigin,
            $this->submissionId,
            $this->conversionId,
            $this->formKey,
            $this->windowStart,
            $this->windowEnd,
            $this->transportAttempted,
            $this->disposition,
        );
    }

    /**
     * The listener-facing array: every documented key always present, in a
     * fixed order, so a listener can index it without defensive checks.
     * Values the calling path cannot know are zero-valued rather than absent.
     *
     * This shape is public API. It is what every 'convermetry_webhook_*'
     * action's $context argument has always been, and it must not gain,
     * lose, or reorder a key without a schema decision.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message_type'        => $this->messageType->value,
            'kind'                => $this->kind->value,
            'attempt'             => $this->attempt,
            'delivery_id'         => $this->deliveryId,
            'is_test'             => $this->isTest,
            'endpoint_key'        => $this->endpointKey,
            'endpoint_label'      => $this->endpointLabel,
            'endpoint_origin'     => $this->endpointOrigin,
            'submission_id'       => $this->submissionId,
            'conversion_id'       => $this->conversionId,
            'form_key'            => $this->formKey,
            'window_start'        => $this->windowStart,
            'window_end'          => $this->windowEnd,
            'transport_attempted' => $this->transportAttempted,
            'disposition'         => $this->disposition,
        ];
    }
}
