<?php
declare(strict_types=1);

namespace Convermetry\Forms\Providers;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\FormProviderInterface;
use Convermetry\Forms\SubmissionService;

/**
 * Ninja Forms integration.
 *
 * Uses only Ninja Forms' public APIs: the model factory returned by
 * Ninja_Forms()->form() for discovery and the documented
 * ninja_forms_after_submission action for server-confirmed submissions.
 *
 * That action fires once the whole action-processing loop has finished. A
 * submission that fails validation — or one an action deliberately halts —
 * responds and exits well before that point, so the hook only ever sees
 * submissions Ninja Forms itself accepted.
 *
 * Identity: the numeric Ninja Forms form id (stable across renames).
 *
 * Note: the action runs inside Ninja Forms' AJAX response, so this provider
 * must never emit output — in the default 'background' failure mode the
 * pipeline queues delivery and returns immediately, which keeps it silent.
 */
final class NinjaFormsProvider implements FormProviderInterface
{
    /**
     * Field types that never carry a submitted value — the submit button and
     * layout-only markup. Ninja Forms hands these to the hook alongside real
     * fields, so they are skipped rather than recorded as empty values.
     *
     * @var string[]
     */
    private const array NON_DATA_FIELD_TYPES = ['submit', 'html', 'hr'];

    public function getKey(): string
    {
        return 'ninjaforms';
    }

    public function getLabel(): string
    {
        return 'Ninja Forms';
    }

    public function isAvailable(): bool
    {
        return function_exists('Ninja_Forms');
    }

    /**
     * @return array<int, array{native_id: string, name: string}>
     */
    public function getForms(): array
    {
        $factory = \Ninja_Forms()->form();
        if (!is_object($factory) || !method_exists($factory, 'get_forms')) {
            return [];
        }

        $forms = $factory->get_forms();
        if (!is_array($forms)) {
            return [];
        }

        $out = [];
        foreach ($forms as $form) {
            if (!is_object($form) || !method_exists($form, 'get_id') || !method_exists($form, 'get_setting')) {
                continue;
            }

            $id = (string) $form->get_id();
            if ($id === '' || $id === '0') {
                continue;
            }

            $title = trim((string) $form->get_setting('title'));

            $out[] = [
                'native_id' => $id,
                'name'      => $title !== '' ? $title : ('Form ' . $id),
            ];
        }

        return $out;
    }

    public function registerHooks(SubmissionService $service): void
    {
        add_action(
            'ninja_forms_after_submission',
            function (mixed $formData) use ($service): void {
                $this->handleSubmission($formData, $service);
            }
        );
    }

    /**
     * Flattens a processed Ninja Forms submission into label → value pairs
     * and forwards it to the pipeline.
     *
     * @param mixed             $formData Ninja Forms' processed submission array
     *                                    (form_id, settings, fields, extra).
     * @param SubmissionService $service  The shared pipeline.
     * @return void
     */
    private function handleSubmission(mixed $formData, SubmissionService $service): void
    {
        if (!is_array($formData) || empty($formData['form_id'])) {
            return;
        }

        $settings = is_array($formData['settings'] ?? null) ? $formData['settings'] : [];

        // Previewing a form in the admin runs the full submission pipeline,
        // including this hook. Previews are not conversions.
        if (!empty($settings['is_preview'])) {
            return;
        }

        $formId = $this->normalizeFormId((string) $formData['form_id']);
        if ($formId === '') {
            return;
        }

        $fields = [];

        foreach ((array) ($formData['fields'] ?? []) as $id => $field) {
            if (!is_array($field)) {
                continue;
            }

            if (in_array((string) ($field['type'] ?? ''), self::NON_DATA_FIELD_TYPES, true)) {
                continue;
            }

            // Ninja Forms carries the richest per-field metadata of the
            // bundled providers: a numeric id, a stable developer 'key', and
            // the visitor-facing 'label'. Prefer the id for automation and
            // fall back to the key, which is still stable across renames.
            $fieldId = trim((string) ($field['id'] ?? ''));
            if ($fieldId === '') {
                $fieldId = trim((string) ($field['key'] ?? ''));
            }
            if ($fieldId === '') {
                $fieldId = (string) $id;
            }

            $label = trim((string) ($field['label'] ?? ''));
            if ($label === '') {
                $label = trim((string) ($field['key'] ?? ''));
            }

            $fields[] = ['id' => $fieldId, 'label' => $label, 'value' => $field['value'] ?? ''];
        }

        $title = trim((string) ($settings['title'] ?? ''));

        $service->record(
            provider: $this->getKey(),
            nativeId: $formId,
            formName: $title !== '' ? $title : ('Form ' . $formId),
            fields: $fields
        );
    }

    /**
     * Reduces a submitted form id to its stable numeric identity.
     *
     * When one form is rendered more than once on a page, Ninja Forms
     * submits it as "<form id>_<instance>". It normally resolves that back to
     * the bare id before this hook runs, but normalizing here keeps the
     * recorded identity stable regardless.
     *
     * @param string $formId The submitted form id.
     * @return string The numeric form id, or '' when it is not usable.
     */
    private function normalizeFormId(string $formId): string
    {
        if (str_contains($formId, '_')) {
            $formId = (string) strstr($formId, '_', true);
        }

        return ctype_digit($formId) ? $formId : '';
    }
}
