<?php

/**
 * Plugin Name: MS Graph Mailer
 * Plugin URI: https://github.com/Awais-Qarni/MS-Graph-Mailer.git
 * Description: Sends your WordPress emails reliably using Microsoft 365. Includes a dashboard to track, view, and resend messages easily.
 * Version: 2.1.2
 * Author: Muhammad Awais
 * Author URI: mailto:reachoutawais@gmail.com
 * License: GPLv2 or later
 * Text Domain: ms-graph-mailer
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (! defined('ABSPATH')) {
	exit;
}

// Define Constants
define('MS_GRAPH_MAILER_VERSION', '2.1.2');
define('MS_GRAPH_MAILER_PATH', plugin_dir_path(__FILE__));
define('MS_GRAPH_MAILER_URL', plugin_dir_url(__FILE__));

// Includes
require_once MS_GRAPH_MAILER_PATH . 'includes/class-msgraph-settings.php';
require_once MS_GRAPH_MAILER_PATH . 'includes/class-msgraph-logger.php';
require_once MS_GRAPH_MAILER_PATH . 'includes/class-msgraph-mailer-core.php';
require_once MS_GRAPH_MAILER_PATH . 'includes/class-msgraph-auth.php';
require_once MS_GRAPH_MAILER_PATH . 'includes/class-msgraph-admin.php';
require_once MS_GRAPH_MAILER_PATH . 'includes/class-msgraph-sender.php';

/**
 * Add settings link to plugins page.
 */
function msgm_plugin_action_links($links)
{
	$settings_link = '<a href="options-general.php?page=ms-graph-mailer">' . __('Settings') . '</a>';
	array_unshift($links, $settings_link);
	return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'msgm_plugin_action_links');

/**
 * Activation hook.
 */
function msgm_activate()
{
	// Create logs table
	MSGraph_Logger::create_table();

	// Schedule daily pruning
	if (! wp_next_scheduled('msgraph_daily_log_prune')) {
		wp_schedule_event(time(), 'daily', 'msgraph_daily_log_prune');
	}
}
register_activation_hook(__FILE__, 'msgm_activate');

/**
 * Deactivation hook.
 */
function msgm_deactivate()
{
	wp_clear_scheduled_hook('msgraph_daily_log_prune');
}
register_deactivation_hook(__FILE__, 'msgm_deactivate');

/**
 * Initialize the plugin.
 */
function msgm_init()
{
	$plugin = new MSGraph_Mailer_Core();
	$plugin->run();
}
add_action('plugins_loaded', 'msgm_init');
