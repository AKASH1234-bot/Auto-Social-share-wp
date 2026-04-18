<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap wpap-wrap">
    <div class="wpap-header">
        <div class="wpap-header-inner">
            <h1>📜 <?php esc_html_e( 'Activity Logs', 'wp-autopilot' ); ?></h1>
            <div class="wpap-header-actions">
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e( 'Clear all logs?', 'wp-autopilot' ); ?>')">
                    <?php wp_nonce_field( 'wpap_clear_logs' ); ?>
                    <input type="hidden" name="action" value="wpap_clear_logs">
                    <button type="submit" class="button button-secondary">🗑 <?php esc_html_e( 'Clear Logs', 'wp-autopilot' ); ?></button>
                </form>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="wpap-filters">
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wpap-logs' ) ); ?>" class="button <?php echo empty( $_GET['level'] ) ? 'button-primary' : ''; ?>">
            <?php esc_html_e( 'All', 'wp-autopilot' ); ?> (<?php echo esc_html( number_format( $total ) ); ?>)
        </a>
        <?php foreach ( [ 'success', 'info', 'warning', 'error' ] as $lv ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'level', $lv ) ); ?>" class="button <?php echo ( ( $_GET['level'] ?? '' ) === $lv ) ? 'button-primary' : ''; ?>">
                <?php echo esc_html( ucfirst( $lv ) ); ?>
            </a>
        <?php endforeach; ?>
        <?php if ( ! empty( $platforms ) ) : ?>
            <select onchange="window.location=this.value">
                <option value="<?php echo esc_url( remove_query_arg( 'platform' ) ); ?>"><?php esc_html_e( 'All Platforms', 'wp-autopilot' ); ?></option>
                <?php foreach ( $platforms as $p ) : ?>
                    <option value="<?php echo esc_url( add_query_arg( 'platform', $p ) ); ?>" <?php selected( ( $_GET['platform'] ?? '' ), $p ); ?>>
                        <?php echo esc_html( ucfirst( $p ) ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>

    <div class="wpap-card">
        <?php if ( empty( $logs ) ) : ?>
            <p class="wpap-empty" style="padding:20px;"><?php esc_html_e( 'No logs found.', 'wp-autopilot' ); ?></p>
        <?php else : ?>
            <table class="wpap-table widefat">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Time', 'wp-autopilot' ); ?></th>
                        <th><?php esc_html_e( 'Level', 'wp-autopilot' ); ?></th>
                        <th><?php esc_html_e( 'Platform', 'wp-autopilot' ); ?></th>
                        <th><?php esc_html_e( 'Post', 'wp-autopilot' ); ?></th>
                        <th><?php esc_html_e( 'Message', 'wp-autopilot' ); ?></th>
                        <th><?php esc_html_e( 'Context', 'wp-autopilot' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $logs as $log ) :
                        $post_title = $log->post_id ? get_the_title( (int) $log->post_id ) : '—';
                    ?>
                        <tr class="wpap-log-row wpap-log-<?php echo esc_attr( $log->level ); ?>">
                            <td><small><?php echo esc_html( wp_date( 'M j, H:i:s', strtotime( $log->created_at ) ) ); ?></small></td>
                            <td>
                                <span class="wpap-level-badge wpap-level-<?php echo esc_attr( $log->level ); ?>">
                                    <?php echo esc_html( strtoupper( $log->level ) ); ?>
                                </span>
                            </td>
                            <td><?php echo $log->platform ? '<span class="wpap-platform-badge wpap-badge-' . esc_attr( $log->platform ) . '">' . esc_html( ucfirst( $log->platform ) ) . '</span>' : '—'; ?></td>
                            <td>
                                <?php if ( $log->post_id ) : ?>
                                    <a href="<?php echo esc_url( get_edit_post_link( (int) $log->post_id ) ); ?>" target="_blank">
                                        <?php echo esc_html( wp_trim_words( $post_title, 5 ) ); ?>
                                    </a>
                                <?php else : echo '—'; endif; ?>
                            </td>
                            <td><?php echo esc_html( $log->message ); ?></td>
                            <td>
                                <?php if ( $log->context ) : ?>
                                    <button class="button button-small wpap-toggle-context" data-id="<?php echo esc_attr( $log->id ); ?>">+</button>
                                    <pre class="wpap-log-context" id="ctx-<?php echo esc_attr( $log->id ); ?>" style="display:none;font-size:11px;max-height:150px;overflow:auto;"><?php echo esc_html( json_encode( json_decode( $log->context ), JSON_PRETTY_PRINT ) ); ?></pre>
                                <?php else : echo '—'; endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php
            $total_pages = ceil( $total / $per_page );
            if ( $total_pages > 1 ) :
                $base = add_query_arg( 'paged', '%#%' );
                echo wp_kses_post( paginate_links( [
                    'base'    => $base,
                    'format'  => '',
                    'current' => $page,
                    'total'   => $total_pages,
                ] ) );
            endif;
            ?>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelectorAll('.wpap-toggle-context').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var id = this.dataset.id;
        var ctx = document.getElementById('ctx-' + id);
        if (ctx.style.display === 'none') {
            ctx.style.display = 'block';
            this.textContent = '-';
        } else {
            ctx.style.display = 'none';
            this.textContent = '+';
        }
    });
});
</script>
