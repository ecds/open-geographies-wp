<?php

/**
 * Plugin Name:       Open Geographies
 * Plugin URI:        https://github.com/ecds/open-geographies-wp
 * Description:       Fetches data from an Open Geographies compliant API based on the current URL path and exposes response fields via shortcodes.
 * Version:           0.0.4
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Your Name
 * License:           GPL-2.0-or-later
 * Text Domain:       open-geographies
 * GitHub Plugin URI: ecds/open-geographies-wp
 * Primary Branch:    main
 */

defined('ABSPATH') || exit;

// ─────────────────────────────────────────────
// 1.  Bootstrap
// ─────────────────────────────────────────────

define('OG_VERSION',    '0.0.4');
define('OG_OPTION_KEY', 'og_settings');
define('OG_CACHE_TTL',  60);
define('OG_CRON_HOOK',  'og_sync_cron');
define('OG_META_SLUG',  '_og_api_slug');   // post meta key that stores the API slug

add_action('init',                            ['Open_Geographies', 'init']);
add_action('admin_menu',                      ['Open_Geographies', 'admin_menu']);
add_action('admin_init',                      ['Open_Geographies', 'admin_init']);
add_action('admin_notices',                   ['Open_Geographies', 'admin_notices']);
add_action(OG_CRON_HOOK,                     ['Open_Geographies', 'run_sync']);
add_action('update_option_' . OG_OPTION_KEY, 'og_maybe_flush_rewrites', 10, 2);

// ─────────────────────────────────────────────
// 2.  Main class
// ─────────────────────────────────────────────

class Open_Geographies
{

    /** @var array|null  Decoded JSON payload for the current page request */
    private static ?array $payload = null;

    /** @var string|null  Error message if the API call failed */
    private static ?string $error = null;

    // ── Lifecycle ────────────────────────────

    public static function init(): void
    {
        add_shortcode('og_field',   [__CLASS__, 'sc_field']);
        add_shortcode('og_element', [__CLASS__, 'sc_element']);
        add_shortcode('og_html',    [__CLASS__, 'sc_html']);
        add_shortcode('og_list',    [__CLASS__, 'sc_list']);
        add_shortcode('og_table',   [__CLASS__, 'sc_table']);
        add_shortcode('og_links',   [__CLASS__, 'sc_links']);
        add_shortcode('og_json',    [__CLASS__, 'sc_json']);
        add_shortcode('og_if',      [__CLASS__, 'sc_if']);
        add_shortcode('og_data',    [__CLASS__, 'sc_data']);
        add_shortcode('og_image',   [__CLASS__, 'sc_image']);
        add_shortcode('og_gallery', [__CLASS__, 'sc_gallery']);
        add_shortcode('og_map',     [__CLASS__, 'sc_map']);
        add_shortcode('og_error',   [__CLASS__, 'sc_error']);

        add_action('wp_enqueue_scripts',  [__CLASS__, 'enqueue_swiper']);
        add_filter('post_thumbnail_html', [__CLASS__, 'filter_thumbnail'], 10, 2);

        self::register_cpt();
    }

    // ── Custom Post Type ─────────────────────

    public static function register_cpt(): void
    {
        $settings = self::get_settings();
        $slug     = ! empty($settings['cpt_slug'])     ? sanitize_title($settings['cpt_slug']) : 'og-item';
        $singular = ! empty($settings['cpt_singular']) ? $settings['cpt_singular']               : 'Item';
        $plural   = ! empty($settings['cpt_plural'])   ? $settings['cpt_plural']                 : 'Items';

        register_post_type('og_item', [
            'labels'       => [
                'name'               => $plural,
                'singular_name'      => $singular,
                'add_new_item'       => "Add New {$singular}",
                'edit_item'          => "Edit {$singular}",
                'view_item'          => "View {$singular}",
                'search_items'       => "Search {$plural}",
                'not_found'          => "No {$plural} found",
                'not_found_in_trash' => "No {$plural} found in trash",
                'menu_name'          => $plural,
            ],
            'public'       => true,
            'has_archive'  => true,
            'show_in_rest' => true,
            'rewrite'      => ['slug' => $slug, 'with_front' => false],
            'supports'     => ['title', 'editor', 'thumbnail', 'excerpt', 'comments'],
            'menu_icon'    => 'dashicons-rest-api',
        ]);
    }

    // ── Admin: menu ──────────────────────────

    public static function admin_menu(): void
    {
        add_options_page(
            __('Open Geographies', 'open-geographies'),
            __('Open Geographies', 'open-geographies'),
            'manage_options',
            'open-geographies',
            [__CLASS__, 'render_settings_page']
        );
    }

    // ── Admin: settings registration ─────────

