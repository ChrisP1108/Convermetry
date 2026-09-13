<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\Atomic\AtomicFormsBridge;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\FormSettings;
use Convermetry\Forms\Providers\ElementorAtomicProvider;
use Convermetry\Forms\SubmissionResult;
use Convermetry\Forms\SubmissionService;
use Elementor\Modules\AtomicWidgets\Controls\Section;
use Elementor\Modules\AtomicWidgets\Controls\Types\Chips_Control;
use Elementor\Modules\AtomicWidgets\Elements\Atomic_Form\Atomic_Form;
use Elementor\Modules\AtomicWidgets\Elements\Atomic_Form\Atomic_Form_Field;
use ElementorPro\Modules\AtomicForm\Actions\Action_Base;
use ElementorPro\Modules\AtomicForm\Actions\Action_Runner;
use ElementorPro\Modules\AtomicForm\Actions\Unusable_Runner;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/elementor-atomic.php';

/**
 * The Elementor Pro Atomic Forms integration.
 *
 * Atomic is not "Elementor with different markup". It runs an explicit list of
 * actions after submit, so capture is opt-in per form; its element ids are
 * unique only within a document, so identity has to be scoped; and its frontend
 * hand-builds the request rather than serializing the form, so the transport
 * everything else relies on does not exist here. Each of those is a way this
 * integration could look correct and capture nothing, so each is pinned.
 *
 * Two things these tests deliberately do NOT claim, both recorded in the stub
 * file: that any particular Elementor Pro build implements the (unpublished)
 * action contract exactly as stubbed, and that the browser transport works —
 * that one is JavaScript and is covered by a source contract in
 * {@see AtomicCorrelationTransportTest}.
 */
final class AtomicFormsTest extends TestCase
{
    /** @var array<string, list<callable>> Hook name → registered callbacks. */
    private array $hooks = [];

    /** @var array<string, array<string, mixed>> Stored per-form settings. */
    private array $settings = [];

    /** @var list<string> Provider keys observed entering SubmissionService::record(). */
    private array $pipelineEntries = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->hooks           = [];
        $this->settings        = [];
        $this->pipelineEntries = [];

        Action_Runner::reset();

        Functions\when('add_action')->alias(function (string $hook, $callback, $priority = 10, $args = 1): bool {
            $this->hooks[$hook][] = $callback;
            return true;
        });
        Functions\when('add_filter')->alias(function (string $hook, $callback, $priority = 10, $args = 1): bool {
            $this->hooks[$hook][] = $callback;
            return true;
        });
        Functions\when('did_action')->justReturn(0);
        Functions\when('sanitize_text_field')->alias(static fn($value) => trim((string) $value));

