<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\Bricks\BricksFormsBridge;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\FormSettings;
use Convermetry\Forms\Providers\BricksFormsProvider;
use Convermetry\Forms\SubmissionResult;
use Convermetry\Forms\SubmissionService;
use Convermetry\Tests\Stubs\BricksForm;
use Convermetry\Tests\Stubs\BrokenBricksForm;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/bricks.php';

/**
 * The Bricks Builder native Form element integration.
 *
 * Bricks is not "another form plugin". It is a THEME, so it is not loaded when
 * Convermetry boots; capture is opt-in per form, because a Bricks form runs only
 * the actions its owner selected; its field ids are opaque six-character
 * strings, so a submitted value can only be classified through its definition;
 * and a Bricks action that reports a failure HALTS every later action on the
 * form. Each of those is a way this integration could look correct and be
 * wrong — silently capturing nothing, persisting a password, or costing a
 * visitor their submission — so each is pinned here.
 *
 * What these tests deliberately do NOT claim: that any particular Bricks build
 * behaves exactly as its documentation describes. Bricks is not installed in
 * this suite and its source is not published, so a green run proves Convermetry
 * holds up its end of the documented contract. The runtime half is a live-site
 * check and is recorded as unverified in the README.
 */
final class BricksFormsTest extends TestCase
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
        // and nothing on the Bricks path to it calls sanitize_key — so anything
        // collected here is proof the shared pipeline was genuinely entered
        // rather than this integration deciding things for itself. The same
        // technique ProviderHookTest uses for the other providers.
        Functions\when('sanitize_key')->alias(function ($value) {
            $this->pipelineEntries[] = (string) $value;

            return strtolower((string) $value);
        });
        Functions\when('get_option')->alias(fn(string $key, $default = false) => $key === FormSettings::OPTION_KEY
            ? $this->settings
            : $default);

        // Enough of WordPress for Correlation to resolve a server-side
        // conversion id when a submission gets past the exclusion gate.
        Functions\when('wp_unslash')->alias(static fn($value) => $value);
        Functions\when('wp_generate_uuid4')->justReturn('11111111-2222-3333-4444-555555555555');
        Functions\when('wp_rand')->justReturn(7);
    }

    /**
     * Makes the pipeline decline this submission at its documented veto point.
     *
     * That is the deepest a unit suite can honestly follow a Bricks submission:
     * everything past it writes to a database this suite deliberately does not
     * have. It is also the exact case that matters here — a declined recording
     * is a SUCCESSFUL no-op, and must not be reported to Bricks as a failure.
     *
     * @return void
     */
    private static function declineRecording(): void
    {
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value = null, mixed ...$rest): mixed
                => $hook === 'convermetry_should_record_submission' ? false : $value
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function bridge(): BricksFormsBridge
    {
        return new BricksFormsBridge(new SubmissionService());
    }

    /**
     * Switches the site to the synchronous 'show_error' failure mode, which is
     * the only mode whose outcome distinguishes a skip from a real failure.
     *
     * @return void
     */
    private function showErrorMode(): void
    {
        Functions\when('get_option')->alias(function (string $key, $default = false) {
            if ($key === FormSettings::OPTION_KEY) {
                return $this->settings;
            }

            if ($key === \Convermetry\Settings\Options::WEBHOOK_OPTION_KEY) {
                return ['failure_mode' => 'show_error'];
            }

            return $default;
        });
    }

    /**
     * Bricks' form element settings for a two-field contact form.
     *
     * @param list<array<string, mixed>> $fields Field definitions.
     * @param array<string, mixed>       $extra  Extra element settings.
     * @return array<string, mixed>
     */
    private static function formSettings(array $fields, array $extra = []): array
    {
        return array_merge(['fields' => $fields, 'actions' => ['email', 'convermetry']], $extra);
    }

    // ------------------------------------------------------------ registration

    public function testRegisteringWiresTheEditorChoiceTheActionAndTheRenderedAttributes(): void
    {
        $this->bridge()->register();

        self::assertArrayHasKey(BricksFormsBridge::CONTROLS_FILTER, $this->hooks);
        self::assertArrayHasKey(BricksFormsBridge::CONTROL_GROUPS_FILTER, $this->hooks);
        self::assertArrayHasKey(BricksFormsBridge::ACTION_HOOK, $this->hooks);
        self::assertArrayHasKey(BricksFormsBridge::RENDER_ATTRIBUTES_FILTER, $this->hooks);
    }

    /** The documented hook name, spelled out: a typo here captures nothing. */
    public function testTheActionHookIsTheDocumentedNamedCustomAction(): void
    {
        self::assertSame('convermetry', BricksFormsBridge::ACTION_SLUG);
        self::assertSame('bricks/form/action/convermetry', BricksFormsBridge::ACTION_HOOK);
        self::assertSame('bricks/elements/form/controls', BricksFormsBridge::CONTROLS_FILTER);
    }

    /**
     * A second register() must not double-wire: WordPress would then run the
     * controls filter twice over one control set and record one submission
     * twice.
     */
    public function testRegistrationIsIdempotent(): void
    {
        $bridge = $this->bridge();
        $bridge->register();
        $bridge->register();

        self::assertCount(1, $this->hooks[BricksFormsBridge::CONTROLS_FILTER]);
        self::assertCount(1, $this->hooks[BricksFormsBridge::ACTION_HOOK]);
        self::assertCount(1, $this->hooks[BricksFormsBridge::RENDER_ATTRIBUTES_FILTER]);
    }

    /** The provider re-registering (the registry's deferred pass) must be free. */
    public function testRepeatedProviderRegistrationAddsNoDuplicateHooks(): void
    {
        $provider = new BricksFormsProvider();
        $service  = new SubmissionService();

        $provider->registerHooks($service);
        $provider->registerHooks($service);

        self::assertCount(1, $this->hooks[BricksFormsBridge::ACTION_HOOK]);
    }

    public function testTheProviderIsIdentifiedAsBricks(): void
    {
        $provider = new BricksFormsProvider();

        self::assertSame('bricks', $provider->getKey());
        self::assertSame('Bricks Builder', $provider->getLabel());
        self::assertSame('bricks:ab12cd', FormProviderRegistry::formKey($provider->getKey(), 'ab12cd'));
    }

    /**
     * Bricks forms must never inherit Elementor's name-keyed legacy entries: an
     * Elementor "Contact" form's configuration applying to a same-named Bricks
     * form would be silent and wrong.
     */
    public function testBricksNeverInheritsAnyLegacyNameKeyedSettings(): void
    {
        self::assertSame('', FormProviderRegistry::legacyFormKey(BricksFormsBridge::PROVIDER_KEY, 'Contact'));
    }

    // ------------------------------------------------------------ editor choice

    /**
     * Bricks' documented shape: $controls['actions']['options'][<slug>] = label.
     * Every choice Bricks already offers has to survive, in order.
     */
    public function testTheEditorChoiceIsAppendedAndBricksOwnOptionsArePreserved(): void
    {
        $controls = $this->bridge()->addActionOption([
            'fields'  => ['type' => 'repeater'],
            'actions' => [
                'type'    => 'select',
                'label'   => 'Actions after successful form submit',
                'options' => ['email' => 'Email', 'webhook' => 'Webhook', 'redirect' => 'Redirect'],
            ],
        ]);

        self::assertIsArray($controls);
        self::assertSame(
            ['email', 'webhook', 'redirect', 'convermetry'],
            array_keys($controls['actions']['options']),
            'the choice is appended; every Bricks option is preserved in order'
        );
        self::assertSame('Convermetry', $controls['actions']['options']['convermetry']);
        self::assertSame(['type' => 'repeater'], $controls['fields'], 'unrelated controls are untouched');
    }

    public function testTheChoiceIsNotAppendedTwiceWhenTheFilterRunsAgain(): void
    {
        $bridge   = $this->bridge();
        $controls = ['actions' => ['options' => ['email' => 'Email']]];

        $controls = $bridge->addActionOption($controls);
        $controls = $bridge->addActionOption($controls);

        self::assertIsArray($controls);
        self::assertSame(['email', 'convermetry'], array_keys($controls['actions']['options']));
    }

    /** Another plugin's label for the same slug is left alone rather than replaced. */
    public function testAnExistingChoiceUnderTheSameSlugIsNotOverwritten(): void
    {
        $controls = $this->bridge()->addActionOption([
            'actions' => ['options' => ['convermetry' => 'Someone else']],
        ]);

        self::assertIsArray($controls);
        self::assertSame('Someone else', $controls['actions']['options']['convermetry']);
    }

    public function testTheSetupNoteIsAddedInItsOwnConditionalGroup(): void
    {
        $bridge   = $this->bridge();
        $controls = $bridge->addActionOption(['actions' => ['options' => []]]);
        $groups   = $bridge->addControlGroup(['email' => ['title' => 'Email']]);

        self::assertIsArray($controls);
        self::assertSame(BricksFormsBridge::CONTROL_GROUP, $controls['convermetryInfo']['group']);
        self::assertSame('info', $controls['convermetryInfo']['type'], 'guidance is read-only');
        self::assertStringContainsString('Convermetry', (string) $controls['convermetryInfo']['content']);

        self::assertIsArray($groups);
        self::assertSame(['title' => 'Email'], $groups['email'], "Bricks' own groups survive");
        self::assertSame(
            ['actions', '=', 'convermetry'],
            $groups[BricksFormsBridge::CONTROL_GROUP]['required'],
            'the group appears only when the action is actually selected'
        );
    }

    /**
     * A control set this code does not recognise is returned untouched: quietly
     * rebuilding another product's control would be worse than adding nothing.
     */
    public function testUnrecognisedControlShapesAreLeftAlone(): void
    {
        $bridge = $this->bridge();

        self::assertSame('not-an-array', $bridge->addActionOption('not-an-array'));
        self::assertSame([], $bridge->addActionOption([]), 'no actions control means no choice');
        self::assertSame(
            ['actions' => ['options' => 'unexpected']],
            $bridge->addActionOption(['actions' => ['options' => 'unexpected']])
        );
        self::assertSame('not-an-array', $bridge->addControlGroup('not-an-array'));
    }

    // -------------------------------------------------------- render attributes

    public function testTheFormTagCarriesTheAuthoritativeTrackingKey(): void
    {
        $element = new \stdClass();
        $element->name     = 'form';
        $element->id       = 'ab12cd';
        $element->settings = ['submissionFormName' => 'Contact us'];

        $attributes = $this->bridge()->filterRenderAttributes(
            ['_root' => ['class' => ['brxe-form']]],
            '_root',
            $element
        );

        self::assertIsArray($attributes);
        self::assertSame('bricks:ab12cd', $attributes['_root']['data-cvm-form-key']);
        self::assertSame('Contact us', $attributes['_root']['data-cvm-form-name']);
        self::assertSame(['brxe-form'], $attributes['_root']['class'], "Bricks' own attributes survive");
    }

    /**
     * The rendered key IS the server-side identity. If these ever diverge, a
     * form's browser-observed engagement is attributed to nothing.
     */
    public function testTheRenderedKeyMatchesTheKeyASubmissionIsRecordedUnder(): void
    {
        $element = new \stdClass();
        $element->name     = 'form';
        $element->id       = 'ab12cd';
        $element->settings = [];

        $attributes = $this->bridge()->filterRenderAttributes(['_root' => []], '_root', $element);
        self::assertIsArray($attributes);

        self::assertSame(
            FormProviderRegistry::formKey(
                BricksFormsBridge::PROVIDER_KEY,
                BricksFormsBridge::nativeId(['formId' => 'ab12cd', 'postId' => 91])
            ),
            $attributes['_root']['data-cvm-form-key']
        );
    }

    public function testAnUnnamedFormRendersNoNameAttribute(): void
    {
        $element = new \stdClass();
        $element->name     = 'form';
        $element->id       = 'ab12cd';
        $element->settings = ['submissionFormName' => '   '];

        $attributes = $this->bridge()->filterRenderAttributes(['_root' => []], '_root', $element);

        self::assertIsArray($attributes);
        self::assertArrayNotHasKey('data-cvm-form-name', $attributes['_root'], 'a name is never invented');
    }

    public function testEveryOtherElementAndAttributeGroupIsUntouched(): void
    {
        $bridge = $this->bridge();

        $heading = new \stdClass();
        $heading->name = 'heading';
        $heading->id   = 'zz99zz';

        $form = new \stdClass();
        $form->name     = 'form';
        $form->id       = 'ab12cd';
        $form->settings = [];

        self::assertSame(['_root' => []], $bridge->filterRenderAttributes(['_root' => []], '_root', $heading));
        self::assertSame(['submit' => []], $bridge->filterRenderAttributes(['submit' => []], 'submit', $form));
        self::assertSame('not-an-array', $bridge->filterRenderAttributes('not-an-array', '_root', $form));
        self::assertSame(['_root' => []], $bridge->filterRenderAttributes(['_root' => []], '_root', null));
    }

    // ---------------------------------------------------------------- identity

    /**
     * The identity decision this integration turns on. Bricks reports the post
     * a submission came FROM, which for a header, footer, popup or reused
     * template is not the document that defines the form — so the element id
     * alone is the only thing discovery and submission can both agree on.
     */
    public function testIdentityIsTheElementIdAloneAndNeverTheSubmittingPost(): void
    {
        self::assertSame('ab12cd', BricksFormsBridge::nativeId(['formId' => 'ab12cd', 'postId' => 167]));
        self::assertSame('ab12cd', BricksFormsBridge::identityFor('ab12cd'));

        self::assertSame(
            BricksFormsBridge::nativeId(['formId' => 'ab12cd', 'postId' => 167]),
            BricksFormsBridge::nativeId(['formId' => 'ab12cd', 'postId' => 4102]),
            'one header form submitted from two pages is one form'
        );
    }

    /**
     * The four render situations the identity has to survive: a page form, a
     * form in a reused template, one in a header rendered on every page, and a
     * popup whose submitting post is whatever page opened it.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function renderSituations(): array
    {
        return [
            'page form'         => [['formId' => 'ab12cd', 'postId' => 12]],
            'reused template'   => [['formId' => 'ab12cd', 'postId' => 340]],
            'header on any page' => [['formId' => 'ab12cd', 'postId' => 7]],
            'popup'             => [['formId' => 'ab12cd', 'postId' => 9001]],
            'no post reported'  => [['formId' => 'ab12cd']],
        ];
    }

    /**
     * @dataProvider renderSituations
     * @param array<string, mixed> $submitted
     */
    public function testDiscoveryAndSubmissionAgreeOnTheSameIdentityWhereverTheFormIsRendered(
        array $submitted
    ): void {
        $discovered = BricksFormsBridge::formsIn([
            ['id' => 'ab12cd', 'name' => 'form', 'parent' => 0, 'children' => [], 'settings' => []],
        ]);

        self::assertSame(
            $discovered[0]['native_id'],
            BricksFormsBridge::nativeId($submitted),
            'what the Forms screen configures must be what a submission is recorded under'
        );
    }

    public function testTheSubmittingPostIsKeptAsPageContextNotAsIdentity(): void
    {
        self::assertSame(167, BricksFormsBridge::submittedFromPostId(['formId' => 'ab12cd', 'postId' => 167]));
        self::assertSame(0, BricksFormsBridge::submittedFromPostId(['formId' => 'ab12cd']));
        self::assertSame(0, BricksFormsBridge::submittedFromPostId(['postId' => 'nope']));
    }

    public function testNoFormIdMeansNoIdentity(): void
    {
        self::assertSame('', BricksFormsBridge::nativeId([]));
        self::assertSame('', BricksFormsBridge::nativeId(['postId' => 167]));
        self::assertSame('', BricksFormsBridge::nativeId(['formId' => '   ']));
        self::assertSame('', BricksFormsBridge::nativeId(['formId' => ['array']]));
        self::assertSame('', BricksFormsBridge::identityFor(''));
    }

    public function testExclusionAppliesToTheElementIdentity(): void
    {
        $this->settings = ['bricks:ab12cd' => ['excluded' => true]];

        self::assertTrue(FormSettings::isExcluded('bricks:ab12cd'));
        self::assertFalse(FormSettings::isExcluded('bricks:zz99zz'));
    }

    // ------------------------------------------------------------------ fields

    public function testNativeFieldIdsAndEditorLabelsBothSurviveInFormOrder(): void
    {
        $fields = BricksFormsBridge::buildFields(
            self::formSettings([
                ['id' => '15bc57', 'type' => 'text', 'label' => 'Name'],
                ['id' => '3db633', 'type' => 'email', 'label' => 'Email'],
                ['id' => 'f65f2c', 'type' => 'textarea', 'label' => 'Message'],
            ]),
            [
                'form-field-f65f2c' => 'Thank you for using Bricks!',
                'form-field-15bc57' => 'John Doe',
                'form-field-3db633' => 'john.doe@example.com',
                'postId'            => 167,
                'formId'            => 'yrnkmt',
            ]
        );

        self::assertSame(
            [
                ['id' => '15bc57', 'label' => 'Name', 'value' => 'John Doe'],
                ['id' => '3db633', 'label' => 'Email', 'value' => 'john.doe@example.com'],
                ['id' => 'f65f2c', 'label' => 'Message', 'value' => 'Thank you for using Bricks!'],
            ],
            $fields,
            'order follows the form, not the order values happened to arrive in'
        );
    }

    /**
     * The reason descriptors beat a label-keyed map: three fields labelled
     * "Name" are three fields, not one.
     */
    public function testDuplicateLabelsStayDistinctFields(): void
    {
        $fields = BricksFormsBridge::buildFields(
            self::formSettings([
                ['id' => 'aaa111', 'type' => 'text', 'label' => 'Name'],
                ['id' => 'bbb222', 'type' => 'text', 'label' => 'Name'],
                ['id' => 'ccc333', 'type' => 'text', 'label' => 'Name'],
            ]),
            ['form-field-aaa111' => 'first', 'form-field-bbb222' => 'second', 'form-field-ccc333' => 'third']
        );

        self::assertCount(3, $fields);
        self::assertSame(['aaa111', 'bbb222', 'ccc333'], array_column($fields, 'id'));
        self::assertSame(['first', 'second', 'third'], array_column($fields, 'value'));
    }

    /**
     * A field left blank, and a field whose answer is "0", are answers. Dropping
     * either would silently rewrite what the visitor submitted.
     */
    public function testEmptyAndZeroValuesArePreserved(): void
    {
        $fields = BricksFormsBridge::buildFields(
            self::formSettings([
                ['id' => 'aaa111', 'type' => 'text', 'label' => 'Company'],
                ['id' => 'bbb222', 'type' => 'number', 'label' => 'Employees'],
                ['id' => 'ccc333', 'type' => 'checkbox', 'label' => 'Newsletter'],
            ]),
            ['form-field-aaa111' => '', 'form-field-bbb222' => '0']
        );

        $values = array_column($fields, 'value', 'id');

        self::assertSame('', $values['aaa111'], 'a blank answer is still an answer');
        self::assertSame('0', $values['bbb222'], 'zero is not nothing');
        self::assertSame('', $values['ccc333'], 'an unticked checkbox sends nothing and is reported as blank');
    }

    public function testMultiValueFieldsStayListsAndNeverBecomeTheStringArray(): void
    {
        $fields = BricksFormsBridge::buildFields(
            self::formSettings([
                ['id' => 'aaa111', 'type' => 'checkbox', 'label' => 'Services'],
                ['id' => 'bbb222', 'type' => 'select', 'label' => 'Regions'],
            ]),
            [
                'form-field-aaa111' => ['Tax planning', 'Retirement'],
                'form-field-bbb222' => ['eu' => ['Germany', 'France']],
            ]
        );

        $values = array_column($fields, 'value', 'id');

        self::assertSame(['Tax planning', 'Retirement'], $values['aaa111']);
        self::assertSame(['Germany', 'France'], $values['bbb222'], 'nested values are kept, not dropped');
    }

    /**
     * THE SAFETY PROPERTY. Bricks field ids are opaque — '4f2a9c' says nothing —
     * so Convermetry's name-based redaction cannot see a password. The field
     * TYPE can, and is the only thing that can.
     */
    public function testPasswordFieldsAreNeverRecorded(): void
    {
        $fields = BricksFormsBridge::buildFields(
            self::formSettings([
                ['id' => 'aaa111', 'type' => 'email', 'label' => 'Email'],
                ['id' => '4f2a9c', 'type' => 'password', 'label' => 'Choose a password'],
                ['id' => 'bbb222', 'type' => 'password'],
            ]),
            [
                'form-field-aaa111' => 'ada@example.test',
                'form-field-4f2a9c' => 'hunter2-correct-horse',
                'form-field-bbb222' => 'another-secret',
            ]
        );

        self::assertSame(['aaa111'], array_column($fields, 'id'));
        self::assertStringNotContainsString('hunter2', json_encode($fields, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('another-secret', json_encode($fields, JSON_THROW_ON_ERROR));
    }

    /**
     * The same guarantee stated the other way: a submitted value whose type
     * cannot be established is not recorded, because an unknown type could be a
     * password.
     */
    public function testSubmittedValuesWithNoFieldDefinitionAreNotRecorded(): void
    {
        $fields = BricksFormsBridge::buildFields(
            self::formSettings([['id' => 'aaa111', 'type' => 'text', 'label' => 'Name']]),
            ['form-field-aaa111' => 'Ada', 'form-field-unknown' => 'who knows what this is']
        );

        self::assertSame(['aaa111'], array_column($fields, 'id'));
    }

    public function testDisplayOnlyAndHoneypotFieldsAreNotSubmittedData(): void
    {
        $fields = BricksFormsBridge::buildFields(
            self::formSettings([
                ['id' => 'aaa111', 'type' => 'text', 'label' => 'Name'],
                ['id' => 'bbb222', 'type' => 'html', 'label' => 'Notice'],
                ['id' => 'ccc333', 'type' => 'rememberme', 'label' => 'Remember me'],
                ['id' => 'ddd444', 'type' => 'text', 'label' => 'Website', 'honeypot' => true],
            ]),
            [
                'form-field-aaa111' => 'Ada',
                'form-field-bbb222' => '<p>markup</p>',
                'form-field-ccc333' => '1',
                'form-field-ddd444' => 'spam-bot-filled-this',
            ]
        );

        self::assertSame(['aaa111'], array_column($fields, 'id'));
    }

    /**
     * Transport metadata and Convermetry's own correlation values arrive as
     * TOP-LEVEL keys, never as form-field-<id>, so reading only the prefixed
     * keys excludes every one of them by construction.
     */
    public function testTransportMetadataCaptchaTokensAndInternalFieldsAreAllExcluded(): void
    {
        $fields = BricksFormsBridge::buildFields(
            self::formSettings([['id' => 'aaa111', 'type' => 'email', 'label' => 'Email']]),
            [
                'form-field-aaa111'    => 'ada@example.test',
                'postId'               => 167,
                'formId'               => 'yrnkmt',
                'referrer'             => 'https://example.test/contact',
                'action'               => 'bricks_form_submit',
                'nonce'                => 'abc123',
                'g-recaptcha-response' => 'recaptcha-token',
                'h-captcha-response'   => 'hcaptcha-token',
                'cf-turnstile-response' => 'turnstile-token',
                'cvm_conversion_id'    => 'c0123456789abcdef',
                'cvm_session_id'       => str_repeat('a', 32),
                'cvm_context'          => '{"utm_source":"newsletter"}',
            ]
        );

        $encoded = json_encode($fields, JSON_THROW_ON_ERROR);

        self::assertSame(['aaa111'], array_column($fields, 'id'));
        foreach (['yrnkmt', 'recaptcha-token', 'hcaptcha-token', 'turnstile-token', 'cvm_', 'abc123'] as $leak) {
            self::assertStringNotContainsString($leak, $encoded);
        }
    }

    /** Belt and braces: even if one somehow arrived as a field, it is stripped. */
    public function testInternalCorrelationFieldsNeverSurviveNormalization(): void
    {
        $descriptors = BricksFormsBridge::buildFields(
            self::formSettings([
                ['id' => 'cvm_conversion_id', 'type' => 'hidden'],
                ['id' => 'aaa111', 'type' => 'email', 'label' => 'Email'],
            ]),
            ['form-field-cvm_conversion_id' => 'c123', 'form-field-aaa111' => 'ada@example.test']
        );

        $normalized = \Convermetry\Forms\SubmissionFields::normalize($descriptors);

        self::assertSame(['aaa111'], array_column($normalized, 'id'));
    }

    /**
     * Uploads follow the convention every other provider follows: the URL the
     * form plugin chose to expose travels, the physical path never does.
     */
    public function testUploadsCarryOnlyTheirUrlsAndNeverAFilesystemPath(): void
    {
        $fields = BricksFormsBridge::buildFields(
            self::formSettings([['id' => 'sajbjc', 'type' => 'file', 'label' => 'Your CV']]),
            ['form-field-sajbjc' => 'ignored'],
            [
                'form-field-sajbjc' => [
                    ['file' => '/var/www/html/wp-content/uploads/bricks/cv.pdf', 'url' => 'https://example.test/cv.pdf'],
                    ['file' => '/tmp/php7A2B', 'url' => 'https://example.test/cover.docx'],
                ],
            ]
        );

        self::assertSame(
            [['id' => 'sajbjc', 'label' => 'Your CV', 'value' => [
                'https://example.test/cv.pdf',
                'https://example.test/cover.docx',
            ]]],
            $fields
        );
        self::assertStringNotContainsString('/var/www', json_encode($fields, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('/tmp/', json_encode($fields, JSON_THROW_ON_ERROR));
    }

    /**
     * A media-library Image or Gallery field picks an existing attachment rather
     * than uploading one, so there is no uploaded-files entry. The submitted
     * value is used — narrowed to what is unambiguously safe to publish.
     */
    public function testMediaPickerValuesFallBackToUrlsAndAttachmentIdsOnly(): void
    {
        $fields = BricksFormsBridge::buildFields(
            self::formSettings([
                ['id' => 'aaa111', 'type' => 'image', 'label' => 'Logo'],
                ['id' => 'bbb222', 'type' => 'gallery', 'label' => 'Photos'],
                ['id' => 'ccc333', 'type' => 'file', 'label' => 'Attachment'],
            ]),
            [
                'form-field-aaa111' => '4821',
                'form-field-bbb222' => ['https://example.test/a.jpg', '/var/www/html/uploads/secret.jpg', ''],
                'form-field-ccc333' => '/tmp/php9Z8Y',
            ],
            []
        );

        $values = array_column($fields, 'value', 'id');

        self::assertSame(['4821'], $values['aaa111']);
        self::assertSame(['https://example.test/a.jpg'], $values['bbb222'], 'a bare path is dropped, not published');
        self::assertSame([], $values['ccc333']);
    }

    public function testMalformedDefinitionsAndValuesAreCoercedWithoutNotices(): void
    {
        $fields = BricksFormsBridge::buildFields(
            ['fields' => [
                ['id' => 'aaa111', 'type' => 'checkbox', 'label' => 'Flag'],
                ['id' => '', 'type' => 'text'],
                'not-an-array',
                ['type' => 'text', 'label' => 'No id'],
                ['id' => 'bbb222'],
                ['id' => 'ccc333', 'type' => 'text', 'label' => ['nested']],
            ]],
            [
                'form-field-aaa111' => true,
                'form-field-bbb222' => new \stdClass(),
                'form-field-ccc333' => 7,
            ]
        );

        self::assertSame(['aaa111', 'bbb222', 'ccc333'], array_column($fields, 'id'));
        self::assertSame('1', $fields[0]['value']);
        self::assertSame('', $fields[1]['value']);
        self::assertSame('7', $fields[2]['value']);
        self::assertSame('', $fields[2]['label'], 'a non-scalar label is reported as blank, not invented');
    }

    public function testSettingsWithNoFieldDefinitionsProduceNoFieldData(): void
    {
        self::assertSame([], BricksFormsBridge::buildFields([], ['form-field-aaa111' => 'Ada']));
        self::assertSame([], BricksFormsBridge::buildFields(['fields' => 'nope'], ['form-field-aaa111' => 'Ada']));
    }

    // -------------------------------------------------------------- form names

    public function testTheFormNameComesFromBricksOwnSettingWithADeterministicFallback(): void
    {
        self::assertSame('Newsletter', BricksFormsBridge::formName(['submissionFormName' => 'Newsletter'], 'fb'));
        self::assertSame('Sidebar CTA', BricksFormsBridge::formName(['label' => 'Sidebar CTA'], 'fb'));
        self::assertSame(
            'Newsletter',
            BricksFormsBridge::formName(['submissionFormName' => 'Newsletter', 'label' => 'Sidebar CTA'], 'fb'),
            "Bricks' own Form name wins over the builder's element label"
        );
        self::assertSame('fb', BricksFormsBridge::formName(['submissionFormName' => '  '], 'fb'));
        self::assertSame('fb', BricksFormsBridge::formName([], 'fb'));
    }

    // ------------------------------------------------------- outcome semantics

    /**
     * Background mode is the default and must never fail a visitor's form:
     * nothing has been delivered yet, and Convermetry retries on its own.
     */
    public function testBackgroundModeAlwaysReportsSuccessToBricks(): void
    {
        $failed = new SubmissionResult(
            ok: false,
            msg: 'nope',
            failedDeliveries: [['url' => 'https://x.test', 'endpoint_url' => 'https://x.test', 'headers' => [], 'body' => '', 'label' => '']]
        );

        self::assertTrue(BricksFormsBridge::outcomeFor($failed, false)['ok']);
        self::assertTrue(BricksFormsBridge::outcomeFor(new SubmissionResult(ok: true, queued: true), false)['ok']);
    }

    /**
     * A Bricks action that reports a failure HALTS every later action on the
     * form, so a spurious failure costs the site owner their confirmation email
     * and the visitor their redirect. Only a genuine dispatch failure qualifies.
     */
    public function testSynchronousModeFailsOnlyOnRealDeliveryFailures(): void
    {
        $excluded = new SubmissionResult(ok: false, msg: 'This form is excluded from Convermetry by the current settings.');
        $declined = new SubmissionResult(ok: true, submissionId: '', conversionId: '', queued: false);
        $noSend   = new SubmissionResult(ok: true, submissionId: 's1', conversionId: 'c1', queued: false);

        self::assertTrue(BricksFormsBridge::outcomeFor($excluded, true)['ok'], 'an exclusion must not fail the form');
        self::assertTrue(BricksFormsBridge::outcomeFor($declined, true)['ok'], 'a declined recording must not fail the form');
        self::assertTrue(BricksFormsBridge::outcomeFor($noSend, true)['ok'], 'no endpoints configured is not a failure');

        $dispatchFailed = new SubmissionResult(
            ok: false,
            submissionId: 's1',
            conversionId: 'c1',
            msg: 'There was an issue submitting the form data through the webhook.',
            failedDeliveries: [['url' => 'https://x.test', 'endpoint_url' => 'https://x.test', 'headers' => [], 'body' => '{}', 'label' => 'CRM']]
        );

        self::assertFalse(BricksFormsBridge::outcomeFor($dispatchFailed, true)['ok']);
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

        $message = BricksFormsBridge::outcomeFor($result, true)['message'];

        self::assertSame('There was an issue submitting the form data through the webhook.', $message);
        self::assertStringNotContainsString('ada@example.test', $message);
        self::assertStringNotContainsString('crm.example.test', $message);
    }

    // ------------------------------------------------------------- submissions

    /**
     * The path a real submission takes: Bricks dispatches the named action, and
     * the callback registered for it is what runs.
     */
    public function testTheRegisteredActionCallbackRecordsThroughTheSharedPipeline(): void
    {
        $this->settings = ['bricks:yrnkmt' => ['excluded' => true]];
        $this->bridge()->register();

        $callback = $this->hooks[BricksFormsBridge::ACTION_HOOK][0];
        $form     = new BricksForm(
            self::formSettings([['id' => '15bc57', 'type' => 'text', 'label' => 'Name']]),
            ['form-field-15bc57' => 'John Doe', 'formId' => 'yrnkmt', 'postId' => 167]
        );

        $callback($form);

        self::assertSame(
            ['bricks'],
            $this->pipelineEntries,
            'the submission must reach the shared pipeline, which owns the exclusion rule'
        );
    }

    /**
     * An excluded form records nothing — and must still let the visitor's
     * submission (and every later Bricks action) succeed.
     *
     * Run in 'show_error' mode deliberately: in the default background mode the
     * outcome is success whatever happens, so only the synchronous mode can tell
     * "excluded" apart from "delivery failed".
     */
    public function testAnExcludedBricksFormIsSkippedWithoutFailingTheVisitor(): void
    {
        $this->settings = ['bricks:yrnkmt' => ['excluded' => true]];
        $this->showErrorMode();

        $form = new BricksForm(
            self::formSettings([['id' => '15bc57', 'type' => 'text', 'label' => 'Name']]),
            ['form-field-15bc57' => 'John Doe', 'formId' => 'yrnkmt']
        );

        $this->bridge()->handleSubmission($form);

        self::assertSame(['bricks'], $this->pipelineEntries);
        self::assertSame([], $form->results, 'no result is set, so Bricks runs every later action normally');
    }

    /**
     * A success sets nothing: silence is how Bricks is told the action passed,
     * and setting a result would replace the form's own success message.
     */
    public function testASuccessfulCaptureSetsNoResult(): void
    {
        $this->showErrorMode();
        self::declineRecording();

        $form = new BricksForm(
            self::formSettings([['id' => '15bc57', 'type' => 'text', 'label' => 'Name']]),
            ['form-field-15bc57' => 'John Doe', 'formId' => 'yrnkmt']
        );

        $this->bridge()->handleSubmission($form);

        self::assertSame([], $form->results);
    }

    public function testASubmissionWithNoIdentifiableFormIsSkippedNotFailed(): void
    {
        $form = new BricksForm(self::formSettings([]), ['postId' => 167]);

        $this->bridge()->handleSubmission($form);

        self::assertSame([], $this->pipelineEntries, 'nothing is recorded under an identity that cannot be matched');
        self::assertSame([], $form->results, 'and the visitor is not failed for it');
    }

    /**
     * A Bricks that renamed or broke an accessor must degrade to "records what
     * is available", never to a fatal error on a visitor's submission.
     */
    public function testAFormObjectThatCannotAnswerIsHandledWithoutFatalling(): void
    {
        self::declineRecording();

        $bridge = $this->bridge();

        $bridge->handleSubmission(new BrokenBricksForm());
        $bridge->handleSubmission('not-an-object');
        $bridge->handleSubmission(null);
        $bridge->handleSubmission(new \stdClass());

        self::assertSame(['bricks'], $this->pipelineEntries, 'the one object with a usable form id still records');
    }

    /**
     * Lead capture must not depend on webhook delivery. The pipeline records the
     * submission and the conversion before it looks at endpoints — so what this
     * integration has to get right is never adding a gate of its own in front of
     * that, which is a property of this code rather than of one run through it.
     */
    public function testTheBricksPathNeverGatesCaptureOnDeliverySettings(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Forms/Bricks/BricksFormsBridge.php');

        // Strip the docblocks: they legitimately discuss delivery settings.
        $code = (string) preg_replace('~/\*\*.*?\*/~s', '', $source);

        foreach (['webhooksActive', 'formEndpoints', 'analyticsEndpoints'] as $gate) {
            self::assertStringNotContainsString(
                $gate,
                $code,
                'the Bricks integration must not decide whether to record from delivery configuration'
            );
        }

        self::assertStringContainsString('$this->service->record(', $code, 'it records through the shared pipeline');
    }

    /**
     * There is exactly one capture path, and it is the selected action. An
     * unconditional response hook would record forms whose owner never opted in,
     * and would record the ones who did a second time.
     */
    public function testThereIsNoUnconditionalSubmissionHook(): void
    {
        $this->bridge()->register();

        self::assertSame(
            [BricksFormsBridge::ACTION_HOOK],
            array_keys(array_filter(
                $this->hooks,
                static fn(string $hook): bool => str_starts_with($hook, 'bricks/form/'),
                ARRAY_FILTER_USE_KEY
            )),
            'the only bricks/form/* hook listened to is the named custom action'
        );
    }
}
