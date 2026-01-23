<?php
if (! class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class MSGraph_Log_List_Table extends WP_List_Table
{

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
        echo 'No email logs found.';
    }

    public function get_columns()
    {
        $columns = array(
            'created_at'    => 'Date',
            'recipient'     => 'Recipient',
            'subject'       => 'Subject',
            'status'        => 'Status',
            'retry_count'   => 'Retries',
        );
        return $columns;
    }

    public function get_bulk_actions()
    {
        return array();
    }

    protected function get_sortable_columns()
    {
        return array(
            'created_at' => array('created_at', true),
            'status'     => array('status', false),
        );
    }

    public function column_default($item, $column_name)
    {
        $val = isset($item[$column_name]) ? $item[$column_name] : '';

        switch ($column_name) {
            case 'created_at':
            case 'recipient':
            case 'subject':
            case 'retry_count':
                return esc_html($val);
            case 'status':
                $class = 'notice notice-info inline';
                if ($val === 'success' || $val === 'retried_success') {
                    $class = 'notice notice-success inline';
                } elseif ($val === 'failed' || $val === 'retried_failed') {
                    $class = 'notice notice-error inline';
                }
                $status_text = ucfirst(str_replace('_', ' ', (string)$val));
                $output = '<div class="' . $class . '" style="padding: 2px 5px; margin: 0;"><p>' . esc_html($status_text) . '</p></div>';

                if (! empty($item['error_message'])) {
                    $output .= '<p class="description"><small>' . esc_html($item['error_message']) . '</small></p>';
                }
                return $output;
            default:
                return esc_html((string)$val);
        }
    }


    public function column_recipient($item)
    {
        $recipient = isset($item['recipient']) ? $item['recipient'] : '';
        $actions = array();

        // Resend Action
        $resend_url = wp_nonce_url(
            add_query_arg(array('action' => 'resend', 'log_id' => $item['id'])),
            'msgraph_resend_log_' . $item['id']
        );
        $actions['resend'] = '<a href="' . esc_url($resend_url) . '">Resend</a>';

        // View Action (Conditional)
        $options = get_option('msgraph_mailer_settings');
        if (! empty($options['enable_view_log'])) {
            $view_url = add_query_arg(array('view_log_id' => $item['id']), admin_url('options-general.php?page=ms-graph-mailer&tab=logs'));
            $actions['view'] = '<a href="' . esc_url($view_url) . '">View Content</a>';
        }

        return sprintf('%1$s %2$s', esc_html((string)$recipient), $this->row_actions($actions));
    }

    public function prepare_items()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'msgraph_email_logs';

        $per_page = 20;
        $current_page = $this->get_pagenum();

        $orderby = (! empty($_GET['orderby'])) ? sanitize_text_field($_GET['orderby']) : 'created_at';
        $order   = (! empty($_GET['order'])) ? sanitize_text_field($_GET['order']) : 'DESC';

        // Safety check for orderby columns
        $allowed_sort = array('created_at', 'status', 'recipient', 'subject');
        if (! in_array($orderby, $allowed_sort)) {
            $orderby = 'created_at';
        }

        $total_items = $wpdb->get_var("SELECT COUNT(id) FROM $table_name");

        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, recipient, subject, status, error_message, retry_count, created_at FROM $table_name ORDER BY $orderby $order LIMIT %d OFFSET %d",
                $per_page,
                ($current_page - 1) * $per_page
            ),
            ARRAY_A
        );

        // Force column headers
        $this->_column_headers = array($this->get_columns(), array(), $this->get_sortable_columns());

        $this->set_pagination_args(array(
            'total_items' => intval($total_items),
            'per_page'    => $per_page,
            'total_pages' => ceil($total_items / $per_page),
        ));
    }
}
