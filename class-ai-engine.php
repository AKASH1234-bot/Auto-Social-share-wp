<?php
namespace WPAutoPilot;

defined( 'ABSPATH' ) || exit;

/**
 * AI Engine — generates platform-specific content variations using OpenAI.
 */
class AIEngine {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    private const OPENAI_CHAT = 'https://api.openai.com/v1/chat/completions';

    /** Platform-specific system prompts */
    private const PLATFORM_PROMPTS = [
        'twitter' => <<<'P'
You are a viral Twitter/X copywriter. Generate a tweet from the blog post.
Rules:
- Max 240 characters for the text (URL + hashtags are added separately)
- Start with a powerful hook (question, bold claim, or shocking stat)
- Use active voice, punchy language
- Include 3-5 relevant hashtags
- No emojis unless essential
Output JSON: {"hook":"","text":"","hashtags":[],"tone":"viral"}
P,
        'linkedin' => <<<'P'
You are a LinkedIn thought leadership writer. Generate a LinkedIn post from the blog post.
Rules:
- 150-300 words
- Start with a bold hook (a counterintuitive insight or strong opinion)
- Use short paragraphs (1-2 sentences max)
- Include 3 hashtags
- End with a thought-provoking question to drive comments
- Professional but human tone
Output JSON: {"hook":"","linkedin_text":"","hashtags":[],"tone":"authority"}
P,
        'facebook' => <<<'P'
You are a Facebook engagement specialist. Generate a Facebook post.
Rules:
- 80-150 words
- Conversational, engaging tone
- Ask a question to drive comments
- Use 1-2 emojis naturally
- 3 hashtags max
Output JSON: {"text":"","hashtags":[],"tone":"engaging"}
P,
        'instagram' => <<<'P'
You are an Instagram content creator. Generate an Instagram caption.
Rules:
- 100-150 words
- Start with a hook emoji
- Tell a mini story or insight
- 15-20 hashtags (mix of popular + niche)
- End with a CTA
Output JSON: {"text":"","hashtags":[],"tone":"inspiring"}
P,
        'reddit' => <<<'P'
You are a Reddit power user. Generate a discussion-style Reddit post title and body.
Rules:
- Title: curiosity-driven, question format preferred, no clickbait, max 200 chars
- Body: genuinely helpful, educational, non-promotional
- Discussion tone — written as a community member sharing knowledge
- No affiliate language, no "I wrote this article"
- For self posts: provide value directly, then mention the source naturally
Output JSON: {"reddit_title":"","reddit_body":"","tone":"discussion","post_type":"link"}
P,
        'pinterest' => <<<'P'
You are a Pinterest SEO specialist. Generate a Pinterest pin description.
Rules:
- 200-500 characters
- Keyword-rich for Pinterest search
- Describe what the image shows
- Include a benefit or outcome
- 5-10 relevant hashtags
Output JSON: {"text":"","hashtags":[],"tone":"aspirational"}
P,
        'discord' => <<<'P'
You are a Discord community manager. Generate a Discord announcement.
Rules:
- Concise (50-100 words)
- Community-friendly tone
- Use 1-2 relevant emojis
- Include a clear CTA
Output JSON: {"text":"","tone":"community"}
P,
        'mastodon' => <<<'P'
You are a Mastodon user. Generate a Mastodon toot.
Rules:
- Max 450 characters including URL
- Fediverse-friendly, open web mindset
- 3-5 hashtags (CamelCase for accessibility)
- No corporate speak
Output JSON: {"text":"","hashtags":[],"tone":"open"}
P,
        'medium' => <<<'P'
You are a Medium editor. Generate a compelling intro hook for a Medium article.
Output JSON: {"hook":"","subtitle":"","tags":[],"tone":"editorial"}
P,
        'tumblr' => <<<'P'
You are a Tumblr blogger. Generate a Tumblr caption.
Rules:
- Casual, witty, relatable
- 50-100 words
- Tags (no # needed)
Output JSON: {"text":"","tags":[],"tone":"casual"}
P,
        'default' => <<<'P'
You are a social media content specialist. Generate engaging social media content.
Output JSON: {"text":"","hashtags":[],"hook":"","tone":"engaging"}
P,
    ];

    /**
     * Generate platform-specific content for a post.
     *
     * @param \WP_Post $post     The WordPress post.
     * @param string   $platform Target platform slug.
     * @param array    $cfg      Platform config (may contain custom templates).
     * @return array             Content array with keys: text, hook, hashtags, etc.
     */
    public function generate_content( \WP_Post $post, string $platform, array $cfg = [] ): array {
        // Check for custom template override.
        if ( ! empty( $cfg['custom_template'] ) ) {
            return $this->apply_custom_template( $cfg['custom_template'], $post );
        }

        $api_key = $this->get_api_key();
        if ( ! $api_key ) {
            // Fallback to basic content without AI.
            return $this->basic_content( $post, $platform );
        }

        $system_prompt = self::PLATFORM_PROMPTS[ $platform ] ?? self::PLATFORM_PROMPTS['default'];
        $post_data     = $this->extract_post_data( $post );

        $user_message = sprintf(
            "Generate content for this blog post:\n\nTitle: %s\n\nExcerpt: %s\n\nCategories: %s\n\nTags: %s",
            $post_data['title'],
            $post_data['excerpt'],
            $post_data['categories'],
            $post_data['tags']
        );

        $ai_text = $this->raw_completion( $user_message, [
            'system'      => $system_prompt,
            'max_tokens'  => 600,
            'temperature' => 0.8,
        ] );

        if ( is_wp_error( $ai_text ) ) {
            Logger::warning(
                "AI content generation failed for {$platform}: " . $ai_text->get_error_message(),
                [ 'post_id' => $post->ID ]
            );
            return $this->basic_content( $post, $platform );
        }

        // Parse the JSON response.
        $text  = trim( $ai_text );
        $text  = preg_replace( '/^```(?:json)?\s*/m', '', $text );
        $text  = preg_replace( '/```\s*$/m', '', $text );
        $data  = json_decode( $text, true );

        if ( ! $data ) {
            return $this->basic_content( $post, $platform );
        }

        // Merge in basic fallbacks.
        return array_merge( $this->basic_content( $post, $platform ), array_filter( $data ) );
    }

    /**
     * Direct completion call to OpenAI (also used by QuoraHelper).
     *
     * @param string $user_message
     * @param array  $opts { system, max_tokens, temperature }
     * @return string|\WP_Error
     */
    public function raw_completion( string $user_message, array $opts = [] ): string|\WP_Error {
        $api_key = $this->get_api_key();
        if ( ! $api_key ) {
            return new \WP_Error( 'no_ai_key', 'OpenAI API key not configured.' );
        }

        $messages = [];
        if ( ! empty( $opts['system'] ) ) {
            $messages[] = [ 'role' => 'system', 'content' => $opts['system'] ];
        }
        $messages[] = [ 'role' => 'user', 'content' => $user_message ];

        $body = [
            'model'       => $opts['model'] ?? 'gpt-4o-mini',
            'messages'    => $messages,
            'max_tokens'  => $opts['max_tokens'] ?? 600,
            'temperature' => $opts['temperature'] ?? 0.7,
        ];

        $response = wp_remote_post( self::OPENAI_CHAT, [
            'timeout' => 45,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode( $body ),
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $payload = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $err = $payload['error']['message'] ?? 'Unknown OpenAI error';
            return new \WP_Error( 'openai_error', $err );
        }

        return trim( $payload['choices'][0]['message']['content'] ?? '' );
    }

    /**
     * Generate viral hook variations for a post.
     *
     * @return string[]
     */
    public function generate_hooks( \WP_Post $post, int $count = 5 ): array {
        $prompt = sprintf(
            'Generate %d viral hook variations for this blog post title: "%s". ' .
            'Each hook should be max 100 chars, create curiosity, and be different in approach. ' .
            'Return as JSON array of strings only.',
            $count,
            get_the_title( $post )
        );

        $result = $this->raw_completion( $prompt, [ 'temperature' => 0.9 ] );
        if ( is_wp_error( $result ) ) {
            return [];
        }

        $hooks = json_decode( $result, true );
        return is_array( $hooks ) ? $hooks : [];
    }

    /**
     * Generate hashtag suggestions for a post.
     *
     * @return string[]
     */
    public function generate_hashtags( \WP_Post $post, string $platform = 'twitter', int $count = 10 ): array {
        $prompt = sprintf(
            'Generate %d relevant hashtags for a %s post about: "%s". ' .
            'Mix trending and niche hashtags. No # symbol. Return JSON array of strings.',
            $count,
            $platform,
            get_the_title( $post )
        );

        $result = $this->raw_completion( $prompt, [ 'temperature' => 0.5 ] );
        if ( is_wp_error( $result ) ) {
            return [];
        }

        $tags = json_decode( $result, true );
        return is_array( $tags ) ? array_map( fn($t) => ltrim( $t, '#' ), $tags ) : [];
    }

    // ─── Private Helpers ──────────────────────────────────────────────────────

    private function get_api_key(): string {
        $general = get_option( 'wpap_general', [] );
        return $general['openai_api_key'] ?? '';
    }

    private function extract_post_data( \WP_Post $post ): array {
        $cats = get_the_terms( $post->ID, 'category' );
        $tags = get_the_terms( $post->ID, 'post_tag' );

        return [
            'title'      => get_the_title( $post ),
            'excerpt'    => wp_trim_words( $post->post_excerpt ?: $post->post_content, 60 ),
            'categories' => $cats && ! is_wp_error( $cats ) ? implode( ', ', wp_list_pluck( $cats, 'name' ) ) : '',
            'tags'       => $tags && ! is_wp_error( $tags ) ? implode( ', ', wp_list_pluck( $tags, 'name' ) ) : '',
        ];
    }

    private function basic_content( \WP_Post $post, string $platform ): array {
        $title   = get_the_title( $post );
        $excerpt = wp_trim_words( $post->post_excerpt ?: $post->post_content, 30 );
        $tags    = get_the_terms( $post->ID, 'post_tag' );
        $hashtags = [];

        if ( $tags && ! is_wp_error( $tags ) ) {
            $hashtags = array_map(
                fn($t) => str_replace( [ ' ', '-' ], '', $t->name ),
                array_slice( $tags, 0, 5 )
            );
        }

        return [
            'title'          => $title,
            'text'           => $excerpt,
            'hook'           => $title,
            'hashtags'       => $hashtags,
            'linkedin_text'  => $excerpt,
            'reddit_title'   => $title,
            'reddit_body'    => $excerpt,
            'tone'           => 'informative',
        ];
    }

    private function apply_custom_template( string $template, \WP_Post $post ): array {
        $url     = get_permalink( $post );
        $title   = get_the_title( $post );
        $excerpt = wp_trim_words( $post->post_excerpt ?: $post->post_content, 30 );

        $text = str_replace(
            [ '{title}', '{url}', '{excerpt}' ],
            [ $title, $url, $excerpt ],
            $template
        );

        return [
            'text'          => $text,
            'hook'          => $title,
            'hashtags'      => [],
            'linkedin_text' => $text,
            'reddit_title'  => $title,
            'reddit_body'   => $text,
        ];
    }
}
