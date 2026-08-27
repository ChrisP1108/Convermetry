<?php

declare(strict_types=1);

namespace Convermetry\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Convermetry\Support\Http;
use Convermetry\Webhook\FormDeliveryQueue;
use PHPUnit\Framework\TestCase;

/**
 * The webhook delivery lifecycle: which actions fire, in what order, and — just
 * as important — which ones do NOT fire when nothing reached the wire.
 *
 * Two halves, and the docblocks say which is which because they are not worth
 * the same.
 *
 * The BEHAVIOURAL half drives FormDeliveryQueue::testEndpoint() for real. It is
 * the one delivery path that needs neither a cron lease, a claimed queue row,
 * nor a $wpdb whose return values decide anything — so the ordering assertions
 * here are observations of the running code, not of its source.
 *
 * The SOURCE-CONTRACT half covers the paths that do need all of that. Those
 * assertions prove that the pairing of an action with the call it announces
 * survived a refactor; they prove nothing about runtime ordering, and are not
 * offered as if they did. This is the same technique NotificationLifecycleTest
 * uses for claims its harness cannot reach.
 */
final class WebhookLifecycleTest extends TestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../';

    /** @var list<array{0: string, 1: list<mixed>}> */
    private array $fired = [];

    /** @var array<string, mixed> */
    private array $lastRequest = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->fired       = [];
        $this->lastRequest = [];

        Functions\when('do_action')->alias(function (string $hook, mixed ...$args): void {
            $this->fired[] = [$hook, $args];
        });

        Functions\when('sanitize_text_field')->alias(static fn($v): string => trim(strip_tags((string) $v)));
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('site_url')->justReturn('https://example.com');
        Functions\when('get_bloginfo')->justReturn('Example');
        Functions\when('esc_url_raw')->returnArg(1);
        Functions\when('wp_parse_url')->alias(static fn(string $u, int $c = -1) => parse_url($u, $c));
        Functions\when('wp_json_encode')->alias(
            static fn($data, $options = 0, $depth = 512) => json_encode($data, $options, $depth)
        );
        Functions\when('wp_rand')->justReturn(7);
        Functions\when('add_query_arg')->alias(static function (array $args, string $url): string {
            return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($args);
        });
        Functions\when('get_option')->justReturn([]);

        // $wpdb is a plain sink here: it exists so DeliveryLog::log() can run to
        // completion and execution can reach the actions under test. No
        // assertion in this file depends on anything it returns.
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';

            public function insert(string $table, array $row, array $formats): int
            {
                return 1;
            }
        };
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $response
     */
    private function stubTransport(array $response): void
    {
        Functions\when('wp_safe_remote_post')->alias(function (string $url, array $args) use ($response): array {
            $this->lastRequest = ['url' => $url] + $args;

            return $response;
        });
        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('wp_remote_retrieve_response_code')->justReturn($response['response']['code'] ?? 200);
        Functions\when('wp_remote_retrieve_response_message')->justReturn($response['response']['message'] ?? 'OK');
        Functions\when('wp_remote_retrieve_body')->justReturn($response['body'] ?? '');
    }

    /**
     * @return list<string>
     */
    private function firedHooks(): array
    {
        return array_values(array_filter(
            array_column($this->fired, 0),
            static fn(string $hook): bool => str_starts_with($hook, 'convermetry_')
        ));
    }

    // ---------------------------------------------------- behavioural: 2xx

    /**
     * The happy path, observed end to end. Order is the contract: a listener
     * that reacts to _succeeded must be able to assume the attempt was already
     * announced and logged.
     */
    public function testASuccessfulDeliveryFiresTheFullLifecycleInOrder(): void
    {
        $this->stubTransport(['response' => ['code' => 200, 'message' => 'OK'], 'body' => 'ok']);

        FormDeliveryQueue::testEndpoint('https://hooks.example.com/ingest');

        self::assertSame([
            'convermetry_webhook_before_send',
            'convermetry_webhook_delivery_attempted',
            'convermetry_delivery_attempt_logged',
            'convermetry_webhook_delivery_succeeded',
        ], $this->firedHooks());
    }

    public function testTheContextIdentifiesTheEndpointWithoutItsUrl(): void
    {
        $this->stubTransport(['response' => ['code' => 200, 'message' => 'OK'], 'body' => '']);

        FormDeliveryQueue::testEndpoint('https://hooks.example.com/ingest?token=SECRET');

        $context = $this->fired[0][1][0];

        self::assertSame('form_submission', $context['message_type']);
        self::assertSame('test', $context['kind']);
        self::assertTrue($context['is_test']);
        self::assertSame(1, $context['attempt']);
        self::assertSame('https://hooks.example.com', $context['endpoint_origin']);
        self::assertStringNotContainsString('SECRET', (string) json_encode($context));
    }

    // ---------------------------------------------------- behavioural: 5xx

    /**
     * A test button has no retry chain, so a failure must not fire any of the
     * chain actions — announcing a retry that will never happen would be worse
     * than announcing nothing.
     */
    public function testAFailedTestFiresNoSuccessAndNoChainAction(): void
    {
        $this->stubTransport(['response' => ['code' => 500, 'message' => 'Server Error'], 'body' => 'boom']);

        FormDeliveryQueue::testEndpoint('https://hooks.example.com/ingest');

        self::assertSame([
            'convermetry_webhook_before_send',
            'convermetry_webhook_delivery_attempted',
            'convermetry_delivery_attempt_logged',
        ], $this->firedHooks());

        [$context, $ok, $code] = $this->fired[1][1];
        self::assertFalse($ok);
        self::assertSame(500, $code);
        self::assertTrue($context['transport_attempted']);
    }

    // ------------------------------------------- behavioural: nothing sent

    /**
     * The distinction the whole `transport_attempted` flag exists for: an
     * attempt that failed before the wire is still an attempt, but nothing was
     * announced as being about to be sent, because nothing was.
     */
    public function testAnUnencodablePayloadIsAttemptedButNeverAnnouncedAsSent(): void
    {
        $this->stubTransport(['response' => ['code' => 200, 'message' => 'OK'], 'body' => '']);
        Functions\when('wp_json_encode')->alias(
            static fn($data, $options = 0, $depth = 512): string|false => is_array($data) && isset($data['schema_version'])
                ? false          // the payload itself
                : (string) json_encode($data, $options, $depth)
        );

        FormDeliveryQueue::testEndpoint('https://hooks.example.com/ingest');

        self::assertSame([
            'convermetry_webhook_delivery_attempted',
            'convermetry_delivery_attempt_logged',
        ], $this->firedHooks());

        self::assertNotContains('convermetry_webhook_before_send', $this->firedHooks());
        self::assertFalse($this->fired[0][1][0]['transport_attempted']);
    }

    // ------------------------------------------------- behavioural: timeout

    public function testTheDefaultTimeoutIsUnchangedWithNoCallback(): void
    {
        $this->stubTransport(['response' => ['code' => 200, 'message' => 'OK'], 'body' => '']);

        FormDeliveryQueue::testEndpoint('https://hooks.example.com/ingest');

        self::assertSame(Http::TIMEOUT, $this->lastRequest['timeout']);
        self::assertSame(0, $this->lastRequest['redirection']);
    }

    /**
     * Out-of-range values are ignored rather than clamped: silently turning 600
     * into 30 hides the mistake, and one request must never be able to eat the
     * queue worker's whole 45-second pass budget.
     *
     * @dataProvider timeouts
     */
    public function testTheTimeoutFilterIsBoundedAndRejectsRatherThanClamps(mixed $returned, int $expected): void
    {
        $this->stubTransport(['response' => ['code' => 200, 'message' => 'OK'], 'body' => '']);
        Functions\when('apply_filters')->alias(
            static fn(string $hook, mixed $value, mixed ...$rest) => $hook === 'convermetry_webhook_timeout'
                ? $returned
                : $value
        );

        FormDeliveryQueue::testEndpoint('https://hooks.example.com/ingest');

        self::assertSame($expected, $this->lastRequest['timeout']);
    }

    /**
     * @return array<string, array{mixed, int}>
     */
    public static function timeouts(): array
    {
        return [
            'in range'       => [25, 25],
            'lower bound'    => [1, 1],
            'upper bound'    => [30, 30],
            'zero rejected'  => [0, Http::TIMEOUT],
            'huge rejected'  => [600, Http::TIMEOUT],
            'negative'       => [-5, Http::TIMEOUT],
            'non-numeric'    => ['forever', Http::TIMEOUT],
        ];
    }

    public function testTheTimeoutCapStaysBelowTheQueueWorkerBudget(): void
    {
        self::assertLessThan(45, Http::MAX_TIMEOUT, 'One request must not be able to consume a whole worker pass.');
        self::assertSame(15, Http::TIMEOUT, 'The documented default must not drift.');
    }

    // ------------------------------------------------------ source contract

    /**
     * Source-contract, not runtime: this asserts the PAIRING survived, i.e.
     * that nobody added a sixth transport call without announcing it. It says
     * nothing about execution order, and a listener that throws still prevents
     * the request it was told about — which is documented on the hook.
     */
    public function testEveryTransportCallIsPrecededByTheBeforeSendAnnouncement(): void
    {
        $sources = $this->sourcesUnder('src');
        $sites   = 0;

        foreach ($sources as $file => $source) {
            $offset = 0;
            while (($pos = strpos($source, 'Http::postJson(', $offset)) !== false) {
                $sites++;
                $offset = $pos + 1;

                $preceding = substr($source, max(0, $pos - 400), min($pos, 400));
                self::assertStringContainsString(
                    'DeliveryContext::beforeSend(',
                    $preceding,
                    "A transport call in {$file} is not announced by DeliveryContext::beforeSend()."
                );
            }
        }

        self::assertSame(5, $sites, 'The five known delivery paths: two tests, analytics, form queue, sync.');
    }

    /**
     * Source-contract. Every Activity Log write for a delivery attempt must be
     * followed by the action reporting what became of it.
     */
    public function testEveryDeliveryLogWriteIsFollowedByTheAttemptLoggedAnnouncement(): void
    {
        foreach ($this->sourcesUnder('src/Webhook') + $this->sourcesUnder('src/Forms') as $file => $source) {
            if (str_ends_with($file, 'DeliveryLog.php')) {
                continue; // The implementation itself, not a caller.
            }

            $offset = 0;
            while (($pos = strpos($source, 'DeliveryLog::log(', $offset)) !== false) {
                $offset = $pos + 1;

                $following = substr($source, $pos, 1600);
                self::assertStringContainsString(
                    'DeliveryContext::attemptLogged(',
                    $following,
                    "A DeliveryLog::log() call in {$file} does not report its outcome."
                );
            }
        }
    }

    /**
     * The commit-ordering rule, asserted structurally because the analytics
     * dispatcher needs a cron lease to drive: success is announced only after
     * the last-sent marker has advanced and the retry chain is cleared.
     */
    public function testAnalyticsSuccessIsAnnouncedAfterTheBookkeepingCommits(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Webhook/AnalyticsDispatcher.php');

        // Scoped to attemptDelivery(): testEndpoint() also announces success,
        // earlier in the file, and it has no bookkeeping to commit first.
        $start = strpos($source, 'private static function attemptDelivery');
        self::assertIsInt($start);

        $method = substr($source, $start, 3000);

        $update    = strpos($method, 'update_option(self::LAST_SENT_OPTION');
        $clear     = strpos($method, 'self::clearRetry($url);');
        $succeeded = strpos($method, 'DeliveryContext::succeeded(');

        self::assertIsInt($update);
        self::assertIsInt($clear);
        self::assertIsInt($succeeded);
        self::assertGreaterThan($update, $succeeded, 'succeeded must follow the last-sent commit');
        self::assertGreaterThan($clear, $succeeded, 'succeeded must follow clearRetry()');
    }

    /**
     * A frozen retry must replay its frozen request, so the legacy-state
     * recovery path uses the unfiltered builders. If it called buildUrl() /
     * buildHeaders() again, a delivery could change destination between
     * attempt one and attempt four.
     */
    public function testLegacyRetryRecoveryDoesNotRerunTheCompositionFilters(): void
    {
        $source = (string) file_get_contents(self::PLUGIN_DIR . 'src/Webhook/AnalyticsDispatcher.php');

        $start = strpos($source, 'private static function deliveryFromState');
        self::assertIsInt($start);

        $method = substr($source, $start, 1400);

        self::assertStringContainsString('RequestFactory::recoverUrl(', $method);
        self::assertStringContainsString('RequestFactory::recoverHeaders(', $method);
        self::assertStringNotContainsString('RequestFactory::buildUrl(', $method);
        self::assertStringNotContainsString('RequestFactory::buildHeaders(', $method);
    }

    /**
     * Every PHP source under a directory, with comments stripped.
     *
     * Comments have to go: a @param line reading "Http::postJson() result" is
     * documentation, not a transport call, and counting it would make this
     * suite fail for writing something down.
     *
     * @return array<string, string>
     */
    private function sourcesUnder(string $relative): array
    {
        $dir = self::PLUGIN_DIR . $relative;

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        $out   = [];

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $out[$file->getPathname()] = self::stripComments((string) file_get_contents($file->getPathname()));
            }
        }

        return $out;
    }

    /**
     * Replaces every comment with an equal run of newlines, so byte offsets —
     * and therefore the "within N characters" proximity checks — stay meaningful.
     */
    private static function stripComments(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $out .= str_repeat("\n", substr_count($token[1], "\n")) . str_repeat(' ', 0);
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }
}
