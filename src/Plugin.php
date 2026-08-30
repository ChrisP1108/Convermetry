<?php
declare(strict_types=1);

namespace Convermetry;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AboutPage;
use Convermetry\Admin\ActivityLogPage;
use Convermetry\Admin\AnalyticsPage;
use Convermetry\Admin\FormsPage;
use Convermetry\Admin\FunnelsPage;
use Convermetry\Admin\GoalsPage;
use Convermetry\Admin\NotificationsPage;
use Convermetry\Admin\SettingsPage;
use Convermetry\Admin\SubmissionsPage;
use Convermetry\Admin\WebhooksPage;
use Convermetry\Api\DeliveryLogController;
use Convermetry\Api\TrackingController;
use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Database\MigrationRunner;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\SubmissionService;
use Convermetry\Goals\GoalCompletions;
use Convermetry\Leads\LeadEvents;
use Convermetry\Notifications\NotificationDispatcher;
use Convermetry\Notifications\NotificationQueue;
use Convermetry\Settings\SettingsEvents;
use Convermetry\Tracking\ScriptLoader;
use Convermetry\Webhook\AnalyticsDispatcher;
use Convermetry\Webhook\DeliveryLog;
use Convermetry\Webhook\FormDeliveryQueue;

/**
 * Composition root for the entire plugin.
 *
 * A single instance is created on plugins_loaded (see the main plugin file)
 * and init() wires every subsystem together:
 *
 *  - MigrationRunner      — decides which request may run schema DDL, and
 *                           defers it out of visitors' page loads
 *  - DatabaseManager / FormSubmissions / DeliveryLog / FormDeliveryQueue /
 *    NotificationQueue / GoalCompletions / LeadEvents — the seven custom
 *    tables (creation, upgrades, retention cleanup)
 *  - TrackingController   — public REST endpoint that receives tracked events
 *  - ScriptLoader         — enqueues the frontend tracker script with its config
 *  - AnalyticsDispatcher  — scheduled analytics-report webhook delivery
 *  - FormDeliveryQueue    — background form-submission webhook delivery
 *  - FormProviderRegistry — form-plugin integrations (feature-detected)
 *  - SubmissionService    — the pipeline every confirmed submission flows through
 *  - DeliveryLogController — read-only deliveries REST API
 *  - Admin pages          — Analytics, Submissions, Forms, Webhooks,
 *                           Activity Log, Settings, About
 *
 * Static subsystems expose an init() that registers their own hooks; the
 * instance-based form layer (registry + service) is held here so the public
 * convermetry_submit_form() helper can reach it.
 */
final class Plugin
{
    /** @var Plugin|null Singleton instance. */
    private static ?Plugin $instance = null;

    private readonly FormProviderRegistry $formRegistry;
    private readonly SubmissionService $submissionService;

    /**
     * Private constructor; use {@see getInstance()}.
     */
    private function __construct()
    {
        $this->formRegistry      = new FormProviderRegistry();
        $this->submissionService = new SubmissionService();
    }

    /**
     * Returns the shared plugin instance, creating it on first call.
     *
     * @return Plugin
     */
    public static function getInstance(): Plugin
    {
        return self::$instance ??= new self();
    }

    /**
     * The form-provider registry (for the Forms admin page).
     *
     * @return FormProviderRegistry
     */
    public function getFormRegistry(): FormProviderRegistry
    {
        return $this->formRegistry;
    }

    /**
     * The shared submission pipeline (for convermetry_submit_form()).
     *
     * @return SubmissionService
     */
    public function getSubmissionService(): SubmissionService
    {
        return $this->submissionService;
    }

