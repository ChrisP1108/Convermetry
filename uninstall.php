<?php
/**
 * Convermetry — uninstall cleanup.
 *
 * Runs only when the plugin is deleted from the Plugins screen (never on
 * deactivation). Removes everything the plugin created: all four custom
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
function cvm_uninstall_current_site(): void
{
    global $wpdb;

    // Custom tables: analytics events, activity log, form submissions,
    // and the form-delivery queue.
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cvm_events");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cvm_webhook_deliveries");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cvm_form_submissions");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}cvm_delivery_queue");

    // Plugin options.
    delete_option('cvm_settings');
    delete_option('cvm_webhook_settings');
    delete_option('cvm_form_settings');
    delete_option('cvm_db_version');
    delete_option('cvm_delivery_db_version');
    delete_option('cvm_submissions_db_version');
    delete_option('cvm_queue_db_version');
    delete_option('cvm_delivery_api_active');
    delete_option('cvm_delivery_api_key_hash');
    delete_option('cvm_webhook_last_sent');
    delete_option('cvm_webhook_retry_state');
    delete_option('cvm_webhook_dispatch_lock');

    // Cleanup mutex. Unlike at deactivation, uninstall runs strictly after
    // deactivation has already completed — no plugin code can still be
    // running — so there is no in-progress holder left to disturb.
    delete_option('cvm_cleanup_lock');

    // Rate-limit counter rows, written directly to the options table by the
    // tracking REST controller when no persistent object cache is available.
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'cvm\\_rl\\_%'");

    // Transients (form-discovery caches, rate-limit flag, failure-log
    // throttle, API auth-failure counters).
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '\\_transient\\_cvm\\_%'
            OR option_name LIKE '\\_transient\\_timeout\\_cvm\\_%'"
    );

    // Scheduled cron events, including any pending single-event retries and
    // queue-worker runs.
    wp_clear_scheduled_hook('cvm_cleanup_old_events');
    wp_clear_scheduled_hook('cvm_cleanup_old_events_catchup');
    wp_clear_scheduled_hook('cvm_submissions_backfill_catchup');
    wp_clear_scheduled_hook('cvm_dispatch_webhooks');
    wp_clear_scheduled_hook('cvm_process_form_queue');
    wp_unschedule_hook('cvm_retry_webhook');
}

if (is_multisite()) {
    $cvm_site_ids = get_sites(['fields' => 'ids', 'number' => 0]);

    foreach ($cvm_site_ids as $cvm_site_id) {
        switch_to_blog((int) $cvm_site_id);
        cvm_uninstall_current_site();
        restore_current_blog();
    }
} else {
    cvm_uninstall_current_site();
}
