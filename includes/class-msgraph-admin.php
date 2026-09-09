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

    const PAGE_SLUG = 'ms-graph-mailer';

    private $option_group = 'msgraph_mailer_options';
    private $option_name  = 'msgraph_mailer_settings';

    /**
     * Admin page hook, used to scope asset loading.
     */
    private $hook = '';

    public function add_plugin_admin_menu()
    {
        $this->hook = add_options_page(
            __('MS Graph Mailer', 'ms-graph-mailer'),
            __('MS Graph Mailer', 'ms-graph-mailer'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'display_plugin_setup_page')
        );

        add_action('load-' . $this->hook, array($this, 'handle_tab_actions'));
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
    }

    /**
     * Load the stylesheet only where it is used.
     */
    public function enqueue_assets($hook_suffix)
    {
        if ($hook_suffix !== $this->hook && 'index.php' !== $hook_suffix) {
            return;
        }

        wp_enqueue_style(
            'msgraph-mailer-admin',
            MS_GRAPH_MAILER_URL . 'assets/admin.css',
            array('dashicons'),
            MS_GRAPH_MAILER_VERSION
        );
    }

    public function add_dashboard_widget()
    {
        if (current_user_can('manage_options')) {
            wp_add_dashboard_widget(
                'msgraph_mailer_stats_widget',
                __('MS Graph Mailer', 'ms-graph-mailer'),
                array($this, 'render_dashboard_widget')
            );
        }
    }

    /* ---------------------------------------------------------------------
     * Settings registration
     * ------------------------------------------------------------------ */

    public function register_settings()
    {
        register_setting($this->option_group, $this->option_name, array(
            'sanitize_callback' => array($this, 'sanitize_settings'),
        ));

        add_settings_section(
            'msgraph_connection_section',
            __('Microsoft 365 Connection', 'ms-graph-mailer'),
            array($this, 'connection_section_callback'),
            self::PAGE_SLUG
        );

        $fields = array(
            'client_id' => array(
                'label'    => __('Application (Client) ID', 'ms-graph-mailer'),
                'callback' => 'text_field_callback',
                'help'     => __('From the Overview page of your Azure app registration.', 'ms-graph-mailer'),
            ),
            'client_secret' => array(
                'label'    => __('Client Secret', 'ms-graph-mailer'),
                'callback' => 'password_field_callback',
                'help'     => __('The secret Value, not the Secret ID.', 'ms-graph-mailer'),
            ),
            'tenant_id' => array(
                'label'    => __('Directory (Tenant) ID', 'ms-graph-mailer'),
                'callback' => 'text_field_callback',
            ),
            'from_email' => array(
                'label'    => __('From Email Address', 'ms-graph-mailer'),
                'callback' => 'text_field_callback',
                'help'     => __('Must be a real, licensed mailbox in your tenant.', 'ms-graph-mailer'),
            ),
            'from_name' => array(
                'label'    => __('From Name', 'ms-graph-mailer'),
                'callback' => 'text_field_callback',
                'help'     => __('Optional display name shown to recipients. Leave empty to use the mailbox default.', 'ms-graph-mailer'),
            ),
        );

        foreach ($fields as $key => $field) {
            add_settings_field(
                $key,
                $field['label'],
                array($this, $field['callback']),
                self::PAGE_SLUG,
                'msgraph_connection_section',
                array(
                    'field'     => $key,
                    'help'      => isset($field['help']) ? $field['help'] : '',
                    'label_for' => $key,
                )
            );
        }

        add_settings_section(
            'msgraph_logging_section',
            __('Logging &amp; Privacy', 'ms-graph-mailer'),
            '__return_false',
            self::PAGE_SLUG
        );

        add_settings_field(
            'log_retention',
            __('Log Retention', 'ms-graph-mailer'),
            array($this, 'number_field_callback'),
            self::PAGE_SLUG,
            'msgraph_logging_section',
            array(
                'field'     => 'log_retention',
                'default'   => 30,
                'label_for' => 'log_retention',
                'help'      => __('Logs older than this are pruned daily. Set to 0 to keep everything.', 'ms-graph-mailer'),
            )
        );

        add_settings_field(
            'store_body',
            __('Store Email Content', 'ms-graph-mailer'),
            array($this, 'checkbox_field_callback'),
            self::PAGE_SLUG,
            'msgraph_logging_section',
            array(
                'field'   => 'store_body',
                'default' => 1,
                'label'   => __('Keep a copy of each message body in the log.', 'ms-graph-mailer'),
                'help'    => __('Bodies can contain password-reset links and personal data. Turning this off improves privacy, but Resend will no longer be able to reproduce the message.', 'ms-graph-mailer'),
            )
        );

        add_settings_field(
            'enable_view_log',
            __('View Email Content', 'ms-graph-mailer'),
            array($this, 'checkbox_field_callback'),
            self::PAGE_SLUG,
            'msgraph_logging_section',
            array(
                'field' => 'enable_view_log',
                'label' => __('Allow administrators to read stored message bodies from the log.', 'ms-graph-mailer'),
            )
        );

        add_settings_field(
            'delete_on_uninstall',
            __('Delete Data on Uninstall', 'ms-graph-mailer'),
            array($this, 'checkbox_field_callback'),
            self::PAGE_SLUG,
            'msgraph_logging_section',
            array(
                'field' => 'delete_on_uninstall',
                'label' => __('Remove all logs and settings when the plugin is deleted.', 'ms-graph-mailer'),
            )
        );
    }

    public function sanitize_settings($input)
    {
        $existing  = get_option($this->option_name);
        $existing  = is_array($existing) ? $existing : array();
        $new_input = array();

        if (isset($input['client_id'])) $new_input['client_id'] = sanitize_text_field($input['client_id']);
        if (isset($input['tenant_id'])) $new_input['tenant_id'] = sanitize_text_field($input['tenant_id']);
        if (isset($input['from_name'])) $new_input['from_name'] = sanitize_text_field($input['from_name']);

        if (isset($input['from_email'])) {
            $from_email = sanitize_email($input['from_email']);
            if ('' !== trim($input['from_email']) && ! is_email($from_email)) {
                add_settings_error(
                    $this->option_name,
                    'invalid_from_email',
                    __('The From Email Address is not a valid email address; the previous value was kept.', 'ms-graph-mailer'),
                    'error'
                );
                $from_email = isset($existing['from_email']) ? $existing['from_email'] : '';
            }
            $new_input['from_email'] = $from_email;
        }

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

    public function connection_section_callback()
    {
        echo '<p class="description">' . esc_html__('Credentials from your Azure app registration, using application (app-only) permissions.', 'ms-graph-mailer') . '</p>';
    }

    /* ---------------------------------------------------------------------
     * Field renderers
     * ------------------------------------------------------------------ */

    public function text_field_callback($args)
    {
        if (MSGraph_Settings::is_constant($args['field'])) {
            $this->render_constant_notice($args['field']);
            return;
        }

        $options = get_option($this->option_name);
        $val = isset($options[$args['field']]) ? $options[$args['field']] : '';

        printf(
            '<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" spellcheck="false" />',
            esc_attr($args['field']),
            esc_attr($this->option_name . '[' . $args['field'] . ']'),
            esc_attr($val)
        );

        $this->render_help($args);
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

        printf(
            '<input type="password" id="%1$s" name="%2$s" value="%3$s" autocomplete="new-password" class="regular-text" />',
            esc_attr($field),
            esc_attr($this->option_name . '[' . $field . ']'),
            $has_value ? esc_attr(self::SECRET_PLACEHOLDER) : ''
        );

        if ($has_value) {
            echo '<p class="description">' . esc_html__('A secret is saved. Leave this field untouched to keep it, or paste a new secret to replace it.', 'ms-graph-mailer') . '</p>';
        } else {
            $this->render_help($args);
        }
    }

    public function number_field_callback($args)
    {
        $options = get_option($this->option_name);
        $default = isset($args['default']) ? $args['default'] : '';
        $val = isset($options[$args['field']]) ? $options[$args['field']] : $default;

        printf(
            '<input type="number" min="0" step="1" id="%1$s" name="%2$s" value="%3$s" class="small-text" /> %4$s',
            esc_attr($args['field']),
            esc_attr($this->option_name . '[' . $args['field'] . ']'),
            esc_attr($val),
            esc_html__('days', 'ms-graph-mailer')
        );

        $this->render_help($args);
    }

    public function checkbox_field_callback($args)
    {
        $options = get_option($this->option_name);
        $default = isset($args['default']) ? (int) $args['default'] : 0;
        $val = isset($options[$args['field']]) ? (int) $options[$args['field']] : $default;

        printf(
            '<label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s" value="1" %3$s /> %4$s</label>',
            esc_attr($args['field']),
            esc_attr($this->option_name . '[' . $args['field'] . ']'),
            checked(1, $val, false),
            esc_html(isset($args['label']) ? $args['label'] : '')
        );

        $this->render_help($args);
    }

    private function render_help($args)
    {
        if (! empty($args['help'])) {
            echo '<p class="description">' . esc_html($args['help']) . '</p>';
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

    /* ---------------------------------------------------------------------
     * Page rendering
     * ------------------------------------------------------------------ */

    public function display_plugin_setup_page()
    {
        // Notices queued before a redirect.
        $notices = get_transient('msgraph_admin_notices');
        if (is_array($notices)) {
            foreach ($notices as $notice) {
                add_settings_error($this->option_name, $notice['code'], $notice['message'], $notice['type']);
            }
            delete_transient('msgraph_admin_notices');
        }

        $active_tab = (isset($_GET['tab']) && 'logs' === $_GET['tab']) ? 'logs' : 'settings';
        ?>
        <div class="wrap msgm-wrap">
            <h1><?php esc_html_e('MS Graph Mailer', 'ms-graph-mailer'); ?></h1>

            <?php settings_errors($this->option_name); ?>

            <nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e('Secondary menu', 'ms-graph-mailer'); ?>">
                <a href="<?php echo esc_url($this->tab_url('settings')); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'ms-graph-mailer'); ?>
                </a>
                <a href="<?php echo esc_url($this->tab_url('logs')); ?>" class="nav-tab <?php echo 'logs' === $active_tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Email Logs', 'ms-graph-mailer'); ?>
                </a>
            </nav>

            <?php
            if ('logs' === $active_tab) {
                $this->render_logs_tab();
            } else {
                $this->render_settings_tab();
            }
            ?>
        </div>
        <?php
    }

    private function tab_url($tab)
    {
        return admin_url('options-general.php?page=' . self::PAGE_SLUG . '&tab=' . $tab);
    }

    private function render_settings_tab()
    {
        $this->render_status_card();
        ?>
        <div class="msgm-columns">
            <div>
                <div class="msgm-panel">
                    <form action="options.php" method="post">
                        <?php
                        settings_fields($this->option_group);
                        do_settings_sections(self::PAGE_SLUG);
                        submit_button(__('Save Settings', 'ms-graph-mailer'));
                        ?>
                    </form>
                </div>

                <div class="msgm-panel">
                    <h2><?php esc_html_e('Send a Test Email', 'ms-graph-mailer'); ?></h2>
                    <form method="post" action="<?php echo esc_url($this->tab_url('settings')); ?>">
                        <?php wp_nonce_field('msgraph_test_email', 'msgraph_test_nonce'); ?>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="test_email_to"><?php esc_html_e('Send to', 'ms-graph-mailer'); ?></label></th>
                                <td>
                                    <input type="email" id="test_email_to" name="test_email_to" class="regular-text" required
                                        value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" />
                                </td>
                            </tr>
                        </table>
                        <?php submit_button(__('Send Test Email', 'ms-graph-mailer'), 'secondary', 'send_test_email', false); ?>
                    </form>
                </div>

                <div class="msgm-panel msgm-danger-zone">
                    <h2><?php esc_html_e('Delete All Logs', 'ms-graph-mailer'); ?></h2>
                    <p class="description"><?php esc_html_e('Permanently removes every log entry. This cannot be undone.', 'ms-graph-mailer'); ?></p>
                    <p>
                        <a href="<?php echo esc_url(wp_nonce_url(add_query_arg('action', 'clear_logs', $this->tab_url('logs')), 'msgraph_clear_logs')); ?>"
                            class="button button-link-delete"
                            onclick="return confirm('<?php echo esc_js(__('Delete all email logs? This cannot be undone.', 'ms-graph-mailer')); ?>');">
                            <?php esc_html_e('Clear All Logs', 'ms-graph-mailer'); ?>
                        </a>
                    </p>
                </div>
            </div>

            <div>
                <div class="msgm-panel">
                    <h2><?php esc_html_e('Setup Checklist', 'ms-graph-mailer'); ?></h2>
                    <ol class="msgm-checklist">
                        <li><?php esc_html_e('Register an application in Microsoft Entra ID.', 'ms-graph-mailer'); ?></li>
                        <li>
                            <?php
                            printf(
                                /* translators: %s: Microsoft Graph permission name. */
                                esc_html__('Add the %s application permission, then grant admin consent.', 'ms-graph-mailer'),
                                '<code>Mail.Send</code>'
                            );
                            ?>
                        </li>
                        <li><?php esc_html_e('Create a client secret and copy its Value immediately.', 'ms-graph-mailer'); ?></li>
                        <li><?php esc_html_e('Paste the Client ID, Secret and Tenant ID into this page.', 'ms-graph-mailer'); ?></li>
                        <li><?php esc_html_e('Send a test email to confirm the connection.', 'ms-graph-mailer'); ?></li>
                    </ol>
                </div>

                <div class="msgm-panel">
                    <h2><?php esc_html_e('Keeping Credentials Out of the Database', 'ms-graph-mailer'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Define any of these in wp-config.php and this plugin will use them instead of the values stored in the database:', 'ms-graph-mailer'); ?>
                    </p>
                    <p><code>MSGRAPH_CLIENT_ID</code><br>
                       <code>MSGRAPH_CLIENT_SECRET</code><br>
                       <code>MSGRAPH_TENANT_ID</code><br>
                       <code>MSGRAPH_FROM_EMAIL</code></p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Connection status, with the failure reason when there is one.
     */
    private function render_status_card()
    {
        if (! MSGraph_Settings::get('client_id') || ! MSGraph_Settings::get('client_secret') || ! MSGraph_Settings::get('tenant_id')) {
            $this->status_card(
                'warning',
                'dashicons-info-outline',
                __('Not configured yet', 'ms-graph-mailer'),
                __('Enter your Client ID, Client Secret and Tenant ID below to connect.', 'ms-graph-mailer')
            );
            return;
        }

        $auth = new MSGraph_Auth();

        if ($auth->get_access_token()) {
            $detail = MSGraph_Settings::get('from_email')
                ? sprintf(
                    /* translators: %s: sender email address. */
                    __('Sending as %s using the client credentials flow.', 'ms-graph-mailer'),
                    '<code>' . esc_html(MSGraph_Settings::get('from_email')) . '</code>'
                )
                : __('Add a From Email Address to start sending.', 'ms-graph-mailer');

            $this->status_card('ok', 'dashicons-yes-alt', __('Connected', 'ms-graph-mailer'), $detail, true);
            return;
        }

        $last_err = get_transient('msgraph_last_auth_error');
        $this->status_card(
            'error',
            'dashicons-warning',
            __('Connection failed', 'ms-graph-mailer'),
            $last_err ? '<code>' . esc_html($last_err) . '</code>' : __('Microsoft rejected the credentials. Check the Client ID, Secret and Tenant ID.', 'ms-graph-mailer'),
            true
        );
    }

    /**
     * @param string $detail    Detail text. Pre-escaped when $detail_is_html.
     * @param bool   $detail_is_html Whether $detail already contains safe markup.
     */
    private function status_card($state, $icon, $title, $detail, $detail_is_html = false)
    {
        ?>
        <div class="msgm-status msgm-status--<?php echo esc_attr($state); ?>">
            <span class="dashicons <?php echo esc_attr($icon); ?>" aria-hidden="true"></span>
            <div class="msgm-status-body">
                <p class="msgm-status-title"><?php echo esc_html($title); ?></p>
                <p class="msgm-status-detail"><?php echo $detail_is_html ? wp_kses($detail, array('code' => array())) : esc_html($detail); ?></p>
            </div>
        </div>
        <?php
    }

    private function render_logs_tab()
    {
        $this->render_stat_cards(MSGraph_Logger::get_all_stats());
        $this->render_log_viewer();
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('options-general.php')); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>" />
            <input type="hidden" name="tab" value="logs" />
            <?php
            if (! empty($_GET['status'])) {
                printf('<input type="hidden" name="status" value="%s" />', esc_attr(sanitize_key(wp_unslash($_GET['status']))));
            }
            $log_table = new MSGraph_Log_List_Table();
            $log_table->prepare_items();
            $log_table->views();
            $log_table->search_box(__('Search Logs', 'ms-graph-mailer'), 'msgm-log-search');
            ?>
        </form>

        <form method="post" action="<?php echo esc_url($this->tab_url('logs')); ?>">
            <?php
            wp_nonce_field('msgraph_bulk_logs', 'msgraph_bulk_nonce', false);
            $log_table->display();
            ?>
        </form>
        <?php
    }

    /**
     * The message viewer, shown when a row's "View Content" link is followed.
     */
    private function render_log_viewer()
    {
        if (empty($_GET['view_log_id'])) {
            return;
        }

        $options = get_option($this->option_name);
        if (empty($options['enable_view_log'])) {
            return;
        }

        $log = MSGraph_Logger::get_log((int) $_GET['view_log_id']);
        if (! $log) {
            return;
        }
        ?>
        <div class="msgm-log-viewer">
            <h2>
                <?php
                printf(
                    /* translators: %d: log entry ID. */
                    esc_html__('Message content (log #%d)', 'ms-graph-mailer'),
                    (int) $log->id
                );
                ?>
            </h2>
            <div class="msgm-log-meta">
                <div><strong><?php esc_html_e('To:', 'ms-graph-mailer'); ?></strong> <?php echo esc_html($log->recipient); ?></div>
                <div><strong><?php esc_html_e('Subject:', 'ms-graph-mailer'); ?></strong> <?php echo esc_html($log->subject); ?></div>
                <div><strong><?php esc_html_e('Sent:', 'ms-graph-mailer'); ?></strong> <?php echo esc_html(MSGraph_Logger::format_date($log->created_at)); ?></div>
            </div>

            <?php if ('' === (string) $log->body) : ?>
                <p class="description"><?php esc_html_e('No content was stored for this message.', 'ms-graph-mailer'); ?></p>
            <?php else : ?>
                <div class="msgm-log-body"><?php echo wp_kses_post(wpautop($log->body)); ?></div>
            <?php endif; ?>

            <p><a href="<?php echo esc_url($this->tab_url('logs')); ?>" class="button"><?php esc_html_e('Close', 'ms-graph-mailer'); ?></a></p>
        </div>
        <?php
    }

    private function render_stat_cards($stats)
    {
        $cards = array(
            'today' => __('Today', 'ms-graph-mailer'),
            'week'  => __('Last 7 Days', 'ms-graph-mailer'),
            'all'   => __('All Time', 'ms-graph-mailer'),
        );
        ?>
        <div class="msgm-stats">
            <?php foreach ($cards as $key => $label) :
                $sent   = $stats[$key]['success'];
                $failed = $stats[$key]['failed'];
                ?>
                <div class="msgm-stat">
                    <p class="msgm-stat-label"><?php echo esc_html($label); ?></p>
                    <p class="msgm-stat-value"><?php echo esc_html(number_format_i18n($sent)); ?></p>
                    <p class="msgm-stat-meta">
                        <?php if ($failed > 0) : ?>
                            <span class="msgm-failed"><?php
                                printf(
                                    /* translators: %s: number of failed messages. */
                                    esc_html(_n('%s failed', '%s failed', $failed, 'ms-graph-mailer')),
                                    esc_html(number_format_i18n($failed))
                                );
                            ?></span>
                        <?php elseif ($sent > 0) : ?>
                            <span class="msgm-ok"><?php esc_html_e('No failures', 'ms-graph-mailer'); ?></span>
                        <?php else : ?>
                            <?php esc_html_e('No activity', 'ms-graph-mailer'); ?>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    public function render_dashboard_widget()
    {
        $stats = MSGraph_Logger::get_all_stats();
        $this->render_stat_cards($stats);

        $failed = $stats['week']['failed'];
        ?>
        <p class="msgm-widget-footer">
            <span>
                <?php if ($failed > 0) : ?>
                    <span class="msgm-pill msgm-pill--failed"><?php
                        printf(
                            /* translators: %s: number of failed messages. */
                            esc_html(_n('%s failure this week', '%s failures this week', $failed, 'ms-graph-mailer')),
                            esc_html(number_format_i18n($failed))
                        );
                    ?></span>
                <?php else : ?>
                    <span class="msgm-pill msgm-pill--success"><?php esc_html_e('All mail delivered', 'ms-graph-mailer'); ?></span>
                <?php endif; ?>
            </span>
            <a href="<?php echo esc_url($this->tab_url('logs')); ?>" class="button button-secondary"><?php esc_html_e('View Logs', 'ms-graph-mailer'); ?></a>
        </p>
        <?php
    }

    /* ---------------------------------------------------------------------
     * Actions
     * ------------------------------------------------------------------ */

    public function handle_tab_actions()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['send_test_email'])) {
            check_admin_referer('msgraph_test_email', 'msgraph_test_nonce');
            $this->handle_test_email();
            wp_safe_redirect($this->tab_url('settings'));
            exit;
        }

        if ($this->handle_bulk_delete()) {
            wp_safe_redirect($this->tab_url('logs'));
            exit;
        }

        if (isset($_GET['action']) && 'resend' === $_GET['action'] && isset($_GET['log_id'])) {
            $log_id = intval($_GET['log_id']);
            check_admin_referer('msgraph_resend_log_' . $log_id);
            $this->resend_email($log_id);
            wp_safe_redirect($this->tab_url('logs'));
            exit;
        }

        if (isset($_GET['action']) && 'clear_logs' === $_GET['action']) {
            check_admin_referer('msgraph_clear_logs');
            self::clear_all_logs();
            $this->queue_notice('logs_cleared', __('All email logs were deleted.', 'ms-graph-mailer'), 'success');
            wp_safe_redirect($this->tab_url('logs'));
            exit;
        }
    }

    /**
     * Delete the rows selected with the list table's bulk action.
     *
     * @return bool Whether a bulk action was handled.
     */
    private function handle_bulk_delete()
    {
        $action = '';
        foreach (array('action', 'action2') as $key) {
            if (! empty($_POST[$key]) && '-1' !== $_POST[$key]) {
                $action = sanitize_key(wp_unslash($_POST[$key]));
                break;
            }
        }

        if ('delete' !== $action) {
            return false;
        }

        check_admin_referer('msgraph_bulk_logs', 'msgraph_bulk_nonce');

        $ids = isset($_POST['log_ids']) ? (array) wp_unslash($_POST['log_ids']) : array();
        $deleted = MSGraph_Logger::delete_logs($ids);

        $this->queue_notice(
            'logs_deleted',
            sprintf(
                /* translators: %s: number of deleted log entries. */
                esc_html(_n('%s log entry deleted.', '%s log entries deleted.', $deleted, 'ms-graph-mailer')),
                number_format_i18n($deleted)
            ),
            'success'
        );

        return true;
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

        if ('' === (string) $log->body && ! (int) MSGraph_Settings::get('store_body', 1)) {
            $this->queue_notice(
                'resend_no_body',
                __('This message was logged without its content, so it cannot be resent. Enable "Store Email Content" to allow resending.', 'ms-graph-mailer'),
                'warning'
            );
            return;
        }

        $headers     = MSGraph_Logger::decode_column($log->headers);
        $attachments = MSGraph_Logger::decode_column($log->attachments);

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
}
