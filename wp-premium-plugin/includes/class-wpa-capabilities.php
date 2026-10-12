<?php
defined('ABSPATH') || exit;

/**
 * WPA_Capabilities — sync WPA permissions to native WP user capabilities.
 *
 * Any plugin can then use standard WP API:
 *   current_user_can('wpa_no_ads')
 *   current_user_can('wpa_premium')
 *   current_user_can('wpa_early_access')
 *
 * Mapping stored in wp_options as 'wpa_capability_map':
 *   ['no_ads' => 'wpa_no_ads', 'early_access' => 'wpa_early_access', ...]
 *
 * wpa_premium is always added when any premium role is active.
 *
 * Sync is triggered:
 *   - On role grant
 *   - On role revoke
 *   - On user delete (cleanup)
 *   - Via daily cron (catch expirations)
 */
class WPA_Capabilities {

    /**
     * Default mapping — permission slug → WP capability.
     * Prefixed with wpa_ to avoid conflicts with any other plugin.
     */
    public const DEFAULT_MAP = [
        'no_ads'           => 'wpa_no_ads',
        'early_access'     => 'wpa_early_access',
        'export_md'        => 'wpa_export_md',
        'flux_6'           => 'wpa_flux_6',
        'sync'             => 'wpa_sync',
        'priority_support' => 'wpa_priority_support',
    ];

    /**
     * Generic capability always granted to any premium user.
     * Useful for a simple current_user_can('wpa_premium') check.
     */
    public const CAP_PREMIUM = 'wpa_premium';

    public static function init(): void {
        // Sync on cron — catch role expirations
        add_action('wpa_daily_cron', [self::class, 'sync_all_expired']);
        // Declare all wpa_ capabilities in subscriber so other plugins see them
        add_action('init', [self::class, 'declare_in_subscriber'], 1);
        // One-time migration — only runs if transient not set (once per day max)
        if (!get_transient('wpa_migration_done')) {
            add_action('init', [self::class, 'maybe_migrate_role_definitions'], 2);
        }
        // ensure_wp_roles only if transient says roles are missing
        if (!get_transient('wpa_wp_roles_ok')) {
            add_action('init', [self::class, 'ensure_wp_roles'], 3);
        }
    }

    /**
     * Declare all WPA capabilities in the WP 'subscriber' role with value false.
     * This makes them visible to any plugin that scans wp_user_roles / get_role().
     * Called on 'init' and on plugin activation.
     * Capabilities are set to false — they are only granted per-user via sync_user().
     */
    /**
     * Migrate existing roles from role_perms into role_definitions.
     * Runs once on init if role_definitions is empty.
     * Handles upgrades from older plugin versions.
     */
    public static function maybe_migrate_role_definitions(): void {
        global $wpdb;
        $def_table  = $wpdb->prefix . 'wpa_role_definitions';
        $perm_table = $wpdb->prefix . 'wpa_role_perms';

        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$def_table'") !== $def_table) {
            set_transient('wpa_migration_done', 1, DAY_IN_SECONDS);
            return;
        }

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $def_table");
        if ($count === 0) {
            // Seed role_definitions from role_perms
            $wpdb->query("INSERT IGNORE INTO $def_table (role) SELECT DISTINCT role FROM $perm_table");
            foreach (array_keys(WPA_Roles::BUILTIN_PERMISSIONS) as $role) {
                $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $def_table (role) VALUES (%s)", $role));
            }
        }

        // Also migrate capabilities table if empty
        $cap_table = $wpdb->prefix . 'wpa_capabilities';
        if ($wpdb->get_var("SHOW TABLES LIKE '$cap_table'") === $cap_table) {
            $cap_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $cap_table");
            if ($cap_count === 0) {
                $cap_map = self::DEFAULT_MAP;
                foreach (WPA_Roles::ALL_PERMISSIONS as $slug => $label) {
                    $wp_cap = $cap_map[$slug] ?? ('wpa_' . $slug);
                    $wpdb->query($wpdb->prepare(
                        "INSERT IGNORE INTO $cap_table (slug, label, wp_cap, is_builtin) VALUES (%s, %s, %s, 1)",
                        $slug, $label, $wp_cap
                    ));
                }
            }
        }

