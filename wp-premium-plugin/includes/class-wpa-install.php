<?php
defined('ABSPATH') || exit;

class WPA_Install {

    public static function activate(): void {
        self::create_tables();
        flush_rewrite_rules();
    }

    /**
     * No automatic upgrade on every request — zero SQL overhead.
     * Tables are created/updated:
     *   - On plugin activation (register_activation_hook)
     *   - On plugin deactivation/reactivation
     *   - Manually via the "Update database" button in Settings
     *
     * @see WPA_Admin::ajax_update_database()
     */
    public static function maybe_upgrade(): void {
        // Intentionally empty — no automatic DB checks on page load.
    }

    /**
     * Add UNIQUE KEY (user_id, role) to existing wp_wpa_roles table.
     * Safe to call multiple times — checks before adding.
     * Called from ajax_update_database and activation.
     */
    public static function migrate_roles_unique_key(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wpa_roles';

        // Check if UNIQUE KEY already exists
        $index = $wpdb->get_row(
            "SHOW INDEX FROM $table WHERE Key_name = 'user_role'"
        );
        if ($index) return; // already present

        // Remove duplicate rows — keep the one with NULL expires_at (lifetime)
        // or the latest expires_at, or the highest id as tiebreaker
        $wpdb->query(
            "DELETE r1 FROM $table r1
             INNER JOIN $table r2
             ON r1.user_id = r2.user_id
             AND r1.role = r2.role
             AND (
                 -- r2 is lifetime, r1 is not
                 (r2.expires_at IS NULL AND r1.expires_at IS NOT NULL)
                 OR
                 -- both timed, r2 expires later
                 (r1.expires_at IS NOT NULL AND r2.expires_at IS NOT NULL AND r1.expires_at < r2.expires_at)
                 OR
                 -- same expiry (or both null), keep highest id
                 (r1.expires_at <=> r2.expires_at AND r1.id < r2.id)
             )"
        );

        // Add the unique index
        $wpdb->query("ALTER TABLE $table ADD UNIQUE KEY user_role (user_id, role)");
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    public static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // 1. Sessions
        $sql_sessions = "CREATE TABLE {$wpdb->prefix}wpa_sessions (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            token_hash    VARCHAR(64)     NOT NULL,
            expires_at    DATETIME        NOT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY user_id (user_id)
        ) $charset;";

