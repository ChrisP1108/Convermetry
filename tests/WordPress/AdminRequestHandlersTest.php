<?php
declare(strict_types=1);

namespace Convermetry\Tests\WordPress;

use Convermetry\Admin\Capability;
use Convermetry\Admin\Pages\ActivityLogPage;
use Convermetry\Admin\Pages\FormsPage;
use Convermetry\Admin\Pages\FunnelsPage;
use Convermetry\Admin\Pages\GoalsPage;
use Convermetry\Admin\Pages\NotificationsPage;
use Convermetry\Admin\Pages\SettingsPage;
use Convermetry\Admin\Pages\SubmissionsPage;
use Convermetry\Admin\Pages\WebhooksPage;
use Convermetry\Admin\ReportPeriod;
use Convermetry\Database\FormSubmissions;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\FormSettings;
use Convermetry\Funnels\FunnelRepository;
use Convermetry\Funnels\FunnelSettings;
use Convermetry\Goals\GoalRepository;
use Convermetry\Goals\GoalSettings;
use Convermetry\Notifications\NotificationQueue;
use Convermetry\Notifications\NotificationSettings;
use Convermetry\Notifications\SiteInfo;
use Convermetry\Settings\Options;
use Convermetry\Webhook\DeliveryKind;
use Convermetry\Webhook\DeliveryLog;
use Convermetry\Webhook\DeliveryLogEntry;
use Convermetry\Webhook\MessageType;
use Convermetry\Webhook\TransportResult;

/**
 * Every admin request handler, driven through the hook WordPress calls it on,
 * against WordPress's own nonces, users, roles and capabilities.
 *
 * Each handler is attacked the same eight ways — no nonce, a garbage nonce, an
 * expired nonce, a nonce for another action (including the read-only period
 * filter's), another user's nonce, an array where the nonce belongs, a user
 * whose role lacks the scope, and the wrong HTTP method — with a request body
 * that WOULD change something if any guard let it through. What is asserted is
 * not only that the request was refused but that nothing it could have done
 * happened: every option, table, the lead, the API key, the retry chain, the
 * outbox and the HTTP transport are compared before and after.
 *
 * Termination is modelled, not ignored: wp_die(), wp_send_json_*() and the
 * redirect that precedes every handler's exit all throw {@see HandlerHalted},
 * so code after a refusal cannot run in the test any more than it could in
 * production.
 *
 * Valid exports are not driven here: they stream with header() and end with
 * exit, which a PHPUnit process cannot survive. Their refusals are covered
 * below; the successful download is exercised over HTTP on a clean install.
 */
final class AdminRequestHandlersTest extends WordPressTestCase
{
    private const string ENDPOINT_URL   = 'https://93.184.215.14/hook';
    private const string ENDPOINT_ID    = 'a5d1f3c2-0b7e-4c1a-9f00-000000000001';
    private const string ENDPOINT_URL_2 = 'https://93.184.215.15/hook';
    private const string ENDPOINT_ID_2  = 'a5d1f3c2-0b7e-4c1a-9f00-000000000002';
    private const string RETRY_OPTION   = 'cvmtry_webhook_retry_state';
    private const string API_ACTIVE     = 'cvmtry_delivery_api_active';
    private const string API_KEY_HASH   = 'cvmtry_delivery_api_key_hash';

    /** @var array<string, int> */
    private static array $users = [];

    /** @var list<array<string, mixed>> */
    private array $mail = [];

    /** @var list<string> */
    private array $http = [];

    /** @var list<string> */
    private array $phpErrors = [];

    /** @var array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>, 3: array<string, mixed>} */
    private array $superglobals = [[], [], [], []];

    /** @var array{goal: string, funnel: string, submissionRow: int, submissionId: string, logRow: int} */
    private array $seed = ['goal' => '', 'funnel' => '', 'submissionRow' => 0, 'submissionId' => '', 'logRow' => 0];

    protected function setUp(): void
    {
        parent::setUp();

        $this->superglobals = [$_SERVER, $_GET, $_POST, $_REQUEST];

        self::registerAdminHandlers();
        self::ensureUsers();
        Capability::reset();

        // Installed before anything is seeded: nothing in this class may send
        // real mail or reach the network, fixtures included.
        add_filter('wp_die_handler', [self::class, 'dieHandler']);
        add_filter('wp_die_ajax_handler', [self::class, 'dieHandler']);
        add_filter('wp_redirect', [self::class, 'haltOnRedirect'], PHP_INT_MAX, 2);
        add_filter('pre_wp_mail', [$this, 'captureMail'], 10, 2);
        add_filter('pre_http_request', [$this, 'captureHttp'], 10, 3);

        $this->truncatePluginTables();
        $this->truncateNotificationQueue();
        $this->seedFixtures();

        $this->mail      = [];
        $this->http      = [];
        $this->phpErrors = [];

        // Anything the plugin's own code emits while handling a request —
        // "Array to string conversion" above all — fails the test that caused it.
        set_error_handler(function (int $errno, string $message, string $file, int $line): bool {
            if (str_contains($file, DIRECTORY_SEPARATOR . 'convermetry' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR)) {
                $this->phpErrors[] = $message . ' at ' . basename($file) . ':' . $line;

                return true;
            }

            return false;
        });

        wp_set_current_user(self::$users['admin']);
    }

    protected function tearDown(): void
    {
        restore_error_handler();

        remove_filter('wp_die_handler', [self::class, 'dieHandler']);
        remove_filter('wp_die_ajax_handler', [self::class, 'dieHandler']);
        remove_filter('wp_redirect', [self::class, 'haltOnRedirect'], PHP_INT_MAX);
        remove_filter('pre_wp_mail', [$this, 'captureMail'], 10);
        remove_filter('pre_http_request', [$this, 'captureHttp'], 10);
        remove_all_filters('convermetry_admin_capability');
        remove_all_filters('wp_doing_ajax');

        [$_SERVER, $_GET, $_POST, $_REQUEST] = $this->superglobals;

        wp_set_current_user(0);
        Capability::reset();

        parent::tearDown();
    }

    // ── The eight refusals, for every handler ────────────────────────────────