    /**
     * Initializes every subsystem. Called once on plugins_loaded.
     *
     * @return void
     */
    public function init(): void
    {
        // Schema migrations used to run right here, on plugins_loaded — which
        // means they ran inside every anonymous frontend page load. That was
        // survivable while migrations only added a column; it stopped being
        // survivable once they started adding indexes to the events table, which
        // is a locking rebuild on every engine. MigrationRunner owns the
        // decision about which request may pay that cost, and defers the rest.
        MigrationRunner::init();
        MigrationRunner::boot();

        TrackingController::init();
        ScriptLoader::init();
        AnalyticsDispatcher::init();
        FormDeliveryQueue::init();
        NotificationQueue::init();
        DeliveryLogController::init();

        // Listens on WordPress's own option-write hooks rather than the admin
        // handlers, so convermetry_settings_saved fires on a real write and
        // never on a form submitted without edits.
        SettingsEvents::init();

        // Listens on 'convermetry_submission_recorded', which fires before the
        // webhook-endpoint check in SubmissionService::record() — so internal
        // email notifications work on a site with no webhooks configured, and
        // are governed by their own master toggle.
        NotificationDispatcher::init();

        // Form-provider hooks are feature-detected: providers whose plugin is
        // absent register nothing, so nothing here can fatal without them.
        $this->formRegistry->registerHooks($this->submissionService);

        // Public custom-form API: fire-and-forget submissions with
        // background delivery. Result-aware callers use
        // convermetry_submit_form() instead.
        //
        //     do_action('convermetry_form_submission', $formIdentifier, $fields, $context);
        //
        // $formIdentifier is ['form_name' => string, 'form_id' => string?].
        // $fields is either a list of ['id', 'label', 'value'] descriptors —
        // the same shape webhook receivers get as submission_data — or the
        // long-standing name => value map, where each key becomes both id and
        // label. SubmissionFields owns the normalization for both.
        // $context is optional: ['url_query' => array, 'headers' => array],
        // applying to this submission only.
        add_action(
            'convermetry_form_submission',
            function (array $formIdentifier, array $fields, array $context = []): void {
                $this->submissionService->submitCustom(
                    $formIdentifier,
                    $fields,
                    is_array($context['url_query'] ?? null) ? $context['url_query'] : [],
                    is_array($context['headers'] ?? null) ? $context['headers'] : [],
                    false
                );
            },
            10,
            3
        );

        add_action('cvm_cleanup_old_events', [DatabaseManager::class, 'cleanupOldEvents']);
        add_action('cvm_cleanup_old_events', [DeliveryLog::class, 'purgeOld']);
        add_action('cvm_cleanup_old_events', [FormSubmissions::class, 'purgeOld']);
        // Goal completions and lead status history are analytics data and age
        // out on exactly the same window as everything else — a site owner who
        // sets 30-day retention must not find two tables quietly keeping
        // conversion history forever.
        add_action('cvm_cleanup_old_events', [GoalCompletions::class, 'purgeOld']);
        add_action('cvm_cleanup_old_events', [LeadEvents::class, 'purgeOld']);
        // Finishes the derived-column backfill (channel/utm_campaign from
        // 1.2.0, delivery_state from 1.3.0); a no-op once every row is
        // populated. The catch-up hook drains large tables sooner than the
        // daily run would, re-arming itself while work remains.
        add_action('cvm_cleanup_old_events', [FormSubmissions::class, 'backfillOnCleanup']);
        add_action(FormSubmissions::BACKFILL_CATCHUP_HOOK, [FormSubmissions::class, 'backfillCatchUp']);
        add_action('cvm_cleanup_old_events', [FormDeliveryQueue::class, 'ensureWorkerScheduled']);
        add_action('cvm_cleanup_old_events', [NotificationQueue::class, 'ensureWorkerScheduled']);
        add_action('cvm_cleanup_old_events', [NotificationQueue::class, 'purgeOrphans']);
        add_action(DatabaseManager::CLEANUP_CATCHUP_HOOK, [DatabaseManager::class, 'cleanupOldEventsCatchUp']);

        $this->ensureCronScheduled();

        if (is_admin()) {
            // Submenu order follows registration order — Submissions sits
            // directly under Analytics.
            AnalyticsPage::init();
            SubmissionsPage::init();
            GoalsPage::init();
            FunnelsPage::init();
            FormsPage::init($this->formRegistry);
            NotificationsPage::init($this->formRegistry);
            WebhooksPage::init();
            ActivityLogPage::init();
            SettingsPage::init();
            AboutPage::init();
        }
    }

    /**
     * Safety net that re-schedules the cron events if they are missing.
     *
     * Activation normally schedules both events, but crons can be lost (a
     * cron-clearing plugin, a site migration, a manual wp-cron purge).
     * Re-checking on every load keeps retention cleanup and webhook dispatch
     * running without requiring a re-activation.
     *
     * @return void
     */
    private function ensureCronScheduled(): void
    {
        if (!wp_next_scheduled('cvm_cleanup_old_events')) {
            wp_schedule_event(time(), 'daily', 'cvm_cleanup_old_events');
        }

        AnalyticsDispatcher::schedule();
    }
}
