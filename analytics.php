<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap wpap-wrap">
    <div class="wpap-header">
        <div class="wpap-header-inner">
            <h1>📊 <?php esc_html_e( 'Analytics', 'wp-autopilot' ); ?></h1>
        </div>
    </div>

    <!-- Summary -->
    <div class="wpap-stats-grid">
        <div class="wpap-stat-card wpap-stat-success">
            <div class="wpap-stat-icon">✅</div>
            <div class="wpap-stat-value"><?php echo esc_html( number_format( $data['summary']['total_published'] ) ); ?></div>
            <div class="wpap-stat-label"><?php esc_html_e( 'Total Published', 'wp-autopilot' ); ?></div>
        </div>
        <div class="wpap-stat-card wpap-stat-failed">
            <div class="wpap-stat-icon">❌</div>
            <div class="wpap-stat-value"><?php echo esc_html( number_format( $data['summary']['total_failed'] ) ); ?></div>
            <div class="wpap-stat-label"><?php esc_html_e( 'Failed', 'wp-autopilot' ); ?></div>
        </div>
        <div class="wpap-stat-card wpap-stat-pending">
            <div class="wpap-stat-icon">⏳</div>
            <div class="wpap-stat-value"><?php echo esc_html( number_format( $data['summary']['total_pending'] ) ); ?></div>
            <div class="wpap-stat-label"><?php esc_html_e( 'Pending', 'wp-autopilot' ); ?></div>
        </div>
        <div class="wpap-stat-card wpap-stat-posts">
            <div class="wpap-stat-icon">📄</div>
            <div class="wpap-stat-value"><?php echo esc_html( number_format( $data['summary']['total_posts'] ) ); ?></div>
            <div class="wpap-stat-label"><?php esc_html_e( 'Posts Distributed', 'wp-autopilot' ); ?></div>
        </div>
    </div>

    <div class="wpap-two-col">
        <!-- Platform Success Rates -->
        <div class="wpap-card">
            <div class="wpap-card-header"><h2><?php esc_html_e( 'Platform Success Rates', 'wp-autopilot' ); ?></h2></div>
            <div class="wpap-card-body">
                <?php if ( empty( $data['platform_rates'] ) ) : ?>
                    <p class="wpap-empty"><?php esc_html_e( 'No data yet.', 'wp-autopilot' ); ?></p>
                <?php else : ?>
                    <table class="wpap-table">
                        <thead><tr><th><?php esc_html_e( 'Platform', 'wp-autopilot' ); ?></th><th><?php esc_html_e( 'Success', 'wp-autopilot' ); ?></th><th><?php esc_html_e( 'Failed', 'wp-autopilot' ); ?></th><th><?php esc_html_e( 'Rate', 'wp-autopilot' ); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ( $data['platform_rates'] as $pr ) : ?>
                                <tr>
                                    <td><span class="wpap-platform-badge wpap-badge-<?php echo esc_attr( $pr['platform'] ); ?>"><?php echo esc_html( ucfirst( $pr['platform'] ) ); ?></span></td>
                                    <td><?php echo esc_html( $pr['success'] ); ?></td>
                                    <td><?php echo esc_html( $pr['failed'] ); ?></td>
                                    <td>
                                        <div class="wpap-progress-bar">
                                            <div class="wpap-progress-fill" style="width:<?php echo esc_attr( $pr['rate'] ); ?>%"></div>
                                            <span><?php echo esc_html( $pr['rate'] ); ?>%</span>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- Top Posts -->
        <div class="wpap-card">
            <div class="wpap-card-header"><h2><?php esc_html_e( 'Top Distributed Posts', 'wp-autopilot' ); ?></h2></div>
            <div class="wpap-card-body">
                <?php if ( empty( $data['top_posts'] ) ) : ?>
                    <p class="wpap-empty"><?php esc_html_e( 'No data yet.', 'wp-autopilot' ); ?></p>
                <?php else : ?>
                    <table class="wpap-table">
                        <thead><tr><th><?php esc_html_e( 'Post', 'wp-autopilot' ); ?></th><th><?php esc_html_e( 'Platforms', 'wp-autopilot' ); ?></th></tr></thead>
                        <tbody>
                            <?php foreach ( $data['top_posts'] as $tp ) : ?>
                                <tr>
                                    <td><a href="<?php echo esc_url( get_permalink( (int) $tp['post_id'] ) ); ?>" target="_blank"><?php echo esc_html( wp_trim_words( $tp['post_title'] ?? '', 8 ) ); ?></a></td>
                                    <td><?php echo esc_html( $tp['platforms'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Daily Activity Chart -->
    <div class="wpap-card">
        <div class="wpap-card-header"><h2><?php esc_html_e( 'Daily Activity (Last 30 Days)', 'wp-autopilot' ); ?></h2></div>
        <div class="wpap-card-body">
            <canvas id="wpap-daily-chart" height="80"></canvas>
        </div>
    </div>
</div>

<script>
(function($) {
    var daily = <?php echo wp_json_encode( $data['daily'] ); ?>;
    var labels = daily.map(function(d){ return d.date; });
    var success = daily.map(function(d){ return parseInt(d.success); });
    var total = daily.map(function(d){ return parseInt(d.total); });

    var ctx = document.getElementById('wpap-daily-chart');
    if (ctx && typeof Chart !== 'undefined') {
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    { label: '<?php esc_html_e( 'Total', 'wp-autopilot' ); ?>', data: total, borderColor: '#6c757d', fill: false },
                    { label: '<?php esc_html_e( 'Success', 'wp-autopilot' ); ?>', data: success, borderColor: '#28a745', fill: true, backgroundColor: 'rgba(40,167,69,0.1)' }
                ]
            },
            options: { responsive: true, plugins: { legend: { position: 'top' } } }
        });
    }
})(jQuery);
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
