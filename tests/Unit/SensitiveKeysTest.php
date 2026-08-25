<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Support\SensitiveKeys;
use PHPUnit\Framework\TestCase;

/**
 * The shared "does this name carry a secret?" policy.
 *
 * Three surfaces depend on this answer — Activity Log headers, Activity Log
 * bodies, and notification emails — and the email surface is unforgiving: a
 * logged secret can be purged by retention, an emailed one cannot be recalled.
 *
 * Matching happens on a canonical form because real names spell the same
 * secret many ways, and structured submission fields carry human LABELS, so
 * "API Key" is the common spelling rather than an edge case.
 */
final class SensitiveKeysTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('apply_filters')->returnArg(2);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @dataProvider secretSpellings
     */
    public function testEverySpellingOfTheSameSecretMatches(string $name): void
    {
        self::assertTrue(SensitiveKeys::matches($name), "{$name} was not recognised as sensitive");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function secretSpellings(): array
    {
        return [
            'header hyphens'     => ['X-API-Key'],
            'lowercase hyphens'  => ['x-api-key'],
            'underscores'        => ['X_Api_Key'],
            'human label'        => ['API Key'],
            'human label spaced' => ['Api  Key'],
            'camel'              => ['apiKey'],
            'plain'              => ['password'],
            'label with colon'   => ['Password:'],
            'compound'           => ['user_password_confirm'],
            'bearer'             => ['Authorization'],
            'proxy'              => ['Proxy-Authorization'],
            'nested secret'      => ['client_secret'],
            'access token'       => ['Access Token'],
        ];
    }

    /**
     * @dataProvider innocuousNames
     */
    public function testInnocuousNamesDoNotMatch(string $name): void
    {
        self::assertFalse(SensitiveKeys::matches($name), "{$name} was wrongly treated as sensitive");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function innocuousNames(): array
    {
        return [
            // The reason 'authorization' is spelled out rather than 'auth'.
            'author'      => ['Author'],
            'author bio'  => ['author_bio'],
            'name'        => ['Full Name'],
            'email'       => ['Email address'],
            'signature'   => ['X-Convermetry-Signature'],
            'message'     => ['Your message'],
            'empty'       => [''],
            'punctuation' => ['---'],
        ];
    }

    /**
     * 'cookie' is a header-only pattern. A form field labelled "Cookie policy"
     * is ordinary lead data and must reach the payload and the email intact,
     * while a Cookie request header carries session credentials.
     */
    public function testCookieIsAHeaderOnlyPattern(): void
    {
        self::assertTrue(SensitiveKeys::matchesHeader('Cookie'));
        self::assertTrue(SensitiveKeys::matchesHeader('Set-Cookie'));

        self::assertFalse(SensitiveKeys::matches('Cookie policy'));
        self::assertFalse(SensitiveKeys::matches('Do you accept cookies?'));
    }

    public function testHeaderMatchingAlsoAppliesTheSharedPatterns(): void
    {
        self::assertTrue(SensitiveKeys::matchesHeader('X-API-Key'));
        self::assertFalse(SensitiveKeys::matchesHeader('Content-Type'));
    }

    public function testCanonicalizeCollapsesNonAlphanumericRuns(): void
    {
        self::assertSame('api_key', SensitiveKeys::canonicalize('API Key'));
        self::assertSame('x_api_key', SensitiveKeys::canonicalize('X-API-Key'));
        self::assertSame('api_key', SensitiveKeys::canonicalize('--api--key--'));
        self::assertSame('', SensitiveKeys::canonicalize('!!!'));
    }

    public function testFilterCanExtendThePatternList(): void
    {
        self::assertFalse(SensitiveKeys::matches('SSN'));

        Monkey\tearDown();
        Monkey\setUp();
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value) => $hook === 'convermetry_sensitive_keys'
                ? [...SensitiveKeys::PATTERNS, 'ssn']
                : $value
        );

        self::assertTrue(SensitiveKeys::matches('SSN'));
        self::assertTrue(SensitiveKeys::matches('Applicant SSN'));
    }

    /**
     * A filter returning junk must not produce PHP warnings or a pattern that
     * matches everything.
     */
    public function testFilterReturningNonStringsIsCoerced(): void
    {
        Monkey\tearDown();
        Monkey\setUp();
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value) => $hook === 'convermetry_sensitive_keys'
                ? ['password', ['nested'], null, 42, '', '!!!']
                : $value
        );

        self::assertTrue(SensitiveKeys::matches('password'));
        self::assertTrue(SensitiveKeys::matches('42'), 'A scalar pattern is canonicalized, not dropped');
        self::assertFalse(SensitiveKeys::matches('Full Name'), 'Empty patterns must not match everything');
    }

    public function testFilterReturningANonArrayFallsBackToTheDefaults(): void
    {
        Monkey\tearDown();
        Monkey\setUp();
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value) => $hook === 'convermetry_sensitive_keys' ? 'nope' : $value
        );

        self::assertTrue(SensitiveKeys::matches('password'));
    }
}
