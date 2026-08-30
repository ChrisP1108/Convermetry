<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

/**
 * The two kinds of message Convermetry sends over a webhook.
 *
 * These strings are WIRE VALUES. They travel in every payload's
 * 'message_type' property, are stored in the Activity Log's message_type
 * column, are exposed by the deliveries REST API, and are handed to every
 * delivery lifecycle action. Changing a case's value changes all five at
 * once, so the values here are fixed by the published schema — the enum
 * exists to stop a typo producing a sixth, silently unroutable variant, not
 * to make the vocabulary editable.
 *
 * {@see DeliveryContext::ANALYTICS} and {@see DeliveryContext::FORM} remain
 * the constants call sites and integrations use; both are now defined from
 * these cases, so the two can no longer drift apart.
 */
enum MessageType: string
{
    /** A scheduled (or retried, or tested) analytics reporting window. */
    case AnalyticsReport = 'analytics_report';

    /** One form submission delivered to one endpoint. */
    case FormSubmission = 'form_submission';

    /**
     * The case for a stored/received value, or null when it is not one of
     * the two.
     *
     * Used where a value arrives from the database or a request rather than
     * from code — the Activity Log's own rows included, since a row written
     * by an older version can hold anything.
     *
     * @param mixed $value Candidate wire value.
     * @return self|null
     */
    public static function tryFromMixed(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }

    /**
     * Every wire value, for validation against stored input.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
