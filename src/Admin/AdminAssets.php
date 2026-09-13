<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

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
 *  - cvm-admin-ui      assets/css/admin-ui.css — the design system: colour,
 *                      type, radius and spacing TOKENS, plus the `cvm-ui-*`
 *                      component classes the Home page is built from. Every
 *                      selector is scoped under `.cvm-ui`, so it does nothing
 *                      on a screen that has not opted in — which is what lets
 *                      the other screens adopt a component at a time, with no
 *                      further asset wiring and no risk to the ones that have
 *                      not.
 *  - cvm-admin-common  assets/css/admin-common.css — the older `cvm-*`
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
 * Neither is enqueued anywhere outside Convermetry's own screens.
 */
final class AdminAssets
{
    /** Style handle for the design-system tokens; other screens depend on it. */
    public const string DESIGN_SYSTEM_HANDLE = 'cvm-admin-ui';

    /** Style handle for the shared, multi-page component styles. */
    public const string COMMON_HANDLE = 'cvm-admin-common';

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
            CVM_PLUGIN_URL . 'assets/css/admin-ui.css',
            [],
            CVM_VERSION
        );

        wp_enqueue_style(
            self::COMMON_HANDLE,
            CVM_PLUGIN_URL . 'assets/css/admin-common.css',
            [self::DESIGN_SYSTEM_HANDLE],
            CVM_VERSION
        );
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
