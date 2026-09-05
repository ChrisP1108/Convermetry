<?php
declare(strict_types=1);

namespace Convermetry\Tests\WordPress;

use Convermetry\Database\FormSubmissions;
use Convermetry\Settings\Options;
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
            $receiver = new WebhookReceiver((int) (getenv('CVM_WP_PORT') ?: 8731));

            if (!$receiver->start()) {
                $held = $receiver->portHeldByStranger()
                    ? ' Something else is already listening on that port — set CVM_WP_PORT to a free one.'
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
            'cvm_events',
            'cvm_form_submissions',
            'cvm_delivery_queue',
            'cvm_webhook_deliveries',
            'cvm_notification_queue',
            'cvm_goal_completions',
            'cvm_lead_events',
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
        self::assertIsInt(wp_next_scheduled('cvm_cleanup_old_events'), 'The daily cleanup must be scheduled');
        self::assertIsInt(wp_next_scheduled('cvm_dispatch_webhooks'), 'The analytics dispatcher must be scheduled');
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
            $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cvm_events'),
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
            $wpdb->get_var('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cvm_events'),
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
            'SELECT submission_id FROM ' . $wpdb->prefix . 'cvm_form_submissions LIMIT 1'
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
            'SELECT submission_id FROM ' . $wpdb->prefix . 'cvm_form_submissions LIMIT 1'
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
            'SELECT submission_id FROM ' . $wpdb->prefix . 'cvm_form_submissions LIMIT 1'
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
                'SELECT response_code FROM ' . $wpdb->prefix . 'cvm_webhook_deliveries'
                . " WHERE submission_id = '" . esc_sql($submissionId) . "' ORDER BY id DESC LIMIT 1"
            )
        );
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
            'SELECT submission_id FROM ' . $wpdb->prefix . 'cvm_form_submissions LIMIT 1'
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
}
