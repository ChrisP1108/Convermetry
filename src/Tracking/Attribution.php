<?php
declare(strict_types=1);

namespace Convermetry\Tracking;

if (!defined('ABSPATH')) exit;

/**
 * A visit's campaign attribution: the five utm values, utm_id, and the TYPE of
 * ad-click identifier that brought the visitor in.
 *
 * The seven fields travelled together as an `array<string, string>` through
 * correlation, channel classification, the submission's stored context, and
 * every webhook payload — seven keys that had to be spelled identically in
 * about a dozen places, with `$attribution['utm_campaign'] ?? ''` at each one.
 * They are properties now; the array is produced only where an array is the
 * actual format ({@see toArray()}, for storage and the wire).
 *
 * WHAT IS DELIBERATELY NOT HERE. The ad-click identifier's VALUE. Only its
 * parameter name is kept, as {@see $clickIdType} — the value is a cross-site
 * advertising ID that may qualify as personal data, so it is never stored,
 * never classified on, and never sent. {@see Channels::CLICK_ID_TYPES} is the
 * whitelist of names that pass.
 *
 * Every field is a string and empty means absent: the wire schema promises
 * these keys are always present, so there is nothing for a null to mean that
 * '' does not already say.
 */
final readonly class Attribution
{
    /**
     * @param string $utmSource   Canonical source name ({@see Channels::normalizeSource()}).
     * @param string $utmMedium   Lowercased medium.
     * @param string $utmCampaign Campaign name.
     * @param string $utmId       Campaign id.
     * @param string $utmTerm     Paid keyword term.
     * @param string $utmContent  Creative/variant identifier.
     * @param string $clickIdType Ad-click parameter NAME only (e.g. 'gclid'); never its value.
     */
    public function __construct(
        public string $utmSource = '',
        public string $utmMedium = '',
        public string $utmCampaign = '',
        public string $utmId = '',
        public string $utmTerm = '',
        public string $utmContent = '',
        public string $clickIdType = '',
    ) {
    }

    /**
     * Reads attribution out of a sanitized map — a decoded analytics context,
     * a stored submission row, or the tracker's cvm_context snapshot after
     * {@see Correlation} has validated it.
     *
     * Nothing here re-sanitizes: the callers hand over values that have already
     * been through the same validation the tracking endpoint applies. This only
     * narrows the types.
     *
     * @param array<string, mixed> $map Sanitized attribution values.
     * @return self
     */
    public static function fromArray(array $map): self
    {
        $value = static fn(string $key): string
            => is_scalar($map[$key] ?? null) ? (string) $map[$key] : '';

        return new self(
            utmSource: $value('utm_source'),
            utmMedium: $value('utm_medium'),
            utmCampaign: $value('utm_campaign'),
            utmId: $value('utm_id'),
            utmTerm: $value('utm_term'),
            utmContent: $value('utm_content'),
            clickIdType: $value('click_id_type'),
        );
    }

    /**
     * Whether the visit carried any campaign tagging at all.
     *
     * The click-id type is deliberately NOT part of this: an ad click is
     * unambiguous on its own and is handled before the tagged/untagged
     * question is asked. See {@see Channels::classify()}.
     *
     * @return bool
     */
    public function isTagged(): bool
    {
        return $this->utmSource !== ''
            || $this->utmMedium !== ''
            || $this->utmCampaign !== ''
            || $this->utmId !== ''
            || $this->utmTerm !== ''
            || $this->utmContent !== '';
    }

    /**
     * The wire and storage form: the seven keys, in the order every payload
     * has always carried them.
     *
     * @return array{utm_source: string, utm_medium: string, utm_campaign: string, utm_id: string, utm_term: string, utm_content: string, click_id_type: string}
     */
    public function toArray(): array
    {
        return [
            'utm_source'    => $this->utmSource,
            'utm_medium'    => $this->utmMedium,
            'utm_campaign'  => $this->utmCampaign,
            'utm_id'        => $this->utmId,
            'utm_term'      => $this->utmTerm,
            'utm_content'   => $this->utmContent,
            'click_id_type' => $this->clickIdType,
        ];
    }
}
