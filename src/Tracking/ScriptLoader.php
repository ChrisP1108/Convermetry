<?php
declare(strict_types=1);

namespace Convermetry\Tracking;

if (!defined('ABSPATH')) exit;

use Convermetry\Goals\GoalRepository;
use Convermetry\Settings\Options;

/**
 * Enqueues the frontend tracker script and injects its configuration.
 *
 * The tracker is a single dependency-free script loaded deferred in the
 * footer. Its behavior (REST endpoint, which event types to record, hover
 * dwell time, batching, form correlation) is passed via a
 * window.ConvermetryConfig object printed immediately before the script tag,
 * so the JS file itself stays static and cacheable.
 */
final class ScriptLoader
{
    /** Script handle for the frontend tracker. */
    private const string HANDLE = 'cvm-tracker';

    /**
     * Registers the wp_enqueue_scripts hook.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    /**
     * Enqueues the tracker on frontend requests that should be tracked.
     *
     * Skipped entirely for logged-in users when exclusion is enabled and when
     * every event type has been turned off — in both cases no script tag is
     * printed at all.
     *
     * @return void
     */
    public static function enqueue(): void
    {
        if (Options::excludeLoggedIn() && is_user_logged_in()) {
            return;
        }

        $enabled = Options::enabledTypes();

        // The tracker is not needed only when there is genuinely nothing for it
        // to do. "No event types enabled" used to be the whole test, which would
        // have silently disabled goal collection on a site that had turned the
        // built-in interactions off but still had custom-event goals configured
        // — the goals would have looked configured and collected nothing.
        //
        // Note the asymmetry: a URL or click goal cannot be rescued here,
        // because it is matched against pageview/click events that are switched
        // off at source. The Goals screen warns about exactly that case rather
        // than silently re-enabling tracking the site owner turned off.
        $selectorGoals = GoalRepository::browserSelectors();

        if ($enabled === [] && $selectorGoals === []) {
            return;
        }

        wp_enqueue_script(
            self::HANDLE,
            CVM_PLUGIN_URL . 'assets/js/tracker.js',
            [],
            CVM_VERSION,
            ['in_footer' => true, 'strategy' => 'defer']
        );

        $config = [
            'endpoint'        => esc_url_raw(rest_url('convermetry/v1/track')),
            'events'          => array_fill_keys($enabled, true),
            'hoverDwellMs'    => Options::hoverDwellMs(),
            'flushIntervalMs' => 5000,
            'maxBatch'        => 20,
            'respectDnt'      => Options::respectDnt(),
            // The ONLY goal configuration that ever reaches a browser: the CSS
            // selectors that cannot be evaluated without the DOM, each with the
            // id to report back. No name, no value, no operator, and nothing at
            // all about goals of any other type — a site's list of valuable
            // actions is competitive information and stays on the server.
            'selectorGoals'   => (object) $selectorGoals,
        ];

        wp_add_inline_script(
            self::HANDLE,
            'window.ConvermetryConfig = ' . wp_json_encode($config) . ';',
            'before'
        );
    }
}
