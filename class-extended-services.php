<?php
namespace WPAutoPilot\Services;

use WPAutoPilot\{Logger, EncryptionHelper};

defined( 'ABSPATH' ) || exit;

// ═══════════════════════════════════════════════════════════════════════════
// Pinterest Service — Pinterest API v5
// ═══════════════════════════════════════════════════════════════════════════

class PinterestService extends AbstractService {

    protected string $platform = 'pinterest';
    private const API_BASE     = 'https://api.pinterest.com/v5';

    public function publish( \WP_Post $post, array $content, array $cfg = [] ): array|\WP_Error {
        $token    = $this->config['access_token'] ?? '';
        $board_id = $cfg['board_id'] ?? $this->config['board_id'] ?? '';

        if ( ! $token || ! $board_id ) {
            return new \WP_Error( 'pinterest_not_configured', 'Pinterest access token or board ID missing.' );
        }

        $image_url = $this->get_featured_image_url( $post );
        if ( ! $image_url ) {
            return new \WP_Error( 'pinterest_no_image', 'Pinterest requires a featured image.' );
        }

        $url         = $this->get_post_url( $post );
        $description = $content['text'] ?? '';
        $title       = get_the_title( $post );

        $body = [
            'board_id'   => $board_id,
            'title'      => substr( $title, 0, 100 ),
            'description'=> substr( $description, 0, 500 ),
            'link'       => $url,
            'media_source' => [
                'source_type' => 'image_url',
                'url'         => $image_url,
            ],
        ];

        $result = $this->http_post( self::API_BASE . '/pins', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $pin_id  = $result['id'] ?? '';
        $pin_url = $pin_id ? "https://pinterest.com/pin/{$pin_id}/" : '';
        $this->record_stat( $post->ID, $pin_id, $pin_url );

        return [ 'remote_id' => $pin_id, 'remote_url' => $pin_url, 'platform' => 'pinterest' ];
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// Medium Service — Medium Integration API
// ═══════════════════════════════════════════════════════════════════════════

class MediumService extends AbstractService {

    protected string $platform = 'medium';
    private const API_BASE     = 'https://api.medium.com/v1';

    public function publish( \WP_Post $post, array $content, array $cfg = [] ): array|\WP_Error {
        $token       = $this->config['access_token'] ?? '';
        $publication = $cfg['publication_id'] ?? $this->config['publication_id'] ?? '';

        if ( ! $token ) {
            return new \WP_Error( 'medium_not_configured', 'Medium integration token missing.' );
        }

        // Get author ID.
        $me = $this->http_get( self::API_BASE . '/me', [
            'headers' => [ 'Authorization' => 'Bearer ' . $token ],
        ] );

        if ( is_wp_error( $me ) ) {
            return $me;
        }

        $author_id  = $me['data']['id'] ?? '';
        $endpoint   = $publication
            ? self::API_BASE . "/publications/{$publication}/posts"
            : self::API_BASE . "/users/{$author_id}/posts";

        $body = [
            'title'          => get_the_title( $post ),
            'contentFormat'  => 'html',
            'content'        => $this->build_medium_content( $post ),
            'canonicalUrl'   => get_permalink( $post ),
            'tags'           => $this->get_tags( $post ),
            'publishStatus'  => $cfg['status'] ?? 'public',
        ];

        $result = $this->http_post( $endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $medium_url = $result['data']['url'] ?? '';
        $medium_id  = $result['data']['id'] ?? '';
        $this->record_stat( $post->ID, $medium_id, $medium_url );

        return [ 'remote_id' => $medium_id, 'remote_url' => $medium_url, 'platform' => 'medium' ];
    }

    private function build_medium_content( \WP_Post $post ): string {
        $content = $post->post_content;
        $content = apply_filters( 'the_content', $content );
        // Add canonical notice.
        $original_url = get_permalink( $post );
        $content .= sprintf(
            '<hr><p><em>Originally published at <a href="%s">%s</a></em></p>',
            esc_url( $original_url ),
            esc_url( $original_url )
        );
        return $content;
    }

    private function get_tags( \WP_Post $post ): array {
        $tags = get_the_terms( $post->ID, 'post_tag' );
        if ( ! $tags || is_wp_error( $tags ) ) {
            return [];
        }
        return array_slice( wp_list_pluck( $tags, 'name' ), 0, 5 );
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// Tumblr Service — Tumblr API v2
// ═══════════════════════════════════════════════════════════════════════════

class TumblrService extends AbstractService {

    protected string $platform = 'tumblr';
    private const API_BASE     = 'https://api.tumblr.com/v2';

    public function publish( \WP_Post $post, array $content, array $cfg = [] ): array|\WP_Error {
        $blog_name = $cfg['blog_name'] ?? $this->config['blog_name'] ?? '';
        $api_key   = $this->config['api_key'] ?? '';
        $token     = $this->config['access_token'] ?? '';

        if ( ! $blog_name || ! $token ) {
            return new \WP_Error( 'tumblr_not_configured', 'Tumblr blog name or access token missing.' );
        }

        $url     = $this->get_post_url( $post );
        $caption = $content['text'] ?? get_the_title( $post );
        $tags    = implode( ',', $this->get_tags( $post ) );

        $image_url = $this->get_featured_image_url( $post );
        if ( $image_url ) {
            $endpoint = "/blog/{$blog_name}/post";
            $body     = [
                'type'    => 'photo',
                'source'  => $image_url,
                'caption' => $caption . "\n\n" . $url,
                'tags'    => $tags,
            ];
        } else {
            $endpoint = "/blog/{$blog_name}/post";
            $body     = [
                'type'  => 'link',
                'url'   => $url,
                'title' => get_the_title( $post ),
                'description' => $caption,
                'tags'  => $tags,
            ];
        }

        $result = $this->http_post( self::API_BASE . $endpoint . '?api_key=' . $api_key, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $post_id_tumblr = $result['response']['id'] ?? '';
        $post_url = $post_id_tumblr ? "https://{$blog_name}.tumblr.com/post/{$post_id_tumblr}" : '';
        $this->record_stat( $post->ID, (string) $post_id_tumblr, $post_url );

        return [ 'remote_id' => (string) $post_id_tumblr, 'remote_url' => $post_url, 'platform' => 'tumblr' ];
    }

    private function get_tags( \WP_Post $post ): array {
        $tags = get_the_terms( $post->ID, 'post_tag' );
        if ( ! $tags || is_wp_error( $tags ) ) {
            return [];
        }
        return wp_list_pluck( $tags, 'name' );
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// Discord Service — Webhook (no OAuth needed)
// ═══════════════════════════════════════════════════════════════════════════

class DiscordService extends AbstractService {

    protected string $platform = 'discord';

    public function publish( \WP_Post $post, array $content, array $cfg = [] ): array|\WP_Error {
        $webhook_url = $this->config['webhook_url'] ?? '';
        if ( ! $webhook_url ) {
            return new \WP_Error( 'discord_not_configured', 'Discord webhook URL missing.' );
        }

        $url       = $this->get_post_url( $post );
        $title     = get_the_title( $post );
        $text      = $content['text'] ?? '';
        $image_url = $this->get_featured_image_url( $post );

        // Build a rich embed.
        $embed = [
            'title'       => $title,
            'url'         => $url,
            'description' => substr( $text, 0, 4096 ),
            'color'       => hexdec( ltrim( $cfg['embed_color'] ?? '5865F2', '#' ) ),
            'timestamp'   => gmdate( 'c' ),
            'footer'      => [
                'text' => parse_url( get_site_url(), PHP_URL_HOST ) . ' via WP AutoPilot',
            ],
        ];

        if ( $image_url ) {
            $embed['image'] = [ 'url' => $image_url ];
        }

        $payload = [ 'embeds' => [ $embed ] ];

        if ( ! empty( $cfg['role_mention'] ) ) {
            $payload['content'] = '<@&' . sanitize_text_field( $cfg['role_mention'] ) . '>';
        }

        $result = $this->http_post( $webhook_url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
        ] );

        // Discord returns 204 No Content on success — parse_response won't throw.
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $this->record_stat( $post->ID, uniqid( 'discord_', true ), $url );
        return [ 'remote_id' => 'webhook', 'remote_url' => $url, 'platform' => 'discord' ];
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// Mastodon Service — ActivityPub / Mastodon API v1
// ═══════════════════════════════════════════════════════════════════════════

class MastodonService extends AbstractService {

    protected string $platform = 'mastodon';

    public function publish( \WP_Post $post, array $content, array $cfg = [] ): array|\WP_Error {
        $instance_url = rtrim( $this->config['instance_url'] ?? 'https://mastodon.social', '/' );
        $token        = $this->config['access_token'] ?? '';

        if ( ! $token ) {
            return new \WP_Error( 'mastodon_not_configured', 'Mastodon access token missing.' );
        }

        $url      = $this->get_post_url( $post );
        $text     = $content['text'] ?? '';
        $tags     = $content['hashtags'] ?? [];
        $tag_str  = empty( $tags ) ? '' : ' ' . implode( ' ', array_map( fn($t) => '#' . $t, $tags ) );
        $status   = $text . "\n\n" . $url . $tag_str;

        // Mastodon has 500 char limit by default (instance can raise it).
        $max = (int) ( $cfg['char_limit'] ?? 500 );
        if ( strlen( $status ) > $max ) {
            $overflow = strlen( $status ) - $max;
            $text     = substr( $text, 0, strlen( $text ) - $overflow - 3 ) . '...';
            $status   = $text . "\n\n" . $url . $tag_str;
        }

        $media_ids = [];
        if ( ! empty( $cfg['attach_image'] ) ) {
            $image_path = $this->get_featured_image_path( $post );
            if ( $image_path ) {
                $upload = $this->upload_media( $instance_url, $token, $image_path );
                if ( ! is_wp_error( $upload ) ) {
                    $media_ids[] = $upload['id'];
                }
            }
        }

        $body = [ 'status' => $status, 'visibility' => $cfg['visibility'] ?? 'public' ];
        if ( ! empty( $media_ids ) ) {
            $body['media_ids'] = $media_ids;
        }

        $result = $this->http_post( "{$instance_url}/api/v1/statuses", [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $toot_id  = $result['id'] ?? '';
        $toot_url = $result['url'] ?? '';
        $this->record_stat( $post->ID, $toot_id, $toot_url );

        return [ 'remote_id' => $toot_id, 'remote_url' => $toot_url, 'platform' => 'mastodon' ];
    }

    private function upload_media( string $instance_url, string $token, string $path ): array|\WP_Error {
        $boundary = wp_generate_uuid4();
        $data     = file_get_contents( $path ); // phpcs:ignore
        $mime     = mime_content_type( $path );
        $filename = basename( $path );

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n";
        $body .= "Content-Type: {$mime}\r\n\r\n";
        $body .= $data . "\r\n";
        $body .= "--{$boundary}--\r\n";

        return $this->http_post( "{$instance_url}/api/v2/media", [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => "multipart/form-data; boundary={$boundary}",
            ],
            'body' => $body,
        ] );
    }
}
