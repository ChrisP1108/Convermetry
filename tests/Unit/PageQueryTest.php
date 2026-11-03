<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\FormSettings;
use Convermetry\Forms\PageQuery;
use Convermetry\Forms\SubmissionService;
use Convermetry\Settings\Options;
use Convermetry\Tracking\Correlation;
use Convermetry\Webhook\PayloadBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Which of a submitting page's URL parameters may be kept.
 *
 * Regression origin: SubmissionService::pageInfo() stored every parameter on the
 * Referer whatever the page-parameters setting said, so ad-click ids, email
 * addresses and reset tokens reached page_query, the Submissions screen and
 * website_info.page.query. It also ran sanitize_text_field() over the raw URL,
 * which strips percent-encoded octets and so turned 'leak.check%40example.com'
 * into 'leak.checkexample.com' — hiding the very character the email guard reads.
 */
final class PageQueryTest extends TestCase
{
    private const string FORM_KEY = 'gravityforms:7';

    /** @var array<string, mixed> */
    private array $webhookSettings = [];

    /** @var array<string, mixed> */
    private array $formSettings = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(fn(string $key, mixed $default = false): mixed => match ($key) {
            Options::WEBHOOK_OPTION_KEY => $this->webhookSettings,
            FormSettings::OPTION_KEY    => $this->formSettings,
            default                     => $default,
        });
        Functions\when('apply_filters')->returnArg(2);
        // The real one strips percent-encoded octets, which is the whole reason
        // the order of decoding and sanitizing matters.
        Functions\when('sanitize_text_field')->alias(
            static fn($v): string => trim((string) preg_replace('/%[a-f0-9]{2}/i', '', strip_tags((string) $v)))
        );
    }

    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_REFERER']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @return array<string, string> The parameters the test report found stored. */
    private function reportedParams(): array
    {
        return [
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
            'gclid'      => 'CLKSECRET123VALUE',
            'fbclid'     => 'CLKSECRET123VALUEFB',
            'email'      => 'leak.check@example.com',
            'token'      => 'TOKSECRET456VALUE',
            'reset_key'  => 'TOKSECRET456VALUERK',
        ];
    }

    public function testWithForwardingOffOnlyCampaignParametersSurvive(): void
    {
        self::assertSame(
            ['utm_source' => 'google', 'utm_medium' => 'cpc'],
            PageQuery::filter($this->reportedParams() + ['ref' => 'partner'], false)
        );
    }

    /**
     * The setting's own copy promises click ids, and offline conversion imports
     * depend on them. Secrets and addresses stay out even so.
     */
    public function testForwardingOnKeepsClickIdsButNeverSecrets(): void
    {
        self::assertSame(
            [
                'utm_source' => 'google',
                'utm_medium' => 'cpc',
                'gclid'      => 'CLKSECRET123VALUE',
                'fbclid'     => 'CLKSECRET123VALUEFB',
            ],
            PageQuery::filter($this->reportedParams(), true)
        );
    }

    /** @return array<string, array{0: string}> */
    public static function privateNames(): array
    {
        return array_combine($names = [
            'key', 'reset_key', 'rp_key', 'activation-key', '_wpnonce', 'nonce', 'token', 'access_token',
            'password', 'pwd', 'secret', 'api_key', 'otp', 'code', 'auth_code', 'verification_code', 'sig',
            'signature', 'hash', 'session', 'session_id', 'sessionid', 'PHPSESSID', 'jwt', 'login', 'email',
            'e-mail', 'user_email', 'phone', 'name', 'first_name', 'lastName',
        ], array_map(static fn(string $name): array => [$name], $names));
    }

    #[DataProvider('privateNames')]
    public function testPrivateNamesAreNeverForwarded(string $name): void
    {
        self::assertSame([], PageQuery::filter([$name => 'value'], true));
    }

    /** @return array<string, array{0: string}> */
    public static function ordinaryNames(): array
    {
        return array_combine($names = [
            'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'ttclid', 'twclid', 'li_fat_id',
            'ref', 'keyword', 'monkey', 'promo_code', 'campaign_name', 'ad_name', 'hashtag',
        ], array_map(static fn(string $name): array => [$name], $names));
    }

    /** A careless pattern would quietly strip what forwarding exists to send. */
    #[DataProvider('ordinaryNames')]
    public function testOrdinaryNamesAreForwarded(string $name): void
    {
        self::assertSame([$name => 'value'], PageQuery::filter([$name => 'value'], true));
    }

    public function testAnEmailAddressIsDroppedWhateverItIsCalled(): void
    {
        foreach ([false, true] as $forwarding) {
            self::assertSame([], PageQuery::filter([
                'utm_term' => 'jo@example.com',
                'ref'      => 'jo@example.com',
                'double'   => 'jo%40example.com',
            ], $forwarding));
        }
    }

    public function testNonScalarValuesAndBlankNamesAreDropped(): void
    {
        self::assertSame(['ref' => 'a'], PageQuery::filter(['ref' => 'a', 'list' => ['b'], '<b></b>' => 'c'], true));
    }

    public function testCampaignNamesMatchInAnyCase(): void
    {
        self::assertSame(['UTM_Source' => 'x'], PageQuery::filter(['UTM_Source' => 'x'], false));
    }

    public function testForwardingFollowsTheGlobalSettingOrTheFormsOverride(): void
    {
        self::assertFalse(PageQuery::isForwarded(self::FORM_KEY));

        $this->formSettings = [self::FORM_KEY => ['include_page_params' => true]];
        self::assertTrue(PageQuery::isForwarded(self::FORM_KEY));
        self::assertFalse(PageQuery::isForwarded('gravityforms:8'), 'The override is one form only.');

        $this->webhookSettings = ['include_page_params' => true];
        self::assertTrue(PageQuery::isForwarded('gravityforms:8'));
    }

    /**
     * A row recorded before the policy existed holds every parameter. It must
     * not go out in the payload again while it waits for retention.
     */
    public function testThePayloadAppliesThePolicyToAnOlderRow(): void
    {
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('get_bloginfo')->justReturn('Example');
        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));

        $payload = PayloadBuilder::formSubmission([
            'submission_id' => 's1',
            'form_key'      => self::FORM_KEY,
            'page_url'      => 'https://example.com/quote/',
            'page_query'    => (string) json_encode($this->reportedParams()),
            'created_at'    => '2026-08-22 14:32:00',
        ]);

        self::assertSame(
            ['utm_source' => 'google', 'utm_medium' => 'cpc'],
            $payload['website_info']['page']['query']
        );
    }

    /**
     * Runs the private pageInfo() the way a form request reaches it.
     *
     * @return array{url: string, query: array<string, string>}
     */
    private function pageInfo(string $referer, string $pageUrlInContext = '', string $providerPageUrl = ''): array
    {
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('wp_unslash')->returnArg(1);
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('wp_generate_uuid4')->justReturn('uuid');
        Functions\when('wp_rand')->justReturn(7);
        Functions\when('sanitize_key')->returnArg(1);

        if ($referer !== '') {
            $_SERVER['HTTP_REFERER'] = str_replace('SITE', $this->siteHost(), $referer);
        }

        $correlation = Correlation::fromFields($pageUrlInContext === '' ? [] : [
            Correlation::FIELD_CONTEXT => (string) json_encode([
                'page_url' => str_replace('SITE', $this->siteHost(), $pageUrlInContext),
            ]),
        ]);

        $method = new ReflectionMethod(SubmissionService::class, 'pageInfo');

        /** @var array{url: string, query: array<string, string>} */
        return $method->invoke(
            new SubmissionService(),
            $correlation,
            self::FORM_KEY,
            str_replace('SITE', $this->siteHost(), $providerPageUrl)
        );
    }

    /** The memoized allowed host, whichever test in this process set it first. */
    private function siteHost(): string
    {
        return Options::allowedHosts()[0];
    }

    public function testNothingFromTheReportedRefererIsKeptWithForwardingOff(): void
    {
        $page = $this->pageInfo('https://SITE/quote/?gclid=CLK&fbclid=FB&email=leak.check%40example.com&token=TOK&reset_key=RK&utm_source=google');

        self::assertSame(['utm_source' => 'google'], $page['query']);
    }

    public function testPercentEncodedValuesAreDecodedRatherThanMangled(): void
    {
        $this->webhookSettings = ['include_page_params' => true];

        $page = $this->pageInfo('https://SITE/quote/?ref=a%2Fb&utm_term=caf%C3%A9&email=leak.check%40example.com');

        self::assertSame(['ref' => 'a/b', 'utm_term' => 'café'], $page['query']);
    }

    public function testAPercentEncodedPathSurvivesInTheFallbackUrl(): void
    {
        self::assertSame(
            'https://' . $this->siteHost() . '/caf%C3%A9/',
            $this->pageInfo('https://SITE/caf%C3%A9/?gclid=CLK')['url']
        );
    }

    public function testARefererOnAnotherHostIsNotTheSubmittingPage(): void
    {
        self::assertSame(
            ['url' => '', 'query' => []],
            $this->pageInfo('https://elsewhere.test/page/?utm_source=theirs')
        );
    }

    public function testTheTrackersPageUrlWinsOverTheReferer(): void
    {
        self::assertSame(
            'https://' . $this->siteHost() . '/from-tracker/',
            $this->pageInfo('https://SITE/from-referer/', 'https://SITE/from-tracker/')['url']
        );
    }

    public function testAProviderReportedPageIsTheLastResortAndFollowsThePolicy(): void
    {
        $page = $this->pageInfo('', '', 'https://SITE/atomic/?utm_source=x&gclid=CLK');

        self::assertSame('https://' . $this->siteHost() . '/atomic/', $page['url']);
        self::assertSame(['utm_source' => 'x'], $page['query']);
    }
}
