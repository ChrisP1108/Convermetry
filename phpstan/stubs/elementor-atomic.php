<?php

/**
 * The one Elementor Pro symbol Convermetry INHERITS from.
 *
 * Every other third-party form-plugin symbol is declared in form-plugins.php;
 * this one needs a file of its own only because it is namespaced, and PHP will
 * not accept a namespace declaration after the global-scope classes in that
 * file.
 *
 * WHAT IS AND IS NOT VERIFIED. Elementor CORE is open source, and the Atomic
 * pieces Convermetry reads were checked against it: the form element's type
 * ('e-form'), its form-name prop and default, the actions-after-submit Chips
 * control, and the elementor/atomic-widgets/controls filter — which really is
 * applied as apply_filters('elementor/atomic-widgets/controls',
 * $this->define_atomic_controls(), $this). Elementor PRO is not published, so
 * Action_Base below follows its documented contract and the behaviour observed
 * in Elementor Pro 4.2.3; it is stubbed narrowly, to the four members the
 * subclass actually uses.
 *
 * Convermetry never hard-depends on any of it: the subclass is referenced only
 * after class_exists() confirms this parent exists, so a site without Elementor
 * Pro loads the plugin normally and registers hooks that simply never fire.
 *
 * @see \Convermetry\Forms\Atomic\ConvermetryAtomicAction
 * @see \Convermetry\Forms\Atomic\AtomicFormsBridge::registerAction()
 */

namespace ElementorPro\Modules\AtomicForm\Actions;

abstract class Action_Base
{
    /** @return string */
    abstract public function get_type(): string;

    /**
     * @param array<string, mixed> $form_data
     * @param array<string, mixed> $widget_settings
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    abstract public function execute(array $form_data, array $widget_settings, array $context): array;

    /**
     * Elementor Pro returns ['status' => 'success', 'message' => $message]
     * merged with $additional_data, which Convermetry never passes.
     *
     * @param array<string, mixed> $additional_data
     * @return array{status: string, message?: string, error?: string}
     */
    protected function success(string $message, array $additional_data = []) {}

    /**
     * Elementor Pro returns ['status' => 'failed', 'error' => $error] merged
     * with $additional_data, which Convermetry never passes.
     *
     * @param array<string, mixed> $additional_data
     * @return array{status: string, message?: string, error?: string}
     */
    protected function failure(string $error, array $additional_data = []) {}
}
