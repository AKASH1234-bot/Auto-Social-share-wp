<?php
namespace WPAutoPilot\Services;

use WPAutoPilot\{Logger, UrlShortener, EncryptionHelper, MediaHandler};

defined( 'ABSPATH' ) || exit;

/**
 * Twitter / X platform service.
 * Uses Twitter API v2 with OAuth 2.0 (PKCE) for user auth,
 * and OAuth 1.0a for media upload (v1.1).
 */
class TwitterService extends AbstractService {

    protected string $platform = 'twitter';

    private const API_BASE       = 'https://api.twitter.com/2';
    private const UPLOAD_BASE    = 'https://upload.twitter.com/1.1';
    private const AUTH_URL       = 'https://twitter.com/i/oauth2/authorize';
    private const TOKEN_URL      = 'https://api.twitter.com/2/oauth2/token';
    private const CHAR_LIMIT     = 280;

    // ─── OAuth 2.0 PKCE ───────────────────────────────────────────────────────

    public function get_auth_url( string $redirect_uri ): string {
        $verifier = $this->generate_code_verifier();
        $challenge = $this->generate_code_challenge( $verifier );

        set_transient( 'wpap_twitter_pkce_' . $this->account_id, $verifier, 600 );

        $params = [
            'response_type'         => 'code',
            'client_id'             => $this->config['client_id'] ?? '',
            'redirect_uri'          => $redirect_uri,
            'scope'                 => 'tweet.read tweet.write users.read offline.access',
            'state'                 => wp_create_nonce( 'wpap_twitter_oauth' ),
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ];

        return self::AUTH_URL . '?' . http_build_query( $params );
    }

    public function handle_oauth_callback( \WP_REST_Request $request ): bool|\WP_Error {
        $code  = sanitize_text_field( $request->get_param( 'code' ) );
        $state = sanitize_text_field( $request->get_param( 'state' ) );

        if ( ! wp_verify_nonce( $state, 'wpap_twitter_oauth' ) ) {
            return new \WP_Error( 'invalid_state', 'OAuth state mismatch.' );
        }

        $verifier = get_transient( 'wpap_twitter_pkce_' . $this->account_id );
        delete_transient( 'wpap_twitter_pkce_' . $this->account_id );

        $redirect_uri = rest_url( 'wpap/v1/oauth/callback/twitter' );
        $result       = $this->exchange_code( $code, $verifier, $redirect_uri );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $this->save_tokens( $result );
        return true;
    }

    private function exchange_code( string $code, string $verifier, string $redirect_uri ): array|\WP_Error {
        $credentials = base64_encode( ( $this->config['client_id'] ?? '' ) . ':' . ( $this->config['client_secret'] ?? '' ) );

        return $this->http_post( self::TOKEN_URL, [
            'headers' => [
                'Authorization' => 'Basic ' . $credentials,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'code'          => $code,
                'grant_type'    => 'authorization_code',
                'redirect_uri'  => $redirect_uri,
                'code_verifier' => $verifier,
            ],
        ] );
    }

    private function save_tokens( array $tokens ): void {
        $platforms   = get_option( 'wpap_platforms', [] );
        $accounts    = $platforms['twitter']['accounts'] ?? [];
        $encrypted   = [
            'access_token'  => EncryptionHelper::encrypt( $tokens['access_token'] ),
            'refresh_token' => EncryptionHelper::encrypt( $tokens['refresh_token'] ?? '' ),
            'expires_at'    => time() + (int) ( $tokens['expires_in'] ?? 7200 ),
            'enabled'       => true,
        ];
        $accounts[ $this->account_id ] = array_merge( $accounts[ $this->account_id ] ?? [], $encrypted );
        $platforms['twitter']['accounts'] = $accounts;
        update_option( 'wpap_platforms', $platforms );
    }

    // ─── Token Refresh ────────────────────────────────────────────────────────

