<?php
declare(strict_types=1);

namespace Convermetry\Admin;

if (!defined('ABSPATH')) exit;

/**
 * One step of the Home page's Getting Started sequence.
 *
 * $complete is never a guess. Each step is marked done only when the plugin can
 * observe the thing the step describes having happened — settings actually
 * saved, a form provider actually active, an event actually recorded, a
 * submission actually captured. Anything the plugin cannot observe is left
 * incomplete rather than assumed, because a setup checklist that ticks itself
 * off optimistically tells a site owner their tracking works when it does not.
 *
 * $current marks the first incomplete step, and only that one, so the page can
 * point at a single next action instead of four competing ones.
 */
final readonly class HomeSetupStep
{
    /**
     * @param string $title       Step heading, e.g. "Verify Tracking".
     * @param string $description What the site owner is being asked to do.
     * @param string $actionLabel Link text, e.g. "Open Settings".
     * @param string $actionUrl   Admin URL, or '' when the current user lacks
     *                            the capability for that screen — the step
     *                            still renders, without a dead link.
     * @param bool   $complete    Whether the plugin observed this step done.
     * @param bool   $current     Whether this is the first incomplete step.
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $actionLabel,
        public string $actionUrl,
        public bool $complete,
        public bool $current = false,
    ) {
    }

    /**
     * A copy of this step marked as the next one to do.
     *
     * @return self
     */
    public function asCurrent(): self
    {
        return new self(
            $this->title,
            $this->description,
            $this->actionLabel,
            $this->actionUrl,
            $this->complete,
            true
        );
    }
}
