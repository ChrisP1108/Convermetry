<?php
/**
 * Plugin Name: Convermetry
 * Description: Visitor analytics, campaign attribution, and server-confirmed form conversion tracking with reliable webhook delivery. Connects every lead to its analytics session, traffic source, and campaign, and delivers analytics reports and form submissions to any number of webhook endpoints with signing, retries, and idempotency.
 * Version:     0.1.2
 * Requires at least: 6.3
 * Requires PHP: 8.3
 * Author:      Chris Paschall
 * License:     GPL-2.0-or-later
 * Text Domain: convermetry
 */

if (!defined('ABSPATH')) exit;

/**
 * PHP version guard.
 *
 * This file must not use PHP 8.3+ syntax directly. PHP parses the entire file
 * before executing any branch, so 8.3+ syntax here would cause a fatal parse
 * error on older runtimes before this guard ever runs. PHP 8.3+ code is safely
 * isolated in the separately required files inside the else block below.
 */
if (version_compare(PHP_VERSION, '8.3', '<')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>';
        printf(
            '<strong>Convermetry</strong> requires PHP 8.3 or higher. '
            . 'Your server is running PHP %s. Please contact your host to upgrade PHP before activating this plugin.',
            esc_html(PHP_VERSION)
        );
        echo '</p></div>';
    });

/**
 * Main plugin bootstrap.
 * Defines plugin constants, boots the PSR-4 autoloader, and registers the
 * activation/deactivation hooks and the plugins_loaded handler that
 * instantiates and initializes the Plugin composition root.
 */
} else {

    define('CVM_VERSION', '0.1.2');
    define('CVM_PLUGIN_FILE', __FILE__);
    define('CVM_PLUGIN_DIR', plugin_dir_path(__FILE__));
    define('CVM_PLUGIN_URL', plugin_dir_url(__FILE__));

    require_once CVM_PLUGIN_DIR . 'src/Autoloader.php';

    Convermetry\Autoloader::boot(CVM_PLUGIN_DIR);

    /**
     * Plugin activation: create the custom tables and schedule the cron
     * events for daily data retention cleanup and periodic webhook dispatch.
     *
     * register_activation_hook must be called in the main plugin file (not
     * inside a class method) to fire reliably. Each table owner handles
     * idempotent creation via dbDelta, so re-activating the plugin is safe.
     */
    register_activation_hook(__FILE__, static function (): void {
        Convermetry\Database\DatabaseManager::createTable();
        Convermetry\Database\FormSubmissions::createTable();
        Convermetry\Webhook\DeliveryLog::createTable();
        Convermetry\Webhook\FormDeliveryQueue::createTable();

        if (!wp_next_scheduled('cvm_cleanup_old_events')) {
            wp_schedule_event(time(), 'daily', 'cvm_cleanup_old_events');
        }

        Convermetry\Webhook\AnalyticsDispatcher::schedule();

        // Resume any form-delivery queue rows that were pending when the
        // plugin was deactivated — deactivation clears the worker cron but
        // keeps the queue rows, so the first activation re-arms the worker.
        Convermetry\Webhook\FormDeliveryQueue::ensureWorkerScheduled();
    });

    /**
     * Plugin deactivation: unschedule the plugin's cron events. Pending
     * webhook work is suspended, not discarded:
     *
     *  - Analytics-report retry chains keep their frozen deliveries in the
     *    exhausted state so the first scheduled dispatch after reactivation
     *    resumes them under their original delivery IDs.
     *  - Form-submission queue rows stay in the database and are resumed by
     *    the worker that activation re-schedules.
     *
     * All tables and their data are intentionally preserved on deactivation.
     * Data is only removed when rows age past the configured retention
     * window or when the plugin is uninstalled (see uninstall.php).
     */
    register_deactivation_hook(__FILE__, static function (): void {
        wp_clear_scheduled_hook('cvm_cleanup_old_events');
        wp_clear_scheduled_hook(Convermetry\Database\DatabaseManager::CLEANUP_CATCHUP_HOOK);
        wp_clear_scheduled_hook(Convermetry\Webhook\AnalyticsDispatcher::CRON_HOOK);
        wp_clear_scheduled_hook(Convermetry\Webhook\FormDeliveryQueue::WORKER_HOOK);
        Convermetry\Webhook\AnalyticsDispatcher::suspendAllRetries();
    });

    add_action('plugins_loaded', static function (): void {
        Convermetry\Plugin::getInstance()->init();
    });

    /**
     * Records a custom analytics event from server-side code.
     *
     * Use this to track interactions the frontend script cannot see (e.g. a
     * REST API hit, a completed purchase, a custom conversion action). Events
     * recorded here appear in the dashboard's totals and are included in
     * analytics webhook payloads under their own event type.
     *
     * @param string               $type Short event type key (lowercase letters,
     *                                   digits, dashes, underscores; max 20 chars).
     * @param array<string, mixed> $data Optional event context. Recognized keys:
     *                                   'page_url', 'page_title', 'element_tag',
     *                                   'element_label', 'target_url',
     *                                   'event_value', 'referrer', 'session_id',
     *                                   'device', 'utm_source', 'utm_medium',
     *                                   'utm_campaign', 'utm_id', 'utm_term',
     *                                   'utm_content', 'click_id_type',
     *                                   'channel', and 'session_referrer' (the
     *                                   session's entrance referrer — used for
     *                                   channel classification, not stored).
     *                                   Unknown keys are ignored.
     *                                   Note: a 'form_success' event requires
     *                                   'event_value' to be a unique conversion
     *                                   id (8–100 chars of A-Za-z0-9_.:-);
     *                                   events without one are rejected so
     *                                   conversion dedup stays consistent.
     * @return bool True when the event row was stored.
     */
    function cvm_track_event($type, array $data = array())
    {
        return Convermetry\Database\DatabaseManager::insertEvent((string) $type, $data);
    }

    /**
     * Submits a custom (non-integrated) form through Convermetry and returns
     * the result.
     *
     * The submission is recorded (with a conversion record and analytics
     * correlation when the Convermetry tracker's hidden fields are present in
     * the current request) and delivered SYNCHRONOUSLY to every webhook
     * endpoint that accepts form submissions. Use this when the caller needs
     * to know whether delivery succeeded; failed deliveries are NOT retried
     * automatically — the caller receives them in the result and decides.
     *
     * For fire-and-forget integrations with background retries, use:
     *
     *     do_action('convermetry_form_submission', $formIdentifier, $fields, $context);
     *
     * @param array{form_name: string, form_id?: string} $form_identifier Form name (required)
     *                                                   and optional native form id.
     * @param array<string, mixed>  $fields          Field names/IDs to raw values.
     * @param array<string, mixed>  $url_query       Extra query parameters for this call only.
     * @param array<string, string> $request_headers Extra request headers for this call only.
     * @return \Convermetry\Forms\SubmissionResult Readonly result object.
     */
    function convermetry_submit_form(array $form_identifier, array $fields, array $url_query = [], array $request_headers = []): \Convermetry\Forms\SubmissionResult
    {
        return Convermetry\Plugin::getInstance()
            ->getSubmissionService()
            ->submitCustom($form_identifier, $fields, $url_query, $request_headers, true);
    }
}