    private function get_valid_access_token(): string|\WP_Error {
        $token      = $this->config['access_token'] ?? '';
        $refresh    = $this->config['refresh_token'] ?? '';
        $expires_at = (int) ( $this->config['expires_at'] ?? 0 );

        if ( $token && ( $expires_at === 0 || time() < $expires_at - 60 ) ) {
            return $token;
        }

        if ( ! $refresh ) {
            return new \WP_Error( 'no_refresh_token', 'Twitter access token expired and no refresh token available.' );
        }

        $credentials = base64_encode( ( $this->config['client_id'] ?? '' ) . ':' . ( $this->config['client_secret'] ?? '' ) );
        $result      = $this->http_post( self::TOKEN_URL, [
            'headers' => [
                'Authorization' => 'Basic ' . $credentials,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refresh,
            ],
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $this->config['access_token']  = $result['access_token'];
        $this->config['refresh_token'] = $result['refresh_token'] ?? $refresh;
        $this->config['expires_at']    = time() + (int) ( $result['expires_in'] ?? 7200 );
        $this->save_tokens( $result );

        return $result['access_token'];
    }

    // ─── Publish ──────────────────────────────────────────────────────────────

    public function publish( \WP_Post $post, array $content, array $cfg = [] ): array|\WP_Error {
        if ( ! $this->is_configured() ) {
            return new \WP_Error( 'not_configured', 'Twitter service is not configured.' );
        }

        $token = $this->get_valid_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $text = $this->build_tweet_text( $content, $post );

        // Handle media upload if featured image exists.
        $media_ids = [];
        if ( ! empty( $cfg['attach_image'] ) ) {
            $image_path = $this->get_featured_image_path( $post );
            if ( $image_path ) {
                $media_id = $this->upload_media( $image_path, $token );
                if ( ! is_wp_error( $media_id ) ) {
                    $media_ids[] = $media_id;
                }
            }
        }

        $body = [ 'text' => $text ];
        if ( ! empty( $media_ids ) ) {
            $body['media'] = [ 'media_ids' => $media_ids ];
        }

        // Thread / poll support could be added here.
        $result = $this->http_post( self::API_BASE . '/tweets', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $tweet_id  = $result['data']['id'] ?? '';
        $tweet_url = $tweet_id ? 'https://twitter.com/i/web/status/' . $tweet_id : '';

        $this->record_stat( $post->ID, $tweet_id, $tweet_url );

        return [
            'remote_id'  => $tweet_id,
            'remote_url' => $tweet_url,
            'platform'   => 'twitter',
        ];
    }

    // ─── Media Upload (v1.1) ──────────────────────────────────────────────────

    private function upload_media( string $file_path, string $bearer_token ): string|\WP_Error {
        // Twitter v1.1 media upload requires OAuth 1.0a; we use v2 user context here.
        // For simplicity, we use simple upload (INIT/APPEND/FINALIZE for large files).
        $mime_type = mime_content_type( $file_path );
        $data      = file_get_contents( $file_path ); // phpcs:ignore
        if ( ! $data ) {
            return new \WP_Error( 'media_read', 'Cannot read image file.' );
        }

        // Use OAuth1 if keys are provided, else skip.
        $api_key        = $this->config['api_key'] ?? '';
        $api_secret     = $this->config['api_secret'] ?? '';
        $access_token   = $this->config['oauth1_access_token'] ?? '';
        $access_secret  = $this->config['oauth1_access_secret'] ?? '';

        if ( ! $api_key || ! $api_secret || ! $access_token || ! $access_secret ) {
            return new \WP_Error( 'media_oauth', 'OAuth 1.0a keys required for media upload. Provide api_key, api_secret, oauth1_access_token, oauth1_access_secret.' );
        }

        $upload_url = self::UPLOAD_BASE . '/media/upload.json';
        $auth_header = $this->build_oauth1_header( 'POST', $upload_url, [], $api_key, $api_secret, $access_token, $access_secret );

        $response = wp_remote_post( $upload_url, [
            'timeout' => 60,
            'headers' => [
                'Authorization' => $auth_header,
            ],
            'body' => [
                'media_data'  => base64_encode( $data ),
                'media_type'  => $mime_type,
            ],
        ] );

        $parsed = $this->parse_response( $response );
        if ( is_wp_error( $parsed ) ) {
            return $parsed;
        }

        return (string) ( $parsed['media_id_string'] ?? '' );
    }

    // ─── Content Builder ──────────────────────────────────────────────────────

    private function build_tweet_text( array $content, \WP_Post $post ): string {
        $url       = $this->get_post_url( $post );
        $hook      = $content['hook'] ?? '';
        $hashtags  = $this->build_hashtags( $content['hashtags'] ?? [] );
        $base_text = $hook ?: $content['text'] ?? '';

        // Trim to fit within 280 chars, accounting for URL (t.co wraps to 23 chars).
        $url_len   = 24; // 23 chars + space
        $tag_len   = strlen( $hashtags ) > 0 ? strlen( $hashtags ) + 1 : 0;
        $available = self::CHAR_LIMIT - $url_len - $tag_len - 1;

        if ( strlen( $base_text ) > $available ) {
            $base_text = substr( $base_text, 0, $available - 1 ) . '…';
        }

        $parts = array_filter( [ $base_text, $hashtags, $url ] );
        return implode( ' ', $parts );
    }

    private function build_hashtags( array $tags ): string {
        if ( empty( $tags ) ) {
            return '';
        }
        return implode( ' ', array_map(
            fn( $t ) => '#' . preg_replace( '/\s+/', '', $t ),
            array_slice( $tags, 0, 5 )
        ) );
    }

    // ─── OAuth 1.0a Signature ─────────────────────────────────────────────────

    private function build_oauth1_header(
        string $method,
        string $url,
        array  $params,
        string $consumer_key,
        string $consumer_secret,
        string $token,
        string $token_secret
    ): string {
        $oauth = [
            'oauth_consumer_key'     => $consumer_key,
            'oauth_nonce'            => wp_generate_uuid4(),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) time(),
            'oauth_token'            => $token,
            'oauth_version'          => '1.0',
        ];

        $base_params = array_merge( $params, $oauth );
        ksort( $base_params );

        $base_string = strtoupper( $method ) . '&'
            . rawurlencode( $url ) . '&'
            . rawurlencode( http_build_query( $base_params, '', '&', PHP_QUERY_RFC3986 ) );

        $signing_key = rawurlencode( $consumer_secret ) . '&' . rawurlencode( $token_secret );
        $signature   = base64_encode( hash_hmac( 'sha1', $base_string, $signing_key, true ) );
        $oauth['oauth_signature'] = $signature;

        $header_parts = [];
        foreach ( $oauth as $k => $v ) {
            $header_parts[] = rawurlencode( $k ) . '="' . rawurlencode( $v ) . '"';
        }

        return 'OAuth ' . implode( ', ', $header_parts );
    }

    // ─── PKCE Helpers ─────────────────────────────────────────────────────────

    private function generate_code_verifier(): string {
        return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
    }

    private function generate_code_challenge( string $verifier ): string {
        return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
    }
}
