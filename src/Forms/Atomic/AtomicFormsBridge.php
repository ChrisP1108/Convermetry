<?php
declare(strict_types=1);

namespace Convermetry\Forms\Atomic;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\SubmissionResult;
use Convermetry\Forms\SubmissionService;
use Convermetry\Settings\Options;
use Convermetry\Support\Url;

/**
 * The Elementor Pro Atomic Forms integration, minus the one class that has to
 * inherit from Elementor.
 *
 * Atomic forms do not fire elementor_pro/forms/new_record. They run an ordered
 * list of "Actions after submit" through Elementor Pro's Action_Runner, so
 * capture is OPT-IN PER FORM: the site owner adds "Convermetry" to that list in
 * the editor, and until they do, Elementor never calls us. Nothing here can
 * change that — Convermetry does not edit saved Elementor documents — so the
 * Forms screen says so in as many words.
 *
 * This class owns three things:
 *
 *  1. REGISTRATION. It registers {@see ConvermetryAtomicAction} with the
 *     Action_Runner, adds a matching "Convermetry" choice to the Atomic form
 *     root's actions control in the editor, and names the action in Elementor's
 *     submission log. Elementor's own 'webhook' action, and any other plugin's
 *     action, are left untouched: this appends a choice, never replaces a list.
 *
 *  2. IDENTITY. Atomic element ids are only unique WITHIN a document, and the
 *     same template rendered on two pages keeps its ids, so identity is scoped
 *     as "<document id>:<element id>" — the same pair discovery reads out of
 *     _elementor_data and the same pair Elementor posts back at submit time
 *     (its frontend derives post_id from the nearest [data-elementor-id]
 *     ancestor, which for a library template IS that template's document). The
 *     display NAME is never part of the identity: two forms may share one, and
 *     renaming one must not orphan its settings.
 *
 *  3. TRANSLATION. Atomic's ($form_data, $context) pair becomes Convermetry's
 *     {id, label, value} descriptors and one {@see SubmissionService::record()}
 *     call, so Atomic submissions get the same exclusions, redaction, storage,
 *     conversion recording, notifications, delivery and deduplication as every
 *     other provider. No payload, queue, retry or settings code is duplicated.
 *
 * Every Elementor symbol is guarded before it is touched, and the one class
 * that extends an Elementor base is referenced only after its parent is known
 * to exist — so this file loads, and these hooks register, on a site with no
 * Elementor at all.
 *
 * The decision helpers ({@see buildFields()}, {@see nativeId()},
 * {@see outcomeFor()}) are static and take plain values, so what this
 * integration actually decides is testable without Elementor Pro installed.
 */
final class AtomicFormsBridge
{
    /**
     * Provider key for Atomic forms.
     *
     * Deliberately separate from the classic 'elementor' provider: the two have
     * different identities (widget id vs document-scoped element id), different
     * capture mechanics (automatic vs opt-in per form), and must never share a
     * per-form settings entry.
     */
    public const string PROVIDER_KEY = 'elementor_atomic';

    /**
     * Action slug persisted in the Atomic form's actions-after-submit list.
     *
     * Deliberately distinct from Elementor's own 'webhook' action and from any
     * other plugin's slug, so several can be selected on one form and none
     * overwrites another.
     */
    public const string ACTION_SLUG = 'convermetry';

    /** Label shown in the editor control and in Elementor's action log. */
    public const string ACTION_LABEL = 'Convermetry';

    /** elType of the Atomic form ROOT element, in _elementor_data and in the DOM. */
    public const string FORM_ELEMENT_TYPE = 'e-form';

    /** The Atomic form prop holding the actions-after-submit list. */
    private const string ACTIONS_PROP = 'actions-after-submit';

    /** The Atomic form prop holding the display name. */
    public const string FORM_NAME_PROP = 'form-name';

    /**
     * Elementor Pro's Atomic action base class. {@see ConvermetryAtomicAction}
     * extends it, so it must exist before that class is referenced at all.
     */
    public const string ACTION_BASE_CLASS = 'ElementorPro\\Modules\\AtomicForm\\Actions\\Action_Base';

    /** Elementor core's Atomic form element class, queried for its own type and default name. */
    public const string FORM_ELEMENT_CLASS = 'Elementor\\Modules\\AtomicWidgets\\Elements\\Atomic_Form\\Atomic_Form';

