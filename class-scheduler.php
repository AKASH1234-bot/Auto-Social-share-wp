<?php
namespace WPAutoPilot;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the WP-Cron schedule and queue insertion.
 */
class Scheduler {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function register_hooks(): void {
        add_filter( 'cron_schedules', [ $this, 'add_cron_intervals' ] );
        add_action( 'wpap_process_queue', [ QueueProcessor::class, 'run' ] );
    }

    public function add_cron_intervals( array $schedules ): array {
        $schedules['wpap_every_minute'] = [
            'interval' => 60,
            'display'  => __( 'Every Minute (WP AutoPilot)', 'wp-autopilot' ),
        ];
        $schedules['wpap_every_5min'] = [
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes (WP AutoPilot)', 'wp-autopilot' ),
        ];
        return $schedules;
    }

    public function schedule_cron(): void {
        if ( ! wp_next_scheduled( 'wpap_process_queue' ) ) {
            wp_schedule_event( time(), 'wpap_every_minute', 'wpap_process_queue' );
        }
    }

    public function unschedule_cron(): void {
        $timestamp = wp_next_scheduled( 'wpap_process_queue' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'wpap_process_queue' );
        }
    }

    /**
     * Insert a job into the queue table.
     *
     * @param array $data {
     *   post_id, platform, account_id, scheduled_at, payload
     * }
     */
    public function enqueue( array $data ): int|false {
        global $wpdb;

        $insert = [
            'post_id'      => absint( $data['post_id'] ),
            'platform'     => sanitize_key( $data['platform'] ),
            'account_id'   => sanitize_text_field( $data['account_id'] ?? 'default' ),
            'status'       => 'pending',
            'payload'      => $data['payload'] ?? '',
            'attempts'     => 0,
            'scheduled_at' => $data['scheduled_at'] ?? current_time( 'mysql', true ),
        ];

        $result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . WPAP_QUEUE_TABLE,
            $insert,
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Fetch pending jobs that are due for processing.
     *
     * @return array<object>
     */
    public function get_due_jobs( int $limit = 20 ): array {
        global $wpdb;
        $table = $wpdb->prefix . WPAP_QUEUE_TABLE;
        $now   = current_time( 'mysql', true );

        return $wpdb->get_results( // phpcs:ignore
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE status = 'pending'
                   AND scheduled_at <= %s
                 ORDER BY scheduled_at ASC
                 LIMIT %d",
                $now,
                $limit
            )
        );
    }

    /**
     * Update a queue job's status.
     */
    public function update_job( int $id, array $data ): bool {
        global $wpdb;
        $result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->prefix . WPAP_QUEUE_TABLE,
            $data,
            [ 'id' => $id ],
            null,
            [ '%d' ]
        );
        return false !== $result;
    }
}
