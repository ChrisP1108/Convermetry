<?php
declare(strict_types=1);

namespace Convermetry\Support;

if (!defined('ABSPATH')) exit;

/**
 * Whether the CURRENT request carries a browser opt-out signal — Do Not
 * Track or Global Privacy Control.
 *
 * The single source of truth for the non-REST write paths (the server-side
 * conversion recorder, event inserts, and IP capture), so "this visitor
 * opted out" cannot mean two different things in two files. The tracking
 * REST endpoint reads the same two headers off its WP_REST_Request instead,
 * because at that point the headers are the request object's, not the
 * superglobals'.
 *
 * These signals are only ACTED ON when the site has opted in via
 * Options::respectDnt() — DNT/GPC is an opt-out signal, never a consent
 * mechanism, so callers pair this with that setting rather than treating a
 * missing signal as permission.
 */
final class PrivacySignal
{
    /**
     * Whether the current request asks not to be tracked.
     *
     * @return bool True when DNT: 1 or Sec-GPC: 1 is present.
     */
    public static function fromCurrentRequest(): bool
    {
        return ($_SERVER['HTTP_DNT'] ?? '') === '1'
            || ($_SERVER['HTTP_SEC_GPC'] ?? '') === '1';
    }
}