    /** Elementor Pro's Atomic action runner, used only for the late-registration fallback. */
    private const string ACTION_RUNNER_CLASS = 'ElementorPro\\Modules\\AtomicForm\\Actions\\Action_Runner';

    /** The hook Elementor Pro fires to collect Atomic actions. */
    public const string REGISTER_HOOK = 'elementor_pro/atomic_forms/actions/register';

    /** Fallback for Elementor's own form-name prop default. */
    private const string FORM_NAME_DEFAULT = 'Form';

    /** Guards against wiring the same callbacks twice. */
    private bool $registered = false;

    /**
     * @param SubmissionService $service The shared pipeline every confirmed
     *                                   submission flows through.
     */
    public function __construct(private readonly SubmissionService $service)
    {
    }

    // ------------------------------------------------------------- registration

    /**
     * Wires the action registration, the editor choice, and the log label.
     *
     * Deliberately NOT gated on whether webhooks are configured or active. A
     * form that has already saved this action would otherwise be told "Invalid
     * action type: convermetry" by Elementor's runner and the VISITOR'S
     * SUBMISSION WOULD FAIL because a site owner turned delivery off. The action
     * stays registered for as long as Convermetry is active and reports a
     * successful no-op when there is nothing to do.
     *
     * Registering the listener needs no Elementor class to exist, so this is
     * safe at plugins_loaded even though Elementor's own classes may not be
     * autoloadable yet; the class guards run later, inside the callbacks, by
     * which point Elementor Pro is necessarily loaded — it is the thing calling.
     *
     * @return void
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        add_action(self::REGISTER_HOOK, [$this, 'registerAction'], 10, 1);
        add_filter('elementor/atomic-widgets/controls', [$this, 'addEditorChoice'], 10, 2);
        add_filter('elementor_pro/atomic_forms/action_log_label', [$this, 'filterActionLogLabel'], 10, 2);

        // Plugin load order is not guaranteed: if Elementor Pro already ran its
        // registration pass before Convermetry booted, the listener above is too
        // late and would leave a saved 'convermetry' action invalid. Registering
        // straight against the runner covers that, and is a no-op otherwise.
        if (did_action(self::REGISTER_HOOK) > 0) {
            $this->registerAction(self::ACTION_RUNNER_CLASS);
        }
    }

    /**
     * Registers the action object with Elementor Pro's Action_Runner.
     *
     * Elementor Pro passes the runner CLASS NAME rather than an instance, so
     * both are accepted. Registration is skipped when the Atomic action base
     * class is unavailable, when the runner does not expose the static API this
     * expects, or when something is already registered under this slug — that
     * last check is what keeps registration idempotent and what guarantees this
     * never displaces another plugin's action.
     *
     * @param mixed $runner Action_Runner class-string or instance from Elementor Pro.
     * @return void
     */
    public function registerAction(mixed $runner = null): void
    {
        // The subclass must not even be NAMED before its parent exists: doing so
        // triggers the autoloader, which would fatal on the missing base class.
        if (!class_exists(self::ACTION_BASE_CLASS)) {
            return;
        }

        $runnerClass = match (true) {
            is_object($runner) => $runner::class,
            is_string($runner) => $runner,
            default            => '',
        };

        if ($runnerClass === '' || !class_exists($runnerClass)) {
            return;
        }

        if (!is_callable([$runnerClass, 'register_action']) || !is_callable([$runnerClass, 'has_action'])) {
            return;
        }

        if ($runnerClass::has_action(self::ACTION_SLUG)) {
            return;
        }

        $runnerClass::register_action(new ConvermetryAtomicAction($this));
    }

    /**
     * Appends the "Convermetry" choice to the Atomic form root's
     * "Actions after submit" control.
     *
     * Elementor hardcodes its own Email / Collect submissions / Webhook choices
     * in Atomic_Form::define_atomic_controls() and exposes the assembled control
     * objects through elementor/atomic-widgets/controls. The Chips control's
     * current options are read back through its public get_props() accessor and
     * re-set with this one appended — every existing choice preserved, no
     * reflection, no private access.
     *
     * Only the form ROOT is touched; field elements are left alone. The choice
     * is not appended twice if the filter runs over the same control again.
     *
     * @param mixed $controls Array of Section / control objects.
     * @param mixed $element  The element whose controls are being assembled.
     * @return mixed The controls, unchanged in shape.
     */
    public function addEditorChoice(mixed $controls, mixed $element = null): mixed
    {
        if (!is_array($controls) || !self::isFormRoot($element)) {
            return $controls;
        }

        self::injectChoice($controls);

        return $controls;
    }

