<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\Atomic\AtomicFormsBridge;
use Convermetry\Forms\Providers\ElementorAtomicProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../stubs/elementor-atomic.php';

/**
 * Finding Atomic forms in a site's Elementor content.
 *
 * Discovery is what makes a form configurable, and it runs whether or not the
 * Convermetry action has been added to that form — an administrator has to be
 * able to see a form before wiring it up. It therefore proves nothing about
 * capture, which is why the Forms screen says so beside these rows and why no
 * test here implies otherwise.
 *
 * The tree walk is exercised directly rather than through the postmeta query:
 * this suite has no database and deliberately owns no mock of one, and what is
 * worth pinning is which nodes count as forms, what identity each gets, and
 * what name each is listed under.
 */
final class AtomicDiscoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_text_field')->alias(static fn($value) => trim((string) $value));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * One Atomic form root, as Elementor stores it.
     *
     * @param array<string, mixed> $settings
     * @param array<int, mixed>    $children
     * @return array<string, mixed>
     */
    private static function form(string $id, array $settings = [], array $children = []): array
    {
        return ['id' => $id, 'elType' => 'e-form', 'settings' => $settings, 'elements' => $children];
    }

    public function testATypedFormNameIsRead(): void
    {
        $forms = ElementorAtomicProvider::formsIn(
            [self::form('e1a2b3c', ['form-name' => ['$$type' => 'string', 'value' => 'Contact']])],
            42
        );

        self::assertSame([['native_id' => '42:e1a2b3c', 'name' => 'Contact']], $forms);
    }

    public function testAPlainFormNameIsRead(): void
    {
        $forms = ElementorAtomicProvider::formsIn([self::form('e1a2b3c', ['form-name' => 'Newsletter'])], 42);

        self::assertSame('Newsletter', $forms[0]['name']);
    }

    /**
     * No setting at all means Elementor resolves its own prop default, and that
     * default is what the form then posts — so the list has to show it rather
     * than a blank.
     */
    public function testAFormWithNoNameSettingUsesElementorsOwnDefault(): void
    {
        $forms = ElementorAtomicProvider::formsIn([self::form('e1a2b3c')], 42);

        self::assertSame(AtomicFormsBridge::formNameDefault(), $forms[0]['name']);
        self::assertSame('Form', $forms[0]['name']);
    }

    /**
     * An explicitly emptied name is different from an absent one: Elementor
     * omits data-form-name entirely, so there is no name to show and the element
     * id is the only honest label. Identity is unaffected either way.
     */
    public function testAnExplicitlyEmptyNameFallsBackToTheElementId(): void
    {
        $forms = ElementorAtomicProvider::formsIn(
            [self::form('e1a2b3c', ['form-name' => ['$$type' => 'string', 'value' => '']])],
            42
        );

        self::assertSame('e1a2b3c', $forms[0]['name']);
        self::assertSame('42:e1a2b3c', $forms[0]['native_id']);
    }

    public function testADisabledOrDynamicNameDoesNotInventALabel(): void
    {
        $forms = ElementorAtomicProvider::formsIn(
            [self::form('e1a2b3c', ['form-name' => ['disabled' => true, 'value' => 'Hidden']])],
            42
        );

        self::assertSame('e1a2b3c', $forms[0]['name']);
    }

    public function testFormsNestedInsideContainersAreFound(): void
    {
        $tree = [
            [
                'id'       => 'container1',
                'elType'   => 'e-div-block',
                'elements' => [
                    ['id' => 'inner', 'elType' => 'e-flexbox', 'elements' => [
                        self::form('deep1', ['form-name' => 'Deep form']),
                    ]],
                ],
            ],
        ];

        $forms = ElementorAtomicProvider::formsIn($tree, 42);

        self::assertSame([['native_id' => '42:deep1', 'name' => 'Deep form']], $forms);
    }

    /** A future registration as a widget type must still be discovered. */
    public function testAFormStoredAsAWidgetTypeIsAlsoFound(): void
    {
        $forms = ElementorAtomicProvider::formsIn(
            [['id' => 'w1', 'widgetType' => 'e-form', 'settings' => ['form-name' => 'Widgetised']]],
            42
        );

        self::assertSame('42:w1', $forms[0]['native_id']);
    }

    public function testFieldElementsAndClassicFormWidgetsAreNotAtomicForms(): void
    {
        $tree = [
            ['id' => 'f1', 'elType' => 'widget', 'widgetType' => 'form', 'settings' => ['form_name' => 'Classic']],
            ['id' => 'i1', 'elType' => 'widget', 'widgetType' => 'e-form-input', 'settings' => []],
            ['id' => 'h1', 'elType' => 'e-heading', 'settings' => []],
        ];

        self::assertSame([], ElementorAtomicProvider::formsIn($tree, 42));
    }

    /**
     * The defect a name-keyed identity would reintroduce: two forms left at the
     * default name are two forms, and each keeps its own configuration.
     */
    public function testSameNamedFormsStayDistinct(): void
    {
        $forms = ElementorAtomicProvider::formsIn(
            [self::form('e1a2b3c', ['form-name' => 'Contact']), self::form('e9z8y7x', ['form-name' => 'Contact'])],
            42
        );

        self::assertCount(2, $forms);
        self::assertSame(['42:e1a2b3c', '42:e9z8y7x'], array_column($forms, 'native_id'));
    }

    /**
     * The same element id in two documents — a template exported into another
     * site, a duplicated library item — is two forms, not one shared one.
     */
    public function testTheSameElementIdInTwoDocumentsIsTwoForms(): void
    {
        $onPage     = ElementorAtomicProvider::formsIn([self::form('e1a2b3c', ['form-name' => 'Contact'])], 42);
        $onTemplate = ElementorAtomicProvider::formsIn([self::form('e1a2b3c', ['form-name' => 'Contact'])], 99);

        self::assertNotSame($onPage[0]['native_id'], $onTemplate[0]['native_id']);
    }

    /** Renaming a form must not move its settings: identity is the id, not the name. */
    public function testRenamingAFormPreservesItsIdentity(): void
    {
        $before = ElementorAtomicProvider::formsIn([self::form('e1a2b3c', ['form-name' => 'Contact'])], 42);
        $after  = ElementorAtomicProvider::formsIn([self::form('e1a2b3c', ['form-name' => 'Get in touch'])], 42);

        self::assertSame($before[0]['native_id'], $after[0]['native_id']);
        self::assertNotSame($before[0]['name'], $after[0]['name']);
    }

    /**
     * A form with no element id cannot be keyed, matched, or configured, so it
     * is left out rather than listed with settings that could never apply.
     */
    public function testAFormWithNoElementIdIsNotListed(): void
    {
        self::assertSame([], ElementorAtomicProvider::formsIn([['elType' => 'e-form', 'settings' => []]], 42));
    }

    public function testMalformedTreesAreSurvived(): void
    {
        $tree = ['not-an-array', 42, null, ['elType' => 'e-form', 'id' => 'ok1', 'elements' => 'not-an-array']];

        self::assertSame([['native_id' => '42:ok1', 'name' => 'Form']], ElementorAtomicProvider::formsIn($tree, 42));
    }

    public function testTheSameFormFoundTwiceInOneDocumentIsListedOnce(): void
    {
        $forms = ElementorAtomicProvider::formsIn(
            [self::form('e1a2b3c', ['form-name' => 'Contact']), self::form('e1a2b3c', ['form-name' => 'Contact'])],
            42
        );

        self::assertCount(1, $forms);
    }

    /**
     * Template-based forms live in elementor_library, a PRIVATE post type that
     * get_posts() with post_type 'any' silently skips — which is why discovery
     * reads postmeta directly, exactly as the classic provider does. Pinned
     * against the source because proving it needs a database.
     */
    public function testDiscoveryReadsPostmetaDirectlySoTemplatesAreNotSkipped(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../src/Forms/Providers/ElementorAtomicProvider.php');

        // Docblocks are stripped first: the class comment explains the WP_Query
        // trap by name, and matching its own explanation would be no test.
        $code = (string) preg_replace('~/\*\*.*?\*/~s', '', $source);

        self::assertStringContainsString('_elementor_data', $code);
        self::assertStringContainsString('{$wpdb->postmeta}', $code);
        self::assertStringNotContainsString('get_posts(', $code);
        self::assertStringNotContainsString('WP_Query', $code);
    }
}
