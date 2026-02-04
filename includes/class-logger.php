<?php
/**
 * Logger Class
 * Handles logging of import activities
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class PI_Logger {

    /**
     * Log table name
     */
    const TABLE_NAME = 'pi_import_log';

    /**
     * Create log table on plugin activation
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            page_id bigint(20) UNSIGNED NOT NULL,
            file_name varchar(255) NOT NULL,
            status varchar(50) NOT NULL,
            message text,
            user_id bigint(20) UNSIGNED NOT NULL,
            import_date datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY page_id (page_id),
            KEY import_date (import_date)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Log an import
     *
     * @param int $page_id Page ID
     * @param string $file_name Original file name
     * @param string $status Status (success, failed, skipped)
     * @param string $message Optional message
     * @return bool|int Insert ID or false on failure
     */
    public static function log_import($page_id, $file_name, $status = 'success', $message = '') {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $result = $wpdb->insert(
            $table_name,
            array(
                'page_id' => $page_id,
                'file_name' => $file_name,
                'status' => $status,
                'message' => $message,
                'user_id' => get_current_user_id(),
                'import_date' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%d', '%s')
        );

        if ($result === false) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Get import logs
     *
     * @param array $args Query arguments
     * @return array Log entries
     */
    public static function get_logs($args = array()) {
        global $wpdb;

        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'status' => '',
            'orderby' => 'import_date',
            'order' => 'DESC'
        );

        $args = wp_parse_args($args, $defaults);
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $where = '';
        if (!empty($args['status'])) {
            $where = $wpdb->prepare('WHERE status = %s', $args['status']);
        }

        $query = sprintf(
            "SELECT * FROM %s %s ORDER BY %s %s LIMIT %d OFFSET %d",
            $table_name,
            $where,
            esc_sql($args['orderby']),
            esc_sql($args['order']),
            absint($args['limit']),
            absint($args['offset'])
        );

        return $wpdb->get_results($query);
    }

    /**
     * Get import statistics
     *
     * @return array Statistics
     */
    public static function get_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $total = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
        $success = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'success'");
        $failed = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE status = 'failed'");

        $last_import = $wpdb->get_row(
            "SELECT * FROM $table_name ORDER BY import_date DESC LIMIT 1"
        );

        return array(
            'total' => (int) $total,
            'success' => (int) $success,
            'failed' => (int) $failed,
            'last_import' => $last_import
        );
    }

    /**
     * Clear old logs
     *
     * @param int $days Number of days to keep
     * @return int Number of rows deleted
     */
    public static function clear_old_logs($days = 30) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name WHERE import_date < %s",
                $date
            )
        );
    }

    /**
     * Delete all logs
     *
     * @return int Number of rows deleted
     */
    public static function clear_all_logs() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        return $wpdb->query("TRUNCATE TABLE $table_name");
    }
}

// Create table on plugin activation
register_activation_hook(PI_PLUGIN_FILE, array('PI_Logger', 'create_table'));