    /**
     * Names this action in Elementor's submission action log, which would
     * otherwise derive its label from the slug.
     *
     * @param mixed $label      Elementor's computed label.
     * @param mixed $actionType The action slug being labelled.
     * @return mixed
     */
    public function filterActionLogLabel(mixed $label, mixed $actionType = null): mixed
    {
        return $actionType === self::ACTION_SLUG ? self::ACTION_LABEL : $label;
    }

    // -------------------------------------------------------------- submission

    /**
     * Records one Atomic submission and reports the outcome to Elementor.
     *
     * A skip is reported as SUCCESS on purpose. An excluded form, a malformed
     * context, or a submission a 'convermetry_should_record_submission' callback
     * declined are all cases where Convermetry was never going to do anything —
     * and the person who just filled the form in submitted it perfectly well.
     * Only a genuine dispatch failure in 'show_error' mode is reported as a
     * failure.
     *
     * @param array<string, mixed> $formData Atomic field values keyed by field id.
     * @param array<string, mixed> $context  Elementor's Atomic context: post_id, form_id,
     *                                       form_name, field_metadata, referrer, files, …
     * @return array{ok: bool, message: string}
     */
    public function handleSubmission(array $formData, array $context): array
    {
        $nativeId = self::nativeId($context);

        // Without an element id there is no stable identity, so per-form
        // settings could not be honoured and two forms could not be told apart.
        // Skipping beats recording a submission under an identity that will not
        // match the one on the Forms screen.
        if ($nativeId === '') {
            return self::skipped('Convermetry could not identify this Atomic form — submission not recorded.');
        }

        $sync = Options::formFailureMode() === 'show_error';

        $result = $this->service->record(
            provider: self::PROVIDER_KEY,
            nativeId: $nativeId,
            formName: self::formName($context, $nativeId),
            fields: self::buildFields($formData, self::fieldMetadata($context)),
            sync: $sync,
            // Last-resort page context when the tracker sent none and the
            // request carried no Referer header. Validated to this site's own
            // host before it is trusted; request globals are never rewritten.
            pageUrl: self::referrer($context),
        );

        return self::outcomeFor($result, $sync);
    }

    /**
     * Translates a pipeline result into the outcome Elementor's runner expects.
     *
     * The distinction that matters: an EXCLUSION or a declined recording leaves
     * failedDeliveries empty and must never fail the visitor's form, while a
     * synchronous dispatch that genuinely failed should surface. Background mode
     * never fails the form at all — the delivery has not been attempted yet, and
     * Convermetry retries it on its own.
     *
     * @param SubmissionResult $result The pipeline's result.
     * @param bool             $sync   Whether delivery ran synchronously ('show_error' mode).
     * @return array{ok: bool, message: string}
     */
    public static function outcomeFor(SubmissionResult $result, bool $sync): array
    {
        if (!$sync) {
            return ['ok' => true, 'message' => ''];
        }

        if (!$result->ok && $result->failedDeliveries !== []) {
            // Deliberately generic: an endpoint's own response body, and the
            // submitted data, are never shown to the visitor.
            return ['ok' => false, 'message' => 'There was an issue submitting the form data through the webhook.'];
        }

        return ['ok' => true, 'message' => ''];
    }

    /**
     * Converts Atomic's field values and metadata into Convermetry descriptors.
     *
     * Native field ids are preserved as the id — they are what automation joins
     * on — and Elementor's editor label travels alongside for humans.
     * {@see \Convermetry\Forms\SubmissionFields} owns sanitizing, the label
     * fallback, and stripping Convermetry's own cvm_* fields, so none of that is
     * repeated here. Nothing is keyed by label: two fields called "Name" stay
     * two fields, which a label-keyed map could not express.
     *
     * @param array<string, mixed> $formData      Field values keyed by native field id.
     * @param array<string, mixed> $fieldMetadata Elementor's per-field metadata.
     * @return list<array{id: string, label: string, value: string|list<string>}>
     */
    public static function buildFields(array $formData, array $fieldMetadata): array
    {
        $fields = [];

        foreach ($formData as $fieldId => $value) {
            $id = (string) $fieldId;
            if ($id === '') {
                continue;
            }

            $meta     = $fieldMetadata[$id] ?? null;
            $rawLabel = is_array($meta) ? ($meta['label'] ?? null) : null;

            $fields[] = [
                'id'    => $id,
                'label' => is_scalar($rawLabel) ? trim((string) $rawLabel) : '',
                'value' => self::fieldValue($value),
            ];
        }

        return $fields;
    }

