<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

/**
 * Server-rendered chart markup shared by the Analytics, Forms and Goals screens.
 *
 * Presentation only. Every chart is drawn from rows a report has ALREADY
 * returned for the table on the same screen, so a chart never costs a query of
 * its own and can never disagree with the table it summarizes — that table
 * stays on the page as the exact record.
 *
 * No script and no library: a bar is a span sized by a custom property and the
 * doughnut is an SVG. Every value is printed as text beside its mark, and the
 * marks themselves are hidden from assistive technology, so nothing here
 * depends on colour, hover, or JavaScript. Styles live in
 * assets/css/admin-common.css under "Charts".
 */
final class Charts
{
    /** Categorical slots for the doughnut — validated as a set; see admin-common.css. */
    private const int CATEGORY_SLOTS = 4;

    /**
     * Ranked horizontal bars: one row per item, one bar per series.
     *
     * Several series are drawn side by side on ONE shared scale — grouped, never
     * stacked. The lead counts this is used for overlap (a won lead is also a
     * qualified one), so stacking them would invent a whole they do not form.
     *
     * @param string                                        $labelledBy Id of the visible heading that names the chart.
     * @param list<string>                                  $series     Series names, in legend order. One series draws no legend.
     * @param array<int, array{label: string, values: array<int, int>}> $rows       Items in display order; one value per series.
     * @return void
     */
    public static function bars(string $labelledBy, array $series, array $rows): void
    {
        $max = 0;
        foreach ($rows as $row) {
            foreach ($row['values'] as $value) {
                $max = max($max, $value);
            }
        }

        $grouped = count($series) > 1;

        ?>
        <figure class="cvmtry-bars<?php echo $grouped ? ' cvmtry-bars--grouped' : ''; ?>" aria-labelledby="<?php echo esc_attr($labelledBy); ?>">
        <?php
        if ($grouped) {
            self::legend($series, 'cvmtry-bars-s');
        }
        ?>
        <ol class="cvmtry-bars-list">
        <?php
        foreach ($rows as $row) {
            ?>
            <li class="cvmtry-bars-row"><span class="cvmtry-bars-label"><?php echo esc_html($row['label']); ?></span>
            <?php
            foreach ($row['values'] as $i => $value) {
                // A non-zero value always shows a sliver; zero draws nothing.
                $width = $max > 0 && $value > 0 ? max(1.0, round($value / $max * 100, 2)) : 0;
                ?>
                <span class="cvmtry-bars-line"><span class="cvmtry-bars-track" aria-hidden="true"><span class="cvmtry-bars-bar cvmtry-bars-s<?php echo esc_attr((string) ($i + 1)); ?>" style="--cvmtry-w:<?php echo esc_attr((string) $width); ?>%"></span></span><span class="cvmtry-bars-value"><span class="screen-reader-text"><?php echo esc_html($series[$i] ?? ''); ?> </span><?php echo esc_html(number_format_i18n($value)); ?></span></span>
                <?php
            }
            ?>
            </li>
            <?php
        }
        ?>
        </ol></figure>
        <?php
    }

    /**
     * A doughnut of shares, with a legend that prints each part's name, share
     * and count — the legend is the readable chart; the ring only illustrates it.
     *
     * Slots follow the ENTITY, not its rank: the caller passes a stable slot per
     * item, so a part keeps its colour when the order or the period changes.
     *
     * @param string                                                     $labelledBy Id of the visible heading that names the chart.
     * @param list<array{label: string, share: string, detail: string, value: int, slot: int}> $parts
     *        Parts in display order. share and detail are already formatted.
     * @return void
     */
    public static function doughnut(string $labelledBy, array $parts): void
    {
        $total = 0;
        foreach ($parts as $part) {
            $total += $part['value'];
        }

        // A circle of radius 100/(2π) has a circumference of exactly 100, so a
        // dash length is the part's percentage. A 1-unit gap in the surface
        // colour separates neighbours; a lone part is a closed ring.
        $visible = count(array_filter($parts, static fn(array $part): bool => $part['value'] > 0));
        $gap     = $visible > 1 ? 1.0 : 0.0;
        $offset  = 0.0;

        ?>
        <figure class="cvmtry-donut" aria-labelledby="<?php echo esc_attr($labelledBy); ?>">
        <svg class="cvmtry-donut-ring" viewBox="0 0 42 42" aria-hidden="true" focusable="false">
        <?php
        foreach ($parts as $part) {
            if ($total <= 0 || $part['value'] <= 0) {
                continue;
            }

            $length = $part['value'] / $total * 100;
            $dash   = max(0.0, $length - $gap);
            ?>
            <circle class="cvmtry-donut-seg cvmtry-cat-<?php echo esc_attr((string) self::slot($part['slot'])); ?>" cx="21" cy="21" r="15.9155" stroke-dasharray="<?php echo esc_attr(round($dash, 3) . ' ' . round(100 - $dash, 3)); ?>" stroke-dashoffset="<?php echo esc_attr((string) round(-$offset, 3)); ?>"></circle>
            <?php
            $offset += $length;
        }
        ?>
        </svg>
        <ul class="cvmtry-chart-legend cvmtry-donut-legend">
        <?php
        foreach ($parts as $part) {
            ?>
            <li><span class="cvmtry-chart-swatch cvmtry-cat-<?php echo esc_attr((string) self::slot($part['slot'])); ?>" aria-hidden="true"></span><span class="cvmtry-donut-label"><?php echo esc_html($part['label']); ?></span><span class="cvmtry-donut-share"><?php echo esc_html($part['share']); ?></span><span class="cvmtry-donut-detail"><?php echo esc_html($part['detail']); ?></span></li>
            <?php
        }
        ?>
        </ul></figure>
        <?php
    }

    /**
     * A legend of series names with their swatches.
     *
     * @param list<string> $series      Series names.
     * @param string       $classPrefix Swatch class prefix; the slot number is appended.
     * @return void
     */
    private static function legend(array $series, string $classPrefix): void
    {
        ?>
        <ul class="cvmtry-chart-legend">
        <?php
        foreach ($series as $i => $name) {
            ?>
            <li><span class="cvmtry-chart-swatch <?php echo esc_attr($classPrefix . ($i + 1)); ?>" aria-hidden="true"></span><?php echo esc_html($name); ?></li>
            <?php
        }
        ?>
        </ul>
        <?php
    }

    /**
     * Clamps a requested categorical slot into the validated set.
     *
     * @param int $slot 1-based slot.
     * @return int
     */
    private static function slot(int $slot): int
    {
        return max(1, min(self::CATEGORY_SLOTS, $slot));
    }
}
