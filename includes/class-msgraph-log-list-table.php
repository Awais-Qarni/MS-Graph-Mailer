<?php
if (! defined('ABSPATH')) {
    exit;
}

if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class MSGraph_Log_List_Table extends WP_List_Table
{

    const PER_PAGE = 20;

    public function __construct()
    {
        parent::__construct(array(
            'singular' => 'email_log',
            'plural'   => 'email_logs',
            'ajax'     => false,
        ));
    }

    public function no_items()
    {
        esc_html_e('No email logs found.', 'ms-graph-mailer');
    }

    public function get_columns()
    {
        return array(
            'cb'          => '<input type="checkbox" />',
            'created_at'  => __('Date', 'ms-graph-mailer'),
            'recipient'   => __('Recipient', 'ms-graph-mailer'),
            'subject'     => __('Subject', 'ms-graph-mailer'),
            'status'      => __('Status', 'ms-graph-mailer'),
            'retry_count' => __('Retries', 'ms-graph-mailer'),
        );
    }

    public function get_bulk_actions()
    {
        return array(
            'delete' => __('Delete', 'ms-graph-mailer'),
        );
    }

    protected function get_sortable_columns()
    {
        return array(
            'created_at'  => array('created_at', true),
            'recipient'   => array('recipient', false),
            'subject'     => array('subject', false),
            'status'      => array('status', false),
            'retry_count' => array('retry_count', false),
        );
    }

    protected function get_default_primary_column_name()
    {
        return 'recipient';
    }

    /**
     * Status filter links above the table.
     */
    protected function get_views()
    {
        $counts  = MSGraph_Logger::get_status_counts();
        $current = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'all';
        $base    = admin_url('options-general.php?page=ms-graph-mailer&tab=logs');

        $views = array(
            'all'     => __('All', 'ms-graph-mailer'),
            'success' => __('Sent', 'ms-graph-mailer'),
            'failed'  => __('Failed', 'ms-graph-mailer'),
        );

        $output = array();
        foreach ($views as $key => $label) {
            $url = 'all' === $key ? $base : add_query_arg('status', $key, $base);

            $output[$key] = sprintf(
                '<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
                esc_url($url),
                $current === $key ? ' class="current" aria-current="page"' : '',
                esc_html($label),
                esc_html(number_format_i18n($counts[$key]))
            );
        }

        return $output;
    }

    public function column_cb($item)
    {
        return sprintf('<input type="checkbox" name="log_ids[]" value="%d" />', (int) $item['id']);
    }

    public function column_created_at($item)
    {
        $timestamp = MSGraph_Logger::to_timestamp($item['created_at']);
        if (! $timestamp) {
            return esc_html((string) $item['created_at']);
        }

        $output = sprintf(
            '<span title="%1$s">%2$s</span>',
            esc_attr(wp_date('c', $timestamp)),
            esc_html(MSGraph_Logger::format_date($item['created_at']))
        );

        if (! empty($item['last_retry_at'])) {
            $retry_timestamp = MSGraph_Logger::to_timestamp($item['last_retry_at']);
            if ($retry_timestamp) {
                $output .= '<span class="msgm-retry-note">' . sprintf(
                    /* translators: %s: human readable time difference, e.g. "2 hours". */
                    esc_html__('Retried %s ago', 'ms-graph-mailer'),
                    esc_html(human_time_diff($retry_timestamp))
                ) . '</span>';
            }
        }

        return $output;
    }

    public function column_status($item)
    {
        $status = (string) $item['status'];

        $modifier = 'neutral';
        if (in_array($status, array('success', 'retried_success'), true)) {
            $modifier = 'success';
        } elseif (in_array($status, array('failed', 'retried_failed'), true)) {
            $modifier = 'failed';
        }

        $labels = array(
            'success'         => __('Sent', 'ms-graph-mailer'),
            'retried_success' => __('Sent on retry', 'ms-graph-mailer'),
            'failed'          => __('Failed', 'ms-graph-mailer'),
            'retried_failed'  => __('Failed on retry', 'ms-graph-mailer'),
        );
        $label = isset($labels[$status]) ? $labels[$status] : ucfirst(str_replace('_', ' ', $status));

        $output = sprintf(
            '<span class="msgm-pill msgm-pill--%1$s">%2$s</span>',
            esc_attr($modifier),
            esc_html($label)
        );

        if (! empty($item['error_message'])) {
            $output .= sprintf(
                '<span class="msgm-error-text" title="%1$s">%2$s</span>',
                esc_attr($item['error_message']),
                esc_html(wp_trim_words($item['error_message'], 24, '&hellip;'))
            );
        }

        return $output;
    }

    public function column_recipient($item)
    {
        $actions = array();

        $resend_url = wp_nonce_url(
            add_query_arg(array('action' => 'resend', 'log_id' => (int) $item['id'])),
            'msgraph_resend_log_' . (int) $item['id']
        );
        $actions['resend'] = sprintf('<a href="%s">%s</a>', esc_url($resend_url), esc_html__('Resend', 'ms-graph-mailer'));

        if (MSGraph_Settings::get('enable_view_log')) {
            $view_url = add_query_arg(
                array('view_log_id' => (int) $item['id']),
                admin_url('options-general.php?page=ms-graph-mailer&tab=logs')
            );
            $actions['view'] = sprintf('<a href="%s">%s</a>', esc_url($view_url), esc_html__('View Content', 'ms-graph-mailer'));
        }

        return sprintf(
            '<strong>%1$s</strong>%2$s',
            esc_html((string) $item['recipient']),
            $this->row_actions($actions)
        );
    }

    public function column_default($item, $column_name)
    {
        $val = isset($item[$column_name]) ? $item[$column_name] : '';

        if ('subject' === $column_name && '' === (string) $val) {
            return '<span class="description">' . esc_html__('(no subject)', 'ms-graph-mailer') . '</span>';
        }

        return esc_html((string) $val);
    }

    public function prepare_items()
    {
        global $wpdb;
        $table_name = MSGraph_Logger::table();

        $current_page = $this->get_pagenum();

        // Both fragments are interpolated into SQL, so both must come from a
        // fixed whitelist. sanitize_text_field() does not neutralise SQL.
        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'created_at';
        $allowed_sort = array('created_at', 'status', 'recipient', 'subject', 'retry_count');
        if (! in_array($orderby, $allowed_sort, true)) {
            $orderby = 'created_at';
        }

        $order = 'DESC';
        if (isset($_GET['order']) && 'asc' === strtolower(sanitize_key(wp_unslash($_GET['order'])))) {
            $order = 'ASC';
        }

        // Filters
        $where  = array('1=1');
        $params = array();

        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        if ('success' === $status) {
            $where[] = "status IN ('success', 'retried_success')";
        } elseif ('failed' === $status) {
            $where[] = "status IN ('failed', 'retried_failed')";
        }

        $search = isset($_REQUEST['s']) ? trim(sanitize_text_field(wp_unslash($_REQUEST['s']))) : '';
        if ('' !== $search) {
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $where[]  = '(recipient LIKE %s OR subject LIKE %s)';
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(id) FROM $table_name WHERE $where_sql";
        $total_items = (int) ($params
            ? $wpdb->get_var($wpdb->prepare($count_sql, $params))
            : $wpdb->get_var($count_sql));

        $select_sql = "SELECT id, recipient, subject, status, error_message, retry_count, created_at, last_retry_at
                         FROM $table_name
                        WHERE $where_sql
                     ORDER BY $orderby $order
                        LIMIT %d OFFSET %d";

        $query_params   = $params;
        $query_params[] = self::PER_PAGE;
        $query_params[] = ($current_page - 1) * self::PER_PAGE;

        $this->items = $wpdb->get_results($wpdb->prepare($select_sql, $query_params), ARRAY_A);

        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
            $this->get_default_primary_column_name(),
        );

        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page'    => self::PER_PAGE,
            'total_pages' => (int) ceil($total_items / self::PER_PAGE),
        ));
    }
}
