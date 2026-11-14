<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Convermetry\Support\UrlPattern;
use PHPUnit\Framework\TestCase;

/**
 * The URL-pattern rule shared by URL goals, link-click goals and funnel page
 * steps.
 *
 * Regression origin: a pattern went through sanitize_text_field(), which
 * deletes every %XX octet, so an address pasted for a page with a Cyrillic slug
 * was saved as "//" and matched the home page; the same address typed as the
 * address bar shows it was compared undecoded and matched nothing. GoalMatcher
 * and StepCompiler are tested against this rule in their own suites, and the
 * save path against the real sanitize_text_field() in the WordPress suite.
 */
final class UrlPatternTest extends TestCase
{
    private const string TYPED = '/контакты/';
    private const string UPPER = '/%D0%BA%D0%BE%D0%BD%D1%82%D0%B0%D0%BA%D1%82%D1%8B/';
    private const string LOWER = '/%d0%ba%d0%be%d0%bd%d1%82%d0%b0%d0%ba%d1%82%d1%8b/';

    public function testCleaningKeepsPercentEncodedOctets(): void
    {
        self::assertSame(self::UPPER, UrlPattern::clean('  ' . self::UPPER . "\n", 191));
        self::assertSame('/demo/Price%20List%202026.pdf', UrlPattern::clean('/demo/Price%20List%202026.pdf', 191));
        self::assertSame(self::TYPED, UrlPattern::clean(self::TYPED, 191));
    }

    public function testCleaningRemovesWhatNoUrlContains(): void
    {
        self::assertSame('/a/b/', UrlPattern::clean("/a/\x00\x07b/", 191));
        self::assertSame('/pricing/x', UrlPattern::clean('/pricing/<b>x</b>', 191));
        self::assertSame('', UrlPattern::clean("/caf\xE9/", 191), 'Not UTF-8: refused whole, as sanitize_text_field() does.');
        self::assertSame('', UrlPattern::clean(['/a/'], 191));
        self::assertSame('/конт', UrlPattern::clean(self::TYPED, 5), 'Cut in characters, not bytes.');
    }

    public function testEveryWayOfEnteringTheAddressHasOneCanonicalForm(): void
    {
        self::assertSame(self::TYPED, UrlPattern::canonical(self::TYPED));
        self::assertSame(self::TYPED, UrlPattern::canonical(self::UPPER));
        self::assertSame(self::TYPED, UrlPattern::canonical(self::LOWER));
        self::assertSame('/price list/', UrlPattern::canonical('/Price%20List/'));
    }

    public function testCanonicalFoldsNonLatinCaseWithMbstring(): void
    {
        if (!function_exists('mb_strtolower')) {
            self::markTestSkipped('mbstring is not loaded.');
        }

        self::assertSame(self::TYPED, UrlPattern::canonical('/Контакты/'));
    }

    public function testAnUndecodableSequenceIsComparedAsWritten(): void
    {
        self::assertSame('/%ff/', UrlPattern::canonical('/%FF/'));
        self::assertNotSame(UrlPattern::canonical('/%ff/'), UrlPattern::canonical('/%fe/'));
    }

    /**
     * What a funnel's SQL is bound with: the encoding WordPress stores a slug
     * in, however the address was entered.
     */
    public function testTheEncodedFormIsWordPresssOwn(): void
    {
        foreach ([self::TYPED, self::UPPER, self::LOWER] as $pattern) {
            self::assertSame(self::LOWER, UrlPattern::encoded($pattern), $pattern);
        }

        self::assertSame('https://example.com/a%20b/', UrlPattern::encoded('https://Example.com/a b/'));
        self::assertSame('/100%25/', UrlPattern::encoded('/100%25/'), 'A literal % stays encoded.');
        self::assertSame('/%ff/', UrlPattern::encoded('/%FF/'), 'Not text: kept, never re-encoded to %25ff.');
        self::assertSame('/thank-you/', UrlPattern::encoded('/Thank-You/'));
    }

    /**
     * @dataProvider usability
     */
    public function testAPatternWithAnEmptySegmentIsUnusable(string $pattern, bool $usable): void
    {
        self::assertSame($usable, UrlPattern::isUsable($pattern));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function usability(): array
    {
        return [
            'nothing'                => ['', false],
            '1.1.5 paste'            => ['//', false],
            'nested paste'           => ['///', false],
            'paste under a parent'   => ['/catalog//', false],
            'full url paste'         => ['https://example.com//', false],
            'home page'              => ['/', true],
            'full url home'          => ['https://example.com/', true],
            'full url, no path'      => ['https://example.com', true],
            'ordinary path'          => ['/thank-you/', true],
            'typed slug'             => [self::TYPED, true],
            'encoded slug'           => [self::UPPER, true],
            'mixed survivor'         => ['/-probe/', true],
            'contains fragment'      => ['thank-you', true],
        ];
    }
}
