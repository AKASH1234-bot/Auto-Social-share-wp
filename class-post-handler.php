<?php
namespace WPAutoPilot;

defined( 'ABSPATH' ) || exit;

/**
 * Listens to WordPress post lifecycle events and enqueues distribution jobs.
 */
class PostHandler {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks(): void {
        // Fires when a post transitions to 'publish'.
        add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );

        // Meta box for per-post overrides.
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post',       [ $this, 'save_meta_box' ] );

        // Manual re-share via admin action.
        add_action( 'admin_post_wpap_manual_share', [ $this, 'manual_share' ] );
    }

    /**
     * Called on every post status transition.
     */
    public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
        $general     = get_option( 'wpap_general', [] );
        $post_types  = $general['post_types'] ?? [ 'post' ];

        if ( ! in_array( $post->post_type, $post_types, true ) ) {
            return;
        }

        // Skip auto-drafts, revisions, etc.
        if ( in_array( $post->post_type, [ 'revision', 'auto-draft', 'nav_menu_item' ], true ) ) {
            return;
        }

        $is_publish = ( 'publish' === $new_status && 'publish' !== $old_status );
        $is_update  = ( 'publish' === $new_status && 'publish' === $old_status );

        $should_fire = false;
        if ( $is_publish && ! empty( $general['trigger_on_publish'] ) ) {
            $should_fire = true;
        }
        if ( $is_update && ! empty( $general['trigger_on_update'] ) ) {
            $should_fire = true;
        }

        if ( ! $should_fire ) {
            return;
        }

        // Per-post opt-out check.
        if ( get_post_meta( $post->ID, '_wpap_skip', true ) ) {
            return;
        }

        $this->enqueue_post( $post->ID, $is_update ? 'update' : 'publish' );
    }

    /**
     * Enqueue all enabled platforms for a given post.
     */
    public function enqueue_post( int $post_id, string $trigger = 'publish' ): int {
        $general         = get_option( 'wpap_general', [] );
        $platform_config = get_option( 'wpap_platforms', [] );
        $global_delay    = (int) ( $general['global_delay'] ?? 0 );
        $platform_delay  = (int) ( $general['platform_delay'] ?? 30 );

        $enabled_platforms = array_filter(
            $platform_config,
            fn( $cfg ) => ! empty( $cfg['enabled'] )
        );

        if ( empty( $enabled_platforms ) ) {
            Logger::warning( 'No platforms enabled — nothing to enqueue', [ 'post_id' => $post_id ] );
            return 0;
        }

        // Per-post platform overrides stored in meta.
        $post_overrides = get_post_meta( $post_id, '_wpap_platforms', true ) ?: [];

        $queued  = 0;
        $delay   = $global_delay;

        foreach ( $enabled_platforms as $platform => $cfg ) {
            // Respect per-post platform toggle.
            if ( isset( $post_overrides[ $platform ] ) && ! $post_overrides[ $platform ] ) {
                continue;
            }

            $accounts = $cfg['accounts'] ?? [ 'default' => $cfg ];

            foreach ( $accounts as $account_id => $account_cfg ) {
                if ( empty( $account_cfg['enabled'] ) ) {
                    continue;
                }

                $scheduled_at = gmdate( 'Y-m-d H:i:s', time() + $delay );

                Scheduler::instance()->enqueue( [
                    'post_id'      => $post_id,
                    'platform'     => $platform,
                    'account_id'   => (string) $account_id,
                    'scheduled_at' => $scheduled_at,
                    'payload'      => wp_json_encode( [
                        'trigger'  => $trigger,
                        'cfg'      => $account_cfg,
                    ] ),
                ] );

                $delay += $platform_delay;
                $queued++;
            }
        }

        Logger::info(
            sprintf( 'Enqueued %d jobs for post #%d (trigger: %s)', $queued, $post_id, $trigger ),
            [ 'post_id' => $post_id ]
        );

        return $queued;
    }

    // ─── Meta Box ─────────────────────────────────────────────────────────────

    public function add_meta_box(): void {
        $general    = get_option( 'wpap_general', [] );
        $post_types = $general['post_types'] ?? [ 'post' ];

        foreach ( $post_types as $pt ) {
            add_meta_box(
                'wpap_meta_box',
                __( '🚀 WP AutoPilot — Social Share', 'wp-autopilot' ),
                [ $this, 'render_meta_box' ],
                $pt,
                'side',
                'high'
            );
        }
    }

    public function render_meta_box( \WP_Post $post ): void {
        wp_nonce_field( 'wpap_meta_box_save', 'wpap_meta_nonce' );

        $skip            = get_post_meta( $post->ID, '_wpap_skip', true );
        $platform_config = get_option( 'wpap_platforms', [] );
        $post_overrides  = get_post_meta( $post->ID, '_wpap_platforms', true ) ?: [];

        echo '<div class="wpap-meta-box">';
        echo '<label style="display:flex;align-items:center;gap:6px;margin-bottom:10px;">';
        echo '<input type="checkbox" name="wpap_skip" value="1"' . checked( $skip, '1', false ) . '>';
        echo '<strong>' . esc_html__( 'Skip auto-sharing this post', 'wp-autopilot' ) . '</strong>';
        echo '</label>';
        echo '<p style="color:#888;font-size:11px;margin:0 0 10px;">' . esc_html__( 'Per-platform toggles:', 'wp-autopilot' ) . '</p>';

        foreach ( $platform_config as $platform => $cfg ) {
            if ( empty( $cfg['enabled'] ) ) {
                continue;
            }
            $checked = $post_overrides[ $platform ] ?? true;
            printf(
                '<label style="display:flex;align-items:center;gap:6px;margin-bottom:4px;font-size:12px;">
                    <input type="checkbox" name="wpap_platforms[%1$s]" value="1"%2$s>
                    %3$s
                </label>',
                esc_attr( $platform ),
                checked( $checked, true, false ),
                esc_html( ucfirst( $platform ) )
            );
        }

        // Manual share button (only on published posts).
        if ( 'publish' === $post->post_status ) {
            $url = wp_nonce_url(
                admin_url( 'admin-post.php?action=wpap_manual_share&post_id=' . $post->ID ),
                'wpap_manual_share_' . $post->ID
            );
            echo '<hr style="margin:10px 0;">';
            printf(
                '<a href="%s" class="button button-secondary" style="width:100%%;text-align:center;">%s</a>',
                esc_url( $url ),
                esc_html__( '↻ Re-share Now', 'wp-autopilot' )
            );
        }

        echo '</div>';
    }

    public function save_meta_box( int $post_id ): void {
        if ( ! isset( $_POST['wpap_meta_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( $_POST['wpap_meta_nonce'] ), 'wpap_meta_box_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $skip = ! empty( $_POST['wpap_skip'] ) ? '1' : '0';
        update_post_meta( $post_id, '_wpap_skip', $skip );

        $platforms = [];
        $posted    = isset( $_POST['wpap_platforms'] ) ? (array) $_POST['wpap_platforms'] : []; // phpcs:ignore
        foreach ( array_keys( get_option( 'wpap_platforms', [] ) ) as $platform ) {
            $platforms[ $platform ] = isset( $posted[ $platform ] );
        }
        update_post_meta( $post_id, '_wpap_platforms', $platforms );
    }

    public function manual_share(): void {
        $post_id = absint( $_GET['post_id'] ?? 0 );
        check_admin_referer( 'wpap_manual_share_' . $post_id );

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Permission denied.', 'wp-autopilot' ) );
        }

        $queued = $this->enqueue_post( $post_id, 'manual' );
        wp_redirect( admin_url( 'admin.php?page=wpap-logs&queued=' . $queued ) );
        exit;
    }
}