        // sanitize_key() is the first statement in SubmissionService::record(),
        // and nothing on the Atomic path to it calls sanitize_key — so anything
        // collected here is proof the shared pipeline was genuinely entered
        // rather than the integration deciding things for itself. The same
        // technique ProviderHookTest uses for the other providers.
        Functions\when('sanitize_key')->alias(function ($value) {
            $this->pipelineEntries[] = (string) $value;

            return strtolower((string) $value);
        });
        Functions\when('get_option')->alias(fn(string $key, $default = false) => $key === FormSettings::OPTION_KEY
            ? $this->settings
            : $default);
    }

    protected function tearDown(): void
    {
        Action_Runner::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function bridge(): AtomicFormsBridge
    {
        return new AtomicFormsBridge(new SubmissionService());
    }

    /**
     * Switches the site to the synchronous 'show_error' failure mode, which is
     * the only mode whose outcome distinguishes a skip from a real failure.
     *
     * @param array<string, mixed> $webhook Extra webhook settings to store.
     * @return void
     */
    private function showErrorMode(array $webhook = []): void
    {
        $settings = array_merge(['failure_mode' => 'show_error'], $webhook);

        Functions\when('get_option')->alias(function (string $key, $default = false) use ($settings) {
            if ($key === FormSettings::OPTION_KEY) {
                return $this->settings;
            }

            if ($key === \Convermetry\Settings\Options::WEBHOOK_OPTION_KEY) {
                return $settings;
            }

            return $default;
        });
    }


    // ------------------------------------------------------------ registration

    public function testRegisteringWiresTheActionTheEditorChoiceAndTheLogLabel(): void
    {
        $this->bridge()->register();

        self::assertArrayHasKey(AtomicFormsBridge::REGISTER_HOOK, $this->hooks);
        self::assertArrayHasKey('elementor/atomic-widgets/controls', $this->hooks);
        self::assertArrayHasKey('elementor_pro/atomic_forms/action_log_label', $this->hooks);
    }

    /**
     * A second register() must not double-wire: WordPress would then run the
     * editor filter twice over one control and the runner registration twice.
     */
    public function testRegistrationIsIdempotent(): void
    {
        $bridge = $this->bridge();
        $bridge->register();
        $bridge->register();

        self::assertCount(1, $this->hooks[AtomicFormsBridge::REGISTER_HOOK]);
        self::assertCount(1, $this->hooks['elementor/atomic-widgets/controls']);
    }

    public function testTheActionIsRegisteredWithTheRunnerUnderItsOwnSlug(): void
    {
        $this->bridge()->registerAction(Action_Runner::class);

        self::assertTrue(Action_Runner::has_action(AtomicFormsBridge::ACTION_SLUG));
        self::assertSame('convermetry', AtomicFormsBridge::ACTION_SLUG);
        self::assertInstanceOf(Action_Base::class, Action_Runner::create_action('convermetry'));
    }

    /** Elementor Pro hands out the runner CLASS NAME, but an instance must work too. */
    public function testAnInstanceIsAcceptedAsWellAsAClassString(): void
    {
        $this->bridge()->registerAction(new Action_Runner());

        self::assertTrue(Action_Runner::has_action(AtomicFormsBridge::ACTION_SLUG));
    }

    public function testRegisteringTwiceLeavesOneAction(): void
    {
        $bridge = $this->bridge();
        $bridge->registerAction(Action_Runner::class);
        $first = Action_Runner::create_action('convermetry');

        $bridge->registerAction(Action_Runner::class);

        self::assertSame($first, Action_Runner::create_action('convermetry'), 'the first registration is kept');
        self::assertCount(1, Action_Runner::get_registered_actions());
    }

    /**
     * The whole reason for a distinct slug: Elementor's own webhook action, and
     * any other plugin's, must survive untouched.
     */
    public function testNativeAndThirdPartyActionsAreNeverReplaced(): void
    {
        $native = new class extends Action_Base {
            public function get_type(): string
            {
                return 'webhook';
            }

            /** @return array<string, mixed> */
            public function execute(array $form_data, array $widget_settings, array $context): array
            {
                return ['status' => 'success'];
            }
        };

        Action_Runner::register_action($native);
        $this->bridge()->registerAction(Action_Runner::class);

        self::assertSame($native, Action_Runner::create_action('webhook'), "Elementor's own action is intact");
        self::assertTrue(Action_Runner::has_action('convermetry'));
        self::assertCount(2, Action_Runner::get_registered_actions(), 'both coexist on the same form');
    }

    /**
     * If another plugin somehow claimed this slug first, Convermetry yields
     * rather than displacing it.
     */
    public function testAnExistingActionUnderTheSameSlugIsNotOverwritten(): void
    {
        $squatter = new class extends Action_Base {
            public function get_type(): string
            {
                return AtomicFormsBridge::ACTION_SLUG;
            }

            /** @return array<string, mixed> */
            public function execute(array $form_data, array $widget_settings, array $context): array
            {
                return ['status' => 'success'];
            }
        };

        Action_Runner::register_action($squatter);
        $this->bridge()->registerAction(Action_Runner::class);

        self::assertSame($squatter, Action_Runner::create_action(AtomicFormsBridge::ACTION_SLUG));
    }

    /**
     * Plugin load order is not guaranteed. If Elementor Pro registered its
     * actions before Convermetry booted, the listener is too late and a saved
     * 'convermetry' action would be reported invalid — failing the visitor's
     * submission. The fallback covers exactly that.
     */
    public function testALateBootStillRegistersAgainstTheRunner(): void
    {
        Functions\when('did_action')->justReturn(1);

        $this->bridge()->register();

        self::assertTrue(
            Action_Runner::has_action(AtomicFormsBridge::ACTION_SLUG),
            'a Convermetry that loaded after Elementor Pro must still register its action'
        );
    }

    public function testAnUnusableRunnerRegistersNothingAndDoesNotFatal(): void
    {
        $bridge = $this->bridge();

        $bridge->registerAction(null);
        $bridge->registerAction('');
        $bridge->registerAction('Convermetry\\No\\Such\\Runner');
        $bridge->registerAction(Unusable_Runner::class);
        $bridge->registerAction(new Unusable_Runner());
        $bridge->registerAction(42);

        self::assertSame([], Action_Runner::get_registered_actions());
    }

    // ------------------------------------------------------------ editor choice

    public function testTheEditorChoiceIsAddedOnceToTheAtomicFormRoot(): void
    {
        $controls = $this->bridge()->addEditorChoice(Atomic_Form::build_controls(), new Atomic_Form());

        self::assertIsArray($controls);
        self::assertSame(
            ['email', 'collect-submissions', 'webhook', 'convermetry'],
            self::actionValues($controls),
            'the choice is appended; every Elementor option is preserved in order'
        );
    }

    public function testTheChoiceIsNotAppendedTwiceWhenTheFilterRunsAgain(): void
    {
        $bridge   = $this->bridge();
        $controls = Atomic_Form::build_controls();

        $controls = $bridge->addEditorChoice($controls, new Atomic_Form());
        $controls = $bridge->addEditorChoice($controls, new Atomic_Form());

        self::assertIsArray($controls);
        self::assertSame(
            ['convermetry'],
            array_values(array_filter(
                self::actionValues($controls),
                static fn(string $value): bool => $value === 'convermetry'
            ))
        );
    }

    public function testTheChoiceCarriesTheConvermetryLabel(): void
    {
        $controls = $this->bridge()->addEditorChoice(Atomic_Form::build_controls(), new Atomic_Form());
        self::assertIsArray($controls);

        $options = self::actionsControl($controls)?->get_props()['options'] ?? [];
        $labels  = array_column(is_array($options) ? $options : [], 'label', 'value');

        self::assertSame('Convermetry', $labels['convermetry'] ?? null);
    }

    /** Field elements are not forms; only the root submits. */
    public function testFieldElementsNeverReceiveTheChoice(): void
    {
        $controls = $this->bridge()->addEditorChoice(Atomic_Form_Field::build_controls(), new Atomic_Form_Field());

        self::assertIsArray($controls);
        self::assertNotContains('convermetry', self::actionValues($controls));
    }

    public function testUnrelatedElementsAndMalformedControlsAreLeftAlone(): void
    {
        $bridge = $this->bridge();

        self::assertSame('not-an-array', $bridge->addEditorChoice('not-an-array', new Atomic_Form()));
        self::assertSame([], $bridge->addEditorChoice([], null));
        self::assertSame([], $bridge->addEditorChoice([], new \stdClass()));
    }

    public function testTheActionLogLabelIsNamedForThisActionOnly(): void
    {
        $bridge = $this->bridge();

        self::assertSame('Convermetry', $bridge->filterActionLogLabel('Convermetry Slug', 'convermetry'));
        self::assertSame('Webhook', $bridge->filterActionLogLabel('Webhook', 'webhook'));
    }

    // ---------------------------------------------------------------- identity

    /**
     * Element ids are unique within a document, not across one — and a library
     * template reused on several pages keeps its ids. Scoping by document is
     * what stops two forms sharing one configuration.
     */
    public function testIdentityIsScopedByDocument(): void
    {
        self::assertSame('42:e1a2b3c', AtomicFormsBridge::nativeId(['post_id' => 42, 'form_id' => 'e1a2b3c']));
        self::assertSame('42:e1a2b3c', AtomicFormsBridge::identityFor(42, 'e1a2b3c'));

        self::assertNotSame(
            AtomicFormsBridge::identityFor(42, 'e1a2b3c'),
            AtomicFormsBridge::identityFor(99, 'e1a2b3c'),
            'the same element id in two documents is two forms'
        );
    }

    public function testDiscoveryAndSubmissionAgreeOnTheSameIdentity(): void
    {
        self::assertSame(
            AtomicFormsBridge::identityFor(42, 'e1a2b3c'),
            AtomicFormsBridge::nativeId(['post_id' => '42', 'form_id' => 'e1a2b3c']),
            'what the Forms screen configures must be what a submission is recorded under'
        );
    }

    public function testAMissingPostIdFallsBackToTheBareElementId(): void
    {
        self::assertSame('e1a2b3c', AtomicFormsBridge::nativeId(['form_id' => 'e1a2b3c']));
        self::assertSame('e1a2b3c', AtomicFormsBridge::identityFor(0, 'e1a2b3c'));
    }

    public function testNoElementIdMeansNoIdentity(): void
    {
        self::assertSame('', AtomicFormsBridge::nativeId([]));
        self::assertSame('', AtomicFormsBridge::nativeId(['post_id' => 42]));
        self::assertSame('', AtomicFormsBridge::nativeId(['form_id' => '  ', 'post_id' => 42]));
        self::assertSame('', AtomicFormsBridge::nativeId(['form_id' => ['array'], 'post_id' => 42]));
        self::assertSame('', AtomicFormsBridge::identityFor(42, ''));
    }

    /**
     * Atomic settings must never inherit the classic provider's name-keyed
     * legacy entries: a classic "Contact" form's configuration applying to an
     * Atomic form that happens to share the name would be silent and wrong.
     */
    public function testAtomicNeverInheritsClassicNameKeyedSettings(): void
    {
        self::assertSame(
            '',
            FormProviderRegistry::legacyFormKey(AtomicFormsBridge::PROVIDER_KEY, 'Contact'),
            'the Atomic provider has no legacy name key'
        );
        self::assertSame(
            'elementor:Contact',
            FormProviderRegistry::legacyFormKey('elementor', 'Contact'),
            "the classic provider's own fallback is untouched"
        );
    }

    public function testTheProviderAndItsFormKeysAreDistinctFromClassicElementor(): void
    {
        $provider = new ElementorAtomicProvider();

        self::assertSame('elementor_atomic', $provider->getKey());
        self::assertSame('Elementor Pro — Atomic Forms', $provider->getLabel());
        self::assertSame(
            'elementor_atomic:42:e1a2b3c',
            FormProviderRegistry::formKey($provider->getKey(), '42:e1a2b3c')
        );
    }

    // ------------------------------------------------------------------ fields

    public function testNativeFieldIdsAndEditorLabelsBothSurvive(): void
    {
        $fields = AtomicFormsBridge::buildFields(
            ['f_name' => 'Ada Lovelace', 'f_email' => 'ada@example.test'],
            [
                'f_name'  => ['label' => 'Full name', 'type' => 'text'],
                'f_email' => ['label' => 'Email', 'type' => 'email'],
            ]
        );

        self::assertSame(
            [
                ['id' => 'f_name', 'label' => 'Full name', 'value' => 'Ada Lovelace'],
                ['id' => 'f_email', 'label' => 'Email', 'value' => 'ada@example.test'],
            ],
            $fields
        );
    }

    /**
     * The reason descriptors beat the reference plugin's label-keyed map: three
     * fields labelled "Name" are three fields, not one.
     */
    public function testDuplicateLabelsStayDistinctFields(): void
    {
        $fields = AtomicFormsBridge::buildFields(
            ['e1' => 'first', 'e2' => 'second', 'e3' => 'third'],
            ['e1' => ['label' => 'Name'], 'e2' => ['label' => 'Name'], 'e3' => ['label' => 'Name']]
        );

        self::assertCount(3, $fields);
        self::assertSame(['e1', 'e2', 'e3'], array_column($fields, 'id'));
        self::assertSame(['first', 'second', 'third'], array_column($fields, 'value'));
    }

    public function testMissingOrBlankMetadataLeavesTheLabelToTheNormalizer(): void
    {
        $fields = AtomicFormsBridge::buildFields(
            ['e1' => 'a', 'e2' => 'b'],
            ['e1' => ['label' => '   ']]
        );

        self::assertSame('', $fields[0]['label'], 'a blank label is reported as blank, not invented');
        self::assertSame('', $fields[1]['label'], 'absent metadata is not an error');
    }

    public function testMultiValueFieldsStayListsAndNeverBecomeTheStringArray(): void
    {
        $fields = AtomicFormsBridge::buildFields(
            [
                'checkboxes' => ['Option A', 'Option B'],
                'nested'     => ['one', ['two', 'three']],
                'empty'      => [],
                'files'      => ['https://example.test/a.pdf', 'https://example.test/b.jpg'],
            ],
            []
        );

        $values = array_column($fields, 'value', 'id');

        self::assertSame(['Option A', 'Option B'], $values['checkboxes']);
        self::assertSame(['one', 'two', 'three'], $values['nested'], 'nested values are kept, not dropped');
        self::assertSame([], $values['empty']);
        self::assertSame(
            ['https://example.test/a.pdf', 'https://example.test/b.jpg'],
            $values['files'],
            'uploaded-file URLs are forwarded as supplied'
        );
    }

    public function testMalformedValuesAreCoercedWithoutNotices(): void
    {
        $fields = AtomicFormsBridge::buildFields(
            ['flag' => true, 'off' => false, 'nothing' => null, 'object' => new \stdClass(), 'n' => 7],
            ['flag' => 'not-an-array']
        );

        $values = array_column($fields, 'value', 'id');

        self::assertSame('1', $values['flag']);
        self::assertSame('', $values['off']);
        self::assertSame('', $values['nothing']);
        self::assertSame('', $values['object']);
        self::assertSame('7', $values['n']);
    }

    public function testFieldsWithNoIdAreSkipped(): void
    {
        self::assertSame([], AtomicFormsBridge::buildFields(['' => 'orphan'], []));
    }

    /**
     * The correlation values ride the request as top-level fields, never as form
     * fields — but if a site ever posted one as a field, the normalizer must
     * still strip it before it can reach a lead, an export, or a payload.
     */
    public function testInternalCorrelationFieldsNeverSurviveNormalization(): void
    {
        $descriptors = AtomicFormsBridge::buildFields(
            ['cvm_conversion_id' => 'c123', 'cvm_session_id' => 'abc', 'email' => 'ada@example.test'],
            []
        );

        $normalized = \Convermetry\Forms\SubmissionFields::normalize($descriptors);

        self::assertSame(['email'], array_column($normalized, 'id'));
    }

    // --------------------------------------------------------- form-name reading

    public function testTypedPlainAndDefaultFormNamesAreAllResolved(): void
    {
        self::assertSame('Contact', AtomicFormsBridge::unwrapSetting(['$$type' => 'string', 'value' => 'Contact']));
        self::assertSame('Contact', AtomicFormsBridge::unwrapSetting('Contact'));
        self::assertSame('', AtomicFormsBridge::unwrapSetting(['$$type' => 'string', 'value' => '']));
        self::assertSame('', AtomicFormsBridge::unwrapSetting(['disabled' => true, 'value' => 'Ignored']));
        self::assertSame('', AtomicFormsBridge::unwrapSetting(['unexpected' => 'shape']));
        self::assertSame('Form', AtomicFormsBridge::formNameDefault(), "Elementor's own prop default is read");
    }

    public function testTheElementTypeIsReadFromElementorWhenAvailable(): void
    {
        self::assertSame('e-form', AtomicFormsBridge::formElementType());
        self::assertSame(Atomic_Form::get_element_type(), AtomicFormsBridge::formElementType());
    }

    // ------------------------------------------------------- outcome semantics

    /**
     * Background mode is the default and must never fail a visitor's form:
     * nothing has been delivered yet, and Convermetry retries on its own.
     */
    public function testBackgroundModeAlwaysReportsSuccessToElementor(): void
    {
        $failed = new SubmissionResult(
            ok: false,
            msg: 'nope',
            failedDeliveries: [['url' => 'https://x.test', 'endpoint_url' => 'https://x.test', 'headers' => [], 'body' => '', 'label' => '']]
        );

        self::assertTrue(AtomicFormsBridge::outcomeFor($failed, false)['ok']);
        self::assertTrue(AtomicFormsBridge::outcomeFor(new SubmissionResult(ok: true, queued: true), false)['ok']);
    }

    /**
     * The distinction the classic provider already draws: an exclusion or a
     * declined recording is not a delivery failure, and the person who filled
     * the form in submitted it perfectly well.
     */
    public function testSynchronousModeFailsOnlyOnRealDeliveryFailures(): void
    {
        $excluded = new SubmissionResult(ok: false, msg: 'This form is excluded from Convermetry by the current settings.');
        $declined = new SubmissionResult(ok: true, submissionId: '', conversionId: '', queued: false);
        $noSend   = new SubmissionResult(ok: true, submissionId: 's1', conversionId: 'c1', queued: false);

        self::assertTrue(AtomicFormsBridge::outcomeFor($excluded, true)['ok'], 'an exclusion must not fail the form');
        self::assertTrue(AtomicFormsBridge::outcomeFor($declined, true)['ok'], 'a declined recording must not fail the form');
        self::assertTrue(AtomicFormsBridge::outcomeFor($noSend, true)['ok'], 'no endpoints configured is not a failure');

        $dispatchFailed = new SubmissionResult(
            ok: false,
            submissionId: 's1',
            conversionId: 'c1',
            msg: 'There was an issue submitting the form data through the webhook.',
            failedDeliveries: [['url' => 'https://x.test', 'endpoint_url' => 'https://x.test', 'headers' => [], 'body' => '{}', 'label' => 'CRM']]
        );

        self::assertFalse(AtomicFormsBridge::outcomeFor($dispatchFailed, true)['ok']);
    }

    /** An endpoint's response, and the visitor's own data, are never shown back to them. */
    public function testTheVisitorFacingMessageIsGeneric(): void
    {
        $result = new SubmissionResult(
            ok: false,
            msg: '500 from https://crm.example.test/hook: {"error":"ada@example.test already exists"}',
            data: ['error' => 'ada@example.test already exists'],
            failedDeliveries: [['url' => 'https://crm.example.test/hook', 'endpoint_url' => 'https://crm.example.test/hook', 'headers' => [], 'body' => '{"email":"ada@example.test"}', 'label' => 'CRM']]
        );

        $message = AtomicFormsBridge::outcomeFor($result, true)['message'];

        self::assertSame('There was an issue submitting the form data through the webhook.', $message);
        self::assertStringNotContainsString('ada@example.test', $message);
        self::assertStringNotContainsString('crm.example.test', $message);
    }

    // ------------------------------------------------------------- submissions

    /**
     * An excluded form records nothing — and still reports success, because the
     * visitor's submission is not the site owner's configuration choice.
     *
     * Run in 'show_error' mode deliberately: in the default background mode the
     * outcome is success whatever happens, so only the synchronous mode can tell
     * "excluded" apart from "delivery failed".
     */
    public function testAnExcludedAtomicFormIsSkippedWithoutFailingTheVisitor(): void
    {
        $this->settings = ['elementor_atomic:42:e1a2b3c' => ['excluded' => true]];
        $this->showErrorMode();

        $outcome = $this->bridge()->handleSubmission(
            ['f1' => 'value'],
            ['post_id' => 42, 'form_id' => 'e1a2b3c', 'form_name' => 'Contact']
        );

        self::assertSame(
            ['elementor_atomic'],
            $this->pipelineEntries,
            'the submission must reach the shared pipeline, which owns the exclusion rule'
        );
        self::assertTrue($outcome['ok'], 'an excluded form must never fail the visitor, even in show_error mode');
        self::assertSame('', $outcome['message']);
    }

    /**
     * Lead capture must not depend on webhook delivery. The pipeline already
     * records the submission and the conversion before it looks at endpoints —
     * so what this integration has to get right is simply never adding a gate of
     * its own in front of that, which is a property of this code rather than of
     * one run through it.
     *
     * Asserted against the source because the alternative — driving a real
     * submission all the way through storage — would need a database this suite
     * deliberately does not have.
     */
    public function testTheAtomicPathNeverGatesCaptureOnDeliverySettings(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Forms/Atomic/AtomicFormsBridge.php');

        // Strip the docblocks: they legitimately discuss delivery settings.
        $code = (string) preg_replace('~/\*\*.*?\*/~s', '', $source);

        foreach (['webhooksActive', 'formEndpoints', 'analyticsEndpoints'] as $gate) {
            self::assertStringNotContainsString(
                $gate,
                $code,
                'the Atomic integration must not decide whether to record from delivery configuration'
            );
        }

        self::assertStringContainsString('$this->service->record(', $code, 'it records through the shared pipeline');
    }

    /**
     * Exclusion is keyed by the document-scoped identity, so the same element id
     * in another document is a different form and stays included.
     */
    public function testExclusionAppliesToTheScopedIdentityOnly(): void
    {
        $this->settings = ['elementor_atomic:42:e1a2b3c' => ['excluded' => true]];

        self::assertTrue(FormSettings::isExcluded('elementor_atomic:42:e1a2b3c'));
        self::assertFalse(FormSettings::isExcluded('elementor_atomic:99:e1a2b3c'));
    }

    public function testASubmissionWithNoIdentifiableFormIsSkippedNotFailed(): void
    {
        $outcome = $this->bridge()->handleSubmission(['f1' => 'v'], ['post_id' => 42]);

        self::assertTrue($outcome['ok'], 'a malformed context must not fail the visitor');
        self::assertStringContainsString('could not identify', $outcome['message']);
    }

    /**
     * The action reaches Convermetry through Elementor's own runner, with
     * Elementor's own argument order — the path a real submission takes.
     */
    public function testTheActionForwardsThroughElementorsRunner(): void
    {
        $this->settings = ['elementor_atomic:42:e1a2b3c' => ['excluded' => true]];

        $this->bridge()->registerAction(Action_Runner::class);
        $action = Action_Runner::create_action(AtomicFormsBridge::ACTION_SLUG);

        self::assertInstanceOf(Action_Base::class, $action);

        $result = $action->execute(
            ['f1' => 'value'],
            ['webhook_url' => 'https://elementor-native.test/hook'],
            ['post_id' => 42, 'form_id' => 'e1a2b3c', 'form_name' => 'Contact']
        );

        self::assertSame('success', $result['status']);
        self::assertSame('convermetry', $action->get_type());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param array<int, mixed> $controls
     * @return list<string>
     */
    private static function actionValues(array $controls): array
    {
        $control = self::actionsControl($controls);

        if ($control === null) {
            return [];
        }

        $options = $control->get_props()['options'] ?? [];

        return array_values(array_map(
            static fn(array $option): string => (string) ($option['value'] ?? ''),
            is_array($options) ? $options : []
        ));
    }

    /**
     * @param array<int, mixed> $controls
     */
    private static function actionsControl(array $controls): ?Chips_Control
    {
        foreach ($controls as $control) {
            if ($control instanceof Section) {
                $found = self::actionsControl($control->get_items());
                if ($found !== null) {
                    return $found;
                }

                continue;
            }

            if ($control instanceof Chips_Control && $control->get_bind() === 'actions-after-submit') {
                return $control;
            }
        }

        return null;
    }
}
