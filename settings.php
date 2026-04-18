<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap wpap-wrap">
    <div class="wpap-header">
        <div class="wpap-header-inner">
            <h1>⚙️ <?php esc_html_e( 'WP AutoPilot Settings', 'wp-autopilot' ); ?></h1>
        </div>
    </div>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'wpap_save_settings' ); ?>
        <input type="hidden" name="action" value="wpap_save_settings">

        <div class="wpap-settings-layout">
            <!-- Left: Tabs Nav -->
            <div class="wpap-settings-nav">
                <ul class="wpap-tabs-nav">
                    <li><a href="#tab-general" class="wpap-tab-link active">🛠 <?php esc_html_e( 'General', 'wp-autopilot' ); ?></a></li>
                    <li><a href="#tab-ai" class="wpap-tab-link">🧠 <?php esc_html_e( 'AI Engine', 'wp-autopilot' ); ?></a></li>
                    <li class="wpap-nav-section"><?php esc_html_e( 'Platforms', 'wp-autopilot' ); ?></li>
                    <?php
                    $platform_defs = [
                        'twitter'   => '𝕏 Twitter / X',
                        'facebook'  => '📘 Facebook',
                        'instagram' => '📷 Instagram',
                        'linkedin'  => '💼 LinkedIn',
                        'reddit'    => '🤖 Reddit',
                        'pinterest' => '📌 Pinterest',
                        'medium'    => 'M Medium',
                        'tumblr'    => '📝 Tumblr',
                        'discord'   => '💬 Discord',
                        'mastodon'  => '🐘 Mastodon',
                        'quora'     => '🔵 Quora Helper',
                    ];
                    foreach ( $platform_defs as $slug => $label ) :
                        $active_class = ! empty( $platforms[ $slug ]['enabled'] ) ? 'wpap-nav-active' : '';
                    ?>
                        <li><a href="#tab-<?php echo esc_attr( $slug ); ?>" class="wpap-tab-link <?php echo esc_attr( $active_class ); ?>"><?php echo esc_html( $label ); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Right: Tab Content -->
            <div class="wpap-settings-content">

                <!-- General Tab -->
                <div id="tab-general" class="wpap-tab-content active">
                    <h2><?php esc_html_e( 'General Settings', 'wp-autopilot' ); ?></h2>

                    <table class="form-table wpap-form-table">
                        <tr>
                            <th><?php esc_html_e( 'Post Types to Share', 'wp-autopilot' ); ?></th>
                            <td>
                                <?php
                                $post_types    = get_post_types( [ 'public' => true ], 'objects' );
                                $selected_pts  = $general['post_types'] ?? [ 'post' ];
                                foreach ( $post_types as $pt ) : ?>
                                    <label style="display:block;margin-bottom:4px;">
                                        <input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>"
                                            <?php checked( in_array( $pt->name, $selected_pts, true ) ); ?>>
                                        <?php echo esc_html( $pt->labels->name ); ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Trigger On', 'wp-autopilot' ); ?></th>
                            <td>
                                <label><input type="checkbox" name="trigger_on_publish" value="1" <?php checked( $general['trigger_on_publish'] ?? true ); ?>>
                                    <?php esc_html_e( 'New Publish', 'wp-autopilot' ); ?></label><br>
                                <label><input type="checkbox" name="trigger_on_update" value="1" <?php checked( $general['trigger_on_update'] ?? false ); ?>>
                                    <?php esc_html_e( 'Post Update', 'wp-autopilot' ); ?></label>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Global Delay (seconds)', 'wp-autopilot' ); ?></th>
                            <td>
                                <input type="number" name="global_delay" value="<?php echo esc_attr( $general['global_delay'] ?? 0 ); ?>" min="0" max="3600" class="small-text">
                                <p class="description"><?php esc_html_e( 'Wait before the first platform job fires after publish.', 'wp-autopilot' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Platform-to-Platform Delay (seconds)', 'wp-autopilot' ); ?></th>
                            <td>
                                <input type="number" name="platform_delay" value="<?php echo esc_attr( $general['platform_delay'] ?? 30 ); ?>" min="0" max="3600" class="small-text">
                                <p class="description"><?php esc_html_e( 'Gap between each platform job to avoid rate limits.', 'wp-autopilot' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Max Retry Attempts', 'wp-autopilot' ); ?></th>
                            <td><input type="number" name="max_attempts" value="<?php echo esc_attr( $general['max_attempts'] ?? 3 ); ?>" min="1" max="10" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Retry Delay (seconds)', 'wp-autopilot' ); ?></th>
                            <td><input type="number" name="retry_delay" value="<?php echo esc_attr( $general['retry_delay'] ?? 300 ); ?>" min="60" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'URL Shortener', 'wp-autopilot' ); ?></th>
                            <td>
                                <select name="url_shortener">
                                    <option value="none" <?php selected( $general['url_shortener'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'None', 'wp-autopilot' ); ?></option>
                                    <option value="bitly" <?php selected( $general['url_shortener'] ?? '', 'bitly' ); ?>>Bit.ly</option>
                                    <option value="yourls" <?php selected( $general['url_shortener'] ?? '', 'yourls' ); ?>>YOURLS (self-hosted)</option>
                                </select>
                            </td>
                        </tr>
                        <tr class="wpap-bitly-row" <?php echo ( ( $general['url_shortener'] ?? '' ) !== 'bitly' ) ? 'style="display:none"' : ''; ?>>
                            <th><?php esc_html_e( 'Bit.ly Token', 'wp-autopilot' ); ?></th>
                            <td><input type="password" name="bitly_token" value="<?php echo esc_attr( $general['bitly_token'] ?? '' ); ?>" class="regular-text" autocomplete="new-password"></td>
                        </tr>
                        <tr class="wpap-yourls-row" <?php echo ( ( $general['url_shortener'] ?? '' ) !== 'yourls' ) ? 'style="display:none"' : ''; ?>>
                            <th><?php esc_html_e( 'YOURLS URL', 'wp-autopilot' ); ?></th>
                            <td><input type="url" name="yourls_url" value="<?php echo esc_attr( $general['yourls_url'] ?? '' ); ?>" class="regular-text" placeholder="https://your-domain.com"></td>
                        </tr>
                        <tr class="wpap-yourls-row" <?php echo ( ( $general['url_shortener'] ?? '' ) !== 'yourls' ) ? 'style="display:none"' : ''; ?>>
                            <th><?php esc_html_e( 'YOURLS Signature Token', 'wp-autopilot' ); ?></th>
                            <td><input type="password" name="yourls_token" value="<?php echo esc_attr( $general['yourls_token'] ?? '' ); ?>" class="regular-text" autocomplete="new-password"></td>
                        </tr>
                    </table>
                </div>

                <!-- AI Engine Tab -->
                <div id="tab-ai" class="wpap-tab-content">
                    <h2><?php esc_html_e( 'AI Content Engine', 'wp-autopilot' ); ?></h2>
                    <p><?php esc_html_e( 'AI generates platform-specific captions, hooks, and hashtags for each post automatically.', 'wp-autopilot' ); ?></p>
                    <table class="form-table wpap-form-table">
                        <tr>
                            <th><?php esc_html_e( 'OpenAI API Key', 'wp-autopilot' ); ?></th>
                            <td>
                                <input type="password" name="openai_api_key" value="<?php echo esc_attr( $general['openai_api_key'] ?? '' ); ?>" class="regular-text" autocomplete="new-password" placeholder="sk-...">
                                <p class="description"><?php esc_html_e( 'Required for AI content generation and Quora answer generation.', 'wp-autopilot' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e( 'Model', 'wp-autopilot' ); ?></th>
                            <td>
                                <select name="openai_model">
                                    <option value="gpt-4o-mini" <?php selected( $general['openai_model'] ?? 'gpt-4o-mini', 'gpt-4o-mini' ); ?>>GPT-4o Mini (fast, cheap)</option>
                                    <option value="gpt-4o" <?php selected( $general['openai_model'] ?? '', 'gpt-4o' ); ?>>GPT-4o (best quality)</option>
                                    <option value="gpt-3.5-turbo" <?php selected( $general['openai_model'] ?? '', 'gpt-3.5-turbo' ); ?>>GPT-3.5 Turbo (legacy)</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Twitter Tab -->
                <div id="tab-twitter" class="wpap-tab-content">
                    <?php $tw = $platforms['twitter']['accounts']['default'] ?? []; ?>
                    <h2>𝕏 <?php esc_html_e( 'Twitter / X', 'wp-autopilot' ); ?></h2>
                    <label class="wpap-toggle"><input type="checkbox" name="platforms[twitter][enabled]" value="1" <?php checked( ! empty( $platforms['twitter']['enabled'] ) ); ?>><span><?php esc_html_e( 'Enable Twitter/X sharing', 'wp-autopilot' ); ?></span></label>
                    <div class="wpap-notice wpap-notice-info">
                        <strong><?php esc_html_e( 'Setup:', 'wp-autopilot' ); ?></strong>
                        <?php esc_html_e( 'Create an app at developer.twitter.com. Enable OAuth 2.0 with PKCE. Add your callback URL:', 'wp-autopilot' ); ?>
                        <code><?php echo esc_url( rest_url( 'wpap/v1/oauth/callback/twitter' ) ); ?></code>
                    </div>
                    <table class="form-table wpap-form-table">
                        <tr><th><?php esc_html_e( 'Client ID (OAuth 2.0)', 'wp-autopilot' ); ?></th><td><input type="text" name="platforms[twitter][accounts][default][client_id]" value="" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'wp-autopilot' ); ?>" class="regular-text"></td></tr>
                        <tr><th><?php esc_html_e( 'Client Secret (OAuth 2.0)', 'wp-autopilot' ); ?></th><td><input type="password" name="platforms[twitter][accounts][default][client_secret]" value="" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'wp-autopilot' ); ?>" class="regular-text" autocomplete="new-password"></td></tr>
                        <tr><th><?php esc_html_e( 'API Key (OAuth 1.0a, for media)', 'wp-autopilot' ); ?></th><td><input type="password" name="platforms[twitter][accounts][default][api_key]" value="" placeholder="<?php esc_attr_e( 'Leave blank to keep existing', 'wp-autopilot' ); ?>" class="regular-text" autocomplete="new-password"></td></tr>
                        <tr><th><?php esc_html_e( 'API Secret', 'wp-autopilot' ); ?></th><td><input type="password" name="platforms[twitter][accounts][default][api_secret]" value="" class="regular-text" autocomplete="new-password"></td></tr>
                        <tr><th><?php esc_html_e( 'Attach Featured Image', 'wp-autopilot' ); ?></th><td><label><input type="checkbox" name="platforms[twitter][accounts][default][attach_image]" value="1" <?php checked( ! empty( $tw['attach_image'] ) ); ?>> <?php esc_html_e( 'Yes', 'wp-autopilot' ); ?></label></td></tr>
                        <tr><th><?php esc_html_e( 'Custom Template', 'wp-autopilot' ); ?></th><td><textarea name="platforms[twitter][accounts][default][custom_template]" class="large-text" rows="3" placeholder="{title} {url}"><?php echo esc_textarea( $tw['custom_template'] ?? '' ); ?></textarea><p class="description"><?php esc_html_e( 'Use {title}, {url}, {excerpt}. Leave blank for AI generation.', 'wp-autopilot' ); ?></p></td></tr>
                    </table>
                    <?php if ( ! empty( $tw['access_token'] ) ) : ?>
                        <div class="wpap-notice wpap-notice-success"><?php esc_html_e( '✅ Connected', 'wp-autopilot' ); ?></div>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'wpap_disconnect_platform' ); ?>
                            <input type="hidden" name="action" value="wpap_disconnect_platform">
                            <input type="hidden" name="platform" value="twitter">
                            <input type="hidden" name="account_id" value="default">
                            <button type="submit" class="button button-secondary"><?php esc_html_e( 'Disconnect', 'wp-autopilot' ); ?></button>
                        </form>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'wpap_connect_platform' ); ?>
                            <input type="hidden" name="action" value="wpap_connect_platform">
                            <input type="hidden" name="platform" value="twitter">
                            <input type="hidden" name="account_id" value="default">
                            <button type="submit" class="button button-primary"><?php esc_html_e( '🔗 Connect Twitter / X', 'wp-autopilot' ); ?></button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Reddit Tab -->
                <div id="tab-reddit" class="wpap-tab-content">
                    <?php $rd = $platforms['reddit']['accounts']['default'] ?? []; ?>
                    <h2>🤖 <?php esc_html_e( 'Reddit', 'wp-autopilot' ); ?></h2>
                    <label class="wpap-toggle"><input type="checkbox" name="platforms[reddit][enabled]" value="1" <?php checked( ! empty( $platforms['reddit']['enabled'] ) ); ?>><span><?php esc_html_e( 'Enable Reddit sharing', 'wp-autopilot' ); ?></span></label>
                    <div class="wpap-notice wpap-notice-info">
                        <strong><?php esc_html_e( 'Setup:', 'wp-autopilot' ); ?></strong>
                        <?php esc_html_e( 'Create an app at reddit.com/prefs/apps. Select "web app". Callback URL:', 'wp-autopilot' ); ?>
                        <code><?php echo esc_url( rest_url( 'wpap/v1/oauth/callback/reddit' ) ); ?></code>
                    </div>
                    <table class="form-table wpap-form-table">
                        <tr><th><?php esc_html_e( 'Client ID', 'wp-autopilot' ); ?></th><td><input type="text" name="platforms[reddit][accounts][default][client_id]" value="" class="regular-text"></td></tr>
                        <tr><th><?php esc_html_e( 'Client Secret', 'wp-autopilot' ); ?></th><td><input type="password" name="platforms[reddit][accounts][default][client_secret]" value="" class="regular-text" autocomplete="new-password"></td></tr>
                        <tr>
                            <th><?php esc_html_e( 'Default Subreddits', 'wp-autopilot' ); ?></th>
                            <td>
                                <input type="text" name="platforms[reddit][accounts][default][subreddits]" value="<?php echo esc_attr( is_array( $rd['subreddits'] ?? '' ) ? implode( ',', $rd['subreddits'] ) : ( $rd['subreddits'] ?? '' ) ); ?>" class="regular-text" placeholder="r/webdev,r/programming">
                                <p class="description"><?php esc_html_e( 'Comma-separated. Leave blank to use category mapping.', 'wp-autopilot' ); ?></p>
                            </td>
                        </tr>
                        <tr><th><?php esc_html_e( 'Max Subreddits Per Post', 'wp-autopilot' ); ?></th><td><input type="number" name="platforms[reddit][accounts][default][max_subreddits]" value="<?php echo esc_attr( $rd['max_subreddits'] ?? 2 ); ?>" min="1" max="5" class="small-text"></td></tr>
                        <tr><th><?php esc_html_e( 'Delay Between Subreddits (sec)', 'wp-autopilot' ); ?></th><td><input type="number" name="platforms[reddit][accounts][default][inter_subreddit_delay]" value="<?php echo esc_attr( $rd['inter_subreddit_delay'] ?? 60 ); ?>" min="0" max="600" class="small-text"></td></tr>
                        <tr><th><?php esc_html_e( 'Post Type', 'wp-autopilot' ); ?></th>
                            <td><select name="platforms[reddit][accounts][default][post_type]">
                                <option value="link" <?php selected( $rd['post_type'] ?? 'link', 'link' ); ?>><?php esc_html_e( 'Link Post', 'wp-autopilot' ); ?></option>
                                <option value="self" <?php selected( $rd['post_type'] ?? '', 'self' ); ?>><?php esc_html_e( 'Text Post (Self)', 'wp-autopilot' ); ?></option>
                            </select></td>
                        </tr>
                    </table>
                    <?php if ( ! empty( $rd['access_token'] ) ) : ?>
                        <div class="wpap-notice wpap-notice-success"><?php echo esc_html( sprintf( __( '✅ Connected as u/%s', 'wp-autopilot' ), $rd['username'] ?? '?' ) ); ?></div>
                    <?php else : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                            <?php wp_nonce_field( 'wpap_connect_platform' ); ?>
                            <input type="hidden" name="action" value="wpap_connect_platform">
                            <input type="hidden" name="platform" value="reddit">
                            <input type="hidden" name="account_id" value="default">
                            <button type="submit" class="button button-primary"><?php esc_html_e( '🔗 Connect Reddit', 'wp-autopilot' ); ?></button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- Discord Tab -->
                <div id="tab-discord" class="wpap-tab-content">
                    <?php $dc = $platforms['discord']['accounts']['default'] ?? []; ?>
                    <h2>💬 <?php esc_html_e( 'Discord', 'wp-autopilot' ); ?></h2>
                    <label class="wpap-toggle"><input type="checkbox" name="platforms[discord][enabled]" value="1" <?php checked( ! empty( $platforms['discord']['enabled'] ) ); ?>><span><?php esc_html_e( 'Enable Discord posting', 'wp-autopilot' ); ?></span></label>
                    <div class="wpap-notice wpap-notice-info"><?php esc_html_e( 'Discord uses Webhook URLs — no OAuth required. Get your webhook from: Discord Server → Channel Settings → Integrations → Webhooks.', 'wp-autopilot' ); ?></div>
                    <table class="form-table wpap-form-table">
                        <tr><th><?php esc_html_e( 'Webhook URL', 'wp-autopilot' ); ?></th><td><input type="url" name="platforms[discord][accounts][default][webhook_url]" value="" class="large-text" placeholder="https://discord.com/api/webhooks/..."></td></tr>
                        <tr><th><?php esc_html_e( 'Embed Color (hex)', 'wp-autopilot' ); ?></th><td><input type="text" name="platforms[discord][accounts][default][embed_color]" value="<?php echo esc_attr( $dc['embed_color'] ?? '5865F2' ); ?>" class="small-text" placeholder="5865F2"></td></tr>
                        <tr><th><?php esc_html_e( 'Role Mention ID (optional)', 'wp-autopilot' ); ?></th><td><input type="text" name="platforms[discord][accounts][default][role_mention]" value="<?php echo esc_attr( $dc['role_mention'] ?? '' ); ?>" class="regular-text"></td></tr>
                    </table>
                </div>

                <!-- Mastodon Tab -->
                <div id="tab-mastodon" class="wpap-tab-content">
                    <h2>🐘 <?php esc_html_e( 'Mastodon', 'wp-autopilot' ); ?></h2>
                    <label class="wpap-toggle"><input type="checkbox" name="platforms[mastodon][enabled]" value="1" <?php checked( ! empty( $platforms['mastodon']['enabled'] ) ); ?>><span><?php esc_html_e( 'Enable Mastodon posting', 'wp-autopilot' ); ?></span></label>
                    <table class="form-table wpap-form-table">
                        <tr><th><?php esc_html_e( 'Instance URL', 'wp-autopilot' ); ?></th><td><input type="url" name="platforms[mastodon][accounts][default][instance_url]" value="" class="regular-text" placeholder="https://mastodon.social"></td></tr>
                        <tr><th><?php esc_html_e( 'Access Token', 'wp-autopilot' ); ?></th><td><input type="password" name="platforms[mastodon][accounts][default][access_token]" value="" class="regular-text" autocomplete="new-password"><p class="description"><?php esc_html_e( 'Get from: Mastodon → Settings → Development → New Application', 'wp-autopilot' ); ?></p></td></tr>
                        <tr><th><?php esc_html_e( 'Visibility', 'wp-autopilot' ); ?></th>
                            <td><select name="platforms[mastodon][accounts][default][visibility]">
                                <option value="public"><?php esc_html_e( 'Public', 'wp-autopilot' ); ?></option>
                                <option value="unlisted"><?php esc_html_e( 'Unlisted', 'wp-autopilot' ); ?></option>
                            </select></td>
                        </tr>
                    </table>
                </div>

                <!-- Quora Helper Tab -->
                <div id="tab-quora" class="wpap-tab-content">
                    <h2>🔵 <?php esc_html_e( 'Quora Helper', 'wp-autopilot' ); ?></h2>
                    <div class="wpap-notice wpap-notice-warning">
                        <strong><?php esc_html_e( 'Note:', 'wp-autopilot' ); ?></strong>
                        <?php esc_html_e( 'Quora has no public posting API. This helper uses AI to generate Quora-ready answers from your blog posts for 1-click copy-paste. Configure the AI Engine tab first.', 'wp-autopilot' ); ?>
                    </div>
                    <p><?php esc_html_e( 'Once configured, a "Quora Ready Answer" button appears in the post editor sidebar. Click it to generate a formatted answer, then copy and paste it into Quora.', 'wp-autopilot' ); ?></p>
                    <ul>
                        <li>✅ <?php esc_html_e( 'AI-generated hook', 'wp-autopilot' ); ?></li>
                        <li>✅ <?php esc_html_e( 'Full value answer', 'wp-autopilot' ); ?></li>
                        <li>✅ <?php esc_html_e( 'Natural CTA with link', 'wp-autopilot' ); ?></li>
                        <li>✅ <?php esc_html_e( 'Suggested Quora questions', 'wp-autopilot' ); ?></li>
                        <li>✅ <?php esc_html_e( '1-click full copy', 'wp-autopilot' ); ?></li>
                    </ul>
                </div>

            </div><!-- .wpap-settings-content -->
        </div><!-- .wpap-settings-layout -->

        <div class="wpap-settings-footer">
            <?php submit_button( __( '💾 Save All Settings', 'wp-autopilot' ), 'primary large', 'submit', false ); ?>
        </div>
    </form>
</div>
