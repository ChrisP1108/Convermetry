<?php
declare(strict_types=1);

namespace Convermetry\Forms\Providers;

if (!defined('ABSPATH')) exit;

use Convermetry\Forms\Atomic\AtomicFormsBridge;
use Convermetry\Forms\FormProviderInterface;
use Convermetry\Forms\SubmissionService;

/**
 * Elementor Pro Atomic Forms integration.
 *
 * Separate from {@see ElementorProvider} rather than folded into it, because
 * the two share a vendor and nothing else:
 *
 *  - CLASSIC forms fire elementor_pro/forms/new_record on every submission, so
 *    capture is automatic. ATOMIC forms run an explicit "Actions after submit"
 *    list, so capture happens only once a site owner adds Convermetry to that
 *    list in the editor. That difference is the single most important thing an
 *    administrator needs told, and one merged provider card could not say it.
 *  - Classic identity is the widget id, with a legacy fallback to the form NAME
 *    for sites that predate that change. Atomic identity is document-scoped
 *    ("<post id>:<element id>") and has NO name-based history to inherit —
 *    sharing the classic provider's key would have quietly offered Atomic forms
 *    a legacy name fallback that was never theirs, letting a classic form's
 *    settings leak onto a same-named Atomic one.
 *
 * Availability is the Elementor Pro marker alone, exactly as the classic
 * provider tests it, and deliberately NOT a class_exists() probe for the Atomic
 * module. Provider hooks are registered on plugins_loaded, where a plugin's
 * CONSTANTS are reliably defined but another plugin's autoloader may not be
 * registered yet — so probing for an Elementor class there can answer "no" on a
 * site that has it, and this integration would then silently never register its
 * action. A Pro install without Atomic support simply discovers no Atomic forms.
 */
final class ElementorAtomicProvider implements FormProviderInterface
{
    /** The Atomic form root's element type in _elementor_data. */
    private const string FORM_ELEMENT_TYPE = AtomicFormsBridge::FORM_ELEMENT_TYPE;

    /** The setting holding an Atomic form's display name. */
    private const string FORM_NAME_SETTING = AtomicFormsBridge::FORM_NAME_PROP;

    /** The bridge instance whose hooks were registered, for reuse and for tests. */
    private ?AtomicFormsBridge $bridge = null;

    public function getKey(): string
    {
        return AtomicFormsBridge::PROVIDER_KEY;
    }

    public function getLabel(): string
    {
        return 'Elementor Pro — Atomic Forms';
    }

    public function isAvailable(): bool
    {
        return defined('ELEMENTOR_PRO_VERSION') || class_exists('\ElementorPro\Plugin');
    }

    /**
     * Discovers every Atomic form element on the site.
     *
     * Runs against _elementor_data post meta directly, for the same reason the
     * classic provider does: get_posts() with post_type 'any' searches only
     * PUBLIC post types and would silently skip elementor_library, where
     * template-based forms live.
     *
     * Discovery is deliberately independent of whether the Convermetry action
     * has been added to a form. An administrator has to be able to see and
     * configure a form BEFORE wiring it up — but discovery alone proves nothing
     * about capture, which is why the Forms screen says so beside these rows.
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

            self::extractForms($elements, (int) $postId, $forms);
        }

        return array_values($forms);
    }

    /**
     * Every Atomic form in one decoded document.
     *
     * The tree walk, split out from the postmeta query so what this provider
     * actually decides — which nodes are forms, what identity each gets, and
     * what name it is listed under — is testable as a pure function, with no
     * database and no mock of one.
     *
     * @param array<int|string, mixed> $elements Decoded _elementor_data.
     * @param int                      $postId   The document the tree belongs to.
     * @return array<int, array{native_id: string, name: string}>
     */
    public static function formsIn(array $elements, int $postId): array
    {
        $forms = [];

        self::extractForms($elements, $postId, $forms);

        return array_values($forms);
    }

    /**
     * Registers the Atomic action, its editor choice, and its log label.
     *
     * Nothing here touches an Elementor class: the callbacks do, once Elementor
     * Pro calls them. {@see AtomicFormsBridge::register()} is idempotent, so a
     * second call — a test, a plugin re-initialising — adds no duplicate hooks.
     *
     * @param SubmissionService $service The shared submission pipeline.
     * @return void
     */
    public function registerHooks(SubmissionService $service): void
    {
        $this->bridge ??= new AtomicFormsBridge($service);
        $this->bridge->register();
    }

    /**
     * Walks an element tree collecting Atomic form roots.
     *
     * Atomic field elements (e-form-input, e-form-checkbox, …) are their own
     * types and are deliberately not matched — only the root submits.
     *
     * @param array<int|string, mixed>                              $elements Element tree.
     * @param int                                                   $postId   Document the tree belongs to.
     * @param array<string, array{native_id: string, name: string}> $forms    Collected forms, by identity (by reference).
     * @return void
     */
    private static function extractForms(array $elements, int $postId, array &$forms): void
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (self::isFormRoot($element)) {
                $elementId = is_scalar($element['id'] ?? null) ? trim((string) $element['id']) : '';
                $identity  = AtomicFormsBridge::identityFor($postId, $elementId);

                // No element id means a hand-edited or corrupted tree. There is
                // no identity to key settings by and none to match a submission
                // against, so listing it would offer configuration that could
                // never apply.
                if ($identity !== '') {
                    $forms[$identity] = [
                        'native_id' => $identity,
                        'name'      => self::formName($element, $elementId),
                    ];
                }
            }

            if (!empty($element['elements']) && is_array($element['elements'])) {
                self::extractForms($element['elements'], $postId, $forms);
            }
        }
    }

    /**
     * Whether an element node is an Atomic form root.
     *
     * The root is stored as an ELEMENT (elType 'e-form'); widgetType is checked
     * as well so a future registration as a widget type is still discovered.
     *
     * @param array<string, mixed> $element Element node.
     * @return bool
     */
    private static function isFormRoot(array $element): bool
    {
        return ($element['elType'] ?? null) === self::FORM_ELEMENT_TYPE
            || ($element['widgetType'] ?? null) === self::FORM_ELEMENT_TYPE;
    }

    /**
     * The name an Atomic form is listed and submitted under.
     *
     * Mirrors Elementor's own resolution so the discovered label matches the
     * form_name that actually arrives:
     *
     *  1. A configured, non-empty 'form-name' (typed or plain value).
     *  2. No setting at all — Elementor resolves its prop-schema default
     *     (normally "Form"), which is what the form then posts.
     *  3. An explicitly EMPTY name — Elementor omits data-form-name and the
     *     submission carries no name, so the element id is shown instead. The
     *     name is display only; identity never depends on it.
     *
     * @param array<string, mixed> $element   Element node.
     * @param string               $elementId The element's own id.
     * @return string
     */
    private static function formName(array $element, string $elementId): string
    {
        $settings = $element['settings'] ?? null;
        $raw      = is_array($settings) ? ($settings[self::FORM_NAME_SETTING] ?? null) : null;

        if ($raw === null) {
            return AtomicFormsBridge::formNameDefault();
        }

        $name = AtomicFormsBridge::unwrapSetting($raw);

        return $name !== '' ? $name : $elementId;
    }
}
