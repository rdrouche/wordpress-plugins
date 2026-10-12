<?php
defined('ABSPATH') || exit;

class WPA_Roles {

    /**
     * Built-in roles — seeded into wp_wpa_role_perms on activation.
     * Source of truth for admin UI display and initial seed.
     * Runtime permissions are always read from the DB table.
     */
    public const BUILTIN_PERMISSIONS = [
        'premium_basic'    => ['no_ads', 'early_access'],
        'premium_pro'      => ['no_ads', 'early_access', 'export_md', 'flux_6', 'sync'],
        'premium_lifetime' => ['no_ads', 'early_access', 'export_md', 'flux_6', 'sync', 'priority_support'],
        'editor'           => ['no_ads', 'early_access', 'export_md'],
        'admin'            => ['*'],
    ];

    /**
     * Built-in permissions — used for seeding only.
     * Runtime: always use get_all_capabilities() which reads from DB.
     */
    public const ALL_PERMISSIONS = [
        'no_ads'           => 'No advertisements',
        'early_access'     => 'Early access to scheduled content',
        'export_md'        => 'Export articles as Markdown',
        'flux_6'           => 'Extended newsletter feed (6 sources)',
        'sync'             => 'Sync favorites and read-later across devices',
        'priority_support' => 'Priority support',
    ];

    // -------------------------------------------------------------------------
    // Permission check — reads from DB (includes custom roles)
    // -------------------------------------------------------------------------

    /**
     * Check if a user has a specific permission.
     * Admin wildcard (*) always returns true.
     */
    public static function can(int $user_id, string $permission): bool {
        // WP admins bypass all permission checks
        if (function_exists('wpa_user_is_admin') && wpa_user_is_admin($user_id)) return true;
        $roles = self::get_roles($user_id);

        foreach ($roles as $role) {
            // Admin wildcard
            if ($role === 'admin') return true;
            // Check DB table
            if (self::role_has_permission($role, $permission)) return true;
        }
        return false;
    }

