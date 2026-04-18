# WP AutoPilot — Social Autoposter
### Production-Ready WordPress Plugin v2.0.0

A Zapier-style social distribution engine that automatically publishes your WordPress posts to **11+ platforms** with AI-generated, platform-specific content.

---

## 📋 Table of Contents

1. [Requirements](#requirements)
2. [Installation](#installation)
3. [Plugin Architecture](#plugin-architecture)
4. [Platform Setup Guides](#platform-setup-guides)
5. [AI Engine Configuration](#ai-engine-configuration)
6. [Scheduler & Queue System](#scheduler--queue-system)
7. [Quora Helper Module](#quora-helper-module)
8. [Security Model](#security-model)
9. [Developer Hooks & Filters](#developer-hooks--filters)
10. [Troubleshooting](#troubleshooting)

---

## Requirements

| Requirement | Minimum |
|-------------|---------|
| WordPress   | 6.0+    |
| PHP         | 8.1+    |
| MySQL       | 5.7+ / MariaDB 10.3+ |
| Extensions  | `openssl`, `json`, `curl` |
| WP-Cron     | Enabled (or server cron) |

---

## Installation

### Method A — Manual Upload
1. Download/clone this repository
2. Zip the `wp-autopilot/` folder
3. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
4. Upload the ZIP and activate

### Method B — Direct (Development)
```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/your-org/wp-autopilot.git
```
Then activate from **Plugins → WP AutoPilot**.

### Post-Activation
The plugin automatically:
- Creates 3 database tables (`wpap_queue`, `wpap_logs`, `wpap_stats`)
- Registers a WP-Cron job every 60 seconds
- Sets default options

---

## Plugin Architecture

```
wp-autopilot/
├── wp-autopilot.php              ← Main bootstrap (constants, file loader, hooks)
│
├── includes/
│   ├── class-core.php            ← Singleton boot orchestrator, REST routes
│   ├── class-installer.php       ← DB tables, activation/deactivation/uninstall
│   ├── class-post-handler.php    ← WP post lifecycle hooks, meta box, manual re-share
│   └── class-service-factory.php ← Platform slug → service class resolver
│
├── scheduler/
│   ├── class-scheduler.php       ← WP-Cron registration, queue insertion, job queries
│   └── class-queue-processor.php ← Batch processor, retry logic, transient locking
│
├── services/
│   ├── class-abstract-service.php    ← Base class (HTTP helpers, stat recording)
│   ├── class-ai-engine.php           ← OpenAI GPT content generation per platform
│   ├── class-twitter-service.php     ← Twitter/X API v2 (OAuth 2.0 PKCE + media upload)
│   ├── class-social-services.php     ← Facebook (Graph), Instagram (Graph), LinkedIn (UGC)
│   ├── class-extended-services.php   ← Pinterest, Medium, Tumblr, Discord, Mastodon
│   ├── class-reddit-service.php      ← Reddit OAuth2 + subreddit mapping + anti-spam
│   └── class-quora-helper.php        ← AI answer generator + 1-click copy UI
│
├── admin/
│   ├── class-dashboard.php       ← Menu registration, AJAX handlers, enqueue
│   ├── class-settings.php        ← Settings save, OAuth connect/disconnect flows
│   ├── class-analytics.php       ← Analytics data queries + log viewer
│   └── views/
│       ├── dashboard.php         ← Main dashboard with stats + platform status
│       ├── settings.php          ← Full settings form (all platforms, AI, general)
│       ├── queue.php             ← AJAX-powered queue management table
│       ├── logs.php              ← Filterable activity log viewer
│       └── analytics.php        ← Charts + top posts + platform rates
│
├── utils/
│   └── class-utilities.php      ← Logger, EncryptionHelper (AES-256), UrlShortener, MediaHandler
│
└── assets/
    ├── css/admin.css             ← Full admin stylesheet (CSS variables, responsive)
    └── js/admin.js               ← Tabs, AJAX queue, Quora panel, copy-to-clipboard
```

---

## Platform Setup Guides

### 🐦 Twitter / X

1. Go to [developer.twitter.com](https://developer.twitter.com)
2. Create a Project + App
3. Enable **OAuth 2.0** with **PKCE** (under "User authentication settings")
4. Set **Callback URI**: `https://yoursite.com/wp-json/wpap/v1/oauth/callback/twitter`
5. Set **App permissions**: Read + Write
6. Copy **Client ID** and **Client Secret** → paste in plugin Settings → Twitter tab
7. Click **Connect Twitter / X** → authorize in the popup

**For media uploads** (images), also create OAuth 1.0a credentials:
- Copy API Key, API Secret, Access Token, Access Secret → paste in same tab

---

### 🤖 Reddit

1. Go to [reddit.com/prefs/apps](https://reddit.com/prefs/apps)
2. Click **Create App** → Select type: **web app**
3. Set **Redirect URI**: `https://yoursite.com/wp-json/wpap/v1/oauth/callback/reddit`
4. Copy **Client ID** (under app name) and **Client Secret**
5. Paste into Settings → Reddit tab
6. Click **Connect Reddit** → authorize

**Subreddit Mapping** — Map WordPress categories to subreddits:
- Leave blank to use the built-in category→subreddit map
- Or specify explicit subreddits: `r/webdev,r/programming`

---

### 📘 Facebook Pages

1. Go to [developers.facebook.com](https://developers.facebook.com)
2. Create an App → Business type
3. Add **Facebook Login** and **Pages API** products
4. Generate a **Page Access Token** (long-lived) via Graph API Explorer
5. Find your **Page ID** from your Facebook Page settings
6. Paste both into Settings → Facebook tab

---

### 📷 Instagram Business

Requires a Facebook-connected Instagram Business Account.

1. Same Facebook App as above
2. Add **Instagram Graph API** product
3. Get **Instagram User ID**: `GET /me/accounts?access_token=PAGE_TOKEN`
4. Paste Instagram User ID + Page Access Token → Settings → Instagram tab
5. ⚠️ Instagram **requires a featured image** to post — posts without images are skipped

---

### 💼 LinkedIn

1. Go to [developer.linkedin.com](https://developer.linkedin.com)
2. Create an App → Add products: **Share on LinkedIn** + **Sign In with LinkedIn**
3. Set **Callback URL**: `https://yoursite.com/wp-json/wpap/v1/oauth/callback/linkedin`
4. Copy **Client ID** + **Client Secret** → paste in Settings → LinkedIn tab
5. Click **Connect LinkedIn** → authorize

---

### 💬 Discord (No OAuth needed)

1. Open your Discord Server → Channel → ⚙️ Settings → Integrations → Webhooks
2. Create a new Webhook → Copy Webhook URL
3. Paste into Settings → Discord tab
4. Optionally set embed color (hex) and role mention ID

---

### 🐘 Mastodon

1. Go to your Mastodon instance → Settings → Development → New Application
2. Set scopes: `read write`
3. Copy the **Access Token**
4. Paste instance URL + token into Settings → Mastodon tab

---

### 📌 Pinterest

1. Go to [developers.pinterest.com](https://developers.pinterest.com)
2. Create an App → Get API approval
3. Generate Access Token with `boards:read boards:write pins:read pins:write` scopes
4. Find your Board ID from the Pinterest API
5. Paste into Settings → Pinterest tab

---

### M Medium

1. Go to Medium → Settings → Security → Integration tokens
2. Generate a token
3. Paste into Settings → Medium tab
4. Optionally set a Publication ID for posting to a publication

---

## AI Engine Configuration

Go to **Settings → AI Engine** tab:

| Field | Description |
|-------|-------------|
| OpenAI API Key | Get from [platform.openai.com](https://platform.openai.com) |
| Model | `gpt-4o-mini` (default, cheap) or `gpt-4o` (best quality) |

### What AI Generates Per Platform

| Platform  | AI Output |
|-----------|-----------|
| Twitter   | Viral hook, 240-char text, 5 hashtags |
| LinkedIn  | 200-word authority post, 3 hashtags, engagement question |
| Instagram | Caption with story angle, 15-20 hashtags, CTA |
| Reddit    | Discussion-tone title + body (non-promotional) |
| Facebook  | Engaging 100-word post, emoji, question |
| Pinterest | SEO-rich description, 10 hashtags |
| Discord   | Concise announcement with embed |
| Mastodon  | Fediverse-friendly toot with CamelCase hashtags |

### Custom Templates (Override AI)

In each platform tab, you can set a **Custom Template**:
```
{title} — {excerpt}

Read more: {url}
```
Variables: `{title}`, `{url}`, `{excerpt}`

---

## Scheduler & Queue System

### How It Works

```
Post Published → PostHandler::on_transition()
    → PostHandler::enqueue_post()
        → Scheduler::enqueue() × N platforms
            → wpap_queue table rows with scheduled_at timestamps

WP-Cron (every 60s) → wpap_process_queue action
    → QueueProcessor::process_batch()
        → get_due_jobs() → foreach job:
            → ServiceFactory::make(platform)
            → AIEngine::generate_content()
            → Service::publish()
            → Logger::success() / Logger::error()
            → Retry if failed (up to max_attempts)
```

### Queue Management

Visit **AutoPilot → Queue** to:
- See all pending / done / failed jobs
- Retry failed jobs individually
- Delete jobs
- Filter by status

### WP-Cron vs Server Cron

For production, disable WP-Cron and use a real cron:
```bash
# crontab -e
* * * * * wget -q -O /dev/null "https://yoursite.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

Add to `wp-config.php`:
```php
define('DISABLE_WP_CRON', true);
```

---

## Quora Helper Module

Since Quora has no posting API, WP AutoPilot provides an AI-powered workflow:

### How to Use

1. Open any published post in the WordPress editor
2. Find the **🚀 WP AutoPilot** meta box in the sidebar
3. Scroll to the **Quora Ready Answer** section
4. Click **✨ Generate Answer**
5. AI generates:
   - **Hook** — attention-grabbing opener
   - **Value Answer** — substantive answer (3-6 paragraphs)
   - **CTA** — natural link with your post URL
   - **Question Suggestions** — relevant Quora questions to answer
   - **Full Answer** — ready-to-paste complete text
6. Click **📋 1-Click Copy Full Answer**
7. Go to Quora → find a matching question → paste and post

### Question Strategy

Use the **Question Suggestions** to find existing Quora questions:
1. Copy a suggestion
2. Search on Quora
3. Find high-traffic questions (many followers)
4. Answer those using the generated content

---

## Security Model

| Security Feature | Implementation |
|-----------------|----------------|
| Token Storage   | AES-256-CBC encryption using WordPress AUTH_KEY salts |
| OAuth Callbacks | State parameter with wp_create_nonce / wp_verify_nonce |
| Admin Actions   | check_admin_referer() + current_user_can() on all actions |
| AJAX Handlers   | check_ajax_referer() + capability checks |
| REST Endpoints  | Nonce verification on sensitive endpoints |
| Input Sanitization | sanitize_key(), sanitize_text_field(), esc_url_raw(), absint() throughout |
| Output Escaping | esc_html(), esc_attr(), esc_url() on all output |
| DB Queries      | Exclusively $wpdb->prepare() + $wpdb->insert/update/replace |

---

## Developer Hooks & Filters

```php
// Fired after plugin boots
do_action( 'wpap_loaded' );

// Modify content before it's sent to a platform
// $content = ['text'=>'', 'hook'=>'', 'hashtags'=>[], ...]
add_filter( 'wpap_pre_publish_content', function( $content, $post, $platform ) {
    return $content;
}, 10, 3 );

// Register a custom platform
add_action( 'wpap_loaded', function() {
    \WPAutoPilot\ServiceFactory::register( 'myplatform', MyPlatformService::class );
});

// Fired after a successful post
add_action( 'wpap_post_published', function( $post_id, $platform, $result ) {
    // $result = ['remote_id'=>'', 'remote_url'=>'']
}, 10, 3 );

// Skip a post from being queued
add_filter( 'wpap_should_queue_post', function( $should, $post_id, $trigger ) {
    // return false to skip
    return $should;
}, 10, 3 );
```

---

## Troubleshooting

### Posts Not Being Shared

1. Check **AutoPilot → Logs** for error messages
2. Ensure at least one platform is **enabled** in Settings
3. Check post type matches the configured post types
4. Verify WP-Cron is running: `wp cron event list` (WP-CLI)
5. Ensure the post doesn't have "Skip auto-sharing" checked in the meta box

### OAuth Connection Fails

1. Verify the Callback/Redirect URI exactly matches what's configured in the developer portal
2. Check your site URL uses HTTPS (most OAuth providers require it)
3. Ensure the REST API is accessible: visit `yoursite.com/wp-json/` in browser

### AI Content Not Generating

1. Verify OpenAI API key is set in **Settings → AI Engine**
2. Check you have API credits at [platform.openai.com](https://platform.openai.com)
3. Plugin falls back to basic content (title + excerpt) if AI fails — check logs for "AI content generation failed"

### Token Encryption Issues

If tokens were stored before encryption was enabled:
1. Disconnect the platform
2. Re-enter credentials and reconnect
3. Tokens are now stored encrypted with AES-256

### WP-Cron Not Running

```php
// Add to wp-config.php
define('ALTERNATE_WP_CRON', true); // Helps on some hosts
```

Or use WP-CLI to manually trigger:
```bash
wp cron event run wpap_process_queue
```

---

## Changelog

### v2.0.0
- Full Twitter/X API v2 with OAuth 2.0 PKCE
- Reddit OAuth2 with intelligent subreddit mapping and anti-spam delays
- AI content generation (OpenAI GPT-4o) per platform
- Quora AI answer generator with 1-click copy
- Queue-based scheduler with retry system
- AES-256-CBC token encryption
- Full analytics dashboard with Chart.js
- Discord webhook integration
- Mastodon ActivityPub support
- URL shortener integration (Bit.ly / YOURLS)

---

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).
