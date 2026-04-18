<?php
namespace WPAutoPilot\Services;

use WPAutoPilot\{AIEngine, Logger};

defined( 'ABSPATH' ) || exit;

/**
 * Quora Helper — no direct API available.
 *
 * Generates formatted Quora-style answers from post content using AI:
 *  - Hook (attention grabber)
 *  - Value answer (answer-style content)
 *  - CTA with link
 *  - Question suggestions for related Quora topics
 *
 * Also provides browser notification bridge and clipboard copy.
 */
class QuoraHelper {

    private const PROMPT_TEMPLATE = <<<'PROMPT'
You are an expert Quora writer. Based on the following blog post, generate a high-quality Quora answer.

Post Title: {{TITLE}}
Post Content: {{CONTENT}}
Post URL: {{URL}}

Generate the answer in this EXACT JSON structure:
{
  "hook": "An attention-grabbing opening line (1-2 sentences, pose a question or bold statement)",
  "value_answer": "The main substantive answer (3-6 paragraphs, answer-style, educational, no promotional tone)",
  "cta": "A natural CTA sentence with the link embedded — e.g. 'For a detailed breakdown, I wrote about this at [URL]'",
  "question_suggestions": [
    "Suggested Quora question 1 that this post answers",
    "Suggested Quora question 2",
    "Suggested Quora question 3"
  ],
  "full_formatted_answer": "The complete formatted answer ready to paste into Quora = hook + newline + value_answer + newline + cta"
}

Rules:
- Never use promotional language like 'Check out my blog'
- Write in first person, conversational expert tone
- The value_answer must be genuinely helpful and complete
- Do NOT mention the blog post title directly
- Return ONLY valid JSON, no markdown fences
PROMPT;

    /**
     * Generate a Quora-ready answer for a given post.
     *
     * @return array{
     *   hook: string,
     *   value_answer: string,
     *   cta: string,
     *   question_suggestions: string[],
     *   full_formatted_answer: string,
     *   post_url: string,
     *   word_count: int,
     *   cached: bool
     * }
     */
    public static function generate_answer( int $post_id ): array {
        $cache_key = 'wpap_quora_' . $post_id;
        $cached    = get_transient( $cache_key );

        if ( $cached ) {
            return array_merge( $cached, [ 'cached' => true ] );
        }

        $post = get_post( $post_id );
        if ( ! $post ) {
            return self::error_result( 'Post not found.' );
        }

        $url     = get_permalink( $post );
        $title   = get_the_title( $post );
        $content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
        // Limit content length to avoid token overflow.
        $content = substr( $content, 0, 4000 );

        $prompt = str_replace(
            [ '{{TITLE}}', '{{CONTENT}}', '{{URL}}' ],
            [ $title, $content, $url ],
            self::PROMPT_TEMPLATE
        );

        $ai_result = AIEngine::instance()->raw_completion( $prompt, [
            'max_tokens'  => 1500,
            'temperature' => 0.7,
        ] );

        if ( is_wp_error( $ai_result ) ) {
            Logger::error( 'Quora AI generation failed: ' . $ai_result->get_error_message(), [ 'post_id' => $post_id ] );
            return self::error_result( $ai_result->get_error_message() );
        }

        // Parse JSON from AI response.
        $text = trim( $ai_result );
        $text = preg_replace( '/^```(?:json)?\s*/m', '', $text );
        $text = preg_replace( '/```\s*$/m', '', $text );
        $data = json_decode( $text, true );

        if ( ! $data || json_last_error() !== JSON_ERROR_NONE ) {
            // Fallback: manually build a basic answer.
            $data = self::build_fallback( $post, $url );
        }

        $result = [
            'hook'                 => sanitize_textarea_field( $data['hook'] ?? '' ),
            'value_answer'         => wp_kses_post( $data['value_answer'] ?? '' ),
            'cta'                  => sanitize_textarea_field( $data['cta'] ?? "Read more at: {$url}" ),
            'question_suggestions' => array_map( 'sanitize_text_field', $data['question_suggestions'] ?? [] ),
            'full_formatted_answer'=> sanitize_textarea_field( $data['full_formatted_answer'] ?? '' ),
            'post_url'             => $url,
            'word_count'           => str_word_count( $data['full_formatted_answer'] ?? '' ),
            'cached'               => false,
        ];

        // Cache for 24 hours — regenerate on demand.
        set_transient( $cache_key, $result, DAY_IN_SECONDS );

        return $result;
    }

