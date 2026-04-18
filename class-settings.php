<?php
namespace WPAutoPilot\Admin;

use WPAutoPilot\{EncryptionHelper, ServiceFactory};

defined( 'ABSPATH' ) || exit;

/**
 * Settings page — platform connections, general config, OAuth flows.
 */
class Settings {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks(): void {
        add_action( 'admin_post_wpap_save_settings', [ $this, 'handle_save' ] );
        add_action( 'admin_post_wpap_connect_platform', [ $this, 'handle_connect' ] );
        add_action( 'admin_post_wpap_disconnect_platform', [ $this, 'handle_disconnect' ] );
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );
    }

    public function render(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-autopilot' ) );
        }
        $general   = get_option( 'wpap_general', [] );
        $platforms = get_option( 'wpap_platforms', [] );
        include WPAP_DIR . 'admin/views/settings.php';
    }

    public function handle_save(): void {
        check_admin_referer( 'wpap_save_settings' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-autopilot' ) );
        }

        // ─── General Settings ─────────────────────────────────────────────
        $general = [
            'post_types'         => array_map( 'sanitize_key', (array) ( $_POST['post_types'] ?? [ 'post' ] ) ),
            'trigger_on_publish' => ! empty( $_POST['trigger_on_publish'] ),
            'trigger_on_update'  => ! empty( $_POST['trigger_on_update'] ),
            'global_delay'       => absint( $_POST['global_delay'] ?? 0 ),
            'platform_delay'     => absint( $_POST['platform_delay'] ?? 30 ),
            'max_attempts'       => min( 10, absint( $_POST['max_attempts'] ?? 3 ) ),
            'retry_delay'        => absint( $_POST['retry_delay'] ?? 300 ),
            'url_shortener'      => sanitize_key( $_POST['url_shortener'] ?? 'none' ),
            'bitly_token'        => sanitize_text_field( $_POST['bitly_token'] ?? '' ),
            'yourls_url'         => esc_url_raw( $_POST['yourls_url'] ?? '' ),
            'yourls_token'       => sanitize_text_field( $_POST['yourls_token'] ?? '' ),
            'ai_provider'        => sanitize_key( $_POST['ai_provider'] ?? 'openai' ),
            'openai_api_key'     => sanitize_text_field( $_POST['openai_api_key'] ?? '' ),
            'openai_model'       => sanitize_text_field( $_POST['openai_model'] ?? 'gpt-4o-mini' ),
        ];

        update_option( 'wpap_general', $general );

        // ─── Platform Settings ────────────────────────────────────────────
        $existing_platforms = get_option( 'wpap_platforms', [] );
        $posted_platforms   = (array) ( $_POST['platforms'] ?? [] );

        foreach ( $posted_platforms as $platform => $cfg ) {
            $platform = sanitize_key( $platform );
            $accounts = $cfg['accounts'] ?? [ 'default' => $cfg ];

            foreach ( $accounts as $account_id => $account_cfg ) {
                $account_id  = sanitize_key( $account_id );
                $existing    = $existing_platforms[ $platform ]['accounts'][ $account_id ] ?? [];
                $new_account = [
                    'enabled'         => ! empty( $account_cfg['enabled'] ),
                    'custom_template' => sanitize_textarea_field( $account_cfg['custom_template'] ?? '' ),
                    'attach_image'    => ! empty( $account_cfg['attach_image'] ),
                ];

                // Encrypt sensitive fields, preserve existing if field is blank.
                $sensitive_fields = [
                    'api_key', 'api_secret', 'access_token', 'access_secret',
                    'client_id', 'client_secret', 'bearer_token', 'webhook_url',
                    'refresh_token', 'page_id', 'page_token', 'ig_user_id',
                    'person_urn', 'blog_name', 'publication_id', 'board_id',
                    'instance_url',
                ];

                foreach ( $sensitive_fields as $field ) {
                    if ( isset( $account_cfg[ $field ] ) && $account_cfg[ $field ] !== '' ) {
                        $new_account[ $field ] = EncryptionHelper::encrypt(
                            sanitize_text_field( $account_cfg[ $field ] )
                        );
                    } elseif ( isset( $existing[ $field ] ) ) {
                        $new_account[ $field ] = $existing[ $field ]; // Keep existing encrypted value.
                    }
                }

                // Merge platform-level non-sensitive config.
                $other_fields = [ 'subreddits', 'category_map', 'flair_id', 'flair_text',
                                   'inter_subreddit_delay', 'max_subreddits', 'embed_color',
                                   'visibility', 'char_limit', 'status', 'role_mention' ];
                foreach ( $other_fields as $field ) {
                    if ( isset( $account_cfg[ $field ] ) ) {
                        $new_account[ $field ] = sanitize_text_field( $account_cfg[ $field ] );
                    }
                }

                $existing_platforms[ $platform ]['accounts'][ $account_id ] = array_merge( $existing, $new_account );
            }

            $existing_platforms[ $platform ]['enabled'] = ! empty( $cfg['enabled'] );
        }

        update_option( 'wpap_platforms', $existing_platforms );

        wp_redirect( admin_url( 'admin.php?page=wpap-settings&saved=1' ) );
        exit;
    }

    public function handle_connect(): void {
        check_admin_referer( 'wpap_connect_platform' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-autopilot' ) );
        }

        $platform   = sanitize_key( $_POST['platform'] ?? '' );
        $account_id = sanitize_key( $_POST['account_id'] ?? 'default' );

        $service      = ServiceFactory::make( $platform, $account_id );
        $redirect_uri = rest_url( "wpap/v1/oauth/callback/{$platform}" );
        $auth_url     = $service ? $service->get_auth_url( $redirect_uri ) : '';

        if ( ! $auth_url ) {
            wp_redirect( admin_url( 'admin.php?page=wpap-settings&error=no_auth_url&platform=' . $platform ) );
            exit;
        }

        wp_redirect( $auth_url );
        exit;
    }

    public function handle_disconnect(): void {
        check_admin_referer( 'wpap_disconnect_platform' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-autopilot' ) );
        }

        $platform   = sanitize_key( $_POST['platform'] ?? '' );
        $account_id = sanitize_key( $_POST['account_id'] ?? 'default' );
        $platforms  = get_option( 'wpap_platforms', [] );

        unset( $platforms[ $platform ]['accounts'][ $account_id ] );
        update_option( 'wpap_platforms', $platforms );

        wp_redirect( admin_url( 'admin.php?page=wpap-settings&disconnected=' . $platform ) );
        exit;
    }

    public function admin_notices(): void {
        $screen = get_current_screen();
        if ( ! $screen || ! str_contains( $screen->id, 'wpap' ) ) {
            return;
        }

        if ( isset( $_GET['saved'] ) ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( '✅ Settings saved.', 'wp-autopilot' ) . '</p></div>';
        }
        if ( isset( $_GET['connected'] ) ) {
            printf( '<div class="notice notice-success is-dismissible"><p>%s %s</p></div>',
                esc_html__( '✅ Connected to:', 'wp-autopilot' ),
                esc_html( ucfirst( sanitize_key( $_GET['connected'] ) ) )
            );
        }
        if ( isset( $_GET['disconnected'] ) ) {
            printf( '<div class="notice notice-warning is-dismissible"><p>%s %s</p></div>',
                esc_html__( '⚠️ Disconnected from:', 'wp-autopilot' ),
                esc_html( ucfirst( sanitize_key( $_GET['disconnected'] ) ) )
            );
        }
        if ( isset( $_GET['error'] ) ) {
            printf( '<div class="notice notice-error is-dismissible"><p>%s %s</p></div>',
                esc_html__( '❌ Error:', 'wp-autopilot' ),
                esc_html( urldecode( $_GET['error'] ) )
            );
        }
        if ( isset( $_GET['queued'] ) ) {
            printf( '<div class="notice notice-success is-dismissible"><p>%s %d %s</p></div>',
                esc_html__( '✅ Queued', 'wp-autopilot' ),
                absint( $_GET['queued'] ),
                esc_html__( 'platform jobs.', 'wp-autopilot' )
            );
        }
    }
}