    /**
     * Check if a role has a permission — queries wp_wpa_role_perms.
     */
    public static function role_has_permission(string $role, string $permission): bool {
        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wpa_role_perms
             WHERE role = %s AND permission = %s",
            $role, $permission
        ));
        return (int) $count > 0;
    }

    /**
     * Get all permissions for a role from DB.
     */
    public static function get_role_permissions(string $role): array {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT permission FROM {$wpdb->prefix}wpa_role_perms WHERE role = %s",
            $role
        ));
    }

    /**
     * Get all defined roles from wp_wpa_role_definitions.
     * Includes roles with zero permissions (newly created, empty roles).
     */
    public static function get_all_defined_roles(): array {
        global $wpdb;
        $roles = $wpdb->get_col(
            "SELECT role FROM {$wpdb->prefix}wpa_role_definitions ORDER BY role ASC"
        );
        // Fallback — if table doesn't exist yet (pre-migration), use old method
        if (empty($roles)) {
            $roles = $wpdb->get_col(
                "SELECT DISTINCT role FROM {$wpdb->prefix}wpa_role_perms ORDER BY role ASC"
            );
        }
        return $roles ?: [];
    }

    // -------------------------------------------------------------------------
    // User roles
    // -------------------------------------------------------------------------

    public static function get_roles(int $user_id): array {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            "SELECT role FROM {$wpdb->prefix}wpa_roles
             WHERE user_id = %d
               AND (expires_at IS NULL OR expires_at > NOW())",
            $user_id
        ));
    }

    /**
     * Grant a role to a user.
     *
     * Extension logic:
     *   - If user already has the role with a future expiry → extend from the
     *     current expiry date (not from today), so the user never loses time.
     *   - If $expires_at is null (lifetime) → always overwrite with lifetime.
     *   - If user has a lifetime role → keep lifetime, ignore extension.
     */
    public static function grant(int $user_id, string $role, string $granted_by = 'admin', ?string $expires_at = null): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpa_roles';

        // Check existing row
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT expires_at FROM $table WHERE user_id = %d AND role = %s",
            $user_id, $role
        ));

        if ($existing) {
            if ($expires_at === null) {
                // New grant is lifetime — upgrade unconditionally
                $final_expiry = null;
            } elseif ($existing->expires_at === null) {
                // Already lifetime — keep lifetime
                $final_expiry = null;
            } else {
                // Both timed — extend from whichever is later: current expiry or now
                $base = max(strtotime($existing->expires_at), time());
                // Calculate duration from the new expires_at relative to now
                $duration = strtotime($expires_at) - time();
                $final_expiry = date('Y-m-d H:i:s', $base + $duration);
            }
        } else {
            $final_expiry = $expires_at;
        }

        // Use INSERT ... ON DUPLICATE KEY UPDATE to avoid duplicate rows
        // Requires UNIQUE KEY (user_id, role) on wp_wpa_roles
        $now        = current_time('mysql');
        $expires_sql = $final_expiry ? "'" . esc_sql($final_expiry) . "'" : 'NULL';
        $result = $wpdb->query($wpdb->prepare(
            "INSERT INTO $table (user_id, role, granted_by, granted_at, expires_at)
             VALUES (%d, %s, %s, %s, " . $expires_sql . ")
             ON DUPLICATE KEY UPDATE
                granted_by = VALUES(granted_by),
                granted_at = VALUES(granted_at),
                expires_at = " . $expires_sql,
            $user_id, $role, $granted_by, $now
        ));

        // Sync WP capabilities and native WP role immediately after grant
        if ($result !== false) {
            WPA_Capabilities::sync_user($user_id);
            WPA_Capabilities::assign_wp_role($user_id, $role);
            do_action('wpa_role_granted', $user_id, $role);
        }

        return $result !== false;
    }

    public static function revoke(int $user_id, string $role): void {
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'wpa_roles',
            ['user_id' => $user_id, 'role' => $role],
            ['%d', '%s']
        );
        // Resync — user may still have other active roles
        WPA_Capabilities::sync_user($user_id);
        WPA_Capabilities::remove_wp_role($user_id, $role);
        do_action('wpa_role_revoked', $user_id, $role);
    }

    public static function revoke_all(int $user_id): void {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'wpa_roles', ['user_id' => $user_id], ['%d']);
        // Remove all WPA capabilities and WP roles
        WPA_Capabilities::remove_all($user_id);
        WPA_Capabilities::remove_all_wp_roles($user_id);
        do_action('wpa_roles_revoked_all', $user_id);
    }

    public static function is_premium(int $user_id): bool {
        $roles = self::get_roles($user_id);
        foreach ($roles as $role) {
            if (str_starts_with($role, 'premium_') || in_array($role, ['editor', 'admin'], true)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------------------
    // Role CRUD — admin UI
    // -------------------------------------------------------------------------

    /**
     * Create or update a role with a set of permissions.
     * Replaces all existing permissions for that role.
     */
    public static function save_role(string $role, array $permissions): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wpa_role_perms';
        $role  = sanitize_key($role);

        if (empty($role)) return false;

        // Always register the role — ensures it appears even with zero permissions
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}wpa_role_definitions (role) VALUES (%s)",
            $role
        ));

        // Delete existing permissions for this role
        $wpdb->delete($table, ['role' => $role], ['%s']);

        // Insert new permissions
        foreach ($permissions as $perm) {
            $perm = sanitize_key($perm);
            if (empty($perm)) continue;
            $wpdb->insert($table, ['role' => $role, 'permission' => $perm], ['%s', '%s']);
        }

        return true;
    }

    /**
     * Delete a role from role_perms.
     * Does NOT revoke the role from users — handle separately.
     */
    // -------------------------------------------------------------------------
    // Expiry cleanup — called by daily cron
    // -------------------------------------------------------------------------

    /**
     * Revoke expired roles and clean up WP capabilities/native roles.
     * Called once per day via wpa_daily_cron.
     * Also fires do_action('wpa_role_expired', $user_id, $role) per entry.
     */
    /**
     * Get all active permissions for a user (across all their active roles).
     * Used by the wpa_user_permissions filter.
     */
    public static function get_user_permissions(int $user_id): array {
        global $wpdb;
        $roles = $wpdb->get_col($wpdb->prepare(
            "SELECT role FROM {$wpdb->prefix}wpa_roles
             WHERE user_id = %d AND (expires_at IS NULL OR expires_at > NOW())",
            $user_id
        ));

        $permissions = [];
        foreach ($roles as $role) {
            $role_perms = WPA_Roles::get_role_permissions($role);
            $permissions = array_merge($permissions, $role_perms);
        }
        return array_unique($permissions);
    }

    public static function cleanup_expired(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wpa_roles';

        // Find all expired rows
        $expired = $wpdb->get_results(
            "SELECT id, user_id, role FROM $table
             WHERE expires_at IS NOT NULL AND expires_at <= NOW()"
        );

        if (empty($expired)) return;

        $affected_users = [];

        foreach ($expired as $row) {
            // Delete the expired role row
            $wpdb->delete($table, ['id' => $row->id], ['%d']);

            $affected_users[$row->user_id][] = $row->role;
            do_action('wpa_role_expired', (int) $row->user_id, $row->role);
        }

        // Resync capabilities and WP native roles for each affected user
        foreach ($affected_users as $user_id => $roles) {
            // Remove WP native role for each expired role
            foreach ($roles as $role) {
                WPA_Capabilities::remove_wp_role($user_id, $role);
            }

            // Resync remaining capabilities
            WPA_Capabilities::sync_user($user_id);

            // If user has no more active premium roles — remove wpa_premium cap
            if (!self::is_premium($user_id)) {
                WPA_Capabilities::remove_all_wp_roles($user_id);
                WPA_Capabilities::remove_all($user_id);
            }

            do_action('wpa_roles_revoked_all', $user_id);
        }
    }

    public static function delete_role(string $role): void {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'wpa_role_perms',        ['role' => $role], ['%s']);
        $wpdb->delete($wpdb->prefix . 'wpa_role_definitions',  ['role' => $role], ['%s']);
    }

    // -------------------------------------------------------------------------
    // Ko-fi mapping helpers
    // -------------------------------------------------------------------------

    public static function expiry_from_amount(float $amount): ?string {
        $tiers = get_option('wpa_kofi_tiers', self::default_tiers());
        foreach ($tiers as $tier) {
            if ($amount >= $tier['min']) {
                if ($tier['months'] === null) return null;
                return date('Y-m-d H:i:s', strtotime("+{$tier['months']} months"));
            }
        }
        return null;
    }

    public static function role_from_amount(float $amount): string {
        $tiers = get_option('wpa_kofi_tiers', self::default_tiers());
        foreach ($tiers as $tier) {
            if ($amount >= $tier['min']) return $tier['role'];
        }
        return 'premium_basic';
    }

    private static function default_tiers(): array {
        return [
            ['min' => 50, 'role' => 'premium_lifetime', 'months' => null],
            ['min' => 10, 'role' => 'premium_pro',      'months' => 1],
            ['min' => 5,  'role' => 'premium_basic',    'months' => 1],
        ];
    }


    // =========================================================================
    // Capability CRUD — reads/writes wp_wpa_capabilities
    // =========================================================================

    /**
     * Get all capabilities from DB.
     * Returns array of objects: slug, label, wp_cap, is_builtin.
     */
    public static function get_all_capabilities(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT slug, label, wp_cap, is_builtin
             FROM {$wpdb->prefix}wpa_capabilities
             ORDER BY is_builtin DESC, slug ASC"
        );
        // Fallback if table empty — use built-in constant
        if (empty($rows)) {
            $cap_map = WPA_Capabilities::DEFAULT_MAP;
            foreach (self::ALL_PERMISSIONS as $slug => $label) {
                $obj            = new stdClass();
                $obj->slug      = $slug;
                $obj->label     = $label;
                $obj->wp_cap    = $cap_map[$slug] ?? ('wpa_' . $slug);
                $obj->is_builtin = 1;
                $rows[]         = $obj;
            }
        }
        return $rows;
    }

    /**
     * Save (create or update) a capability.
     */
    public static function save_capability(string $slug, string $label, string $wp_cap, bool $is_builtin = false): bool {
        global $wpdb;
        $slug   = sanitize_key($slug);
        $wp_cap = sanitize_key($wp_cap);
        if (!str_starts_with($wp_cap, 'wpa_')) $wp_cap = 'wpa_' . $wp_cap;

        $result = $wpdb->replace(
            $wpdb->prefix . 'wpa_capabilities',
            [
                'slug'       => $slug,
                'label'      => sanitize_text_field($label),
                'wp_cap'     => $wp_cap,
                'is_builtin' => $is_builtin ? 1 : 0,
            ],
            ['%s','%s','%s','%d']
        );
        return $result !== false;
    }

    /**
     * Delete a custom capability.
     * Also removes it from all role_perms and revokes it from all users.
     */
    public static function delete_capability(string $slug): void {
        global $wpdb;
        $slug = sanitize_key($slug);

        // Get wp_cap name before deleting
        $wp_cap = $wpdb->get_var($wpdb->prepare(
            "SELECT wp_cap FROM {$wpdb->prefix}wpa_capabilities WHERE slug = %s",
            $slug
        ));

        // Remove from capabilities table
        $wpdb->delete($wpdb->prefix . 'wpa_capabilities', ['slug' => $slug], ['%s']);
        // Remove from all role permissions
        $wpdb->delete($wpdb->prefix . 'wpa_role_perms', ['permission' => $slug], ['%s']);

        // Revoke WP capability from all users who had it
        if ($wp_cap) {
            $users = get_users(['meta_key' => $wp_cap]);
            foreach ($users as $user) {
                $user->remove_cap($wp_cap);
            }
        }
    }
}
