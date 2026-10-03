<?php
declare(strict_types=1);

namespace Convermetry\Tests\WordPress;

/**
 * Thrown where a real request would END: by the wp_die handlers and by the
 * wp_redirect filter that AdminRequestHandlersTest installs.
 *
 * In production wp_die() exits, wp_send_json_*() exits through wp_die(), and
 * every handler follows wp_safe_redirect() with exit. A test double that simply
 * returned would let execution continue past the point the handler meant to
 * stop — so a denied request could reach the side effect the guard exists to
 * prevent and the test would never see it. Throwing models the termination
 * exactly: nothing after the halt runs, and the test learns how the request
 * ended.
 */
final class HandlerHalted extends \RuntimeException
{
    /**
     * @param string $how      'die' or 'redirect'.
     * @param string $text     The wp_die() message, when it died.
     * @param int    $status   The wp_die() 'response' status, when one was given.
     * @param string $location The redirect target, when it redirected.
     */
    public function __construct(
        public readonly string $how,
        public readonly string $text = '',
        public readonly int $status = 0,
        public readonly string $location = ''
    ) {
        parent::__construct($how . ($text !== '' ? ': ' . $text : '') . ($location !== '' ? ' → ' . $location : ''));
    }
}
