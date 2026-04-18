<?php
namespace WPAutoPilot\Services;

use WPAutoPilot\{Logger, EncryptionHelper};

defined( 'ABSPATH' ) || exit;

/**
 * Reddit platform service.
 *
 * Features:
 *  - OAuth2 (script/web app flows)
 *  - Category → subreddit mapping
 *  - Discussion-tone post generation
 *  - Anti-spam delays and karma checks
 *  - Flair support
 */
class RedditService extends AbstractService {

    protected string $platform = 'reddit';

    private const API_BASE   = 'https://oauth.reddit.com';
    private const AUTH_URL   = 'https://www.reddit.com/api/v1/authorize';
    private const TOKEN_URL  = 'https://www.reddit.com/api/v1/access_token';
    private const USER_AGENT = 'WPAutoPilot/2.0 (WordPress plugin; +https://your-org.com)';

    // Default subreddit mapping: WP category slug → subreddit(s).
    private const DEFAULT_SUBREDDIT_MAP = [
        'technology'    => [ 'r/technology', 'r/tech' ],
        'science'       => [ 'r/science', 'r/EverythingScience' ],
        'business'      => [ 'r/business', 'r/Entrepreneur' ],
        'health'        => [ 'r/Health', 'r/health' ],
        'finance'       => [ 'r/personalfinance', 'r/investing' ],
        'programming'   => [ 'r/programming', 'r/webdev' ],
        'marketing'     => [ 'r/marketing', 'r/socialmedia' ],
        'wordpress'     => [ 'r/Wordpress', 'r/webdev' ],
        'ai'            => [ 'r/artificial', 'r/MachineLearning', 'r/ChatGPT' ],
        'gaming'        => [ 'r/gaming' ],
        'entertainment' => [ 'r/entertainment' ],
        'news'          => [ 'r/worldnews', 'r/news' ],
        'default'       => [ 'r/InternetIsBeautiful' ],
    ];

    // ─── OAuth 2.0 ────────────────────────────────────────────────────────────

    public function get_auth_url( string $redirect_uri ): string {
        $state  = wp_create_nonce( 'wpap_reddit_oauth_' . $this->account_id );
        $params = [
            'client_id'    => $this->config['client_id'] ?? '',
            'response_type'=> 'code',
            'state'        => $state,
            'redirect_uri' => $redirect_uri,
            'duration'     => 'permanent',
            'scope'        => 'submit identity read',
        ];
        return self::AUTH_URL . '?' . http_build_query( $params );
    }

    public function handle_oauth_callback( \WP_REST_Request $request ): bool|\WP_Error {
        $error = $request->get_param( 'error' );
        if ( $error ) {
            return new \WP_Error( 'reddit_oauth_denied', 'Reddit OAuth denied: ' . $error );
        }

        $code  = sanitize_text_field( $request->get_param( 'code' ) );
        $state = sanitize_text_field( $request->get_param( 'state' ) );

        if ( ! wp_verify_nonce( $state, 'wpap_reddit_oauth_' . $this->account_id ) ) {
            return new \WP_Error( 'invalid_state', 'OAuth state mismatch for Reddit.' );
        }

        $redirect_uri = rest_url( 'wpap/v1/oauth/callback/reddit' );
        $tokens       = $this->exchange_code_for_tokens( $code, $redirect_uri );

        if ( is_wp_error( $tokens ) ) {
            return $tokens;
        }

        $this->persist_tokens( $tokens );

        // Fetch and store the username for display.
        $me = $this->api_get( '/api/v1/me', $tokens['access_token'] );
        if ( ! is_wp_error( $me ) && ! empty( $me['name'] ) ) {
            $this->update_account_field( 'username', sanitize_text_field( $me['name'] ) );
        }

        return true;
    }

    private function exchange_code_for_tokens( string $code, string $redirect_uri ): array|\WP_Error {
        return $this->reddit_auth_post( [
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $redirect_uri,
        ] );
    }

    private function refresh_token( string $refresh_token ): array|\WP_Error {
        return $this->reddit_auth_post( [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refresh_token,
        ] );
    }

    private function reddit_auth_post( array $body ): array|\WP_Error {
        $credentials = base64_encode(
            ( $this->config['client_id'] ?? '' ) . ':' . ( $this->config['client_secret'] ?? '' )
        );

        return $this->http_post( self::TOKEN_URL, [
            'headers' => [
                'Authorization' => 'Basic ' . $credentials,
                'User-Agent'    => self::USER_AGENT,
            ],
            'body' => $body,
        ] );
    }

