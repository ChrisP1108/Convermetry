<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

/**
 * How a delivery attempt came to be made.
 *
 * Like {@see MessageType} these are WIRE VALUES: they are stored in the
 * Activity Log's 'kind' column, returned by the deliveries REST API, and
 * carried on every delivery lifecycle action's context. They answer "why is
 * this request happening?", which is the question the Activity Log is read to
 * answer, and they are deliberately orthogonal to the outcome.
 *
 * 'scheduled' is the fallback for an unrecognized stored value, matching the
 * column default — see {@see DeliveryLog::log()}.
 */
enum DeliveryKind: string
{
    /** An analytics reporting window dispatched by its cron schedule. */
    case Scheduled = 'scheduled';

    /** A form submission's first attempt, queued or synchronous. */
    case Immediate = 'immediate';

    /** Any attempt after the first in a retry chain. */
    case Retry = 'retry';

    /** A "Send test" button on the Webhooks page. */
    case Test = 'test';

    /**
     * The case for a stored value, or null when unrecognized.
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
