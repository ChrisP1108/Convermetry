<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\Pages\AnalyticsPage;
use Convermetry\Admin\Pages\HomePage;

/**
 * The two stylesheets every Convermetry admin screen shares.
 *
 * This used to live on {@see AnalyticsPage}, which worked only because that
 * page owned the top-level slug 'convermetry' and every Convermetry hook
 * suffix therefore contained it. When the Home page took the top-level slug
 * and Analytics moved to its own, that arrangement would have quietly stopped
 * enqueueing the shared stylesheet on nine screens — the kind of breakage that
 * produces no error, just unstyled pages. Shared assets now belong to a class
 * whose only job is sharing them.
 *
 * Two sheets, loaded in this order because the second reads custom properties
 * the first declares:
 *
 *  - cvmtry-admin-ui      assets/css/admin-ui.css — the design system: colour,
 *                      type, radius and spacing TOKENS, plus the `cvmtry-ui-*`
 *                      component classes the Home page is built from. Every
 *                      selector is scoped under `.cvmtry-ui`, so it does nothing
 *                      on a screen that has not opted in — which is what lets
 *                      the other screens adopt a component at a time, with no
 *                      further asset wiring and no risk to the ones that have
 *                      not.
 *  - cvmtry-admin-common  assets/css/admin-common.css — the older `cvmtry-*`
 *                      component classes shared by two or more admin screens
 *                      (cards, the toggle switch, the accordion/pagination
 *                      list pattern, and so on), plus the rules that point
 *                      THEIR headings and buttons at the same design-system
 *                      tokens. A class used by exactly one screen lives in
 *                      that screen's own assets/css/admin-<page>.css instead,
 *                      enqueued by that page's own enqueueAssets() with a
 *                      dependency on this handle — see admin-common.css's
 *                      header for the full per-page map.
 *
 * One script is registered alongside them: cvmtry-admin-confirm
 * (assets/js/admin-confirm.js), which asks before any element carrying
 * data-cvmtry-confirm runs its action. Page scripts that need it list it as a
 * dependency.
 *
 * None of them is registered or enqueued outside Convermetry's own screens.
 */
final class AdminAssets
{
    /** Style handle for the design-system tokens; other screens depend on it. */
    public const string DESIGN_SYSTEM_HANDLE = 'cvmtry-admin-ui';

    /** Style handle for the shared, multi-page component styles. */
    public const string COMMON_HANDLE = 'cvmtry-admin-common';

    /** Script handle for the data-cvmtry-confirm prompts on destructive actions. */
    public const string CONFIRM_HANDLE = 'cvmtry-admin-confirm';

    /**
     * Registers the enqueue hook.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    /**
     * Enqueues the shared stylesheets on Convermetry screens only.
     *
     * @param string $hook The current admin page hook suffix.
     * @return void
     */
    public static function enqueue(string $hook): void
    {
        if (!self::isConvermetryScreen($hook)) {
            return;
        }

        wp_enqueue_style(
            self::DESIGN_SYSTEM_HANDLE,
            CVMTRY_PLUGIN_URL . 'assets/css/admin-ui.css',
            [],
            CVMTRY_VERSION
        );

        wp_enqueue_style(
            self::COMMON_HANDLE,
            CVMTRY_PLUGIN_URL . 'assets/css/admin-common.css',
            [self::DESIGN_SYSTEM_HANDLE],
            CVMTRY_VERSION
        );

        // Registered, not enqueued: it loads only as a dependency of the page
        // scripts whose screens offer a Remove / Clear All action, so none of
        // them needs an inline event handler to ask first — and a screen with
        // no such action (Home ships no JavaScript at all) never receives it.
        wp_register_script(
            self::CONFIRM_HANDLE,
            CVMTRY_PLUGIN_URL . 'assets/js/admin-confirm.js',
            [],
            CVMTRY_VERSION,
            true
        );
    }

    /**
     * The twelve month names in the site's language, January first.
     *
     * Handed to the scripts that build month filters in the browser, so they
     * use WordPress's own translations rather than carrying a second copy of
     * the calendar in this plugin's text domain.
     *
     * @return list<string>
     */
    public static function monthNames(): array
    {
        global $wp_locale;

        $names = [];
        for ($month = 1; $month <= 12; $month++) {
            $names[] = $wp_locale instanceof \WP_Locale
                ? (string) $wp_locale->get_month($month)
                : gmdate('F', (int) gmmktime(0, 0, 0, $month, 1, 2000));
        }

        return $names;
    }

    /**
     * Whether a hook suffix belongs to one of Convermetry's admin screens.
     *
     * Every Convermetry slug starts with the top-level 'convermetry', so the
     * substring test covers the top-level screen and every submenu without
     * having to list them.
     *
     * @param string $hook The current admin page hook suffix.
     * @return bool
     */
    public static function isConvermetryScreen(string $hook): bool
    {
        return str_contains($hook, HomePage::MENU_SLUG);
    }
}
