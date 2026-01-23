<?php
if (! defined('ABSPATH')) {
    exit;
}

class MSGraph_Mailer_Core
{

    public function run()
    {
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->define_cron_hooks();
    }

    private function define_admin_hooks()
    {
        $plugin_admin = new MSGraph_Admin();
        add_action('admin_menu', array($plugin_admin, 'add_plugin_admin_menu'));
        add_action('admin_init', array($plugin_admin, 'register_settings'));
    }

    private function define_public_hooks()
    {
        $plugin_sender = new MSGraph_Sender();
        add_filter('pre_wp_mail', array($plugin_sender, 'send_email'), 10, 2);
    }

    private function define_cron_hooks()
    {
        add_action('msgraph_daily_log_prune', array('MSGraph_Logger', 'prune_logs'));
    }
}
