<?php
declare(strict_types=1);

namespace Convermetry\Settings;

if (!defined('ABSPATH')) exit;

/**
 * Announces settings changes, once they have actually happened.
 *
 * The obvious place to fire a "settings saved" event is the admin handler that
 * processes the form — and it would be wrong. The main settings screen posts to
 * options.php and reaches Convermetry only as a sanitize_callback, which runs on
 * a value that has not been stored yet and may never be: WordPress skips the
 * write entirely when the sanitized value matches what is already there. An
 * action fired from there would announce saves that did not happen and stay
 * silent about saves made any other way.
 *
 * So this listens on WordPress's own update_option_{$option} and
 * add_option_{$option} instead. Those fire only on a real write, carry the old
 * and new values, and catch every writer — the admin screens, WP-CLI, a
 * migration, another plugin — rather than only the ones that went through a
 * form.
 *
 * WHAT IS PASSED ON: the section name and the KEYS that changed. Never values.
 * Two of these options hold signing secrets and endpoint URLs that routinely
 * embed bearer tokens, and a "settings changed" listener is exactly the kind of
 * thing people wire to a log file or a Slack webhook.
 */
final class SettingsEvents
{
    /** @var array<string, string> Option key → the section name reported to listeners. */
    private const array SECTIONS = [
        Options::OPTION_KEY              => 'general',
        Options::WEBHOOK_OPTION_KEY      => 'webhooks',
        Options::NOTIFICATION_OPTION_KEY => 'notifications',
        Options::GOALS_OPTION_KEY        => 'goals',
        Options::FUNNELS_OPTION_KEY      => 'funnels',
    ];

    /**
     * Registers the option-write listeners.
     *
     * @return void
     */
    public static function init(): void
    {
        foreach (array_keys(self::SECTIONS) as $option) {
            add_action("update_option_{$option}", [self::class, 'onUpdate'], 10, 3);
            add_action("add_option_{$option}", [self::class, 'onAdd'], 10, 2);
        }
    }

    /**
     * Handles an updated option.
     *
     * @param mixed  $old    The previous stored value.
     * @param mixed  $new    The value just stored.
     * @param string $option The option name.
     * @return void
     */
    public static function onUpdate(mixed $old, mixed $new, string $option): void
    {
        self::announce($option, self::changedKeys($old, $new));
    }

    /**
     * Handles an option stored for the first time.
     *
     * @param string $option The option name.
     * @param mixed  $value  The value just stored.
     * @return void
     */
    public static function onAdd(string $option, mixed $value): void
    {
        self::announce($option, self::changedKeys([], $value));
    }

    /**
     * Fires the public action for one settings section.
     *
     * @param string   $option      The option name that was written.
     * @param string[] $changedKeys Top-level keys whose value differs.
     * @return void
     */
    private static function announce(string $option, array $changedKeys): void
    {
        $section = self::SECTIONS[$option] ?? '';

        if ($section === '') {
            return;
        }

        /**
         * Fires after a Convermetry settings section is written to the database.
         *
         * Fires only on a REAL write. WordPress skips update_option() entirely
         * when the value is unchanged, and this listens on WordPress's own
         * write hooks, so a form submitted without edits announces nothing.
         * It catches every writer, not just the admin screens: WP-CLI, a
         * migration, or another plugin calling update_option() all reach here.
         *
         * $section is one of 'general', 'webhooks', 'notifications', 'goals',
         * or 'funnels'.
         *
         * $changedKeys names the top-level keys whose value differs. VALUES ARE
         * DELIBERATELY NOT PASSED: the webhooks section holds the shared signing
         * secret, per-endpoint secrets, and endpoint URLs that frequently embed
         * bearer tokens, and this is precisely the sort of action people wire to
         * a log file or a chat notification. Read what you need with the Options
         * accessors, which is an explicit decision rather than an accident.
         *
         * Settings changes can have consequences: shortening retention_days
         * deletes data on the next cleanup pass, and turning off
         * store_ip_address stops new addresses being kept.
         *
         * @param string   $section     The settings section that changed.
         * @param string[] $changedKeys Top-level keys whose value differs.
         */
        do_action('convermetry_settings_saved', $section, $changedKeys);
    }

    /**
     * The top-level keys whose value differs between two stored option values.
     *
     * @param mixed $old Previous value.
     * @param mixed $new New value.
     * @return string[]
     */
    private static function changedKeys(mixed $old, mixed $new): array
    {
        if (!is_array($old) || !is_array($new)) {
            return [];
        }

        $changed = [];

        foreach (array_keys($old + $new) as $key) {
            $before = $old[$key] ?? null;
            $after  = $new[$key] ?? null;

            if ($before !== $after) {
                $changed[] = (string) $key;
            }
        }

        return $changed;
    }
}
