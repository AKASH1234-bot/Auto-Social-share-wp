<?php
namespace WPAutoPilot;

use WPAutoPilot\Services\{
    TwitterService, FacebookService, InstagramService,
    LinkedInService, RedditService, PinterestService,
    MediumService, TumblrService, DiscordService, MastodonService
};

defined( 'ABSPATH' ) || exit;

/**
 * Factory to resolve platform name → service instance.
 */
class ServiceFactory {

    private static array $registry = [
        'twitter'   => TwitterService::class,
        'x'         => TwitterService::class,
        'facebook'  => FacebookService::class,
        'instagram' => InstagramService::class,
        'linkedin'  => LinkedInService::class,
        'reddit'    => RedditService::class,
        'pinterest' => PinterestService::class,
        'medium'    => MediumService::class,
        'tumblr'    => TumblrService::class,
        'discord'   => DiscordService::class,
        'mastodon'  => MastodonService::class,
    ];

    /**
     * Create and return a service instance for the given platform.
     *
     * @param string $platform   Platform slug.
     * @param string $account_id Account identifier (for multi-account).
     * @return object|null
     */
    public static function make( string $platform, string $account_id = 'default' ): ?object {
        $platform = strtolower( trim( $platform ) );
        $class    = self::$registry[ $platform ] ?? null;

        if ( ! $class ) {
            return null;
        }

        $config = self::get_account_config( $platform, $account_id );

        /** @var object $service */
        $service = new $class( $config, $account_id );
        return $service;
    }

    /**
     * Load decrypted credentials for a specific account.
     */
    private static function get_account_config( string $platform, string $account_id ): array {
        $all_platforms = get_option( 'wpap_platforms', [] );
        $platform_data = $all_platforms[ $platform ] ?? [];
        $accounts      = $platform_data['accounts'] ?? [ 'default' => $platform_data ];
        $raw_config    = $accounts[ $account_id ] ?? [];

        // Decrypt sensitive fields.
        $sensitive = [ 'api_key', 'api_secret', 'access_token', 'access_secret',
                        'client_id', 'client_secret', 'bearer_token', 'webhook_url',
                        'refresh_token', 'password' ];

        foreach ( $sensitive as $key ) {
            if ( isset( $raw_config[ $key ] ) ) {
                $raw_config[ $key ] = EncryptionHelper::decrypt( $raw_config[ $key ] );
            }
        }

        return $raw_config;
    }

    /**
     * Register a custom platform service.
     */
    public static function register( string $platform, string $class ): void {
        self::$registry[ $platform ] = $class;
    }

    public static function supported_platforms(): array {
        return array_unique( array_keys( self::$registry ) );
    }
}
