<?php
namespace WPAutoPilot;

defined( 'ABSPATH' ) || exit;

/**
 * Pulls pending jobs from the queue and dispatches them.
 */
class QueueProcessor {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    /** Entry point for WP-Cron. */
    public static function run(): void {
        self::instance()->process_batch();
    }

    /**
     * Process a batch of due queue jobs.
     *
     * @return int Number of jobs processed.
     */
    public function process_batch( int $limit = 20 ): int {
        // Prevent overlapping cron runs using a transient lock.
        if ( get_transient( 'wpap_queue_lock' ) ) {
            return 0;
        }
        set_transient( 'wpap_queue_lock', 1, 90 );

        $jobs      = Scheduler::instance()->get_due_jobs( $limit );
        $general   = get_option( 'wpap_general', [] );
        $max_tries = (int) ( $general['max_attempts'] ?? 3 );
        $retry_sec = (int) ( $general['retry_delay'] ?? 300 );

        $processed = 0;

        foreach ( $jobs as $job ) {
            // Mark as processing to avoid double-pick.
            Scheduler::instance()->update_job( (int) $job->id, [
                'status'   => 'processing',
                'attempts' => (int) $job->attempts + 1,
            ] );

            try {
                $result = $this->dispatch( $job );

                if ( is_wp_error( $result ) ) {
                    throw new \RuntimeException( $result->get_error_message() );
                }

                Scheduler::instance()->update_job( (int) $job->id, [
                    'status'       => 'done',
                    'processed_at' => current_time( 'mysql', true ),
                    'error_msg'    => null,
                ] );

                Logger::success(
                    sprintf( '[%s] Post #%d shared successfully.', strtoupper( $job->platform ), $job->post_id ),
                    [ 'queue_id' => $job->id, 'result' => is_array( $result ) ? $result : [] ]
                );

            } catch ( \Throwable $e ) {
                $attempts = (int) $job->attempts + 1;

                if ( $attempts >= $max_tries ) {
                    $new_status = 'failed';
                    $next_run   = null;
                } else {
                    $new_status = 'pending';
                    $next_run   = gmdate( 'Y-m-d H:i:s', time() + $retry_sec * $attempts );
                }

                Scheduler::instance()->update_job( (int) $job->id, array_filter( [
                    'status'       => $new_status,
                    'attempts'     => $attempts,
                    'error_msg'    => substr( $e->getMessage(), 0, 500 ),
                    'processed_at' => current_time( 'mysql', true ),
                    'scheduled_at' => $next_run,
                ] ) );

                Logger::error(
                    sprintf( '[%s] Post #%d failed (attempt %d/%d): %s',
                        strtoupper( $job->platform ), $job->post_id, $attempts, $max_tries, $e->getMessage()
                    ),
                    [ 'queue_id' => $job->id, 'trace' => $e->getTraceAsString() ]
                );
            }

            $processed++;
        }

        delete_transient( 'wpap_queue_lock' );
        return $processed;
    }

    /**
     * Dispatch a single queue job to its platform service.
     *
     * @return array|\WP_Error
     */
    private function dispatch( object $job ): array|\WP_Error {
        $payload    = json_decode( $job->payload, true ) ?? [];
        $post       = get_post( (int) $job->post_id );

        if ( ! $post || 'publish' !== $post->post_status ) {
            return new \WP_Error( 'post_unavailable', 'Post not found or not published.' );
        }

        $service = ServiceFactory::make( $job->platform, $job->account_id );
        if ( ! $service ) {
            return new \WP_Error( 'no_service', "No service found for platform: {$job->platform}" );
        }

        // Let the AI engine generate platform-specific content.
        $content = AIEngine::instance()->generate_content( $post, $job->platform, $payload['cfg'] ?? [] );

        return $service->publish( $post, $content, $payload['cfg'] ?? [] );
    }
}
