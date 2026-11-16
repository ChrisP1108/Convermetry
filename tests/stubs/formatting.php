<?php

/**
 * WordPress formatting functions that plugin code calls in places the unit
 * suite exercises with no WordPress loaded.
 *
 * Each one does what its WordPress counterpart does (wp-includes/formatting.php),
 * not a simplification of it: a stand-in that differed would let a unit test
 * pass on behaviour no site has. Loaded from the bootstrap AFTER Composer's
 * autoloader, so Patchwork can still redefine any of them in a test.
 */

declare(strict_types=1);

if (!function_exists('wp_strip_all_tags')) {
    /**
     * Used by {@see \Convermetry\Support\UrlPattern::clean()}, which
     * UrlPatternTest calls directly.
     */
    function wp_strip_all_tags(mixed $text, bool $remove_breaks = false): string
    {
        if ($text === null || !is_scalar($text)) {
            return '';
        }

        $text = (string) preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text);
        $text = strip_tags($text);

        if ($remove_breaks) {
            $text = (string) preg_replace('/[\r\n\t ]+/', ' ', $text);
        }

        return trim($text);
    }
}
