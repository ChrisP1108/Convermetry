<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Forms\FormSettings;
use Convermetry\Settings\Options;
use Convermetry\Webhook\RequestFactory;
use PHPUnit\Framework\TestCase;

/**
 * Query-parameter and header merge precedence.
 *
 * Four layers, each overriding the previous one for a shared key:
 * global → page → per-form → runtime.
 */
final class RequestFactoryTest extends TestCase
{
    private const FORM_KEY = 'elementor:a1b2c3d';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(static function (string $key, $default = false) {
            if ($key === Options::WEBHOOK_OPTION_KEY) {
                return [
                    'endpoints'           => [],
                    'global_query'        => [['key' => 'src', 'value' => 'global'], ['key' => 'g', 'value' => '1']],
                    'global_headers'      => [['key' => 'X-Tier', 'value' => 'global'], ['key' => 'X-G', 'value' => '1']],
                    'include_page_params' => false,
                ];
            }

            if ($key === FormSettings::OPTION_KEY) {
                return [self::FORM_KEY => [
                    'query_params'        => [['key' => 'src', 'value' => 'form']],
                    'headers'             => [['key' => 'X-Tier', 'value' => 'form']],
                    'include_page_params' => true,
                ]];
            }

            return $default;
        });

        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('apply_filters')->returnArg(2);

