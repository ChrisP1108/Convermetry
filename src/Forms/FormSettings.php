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
    public const OPTION_KEY = 'cvm_form_settings';

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
     * Picks which stored key actually holds a form's configuration, when a
     * provider has migrated the identity it keys settings by.
     *
     * Elementor is the case this exists for: settings used to be keyed by form
     * NAME, which collapses every widget sharing a name into one shared
     * configuration. They are now keyed by the widget id, but an existing site
     * still has entries under the old name key — and simply switching would
     * silently orphan them. Resolution is therefore: use the new key when it
     * has an entry, otherwise fall back to the legacy key, otherwise the new
     * key (so a brand-new form is configured under the new identity).
     *
     * The legacy entry is never deleted here. Submissions queued before an
     * admin re-saves still carry the legacy form key and read it when their
     * delivery is first frozen, which can be long after the row was written.
     *
     * @param string $primaryKey Current provider-scoped form key.
     * @param string $legacyKey  Previous key for the same form ('' when none).
     * @return string The key to read configuration from.
     */
    public static function resolveKey(string $primaryKey, string $legacyKey): string
    {
        if ($legacyKey === '' || $legacyKey === $primaryKey) {
            return $primaryKey;
        }

        $all = self::all();

        if (isset($all[$primaryKey])) {
            return $primaryKey;
        }

        return isset($all[$legacyKey]) ? $legacyKey : $primaryKey;
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

        update_option(self::OPTION_KEY, $merged, false);
    }
}