    /**
     * kind, action (admin-post or AJAX), nonce field, nonce action, scope.
     *
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function handlers(): array
    {
        return [
            'goals: save'                    => ['post', 'cvmtry_save_goal', 'cvmtry_nonce', 'cvmtry_save_goal', Capability::GOALS_MANAGE],
            'goals: delete'                  => ['post', 'cvmtry_delete_goal', 'cvmtry_nonce', 'cvmtry_delete_goal', Capability::GOALS_MANAGE],
            'funnels: save'                  => ['post', 'cvmtry_save_funnel', 'cvmtry_nonce', 'cvmtry_save_funnel', Capability::FUNNELS_MANAGE],
            'funnels: delete'                => ['post', 'cvmtry_delete_funnel', 'cvmtry_nonce', 'cvmtry_delete_funnel', Capability::FUNNELS_MANAGE],
            'activity log: clear'            => ['post', 'cvmtry_clear_activity_logs', 'cvmtry_clear_nonce', 'cvmtry_clear_activity_logs', Capability::ACTIVITY_MANAGE],
            'activity log: export csv'       => ['get', 'cvmtry_activity_export_csv', '_wpnonce', 'cvmtry_activity_export_csv', Capability::ACTIVITY_VIEW],
            'activity log: export json'      => ['get', 'cvmtry_activity_export_json', '_wpnonce', 'cvmtry_activity_export_json', Capability::ACTIVITY_VIEW],
            'submissions: clear'             => ['post', 'cvmtry_clear_submissions', 'cvmtry_clear_nonce', 'cvmtry_clear_submissions', Capability::SUBMISSIONS_DELETE],
            'submissions: export'            => ['get', 'cvmtry_submissions_export_csv', '_wpnonce', 'cvmtry_submissions_export_csv', Capability::SUBMISSIONS_EXPORT],
            'submissions: export filtered'   => ['get', 'cvmtry_submissions_export_csv_filtered', '_wpnonce', 'cvmtry_submissions_export_csv_filtered', Capability::SUBMISSIONS_EXPORT],
            'webhooks: save'                 => ['post', 'cvmtry_save_webhooks', 'cvmtry_webhooks_nonce', 'cvmtry_save_webhooks', Capability::WEBHOOKS_MANAGE],
            'webhooks: discard retry'        => ['get', 'cvmtry_discard_retry', 'cvmtry_nonce', 'cvmtry_discard_retry', Capability::WEBHOOKS_MANAGE],
            'notifications: save'            => ['post', 'cvmtry_save_notifications', 'cvmtry_notifications_nonce', 'cvmtry_save_notifications', Capability::NOTIFICATIONS_MANAGE],
            'notifications: discard queue'   => ['post', 'cvmtry_cancel_notifications', 'cvmtry_notifications_nonce', 'cvmtry_cancel_notifications', Capability::NOTIFICATIONS_MANAGE],
            'forms: save'                    => ['post', 'cvmtry_save_forms', 'cvmtry_forms_nonce', 'cvmtry_save_forms', Capability::FORMS_MANAGE],
            'ajax: activity log list'        => ['ajax', 'cvmtry_get_activity_logs', 'nonce', 'cvmtry_get_activity_logs', Capability::ACTIVITY_VIEW],
            'ajax: activity log delete'      => ['ajax', 'cvmtry_delete_activity_log', 'nonce', 'cvmtry_delete_activity_log', Capability::ACTIVITY_MANAGE],
            'ajax: deliveries API toggle'    => ['ajax', 'cvmtry_toggle_delivery_api', 'nonce', 'cvmtry_toggle_delivery_api', Capability::API_MANAGE],
            'ajax: deliveries API new key'   => ['ajax', 'cvmtry_regen_delivery_api_key', 'nonce', 'cvmtry_regen_delivery_api_key', Capability::API_MANAGE],
            'ajax: submissions list'         => ['ajax', 'cvmtry_get_submissions', 'nonce', 'cvmtry_get_submissions', Capability::SUBMISSIONS_VIEW],
            'ajax: submission detail'        => ['ajax', 'cvmtry_get_submission_detail', 'nonce', 'cvmtry_get_submission_detail', Capability::SUBMISSIONS_VIEW],
            'ajax: submission delete'        => ['ajax', 'cvmtry_delete_submission', 'nonce', 'cvmtry_delete_submission', Capability::SUBMISSIONS_DELETE],
            'ajax: lead update'              => ['ajax', 'cvmtry_update_lead', 'nonce', 'cvmtry_update_lead', Capability::LEADS_EDIT],
            'ajax: webhook test'             => ['ajax', 'cvmtry_test_webhook', 'nonce', 'cvmtry_test_webhook', Capability::WEBHOOKS_MANAGE],
            'ajax: notification test'        => ['ajax', 'cvmtry_test_notification', 'nonce', 'cvmtry_test_notification', Capability::NOTIFICATIONS_MANAGE],
        ];
    }

    /**
     * @dataProvider handlers
     */
    public function testAMissingNonceIsRefusedAndChangesNothing(string $kind, string $action, string $field, string $nonceAction, string $scope): void
    {
        $this->assertRefusedWithoutSideEffects($kind, $action, $this->payloadFor($action), 403);
    }

    /**
     * An administrator — a user who WOULD be allowed — with a nonce that is
     * simply wrong.
     *
     * @dataProvider handlers
     */
    public function testAnAuthorizedUserWithAnInvalidNonceIsRefused(string $kind, string $action, string $field, string $nonceAction, string $scope): void
    {
        $this->assertRefusedWithoutSideEffects($kind, $action, [$field => 'a1b2c3d4e5'] + $this->payloadFor($action), 403);
    }

    /**
     * @dataProvider handlers
     */
    public function testAnExpiredNonceIsRefused(string $kind, string $action, string $field, string $nonceAction, string $scope): void
    {
        $this->assertRefusedWithoutSideEffects($kind, $action, [$field => $this->expiredNonce($nonceAction)] + $this->payloadFor($action), 403);
    }

    /**
     * A genuine nonce for a DIFFERENT action: a sibling handler's, and the
     * read-only period filter's. Neither may stand in for this one.
     *
     * @dataProvider handlers
     */
    public function testANonceIssuedForAnotherActionIsRefused(string $kind, string $action, string $field, string $nonceAction, string $scope): void
    {
        $sibling = $nonceAction === 'cvmtry_save_goal' ? 'cvmtry_delete_goal' : 'cvmtry_save_goal';

        foreach ([$sibling, GoalsPage::PERIOD_NONCE, FunnelsPage::PERIOD_NONCE] as $other) {
            $this->assertRefusedWithoutSideEffects($kind, $action, [$field => wp_create_nonce($other)] + $this->payloadFor($action), 403);
        }
    }

    /**
     * @dataProvider handlers
     */
    public function testAnotherUsersNonceIsRefused(string $kind, string $action, string $field, string $nonceAction, string $scope): void
    {
        wp_set_current_user(self::$users['editor']);
        $editorsNonce = wp_create_nonce($nonceAction);
        wp_set_current_user(self::$users['admin']);

        $this->assertRefusedWithoutSideEffects($kind, $action, [$field => $editorsNonce] + $this->payloadFor($action), 403);
    }

    /**
     * An array where the nonce belongs is refused — not cast to "Array", and
     * not allowed to emit a PHP warning on the way.
     *
     * @dataProvider handlers
     */
    public function testAnArrayValuedNonceIsRefusedWithoutWarnings(string $kind, string $action, string $field, string $nonceAction, string $scope): void
    {
        $this->assertRefusedWithoutSideEffects($kind, $action, [$field => [wp_create_nonce($nonceAction)]] + $this->payloadFor($action), 403);
    }

    /**
     * A subscriber holding a perfectly valid nonce of their own: the nonce
     * proves where the request came from, never that its sender may act.
     *
     * @dataProvider handlers
     */
    public function testAValidNonceDoesNotStandInForTheCapability(string $kind, string $action, string $field, string $nonceAction, string $scope): void
    {
        wp_set_current_user(self::$users['subscriber']);

        $this->assertRefusedWithoutSideEffects($kind, $action, [$field => wp_create_nonce($nonceAction)] + $this->payloadFor($action), 403);
    }

    /**
     * Forms POST and links GET; each handler accepts only its own method.
     *
     * @dataProvider handlers
     */
    public function testTheWrongMethodIsRefused(string $kind, string $action, string $field, string $nonceAction, string $scope): void
    {
        $method = $kind === 'get' ? 'POST' : 'GET';

        $this->assertRefusedWithoutSideEffects($kind, $action, [$field => wp_create_nonce($nonceAction)] + $this->payloadFor($action), 405, $method);
    }

    // ── Capability scopes stay distinct ──────────────────────────────────────

    /**
     * A site that grants editors submissions.view gives them the list and the
     * detail — and still not deletion, which is its own scope.
     */
    public function testViewingSubmissionsDoesNotGrantDeletingThem(): void
    {
        $this->grantScopeTo(Capability::SUBMISSIONS_VIEW, 'edit_posts');
        wp_set_current_user(self::$users['editor']);

        $list = $this->ajax('cvmtry_get_submissions', ['nonce' => wp_create_nonce('cvmtry_get_submissions'), 'page' => '1']);
        self::assertTrue($list['success']);
        self::assertSame(1, $list['data']['total']);

        $before = $this->snapshot();
        $delete = $this->ajax('cvmtry_delete_submission', [
            'nonce'          => wp_create_nonce('cvmtry_delete_submission'),
            'submission_row' => (string) $this->seed['submissionRow'],
        ]);

        self::assertFalse($delete['success']);
        self::assertSame($before, $this->snapshot());
    }

    /**
     * And once the site grants editors submissions.delete too, the same
     * request succeeds — the scope is honored, not hard-coded to
     * manage_options.
     */
    public function testAGrantedDeleteScopeIsHonoredForANonAdministrator(): void
    {
        $this->grantScopeTo(Capability::SUBMISSIONS_DELETE, 'edit_posts');
        wp_set_current_user(self::$users['editor']);

        $delete = $this->ajax('cvmtry_delete_submission', [
            'nonce'          => wp_create_nonce('cvmtry_delete_submission'),
            'submission_row' => (string) $this->seed['submissionRow'],
        ]);

        self::assertTrue($delete['success']);
        self::assertSame(0, $this->rowCount(FormSubmissions::tableName()));
    }

