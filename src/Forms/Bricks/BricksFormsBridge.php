<?php
declare(strict_types=1);

namespace Convermetry\Forms\Bricks;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\SubmissionResult;
use Convermetry\Forms\SubmissionService;
use Convermetry\Settings\Options;
use Convermetry\Support\Url;

/**
 * The Bricks Builder native Form element integration.
 *
 * Bricks forms run an ordered list of "Actions after successful form submit".
 * Capture is therefore OPT-IN PER FORM: the site owner selects "Convermetry" in
 * that control, and until they do, Bricks never calls us. Convermetry does not
 * edit saved Bricks content to add it — rewriting another product's documents
 * behind the owner's back is a worse problem than a manual step — so the Forms
 * screen says so in as many words.
 *
 * Scope is the NATIVE Bricks Form element only (`name` = `form`). Third-party
 * Bricks form add-ons ship their own elements, storage and submit paths; none of
 * them is claimed here.
 *
 * This class owns four things:
 *
 *  1. REGISTRATION. It appends "Convermetry" to the form element's `actions`
 *     control, adds a conditional control group carrying the one-line setup
 *     note, listens on `bricks/form/action/convermetry`, and renders the
 *     tracking attributes on the form tag. Bricks' own Email / Webhook /
 *     Save submission choices, and any other plugin's, are left untouched:
 *     this appends a choice, never replaces a list.
 *
 *  2. IDENTITY. One contract, shared by discovery, submission, the rendered
 *     data-cvm-form-key attribute and per-form settings: the Bricks form
 *     ELEMENT ID, on its own. See {@see identityFor()} for why it is not scoped
 *     by document — the short version is that Bricks reports the post the
 *     submission came FROM, which for a header, footer, popup or reused
 *     template is not the document that defines the form.
 *
 *  3. TRANSLATION. Bricks' ($form->get_settings(), ->get_fields(),
 *     ->get_uploaded_files()) become Convermetry's {id, label, value}
 *     descriptors and one {@see SubmissionService::record()} call, so Bricks
 *     submissions get the same exclusions, redaction, storage, conversion
 *     recording, notifications, delivery and deduplication as every other
 *     provider. No payload, queue, retry or settings code is duplicated.
 *
 *  4. FAILURE SEMANTICS. Only a genuinely failed synchronous delivery is
 *     reported back to Bricks as an action error; everything else reports
 *     nothing, because a Bricks action that fails halts every later action on
 *     the form — an exclusion or a "no endpoints configured" must never cost a
 *     visitor their submission or the site owner their confirmation email.
 *
 * NOTHING HERE NAMES A BRICKS CLASS. Bricks is a theme, its PHP API is not
 * published as a package, and the submitted form object is only ever
 * duck-typed — `is_callable([$form, 'get_fields'])` and so on. A Bricks that
 * renamed a method degrades to "records nothing" rather than to a fatal error.
 *
 * The decision helpers ({@see buildFields()}, {@see nativeId()},
 * {@see outcomeFor()}, {@see formsIn()}) are static and take plain values, so
 * what this integration actually decides is testable with no Bricks installed.
 */
final class BricksFormsBridge
{
    /**
     * Provider key for native Bricks forms.
     *
     * Deliberately not shared with any Bricks form ADD-ON: their identities,
     * storage and submit paths are their own, and a shared key would let one
     * product's per-form settings apply to another's form.
     */
    public const string PROVIDER_KEY = 'bricks';

    /**
     * Action slug persisted in the form element's `actions` setting, and the
     * `{form_action}` half of the hook Bricks fires.
     *
     * Distinct from Bricks' own 'email', 'webhook', 'submission' and the rest,
     * so several actions can be selected on one form and none overwrites
     * another.
     */
    public const string ACTION_SLUG = 'convermetry';

    /** Label shown in the editor's actions control. */
    public const string ACTION_LABEL = 'Convermetry';

    /** The Bricks element `name` of the native Form element. */
    public const string ELEMENT_NAME = 'form';