    /**
     * The stable per-form identity: "<document id>:<element id>".
     *
     * Scoped by document because Atomic element ids are unique only within one,
     * and a library template reused on several pages keeps its ids. Falls back
     * to the bare element id when Elementor sent no post id, which is still
     * stable — just no longer collision-proof across documents.
     *
     * @param array<string, mixed> $context Elementor's Atomic context.
     * @return string The identity, or '' when no element id was supplied.
     */
    public static function nativeId(array $context): string
    {
        $elementId = $context['form_id'] ?? '';
        $elementId = is_scalar($elementId) ? trim(sanitize_text_field((string) $elementId)) : '';

        if ($elementId === '') {
            return '';
        }

        $postId = $context['post_id'] ?? '';
        $postId = is_scalar($postId) ? trim(sanitize_text_field((string) $postId)) : '';

        return $postId !== '' ? $postId . ':' . $elementId : $elementId;
    }

    /**
     * The identity for one element found during discovery, matching what
     * {@see nativeId()} derives at submit time.
     *
     * @param int    $postId    The document the element was found in.
     * @param string $elementId The element's own id.
     * @return string
     */
    public static function identityFor(int $postId, string $elementId): string
    {
        $elementId = trim($elementId);

        if ($elementId === '') {
            return '';
        }

        return $postId > 0 ? $postId . ':' . $elementId : $elementId;
    }

