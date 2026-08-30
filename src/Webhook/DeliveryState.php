<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

/**
 * A submission's recorded webhook delivery verdict.
 *
 * Stored in the submissions table's delivery_state column, used by the
 * Submissions page's status filter and CSV export, and announced by
 * 'convermetry_submission_delivery_state_changed' — so, again, wire values
 * fixed by what is already on disk.
 *
 * A submission is judged against the endpoints it was ACTUALLY attempted
 * against, never against the endpoints configured right now; see
 * {@see \Convermetry\Database\FormSubmissions::classifyDelivery()}, which owns
 * the derivation.
 *
 * NotSent is NEUTRAL, never a failure — it is the ordinary condition of a site
 * that uses the plugin without webhooks at all.
 */
enum DeliveryState: string
{
    /** No endpoint was ever attempted. */
    case NotSent = 'not_sent';

    /** At least one endpoint still has an undelivered queue row. */
    case Pending = 'pending';

    /** Some endpoints accepted the submission and others did not. */
    case Partial = 'partial';

    /** Every attempted endpoint accepted the submission. */
    case Delivered = 'delivered';

    /** Every attempted endpoint refused it, and none is still queued. */
    case Failed = 'failed';

    /**
     * The case for a stored value, or null when unrecognized.
     *
     * @param mixed $value Candidate stored value.
     * @return self|null
     */
    public static function tryFromMixed(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
