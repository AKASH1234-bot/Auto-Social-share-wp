<?php
namespace WPAutoPilot\Admin;

use WPAutoPilot\{Logger, AIEngine, Services\QuoraHelper};

defined( 'ABSPATH' ) || exit;

/**
 * Admin dashboard controller.
 */
class Dashboard {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks(): void {
        add_action( 'admin_menu',             [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_wpap_get_queue', [ $this, 'ajax_get_queue' ] );
        add_action( 'wp_ajax_wpap_retry_job', [ $this, 'ajax_retry_job' ] );
        add_action( 'wp_ajax_wpap_delete_job', [ $this, 'ajax_delete_job' ] );
        add_action( 'wp_ajax_wpap_quora_generate', [ $this, 'ajax_quora_generate' ] );
        add_action( 'wp_ajax_wpap_generate_hooks', [ $this, 'ajax_generate_hooks' ] );
    }

    public function register_menus(): void {
        add_menu_page(
            __( 'WP AutoPilot', 'wp-autopilot' ),
            __( 'AutoPilot', 'wp-autopilot' ),
            'manage_options',
            'wpap-dashboard',
            [ $this, 'render_dashboard' ],
            'dashicons-share-alt2',
            30
        );

        add_submenu_page( 'wpap-dashboard', __( 'Dashboard', 'wp-autopilot' ), __( 'Dashboard', 'wp-autopilot' ), 'manage_options', 'wpap-dashboard', [ $this, 'render_dashboard' ] );
        add_submenu_page( 'wpap-dashboard', __( 'Queue', 'wp-autopilot' ), __( 'Queue', 'wp-autopilot' ), 'manage_options', 'wpap-queue', [ $this, 'render_queue' ] );
        add_submenu_page( 'wpap-dashboard', __( 'Logs', 'wp-autopilot' ), __( 'Logs', 'wp-autopilot' ), 'manage_options', 'wpap-logs', [ LogViewer::instance(), 'render' ] );
        add_submenu_page( 'wpap-dashboard', __( 'Analytics', 'wp-autopilot' ), __( 'Analytics', 'wp-autopilot' ), 'manage_options', 'wpap-analytics', [ Analytics::instance(), 'render' ] );
        add_submenu_page( 'wpap-dashboard', __( 'Settings', 'wp-autopilot' ), __( 'Settings', 'wp-autopilot' ), 'manage_options', 'wpap-settings', [ Settings::instance(), 'render' ] );
    }

    public function enqueue_assets( string $hook ): void {
        if ( ! str_contains( $hook, 'wpap' ) && ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }

        wp_enqueue_style( 'wpap-admin', WPAP_URL . 'assets/css/admin.css', [], WPAP_VERSION );
        wp_enqueue_script( 'wpap-admin', WPAP_URL . 'assets/js/admin.js', [ 'jquery', 'wp-util' ], WPAP_VERSION, true );

        wp_localize_script( 'wpap-admin', 'wpap', [
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'wpap_admin' ),
            'rest_url'   => rest_url( 'wpap/v1/' ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'       => [
                'copied'       => __( 'Copied!', 'wp-autopilot' ),
                'generating'   => __( 'Generating...', 'wp-autopilot' ),
                'retrying'     => __( 'Retrying...', 'wp-autopilot' ),
                'confirm_del'  => __( 'Delete this job?', 'wp-autopilot' ),
            ],
        ] );
    }

    // ─── Dashboard Page ───────────────────────────────────────────────────────

    public function render_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-autopilot' ) );
        }

        global $wpdb;
        $queue_table = $wpdb->prefix . WPAP_QUEUE_TABLE;
        $log_table   = $wpdb->prefix . WPAP_LOG_TABLE;
        $stats_table = $wpdb->prefix . WPAP_STATS_TABLE;

        $stats = [
            'pending'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE status='pending'" ), // phpcs:ignore
            'done'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE status='done'" ), // phpcs:ignore
            'failed'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table} WHERE status='failed'" ), // phpcs:ignore
            'total_posts'=> (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$stats_table}" ), // phpcs:ignore
        ];

        $platform_stats = $wpdb->get_results( // phpcs:ignore
            "SELECT platform, COUNT(*) as total, SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) as success
             FROM {$queue_table}
             GROUP BY platform
             ORDER BY total DESC"
        );

        $recent_logs = $wpdb->get_results( // phpcs:ignore
            "SELECT * FROM {$log_table} ORDER BY created_at DESC LIMIT 10"
        );

        include WPAP_DIR . 'admin/views/dashboard.php';
    }

    // ─── Queue Page ───────────────────────────────────────────────────────────

    public function render_queue(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-autopilot' ) );
        }
        include WPAP_DIR . 'admin/views/queue.php';
    }

    // ─── AJAX Handlers ────────────────────────────────────────────────────────

    public function ajax_get_queue(): void {
        check_ajax_referer( 'wpap_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        global $wpdb;
        $table  = $wpdb->prefix . WPAP_QUEUE_TABLE;
        $status = sanitize_key( $_POST['status'] ?? '' );
        $page   = max( 1, (int) ( $_POST['page'] ?? 1 ) );
        $limit  = 20;
        $offset = ( $page - 1 ) * $limit;

        $where = $status ? $wpdb->prepare( "WHERE status = %s", $status ) : '';
        $jobs  = $wpdb->get_results( // phpcs:ignore
            "SELECT q.*, p.post_title FROM {$table} q
             LEFT JOIN {$wpdb->posts} p ON p.ID = q.post_id
             {$where}
             ORDER BY scheduled_at DESC LIMIT {$limit} OFFSET {$offset}"
        );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore

        wp_send_json_success( [ 'jobs' => $jobs, 'total' => $total, 'pages' => ceil( $total / $limit ) ] );
    }

    public function ajax_retry_job(): void {
        check_ajax_referer( 'wpap_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $id = absint( $_POST['job_id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( 'Invalid job ID.' );
        }

        global $wpdb;
        $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . WPAP_QUEUE_TABLE,
            [ 'status' => 'pending', 'attempts' => 0, 'error_msg' => null, 'scheduled_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s', '%s' ],
            [ '%d' ]
        );

        wp_send_json_success( [ 'message' => 'Job queued for retry.' ] );
    }

    public function ajax_delete_job(): void {
        check_ajax_referer( 'wpap_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $id = absint( $_POST['job_id'] ?? 0 );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . WPAP_QUEUE_TABLE, [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore

        wp_send_json_success();
    }

    public function ajax_quora_generate(): void {
        check_ajax_referer( 'wpap_admin', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( 'post_id required.' );
        }

        $result = QuoraHelper::generate_answer( $post_id );
        if ( isset( $result['error'] ) ) {
            wp_send_json_error( $result['error'] );
        }
        wp_send_json_success( $result );
    }

    public function ajax_generate_hooks(): void {
        check_ajax_referer( 'wpap_admin', 'nonce' );
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        $post    = get_post( $post_id );
        if ( ! $post ) {
            wp_send_json_error( 'Post not found.' );
        }

        $hooks = AIEngine::instance()->generate_hooks( $post );
        wp_send_json_success( [ 'hooks' => $hooks ] );
    }
}
