<?php

/**
 * MS Graph Mailer Uninstall
 *
 * This file is called when the user clicks "Delete" in the WordPress plugins UI.
 */

// If uninstall not called from WordPress, exit.
if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Remove all plugin data for the current site.
 */
function msgm_uninstall_site()
{
    global $wpdb;

    $options = get_option('msgraph_mailer_settings');
    $should_delete = is_array($options) && ! empty($options['delete_on_uninstall']);

    if (! $should_delete) {
        return;
    }

    delete_option('msgraph_mailer_settings');
    delete_option('msgraph_tokens');
    delete_option('msgraph_db_version');

    delete_transient('msgraph_last_auth_error');
    delete_transient('msgraph_admin_notices');
    delete_transient('msgraph_stats_cache');

    wp_clear_scheduled_hook('msgraph_daily_log_prune');

    $table_name = $wpdb->prefix . 'msgraph_email_logs';
    $wpdb->query("DROP TABLE IF EXISTS `$table_name`");
}

if (is_multisite()) {
    $site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        msgm_uninstall_site();
        restore_current_blog();
    }
} else {
    msgm_uninstall_site();
}

// Note: WordPress automatically deletes the plugin directory after this script runs.