    public static function admin_init(): void
    {
        register_setting('og_settings_group', OG_OPTION_KEY, [
            'sanitize_callback' => [__CLASS__, 'sanitize_settings'],
            'default'           => [],
        ]);

        add_settings_section('og_main_section', __('API Configuration', 'open-geographies'), '__return_false', 'open-geographies');
        add_settings_section('og_cpt_section',  __('Post Type',         'open-geographies'), '__return_false', 'open-geographies');

        $fields = [
            'endpoint' => [
                'label'       => __('API Base Endpoint', 'open-geographies'),
                'description' => __('e.g. <code>https://api.example.com/v1/products</code>. Used for both the list sync (<code>GET /products</code>) and single-item fetch (<code>GET /products/slug</code>).', 'open-geographies'),
                'type'        => 'url',
                'section'     => 'og_main_section',
            ],
            'request_timeout' => [
                'label'       => __('Request Timeout (seconds)', 'open-geographies'),
                'description' => __('Default: 10.', 'open-geographies'),
                'type'        => 'number',
                'section'     => 'og_main_section',
            ],
            'auth_header' => [
                'label'       => __('Authorization Header Value', 'open-geographies'),
                'description' => __('Optional. e.g. <code>Bearer my-secret-token</code>.', 'open-geographies'),
                'type'        => 'text',
                'section'     => 'og_main_section',
            ],
            'google_maps_api_key' => [
                'label'       => __('Google Maps API Key', 'open-geographies'),
                'description' => __('Used by <code>[og_map]</code> to render GeoJSON Point geometries. This key is exposed client-side in page source, so restrict it by HTTP referrer in the Google Cloud Console.', 'open-geographies'),
                'type'        => 'text',
                'section'     => 'og_main_section',
            ],
            'cache_ttl' => [
                'label'       => __('Cache TTL (seconds)', 'open-geographies'),
                'description' => __('How long to cache single-item API responses. Set to 0 to disable.', 'open-geographies'),
                'type'        => 'number',
                'section'     => 'og_main_section',
            ],
            'error_message' => [
                'label'       => __('Custom Error Message', 'open-geographies'),
                'description' => __('Shown to visitors if the API call fails.', 'open-geographies'),
                'type'        => 'text',
                'section'     => 'og_main_section',
            ],
            'enable_logging' => [
                'label'       => __('Enable Debug Logging', 'open-geographies'),
                'description' => __('Logs requests and sync activity to <code>wp-content/og-debug.log</code>. Disable in production.', 'open-geographies'),
                'type'        => 'checkbox',
                'section'     => 'og_main_section',
            ],
            'cpt_singular' => [
                'label'       => __('Singular Name', 'open-geographies'),
                'description' => __('e.g. <code>Product</code>', 'open-geographies'),
                'type'        => 'text',
                'section'     => 'og_cpt_section',
            ],
            'cpt_plural' => [
                'label'       => __('Plural Name', 'open-geographies'),
                'description' => __('e.g. <code>Products</code>', 'open-geographies'),
                'type'        => 'text',
                'section'     => 'og_cpt_section',
            ],
            'cpt_slug' => [
                'label'       => __('URL Slug', 'open-geographies'),
                'description' => __('e.g. <code>products</code> → <code>mysite.com/products/blue-widget</code>. Go to Settings → Permalinks and save after changing.', 'open-geographies'),
                'type'        => 'text',
                'section'     => 'og_cpt_section',
            ],
            'thumbnail_key' => [
                'label'       => __('Featured Image: API Key', 'open-geographies'),
                'description' => __('Dot-notation path to the image URL in the API response, e.g. <code>images.hero</code>.', 'open-geographies'),
                'type'        => 'text',
                'section'     => 'og_cpt_section',
            ],
            'thumbnail_alt_key' => [
                'label'       => __('Featured Image: Alt Text Key', 'open-geographies'),
                'description' => __('Dot-notation path to the alt text. Leave blank to use the post title.', 'open-geographies'),
                'type'        => 'text',
                'section'     => 'og_cpt_section',
            ],
        ];

        foreach ($fields as $key => $args) {
            add_settings_field(
                'og_' . $key,
                $args['label'],
                [__CLASS__, 'render_field'],
                'open-geographies',
                $args['section'],
                array_merge($args, ['key' => $key])
            );
        }
    }

    // ── Admin: notices ───────────────────────

    public static function admin_notices(): void
    {
        $settings = self::get_settings();

        if (empty($settings['endpoint']) && current_user_can('manage_options')) {
            printf(
                '<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
                esc_html__('Open Geographies: No API endpoint configured.', 'open-geographies'),
                esc_url(admin_url('options-general.php?page=open-geographies')),
                esc_html__('Configure now →', 'open-geographies')
            );
        }
    }

    // ── Settings page ─────────────────────────

    public static function render_settings_page(): void
    {
        if (! current_user_can('manage_options')) return;

        $notice = null;

        if (isset($_POST['og_flush_cache']) && check_admin_referer('og_flush_cache_action', 'og_flush_cache_nonce')) {
            self::flush_all_cache();
            $notice = ['type' => 'success', 'text' => __('Cache flushed successfully.', 'open-geographies')];
        }

        if (isset($_POST['og_run_sync']) && check_admin_referer('og_run_sync_action', 'og_run_sync_nonce')) {
            $result = self::run_sync();
            $notice = [
                'type' => 'success',
                'text' => sprintf(
                    /* translators: 1: created count  2: trashed count */
                    __('Sync complete — Created: %1$d, Trashed: %2$d.', 'open-geographies'),
                    $result['created'],
                    $result['trashed']
                ),
            ];
        }

        $next_cron = wp_next_scheduled(OG_CRON_HOOK);
?>
        <div class="wrap">
            <h1><?php esc_html_e('Open Geographies — Settings', 'open-geographies'); ?></h1>

            <?php if ($notice) : ?>
                <div class="notice notice-<?php echo esc_attr($notice['type']); ?> is-dismissible">
                    <p><?php echo esc_html($notice['text']); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('og_settings_group'); ?>
                <?php do_settings_sections('open-geographies'); ?>
                <?php submit_button(); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Sync', 'open-geographies'); ?></h2>
            <p>
                <?php if ($next_cron) : ?>
                    <?php printf(
                        /* translators: %s = human time diff */
                        esc_html__('Next automatic sync in %s.', 'open-geographies'),
                        esc_html(human_time_diff(time(), $next_cron))
                    ); ?>
                <?php else : ?>
                    <?php esc_html_e('Automatic sync is not scheduled. Deactivate and reactivate the plugin to reschedule.', 'open-geographies'); ?>
                <?php endif; ?>
            </p>
            <form method="post">
                <?php wp_nonce_field('og_run_sync_action', 'og_run_sync_nonce'); ?>
                <?php submit_button(__('Run Sync Now', 'open-geographies'), 'primary', 'og_run_sync', false); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Cache', 'open-geographies'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('og_flush_cache_action', 'og_flush_cache_nonce'); ?>
                <?php submit_button(__('Flush All Cached Responses', 'open-geographies'), 'secondary', 'og_flush_cache', false); ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Shortcode Reference', 'open-geographies'); ?></h2>
            <?php self::render_shortcode_docs(); ?>
        </div>
    <?php
    }

