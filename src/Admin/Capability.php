<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Resolves the WordPress capability required for each of Convermetry's admin
 * surfaces.
 *
 * Every one of these used to be the literal 'manage_options', written out in
 * forty-odd places: on each menu registration, at the top of each render()
 * method, and inside each AJAX and admin-post handler. That is the correct
 * default — the plugin manages webhook credentials and stores lead data — but
 * having it as a literal meant a site could not delegate anything without
 * making somebody a full administrator.
 *
 * The scopes are separate because these permissions genuinely are not the same
 * permission. Reading a conversion chart, exporting a CSV of every lead's name
 * and email, rotating a webhook signing secret, and deleting every submission
 * on the site are four different levels of trust, and a single filter would
 * have forced a site to grant the highest of them to get any of them.
 *
 * All of them default to 'manage_options', so a site that registers no callback
 * behaves exactly as it always has.
 */
final class Capability
{
    /** The capability every scope resolves to unless a filter says otherwise. */
    public const string DEFAULT = 'manage_options';

    /** Viewing the analytics dashboard and its reports. */
    public const string ANALYTICS_VIEW = 'analytics.view';

    /** Viewing the submissions list and one submission's detail (reads PII). */
    public const string SUBMISSIONS_VIEW = 'submissions.view';

    /** Exporting submissions as CSV (bulk PII egress). */
    public const string SUBMISSIONS_EXPORT = 'submissions.export';

    /** Deleting one submission, or clearing them all (irreversible). */
    public const string SUBMISSIONS_DELETE = 'submissions.delete';

    /** Changing a lead's status or value. */
    public const string LEADS_EDIT = 'leads.edit';

    /** Creating, editing, and deleting goals. */
    public const string GOALS_MANAGE = 'goals.manage';

    /** Creating, editing, and deleting funnels. */
    public const string FUNNELS_MANAGE = 'funnels.manage';

    /** Configuring per-form settings. */
    public const string FORMS_MANAGE = 'forms.manage';

    /** Configuring notification recipients and content. */
    public const string NOTIFICATIONS_MANAGE = 'notifications.manage';

    /** Configuring webhook endpoints and their signing secrets. */
    public const string WEBHOOKS_MANAGE = 'webhooks.manage';

    /** Viewing the Activity Log (delivery metadata, redacted payloads). */
    public const string ACTIVITY_VIEW = 'activity.view';

    /** Deleting Activity Log entries, or clearing the log. */
    public const string ACTIVITY_MANAGE = 'activity.manage';

    /** Enabling the delivery-log REST API and rotating its key. */
    public const string API_MANAGE = 'api.manage';

    /** Changing plugin settings, including retention and IP storage. */
    public const string SETTINGS_MANAGE = 'settings.manage';

    /** @var array<string, string> Resolved capability per scope, per request. */
    private static array $resolved = [];

    /**
     * The capability required for one scope.
     *
     * @param string $scope One of this class's scope constants.
     * @return string A WordPress capability name.
     */
    public static function required(string $scope): string
    {
        if (isset(self::$resolved[$scope])) {
            return self::$resolved[$scope];
        }

        /**
         * Filters the capability required for one Convermetry admin surface.
         *
         * Applied consistently: the same call decides whether a menu entry is
         * shown, whether a page renders, and whether the AJAX, admin-post, and
         * REST handlers behind it will act — so a capability that grants access
         * to a screen always grants access to the things that screen does, and
         * one that does not is refused at every layer rather than merely hidden.
         *
         * $scope names the surface. Available scopes, all defaulting to
         * 'manage_options':
         *
         *   analytics.view        the dashboard and its reports
         *   submissions.view      the submissions list and detail (reads PII)
         *   submissions.export    CSV export (bulk PII egress)
         *   submissions.delete    deleting one submission or all of them
         *   leads.edit            changing a lead's status or value
         *   goals.manage          creating, editing, deleting goals
         *   funnels.manage        creating, editing, deleting funnels
         *   forms.manage          per-form configuration
         *   notifications.manage  notification recipients and content
         *   webhooks.manage       endpoints and their signing secrets
         *   activity.view         the Activity Log
         *   activity.manage       deleting Activity Log entries
         *   api.manage            the delivery-log REST API and its key
         *   settings.manage       plugin settings, retention, IP storage
         *
         * Grant deliberately. 'submissions.export' hands out every lead's name
         * and email in one file; 'webhooks.manage' exposes signing secrets and
         * endpoint URLs that frequently contain bearer tokens; 'settings.manage'
         * can shorten the retention period that deletes data.
         *
         * The return value must be a non-empty lowercase capability name
         * ([a-z0-9_]); anything else falls back to 'manage_options'. Convermetry
         * validates the SHAPE only and never the privilege level — granting a
         * lower or higher capability is the site owner's decision — but an empty
         * string would make current_user_can() false for everyone and lock the
         * owner out of their own plugin, so that specific mistake is refused.
         *
         * Resolved once per scope per request, so registering a callback after
         * the admin menu is built has no effect.
         *
         * @param string $capability The default, 'manage_options'.
         * @param string $scope      The surface being authorized.
         */
        $filtered = apply_filters('convermetry_admin_capability', self::DEFAULT, $scope);

        $capability = is_string($filtered) && preg_match('~^[a-z0-9_]+$~', $filtered) === 1
            ? $filtered
            : self::DEFAULT;

        return self::$resolved[$scope] = $capability;
    }

    /**
     * Whether the current user may act on one scope.
     *
     * @param string $scope One of this class's scope constants.
     * @return bool
     */
    public static function currentUserCan(string $scope): bool
    {
        return current_user_can(self::required($scope));
    }

    /**
     * Clears the resolved capabilities.
     *
     * Only needed where one PHP process serves more than one logical request —
     * tests, and long-running CLI workers.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$resolved = [];
    }
}