    /**
     * Invalidate the cached answer for a post (call on post update).
     */
    public static function invalidate_cache( int $post_id ): void {
        delete_transient( 'wpap_quora_' . $post_id );
    }

    /**
     * Render the Quora helper UI panel (called from admin meta box or dashboard).
     */
    public static function render_panel( int $post_id ): void {
        $nonce = wp_create_nonce( 'wpap_quora_' . $post_id );
        ?>
        <div class="wpap-quora-panel" data-post-id="<?php echo esc_attr( $post_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
            <div class="wpap-quora-header">
                <span class="wpap-platform-icon">🔵</span>
                <strong><?php esc_html_e( 'Quora Ready Answer', 'wp-autopilot' ); ?></strong>
                <button type="button" class="button button-small wpap-quora-generate" data-post-id="<?php echo esc_attr( $post_id ); ?>">
                    ✨ <?php esc_html_e( 'Generate Answer', 'wp-autopilot' ); ?>
                </button>
            </div>

            <div class="wpap-quora-content" style="display:none;">
                <div class="wpap-quora-section">
                    <label><?php esc_html_e( '🎣 Hook', 'wp-autopilot' ); ?></label>
                    <div class="wpap-quora-field wpap-quora-hook"></div>
                    <button type="button" class="wpap-copy-btn" data-target="hook">📋 <?php esc_html_e( 'Copy', 'wp-autopilot' ); ?></button>
                </div>
                <div class="wpap-quora-section">
                    <label><?php esc_html_e( '📝 Value Answer', 'wp-autopilot' ); ?></label>
                    <div class="wpap-quora-field wpap-quora-value_answer"></div>
                    <button type="button" class="wpap-copy-btn" data-target="value_answer">📋 <?php esc_html_e( 'Copy', 'wp-autopilot' ); ?></button>
                </div>
                <div class="wpap-quora-section">
                    <label><?php esc_html_e( '🔗 CTA', 'wp-autopilot' ); ?></label>
                    <div class="wpap-quora-field wpap-quora-cta"></div>
                </div>
                <div class="wpap-quora-section">
                    <label><?php esc_html_e( '❓ Question Suggestions', 'wp-autopilot' ); ?></label>
                    <ul class="wpap-quora-suggestions"></ul>
                </div>
                <div class="wpap-quora-section wpap-quora-full">
                    <label><?php esc_html_e( '📄 Full Answer (Copy & Paste into Quora)', 'wp-autopilot' ); ?></label>
                    <textarea class="wpap-quora-full-text" rows="12" readonly></textarea>
                    <div class="wpap-quora-actions">
                        <button type="button" class="button button-primary wpap-copy-full">
                            📋 <?php esc_html_e( '1-Click Copy Full Answer', 'wp-autopilot' ); ?>
                        </button>
                        <a href="https://www.quora.com/search?q=" target="_blank" class="button wpap-quora-open" rel="noopener noreferrer">
                            🌐 <?php esc_html_e( 'Open Quora', 'wp-autopilot' ); ?>
                        </a>
                        <span class="wpap-word-count"></span>
                    </div>
                </div>
            </div>

            <div class="wpap-quora-loading" style="display:none;">
                <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
                <?php esc_html_e( 'Generating Quora answer with AI...', 'wp-autopilot' ); ?>
            </div>
            <div class="wpap-quora-error" style="display:none;color:red;"></div>
        </div>
        <?php
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private static function error_result( string $message ): array {
        return [
            'hook'                 => '',
            'value_answer'         => '',
            'cta'                  => '',
            'question_suggestions' => [],
            'full_formatted_answer'=> '',
            'post_url'             => '',
            'word_count'           => 0,
            'cached'               => false,
            'error'                => $message,
        ];
    }

    private static function build_fallback( \WP_Post $post, string $url ): array {
        $excerpt = wp_trim_words( $post->post_content, 80 );
        return [
            'hook'                  => 'Great question — here\'s what I\'ve found after researching this extensively.',
            'value_answer'          => $excerpt,
            'cta'                   => "I wrote a detailed breakdown on this: {$url}",
            'question_suggestions'  => [ get_the_title( $post ) ],
            'full_formatted_answer' => "Great question — here's what I've found after researching this extensively.\n\n{$excerpt}\n\nI wrote a detailed breakdown on this: {$url}",
        ];
    }
}
