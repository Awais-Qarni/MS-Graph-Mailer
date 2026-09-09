<?php
if (! defined('ABSPATH')) {
    exit;
}

require_once 'class-msgraph-log-list-table.php';

class MSGraph_Admin
{

    /**
     * Stand-in rendered in the secret field when a secret is stored.
     */
    const SECRET_PLACEHOLDER = '__msgm_unchanged__';

    private $option_group = 'msgraph_mailer_options';
    private $option_name = 'msgraph_mailer_settings';

    public function add_plugin_admin_menu()
    {
        $hook = add_options_page(
            'MS Graph Mailer',
            'MS Graph Mailer',
            'manage_options',
            'ms-graph-mailer',
            array($this, 'display_plugin_setup_page')
        );

        add_action("load-$hook", array($this, 'handle_tab_actions'));
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
    }

    public function add_dashboard_widget()
    {
        if (current_user_can('manage_options')) {
            wp_add_dashboard_widget(
                'msgraph_mailer_stats_widget',
                'MS Graph Mailer Stats',
                array($this, 'render_dashboard_widget')
            );
        }
    }

    public function register_settings()
    {
        register_setting($this->option_group, $this->option_name, array($this, 'sanitize_settings'));

        add_settings_section(
            'msgraph_settings_section',
            'App Registration Settings',
            array($this, 'section_callback'),
            'ms-graph-mailer'
        );

        add_settings_field(
            'client_id',
            'Application (Client) ID',
            array($this, 'text_field_callback'),
            'ms-graph-mailer',
            'msgraph_settings_section',
            array('field' => 'client_id')
        );

        add_settings_field(
            'client_secret',
            'Client Secret',
            array($this, 'password_field_callback'),
            'ms-graph-mailer',
            'msgraph_settings_section',
            array('field' => 'client_secret')
        );

        add_settings_field(
            'tenant_id',
            'Directory (Tenant) ID',
            array($this, 'text_field_callback'),
            'ms-graph-mailer',
            'msgraph_settings_section',
            array('field' => 'tenant_id')
        );

        add_settings_field(
            'from_email',
            'From Email Address',
            array($this, 'text_field_callback'),
            'ms-graph-mailer',
            'msgraph_settings_section',
            array('field' => 'from_email')
        );

        add_settings_field(
            'log_retention',
            'Log Retention (Days)',
            array($this, 'number_field_callback'),
            'ms-graph-mailer',
            'msgraph_settings_section',
            array('field' => 'log_retention', 'default' => 30)
        );

        add_settings_field(
            'store_body',
            'Store Email Content',
            array($this, 'checkbox_field_callback'),
            'ms-graph-mailer',
            'msgraph_settings_section',
            array(
                'field'   => 'store_body',
                'default' => 1,
                'label'   => 'Keep a copy of each message body in the log.',
                'help'    => 'Bodies can contain password-reset links and personal data. Turning this off improves privacy, but "Resend" will no longer be able to reproduce the message.',
            )
        );

        add_settings_field(
            'enable_view_log',
            'Enable Viewing Email Content',
            array($this, 'checkbox_field_callback'),
            'ms-graph-mailer',
            'msgraph_settings_section',
            array('field' => 'enable_view_log', 'label' => 'Allow users to read full email content in logs (Privacy sensitive)')
        );

        add_settings_field(
            'delete_on_uninstall',
            'Delete Data on Uninstall',
            array($this, 'checkbox_field_callback'),
            'ms-graph-mailer',
            'msgraph_settings_section',
            array('field' => 'delete_on_uninstall', 'label' => 'Completely remove all logs and settings when the plugin is deleted.')
        );
    }

