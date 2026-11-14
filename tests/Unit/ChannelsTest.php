<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Tracking\Channels;
use PHPUnit\Framework\TestCase;

/**
 * Marketing-channel classification, as ingestion runs it: utm_source is
 * normalized first (DatabaseManager::sanitizeRow() and Correlation both do),
 * then the row is classified.
 *
 * Regression origin, both from the referrer-channels scenario: untagged visits
 * from X/Twitter, LinkedIn and Bluesky share links arrived from t.co, x.com,
 * lnkd.in and bsky.app, none of which carries the network's name where the
 * host matcher looks, so they were Referral; and Meta's placement sources
 * "msg" (Messenger) and "an" (Audience Network) were not aliased, so a paid
 * Messenger ad was Paid Search.
 */
final class ChannelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('wp_parse_url')->alias(static fn(string $url, int $component = -1): mixed => parse_url($url, $component));
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('apply_filters')->returnArg(2);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param array<string, string> $row
     */
    private function channel(array $row, string $type = 'pageview'): string
    {
        $row['utm_source'] = Channels::normalizeSource($row['utm_source'] ?? '');

        return Channels::classify($row, $type);
    }

    /**
     * @dataProvider socialShortLinks
     */
    public function testAnUntaggedVisitFromANetworksShortLinkIsOrganicSocial(string $referrer): void
    {
        self::assertSame('Organic Social', $this->channel(['referrer' => $referrer]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function socialShortLinks(): array
    {
        return [
            't.co'        => ['https://t.co/AbC123'],
            'x.com'       => ['https://x.com/someone/status/1'],
            'www.x.com'   => ['https://www.x.com/'],
            'lnkd.in'     => ['https://lnkd.in/eXyZ'],
            'bsky.app'    => ['https://bsky.app/profile/someone'],
            'go.bsky.app' => ['https://go.bsky.app/AbC'],
        ];
    }

    /**
     * What already worked keeps working: a network named in the
     * registrable-domain position, search engines, and an unknown site.
     *
     * @dataProvider unchangedReferrers
     */
    public function testReferrersThatAlreadyClassifiedKeepTheirChannel(string $referrer, string $channel): void
    {
        self::assertSame($channel, $this->channel(['referrer' => $referrer]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unchangedReferrers(): array
    {
        return [
            'facebook link shim' => ['https://l.facebook.com/l.php', 'Organic Social'],
            'threads'            => ['https://www.threads.net/@x', 'Organic Social'],
            'google.co.uk'       => ['https://www.google.co.uk/', 'Organic Search'],
            'duckduckgo'         => ['https://duckduckgo.com/', 'Organic Search'],
            'bing via alias'     => ['https://bing.com/search', 'Organic Search'],
            'an ordinary site'   => ['https://blog.example.org/post/', 'Referral'],
            'name not in place'  => ['https://google.example.test/', 'Referral'],
            // The alias map also holds bare source names ("x", "fb"), and an
            // intranet host called "x" is not X.
            'a bare host "x"'    => ['http://x/wiki/', 'Referral'],
        ];
    }

    public function testMetasPlacementSourcesAreAliased(): void
    {
        self::assertSame('messenger', Channels::normalizeSource('msg'));
        self::assertSame('facebook', Channels::normalizeSource('an'));
        self::assertSame('facebook', Channels::normalizeSource('fb'));
        self::assertSame('instagram', Channels::normalizeSource('IG'));
    }

    /**
     * Meta's recommended utm_source={{site_source_name}}: every placement of a
     * paid Meta ad is Paid Social, never Paid Search.
     *
     * @dataProvider metaPlacements
     */
    public function testAPaidMetaPlacementIsPaidSocial(string $source, string $medium): void
    {
        self::assertSame('Paid Social', $this->channel(['utm_source' => $source, 'utm_medium' => $medium]));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function metaPlacements(): array
    {
        return [
            'messenger, paid' => ['msg', 'paid'],
            'messenger, cpc'  => ['msg', 'cpc'],
            'audience, paid'  => ['an', 'paid'],
            'audience, cpc'   => ['an', 'cpc'],
            'facebook, paid'  => ['fb', 'paid'],
            'instagram, cpc'  => ['ig', 'cpc'],
        ];
    }
}
