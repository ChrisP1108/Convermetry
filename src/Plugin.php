<?php
declare(strict_types=1);

namespace Convermetry;

if (!defined('ABSPATH')) exit;

use Convermetry\Admin\AboutPage;
use Convermetry\Admin\ActivityLogPage;
use Convermetry\Admin\AnalyticsPage;
use Convermetry\Admin\FormsPage;
use Convermetry\Admin\SettingsPage;
use Convermetry\Admin\WebhooksPage;
use Convermetry\Api\DeliveryLogController;
use Convermetry\Api\TrackingController;
use Convermetry\Database\DatabaseManager;
use Convermetry\Database\FormSubmissions;
use Convermetry\Forms\FormProviderRegistry;
use Convermetry\Forms\SubmissionService;
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
 *  - DatabaseManager / FormSubmissions / DeliveryLog / FormDeliveryQueue —
 *    the four custom tables (creation, upgrades, retention cleanup)
 *  - TrackingController   — public REST endpoint that receives tracked events
 *  - ScriptLoader         — enqueues the frontend tracker script with its config
 *  - AnalyticsDispatcher  — scheduled analytics-report webhook delivery
 *  - FormDeliveryQueue    — background form-submission webhook delivery
 *  - FormProviderRegistry — form-plugin integrations (feature-detected)
 *  - SubmissionService    — the pipeline every confirmed submission flows through
 *  - DeliveryLogController — read-only deliveries REST API
 *  - Admin pages          — Analytics, Forms, Webhooks, Activity Log, Settings, About
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
        DatabaseManager::maybeUpgrade();
        FormSubmissions::maybeUpgrade();
        DeliveryLog::maybeCreateTable();
        FormDeliveryQueue::maybeUpgrade();

        TrackingController::init();
        ScriptLoader::init();
        AnalyticsDispatcher::init();
        FormDeliveryQueue::init();
        DeliveryLogController::init();

        // Form-provider hooks are feature-detected: providers whose plugin is
        // absent register nothing, so nothing here can fatal without them.
        $this->formRegistry->registerHooks($this->submissionService);

        // Public custom-form API: fire-and-forget submissions with
        // background delivery. Result-aware callers use
        // convermetry_submit_form() instead.
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
        add_action('cvm_cleanup_old_events', [FormDeliveryQueue::class, 'ensureWorkerScheduled']);
        add_action(DatabaseManager::CLEANUP_CATCHUP_HOOK, [DatabaseManager::class, 'cleanupOldEventsCatchUp']);

        $this->ensureCronScheduled();

        if (is_admin()) {
            AnalyticsPage::init();
            FormsPage::init($this->formRegistry);
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
