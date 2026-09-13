<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

/**
 * One card in the Home page's "Convermetry Status" grid.
 *
 * Two shapes share the class because they share the card:
 *
 *  - A STATE. $level is set, and $label is the word shown in the pill beside
 *    the glyph ("Active", "Attention Required", "Not Configured").
 *  - A MEASUREMENT. $level is null, and $label is the value itself ("3 pending",
 *    "4 minutes ago"), rendered as monospaced text rather than a coloured pill.
 *    A count is not a verdict, and dressing one up as a green pill would invent
 *    an opinion the plugin does not hold.
 *
 * Values are plain text in both cases; the view escapes them.
 */
final readonly class HomeStatusItem
{
    /**
     * @param string               $title       Card heading, e.g. "Webhook Delivery".
     * @param string               $label       Pill text, or the measured value.
     * @param string               $description One sentence saying what the card means.
     * @param HomeStatusLevel|null $level       Null for a measurement (see the class note).
     */
    public function __construct(
        public string $title,
        public string $label,
        public string $description,
        public ?HomeStatusLevel $level = null,
    ) {
    }

    /**
     * A state card.
     *
     * @param string          $title       Card heading.
     * @param HomeStatusLevel $level       The state.
     * @param string          $label       Pill text.
     * @param string          $description One sentence of explanation.
     * @return self
     */
    public static function state(
        string $title,
        HomeStatusLevel $level,
        string $label,
        string $description,
    ): self {
        return new self($title, $label, $description, $level);
    }

    /**
     * A measurement card.
     *
     * @param string $title       Card heading.
     * @param string $value       The measured value, already formatted.
     * @param string $description One sentence of explanation.
     * @return self
     */
    public static function measurement(string $title, string $value, string $description): self
    {
        return new self($title, $value, $description, null);
    }

    /**
     * Whether this item is a state (pill) rather than a measurement.
     *
     * @return bool
     */
    public function isState(): bool
    {
        return $this->level !== null;
    }
}
