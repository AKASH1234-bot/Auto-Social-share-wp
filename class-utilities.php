<?php
namespace WPAutoPilot;

defined( 'ABSPATH' ) || exit;

// ═══════════════════════════════════════════════════════════════════════════
// Logger
// ═══════════════════════════════════════════════════════════════════════════

class Logger {

    public static function info( string $message, array $context = [] ): void {
        self::write( 'info', $message, $context );
    }

    public static function success( string $message, array $context = [] ): void {
        self::write( 'success', $message, $context );
    }

    public static function warning( string $message, array $context = [] ): void {
        self::write( 'warning', $message, $context );
    }

    public static function error( string $message, array $context = [] ): void {
        self::write( 'error', $message, $context );
    }

    private static function write( string $level, string $message, array $context ): void {
        global $wpdb;

        $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . WPAP_LOG_TABLE,
            [
                'queue_id'   => $context['queue_id'] ?? null,
                'post_id'    => $context['post_id'] ?? null,
                'platform'   => $context['platform'] ?? null,
                'level'      => $level,
                'message'    => substr( $message, 0, 500 ),
                'context'    => ! empty( $context ) ? wp_json_encode( $context ) : null,
                'created_at' => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        // Also write to PHP error log in debug mode.
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( sprintf( '[WPAP:%s] %s | %s', strtoupper( $level ), $message, wp_json_encode( $context ) ) ); // phpcs:ignore
        }
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// EncryptionHelper — AES-256-CBC via WordPress AUTH_KEY salts
// ═══════════════════════════════════════════════════════════════════════════

class EncryptionHelper {

    private static function get_key(): string {
        // Derive from WordPress secret keys so tokens are site-specific.
        return hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY . LOGGED_IN_KEY, true );
    }

    public static function encrypt( string $plaintext ): string {
        if ( empty( $plaintext ) ) {
            return '';
        }
        $iv         = random_bytes( 16 );
        $ciphertext = openssl_encrypt( $plaintext, 'AES-256-CBC', self::get_key(), OPENSSL_RAW_DATA, $iv );
        if ( false === $ciphertext ) {
            return $plaintext; // Fallback — openssl not available.
        }
        return base64_encode( $iv . $ciphertext );
    }

    public static function decrypt( string $encoded ): string {
        if ( empty( $encoded ) ) {
            return '';
        }
        $raw = base64_decode( $encoded, true );
        if ( ! $raw || strlen( $raw ) < 17 ) {
            return $encoded; // Already plaintext (legacy/migrating).
        }
        $iv         = substr( $raw, 0, 16 );
        $ciphertext = substr( $raw, 16 );
        $plaintext  = openssl_decrypt( $ciphertext, 'AES-256-CBC', self::get_key(), OPENSSL_RAW_DATA, $iv );
        return ( false === $plaintext ) ? $encoded : $plaintext;
    }

    /**
     * Safely decrypt if the value looks encrypted (base64), otherwise return as-is.
     * Handles legacy plaintext tokens stored before encryption was added.
     */
    public static function maybe_decrypt( string $value ): string {
        if ( empty( $value ) ) {
            return '';
        }
        $decoded = base64_decode( $value, true );
        if ( $decoded && strlen( $decoded ) >= 17 ) {
            $result = self::decrypt( $value );
            if ( $result !== $value ) {
                return $result;
            }
        }
        return $value;
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// UrlShortener — Bit.ly / YOURLS / none
// ═══════════════════════════════════════════════════════════════════════════

class UrlShortener {

    public static function shorten( string $url ): ?string {
        $general  = get_option( 'wpap_general', [] );
        $provider = $general['url_shortener'] ?? 'none';

        switch ( $provider ) {
            case 'bitly':
                return self::bitly( $url, $general['bitly_token'] ?? '' );
            case 'yourls':
                return self::yourls( $url, $general['yourls_url'] ?? '', $general['yourls_token'] ?? '' );
            default:
                return null;
        }
    }

    private static function bitly( string $url, string $token ): ?string {
        if ( ! $token ) {
            return null;
        }
        $cache_key = 'wpap_short_' . md5( $url );
        $cached    = get_transient( $cache_key );
        if ( $cached ) {
            return $cached;
        }

        $response = wp_remote_post( 'https://api-ssl.bitly.com/v4/shorten', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( [ 'long_url' => $url ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $data  = json_decode( wp_remote_retrieve_body( $response ), true );
        $short = $data['link'] ?? null;

        if ( $short ) {
            set_transient( $cache_key, $short, WEEK_IN_SECONDS );
        }

        return $short;
    }

    private static function yourls( string $url, string $yourls_url, string $token ): ?string {
        if ( ! $yourls_url || ! $token ) {
            return null;
        }

        $response = wp_remote_get( add_query_arg( [
            'signature' => $token,
            'action'    => 'shorturl',
            'url'       => rawurlencode( $url ),
            'format'    => 'json',
        ], rtrim( $yourls_url, '/' ) . '/yourls-api.php' ) );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['shorturl'] ?? null;
    }
}


// ═══════════════════════════════════════════════════════════════════════════
// MediaHandler — image resizing for platform requirements
// ═══════════════════════════════════════════════════════════════════════════

class MediaHandler {

    // Platform image requirements (width, height).
    private const PLATFORM_DIMS = [
        'twitter'   => [ 1200, 675 ],   // 16:9
        'facebook'  => [ 1200, 630 ],
        'instagram' => [ 1080, 1080 ],  // Square
        'linkedin'  => [ 1200, 627 ],
        'pinterest' => [ 1000, 1500 ],  // Portrait
        'default'   => [ 1200, 630 ],
    ];

    /**
     * Get or generate a platform-optimized image path.
     *
     * @return string|null Local file path or null if unavailable.
     */
    public static function get_platform_image( int $post_id, string $platform ): ?string {
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( ! $thumb_id ) {
            return self::generate_fallback( $post_id );
        }

        [$width, $height] = self::PLATFORM_DIMS[ $platform ] ?? self::PLATFORM_DIMS['default'];

        // Check if a resized version already exists.
        $cache_key = "wpap_img_{$thumb_id}_{$platform}";
        $cached    = get_transient( $cache_key );
        if ( $cached && file_exists( $cached ) ) {
            return $cached;
        }

        $original = get_attached_file( $thumb_id );
        if ( ! $original || ! file_exists( $original ) ) {
            return null;
        }

        $resized = self::resize( $original, $width, $height );
        if ( $resized ) {
            set_transient( $cache_key, $resized, WEEK_IN_SECONDS );
        }

        return $resized ?: $original;
    }

    private static function resize( string $file, int $width, int $height ): ?string {
        $editor = wp_get_image_editor( $file );
        if ( is_wp_error( $editor ) ) {
            return null;
        }

        $editor->resize( $width, $height, true );
        $info   = pathinfo( $file );
        $suffix = "{$width}x{$height}";
        $dest   = "{$info['dirname']}/{$info['filename']}-wpap-{$suffix}.{$info['extension']}";

        $saved = $editor->save( $dest );
        if ( is_wp_error( $saved ) ) {
            return null;
        }

        return $saved['path'] ?? null;
    }

    /**
     * Generate a simple placeholder image using GD if no featured image exists.
     */
    private static function generate_fallback( int $post_id ): ?string {
        if ( ! function_exists( 'imagecreatetruecolor' ) ) {
            return null;
        }

        $upload_dir = wp_upload_dir();
        $path       = $upload_dir['basedir'] . '/wpap-fallback-' . $post_id . '.jpg';

        if ( file_exists( $path ) ) {
            return $path;
        }

        $img    = imagecreatetruecolor( 1200, 630 );
        $bg     = imagecolorallocate( $img, 30, 30, 46 );
        $text_c = imagecolorallocate( $img, 255, 255, 255 );
        imagefill( $img, 0, 0, $bg );

        $title = wp_strip_all_tags( get_the_title( $post_id ) );
        $title = substr( $title, 0, 60 );
        imagestring( $img, 5, 50, 290, $title, $text_c );

        imagejpeg( $img, $path, 90 );
        imagedestroy( $img );

        return file_exists( $path ) ? $path : null;
    }
}