    /** Bricks' controls filter for the native form element. */
    public const string CONTROLS_FILTER = 'bricks/elements/form/controls';

    /** Bricks' control-groups filter for the native form element. */
    public const string CONTROL_GROUPS_FILTER = 'bricks/elements/form/control_groups';

    /**
     * The hook Bricks fires for this action.
     *
     * Bricks' documentation calls `bricks/form/action/{form_action}` a filter in
     * its hook index and registers the callback with add_action() in its own
     * example. It is an action: the callback returns nothing and reports an
     * outcome through $form->set_result(). Registered with add_action() to match
     * the documented example.
     */
    public const string ACTION_HOOK = 'bricks/form/action/' . self::ACTION_SLUG;

    /** Bricks' element attribute filter, used to render the tracking attributes. */
    public const string RENDER_ATTRIBUTES_FILTER = 'bricks/element/render_attributes';

    /** The attribute group holding an element's own root tag attributes. */
    public const string ROOT_ATTRIBUTE_KEY = '_root';

    /** Prefix Bricks gives every submitted field value: form-field-<field id>. */
    public const string FIELD_PREFIX = 'form-field-';

    /** The form element setting holding Bricks' own "Form name". */
    public const string FORM_NAME_SETTING = 'submissionFormName';

    /** The form element setting holding the actions-after-submit list. */
    public const string ACTIONS_SETTING = 'actions';

    /** The control group this integration adds its setup note under. */
    public const string CONTROL_GROUP = 'convermetry';

    /**
     * The three post meta keys a Bricks document's elements live in — header,
     * content and footer content areas, each a flat array of element records.
     *
     * @var list<string>
     */
    public const array CONTENT_META_KEYS = [
        '_bricks_page_content_2',
        '_bricks_page_header_2',
        '_bricks_page_footer_2',
    ];

    /**
     * The first Bricks release with NAMED custom form actions
     * (`bricks/form/action/{form_action}`).
     *
     * Below this, selecting "Convermetry" in the editor would save an action
     * Bricks never dispatches — an option that looks like it works and captures
     * nothing. So an older Bricks is reported unavailable and nothing registers.
     */
    public const string MIN_VERSION = '1.12.2';

    /** The constant Bricks defines, for both the parent theme and a child theme. */
    private const string VERSION_CONSTANT = 'BRICKS_VERSION';

    /**
     * Bricks field types that never carry submitted lead data.
     *
     * 'html' is display-only markup, and 'rememberme' is a login checkbox whose
     * value is a mechanism rather than an answer.
     *
     * @var list<string>
     */
    private const array NON_DATA_FIELD_TYPES = ['html', 'rememberme'];

    /**
     * Bricks field types whose value is an uploaded or picked FILE.
     *
     * @var list<string>
     */
    private const array UPLOAD_FIELD_TYPES = ['file', 'image', 'gallery'];

    /** The Bricks field type whose value is a credential. */
    private const string PASSWORD_FIELD_TYPE = 'password';

    /** Guards against wiring the same callbacks twice. */
    private bool $registered = false;

    /**
     * @param SubmissionService $service The shared pipeline every confirmed
     *                                   submission flows through.
     */
    public function __construct(private readonly SubmissionService $service)
    {
    }

    // ------------------------------------------------------------- availability

    /**
     * Whether a Bricks theme is loaded at all.
     *
     * Bricks defines its version constant in the theme's own functions.php,
     * which WordPress loads AFTER plugins_loaded — so this answers "no" during
     * Convermetry's own boot and "yes" from after_setup_theme onward. That is
     * the whole reason provider registration has a deferred pass; see
     * {@see FormProviderRegistry::registerHooks()}.
     *
     * A Bricks CHILD theme still loads the parent's functions.php, so the
     * constant is defined there too — which is why this tests the constant
     * rather than the active stylesheet's folder name.
     *
     * @return bool
     */
    public static function isInstalled(): bool
    {
        return defined(self::VERSION_CONSTANT);
    }

