<?php

/**
 * Pass-through stand-ins for WordPress's translation functions.
 *
 * The unit suite has no WordPress, and every user-facing string in the plugin
 * now goes through __(), _n() and friends. These return the untranslated text,
 * which is exactly what WordPress returns on a site with no translation loaded
 * for the 'convermetry' domain, so a test asserting on an English string keeps
 * asserting on what an English site shows.
 *
 * The escaping variants escape the way their WordPress counterparts do. Loaded
 * from the bootstrap AFTER Composer's autoloader, so Patchwork can still
 * redefine any of them in a test that needs a real translation.
 */

declare(strict_types=1);

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('_x')) {
    function _x(string $text, string $context, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('_nx')) {
    function _nx(string $single, string $plural, int $number, string $context, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('_e')) {
    function _e(string $text, string $domain = 'default'): void
    {
        echo $text;
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('esc_html_x')) {
    function esc_html_x(string $text, string $context, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('esc_attr_x')) {
    function esc_attr_x(string $text, string $context, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void
    {
        echo htmlspecialchars($text, ENT_QUOTES, 'UTF-8', false);
    }
}

if (!function_exists('wp_set_script_translations')) {
    /**
     * Registers a script's translation domain. Nothing to load without
     * WordPress, so it only reports success, as WordPress does for a script
     * with no translation file available.
     */
    function wp_set_script_translations(string $handle, string $domain = 'default', string $path = ''): bool
    {
        return true;
    }
}