    public static function render_field(array $args): void
    {
        $settings = self::get_settings();
        $key      = $args['key'];
        $type     = $args['type'] ?? 'text';
        $value    = $settings[$key] ?? '';
        $name     = OG_OPTION_KEY . '[' . esc_attr($key) . ']';
        $id       = 'og_' . esc_attr($key);

        if ($type === 'checkbox') {
            printf('<input type="checkbox" id="%s" name="%s" value="1" %s>', $id, esc_attr($name), checked(1, $value, false));
        } else {
            printf('<input type="%s" id="%s" name="%s" value="%s" class="regular-text">', esc_attr($type), $id, esc_attr($name), esc_attr((string) $value));
        }

        if (! empty($args['description'])) {
            echo '<p class="description">' . wp_kses_post($args['description']) . '</p>';
        }
    }

    public static function sanitize_settings(mixed $input): array
    {
        return [
            'endpoint'          => esc_url_raw(trim($input['endpoint']          ?? '')),
            'request_timeout'   => absint($input['request_timeout']              ?? 10) ?: 10,
            'auth_header'       => sanitize_text_field($input['auth_header']     ?? ''),
            'google_maps_api_key' => sanitize_text_field($input['google_maps_api_key'] ?? ''),
            'cache_ttl'         => absint($input['cache_ttl']                    ?? OG_CACHE_TTL),
            'error_message'     => sanitize_text_field($input['error_message']   ?? ''),
            'enable_logging'    => ! empty($input['enable_logging']) ? 1 : 0,
            'cpt_singular'      => sanitize_text_field($input['cpt_singular']    ?? 'Item'),
            'cpt_plural'        => sanitize_text_field($input['cpt_plural']      ?? 'Items'),
            'cpt_slug'          => sanitize_title($input['cpt_slug']             ?? 'og-item'),
            'thumbnail_key'     => sanitize_text_field($input['thumbnail_key']     ?? ''),
            'thumbnail_alt_key' => sanitize_text_field($input['thumbnail_alt_key'] ?? ''),
        ];
    }

    // ── Sync ──────────────────────────────────

    /**
     * Fetch the full list from the API and reconcile against existing og_item posts.
     * Creates posts for new slugs, trashes posts whose slugs have been removed.
     * Returns [ 'created' => int, 'trashed' => int ].
     */
    public static function run_sync(): array
    {
        $result   = ['created' => 0, 'trashed' => 0];
        $settings = self::get_settings();
        $endpoint = rtrim($settings['endpoint'] ?? '', '/');

        if (empty($endpoint)) {
            self::log('error', 'Sync aborted: no endpoint configured.');
            return $result;
        }

        // ── 1. Fetch list from API ────────────

        $http_args = [
            'timeout' => (int) ($settings['request_timeout'] ?? 10),
            'headers' => ['Accept' => 'application/json'],
        ];
        if (! empty($settings['auth_header'])) {
            $http_args['headers']['Authorization'] = $settings['auth_header'];
        }

        self::log('info', 'Sync: fetching list', ['url' => $endpoint]);

        $response = wp_remote_get($endpoint, $http_args);

        if (is_wp_error($response)) {
            self::log('error', 'Sync: list fetch failed', ['error' => $response->get_error_message()]);
            return $result;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            self::log('error', 'Sync: bad status from list endpoint', ['status' => $code]);
            return $result;
        }

        $items = json_decode(wp_remote_retrieve_body($response), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($items)) {
            self::log('error', 'Sync: invalid JSON from list endpoint');
            return $result;
        }

        // Build slug => title map from API response
        $api_slugs = [];
        foreach ($items as $item) {
            if (! empty($item['slug'])) {
                $api_slugs[$item['slug']] = sanitize_text_field($item['name'] ?? $item['slug']);
            }
        }

        self::log('info', 'Sync: API returned items', ['count' => json_encode($api_slugs)]);

        // ── 2. Load existing og_item posts ───

        // Build slug => post_id map from WordPress
        $existing_slugs = [];
        $existing_posts = get_posts([
            'post_type'      => 'og_item',
            'post_status'    => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_key'       => OG_META_SLUG,
        ]);
        foreach ($existing_posts as $post_id) {
            $s = get_post_meta($post_id, OG_META_SLUG, true);
            if ($s) $existing_slugs[$s] = $post_id;
        }

        // ── 3. Create missing posts ───────────

        foreach ($api_slugs as $slug => $title) {
            if (isset($existing_slugs[$slug])) continue;

            $post_id = wp_insert_post([
                'post_type'   => 'og_item',
                'post_title'  => $title,
                'post_name'   => sanitize_title($slug),
                'post_status' => 'publish',
            ]);

            if (is_wp_error($post_id)) {
                self::log('error', 'Sync: failed to create post', ['slug' => $slug, 'error' => $post_id->get_error_message()]);
                continue;
            }

            update_post_meta($post_id, OG_META_SLUG, $slug);
            $result['created']++;
            self::log('info', 'Sync: created post', ['slug' => $slug, 'post_id' => $post_id]);
        }

        // ── 4. Trash removed posts ────────────

        foreach ($existing_slugs as $slug => $post_id) {
            if (isset($api_slugs[$slug])) continue;

            wp_trash_post($post_id);
            $result['trashed']++;
            self::log('info', 'Sync: trashed post', ['slug' => $slug, 'post_id' => $post_id]);
        }

        self::log('info', 'Sync complete', $result);
        return $result;
    }

