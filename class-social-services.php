<?php
namespace WPAutoPilot\Services;

use WPAutoPilot\{Logger, EncryptionHelper};

defined( 'ABSPATH' ) || exit;

// ═══════════════════════════════════════════════════════════════════════════
// Facebook Pages Service — Meta Graph API v19+
// ═══════════════════════════════════════════════════════════════════════════

class FacebookService extends AbstractService {

    protected string $platform = 'facebook';
    private const GRAPH_BASE   = 'https://graph.facebook.com/v19.0';

    public function publish( \WP_Post $post, array $content, array $cfg = [] ): array|\WP_Error {
        $page_id    = $cfg['page_id']     ?? $this->config['page_id']     ?? '';
        $page_token = $cfg['page_token']  ?? $this->config['page_token']  ?? '';
        $page_token = EncryptionHelper::maybe_decrypt( $page_token );

        if ( ! $page_id || ! $page_token ) {
            return new \WP_Error( 'fb_not_configured', 'Facebook Page ID or Page Token missing.' );
        }

        $url   = $this->get_post_url( $post );
        $msg   = $content['text'] ?? '';
        $image = $this->get_featured_image_url( $post );

        // If we have an image, post as photo with caption; otherwise link post.
        if ( $image && ! empty( $cfg['attach_image'] ) ) {
            $endpoint = "/{$page_id}/photos";
            $body     = [
                'url'          => $image,
                'caption'      => $msg . "\n\n" . $url,
                'access_token' => $page_token,
            ];
        } else {
            $endpoint = "/{$page_id}/feed";
            $body     = [
                'message'      => $msg,
                'link'         => $url,
                'access_token' => $page_token,
            ];
        }

        $result = $this->http_post( self::GRAPH_BASE . $endpoint, [ 'body' => $body ] );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $post_graph_id = $result['id'] ?? '';
        $post_url      = $post_graph_id ? "https://facebook.com/{$post_graph_id}" : '';
        $this->record_stat( $post->ID, $post_graph_id, $post_url );

        return [ 'remote_id' => $post_graph_id, 'remote_url' => $post_url, 'platform' => 'facebook' ];
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// Instagram Business Service — Meta Graph API (image required)
// ═══════════════════════════════════════════════════════════════════════════

class InstagramService extends AbstractService {

    protected string $platform   = 'instagram';
    private const GRAPH_BASE     = 'https://graph.facebook.com/v19.0';

    public function publish( \WP_Post $post, array $content, array $cfg = [] ): array|\WP_Error {
        $ig_user_id = $cfg['ig_user_id']  ?? $this->config['ig_user_id']  ?? '';
        $token      = $cfg['access_token'] ?? $this->config['access_token'] ?? '';
        $token      = EncryptionHelper::maybe_decrypt( $token );

        if ( ! $ig_user_id || ! $token ) {
            return new \WP_Error( 'ig_not_configured', 'Instagram User ID or Access Token missing.' );
        }

        $image_url = $this->get_featured_image_url( $post );
        if ( ! $image_url ) {
            return new \WP_Error( 'ig_no_image', 'Instagram requires a featured image.' );
        }

        $caption = $this->build_caption( $content, $post );

        // Step 1: Create media container.
        $container = $this->http_post( self::GRAPH_BASE . "/{$ig_user_id}/media", [
            'body' => [
                'image_url'    => $image_url,
                'caption'      => $caption,
                'access_token' => $token,
            ],
        ] );

        if ( is_wp_error( $container ) ) {
            return $container;
        }

        $container_id = $container['id'] ?? '';
        if ( ! $container_id ) {
            return new \WP_Error( 'ig_container_failed', 'Failed to create Instagram media container.' );
        }

        // Step 2: Publish media.
        $publish = $this->http_post( self::GRAPH_BASE . "/{$ig_user_id}/media_publish", [
            'body' => [
                'creation_id'  => $container_id,
                'access_token' => $token,
            ],
        ] );

        if ( is_wp_error( $publish ) ) {
            return $publish;
        }

        $media_id = $publish['id'] ?? '';
        $post_url = $media_id ? "https://instagram.com/p/{$media_id}/" : '';
        $this->record_stat( $post->ID, $media_id, $post_url );

        return [ 'remote_id' => $media_id, 'remote_url' => $post_url, 'platform' => 'instagram' ];
    }

    private function build_caption( array $content, \WP_Post $post ): string {
        $text = $content['text'] ?? '';
        $tags = $content['hashtags'] ?? [];
        $url  = $this->get_post_url( $post );
        $tag_str = empty( $tags ) ? '' : "\n\n" . implode( ' ', array_map( fn($t) => '#' . $t, $tags ) );
        $caption = $text . "\n\n🔗 " . $url . $tag_str;
        // Instagram captions max 2200 chars.
        return substr( $caption, 0, 2200 );
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// LinkedIn Service — LinkedIn API v2 (UGC Posts)
// ═══════════════════════════════════════════════════════════════════════════

class LinkedInService extends AbstractService {

    protected string $platform = 'linkedin';
    private const API_BASE     = 'https://api.linkedin.com/v2';
    private const AUTH_URL     = 'https://www.linkedin.com/oauth/v2/authorization';
    private const TOKEN_URL    = 'https://www.linkedin.com/oauth/v2/accessToken';

    public function get_auth_url( string $redirect_uri ): string {
        $state  = wp_create_nonce( 'wpap_linkedin_oauth_' . $this->account_id );
        $params = [
            'response_type' => 'code',
            'client_id'     => $this->config['client_id'] ?? '',
            'redirect_uri'  => $redirect_uri,
            'state'         => $state,
            'scope'         => 'openid profile w_member_social',
        ];
        return self::AUTH_URL . '?' . http_build_query( $params );
    }

    public function handle_oauth_callback( \WP_REST_Request $request ): bool|\WP_Error {
        $code  = sanitize_text_field( $request->get_param( 'code' ) );
        $state = sanitize_text_field( $request->get_param( 'state' ) );

        if ( ! wp_verify_nonce( $state, 'wpap_linkedin_oauth_' . $this->account_id ) ) {
            return new \WP_Error( 'invalid_state', 'OAuth state mismatch.' );
        }

        $redirect_uri = rest_url( 'wpap/v1/oauth/callback/linkedin' );
        $tokens = $this->http_post( self::TOKEN_URL, [
            'body' => [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $redirect_uri,
                'client_id'     => $this->config['client_id'] ?? '',
                'client_secret' => $this->config['client_secret'] ?? '',
            ],
        ] );

        if ( is_wp_error( $tokens ) ) {
            return $tokens;
        }

        $platforms = get_option( 'wpap_platforms', [] );
        $platforms['linkedin']['accounts'][ $this->account_id ] = [
            'access_token' => EncryptionHelper::encrypt( $tokens['access_token'] ),
            'expires_at'   => time() + (int) ( $tokens['expires_in'] ?? 5183000 ),
            'enabled'      => true,
        ];
        update_option( 'wpap_platforms', $platforms );

        // Fetch person URN.
        $me = $this->api_get( '/userinfo', $tokens['access_token'] );
        if ( ! is_wp_error( $me ) && isset( $me['sub'] ) ) {
            $platforms = get_option( 'wpap_platforms', [] );
            $platforms['linkedin']['accounts'][ $this->account_id ]['person_urn'] = 'urn:li:person:' . $me['sub'];
            update_option( 'wpap_platforms', $platforms );
        }

        return true;
    }

    public function publish( \WP_Post $post, array $content, array $cfg = [] ): array|\WP_Error {
        $token      = $this->config['access_token'] ?? '';
        $person_urn = $this->config['person_urn'] ?? '';

        if ( ! $token || ! $person_urn ) {
            return new \WP_Error( 'li_not_configured', 'LinkedIn not configured. Please reconnect.' );
        }

        $url     = $this->get_post_url( $post );
        $text    = $content['linkedin_text'] ?? $content['text'] ?? '';
        $img_url = $this->get_featured_image_url( $post );

        $ugc_body = [
            'author'          => $person_urn,
            'lifecycleState'  => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => [ 'text' => $text ],
                    'shareMediaCategory' => 'ARTICLE',
                    'media' => [
                        [
                            'status'         => 'READY',
                            'originalUrl'    => $url,
                            'title'          => [ 'text' => get_the_title( $post ) ],
                            'description'    => [ 'text' => wp_trim_words( $post->post_excerpt ?: $post->post_content, 30 ) ],
                        ],
                    ],
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $result = $this->http_post( self::API_BASE . '/ugcPosts', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'X-Restli-Protocol-Version' => '2.0.0',
            ],
            'body' => wp_json_encode( $ugc_body ),
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $post_urn = $result['id'] ?? '';
        $post_url = $post_urn ? 'https://www.linkedin.com/feed/update/' . $post_urn . '/' : '';
        $this->record_stat( $post->ID, $post_urn, $post_url );

        return [ 'remote_id' => $post_urn, 'remote_url' => $post_url, 'platform' => 'linkedin' ];
    }

    private function api_get( string $endpoint, string $token ): array|\WP_Error {
        $base = str_starts_with( $endpoint, '/userinfo' ) ? 'https://api.linkedin.com/v2' : self::API_BASE;
        return $this->http_get( $base . $endpoint, [
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );
    }
}