    /**
     * Reading the Activity Log is not managing the deliveries API's key.
     */
    public function testViewingTheActivityLogDoesNotGrantTheApiKey(): void
    {
        $this->grantScopeTo(Capability::ACTIVITY_VIEW, 'edit_posts');
        wp_set_current_user(self::$users['editor']);

        $list = $this->ajax('cvmtry_get_activity_logs', ['nonce' => wp_create_nonce('cvmtry_get_activity_logs'), 'status' => 'error']);
        self::assertTrue($list['success']);

        $before = $this->snapshot();
        $regen  = $this->ajax('cvmtry_regen_delivery_api_key', ['nonce' => wp_create_nonce('cvmtry_regen_delivery_api_key')]);

        self::assertFalse($regen['success']);
        self::assertSame($before, $this->snapshot());
    }

    /**
     * options.php asks for 'manage_options' unless told otherwise; the
     * settings.manage scope is what it is told.
     */
    public function testTheSettingsGroupSaveUsesTheSettingsScope(): void
    {
        self::assertSame('manage_options', apply_filters('option_page_capability_cvmtry_settings_group', 'manage_options'));

        Capability::reset();
        $this->grantScopeTo(Capability::SETTINGS_MANAGE, 'edit_posts');

        self::assertSame('edit_posts', apply_filters('option_page_capability_cvmtry_settings_group', 'manage_options'));
    }

    // ── Valid requests still work ────────────────────────────────────────────

    public function testAGoalIsCreatedAndTheRedirectKeepsThePeriodWithAFreshFilterNonce(): void
    {
        $halt = $this->adminPost('cvmtry_save_goal', [
            'cvmtry_nonce' => wp_create_nonce(GoalsPage::SAVE_ACTION),
            'goal'         => ['name' => 'Brochure download', 'type' => 'click', 'operator' => 'contains', 'value' => '.pdf', 'enabled' => '1'],
        ], 'POST', ReportPeriod::queryArgs(GoalsPage::PERIOD_NONCE, 7));

        self::assertSame('redirect', $halt->how);
        self::assertCount(2, GoalRepository::visible());

        $query = $this->queryOf($halt->location);
        self::assertSame(GoalsPage::MENU_SLUG, $query['page']);
        self::assertSame('created', $query['cvmtry_goal_saved']);
        self::assertSame('7', $query['period']);

        // The carried nonce is a FILTER nonce: it reads the period and
        // authorizes nothing else.
        self::assertNotFalse(wp_verify_nonce($query[ReportPeriod::NONCE_FIELD], GoalsPage::PERIOD_NONCE));
        self::assertFalse(wp_verify_nonce($query[ReportPeriod::NONCE_FIELD], GoalsPage::SAVE_ACTION));
        self::assertFalse(wp_verify_nonce($query[ReportPeriod::NONCE_FIELD], GoalsPage::DELETE_ACTION));
    }

    public function testARedirectDropsAPeriodWhoseFilterNonceDidNotVerify(): void
    {
        $halt = $this->adminPost('cvmtry_save_goal', [
            'cvmtry_nonce' => wp_create_nonce(GoalsPage::SAVE_ACTION),
            'goal'         => ['name' => 'Brochure download', 'type' => 'click', 'operator' => 'contains', 'value' => '.pdf'],
        ], 'POST', ['period' => '7', ReportPeriod::NONCE_FIELD => wp_create_nonce(GoalsPage::SAVE_ACTION)]);

        $query = $this->queryOf($halt->location);
        self::assertSame('created', $query['cvmtry_goal_saved']);
        self::assertArrayNotHasKey('period', $query, 'A save nonce must not be accepted as the filter nonce.');
    }

    public function testAGoalIsRemovedOnlyByItsOwnId(): void
    {
        $halt = $this->adminPost('cvmtry_delete_goal', [
            'cvmtry_nonce' => wp_create_nonce(GoalsPage::DELETE_ACTION),
            'goal_id'      => [$this->seed['goal']],
        ]);

        self::assertSame('missing', $this->queryOf($halt->location)['cvmtry_goal_error']);
        self::assertCount(1, GoalRepository::visible(), 'An array-valued id removes nothing.');

        $halt = $this->adminPost('cvmtry_delete_goal', [
            'cvmtry_nonce' => wp_create_nonce(GoalsPage::DELETE_ACTION),
            'goal_id'      => $this->seed['goal'],
        ]);

        self::assertSame('deleted', $this->queryOf($halt->location)['cvmtry_goal_saved']);
        self::assertSame([], GoalRepository::visible());
    }

    public function testAFunnelIsCreatedAndRemoved(): void
    {
        $this->adminPost('cvmtry_save_funnel', [
            'cvmtry_nonce' => wp_create_nonce(FunnelsPage::SAVE_ACTION),
            'funnel'       => $this->funnelFields('Signup'),
        ]);
        self::assertCount(2, FunnelRepository::visible());

        $halt = $this->adminPost('cvmtry_delete_funnel', [
            'cvmtry_nonce' => wp_create_nonce(FunnelsPage::DELETE_ACTION),
            'funnel_id'    => $this->seed['funnel'],
        ]);

        self::assertSame('deleted', $this->queryOf($halt->location)['cvmtry_funnel_saved']);
        self::assertCount(1, FunnelRepository::visible());
    }

    public function testClearingTheActivityLogAndSubmissions(): void
    {
        $this->adminPost('cvmtry_clear_activity_logs', ['cvmtry_clear_nonce' => wp_create_nonce('cvmtry_clear_activity_logs')]);
        self::assertSame(0, $this->rowCount(DeliveryLog::tableName()));

        $this->adminPost('cvmtry_clear_submissions', ['cvmtry_clear_nonce' => wp_create_nonce('cvmtry_clear_submissions')]);
        self::assertSame(0, $this->rowCount(FormSubmissions::tableName()));
    }

    /**
     * intval(['5']) is 1. Before this release an array-valued row id named
     * row 1 — which is exactly the row the fixtures put there.
     */
    public function testAnArrayValuedRowIdDeletesNothing(): void
    {
        self::assertSame(1, $this->seed['submissionRow']);
        self::assertSame(1, $this->seed['logRow']);

        $before = $this->snapshot();

        $submission = $this->ajax('cvmtry_delete_submission', [
            'nonce'          => wp_create_nonce('cvmtry_delete_submission'),
            'submission_row' => ['5'],
        ]);
        $log = $this->ajax('cvmtry_delete_activity_log', [
            'nonce'  => wp_create_nonce('cvmtry_delete_activity_log'),
            'log_id' => ['5'],
        ]);
        $detail = $this->ajax('cvmtry_get_submission_detail', [
            'nonce'          => wp_create_nonce('cvmtry_get_submission_detail'),
            'submission_row' => ['1'],
        ]);

        foreach ([$submission, $log, $detail] as $response) {
            self::assertFalse($response['success']);
            self::assertSame(['message'], array_keys($response['data']));
        }

        self::assertSame($before, $this->snapshot());
    }

    public function testRowsAreDeletedAndReadByAValidId(): void
    {
        $detail = $this->ajax('cvmtry_get_submission_detail', [
            'nonce'          => wp_create_nonce('cvmtry_get_submission_detail'),
            'submission_row' => (string) $this->seed['submissionRow'],
        ]);
        self::assertTrue($detail['success']);
        self::assertStringContainsString('ada@example.com', $detail['data']['html']);

        $log = $this->ajax('cvmtry_delete_activity_log', [
            'nonce'  => wp_create_nonce('cvmtry_delete_activity_log'),
            'log_id' => (string) $this->seed['logRow'],
        ]);
        self::assertTrue($log['success']);
        self::assertSame(0, $this->rowCount(DeliveryLog::tableName()));

        $submission = $this->ajax('cvmtry_delete_submission', [
            'nonce'          => wp_create_nonce('cvmtry_delete_submission'),
            'submission_row' => (string) $this->seed['submissionRow'],
        ]);
        self::assertTrue($submission['success']);
        self::assertSame(0, $this->rowCount(FormSubmissions::tableName()));
    }

