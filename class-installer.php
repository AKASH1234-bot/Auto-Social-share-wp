<?php
namespace WPAutoPilot;

defined( 'ABSPATH' ) || exit;

/**
 * Handles plugin installation, upgrade, and uninstall.
 */
class Installer {

    public static function activate(): void {
        self::upgrade_tables();
        self::set_default_options();
        Scheduler::instance()->schedule_cron();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        Scheduler::instance()->unschedule_cron();
        flush_rewrite_rules();
    }

    public static function uninstall(): void {
        if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
            return;
        }
        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery
        foreach ( [ WPAP_LOG_TABLE, WPAP_QUEUE_TABLE, WPAP_STATS_TABLE ] as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}{$table}" ); // phpcs:ignore
        }
        // Remove all plugin options.
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpap_%'" ); // phpcs:ignore
        // phpcs:enable
    }

    public static function upgrade_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        // --- Queue table ---
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}" . WPAP_QUEUE_TABLE . " (
                id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id      BIGINT(20) UNSIGNED NOT NULL,
                platform     VARCHAR(50) NOT NULL,
                account_id   VARCHAR(100) NOT NULL DEFAULT 'default',
                status       ENUM('pending','processing','done','failed','skipped') NOT NULL DEFAULT 'pending',
                payload      LONGTEXT,
                attempts     TINYINT UNSIGNED NOT NULL DEFAULT 0,
                scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                processed_at DATETIME NULL,
                error_msg    TEXT NULL,
                PRIMARY KEY (id),
                KEY idx_status_scheduled (status, scheduled_at),
                KEY idx_post_platform (post_id, platform)
            ) $charset;
        " );

        // --- Log table ---
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}" . WPAP_LOG_TABLE . " (
                id         BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                queue_id   BIGINT(20) UNSIGNED NULL,
                post_id    BIGINT(20) UNSIGNED NULL,
                platform   VARCHAR(50) NULL,
                level      ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
                message    TEXT NOT NULL,
                context    LONGTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_post_id (post_id),
                KEY idx_platform (platform),
                KEY idx_level (level),
                KEY idx_created (created_at)
            ) $charset;
        " );

        // --- Stats table ---
        dbDelta( "
            CREATE TABLE {$wpdb->prefix}" . WPAP_STATS_TABLE . " (
                id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id     BIGINT(20) UNSIGNED NOT NULL,
                platform    VARCHAR(50) NOT NULL,
                account_id  VARCHAR(100) NOT NULL DEFAULT 'default',
                remote_id   VARCHAR(255) NULL,
                remote_url  VARCHAR(2048) NULL,
                short_url   VARCHAR(512) NULL,
                clicks      INT UNSIGNED NOT NULL DEFAULT 0,
                shares      INT UNSIGNED NOT NULL DEFAULT 0,
                posted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_post_platform_account (post_id, platform, account_id),
                KEY idx_platform (platform)
            ) $charset;
        " );

        update_option( 'wpap_db_version', WPAP_DB_VERSION );
    }

    private static function set_default_options(): void {
        $defaults = [
            'wpap_general'  => [
                'post_types'         => [ 'post' ],
                'trigger_on_publish' => true,
                'trigger_on_update'  => false,
                'global_delay'       => 0,
                'platform_delay'     => 30,
                'max_attempts'       => 3,
                'retry_delay'        => 300,
                'url_shortener'      => 'none',
                'ai_provider'        => 'openai',
            ],
            'wpap_platforms' => [],
        ];
        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }
}
