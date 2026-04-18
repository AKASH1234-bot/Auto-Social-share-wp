<?php
// Queue View
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap wpap-wrap">
    <div class="wpap-header">
        <div class="wpap-header-inner">
            <h1>📋 <?php esc_html_e( 'Distribution Queue', 'wp-autopilot' ); ?></h1>
            <div class="wpap-header-actions">
                <select id="wpap-queue-filter">
                    <option value=""><?php esc_html_e( 'All Statuses', 'wp-autopilot' ); ?></option>
                    <option value="pending"><?php esc_html_e( 'Pending', 'wp-autopilot' ); ?></option>
                    <option value="processing"><?php esc_html_e( 'Processing', 'wp-autopilot' ); ?></option>
                    <option value="done"><?php esc_html_e( 'Done', 'wp-autopilot' ); ?></option>
                    <option value="failed"><?php esc_html_e( 'Failed', 'wp-autopilot' ); ?></option>
                </select>
                <button class="button" id="wpap-refresh-queue">🔄 <?php esc_html_e( 'Refresh', 'wp-autopilot' ); ?></button>
            </div>
        </div>
    </div>

    <div class="wpap-card">
        <div id="wpap-queue-table-wrap">
            <table class="wpap-table widefat" id="wpap-queue-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th><?php esc_html_e( 'Post', 'wp-autopilot' ); ?></th>
                        <th><?php esc_html_e( 'Platform', 'wp-autopilot' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'wp-autopilot' ); ?></th>
                        <th><?php esc_html_e( 'Scheduled', 'wp-autopilot' ); ?></th>
                        <th><?php esc_html_e( 'Attempts', 'wp-autopilot' ); ?></th>
                        <th><?php esc_html_e( 'Error', 'wp-autopilot' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wp-autopilot' ); ?></th>
                    </tr>
                </thead>
                <tbody id="wpap-queue-tbody">
                    <tr><td colspan="8" style="text-align:center;padding:20px;">
                        <span class="spinner is-active" style="float:none;"></span>
                        <?php esc_html_e( 'Loading...', 'wp-autopilot' ); ?>
                    </td></tr>
                </tbody>
            </table>
            <div id="wpap-queue-pagination" class="wpap-pagination"></div>
        </div>
    </div>
</div>
