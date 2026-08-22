<?php
declare(strict_types=1);

namespace Convermetry\Forms\Providers;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\FormProviderInterface;
use Convermetry\Forms\SubmissionService;

/**
 * Gravity Forms integration.
 *
 * Uses only Gravity Forms' supported public APIs: GFAPI::get_forms() for
 * discovery and the documented gform_after_submission action — which fires
 * once the entry has been fully saved — for server-confirmed submissions.
 *
 * Identity: the numeric Gravity Forms form id (stable across renames).
 */
final class GravityFormsProvider implements FormProviderInterface
{
    public function getKey(): string
    {
        return 'gravityforms';
    }

    public function getLabel(): string
    {
        return 'Gravity Forms';
    }

    public function isAvailable(): bool
    {
        return class_exists('GFAPI');
    }

    /**
     * @return array<int, array{native_id: string, name: string}>
     */
    public function getForms(): array
    {
        $forms = \GFAPI::get_forms(true);
        if (!is_array($forms)) {
            return [];
        }

        $out = [];
        foreach ($forms as $form) {
            if (!is_array($form) || empty($form['id'])) {
                continue;
            }

            $out[] = [
                'native_id' => (string) $form['id'],
                'name'      => (string) ($form['title'] ?? ('Form ' . $form['id'])),
            ];
        }

        return $out;
    }

    public function registerHooks(SubmissionService $service): void
    {
        add_action(
            'gform_after_submission',
            function (mixed $entry, mixed $form) use ($service): void {
                $this->handleSubmission($entry, $form, $service);
            },
            10,
            2
        );
    }

    /**
     * Flattens a saved Gravity Forms entry into label → value pairs and
     * forwards it to the pipeline.
     *
     * @param mixed             $entry   The saved entry array.
     * @param mixed             $form    The form meta array.
     * @param SubmissionService $service The shared pipeline.
     * @return void
     */
    private function handleSubmission(mixed $entry, mixed $form, SubmissionService $service): void
    {
        if (!is_array($entry) || !is_array($form) || empty($form['id'])) {
            return;
        }

        $fields = [];

        foreach ((array) ($form['fields'] ?? []) as $field) {
            if (!is_object($field) || !isset($field->id)) {
                continue;
            }

            $label = trim((string) ($field->label ?? ''));
            if ($label === '') {
                $label = 'field_' . $field->id;
            }

            // Multi-input fields (name, address, checkboxes) store their
            // values under "id.sub" keys; collect them in order.
            if (!empty($field->inputs) && is_array($field->inputs)) {
                $parts = [];
                foreach ($field->inputs as $input) {
                    $inputId = (string) ($input['id'] ?? '');
                    $value   = trim((string) ($entry[$inputId] ?? ''));
                    if ($value !== '') {
                        $parts[] = $value;
                    }
                }
                $fields[$label] = implode(' ', $parts);
                continue;
            }

            $fields[$label] = (string) ($entry[(string) $field->id] ?? '');
        }

        $service->record(
            provider: $this->getKey(),
            nativeId: (string) $form['id'],
            formName: (string) ($form['title'] ?? ('Form ' . $form['id'])),
            fields: $fields
        );
    }
}
