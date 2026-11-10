<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Settings\Options;
use Convermetry\Webhook\DeliveryKind;
use Convermetry\Webhook\DeliveryLog;
use Convermetry\Webhook\DeliveryLogEntry;
use Convermetry\Webhook\LogOutcome;
use Convermetry\Webhook\MessageType;
use Convermetry\Webhook\TransportResult;
use PHPUnit\Framework\TestCase;

/**
 * Every Activity Log body is something the database will accept.
 *
 * Regression origin: $wpdb refuses a whole row when a value is not valid UTF-8
 * for its utf8mb4 column (wpdb::strip_invalid_text() changes it, and insert()
 * returns false). The 64 KB cap cut in bytes and could split a multibyte
 * character, and a body in another encoding was stored as received. Either way
 * the attempt had no log row, and the submission's delivery state, which is
 * computed from those rows, settled on "not_sent" for a lead that was delivered
 * or that failed every retry.
 *
 * The stand-in $wpdb below refuses invalid UTF-8 the way the real one does. The
 * WordPress suite repeats the delivery against the real one, over real HTTP.
 */
final class DeliveryLogStorageTest extends TestCase
{
    private const string MARKER = ' [TRUNCATED]';

    /** @var array<string, mixed>|null */
    private ?array $row = null;

    private mixed $previousWpdb = null;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('get_option')->alias(static fn(string $key, mixed $default = false): mixed => $key === Options::OPTION_KEY
            ? ['log_submission_data' => true]
            : $default);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('wp_json_encode')->alias(static fn($data) => json_encode($data));

        $this->previousWpdb = $GLOBALS['wpdb'] ?? null;
        $test = $this;
        $GLOBALS['wpdb'] = new class ($test) {
            public string $prefix = 'wp_';

            public function __construct(private DeliveryLogStorageTest $test)
            {
            }

            /**
             * Refuses the row when any string is not valid UTF-8, as
             * wpdb::process_fields() does for a utf8mb4 table.
             *
             * @param array<string, mixed> $row
             */
            public function insert(string $table, array $row): int|false
            {
                foreach ($row as $value) {
                    if (is_string($value) && preg_match('//u', $value) !== 1) {
                        return false;
                    }
                }

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

    /**
     * Logs one form delivery answered with $body, asserts the row was stored,
     * and returns its response_data.
     */
    private function storedResponse(string $body, int $code = 200): string
    {
        $this->row = null;

        $outcome = DeliveryLog::log(new DeliveryLogEntry(
            result: new TransportResult($code >= 200 && $code < 300, $code, 'HTTP ' . $code, $body),
            endpointUrl: 'https://receiver.test/hook',
            endpointLabel: 'Receiver',
            deliveryId: 'd1',
            messageType: MessageType::FormSubmission,
            kind: DeliveryKind::Immediate,
            attempt: 1,
            requestUrl: 'https://receiver.test/hook',
            requestHeaders: [],
            requestData: '{"form_submission":{"submission_id":"s1"}}',
            submissionId: 's1',
            conversionId: 'c1',
            formProvider: 'custom',
            formName: 'Quote',
        ));

        self::assertSame(LogOutcome::Stored, $outcome, 'The attempt must have its Activity Log row');
        self::assertNotNull($this->row);

        return (string) $this->row['response_data'];
    }

    /**
     * About 90 KB of Japanese HTML, padded so that the plain byte cut would land
     * inside a three-byte character.
     */
    private static function japaneseHtml(): string
    {
        $limit = DeliveryLog::MAX_BODY_BYTES - strlen(self::MARKER);

        for ($pad = 0; $pad < 40; $pad++) {
            $body = '<html>' . str_repeat('x', $pad) . str_repeat('<p>日本語のテキストです。</p>', 3000) . '</html>';

            if (preg_match('//u', substr($body, 0, $limit)) !== 1) {
                return $body;
            }
        }

        self::fail('No padding puts the byte cut inside a character.');
    }

    public function testAnOversizedMultibyteBodyIsCutOnACharacterBoundary(): void
    {
        $body = self::japaneseHtml();

        self::assertGreaterThan(DeliveryLog::MAX_BODY_BYTES, strlen($body));

        foreach ([200, 500] as $code) {
            $stored = $this->storedResponse($body, $code);

            self::assertLessThanOrEqual(DeliveryLog::MAX_BODY_BYTES, strlen($stored), "HTTP {$code}");
            self::assertSame(1, preg_match('//u', $stored), "HTTP {$code}: valid UTF-8");
            self::assertStringEndsWith(self::MARKER, $stored, "HTTP {$code}");
            self::assertStringStartsWith(
                substr($stored, 0, -strlen(self::MARKER)),
                $body,
                "HTTP {$code}: what is kept is the body's own beginning"
            );
            self::assertGreaterThan(
                DeliveryLog::MAX_BODY_BYTES - strlen(self::MARKER) - 4,
                strlen($stored) - strlen(self::MARKER),
                "HTTP {$code}: at most one character is given up"
            );
        }
    }

    /** What the transport hands over for a large response: 64 KB, cut in bytes. */
    public function testABodyTheTransportCutMidCharacterIsStored(): void
    {
        $body   = substr(self::japaneseHtml(), 0, DeliveryLog::MAX_BODY_BYTES);
        $stored = $this->storedResponse($body);

        self::assertLessThanOrEqual(DeliveryLog::MAX_BODY_BYTES, strlen($stored));
        self::assertSame(1, preg_match('//u', $stored));
    }

    public function testAnOversizedAsciiBodyStillFillsTheCap(): void
    {
        $stored = $this->storedResponse(str_repeat('a', 90000));

        self::assertSame(DeliveryLog::MAX_BODY_BYTES, strlen($stored));
        self::assertStringEndsWith(self::MARKER, $stored);
    }

    /** An ISO-8859-1 error page: "é" is the single byte 0xE9. */
    public function testABodyInAnotherEncodingIsStoredAsValidUtf8(): void
    {
        $stored = $this->storedResponse("<h1>Erreur</h1><p>Caf\xE9 ferm\xE9 &amp; <b>indisponible</b></p>", 500);

        self::assertSame("<h1>Erreur</h1><p>Caf\u{FFFD} ferm\u{FFFD} &amp; <b>indisponible</b></p>", $stored,
            'Only the invalid bytes change; markup and entities are kept as received');
    }

    public function testAValidBodyIsStoredUnchanged(): void
    {
        $body = "<p>日本語 &lt;ok&gt; café</p>\n\t\"quoted\" 'single' 🎉";

        self::assertSame($body, $this->storedResponse($body));
    }
}
