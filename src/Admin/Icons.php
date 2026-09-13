<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

/**
 * The inline SVG icons Convermetry's admin screens draw with.
 *
 * One small catalogue rather than an icon framework: the plugin needs about a
 * dozen glyphs, and pulling in a library to get them would add a dependency,
 * a build step, and several hundred unused paths to every admin page load.
 * Dashicons cover some of these, but not the two-tone chart/attribution marks
 * the design uses, and mixing the two would look accidental.
 *
 * Two rules keep the icons honest:
 *
 *  - EVERY icon is decorative. Each carries aria-hidden="true" and
 *    focusable="false", because in every place Convermetry uses one there is a
 *    visible text label beside it. Nothing on a Convermetry screen is
 *    communicated by an icon alone, so none of them needs an accessible name.
 *
 *  - COLOUR COMES FROM CSS, not from the markup. Primary strokes are
 *    currentColor and secondary strokes carry a class, so an icon takes its
 *    colours from the design tokens on its container
 *    (assets/css/admin-ui.css) and re-theming the palette never means editing
 *    PHP.
 *
 * The markup is a fixed internal catalogue with no interpolated input, which
 * is why {@see render()} may echo it directly.
 */
final class Icons
{
    /**
     * name => [viewBox size, path markup].
     *
     * @var array<string, array{0: int, 1: string}>
     */
    private const array CATALOGUE = [
        // The Convermetry mark: a rising line with two highlighted points.
        'logo' => [20,
            '<path d="M3 15.5 L8 10.5 L12 13 L17 5.5" stroke="currentColor" stroke-width="1.8" '
            . 'stroke-linecap="round" stroke-linejoin="round"/>'
            . '<circle cx="8" cy="10.5" r="2" class="cvm-ui-icon__mark"/>'
            . '<circle cx="17" cy="5.5" r="2" class="cvm-ui-icon__mark"/>',
        ],

        // Analytics.
        'chart' => [20,
            '<path d="M3 14.5 L7.5 9 L11 11.5 L17 4" stroke="currentColor" stroke-width="1.7" '
            . 'stroke-linecap="round" stroke-linejoin="round"/>'
            . '<path d="M3 17.5h14" class="cvm-ui-icon__muted" stroke-width="1.4" stroke-linecap="round"/>',
        ],
        'chart-sm' => [16,
            '<path d="M2.5 11.5 6 7.5 8.8 9.8 13.5 4.5" stroke="currentColor" stroke-width="1.6" '
            . 'stroke-linecap="round" stroke-linejoin="round"/>',
        ],

        // Submissions / leads.
        'document' => [20,
            '<rect x="3.5" y="2.5" width="13" height="15" rx="2.5" stroke="currentColor" stroke-width="1.6"/>'
            . '<path d="M7 7.5h6M7 10.5h6M7 13.5h3" class="cvm-ui-icon__muted" stroke-width="1.5" '
            . 'stroke-linecap="round"/>',
        ],
        'document-sm' => [16,
            '<rect x="3" y="2.5" width="10" height="11" rx="2" stroke="currentColor" stroke-width="1.5"/>'
            . '<path d="M5.8 6.5h4.4M5.8 9.5h2.6" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>',
        ],

        // Webhook delivery.
        'share' => [20,
            '<circle cx="5" cy="5" r="2.2" stroke="currentColor" stroke-width="1.6"/>'
            . '<circle cx="15" cy="15" r="2.2" stroke="currentColor" stroke-width="1.6"/>'
            . '<circle cx="15" cy="5" r="2.2" stroke="currentColor" stroke-width="1.6"/>'
            . '<path d="M7.2 5h5.6M5 7.2V13a2 2 0 0 0 2 2h5.8" class="cvm-ui-icon__muted" stroke-width="1.5" '
            . 'stroke-linecap="round"/>',
        ],

        // Developer hooks and filters.
        'code' => [20,
            '<path d="M7.5 5 4 10l3.5 5M12.5 5 16 10l-3.5 5" stroke="currentColor" stroke-width="1.7" '
            . 'stroke-linecap="round" stroke-linejoin="round"/>'
            . '<circle cx="10" cy="10" r="1.4" class="cvm-ui-icon__muted-fill"/>',
        ],

        // Activity log.
        'list-sm' => [16,
            '<path d="M3 4h10M3 8h10M3 12h6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
        ],

        // Settings.
        'gear-sm' => [16,
            '<circle cx="8" cy="8" r="2.4" stroke="currentColor" stroke-width="1.5"/>'
            . '<path d="M8 2v1.6M8 12.4V14M2 8h1.6M12.4 8H14" stroke="currentColor" stroke-width="1.5" '
            . 'stroke-linecap="round"/>',
        ],

        // About / documentation.
        'info-sm' => [16,
            '<circle cx="8" cy="8" r="5.5" stroke="currentColor" stroke-width="1.5"/>'
            . '<path d="M8 7.2v3.4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>'
            . '<circle cx="8" cy="5.2" r="0.9" fill="currentColor"/>',
        ],

        // Data control.
        'shield' => [18,
            '<path d="M9 2.2 14.5 4.3v4.2c0 3.4-2.3 6.2-5.5 7.3-3.2-1.1-5.5-3.9-5.5-7.3V4.3L9 2.2Z" '
            . 'stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>'
            . '<path d="M6.6 9.1 8.3 10.8 11.6 7.4" stroke="currentColor" stroke-width="1.6" '
            . 'stroke-linecap="round" stroke-linejoin="round"/>',
        ],

        // Status glyphs. Each sits beside a word, never instead of one.
        'check' => [12,
            '<path d="M2.5 6.3 4.8 8.6 9.5 3.9" stroke="currentColor" stroke-width="1.8" '
            . 'stroke-linecap="round" stroke-linejoin="round"/>',
        ],
        'warning' => [12,
            '<path d="M6 2.3 11 10H1L6 2.3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>'
            . '<path d="M6 5.4v1.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
        ],
        'error' => [12,
            '<circle cx="6" cy="6" r="4.2" stroke="currentColor" stroke-width="1.5"/>'
            . '<path d="M4.5 4.5 7.5 7.5M7.5 4.5 4.5 7.5" stroke="currentColor" stroke-width="1.5" '
            . 'stroke-linecap="round"/>',
        ],
        'circle' => [12,
            '<circle cx="6" cy="6" r="4" stroke="currentColor" stroke-width="1.6"/>',
        ],
        'dot' => [12,
            '<circle cx="6" cy="6" r="4" fill="currentColor"/>',
        ],

        // Scrolls the page down to Getting Started.
        'arrow-down' => [14,
            '<path d="M7 2.5v9M3 8l4 4 4-4" stroke="currentColor" stroke-width="1.7" '
            . 'stroke-linecap="round" stroke-linejoin="round"/>',
        ],
    ];

