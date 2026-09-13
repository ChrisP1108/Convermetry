<?php
declare(strict_types=1);

namespace Convermetry\Forms\Atomic;

if (!defined('ABSPATH')) exit;

use ElementorPro\Modules\AtomicForm\Actions\Action_Base;

/**
 * The "Convermetry" entry in an Atomic form's "Actions after submit" list.
 *
 * Deliberately the smallest class in the integration: every decision — identity,
 * exclusions, field translation, recording, delivery, failure semantics — lives
 * in {@see AtomicFormsBridge} and, below it, in the shared submission pipeline.
 * All this class does is inherit from Elementor Pro, hand the two arrays over,
 * and translate one outcome back.
 *
 * That split is the point. This file is the only thing in Convermetry that
 * cannot be loaded, parsed, or tested without Elementor Pro's Atomic Form module
 * installed, so everything worth testing is kept out of it.
 *
 * NEVER reference this class without first checking
 * class_exists(AtomicFormsBridge::ACTION_BASE_CLASS): naming it triggers the
 * autoloader, and loading it without its parent present is a fatal error.
 * {@see AtomicFormsBridge::registerAction()} is the one caller, and it performs
 * exactly that check first.
 */
final class ConvermetryAtomicAction extends Action_Base
{
    /**
     * @param AtomicFormsBridge $bridge The integration this action delegates to.
     */
    public function __construct(private readonly AtomicFormsBridge $bridge)
    {
    }

    /**
     * The slug this action is saved under in the form's settings.
     *
     * @return string
     */
    public function get_type(): string
    {
        return AtomicFormsBridge::ACTION_SLUG;
    }

    /**
     * Records one Atomic submission through Convermetry.
     *
     * $widget_settings is unused on purpose: where a submission goes, which
     * fields are redacted, and whether it is delivered at all are Convermetry's
     * own settings, not per-widget Elementor fields. Elementor's native
     * 'webhook' action — which does read widget_settings['webhook_url'] — is
     * untouched and can run alongside this one on the same form.
     *
     * @param array<string, mixed> $form_data       Submitted values keyed by native field id.
     * @param array<string, mixed> $widget_settings Resolved Atomic form settings (unused).
     * @param array<string, mixed> $context         Elementor's Atomic context.
     * @return array{status: string, message?: string, error?: string} The runner's result shape.
     */
    public function execute(array $form_data, array $widget_settings, array $context): array
    {
        $outcome = $this->bridge->handleSubmission($form_data, $context);

        return $outcome['ok']
            ? $this->success($outcome['message'])
            : $this->failure($outcome['message']);
    }
}
