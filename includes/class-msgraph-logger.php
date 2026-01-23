<?php
if (! defined('ABSPATH')) {
    exit;
}

class MSGraph_Logger
{

    /**
     * Create the logs table.
     */
    public static function create_table()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'msgraph_email_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
			id bigint(20) NOT NULL AUTO_INCREMENT,
			recipient text NOT NULL,
			subject text NOT NULL,
			status varchar(20) NOT NULL,
			error_message text DEFAULT '',
			body longtext DEFAULT '',
			headers longtext DEFAULT '',
			attachments longtext DEFAULT '',
			retry_count int(11) DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at)
		) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Log an email entry.
     */
    public static function log($to, $subject, $status, $error_message = '', $body = '', $headers = array(), $attachments = array())
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'msgraph_email_logs';

        $wpdb->insert(
            $table_name,
            array(
                'recipient'     => is_array($to) ? implode(', ', $to) : $to,
                'subject'       => $subject,
                'status'        => $status,
                'error_message' => $error_message,
                'body'          => $body,
                'headers'       => maybe_serialize($headers),
                'attachments'   => maybe_serialize($attachments),
                'created_at'    => current_time('mysql'),
            )
        );

        return $wpdb->insert_id;
    }

    /**
     * Get a single log entry.
     */
    public static function get_log($id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'msgraph_email_logs';
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
    }

    /**
     * Update log status.
     */
    public static function update_log_status($id, $status)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'msgraph_email_logs';
        $wpdb->update(
            $table_name,
            array('status' => $status),
            array('id' => $id)
        );
    }

    /**
     * Update log with result.
     */
    public static function update_log_with_result($id, $status, $error_message = '')
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'msgraph_email_logs';

        // Get current retry count
        $current_retry = $wpdb->get_var($wpdb->prepare("SELECT retry_count FROM $table_name WHERE id = %d", $id));

        $data = array(
            'status'        => $status,
            'error_message' => $error_message,
            'retry_count'   => intval($current_retry) + 1,
            'created_at'    => current_time('mysql'),
        );

        $wpdb->update($table_name, $data, array('id' => $id));
    }

    /**
     * Prune old logs.
     */
    public static function prune_logs()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'msgraph_email_logs';
        $options = get_option('msgraph_mailer_settings');
        $days = isset($options['log_retention']) ? intval($options['log_retention']) : 30;

        if ($days <= 0) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    /**
     * Get email statistics for a given timeframe.
     *
     * @param int|null $days Number of days to look back, null for all time.
     * @return array Array with 'success' and 'failed' counts.
     */
    public static function get_stats($days = null)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'msgraph_email_logs';

        $where = "1=1";
        if ($days !== null) {
            $days = intval($days);
            $where = $wpdb->prepare("created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)", $days);
        }

        $success_query = "SELECT COUNT(*) FROM $table_name WHERE $where AND status IN ('success', 'retried_success')";
        $failed_query  = "SELECT COUNT(*) FROM $table_name WHERE $where AND status IN ('failed', 'retried_failed')";

        return array(
            'success' => intval($wpdb->get_var($success_query)),
            'failed'  => intval($wpdb->get_var($failed_query)),
        );
    }
}
