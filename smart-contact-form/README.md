# Smart Contact Form

**Version:** 1.0.0  
**Requires WordPress:** 6.0+  
**Requires PHP:** 8.0+  
**jQuery-free:** ✅ Yes — zero jQuery dependency (front-end and admin)  
**License:** GPL-2.0+

---

## Description

A robust, developer-friendly contact form plugin for WordPress. Built with performance and security in mind: no jQuery, async loading, multi-layer spam protection, and full dark/light theme support via CSS custom properties.

---

## Features

### Anti-spam — 6 layers
| Layer | Method | Notes |
|---|---|---|
| 1 | **WordPress Nonce** | Standard WP security token |
| 2 | **Honeypot** | Hidden field, invisible to humans |
| 3 | **Minimum fill time** | Bots submit too fast (configurable, default 3 s) |
| 4 | **Rate limiting** | Per IP + form, DB-backed sliding window |
| 5 | **reCAPTCHA v3** | Invisible score-based check (optional) |
| 6 | **Akismet** | If plugin active and API key configured |

Spam submissions are silently accepted (no 403) to avoid bot fingerprinting, but stored with `status = 'spam'` and never emailed.

### Forms
- Unlimited forms via custom post type (`scf_form`)
- Drag-to-reorder field builder (native HTML5 drag, no jQuery UI)
- Field types: `text`, `email`, `tel`, `textarea`, `select`, `number`, `url`
- Per-field: label, placeholder, required toggle
- Shortcode: `[smart_contact_form id="123"]` or alias `[contact_form id="123"]`
- Form HTML loaded asynchronously on page load (no render-blocking)
- Submission via `fetch()` — no page reload

### Submissions
- All submissions stored in `wp_scf_submissions` (DB)
- Statuses: `new` → `read` → `replied` / `spam`
- Auto-mark as read when detail view opened
- Reply directly from admin (sent via `wp_mail`, logged with history)
- Filter tabs per status with live counts
- Unread badge in WP admin menu (like WP Comments)
- Pagination

### Email notifications
- Per-form recipient address
- Customisable subject with `{scf_name}`, `{scf_email}`, `{scf_message}` etc.
- Optional auto-confirmation email to submitter
- HTML email with site branding

### Integrations
- **Polylang** — forms registered as translatable CPT; `pll_get_post()` resolves correct translation automatically
- **Akismet** — detected at runtime, no hard dependency
- **reCAPTCHA v3** — optional, configured in Settings

### Performance
- **Zero jQuery** on both front-end and admin
- **DB check cache** — two levels:
  - PHP `static` variable: zero overhead after first call within the same request
  - WP `transient` (autoloaded, 24 h TTL): `SHOW TABLES` never runs on normal page loads once tables exist
  - Cache invalidated automatically on plugin activate/deactivate/force-repair
- Admin drag-and-drop uses native HTML5 Drag API
- Front-end uses `fetch()` + `FormData` (ES2015, supported everywhere)
- Public CSS inherits all colours from the RDR-IT theme CSS variables (`--rdr-*`) — no palette duplication, automatic dark/light mode support

---

## Installation

1. Upload `smart-contact-form.zip` via **Plugins → Add New → Upload**
2. Activate the plugin
3. Go to **Contact Forms → 🔧 Tools** to verify DB tables were created
4. If tables are missing, click **Force Create / Repair Tables**

> The plugin also runs a table existence check on every boot (`plugins_loaded`) as a safety net against missed activation hooks.

---

## Usage

### Create a form
1. **Contact Forms → Add New**
2. Set a title, configure fields in the **Form Fields** metabox
3. Set recipient email in **Email Notifications**
4. Copy the shortcode from the **Shortcode** metabox

### Embed a form
```
[smart_contact_form id="123"]
[smart_contact_form id="123" title="0" class="my-wrapper"]
```

**Attributes:**

| Attribute | Default | Description |
|---|---|---|
| `id` | — | Form post ID (required) |
| `title` | `1` | Show form title above (`0` to hide) |
| `class` | — | Extra CSS class on wrapper |

### Configure reCAPTCHA v3
1. Get keys at [google.com/recaptcha/admin](https://www.google.com/recaptcha/admin) (choose v3)
2. **Contact Forms → Settings** → paste Site Key + Secret Key
3. Adjust score threshold (0.0 = bot, 1.0 = human; default 0.5)

---

## CSS integration

The public stylesheet (`scf-public.css`) uses only the theme's CSS custom properties:

```css
--rdr-accent        /* primary colour */
--rdr-accent-2      /* secondary / success */
--rdr-text-primary
--rdr-text-secondary
--rdr-text-muted
--rdr-bg-accent
--rdr-border
```

This means the form inherits dark/light mode automatically from `[data-theme="dark|light"]` on `<html>`. Inputs, labels and the submit button are styled by the theme's global rules — no duplication.

---

## Database tables

| Table | Purpose |
|---|---|
| `wp_scf_submissions` | All form submissions |
| `wp_scf_rate_limit` | Per-IP flood protection |

Both are created with `CREATE TABLE IF NOT EXISTS` (safe to re-run). Go to **Contact Forms → 🔧 Tools** to check status and repair if needed.

---

## Hooks

```php
// After a valid (non-spam) submission
do_action( 'scf_after_submission', int $form_id, array $sanitized_data );
```

---

## Changelog

### 1.1.0
- Two-level DB check cache: static variable (zero cost after first call) + WP transient (autoloaded, 24 h TTL)
- `SHOW TABLES` queries only run on first boot or when tables are missing — zero DB overhead on normal page loads
- Cache invalidated automatically on activate/deactivate/force-repair

### 1.0.0
- Initial release
- Zero jQuery (front-end + admin)
- 6-layer spam protection
- Polylang + Akismet + reCAPTCHA v3 integration
- DB diagnostic page with force-repair
- Admin reply system with history
- Unread badge in WP menu
- CSS inherits RDR-IT theme tokens