    /**
     * Elementor's Atomic form element type, read from Elementor when it is
     * loaded and falling back to the published slug when it is not.
     *
     * @return string
     */
    public static function formElementType(): string
    {
        $class = self::FORM_ELEMENT_CLASS;

        if (class_exists($class) && is_callable([$class, 'get_element_type'])) {
            $type = $class::get_element_type();

            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        return self::FORM_ELEMENT_TYPE;
    }

    /**
     * Reads a scalar out of an Atomic setting, which is stored either as a typed
     * value ({"$$type":"string","value":"Contact"}) or as a plain scalar.
     *
     * A disabled value, a dynamic-tag reference, or any other structure yields
     * '' — it cannot be resolved without rendering the element, and guessing
     * would put a wrong name on the Forms screen.
     *
     * @param mixed $value Raw stored setting.
     * @return string
     */
    public static function unwrapSetting(mixed $value): string
    {
        if (is_array($value)) {
            if (!empty($value['disabled'])) {
                return '';
            }

            if (isset($value['$$type']) && array_key_exists('value', $value)) {
                return self::unwrapSetting($value['value']);
            }

            return '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * Elementor's own default for the form-name prop, so a form left unnamed is
     * listed under the name it will actually submit under — including when the
     * site runs a translated Elementor.
     *
     * @return string
     */
    public static function formNameDefault(): string
    {
        $class = self::FORM_ELEMENT_CLASS;

        if (!class_exists($class) || !is_callable([$class, 'get_props_schema'])) {
            return self::FORM_NAME_DEFAULT;
        }

        try {
            $schema = $class::get_props_schema();
            $prop   = is_array($schema) ? ($schema[self::FORM_NAME_PROP] ?? null) : null;

            if (is_object($prop) && method_exists($prop, 'get_default')) {
                $default = self::unwrapSetting($prop->get_default());

                if ($default !== '') {
                    return $default;
                }
            }
        } catch (\Throwable) {
            // Elementor could not build its schema — keep the literal fallback.
        }

        return self::FORM_NAME_DEFAULT;
    }

    // ----------------------------------------------------------------- internals

    /**
     * A successful no-op outcome: nothing was recorded, and the visitor's
     * submission must not be failed for it.
     *
     * @param string $message Reason, surfaced to Elementor's action log only.
     * @return array{ok: bool, message: string}
     */
    private static function skipped(string $message): array
    {
        return ['ok' => true, 'message' => $message];
    }

    /**
     * The display name for this submission: Elementor's own, falling back to the
     * identity so a nameless form is still recognisable in reports.
     *
     * @param array<string, mixed> $context  Elementor's Atomic context.
     * @param string               $nativeId The resolved identity.
     * @return string
     */
    private static function formName(array $context, string $nativeId): string
    {
        $name = $context['form_name'] ?? '';
        $name = is_scalar($name) ? trim(sanitize_text_field((string) $name)) : '';

        return $name !== '' ? $name : $nativeId;
    }

    /**
     * Elementor's per-field metadata, or an empty map when it sent none.
     *
     * @param array<string, mixed> $context Elementor's Atomic context.
     * @return array<string, mixed>
     */
    private static function fieldMetadata(array $context): array
    {
        return isset($context['field_metadata']) && is_array($context['field_metadata'])
            ? $context['field_metadata']
            : [];
    }

    /**
     * The submitting page URL Elementor reported, validated to this site's own
     * host — the same rule the tracker's own page URL passes.
     *
     * @param array<string, mixed> $context Elementor's Atomic context.
     * @return string A same-host URL, or ''.
     */
    private static function referrer(array $context): string
    {
        return Url::boundedUrl($context['referrer'] ?? '', true);
    }

    /**
     * Reduces one Atomic value to a string or a flat list of strings.
     *
     * Multi-value fields — checkbox groups, multi-selects, uploaded-file URL
     * lists — stay lists, and a nested structure is flattened rather than
     * collapsing to the literal "Array" or being dropped. Only the SHAPE is
     * settled here; sanitizing is the normalizer's job.
     *
     * Uploaded files arrive as the URLs Elementor chose to expose. Temporary
     * filesystem paths (context['files']) are deliberately never read.
     *
     * @param mixed $value Raw Atomic value.
     * @return string|list<string>
     */
    private static function fieldValue(mixed $value): string|array
    {
        if (!is_array($value)) {
            return self::stringify($value);
        }

        $flat = [];

        array_walk_recursive($value, static function (mixed $item) use (&$flat): void {
            $flat[] = self::stringify($item);
        });

        return $flat;
    }

    /**
     * Coerces one scalar-ish value to a string without notices.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private static function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Whether the filtered element is the Atomic form ROOT.
     *
     * @param mixed $element Element instance from the controls filter.
     * @return bool
     */
    private static function isFormRoot(mixed $element): bool
    {
        if (!is_object($element) || !method_exists($element, 'get_name')) {
            return false;
        }

        return $element->get_name() === self::formElementType();
    }

    /**
     * Walks a control tree and appends the choice to the actions control.
     *
     * Control objects are mutated in place, so nested Sections need no
     * write-back and the array is never restructured.
     *
     * @param array<int|string, mixed> $controls Section / control objects.
     * @return void
     */
    private static function injectChoice(array $controls): void
    {
        foreach ($controls as $control) {
            if (!is_object($control)) {
                continue;
            }

            if (method_exists($control, 'get_items')) {
                $items = $control->get_items();

                if (is_array($items)) {
                    self::injectChoice($items);
                }

                continue;
            }

            self::maybeAddChoice($control);
        }
    }

    /**
     * Appends the choice to one control when it is the actions-after-submit
     * control and does not already carry it.
     *
     * @param object $control Candidate control object.
     * @return void
     */
    private static function maybeAddChoice(object $control): void
    {
        if (
            !method_exists($control, 'get_bind')
            || !method_exists($control, 'get_props')
            || !method_exists($control, 'set_options')
        ) {
            return;
        }

        if ($control->get_bind() !== self::ACTIONS_PROP) {
            return;
        }

        $props   = $control->get_props();
        $options = is_array($props) && is_array($props['options'] ?? null) ? $props['options'] : [];

        foreach ($options as $option) {
            if (is_array($option) && ($option['value'] ?? null) === self::ACTION_SLUG) {
                return; // Already offered — the filter has run over this control before.
            }
        }

        $options[] = ['label' => self::ACTION_LABEL, 'value' => self::ACTION_SLUG];

        $control->set_options($options);
    }
}