    // ── API fetching (single item) ────────────

    /**
     * Builds the API URL for the current request.
     * Reads the slug from post meta when on a og_item single — falls back to REQUEST_URI.
     */
    public static function build_api_url(): string
    {
        $settings = self::get_settings();
        $endpoint = rtrim($settings['endpoint'] ?? '', '/');

        $slug = '';

        // Prefer the slug stored in post meta — reliable when a real post exists
        if (is_singular('og_item')) {
            $slug = (string) get_post_meta(get_the_ID(), OG_META_SLUG, true);
        }

        // Fall back to the last segment of REQUEST_URI
        if ($slug === '') {
            $path     = isset($_SERVER['REQUEST_URI'])
                ? wp_parse_url(sanitize_url(wp_unslash($_SERVER['REQUEST_URI'])), PHP_URL_PATH)
                : '';
            $segments = array_filter(explode('/', (string) $path));
            $slug     = ! empty($segments) ? end($segments) : '';
        }

        return $endpoint . ($slug ? '/' . rawurlencode($slug) : '');
    }

    /**
     * Fetch and cache the API payload for the current request.
     */
    public static function fetch_payload(): void
    {
        if (self::$payload !== null || self::$error !== null) return;

        $settings  = self::get_settings();
        $api_url   = self::build_api_url();
        $cache_ttl = (int) ($settings['cache_ttl'] ?? OG_CACHE_TTL);
        $cache_key = 'og_' . md5($api_url);

        if ($cache_ttl > 0) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                self::$payload = $cached;
                return;
            }
        }

        $http_args = [
            'timeout' => (int) ($settings['request_timeout'] ?? 10),
            'headers' => ['Accept' => 'application/json'],
        ];
        if (! empty($settings['auth_header'])) {
            $http_args['headers']['Authorization'] = $settings['auth_header'];
        }

        self::log('info', 'Fetching item', ['url' => $api_url]);
        $response = wp_remote_get($api_url, $http_args);
        self::log_response($response, $api_url);

        if (is_wp_error($response)) {
            self::$error = $response->get_error_message();
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            self::$error = sprintf(__('API returned HTTP %d.', 'open-geographies'), $code);
            return;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            self::$error = __('Invalid JSON received from API.', 'open-geographies');
            return;
        }

        self::$payload = $data;
        if ($cache_ttl > 0) set_transient($cache_key, $data, $cache_ttl);
    }

    // ── Dot-notation resolver ─────────────────

    public static function resolve(string $key): mixed
    {
        self::fetch_payload();
        if (self::$payload === null) return null;
        return self::resolve_in(self::$payload, $key);
    }

    /**
     * Same dot-notation walk as resolve(), but against an arbitrary array rather
     * than the request-wide payload. Used to reach into fields of individual
     * items from an array (e.g. each object in a `resources` list), where the
     * dot-path is relative to that item rather than to the top-level response.
     */
    private static function resolve_in(array $data, string $key): mixed
    {
        $cursor = $data;
        foreach (explode('.', $key) as $part) {
            if (is_array($cursor) && array_key_exists($part, $cursor)) {
                $cursor = $cursor[$part];
            } else {
                return null;
            }
        }
        return $cursor;
    }

    // ── Shortcodes ────────────────────────────

    public static function sc_field(array $atts): string
    {
        $atts  = shortcode_atts(['key' => '', 'fallback' => ''], $atts);
        $value = self::resolve($atts['key']);
        if ($value === null || is_array($value)) return esc_html($atts['fallback']);
        return esc_html((string) $value);
    }

    public static function sc_element(array $atts): string
    {
        $atts  = shortcode_atts(['key' => '', 'tag' => 'div', 'fallback' => '', 'class' => ''], $atts);
        $value = self::resolve($atts['key']);
        if ($value === null || is_array($value)) return esc_html($atts['fallback']);
        return sprintf('<%1$s class="%2$s">%3$s</%1$s>', esc_attr($atts['tag']), esc_attr($atts['class']), esc_html((string) $value));
    }

    public static function sc_html(array $atts): string
    {
        $atts = shortcode_atts([
            'key'             => '',
            'fallback'        => '',
            'read_more'       => 'false',
            'read_more_label' => __('Read more...', 'open-geographies'),
        ], $atts);

        $value = self::resolve($atts['key']);
        if ($value === null || is_array($value)) return wp_kses_post($atts['fallback']);
        $html = wp_kses_post((string) $value);

        if (! filter_var($atts['read_more'], FILTER_VALIDATE_BOOLEAN)) {
            return $html;
        }

        return self::wrap_read_more($html, $atts['read_more_label']);
    }

    /**
     * Splits HTML into <p> paragraphs, leaves the first one visible, and collapses the
     * rest behind a click-to-reveal toggle. Falls back to returning $html unchanged if
     * there's only one paragraph (or none) to begin with — nothing to collapse.
     */
    private static function wrap_read_more(string $html, string $label): string
    {
        preg_match_all('/<p[^>]*>.*?<\/p>/is', $html, $matches);
        $paragraphs = ! empty($matches[0]) ? $matches[0] : [$html];

        if (count($paragraphs) <= 1) {
            return $html;
        }

        $first = array_shift($paragraphs);
        $rest  = implode('', $paragraphs);

        return sprintf(
            '<div class="og-html og-readmore">%1$s<a href="javascript:void(0)" class="og-readmore__toggle" onclick="this.style.display=\'none\';this.nextElementSibling.style.display=\'block\';">%2$s</a><div class="og-readmore__rest" style="display:none;">%3$s</div></div>',
            $first,
            esc_html($label),
            $rest
        );
    }

    public static function sc_list(array $atts): string
    {
        $atts  = shortcode_atts(['key' => '', 'ordered' => 'false', 'class' => 'og-list', 'item_class' => '', 'fallback' => ''], $atts);
        $value = self::resolve($atts['key']);
        if (! is_array($value)) return esc_html($atts['fallback']);
        $tag   = filter_var($atts['ordered'], FILTER_VALIDATE_BOOLEAN) ? 'ol' : 'ul';
        $ic    = $atts['item_class'] ? ' class="' . esc_attr($atts['item_class']) . '"' : '';
        $items = '';
        foreach ($value as $item) {
            $items .= '<li' . $ic . '>' . esc_html(is_array($item) ? wp_json_encode($item) : (string) $item) . '</li>';
        }
        return sprintf('<%1$s class="%2$s">%3$s</%1$s>', $tag, esc_attr($atts['class']), $items);
    }

    public static function sc_table(array $atts): string
    {
        $atts    = shortcode_atts(['key' => '', 'class' => 'og-table', 'headers' => '', 'fallback' => ''], $atts);
        $rows    = self::resolve($atts['key']);
        if (! is_array($rows) || empty($rows)) return esc_html($atts['fallback']);
        $rows    = array_map(fn($r) => is_array($r) ? $r : ['value' => $r], $rows);
        $headers = $atts['headers'] !== '' ? array_map('trim', explode(',', $atts['headers'])) : array_keys(reset($rows));
        $th      = implode('', array_map(fn($h) => '<th>' . esc_html($h) . '</th>', $headers));
        $body    = '';
        foreach ($rows as $row) {
            $cells = '';
            foreach ($headers as $col) {
                $cell   = $row[$col] ?? '';
                $cells .= '<td>' . esc_html(is_array($cell) ? wp_json_encode($cell) : (string) $cell) . '</td>';
            }
            $body .= '<tr>' . $cells . '</tr>';
        }
        return sprintf('<table class="%s"><thead><tr>%s</tr></thead><tbody>%s</tbody></table>', esc_attr($atts['class']), $th, $body);
    }

    /**
     * [og_links key="resources" href_field="link.value" label_field="type.name"]
     * Array of objects rendered as a <ul> of <a> links - for cases like a
     * `resources` array where og_list would just JSON-dump each object and
     * og_table has no way to reach nested fields for its cells.
     */
    public static function sc_links(array $atts): string
    {
        $atts = shortcode_atts([
            'key'                  => '',
            'href_field'           => 'link.value',
            'label_field'          => 'name',
            'label_fallback_field' => '',
            'class'                => 'og-links',
            'item_class'           => '',
            'target'               => '_blank',
            'fallback'             => '',
        ], $atts);

        $items = self::resolve($atts['key']);
        if (! is_array($items) || empty($items)) return esc_html($atts['fallback']);

        $rows = '';
        foreach ($items as $item) {
            if (! is_array($item)) continue;

            $href = self::resolve_in($item, $atts['href_field']);
            if (empty($href) || ! is_string($href)) continue;

            $label = self::resolve_in($item, $atts['label_field']);
            if ((empty($label) || is_array($label)) && $atts['label_fallback_field'] !== '') {
                $label = self::resolve_in($item, $atts['label_fallback_field']);
            }
            if (empty($label) || is_array($label)) {
                $label = $href;
            }

            $target_attr = $atts['target'] !== '' ? ' target="' . esc_attr($atts['target']) . '" rel="noopener"' : '';
            $item_class  = $atts['item_class'] !== '' ? ' class="' . esc_attr($atts['item_class']) . '"' : '';

            $rows .= sprintf(
                '<li%1$s><a href="%2$s"%3$s>%4$s</a></li>',
                $item_class,
                esc_url((string) $href),
                $target_attr,
                esc_html((string) $label)
            );
        }

        if (empty($rows)) return esc_html($atts['fallback']);

        return sprintf('<ul class="%s">%s</ul>', esc_attr($atts['class']), $rows);
    }

    public static function sc_json(array $atts): string
    {
        $atts  = shortcode_atts(['key' => '', 'class' => 'og-json'], $atts);
        $value = $atts['key'] !== '' ? self::resolve($atts['key']) : self::$payload;
        if ($value === null) {
            self::fetch_payload();
            $value = self::$payload;
        }
        return sprintf('<pre class="%s">%s</pre>', esc_attr($atts['class']), esc_html((string) wp_json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)));
    }

    public static function sc_if(array $atts, string $content = ''): string
    {
        $atts      = shortcode_atts(['key' => '', 'equals' => null, 'not' => 'false'], $atts);
        $value     = self::resolve($atts['key']);
        $negate    = filter_var($atts['not'], FILTER_VALIDATE_BOOLEAN);
        $condition = $atts['equals'] !== null ? ((string) $value === $atts['equals']) : ! empty($value);
        if ($negate) $condition = ! $condition;
        return $condition ? do_shortcode($content) : '';
    }

    public static function sc_data(array $atts): string
    {
        $atts  = shortcode_atts(['var' => 'ogData', 'key' => ''], $atts);
        $value = $atts['key'] !== '' ? self::resolve($atts['key']) : self::$payload;
        if ($value === null) {
            self::fetch_payload();
            $value = $atts['key'] !== '' ? self::resolve($atts['key']) : self::$payload;
        }
        return sprintf('<script>var %s = %s;</script>', esc_js($atts['var']), wp_json_encode($value));
    }

    public static function sc_image(array $atts): string
    {
        $atts = shortcode_atts(['key' => '', 'alt_key' => '', 'alt' => '', 'class' => ''], $atts);
        if ($atts['key'] === '') return '';
        $src = self::resolve($atts['key']);
        if (empty($src) || ! is_string($src)) return '';
        $alt = $atts['alt'] === '' && $atts['alt_key'] !== '' ? (string) (self::resolve($atts['alt_key']) ?? '') : $atts['alt'];
        return sprintf('<img src="%s" alt="%s"%s>', esc_url($src), esc_attr($alt), $atts['class'] ? ' class="' . esc_attr($atts['class']) . '"' : '');
    }

    public static function sc_gallery(array $atts): string
    {
        static $counter = 0;

        $atts = shortcode_atts([
            'key'        => '',
            'src_field'  => 'src',
            'alt_field'  => 'alt',
            'class'      => '',
            'loop'       => 'true',
            'pagination' => 'true',
            'navigation' => 'true',
            'autoplay'   => '',
            'fallback'   => '',
        ], $atts);

        if ($atts['key'] === '') return '';

        $items = self::resolve($atts['key']);
        if (! is_array($items) || empty($items)) return esc_html($atts['fallback']);

        $slides = [];
        foreach ($items as $item) {
            if (is_string($item)) {
                $slides[] = ['src' => $item, 'alt' => ''];
            } elseif (is_array($item)) {
                $slides[] = [
                    'src' => (string) ($item[$atts['src_field']] ?? ''),
                    'alt' => (string) ($item[$atts['alt_field']] ?? ''),
                ];
            }
        }
        $slides = array_values(array_filter($slides, fn($s) => $s['src'] !== ''));
        if (empty($slides)) return esc_html($atts['fallback']);

        $inline_style = self::enqueue_swiper_assets();

        $counter++;
        $id = 'og-gallery-' . $counter;

        $show_pagination = filter_var($atts['pagination'], FILTER_VALIDATE_BOOLEAN);
        $show_navigation = filter_var($atts['navigation'], FILTER_VALIDATE_BOOLEAN);
        $autoplay        = absint($atts['autoplay']);

        $config = [
            'loop'       => filter_var($atts['loop'], FILTER_VALIDATE_BOOLEAN),
            'pagination' => $show_pagination ? ['el' => '.swiper-pagination', 'clickable' => true] : false,
            'navigation' => $show_navigation ? ['nextEl' => '.swiper-button-next', 'prevEl' => '.swiper-button-prev'] : false,
        ];
        if ($autoplay > 0) {
            $config['autoplay'] = ['delay' => $autoplay];
        }

        ob_start();
    ?>
        <?php echo $inline_style; ?>
        <div class="<?php echo esc_attr(trim('swiper og-gallery ' . $atts['class'])); ?>" id="<?php echo esc_attr($id); ?>">
            <div class="swiper-wrapper">
                <?php foreach ($slides as $slide) : ?>
                    <div class="swiper-slide"><img src="<?php echo esc_url($slide['src']); ?>" alt="<?php echo esc_attr($slide['alt']); ?>"></div>
                <?php endforeach; ?>
            </div>
            <?php if ($show_pagination) : ?><div class="swiper-pagination"></div><?php endif; ?>
            <?php if ($show_navigation) : ?>
                <div class="swiper-button-prev"></div>
                <div class="swiper-button-next"></div>
            <?php endif; ?>
        </div>
        <script>
            (function() {
                function initOgGallery() {
                    new Swiper('#<?php echo esc_js($id); ?>', <?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
                }
                if (window.Swiper) {
                    initOgGallery();
                } else {
                    document.addEventListener('DOMContentLoaded', initOgGallery);
                }
            })();
        </script>
    <?php
        return ob_get_clean();
    }

    public static function sc_map(array $atts): string
    {
        static $counter = 0;

        $atts = shortcode_atts([
            'key'          => '',
            'zoom'         => '14',
            'height'       => '400px',
            'width'        => '100%',
            'class'        => '',
            'marker_title' => '',
            'fallback'     => '',
        ], $atts);

        if ($atts['key'] === '') return '';

        $geometry = self::resolve($atts['key']);
        $coords   = is_array($geometry) ? ($geometry['coordinates'] ?? null) : null;

        if (
            ! is_array($geometry)
            || ($geometry['type'] ?? '') !== 'Point'
            || ! is_array($coords)
            || ! isset($coords[0], $coords[1])
            || ! is_numeric($coords[0])
            || ! is_numeric($coords[1])
        ) {
            return esc_html($atts['fallback']);
        }

        // GeoJSON coordinates are [longitude, latitude] — Google Maps wants {lat, lng}.
        [$lng, $lat] = $coords;

        $settings = self::get_settings();
        $api_key  = trim((string) ($settings['google_maps_api_key'] ?? ''));

        if ($api_key === '') {
            return current_user_can('manage_options')
                ? esc_html__('Google Maps: no API key configured under Settings → Open Geographies.', 'open-geographies')
                : esc_html($atts['fallback']);
        }

        $marker_title = $atts['marker_title'] !== '' ? (string) (self::resolve($atts['marker_title']) ?? '') : '';

        $inline_script = self::enqueue_google_maps_assets($api_key);

        $counter++;
        $id = 'og-map-' . $counter;

        $config = [
            'lat'   => (float) $lat,
            'lng'   => (float) $lng,
            'zoom'  => (int) $atts['zoom'],
            'title' => $marker_title,
        ];

        ob_start();
    ?>
        <?php echo $inline_script; ?>
        <div class="<?php echo esc_attr(trim('og-map ' . $atts['class'])); ?>" id="<?php echo esc_attr($id); ?>" style="height:<?php echo esc_attr($atts['height']); ?>;width:<?php echo esc_attr($atts['width']); ?>;"></div>
        <script>
            (function() {
                function initOgMap() {
                    var el = document.getElementById(<?php echo wp_json_encode($id); ?>);
                    var cfg = <?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                    var center = {
                        lat: cfg.lat,
                        lng: cfg.lng
                    };
                    var map = new google.maps.Map(el, {
                        center: center,
                        zoom: cfg.zoom
                    });
                    new google.maps.Marker({
                        position: center,
                        map: map,
                        title: cfg.title || undefined
                    });
                }
                if (window.google && window.google.maps) {
                    initOgMap();
                } else {
                    document.addEventListener('og-google-maps-loaded', initOgMap);
                }
            })();
        </script>
<?php
        return ob_get_clean();
    }

    public static function sc_error(array $atts): string
    {
        $atts = shortcode_atts(['class' => 'og-error'], $atts);
        self::fetch_payload();
        if (self::$error === null) return '';
        $settings = self::get_settings();
        $message  = ! empty($settings['error_message']) ? $settings['error_message'] : self::$error;
        return sprintf('<div class="%s">%s</div>', esc_attr($atts['class']), esc_html($message));
    }

    // ── Featured image filter ─────────────────

    public static function filter_thumbnail(string $html, int $post_id): string
    {
        if (get_post_type($post_id) !== 'og_item') return $html;
        self::fetch_payload();
        if (self::$payload === null) return $html;
        $settings  = self::get_settings();
        $image_key = $settings['thumbnail_key'] ?? '';
        if ($image_key === '') return $html;
        $src = self::resolve($image_key);
        if (empty($src) || ! is_string($src)) return $html;
        $alt_key = $settings['thumbnail_alt_key'] ?? '';
        $alt     = $alt_key ? (string) self::resolve($alt_key) : get_the_title($post_id);
        return sprintf('<img src="%s" alt="%s" class="wp-post-image og-api-thumbnail">', esc_url($src), esc_attr($alt));
    }

    // ── Swiper ────────────────────────────────

    public static function enqueue_swiper(): void
    {
        global $post;
        $has_gallery = $post instanceof WP_Post && has_shortcode($post->post_content, 'og_gallery');

        if (! is_singular('og_item') && ! $has_gallery) return;

        self::enqueue_swiper_assets();
    }

    /**
     * Registers/enqueues the Swiper assets. Called eagerly on wp_enqueue_scripts when the
     * shortcode is detectable in post_content, and again from sc_gallery() itself as a
     * guarantee for shortcodes rendered from templates, widgets, or reusable blocks that
     * has_shortcode() above can't see. The JS is safe to enqueue late (in_footer + defer),
     * but if wp_head has already printed, a late style enqueue would never get flushed —
     * in that case we return an inline <link> tag for sc_gallery() to output directly.
     */
    private static function enqueue_swiper_assets(): string
    {
        if (! wp_script_is('swiper', 'enqueued')) {
            wp_enqueue_script('swiper', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js', [], '11', ['strategy' => 'defer', 'in_footer' => true]);
        }

        if (wp_style_is('swiper', 'enqueued')) return '';

        wp_enqueue_style('swiper', 'https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css', [], '11');

        if (! did_action('wp_head')) return '';

        return sprintf('<link rel="stylesheet" id="swiper-css" href="%s">' . "\n", esc_url('https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css'));
    }

    // ── Google Maps ────────────────────────────

    /**
     * Registers/enqueues the Google Maps JS API, keyed to the configured API key. Always
     * called from within sc_map() itself (never from a content-scanning hook) so it fires
     * regardless of whether the shortcode came from post content, a template, or a widget —
     * see the equivalent Swiper fix above for why that distinction matters.
     *
     * Uses Google's own `callback` query param rather than defer/DOMContentLoaded timing:
     * the callback fires exactly when the API is ready, no matter how the script tag loads.
     */
    private static function enqueue_google_maps_assets(string $api_key): string
    {
        if (wp_script_is('og-google-maps', 'enqueued')) return '';

        $src = add_query_arg([
            'key'      => $api_key,
            'loading'  => 'async',
            'callback' => 'ogMapsLoaded',
        ], 'https://maps.googleapis.com/maps/api/js');

        wp_register_script('og-google-maps', $src, [], null, ['strategy' => 'defer', 'in_footer' => true]);
        wp_add_inline_script('og-google-maps', "window.ogMapsLoaded = function () { document.dispatchEvent(new Event('og-google-maps-loaded')); };", 'before');
        wp_enqueue_script('og-google-maps');

        if (! did_action('wp_head')) return '';

        // wp_head already printed for this request, so the enqueue above will never get
        // flushed via the normal hook — print the script (and its "before" callback) directly.
        ob_start();
        wp_print_scripts(['og-google-maps']);
        return ob_get_clean();
    }

    // ── Helpers ───────────────────────────────

    public static function get_settings(): array
    {
        return (array) get_option(OG_OPTION_KEY, []);
    }

    public static function flush_all_cache(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            '_transient_og_%',
            '_transient_timeout_og_%'
        ));
    }

    // ── Logging ───────────────────────────────

    private static function log(string $level, string $message, array $context = []): void
    {
        if (empty(self::get_settings()['enable_logging'])) return;
        $line = sprintf("[%s] [%s] %s %s\n", current_time('Y-m-d H:i:s'), strtoupper($level), $message, $context ? wp_json_encode($context) : '');
        file_put_contents(WP_CONTENT_DIR . '/og-debug.log', $line, FILE_APPEND | LOCK_EX);
    }

    private static function log_response(array|WP_Error $response, string $api_url): void
    {
        if (is_wp_error($response)) {
            self::log('error', 'Request failed', ['url' => $api_url, 'error' => $response->get_error_message()]);
            return;
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > 2000) $body = substr($body, 0, 2000) . '… [truncated]';
        self::log($code >= 400 ? 'error' : 'info', 'Response received', ['url' => $api_url, 'status' => $code, 'body' => $body]);
    }

    // ── Shortcode docs ────────────────────────

    private static function render_shortcode_docs(): void
    {
        $docs = [
            '[og_field key="title"]'                            => 'Plain-text string. Dot-notation supported. Optional <code>fallback</code>.',
            '[og_element key="title" tag="h1" class="hero"]'   => 'Plain-text string wrapped in any HTML element.',
            '[og_html key="description.value"]'                 => 'Raw HTML string, sanitized with <code>wp_kses_post()</code>. Add <code>read_more="true"</code> to show only the first <code>&lt;p&gt;</code> with a click-to-expand toggle for the rest (optional <code>read_more_label</code>).',
            '[og_list key="tags"]'                              => 'Flat array as <code>&lt;ul&gt;</code>. Add <code>ordered="true"</code> for <code>&lt;ol&gt;</code>.',
            '[og_table key="rows"]'                             => 'Array of objects as <code>&lt;table&gt;</code>. Optional <code>headers="A,B,C"</code>.',
            '[og_links key="resources" href_field="link.value" label_field="type.name"]' => 'Array of objects as a <code>&lt;ul&gt;</code> of links. <code>href_field</code>/<code>label_field</code> support dot-notation into each item. Optional <code>label_fallback_field</code>, <code>target</code> (default <code>_blank</code>), <code>item_class</code>.',
            '[og_json key="meta"]'                              => 'Pretty-printed JSON in a <code>&lt;pre&gt;</code>. Dev use only.',
            '[og_if key="is_active"]…[/og_if]'                 => 'Conditional block. Add <code>equals="x"</code> or <code>not="true"</code>.',
            '[og_data var="photos" key="gallery"]'              => 'Writes a JS variable for use in Custom HTML blocks.',
            '[og_image key="images.hero" alt_key="images.alt"]' => 'Renders an <code>&lt;img&gt;</code> from an API URL.',
            '[og_gallery key="photographs"]'                    => 'Renders a Swiper gallery from an array of image URLs or objects. Options: <code>src_field</code>, <code>alt_field</code> (default <code>src</code>/<code>alt</code>), <code>loop</code>, <code>pagination</code>, <code>navigation</code>, <code>autoplay</code> (ms), <code>class</code>, <code>fallback</code>.',
            '[og_map key="geometry"]'                           => 'Renders a Google Map with a marker from a GeoJSON Point geometry. Requires a Google Maps API key under Settings. Options: <code>zoom</code>, <code>height</code>, <code>width</code>, <code>marker_title</code> (a key path for the marker tooltip), <code>class</code>, <code>fallback</code>.',
            '[og_error]'                                        => 'Displays the API error message when the fetch fails.',
        ];
        echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>Shortcode</th><th>Description</th></tr></thead><tbody>';
        foreach ($docs as $sc => $desc) {
            printf('<tr><td><code>%s</code></td><td>%s</td></tr>', esc_html($sc), wp_kses_post($desc));
        }
        echo '</tbody></table>';
    }
}

