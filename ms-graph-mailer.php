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
 * Per-site setup: create the log table and schedule pruning.
 */
function msgm_activate_site()
{
	MSGraph_Logger::create_table();

	if (! wp_next_scheduled('msgraph_daily_log_prune')) {
		wp_schedule_event(time(), 'daily', 'msgraph_daily_log_prune');
	}
}

/**
 * Activation hook.
 *
 * On a network activation every site needs its own log table, not just the
 * one that happened to be current.
 *
 * @param bool $network_wide Whether the plugin was network activated.
 */
function msgm_activate($network_wide = false)
{
	if ($network_wide && is_multisite()) {
		$site_ids = get_sites(array('fields' => 'ids', 'number' => 0));
		foreach ($site_ids as $site_id) {
			switch_to_blog($site_id);
			msgm_activate_site();
			restore_current_blog();
		}
		return;
	}

	msgm_activate_site();
}
register_activation_hook(__FILE__, 'msgm_activate');

/**
 * Set up a site created while the plugin is network active.
 */
function msgm_on_new_site($site)
{
	if (! function_exists('is_plugin_active_for_network')) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if (! is_plugin_active_for_network(plugin_basename(__FILE__))) {
		return;
	}

	switch_to_blog((int) $site->blog_id);
	msgm_activate_site();
	restore_current_blog();
}
add_action('wp_initialize_site', 'msgm_on_new_site', 100);

/**
 * Run pending schema upgrades.
 *
 * create_table() only ever ran on activation, so an install that was already
 * active would never receive a later schema change.
 */
function msgm_maybe_upgrade()
{
	MSGraph_Logger::maybe_upgrade();
}
add_action('plugins_loaded', 'msgm_maybe_upgrade', 5);

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
