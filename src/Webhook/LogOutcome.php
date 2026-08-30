<?php
declare(strict_types=1);

namespace Convermetry\Webhook;

if (!defined('ABSPATH')) exit;

/**
 * What became of one Activity Log write.
 *
 * Reported by {@see DeliveryLog::log()} and announced verbatim as the
 * $disposition argument of 'convermetry_delivery_attempt_logged', so the three
 * values are public API.
 *
 * This describes the LOG ROW, never the delivery: a delivery can succeed while
 * its log row is suppressed, and a delivery can fail while its row stores
 * perfectly.
 */
enum LogOutcome: string
{
    /** The row was inserted. */
    case Stored = 'stored';

    /** A convermetry_delivery_log_row callback returned false. */
    case Suppressed = 'suppressed';

    /** The INSERT itself failed. */
    case Failed = 'failed';
}