    public function sanitize_settings($input)
    {
        $existing  = get_option($this->option_name);
        $existing  = is_array($existing) ? $existing : array();
        $new_input = array();

        if (isset($input['client_id'])) $new_input['client_id'] = sanitize_text_field($input['client_id']);
        if (isset($input['tenant_id'])) $new_input['tenant_id'] = sanitize_text_field($input['tenant_id']);
        if (isset($input['from_email'])) $new_input['from_email'] = sanitize_email($input['from_email']);
        if (isset($input['from_name'])) $new_input['from_name'] = sanitize_text_field($input['from_name']);

        // The secret is never rendered back into the page, so an unchanged
        // field arrives as the placeholder. Treat that as "leave as-is".
        $submitted_secret = isset($input['client_secret']) ? trim($input['client_secret']) : '';
        if ('' === $submitted_secret || self::SECRET_PLACEHOLDER === $submitted_secret) {
            if (isset($existing['client_secret'])) {
                $new_input['client_secret'] = $existing['client_secret'];
            }
        } else {
            $new_input['client_secret'] = sanitize_text_field($submitted_secret);
        }

        if (isset($input['log_retention'])) $new_input['log_retention'] = absint($input['log_retention']);
        $new_input['enable_view_log']     = isset($input['enable_view_log']) ? 1 : 0;
        $new_input['store_body']          = isset($input['store_body']) ? 1 : 0;
        $new_input['delete_on_uninstall'] = isset($input['delete_on_uninstall']) ? 1 : 0;

        // Credentials may have changed; force a fresh token on the next send.
        MSGraph_Auth::clear_token();
        MSGraph_Settings::flush_cache();

        return $new_input;
    }

    public function section_callback()
    {
        echo '<p>Enter your Azure App credentials (Application Permissions).</p>';
    }

    public function text_field_callback($args)
    {
        if (MSGraph_Settings::is_constant($args['field'])) {
            $this->render_constant_notice($args['field']);
            return;
        }
        $options = get_option($this->option_name);
        $val = isset($options[$args['field']]) ? $options[$args['field']] : '';
        echo '<input type="text" id="' . esc_attr($args['field']) . '" name="' . esc_attr($this->option_name . '[' . $args['field'] . ']') . '" value="' . esc_attr($val) . '" class="regular-text" />';
    }

    public function password_field_callback($args)
    {
        $field = $args['field'];

        if (MSGraph_Settings::is_constant($field)) {
            $this->render_constant_notice($field);
            return;
        }

        // The stored secret is deliberately never written into the markup.
        // A saved secret is represented by a placeholder; submitting it
        // unchanged leaves the stored value alone.
        $has_value = '' !== MSGraph_Settings::get($field);

        echo '<input type="password" id="' . esc_attr($field) . '" name="' . esc_attr($this->option_name . '[' . $field . ']') . '"'
            . ' value="' . ($has_value ? esc_attr(self::SECRET_PLACEHOLDER) : '') . '"'
            . ' autocomplete="new-password" class="regular-text" />';

        if ($has_value) {
            echo '<p class="description">' . esc_html__('A secret is saved. Leave this field untouched to keep it, or paste a new secret to replace it.', 'ms-graph-mailer') . '</p>';
        }
    }

    /**
     * Shown in place of an input when a value is locked by wp-config.php.
     */
    private function render_constant_notice($field)
    {
        echo '<p class="msgm-locked-field"><span class="dashicons dashicons-lock"></span> '
            . sprintf(
                /* translators: %s: PHP constant name. */
                esc_html__('Defined by the %s constant in wp-config.php.', 'ms-graph-mailer'),
                '<code>' . esc_html(MSGraph_Settings::constant_name($field)) . '</code>'
            )
            . '</p>';
    }

    public function number_field_callback($args)
    {
        $options = get_option($this->option_name);
        $default = isset($args['default']) ? $args['default'] : '';
        $val = isset($options[$args['field']]) ? $options[$args['field']] : $default;
        echo '<input type="number" id="' . esc_attr($args['field']) . '" name="' . esc_attr($this->option_name . '[' . $args['field'] . ']') . '" value="' . esc_attr($val) . '" class="small-text" /> days';
    }

