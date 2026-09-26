<?php
/**
 * Convermetry — uninstall cleanup.
 *
 * Runs only when the plugin is deleted from the Plugins screen (never on
 * deactivation). Removes everything the plugin created: all seven custom
 * tables, all options, transients, and any scheduled cron events. On
 * multisite, the per-site cleanup runs for EVERY site — tables, options,
 * and cron events are per-site, so a network-activated uninstall that only
 * cleaned the current site would leave data behind everywhere else. After
 * this runs, no trace of the plugin remains in the database.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Removes everything the plugin created for the CURRENT site (tables,
 * options, transients, cron events).
 *
 * @return void
 */
function convermetry_uninstall_current_site(): void
{
    global $wpdb;

    // Custom tables: analytics events, activity log, form submissions,
    // the form-delivery queue, the notification queue, goal completions,
    // and lead status history.
    $tables = [
        'cvm_events',
        'cvm_webhook_deliveries',
        'cvm_form_submissions',
        'cvm_delivery_queue',
        'cvm_notification_queue',
        'cvm_goal_completions',
        'cvm_lead_events',
    ];

    foreach ($tables as $table) {
        $wpdb->query($wpdb->prepare('DROP TABLE IF EXISTS %i', $wpdb->prefix . $table));
    }

    // Plugin options.
    delete_option('cvm_settings');
    delete_option('cvm_webhook_settings');
    delete_option('cvm_form_settings');
    delete_option('cvm_notification_settings');
    delete_option('cvm_goals');
    delete_option('cvm_goal_selectors');
    delete_option('cvm_funnels');
    delete_option('cvm_db_version');
    delete_option('cvm_delivery_db_version');
    delete_option('cvm_submissions_db_version');
    delete_option('cvm_queue_db_version');
    delete_option('cvm_notification_db_version');
    delete_option('cvm_goals_db_version');
    delete_option('cvm_leads_db_version');
    delete_option('cvm_delivery_api_active');
    delete_option('cvm_delivery_api_key_hash');
    delete_option('cvm_webhook_last_sent');
    delete_option('cvm_webhook_retry_state');
    delete_option('cvm_webhook_state_version');
    delete_option('cvm_webhook_dispatch_lock');
    delete_option('cvm_migration_lock');

    // Cleanup mutex. Unlike at deactivation, uninstall runs strictly after
    // deactivation has already completed — no plugin code can still be
    // running — so there is no in-progress holder left to disturb.
    delete_option('cvm_cleanup_lock');

    // Rate-limit counter rows, written directly to the options table by the
    // tracking REST controller when no persistent object cache is available.
    $wpdb->query($wpdb->prepare(
        'DELETE FROM %i WHERE option_name LIKE %s',
        $wpdb->options,
        $wpdb->esc_like('cvm_rl_') . '%'
    ));

    // Queue-repair records, one row per submission, written directly for the
    // same reason.
    $wpdb->query($wpdb->prepare(
        'DELETE FROM %i WHERE option_name LIKE %s',
        $wpdb->options,
        $wpdb->esc_like('cvm_queue_repair_') . '%'
    ));

    // Transients (form-discovery caches, rate-limit flag, failure-log
    // throttle, API auth-failure counters).
    $wpdb->query($wpdb->prepare(
        'DELETE FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
        $wpdb->options,
        $wpdb->esc_like('_transient_cvm_') . '%',
        $wpdb->esc_like('_transient_timeout_cvm_') . '%'
    ));

    // Scheduled cron events, including any pending single-event retries and
    // queue-worker runs.
    wp_clear_scheduled_hook('cvm_cleanup_old_events');
    wp_clear_scheduled_hook('cvm_cleanup_old_events_catchup');
    wp_clear_scheduled_hook('cvm_run_migrations');
    wp_clear_scheduled_hook('cvm_submissions_backfill_catchup');
    wp_clear_scheduled_hook('cvm_dispatch_webhooks');
    wp_clear_scheduled_hook('cvm_process_form_queue');
    wp_unschedule_hook('cvm_reconcile_form_queue');
    wp_clear_scheduled_hook('cvm_process_notifications');
    wp_unschedule_hook('cvm_retry_webhook');
}

if (is_multisite()) {
    $convermetry_site_ids = get_sites(['fields' => 'ids', 'number' => 0]);

    foreach ($convermetry_site_ids as $convermetry_site_id) {
        switch_to_blog((int) $convermetry_site_id);
        convermetry_uninstall_current_site();
        restore_current_blog();
    }
} else {
    convermetry_uninstall_current_site();
}
