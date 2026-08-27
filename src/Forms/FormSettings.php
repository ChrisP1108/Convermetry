<?php
declare(strict_types=1);

namespace Convermetry\Forms;

if (!defined('ABSPATH')) exit;

/**
 * Per-form configuration store.
 *
 * Settings are keyed by the provider-scoped form key
 * ({@see FormProviderRegistry::formKey()}), stored in one non-autoloaded
 * option. A form's configuration is PRESERVED while the form is excluded —
 * re-enabling it restores everything — and detected forms are INCLUDED by
 * default: administrators never have to configure a new form for it to
 * start delivering.
 *
 * Per form:
 *  - form_id             — optional custom/external identifier sent as
 *                          'form_id' in the payload (native id is the fallback).
 *  - excluded            — when true, the form's submissions are not recorded
 *                          or delivered.
 *  - include_page_params — pass the submitting page's URL query parameters
 *                          through to the webhook URL for this form.
 *  - query_params        — per-form URL query parameters (highest precedence).
 *  - headers             — per-form request headers.
 */
final class FormSettings
{
    /** The wp_options key holding all per-form configuration. */
    public const string OPTION_KEY = 'cvm_form_settings';

    /**
     * Returns every stored per-form configuration.
     *
     * @return array<string, array<string, mixed>> Map of form key → config.
     */
    public static function all(): array
    {
        $stored = get_option(self::OPTION_KEY, []);

        return is_array($stored) ? $stored : [];
    }

    /**
     * Returns one form's configuration with every key present and typed, so
     * callers never guard against missing sub-keys.
     *
     * @param string $formKey Provider-scoped form key.
     * @return array{form_id: string, excluded: bool, include_page_params: bool, query_params: array<int, array{key: string, value: string}>, headers: array<int, array{key: string, value: string}>}
     */
    public static function forForm(string $formKey): array
    {
        $entry = self::all()[$formKey] ?? [];

        return [
            'form_id'             => (string) ($entry['form_id'] ?? ''),
            'excluded'            => !empty($entry['excluded']),
            'include_page_params' => !empty($entry['include_page_params']),
            'query_params'        => is_array($entry['query_params'] ?? null)
                ? array_values(array_filter($entry['query_params'], 'is_array'))
                : [],
            'headers'             => is_array($entry['headers'] ?? null)
                ? array_values(array_filter($entry['headers'], 'is_array'))
                : [],
        ];
    }

    /**
     * Whether a form is currently excluded from Convermetry processing.
     * Unknown (newly detected) forms are included by default.
     *
     * @param string $formKey Provider-scoped form key.
     * @return bool
     */
    public static function isExcluded(string $formKey): bool
    {
        return self::forForm($formKey)['excluded'];
    }

    /**
     * The effective payload form_id for a form: the configured custom id
     * when set, otherwise the provider's native id.
     *
     * @param string $formKey  Provider-scoped form key.
     * @param string $nativeId Provider-native form identity (fallback).
     * @return string
     */
    public static function effectiveFormId(string $formKey, string $nativeId): string
    {
        $custom = self::forForm($formKey)['form_id'];

        return $custom !== '' ? $custom : $nativeId;
    }

    /**
     * Replaces the stored configuration for a set of form keys, preserving
     * every key NOT in $rendered — configuration for forms whose provider is
     * temporarily deactivated (or that were not rendered on the saving page)
     * survives untouched.
     *
     * @param array<string, array<string, mixed>> $configs  Map of form key → sanitized config.
     * @param string[]                            $rendered Form keys that were actually rendered/submitted.
     * @return void
     */
    public static function saveRendered(array $configs, array $rendered): void
    {
        $merged = self::all();

        foreach ($rendered as $formKey) {
            $merged[$formKey] = $configs[$formKey] ?? [
                'form_id'             => '',
                'excluded'            => false,
                'include_page_params' => false,
                'query_params'        => [],
                'headers'             => [],
            ];
        }

        $saved = update_option(self::OPTION_KEY, $merged, false);

        // Announced only on a real write. update_option() returns false when
        // the stored value is unchanged, and a "settings saved" event that fires
        // for a no-op save is one an integration cannot act on.
        if ($saved) {
            /**
             * Fires after per-form settings are persisted.
             *
             * Fires once per save, from the storage layer rather than the admin
             * page, so a future CLI or REST caller raises the same event.
             *
             * $formKeys lists the forms that were rendered on the saving screen
             * — the ones whose configuration this save could have changed. Forms
             * whose provider is deactivated keep their stored configuration and
             * are not listed. Values are not passed: read them with
             * FormSettings::forForm() if you need them.
             *
             * @param string[] $formKeys Provider-scoped form keys included in this save.
             */
            do_action('convermetry_form_settings_saved', array_values($rendered));
        }
    }
}
