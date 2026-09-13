<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Tracking\Correlation;
use PHPUnit\Framework\TestCase;

/**
 * How session attribution reaches the server on an Atomic form.
 *
 * THE REASON THIS FILE EXISTS. Every other supported form plugin serializes the
 * <form>, so Convermetry's tracker injects three hidden inputs and they arrive
 * in $_POST. Atomic does not serialize the form. Elementor's own frontend
 * handler hand-builds a FormData — action, nonce, post_id, form_id, form_name,
 * referer_title, referrer, and one form_fields[i][…] group per element matched
 * by input[data-interaction-id] — and POSTs that. A hidden input Convermetry
 * appended matches nothing in that loop and is silently dropped. Seeding one
 * would have looked exactly like working code and attributed nothing, which is
 * the specific failure this integration had to avoid.
 *
 * So the tracker appends the three values to that request instead, as top-level
 * fields, leaving form_fields untouched. This file pins the contract from both
 * ends: the JavaScript that has to send them, and the PHP that has to accept
 * them.
 *
 * WHAT IS NOT PROVEN HERE. The JavaScript side is asserted against the tracker's
 * source, because this is a PHP unit suite with no browser and no Elementor. It
 * proves the code says what it must say; it cannot prove a real Alpine
 * submission in a real browser carries the fields. That is a live-site check,
 * and it is listed as unverified in the release notes.
 */
final class AtomicCorrelationTransportTest extends TestCase
{
    private const string TRACKER = __DIR__ . '/../../assets/js/tracker.js';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Only what the no-token path needs to mint a server-side conversion id.
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

    public function testTheTrackerTargetsElementorsOwnAtomicSubmitRequest(): void
    {
        $code = self::trackerCode();

        self::assertStringContainsString(
            'elementor_pro_atomic_forms_send_form',
            $code,
            "the Atomic transport is keyed off Elementor's own admin-ajax action"
        );
        self::assertStringContainsString('instanceof FormData', $code, 'only a FormData body is inspected');
    }

    /** All three values, or attribution is partial and the conversion cannot join. */
    public function testAllThreeCorrelationValuesAreAppended(): void
    {
        $code = self::trackerCode();

        foreach (
            [Correlation::FIELD_CONVERSION, Correlation::FIELD_SESSION, Correlation::FIELD_CONTEXT] as $field
        ) {
            self::assertStringContainsString(
                $field,
                $code,
                $field . ' must travel with an Atomic submission'
            );
        }
    }

    /**
     * The values are Convermetry's, not the visitor's. Appending one into
     * form_fields would make it submitted data — stored on the lead, exported,
     * mailed in a notification, and sent to every webhook endpoint.
     */
    public function testTheCorrelationValuesAreNeverAddedAsFormFields(): void
    {
        $code = self::trackerCode();

        self::assertStringNotContainsString(
            'form_fields[',
            $code,
            "the tracker must not write into Elementor's submitted-field array"
        );
        self::assertStringNotContainsString('data-interaction-id', $code, 'no field is impersonated');
    }

    /**
     * The same rules every other form on the page is held to: nothing is sent
     * cross-origin, and data-cvm-ignore means ignore.
     */
    public function testThePrivacyAndSameOriginGatesApplyToAtomicToo(): void
    {
        $code = self::trackerCode();

        self::assertStringContainsString('data-cvm-ignore', $code);
        self::assertStringContainsString('location.origin', $code);
        self::assertStringContainsString('inAdminBar(form)', $code);
    }

    /**
     * A wrapper around fetch sits in front of a visitor's submission. It must
     * fail open: any error in Convermetry's own code has to leave the original
     * call untouched rather than break the form.
     */
    public function testTheTransportFailsOpen(): void
    {
        $tracker = self::tracker();

        $start = strpos($tracker, 'window.fetch = function');
        self::assertIsInt($start, 'the fetch wrapper must exist');

        $wrapper = substr($tracker, $start, 1800);

        self::assertStringContainsString('try {', $wrapper, 'inspection is guarded');
        self::assertStringContainsString('catch (e)', $wrapper, 'a failure never propagates into the submission');
        self::assertStringContainsString(
            'nativeFetch.apply(window',
            $wrapper,
            'the original fetch is always called, bound to window — the tracker runs in strict mode'
        );
    }

    /**
     * A submit attempt is not a conversion. The browser-side conversion is only
     * claimed from Elementor's own success response, and it reuses the token the
     * server received so the two detection paths deduplicate into one conversion
     * rather than counting twice.
     */
    public function testTheConversionIsClaimedOnlyFromAConfirmedResponse(): void
    {
        $tracker = self::tracker();

        $start = strpos($tracker, 'window.fetch = function');
        self::assertIsInt($start);

        $wrapper = substr($tracker, $start, 1800);

        self::assertStringContainsString('res.ok', $wrapper, 'only a successful HTTP response is considered');
        self::assertStringContainsString('data.success', $wrapper, "Elementor's own verdict decides");
        self::assertStringContainsString('trackConversion(', $wrapper);
        self::assertStringContainsString(
            'res.clone()',
            $wrapper,
            "the response body is cloned so Elementor's own handler still gets to read it"
        );
    }

    // ------------------------------------------------------- the receiving end

    /**
     * The PHP half of the contract: the values arrive as ordinary POST fields,
     * which is exactly where Correlation already looks — so the Atomic path
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
     * A submission with no tracker data at all — JavaScript blocked, the tracker
     * disabled — is still captured, with a server-generated conversion id and no
     * invented session attribution.
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
