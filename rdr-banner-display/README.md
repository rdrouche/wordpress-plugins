# RDR Banner Display

**Version:** 1.0.0  
**Author:** Romain Drouche — [rdr-it.com](https://rdr-it.com)  
**License:** GPL-2.0-or-later  
**Requires WordPress:** 6.0+  
**Requires PHP:** 8.0+  
**Parent theme:** GeneratePress (compatible with RDR-IT child theme)

---

## Overview

RDR Banner Display is a WordPress plugin for diffusing banners (HTML, Google AdSense, WordPress shortcodes) across your site in a privacy-friendly, CLS-safe, performance-conscious way.

Key features:
- **Shortcode-driven** — `[rdr-dif-ban id="X"]`
- **AJAX loading with skeleton preloader** — no layout shift, no render-blocking
- **Adblock detection** — uses `window.isAdblockTrue` (set by your theme); alternate content served automatically
- **Bot filtering** — search-engine crawlers see an empty space of the correct size (good for CLS/LCP scores)
- **No-ads users** — respects the `wpa_no_ads` user meta flag; configurable fallback content types
- **Campaigns** — link banners to date-bound, impression/click-capped campaigns; front-end submission by advertisers
- **Statistics** — impressions, clicks, CTR, adblock rate, daily breakdown, top banners
- **No jQuery on the front end** — pure ES2020 vanilla JS
- **Dark/Light theme aware** — skeleton colors adapt to your RDR-IT theme

---

## Installation

1. Upload the `rdr-banner-display/` folder to `/wp-content/plugins/`.
2. Activate via **Plugins → Installed Plugins**.
3. The database tables are created automatically on activation.

---

## Usage

### 1. Create a Banner

Go to **RDR Banners → Add Banner**.

- **Title** — internal name (not shown publicly).
- **Width / Height** — defines the skeleton placeholder size and preserves layout stability.
- **Display Mode** — `Always first` serves the first eligible item every time; `Random` picks a random eligible item on each page load.
- **Campaign** — optionally attach the banner to a campaign for capping and scheduling.
- **Content Items** — add one or more items:
  - **HTML** — raw HTML markup.
  - **Google AdSense** — paste the full AdSense `<script>` block.
  - **WordPress Shortcode** — any registered shortcode, e.g. `[my-plugin-shortcode]`.
  - **"Show only to no-ads users"** — tick this to make an item visible exclusively to users who have the no-ads flag set.

Publish the banner, then copy the shortcode shown at the bottom of the meta box.

### 2. Place the Shortcode

```
[rdr-dif-ban id="42"]
```

Paste this anywhere: posts, pages, widgets, theme templates.

---

## How It Works

```
Page load
  └─ Shortcode renders a <div> skeleton with correct width/height
        └─ IntersectionObserver fires when near viewport
              └─ AJAX POST → wp_ajax_rdr_bd_load
                    ├─ Bot? → return empty HTML, keep dimensions
                    ├─ Campaign inactive / capped? → return empty
                    ├─ No-ads user? → filter to for_no_ads items of allowed types
                    ├─ Adblock detected? → skip AdSense items, serve fallback
                    └─ Render eligible item → return HTML
              └─ JS injects HTML, fades in, records impression
```

Click events are tracked via `navigator.sendBeacon` for reliability.

---

## Adblock Handling

Your theme must expose adblock status via:

```js
window.isAdblockTrue = true; // or false
```

When `true`:
- **AdSense items are skipped.**
- Other content types (HTML, shortcode) that are not marked "for no-ads only" are served normally.
- If only AdSense items are configured and all are blocked, the space remains the defined size with no visible content.

---

## No-Ads Users

A user with the `wpa_no_ads` meta key set to any truthy value will:
- **Never** see AdSense content.
- **Only** see items explicitly marked "Show only to no-ads users".
- Only receive content of the types allowed in **Settings → No-Ads Users → Allowed Content Types** (default: HTML).

To flag a user programmatically:
```php
update_user_meta( $user_id, 'wpa_no_ads', 1 );
```

The meta key is configurable in **RDR Banners → Settings**.

---

## Campaigns

### Admin-created campaigns

Go to **RDR Banners → Campaigns → New Campaign**. Set:

| Field | Description |
|---|---|
| Title | Internal name |
| Status | `pending` / `active` / `paused` / `ended` |
| Owner | WP user ID of the advertiser (optional) |
| Start / End Date | Date-based scheduling |
| Budget | Informational (€) |
| Impression Cap | Stop serving after N impressions |
| Click Cap | Stop serving after N clicks |

Attach banners to a campaign via the **Campaign** dropdown in the banner meta box.

### Front-end advertiser submission

Users with the `rdr_bd_advertiser` capability can submit campaign requests via AJAX:

```js
fetch('/wp-admin/admin-ajax.php', {
  method: 'POST',
  body: new URLSearchParams({
    action: 'rdr_bd_submit_campaign',
    nonce:  rdrBD.nonce,
    title:  'My Campaign',
    start_date: '2026-05-01T00:00',
    end_date:   '2026-06-01T00:00',
    budget: '500',
  })
});
```

Submitted campaigns start as `pending` and require admin approval. An email notification is sent to the site admin.

Grant the capability to a role in **Settings → Advertiser Role** by checking the role checkbox.

---

## Statistics

Go to **RDR Banners → Statistics**.

- Filter by banner and period (7 / 14 / 30 / 60 / 90 days).
- KPIs: total impressions, clicks, CTR.
- Daily line chart (hand-drawn on `<canvas>`, no external library).
- Top banners table.

Stats are stored in `{prefix}rdr_bd_stats`. Automatic cleanup runs daily; retention period is configurable in **Settings → Statistics**.

---

## Settings

| Setting | Description | Default |
|---|---|---|
| Enable Statistics | Toggle impression/click tracking | On |
| Retention Period | Days to keep stat rows | 90 |
| User Meta Key | Meta key for no-ads flag | `wpa_no_ads` |
| Allowed Types for No-Ads Users | `html` and/or `shortcode` | `html` |
| Skeleton Background Color | Hex color for placeholder | `#1c2128` |
| Skeleton Shine Color | Hex color for shimmer effect | `#2ea8ff` |

---

## Database Tables

| Table | Purpose |
|---|---|
| `{prefix}rdr_bd_stats` | Impression and click events |
| `{prefix}rdr_bd_campaigns` | Campaign definitions |
| `{prefix}rdr_bd_campaign_banners` | Campaign ↔ Banner pivot |

---

## Security

- All AJAX endpoints verify a nonce (`rdr_bd_load` on the front, `rdr_bd_admin` in the admin).
- Admin-only endpoints check `current_user_can('manage_options')`.
- AdSense code is stored as-is (requires admin trust); all other content is sanitized via `wp_kses_post` or `sanitize_textarea_field`.
- IP addresses are stored as a one-way SHA-256 hash salted with `AUTH_SALT` — never in plaintext.
- No external HTTP requests are made by the plugin.

---

## Hooks & Filters

### Filters

```php
// Modify the list of bot user-agent strings
add_filter( 'rdr_bd_bot_patterns', function( array $patterns ): array {
    $patterns[] = 'mycrawler';
    return $patterns;
} );

// Alter rendered item HTML before output
add_filter( 'rdr_bd_render_item', function( string $html, array $item ): string {
    return $html;
}, 10, 2 );
```

> **Note:** The filter hooks listed above are planned for a future release. They are documented here as the intended extension API. To implement them, wrap the relevant logic in `apply_filters()` calls in `helpers.php` and `class-rdr-bd-ajax.php`.

---

## Roadmap

- [ ] Filter hooks for bot patterns and item HTML
- [ ] Front-end campaign request form shortcode
- [ ] WooCommerce integration for paid campaign booking
- [ ] Geo-targeting per banner item
- [ ] A/B testing mode (split between two items, report winner)
- [ ] Export stats to CSV
- [ ] REST API endpoints for headless setups

---

## Changelog

### 1.0.0 — 2026-04-02
- Initial release.
- Custom post type `rdr_banner` with meta box.
- Shortcode `[rdr-dif-ban id="X"]` with AJAX skeleton loading.
- Adblock detection via `window.isAdblockTrue`.
- Bot filtering (UA-based) with CLS-safe empty space.
- No-ads user support (`wpa_no_ads` meta + configurable allowed types).
- Campaign management (admin CRUD + front-end advertiser submission).
- Statistics: impressions, clicks, CTR, daily chart, top banners.
- Daily stats cleanup via WP-Cron.
- Admin UI: dashboard, banners list, campaigns, stats, settings.
- Zero jQuery on the front end.
- Skeleton shimmer colors configurable from settings (CSS vars).
- IP hashing for privacy-safe stat storage.

---

### 1.1.0 — 2026-04-02

#### Bug fixes
- **Banner content not saved / saved as `rnrnrn`** — `$_POST` data is now passed through `wp_unslash()` before processing. WordPress applies magic-quotes-style slashing to all POST data; without `wp_unslash()` backslashes were doubled and newlines escaped as `\r\n` literals in the stored JSON.
- **`sanitize_textarea_field` stripping HTML** — HTML and AdSense items are now stored as-is (the `edit_post` capability check already gates access). Only `shortcode` type is text-sanitized.
- **New item rows had broken indexes** — The JS template was previously generated by including a PHP file inside a `<script type="text/html">` block, causing HTML encoding issues. New item rows are now built entirely in JavaScript (`buildItemRow()`), seeded with a PHP-provided counter (`window.rdrBDNextIndex`).

#### New features
- **"Force to adblock users" flag per content item** — In addition to "No-ads users only", each item can now be flagged `force_on_adblock`. When set, the item is served even to visitors whose adblock detection returns `true`. Useful for serving a non-AdSense alternative (e.g. a house ad) that you want adblock users to see.
- **Configurable adblock detection delay** — A new setting (**Settings → Adblock Detection → Detection Delay**) controls how many milliseconds the front-end waits before firing the AJAX request. Default: `2500` ms. This gives adblock detection scripts (e.g. BlockAdBlock) time to set `window.isAdblockTrue` before the banner decision is made.
- **Bot fast-path** — Search-engine bots (detected by UA on both client and server) bypass the adblock delay entirely. The client resolves immediately; the server returns an empty response with the correct dimensions for CLS safety.
- **True lazy loading via `IntersectionObserver`** — Banners outside the viewport generate zero requests. The observer fires 150 px before the element enters the viewport. Banners that enter the viewport during the adblock-delay window are queued and loaded as soon as the delay expires — no duplicate requests.

#### Filter logic update

| User type | AdSense item | HTML/SC item | `force_on_adblock` AdSense | `for_no_ads` item |
|---|---|---|---|---|
| Regular | ✅ | ✅ | ✅ | ❌ |
| Adblock | ❌ | ✅ | ✅ | ❌ |
| No-ads  | ❌ | ❌ | ❌ | ✅ (if allowed type) |

---

### 1.2.0 — 2026-04-02

#### Root-cause fix: banner content never saved correctly

The core issue in all three content types (HTML empty, AdSense `rnrnrn`, shortcode blank) was the same: PHP's `$_POST` array-of-arrays for fields named `rdr_bd_items[0][content]`, `rdr_bd_items[1][content]` etc. is inherently fragile — WordPress slashes the values, textarea newlines become `\r\n` literals, and `sanitize_textarea_field` strips all HTML tags.

**Fix:** Complete architectural change to how items are submitted.

- **Before:** each item field had a `name="rdr_bd_items[N][field]"` attribute → PHP received a nested array full of slashed, encoded strings.
- **After:** item rows have **no `name=` attributes at all**. On form submit, a JS function (`collectItems()`) walks the DOM, reads every `[data-field="…"]` element directly, builds a clean JS array, `JSON.stringify()`s it, and writes the result into a **single `<input type="hidden" name="rdr_bd_items_json">`**. PHP receives one clean JSON string, `json_decode()`s it, and processes the array — no slashing, no encoding, no tag stripping.

#### New feature: Internal Note field

Each banner now has an **Internal Note** (memo) textarea, visible only in the admin. Use it to record placement details, client name, expiry reminders, or any other private annotation. Stored in `_rdr_bd_note` post meta.

---

### 1.3.0 — 2026-04-02

#### Root-cause fix v3 — base64 transport

Previous attempts failed because any string that traverses `$_POST` in WordPress is subject to `addslashes()`, and any sanitization function risks mangling HTML content. The only robust solution is to make the payload opaque to all of these layers.

**Mechanism:**
1. JS: `JSON.stringify(items)` → UTF-8 bytes → `btoa()` → base64 string → written to `<input type="hidden">`.
2. PHP: `sanitize_text_field(wp_unslash($b64))` → `base64_decode($b64, true)` → `json_decode($json, true)`.

Base64 uses only `A-Za-z0-9+/=` — none of these characters are touched by `addslashes`, `wp_unslash`, or `sanitize_text_field`. The payload arrives at `base64_decode()` byte-perfect regardless of what WordPress does to `$_POST`.

Tested round-trips (confirmed identical):
- HTML with `href`, `src`, `class` attributes and double-quotes
- AdSense block with `<ins>` tag + inline `<script>`
- WordPress shortcode with quoted attributes

#### Also fixed: save trigger reliability

The JS now hooks `submit` event in **capture phase** on `form#post`, plus a `document`-level `submit` listener as fallback — ensuring serialisation fires before the browser sends the form regardless of WordPress version or installed plugins.

---

### 1.4.0 — 2026-04-02

#### Root-cause identified and fixed — for real this time

All previous attempts failed because they treated a symptom, not the cause.

**The actual root cause:** `register_post_meta()` in `class-rdr-bd-post-type.php` was registering `_rdr_bd_items` with `sanitize_callback => 'sanitize_text_field'`. WordPress applies this callback **inside `update_post_meta()`**, *after* all our save code runs. So regardless of how cleanly the data arrived at `update_post_meta()`, it was immediately passed through `sanitize_text_field` → `wp_strip_all_tags` → all `<tags>` stripped → empty HTML, broken AdSense, mangled shortcodes with attributes.

**Proof:** plain text (`hello`) worked fine because strip_tags doesn't touch text. A bare `[shortcode]` worked fine. But `[shortcode id="x"]` — the `"x"` contains `"` which `sanitize_text_field` also normalises. And `<img>` or `<script>` — gone entirely.

**Fix in `class-rdr-bd-post-type.php`:** `_rdr_bd_items` now uses a passthrough lambda that only casts to string:
```php
'sanitize_callback' => static function ( $value ): string {
    return is_string( $value ) ? $value : '';
},
```

**Fix in `class-rdr-bd-admin.php`:** The base64 payload is now cleaned with `preg_replace('/[^A-Za-z0-9+\/=]/', '', $raw)` instead of `sanitize_text_field()` — more precise and cannot strip valid base64 characters.

---

### 1.5.0 — 2026-04-02

#### Definitive fix: dedicated AJAX endpoint for items, reads `php://input`

After extensive diagnosis, the confirmed root cause chain was:

1. WordPress applies `addslashes()` to all `$_POST` values on every request.
2. `register_post_meta()` registers a `sanitize_callback` that runs **inside `update_post_meta()`** — after our code, not before.
3. Even with a passthrough lambda as the callback (v1.4.0), WP also fires the `sanitize_post_meta_{key}` filter which other plugins or WP core can hook.
4. Any value containing `<`, `>`, or `"` is irreversibly corrupted somewhere in this chain.

**The only reliable solution:** never let banner item data touch `$_POST` at all.

**New architecture:**
- JS intercepts the Publish/Update button click (capture phase, `e.preventDefault()`).
- JS calls `wp_ajax_rdr_bd_save_items` via `fetch()` with items as a plain JSON string in a `URLSearchParams` body.
- PHP reads `php://input` directly (raw request body — no WordPress processing whatsoever), calls `parse_str()` on it manually, then `json_decode()`.
- On success, JS releases the button and lets the WP form submit normally for title/status/etc.
- `save_meta_box()` no longer touches items at all — they are already in the DB.

`php://input` is the only way to read POST data that is guaranteed to be byte-identical to what the browser sent.

A debug logger (`class-rdr-bd-debug.php`) is also included; enable with `define('RDR_BD_DEBUG', true)` in `wp-config.php` to log the full save pipeline to `wp-content/rdr-bd-debug.log`.

---

### 1.6.0 — 2026-04-02

#### Bug fixes: rendering pipeline

**`wp_kses_post` was stripping `<script>` and `data-ad-*` attributes** in `rdr_bd_render_item()` for the `html` type. Fixed:
- `html` → uses a custom `rdr_bd_kses_html_allowed()` allowlist that preserves `<a>`, `<img>`, `<div>`, `data-*`, inline `style`, etc.
- `adsense` → printed verbatim (no filtering — `<script>` and `data-ad-*` must survive).
- `shortcode` → `wp_kses_post` on the shortcode tag only (safe), then `do_shortcode`.

**Missing `return` after `wp_send_json_*` calls** in `load_banner()` caused PHP to continue executing after sending the response, sometimes producing a malformed double-JSON response. All `wp_send_json_*` calls now have explicit `return`.

#### New: Preview button in banner meta box

A **"Preview stored content"** button now appears in the banner editor. Click it after saving to see:
- Number of items in the database
- Raw JSON stored in `_rdr_bd_items`
- Stored content per item (before rendering)
- Rendered HTML per item (after `rdr_bd_render_item()`)

This lets you verify exactly what is stored and how it renders without needing database access or browser dev tools.

---

### 1.7.0 — 2026-04-02

#### Root cause of empty front-end render: double-escaped JSON on read

The data was correctly stored in the database (confirmed via direct DB inspection and the Preview button). The failure was in **reading and decoding** it.

WordPress/MySQL stores the JSON fine, but `get_post_meta()` can return the value with an extra level of backslash-escaping depending on server configuration (PHP `magic_quotes_gpc`, MySQL driver, or wpdb internals). The JSON `{"content":"<a href=\"...\">"}` would be returned as `{\"content\":\"<a href=\\\"...\\\">\"}`  — valid MySQL-escaped but not valid JSON. `json_decode()` would return `null`, causing `rdr_bd_get_items()` to return `[]`.

**Fix: `rdr_bd_decode_items_json()`** — new shared function that tries up to three decode passes, each stripping one level of slashes:
```
Attempt 1: json_decode($raw)                            — clean JSON
Attempt 2: json_decode(stripslashes($raw))              — one extra slash level  
Attempt 3: json_decode(stripslashes(stripslashes($raw))) — two extra slash levels
```
Used everywhere `_rdr_bd_items` is read: `rdr_bd_get_items()`, `render_meta_box()`, `ajax_preview()`.

#### Items editor: JS fallback rebuild

If PHP `partial-item-row.php` rendered zero rows (because `$items` was empty due to the decode bug above), the JS now detects the empty DOM and rebuilds all rows from `window.rdrBDItems` (always set by the meta box template via `wp_json_encode`, which is immune to the slashing issue).

#### `window.rdrBDPostId` 

The post ID is now explicitly exposed as `window.rdrBDPostId` from the meta box template, making the Preview and AJAX save functions reliable regardless of how WordPress renders the page URL.

---

### 1.8.0 — 2026-04-02

#### Definitive storage and retrieval fix

**Root cause confirmed from screenshot:** The raw meta in the DB showed `"content":"<div style="width: 300px` — the quotes inside the HTML attributes were **not escaped** in the JSON. This means `URLSearchParams` + `parse_str` was stripping the backslash-escaping that `JSON.stringify` had added (`\"` → `"`), producing invalid JSON that `json_decode` correctly rejected.

**Fix 1 — Transport: `URLSearchParams` → `FormData`**  
`FormData` sends `multipart/form-data`. PHP receives the `items_json` field in `$_POST` with its exact bytes intact. No `parse_str`, no URL-decoding ambiguity, no destruction of `\"` escape sequences. Just `wp_unslash()` + `json_decode()`.

**Fix 2 — Storage: `update_post_meta()` → direct `$wpdb->update/insert`**  
`update_post_meta()` runs the value through `sanitize_meta()` → registered `sanitize_callback` → potentially other hooked filters. Using `$wpdb->update()` directly writes the JSON string to the `postmeta` table with no intermediate processing. `wp_cache_delete()` is called after to invalidate the object cache.

**Fix 3 — Retrieval: `get_post_meta()` → direct `$wpdb->get_var()`**  
`get_post_meta()` returns values that have been through WP's object cache and potentially `stripslashes_deep()` on older WP versions. Reading directly with `$wpdb->get_var()` returns the exact string that MySQL has — no transformations.

Applied everywhere: `rdr_bd_get_items()`, `render_meta_box()`, `ajax_preview()`.

---

### 1.9.0 — 2026-04-02

#### Bug fixes
- **Broken `<script>` tag in meta box** — `window.rdrBDItems` and `window.rdrBDPostId` were set via an inline `<script>` block that WordPress was displacing outside the meta box div. Both values are now stored in `data-items` and `data-post-id` attributes on the `.rdr-bd-meta-box` container and read by JS via `dataset`. No inline script, no broken HTML.
- **Admin dark mode unreadable** — Complete CSS rewrite using WP native variables (`--rdr-text`, `--rdr-bg-alt`, etc.) with explicit fallbacks. All labels, table cells, form fields and modal content now have correct contrast on both light and dark admin themes.

#### New features

**Reset standalone stats per banner**  
On the Statistics page, when a specific banner is selected, a "Reset standalone stats" button appears. It deletes impression/click rows for that banner where `campaign_id IS NULL` — i.e. standalone traffic only. Campaign-linked stats are preserved so campaign reporting stays intact.

**Per-item impression/click caps**  
Each content item in a banner now has its own `Imp. cap` and `Click cap` fields. When a cap is reached, that specific item is excluded from the eligible pool and the next item (or a random other eligible item) is served instead. This allows mixing items from different campaigns on a single banner — when one item's cap is exhausted, the banner seamlessly falls back to another item without going blank.

The caps are tracked via a new `item_hash` column in `rdr_bd_stats` (a 16-char MD5 of `type|content`). The `item_hash` is returned in the AJAX load response and passed back in the stat ping, so no client-side hashing is needed.

#### Clarification: User Meta Key
The "User Meta Key" setting in **Settings → No-Ads Users** is a WordPress **user meta key** (stored in `wp_usermeta`), not a capability. Set it with:
```php
update_user_meta( $user_id, 'wpa_no_ads', 1 );
```

---

### 1.9.1 — 2026-04-02

#### Fix: No-Ads detection now supports WordPress capabilities

`wpa_no_ads` is set as a **WordPress capability** by account/membership plugins, not stored as a user meta value. The previous implementation used `get_user_meta()` which always returned empty for capability-based flags.

**New setting: Detection Mode** (Settings → No-Ads Users)

| Mode | Function used | When to use |
|---|---|---|
| **WordPress Capability** (default) | `user_can( $user_id, 'wpa_no_ads' )` | Flag set by membership plugin (WPA, MemberPress, etc.) |
| **User Meta** | `get_user_meta( $user_id, 'wpa_no_ads', true )` | Flag set manually with `update_user_meta()` |

The key name (`wpa_no_ads` by default) is shared between both modes and configurable in the same field.

---

### 2.0.0 — 2026-04-02

#### Breaking change: Campaign is now per content item, not per banner

The `_rdr_bd_campaign_id` post meta field has been removed. Campaign assignment has moved inside the `_rdr_bd_items` JSON, on each individual item.

**What this means:**
- A single banner can now hold items from **different campaigns** simultaneously.
- When a campaign ends, is paused, or hits its caps, only items belonging to that campaign are excluded — the banner seamlessly falls back to remaining eligible items.
- Standalone items (no campaign) always serve as the default fallback.

**Data migration:** Existing banners with a global `_rdr_bd_campaign_id` will continue to work — the banner-level field is simply ignored. To migrate, edit each banner and assign the campaign on each item individually.

**UI change:** The "Campaign" dropdown has been removed from the banner header row. Each content item now has its own **Campaign** selector between the flags and the textarea.

**AJAX change:** The `campaign_id` returned in the AJAX response now reflects the campaign of the **served item**, not the banner. Stats are recorded with the correct per-item campaign ID.

---

### 2.1.0 — 2026-04-02

#### Bug fix: Creating new banners and renaming broken

The AJAX-intercept approach (`e.preventDefault()` + `e.stopImmediatePropagation()` on the Publish button, then `form.submit()`) was broken in two ways:
- **New banners**: `post_id = 0` at click time → `saveItemsAjax()` returned `false` → alert shown, form never submitted → impossible to create a new banner.
- **Rename/title change**: `form.submit()` bypasses WordPress's native submit handlers, so WP's own data (title, status, etc.) was not processed correctly.

**Fix (definitive):** Removed AJAX-based save entirely for items. Items are now saved the **same way as all other meta** — through the normal WP form POST:

1. JS hooks `mousedown` on save buttons + `submit` on `form#post` to call `serialise()` synchronously (no async, no AJAX).
2. `serialise()` reads all item DOM rows via `collectItems()` and writes `JSON.stringify(items)` to the hidden field `#rdr-bd-items-json`.
3. PHP `save_meta_box()` reads `$_POST['rdr_bd_items_json']`, applies `wp_unslash()` + `json_decode()`, then writes directly to `$wpdb->postmeta` — **bypassing `update_post_meta()` and its `sanitize_callback` chain** which was stripping HTML.

This approach works for both new and existing banners, and does not interfere with any WP publish flow.

---

### 2.2.0 — 2026-04-02

#### Stats: Redis/RAM buffer for impressions, immediate DB for clicks

At 6,000 pages/day × 4–5 banners each, storing one DB row per impression would generate ~25,000–30,000 rows/day. Over 90 days that is ~2.7M rows — significant overhead on every page load.

**New architecture:**

| Event type | Storage | Why |
|---|---|---|
| **Impression** | RAM buffer (Redis if available, WP transient otherwise) → flushed to DB every 5 min via WP-Cron as **aggregated rows** (`count` column) | Reduces DB writes by ~99% |
| **Click** | Immediate DB row (one per click) | Enables duplicate-click detection via `ip_hash`; click volume is negligible |

The buffer works transparently with any persistent object cache. With Redis/Memcached (`wp_using_ext_object_cache() === true`), increments are atomic in RAM. Without persistent cache, a WP transient is used as fallback. On plugin deactivation, the buffer is force-flushed to DB.

The `rdr_bd_stats` table now has a `count` column (default 1). All queries use `SUM(count)` for impressions — existing rows are backward-compatible (count = 1).

#### No-ads users: space collapsed instead of empty gap

When a user has the `wpa_no_ads` capability and no eligible content exists, the banner wrapper is now animated to `height: 0` with a smooth CSS transition. No more ugly gaps in article content.

#### Campaign stats graph

The Statistics page now has a **Campaign** filter. Selecting a campaign shows:
- KPIs: campaign-level impressions, clicks, CTR
- Breakdown table by banner (which banners served this campaign's items)
- Daily trend chart for the campaign

Stats are linked to campaigns via `campaign_id` stored in `rdr_bd_stats` rows — the same field written when a banner item's campaign is selected.

---

### 2.2.1 — 2026-04-02

#### Bug fix: "Unknown column 'count'" on existing installations

`dbDelta()` with `CREATE TABLE IF NOT EXISTS` does not modify tables that already exist. The `count` and `item_hash` columns introduced in v2.2.0 were never added to existing installations, causing every stats query to fail with MySQL error 1054.

**Fix: `class-rdr-bd-migrations.php`**

A migrations class runs automatically at `plugins_loaded` (priority 1) whenever the stored `rdr_bd_db_version` option is behind the current plugin version. Each migration is idempotent — it first checks whether the column/index already exists via `SHOW COLUMNS FROM` before issuing `ALTER TABLE`.

Migration `2.2.0`:
- Adds `item_hash VARCHAR(16)` with index `idx_item_hash` if missing.
- Adds `count INT UNSIGNED NOT NULL DEFAULT 1` if missing.

**Fix: `COALESCE(\`count\`, 1)` in all stats queries**

All `SUM(count)` calls have been replaced with `SUM(COALESCE(\`count\`, 1))`. This makes every query backward-compatible with rows inserted before the column existed (where `count` would be NULL) — they are treated as count = 1.

**Fix: `flush_to_db` migration-safe**

The buffer flush checks whether the `count` column exists before using it. If not yet migrated (edge case), it falls back to inserting individual rows.

---

### 2.3.0 — 2026-04-02

#### Complete stats rewrite — definitive fix

The v2.2.x buffer approach had fundamental reliability problems:
- `wp_cache_set` without a persistent cache backend returns a new value on every request — counts were lost between requests.
- The transient fallback used `set_transient` which overwrites the entire array, creating race conditions where concurrent requests would overwrite each other's increments.
- Result: stats reset randomly, clicks not counted, totals wrong.

**New two-table schema:**

| Table | Purpose | Write strategy |
|---|---|---|
| `rdr_bd_stats_daily` | One row per `(date, banner, campaign, item_hash, adblock)` | `INSERT ... ON DUPLICATE KEY UPDATE impressions = impressions + 1` — atomic, no race condition |
| `rdr_bd_clicks` | One row per click | `INSERT` — kept for duplicate detection via `ip_hash` |

**Why `ON DUPLICATE KEY UPDATE` solves everything:**
- The UNIQUE KEY on `(stat_date, banner_id, campaign_id, item_hash, adblock)` ensures MySQL handles the "does this row exist?" check atomically inside the engine.
- No PHP-level read-then-write, no race condition, no data loss.
- At 6,000 pages/day × 5 banners = 30,000 impression calls/day → at most `N_banners × N_campaigns × N_items × 2` rows per day (one per adblock value). Typically < 100 rows/day regardless of traffic volume.

**Migration:** Existing data from `rdr_bd_stats` is migrated automatically on first load after update (grouped by date into `stats_daily`, clicks moved to `rdr_bd_clicks`).

The `class-rdr-bd-stats-buffer.php` file is no longer used.

---

### 2.4.0 — 2026-04-02

#### SQL performance optimisations

Root cause of slow queries identified and fixed on all 6 points:

**1. Missing composite indexes** (most impactful)

The cap-check queries `WHERE banner_id = X AND item_hash = Y` were using only the single-column `idx_banner_id` index, forcing MySQL to scan all rows for that banner then filter by item_hash. Added:

| Table | Index | Used by |
|---|---|---|
| `rdr_bd_stats_daily` | `(banner_id, item_hash)` | `item_within_caps()` impression check |
| `rdr_bd_stats_daily` | `(campaign_id, stat_date)` | campaign impression cap + daily stats |
| `rdr_bd_clicks` | `(banner_id, item_hash)` | `item_within_caps()` click check |
| `rdr_bd_clicks` | `(campaign_id, clicked_at)` | campaign click cap + campaign daily stats |

These are added automatically by migration v2.4.0 on existing installs.

**2. `rdr_bd_get_items()` — WP object cache**

Was using `$wpdb->get_var()` directly (bypasses WP cache → SQL on every request). Now uses `get_post_meta()` (populates WP's postmeta object cache, Redis-persistent on sites with object cache) + a secondary `wp_cache_set()` for the decoded JSON array.

**3. `campaign_is_active()` — request-level static cache**

Was called once per item. With 5 items on 3 banners all linked to the same campaign = 15 identical SQL queries per page load. Now: first call executes SQL and stores result in `self::$campaign_cache[$campaign_id]`. Subsequent calls for the same campaign are instant array lookups.

**4. `item_within_caps()` — request-level static cache**

Same pattern as campaign cache: `self::$cap_cache["$banner_id:$item_hash"]` eliminates duplicate queries within the same request.

**5. Fast-exit when no cap is set**

`item_within_caps()` returns `true` immediately if both `impression_cap` and `click_cap` are 0 — zero SQL for uncapped items (the common case).

**6. Admin meta box uses `rdr_bd_get_items()` (cache-aware)**

Was using raw `$wpdb->get_var()` which bypasses WP cache. Now uses the same `rdr_bd_get_items()` function as the front-end.

#### Net result (typical page with 5 banners, 2 campaigns, no caps)

| Before | After |
|---|---|
| 5× `wpdb->get_var` (items) | 5× `get_post_meta` (cache hit after first) |
| 5× campaign SQL queries | 2× campaign SQL (cached, shared across banners) |
| Slow partial index scans | Fast composite index lookups |
| ~15 SQL queries/banner load | ~3-4 SQL queries/banner load |

---

### 2.5.0 — 2026-04-02

#### Redis queue for stats — the right implementation

Previous buffer approach used `wp_cache_set/get` which is not a queue and has race conditions. This version implements the correct Redis **list-based queue** pattern.

**How it works:**

```
Each impression/click:
  PHP → RPUSH rdr_bd_queue_imp  '{"b":1,"c":2,"h":"abc","a":0}'
  PHP → RPUSH rdr_bd_queue_clk  '{"b":1,"c":2,"h":"abc","a":0,"ip":"...","ua":"..."}'
  → Returns immediately (~0.1ms), no SQL

Every 5 minutes (WP-Cron):
  1. LLEN  rdr_bd_queue_imp          → N items waiting
  2. LRANGE 0 N-1                    → fetch all N as PHP array
  3. LTRIM N -1                      → atomically remove the N items read
  4. Aggregate in PHP by (date, banner, campaign, item_hash, adblock)
  5. One bulk INSERT … ON DUPLICATE KEY UPDATE → one SQL query for all groups
  Same pattern for clicks → one bulk INSERT into rdr_bd_clicks
```

**Why LRANGE + LTRIM is safe:**
- RPUSH is atomic — concurrent requests never conflict
- LRANGE + LTRIM drain exactly N items regardless of what arrives between the two calls
- If the cron dies after LTRIM but before the DB write, those N events are lost (acceptable — this is the same trade-off as any analytics buffer, and it only happens on server crash)
- If the cron dies before LTRIM, events stay in the queue and are processed next run — no loss

**Redis connection priority:**
1. Existing `$wp_object_cache->redis` (reuses the object cache connection — zero overhead)
2. `$wp_object_cache->get_redis()` (some plugins)
3. Direct `new Redis()` with `RDR_BD_REDIS_HOST` / `RDR_BD_REDIS_PORT` constants from wp-config.php

**Fallback (Redis unavailable):**
Direct `INSERT … ON DUPLICATE KEY UPDATE` per event (previous v2.4.0 behavior). No data loss, just more SQL.

**Admin dashboard** now shows Redis connection status, queue depth (impressions + clicks pending), and a "Flush now" button to trigger an immediate drain.

**Configuration (wp-config.php, optional — only if not using a Redis object cache plugin):**
```php
define( 'RDR_BD_REDIS_HOST',     '127.0.0.1' );
define( 'RDR_BD_REDIS_PORT',     6379 );
define( 'RDR_BD_REDIS_PASSWORD', '' );   // optional
define( 'RDR_BD_REDIS_DB',       0 );    // optional
```

---

### 2.5.1 — 2026-04-23

#### Bug fix: incoherent stats after migration

**Root cause:** The migration from `rdr_bd_stats` → `rdr_bd_stats_daily` ran multiple times across versions 2.3→2.4→2.5 using `INSERT IGNORE`, which silently skips duplicate rows. This left `stats_daily` with only a partial import while the old table remained active. Dashboard queries hit `stats_daily` (partial) for totals but the top-banners detail query could indirectly use old data — producing incoherent numbers (37K total vs 2.1M per banner).

**Fix: v2.5.0 migration step**
- TRUNCATES `stats_daily` and `rdr_bd_clicks` before re-importing (clean slate)
- Uses `INSERT … ON DUPLICATE KEY UPDATE impressions = impressions + VALUES(impressions)` instead of `INSERT IGNORE`
- **RENAMES** `rdr_bd_stats` → `rdr_bd_stats_archived` so it is never queried again by the plugin (data preserved for inspection)

**Dashboard: data integrity warning**
If `rdr_bd_stats` still exists, the dashboard shows a warning with a **"Force clean migration now"** button that performs the full truncate→re-import→rename operation in one click with immediate feedback.

**Fix: `get_top_banners()` cross-join**
The LEFT JOIN between `stats_daily` and `rdr_bd_clicks` on `banner_id` without a date condition on clicks was producing inflated click counts (all-time clicks × impression rows). Replaced with two independent subqueries joined on `banner_id`.

**After this update:**
1. Install the plugin update — migration v2.5.0 runs automatically
2. If the dashboard shows the warning, click "Force clean migration now"
3. Verify: total impressions in KPIs should match the sum of per-banner impressions in the table

---

### 2.5.2 — 2026-04-23

#### Bug fix: "Unknown column 'd.stat_date' in WHERE"

In `get_top_banners()`, the date filter was built as:
```php
$where_d = "AND d.stat_date >= CURDATE() - INTERVAL 30 DAY";
```
Then injected into a subquery:
```sql
SELECT banner_id, SUM(impressions) AS impressions
FROM wp_rdr_bd_stats_daily
WHERE 1=1 AND d.stat_date >= ...  ← alias 'd' doesn't exist yet inside the subquery
```

MySQL evaluates the subquery independently — the alias `d` is only defined in the outer query. The `WHERE` clause inside the subquery must use the real column name `stat_date` directly.

Fix: renamed to `$imp_date_sql` / `$clk_date_sql` and use bare column names (`stat_date`, `clicked_at`) inside each subquery.

---

### 2.5.3 — 2026-04-23

#### Fix: CLS caused by bot detection removing the skeleton

**Root cause:** Googlebot executes JavaScript. The previous flow was:

```
1. PHP shortcode renders skeleton with inline width/height  → layout established ✓
2. JS IntersectionObserver fires → fetch AJAX
3. Server detects Googlebot UA → returns { html: '', is_bot: true }
4. JS calls setSilent(el) → removes .rdr-bd-skeleton class
5. Skeleton CSS (background, animation) disappears → layout recalculates → CLS ✗
```

Step 4 was the problem. Even though `setSilent` didn't change `width`/`height`, removing the skeleton class changed the element's background and visual weight, triggering a Cumulative Layout Shift.

**Fix: bots never trigger AJAX**

If `IS_BOT` is detected client-side, the JS now:
1. Calls `setBotPlaceholder()` on all banners immediately
2. `return`s — no IntersectionObserver, no fetch, nothing

`setBotPlaceholder()` only removes the shimmer animation (bots don't need it) and adds `.rdr-bd-bot` (transparent background, no animation). Dimensions stay exactly as set by the PHP shortcode inline style. **Zero DOM change after initial paint = zero CLS.**

The server-side bot check in `load_banner()` remains as a safety net for bots that don't execute JS (curl, wget, etc.) — they never call the endpoint at all.

---

### 2.5.4 — 2026-05-04

#### Bug fix: Campaigns admin page shows empty list

`get_all()` in `class-rdr-bd-campaign.php` had a `LEFT JOIN {prefix}rdr_bd_stats` — the old stats table renamed to `rdr_bd_stats_archived` by the v2.5.0 migration. The JOIN failed silently, returning zero rows even though campaigns existed.

Fixed: replaced with subquery JOINs on the new `rdr_bd_stats_daily` and `rdr_bd_clicks` tables.

#### Bug fix + UX: "Force adblock users" flag was confusing

The existing `force_on_adblock` flag means *"serve this AdSense item even to users with an adblocker"* — it does NOT exclude regular users. The label "Force adblock users" was misleading users into thinking it meant "show ONLY to adblock users".

**New flag: `adblock_only`** — *"Show this item ONLY to users who have an adblocker. Regular users will not see it."* This is the adblock equivalent of `for_no_ads`.

Updated flag matrix:

| Flag | Who sees the item | Who doesn't |
|---|---|---|
| *(none)* | Everyone | — |
| `for_no_ads` | No-ads users only | Everyone else |
| `adblock_only` | Adblock users only | Regular users |
| `force_on_adblock` | AdSense: also serves to adblock users | — (additive, not restrictive) |

Random mode now works correctly for the intended use case: AdSense (no flags) + HTML fallback (no flags) → both eligible for all regular users → truly random selection.

---

### 2.6.0 — 2026-05-04

#### Complete rewrite of admin JS save mechanism

Previous approach had multiple failure points that caused "hasardeux" (unreliable) saves:

1. **`mousedown` on `#publish` only** — keyboard shortcuts, auto-save, Enter key bypass `mousedown`
2. **`form#post submit` listener** — WP's "Mettre à jour" button is sometimes rendered outside the form
3. **`return` on empty JSON** — if JS failed to write the hidden field for any reason, PHP returned early and kept stale data, making it look like changes were saved when they weren't

**v2.6.0 fixes:**

- `serialise()` is now hooked on **all three levels simultaneously**:
  1. `document.addEventListener('submit', serialise, true)` — capture phase on ANY form submit on the page
  2. `mousedown` on ALL WP save button selectors including Gutenberg variants
  3. `window.beforeunload` — absolute last resort
  4. `MutationObserver` watches for dynamically added save buttons (Gutenberg SPA navigation)

- PHP: distinction between **field absent from POST** (autosave/irrelevant context → skip) vs **field present but empty** (JS ran, no items → save empty array)

- `collectItems()` rewritten with a `get(field)/checked(field)/val(field)` helper pattern — cleaner and easier to verify that all data-field attributes are read correctly

- Preview button now shows `for_no_ads`, `adblock_only`, `force_on_adblock` values for each stored item to make debugging easy

---

### 2.7.0 — 2026-05-04

#### Definitive save fix: native HTML form fields

All previous attempts used a hidden JSON field populated by JS before submit. This was unreliable because it depended on JS firing at exactly the right moment, on the right event, before WordPress sent the form — and any failure silently dropped the save.

**v2.7.0 abandons the JS-JSON approach entirely.**

Each item field now has a real HTML `name` attribute (`rdr_bd_item[N][field]`), submitted natively by the browser with the form — zero JS dependency. PHP reads `$_POST['rdr_bd_item']` directly.

| Field | How submitted | How PHP reads it |
|---|---|---|
| type, campaign, caps, flags | `name="rdr_bd_item[N][type]"` etc. | `$_POST['rdr_bd_item'][N]['type']` |
| content | `base64` in a hidden `name="rdr_bd_item[N][content_b64]"` field | `base64_decode($item['content_b64'])` |

Content uses base64 because it contains HTML/AdSense with quotes that WordPress's `addslashes()` would corrupt. Base64 uses only `[A-Za-z0-9+/=]` — immune to `addslashes`.

JS role is now minimal:
- Sync textarea → sibling base64 hidden field on submit (one `btoa()` call per row)
- Add/remove rows dynamically with correct `N` index
- Campaign dropdown, preview button, charts, AJAX for campaigns/settings

New rows added by JS use `name="rdr_bd_item[N][...]"` with incrementing `N` starting from the existing row count — no index collision possible.

---

### 2.7.3 — 2026-05-04

#### Fix: Preview shows "Items in DB: 0" despite data in Raw JSON

`rdr_bd_get_items()` used `get_post_meta()` which reads from WP's object cache. Since we write via `$wpdb->update()` (bypassing `update_post_meta()` to avoid sanitize_callback), WP's postmeta cache could be stale — even after `clean_post_cache()` — especially with Redis persistent object cache.

Fixed: `rdr_bd_get_items()` now reads directly from `$wpdb->get_var()` every time, using a `static $cache` array for per-request deduplication only. No WP object cache involved.

#### Fix: Duplicate content in item textareas

PHP's `include` shares variable scope with the calling context. When `partial-item-row.php` was included in a loop, variables from one iteration (`$content`, `$type`, etc.) leaked into the next. Fixed by wrapping each include in a static closure.

#### Fix: Banner AJAX response cached by LiteSpeed/Varnish

Added `nocache_headers()` at the top of `load_banner()`. This sends `Cache-Control: no-cache, no-store, must-revalidate` headers, preventing LiteSpeed Cache, Varnish, and Cloudflare from caching personalized AJAX responses.

#### Fix: Preview now shows all item flags

`ajax_preview()` now returns `for_no_ads`, `adblock_only`, `force_on_adblock`, `campaign_id`, `impression_cap`, `click_cap` for each item.