        // Mark migration as done — won't run again for 24h
        set_transient('wpa_migration_done', 1, DAY_IN_SECONDS);
    }

    /**
     * Lightweight check — create WP roles if any are missing.
     * Only calls sync_wp_roles() when needed.
     */
    public static function ensure_wp_roles(): void {
        $roles  = WPA_Roles::get_all_defined_roles();
        $missing = false;
        foreach ($roles as $role) {
            if ($role === 'admin') continue;
            if (!get_role('wpa_' . $role)) { $missing = true; break; }
        }
        if ($missing) {
            self::sync_wp_roles();
        } else {
            // All roles present — cache result for 1 hour
            set_transient('wpa_wp_roles_ok', 1, HOUR_IN_SECONDS);
        }
    }

    public static function declare_in_subscriber(): void {
        $subscriber = get_role('subscriber');
        if (!$subscriber) return;

        $caps = array_values(self::get_map());
        $caps[] = self::CAP_PREMIUM;

        foreach ($caps as $cap) {
            // Only add if not already declared — avoids unnecessary DB writes
            if (!isset($subscriber->capabilities[$cap])) {
                $subscriber->add_cap($cap, false);
            }
        }
    }

    /**
     * Remove all WPA capability declarations from subscriber role.
     * Called on plugin deactivation — clean uninstall.
     */
    public static function undeclare_from_subscriber(): void {
        $subscriber = get_role('subscriber');
        if (!$subscriber) return;

        $caps = array_values(self::get_map());
        $caps[] = self::CAP_PREMIUM;

        foreach ($caps as $cap) {
            $subscriber->remove_cap($cap);
        }
    }

    // =========================================================================
    // Get capability map
    // =========================================================================

    /**
     * Get the active mapping from wp_options.
     * Merges defaults with any admin customisation.
     */
    public static function get_map(): array {
        $stored = get_option('wpa_capability_map', []);
        // Merge — stored values override defaults, new default keys are added
        return array_merge(self::DEFAULT_MAP, $stored);
    }

    // =========================================================================
    // Sync a single user
    // =========================================================================

    /**
     * Recalculate and apply WP capabilities for a user based on active WPA roles.
     * Called after every grant or revoke.
     */
    public static function sync_user(int $user_id): void {
        $user = get_user_by('ID', $user_id);
        if (!$user) return;

        $map         = self::get_map();
        $all_wpa_caps = array_values($map);
        $all_wpa_caps[] = self::CAP_PREMIUM;

        // Collect all active permissions for this user
        $active_permissions = self::get_active_permissions($user_id);
        $is_premium         = WPA_Roles::is_premium($user_id);

        foreach ($map as $permission => $capability) {
            if (in_array($permission, $active_permissions, true)) {
                // Grant WP capability
                $user->add_cap($capability, true);
            } else {
                // Remove WP capability — only if it's a WPA-managed one
                $user->remove_cap($capability);
            }
        }

        // Generic wpa_premium capability
        if ($is_premium) {
            $user->add_cap(self::CAP_PREMIUM, true);
        } else {
            $user->remove_cap(self::CAP_PREMIUM);
        }
    }

    /**
     * Remove ALL WPA capabilities from a user.
     * Called on full revoke or account deletion.
     */
    public static function remove_all(int $user_id): void {
        $user = get_user_by('ID', $user_id);
        if (!$user) return;

        $map = self::get_map();
        foreach (array_values($map) as $capability) {
            $user->remove_cap($capability);
        }
        $user->remove_cap(self::CAP_PREMIUM);
    }

    // =========================================================================
    // Collect active permissions
    // =========================================================================

    /**
     * Get all active permissions for a user across all non-expired roles.
     */
    private static function get_active_permissions(int $user_id): array {
        $roles = WPA_Roles::get_roles($user_id);

        if (empty($roles)) return [];

        // Admin wildcard
        if (in_array('admin', $roles, true)) {
            return array_keys(WPA_Roles::ALL_PERMISSIONS);
        }

        $permissions = [];
        foreach ($roles as $role) {
            $perms = WPA_Roles::get_role_permissions($role);
            $permissions = array_merge($permissions, $perms);
        }

        return array_unique($permissions);
    }

    // =========================================================================
    // WP native role sync — makes WPA roles visible in LiteSpeed, etc.
    // =========================================================================

    /**
     * Register all WPA roles as native WP roles.
     * Prefix with wpa_ to avoid conflicts.
     * Called on activation and when roles change.
     */
    public static function sync_wp_roles(): void {
        $all_roles  = WPA_Roles::get_all_defined_roles();
        $cap_map    = self::get_map();

        foreach ($all_roles as $role) {
            if ($role === 'admin') continue; // skip — maps to WP admin

            $wp_role_slug = 'wpa_' . $role;
            $wp_role_name = ucwords(str_replace('_', ' ', $role));

            // Build capability array for this role
            $perms = WPA_Roles::get_role_permissions($role);
            $caps  = [self::CAP_PREMIUM => true]; // always grant wpa_premium

            foreach ($perms as $perm) {
                $wp_cap = $cap_map[$perm] ?? ('wpa_' . $perm);
                $caps[$wp_cap] = true;
            }

            // Create or update the WP role
            $existing = get_role($wp_role_slug);
            if ($existing) {
                // Update capabilities
                foreach ($caps as $cap => $grant) {
                    $existing->add_cap($cap, $grant);
                }
            } else {
                add_role($wp_role_slug, $wp_role_name, $caps);
            }
        }
    }

    /**
     * Remove WPA-generated WP roles.
     * Called on plugin deactivation.
     */
    public static function remove_wp_roles(): void {
        $all_roles = WPA_Roles::get_all_defined_roles();
        foreach ($all_roles as $role) {
            remove_role('wpa_' . $role);
        }
    }

    /**
     * Assign the matching WP role to a user when a WPA role is granted.
     * Called after WPA_Roles::grant().
     */
    public static function assign_wp_role(int $user_id, string $wpa_role): void {
        if ($wpa_role === 'admin') return;

        $wp_role_slug = 'wpa_' . $wpa_role;
        if (!get_role($wp_role_slug)) {
            self::sync_wp_roles(); // ensure role exists
        }

        $user = get_user_by('ID', $user_id);
        if (!$user) return;

        // Add the WPA WP role (keep subscriber role intact)
        $user->add_role($wp_role_slug);
    }

    /**
     * Remove WPA WP roles from a user when WPA role is revoked.
     * Keeps subscriber role intact.
     */
    public static function remove_wp_role(int $user_id, string $wpa_role): void {
        $user = get_user_by('ID', $user_id);
        if (!$user) return;
        $user->remove_role('wpa_' . $wpa_role);
    }

    /**
     * Remove ALL WPA WP roles from a user.
     */
    public static function remove_all_wp_roles(int $user_id): void {
        $user = get_user_by('ID', $user_id);
        if (!$user) return;
        foreach ($user->roles as $role) {
            if (str_starts_with($role, 'wpa_')) {
                $user->remove_role($role);
            }
        }
    }

    // =========================================================================
    // Bulk sync — cron + admin action
    // =========================================================================


    /**
     * Sync capabilities for all users whose premium has just expired.
     * Runs daily via wpa_daily_cron.
     */
    public static function sync_all_expired(): void {
        global $wpdb;

        // Find users whose last role expired in the last 48h
        $user_ids = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$wpdb->prefix}wpa_roles
             WHERE expires_at IS NOT NULL
               AND expires_at BETWEEN DATE_SUB(NOW(), INTERVAL 48 HOUR) AND NOW()"
        );

        foreach ($user_ids as $user_id) {
            self::sync_user((int) $user_id);
        }
    }

    /**
     * Full resync of ALL premium users — available as admin action.
     * Useful after a bulk import or map change.
     */
    public static function sync_all(): int {
        global $wpdb;

        $user_ids = $wpdb->get_col(
            "SELECT DISTINCT user_id FROM {$wpdb->prefix}wpa_roles
             WHERE expires_at IS NULL OR expires_at > NOW()"
        );

        foreach ($user_ids as $user_id) {
            self::sync_user((int) $user_id);
        }

        return count($user_ids);
    }
}
