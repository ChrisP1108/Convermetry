<?php
declare(strict_types=1);

namespace Convermetry\Forms;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\Providers\BricksFormsProvider;
use Convermetry\Forms\Providers\ContactForm7Provider;
use Convermetry\Forms\Providers\ElementorAtomicProvider;
use Convermetry\Forms\Providers\ElementorProvider;
use Convermetry\Forms\Providers\FluentFormsProvider;
use Convermetry\Forms\Providers\FormidableFormsProvider;
use Convermetry\Forms\Providers\GravityFormsProvider;
use Convermetry\Forms\Providers\NinjaFormsProvider;
use Convermetry\Forms\Providers\WPFormsProvider;

/**
 * Central registry of every form-provider integration.
 *
 * Built-in providers (Elementor Pro classic and Atomic, Bricks Builder,
 * Gravity Forms, WPForms, Contact Form 7, Fluent Forms, Ninja Forms, Formidable
 * Forms) are instantiated here; third-party code adds its own adapters via the
 * 'convermetry_form_providers' filter:
 *
 *     add_filter('convermetry_form_providers', function (array $providers) {
 *         $providers[] = new My_Provider(); // implements FormProviderInterface
 *         return $providers;
 *     });
 *
 * Hook registration is feature-gated: a provider whose plugin is not active
 * never has its hooks registered, so activation can never fatal because a
 * third-party form plugin is missing. It also runs in TWO passes, because not
 * every integration is a plugin — see {@see registerHooks()}.
 *
 * Form discovery results are cached briefly per provider — some providers'
 * discovery walks post meta — so admin screens don't re-run discovery on
 * every load.
 */
final class FormProviderRegistry
{
    /** Transient key prefix for per-provider discovery caches. */
    private const string DISCOVERY_CACHE_PREFIX = 'cvmtry_forms_';

    /** Seconds a provider's discovered-forms list is cached. */
    private const int DISCOVERY_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

    /** @var array<string, FormProviderInterface>|null Memoized provider map (key → provider). */
    private ?array $providers = null;

    /** @var array<string, true> Provider keys whose hooks have already been registered. */
    private array $registered = [];

    /** Whether the deferred theme-lifecycle pass has been scheduled. */
    private bool $themePassScheduled = false;

    /**
     * Returns every registered provider, keyed by provider key.
     *
     * @return array<string, FormProviderInterface>
     */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [
            new ElementorProvider(),
            new ElementorAtomicProvider(),
            new BricksFormsProvider(),
            new GravityFormsProvider(),
            new WPFormsProvider(),
            new ContactForm7Provider(),
            new FluentFormsProvider(),
            new NinjaFormsProvider(),
            new FormidableFormsProvider(),
        ];

        /**
         * Filters the registered form providers. Append objects implementing
         * {@see FormProviderInterface} to integrate additional form plugins.
         *
         * @param FormProviderInterface[] $providers The provider list.
         */
        $providers = (array) apply_filters('convermetry_form_providers', $providers);

        $map = [];
        foreach ($providers as $provider) {
            if ($provider instanceof FormProviderInterface) {
                $map[$provider->getKey()] = $provider;
            }
        }

