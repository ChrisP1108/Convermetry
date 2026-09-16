<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\Bricks\BricksFormsBridge;
use PHPUnit\Framework\TestCase;

/**
 * Finding native Bricks forms in a site's Bricks content.
 *
 * Discovery is what makes a form CONFIGURABLE, and it runs whether or not the
 * Convermetry action has been selected on that form — an administrator has to
 * be able to see a form before wiring it up. It therefore proves nothing about
 * capture, which is why the Forms screen says so beside these rows and why no
 * test here implies otherwise.
 *
 * Bricks stores each content area as a FLAT array of element records in post
 * meta (`_bricks_page_content_2`, `_bricks_page_header_2`,
 * `_bricks_page_footer_2`); parent/child relationships are id references, not
 * nesting. So the interesting decisions are which records count as native form
 * elements, what identity each gets, what name it is listed under, and what is
 * deliberately NOT listed. Those are exercised directly rather than through the
 * postmeta query: this suite has no database and deliberately owns no mock of
 * one.
 */
final class BricksDiscoveryTest extends TestCase
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
     * One element record, as Bricks stores it in a content area.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function element(string $id, string $name, array $overrides = []): array
    {
        return array_merge(
            ['id' => $id, 'name' => $name, 'parent' => 0, 'children' => [], 'settings' => []],
            $overrides
        );
    }

    public function testANativeFormElementIsFoundInAFlatContentArea(): void
    {
        $forms = BricksFormsBridge::formsIn([
            self::element('aaa111', 'section', ['children' => ['bbb222']]),
            self::element('bbb222', 'form', [
                'parent'   => 'aaa111',
                'settings' => ['submissionFormName' => 'Contact', 'fields' => []],
            ]),
        ]);

        self::assertSame([['native_id' => 'bbb222', 'name' => 'Contact']], $forms);
    }

    /** Only the native Form element. Nothing else on the page is a form. */
    public function testOnlyTheNativeFormElementIsMatched(): void
    {
        $forms = BricksFormsBridge::formsIn([
            self::element('aaa111', 'heading'),
            self::element('bbb222', 'search'),
            self::element('ccc333', 'filter-submit'),
            self::element('ddd444', 'woocommerce-account-login-form'),
            self::element('eee555', 'form'),
        ]);

        self::assertSame(['eee555'], array_column($forms, 'native_id'));
    }

    /**
     * The identity is the element id alone, so the same form found in several
     * documents — a template reused across the site — is ONE row, matching the
     * one identity its submissions arrive under.
     */
    public function testTheSameFormFoundTwiceIsOneRow(): void
    {
        $forms = [];

        BricksFormsBridge::collectForms([self::element('bbb222', 'form', [
            'settings' => ['submissionFormName' => 'Contact'],
        ])], $forms);
        BricksFormsBridge::collectForms([self::element('bbb222', 'form', [
            'settings' => ['submissionFormName' => 'Contact'],
        ])], $forms);

        self::assertCount(1, $forms);
    }

    /**
     * Two DIFFERENT forms that share a display name stay two rows. Identity is
     * never the name: renaming a form must not orphan its configuration, and two
     * forms called "Contact" must not share one.
     */
    public function testTwoFormsWithTheSameNameStayTwoForms(): void
    {
        $forms = BricksFormsBridge::formsIn([
            self::element('aaa111', 'form', ['settings' => ['submissionFormName' => 'Contact']]),
            self::element('bbb222', 'form', ['settings' => ['submissionFormName' => 'Contact']]),
        ]);

        self::assertSame(['aaa111', 'bbb222'], array_column($forms, 'native_id'));
        self::assertSame(['Contact', 'Contact'], array_column($forms, 'name'));
    }

    public function testNamesComeFromBricksOwnSettingThenTheElementLabel(): void
    {
        $forms = BricksFormsBridge::formsIn([
            self::element('aaa111', 'form', ['settings' => ['submissionFormName' => 'Newsletter']]),
            self::element('bbb222', 'form', ['label' => 'Sidebar CTA']),
            self::element('ccc333', 'form', [
                'label'    => 'Ignored',
                'settings' => ['submissionFormName' => 'Quote request'],
            ]),
        ]);

        self::assertSame(
            ['Newsletter', 'Sidebar CTA', 'Quote request'],
            array_column($forms, 'name')
        );
    }

    /**
     * An unnamed form gets a DETERMINISTIC label built from its own id, not a
     * shared placeholder — so two unnamed forms are told apart on the Forms
     * screen, and a row keeps the same label between discoveries.
     */
    public function testUnnamedFormsGetADeterministicPerFormFallbackName(): void
    {
        $forms = BricksFormsBridge::formsIn([
            self::element('aaa111', 'form'),
            self::element('bbb222', 'form', ['settings' => ['submissionFormName' => '   ']]),
        ]);

        self::assertSame(['Bricks form aaa111', 'Bricks form bbb222'], array_column($forms, 'name'));
        self::assertSame(
            $forms[0]['name'],
            BricksFormsBridge::formsIn([self::element('aaa111', 'form')])[0]['name'],
            'the same form is listed under the same name every time'
        );
    }

    /**
     * A form inside a Bricks COMPONENT is not listed. Bricks stores component
     * definitions in the `bricks_components` option rather than in a document's
     * content areas, so the record left behind here carries a 'cid' reference
     * and none of the form's own settings — and the identity such a form submits
     * under has not been verified. Offering configuration under an unverified
     * identity would be a row that looks configured and applies to nothing.
     *
     * Capture is unaffected: the action still runs, and the submission is still
     * recorded under whatever form id Bricks reports.
     */
    public function testComponentInstancesAreNotListed(): void
    {
        $forms = BricksFormsBridge::formsIn([
            self::element('aaa111', 'form', ['cid' => 'comp42']),
            self::element('bbb222', 'form'),
        ]);

        self::assertSame(['bbb222'], array_column($forms, 'native_id'));
    }

    /**
     * A record with no id is a hand-edited or corrupted document. There is no
     * identity to key settings by and none to match a submission against, so
     * listing it would offer configuration that could never apply.
     */
    public function testMalformedRecordsAreSkippedWithoutBreakingDiscovery(): void
    {
        $forms = BricksFormsBridge::formsIn([
            'not-an-array',
            ['name' => 'form'],
            ['id' => '', 'name' => 'form'],
            ['id' => '   ', 'name' => 'form'],
            ['id' => ['nested'], 'name' => 'form'],
            ['id' => 'aaa111'],
            ['id' => 'bbb222', 'name' => 'form'],
            42,
            null,
        ]);

        self::assertSame(['bbb222'], array_column($forms, 'native_id'));
    }

    public function testAnEmptyOrUnrecognisedContentAreaFindsNothing(): void
    {
        self::assertSame([], BricksFormsBridge::formsIn([]));
        self::assertSame([], BricksFormsBridge::formsIn([self::element('aaa111', 'section')]));
    }

    /**
     * The three content areas a Bricks document stores its elements in. Header
     * and footer are where site-wide forms live, and a discovery that read only
     * the content area would miss every one of them.
     */
    public function testAllThreeContentAreasAreCovered(): void
    {
        self::assertSame(
            ['_bricks_page_content_2', '_bricks_page_header_2', '_bricks_page_footer_2'],
            BricksFormsBridge::CONTENT_META_KEYS
        );
    }

    /**
     * Forms found across several content areas of several documents collapse to
     * one row per element id — the shape the provider's postmeta walk produces.
     */
    public function testFormsFromEveryAreaAndDocumentCollapseByElementId(): void
    {
        $forms = [];

        // A header template, rendered on every page.
        BricksFormsBridge::collectForms([self::element('hdr001', 'form', [
            'settings' => ['submissionFormName' => 'Newsletter'],
        ])], $forms);

        // A page with its own form, plus the same header form found again while
        // walking a second document.
        BricksFormsBridge::collectForms([
            self::element('pge002', 'form', ['settings' => ['submissionFormName' => 'Contact']]),
            self::element('hdr001', 'form', ['settings' => ['submissionFormName' => 'Newsletter']]),
        ], $forms);

        // A footer template.
        BricksFormsBridge::collectForms([self::element('ftr003', 'form')], $forms);

        self::assertSame(['hdr001', 'pge002', 'ftr003'], array_keys($forms));
        self::assertSame(
            ['hdr001', 'pge002', 'ftr003'],
            array_column(array_values($forms), 'native_id')
        );
    }

    /**
     * The one thing discovery and submission must never disagree about. A row
     * whose native id is not what a submission from that form arrives under is a
     * row whose configuration silently applies to nothing.
     */
    public function testEveryDiscoveredIdentityIsOneASubmissionCanArriveUnder(): void
    {
        $forms = BricksFormsBridge::formsIn([
            self::element('aaa111', 'form'),
            self::element('bbb222', 'form', ['settings' => ['submissionFormName' => 'Contact']]),
        ]);

        foreach ($forms as $form) {
            self::assertSame(
                $form['native_id'],
                BricksFormsBridge::nativeId(['formId' => $form['native_id'], 'postId' => 167]),
                'discovery and submission must agree on the identity'
            );
        }
    }
}
