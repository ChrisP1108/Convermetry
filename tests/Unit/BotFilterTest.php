<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Api\TrackingController;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Which user agents the tracking endpoint silently discards.
 *
 * Regression origin: the filter matched the bare substring "bot", so every
 * visitor on a CUBOT handset was thrown away — "CUBOT X70" is in the same user
 * agent string a crawler names itself in — while the same request from a Pixel
 * was kept. Requiring a word boundary instead would have missed the crawlers
 * that matter most, whose "bot" is a suffix: Googlebot, bingbot.
 *
 * Every user agent below is a real one, or a real one's exact shape.
 */
final class BotFilterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('wp_unslash')->returnArg(1);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_USER_AGENT']);
        Monkey\tearDown();
        parent::tearDown();
    }

    private function isBot(?string $userAgent): bool
    {
        if ($userAgent === null) {
            unset($_SERVER['HTTP_USER_AGENT']);
        } else {
            $_SERVER['HTTP_USER_AGENT'] = $userAgent;
        }

        return (bool) (new ReflectionMethod(TrackingController::class, 'isBot'))->invoke(null);
    }

    /**
     * The handset that exposed the defect, beside the otherwise identical
     * Pixel request that was always accepted.
     */
    public function testACubotHandsetIsTreatedExactlyLikeAPixel(): void
    {
        $template = 'Mozilla/5.0 (Linux; Android 13; %s) AppleWebKit/537.36 (KHTML, like Gecko) '
            . 'Chrome/124.0.0.0 Mobile Safari/537.36';

        self::assertFalse($this->isBot(sprintf($template, 'Pixel 8')));
        self::assertFalse($this->isBot(sprintf($template, 'CUBOT X70')), 'A CUBOT visitor is a visitor.');
    }

    /**
     * @dataProvider people
     */
    public function testPeopleAreKept(string $userAgent): void
    {
        self::assertFalse($this->isBot($userAgent));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function people(): array
    {
        return [
            'CUBOT model with underscore' => ['Mozilla/5.0 (Linux; Android 11; CUBOT_P50 Build/RP1A.200720.011; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/120.0.0.0 Mobile Safari/537.36'],
            'CUBOT with build id'         => ['Mozilla/5.0 (Linux; Android 12; CUBOT KINGKONG 9 Build/SP1A.210812.016) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.0.0 Mobile Safari/537.36'],
            'iPhone Safari'               => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1'],
            'Firefox'                     => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0'],
            'Edge'                        => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Edg/124.0.2478.80'],
            'Samsung Internet'            => ['Mozilla/5.0 (Linux; Android 14; SAMSUNG SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0.0.0 Mobile Safari/537.36'],
            'Facebook in-app browser'     => ['Mozilla/5.0 (Linux; Android 14; Pixel 7 Build/UQ1A.240205.004; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/122.0.6261.119 Mobile Safari/537.36 [FB_IAB/FB4A;FBAV/454.0.0.44.108;]'],
            'an app whose name starts Bot' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 Botim/4.2'],
            '"curl" inside another word'  => ['Mozilla/5.0 (X11; CrOS x86_64 14541.0.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 Securly'],
        ];
    }

    /**
     * @dataProvider automatedClients
     */
    public function testCrawlersHeadlessBrowsersAndScriptedClientsAreStillDiscarded(string $userAgent): void
    {
        self::assertTrue($this->isBot($userAgent));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function automatedClients(): array
    {
        return [
            'Googlebot'              => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'Googlebot smartphone'   => ['Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.6367.155 Mobile Safari/537.36 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'Googlebot-Image'        => ['Googlebot-Image/1.0'],
            'Googlebot-News'         => ['Googlebot-News'],
            'bingbot'                => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm) Chrome/116.0.1938.76 Safari/537.36'],
            'Applebot'               => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.1.1 Safari/605.1.15 (Applebot/0.1; +http://www.apple.com/go/applebot)'],
            'YandexBot'              => ['Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)'],
            'AhrefsBot'              => ['Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)'],
            'PetalBot'               => ['Mozilla/5.0 (Linux; Android 7.0;) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; PetalBot;+https://webmaster.petalsearch.com/site/petalbot)'],
            'archive.org_bot'        => ['Mozilla/5.0 (compatible; archive.org_bot +http://archive.org/details/archive.org_bot)'],
            'a bot with no version'  => ['SiteMonitor Bot 1.0'],
            'Baiduspider'            => ['Mozilla/5.0 (compatible; Baiduspider/2.0; +http://www.baidu.com/search/spider.html)'],
            'Yahoo! Slurp'           => ['Mozilla/5.0 (compatible; Yahoo! Slurp; http://help.yahoo.com/help/us/ysearch/slurp)'],
            'BingPreview'            => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) BingPreview/1.0b'],
            'Slack link expander'    => ['Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)'],
            'HeadlessChrome'         => ['Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/124.0.0.0 Safari/537.36'],
            'curl'                   => ['curl/8.4.0'],
            'libcurl via PycURL'     => ['PycURL/7.45.2 libcurl/8.4.0 OpenSSL/3.0.13'],
            'Wget'                   => ['Wget/1.21.4'],
            'python-requests'        => ['python-requests/2.31.0'],
        ];
    }

    public function testAMissingOrEmptyUserAgentIsStillDiscarded(): void
    {
        self::assertTrue($this->isBot(null));
        self::assertTrue($this->isBot(''));
    }
}
