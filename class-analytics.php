<?php
namespace WPAutoPilot\Admin;

defined( 'ABSPATH' ) || exit;

// ═══════════════════════════════════════════════════════════════════════════
// Log Viewer
// ═══════════════════════════════════════════════════════════════════════════

class LogViewer {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks(): void {
        add_action( 'admin_post_wpap_clear_logs', [ $this, 'handle_clear_logs' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-autopilot' ) );
        }

        global $wpdb;
        $table    = $wpdb->prefix . WPAP_LOG_TABLE;
        $level    = sanitize_key( $_GET['level'] ?? '' );
        $platform = sanitize_key( $_GET['platform'] ?? '' );
        $page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $per_page = 50;
        $offset   = ( $page - 1 ) * $per_page;

        $where_clauses = [];
        $where_values  = [];

        if ( $level ) {
            $where_clauses[] = 'level = %s';
            $where_values[]  = $level;
        }
        if ( $platform ) {
            $where_clauses[] = 'platform = %s';
            $where_values[]  = $platform;
        }

        $where_sql = $where_clauses ? 'WHERE ' . implode( ' AND ', $where_clauses ) : '';

        if ( $where_values ) {
            $query = $wpdb->prepare( // phpcs:ignore
                "SELECT * FROM {$table} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                array_merge( $where_values, [ $per_page, $offset ] )
            );
            $count_query = $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$where_sql}", $where_values ); // phpcs:ignore
        } else {
            $query       = "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT {$per_page} OFFSET {$offset}";
            $count_query = "SELECT COUNT(*) FROM {$table}";
        }

        $logs  = $wpdb->get_results( $query ); // phpcs:ignore
        $total = (int) $wpdb->get_var( $count_query ); // phpcs:ignore

        $platforms = $wpdb->get_col( "SELECT DISTINCT platform FROM {$table} WHERE platform IS NOT NULL ORDER BY platform" ); // phpcs:ignore

        include WPAP_DIR . 'admin/views/logs.php';
    }

    public function handle_clear_logs(): void {
        check_admin_referer( 'wpap_clear_logs' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-autopilot' ) );
        }

        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}" . WPAP_LOG_TABLE ); // phpcs:ignore

        wp_redirect( admin_url( 'admin.php?page=wpap-logs&cleared=1' ) );
        exit;
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// Analytics
// ═══════════════════════════════════════════════════════════════════════════

class Analytics {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks(): void {
        add_action( 'wp_ajax_wpap_analytics_data', [ $this, 'ajax_analytics_data' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-autopilot' ) );
        }

        $data = $this->get_analytics_data();
        include WPAP_DIR . 'admin/views/analytics.php';
    }

    public function ajax_analytics_data(): void {
        check_ajax_referer( 'wpap_admin', 'nonce' );
        wp_send_json_success( $this->get_analytics_data() );
    }

    private function get_analytics_data(): array {
        global $wpdb;
        $queue_table = $wpdb->prefix . WPAP_QUEUE_TABLE;
        $stats_table = $wpdb->prefix . WPAP_STATS_TABLE;

        // Success rate by platform.
        $platform_rates = $wpdb->get_results( // phpcs:ignore
            "SELECT platform,
                COUNT(*) as total,
                SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
                ROUND(SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1) as rate
             FROM {$queue_table}
             GROUP BY platform ORDER BY total DESC",
            ARRAY_A
        );

        // Daily activity (last 30 days).
        $daily = $wpdb->get_results( // phpcs:ignore
            "SELECT DATE(scheduled_at) as date, COUNT(*) as total,
                SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) as success
             FROM {$queue_table}
             WHERE scheduled_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             GROUP BY DATE(scheduled_at) ORDER BY date ASC",
            ARRAY_A
        );

        // Top performing posts.
        $top_posts = $wpdb->get_results( // phpcs:ignore
            "SELECT s.post_id, p.post_title, COUNT(s.id) as platforms, SUM(s.clicks) as clicks
             FROM {$stats_table} s
             LEFT JOIN {$wpdb->posts} p ON p.ID = s.post_id
             GROUP BY s.post_id ORDER BY platforms DESC LIMIT 10",
            ARRAY_A
        );

        // Summary counts.
        $summary = [
            'total_published' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE status='done'" ), // phpcs:ignore
            'total_failed'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE status='failed'" ), // phpcs:ignore
            'total_pending'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE status='pending'" ), // phpcs:ignore
            'total_posts'     => (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$stats_table}" ), // phpcs:ignore
        ];

        return compact( 'platform_rates', 'daily', 'top_posts', 'summary' );
    }
}