    public function testALeadIsUpdatedOnlyWithScalarFields(): void
    {
        $before = $this->snapshot();

        $refused = $this->ajax('cvmtry_update_lead', [
            'nonce'         => wp_create_nonce('cvmtry_update_lead'),
            'submission_id' => $this->seed['submissionId'],
            'lead_status'   => ['won'],
        ]);
        self::assertFalse($refused['success']);
        self::assertSame($before, $this->snapshot());

        $updated = $this->ajax('cvmtry_update_lead', [
            'nonce'         => wp_create_nonce('cvmtry_update_lead'),
            'submission_id' => $this->seed['submissionId'],
            'lead_status'   => 'won',
            'lead_value'    => '1250.00',
        ]);
        self::assertTrue($updated['success']);
        self::assertSame('won', FormSubmissions::getLead($this->seed['submissionId'])['lead_status'] ?? null);
    }

    public function testTheDeliveriesApiTogglesAndRegeneratesOnlyOnWellFormedRequests(): void
    {
        $before = $this->snapshot();

        foreach ([['active' => ['1']], ['active' => 'yes'], []] as $fields) {
            $response = $this->ajax('cvmtry_toggle_delivery_api', ['nonce' => wp_create_nonce('cvmtry_toggle_delivery_api')] + $fields);
            self::assertFalse($response['success']);
        }

        self::assertSame($before, $this->snapshot(), 'A malformed toggle neither disables the API nor mints a key.');

        $on = $this->ajax('cvmtry_toggle_delivery_api', ['nonce' => wp_create_nonce('cvmtry_toggle_delivery_api'), 'active' => '1']);
        self::assertTrue($on['success']);
        self::assertTrue((bool) get_option(self::API_ACTIVE));

        $hash  = get_option(self::API_KEY_HASH);
        $regen = $this->ajax('cvmtry_regen_delivery_api_key', ['nonce' => wp_create_nonce('cvmtry_regen_delivery_api_key')]);
        self::assertTrue($regen['success']);
        self::assertNotSame('', $regen['data']['key']);
        self::assertNotSame($hash, get_option(self::API_KEY_HASH));
    }

    public function testTheWebhookTestSendsOnlyForAValidRequest(): void
    {
        foreach ([
            ['url' => [self::ENDPOINT_URL], 'type' => 'analytics'],
            ['url' => self::ENDPOINT_URL, 'type' => ['form']],
            ['url' => self::ENDPOINT_URL, 'type' => 'everything'],
            ['url' => 'http://93.184.215.14/hook', 'type' => 'analytics'],
            ['url' => 'https://127.0.0.1/hook', 'type' => 'analytics'],
            ['url' => 'javascript:alert(1)', 'type' => 'analytics'],
        ] as $fields) {
            $response = $this->ajax('cvmtry_test_webhook', ['nonce' => wp_create_nonce('cvmtry_test_webhook')] + $fields);
            self::assertFalse($response['success'], (string) wp_json_encode($fields));
        }

        self::assertSame([], $this->http, 'No request leaves for a refused test.');

        $response = $this->ajax('cvmtry_test_webhook', [
            'nonce' => wp_create_nonce('cvmtry_test_webhook'),
            'url'   => self::ENDPOINT_URL,
            'type'  => 'form',
        ]);

        self::assertTrue($response['success']);
        self::assertCount(1, $this->http);
        self::assertStringStartsWith(self::ENDPOINT_URL, $this->http[0]);
    }

    public function testTheNotificationTestMailsOnlyAValidAddress(): void
    {
        foreach ([['recipient' => ['ops@example.com']], ['recipient' => 'not an address'], []] as $fields) {
            $response = $this->ajax('cvmtry_test_notification', ['nonce' => wp_create_nonce('cvmtry_test_notification')] + $fields);
            self::assertFalse($response['success']);
        }

        self::assertSame([], $this->mail);

        $response = $this->ajax('cvmtry_test_notification', [
            'nonce'     => wp_create_nonce('cvmtry_test_notification'),
            'recipient' => 'ops@example.com',
        ]);

        self::assertTrue($response['success']);
        self::assertCount(1, $this->mail);
        self::assertSame('ops@example.com', $this->mail[0]['to']);
    }

    public function testDiscardingQueuedNotifications(): void
    {
        self::assertSame(1, NotificationQueue::pendingCount());

        $this->adminPost('cvmtry_cancel_notifications', [
            'cvmtry_notifications_nonce' => wp_create_nonce('cvmtry_cancel_notifications'),
        ]);

        self::assertSame(0, NotificationQueue::pendingCount());
    }

    /**
     * Regression: the Discard link names a chain by md5 of its URL, while the
     * state map is keyed by the endpoint's durable id. Discard used to report
     * success and leave the retry queued.
     */
    public function testDiscardingARetryRemovesTheChainForAConfiguredEndpoint(): void
    {
        $refused = $this->adminGet('cvmtry_discard_retry', [
            'cvmtry_nonce' => wp_create_nonce('cvmtry_discard_retry'),
            'cvmtry_retry' => [md5(self::ENDPOINT_URL)],
        ]);
        self::assertSame(400, $refused->status);
        self::assertArrayHasKey(self::ENDPOINT_ID, (array) get_option(self::RETRY_OPTION));

        $halt = $this->adminGet('cvmtry_discard_retry', [
            'cvmtry_nonce' => wp_create_nonce('cvmtry_discard_retry'),
            'cvmtry_retry' => md5(self::ENDPOINT_URL),
        ]);

        self::assertSame('1', $this->queryOf($halt->location)['cvmtry_retry_discarded']);
        self::assertSame([], get_option(self::RETRY_OPTION));
    }

    // ── Webhook settings: sanitize, validate, never reset ─────────────────────

    public function testAWebhookSaveKeepsEndpointIdentityAndCredentialsByteForByte(): void
    {
        $secret = 'p@ss%41w0rd<with>"quotes"';

        $this->adminPost('cvmtry_save_webhooks', $this->webhookForm([
            ['id' => self::ENDPOINT_ID_2, 'url' => self::ENDPOINT_URL_2, 'label' => 'Second', 'secret' => $secret, 'forms' => '1'],
            ['id' => self::ENDPOINT_ID, 'url' => self::ENDPOINT_URL, 'label' => '<b>First</b>', 'analytics' => '1'],
            ['id' => '', 'url' => 'https://93.184.215.16/new', 'label' => 'New', 'analytics' => '1'],
        ], [
            'cvmtry_global_headers' => [
                ['key' => 'Authorization', 'value' => 'Bearer abc%2Fdef<urn:x>'],
                ['key' => 'X-Trace', 'value' => "a\tb"],
            ],
            'cvmtry_global_query'   => [['key' => 'src', 'value' => 'a%2Fb&c=d']],
        ]));

        $endpoints = Options::endpoints();
        self::assertCount(3, $endpoints);
        self::assertSame(self::ENDPOINT_ID_2, $endpoints[0]->id);
        self::assertSame($secret, $endpoints[0]->secret);
        self::assertSame(self::ENDPOINT_ID, $endpoints[1]->id);
        self::assertSame('First', $endpoints[1]->label, 'Labels are display text: tags are stripped.');
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $endpoints[2]->id);
        self::assertNotContains($endpoints[2]->id, [self::ENDPOINT_ID, self::ENDPOINT_ID_2]);

        self::assertSame([
            ['key' => 'Authorization', 'value' => 'Bearer abc%2Fdef<urn:x>'],
            ['key' => 'X-Trace', 'value' => "a\tb"],
        ], Options::globalHeaders());
        self::assertSame([['key' => 'src', 'value' => 'a%2Fb&c=d']], Options::globalQueryParams());

