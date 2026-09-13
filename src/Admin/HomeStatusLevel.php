<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

/**
 * How a Convermetry status reads at a glance.
 *
 * Four levels, because the Home page has to distinguish four genuinely
 * different things and a boolean would collapse two of them into a lie:
 *
 *  - Success  — working, nothing to do.
 *  - Warning  — configured, but something needs attention.
 *  - Error    — a subsystem is not doing its job.
 *  - Neutral  — nothing is wrong and nothing has happened yet. A site with no
 *               webhooks configured is not failing; a site that has recorded
 *               no submissions yet is not broken. Rendering either as a red
 *               or green state would misinform the site owner on their first
 *               visit, which is exactly who this page is for.
 *
 * Each level carries its own glyph as well as its own colour, and every place
 * one is rendered also prints a word ("Active", "Not Configured"), so no state
 * is ever communicated by colour alone.
 */
enum HomeStatusLevel: string
{
    case Success = 'success';
    case Warning = 'warning';
    case Error   = 'error';
    case Neutral = 'neutral';

    /**
     * The design-system modifier class for this level's pill.
     *
     * @return string
     */
    public function pillClass(): string
    {
        return 'cvm-ui-status cvm-ui-status--' . $this->value;
    }

    /**
     * The {@see Icons} catalogue name for this level's glyph.
     *
     * @return string
     */
    public function icon(): string
    {
        return match ($this) {
            self::Success => 'check',
            self::Warning => 'warning',
            self::Error   => 'error',
            self::Neutral => 'circle',
        };
    }

    /**
     * Whether a card in this state should also take a coloured edge.
     *
     * Reserved for the states a site owner must not scroll past; success and
     * neutral cards keep the default border so an attention state is the only
     * thing that stands out in the grid.
     *
     * @return string The card modifier class, or '' for none.
     */
    public function cardClass(): string
    {
        return match ($this) {
            self::Warning => ' cvm-ui-card--warning',
            self::Error   => ' cvm-ui-card--error',
            default       => '',
        };
    }
}
