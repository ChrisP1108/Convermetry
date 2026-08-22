<?php
declare(strict_types=1);

namespace Convermetry\Forms\Providers;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\FormProviderInterface;
use Convermetry\Forms\SubmissionService;
use Convermetry\Settings\Options;

/**
 * Elementor Pro form integration.
 *
 * Hooks elementor_pro/forms/new_record — Elementor Pro's server-side hook
 * that fires after a submission passed validation and was processed — and
 * feeds the record into the shared submission pipeline.
 *
 * Identity: per-form settings are keyed by the Elementor widget id, which also
 * travels in payloads as native_form_id, so two widgets sharing a name hold
 * independent configuration. Settings saved under the older name-based key (the
 * identity the legacy Forms Webhook Integrator used) are still honoured as a
 * fallback until an administrator re-saves the form; see
 * {@see \Convermetry\Forms\FormSettings::resolveKey()}.
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
     * Discovers every Elementor form widget on the site, identified by widget id.
     *
     * Each entry carries the widget id as native_id (the identity settings are
     * keyed by, and the one that ships in payloads as native_form_id), the form
     * name for display, and legacy_id — the name this widget's settings used to
     * be keyed by, so the Forms page can fall back to an existing configuration
     * that predates the switch.
     *
     * Widget ids are unique within an Elementor document but not guaranteed
     * unique across posts, since duplicating a page can copy element ids
     * verbatim. Any id seen in more than one post is reported via shared_id so
     * the admin can be warned rather than two distinct widgets silently sharing
     * one configuration.
     *
     * @return array<int, array{native_id: string, name: string, legacy_id: string, post_id: int, shared_id: bool}>
     */
    public function getForms(): array
    {
        global $wpdb;

        $widgets = [];

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

            $this->extractFormWidgets($elements, (int) $postId, $widgets);
        }

        // A widget id appearing under more than one post is a duplication
        // artefact, not two references to the same form.
        $out = [];
        foreach ($widgets as $widget) {
            $widget['shared_id'] = count($widget['post_ids']) > 1;
            unset($widget['post_ids']);
            $out[] = $widget;
        }

        return $out;
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

        $rawFields = $record->get('fields');
        $fields    = [];

        if (is_array($rawFields)) {
            foreach ($rawFields as $id => $field) {
                $fields[(string) $id] = is_array($field) ? ($field['value'] ?? '') : $field;
            }
        }

        $sync = Options::formFailureMode() === 'show_error';

        $result = $service->record(
            provider: $this->getKey(),
            nativeId: $widgetId,
            formName: $formName,
            fields: $fields,
            sync: $sync,
            // Settings are keyed by widget id, falling back to the form name
            // for configurations saved before that switch. A widget with no id
            // keeps the name as its identity, matching discovery.
            identity: $widgetId !== '' ? $widgetId : $formName,
            legacyIdentity: $formName
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
     * Recursively walks Elementor element trees to find form widget names.
     *
     * A widget is keyed by its Elementor element id, falling back to the form
     * name when an element carries no id — an id-less widget cannot be told
     * apart from another of the same name, which is exactly the pre-migration
     * behaviour and the best available for that element.
     *
     * @param array<int|string, mixed>                                                                    $elements Element tree.
     * @param int                                                                                         $postId   Post the tree belongs to.
     * @param array<string, array{native_id: string, name: string, legacy_id: string, post_id: int, shared_id: bool}> $widgets  Collected widgets (by reference).
     * @return void
     */
    private function extractFormWidgets(array $elements, int $postId, array &$widgets): void
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
                $name     = (string) $element['settings']['form_name'];
                $widgetId = is_scalar($element['id'] ?? null) ? (string) $element['id'] : '';
                $nativeId = $widgetId !== '' ? $widgetId : $name;

                if (isset($widgets[$nativeId])) {
                    // Same id in another post: record the extra post so the
                    // duplicate can be reported, and keep the first name.
                    $widgets[$nativeId]['post_ids'][$postId] = true;
                } else {
                    $widgets[$nativeId] = [
                        'native_id' => $nativeId,
                        'name'      => $name,
                        'legacy_id' => $name,
                        'post_id'   => $postId,
                        'post_ids'  => [$postId => true],
                        'shared_id' => false,
                    ];
                }
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                $this->extractFormWidgets($element['elements'], $postId, $widgets);
            }
        }
    }
}
