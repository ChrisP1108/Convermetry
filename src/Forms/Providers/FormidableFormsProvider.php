<?php
declare(strict_types=1);

namespace Convermetry\Forms\Providers;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\FormProviderInterface;
use Convermetry\Forms\SubmissionService;

/**
 * Formidable Forms integration.
 *
 * Uses only Formidable's public model classes: FrmForm::get_published_forms()
 * for discovery and the documented frm_after_create_entry action — fired once
 * the entry row exists — for server-confirmed submissions.
 *
 * Two Formidable behaviours the hook alone does not account for, and which
 * this provider filters out, are worth knowing about:
 *
 *  - Repeater and embedded-form rows fire frm_after_create_entry for each
 *    CHILD entry as well as the parent, so one visitor submission would
 *    otherwise be recorded several times. The hook's third argument carries
 *    an 'is_child' flag that identifies them.
 *  - Saving a form as a draft also creates an entry. A draft is not a
 *    completed submission, so entries flagged is_draft are skipped.
 *
 * Hooks are registered at priority 30 because Formidable runs its own form
 * actions (notification emails, post creation) at priority 20 — by 30 the
 * entry and its metas are fully settled.
 *
 * Identity: the numeric Formidable form id (stable across renames).
 */
final class FormidableFormsProvider implements FormProviderInterface
{
    public function getKey(): string
    {
        return 'formidable';
    }

    public function getLabel(): string
    {
        return 'Formidable Forms';
    }

    public function isAvailable(): bool
    {
        return class_exists('FrmForm') && class_exists('FrmEntry') && class_exists('FrmField');
    }

    /**
     * @return array<int, array{native_id: string, name: string}>
     */
    public function getForms(): array
    {
        // Excludes templates, trashed forms, and the child forms behind
        // repeaters and embedded forms — none of which a visitor submits.
        $forms = \FrmForm::get_published_forms();
        if (!is_array($forms)) {
            return [];
        }

        $out = [];
        foreach ($forms as $form) {
            if (!is_object($form) || empty($form->id)) {
                continue;
            }

            $name = trim((string) ($form->name ?? ''));

            $out[] = [
                'native_id' => (string) $form->id,
                'name'      => $name !== '' ? $name : ('Form ' . $form->id),
            ];
        }

        return $out;
    }

    public function registerHooks(SubmissionService $service): void
    {
        add_action(
            'frm_after_create_entry',
            function (mixed $entryId, mixed $formId, mixed $args = []) use ($service): void {
                $this->handleSubmission($entryId, $formId, $args, $service);
            },
            30,
            3
        );
    }

    /**
     * Reads a newly created Formidable entry, flattens it into label → value
     * pairs and forwards it to the pipeline.
     *
     * @param mixed             $entryId The new entry's id.
     * @param mixed             $formId  The submitted form's id.
     * @param mixed             $args    Formidable's context array; carries 'is_child'.
     * @param SubmissionService $service The shared pipeline.
     * @return void
     */
    private function handleSubmission(mixed $entryId, mixed $formId, mixed $args, SubmissionService $service): void
    {
        // A repeater row or embedded-form entry, not the visitor's submission.
        if (is_array($args) && !empty($args['is_child'])) {
            return;
        }

        $entryId = (int) $entryId;
        $formId  = (int) $formId;

        if ($entryId <= 0 || $formId <= 0) {
            return;
        }

        // The second argument loads the entry's field values into ->metas.
        $entry = \FrmEntry::getOne($entryId, true);
        if (!is_object($entry)) {
            return;
        }

        // A saved draft creates an entry but is not a completed submission;
        // the real submission fires this hook again on final submit.
        if (!empty($entry->is_draft)) {
            return;
        }

        $metas  = is_array($entry->metas ?? null) ? $entry->metas : [];
        $fields = [];

        foreach ((array) \FrmField::get_all_for_form($formId) as $field) {
            if (!is_object($field) || empty($field->id)) {
                continue;
            }

            // Dividers, page breaks, HTML blocks, captchas and the submit
            // button store no value — Formidable lists them itself.
            if (\FrmField::is_no_save_field((string) ($field->type ?? ''))) {
                continue;
            }

            // Formidable's field id is numeric and stable; 'name' is the
            // visitor-facing label and 'field_key' the developer handle.
            $label = trim((string) ($field->name ?? ''));
            if ($label === '') {
                $label = trim((string) ($field->field_key ?? ''));
            }

            $fields[] = [
                'id'    => (string) $field->id,
                'label' => $label,
                'value' => $metas[$field->id] ?? '',
            ];
        }

        $formName = trim((string) ($entry->form_name ?? ''));

        $service->record(
            provider: $this->getKey(),
            nativeId: (string) $formId,
            formName: $formName !== '' ? $formName : ('Form ' . $formId),
            fields: $fields
        );
    }
}
