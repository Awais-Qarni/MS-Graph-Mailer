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

// 1. Check Delete Data Option
$options = get_option('msgraph_mailer_settings');
$should_delete = isset($options['delete_on_uninstall']) ? (bool)$options['delete_on_uninstall'] : false;

if ($should_delete) {
    // Delete Options
    delete_option('msgraph_mailer_settings');
    delete_option('msgraph_tokens');

    // Delete Transients
    delete_transient('msgraph_last_auth_error');

    // Drop Table
    global $wpdb;
    $table_name = $wpdb->prefix . 'msgraph_email_logs';
    $wpdb->query("DROP TABLE IF EXISTS $table_name");
}

// Note: WordPress automatically deletes the plugin directory after this script runs.
