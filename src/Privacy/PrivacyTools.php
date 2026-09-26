<?php
declare(strict_types=1);

namespace Convermetry\Privacy;

if (!defined('ABSPATH')) exit;

/**
 * Wires Convermetry into WordPress's privacy tools: the suggested policy text
 * (Settings → Privacy) and the personal-data exporter and eraser that the
 * Tools → Export / Erase Personal Data screens run for an email address.
 *
 * Registered on every request rather than only in wp-admin because the
 * exporter and eraser run inside admin-ajax requests, where is_admin() is true
 * but the Tools screen that started them is not the current page. The filters
 * cost one array entry each.
 */
final class PrivacyTools
{
    /** Key under which the exporter and eraser are registered. */
    public const string KEY = 'convermetry';

    /**
     * Registers the hooks.
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('admin_init', [PrivacyPolicy::class, 'register']);
        add_filter('wp_privacy_personal_data_exporters', [self::class, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [self::class, 'registerEraser']);
    }

    /**
     * @param mixed $exporters Registered exporters.
     * @return array<string, mixed>
     */
    public static function registerExporter(mixed $exporters): array
    {
        $exporters = is_array($exporters) ? $exporters : [];

        $exporters[self::KEY] = [
            'exporter_friendly_name' => __('Convermetry form submissions and analytics', 'convermetry'),
            'callback'               => [PersonalDataExporter::class, 'export'],
        ];

        return $exporters;
    }

    /**
     * @param mixed $erasers Registered erasers.
     * @return array<string, mixed>
     */
    public static function registerEraser(mixed $erasers): array
    {
        $erasers = is_array($erasers) ? $erasers : [];

        $erasers[self::KEY] = [
            'eraser_friendly_name' => __('Convermetry form submissions and analytics', 'convermetry'),
            'callback'             => [PersonalDataEraser::class, 'erase'],
        ];

        return $erasers;
    }
}
