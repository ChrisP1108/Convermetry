<?php
declare(strict_types=1);

namespace Convermetry\Forms;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\Providers\ContactForm7Provider;
use Convermetry\Forms\Providers\ElementorProvider;
use Convermetry\Forms\Providers\FluentFormsProvider;
use Convermetry\Forms\Providers\GravityFormsProvider;
use Convermetry\Forms\Providers\WPFormsProvider;

/**
 * Central registry of every form-provider integration.
 *
 * Built-in providers (Elementor Pro, Gravity Forms, WPForms, Contact Form 7,
 * Fluent Forms) are instantiated here; third-party code adds its own
 * adapters via the 'convermetry_form_providers' filter:
 *
 *     add_filter('convermetry_form_providers', function (array $providers) {
 *         $providers[] = new My_Provider(); // implements FormProviderInterface
 *         return $providers;
 *     });
 *
 * Hook registration is feature-gated: a provider whose plugin is not active
 * never has its hooks registered, so activation can never fatal because a
 * third-party form plugin is missing.
 *
 * Form discovery results are cached briefly per provider — some providers'
 * discovery walks post meta — so admin screens don't re-run discovery on
 * every load.
 */
final class FormProviderRegistry
{
    /** Transient key prefix for per-provider discovery caches. */
    /**
     * Transient prefix for cached provider discovery. The suffix is a shape
     * version: discovery entries gained legacy_id/shared_id, and Elementor's
     * native_id changed from the form name to the widget id, so cached entries
     * written by an earlier release describe forms differently. Bumping this
     * retires them instead of serving a stale shape until they expire.
     */
    private const DISCOVERY_CACHE_PREFIX = 'cvm_forms_v2_';

    /** Seconds a provider's discovered-forms list is cached. */
    private const DISCOVERY_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

    /** @var array<string, FormProviderInterface>|null Memoized provider map (key → provider). */
    private ?array $providers = null;

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
            new GravityFormsProvider(),
            new WPFormsProvider(),
            new ContactForm7Provider(),
            new FluentFormsProvider(),
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
     * Registers submission hooks for every AVAILABLE provider.
     *
     * @param SubmissionService $service The shared submission pipeline.
     * @return void
     */
    public function registerHooks(SubmissionService $service): void
    {
        foreach ($this->all() as $provider) {
            if ($provider->isAvailable()) {
                $provider->registerHooks($service);
            }
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
        set_transient($cacheKey, $forms, self::DISCOVERY_CACHE_TTL);

        return $forms;
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
}