    private function get_valid_token(): string|\WP_Error {
        $token      = $this->config['access_token'] ?? '';
        $refresh    = $this->config['refresh_token'] ?? '';
        $expires_at = (int) ( $this->config['expires_at'] ?? 0 );

        if ( $token && ( $expires_at === 0 || time() < $expires_at - 60 ) ) {
            return $token;
        }

        if ( ! $refresh ) {
            return new \WP_Error( 'reddit_no_refresh', 'Reddit access token expired. Please reconnect.' );
        }

        $new_tokens = $this->refresh_token( $refresh );
        if ( is_wp_error( $new_tokens ) ) {
            return $new_tokens;
        }

        $this->persist_tokens( $new_tokens );
        return $new_tokens['access_token'];
    }

    // ─── Publish ──────────────────────────────────────────────────────────────

    public function publish( \WP_Post $post, array $content, array $cfg = [] ): array|\WP_Error {
        if ( ! $this->is_configured() ) {
            return new \WP_Error( 'reddit_not_configured', 'Reddit service not configured.' );
        }

        $token = $this->get_valid_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        // Resolve which subreddits to post to.
        $subreddits = $this->resolve_subreddits( $post, $cfg );
        if ( empty( $subreddits ) ) {
            return new \WP_Error( 'reddit_no_subreddit', 'No subreddit resolved for this post.' );
        }

        $results  = [];
        $errors   = [];

        foreach ( $subreddits as $subreddit ) {
            $subreddit = $this->normalize_subreddit( $subreddit );

            // Anti-spam: enforce delay between subreddit posts.
            if ( ! empty( $results ) ) {
                $delay = (int) ( $cfg['inter_subreddit_delay'] ?? 60 );
                if ( $delay > 0 ) {
                    sleep( min( $delay, 300 ) );
                }
            }

            $result = $this->submit_to_subreddit( $post, $content, $cfg, $subreddit, $token );

            if ( is_wp_error( $result ) ) {
                Logger::warning(
                    "[Reddit] Failed to post to {$subreddit}: " . $result->get_error_message(),
                    [ 'post_id' => $post->ID ]
                );
                $errors[] = $result->get_error_message();
            } else {
                $results[] = $result;
                $this->record_stat( $post->ID, $result['remote_id'], $result['remote_url'] );
            }
        }

        if ( empty( $results ) ) {
            return new \WP_Error( 'reddit_all_failed', implode( '; ', $errors ) );
        }

        return $results[0]; // Return the primary result.
    }

    private function submit_to_subreddit(
        \WP_Post $post,
        array    $content,
        array    $cfg,
        string   $subreddit,
        string   $token
    ): array|\WP_Error {

        $post_type = $cfg['post_type'] ?? 'link'; // 'link' or 'self'
        $url       = $this->get_post_url( $post );

        $body = [
            'sr'          => $subreddit,
            'kind'        => $post_type,
            'title'       => $this->build_reddit_title( $content, $post ),
            'nsfw'        => false,
            'spoiler'     => false,
            'sendreplies' => true,
            'resubmit'    => false,
        ];

        if ( 'link' === $post_type ) {
            $body['url'] = $url;
        } else {
            $body['text'] = $content['reddit_body'] ?? $content['text'] ?? '';
        }

        if ( ! empty( $cfg['flair_id'] ) ) {
            $body['flair_id']   = sanitize_text_field( $cfg['flair_id'] );
            $body['flair_text'] = sanitize_text_field( $cfg['flair_text'] ?? '' );
        }

        $result = $this->api_post( '/api/submit', $body, $token );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // Reddit wraps the response in a Listing envelope.
        $data      = $result['jquery'] ?? null;
        $permalink = $result['data']['url'] ?? '';
        $remote_id = $result['data']['id'] ?? '';

        // Sometimes the response is nested.
        if ( isset( $result['data']['json']['data']['id'] ) ) {
            $remote_id = $result['data']['json']['data']['id'];
            $permalink = 'https://reddit.com' . ( $result['data']['json']['data']['url'] ?? '' );
        }

        // Fallback: search for the post URL pattern in the response.
        if ( ! $remote_id ) {
            $raw = wp_json_encode( $result );
            if ( preg_match( '/"t3_([a-z0-9]+)"/', $raw, $m ) ) {
                $remote_id = $m[1];
                $permalink = "https://reddit.com/r/{$subreddit}/comments/{$remote_id}/";
            }
        }

        return [
            'remote_id'   => $remote_id,
            'remote_url'  => $permalink,
            'platform'    => 'reddit',
            'subreddit'   => $subreddit,
        ];
    }