    /**
     * One icon's markup, or '' when the name is not in the catalogue.
     *
     * @param string $name One of {@see CATALOGUE}'s keys.
     * @param int    $size Rendered width/height in pixels. The viewBox is the
     *                     icon's own, so any size scales cleanly.
     * @return string SVG markup, safe to echo — no caller input reaches it.
     */
    public static function svg(string $name, int $size): string
    {
        $icon = self::CATALOGUE[$name] ?? null;
        if ($icon === null) {
            return '';
        }

        [$box, $paths] = $icon;

        return sprintf(
            '<svg width="%1$d" height="%1$d" viewBox="0 0 %2$d %2$d" fill="none" aria-hidden="true" '
            . 'focusable="false">%3$s</svg>',
            max(1, $size),
            $box,
            $paths
        );
    }

    /**
     * Echoes one icon.
     *
     * @param string $name One of {@see CATALOGUE}'s keys.
     * @param int    $size Rendered width/height in pixels.
     * @return void
     */
    public static function render(string $name, int $size): void
    {
        // A fixed catalogue of author-written markup with nothing interpolated
        // into it; escaping it would print the SVG source instead of drawing it.
        echo self::svg($name, $size); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Whether a name exists in the catalogue.
     *
     * @param string $name Icon name.
     * @return bool
     */
    public static function has(string $name): bool
    {
        return isset(self::CATALOGUE[$name]);
    }

    /**
     * The top-level "Convermetry" WordPress admin menu icon: the same line-
     * and-two-dots mark as the {@see CATALOGUE}'s 'logo' entry, as a
     * self-contained `data:image/svg+xml;base64,` URI for
     * {@see \add_menu_page()}'s `$icon_url` parameter.
     *
     * It cannot simply reuse the catalogue entry. `add_menu_page()` renders a
     * base64 SVG as a plain CSS `background-image` on the menu row — outside
     * this plugin's own CSS cascade entirely — so `stroke="currentColor"` and
     * the `cvm-ui-icon__mark` class the catalogue entry relies on would
     * resolve to nothing (black) rather than to this plugin's tokens. Every
     * colour here is hard-coded instead, and all of it is one flat grey,
     * `#a7aaad` — wp-admin's own resting menu-icon colour (see
     * #adminmenu div.wp-menu-image:before in wp-admin/css/admin-menu.css) —
     * rather than the logo's own two-tone mint accent: WordPress does not
     * recolour an SVG menu icon for hover or "current page" the way it does a
     * Dashicons font glyph, so unlike every other icon in this class this
     * one's colours are final, and a flat grey is the safe choice that
     * matches every core Dashicon beside it at every state.
     *
     * @return string A `data:image/svg+xml;base64,...` URI.
     */
    public static function adminMenuIcon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="none">'
            . '<path d="M3 15.5 L8 10.5 L12 13 L17 5.5" stroke="#a7aaad" stroke-width="1.8" '
            . 'stroke-linecap="round" stroke-linejoin="round"/>'
            . '<circle cx="8" cy="10.5" r="2" fill="#a7aaad"/>'
            . '<circle cx="17" cy="5.5" r="2" fill="#a7aaad"/>'
            . '</svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
