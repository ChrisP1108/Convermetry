<?php
declare(strict_types=1);

namespace Convermetry\Tests\WordPress;

use Convermetry\Database\FormSubmissions;
use Convermetry\Leads\LeadService;
use Convermetry\Privacy\PersonalDataEraser;
use Convermetry\Privacy\PersonalDataExporter;
use Convermetry\Privacy\PrivacyPolicy;
use Convermetry\Privacy\PrivacyTools;
use Convermetry\Settings\Options;
use Convermetry\Support\ClientIp;
use Convermetry\Webhook\FormDeliveryQueue;

/**
 * WordPress's personal-data export and erasure, run the way the Tools screens
 * run them: the registered callback, called page by page until it says done,
 * against real tables in a real WordPress.
 *
 * This suite exists because an erasure guarantee is exactly the claim a unit
 * test with a mocked $wpdb cannot support. What is asserted here is what the
 * database holds afterwards.
 *
 * Like EndToEndDeliveryTest, it opts the local receiver in to
 * wp_safe_remote_post() per test, so a real delivery produces real Activity Log
 * rows to erase. It uses its own port so the two suites' receivers never meet.
 */
final class PrivacyToolsTest extends WordPressTestCase
{
    private const string ANN     = 'ann@example.com';
    private const string SESSION = 'a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private static ?WebhookReceiver $receiver = null;

    /** @var callable|null */
    private $allowLoopback = null;