    /**
     * The loaded Bricks version, or '' when Bricks is not loaded.
     *
     * Read through constant() rather than the bare name so static analysis does
     * not have to be told about a constant this plugin never defines.
     *
     * @return string
     */
    public static function installedVersion(): string
    {
        if (!self::isInstalled()) {
            return '';
        }

        $version = constant(self::VERSION_CONSTANT);

        return is_scalar($version) ? trim((string) $version) : '';
    }

    /**
     * Whether the loaded Bricks is new enough to dispatch a named custom form
     * action.
     *
     * A Bricks with no readable version is treated as unsupported: registering
     * against a build whose action dispatch cannot be established would offer an
     * editor choice that might silently capture nothing.
     *
     * @return bool
     */
    public static function isSupported(): bool
    {
        return self::supports(self::installedVersion());
    }

    /**
     * Whether one Bricks version string is new enough.
     *
     * Split from {@see isSupported()} so the rule itself is testable: the
     * version constant cannot be undefined once PHP has defined it, and a suite
     * that had to define it to test the gate could never test the other side.
     *
     * @param string $version A Bricks version string, or '' for "not loaded".
     * @return bool
     */
    public static function supports(string $version): bool
    {
        return $version !== '' && version_compare($version, self::MIN_VERSION, '>=');
    }

    // ------------------------------------------------------------- registration

