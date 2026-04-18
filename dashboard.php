<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap wpap-wrap">
    <div class="wpap-header">
        <div class="wpap-header-inner">
            <div class="wpap-logo">
                <span class="wpap-logo-icon">🚀</span>
                <div>
                    <h1><?php esc_html_e( 'WP AutoPilot', 'wp-autopilot' ); ?></h1>
                    <span class="wpap-version">v<?php echo esc_html( WPAP_VERSION ); ?></span>
                </div>
            </div>
            <div class="wpap-header-actions">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpap-settings' ) ); ?>" class="button button-secondary">
                    ⚙️ <?php esc_html_e( 'Settings', 'wp-autopilot' ); ?>
                </a>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="wpap-stats-grid">
        <div class="wpap-stat-card wpap-stat-success">
            <div class="wpap-stat-icon">✅</div>
            <div class="wpap-stat-value"><?php echo esc_html( number_format( $stats['done'] ) ); ?></div>
            <div class="wpap-stat-label"><?php esc_html_e( 'Published', 'wp-autopilot' ); ?></div>
        </div>
        <div class="wpap-stat-card wpap-stat-pending">
            <div class="wpap-stat-icon">⏳</div>
            <div class="wpap-stat-value"><?php echo esc_html( number_format( $stats['pending'] ) ); ?></div>
            <div class="wpap-stat-label"><?php esc_html_e( 'Pending', 'wp-autopilot' ); ?></div>
        </div>
        <div class="wpap-stat-card wpap-stat-failed">
            <div class="wpap-stat-icon">❌</div>
            <div class="wpap-stat-value"><?php echo esc_html( number_format( $stats['failed'] ) ); ?></div>
            <div class="wpap-stat-label"><?php esc_html_e( 'Failed', 'wp-autopilot' ); ?></div>
        </div>
        <div class="wpap-stat-card wpap-stat-posts">
            <div class="wpap-stat-icon">📄</div>
            <div class="wpap-stat-value"><?php echo esc_html( number_format( $stats['total_posts'] ) ); ?></div>
            <div class="wpap-stat-label"><?php esc_html_e( 'Posts Distributed', 'wp-autopilot' ); ?></div>
        </div>
    </div>

    <div class="wpap-two-col">
        <!-- Platform Performance -->
        <div class="wpap-card">
            <div class="wpap-card-header">
                <h2><?php esc_html_e( 'Platform Performance', 'wp-autopilot' ); ?></h2>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpap-analytics' ) ); ?>" class="wpap-link"><?php esc_html_e( 'Full Analytics →', 'wp-autopilot' ); ?></a>
            </div>
            <div class="wpap-card-body">
                <?php if ( empty( $platform_stats ) ) : ?>
                    <p class="wpap-empty"><?php esc_html_e( 'No data yet. Publish a post to get started.', 'wp-autopilot' ); ?></p>
                <?php else : ?>
                    <table class="wpap-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Platform', 'wp-autopilot' ); ?></th>
                                <th><?php esc_html_e( 'Total', 'wp-autopilot' ); ?></th>
                                <th><?php esc_html_e( 'Success', 'wp-autopilot' ); ?></th>
                                <th><?php esc_html_e( 'Rate', 'wp-autopilot' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $platform_stats as $ps ) :
                                $rate = $ps->total > 0 ? round( $ps->success / $ps->total * 100 ) : 0;
                            ?>
                                <tr>
                                    <td><span class="wpap-platform-badge wpap-badge-<?php echo esc_attr( $ps->platform ); ?>"><?php echo esc_html( ucfirst( $ps->platform ) ); ?></span></td>
                                    <td><?php echo esc_html( $ps->total ); ?></td>
                                    <td><?php echo esc_html( $ps->success ); ?></td>
                                    <td>
                                        <div class="wpap-progress-bar">
                                            <div class="wpap-progress-fill" style="width:<?php echo esc_attr( $rate ); ?>%"></div>
                                            <span><?php echo esc_html( $rate ); ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="wpap-card">
            <div class="wpap-card-header">
                <h2><?php esc_html_e( 'Recent Activity', 'wp-autopilot' ); ?></h2>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpap-logs' ) ); ?>" class="wpap-link"><?php esc_html_e( 'View All Logs →', 'wp-autopilot' ); ?></a>
            </div>
            <div class="wpap-card-body">
                <?php if ( empty( $recent_logs ) ) : ?>
                    <p class="wpap-empty"><?php esc_html_e( 'No activity yet.', 'wp-autopilot' ); ?></p>
                <?php else : ?>
                    <ul class="wpap-activity-list">
                        <?php foreach ( $recent_logs as $log ) : ?>
                            <li class="wpap-activity-item wpap-log-<?php echo esc_attr( $log->level ); ?>">
                                <span class="wpap-log-icon"><?php echo esc_html( match( $log->level ) {
                                    'success' => '✅',
                                    'error'   => '❌',
                                    'warning' => '⚠️',
                                    default   => 'ℹ️'
                                } ); ?></span>
                                <div class="wpap-activity-content">
                                    <p><?php echo esc_html( $log->message ); ?></p>
                                    <small><?php echo esc_html( human_time_diff( strtotime( $log->created_at ), time() ) . ' ago' ); ?></small>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Connected Platforms -->
    <?php
    $platform_config = get_option( 'wpap_platforms', [] );
    $all_platforms = [
        'twitter'   => [ 'label' => 'Twitter / X',    'icon' => '𝕏',  'color' => '#000' ],
        'facebook'  => [ 'label' => 'Facebook',        'icon' => '📘', 'color' => '#1877F2' ],
        'instagram' => [ 'label' => 'Instagram',       'icon' => '📷', 'color' => '#E1306C' ],
        'linkedin'  => [ 'label' => 'LinkedIn',        'icon' => '💼', 'color' => '#0A66C2' ],
        'reddit'    => [ 'label' => 'Reddit',          'icon' => '🤖', 'color' => '#FF4500' ],
        'pinterest' => [ 'label' => 'Pinterest',       'icon' => '📌', 'color' => '#E60023' ],
        'medium'    => [ 'label' => 'Medium',          'icon' => 'M',  'color' => '#000' ],
        'tumblr'    => [ 'label' => 'Tumblr',          'icon' => '📝', 'color' => '#35465C' ],
        'discord'   => [ 'label' => 'Discord',         'icon' => '💬', 'color' => '#5865F2' ],
        'mastodon'  => [ 'label' => 'Mastodon',        'icon' => '🐘', 'color' => '#6364FF' ],
    ];
    ?>
    <div class="wpap-card wpap-platforms-overview">
        <div class="wpap-card-header">
            <h2><?php esc_html_e( 'Platform Status', 'wp-autopilot' ); ?></h2>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpap-settings' ) ); ?>" class="wpap-link"><?php esc_html_e( 'Manage →', 'wp-autopilot' ); ?></a>
        </div>
        <div class="wpap-platforms-grid">
            <?php foreach ( $all_platforms as $slug => $info ) :
                $cfg       = $platform_config[ $slug ] ?? [];
                $enabled   = ! empty( $cfg['enabled'] );
                $has_token = ! empty( $cfg['accounts']['default']['access_token'] ) || ! empty( $cfg['accounts']['default']['webhook_url'] );
            ?>
                <div class="wpap-platform-tile <?php echo $enabled ? 'wpap-tile-active' : 'wpap-tile-inactive'; ?>">
                    <div class="wpap-tile-icon" style="background:<?php echo esc_attr( $info['color'] ); ?>">
                        <?php echo esc_html( $info['icon'] ); ?>
                    </div>
                    <div class="wpap-tile-info">
                        <strong><?php echo esc_html( $info['label'] ); ?></strong>
                        <span class="wpap-status-badge <?php echo $enabled ? 'wpap-badge-active' : 'wpap-badge-inactive'; ?>">
                            <?php echo $enabled ? esc_html__( 'Active', 'wp-autopilot' ) : esc_html__( 'Off', 'wp-autopilot' ); ?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
