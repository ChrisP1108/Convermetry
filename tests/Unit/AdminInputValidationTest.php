<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Admin\AdminRequest;
use Convermetry\Admin\ReportPeriod;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Notifications\NotificationSettings;
use Convermetry\Settings\WebhookSettingsInput;
use Convermetry\Support\KeyValuePairs;
use PHPUnit\Framework\TestCase;

/**
 * The validators every admin save runs its input through, without WordPress.
 *
 * Their real-WordPress counterparts — the handlers, nonces and capabilities
 * around them — are exercised end to end by
 * tests/WordPress/AdminRequestHandlersTest. These pin the rules themselves:
 * which values survive byte for byte, which rows are rejected and counted, and
 * which request shapes are malformed and must store nothing at all.
 */
final class AdminInputValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('wp_unslash')->alias(static fn(mixed $v): mixed => is_string($v) ? stripslashes($v) : $v);
        Functions\when('sanitize_text_field')->alias(
            static fn(mixed $v): string => is_string($v) ? trim((string) preg_replace('/\s+/', ' ', strip_tags($v))) : ''
        );
        Functions\when('sanitize_key')->alias(
            static fn(mixed $v): string => is_scalar($v) ? (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)) : ''
        );
        Functions\when('sanitize_email')->alias(static fn(string $v): string => trim($v));
        Functions\when('is_email')->alias(static fn(string $v): bool => (bool) filter_var($v, FILTER_VALIDATE_EMAIL));
        Functions\when('get_option')->justReturn([]);

        // Stand-ins that keep the two properties the endpoint rules rely on:
        // esc_url_raw() refuses a scheme outside the allowed list, and
        // wp_http_validate_url() refuses local hosts.
        Functions\when('esc_url_raw')->alias(static function (string $url, ?array $protocols = null): string {
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            return in_array($scheme, $protocols ?? ['http', 'https'], true) ? $url : '';
        });
        Functions\when('wp_http_validate_url')->alias(
            static fn(string $url): string|false => in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true) ? false : $url
        );
    }

    protected function tearDown(): void
    {
        $_GET = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── Headers and query parameters ─────────────────────────────────────────

    public function testHeaderValuesAndCredentialsSurviveByteForByte(): void
    {
        $result = KeyValuePairs::fromHeaderInput([
            ['key' => 'Authorization', 'value' => 'Bearer abc%2Fdef<urn:x>"q"'],
            ['key' => ' X-Trace ', 'value' => "  a\tb  "],
            ['key' => '', 'value' => 'blank rows are skipped, not counted'],
        ]);

        self::assertFalse($result['malformed']);
        self::assertSame(0, $result['rejected']);
        self::assertSame([
            ['key' => 'Authorization', 'value' => 'Bearer abc%2Fdef<urn:x>"q"'],
            ['key' => 'X-Trace', 'value' => "a\tb"],
        ], $result['pairs']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedHeaders(): array
    {
        return [
            'space in name'     => ['X Api Key', 'v'],
            'colon in name'     => ['X-Api:Key', 'v'],
            'non-ASCII name'    => ['X-Ünicode', 'v'],
            'CRLF in value'     => ['X-Ok', "a\r\nSet-Cookie: x=1"],
            'LF in value'       => ['X-Ok', "a\nb"],
            'NUL in value'      => ['X-Ok', "a\0b"],
            'DEL in value'      => ['X-Ok', "a\x7Fb"],
            'invalid UTF-8'     => ['X-Ok', "a\xC3\x28"],
            'over-long value'   => ['X-Ok', str_repeat('a', KeyValuePairs::MAX_VALUE_LEN + 1)],
            'over-long name'    => [str_repeat('a', KeyValuePairs::MAX_NAME_LEN + 1), 'v'],
        ];
    }

    /**
     * @dataProvider rejectedHeaders
     */
    public function testAnInvalidHeaderRowIsRejectedAndCounted(string $name, string $value): void
    {
        $result = KeyValuePairs::fromHeaderInput([['key' => $name, 'value' => $value], ['key' => 'X-Kept', 'value' => 'yes']]);

        self::assertFalse($result['malformed']);
        self::assertSame(1, $result['rejected']);
        self::assertSame([['key' => 'X-Kept', 'value' => 'yes']], $result['pairs']);
    }

    public function testQueryValuesKeepEveryCharacterTheEncoderHandles(): void
    {
        $result = KeyValuePairs::fromQueryInput([
            ['key' => 'utm source', 'value' => 'a%2Fb&c=d+e <f>'],
            ['key' => 'bad', 'value' => "x\ny"],
        ]);

        self::assertSame([['key' => 'utm source', 'value' => 'a%2Fb&c=d+e <f>']], $result['pairs']);
        self::assertSame(1, $result['rejected']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedPairLists(): array
    {
        return [
            'a string'             => ['Authorization: x'],
            'a row that is text'   => [['Authorization: x']],
            'a name that is array' => [[['key' => ['Authorization'], 'value' => 'x']]],
            'a value that is array'=> [[['key' => 'Authorization', 'value' => ['x']]]],
            'nested rows'          => [[[['key' => 'a', 'value' => 'b']]]],
            'an integer'           => [42],
        ];
    }

    /**
     * @dataProvider malformedPairLists
     */
    public function testAMalformedPairListYieldsNothingToStore(mixed $raw): void
    {
        foreach ([KeyValuePairs::fromHeaderInput($raw), KeyValuePairs::fromQueryInput($raw)] as $result) {
            self::assertTrue($result['malformed']);
            self::assertSame([], $result['pairs']);
        }
    }

    public function testAnAbsentPairListIsEmptyRatherThanMalformed(): void
    {
        self::assertSame(['pairs' => [], 'rejected' => 0, 'malformed' => false], KeyValuePairs::fromHeaderInput(null));
    }

    // ── Form keys ────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function validFormKeys(): array
    {
        return [
            'Elementor name with spaces and capitals' => ['elementor:Contact Form 2'],
            'double space kept'                       => ['elementor:Contact  Form'],
            'percent and markup kept verbatim'        => ['elementor:50%ab <Promo>'],
            'Atomic element id'                       => ['elementor_atomic:7f3a9c1'],
            'numeric native id'                       => ['gravityforms:12'],
            'identity containing a colon'             => ['bricks:abc:def'],
        ];
    }

    /**
     * @dataProvider validFormKeys
     */
    public function testAValidFormKeyIsReturnedUnchanged(string $key): void
    {
        self::assertSame($key, FormProviderRegistry::validFormKey($key));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidFormKeys(): array
    {
        return [
            'array'             => [['elementor:Contact']],
            'integer'           => [12],
            'empty'             => [''],
            'no provider'       => ['Contact Form'],
            'uppercase provider'=> ['Elementor:Contact'],
            'empty identity'    => ['elementor:'],
            'blank identity'    => ['elementor:   '],
            'newline'           => ["elementor:Contact\nForm"],
            'NUL'               => ["elementor:Contact\0"],
            'invalid UTF-8'     => ["elementor:\xC3\x28"],
            'too long'          => ['elementor:' . str_repeat('a', 600)],
        ];
    }

    /**
     * @dataProvider invalidFormKeys
     */
    public function testAnInvalidFormKeyIsRefused(mixed $key): void
    {
        self::assertSame('', FormProviderRegistry::validFormKey($key));
    }

    public function testNotificationRulesKeepFormKeysVerbatim(): void
    {
        self::assertSame(
            ['elementor:Contact Form 2' => 'enabled', 'gravityforms:3' => 'disabled'],
            NotificationSettings::sanitizeFormRules([
                'elementor:Contact Form 2' => 'enabled',
                'gravityforms:3'           => 'disabled',
                'elementor:Inherited'      => 'inherit',
                'Not A Key'                => 'enabled',
                'bricks:x'                 => ['enabled'],
                7                          => 'enabled',
            ])
        );
    }

    // ── Notification settings shape ──────────────────────────────────────────

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedNotificationSettings(): array
    {
        $valid = ['recipients' => 'ops@example.com', 'subject' => 'New lead', 'scope' => 'all'];

        return [
            'recipients missing'   => [array_diff_key($valid, ['recipients' => 0])],
            'recipients array'     => [['recipients' => ['ops@example.com']] + $valid],
            'subject array'        => [['subject' => ['x']] + $valid],
            'scope missing'        => [array_diff_key($valid, ['scope' => 0])],
            'scope unknown'        => [['scope' => 'some'] + $valid],
            'toggle array'         => [['include_ip' => ['1']] + $valid],
            'forms not a map'      => [['forms' => 'x'] + $valid],
            'rule not a string'    => [['forms' => ['elementor:A' => ['enabled']]] + $valid],
            'rule unknown'         => [['forms' => ['elementor:A' => 'always']] + $valid],
        ];
    }

    /**
     * @dataProvider malformedNotificationSettings
     * @param array<string, mixed> $raw
     */
    public function testAMalformedNotificationSubmissionIsRefusedWhole(array $raw): void
    {
        self::assertNull(NotificationSettings::fromSubmission($raw));
    }

    public function testAWellFormedNotificationSubmissionIsSanitized(): void
    {
        $clean = NotificationSettings::fromSubmission([
            'enabled'    => '1',
            'recipients' => "ops@example.com\nnope",
            'subject'    => "New\r\nlead",
            'scope'      => 'selected',
            'forms'      => ['elementor:Contact Form 2' => 'enabled', 'gravityforms:1' => 'inherit'],
        ]);

        self::assertIsArray($clean);
        self::assertTrue($clean['enabled']);
        self::assertSame(['ops@example.com'], $clean['recipients']);
        self::assertSame('New lead', $clean['subject']);
        self::assertSame('selected', $clean['scope']);
        self::assertSame(['elementor:Contact Form 2' => 'enabled'], $clean['forms']);
    }

    // ── Webhook settings ─────────────────────────────────────────────────────

    public function testWebhookSettingsKeepIdsSecretsAndHeaders(): void
    {
        $result = WebhookSettingsInput::sanitize(
            $this->webhookFields([
                ['id' => 'known-1', 'url' => 'https://a.example/hook', 'label' => '<b>A</b>', 'secret' => ' s%41<x> ', 'analytics' => '1'],
                ['id' => 'known-1', 'url' => 'https://b.example/hook', 'forms' => '1'],
                ['id' => 'forged', 'url' => 'https://c.example/hook', 'forms' => '1'],
                ['url' => '   '],
            ]),
            ['known-1' => true],
            false
        );

        self::assertFalse($result['malformed']);
        self::assertSame([], $result['rejected']);

        $endpoints = $result['settings']['endpoints'];
        self::assertSame(['known-1', '', ''], array_column($endpoints, 'id'), 'Known once; never claimed twice; never forged.');
        self::assertSame('A', $endpoints[0]['label']);
        self::assertSame('s%41<x>', $endpoints[0]['secret']);
        self::assertSame([['key' => 'Authorization', 'value' => 'Bearer t%2F']], $result['settings']['global_headers']);
        self::assertSame('weekly', $result['settings']['interval']);
        self::assertTrue($result['settings']['active']);
    }

    public function testARejectedEndpointIsReportedAsStructuredSanitizedData(): void
    {
        $result = WebhookSettingsInput::sanitize(
            $this->webhookFields([
                ['url' => 'https://ok.example/hook'],
                ['url' => 'http://plain.example/<script>alert(1)</script>'],
                ['url' => "https://127.0.0.1/\r\n<img src=x onerror=alert(1)>"],
                ['url' => 'javascript:alert(1)'],
            ]),
            [],
            false
        );

        self::assertFalse($result['malformed']);
        self::assertCount(1, $result['settings']['endpoints']);
        self::assertSame([2, 3, 4], array_column($result['rejected'], 'row'));
        self::assertSame(
            [WebhookSettingsInput::REASON_INSECURE, WebhookSettingsInput::REASON_INVALID, WebhookSettingsInput::REASON_INVALID],
            array_column($result['rejected'], 'reason')
        );

        foreach ($result['rejected'] as $entry) {
            self::assertDoesNotMatchRegularExpression('/[<>\x00-\x1F]/', $entry['display']);
            self::assertLessThanOrEqual(WebhookSettingsInput::MAX_DISPLAY_LEN, mb_strlen($entry['display']));
        }
    }

    public function testHttpIsAcceptedOnlyWhenTheDevelopmentFilterAllowsIt(): void
    {
        $fields = $this->webhookFields([['url' => 'http://dev.example/hook']]);

        self::assertCount(0, WebhookSettingsInput::sanitize($fields, [], false)['settings']['endpoints']);
        self::assertCount(1, WebhookSettingsInput::sanitize($fields, [], true)['settings']['endpoints']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedWebhookFields(): array
    {
        return [
            'no endpoint list'      => [['endpoints' => null]],
            'empty endpoint list'   => [['endpoints' => []]],
            'endpoint list string'  => [['endpoints' => 'x']],
            'endpoint row string'   => [['endpoints' => ['x']]],
            'url array'             => [['endpoints' => [['url' => ['https://a.example']]]]],
            'id array'              => [['endpoints' => [['url' => 'https://a.example', 'id' => ['x']]]]],
            'secret with a newline' => [['endpoints' => [['url' => 'https://a.example', 'secret' => "a\nb"]]]],
            'interval missing'      => [['interval' => null]],
            'interval unknown'      => [['interval' => 'minutely']],
            'failure mode unknown'  => [['failure_mode' => 'loud']],
            'toggle array'          => [['active' => ['1']]],
            'shared secret array'   => [['shared_secret' => ['x']]],
            'headers malformed'     => [['global_headers' => 'x']],
            'query malformed'       => [['global_query' => [['key' => 'a', 'value' => ['b']]]]],
        ];
    }

    /**
     * @dataProvider malformedWebhookFields
     * @param array<string, mixed> $override
     */
    public function testAMalformedWebhookFormYieldsNothingToStore(array $override): void
    {
        $result = WebhookSettingsInput::sanitize(array_merge($this->webhookFields([['url' => 'https://a.example/hook']]), $override), [], false);

        self::assertTrue($result['malformed']);
        self::assertSame([], $result['settings']);
    }

    // ── Request parsing ──────────────────────────────────────────────────────

    /**
     * @return array<string, array{string, int}>
     */
    public static function rowIds(): array
    {
        return [
            'plain'         => ['42', 42],
            'zero'          => ['0', 0],
            'leading zero'  => ['042', 0],
            'negative'      => ['-1', 0],
            'trailing junk' => ['5abc', 0],
            'float'         => ['5.0', 0],
            'space'         => [' 5', 0],
            'empty'         => ['', 0],
            'overflow'      => [str_repeat('9', 30), 0],
        ];
    }

    /**
     * @dataProvider rowIds
     */
    public function testARowIdIsDigitsOnly(string $raw, int $expected): void
    {
        self::assertSame($expected, AdminRequest::positiveId($raw));
    }

    public function testAScalarReadTreatsAnArrayAsAbsent(): void
    {
        self::assertSame('', AdminRequest::scalar(['page' => ['2']], 'page'));
        self::assertSame('', AdminRequest::scalar([], 'page'));
        self::assertSame("O'Neil", AdminRequest::scalar(['q' => "O\\'Neil"], 'q'));
        self::assertSame('3', AdminRequest::scalar(['page' => 3], 'page'));
    }

    // ── The reporting-period filter ──────────────────────────────────────────

    public function testThePeriodFilterHonoursOnlyItsOwnNonce(): void
    {
        Functions\when('wp_verify_nonce')->alias(
            static fn(string $nonce, string $action): int|false => $nonce === 'nonce-for-' . $action ? 1 : false
        );
        Functions\when('wp_create_nonce')->alias(static fn(string $action): string => 'nonce-for-' . $action);

        $read = static function (array $query): array {
            $_GET     = $query;
            $selected = ReportPeriod::fromRequest('cvmtry_goals_period', [7, 30, 90]);

            return [$selected->days, $selected->refused];
        };

        self::assertSame([30, false], $read([]));
        self::assertSame([7, false], $read(ReportPeriod::queryArgs('cvmtry_goals_period', 7)));
        self::assertSame([30, true], $read(['period' => '7']));
        self::assertSame([30, true], $read(ReportPeriod::queryArgs('cvmtry_save_goal', 7)));
        self::assertSame([30, true], $read(['period' => '7', ReportPeriod::NONCE_FIELD => ['nonce-for-cvmtry_goals_period']]));
        self::assertSame([30, true], $read(['period' => ['7'], ReportPeriod::NONCE_FIELD => 'nonce-for-cvmtry_goals_period']));
        self::assertSame([30, true], $read(ReportPeriod::queryArgs('cvmtry_goals_period', 45)));
    }

    public function testThePeriodIsCarriedOnlyWhenItIsNotTheDefault(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_create_nonce')->alias(static fn(string $action): string => 'nonce-for-' . $action);

        $_GET = [];
        self::assertSame([], ReportPeriod::fromRequest('x', [7, 30])->carryArgs('x'));

        $_GET = ['period' => '7', ReportPeriod::NONCE_FIELD => 'n'];
        self::assertSame(
            ['period' => '7', ReportPeriod::NONCE_FIELD => 'nonce-for-x'],
            ReportPeriod::fromRequest('x', [7, 30])->carryArgs('x')
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function webhookFields(array $rows): array
    {
        return [
            'endpoints'      => $rows,
            'active'         => '1',
            'interval'       => 'weekly',
            'shared_secret'  => '',
            'failure_mode'   => 'background',
            'global_headers' => [['key' => 'Authorization', 'value' => 'Bearer t%2F']],
            'global_query'   => null,
        ];
    }
}
