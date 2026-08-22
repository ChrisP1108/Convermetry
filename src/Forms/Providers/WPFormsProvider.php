<?php
declare(strict_types=1);

namespace Convermetry\Forms\Providers;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\FormProviderInterface;
use Convermetry\Forms\SubmissionService;

/**
 * WPForms integration (WPForms Lite and Pro).
 *
 * Uses the documented wpforms_process_complete action — fired after a
 * submission passed processing — with the processed field set WPForms hands
 * the hook. Discovery lists the 'wpforms' post type, the storage WPForms
 * itself documents for forms.
 *
 * Identity: the WPForms form id (the form post's ID, stable across renames).
 */
final class WPFormsProvider implements FormProviderInterface
{
    public function getKey(): string
    {
        return 'wpforms';
    }

    public function getLabel(): string
    {
        return 'WPForms';
    }

    public function isAvailable(): bool
    {
        return function_exists('wpforms');
    }

    /**
     * @return array<int, array{native_id: string, name: string}>
     */
    public function getForms(): array
    {
        $posts = get_posts([
            'post_type'      => 'wpforms',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $out = [];
        foreach ($posts as $post) {
            $out[] = [
                'native_id' => (string) $post->ID,
                'name'      => $post->post_title !== '' ? $post->post_title : ('Form ' . $post->ID),
            ];
        }

        return $out;
    }

    public function registerHooks(SubmissionService $service): void
    {
        add_action(
            'wpforms_process_complete',
            function (mixed $fields, mixed $entry, mixed $formData) use ($service): void {
                $this->handleSubmission($fields, $formData, $service);
            },
            10,
            3
        );
    }

    /**
     * Flattens WPForms' processed field set into name → value pairs and
     * forwards it to the pipeline.
     *
     * @param mixed             $fields   Processed fields (id → {name, value, ...}).
     * @param mixed             $formData Form settings/meta.
     * @param SubmissionService $service  The shared pipeline.
     * @return void
     */
    private function handleSubmission(mixed $fields, mixed $formData, SubmissionService $service): void
    {
        if (!is_array($fields) || !is_array($formData) || empty($formData['id'])) {
            return;
        }

        $flat = [];
        foreach ($fields as $id => $field) {
            if (!is_array($field)) {
                continue;
            }

            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '') {
                $name = 'field_' . $id;
            }

            $flat[$name] = $field['value'] ?? '';
        }

        $service->record(
            provider: $this->getKey(),
            nativeId: (string) $formData['id'],
            formName: (string) ($formData['settings']['form_title'] ?? ('Form ' . $formData['id'])),
            fields: $flat
        );
    }
}