        // 2. User role assignments
        $sql_roles = "CREATE TABLE {$wpdb->prefix}wpa_roles (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            role          VARCHAR(50)     NOT NULL,
            granted_by    VARCHAR(50)     NOT NULL DEFAULT 'admin',
            granted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at    DATETIME        NULL DEFAULT NULL,
            enc_key_enc   VARCHAR(512)    NULL DEFAULT NULL,
            key_version   TINYINT UNSIGNED NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY user_role (user_id, role),
            KEY user_id (user_id),
            KEY role (role)
        ) $charset;";

        // 3. Role definitions — stores role slugs independently of permissions
        $sql_role_definitions = "CREATE TABLE {$wpdb->prefix}wpa_role_definitions (
            role        VARCHAR(50)  NOT NULL,
            label       VARCHAR(100) NOT NULL DEFAULT '',
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (role)
        ) $charset;";

        // 4. Capability definitions — all available capabilities
        $sql_capabilities = "CREATE TABLE {$wpdb->prefix}wpa_capabilities (
            slug        VARCHAR(50)  NOT NULL,
            label       VARCHAR(100) NOT NULL DEFAULT '',
            wp_cap      VARCHAR(100) NOT NULL DEFAULT '',
            is_builtin  TINYINT(1)   NOT NULL DEFAULT 0,
            created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (slug)
        ) $charset;";

        // 5. Role permissions — which permissions belong to each role
        $sql_role_perms = "CREATE TABLE {$wpdb->prefix}wpa_role_perms (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            role          VARCHAR(50)     NOT NULL,
            permission    VARCHAR(50)     NOT NULL,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY role_perm (role, permission),
            KEY role (role)
        ) $charset;";

        // 6. Favorites sync
        $sql_favorites = "CREATE TABLE {$wpdb->prefix}wpa_favorites (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            article_id    VARCHAR(512)    NOT NULL,
            saved_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $charset;";

        // 7. Read-later sync
        $sql_read_later = "CREATE TABLE {$wpdb->prefix}wpa_read_later (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id       BIGINT UNSIGNED NOT NULL,
            article_id    VARCHAR(512)    NOT NULL,
            saved_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $charset;";

        // 8. Webhook log
        $sql_webhook_log = "CREATE TABLE {$wpdb->prefix}wpa_webhook_log (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            received_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            type          VARCHAR(50)     NOT NULL DEFAULT '',
            email         VARCHAR(255)    NOT NULL DEFAULT '',
            amount        DECIMAL(10,2)   NOT NULL DEFAULT 0.00,
            role_granted  VARCHAR(50)     NOT NULL DEFAULT '',
            status        VARCHAR(20)     NOT NULL DEFAULT 'ok',
            note          VARCHAR(255)    NOT NULL DEFAULT '',
            payload       TEXT            NULL,
            PRIMARY KEY (id),
            KEY received_at (received_at)
        ) $charset;";

        // 9. Processed message IDs — deduplication (Ko-fi + Stripe), Redis-safe
        $sql_processed_messages = "CREATE TABLE {$wpdb->prefix}wpa_processed_messages (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            message_id  VARCHAR(100)    NOT NULL,
            type        VARCHAR(50)     NOT NULL DEFAULT '',
            received_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY message_id (message_id),
            KEY received_at (received_at)
        ) $charset;";

        // 9a. API tokens
        $sql_api_tokens = "CREATE TABLE {$wpdb->prefix}wpa_api_tokens (
            id          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            token_hash  VARCHAR(64)      NOT NULL,
            description VARCHAR(255)     NOT NULL DEFAULT '',
            created_at  DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used   DATETIME         NULL,
            created_by  BIGINT UNSIGNED  NOT NULL DEFAULT 0,
            is_active   TINYINT(1)       NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY token_hash (token_hash),
            KEY is_active (is_active)
        ) $charset;";

        // 9b. Login history — last 30 logins per user
        $sql_login_history = "CREATE TABLE {$wpdb->prefix}wpa_login_history (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id      BIGINT UNSIGNED NOT NULL,
            logged_in_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip           VARCHAR(45)     NOT NULL DEFAULT '',
            user_agent   VARCHAR(255)    NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY logged_in_at (logged_in_at)
        ) $charset;";

        // 10. Magic link request log — traceability
        $sql_magic_link_log = "CREATE TABLE {$wpdb->prefix}wpa_magic_link_log (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            requested_at DATETIME       NOT NULL DEFAULT CURRENT_TIMESTAMP,
            email       VARCHAR(255)    NOT NULL DEFAULT '',
            ip          VARCHAR(45)     NOT NULL DEFAULT '',
            user_agent  VARCHAR(255)    NOT NULL DEFAULT '',
            email_exists TINYINT(1)     NOT NULL DEFAULT 0,
            sent        TINYINT(1)      NOT NULL DEFAULT 0,
            blocked_reason VARCHAR(50)  NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY requested_at (requested_at),
            KEY ip (ip),
            KEY email (email(20))
        ) $charset;";

        // 11. Rate limits — persistent, Redis-safe
        $sql_rate_limits = "CREATE TABLE {$wpdb->prefix}wpa_rate_limits (
            id           BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
            key_hash     VARCHAR(64)       NOT NULL,
            hits         SMALLINT UNSIGNED NOT NULL DEFAULT 1,
            window_start DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY key_hash (key_hash),
            KEY window_start (window_start)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_sessions);
        dbDelta($sql_roles);
        dbDelta($sql_role_definitions);
        dbDelta($sql_capabilities);
        dbDelta($sql_role_perms);
        dbDelta($sql_favorites);
        dbDelta($sql_read_later);
        dbDelta($sql_webhook_log);
        dbDelta($sql_processed_messages);
        dbDelta($sql_api_tokens);
        dbDelta($sql_login_history);
        dbDelta($sql_magic_link_log);
        dbDelta($sql_rate_limits);

        self::seed_builtin_roles();
        self::create_pages();

        update_option('wpa_db_version', WPA_VERSION);
    }

    /**
     * Seed built-in roles into wp_wpa_role_definitions and wp_wpa_role_perms.
     * INSERT IGNORE — never overwrites manual changes.
     */
    public static function seed_builtin_roles(): void {
        global $wpdb;
        $def_table  = $wpdb->prefix . 'wpa_role_definitions';
        $perm_table = $wpdb->prefix . 'wpa_role_perms';
        $cap_table  = $wpdb->prefix . 'wpa_capabilities';
        $cap_map    = WPA_Capabilities::DEFAULT_MAP;

        // Seed built-in capabilities
        foreach (WPA_Roles::ALL_PERMISSIONS as $slug => $label) {
            $wp_cap = $cap_map[$slug] ?? ('wpa_' . $slug);
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $cap_table (slug, label, wp_cap, is_builtin) VALUES (%s, %s, %s, 1)",
                $slug, $label, $wp_cap
            ));
        }

        // Seed built-in roles
        foreach (WPA_Roles::BUILTIN_PERMISSIONS as $role => $permissions) {
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO $def_table (role) VALUES (%s)",
                $role
            ));
            foreach ($permissions as $perm) {
                if ($perm === '*') continue;
                $wpdb->query($wpdb->prepare(
                    "INSERT IGNORE INTO $perm_table (role, permission) VALUES (%s, %s)",
                    $role, $perm
                ));
            }
        }
    }

    /**
     * Create required frontend pages if they don't exist.
     */
    public static function create_pages(): void {
        $pages = [
            [
                'slug'    => 'connexion-premium',
                'title'   => __('Premium Access', WPA_TEXT_DOMAIN),
                'content' => '[wpa_login_form]',
            ],
            [
                'slug'    => 'mon-compte-premium',
                'title'   => __('My Account', WPA_TEXT_DOMAIN),
                'content' => '[wpa_account]',
            ],
        ];

        foreach ($pages as $page) {
            if (get_page_by_path($page['slug'])) continue;
            wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_name'    => $page['slug'],
                'post_title'   => $page['title'],
                'post_content' => $page['content'],
            ]);
        }
    }
}
