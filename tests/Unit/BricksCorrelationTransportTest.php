<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\Bricks\BricksFormsBridge;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Tracking\Correlation;
use PHPUnit\Framework\TestCase;

/**
 * How session attribution reaches the server on a Bricks form, and how the
 * browser's own view of that submission is kept from double-counting it.
 *
 * Bricks submits over AJAX and publishes three document-level events for exactly
 * this purpose — bricks/form/submit (after the form data is prepared, BEFORE the
 * request is sent), bricks/form/success and bricks/form/error — so the tracker
 * sets the three correlation values on the prepared formData rather than
 * wrapping fetch or guessing at an endpoint. They travel as TOP-LEVEL entries,
 * never as form-field-<id> values, which is what keeps them out of Bricks' own
 * Form Submissions, out of a lead, an export, a notification and a payload.
 *
 * WHAT IS NOT PROVEN HERE. The JavaScript side is asserted against the tracker's
 * source, because this is a PHP unit suite with no browser and no Bricks. It
 * proves the code says what it must say; it cannot prove a real Bricks
 * submission in a real browser carries the fields, or that mutating
 * event.detail.formData reaches the request Bricks actually sends. That is a
 * live-site check, and it is listed as unverified in the README.
 */
final class BricksCorrelationTransportTest extends TestCase
{
    private const string TRACKER = __DIR__ . '/../../assets/js/tracker.js';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_text_field')->alias(static fn($value) => trim((string) $value));
        Functions\when('wp_generate_uuid4')->justReturn('11111111-2222-3333-4444-555555555555');
        Functions\when('wp_rand')->justReturn(7);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private static function tracker(): string
    {
        return (string) file_get_contents(self::TRACKER);
    }