    public function checkbox_field_callback($args)
    {
        $options = get_option($this->option_name);
        $default = isset($args['default']) ? (int)$args['default'] : 0;
        $val = isset($options[$args['field']]) ? (int)$options[$args['field']] : $default;
        $label = isset($args['label']) ? $args['label'] : '';

        echo '<label for="' . esc_attr($args['field']) . '">';
        echo '<input type="checkbox" id="' . esc_attr($args['field']) . '" name="' . esc_attr($this->option_name . '[' . $args['field'] . ']') . '" value="1" ' . checked(1, $val, false) . ' /> ' . esc_html($label);
        echo '</label>';

        if (! empty($args['help'])) {
            echo '<p class="description">' . esc_html($args['help']) . '</p>';
        }
    }

    public function display_plugin_setup_page()
    {
        // Display persisted notices
        $notices = get_transient('msgraph_admin_notices');
        if ($notices) {
            foreach ($notices as $notice) {
                add_settings_error('msgraph_mailer_settings', $notice['code'], $notice['message'], $notice['type']);
            }
            delete_transient('msgraph_admin_notices');
        }

        settings_errors('msgraph_mailer_settings');

        $options = get_option($this->option_name);
        $auth = new MSGraph_Auth();

        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';
?>
        <div class="wrap">
            <h1>Microsoft Graph Mailer</h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=ms-graph-mailer&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">Settings</a>
                <a href="?page=ms-graph-mailer&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">Email Logs</a>
            </h2>

            <?php if ($active_tab == 'settings') : ?>
                <?php
                $status_html = '<span class="dashicons dashicons-warning" style="color:orange;"></span> Not Connected';
                $error_html = '';
                if (MSGraph_Settings::get('client_id') && MSGraph_Settings::get('client_secret')) {
                    $token = $auth->get_access_token();
                    if ($token) {
                        $status_html = '<span class="dashicons dashicons-yes" style="color:green;"></span> Connected (Token Valid)';
                    } else {
                        $last_err = get_transient('msgraph_last_auth_error');
                        $status_html = '<span class="dashicons dashicons-no" style="color:red;"></span> Connection Failed';
                        if ($last_err) {
                            $error_html = '<div style="margin-top:10px; color:#d63638; font-weight:bold;">Error: ' . esc_html($last_err) . '</div>';
                        }
                    }
                }
                ?>
                <div class="card" style="max-width: 600px; padding: 10px 20px; margin-top: 20px;">
                    <h3>Status: <?php echo $status_html; ?></h3>
                    <?php echo $error_html; ?>
                    <p>Mode: <strong>Client Credentials Flow</strong> (Application Permissions)</p>
                </div>

                <form action="options.php" method="post">
                    <?php
                    settings_fields($this->option_group);
                    do_settings_sections('ms-graph-mailer');
                    submit_button('Save Settings');
                    ?>
                </form>

                <hr>
                <h2>Send Test Email</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('msgraph_test_email', 'msgraph_test_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">To Email</th>
                            <td><input type="email" name="test_email_to" class="regular-text" required value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" /></td>
                        </tr>
                    </table>
                    <p><input type="submit" name="send_test_email" class="button button-secondary" value="Send Test Email" /></p>
                </form>

                <hr>
                <h2>Maintenance</h2>
                <div class="card">
                    <p>Clear all email logs from the database. This action cannot be undone.</p>
                    <p>
                        <a href="<?php echo wp_nonce_url(admin_url('options-general.php?page=ms-graph-mailer&tab=logs&action=clear_logs'), 'msgraph_clear_logs'); ?>"
                            class="button button-link-delete"
                            onclick="return confirm('Are you sure you want to delete ALL logs?');">
                            Clear All Logs Permanently
                        </a>
                    </p>
                </div>

            <?php elseif ($active_tab == 'logs') : ?>
                <style>
                    .msgraph-log-viewer {
                        background: #fff;
                        border: 1px solid #ccd0d4;
                        padding: 20px;
                        margin-top: 20px;
                        border-left: 4px solid #72aee6;
                    }

                    .msgraph-log-viewer h4 {
                        margin-top: 0;
                        border-bottom: 1px solid #eee;
                        padding-bottom: 10px;
                    }
                </style>

                <?php if (isset($_GET['view_log_id']) && ! empty($options['enable_view_log'])) :
                    $log = MSGraph_Logger::get_log((int) $_GET['view_log_id']);
                    if ($log) : ?>
                        <div class="msgraph-log-viewer" style="margin-bottom: 20px;">
                            <h4>Viewing Email Content (ID: <?php echo esc_html($log->id); ?>)</h4>
                            <p><strong>To:</strong> <?php echo esc_html($log->recipient); ?></p>
                            <p><strong>Subject:</strong> <?php echo esc_html($log->subject); ?></p>
                            <hr>
                            <div style="background:#f9f9f9; padding:15px; border:1px solid #eee; max-height:400px; overflow:auto;">
                                <?php echo wp_kses_post(wpautop($log->body)); ?>
                            </div>
                            <p><a href="?page=ms-graph-mailer&tab=logs" class="button">Close Viewer</a></p>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div id="poststuff">
                    <div id="post-body" class="metabox-holder">
                        <div id="post-body-content">
                            <div class="meta-box-sortables ui-sortable">
                                <form method="post">
                                    <?php
                                    $log_table = new MSGraph_Log_List_Table();
                                    $log_table->prepare_items();
                                    $log_table->display();
                                    ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_dashboard_widget()
    {
        $stats_today = MSGraph_Logger::get_stats(1);
        $stats_7days = MSGraph_Logger::get_stats(7);
        $stats_all   = MSGraph_Logger::get_stats(null);

        $logs_url = admin_url('options-general.php?page=ms-graph-mailer&tab=logs');
        ?>
        <table class="widefat fixed" style="border: none; box-shadow: none;">
            <thead>
                <tr>
                    <th style="padding-left: 0;">Timeframe</th>
                    <th>Sent</th>
                    <th>Failed</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td style="padding-left: 0;"><strong>Today</strong></td>
                    <td><span class="dashicons dashicons-yes" style="color: green;"></span> <?php echo $stats_today['success']; ?></td>
                    <td><span class="dashicons dashicons-no" style="color: red;"></span> <?php echo $stats_today['failed']; ?></td>
                </tr>
                <tr>
                    <td style="padding-left: 0;"><strong>Last 7 Days</strong></td>
                    <td><span class="dashicons dashicons-yes" style="color: green;"></span> <?php echo $stats_7days['success']; ?></td>
                    <td><span class="dashicons dashicons-no" style="color: red;"></span> <?php echo $stats_7days['failed']; ?></td>
                </tr>
                <tr>
                    <td style="padding-left: 0;"><strong>All Time</strong></td>
                    <td><span class="dashicons dashicons-yes" style="color: green;"></span> <?php echo $stats_all['success']; ?></td>
                    <td><span class="dashicons dashicons-no" style="color: red;"></span> <?php echo $stats_all['failed']; ?></td>
                </tr>
            </tbody>
        </table>
        <p style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 10px;">
            <a href="<?php echo esc_url($logs_url); ?>" class="button button-secondary">View Full Logs</a>
        </p>
<?php
    }

    public static function clear_all_logs()
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        $table_name = MSGraph_Logger::table();
        $wpdb->query("DELETE FROM $table_name");
        MSGraph_Logger::flush_stats_cache();
    }

    public function handle_tab_actions()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['send_test_email'])) {
            check_admin_referer('msgraph_test_email', 'msgraph_test_nonce');
            $this->handle_test_email();
            wp_safe_redirect(admin_url('options-general.php?page=ms-graph-mailer'));
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] == 'resend' && isset($_GET['log_id'])) {
            $log_id = intval($_GET['log_id']);
            check_admin_referer('msgraph_resend_log_' . $log_id);
            $this->resend_email($log_id);
            $redirect_url = admin_url('options-general.php?page=ms-graph-mailer&tab=logs');
            wp_safe_redirect($redirect_url);
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] == 'clear_logs') {
            check_admin_referer('msgraph_clear_logs');
            self::clear_all_logs();
            $this->queue_notice('logs_cleared', __('All email logs were deleted.', 'ms-graph-mailer'), 'success');
            $redirect_url = admin_url('options-general.php?page=ms-graph-mailer&tab=logs');
            wp_safe_redirect($redirect_url);
            exit;
        }
    }

    /**
     * Send the test message and queue a notice describing what happened.
     */
    private function handle_test_email()
    {
        $to = isset($_POST['test_email_to']) ? sanitize_email(wp_unslash($_POST['test_email_to'])) : '';

        if (! is_email($to)) {
            $this->queue_notice('test_email_fail', __('Enter a valid email address to send the test to.', 'ms-graph-mailer'), 'error');
            return;
        }

        $auth = new MSGraph_Auth();
        if (! $auth->get_access_token()) {
            $last_err = get_transient('msgraph_last_auth_error');
            $this->queue_notice('auth_fail', $last_err ? $last_err : __('Authentication failed.', 'ms-graph-mailer'), 'error');
            return;
        }

        // wp_mail() now returns the real outcome, so this reflects whether
        // Microsoft Graph actually accepted the message.
        $sent = wp_mail(
            $to,
            __('Test Email from MS Graph Mailer', 'ms-graph-mailer'),
            __('This is a test email. If you are reading it, MS Graph Mailer is configured correctly.', 'ms-graph-mailer')
        );

        if ($sent) {
            $this->queue_notice(
                'test_email_success',
                sprintf(
                    /* translators: %s: recipient email address. */
                    __('Test email sent to %s.', 'ms-graph-mailer'),
                    $to
                ),
                'success'
            );
            return;
        }

        $this->queue_notice(
            'test_email_fail',
            __('The test email failed. See the Email Logs tab for the error returned by Microsoft Graph.', 'ms-graph-mailer'),
            'error'
        );
    }

    /**
     * Store a notice so it survives the redirect that follows an action.
     */
    private function queue_notice($code, $message, $type = 'success')
    {
        $notices = get_transient('msgraph_admin_notices');
        if (! is_array($notices)) {
            $notices = array();
        }

        $notices[] = array('code' => $code, 'message' => $message, 'type' => $type);

        set_transient('msgraph_admin_notices', $notices, MINUTE_IN_SECONDS);
    }

    private function resend_email($log_id)
    {
        $log = MSGraph_Logger::get_log($log_id);
        if (! $log) {
            $this->queue_notice('resend_missing', __('That log entry no longer exists.', 'ms-graph-mailer'), 'error');
            return;
        }

        $headers     = MSGraph_Logger::decode_column($log->headers);
        $attachments = MSGraph_Logger::decode_column($log->attachments);

        if ('' === (string) $log->body && ! (int) MSGraph_Settings::get('store_body', 1)) {
            $this->queue_notice(
                'resend_no_body',
                __('This message was logged without its content, so it cannot be resent. Enable "Store Email Content" to allow resending.', 'ms-graph-mailer'),
                'warning'
            );
            return;
        }

        // Tag the resend so the sender updates this row instead of adding one.
        $headers[] = 'X-MSGraph-Log-ID: ' . (int) $log_id;

        $sent = wp_mail($log->recipient, $log->subject, $log->body, $headers, $attachments);

        if ($sent) {
            $this->queue_notice(
                'resend_success',
                sprintf(
                    /* translators: %s: recipient email address. */
                    __('Message resent to %s.', 'ms-graph-mailer'),
                    $log->recipient
                ),
                'success'
            );
        } else {
            $this->queue_notice(
                'resend_failed',
                __('The resend failed. The log entry has been updated with the error.', 'ms-graph-mailer'),
                'error'
            );
        }
    }
}
