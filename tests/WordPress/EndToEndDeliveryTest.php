<?php
declare(strict_types=1);

namespace Convermetry\Tests\WordPress;

use Convermetry\Database\FormSubmissions;
use Convermetry\Forms\SubmissionFields;
use Convermetry\Goals\GoalCompletions;
use Convermetry\Goals\GoalRepository;
use Convermetry\Goals\GoalSettings;
use Convermetry\Settings\Options;
use Convermetry\Webhook\AnalyticsDispatcher;
use Convermetry\Webhook\DeliveryLog;
use Convermetry\Webhook\FormDeliveryQueue;
use WP_REST_Request;

/**
 * A form submission's whole life, inside a real WordPress.
 *
 * Everything from activation to an HTTP request arriving at a receiver, with
 * nothing stubbed: dbDelta made the tables, WordPress registered the REST
 * route, WP-Cron ran the worker, and the payload crossed a socket.
 *
 * TWO PRODUCTION SAFEGUARDS ARE DELIBERATELY RELAXED HERE, and only here.
 * Http::postJson() uses wp_safe_remote_post(), so wp_http_validate_url() refuses
 * the receiver twice over: once because 127.0.0.1 is a loopback address, and
 * again because its port is not one of WordPress's safe ports. Both refusals are
 * real SSRF safeguards rather than inconveniences — a delivery endpoint is a
 * URL a site administrator supplies, which is exactly the shape of an SSRF — and
 * they are why a local receiver needs an explicit opt-in rather than just
 * working. Both filters are added per test and removed after, so no other test
 * benefits from them and nothing about the production default changes.
 */
final class EndToEndDeliveryTest extends WordPressTestCase
{
    private static ?WebhookReceiver $receiver = null;

    /** @var callable|null */
    private $allowLoopback = null;

    /** @var callable|null */
    private $allowPort = null;

    public static function setUpAfterClass(): void
    {
        self::$receiver?->stop();
        self::$receiver = null;

        parent::setUpAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$receiver === null) {
            $receiver = new WebhookReceiver((int) (getenv('CVMTRY_WP_PORT') ?: 8731));

            if (!$receiver->start()) {
                $held = $receiver->portHeldByStranger()
                    ? ' Something else is already listening on that port — set CVMTRY_WP_PORT to a free one.'
                    : '';

                self::fail('The webhook receiver did not start.' . $held);
            }

            self::$receiver = $receiver;
        }

        self::$receiver->forget();
        $this->truncatePluginTables();

        // The tracking endpoint accepts-and-discards requests with no
        // User-Agent, because a browser always sends one and a bot often does
        // not. A CLI process has none either, so the suite has to look like the
        // visitor it is standing in for.
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
            . ' (KHTML, like Gecko) Chrome/126.0 Safari/537.36';

        $port = $this->receiver()->port();

        $this->allowLoopback = static fn(bool $external, string $host): bool
            => $host === '127.0.0.1' ? true : $external;

        $this->allowPort = static fn(array $ports): array => array_merge($ports, [$port]);

