<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Settings\Options;
use Convermetry\Webhook\DeliveryKind;
use Convermetry\Webhook\DeliveryLog;
use Convermetry\Webhook\DeliveryLogEntry;
use Convermetry\Webhook\MessageType;
use Convermetry\Webhook\TransportResult;
use PHPUnit\Framework\TestCase;

/**
 * What the Activity Log keeps when "Store form submission data" is off.
 *
 * Regression origin: only the request column was stripped, so a receiver that
 * echoed its input put the visitor's values straight back into response_data.
 */
final class DeliveryLogPrivacyTest extends TestCase
{
    private const string PLACEHOLDER = '[NOT STORED — disabled in Settings]';

    /** @var array<string, mixed> */
    private array $settings = ['log_submission_data' => false];

    /** @var array<string, mixed>|null */
    private ?array $row = null;

    private mixed $previousWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(fn(string $key, mixed $default = false): mixed => $key === Options::OPTION_KEY
            ? $this->settings
            : $default);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('wp_json_encode')->alias(static fn($data) => json_encode($data, JSON_UNESCAPED_UNICODE));
        Functions\when('remove_query_arg')->alias(static function (array $keys, string $url): string {
            [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
            parse_str($query, $params);
            $left = http_build_query(array_diff_key($params, array_flip($keys)));

            return $base . ($left !== '' ? '?' . $left : '');
        });

        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $test = $this;
        $GLOBALS['wpdb'] = new class ($test) {
            public string $prefix = 'wp_';

            public function __construct(private DeliveryLogPrivacyTest $test)
            {
            }

            /** @param array<string, mixed> $row */
            public function insert(string $table, array $row): int
            {
                $this->test->captured($row);

                return 1;
            }
        };
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb'] = $this->previousWpdb;
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param array<string, mixed> $row */
    public function captured(array $row): void
    {
        $this->row = $row;
    }

    private function payload(): string
    {
        return (string) json_encode([
            'website_info'    => ['page' => ['url' => 'https://example.com/quote/', 'query' => ['gclid' => 'CLKVALUE']]],
            'form_submission' => [
                'submission_id'   => 's1',
                'ip_address'      => '203.0.113.42',
                'submission_data' => [['id' => 'email', 'label' => 'Email', 'value' => 'ada@example.com']],
            ],
        ]);
    }

    /** @return array<string, mixed> The stored row. */
    private function log(TransportResult $result, MessageType $type = MessageType::FormSubmission, string $url = 'https://receiver.test/hook?gclid=CLKVALUE&src=site'): array
    {
        DeliveryLog::log(new DeliveryLogEntry(
            result: $result,
            endpointUrl: 'https://receiver.test/hook',
            endpointLabel: 'Receiver',
            deliveryId: 'd1',
            messageType: $type,
            kind: DeliveryKind::Immediate,
            attempt: 1,
            requestUrl: $url,
            requestHeaders: [],
            requestData: $this->payload(),
            submissionId: 's1',
            conversionId: 'c1',
            formProvider: 'custom',
            formName: 'Quote',
        ));

        self::assertNotNull($this->row);

        return $this->row;
    }

    /** Every way a receiver can hand the payload back. */
    public function testAnEchoingReceiverLeavesNoVisitorDataInTheStoredRow(): void
    {
        $bodies = [
            'verbatim'         => $this->payload(),
            'inside a string'  => (string) json_encode(['data' => $this->payload()]),
            'plain text'       => 'Thanks ada@example.com, from 203.0.113.42, click CLKVALUE',
            'an HTML error'    => '<h1>400</h1><pre>' . $this->payload() . '</pre>',
        ];

        foreach ($bodies as $shape => $body) {
            $row    = $this->log(new TransportResult(true, 200, 'Delivered', $body));
            $stored = implode(' ', array_map(strval(...), $row));

            foreach (['ada@example.com', '203.0.113.42', 'CLKVALUE'] as $value) {
                self::assertStringNotContainsString($value, $stored, "Echoed as {$shape}");
            }

            self::assertSame(self::PLACEHOLDER, $row['response_data'], "Echoed as {$shape}");
            self::assertSame(200, $row['response_code'], 'The status is metadata and stays.');
        }
    }

    public function testConfiguredUrlParametersStayWhenForwardedOnesAreRemoved(): void
    {
        self::assertSame(
            'https://receiver.test/hook?src=site',
            $this->log(new TransportResult(true, 200, 'Delivered', ''))['request_url']
        );
    }

    /** It is the plugin's own message, and all an admin has to go on. */
    public function testATransportErrorIsStillRecorded(): void
    {
        $row = $this->log(TransportResult::failure('cURL error 7: connection refused'));

        self::assertSame('{"error":"cURL error 7: connection refused"}', $row['response_data']);
    }

    public function testAnEmptyResponseStoresNoPlaceholder(): void
    {
        self::assertSame('', $this->log(new TransportResult(true, 204, 'Delivered', ''))['response_data']);
    }

    public function testWithTheSettingOnTheResponseIsKept(): void
    {
        $this->settings = ['log_submission_data' => true];

        $row = $this->log(new TransportResult(true, 200, 'Delivered', '{"ok":true}'));

        self::assertSame('{"ok":true}', $row['response_data']);
        self::assertStringContainsString('gclid=CLKVALUE', (string) $row['request_url']);
    }

    /** The switch is about lead data: a report's response is not covered by it. */
    public function testAnAnalyticsReportsResponseIsKept(): void
    {
        $row = $this->log(new TransportResult(true, 200, 'Delivered', '{"ok":true}'), MessageType::AnalyticsReport);

        self::assertSame('{"ok":true}', $row['response_data']);
    }
}
