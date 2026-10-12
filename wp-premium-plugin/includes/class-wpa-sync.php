<?php
defined('ABSPATH') || exit;

class WPA_Sync {

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        $routes = [
            ['favorites',  'wpa_favorites'],
            ['read-later', 'wpa_read_later'],
        ];

        foreach ($routes as [$slug, $table_suffix]) {
            register_rest_route('wpa/v1', "/{$slug}", [
                [
                    'methods'             => 'GET',
                    'callback'            => fn($r) => self::get_items($r, $table_suffix),
                    'permission_callback' => '__return_true',
                ],
                [
                    'methods'             => 'POST',
                    'callback'            => fn($r) => self::sync_items($r, $table_suffix),
                    'permission_callback' => '__return_true',
                ],
                [
                    'methods'             => 'DELETE',
                    'callback'            => fn($r) => self::delete_all($r, $table_suffix),
                    'permission_callback' => '__return_true',
                ],
            ]);

            register_rest_route('wpa/v1', "/{$slug}/(?P<article_id>[a-zA-Z0-9_-]+)", [
                [
                    'methods'             => 'DELETE',
                    'callback'            => fn($r) => self::delete_item($r, $table_suffix),
                    'permission_callback' => '__return_true',
                ],
            ]);
        }

        // Toggle sync option
        register_rest_route('wpa/v1', '/sync-settings', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'toggle_sync'],
            'permission_callback' => '__return_true',
        ]);
    }

    // -------------------------------------------------------------------------
    // Auth helper
    // -------------------------------------------------------------------------

    private static function get_authenticated_user(): ?int {
        $token = sanitize_text_field($_COOKIE[WPA_Sessions::COOKIE_NAME] ?? '');
        return WPA_Sessions::validate($token) ?: null;
    }

    private static function require_sync_permission(): int|\WP_REST_Response {
        $user_id = self::get_authenticated_user();
        if (!$user_id) {
            return new \WP_REST_Response(['error' => __('Authentication required.', WPA_TEXT_DOMAIN)], 401);
        }
        if (!WPA_Roles::can($user_id, 'sync')) {
            return new \WP_REST_Response(['error' => __('Sync requires premium_pro or higher.', WPA_TEXT_DOMAIN)], 403);
        }
        if (!self::is_sync_enabled($user_id)) {
            return new \WP_REST_Response(['error' => __('Sync is not enabled for this account.', WPA_TEXT_DOMAIN)], 403);
        }
        return $user_id;
    }

    // -------------------------------------------------------------------------
    // Sync toggle
    // -------------------------------------------------------------------------

    public static function toggle_sync(\WP_REST_Request $request): \WP_REST_Response {
        $token   = sanitize_text_field($_COOKIE[WPA_Sessions::COOKIE_NAME] ?? '');
        $user_id = WPA_Sessions::validate($token);

        if (!$user_id || !WPA_Roles::can($user_id, 'sync')) {
            return new \WP_REST_Response(['error' => __('Premium required.', WPA_TEXT_DOMAIN)], 403);
        }

        $enable = (bool) $request->get_param('enabled');

        if (!$enable) {
            // User disables sync — delete ALL server data immediately
            self::delete_user_data($user_id);
            update_user_meta($user_id, '_wpa_sync_enabled', 0);
            return new \WP_REST_Response([
                'sync_enabled' => false,
                'message'      => __('Sync disabled. Your data has been deleted from our servers. Local data is preserved.', WPA_TEXT_DOMAIN),
            ], 200);
        }

        update_user_meta($user_id, '_wpa_sync_enabled', 1);
        return new \WP_REST_Response(['sync_enabled' => true], 200);
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    /**
     * Get all items (merge strategy: union with localStorage done client-side).
     */
    public static function get_items(\WP_REST_Request $request, string $table_suffix): \WP_REST_Response {
        $user_id = self::require_sync_permission();
        if ($user_id instanceof \WP_REST_Response) return $user_id;

        global $wpdb;
        $table = $wpdb->prefix . $table_suffix;
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT article_id, saved_at FROM $table WHERE user_id = %d ORDER BY saved_at DESC",
            $user_id
        ));

        return new \WP_REST_Response(['items' => $items], 200);
    }

    /**
     * Sync items from localStorage to server.
     * Accepts array of {article_id, saved_at}.
     * Union merge — never deletes.
     */
    public static function sync_items(\WP_REST_Request $request, string $table_suffix): \WP_REST_Response {
        $user_id = self::require_sync_permission();
        if ($user_id instanceof \WP_REST_Response) return $user_id;

        $items = $request->get_param('items');
        if (!is_array($items)) {
            return new \WP_REST_Response(['error' => __('Invalid items payload.', WPA_TEXT_DOMAIN)], 400);
        }

        global $wpdb;
        $table   = $wpdb->prefix . $table_suffix;
        $synced  = 0;

        foreach ($items as $item) {
            $article_id = sanitize_text_field($item['article_id'] ?? '');
            if (empty($article_id)) continue;

            // Encrypt article_id before storing
            $encrypted = WPA_Encryption::encrypt($article_id, $user_id);

            // INSERT IGNORE — union merge, no overwrites
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE user_id = %d AND article_id = %s",
                $user_id, $encrypted
            ));

            if (!$existing) {
                $wpdb->insert($table, [
                    'user_id'    => $user_id,
                    'article_id' => $encrypted,
                    'saved_at'   => sanitize_text_field($item['saved_at'] ?? current_time('mysql')),
                ], ['%d', '%s', '%s']);
                $synced++;
            }
        }

        return new \WP_REST_Response(['synced' => $synced], 200);
    }

    /**
     * Delete a single item.
     */
    public static function delete_item(\WP_REST_Request $request, string $table_suffix): \WP_REST_Response {
        $user_id = self::require_sync_permission();
        if ($user_id instanceof \WP_REST_Response) return $user_id;

        global $wpdb;
        $table      = $wpdb->prefix . $table_suffix;
        $article_id = WPA_Encryption::encrypt(
            sanitize_text_field($request->get_param('article_id')),
            $user_id
        );

        $wpdb->delete($table, ['user_id' => $user_id, 'article_id' => $article_id], ['%d', '%s']);
        return new \WP_REST_Response(['deleted' => true], 200);
    }

    /**
     * Delete ALL items for a user — called on sync disable or account deletion.
     */
    public static function delete_all(\WP_REST_Request $request, string $table_suffix): \WP_REST_Response {
        $user_id = self::require_sync_permission();
        if ($user_id instanceof \WP_REST_Response) return $user_id;

        self::delete_user_data($user_id);
        return new \WP_REST_Response(['deleted' => true], 200);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function is_sync_enabled(int $user_id): bool {
        return (bool) get_user_meta($user_id, '_wpa_sync_enabled', true);
    }

    /**
     * Hard delete all server-side data for a user.
     */
    public static function delete_user_data(int $user_id): void {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'wpa_favorites',  ['user_id' => $user_id], ['%d']);
        $wpdb->delete($wpdb->prefix . 'wpa_read_later', ['user_id' => $user_id], ['%d']);
    }
}

// -------------------------------------------------------------------------
// Encryption helper
// -------------------------------------------------------------------------

class WPA_Encryption {

    /**
     * Derive a per-user AES-256 key from user_id + server secret.
     */
    private static function get_key(int $user_id): string {
        $secret = defined('WPA_SECRET') ? WPA_SECRET : (AUTH_KEY . SECURE_AUTH_KEY);
        return hash_hmac('sha256', 'user_' . $user_id, $secret, true);
    }

    public static function encrypt(string $data, int $user_id): string {
        $key  = self::get_key($user_id);
        $iv   = random_bytes(16);
        $enc  = openssl_encrypt($data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $enc);
    }

    public static function decrypt(string $data, int $user_id): string {
        $key     = self::get_key($user_id);
        $decoded = base64_decode($data);
        $iv      = substr($decoded, 0, 16);
        $enc     = substr($decoded, 16);
        return openssl_decrypt($enc, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv) ?: '';
    }
}