// ─────────────────────────────────────────────
// 3.  Hooks outside the class
// ─────────────────────────────────────────────

function og_maybe_flush_rewrites(array $old, array $new): void
{
    if (($old['cpt_slug'] ?? '') !== ($new['cpt_slug'] ?? '')) {
        flush_rewrite_rules();
    }
}

// Add a 30-minute interval to WordPress cron
add_filter('cron_schedules', function (array $schedules): array {
    $schedules['og_thirty_minutes'] = [
        'interval' => 30 * MINUTE_IN_SECONDS,
        'display'  => __('Every 30 Minutes', 'open-geographies'),
    ];
    return $schedules;
});

register_activation_hook(__FILE__, function (): void {
    // Register CPT before flushing so its rewrite rules are included
    Open_Geographies::register_cpt();
    flush_rewrite_rules();

    // Schedule the sync cron
    if (! wp_next_scheduled(OG_CRON_HOOK)) {
        wp_schedule_event(time(), 'og_thirty_minutes', OG_CRON_HOOK);
    }

    // Run an initial sync immediately so posts exist right after activation
    Open_Geographies::run_sync();
});

register_deactivation_hook(__FILE__, function (): void {
    wp_clear_scheduled_hook(OG_CRON_HOOK);
    flush_rewrite_rules();
});
