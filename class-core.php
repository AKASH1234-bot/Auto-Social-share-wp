<?php
namespace WPAutoPilot;

defined( 'ABSPATH' ) || exit;

/**
 * Core singleton — boots all subsystems.
 */
final class Core {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function boot(): void {
        load_plugin_textdomain( 'wp-autopilot', false, WPAP_DIR . 'languages' );

        // Run DB migrations if needed.
        if ( get_option( 'wpap_db_version' ) !== WPAP_DB_VERSION ) {
            Installer::upgrade_tables();
        }

        // Register hooks.
        PostHandler::instance()->register_hooks();
        Scheduler::instance()->register_hooks();

        if ( is_admin() ) {
            Admin\Dashboard::instance()->register_hooks();
            Admin\Settings::instance()->register_hooks();
            Admin\LogViewer::instance()->register_hooks();
            Admin\Analytics::instance()->register_hooks();
        }

        // REST API for OAuth callbacks & webhook receivers.
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        do_action( 'wpap_loaded' );
    }

    public function register_rest_routes(): void {
        register_rest_route( 'wpap/v1', '/oauth/callback/(?P<platform>[a-z]+)', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'handle_oauth_callback' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'wpap/v1', '/queue/process', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_queue_trigger' ],
            'permission_callback' => fn( $req ) => wp_verify_nonce( $req->get_header('X-WP-Nonce'), 'wp_rest' ),
        ] );

        register_rest_route( 'wpap/v1', '/quora/generate', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_quora_generate' ],
            'permission_callback' => fn() => current_user_can( 'edit_posts' ),
        ] );
    }

    public function handle_oauth_callback( \WP_REST_Request $request ): \WP_REST_Response {
        $platform = sanitize_key( $request->get_param( 'platform' ) );
        $service  = ServiceFactory::make( $platform );

        if ( ! $service ) {
            return new \WP_REST_Response( [ 'error' => 'Unknown platform' ], 400 );
        }

        $result = $service->handle_oauth_callback( $request );
        if ( is_wp_error( $result ) ) {
            wp_redirect( admin_url( 'admin.php?page=wpap-settings&error=' . urlencode( $result->get_error_message() ) ) );
            exit;
        }

        wp_redirect( admin_url( 'admin.php?page=wpap-settings&connected=' . $platform ) );
        exit;
    }

    public function handle_queue_trigger( \WP_REST_Request $request ): \WP_REST_Response {
        $processed = QueueProcessor::instance()->process_batch();
        return new \WP_REST_Response( [ 'processed' => $processed ], 200 );
    }

    public function handle_quora_generate( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = absint( $request->get_param( 'post_id' ) );
        if ( ! $post_id ) {
            return new \WP_REST_Response( [ 'error' => 'post_id required' ], 400 );
        }
        $result = Services\QuoraHelper::generate_answer( $post_id );
        return new \WP_REST_Response( $result, 200 );
    }
}