        Functions\when('add_query_arg')->alias(static function (array $args, string $url): string {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testHeaderPrecedenceRunsGlobalThenFormThenRuntime(): void
    {
        $headers = RequestFactory::buildHeaders(self::FORM_KEY, ['X-Tier' => 'runtime']);

        self::assertSame('runtime', $headers['X-Tier'], 'Runtime must win');
        self::assertSame('1', $headers['X-G'], 'Non-conflicting global headers survive');
        self::assertSame('application/json; charset=utf-8', $headers['Content-Type']);
    }

    public function testFormHeadersOverrideGlobalWhenNoRuntimeValue(): void
    {
        $headers = RequestFactory::buildHeaders(self::FORM_KEY);

        self::assertSame('form', $headers['X-Tier']);
    }

    public function testAnalyticsDeliveriesGetGlobalHeadersOnly(): void
    {
        $headers = RequestFactory::buildHeaders();

        self::assertSame('global', $headers['X-Tier']);
    }

    public function testQueryPrecedenceRunsGlobalThenPageThenFormThenRuntime(): void
    {
        $url = RequestFactory::buildUrl(
            'https://receiver.test/hook',
            self::FORM_KEY,
            ['src' => 'page', 'p' => 'page'],
            ['src' => 'runtime']
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('runtime', $query['src'], 'Runtime must win');
        self::assertSame('page', $query['p'], 'Page params pass through when enabled for the form');
        self::assertSame('1', $query['g'], 'Non-conflicting global params survive');
    }

    public function testPageParamsAreExcludedForAnalyticsDeliveries(): void
    {
        $url = RequestFactory::buildUrl('https://receiver.test/hook', '', ['p' => 'page']);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertArrayNotHasKey('p', $query);
        self::assertSame('global', $query['src']);
    }

    /**
     * A row recorded before the page-parameter policy still holds everything,
     * and forwarding being on must not send its secrets to the endpoint.
     */
    public function testForwardingNeverSendsASecretFromAnOlderRow(): void
    {
        $url = RequestFactory::buildUrl(
            'https://receiver.test/hook',
            self::FORM_KEY,
            ['gclid' => 'keep', 'reset_key' => 'secret', 'token' => 'secret', 'email' => 'a@example.com']
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertSame('keep', $query['gclid']);
        self::assertArrayNotHasKey('reset_key', $query);
        self::assertArrayNotHasKey('token', $query);
        self::assertArrayNotHasKey('email', $query);
    }

    public function testEndpointUrlIsReturnedUnchangedWhenNothingToMerge(): void
    {
        Functions\when('get_option')->alias(static fn(string $key, $default = false) => $key === Options::WEBHOOK_OPTION_KEY
            ? ['endpoints' => [], 'global_query' => [], 'global_headers' => [], 'include_page_params' => false]
            : $default);

        self::assertSame('https://receiver.test/hook', RequestFactory::buildUrl('https://receiver.test/hook'));
    }

    // ── Header names are case-insensitive ────────────────────────────────────

    /**
     * Global headers saved in another letter case than Convermetry's own,
     * as the webhook-protocol scenario configured them.
     */
    private function storeHeaders(array $global, array $form = []): void
    {
        Functions\when('get_option')->alias(static function (string $key, $default = false) use ($global, $form) {
            if ($key === Options::WEBHOOK_OPTION_KEY) {
                return [
                    'endpoints'      => [[
                        'id'        => 'ep_receiver',
                        'url'       => 'https://receiver.test/hook',
                        'label'     => 'Receiver',
                        'secret'    => 's3cret',
                        'analytics' => true,
                        'forms'     => true,
                    ]],
                    'global_query'   => [],
                    'global_headers' => $global,
                ];
            }

            if ($key === FormSettings::OPTION_KEY) {
                return [self::FORM_KEY => ['query_params' => [], 'headers' => $form, 'include_page_params' => false]];
            }

            return $default;
        });
    }

    /**
     * How many headers a set holds under one name, as HTTP reads names.
     *
     * @param array<string, string> $headers
     */
    private static function copies(array $headers, string $name): int
    {
        return count(array_filter(array_keys($headers), static fn(string $key): bool => strtolower($key) === strtolower($name)));
    }

    /**
     * The webhook-protocol scenario: with global headers x-convermetry-signature,
     * idempotency-key and user-agent saved, each was sent twice, and a receiver
     * that joined them ("sha256=stale, sha256=<real>") failed every signature
     * check and every de-duplication.
     */
    public function testAReservedHeaderInAnotherCaseIsNeverSentBesideConvermetrysOwn(): void
    {
        $this->storeHeaders([
            ['key' => 'x-convermetry-signature', 'value' => 'sha256=stale'],
            ['key' => 'idempotency-key', 'value' => 'fixed'],
            ['key' => 'user-agent', 'value' => 'Mine/1.0'],
            ['key' => 'HOST', 'value' => 'other.example'],
            ['key' => 'X-Kept', 'value' => 'yes'],
        ], [['key' => 'Content-length', 'value' => '1']]);

        $frozen = RequestFactory::buildHeaders(self::FORM_KEY, ['Transfer-Encoding' => 'chunked', 'connection' => 'close']);

        foreach (RequestFactory::RESERVED_HEADERS as $name) {
            self::assertSame(0, self::copies($frozen, $name), "{$name} is not composed from any configured layer");
        }
        self::assertSame('yes', $frozen['X-Kept']);

        $body = '{"delivery_id":"d1"}';
        $sent = RequestFactory::withProtocolHeaders($frozen, 'ep_receiver', $body, 'd1');

        foreach (['User-Agent', 'Idempotency-Key', 'X-Convermetry-Signature'] as $name) {
            self::assertSame(1, self::copies($sent, $name), "exactly one {$name}");
        }
        self::assertSame('d1', $sent['Idempotency-Key']);
        self::assertSame('sha256=' . hash_hmac('sha256', $body, 's3cret'), $sent['X-Convermetry-Signature']);
    }

    /**
     * A queued lead or a pending analytics retry froze its headers under
     * 1.1.5, duplicates included. They are dropped at send time.
     */
    public function testAHeaderSetFrozenBefore116LosesItsReservedCopiesAtSendTime(): void
    {
        $this->storeHeaders([]);

        $sent = RequestFactory::withProtocolHeaders([
            'Content-Type'            => 'application/json; charset=utf-8',
            'x-convermetry-signature' => 'sha256=stale',
            'IDEMPOTENCY-KEY'         => 'fixed',
            'host'                    => 'other.example',
            'X-Kept'                  => 'yes',
        ], 'ep_receiver', '{}', 'd2');

        self::assertSame(1, self::copies($sent, 'X-Convermetry-Signature'));
        self::assertSame(1, self::copies($sent, 'Idempotency-Key'));
        self::assertSame(0, self::copies($sent, 'Host'));
        self::assertSame('d2', $sent['Idempotency-Key']);
        self::assertSame('yes', $sent['X-Kept']);
    }

    /**
     * Content-Type stays configurable, but one configured in another case
     * replaces the JSON default instead of travelling next to it; and a later
     * layer replaces an earlier one whatever case either was typed in.
     */
    public function testLaterLayersReplaceEarlierOnesWhateverTheCase(): void
    {
        $this->storeHeaders(
            [['key' => 'content-type', 'value' => 'application/vnd.api+json'], ['key' => 'X-Api-Key', 'value' => 'global']],
            [['key' => 'x-api-key', 'value' => 'form']]
        );

        $headers = RequestFactory::buildHeaders(self::FORM_KEY, ['X-API-KEY' => 'runtime']);

        self::assertSame(1, self::copies($headers, 'Content-Type'));
        self::assertSame('application/vnd.api+json', $headers['content-type']);
        self::assertSame(1, self::copies($headers, 'X-Api-Key'));
        self::assertSame('runtime', $headers['X-API-KEY']);
    }

    // ── Stored header values are checked again when read ─────────────────────

    /**
     * The Webhooks and Forms screens refuse a line break in a header value,
     * but a value written to the option directly (a migration, an import, a
     * database edit) was sent as stored, and WordPress's transport wrote the
     * text after the line break as a header line of its own.
     */
    public function testAStoredHeaderValueWithALineBreakIsNeverSent(): void
    {
        $this->storeHeaders(
            [
                ['key' => 'X-Global-Injected', 'value' => "a\r\nX-Smuggled: 1"],
                ['key' => 'X-Global-Ok', 'value' => 'kept'],
            ],
            [
                ['key' => 'X-Form-Injected', 'value' => "b\nX-Smuggled: 2"],
                ['key' => 'X-Form-Bare-Cr', 'value' => "c\rd"],
                ['key' => 'X-Form-Ok', 'value' => 'kept too'],
            ]
        );

        $headers = RequestFactory::buildHeaders(self::FORM_KEY);

        foreach (['X-Global-Injected', 'X-Form-Injected', 'X-Form-Bare-Cr'] as $name) {
            self::assertArrayNotHasKey($name, $headers, "{$name} holds a line break and must not be sent");
        }
        foreach ($headers as $name => $value) {
            self::assertDoesNotMatchRegularExpression('/[\r\n]/', $name . $value, "no header carries a line break ({$name})");
        }
        self::assertSame('kept', $headers['X-Global-Ok']);
        self::assertSame('kept too', $headers['X-Form-Ok']);

        // Analytics deliveries read the global layer alone.
        self::assertArrayNotHasKey('X-Global-Injected', RequestFactory::buildHeaders());
    }

    /**
     * The same check as the screens apply on save: a name that is not an
     * HTTP token, a control character, and invalid UTF-8 are dropped; a tab
     * inside a value is legal and kept; surrounding blanks are trimmed. A row
     * with a blank name stays for the editors, which render it back.
     */
    public function testStoredHeadersAreCheckedByTheSaveRule(): void
    {
        $this->storeHeaders([
            ['key' => 'X-Bad Name', 'value' => 'v'],
            ['key' => "X-Name\r\nX-Smuggled", 'value' => 'v'],
            ['key' => 'X-Nul', 'value' => "a\x00b"],
            ['key' => 'X-Latin1', 'value' => "caf\xE9"],
            ['key' => 'X-Tab', 'value' => " a\tb  "],
            ['key' => '', 'value' => "half\ntyped"],
        ]);

        self::assertSame(
            [['key' => 'X-Tab', 'value' => "a\tb"], ['key' => '', 'value' => "half\ntyped"]],
            Options::globalHeaders()
        );

        $sent = RequestFactory::buildHeaders();
        unset($sent['Content-Type']);
        self::assertSame(['X-Tab' => "a\tb"], $sent);
    }
}
