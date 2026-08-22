<?php
declare(strict_types=1);

namespace Convermetry\Forms;

if (!defined('ABSPATH')) exit;

/**
 * Contract every form-provider integration implements.
 *
 * A provider adapts one WordPress form plugin to Convermetry: it detects
 * whether that plugin is active, discovers the site's forms for the Forms
 * admin page, and hooks the plugin's server-side SUCCESSFUL-submission
 * event — the authoritative "the form plugin accepted it" signal — into
 * {@see SubmissionService::record()}.
 *
 * Third-party providers can be added with the 'convermetry_form_providers'
 * filter (see {@see FormProviderRegistry}).
 */
interface FormProviderInterface
{
    /**
     * Stable machine key for this provider (lowercase, no spaces) — used in
     * form keys, payloads, and the Activity Log (e.g. 'elementor',
     * 'gravityforms').
     *
     * @return string
     */
    public function getKey(): string;

    /**
     * Human-readable provider name for the Forms page (e.g. 'Elementor Pro').
     *
     * @return string
     */
    public function getLabel(): string;

    /**
     * Whether the underlying form plugin is installed and active right now.
     * Must feature-detect (class/function/constant existence) and never
     * fatal when the plugin is absent.
     *
     * @return bool
     */
    public function isAvailable(): bool;

    /**
     * Discovers the site's forms for this provider.
     *
     * Only called when {@see isAvailable()} is true. Each form carries the
     * provider's native identity plus a display name:
     *
     * @return array<int, array{native_id: string, name: string}>
     */
    public function getForms(): array;

    /**
     * Registers the provider's server-side submission hooks. Only called
     * when {@see isAvailable()} is true.
     *
     * @param SubmissionService $service The pipeline confirmed submissions feed into.
     * @return void
     */
    public function registerHooks(SubmissionService $service): void;
}
