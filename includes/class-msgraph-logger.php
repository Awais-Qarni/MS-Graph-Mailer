<?php
if (! defined('ABSPATH')) {
    exit;
}

class MSGraph_Logger
{

    /**
     * Bumped whenever the schema changes so upgrades re-run dbDelta.
     */
    const DB_VERSION = 2;
    const DB_VERSION_OPTION = 'msgraph_db_version';

    /**
     * Fully-qualified log table name.
     */
    public static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'msgraph_email_logs';
    }

    /**
     * Create or upgrade the logs table.
     *
     * TEXT/LONGTEXT columns carry no DEFAULT: MySQL below 8.0.13 rejects
     * defaults on those types and dbDelta swallows the error, leaving the
     * column in an unexpected state.
     */
    public static function create_table()
    {
        global $wpdb;
        $table_name = self::table();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			recipient text NOT NULL,
			subject text NOT NULL,
			status varchar(20) NOT NULL,
			error_message text NULL,
			body longtext NULL,
			headers longtext NULL,
			attachments longtext NULL,
			retry_count int(11) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			last_retry_at datetime NULL DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at),
			KEY status_created_at (status,created_at)
		) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Autoloaded deliberately: maybe_upgrade() reads it on every request,
        // so it should come from alloptions rather than its own query.
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, true);
    }

    /**
     * Run pending schema upgrades.
     *
     * create_table() only ran on activation, so installs that were already
     * active never received later schema changes.
     */
    public static function maybe_upgrade()
    {
        if ((int) get_option(self::DB_VERSION_OPTION) === self::DB_VERSION) {
            return;
        }

        self::create_table();

        // Older versions stored the access token in an autoloaded option.
        // Drop it so it is rewritten with autoload disabled.
        delete_option('msgraph_tokens');

        self::flush_stats_cache();
    }

    /**
     * Log an email entry.
     */
    public static function log($to, $subject, $status, $error_message = '', $body = '', $headers = array(), $attachments = array())
    {
        global $wpdb;

        $wpdb->insert(
            self::table(),
            array(
                'recipient'     => is_array($to) ? implode(', ', $to) : (string) $to,
                'subject'       => (string) $subject,
                'status'        => $status,
                'error_message' => (string) $error_message,
                'body'          => (string) $body,
                'headers'       => wp_json_encode($headers),
                'attachments'   => wp_json_encode($attachments),
                'created_at'    => current_time('mysql'),
            ),
            array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s')
        );

        self::flush_stats_cache();

        return $wpdb->insert_id;
    }

    /**
     * Get a single log entry.
     */
    public static function get_log($id)
    {
        global $wpdb;
        $table_name = self::table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", $id));
    }

    /**
     * Update log status.
     */
    public static function update_log_status($id, $status)
    {
        global $wpdb;
        $wpdb->update(
            self::table(),
            array('status' => $status),
            array('id' => $id),
            array('%s'),
            array('%d')
        );
        self::flush_stats_cache();
    }

    /**
     * Update log with result.
     */
    public static function update_log_with_result($id, $status, $error_message = '')
    {
        global $wpdb;
        $table_name = self::table();

        // Increment in SQL so concurrent retries cannot race on a
        // read-then-write, and leave created_at alone: it records when the
        // message was first attempted. The retry time goes to last_retry_at.
        $wpdb->query($wpdb->prepare(
            "UPDATE $table_name
                SET status = %s, error_message = %s, retry_count = retry_count + 1, last_retry_at = %s
              WHERE id = %d",
            $status,
            (string) $error_message,
            current_time('mysql'),
            $id
        ));

        self::flush_stats_cache();
    }

    /**
     * Prune old logs.
     */
    public static function prune_logs()
    {
        global $wpdb;
        $table_name = self::table();
        $days = (int) MSGraph_Settings::get('log_retention', 30);

        if ($days <= 0) {
            return;
        }

        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));

        self::flush_stats_cache();
    }

    const STATS_TRANSIENT = 'msgraph_stats_cache';

    /**
     * Success/failure counts for today, the last 7 days, and all time.
     *
     * Previously this ran two COUNT(*) queries per timeframe on every
     * dashboard load. It is now one pass with conditional aggregation,
     * cached for five minutes and invalidated whenever a log row changes.
     *
     * @return array<string, array{success:int, failed:int}>
     */
    public static function get_all_stats()
    {
        $cached = get_transient(self::STATS_TRANSIENT);
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $table_name = self::table();

        $ok   = "status IN ('success', 'retried_success')";
        $fail = "status IN ('failed', 'retried_failed')";
        $today = "created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)";
        $week  = "created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";

        $row = $wpdb->get_row(
            "SELECT
                SUM($today AND $ok)  AS today_success,
                SUM($today AND $fail) AS today_failed,
                SUM($week AND $ok)   AS week_success,
                SUM($week AND $fail)  AS week_failed,
                SUM($ok)             AS all_success,
                SUM($fail)            AS all_failed
             FROM $table_name",
            ARRAY_A
        );

        $stats = array(
            'today' => array(
                'success' => isset($row['today_success']) ? (int) $row['today_success'] : 0,
                'failed'  => isset($row['today_failed']) ? (int) $row['today_failed'] : 0,
            ),
            'week' => array(
                'success' => isset($row['week_success']) ? (int) $row['week_success'] : 0,
                'failed'  => isset($row['week_failed']) ? (int) $row['week_failed'] : 0,
            ),
            'all' => array(
                'success' => isset($row['all_success']) ? (int) $row['all_success'] : 0,
                'failed'  => isset($row['all_failed']) ? (int) $row['all_failed'] : 0,
            ),
        );

        set_transient(self::STATS_TRANSIENT, $stats, 5 * MINUTE_IN_SECONDS);

        return $stats;
    }

    /**
     * Counts for a single timeframe.
     *
     * @param int|null $days 1, 7, or null for all time.
     * @return array{success:int, failed:int}
     */
    public static function get_stats($days = null)
    {
        $stats = self::get_all_stats();

        if (null === $days) {
            return $stats['all'];
        }

        return (int) $days <= 1 ? $stats['today'] : $stats['week'];
    }

    /**
     * Number of rows per status group, for the log list filters.
     *
     * @return array{all:int, success:int, failed:int}
     */
    public static function get_status_counts()
    {
        global $wpdb;
        $table_name = self::table();

        $row = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(status IN ('success', 'retried_success')) AS ok,
                SUM(status IN ('failed', 'retried_failed')) AS bad
             FROM $table_name",
            ARRAY_A
        );

        return array(
            'all'     => isset($row['total']) ? (int) $row['total'] : 0,
            'success' => isset($row['ok']) ? (int) $row['ok'] : 0,
            'failed'  => isset($row['bad']) ? (int) $row['bad'] : 0,
        );
    }

    /**
     * Delete specific log rows.
     */
    public static function delete_logs(array $ids)
    {
        $ids = array_filter(array_map('intval', $ids));
        if (empty($ids)) {
            return 0;
        }

        global $wpdb;
        $table_name = self::table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        $deleted = $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE id IN ($placeholders)", $ids));

        self::flush_stats_cache();

        return (int) $deleted;
    }

    /**
     * Decode a stored headers/attachments column.
     *
     * Rows written before 2.2 used maybe_serialize(), so fall back to it.
     */
    public static function decode_column($value)
    {
        if (empty($value)) {
            return array();
        }

        $decoded = json_decode($value, true);
        if (JSON_ERROR_NONE === json_last_error()) {
            return is_array($decoded) ? $decoded : array();
        }

        // Legacy rows are plugin-written arrays of strings. Unserialize with
        // classes disallowed so a tampered row cannot instantiate objects.
        if (is_string($value) && 0 === strpos($value, 'a:')) {
            $legacy = @unserialize($value, array('allowed_classes' => false));
            if (is_array($legacy)) {
                return $legacy;
            }
        }

        return array();
    }

    /**
     * Convert a stored datetime into a real UTC timestamp.
     *
     * Rows are written with current_time('mysql'), i.e. site-local time.
     * mysql2date('U', ...) would read that as UTC and wp_date() would then
     * apply the offset a second time, so parse it in the site's timezone.
     *
     * @return int|false
     */
    public static function to_timestamp($mysql_date)
    {
        if (empty($mysql_date) || '0000-00-00 00:00:00' === $mysql_date) {
            return false;
        }

        $datetime = date_create_immutable($mysql_date, wp_timezone());

        return $datetime ? $datetime->getTimestamp() : false;
    }

    /**
     * Format a stored datetime using the site's date and time settings.
     */
    public static function format_date($mysql_date)
    {
        $timestamp = self::to_timestamp($mysql_date);

        if (! $timestamp) {
            return (string) $mysql_date;
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    /**
     * Invalidate the cached statistics.
     */
    public static function flush_stats_cache()
    {
        delete_transient(self::STATS_TRANSIENT);
    }
}