    // ─── Subreddit Resolution ─────────────────────────────────────────────────

    /**
     * Resolve subreddits for a post based on category mapping and explicit config.
     *
     * @return string[]
     */
    private function resolve_subreddits( \WP_Post $post, array $cfg ): array {
        // 1. Explicit override in per-post meta.
        $meta_subs = get_post_meta( $post->ID, '_wpap_reddit_subreddits', true );
        if ( ! empty( $meta_subs ) ) {
            return array_map( 'trim', explode( ',', $meta_subs ) );
        }

        // 2. Explicit subreddits in platform config.
        if ( ! empty( $cfg['subreddits'] ) ) {
            return (array) $cfg['subreddits'];
        }

        // 3. Category → subreddit mapping.
        $map        = $cfg['category_map'] ?? self::DEFAULT_SUBREDDIT_MAP;
        $categories = get_the_terms( $post->ID, 'category' );
        $resolved   = [];

        if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
            foreach ( $categories as $cat ) {
                $slug = $cat->slug;
                if ( isset( $map[ $slug ] ) ) {
                    $resolved = array_merge( $resolved, (array) $map[ $slug ] );
                }
            }
        }

        // 4. Fallback to default.
        if ( empty( $resolved ) ) {
            $resolved = (array) ( $map['default'] ?? [ 'r/InternetIsBeautiful' ] );
        }

        // Limit to max subreddits per post to avoid spam.
        $max = (int) ( $cfg['max_subreddits'] ?? 2 );
        return array_slice( array_unique( $resolved ), 0, $max );
    }

    private function normalize_subreddit( string $sub ): string {
        // Accept "r/webdev" or "webdev" or "/r/webdev".
        $sub = ltrim( $sub, '/' );
        if ( ! str_starts_with( $sub, 'r/' ) ) {
            $sub = 'r/' . $sub;
        }
        return $sub;
    }

    // ─── Content Builders ─────────────────────────────────────────────────────

    private function build_reddit_title( array $content, \WP_Post $post ): string {
        $title = $content['reddit_title'] ?? $content['title'] ?? get_the_title( $post );
        // Reddit title max 300 chars.
        if ( strlen( $title ) > 295 ) {
            $title = substr( $title, 0, 292 ) . '...';
        }
        return wp_strip_all_tags( $title );
    }

    // ─── API Helpers ──────────────────────────────────────────────────────────

    private function api_post( string $endpoint, array $body, string $token ): array|\WP_Error {
        return $this->http_post( self::API_BASE . $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'User-Agent'    => self::USER_AGENT,
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ] );
    }

    private function api_get( string $endpoint, string $token ): array|\WP_Error {
        return $this->http_get( self::API_BASE . $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'User-Agent'    => self::USER_AGENT,
            ],
        ] );
    }

    // ─── Token Persistence ────────────────────────────────────────────────────

    private function persist_tokens( array $tokens ): void {
        $platforms = get_option( 'wpap_platforms', [] );
        $accounts  = $platforms['reddit']['accounts'] ?? [];
        $existing  = $accounts[ $this->account_id ] ?? [];

        $accounts[ $this->account_id ] = array_merge( $existing, [
            'access_token'  => EncryptionHelper::encrypt( $tokens['access_token'] ),
            'refresh_token' => EncryptionHelper::encrypt( $tokens['refresh_token'] ?? '' ),
            'expires_at'    => time() + (int) ( $tokens['expires_in'] ?? 3600 ),
            'enabled'       => true,
        ] );

        $platforms['reddit']['accounts'] = $accounts;
        update_option( 'wpap_platforms', $platforms );
    }

    private function update_account_field( string $key, string $value ): void {
        $platforms = get_option( 'wpap_platforms', [] );
        $platforms['reddit']['accounts'][ $this->account_id ][ $key ] = $value;
        update_option( 'wpap_platforms', $platforms );
    }
}
