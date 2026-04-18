<?php
namespace WPAutoPilot\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Base class for all platform service integrations.
 * Every service must implement publish() and optionally handle_oauth_callback().
 */
abstract class AbstractService {

    protected array  $config;
    protected string $account_id;
    protected string $platform = '';

    public function __construct( array $config, string $account_id = 'default' ) {
        $this->config     = $config;
        $this->account_id = $account_id;
    }

    /**
     * Publish content to the platform.
     *
     * @param \WP_Post $post    The WordPress post object.
     * @param array    $content Generated content (text, hashtags, media, etc.).
     * @param array    $cfg     Platform-specific config for this account.
     * @return array|\WP_Error  On success, array with 'remote_id', 'remote_url'. On failure, WP_Error.
     */
    abstract public function publish( \WP_Post $post, array $content, array $cfg = [] ): array|\WP_Error;

    /**
     * Handle OAuth callback (override in services that use OAuth).
     */
    public function handle_oauth_callback( \WP_REST_Request $request ): bool|\WP_Error {
        return new \WP_Error( 'not_supported', 'OAuth not implemented for ' . static::class );
    }

    /**
     * Build the authorization URL to start OAuth flow.
     */
    public function get_auth_url( string $redirect_uri ): string {
        return '';
    }

    /**
     * Perform an HTTP POST request with error handling.
     */
    protected function http_post( string $url, array $args ): array|\WP_Error {
        $response = wp_remote_post( $url, array_merge( [
            'timeout'    => 30,
            'user-agent' => 'WP-AutoPilot/' . WPAP_VERSION . '; ' . get_bloginfo( 'url' ),
        ], $args ) );

        return $this->parse_response( $response );
    }

    /**
     * Perform an HTTP GET request.
     */
    protected function http_get( string $url, array $args = [] ): array|\WP_Error {
        $response = wp_remote_get( $url, array_merge( [
            'timeout'    => 30,
            'user-agent' => 'WP-AutoPilot/' . WPAP_VERSION . '; ' . get_bloginfo( 'url' ),
        ], $args ) );

        return $this->parse_response( $response );
    }

    /**
     * Parse and validate HTTP response.
     *
     * @return array|\WP_Error
     */
    protected function parse_response( array|\WP_Error $response ): array|\WP_Error {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code >= 400 ) {
            $message = $data['error'] ?? $data['message'] ?? $data['detail'] ?? $body;
            if ( is_array( $message ) ) {
                $message = wp_json_encode( $message );
            }
            return new \WP_Error(
                'api_error_' . $code,
                sprintf( '[%s %d] %s', $this->platform, $code, $message )
            );
        }

        return $data ?? [];
    }

    /**
     * Record the successful post in the stats table.
     */
    protected function record_stat( int $post_id, string $remote_id, string $remote_url, string $short_url = '' ): void {
        global $wpdb;
        $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . WPAP_STATS_TABLE,
            [
                'post_id'    => $post_id,
                'platform'   => $this->platform,
                'account_id' => $this->account_id,
                'remote_id'  => $remote_id,
                'remote_url' => $remote_url,
                'short_url'  => $short_url,
                'posted_at'  => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Get the post URL, optionally shortened.
     */
    protected function get_post_url( \WP_Post $post ): string {
        $url = get_permalink( $post );
        return \WPAutoPilot\UrlShortener::shorten( $url ) ?: $url;
    }

    /**
     * Upload an image to a remote URL and return the local temp path.
     */
    protected function get_featured_image_path( \WP_Post $post ): ?string {
        $thumb_id = get_post_thumbnail_id( $post->ID );
        if ( ! $thumb_id ) {
            return null;
        }
        $path = get_attached_file( $thumb_id );
        return $path ?: null;
    }

    protected function get_featured_image_url( \WP_Post $post ): ?string {
        $thumb_id = get_post_thumbnail_id( $post->ID );
        if ( ! $thumb_id ) {
            return null;
        }
        $src = wp_get_attachment_image_src( $thumb_id, 'large' );
        return $src ? $src[0] : null;
    }

    protected function is_configured(): bool {
        return ! empty( $this->config );
    }
}