        add_filter('http_request_host_is_external', $this->allowLoopback, 10, 2);
        add_filter('http_allowed_safe_ports', $this->allowPort, 10, 1);
    }

    protected function tearDown(): void
    {
        if ($this->allowLoopback !== null) {
            remove_filter('http_request_host_is_external', $this->allowLoopback, 10);
            $this->allowLoopback = null;
        }

        if ($this->allowPort !== null) {
            remove_filter('http_allowed_safe_ports', $this->allowPort, 10);
            $this->allowPort = null;
        }

        unset($_SERVER['HTTP_USER_AGENT']);

        delete_option(Options::WEBHOOK_OPTION_KEY);

        parent::tearDown();
    }

    private function receiver(): WebhookReceiver
    {
        self::assertNotNull(self::$receiver);

        return self::$receiver;
    }

    /**
     * Points the plugin at the local receiver.
     */
    private function configureEndpoint(string $path = '/ok'): void
    {
        update_option(Options::WEBHOOK_OPTION_KEY, [
            'active'        => true,
            'shared_secret' => 'e2e-secret',
            'endpoints'     => [[
                'url'       => $this->receiver()->url($path),
                'label'     => 'E2E receiver',
                'analytics' => false,
                'forms'     => true,
            ]],
        ]);
    }

    /**
     * Records a submission through the plugin's public fire-and-forget action,
     * which is the entry point every provider adapter ends up calling.
     */
    private function submit(string $email = 'ada@example.com'): void
    {
        do_action(
            'convermetry_form_submission',
            ['form_name' => 'Contact', 'form_id' => 'e2e-1'],
            ['email' => $email, 'name' => 'Ada Lovelace'],
            []
        );
    }

    // ── Activation ───────────────────────────────────────────────────────────

    /**
     * dbDelta made these, not a CREATE TABLE the test executed itself. That is
     * the whole point: the integration suite proves the DDL is correct, and
     * this proves WordPress applies it.
     */
    public function testActivationCreatedEveryTable(): void
    {
        global $wpdb;

        foreach ([
            'cvmtry_events',
            'cvmtry_form_submissions',
            'cvmtry_delivery_queue',
            'cvmtry_webhook_deliveries',
            'cvmtry_notification_queue',
            'cvmtry_goal_completions',
            'cvmtry_lead_events',
        ] as $table) {
            $name = $wpdb->prefix . $table;

            self::assertSame(
                $name,
                $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)),
                $name . ' was not created by activation'
            );
        }
    }

    public function testActivationScheduledTheCronEvents(): void
    {
        self::assertIsInt(wp_next_scheduled('cvmtry_cleanup_old_events'), 'The daily cleanup must be scheduled');
        self::assertIsInt(wp_next_scheduled('cvmtry_dispatch_webhooks'), 'The analytics dispatcher must be scheduled');
    }

    // ── REST ─────────────────────────────────────────────────────────────────

    /**
     * Registered on a real rest_api_init and answering a real request, rather
     * than asserted from the source.
     */
    public function testTheTrackingRouteIngestsAnEvent(): void
    {
        global $wpdb;

        $request = new WP_REST_Request('POST', '/convermetry/v1/track');
        $request->add_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'events' => [[
                'type'       => 'pageview',
                'page_url'   => home_url('/pricing/'),
                'session_id' => 'e2esession01',
            ]],
        ]));

        $response = rest_do_request($request);

        self::assertSame(202, $response->get_status(), 'The tracking route must accept a valid batch');

        // The acknowledged count and the table must agree. They are what the
        // tracker uses to decide whether to discard the batch or replay it, so
        // a 202 that overstates what landed loses analytics and one that
        // understates it duplicates them.
        self::assertSame(['stored' => 1], $response->get_data());
        self::assertSame(
            '1',
            $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cvmtry_events'),
            'The event must reach the table'
        );
    }

    /**
     * The gate that made this suite's first tracking assertion fail: a request
     * with no User-Agent is accepted and discarded, so crawlers that execute JS
     * cannot pollute the metrics and see nothing worth probing.
     */
    public function testTheTrackingRouteAcceptsAndDiscardsAUserAgentlessRequest(): void
    {
        global $wpdb;

        unset($_SERVER['HTTP_USER_AGENT']);

        $request = new WP_REST_Request('POST', '/convermetry/v1/track');
        $request->add_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'events' => [[
                'type'       => 'pageview',
                'page_url'   => home_url('/pricing/'),
                'session_id' => 'e2esession02',
            ]],
        ]));

        $response = rest_do_request($request);

        self::assertSame(202, $response->get_status(), 'A bot is answered blandly, not challenged');
        self::assertSame(['stored' => 0], $response->get_data());
        self::assertSame(
            '0',
            $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cvmtry_events'),
            'and nothing it sent is stored'
        );
    }

    public function testTheTrackingRouteIsRegisteredUnderItsNamespace(): void
    {
        $routes = rest_get_server()->get_routes();

        self::assertArrayHasKey('/convermetry/v1/track', $routes);
    }

    // ── The full delivery path ───────────────────────────────────────────────

    /**
     * The end-to-end case: submission → queue → WP-Cron worker → real HTTP →
     * recorded as delivered.
     */
    public function testASubmissionIsQueuedThenDeliveredByTheCronWorker(): void
    {
        global $wpdb;

        $this->configureEndpoint();
        $this->submit();

        $submissionId = (string) $wpdb->get_var(
            'SELECT submission_id FROM ' . $wpdb->prefix . 'cvmtry_form_submissions LIMIT 1'
        );
        self::assertNotSame('', $submissionId, 'The submission must be recorded');

        self::assertSame(1, FormDeliveryQueue::pendingCountFor($submissionId), 'and queued for delivery');

        $submission = FormSubmissions::getBySubmissionId($submissionId);
        self::assertNotNull($submission);
        self::assertSame('pending', $submission['delivery_state'], 'Queued, not yet sent');

        // Fired exactly as WP-Cron fires it.
        do_action(FormDeliveryQueue::WORKER_HOOK);

        self::assertTrue($this->receiver()->waitFor(1), 'The webhook never arrived at the receiver');

        self::assertSame(0, FormDeliveryQueue::pendingCountFor($submissionId), 'The queue row is consumed');

        $submission = FormSubmissions::getBySubmissionId($submissionId);
        self::assertNotNull($submission);
        self::assertSame('delivered', $submission['delivery_state']);
    }

    /**
     * What actually crossed the socket — not what the plugin believed it sent.
     */
    public function testTheDeliveredPayloadCarriesTheSubmissionAndItsSignature(): void
    {
        global $wpdb;

        $this->configureEndpoint();
        $this->submit('grace@example.com');

        $submissionId = (string) $wpdb->get_var(
            'SELECT submission_id FROM ' . $wpdb->prefix . 'cvmtry_form_submissions LIMIT 1'
        );

        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue($this->receiver()->waitFor(1));

        $received = $this->receiver()->received()[0];

        self::assertSame('POST', $received['method']);
        self::assertStringContainsString('grace@example.com', $received['body'], 'The lead must reach the receiver');

        $payload = json_decode($received['body'], true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('delivery_id', $payload);
        self::assertArrayHasKey('form_submission', $payload);
        self::assertIsArray($payload['form_submission']);
        self::assertSame(
            $submissionId,
            $payload['form_submission']['submission_id'],
            'The payload must name the submission that was recorded'
        );
        self::assertNotEmpty($payload['form_submission']['submission_data']);

        // The protocol headers a receiver deduplicates and authenticates with.
        $headers = $received['headers'];
        self::assertArrayHasKey('x-convermetry-signature', $headers, 'A configured secret must produce a signature');
        self::assertArrayHasKey('idempotency-key', $headers);

        self::assertSame(
            'sha256=' . hash_hmac('sha256', $received['body'], 'e2e-secret'),
            $headers['x-convermetry-signature'],
            'The signature must verify against the body that actually arrived'
        );
    }

    /**
     * A receiver that refuses the delivery must leave the lead queued for a
     * retry, never consumed.
     */
    public function testARefusedDeliveryStaysQueuedForRetry(): void
    {
        global $wpdb;

        $this->configureEndpoint('/fail');
        $this->submit();

        $submissionId = (string) $wpdb->get_var(
            'SELECT submission_id FROM ' . $wpdb->prefix . 'cvmtry_form_submissions LIMIT 1'
        );

        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue($this->receiver()->waitFor(1), 'The attempt must actually be made');

        self::assertSame(1, FormDeliveryQueue::pendingCountFor($submissionId), 'A refused lead is not dropped');

        $submission = FormSubmissions::getBySubmissionId($submissionId);
        self::assertNotNull($submission);
        self::assertSame('pending', $submission['delivery_state']);

        // The failure is in the Activity Log, with the receiver's real status.
        self::assertSame(
            '500',
            $wpdb->get_var(
                'SELECT response_code FROM ' . $wpdb->prefix . 'cvmtry_webhook_deliveries'
                . " WHERE submission_id = '" . esc_sql($submissionId) . "' ORDER BY id DESC LIMIT 1"
            )
        );
    }

    // ── What the Activity Log can store ──────────────────────────────────────

    /** The submission recorded by the most recent submit(). */
    private function submissionId(): string
    {
        global $wpdb;

        $submissionId = (string) $wpdb->get_var(
            'SELECT submission_id FROM ' . $wpdb->prefix . 'cvmtry_form_submissions ORDER BY id DESC LIMIT 1'
        );
        self::assertNotSame('', $submissionId, 'The submission must be recorded');

        return $submissionId;
    }

    /**
     * The submission's Activity Log rows, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function logRows(string $submissionId): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            'SELECT attempt, success, response_code, response_data FROM %i WHERE submission_id = %s ORDER BY id ASC',
            DeliveryLog::tableName(),
            $submissionId
        ), ARRAY_A);
    }

    /** The submission's recorded delivery state. */
    private function deliveryState(string $submissionId): string
    {
        $submission = FormSubmissions::getBySubmissionId($submissionId);
        self::assertNotNull($submission);

        return (string) $submission['delivery_state'];
    }

    /**
     * The premise, checked against this WordPress: $wpdb refuses the value
     * outright, so the whole row would be lost if it were stored as received.
     */
    private function assertWpdbRefuses(string $value): void
    {
        global $wpdb;

        self::assertNotSame(
            $value,
            $wpdb->strip_invalid_text_for_column(DeliveryLog::tableName(), 'response_data', $value),
            'The premise: $wpdb would refuse this value for response_data'
        );
    }

    /**
     * About 90 KB of HTML in Japanese, answered with a 200. WordPress's
     * transport stops reading at 64 KB, in bytes, inside a character; that
     * body used to be refused by $wpdb, so the delivery had no log row and
     * the lead settled on "not_sent" although it had arrived.
     */
    public function testALargeMultibyteResponseIsLoggedAndTheLeadIsDelivered(): void
    {
        $this->assertWpdbRefuses(substr(
            '<!doctype html><html lang="ja"><body>' . str_repeat('<p>日本語のテキストです。</p>', 2300),
            0,
            DeliveryLog::MAX_BODY_BYTES
        ));

        $this->configureEndpoint('/ok/japanese-html');
        $this->submit();
        $submissionId = $this->submissionId();

        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue($this->receiver()->waitFor(1), 'The webhook never arrived at the receiver');

        $rows = $this->logRows($submissionId);
        self::assertCount(1, $rows, 'One attempt, one Activity Log row');
        self::assertSame('200', (string) $rows[0]['response_code']);
        self::assertSame(1, preg_match('//u', (string) $rows[0]['response_data']));
        self::assertStringContainsString('日本語のテキストです。', (string) $rows[0]['response_data']);
        self::assertLessThanOrEqual(DeliveryLog::MAX_BODY_BYTES, strlen((string) $rows[0]['response_data']));

        self::assertSame('delivered', $this->deliveryState($submissionId));
    }

    /**
     * The same body with a 500, every time. Each attempt has its row, and when
     * the retries are spent the lead is "failed" rather than "not_sent", which
     * is what tells the site owner that it never arrived.
     */
    public function testALargeMultibyteErrorIsLoggedOnEveryAttemptAndTheLeadFails(): void
    {
        global $wpdb;

        $this->configureEndpoint('/fail/japanese-html');
        $this->submit();
        $submissionId = $this->submissionId();

        $attempts = count(AnalyticsDispatcher::retryDelays()) + 1;

        for ($pass = 1; $pass <= $attempts + 2 && FormDeliveryQueue::pendingCountFor($submissionId) > 0; $pass++) {
            // Each retry is made due now instead of after its backoff.
            $wpdb->query($wpdb->prepare(
                "UPDATE %i SET next_attempt_at = '2000-01-01 00:00:00' WHERE submission_id = %s",
                FormDeliveryQueue::tableName(),
                $submissionId
            ));

            do_action(FormDeliveryQueue::WORKER_HOOK);
        }

        self::assertTrue($this->receiver()->waitFor($attempts), "All {$attempts} attempts must be made");
        self::assertSame(0, FormDeliveryQueue::pendingCountFor($submissionId), 'The retries are spent');

        $rows = $this->logRows($submissionId);
        self::assertSame(range(1, $attempts), array_map(static fn(array $row): int => (int) $row['attempt'], $rows),
            'Every attempt has its Activity Log row');

        foreach ($rows as $row) {
            self::assertSame('500', (string) $row['response_code']);
            self::assertSame(1, preg_match('//u', (string) $row['response_data']));
        }

        self::assertSame('failed', $this->deliveryState($submissionId));
    }

    /**
     * A short error page in ISO-8859-1. Nothing is cut, but the body was never
     * valid UTF-8, and $wpdb refused it just the same.
     */
    public function testAnErrorPageInAnotherEncodingIsLogged(): void
    {
        $this->assertWpdbRefuses("<p>Service momentan\xE9ment indisponible.</p>");

        $this->configureEndpoint('/fail/latin1-html');
        $this->submit();
        $submissionId = $this->submissionId();

        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue($this->receiver()->waitFor(1), 'The attempt must actually be made');

        $rows = $this->logRows($submissionId);
        self::assertCount(1, $rows);
        self::assertSame('500', (string) $rows[0]['response_code']);
        self::assertStringContainsString("Service momentan\u{FFFD}ment indisponible.", (string) $rows[0]['response_data'],
            'Stored with only the bytes that are not UTF-8 replaced');

        self::assertSame('pending', $this->deliveryState($submissionId), 'A retry is scheduled');
    }

    /**
     * A visitor with JavaScript off posts a form with nothing but a Referer. The
     * request is real, so this runs WordPress's own URL parsing and sanitizing —
     * which is what mangled '%40' — and what the receiver gets is what left.
     */
    public function testAVisitorWithoutJavaScriptIsRecordedWithoutLeakingTheirUrl(): void
    {
        global $wpdb;

        $this->configureEndpoint();

        $_SERVER['HTTP_REFERER'] = home_url(
            '/quote/?utm_source=news&gclid=CLKSECRET&email=leak.check%40example.com&token=TOKSECRET&reset_key=RKSECRET'
        );

        try {
            $this->submit();
        } finally {
            unset($_SERVER['HTTP_REFERER']);
        }

        $row = $wpdb->get_row('SELECT * FROM ' . $wpdb->prefix . 'cvmtry_form_submissions LIMIT 1', ARRAY_A);
        self::assertIsArray($row);
        self::assertSame(home_url('/quote/'), $row['page_url']);
        self::assertSame('{"utm_source":"news"}', $row['page_query']);
        self::assertSame('', (string) $row['channel'], 'No evidence, so no channel');

        $event = $wpdb->get_row(
            'SELECT page_url, channel FROM ' . $wpdb->prefix . "cvmtry_events WHERE event_type = 'form_success' LIMIT 1",
            ARRAY_A
        );
        self::assertIsArray($event);
        self::assertSame(home_url('/quote/'), $event['page_url'], 'The event has the same page as the lead');
        self::assertSame('', $event['channel'], 'and the same channel: not an invented Direct');

        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue($this->receiver()->waitFor(1));

        $received = $this->receiver()->received()[0];
        foreach (['CLKSECRET', 'TOKSECRET', 'RKSECRET', 'leak.check', 'checkexample'] as $leak) {
            self::assertStringNotContainsString($leak, (string) json_encode($received));
        }
    }

    /**
     * Forwarding on is the administrator asking for the page's parameters, and
     * click ids are why it exists. It still does not carry a secret or an address.
     */
    public function testForwardingSendsClickIdsButNotSecretsToTheEndpoint(): void
    {
        $this->configureEndpoint();

        $settings = (array) get_option(Options::WEBHOOK_OPTION_KEY, []);
        update_option(Options::WEBHOOK_OPTION_KEY, array_merge($settings, ['include_page_params' => true]));

        $_SERVER['HTTP_REFERER'] = home_url('/quote/?gclid=CLKVALUE&email=leak.check%40example.com&reset_key=RKSECRET&ref=a%2Fb');

        try {
            $this->submit();
        } finally {
            unset($_SERVER['HTTP_REFERER']);
        }

        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue($this->receiver()->waitFor(1));

        $encoded = (string) json_encode($this->receiver()->received()[0]);

        self::assertStringContainsString('CLKVALUE', $encoded);
        self::assertStringNotContainsString('RKSECRET', $encoded);
        self::assertStringNotContainsString('leak.check', $encoded);
    }

    /**
     * A replayed request — the same browser submission arriving twice, with the
     * same conversion token — is one submission and one conversion event. The
     * event used to be written before the duplicate check, so every replay
     * added another form_success row. Reports count distinct conversion ids and
     * never showed it; the raw table and the per-type totals did.
     */
    public function testAReplayedSubmissionRecordsOneConversionEvent(): void
    {
        global $wpdb;

        $_POST['cvmtry_conversion_id'] = 'c0123456789abcdef';
        $_POST['cvmtry_session_id']    = str_repeat('ab', 16);

        try {
            $first  = convermetry_submit_form(['form_name' => 'Contact', 'form_id' => 'e2e-1'], ['email' => 'ada@example.com']);
            $replay = convermetry_submit_form(['form_name' => 'Contact', 'form_id' => 'e2e-1'], ['email' => 'ada@example.com']);
        } finally {
            unset($_POST['cvmtry_conversion_id'], $_POST['cvmtry_session_id']);
        }

        self::assertTrue($first->ok);
        self::assertTrue($replay->ok);
        self::assertNotSame('', $first->submissionId);
        self::assertSame($first->submissionId, $replay->submissionId, 'the replay answers with the original submission');

        self::assertSame('1', (string) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cvmtry_form_submissions'
        ));
        self::assertSame('1', (string) $wpdb->get_var(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . "cvmtry_events WHERE event_type = 'form_success'"
            . " AND event_value = 'c0123456789abcdef'"
        ), 'one form_success row, not one per request');
    }

    /**
     * A site with no endpoint configured records the lead and sends nothing —
     * the neutral state the repair record exists so as not to confuse with a
     * failed enqueue.
     */
    public function testASubmissionWithNoEndpointIsRecordedAndNotSent(): void
    {
        global $wpdb;

        $this->submit();

        $submissionId = (string) $wpdb->get_var(
            'SELECT submission_id FROM ' . $wpdb->prefix . 'cvmtry_form_submissions LIMIT 1'
        );

        self::assertNotSame('', $submissionId);
        self::assertSame(0, FormDeliveryQueue::pendingCountFor($submissionId));
        self::assertSame([], FormDeliveryQueue::pendingRepairFor($submissionId), 'Nothing was owed');

        $submission = FormSubmissions::getBySubmissionId($submissionId);
        self::assertNotNull($submission);
        self::assertSame('not_sent', $submission['delivery_state']);

        do_action(FormDeliveryQueue::WORKER_HOOK);

        self::assertSame([], $this->receiver()->received(), 'Nothing may be sent anywhere');
    }

    // ── 1.1.6: what a visitor typed is what is stored and sent ────────────────

    /**
     * The message-fidelity scenario, through the real sanitizing path.
     * sanitize_text_field() joined the lines, stored "<" as "&lt;" (so a CRM's
     * JSON carried the entity), deleted the tag-like text and turned
     * "100%accuracy" into "100curacy".
     */
    public function testAMessageIsStoredAndDeliveredAsTheVisitorTypedIt(): void
    {
        $values = [
            'message' => "Hi team,\nOur budget is < \$5k and we need 100%accuracy.\n\nThanks,\nProbe",
            'markup'  => '<b>bold</b> <script>x()</script> text',
            'spacing' => "two  spaces\tand tab",
        ];

        $this->configureEndpoint();
        do_action(
            'convermetry_form_submission',
            ['form_name' => 'Contact', 'form_id' => 'e2e-1'],
            ['email' => 'ada@example.com'] + $values + ['padded' => "  {$values['spacing']}  "],
            []
        );
        $submissionId = $this->submissionId();

        $submission = FormSubmissions::getBySubmissionId($submissionId);
        self::assertNotNull($submission);
        $stored = array_column(SubmissionFields::fromStoredJson((string) $submission['submission_data'])->toArray(), 'value', 'id');

        foreach ($values as $id => $value) {
            self::assertSame($value, $stored[$id], "{$id} is stored as typed");
        }
        self::assertSame($values['spacing'], $stored['padded'], 'Only surrounding whitespace is trimmed');

        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue($this->receiver()->waitFor(1));

        $body = $this->receiver()->received()[0]['body'];
        self::assertStringNotContainsString('&lt;', $body, 'Webhook JSON carries text, not HTML entities');

        $payload = json_decode($body, true);
        self::assertIsArray($payload);
        $sent = array_column($payload['form_submission']['submission_data'], 'value', 'id');

        foreach ($values as $id => $value) {
            self::assertSame($value, $sent[$id], "{$id} is delivered as typed");
        }
    }

    // ── 1.1.6: Convermetry's own headers travel once ──────────────────────────

    /**
     * The webhook-protocol scenario: global headers saved by 1.1.5 in another
     * letter case than Convermetry's own. Each travelled next to the real one,
     * and this receiver, like most servers, joined them ("fixed, <id>"), so
     * signature verification and Idempotency-Key de-duplication failed on
     * every request.
     */
    public function testAHeaderSavedInAnotherCaseIsNotSentBesideConvermetrysOwn(): void
    {
        $this->configureEndpoint();
        $settings                   = (array) get_option(Options::WEBHOOK_OPTION_KEY);
        $settings['global_headers'] = [
            ['key' => 'x-convermetry-signature', 'value' => 'sha256=stale'],
            ['key' => 'idempotency-key', 'value' => 'fixed'],
            ['key' => 'user-agent', 'value' => 'Mine/1.0'],
            ['key' => 'X-Kept', 'value' => 'yes'],
        ];
        update_option(Options::WEBHOOK_OPTION_KEY, $settings);

        $this->submit();
        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue($this->receiver()->waitFor(1));

        $received = $this->receiver()->received()[0];
        $headers  = $received['headers'];
        $payload  = json_decode($received['body'], true);
        self::assertIsArray($payload);

        self::assertSame($payload['delivery_id'], $headers['idempotency-key']);
        self::assertSame('sha256=' . hash_hmac('sha256', $received['body'], 'e2e-secret'), $headers['x-convermetry-signature']);
        self::assertSame('WordPress/Convermetry ' . CVMTRY_VERSION, $headers['user-agent']);
        self::assertSame('yes', $headers['x-kept']);
    }

    // ── 1.1.7: a stored header value is checked again before it is sent ───────

    /**
     * The Webhooks screen refuses a line break in a header value, but one
     * written to the option directly (a migration, an import, a database edit)
     * was sent as stored, and WordPress's transport wrote the rest of the value
     * as a header line of its own. The receiver saw a header nobody configured.
     */
    public function testAStoredHeaderValueWithALineBreakDoesNotReachTheReceiver(): void
    {
        $this->configureEndpoint();
        $settings                   = (array) get_option(Options::WEBHOOK_OPTION_KEY);
        $settings['global_headers'] = [
            ['key' => 'X-Imported', 'value' => "a\r\nX-Smuggled: 1"],
            ['key' => 'X-Kept', 'value' => 'yes'],
        ];
        update_option(Options::WEBHOOK_OPTION_KEY, $settings);

        $this->submit();
        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue($this->receiver()->waitFor(1));

        $headers = $this->receiver()->received()[0]['headers'];

        self::assertArrayNotHasKey('x-smuggled', $headers, 'No header line is made from a stored value');
        self::assertArrayNotHasKey('x-imported', $headers, 'The row with the line break is not sent at all');
        self::assertSame('yes', $headers['x-kept'], 'The other stored headers still are');
    }

    // ── 1.1.6: which answers are retried ──────────────────────────────────────

    /** The queue row a submission's delivery waits in, or null once it is gone. */
    private function queueRow(string $submissionId): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT attempt, status, next_attempt_at FROM %i WHERE submission_id = %s',
            FormDeliveryQueue::tableName(),
            $submissionId
        ), ARRAY_A);

        return is_array($row) ? $row : null;
    }

    /** Seconds from now until a queue row's next attempt. */
    private function secondsUntilNextAttempt(string $submissionId): int
    {
        $row = $this->queueRow($submissionId);
        self::assertNotNull($row, 'The delivery must still be queued');

        return (int) strtotime($row['next_attempt_at'] . ' UTC') - time();
    }

    /**
     * A 400 refuses the same frozen request every time. It used to be retried
     * five more times over a day while the lead showed "pending"; the lead is
     * now "failed" after the one attempt, abandoned as 'rejected'.
     *
     * @dataProvider finalAnswers
     */
    public function testAFinalClientErrorFailsTheLeadAtOnce(int $code): void
    {
        $reasons  = [];
        $listener = static function (array $context, string $reason) use (&$reasons): void {
            $reasons[] = $reason;
        };
        add_action('convermetry_webhook_delivery_abandoned', $listener, 10, 2);

        try {
            $this->configureEndpoint("/status/{$code}");
            $this->submit();
            $submissionId = $this->submissionId();

            do_action(FormDeliveryQueue::WORKER_HOOK);
            self::assertTrue($this->receiver()->waitFor(1));

            self::assertNull($this->queueRow($submissionId), "A {$code} is not retried");
            self::assertCount(1, $this->logRows($submissionId));
            self::assertSame('failed', $this->deliveryState($submissionId));
            self::assertSame(['rejected'], $reasons);
        } finally {
            remove_action('convermetry_webhook_delivery_abandoned', $listener, 10);
        }
    }

    /**
     * @return array<string, array{int}>
     */
    public static function finalAnswers(): array
    {
        return ['400' => [400], '404' => [404], '410' => [410], '422' => [422]];
    }

    /**
     * What a later attempt can still change keeps the schedule: the first
     * retry five minutes on. A redirect is one of them, and it is not followed
     * (the receiver's Location target records nothing).
     *
     * @dataProvider retriedAnswers
     */
    public function testARetryableAnswerKeepsTheSchedule(int $code): void
    {
        $this->configureEndpoint("/status/{$code}");
        $this->submit();
        $submissionId = $this->submissionId();

        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue($this->receiver()->waitFor(1));

        self::assertSame('pending', $this->deliveryState($submissionId));
        self::assertEqualsWithDelta(AnalyticsDispatcher::retryDelays()[0], $this->secondsUntilNextAttempt($submissionId), 60);

        self::assertSame(["/status/{$code}"], array_column($this->receiver()->received(), 'path'),
            'Exactly one request, and a redirect is never followed');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function retriedAnswers(): array
    {
        return [
            '301' => [301], '308' => [308], '408' => [408], '409' => [409],
            '425' => [425], '429' => [429], '500' => [500], '503' => [503],
        ];
    }

    /**
     * Retry-After on a 429 or 503 lengthens the wait, as seconds or as a date,
     * and never shortens it below the schedule's five minutes.
     *
     * @dataProvider retryAfterAnswers
     */
    public function testRetryAfterLengthensTheWaitBeforeTheNextAttempt(string $path, int $expected): void
    {
        $path = str_replace('{in-2h}', rawurlencode(gmdate('D, d M Y H:i:s', time() + 7200) . ' GMT'), $path);

        $this->configureEndpoint($path);
        $this->submit();
        $submissionId = $this->submissionId();

        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue($this->receiver()->waitFor(1));

        self::assertSame('pending', $this->deliveryState($submissionId));
        self::assertEqualsWithDelta($expected, $this->secondsUntilNextAttempt($submissionId), 60);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function retryAfterAnswers(): array
    {
        return [
            '429, two hours'      => ['/status/429/retry-after-7200', 7200],
            '503, a date'         => ['/status/503/retry-after-{in-2h}', 7200],
            '429, thirty seconds' => ['/status/429/retry-after-30', 300],
            '500 ignores it'      => ['/status/500/retry-after-7200', 300],
        ];
    }

    /**
     * Points the plugin at one analytics endpoint and returns its durable id.
     */
    private function configureAnalyticsEndpoint(string $path): string
    {
        update_option(Options::WEBHOOK_OPTION_KEY, [
            'active'        => true,
            'shared_secret' => 'e2e-secret',
            'interval'      => 'daily',
            'endpoints'     => [[
                'url'       => $this->receiver()->url($path),
                'label'     => 'E2E analytics',
                'analytics' => true,
                'forms'     => false,
            ]],
        ]);
        Options::ensureEndpointIds();

        return Options::endpoints()[0]->id;
    }

    /** Clears what an analytics dispatch leaves behind. */
    private function forgetAnalyticsState(): void
    {
        wp_clear_scheduled_hook(AnalyticsDispatcher::RETRY_HOOK);
        delete_option('cvmtry_webhook_retry_state');
        delete_option('cvmtry_webhook_last_sent');
        delete_option('cvmtry_webhook_dispatch_lock');
    }

    /**
     * An analytics report answered with a 400 ends its chain at once, so the
     * receiver is asked again at the next scheduled dispatch rather than five
     * more times in between; the frozen window is kept for that dispatch.
     */
    public function testAFinalClientErrorEndsAnAnalyticsChainAtOnce(): void
    {
        $this->forgetAnalyticsState();

        try {
            $id = $this->configureAnalyticsEndpoint('/status/400');

            AnalyticsDispatcher::dispatch();
            self::assertTrue($this->receiver()->waitFor(1));

            $state = (array) get_option('cvmtry_webhook_retry_state', []);
            self::assertArrayHasKey($id, $state, 'The frozen delivery is kept');
            self::assertTrue((bool) $state[$id]['exhausted'], 'and its chain ended');
            self::assertFalse(wp_next_scheduled(AnalyticsDispatcher::RETRY_HOOK, [$id]), 'No retry is scheduled');
        } finally {
            $this->forgetAnalyticsState();
        }
    }

    public function testRetryAfterLengthensTheWaitBeforeAnAnalyticsRetry(): void
    {
        $this->forgetAnalyticsState();

        try {
            $id = $this->configureAnalyticsEndpoint('/status/429/retry-after-7200');

            AnalyticsDispatcher::dispatch();
            self::assertTrue($this->receiver()->waitFor(1));

            $next = wp_next_scheduled(AnalyticsDispatcher::RETRY_HOOK, [$id]);
            self::assertIsInt($next);
            self::assertEqualsWithDelta(7200, $next - time(), 60);
        } finally {
            $this->forgetAnalyticsState();
        }
    }

    // ── 1.1.6: percent-encoded URLs through the tracking route ────────────────

    /**
     * Posts one batch to the tracking route, as the tracker does.
     *
     * @param list<array<string, mixed>> $events
     */
    private function track(array $events): void
    {
        $request = new WP_REST_Request('POST', '/convermetry/v1/track');
        $request->add_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode(['events' => $events]));

        self::assertSame(202, rest_do_request($request)->get_status());
    }

    /** Stores a goal through the save path the Goals screen uses. */
    private function saveGoal(array $fields): array
    {
        $goal = GoalSettings::sanitize($fields + ['enabled' => '1'], null, gmdate('Y-m-d H:i:s'));
        self::assertNotNull($goal, 'The goal must be accepted');
        self::assertTrue(GoalRepository::save($goal));

        return $goal;
    }

    /** Completions of one goal, by session. */
    private function completionSessions(string $goalId): array
    {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare(
            'SELECT session_id FROM %i WHERE goal_id = %s ORDER BY id',
            GoalCompletions::tableName(),
            $goalId
        ));
    }

    /**
     * The intl-paths scenario, item 4: a link to a percent-encoded file name
     * or slug was stored with every %XX deleted ("/PriceList2026.pdf"), and
     * a "contains" click goal was matched against an address that does not
     * exist.
     */
    public function testAClickKeepsItsEncodedDestinationAndCompletesAContainsGoal(): void
    {
        try {
            $encoded = $this->saveGoal(['name' => 'Price list (encoded)', 'type' => 'click', 'operator' => 'contains', 'value' => 'Price%20List']);
            $typed   = $this->saveGoal(['name' => 'Price list (typed)', 'type' => 'click', 'operator' => 'contains', 'value' => 'price list 2026']);
            self::assertSame('Price%20List', $encoded['value'], 'The goal keeps its %20');

            $file  = home_url('/demo-contact/Price%20List%202026.pdf');
            $slug  = home_url('/%d0%ba%d0%be%d0%bd%d1%82%d0%b0%d0%ba%d1%82%d1%8b/');
            $page  = home_url('/demo-contact/');

            $this->track([
                ['type' => 'pageview', 'page_url' => $page, 'session_id' => 'aaaa0001'],
                ['type' => 'click', 'page_url' => $page, 'session_id' => 'aaaa0001', 'element_tag' => 'a', 'target_url' => $file],
                ['type' => 'click', 'page_url' => $page, 'session_id' => 'aaaa0001', 'element_tag' => 'a', 'target_url' => $slug],
            ]);

            global $wpdb;
            self::assertSame([$file, $slug], $wpdb->get_col(
                'SELECT target_url FROM ' . $wpdb->prefix . "cvmtry_events WHERE event_type = 'click' ORDER BY id"
            ));
            self::assertSame(['aaaa0001'], $this->completionSessions((string) $encoded['goal_id']));
            self::assertSame(['aaaa0001'], $this->completionSessions((string) $typed['goal_id']));
        } finally {
            delete_option(Options::GOALS_OPTION_KEY);
            GoalRepository::flushCache();
        }
    }

    /**
     * The intl-paths and intl-home-match scenarios, item 1, through the real
     * sanitize_text_field(): an address pasted from Chrome for /контакты/ used
     * to be saved as "//", and completed the goal, with its value, on every
     * home-page view. It is now saved as pasted and completes on that page
     * only; and a goal 1.1.5 already stored as "//" completes on nothing.
     */
    public function testAUrlGoalForANonAsciiSlugCompletesOnThatPageAndNeverOnTheHomePage(): void
    {
        global $wpdb;

        try {
            $pasted = '/%D0%BA%D0%BE%D0%BD%D1%82%D0%B0%D0%BA%D1%82%D1%8B/';
            $goals  = [
                'pasted' => $this->saveGoal(['name' => 'Contacts (pasted)', 'type' => 'url', 'operator' => 'equals', 'value' => $pasted]),
                'typed'  => $this->saveGoal(['name' => 'Contacts (typed)', 'type' => 'url', 'operator' => 'equals', 'value' => '/контакты/']),
            ];
            self::assertSame($pasted, $goals['pasted']['value'], 'Saved as pasted, not as "//"');

            // What 1.1.5 stored for that paste: written to the option as it was,
            // since no save path in 1.1.6 accepts it.
            $damaged          = $this->saveGoal(['name' => 'Contacts (1.1.5)', 'type' => 'url', 'operator' => 'equals', 'value' => '/x/']);
            $damaged['value'] = '//';
            $damaged['definition_hash'] = GoalSettings::definitionHash($damaged);
            self::assertTrue(GoalRepository::save($damaged));
            GoalRepository::flushCache();
            self::assertNotNull(GoalRepository::find((string) $damaged['goal_id']), 'A damaged goal is kept, not dropped');

            $this->track([
                ['type' => 'pageview', 'page_url' => home_url('/'), 'session_id' => 'bbbb0001'],
                ['type' => 'pageview', 'page_url' => home_url('/demo-services/'), 'session_id' => 'bbbb0001'],
                ['type' => 'pageview', 'page_url' => home_url('/%d0%ba%d0%be%d0%bd%d1%82%d0%b0%d0%ba%d1%82%d1%8b/'), 'session_id' => 'cccc0002'],
            ]);

            self::assertSame(['cccc0002'], $this->completionSessions((string) $goals['pasted']['goal_id']));
            self::assertSame(['cccc0002'], $this->completionSessions((string) $goals['typed']['goal_id']));
            self::assertSame([], $this->completionSessions((string) $damaged['goal_id']), 'The home page completes nothing');
            self::assertSame('3', $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . "cvmtry_events WHERE event_type = 'pageview'"));
        } finally {
            delete_option(Options::GOALS_OPTION_KEY);
            GoalRepository::flushCache();
        }
    }
}