    /** @var callable|null */
    private $allowPort = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$receiver === null) {
            $receiver = new WebhookReceiver((int) (getenv('CVMTRY_WP_PORT') ?: 8731) + 1);

            if (!$receiver->start()) {
                self::fail('The webhook receiver did not start.');
            }

            self::$receiver = $receiver;
        }

        self::$receiver->forget();
        $this->truncateEverything();

        $port = self::$receiver->port();

        $this->allowLoopback = static fn(bool $external, string $host): bool
            => $host === '127.0.0.1' ? true : $external;
        $this->allowPort = static fn(array $ports): array => array_merge($ports, [$port]);

        add_filter('http_request_host_is_external', $this->allowLoopback, 10, 2);
        add_filter('http_allowed_safe_ports', $this->allowPort, 10, 1);

        // The resolved address is memoized per process; an earlier suite in
        // this run may have resolved it with no REMOTE_ADDR at all.
        ClientIp::resetCache();
        $_SERVER['REMOTE_ADDR']     = '198.51.100.7';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/126.0 Safari/537.36';

        update_option(Options::WEBHOOK_OPTION_KEY, [
            'active'        => true,
            'shared_secret' => 'privacy-secret',
            'endpoints'     => [[
                'url'       => self::$receiver->url('/ok?token=do-not-export-me'),
                'label'     => 'Privacy receiver',
                'analytics' => false,
                'forms'     => true,
            ]],
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->allowLoopback !== null) {
            remove_filter('http_request_host_is_external', $this->allowLoopback, 10);
        }

        if ($this->allowPort !== null) {
            remove_filter('http_allowed_safe_ports', $this->allowPort, 10);
        }

        $_POST = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);

        delete_option(Options::WEBHOOK_OPTION_KEY);
        delete_option(Options::NOTIFICATION_OPTION_KEY);
        delete_option(Options::OPTION_KEY);
        delete_option('cvmtry_webhook_retry_state');

        parent::tearDown();
    }

    // ── Registration ─────────────────────────────────────────────────────────

    public function testTheExporterAndEraserAreRegisteredWithCallableCallbacks(): void
    {
        $exporters = apply_filters('wp_privacy_personal_data_exporters', []);
        $erasers   = apply_filters('wp_privacy_personal_data_erasers', []);

        self::assertArrayHasKey(PrivacyTools::KEY, $exporters);
        self::assertArrayHasKey(PrivacyTools::KEY, $erasers);
        self::assertIsCallable($exporters[PrivacyTools::KEY]['callback']);
        self::assertIsCallable($erasers[PrivacyTools::KEY]['callback']);
        self::assertNotSame('', $exporters[PrivacyTools::KEY]['exporter_friendly_name']);
        self::assertNotSame('', $erasers[PrivacyTools::KEY]['eraser_friendly_name']);
    }

    public function testThePolicyTextFollowsTheIpAndSignalSettings(): void
    {
        update_option(Options::OPTION_KEY, ['store_ip_address' => true, 'respect_dnt' => false, 'retention_days' => 30]);
        $withIp = PrivacyPolicy::content();

        self::assertStringContainsString('We also record your IP address', $withIp);
        self::assertStringContainsString('after 30 days', $withIp);
        self::assertStringNotContainsString('Do Not Track', strip_tags($withIp));
        self::assertStringContainsString('privacy-policy-tutorial', $withIp, 'Instructions must be marked as not-for-copying');

        update_option(Options::OPTION_KEY, ['store_ip_address' => false, 'respect_dnt' => true, 'retention_days' => 90]);
        $withoutIp = PrivacyPolicy::content();

        self::assertStringContainsString('We do not record your IP address', $withoutIp);
        self::assertStringContainsString('Do Not Track or Global Privacy Control', $withoutIp);
        self::assertStringContainsString('after 90 days', $withoutIp);
    }

    public function testThePolicyTextNamesWebhookAndEmailRecipientsOnlyWhenConfigured(): void
    {
        $withWebhooks = PrivacyPolicy::content();
        self::assertStringContainsString('[list the services', $withWebhooks);

        delete_option(Options::WEBHOOK_OPTION_KEY);
        $bare = PrivacyPolicy::content();
        self::assertStringNotContainsString('[list the services', $bare);
        self::assertStringNotContainsString('emailed to members of our staff', $bare);

        update_option(Options::NOTIFICATION_OPTION_KEY, ['enabled' => true, 'recipients' => ['staff@example.test']]);
        self::assertStringContainsString('emailed to members of our staff', PrivacyPolicy::content());
    }

    // ── Export ───────────────────────────────────────────────────────────────

    public function testExportReturnsOnlyExactMatchesWithTheirLinkedData(): void
    {
        $this->recordAnnsVisitAndSubmission();
        $this->submit('joann@example.com', 'Jo Ann');
        $this->submit('bob@example.com', 'Bob', 'Please also reply to ann@example.com');

        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue(self::$receiver?->waitFor(3), 'All three leads must be delivered first');

        $ann = $this->submissionFor(self::ANN);
        LeadService::update((string) $ann['submission_id'], 'qualified', '1500', 1);

        $export = PersonalDataExporter::export(self::ANN, 1);
        self::assertTrue($export['done']);

        $groups = array_count_values(array_column($export['data'], 'group_id'));
        self::assertSame(1, $groups['convermetry-submissions'] ?? 0, 'Only Ann\'s own submission — not Jo Ann\'s, not Bob\'s mention');
        self::assertSame(1, $groups['convermetry-lead-history'] ?? 0);
        self::assertSame(1, $groups['convermetry-deliveries'] ?? 0);
        // The whole visit, and only Ann's visit: the three browser events plus
        // the form_success the server records when it confirms the submission.
        self::assertSame(4, $this->rowCount('cvmtry_events', 'session_id', self::SESSION));
        self::assertSame(4, $groups['convermetry-activity'] ?? 0, 'The whole visit, and only Ann\'s visit');

        $submission = $this->itemsIn($export, 'convermetry-submissions')[0];
        self::assertSame(self::ANN, $submission['Email']);
        self::assertSame('198.51.100.7', $submission['IP address']);
        self::assertSame('Qualified', $submission['Lead status']);
        self::assertSame('google', $submission['Campaign source']);
        self::assertSame('[Withheld: this field looks like a password or other credential]', $submission['Password']);

        $delivery = $this->itemsIn($export, 'convermetry-deliveries')[0];
        self::assertSame('127.0.0.1', $delivery['Destination'], 'Host only');
        self::assertStringNotContainsString('do-not-export-me', (string) wp_json_encode($export), 'An endpoint URL can carry a secret; it must never reach the export');
        self::assertSame('Delivered', $delivery['Result']);
    }

    public function testExportMatchesCaseInsensitivelyAndPagesByOffset(): void
    {
        for ($i = 0; $i < PersonalDataExporter::SUBMISSIONS_PER_PAGE + 2; $i++) {
            $this->submit('Ann@Example.COM', 'Ann ' . $i);
        }

        $first  = PersonalDataExporter::export('  ANN@example.com ', 1);
        $second = PersonalDataExporter::export(self::ANN, 2);

        self::assertFalse($first['done']);
        self::assertTrue($second['done']);
        self::assertCount(PersonalDataExporter::SUBMISSIONS_PER_PAGE, $this->itemsIn($first, 'convermetry-submissions'));
        self::assertCount(2, $this->itemsIn($second, 'convermetry-submissions'));
    }

    public function testExportForAnUnknownAddressIsEmptyAndDone(): void
    {
        $this->submit('someone@example.com', 'Someone');

        self::assertSame(['data' => [], 'done' => true], PersonalDataExporter::export('nobody@example.com', 1));
    }

    // ── Erasure ──────────────────────────────────────────────────────────────

    public function testErasureRemovesTheLeadAndScrubsEveryLinkedCopy(): void
    {
        global $wpdb;

        update_option(Options::NOTIFICATION_OPTION_KEY, ['enabled' => true, 'recipients' => ['staff@example.test']]);

        $this->recordAnnsVisitAndSubmission();
        $this->submit('joann@example.com', 'Jo Ann');
        $this->submit('bob@example.com', 'Bob', 'Please also reply to ann@example.com');

        do_action(FormDeliveryQueue::WORKER_HOOK);
        self::assertTrue(self::$receiver?->waitFor(3));

        $ann = $this->submissionFor(self::ANN);
        $bob = $this->submissionFor('bob@example.com');
        LeadService::update((string) $ann['submission_id'], 'won', '900', 1);

        // An analytics report that listed both conversions, as the Activity Log
        // stores it.
        $this->logAnalyticsReport([
            ['conversion_id' => (string) $ann['conversion_id'], 'ip_address' => '198.51.100.7', 'session_id' => self::SESSION],
            ['conversion_id' => (string) $bob['conversion_id'], 'ip_address' => '198.51.100.8', 'session_id' => ''],
        ]);

        self::assertGreaterThan(0, $this->rowCount('cvmtry_notification_queue', 'submission_id', (string) $ann['submission_id']));
        self::assertGreaterThan(0, $this->rowCount('cvmtry_lead_events', 'submission_id', (string) $ann['submission_id']));

        $result = $this->eraseUntilDone(self::ANN);

        self::assertTrue($result['items_removed']);
        self::assertFalse($result['items_retained']);

        // The lead and everything cascading from it.
        self::assertNull(FormSubmissions::getBySubmissionId((string) $ann['submission_id']));
        self::assertSame(0, $this->rowCount('cvmtry_notification_queue', 'submission_id', (string) $ann['submission_id']));
        self::assertSame(0, $this->rowCount('cvmtry_lead_events', 'submission_id', (string) $ann['submission_id']));
        self::assertSame(0, $this->rowCount('cvmtry_delivery_queue', 'submission_id', (string) $ann['submission_id']));

        // The audit trail survives; what it carried does not.
        $log = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . $wpdb->prefix . 'cvmtry_webhook_deliveries WHERE submission_id = %s',
            (string) $ann['submission_id']
        ), ARRAY_A);
        self::assertIsArray($log, 'The delivery record itself is kept');
        self::assertSame('1', (string) $log['success']);
        self::assertStringNotContainsString(self::ANN, (string) $log['request_data']);
        self::assertStringNotContainsString('198.51.100.7', (string) $log['request_data']);
        self::assertStringContainsString('personal_data_erasure', (string) $log['request_data']);
        self::assertStringContainsString('personal_data_erasure', (string) $log['response_data']);
        self::assertSame((string) $log['endpoint_url'], (string) $log['request_url']);

        // The analytics report keeps Bob's IP and loses Ann's.
        $report = (string) $wpdb->get_var(
            'SELECT request_data FROM ' . $wpdb->prefix . "cvmtry_webhook_deliveries WHERE message_type = 'analytics_report'"
        );
        self::assertStringNotContainsString('198.51.100.7', $report);
        self::assertStringNotContainsString(self::SESSION, $report);
        self::assertStringContainsString('198.51.100.8', $report);
        self::assertStringContainsString((string) $ann['conversion_id'], $report, 'Aggregate reporting keeps the conversion');

        // The visit stays as anonymous traffic (three browser events plus the
        // server-recorded conversion).
        self::assertSame(4, $this->rowCount('cvmtry_events', 'session_id', self::SESSION));
        self::assertSame('0', $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . "cvmtry_events WHERE session_id = %s AND ip_address <> ''",
            self::SESSION
        )));

        // Neither look-alike was touched.
        self::assertNotNull($this->submissionFor('joann@example.com'));
        self::assertNotNull(FormSubmissions::getBySubmissionId((string) $bob['submission_id']));
        self::assertStringContainsString(
            'bob@example.com',
            (string) $wpdb->get_var($wpdb->prepare(
                'SELECT request_data FROM ' . $wpdb->prefix . 'cvmtry_webhook_deliveries WHERE submission_id = %s',
                (string) $bob['submission_id']
            ))
        );

        $messages = implode("\n", $result['messages']);
        self::assertStringContainsString('127.0.0.1', $messages, 'The admin is told where copies already went');
        self::assertStringContainsString('cannot be recalled', $messages);
    }

    public function testAFrozenAnalyticsRetryIsReportedAsRetained(): void
    {
        $this->recordAnnsVisitAndSubmission();
        $ann = $this->submissionFor(self::ANN);

        update_option('cvmtry_webhook_retry_state', [
            md5('https://reports.example.test/') => [
                'url'  => 'https://reports.example.test/',
                'body' => (string) wp_json_encode(['analytics' => ['conversions' => ['recent' => [
                    ['conversion_id' => (string) $ann['conversion_id'], 'ip_address' => '198.51.100.7'],
                ]]]]),
            ],
        ], false);

        $result = $this->eraseUntilDone(self::ANN);

        self::assertTrue($result['items_removed']);
        self::assertTrue($result['items_retained'], 'A report the eraser cannot rewrite must be reported, not implied erased');
        self::assertStringContainsString('waiting to be retried', implode("\n", $result['messages']));
    }

    public function testErasurePagesPastNonMatchingCandidatesAndTerminates(): void
    {
        // More look-alikes than one page examines, all ahead of the real ones.
        for ($i = 0; $i < PersonalDataEraser::SUBMISSIONS_PER_PAGE + 5; $i++) {
            $this->submit('x' . $i . 'ann@example.com', 'Decoy ' . $i);
        }
        $this->submit(self::ANN, 'Ann one');
        $this->submit('ANN@example.com', 'Ann two');

        $pages  = 0;
        $result = $this->eraseUntilDone(self::ANN, $pages);

        self::assertTrue($result['items_removed']);
        self::assertSame(2, $pages, 'Resumes after the last examined candidate, and stops');
        self::assertNull($this->submissionFor(self::ANN));
        self::assertSame(
            PersonalDataEraser::SUBMISSIONS_PER_PAGE + 5,
            (int) $GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM ' . $GLOBALS['wpdb']->prefix . 'cvmtry_form_submissions'),
            'Every look-alike survives'
        );
    }

    public function testALostCursorStopsWithAnExplanationInsteadOfLooping(): void
    {
        $this->submit(self::ANN, 'Ann');

        $result = PersonalDataEraser::erase(self::ANN, 2);

        self::assertTrue($result['done']);
        self::assertTrue($result['items_retained']);
        self::assertStringContainsString('Run the erasure request again', implode("\n", $result['messages']));
        self::assertNotNull($this->submissionFor(self::ANN), 'Nothing is erased on an interrupted page');
    }

    public function testErasureForAnUnknownAddressChangesNothing(): void
    {
        $this->submit('someone@example.com', 'Someone');

        $result = $this->eraseUntilDone('nobody@example.com');

        self::assertFalse($result['items_removed']);
        self::assertFalse($result['items_retained']);
        self::assertSame([], $result['messages']);
        self::assertNotNull($this->submissionFor('someone@example.com'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Ann arrives from a Google campaign, views two pages, and submits the
     * contact form with the tracker's correlation fields in the POST — the way
     * a real browser submission reaches the provider hook.
     */
    private function recordAnnsVisitAndSubmission(): void
    {
        global $wpdb;

        $now = time();
        foreach ([['pageview', '/'], ['pageview', '/pricing/'], ['form_success', '/contact/']] as $i => [$type, $path]) {
            $wpdb->insert($wpdb->prefix . 'cvmtry_events', [
                'event_type'  => $type,
                'page_url'    => home_url($path),
                'event_value' => $type === 'form_success' ? 'conv_ann_000001' : '',
                'session_id'  => self::SESSION,
                'device'      => 'desktop',
                'utm_source'  => 'google',
                'ip_address'  => '198.51.100.7',
                'created_at'  => gmdate('Y-m-d H:i:s', $now - 60 + $i),
            ]);
        }

        $_POST = [
            'cvmtry_conversion_id' => 'conv_ann_000001',
            'cvmtry_session_id'    => self::SESSION,
            'cvmtry_context'       => (string) wp_json_encode(['utm_source' => 'google', 'utm_medium' => 'cpc']),
        ];

        do_action(
            'convermetry_form_submission',
            ['form_name' => 'Contact', 'form_id' => 'privacy-1'],
            [
                ['id' => 'name', 'label' => 'Name', 'value' => 'Ann Example'],
                ['id' => 'email', 'label' => 'Email', 'value' => self::ANN],
                ['id' => 'password', 'label' => 'Password', 'value' => 'hunter2'],
            ],
            []
        );

        $_POST = [];
    }

    private function submit(string $email, string $name, string $message = ''): void
    {
        $fields = [
            ['id' => 'name', 'label' => 'Name', 'value' => $name],
            ['id' => 'email', 'label' => 'Email', 'value' => $email],
        ];

        if ($message !== '') {
            $fields[] = ['id' => 'message', 'label' => 'Message', 'value' => $message];
        }

        do_action('convermetry_form_submission', ['form_name' => 'Contact', 'form_id' => 'privacy-1'], $fields, []);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function submissionFor(string $email): ?array
    {
        global $wpdb;

        $rows = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'cvmtry_form_submissions ORDER BY id ASC', ARRAY_A);

        foreach (is_array($rows) ? $rows : [] as $row) {
            foreach ((array) json_decode((string) $row['submission_data'], true) as $field) {
                if (is_array($field) && strtolower((string) ($field['value'] ?? '')) === strtolower($email)) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * Runs the eraser exactly as the Tools screen does: page 1, 2, … until done,
     * merging the per-page results the way core's JavaScript does.
     *
     * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
     */
    private function eraseUntilDone(string $email, int &$pages = 0): array
    {
        $merged = ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => false];

        for ($page = 1; $page <= 50; $page++) {
            $response = PersonalDataEraser::erase($email, $page);
            $pages    = $page;

            $merged['items_removed']  = $merged['items_removed'] || $response['items_removed'];
            $merged['items_retained'] = $merged['items_retained'] || $response['items_retained'];
            $merged['messages']       = array_merge($merged['messages'], $response['messages']);

            if ($response['done']) {
                $merged['done'] = true;

                return $merged;
            }
        }

        self::fail('The eraser never reported done');
    }

    /**
     * The items of one export group, each flattened to name => value.
     *
     * @param array{data: list<array<string, mixed>>, done: bool} $export
     * @return list<array<string, string>>
     */
    private function itemsIn(array $export, string $group): array
    {
        $out = [];

        foreach ($export['data'] as $item) {
            if ($item['group_id'] === $group) {
                $out[] = array_column($item['data'], 'value', 'name');
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, string>> $recent
     */
    private function logAnalyticsReport(array $recent): void
    {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'cvmtry_webhook_deliveries', [
            'success'         => 1,
            'endpoint_url'    => 'https://reports.example.test/',
            'delivery_id'     => md5('report'),
            'message_type'    => 'analytics_report',
            'kind'            => 'scheduled',
            'request_url'     => 'https://reports.example.test/',
            'request_headers' => '{}',
            'request_data'    => (string) wp_json_encode(['analytics' => ['conversions' => ['total' => 2, 'recent' => $recent]]]),
            'response_code'   => 200,
            'response_data'   => '',
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function rowCount(string $table, string $column, string $value): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $wpdb->prefix . $table . ' WHERE ' . $column . ' = %s',
            $value
        ));
    }

    private function truncateEverything(): void
    {
        global $wpdb;

        foreach ([
            'cvmtry_events', 'cvmtry_form_submissions', 'cvmtry_delivery_queue', 'cvmtry_webhook_deliveries',
            'cvmtry_notification_queue', 'cvmtry_goal_completions', 'cvmtry_lead_events',
        ] as $table) {
            $wpdb->query('TRUNCATE TABLE ' . $wpdb->prefix . $table);
        }
    }
}