        // The retry chain keyed by the first endpoint's id is untouched.
        self::assertArrayHasKey(self::ENDPOINT_ID, (array) get_option(self::RETRY_OPTION));
    }

    public function testAPostedIdCanNeitherBeInventedNorClaimedTwice(): void
    {
        $this->adminPost('cvmtry_save_webhooks', $this->webhookForm([
            ['id' => self::ENDPOINT_ID, 'url' => self::ENDPOINT_URL, 'analytics' => '1'],
            ['id' => self::ENDPOINT_ID, 'url' => self::ENDPOINT_URL_2, 'analytics' => '1'],
            ['id' => 'invented-id', 'url' => 'https://93.184.215.16/new', 'analytics' => '1'],
        ]));

        $ids = array_map(static fn($endpoint): string => $endpoint->id, Options::endpoints());

        self::assertSame(self::ENDPOINT_ID, $ids[0]);
        self::assertNotSame(self::ENDPOINT_ID, $ids[1]);
        self::assertNotSame('invented-id', $ids[2]);
        self::assertCount(3, array_unique($ids));
    }

    /**
     * Rejected URLs carrying markup and control characters: the endpoints are
     * not saved, the notice names them by position with a sanitized excerpt,
     * and the URL as typed is stored nowhere.
     */
    public function testRejectedEndpointUrlsAreNeverStoredRaw(): void
    {
        $hostile = [
            'http://203.0.113.9/<script>alert(1)</script>',
            "https://exa\r\nmple.invalid/\x00<img src=x onerror=alert(1)>",
            'javascript:alert(document.cookie)',
            'https://127.0.0.1/' . str_repeat('a', 300),
        ];

        $rows = [['id' => self::ENDPOINT_ID, 'url' => self::ENDPOINT_URL, 'analytics' => '1']];
        foreach ($hostile as $url) {
            $rows[] = ['url' => $url, 'analytics' => '1'];
        }

        $halt = $this->adminPost('cvmtry_save_webhooks', $this->webhookForm($rows, [
            'cvmtry_global_headers' => [
                ['key' => 'X Bad Name', 'value' => 'v'],
                ['key' => 'X-Injected', 'value' => "ok\r\nSet-Cookie: x=1"],
                ['key' => 'X-Fine', 'value' => 'fine'],
            ],
        ]));
        self::assertSame('1', $this->queryOf($halt->location)['cvmtry_saved']);

        self::assertCount(1, Options::endpoints());
        self::assertSame([['key' => 'X-Fine', 'value' => 'fine']], Options::globalHeaders());

        $stored = get_transient('cvmtry_webhook_rejected_' . self::$users['admin']);
        self::assertIsArray($stored);
        self::assertSame(2, $stored['pairs']);
        self::assertSame([2, 3, 4, 5], array_column($stored['endpoints'], 'row'));
        self::assertSame(['insecure', 'invalid', 'invalid', 'invalid'], array_column($stored['endpoints'], 'reason'));

        // Inputs that carried markup or control characters do not survive in
        // any form; what is kept is the sanitized, bounded excerpt.
        $serialized = serialize($stored);
        self::assertStringNotContainsString($hostile[0], $serialized);
        self::assertStringNotContainsString($hostile[1], $serialized);
        self::assertStringNotContainsString($hostile[3], $serialized);
        self::assertSame('http://203.0.113.9/', $stored['endpoints'][0]['display']);
        foreach ($stored['endpoints'] as $entry) {
            self::assertDoesNotMatchRegularExpression('/[<>\x00-\x1F]/', $entry['display']);
            self::assertLessThanOrEqual(80, mb_strlen($entry['display']));
        }

        // The notice prints each excerpt escaped, once, and then forgets it.
        $_GET = ['page' => WebhooksPage::MENU_SLUG, 'cvmtry_saved' => '1'];
        ob_start();
        WebhooksPage::maybeShowNotices();
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('Endpoint 2', $html);
        self::assertStringContainsString('2 header or query-parameter rows were not saved', $html);
        self::assertFalse(get_transient('cvmtry_webhook_rejected_' . self::$users['admin']));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedWebhookForms(): array
    {
        return [
            'endpoint list missing'     => [['cvmtry_webhooks' => null]],
            'endpoint list is a string' => [['cvmtry_webhooks' => 'x']],
            'endpoint row is a string'  => [['cvmtry_webhooks' => ['x']]],
            'url is an array'           => [['cvmtry_webhooks' => [['url' => [self::ENDPOINT_URL]]]]],
            'id is an array'            => [['cvmtry_webhooks' => [['url' => self::ENDPOINT_URL, 'id' => [self::ENDPOINT_ID]]]]],
            'label is an array'         => [['cvmtry_webhooks' => [['url' => self::ENDPOINT_URL, 'label' => ['x']]]]],
            'secret is an array'        => [['cvmtry_webhooks' => [['url' => self::ENDPOINT_URL, 'secret' => ['x']]]]],
            'toggle is an array'        => [['cvmtry_webhooks' => [['url' => self::ENDPOINT_URL, 'forms' => ['1']]]]],
            'interval is an array'      => [['cvmtry_interval' => ['daily']]],
            'interval is unknown'       => [['cvmtry_interval' => 'every second']],
            'failure mode is unknown'   => [['cvmtry_failure_mode' => 'explode']],
            'shared secret is an array' => [['cvmtry_shared_secret' => ['x']]],
            'shared secret has a CR'    => [['cvmtry_shared_secret' => "abc\rdef"]],
            'headers is a string'       => [['cvmtry_global_headers' => 'Authorization: x']],
            'header row is a string'    => [['cvmtry_global_headers' => ['Authorization: x']]],
            'header name is an array'   => [['cvmtry_global_headers' => [['key' => ['Authorization'], 'value' => 'x']]]],
            'query value is an array'   => [['cvmtry_global_query' => [['key' => 'a', 'value' => ['b']]]]],
        ];
    }

    /**
     * Not one of these may be "sanitized" into a save: each would have
     * deleted endpoints, paused delivery or dropped headers nobody removed.
     *
     * @dataProvider malformedWebhookForms
     * @param array<string, mixed> $override
     */
    public function testAMalformedWebhookFormChangesNothing(array $override): void
    {
        $before = $this->snapshot();
        $form   = $this->webhookForm([['id' => self::ENDPOINT_ID, 'url' => self::ENDPOINT_URL_2, 'analytics' => '1']]);

        foreach ($override as $key => $value) {
            if ($value === null) {
                unset($form[$key]);
            } else {
                $form[$key] = $value;
            }
        }

        $halt = $this->adminPost('cvmtry_save_webhooks', $form);

        self::assertSame('malformed', $this->queryOf($halt->location)['cvmtry_error']);
        self::assertSame($before, $this->snapshot());
    }

    // ── Notifications and forms: identities and unrelated settings survive ──

    public function testNotificationRulesKeepFormIdentitiesAndUnrenderedForms(): void
    {
        update_option(Options::NOTIFICATION_OPTION_KEY, [
            'forms' => [
                'gravityforms:7'             => 'disabled', // provider not active: not rendered
                'elementor:Old Contact Form' => 'enabled',
            ],
        ], false);

        $this->adminPost('cvmtry_save_notifications', $this->notificationForm([
            'elementor:Contact Form 2' => 'enabled',
            'elementor:Old Contact Form' => 'inherit',
        ], ['elementor:Contact Form 2', 'elementor:Old Contact Form', "bricks:bad\nkey"]));

        $rules = Options::notificationAll()['forms'];

        self::assertSame(['gravityforms:7' => 'disabled', 'elementor:Contact Form 2' => 'enabled'], $rules);
        self::assertSame(['ops@example.com'], Options::notificationAll()['recipients']);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedNotificationForms(): array
    {
        return [
            'settings missing'          => [['cvmtry_notifications' => null]],
            'settings is a string'      => [['cvmtry_notifications' => 'x']],
            'recipients is an array'    => [['recipients' => ['ops@example.com']]],
            'subject is an array'       => [['subject' => ['x']]],
            'scope is unknown'          => [['scope' => 'some']],
            'toggle is an array'        => [['enabled' => ['1']]],
            'rule map is a string'      => [['forms' => 'x']],
            'a rule is an array'        => [['forms' => ['elementor:Contact' => ['enabled']]]],
            'a rule is unknown'         => [['forms' => ['elementor:Contact' => 'always']]],
            'rendered list is a string' => [['cvmtry_rendered_forms' => 'elementor:Contact']],
            'rendered key is an array'  => [['cvmtry_rendered_forms' => [['elementor:Contact']]]],
        ];
    }

    /**
     * @dataProvider malformedNotificationForms
     * @param array<string, mixed> $override
     */
    public function testAMalformedNotificationFormChangesNothing(array $override): void
    {
        $before = $this->snapshot();
        $form   = $this->notificationForm(['elementor:Contact' => 'enabled'], ['elementor:Contact']);

        foreach ($override as $key => $value) {
            if (!str_starts_with($key, 'cvmtry_')) {
                $form['cvmtry_notifications'][$key] = $value;
            } elseif ($value === null) {
                unset($form[$key]);
            } else {
                $form[$key] = $value;
            }
        }

        $halt = $this->adminPost('cvmtry_save_notifications', $form);

        self::assertSame('malformed', $this->queryOf($halt->location)['cvmtry_error']);
        self::assertSame($before, $this->snapshot());
    }

    public function testFormSettingsSaveOnlyWellFormedBlocksAndKeepEveryOtherForm(): void
    {
        update_option(FormSettings::OPTION_KEY, [
            'gravityforms:7' => ['form_id' => 'kept', 'excluded' => true, 'include_page_params' => false, 'query_params' => [], 'headers' => []],
            'elementor:Broken Block' => ['form_id' => 'untouched', 'excluded' => false, 'include_page_params' => false, 'query_params' => [], 'headers' => []],
        ], false);

        $this->adminPost('cvmtry_save_forms', [
            'cvmtry_forms_nonce' => wp_create_nonce('cvmtry_save_forms'),
            'cvmtry_forms'       => [
                md5('a') => [
                    'key'          => 'elementor:Contact Form 2',
                    'form_id'      => 'crm-contact',
                    'headers'      => [['key' => 'X-Api-Key', 'value' => 'k%3Dv<1>'], ['key' => 'Bad Name', 'value' => 'v']],
                    'query_params' => [['key' => 'list', 'value' => 'a&b']],
                ],
                md5('b') => [
                    'key'     => 'elementor:Broken Block',
                    'form_id' => ['x'],
                ],
                md5('c') => 'not a block',
            ],
        ]);

        $all = FormSettings::all();

        self::assertSame('kept', $all['gravityforms:7']['form_id']);
        self::assertSame('untouched', $all['elementor:Broken Block']['form_id']);
        self::assertSame('crm-contact', $all['elementor:Contact Form 2']['form_id']);
        self::assertSame([['key' => 'X-Api-Key', 'value' => 'k%3Dv<1>']], $all['elementor:Contact Form 2']['headers']);
        self::assertSame([['key' => 'list', 'value' => 'a&b']], $all['elementor:Contact Form 2']['query_params']);
        self::assertSame(['forms' => 2, 'pairs' => 1], get_transient('cvmtry_forms_skipped_' . self::$users['admin']));
    }

    public function testAMalformedFormListChangesNothing(): void
    {
        $before = $this->snapshot();

        $halt = $this->adminPost('cvmtry_save_forms', [
            'cvmtry_forms_nonce' => wp_create_nonce('cvmtry_save_forms'),
            'cvmtry_forms'       => 'elementor:Contact',
        ]);

        self::assertSame('malformed', $this->queryOf($halt->location)['cvmtry_error']);
        self::assertSame($before, $this->snapshot());
    }

    /**
     * (int) of an array is 1, and the clamp made that a 7-day retention.
     */
    public function testAMalformedSettingsFieldKeepsItsStoredValue(): void
    {
        update_option(Options::OPTION_KEY, array_merge(Options::defaults(), ['retention_days' => 120, 'client_id' => 'acme']));

        $clean = SettingsPage::sanitize(['retention_days' => ['1'], 'client_id' => ['x'], 'hover_dwell_ms' => '900']);

        self::assertSame(120, $clean['retention_days']);
        self::assertSame('acme', $clean['client_id']);
        self::assertSame(900, $clean['hover_dwell_ms']);
        self::assertSame(Options::all(), SettingsPage::sanitize('not an array'));
    }

    // ── The reporting-period filter ──────────────────────────────────────────

    public function testThePeriodFilterAcceptsOnlyItsOwnNonce(): void
    {
        $_GET = [];
        $plain = ReportPeriod::fromRequest(GoalsPage::PERIOD_NONCE, [7, 30, 90]);
        self::assertSame([30, false], [$plain->days, $plain->refused], 'Ordinary navigation needs no nonce.');

        $_GET = ReportPeriod::queryArgs(GoalsPage::PERIOD_NONCE, 90);
        $valid = ReportPeriod::fromRequest(GoalsPage::PERIOD_NONCE, [7, 30, 90]);
        self::assertSame([90, false], [$valid->days, $valid->refused]);

        $refusals = [
            'no nonce'               => ['period' => '7'],
            'expired nonce'          => ['period' => '7', ReportPeriod::NONCE_FIELD => $this->expiredNonce(GoalsPage::PERIOD_NONCE)],
            'save nonce'             => ['period' => '7', ReportPeriod::NONCE_FIELD => wp_create_nonce(GoalsPage::SAVE_ACTION)],
            'another screen'         => ['period' => '7', ReportPeriod::NONCE_FIELD => wp_create_nonce(FunnelsPage::PERIOD_NONCE)],
            'array nonce'            => ['period' => '7', ReportPeriod::NONCE_FIELD => [wp_create_nonce(GoalsPage::PERIOD_NONCE)]],
            'array period'           => ['period' => ['7'], ReportPeriod::NONCE_FIELD => wp_create_nonce(GoalsPage::PERIOD_NONCE)],
            'period not offered'     => ['period' => '45', ReportPeriod::NONCE_FIELD => wp_create_nonce(GoalsPage::PERIOD_NONCE)],
            'period with junk'       => ['period' => '7abc', ReportPeriod::NONCE_FIELD => wp_create_nonce(GoalsPage::PERIOD_NONCE)],
            'negative period'        => ['period' => '-7', ReportPeriod::NONCE_FIELD => wp_create_nonce(GoalsPage::PERIOD_NONCE)],
        ];

        foreach ($refusals as $label => $query) {
            $_GET     = $query;
            $selected = ReportPeriod::fromRequest(GoalsPage::PERIOD_NONCE, [7, 30, 90]);

            self::assertSame([30, true], [$selected->days, $selected->refused], $label);
        }

        self::assertSame([], $this->phpErrors);
    }

    /**
     * The rendered screen: every period link carries a filter nonce that
     * verifies, both forms post to admin-post.php under their own action, and
     * an expired link says why it was ignored.
     */
    public function testTheGoalsScreenLinksAndFormsCarryTheRightNonces(): void
    {
        $_GET = ['page' => GoalsPage::MENU_SLUG] + ReportPeriod::queryArgs(GoalsPage::PERIOD_NONCE, 7);
        $html = $this->render([GoalsPage::class, 'render']);

        preg_match_all('/href="([^"]*period=\d+[^"]*)"/', $html, $links);
        self::assertCount(3, $links[1]);
        foreach ($links[1] as $link) {
            $query = $this->queryOf(html_entity_decode($link));
            self::assertNotFalse(wp_verify_nonce($query[ReportPeriod::NONCE_FIELD], GoalsPage::PERIOD_NONCE));
        }

        preg_match_all('/<form method="post" action="([^"]+)"/', $html, $forms);
        self::assertCount(2, $forms[1], 'The Remove form and the editor.');
        foreach ($forms[1] as $action) {
            $action = html_entity_decode($action);
            self::assertStringContainsString('admin-post.php', $action);
            self::assertSame('7', $this->queryOf($action)['period'], 'The form keeps the selected period for its redirect.');
        }

        self::assertStringContainsString('name="action" value="cvmtry_save_goal"', $html);
        self::assertStringContainsString('name="action" value="cvmtry_delete_goal"', $html);
        self::assertStringNotContainsString('cvmtry_action', $html);
        self::assertStringNotContainsString('expired or is not valid', $html);

        $_GET = ['page' => GoalsPage::MENU_SLUG, 'period' => '7', ReportPeriod::NONCE_FIELD => $this->expiredNonce(GoalsPage::PERIOD_NONCE)];
        $html = $this->render([GoalsPage::class, 'render']);

        self::assertStringContainsString('That report link has expired or is not valid, so the last 30 days are shown.', $html);
        self::assertMatchesRegularExpression('/class="button button-primary">Last 30 days/', $html);
    }

    // ── Harness ──────────────────────────────────────────────────────────────

    /**
     * Sends one request that every guard should refuse, and proves nothing
     * changed.
     *
     * @param array<string, mixed> $fields
     */
    private function assertRefusedWithoutSideEffects(string $kind, string $action, array $fields, int $status, ?string $method = null): void
    {
        $before = $this->snapshot();

        if ($kind === 'ajax') {
            $response = $this->ajax($action, $fields, $method ?? 'POST');

            self::assertFalse($response['success'], "wp_ajax_{$action} accepted the request.");
            self::assertSame(['message'], array_keys((array) $response['data']), 'A refusal carries a message and nothing else.');
        } else {
            $halt = $kind === 'get'
                ? $this->adminGet($action, $fields, $method ?? 'GET')
                : $this->adminPost($action, $fields, $method ?? 'POST');

            self::assertSame('die', $halt->how, "admin_post_{$action} did not refuse: {$halt->getMessage()}");
            self::assertSame($status, $halt->status);
        }

        self::assertSame($before, $this->snapshot(), "admin_post/wp_ajax {$action} changed something while refusing.");
    }

    /**
     * Every piece of state any handler can change.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        global $wpdb;

        wp_cache_flush();

        return [
            'goals'         => get_option(Options::GOALS_OPTION_KEY),
            'funnels'       => get_option(Options::FUNNELS_OPTION_KEY),
            'webhooks'      => get_option(Options::WEBHOOK_OPTION_KEY),
            'notifications' => get_option(Options::NOTIFICATION_OPTION_KEY),
            'forms'         => get_option(FormSettings::OPTION_KEY),
            'settings'      => get_option(Options::OPTION_KEY),
            'retries'       => get_option(self::RETRY_OPTION),
            'api active'    => get_option(self::API_ACTIVE),
            'api key'       => get_option(self::API_KEY_HASH),
            'submissions'   => $this->rowCount(FormSubmissions::tableName()),
            'log rows'      => $this->rowCount(DeliveryLog::tableName()),
            'queued mail'   => NotificationQueue::pendingCount(),
            'lead'          => FormSubmissions::getLead($this->seed['submissionId']),
            'rejected'      => get_transient('cvmtry_webhook_rejected_' . get_current_user_id()),
            'mail sent'     => count($this->mail),
            'http sent'     => count($this->http),
            'php errors'    => $this->phpErrors,
            'notification queue table' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . NotificationQueue::tableName()),
        ];
    }

    /**
     * The request body that would have an effect if a guard let it through.
     *
     * @return array<string, mixed>
     */
    private function payloadFor(string $action): array
    {
        return match ($action) {
            'cvmtry_save_goal'             => ['goal' => ['name' => 'Injected', 'type' => 'click', 'operator' => 'tel', 'enabled' => '1']],
            'cvmtry_delete_goal'           => ['goal_id' => $this->seed['goal']],
            'cvmtry_save_funnel'           => ['funnel' => $this->funnelFields('Injected')],
            'cvmtry_delete_funnel'         => ['funnel_id' => $this->seed['funnel']],
            'cvmtry_save_webhooks'         => array_diff_key($this->webhookForm([['url' => 'https://93.184.215.17/injected', 'analytics' => '1']]), ['cvmtry_webhooks_nonce' => 0]),
            'cvmtry_discard_retry'         => ['cvmtry_retry' => md5(self::ENDPOINT_URL)],
            'cvmtry_save_notifications'    => array_diff_key($this->notificationForm(['elementor:Contact' => 'enabled'], ['elementor:Contact']), ['cvmtry_notifications_nonce' => 0]),
            'cvmtry_save_forms'            => ['cvmtry_forms' => [md5('x') => ['key' => 'elementor:Injected', 'form_id' => 'x']]],
            'cvmtry_get_activity_logs',
            'cvmtry_get_submissions'       => ['page' => '1'],
            'cvmtry_delete_activity_log'   => ['log_id' => (string) $this->seed['logRow']],
            'cvmtry_toggle_delivery_api'   => ['active' => '1'],
            'cvmtry_get_submission_detail',
            'cvmtry_delete_submission'     => ['submission_row' => (string) $this->seed['submissionRow']],
            'cvmtry_update_lead'           => ['submission_id' => $this->seed['submissionId'], 'lead_status' => 'won'],
            'cvmtry_test_webhook'          => ['url' => self::ENDPOINT_URL, 'type' => 'analytics'],
            'cvmtry_test_notification'     => ['recipient' => 'ops@example.com'],
            default                        => [],
        };
    }

    /**
     * A complete, valid Webhooks form.
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed>       $extra
     * @return array<string, mixed>
     */
    private function webhookForm(array $rows, array $extra = []): array
    {
        return array_merge([
            'cvmtry_webhooks_nonce' => wp_create_nonce('cvmtry_save_webhooks'),
            'cvmtry_webhook_active' => '1',
            'cvmtry_webhooks'       => $rows,
            'cvmtry_shared_secret'  => 'shared',
            'cvmtry_interval'       => 'weekly',
            'cvmtry_failure_mode'   => 'background',
        ], $extra);
    }

    /**
     * A complete, valid Notifications form.
     *
     * @param array<string, string> $rules
     * @param list<string>          $rendered
     * @return array<string, mixed>
     */
    private function notificationForm(array $rules, array $rendered): array
    {
        return [
            'cvmtry_notifications_nonce' => wp_create_nonce('cvmtry_save_notifications'),
            'cvmtry_notifications'       => [
                'enabled'    => '1',
                'recipients' => "ops@example.com\nnot-an-address",
                'subject'    => 'New {form_name}',
                'scope'      => 'all',
                'forms'      => $rules,
            ],
            'cvmtry_rendered_forms'      => $rendered,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function funnelFields(string $name): array
    {
        return [
            'name'    => $name,
            'enabled' => '1',
            'steps'   => [
                ['type' => 'page', 'operator' => 'equals', 'value' => '/pricing/'],
                ['type' => 'page', 'operator' => 'equals', 'value' => '/thank-you/'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $post
     * @param array<string, mixed> $get
     */
    private function adminPost(string $action, array $post, string $method = 'POST', array $get = []): HandlerHalted
    {
        $this->request($method, $post, $get);

        try {
            do_action('admin_post_' . $action);
        } catch (HandlerHalted $halt) {
            self::assertSame([], $this->phpErrors);

            return $halt;
        }

        self::fail("admin_post_{$action} returned without ending the request.");
    }

    /**
     * @param array<string, mixed> $get
     */
    private function adminGet(string $action, array $get, string $method = 'GET'): HandlerHalted
    {
        $this->request($method, $method === 'GET' ? [] : $get, $get);

        try {
            do_action('admin_post_' . $action);
        } catch (HandlerHalted $halt) {
            self::assertSame([], $this->phpErrors);

            return $halt;
        }

        self::fail("admin_post_{$action} returned without ending the request.");
    }

    /**
     * @param array<string, mixed> $post
     * @return array{success: bool, data: mixed}
     */
    private function ajax(string $action, array $post, string $method = 'POST'): array
    {
        $this->request($method, $method === 'POST' ? $post : [], $method === 'POST' ? [] : $post);

        add_filter('wp_doing_ajax', '__return_true');
        ob_start();
        $halted = false;

        try {
            do_action('wp_ajax_' . $action);
        } catch (HandlerHalted) {
            $halted = true;
        } finally {
            $output = (string) ob_get_clean();
            remove_filter('wp_doing_ajax', '__return_true');
        }

        self::assertTrue($halted, "wp_ajax_{$action} returned without ending the request.");
        self::assertSame([], $this->phpErrors);

        $decoded = json_decode($output, true);
        self::assertIsArray($decoded, "wp_ajax_{$action} did not answer with JSON: {$output}");
        self::assertIsBool($decoded['success'] ?? null);

        return ['success' => $decoded['success'], 'data' => $decoded['data'] ?? null];
    }

    /**
     * Sets the superglobals the way WordPress leaves them: slashed.
     *
     * @param array<string, mixed> $post
     * @param array<string, mixed> $get
     */
    private function request(string $method, array $post, array $get): void
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_POST                     = wp_slash($post);
        $_GET                      = wp_slash($get);
        $_REQUEST                  = array_merge($_GET, $_POST);
    }

    private function render(callable $renderer): string
    {
        ob_start();
        $renderer();

        return (string) ob_get_clean();
    }

    /**
     * @return array<string, mixed>
     */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * A nonce built exactly as wp_create_nonce() builds one, two ticks ago —
     * older than the two ticks wp_verify_nonce() accepts.
     */
    private function expiredNonce(string $action): string
    {
        $token = wp_get_session_token();
        $uid   = get_current_user_id();
        $build = static fn(float|int $tick): string => substr(wp_hash($tick . '|' . $action . '|' . $uid . '|' . $token, 'nonce'), -12, 10);

        // The construction is checked against WordPress's own, so the expired
        // nonce is expired and not merely malformed.
        self::assertSame(wp_create_nonce($action), $build(wp_nonce_tick($action)));

        return $build(wp_nonce_tick($action) - 2);
    }

    private function grantScopeTo(string $scope, string $capability): void
    {
        add_filter(
            'convermetry_admin_capability',
            static fn(string $default, string $requested): string => $requested === $scope ? $capability : $default,
            10,
            2
        );
        Capability::reset();
    }

    private function rowCount(string $table): int
    {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table);
    }

    private function truncateNotificationQueue(): void
    {
        global $wpdb;

        $wpdb->query('TRUNCATE TABLE ' . NotificationQueue::tableName());
    }

    /**
     * One of everything a handler can change, so a refusal that leaked would
     * have something to change.
     */
    private function seedFixtures(): void
    {
        global $wpdb;

        foreach ([Options::GOALS_OPTION_KEY, Options::FUNNELS_OPTION_KEY, Options::NOTIFICATION_OPTION_KEY,
                  FormSettings::OPTION_KEY, self::RETRY_OPTION] as $option) {
            delete_option($option);
        }
        delete_transient('cvmtry_webhook_rejected_' . self::$users['admin']);
        delete_transient('cvmtry_forms_skipped_' . self::$users['admin']);

        $goal = GoalSettings::sanitize(['name' => 'Phone tap', 'type' => 'click', 'operator' => 'tel', 'enabled' => '1'], null, gmdate('Y-m-d H:i:s'));
        self::assertNotNull($goal);
        self::assertTrue(GoalRepository::save($goal));

        $funnel = FunnelSettings::sanitize($this->funnelFields('Checkout'), null, gmdate('Y-m-d H:i:s'));
        self::assertNotNull($funnel);
        self::assertTrue(FunnelRepository::save($funnel));

        update_option(Options::WEBHOOK_OPTION_KEY, [
            'active'        => true,
            'shared_secret' => 'stored-secret',
            'interval'      => 'daily',
            'endpoints'     => [
                ['id' => self::ENDPOINT_ID, 'url' => self::ENDPOINT_URL, 'label' => 'A', 'secret' => '', 'analytics' => true, 'forms' => false],
                ['id' => self::ENDPOINT_ID_2, 'url' => self::ENDPOINT_URL_2, 'label' => 'B', 'secret' => 'b-secret', 'analytics' => false, 'forms' => true],
            ],
            'global_headers' => [['key' => 'X-Stored', 'value' => 'yes']],
        ]);

        // A frozen analytics retry for the first endpoint, keyed — as the
        // dispatcher keys it — by the endpoint's durable id.
        update_option(self::RETRY_OPTION, [self::ENDPOINT_ID => [
            'url' => self::ENDPOINT_URL, 'attempt' => 2, 'scheduled_for' => time() + 600, 'window_start' => time() - 86400,
            'window_end' => time(), 'delivery_id' => 'frozen-1', 'body' => '{}', 'request_url' => self::ENDPOINT_URL,
            'headers' => [], 'exhausted' => false, 'frozen_at' => time(),
        ]], false);

        update_option(self::API_ACTIVE, false);
        update_option(self::API_KEY_HASH, 'stored-hash');

        do_action(
            'convermetry_form_submission',
            ['form_name' => 'Contact', 'form_id' => 'e2e-1'],
            ['email' => 'ada@example.com', 'name' => 'Ada Lovelace'],
            []
        );

        $row = $wpdb->get_row('SELECT id, submission_id FROM ' . FormSubmissions::tableName() . ' ORDER BY id ASC LIMIT 1', ARRAY_A);
        self::assertIsArray($row);
        $this->seed['submissionRow'] = (int) $row['id'];
        $this->seed['submissionId']  = (string) $row['submission_id'];
        $this->seed['goal']          = (string) $goal['goal_id'];
        $this->seed['funnel']        = (string) $funnel['funnel_id'];

        NotificationQueue::enqueue(
            $this->seed['submissionId'],
            ['ops@example.com'],
            NotificationSettings::snapshot(Options::notificationAll(), 'elementor:Contact', SiteInfo::current())
        );

        DeliveryLog::log(new DeliveryLogEntry(
            result: TransportResult::failure('refused by fixture'),
            endpointUrl: self::ENDPOINT_URL,
            endpointLabel: 'A',
            deliveryId: 'fixture-delivery',
            messageType: MessageType::AnalyticsReport,
            kind: DeliveryKind::Test,
        ));
        $this->seed['logRow'] = (int) $wpdb->get_var('SELECT MIN(id) FROM ' . DeliveryLog::tableName());
    }

    /**
     * The admin screens register their handlers under is_admin(); this suite
     * boots WordPress outside wp-admin, so it registers them the same way.
     */
    private static function registerAdminHandlers(): void
    {
        if (has_action('admin_post_' . GoalsPage::DELETE_ACTION) !== false) {
            return;
        }

        $registry = new FormProviderRegistry();

        GoalsPage::init();
        FunnelsPage::init();
        SubmissionsPage::init();
        ActivityLogPage::init();
        WebhooksPage::init();
        NotificationsPage::init($registry);
        FormsPage::init($registry);
        SettingsPage::init();
    }

    private static function ensureUsers(): void
    {
        if (self::$users !== []) {
            return;
        }

        foreach (['admin' => 'administrator', 'editor' => 'editor', 'subscriber' => 'subscriber'] as $name => $role) {
            $login    = 'cvmtry_' . $name;
            $existing = get_user_by('login', $login);

            self::$users[$name] = $existing !== false
                ? $existing->ID
                : (int) wp_insert_user([
                    'user_login' => $login,
                    'user_pass'  => wp_generate_password(24),
                    'user_email' => $login . '@example.test',
                    'role'       => $role,
                ]);
        }
    }

    // ── Termination and transport doubles ────────────────────────────────────

    public static function dieHandler(): callable
    {
        return static function (mixed $message, mixed $title = '', mixed $args = []): never {
            $status = is_array($args) ? (int) ($args['response'] ?? 0) : (is_int($args) ? $args : 0);

            throw new HandlerHalted('die', is_string($message) ? $message : '', $status);
        };
    }

    public static function haltOnRedirect(string $location, int $status): string
    {
        throw new HandlerHalted('redirect', '', $status, $location);
    }

    /**
     * @param array<string, mixed> $atts
     */
    public function captureMail(mixed $short, array $atts): bool
    {
        $this->mail[] = $atts;

        return true;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function captureHttp(mixed $preempt, array $args, string $url): array
    {
        $this->http[] = $url;

        return ['headers' => [], 'body' => '{}', 'response' => ['code' => 200, 'message' => 'OK'], 'cookies' => [], 'filename' => null];
    }
}
