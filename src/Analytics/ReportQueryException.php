<?php
declare(strict_types=1);

namespace Convermetry\Analytics;

if (!defined('ABSPATH')) exit;

/**
 * Thrown when an aggregate report query fails at the database level.
 *
 * $wpdb turns a failed query into an empty array or null indistinguishable
 * from a legitimate zero; {@see Reports} converts that back into this
 * exception so callers (the dashboard, the webhook dispatcher) can tell
 * "no data" apart from "the query failed" — and never silently deliver an
 * empty report built from a failed fetch.
 */
final class ReportQueryException extends \RuntimeException
{
}