    /**
     * The tracker source with block comments removed, so an assertion cannot be
     * satisfied by a comment that merely discusses the thing.
     */
    private static function trackerCode(): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', self::tracker());
    }

    /**
     * Executable JavaScript only: whole-line // comments removed as well as
     * block comments.
     *
     * Every "must NOT contain" assertion below runs against this. Without it an
     * assertion that the tracker never reads a value would be defeated by the
     * comment explaining why it never reads that value — which is exactly
     * backwards. Only comments that occupy a whole line are stripped, so a
     * https:// inside a string is untouched.
     */
    private static function executableCode(string $code): string
    {
        return (string) preg_replace('~^[ \t]*//[^\n]*$~m', '', $code);
    }

    /**
     * The Bricks section of the tracker, from its first documented event
     * listener to the end of the error listener.
     */
    private static function bricksBlock(): string
    {
        $code  = self::trackerCode();
        $start = strpos($code, "addEventListener('bricks/form/submit'");
        self::assertIsInt($start, 'the Bricks submit listener must exist');

        $end = strpos($code, "addEventListener('bricks/form/error'", $start);
        self::assertIsInt($end, 'the Bricks error listener must exist');

        return substr($code, $start, ($end - $start) + 2000);
    }

    // ---------------------------------------------------------------- transport

    public function testTheTrackerUsesBricksOwnDocumentedSubmitEvent(): void
    {
        $code = self::trackerCode();

        self::assertStringContainsString("'bricks/form/submit'", $code);
        self::assertStringContainsString("'bricks/form/success'", $code);
        self::assertStringContainsString("'bricks/form/error'", $code);
        self::assertStringContainsString(
            'detail.formData',
            $code,
            'the documented event payload is what carries the request'
        );
    }

    /** All three values, or attribution is partial and the conversion cannot join. */
    public function testAllThreeCorrelationValuesAreSetOnThePreparedFormData(): void
    {
        $block = self::bricksBlock();

        foreach (
            [Correlation::FIELD_CONVERSION, Correlation::FIELD_SESSION, Correlation::FIELD_CONTEXT] as $field
        ) {
            self::assertStringContainsString(
                'FIELD_' . strtoupper(str_replace('cvm_', '', str_replace('_id', '', $field))),
                $block,
                $field . ' must travel with a Bricks submission'
            );
        }

        self::assertStringContainsString('formData.set(FIELD_CONVERSION, token)', $block);
        self::assertStringContainsString('formData.set(FIELD_SESSION, sessionId())', $block);
        self::assertStringContainsString('formData.set(FIELD_CONTEXT,', $block);
    }

    /**
     * The values are Convermetry's, not the visitor's. Writing one into a
     * form-field-<id> entry would make it submitted data — stored on the lead,
     * exported, mailed in a notification, and sent to every webhook endpoint —
     * and would also present it to Bricks as a field its settings never defined.
     */
    public function testTheCorrelationValuesAreNeverWrittenAsBricksFormFields(): void
    {
        $block = self::bricksBlock();

        self::assertStringNotContainsString(
            'form-field-',
            self::executableCode($block),
            "the tracker must not write into Bricks' submitted-field namespace"
        );
    }

    /**
     * The same rules every other form on the page is held to: data-cvm-ignore
     * means ignore, and nothing inside the admin bar is instrumented.
     */
    public function testThePrivacyAndOptOutGatesApplyToBricksToo(): void
    {
        $block = self::bricksBlock();

        self::assertStringContainsString('data-cvm-ignore', $block);
        self::assertStringContainsString('inAdminBar(form)', $block);
    }

    /**
     * The tracker sits in front of a visitor's submission. It must fail open:
     * any error in Convermetry's own code has to leave Bricks' request on its
     * way rather than break the form.
     */
    public function testTheTransportFailsOpen(): void
    {
        $block = self::bricksBlock();

        self::assertStringContainsString('try {', $block, 'the mutation is guarded');
        self::assertStringContainsString('catch (err)', $block, 'a failure never propagates into the submission');
        self::assertStringContainsString(
            "typeof formData.set !== 'function'",
            $block,
            'anything that is not an amendable body is left completely alone'
        );
    }

    // ------------------------------------------------------------------- tokens

    /**
     * ONE TOKEN PER ATTEMPT. A Bricks form carries the server-rendered
     * data-cvm-form-key, which makes correlatableForm() recognise it — so the
     * native submit listener may already have minted this attempt's token before
     * Bricks prepared its request. Minting a second one here would split one
     * submission between two conversion ids, the server recording one and the
     * browser reporting the other, which is exactly the double count the shared
     * token exists to prevent.
     */
    public function testTheAttemptsExistingTokenIsReusedRatherThanReplaced(): void
    {
        $code = self::trackerCode();

        self::assertStringContainsString('freshTokens.add(form)', $code, 'the submit listener marks the attempt');
        self::assertStringContainsString('freshTokens.has(form)', $code, 'the Bricks handler looks for it');
        self::assertStringContainsString(
            'freshTokens.delete(form)',
            $code,
            'and consumes it, so the NEXT attempt cannot re-report this conversion'
        );
    }

    /**
     * A submit attempt is not a conversion. The browser-side conversion is only
     * claimed from Bricks' own success event, and it reuses the token the server
     * received so the two detection paths deduplicate into one conversion rather
     * than counting twice.
     */
    public function testTheConversionIsClaimedOnlyFromBricksSuccessEvent(): void
    {
        $code = self::trackerCode();

        $submitAt  = strpos($code, "addEventListener('bricks/form/submit'");
        $successAt = strpos($code, "addEventListener('bricks/form/success'");
        $errorAt   = strpos($code, "addEventListener('bricks/form/error'");

        self::assertIsInt($submitAt);
        self::assertIsInt($successAt);
        self::assertIsInt($errorAt);

        $submitBlock  = substr($code, $submitAt, $successAt - $submitAt);
        $successBlock = substr($code, $successAt, $errorAt - $successAt);

        self::assertStringNotContainsString(
            'trackConversion(',
            self::executableCode($submitBlock),
            'preparing a request is an attempt, not a conversion'
        );
        self::assertStringContainsString('trackConversion(', $successBlock);
        self::assertStringContainsString(
            'bricksTokens[elementId]',
            $successBlock,
            'the success event reports the conversion the SERVER recorded'
        );
        self::assertStringContainsString(
            'delete bricksTokens[elementId]',
            $successBlock,
            'consumed on use: a repeated success cannot claim the same conversion twice'
        );
    }

    /**
     * An error is not a conversion either, and its token must not be left lying
     * around for a later success event to claim.
     */
    public function testAFailedAttemptDropsItsTokenAndReportsNothingPrivate(): void
    {
        $code    = self::trackerCode();
        $errorAt = strpos($code, "addEventListener('bricks/form/error'");
        self::assertIsInt($errorAt);

        $errorBlock = substr($code, $errorAt, 1400);

        self::assertStringContainsString('delete bricksTokens[elementId]', $errorBlock);
        self::assertStringNotContainsString('trackConversion(', self::executableCode($errorBlock));
        self::assertStringNotContainsString(
            'detail.res',
            self::executableCode($errorBlock),
            "Bricks' response body can echo submitted values and endpoint messages; it is never read"
        );
        self::assertStringContainsString(
            'config.events.form_error',
            $errorBlock,
            'the site owner\'s error-tracking switch still governs it'
        );
    }

    /**
     * An element id from an event is interpolated into a DOM selector, so it is
     * validated first rather than trusted.
     */
    public function testElementIdsAreValidatedBeforeTheyReachASelector(): void
    {
        $code = self::trackerCode();

        self::assertStringContainsString('const BRICKS_ID = /^[A-Za-z0-9_-]{1,64}$/', $code);
        self::assertStringContainsString('BRICKS_ID.test(id)', $code);
    }

    // ------------------------------------------------- the authoritative key

    /**
     * The tracker prefers the server-rendered data-cvm-form-key over every DOM
     * heuristic, and the server renders the SAME provider-scoped key it records
     * the submission under. If these ever diverge, a form's browser-observed
     * engagement is attributed to a key that joins to nothing.
     */
    public function testTheRenderedKeyIsTheOneTheTrackerReadsAndTheServerRecords(): void
    {
        $code = self::trackerCode();

        self::assertStringContainsString("const FORM_ATTR = 'data-cvm-form-key'", $code);
        self::assertStringContainsString("const BRICKS_KEY_PREFIX = 'bricks:'", $code);

        self::assertSame(
            'bricks:ab12cd',
            FormProviderRegistry::formKey(
                BricksFormsBridge::PROVIDER_KEY,
                BricksFormsBridge::nativeId(['formId' => 'ab12cd', 'postId' => 167])
            ),
            'the prefix the tracker builds selectors from is the provider key the server uses'
        );
    }

    /**
     * Bricks forms are found through the attribute the server rendered, and
     * through Bricks' own default element id — never through a markup signature
     * added to the hidden-input seeding list, because Bricks does not serialize
     * the form and a hidden input would be dead weight.
     */
    public function testBricksFormsAreFoundByTheRenderedAttributeAndBricksOwnElementId(): void
    {
        $code = self::trackerCode();

        self::assertStringContainsString("document.getElementById('brxe-' + elementId)", $code);
        self::assertStringContainsString("'[' + FORM_ATTR + '=\"' + BRICKS_KEY_PREFIX + elementId + '\"]'", $code);
    }

    /**
     * Listeners are on `document`, so a Bricks form inserted later — an AJAX
     * popup, a tabbed step, a query-filter re-render — is covered without any
     * rescanning.
     */
    public function testDynamicallyInsertedFormsAreCoveredByDocumentLevelListeners(): void
    {
        $code = self::trackerCode();

        foreach (['submit', 'success', 'error'] as $event) {
            self::assertStringContainsString(
                "document.addEventListener('bricks/form/" . $event . "'",
                $code
            );
        }
    }

    // ------------------------------------------------------- the receiving end

    /**
     * The PHP half of the contract: the values arrive as ordinary POST fields,
     * which is exactly where Correlation already looks — so the Bricks path
     * needed no new server-side parsing, and nothing about it is special-cased.
     */
    public function testTheServerAcceptsThemAsOrdinaryPostFields(): void
    {
        $correlation = Correlation::fromFields([
            Correlation::FIELD_CONVERSION => 'c0123456789abcdef',
            Correlation::FIELD_SESSION    => str_repeat('a', 32),
        ]);

        self::assertSame('c0123456789abcdef', $correlation->conversionId);
        self::assertSame(str_repeat('a', 32), $correlation->sessionId);
        self::assertTrue($correlation->fromTracker);
    }

    /**
     * Nothing a browser sends is trusted just because it arrived: a forged or
     * malformed token is refused and replaced with a server-generated one, and
     * the submission is still recorded — it simply carries no session.
     */
    public function testForgedCorrelationValuesAreRefusedRatherThanTrusted(): void
    {
        $correlation = Correlation::fromFields([
            Correlation::FIELD_CONVERSION => 'not a valid token!!',
            Correlation::FIELD_SESSION    => 'nope',
        ]);

        self::assertNotSame('not a valid token!!', $correlation->conversionId);
        self::assertMatchesRegularExpression('~^c[a-f0-9]{16}$~', $correlation->conversionId);
        self::assertSame('', $correlation->sessionId);
        self::assertFalse($correlation->fromTracker);
    }

    /**
     * Server capture must work with no tracker at all — JavaScript blocked, the
     * tracker disabled, privacy signals honoured. The submission still records,
     * under a server-generated conversion id, with nothing invented about the
     * session.
     */
    public function testASubmissionWithoutTrackerDataStillGetsAConversionIdentity(): void
    {
        $correlation = Correlation::fromFields([]);

        self::assertNotSame('', $correlation->conversionId);
        self::assertSame('', $correlation->sessionId);
        self::assertFalse($correlation->fromTracker, 'nothing is fabricated about the session');
    }

    /**
     * Deduplication is the reason the browser and the server share one token: a
     * replayed request carries the same conversion id, and the submission row's
     * unique constraint on it is what stops a second lead, a second delivery and
     * a second notification.
     */
    public function testAReplayedRequestResolvesToTheSameConversionIdentity(): void
    {
        $fields = [
            Correlation::FIELD_CONVERSION => 'c0123456789abcdef',
            Correlation::FIELD_SESSION    => str_repeat('b', 32),
        ];

        self::assertSame(
            Correlation::fromFields($fields)->conversionId,
            Correlation::fromFields($fields)->conversionId,
            'the same request must always resolve to the same conversion'
        );
    }
}