    /**
     * Wires the editor choice, its control group, the submission action, and
     * the rendered tracking attributes.
     *
     * Deliberately NOT gated on whether webhooks are configured or active. A
     * form that has already saved this action would otherwise select an action
     * nothing listens for, and the submission's internal capture — the recorded
     * lead, the conversion, the notification email — would silently stop because
     * a site owner turned webhook delivery off. The action stays registered for
     * as long as Convermetry is active and does nothing when there is nothing
     * to do.
     *
     * Idempotent: a second call adds no duplicate hooks, which matters because
     * the registry's deferred theme pass and a plugin re-initialising can both
     * reach it.
     *
     * @return void
     */
    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        add_filter(self::CONTROLS_FILTER, [$this, 'addActionOption'], 10, 1);
        add_filter(self::CONTROL_GROUPS_FILTER, [$this, 'addControlGroup'], 10, 1);
        add_action(self::ACTION_HOOK, [$this, 'handleSubmission'], 10, 1);
        add_filter(self::RENDER_ATTRIBUTES_FILTER, [$this, 'filterRenderAttributes'], 10, 3);
    }

    /**
     * Appends the "Convermetry" choice to the form element's
     * "Actions after successful form submit" control.
     *
     * Bricks documents exactly this shape —
     * $controls['actions']['options']['<slug>'] = '<label>' — and its own
     * choices live in the same map, so the map is read back and re-set with this
     * one appended: every existing choice preserved, in order.
     *
     * A control set with no 'actions' entry, or one whose options are not a map,
     * is left completely alone: that is a Bricks whose shape this code does not
     * recognise, and quietly rebuilding its control would be worse than adding
     * nothing.
     *
     * @param mixed $controls Bricks' assembled controls for the form element.
     * @return mixed The controls, unchanged in shape.
     */
    public function addActionOption(mixed $controls): mixed
    {
        if (!is_array($controls) || !is_array($controls['actions'] ?? null)) {
            return $controls;
        }

        $options = $controls['actions']['options'] ?? null;

        // Absent options means a control Bricks has not populated yet, which is
        // still safe to append to. A non-array means a shape this does not know.
        if ($options !== null && !is_array($options)) {
            return $controls;
        }

        $options ??= [];

        // Already offered — the filter has run over this control before.
        if (!array_key_exists(self::ACTION_SLUG, $options)) {
            $options[self::ACTION_SLUG] = self::ACTION_LABEL;
        }

        $controls['actions']['options'] = $options;
        $controls                       = self::addSetupNote($controls);

        return $controls;
    }

    /**
     * Registers the conditional control group the setup note is shown in.
     *
     * 'required' is Bricks' own visibility rule: the group appears only once
     * "Convermetry" is actually selected under Actions, so a form that does not
     * use it gains no panel clutter.
     *
     * @param mixed $groups Bricks' control groups for the form element.
     * @return mixed The groups, unchanged in shape.
     */
    public function addControlGroup(mixed $groups): mixed
    {
        if (!is_array($groups)) {
            return $groups;
        }

        $groups[self::CONTROL_GROUP] = [
            'title'    => self::ACTION_LABEL,
            'required' => [self::ACTIONS_SETTING, '=', self::ACTION_SLUG],
        ];

        return $groups;
    }

    /**
     * Adds the read-only setup note to the control set.
     *
     * There is deliberately nothing to CONFIGURE here. Everything a Bricks form
     * needs — a custom payload form id, exclusion, per-form headers and query
     * parameters — already lives on Convermetry's own Forms screen, keyed by
     * this form's element id, and duplicating any of it into the Bricks editor
     * would create a second place for the same setting to disagree from.
     *
     * @param array<string, mixed> $controls Bricks' controls for the form element.
     * @return array<string, mixed>
     */
    private static function addSetupNote(array $controls): array
    {
        $controls['convermetryInfo'] = [
            'group'   => self::CONTROL_GROUP,
            'type'    => 'info',
            'content' => 'Submissions from this form are recorded by Convermetry as soon as this action is selected'
                . ' and the page is saved. Configure the form — custom form ID, exclusion, per-form webhook headers'
                . ' and query parameters — under Convermetry → Forms, where it is listed by its Bricks element ID.',
        ];

        return $controls;
    }

    /**
     * Renders Convermetry's tracking attributes on a native Bricks form tag.
     *
     * data-cvm-form-key is AUTHORITATIVE: the tracker prefers it over every DOM
     * heuristic, and it carries the same provider-scoped key the server records
     * the submission under — so a form's browser-side view / start / error
     * observations join to its confirmed submissions instead of being reported
     * against a key that means nothing.
     *
     * Only the form element's own root tag is touched; every other element, and
     * every other attribute group on this one, is returned untouched.
     *
     * @param mixed $attributes Bricks' attributes, grouped by attribute key.
     * @param mixed $key        The attribute group being rendered.
     * @param mixed $element    The Bricks element object (Bricks 1.5+).
     * @return mixed The attributes, unchanged in shape.
     */
    public function filterRenderAttributes(mixed $attributes, mixed $key = null, mixed $element = null): mixed
    {
        if (!is_array($attributes) || $key !== self::ROOT_ATTRIBUTE_KEY || !is_object($element)) {
            return $attributes;
        }

        $name = $element->name ?? null;
        if (!is_string($name) || $name !== self::ELEMENT_NAME) {
            return $attributes;
        }

        $elementId = self::identityFor(is_scalar($element->id ?? null) ? (string) $element->id : '');
        if ($elementId === '') {
            return $attributes;
        }

        $group = $attributes[$key] ?? [];
        if (!is_array($group)) {
            return $attributes;
        }

        $group['data-cvm-form-key'] = FormProviderRegistry::formKey(self::PROVIDER_KEY, $elementId);

        $settings = $element->settings ?? null;
        $formName = self::formName(is_array($settings) ? $settings : [], '');

        // Display only, and only when the site owner actually named the form.
        // A fabricated name here would put a different label on the browser's
        // report rows than the one the submission was recorded under.
        if ($formName !== '') {
            $group['data-cvm-form-name'] = $formName;
        }

        $attributes[$key] = $group;

        return $attributes;
    }

    // -------------------------------------------------------------- submission

    /**
     * Records one Bricks submission, reached only when Bricks executes the
     * Convermetry action the site owner selected on this form.
     *
     * REACHING THIS IS NOT A GUARANTEE THE WHOLE SUBMISSION SUCCEEDS. Bricks
     * runs the selected actions in order (Redirect always last) and stops at the
     * first one that reports a failure, so an action AFTER this one can still
     * fail — and this one has already recorded by then. The recorded submission
     * is the honest answer to "did Bricks accept this submission and start
     * running its actions", which is the same thing every other provider's
     * success hook reports.
     *
     * Bricks validation, spam rejection (reCAPTCHA / hCaptcha / Turnstile /
     * honeypot), max-entry and duplicate-entry limits all run BEFORE the action
     * list, so a rejected submission never arrives here at all. Conditional
     * action rules are Bricks' own, and are likewise applied before dispatch.
     *
     * @param mixed $form Bricks' form object for this submission.
     * @return void
     */
    public function handleSubmission(mixed $form): void
    {
        if (!is_object($form)) {
            return;
        }

        $submitted = self::readArray($form, 'get_fields');
        $nativeId  = self::nativeId($submitted);

        // Without an element id there is no stable identity, so per-form
        // settings could not be honoured and two forms could not be told apart.
        // Skipping beats recording a submission under an identity that will not
        // match the one on the Forms screen — and a skip must never be reported
        // to Bricks as a failure, because the visitor submitted the form fine.
        if ($nativeId === '') {
            return;
        }

        $settings = self::readArray($form, 'get_settings');
        $sync     = Options::formFailureMode() === 'show_error';

        $result = $this->service->record(
            provider: self::PROVIDER_KEY,
            nativeId: $nativeId,
            formName: self::formName($settings, $nativeId),
            fields: self::buildFields($settings, $submitted, self::readArray($form, 'get_uploaded_files')),
            sync: $sync,
            // Last-resort page context when the tracker sent none and the
            // request carried no Referer header. Validated to this site's own
            // host before it is trusted; request globals are never rewritten.
            pageUrl: self::referrer($submitted),
        );

        $outcome = self::outcomeFor($result, $sync);

        // Silence IS the success signal. Bricks treats an action that sets no
        // result as successful, and setting one would replace the form's own
        // configured success message with Convermetry's.
        if ($outcome['ok'] || !is_callable([$form, 'set_result'])) {
            return;
        }

        $form->set_result([
            'action'  => self::ACTION_SLUG,
            'type'    => 'error',
            'message' => $outcome['message'],
        ]);
    }

    /**
     * Translates a pipeline result into the outcome reported back to Bricks.
     *
     * The distinction that matters: an EXCLUSION, a declined recording, or a
     * site with no endpoints configured leaves failedDeliveries empty and must
     * never fail the visitor's form — a Bricks action failure also HALTS every
     * later action, so a spurious failure here would cost the site owner their
     * confirmation email and the visitor their redirect. Only a synchronous
     * dispatch that genuinely failed should surface. Background mode never fails
     * the form at all: the delivery has not been attempted yet, and Convermetry
     * retries it on its own.
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

    // ---------------------------------------------------------------- identity

    /**
     * The stable per-form identity: the Bricks form ELEMENT ID, on its own.
     *
     * NOT scoped by document, and that is the considered choice rather than a
     * simplification. Bricks reports `postId` as the post a submission was made
     * FROM. A form in a header, footer, popup, or a template reused across the
     * site is defined in one `bricks_template` post and submitted from every
     * page that renders it, so a "<post>:<element>" key would split one form's
     * configuration across every page it appears on and would never match the
     * identity discovery derives from the document that actually defines it.
     *
     * The element id alone is what Bricks' own Form Submissions feature groups
     * entries by, site-wide, and it agrees across page forms, header/footer
     * templates, popups, reused templates and repeated render instances. The
     * post a submission came from is still captured — as page context, which is
     * what it actually is.
     *
     * KNOWN CONSEQUENCE: duplicating a WordPress document copies its Bricks
     * element ids verbatim, so the two copies of a form share one identity and
     * one configuration. Bricks' own submissions dashboard groups them together
     * for the same reason.
     *
     * @param array<string, mixed> $submitted Bricks' get_fields() array.
     * @return string The identity, or '' when Bricks reported no form id.
     */
    public static function nativeId(array $submitted): string
    {
        return self::identityFor(
            is_scalar($submitted['formId'] ?? null) ? (string) $submitted['formId'] : ''
        );
    }

    /**
     * The identity for one element id, matching what {@see nativeId()} derives
     * at submit time.
     *
     * @param string $elementId The Bricks element id.
     * @return string The identity, or '' when there is no usable id.
     */
    public static function identityFor(string $elementId): string
    {
        return trim(sanitize_text_field($elementId));
    }

    /**
     * The post a submission was made FROM, which is page context and never
     * identity. Kept separate so the two can never be confused.
     *
     * @param array<string, mixed> $submitted Bricks' get_fields() array.
     * @return int The post id, or 0.
     */
    public static function submittedFromPostId(array $submitted): int
    {
        $postId = $submitted['postId'] ?? null;

        return is_scalar($postId) && (int) $postId > 0 ? (int) $postId : 0;
    }

    // ------------------------------------------------------------------ fields

    /**
     * Converts Bricks' field definitions and submitted values into Convermetry
     * descriptors, in the order the fields appear in the form.
     *
     * DRIVEN BY THE DEFINITIONS, NOT BY THE SUBMITTED ARRAY, and that is the
     * safety property this method exists for. Bricks field ids are opaque
     * six-character strings — '15bc57' says nothing about what it holds — so a
     * submitted value can only be classified by looking up its definition. A
     * submitted `form-field-*` key with no definition is therefore NOT recorded:
     * its type is unknown, and an unknown type could be a password.
     *
     * What that rules out, by construction:
     *
     *  - PASSWORDS. A 'password' field is dropped outright, so no credential is
     *    ever persisted, exported, emailed or delivered. Convermetry's
     *    name-based redaction cannot help here: it matches 'password' in a field
     *    NAME, and a Bricks field is named '4f2a9c'.
     *  - TRANSPORT METADATA. postId, formId, referrer, the nonce, and the
     *    reCAPTCHA / hCaptcha / Turnstile response tokens all arrive as
     *    top-level keys, not as `form-field-*`, so none of them is even looked
     *    at.
     *  - CONVERMETRY'S OWN FIELDS. cvm_conversion_id and friends travel the same
     *    top-level route; {@see \Convermetry\Forms\SubmissionFields} strips the
     *    prefix a second time regardless.
     *  - HONEYPOTS and display-only HTML fields, which Bricks does not treat as
     *    submitted data either.
     *
     * Native field ids are preserved as the id — they are what automation joins
     * on — and Bricks' editor label travels alongside for humans. Nothing is
     * keyed by label: two fields called "Name" stay two fields. Empty and zero
     * values are preserved as submitted; only the definition decides whether a
     * field appears at all.
     *
     * @param array<string, mixed> $settings  Bricks' get_settings() array.
     * @param array<string, mixed> $submitted Bricks' get_fields() array.
     * @param array<string, mixed> $files     Bricks' get_uploaded_files() array.
     * @return list<array{id: string, label: string, value: string|list<string>}>
     */
    public static function buildFields(array $settings, array $submitted, array $files = []): array
    {
        $fields = [];

        foreach (self::fieldDefinitions($settings) as $definition) {
            $id = is_scalar($definition['id'] ?? null) ? trim((string) $definition['id']) : '';
            if ($id === '') {
                continue;
            }

            $type = is_scalar($definition['type'] ?? null) ? strtolower(trim((string) $definition['type'])) : '';

            if (self::isExcludedField($type, $definition)) {
                continue;
            }

            $key      = self::FIELD_PREFIX . $id;
            $rawLabel = $definition['label'] ?? null;

            $fields[] = [
                'id'    => $id,
                'label' => is_scalar($rawLabel) ? trim((string) $rawLabel) : '',
                'value' => in_array($type, self::UPLOAD_FIELD_TYPES, true)
                    ? self::uploadValue($files[$key] ?? null, $submitted[$key] ?? null)
                    : self::fieldValue($submitted[$key] ?? ''),
            ];
        }

        return $fields;
    }

    /**
     * Whether a defined field's value must never be recorded.
     *
     * @param string               $type       Lowercased Bricks field type.
     * @param array<string, mixed> $definition The field's definition.
     * @return bool
     */
    private static function isExcludedField(string $type, array $definition): bool
    {
        if ($type === self::PASSWORD_FIELD_TYPE || in_array($type, self::NON_DATA_FIELD_TYPES, true)) {
            return true;
        }

        // Honeypot fields hold nothing a real visitor typed, and Bricks rejects
        // a submission that filled one before any action runs — so this is
        // tidiness rather than a guard, and a Bricks that spells the flag
        // differently costs an always-empty field in the data, nothing more.
        return !empty($definition['honeypot']);
    }

    /**
     * Bricks' ordered field definitions, or [] when the settings carry none.
     *
     * @param array<string, mixed> $settings Bricks' get_settings() array.
     * @return list<array<string, mixed>>
     */
    private static function fieldDefinitions(array $settings): array
    {
        $fields = $settings['fields'] ?? null;

        if (!is_array($fields)) {
            return [];
        }

        $out = [];
        foreach ($fields as $definition) {
            if (is_array($definition)) {
                $out[] = $definition;
            }
        }

        return $out;
    }

    /**
     * The value recorded for an upload-type field.
     *
     * Bricks' get_uploaded_files() exposes both a 'file' (the physical path the
     * upload was saved to) and a 'url'. ONLY the url is ever read: a filesystem
     * path is not lead data, it is an invitation to store server internals in a
     * webhook payload and a notification email. The same rule the other
     * providers follow.
     *
     * When Bricks reported no uploaded file — a media-library Image or Gallery
     * field picks an existing attachment rather than uploading one — the
     * submitted value is used, narrowed to values that are unambiguously safe to
     * publish: absolute http(s) URLs and numeric attachment ids. Anything else
     * is dropped rather than guessed at.
     *
     * @param mixed $uploaded  Bricks' entry for this field in get_uploaded_files().
     * @param mixed $submitted The submitted value for this field.
     * @return list<string>
     */
    private static function uploadValue(mixed $uploaded, mixed $submitted): array
    {
        $urls = [];

        foreach (is_array($uploaded) ? $uploaded : [] as $file) {
            $url = is_array($file) ? ($file['url'] ?? null) : null;

            if (is_scalar($url) && trim((string) $url) !== '') {
                $urls[] = trim((string) $url);
            }
        }

        if ($urls !== []) {
            return $urls;
        }

        $value = self::fieldValue($submitted);

        return array_values(array_filter(
            is_array($value) ? $value : [$value],
            static fn(string $item): bool => $item !== '' && (
                str_starts_with($item, 'http://')
                || str_starts_with($item, 'https://')
                || ctype_digit($item)
            )
        ));
    }

    /**
     * The display name for this form: Bricks' own "Form name" when the site
     * owner set one, then the builder's element label, then the fallback the
     * caller supplies.
     *
     * Bricks has no equivalent of a form_name that travels with a submission, so
     * nothing here is read out of the request — the name comes from the form's
     * saved settings, the same source discovery reads. It is display only:
     * identity never depends on it, and two forms may share one.
     *
     * @param array<string, mixed> $settings Bricks' form element settings.
     * @param string               $fallback Value to use when the form is unnamed.
     * @return string
     */
    public static function formName(array $settings, string $fallback): string
    {
        foreach ([self::FORM_NAME_SETTING, 'label'] as $key) {
            $value = $settings[$key] ?? null;

            if (is_scalar($value)) {
                $name = trim(sanitize_text_field((string) $value));

                if ($name !== '') {
                    return $name;
                }
            }
        }

        return $fallback;
    }

    /**
     * The name a discovered form is listed under.
     *
     * Falls back to a DETERMINISTIC label built from the element id rather than
     * a shared placeholder, so two unnamed forms are told apart on the Forms
     * screen and a form's row keeps the same label between discoveries.
     *
     * @param array<string, mixed> $element   One Bricks element record.
     * @param string               $elementId The element's own id.
     * @return string
     */
    public static function discoveredName(array $element, string $elementId): string
    {
        $settings = $element['settings'] ?? null;
        $settings = is_array($settings) ? $settings : [];

        // The builder's own element label lives on the element record, beside
        // the settings rather than inside them.
        $label = $element['label'] ?? null;
        if (is_scalar($label) && trim((string) $label) !== '') {
            $settings['label'] = $label;
        }

        return self::formName($settings, 'Bricks form ' . $elementId);
    }

    // --------------------------------------------------------------- discovery

    /**
     * Every native Bricks form element in one decoded content area.
     *
     * A content area is a FLAT array: elements reference each other by id
     * through 'parent' and 'children' rather than nesting, so this is a single
     * pass with no tree walk.
     *
     * COMPONENT INSTANCES ARE SKIPPED. Bricks stores component definitions in
     * the `bricks_components` option, not in a document's content areas, so an
     * instance record here carries a 'cid' reference and none of the form's own
     * settings. Listing it would offer configuration under an identity that has
     * not been verified against what such a form submits — which is exactly the
     * kind of row that looks configured and applies to nothing. See the Forms
     * screen and the README for the explicit statement of this gap.
     *
     * @param array<int|string, mixed>       $elements Decoded content area.
     * @param array<string, array{native_id: string, name: string}> $forms Collected forms, by identity (by reference).
     * @return void
     */
    public static function collectForms(array $elements, array &$forms): void
    {
        foreach ($elements as $element) {
            if (!is_array($element) || ($element['name'] ?? null) !== self::ELEMENT_NAME) {
                continue;
            }

            if (isset($element['cid'])) {
                continue;
            }

            $elementId = self::identityFor(
                is_scalar($element['id'] ?? null) ? (string) $element['id'] : ''
            );

            // No element id means a hand-edited or corrupted record. There is no
            // identity to key settings by and none to match a submission
            // against, so listing it would offer configuration that could never
            // apply.
            if ($elementId === '' || isset($forms[$elementId])) {
                continue;
            }

            $forms[$elementId] = [
                'native_id' => $elementId,
                'name'      => self::discoveredName($element, $elementId),
            ];
        }
    }

    /**
     * Every native Bricks form in one decoded content area, as a list.
     *
     * Split out from the postmeta query so what discovery actually decides —
     * which records count as forms, what identity each gets, and what name it is
     * listed under — is testable as a pure function, with no database and no
     * mock of one.
     *
     * @param array<int|string, mixed> $elements Decoded content area.
     * @return array<int, array{native_id: string, name: string}>
     */
    public static function formsIn(array $elements): array
    {
        $forms = [];

        self::collectForms($elements, $forms);

        return array_values($forms);
    }

    // ----------------------------------------------------------------- internals

    /**
     * Calls one of the Bricks form object's accessors, duck-typed.
     *
     * Bricks' PHP API is not published as a package and this plugin must not
     * name its classes, so the object is only ever asked whether it answers to a
     * method. A Bricks that renamed one yields an empty array rather than a
     * fatal error.
     *
     * @param object $form   Bricks' form object.
     * @param string $method Accessor name.
     * @return array<string, mixed>
     */
    private static function readArray(object $form, string $method): array
    {
        if (!is_callable([$form, $method])) {
            return [];
        }

        try {
            /** @var mixed $value */
            $value = $form->{$method}();
        } catch (\Throwable) {
            // Bricks could not assemble the data — record what is available
            // rather than failing the visitor's submission.
            return [];
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * The submitting page URL Bricks reported, validated to this site's own
     * host — the same rule the tracker's own page URL passes. Request globals
     * are never rewritten.
     *
     * @param array<string, mixed> $submitted Bricks' get_fields() array.
     * @return string A same-host URL, or ''.
     */
    private static function referrer(array $submitted): string
    {
        return Url::boundedUrl($submitted['referrer'] ?? '', true);
    }

    /**
     * Reduces one submitted value to a string or a flat list of strings.
     *
     * Multi-value fields — checkbox groups, multi-selects — stay lists, and a
     * nested structure is flattened rather than collapsing to the literal
     * "Array" or being dropped. Only the SHAPE is settled here; sanitizing is
     * the normalizer's job.
     *
     * @param mixed $value Raw submitted value.
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
}
