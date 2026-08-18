# Open Geographies — WordPress Plugin

Fetches JSON from a remote API endpoint based on the current page's URL slug, then lets you drop individual fields into any template or page using shortcodes.

---

## Installation

1. Copy the `open-geographies/` folder into `wp-content/plugins/`.
2. Activate **Open Geographies** in *Plugins → Installed Plugins*.
3. Go to **Settings → Open Geographies** and enter your API base endpoint.

---

## Updating via Git Updater

This plugin isn't on wordpress.org — it ships updates straight from this GitHub repo using [Git Updater](https://git-updater.com/). The plugin header already declares what Git Updater needs:

```
GitHub Plugin URI: ecds/open-geographies-wp
Primary Branch:    main
```

### One-time setup (per WordPress site)

1. Install and activate **Git Updater** (`git-updater.zip` from [git-updater.com](https://git-updater.com/) or [github.com/afragen/git-updater](https://github.com/afragen/git-updater)) — this is a separate, standalone plugin. Install it once per site; it will then manage updates for *any* plugin/theme with the right headers, not just this one.
2. Install and activate **Open Geographies** itself (see *Installation* above).
3. That's it. No token is required since this repo is public — Git Updater will pull update metadata anonymously. If you're managing several sites and start hitting GitHub's unauthenticated API rate limit, add a personal access token under **Settings → Git Updater → GitHub** (any token works for a public repo; no special scopes needed).

### Publishing a new version

1. Bump `Version:` in the [open-geographies.php](open-geographies.php) header.
2. Commit and push/merge to `main` (the branch Git Updater is configured to watch — see `Primary Branch` above).
3. On each site, **Plugins → Installed Plugins** will show a normal "update available" notice for Open Geographies as soon as Git Updater's next check runs (or trigger it immediately from **Dashboard → Updates → Check Again**). Click **Update Now** like any other plugin.

Git Updater compares the `Version` header of the installed copy against the `Version` header of the file on `main`, so an update won't appear until that number actually changes — pushing commits without bumping it does nothing.

---

## How the URL is built

| WordPress page URL | Configured endpoint | API request URL |
|---|---|---|
| `https://mysite.com/products/blue-widget` | `https://api.example.com/v1/pages` | `https://api.example.com/v1/pages/blue-widget` |
| `https://mysite.com/about` | `https://api.example.com/v1/pages` | `https://api.example.com/v1/pages/about` |

The plugin takes the **last path segment** of the current request URI and appends it to your configured base endpoint.

---

## Settings

| Setting | Description |
|---|---|
| **API Base Endpoint** | Full base URL, e.g. `https://api.example.com/v1/pages` |
| **Request Timeout** | Seconds before giving up. Default: `10` |
| **Authorization Header Value** | Sent as the `Authorization` HTTP header, e.g. `Bearer abc123` |
| **Cache TTL** | Seconds to cache responses via WordPress transients. Set to `0` to disable. |
| **Custom Error Message** | Shown to visitors if the API call fails |

---

## Example API response

The plugin handles mixed JSON payloads — strings, raw HTML, flat arrays, nested objects, boolean flags:

```json
{
  "title": "Blue Widget",
  "slug": "blue-widget",
  "is_published": true,
  "status": "active",
  "body_html": "<p>This is the <strong>main content</strong> area.</p>",
  "intro_html": "<blockquote>A premium product.</blockquote>",
  "author": {
    "name": "Jane Smith",
    "bio": "Senior product writer."
  },
  "tags": ["widget", "blue", "premium"],
  "features": ["Waterproof", "Scratch-resistant", "5-year warranty"],
  "meta": {
    "seo_title": "Buy Blue Widget | MyStore",
    "seo_description": "The best blue widget on the market."
  },
  "products": [
    { "name": "Blue Widget S", "price": "$9.99",  "stock": "In stock" },
    { "name": "Blue Widget M", "price": "$12.99", "stock": "Low stock" },
    { "name": "Blue Widget L", "price": "$15.99", "stock": "Out of stock" }
  ]
}
```

---

## Shortcode reference

### `[og_field]` — plain text value

```
[og_field key="title"]
[og_field key="author.name"]
[og_field key="meta.seo_title" fallback="Untitled"]
```

- `key` — dot-notation path into the JSON
- `fallback` *(optional)* — text shown when the key is missing

---

### `[og_html]` — raw HTML from the API

```
[og_html key="body_html"]
[og_html key="intro_html"]
```

Rendered through `wp_kses_post()` — safe HTML tags are preserved, dangerous ones stripped.

---

### `[og_list]` — array as `<ul>` or `<ol>`

```
[og_list key="tags"]
[og_list key="features" ordered="true" class="feature-list" item_class="feature-item"]
```

- `ordered` — `"true"` for `<ol>`, default `"false"` for `<ul>`
- `class` — CSS class on the list element (default: `og-list`)
- `item_class` — CSS class on each `<li>`

---

### `[og_table]` — array of objects as `<table>`

```
[og_table key="products"]
[og_table key="products" headers="Name,Price,Stock" class="pricing-table"]
```

- `headers` — comma-separated column labels; defaults to object keys from the first row
- `class` — CSS class on `<table>` (default: `og-table`)

---

### `[og_if]` — conditional rendering

```
[og_if key="is_published"]
  This page is live.
[/og_if]

[og_if key="status" equals="active"]
  Status badge here.
[/og_if]

[og_if key="is_published" not="true"]
  Coming soon…
[/og_if]
```

- `equals` *(optional)* — match a specific string value
- `not` — `"true"` inverts the condition

---

### `[og_json]` — debug dump

```
[og_json]
[og_json key="meta"]
[og_json key="author"]
```

Renders a `<pre>` block with pretty-printed JSON. Remove from production templates.

---

### `[og_gallery]` — Swiper image gallery

```
[og_gallery key="photographs"]
[og_gallery key="photographs" loop="false" navigation="false" autoplay="4000"]
```

Renders a complete [Swiper](https://swiperjs.com/) gallery — slides, pagination dots, and prev/next arrows — directly from an API array. No template markup or inline `<script>` needed; the shortcode outputs the full `.swiper` structure and its own scoped init script, and the plugin automatically enqueues the Swiper JS/CSS on any page where this shortcode is used (or on `og_item` singles).

`key` must resolve to an array of either:

- **plain URL strings**:
  ```json
  "photographs": ["https://cdn.example.com/a.jpg", "https://cdn.example.com/b.jpg"]
  ```
- **objects** with a src/alt pair:
  ```json
  "photographs": [
    { "src": "https://cdn.example.com/a.jpg", "alt": "Front view" },
    { "src": "https://cdn.example.com/b.jpg", "alt": "Side view" }
  ]
  ```

| Attribute | Default | Description |
|---|---|---|
| `key` | — | Dot-notation path to the array (required) |
| `src_field` | `src` | Object key to read the image URL from |
| `alt_field` | `alt` | Object key to read the alt text from |
| `class` | *(none)* | Extra CSS class(es) added to the `.swiper.og-gallery` container |
| `loop` | `true` | Infinite looping |
| `pagination` | `true` | Show clickable pagination dots |
| `navigation` | `true` | Show prev/next arrow buttons |
| `autoplay` | *(off)* | Delay in milliseconds between auto-advances, e.g. `4000` |
| `fallback` | *(empty)* | Text shown when the key is missing, not an array, or empty |

Each gallery on a page gets a unique `#og-gallery-N` id, so multiple `[og_gallery]` instances can coexist on the same page/post.

---

### `[og_error]` — display API errors

```
[og_error]
[og_error class="alert alert-danger"]
```

Outputs nothing when the API call succeeds; shows the error (or your custom message) when it fails.

---

## Full template example

```html
<!-- Page template: page-product.php -->

<?php get_header(); ?>

<main class="product-page">

  <!-- Show API errors at the top -->
  <?php echo do_shortcode('[og_error class="site-alert"]'); ?>

  <!-- Conditional: only render if the API says the page is published -->
  <?php echo do_shortcode('[og_if key="is_published"]'); ?>

    <h1><?php echo do_shortcode('[og_field key="title"]'); ?></h1>
    <p class="byline">By <?php echo do_shortcode('[og_field key="author.name" fallback="Editorial Team"]'); ?></p>

    <!-- Raw HTML content from the API -->
    <div class="entry-content">
      <?php echo do_shortcode('[og_html key="body_html"]'); ?>
    </div>

    <!-- Flat array as a bullet list -->
    <h2>Features</h2>
    <?php echo do_shortcode('[og_list key="features" class="feature-list"]'); ?>

    <!-- Tags as an inline list -->
    <h3>Tags</h3>
    <?php echo do_shortcode('[og_list key="tags" class="tag-cloud"]'); ?>

    <!-- Array of objects as a table -->
    <h2>Available Sizes & Pricing</h2>
    <?php echo do_shortcode('[og_table key="products" class="pricing-table" headers="name,price,stock"]'); ?>

    <!-- Nested object field -->
    <meta name="description" content="<?php echo do_shortcode('[og_field key="meta.seo_description"]'); ?>">

  <?php echo do_shortcode('[/og_if]'); ?>

  <!-- "Coming soon" block shown only when NOT published -->
  <?php echo do_shortcode('[og_if key="is_published" not="true"]'); ?>
    <p class="coming-soon">This page is not yet available.</p>
  <?php echo do_shortcode('[/og_if]'); ?>

</main>

<?php get_footer(); ?>
```

### Using shortcodes directly in the Block Editor / Classic Editor

Paste shortcodes directly into a Shortcode block or the content area:

```
[og_error]

[og_if key="status" equals="active"]
  [og_field key="title"]
  [og_html key="body_html"]
  [og_list key="features"]
  [og_table key="products"]
[/og_if]
```

---

## Caching

API responses are cached using WordPress transients.  
The cache key is an MD5 hash of the full API URL, so each unique page gets its own cache entry.

- Adjust TTL in **Settings → Open Geographies**.
- Click **Flush All Cached Responses** in the settings page to clear immediately.
- Cache is bypassed when TTL is set to `0`.

---

## Security notes

- **Output escaping**: `[og_field]` uses `esc_html()`. `[og_html]` uses `wp_kses_post()` to allow safe HTML while stripping scripts/event handlers.
- **Settings sanitization**: all settings are sanitized on save (`esc_url_raw`, `sanitize_text_field`, `absint`).
- **Nonces**: admin forms use `wp_nonce_field` / `check_admin_referer`.
- **API credentials**: the Authorization header is stored in `wp_options` (encrypted at rest if you use a secrets plugin).

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
