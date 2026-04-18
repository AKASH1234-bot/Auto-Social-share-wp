<?php
/**
 * Plugin Name:       WP AutoPilot — Social Autoposter
 * Plugin URI:        https://github.com/your-org/wp-autopilot
 * Description:       Production-grade Zapier-style auto-distribution of WordPress posts to Twitter/X, Facebook, Instagram, LinkedIn, Reddit, Pinterest, Medium, Tumblr, Discord, Mastodon — with AI content generation, queue scheduling, and smart analytics.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            WP AutoPilot Team
 * License:           GPL v2 or later
 * Text Domain:       wp-autopilot
 * Domain Path:       /languages
 *
 * @package WPAutoPilot
 */

defined( 'ABSPATH' ) || exit;

define( 'WPAP_VERSION',     '2.0.0' );
define( 'WPAP_FILE',        __FILE__ );
define( 'WPAP_DIR',         plugin_dir_path( __FILE__ ) );
define( 'WPAP_URL',         plugin_dir_url( __FILE__ ) );
define( 'WPAP_BASENAME',    plugin_basename( __FILE__ ) );
define( 'WPAP_DB_VERSION',  '1.3' );
define( 'WPAP_LOG_TABLE',   'wpap_logs' );
define( 'WPAP_QUEUE_TABLE', 'wpap_queue' );
define( 'WPAP_STATS_TABLE', 'wpap_stats' );

/**
 * Explicit file loader — more reliable than autoloading for plugins.
 */
function wpap_load_files(): void {
    $files = [
        // Utils (no internal deps)
        WPAP_DIR . 'utils/class-utilities.php',

        // Core infrastructure
        WPAP_DIR . 'includes/class-installer.php',
        WPAP_DIR . 'includes/class-post-handler.php',
        WPAP_DIR . 'scheduler/class-scheduler.php',
        WPAP_DIR . 'scheduler/class-queue-processor.php',

        // Services
        WPAP_DIR . 'services/class-abstract-service.php',
        WPAP_DIR . 'services/class-ai-engine.php',
        WPAP_DIR . 'services/class-twitter-service.php',
        WPAP_DIR . 'services/class-social-services.php',
        WPAP_DIR . 'services/class-extended-services.php',
        WPAP_DIR . 'services/class-reddit-service.php',
        WPAP_DIR . 'services/class-quora-helper.php',

        // Factory (after services)
        WPAP_DIR . 'includes/class-service-factory.php',

        // Admin
        WPAP_DIR . 'admin/class-analytics.php',
        WPAP_DIR . 'admin/class-log-viewer.php',
        WPAP_DIR . 'admin/class-settings.php',
        WPAP_DIR . 'admin/class-dashboard.php',

        // Core (orchestrates all)
        WPAP_DIR . 'includes/class-core.php',
    ];

    foreach ( $files as $file ) {
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}

wpap_load_files();

register_activation_hook( WPAP_FILE, [ 'WPAutoPilot\\Installer', 'activate' ] );
register_deactivation_hook( WPAP_FILE, [ 'WPAutoPilot\\Installer', 'deactivate' ] );
register_uninstall_hook( WPAP_FILE, [ 'WPAutoPilot\\Installer', 'uninstall' ] );

add_action( 'plugins_loaded', function () {
    if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
        add_action( 'admin_notices', fn() => printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__( 'WP AutoPilot requires PHP 8.1+.', 'wp-autopilot' )
        ) );
        return;
    }
    WPAutoPilot\Core::instance()->boot();
} );
