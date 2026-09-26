<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Privacy\PersonalDataMatcher;
use Convermetry\Webhook\DeliveryLog;
use PHPUnit\Framework\TestCase;

/**
 * The two pure decisions the personal-data eraser rests on: which stored
 * submission belongs to an email address, and how a logged analytics report
 * loses one conversion's identifiers.
 *
 * The eraser DELETES what the matcher accepts, so the rules pinned here are
 * mostly about what must NOT match. What the database holds before and after
 * an erasure is asserted in tests/WordPress/PrivacyToolsTest, against real
 * tables — never here.
 */
final class PrivacyMatchingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('sanitize_text_field')->alias(static fn(string $v): string => trim(strip_tags($v)));
        Functions\when('wp_json_encode')->alias(static fn(mixed $v, int $flags = 0): string|false => json_encode($v, $flags));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Matching ─────────────────────────────────────────────────────────────

    public function testAnExactFieldValueMatchesCaseInsensitivelyAfterTrimming(): void
    {
        $stored = (string) json_encode([
            ['id' => 'email', 'label' => 'Email', 'value' => '  Ann@Example.COM '],
        ]);

        self::assertTrue(PersonalDataMatcher::storedDataMatches($stored, 'ann@example.com'));
    }

    public function testAnAddressThatMerelyContainsTheRequestedOneDoesNotMatch(): void
    {
        $stored = (string) json_encode([['id' => 'email', 'label' => 'Email', 'value' => 'joann@example.com']]);

        self::assertFalse(PersonalDataMatcher::storedDataMatches($stored, 'ann@example.com'));
    }

    /**
     * Somebody else's lead that mentions the address is still somebody else's
     * lead. Erasing it would delete Bob's enquiry because he named Ann in it.
     */
    public function testAFieldThatMentionsTheAddressInsideOtherTextDoesNotMatch(): void
    {
        $stored = (string) json_encode([
            ['id' => 'email', 'label' => 'Email', 'value' => 'bob@example.com'],
            ['id' => 'message', 'label' => 'Message', 'value' => 'Please copy ann@example.com'],
        ]);

        self::assertFalse(PersonalDataMatcher::storedDataMatches($stored, 'ann@example.com'));
    }

    public function testAnyItemOfAMultiValueFieldMatches(): void
    {
        $stored = (string) json_encode([
            ['id' => 'contacts', 'label' => 'Contacts', 'value' => ['bob@example.com', 'ann@example.com']],
        ]);

        self::assertTrue(PersonalDataMatcher::storedDataMatches($stored, 'ann@example.com'));
    }

    /**
     * Pre-2.0 rows hold a label => value map, and the public custom-form API
     * still accepts one. Both must be erasable.
     */
    public function testALegacyMapRowMatches(): void
    {
        $stored = (string) json_encode(['Your email' => 'ann@example.com', 'Name' => 'Ann']);

        self::assertTrue(PersonalDataMatcher::storedDataMatches($stored, 'ann@example.com'));
    }

    public function testUnreadableOrEmptyDataNeverMatches(): void
    {
        self::assertFalse(PersonalDataMatcher::storedDataMatches('', 'ann@example.com'));
        self::assertFalse(PersonalDataMatcher::storedDataMatches('{not json', 'ann@example.com'));
        self::assertFalse(PersonalDataMatcher::storedDataMatches('[]', 'ann@example.com'));
    }

    public function testAnEmptyAddressMatchesNothing(): void
    {
        $stored = (string) json_encode([['id' => 'email', 'label' => 'Email', 'value' => '']]);

        self::assertFalse(PersonalDataMatcher::storedDataMatches($stored, ''));
    }

    public function testNormalizationRejectsWhatCannotBeAnAddress(): void
    {
        self::assertSame('ann@example.com', PersonalDataMatcher::normalizeEmail(' ANN@example.com '));
        self::assertSame('', PersonalDataMatcher::normalizeEmail('not-an-address'));
        self::assertSame('', PersonalDataMatcher::normalizeEmail(''));
        self::assertSame('', PersonalDataMatcher::normalizeEmail(str_repeat('a', 250) . '@x.io'));
    }

    /**
     * Older rows were written with PHP's default JSON flags, so "/" and
     * non-ASCII characters are stored escaped. The SQL narrowing has to look
     * for that spelling too, or those leads could never be found.
     */
    public function testTheLikePatternsIncludeTheLegacyEscapedSpelling(): void
    {
        self::assertSame(['ann@example.com', 'ann@example.com'], PersonalDataMatcher::likePatterns('ann@example.com'));
        self::assertSame(['a/b@example.com', 'a\\/b@example.com'], PersonalDataMatcher::likePatterns('a/b@example.com'));
        self::assertSame(['josé@example.com', 'jos\\u00e9@example.com'], PersonalDataMatcher::likePatterns('josé@example.com'));
    }

    // ── Logged analytics reports ─────────────────────────────────────────────

    public function testOnlyTheErasedConversionLosesItsIpAndSessionId(): void
    {
        $body = (string) json_encode(['analytics' => ['conversions' => ['total' => 2, 'recent' => [
            ['conversion_id' => 'conv_ann', 'ip_address' => '198.51.100.7', 'session_id' => 'abc', 'form' => 'Contact'],
            ['conversion_id' => 'conv_bob', 'ip_address' => '198.51.100.8', 'session_id' => 'def', 'form' => 'Contact'],
        ]]]]);

        $out = DeliveryLog::withoutConversionIdentifiers($body, 'conv_ann');
        self::assertIsString($out);

        $recent = json_decode($out, true)['analytics']['conversions']['recent'];

        self::assertSame(['conversion_id' => 'conv_ann', 'ip_address' => '', 'session_id' => '', 'form' => 'Contact'], $recent[0]);
        self::assertSame('198.51.100.8', $recent[1]['ip_address'], 'Another visitor\'s entry is untouched');
        self::assertSame('def', $recent[1]['session_id']);
        self::assertSame(2, json_decode($out, true)['analytics']['conversions']['total'], 'Aggregates are kept');
    }

    public function testABodyThatDoesNotListTheConversionIsReturnedUnchanged(): void
    {
        $body = (string) json_encode(['analytics' => ['conversions' => ['recent' => [
            ['conversion_id' => 'conv_bob', 'ip_address' => '198.51.100.8'],
        ]]]]);

        self::assertSame($body, DeliveryLog::withoutConversionIdentifiers($body, 'conv_ann'));
        self::assertSame('{"analytics":{}}', DeliveryLog::withoutConversionIdentifiers('{"analytics":{}}', 'conv_ann'));
    }

    /**
     * A body cut at the 64 KB storage cap is not JSON and cannot be rewritten
     * field by field. Null tells the caller to replace it whole.
     */
    public function testATruncatedBodyCannotBeRewritten(): void
    {
        self::assertNull(DeliveryLog::withoutConversionIdentifiers('{"analytics":{"conversions":{"recent":[{"conv [TRUNCATED]', 'conv_ann'));
        self::assertNull(DeliveryLog::withoutConversionIdentifiers('', 'conv_ann'));
    }
}
