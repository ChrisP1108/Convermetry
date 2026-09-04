<?php
declare(strict_types=1);

namespace Convermetry\Forms\Providers;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\FormProviderInterface;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\FormSettings;
use Convermetry\Forms\SubmissionService;
use Convermetry\Settings\Options;

/**
 * Elementor Pro form integration.
 *
 * Hooks elementor_pro/forms/new_record — Elementor Pro's server-side hook
 * that fires after a submission passed validation and was processed — and
 * feeds the record into the shared submission pipeline.
 *
 * Identity: per-form settings are keyed by the Elementor WIDGET ID, which is
 * stable across renames and unique per widget. They used to be keyed by form
 * NAME, which meant two widgets both left at the default "New Form" shared one
 * configuration, and renaming a form orphaned its settings. Sites upgrading
 * from the name-keyed layout keep working through
 * {@see FormSettings::resolveKey()}, which falls back to the legacy name key
 * until the next save migrates the entry across.
 *
 * Failure modes: in the default 'background' mode the visitor always sees
 * the normal success state and failed webhook deliveries retry in the
 * background. In 'show_error' mode delivery runs synchronously and a
 * failure is surfaced on the form via Elementor's AJAX handler — the one
 * provider whose API exposes that error channel.
 *
 * Form discovery walks _elementor_data post meta directly rather than using
 * WP_Query: get_posts() with post_type 'any' only searches PUBLIC post
 * types, silently excluding private ones such as elementor_library — where
 * template-based forms live.
 */
final class ElementorProvider implements FormProviderInterface
{
    public function getKey(): string
    {
        return 'elementor';
    }

    public function getLabel(): string
    {
        return 'Elementor Pro';
    }

    public function isAvailable(): bool
    {
        return defined('ELEMENTOR_PRO_VERSION') || class_exists('\ElementorPro\Plugin');
    }

    /**
     * Discovers every Elementor form widget on the site.
     *
     * Keyed by widget id so two widgets sharing a name stay distinct; the
     * form name is carried alongside for display and legacy-key fallback.
     *
     * @return array<int, array{native_id: string, name: string}>
     */
    public function getForms(): array
    {
        global $wpdb;

        $forms = [];

        /** @var string[] $postIds */
        $postIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s",
            '_elementor_data'
        ));

        foreach ((array) $postIds as $postId) {
            $rawData = get_post_meta((int) $postId, '_elementor_data', true);

            if (empty($rawData) || !is_string($rawData)) {
                continue;
            }

            $elements = json_decode($rawData, true);
            if (!is_array($elements)) {
                continue;
            }

            $this->extractForms($elements, $forms);
        }

        // Keyed by widget id while collecting, so the same widget found on
        // several posts (a template reused across pages) appears once.
        return array_values($forms);
    }

    public function registerHooks(SubmissionService $service): void
    {
        add_action(
            'elementor_pro/forms/new_record',
            function (mixed $record, mixed $handler) use ($service): void {
                $this->handleSubmission($record, $handler, $service);
            },
            10,
            2
        );
    }

    /**
     * Parses an Elementor form record and forwards it to the pipeline.
     *
     * @param mixed             $record  Elementor_Form_Record instance (typed mixed —
     *                                   Elementor Pro's classes are not loadable at parse time).
     * @param mixed             $handler Ajax_Handler instance used to surface errors in 'show_error' mode.
     * @param SubmissionService $service The shared pipeline.
     * @return void
     */
    private function handleSubmission(mixed $record, mixed $handler, SubmissionService $service): void
    {
        if (!is_object($record) || !method_exists($record, 'get_form_settings') || !method_exists($record, 'get')) {
            return;
        }

        $formName = (string) $record->get_form_settings('form_name');
        if ($formName === '') {
            return;
        }

        $widgetId = (string) $record->get_form_settings('id');

        // Current identity is the widget id. A site that has not re-saved its
        // form settings since upgrading still has them under the name key, so
        // resolve against what is actually stored before recording.
        $providerKey = $this->getKey();
        $identity    = $widgetId !== '' ? $widgetId : $formName;

        if ($widgetId !== '' && $widgetId !== $formName) {
            $legacyKey = FormProviderRegistry::legacyFormKey($providerKey, $formName);
            $resolved  = FormSettings::resolveKey(
                FormProviderRegistry::formKey($providerKey, $widgetId),
                $legacyKey
            );

            if ($resolved === $legacyKey && $legacyKey !== '') {
                $identity = $formName;
            }
        }

        $rawFields = $record->get('fields');
        $fields    = [];

        // Elementor's record entries look like
        //   ['id' => ['id' => …, 'type' => …, 'title' => …, 'value' => …], …]
        // where the outer key is the stable field id and 'title' is the label
        // the site owner typed. Keep both: the id is what automation matches
        // on, the title is the only thing that makes an Elementor lead
        // readable — its ids are opaque ('field_a1b2c3').
        if (is_array($rawFields)) {
            foreach ($rawFields as $id => $field) {
                $fields[] = [
                    'id'    => (string) $id,
                    'label' => is_array($field) ? (string) ($field['title'] ?? '') : '',
                    'value' => is_array($field) ? ($field['value'] ?? '') : $field,
                ];
            }
        }

        $sync = Options::formFailureMode() === 'show_error';

        $result = $service->record(
            provider: $providerKey,
            nativeId: $widgetId,
            formName: $formName,
            fields: $fields,
            sync: $sync,
            identity: $identity
        );

        // Only the synchronous mode surfaces failures to the visitor —
        // and only genuine dispatch failures, never "form is excluded"
        // (nothing was supposed to happen) or background-queue results.
        if (
            $sync
            && !$result->ok
            && $result->failedDeliveries !== []
            && is_object($handler)
            && method_exists($handler, 'add_error_message')
        ) {
            $handler->add_error_message('There was an issue submitting the form data through the webhook.');
            if (property_exists($handler, 'is_success')) {
                $handler->is_success = false;
            }
        }
    }

    /**
     * Recursively walks Elementor element trees collecting form widgets.
     *
     * @param array<int|string, mixed>                                $elements Element tree.
     * @param array<string, array{native_id: string, name: string}>   $forms    Collected forms, keyed by widget id (by reference).
     * @return void
     */
    private function extractForms(array $elements, array &$forms): void
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (
                ($element['widgetType'] ?? null) === 'form'
                && is_string($element['settings']['form_name'] ?? null)
                && $element['settings']['form_name'] !== ''
            ) {
                $name = $element['settings']['form_name'];

                // Elementor always assigns a widget id; fall back to the name
                // only for hand-edited or malformed element trees, which keeps
                // the form visible rather than dropping it from the admin list.
                $widgetId = is_string($element['id'] ?? null) && $element['id'] !== ''
                    ? $element['id']
                    : $name;

                $forms[$widgetId] = ['native_id' => $widgetId, 'name' => $name];
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $this->extractForms($element['elements'], $forms);
            }
        }
    }
}