        return $this->providers = $map;
    }

    /**
     * Returns one provider by key, or null.
     *
     * @param string $key Provider key.
     * @return FormProviderInterface|null
     */
    public function get(string $key): ?FormProviderInterface
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Registers submission hooks for every AVAILABLE provider, in two passes.
     *
     * WHY TWO. Convermetry initialises on plugins_loaded, which is the right
     * moment for a provider whose integration is a PLUGIN: by then every active
     * plugin's file has been loaded and its constants defined. It is the wrong
     * moment for a provider whose integration is a THEME. WordPress loads the
     * active theme's functions.php after plugins_loaded has finished, so a
     * Bricks site truthfully answers "Bricks is not here" during Convermetry's
     * own boot — and a single-pass registry would take that answer as final and
     * wire nothing, on every Bricks site, forever.
     *
     * So the first pass runs immediately, exactly as before, and a second pass
     * runs on after_setup_theme — the first hook that fires once the theme
     * (parent and child alike) is loaded, and still early enough for everything
     * a form integration needs to register.
     *
     * The second pass is NOT a re-run. {@see registerAvailable()} skips every
     * provider key it has already registered, so:
     *
     *  - a provider available at plugins_loaded registers there and is never
     *    touched again — existing provider timing is unchanged;
     *  - a provider that only becomes available once the theme is loaded
     *    registers exactly once, in the second pass;
     *  - a provider available in neither registers nothing at all.
     *
     * Third-party providers added through 'convermetry_form_providers' keep
     * their existing behaviour and gain the same second chance: one that was
     * unavailable at plugins_loaded used to get nothing, and now registers if it
     * has become available. The filter itself still runs exactly once, when the
     * provider list is first built.
     *
     * Calling this method more than once is safe for the same reason, and a
     * second call schedules no second deferred pass.
     *
     * @param SubmissionService $service The shared submission pipeline.
     * @return void
     */
    public function registerHooks(SubmissionService $service): void
    {
        $this->registerAvailable($service);

        if ($this->themePassScheduled) {
            return;
        }

        $this->themePassScheduled = true;

        // Init can legitimately run after the theme is already loaded — a late
        // manual boot, or a test harness. Deferring then would wait for a hook
        // that has already fired and never register anything.
        if (did_action('after_setup_theme') > 0) {
            $this->registerAvailable($service);

            return;
        }

        add_action('after_setup_theme', function () use ($service): void {
            $this->registerAvailable($service);
        }, 20);
    }

    /**
     * Registers hooks for every available provider not already registered.
     *
     * The idempotency guard is by provider KEY rather than by object identity,
     * so it holds across both passes and however many times either runs.
     *
     * @param SubmissionService $service The shared submission pipeline.
     * @return void
     */
    private function registerAvailable(SubmissionService $service): void
    {
        foreach ($this->all() as $key => $provider) {
            if (isset($this->registered[$key]) || !$provider->isAvailable()) {
                continue;
            }

            $this->registered[$key] = true;
            $provider->registerHooks($service);
        }
    }

    /**
     * Discovered forms for one provider, cached briefly.
     *
     * @param FormProviderInterface $provider The provider to discover for.
     * @param bool                  $fresh    Bypass (and refresh) the cache.
     * @return array<int, array{native_id: string, name: string}>
     */
    public function discoveredForms(FormProviderInterface $provider, bool $fresh = false): array
    {
        if (!$provider->isAvailable()) {
            return [];
        }

        $cacheKey = self::DISCOVERY_CACHE_PREFIX . $provider->getKey();

        if (!$fresh) {
            $cached = get_transient($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $forms = $provider->getForms();

        /**
         * Filters the forms discovered for one provider.
         *
         * Runs after the provider's own discovery and BEFORE the result is
         * cached, so a callback can add a form the provider cannot enumerate
         * (one built by a page builder, or registered only at runtime), rename
         * one, or hide one from the Forms page.
         *
         * Because the filtered list is what gets cached — for five minutes —
         * the result is normalized first: each entry must be an array reducible
         * to {native_id, name}, entries without a native id are dropped, names
         * fall back to the id, and duplicate native ids collapse to the first
         * occurrence. A malformed return would otherwise poison the cache for
         * every admin who loads the page in the next five minutes.
         *
         * This affects which forms an administrator can CONFIGURE. It does not
         * affect which submissions are recorded: a form absent from this list
         * still records submissions under its own form key.
         *
         * @param array<int, array{native_id: string, name: string}> $forms Discovered forms.
         * @param string $providerKey Provider key (e.g. 'elementor').
         */
        $filtered = apply_filters('convermetry_discovered_forms', $forms, $provider->getKey());

        if ($filtered !== $forms) {
            $forms = self::normalizeDiscovered($filtered);
        }

        set_transient($cacheKey, $forms, self::DISCOVERY_CACHE_TTL);

        return $forms;
    }

    /**
     * Reduces a filtered discovery result back to the canonical shape.
     *
     * @param mixed $forms Whatever the filter returned.
     * @return array<int, array{native_id: string, name: string}>
     */
    private static function normalizeDiscovered(mixed $forms): array
    {
        $out  = [];
        $seen = [];

        foreach (is_array($forms) ? $forms : [] as $form) {
            if (!is_array($form)) {
                continue;
            }

            $nativeId = sanitize_text_field((string) ($form['native_id'] ?? ''));
            if ($nativeId === '' || isset($seen[$nativeId])) {
                continue;
            }

            $name = sanitize_text_field((string) ($form['name'] ?? ''));

            $seen[$nativeId] = true;
            $out[]           = ['native_id' => $nativeId, 'name' => $name !== '' ? $name : $nativeId];
        }

        return $out;
    }

    /**
     * The provider-scoped form key for one form — the stable identity every
     * per-form setting is stored under, so a rename never orphans a form's
     * configuration (providers use their most stable native id available).
     *
     * @param string $providerKey Provider key.
     * @param string $nativeId    Provider-native form identity.
     * @return string
     */
    public static function formKey(string $providerKey, string $nativeId): string
    {
        return $providerKey . ':' . $nativeId;
    }

    /**
     * The key a form's settings were stored under BEFORE its provider changed
     * identity, or '' when that provider never re-keyed.
     *
     * Only Elementor has re-keyed. Its settings were keyed by form NAME, so two
     * widgets both left at the default "New Form" shared one configuration and
     * renaming a form orphaned its settings; 0.8.0 moved it to the stable widget
     * id. Everything that reads per-form configuration passes this to
     * {@see FormSettings::resolveKey()} so a site that has not re-saved since
     * upgrading still finds its settings.
     *
     * Centralised here rather than spread across callers: the historical mapping
     * is one fact about one provider, and it has to stay consistent between the
     * admin screen that reads a form's configuration and the submission path
     * that applies it.
     *
     * The Atomic provider ('elementor_atomic') deliberately does NOT match here.
     * It has no name-keyed history to migrate, and granting it one would let a
     * classic Elementor form's settings apply to a same-named Atomic form —
     * inheriting a legacy identity that was never its own.
     *
     * @param string $providerKey Provider key.
     * @param string $name        The form's display name.
     * @return string Legacy provider-scoped key, or ''.
     */
    public static function legacyFormKey(string $providerKey, string $name): string
    {
        return ($providerKey === 'elementor' && $name !== '')
            ? self::formKey($providerKey, $name)
            : '';
    }
}
