<?php
/**
 * Plugin Name: WP Premium Access
 * Plugin URI:  https://yoursite.com
 * Description: Premium access management via magic link, Ko-fi webhooks, multi-role system, dark/light theme support, Polylang compatible.
 * Version:     1.0.0
 * Author:      Your Name
 * Text Domain: wp-premium-access
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

define('WPA_VERSION',     '1.1.0');
define('WPA_PLUGIN_DIR',  plugin_dir_path(__FILE__));
define('WPA_PLUGIN_URL',  plugin_dir_url(__FILE__));
define('WPA_TEXT_DOMAIN', 'wp-premium-access');

// --- Autoload includes ---
$includes = [
    'includes/class-wpa-install.php',
    'includes/class-wpa-roles.php',
    'includes/class-wpa-sessions.php',
    'includes/class-wpa-magic-link.php',
    'includes/class-wpa-templates.php',
    'includes/class-wpa-kofi-webhook.php',
    'includes/class-wpa-auth.php',
    'includes/class-wpa-content.php',
    'includes/class-wpa-sync.php',
    'includes/class-wpa-shortcodes.php',
    'includes/class-wpa-widgets.php',
    'includes/class-wpa-admin.php',
    'includes/class-wpa-mailer.php',
    'includes/class-wpa-capabilities.php',
    'includes/class-wpa-stripe.php',
    'includes/class-wpa-api.php',
    'includes/class-wpa-sso.php',
    'includes/class-wpa-diagnostic.php',
    'includes/class-wpa-i18n.php',
];

foreach ($includes as $file) {
    require_once WPA_PLUGIN_DIR . $file;
}

// --- Activation / Deactivation ---
register_activation_hook(__FILE__, function () {
    WPA_Install::activate();
    WPA_Install::migrate_roles_unique_key(); // add UNIQUE KEY if missing
    WPA_Capabilities::declare_in_subscriber();
    WPA_Capabilities::sync_wp_roles();
});

register_deactivation_hook(__FILE__, function () {
    WPA_Install::deactivate();
    WPA_Capabilities::undeclare_from_subscriber();
    WPA_Capabilities::remove_wp_roles();
});

// --- One-time migrations (version-gated, runs once then stops) ---
add_action('plugins_loaded', function () {
    if (!get_transient('wpa_migrated_roles_unique_key')) {
        WPA_Install::migrate_roles_unique_key();
        set_transient('wpa_migrated_roles_unique_key', 1, 0); // 0 = no expiry
    }
}, 2);

// --- Daily expiry cleanup ---
add_action('wpa_daily_cron', ['WPA_Roles', 'cleanup_expired'], 5);

// --- WP admin bypass ---
// WordPress admins (manage_options) bypass all WPA permission checks.
// This means: editors, admins can preview scheduled posts, see gated content,
// and are returned as "premium" by the REST API and SSO endpoints.
// No SESSION tricks — uses native WP capabilities, works everywhere.

if (!function_exists('wpa_current_user_is_admin')) {
    function wpa_current_user_is_admin(): bool {
        // During REST/AJAX — get_current_user_id() works if WP auth cookie present
        return is_user_logged_in() && current_user_can('manage_options');
    }
}

if (!function_exists('wpa_user_is_admin')) {
    function wpa_user_is_admin(int $user_id): bool {
        if (!$user_id) return false;
        $user = get_user_by('ID', $user_id);
        return $user && $user->has_cap('manage_options');
    }
}

// --- Boot ---
add_action('plugins_loaded', function () {
    WPA_i18n::load();
    WPA_Auth::init();
    WPA_Content::init();
    WPA_Shortcodes::init();
    WPA_Widgets::init();
    WPA_KofiWebhook::init();
    WPA_Mailer::init();
    WPA_Capabilities::init();

    // Re-declare in subscriber when capability map changes
    add_action('update_option_wpa_capability_map', function () {
        WPA_Capabilities::declare_in_subscriber();
        WPA_Capabilities::sync_wp_roles();
    });
    WPA_Stripe::init();
    WPA_API::init();
    WPA_SSO::init();

    // -------------------------------------------------------------------------
    // Public filter API for external plugins
    // -------------------------------------------------------------------------

    /**
     * wpa_register_capabilities — let any plugin declare custom WPA capabilities.
     *
     * Usage in your plugin:
     *   add_filter('wpa_register_capabilities', function ($caps) {
     *       $caps['wpa_submit_expert_profile'] = __('Submit expert profile', 'my-plugin');
     *       return $caps;
     *   });
     *
     * The cap will be:
     *   - Added to wp_wpa_capabilities table (visible in Roles & Capabilities UI)
     *   - Declared on the subscriber role (so current_user_can() works)
     *   - Assignable to any WPA role via the admin UI
     */
    $extra_caps = apply_filters('wpa_register_capabilities', []);
    if (!empty($extra_caps)) {
        foreach ($extra_caps as $slug => $label) {
            $slug  = sanitize_key($slug);
            $label = sanitize_text_field($label);
            if (!$slug) continue;

            // Insert into capabilities table if not present
            global $wpdb;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}wpa_capabilities WHERE slug = %s", $slug
            ));
            if (!$exists) {
                $wpdb->insert(
                    $wpdb->prefix . 'wpa_capabilities',
                    ['slug' => $slug, 'label' => $label, 'wp_cap' => $slug, 'is_builtin' => 0],
                    ['%s','%s','%s','%d']
                );
            }

            // Declare on subscriber so current_user_can() can work
            $subscriber = get_role('subscriber');
            if ($subscriber && !isset($subscriber->capabilities[$slug])) {
                $subscriber->add_cap($slug, false); // false = not granted by default
            }
        }
    }

    add_filter('wpa_get_user_expiry', function ($default, $user_id) {
        return WPA_Auth::get_nearest_expiry((int) $user_id);
    }, 10, 2);

    add_filter('wpa_is_premium', function ($default, $user_id) {
        return WPA_Roles::is_premium((int) $user_id);
    }, 10, 2);

    add_filter('wpa_user_permissions', function ($default, $user_id) {
        return WPA_Roles::get_user_permissions((int) $user_id);
    }, 10, 2);

    if (is_admin()) {
        WPA_Admin::init();
        WPA_Diagnostic::init();
    }
});
