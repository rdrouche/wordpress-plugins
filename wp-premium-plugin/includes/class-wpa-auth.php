<?php
defined('ABSPATH') || exit;

class WPA_Auth {

    public static function init(): void {
        // Intercept magic link on every page load
        add_action('init',              [self::class, 'handle_magic_login'], 1);
        // Block password login for premium users
        add_filter('authenticate',      [self::class, 'block_password_auth'], 30, 3);
        // Redirect lost password for premium users
        add_action('lost_password',     [self::class, 'redirect_lost_password']);
        // REST route for requesting a magic link
        add_action('rest_api_init',     [self::class, 'register_routes']);
        // REST route for verifying session
        add_action('rest_api_init',     [self::class, 'register_routes']);
        // WP auth cookie is set at magic link validation — see handle_magic_login()
        // RGPD — clean up all plugin data + WP capabilities when a WP user is deleted
        add_action('delete_user', [self::class, 'on_delete_user']);
        // Cron: purge expired sessions daily
        add_action('wpa_purge_sessions', ['WPA_Sessions', 'purge_expired']);
        if (!wp_next_scheduled('wpa_purge_sessions')) {
            wp_schedule_event(time(), 'daily', 'wpa_purge_sessions');
        }
    }

    // -------------------------------------------------------------------------
    // Magic link processing
    // -------------------------------------------------------------------------

    /**
     * Check for magic link token in URL and log user in.
     */
    public static function handle_magic_login(): void {
        if (!isset($_GET['wpa_action']) || $_GET['wpa_action'] !== 'magic_login') return;
        if (!isset($_GET['token'])) return;

        $raw_token = sanitize_text_field(rawurldecode($_GET['token']));
        $user_id   = WPA_MagicLink::validate($raw_token);

        if (!$user_id) {
            // Invalid or expired — redirect to login page with error
            wp_safe_redirect(add_query_arg('wpa_error', 'expired', home_url('/connexion-premium/')));
            exit;
        }

        // Create server-side session
        $session_token = WPA_Sessions::create($user_id);
        WPA_Sessions::set_cookie($session_token);

        // Set real WordPress auth cookie so WP core sees the user as logged in.
        // This enables: is_user_logged_in(), current_user_can(), block visibility,
        // LiteSpeed Cache role exclusion, nonces, AJAX — everything works natively.
        // remember=true so cookie lasts SESSION_DAYS like our WPA cookie.
        wp_set_auth_cookie($user_id, true);
        wp_set_current_user($user_id);

        // Hide admin bar — premium users are not WP editors
        // (stored as user meta so it persists across requests)
        if (!user_can($user_id, 'edit_posts')) {
            update_user_meta($user_id, 'show_admin_bar_front', 'false');
        }

        // Log login history
        self::log_login($user_id);

        // Fire welcome email on first ever premium login
        $first_login = !get_user_meta($user_id, '_wpa_welcome_sent', true);
        if ($first_login) {
            do_action('wpa_send_welcome', $user_id);
        }

        // Build payload for localStorage (non-sensitive display data only)
        $roles      = WPA_Roles::get_roles($user_id);
        $expires_at = self::get_nearest_expiry($user_id);
        $payload    = json_encode([
            'user_id'          => $user_id,
            'roles'            => $roles,
            'session_expires'  => date('Y-m-d', strtotime('+' . WPA_Sessions::SESSION_DAYS . ' days')),
            'premium_expires'  => $expires_at,
        ]);

        // Pass payload to JS via transient (avoid URL exposure)
        set_transient('wpa_login_payload_' . $user_id, $payload, 60);

        // Determine redirect URL — configurable per language in Settings
        $user         = get_user_by('ID', $user_id);
        $redirect_url = self::get_post_login_redirect($user ?: new WP_User($user_id));
        wp_safe_redirect(add_query_arg('wpa_login', '1', $redirect_url));
        exit;
    }

    /**
     * Determine post-login redirect URL.
     *
     * Priority:
     * 1. ?redirect_to= in the original magic link request (stored in session meta)
     * 2. Per-language URL configured in Settings
     * 3. Polylang home URL for the user's language
     * 4. Fallback: home_url('/')
     */
    private static function get_post_login_redirect(\WP_User $user): string {
        // 1. Stored redirect_to from the magic link request
        $stored = get_user_meta($user->ID, '_wpa_redirect_to', true);
        if ($stored) {
            delete_user_meta($user->ID, '_wpa_redirect_to');
            if (wp_validate_redirect($stored, '')) return $stored;
        }

        // 2. Per-language setting
        $lang = '';
        if (function_exists('pll_get_user_language')) {
            $lang = pll_get_user_language($user->ID) ?: '';
        }
        if (!$lang && function_exists('pll_current_language')) {
            $lang = pll_current_language('slug') ?: '';
        }

        if ($lang) {
            $urls = get_option('wpa_post_login_redirects', []);
            if (!empty($urls[$lang])) {
                return esc_url_raw($urls[$lang]);
            }
        }

        // 3. Default redirect URL (no language)
        $default = get_option('wpa_post_login_redirect_default', '');
        if ($default) return esc_url_raw($default);

        // 4. Polylang home for language
        if ($lang && function_exists('pll_home_url')) {
            return pll_home_url($lang);
        }

        return home_url('/');
    }

    /**
     * Filter: wpa_get_user_expiry
     * Allows external plugins to get a user's nearest expiry date.
     *
     * Usage: $expires = apply_filters('wpa_get_user_expiry', null, $user_id);
     * Returns: date string 'Y-m-d H:i:s' or null for lifetime/not premium
     */

    /**
     * Get nearest active premium expiry for a user.
     */
    public static function get_nearest_expiry(int $user_id): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT expires_at FROM {$wpdb->prefix}wpa_roles
             WHERE user_id = %d AND expires_at IS NOT NULL
             ORDER BY expires_at ASC LIMIT 1",
            $user_id
        ));
    }

    // -------------------------------------------------------------------------
    // Block classic WP auth for premium users
    // -------------------------------------------------------------------------

    /**
     * Prevent premium users from logging in with a password.
     * WP editors and admins are not affected.
     */
    public static function block_password_auth($user, string $email, string $password) {
        if (is_wp_error($user) || empty($email)) return $user;

        $wp_user = get_user_by('email', $email);
        if (!$wp_user) return $user;

        // Allow WP editors/admins to use password as usual
        if (user_can($wp_user, 'edit_posts')) return $user;

        // Block if this is a premium-only user
        if (WPA_Roles::is_premium($wp_user->ID)) {
            return new \WP_Error(
                'wpa_magic_link_only',
                sprintf(
                    /* translators: %s: link to magic link page */
                    __('Please use your magic link to sign in. <a href="%s">Request a new link</a>.', WPA_TEXT_DOMAIN),
                    esc_url(home_url('/connexion-premium/'))
                )
            );
        }

        return $user;
    }

    /**
     * Redirect premium users away from the lost password page.
     */
    public static function redirect_lost_password(): void {
        $email   = sanitize_email($_POST['user_login'] ?? '');
        $wp_user = get_user_by('email', $email);
        if (!$wp_user) return;

        if (WPA_Roles::is_premium($wp_user->ID)) {
            wp_safe_redirect(add_query_arg('wpa_info', 'use_magic_link', home_url('/connexion-premium/')));
            exit;
        }
    }

    // -------------------------------------------------------------------------
    // REST API routes
    // -------------------------------------------------------------------------

    public static function register_routes(): void {
        // Request a magic link
        register_rest_route('wpa/v1', '/request-link', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'rest_request_link'],
            'permission_callback' => '__return_true',
            'args'                => [
                'email' => ['required' => true, 'sanitize_callback' => 'sanitize_email'],
            ],
        ]);

        // Verify current session
        register_rest_route('wpa/v1', '/verify-session', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'rest_verify_session'],
            'permission_callback' => '__return_true',
        ]);

        // Logout
        register_rest_route('wpa/v1', '/logout', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'rest_logout'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function rest_request_link(\WP_REST_Request $request): \WP_REST_Response {
        $email = $request->get_param('email');
        WPA_MagicLink::request($email);
        // Always return the same message
        return new \WP_REST_Response([
            'message' => __('If this email is recognized, a link has been sent.', WPA_TEXT_DOMAIN),
        ], 200);
    }

    public static function rest_verify_session(\WP_REST_Request $request): \WP_REST_Response {
        $token   = sanitize_text_field($_COOKIE[WPA_Sessions::COOKIE_NAME] ?? '');
        $user_id = WPA_Sessions::validate($token);

        if (!$user_id) {
            return new \WP_REST_Response(['authenticated' => false], 200);
        }

        $roles = WPA_Roles::get_roles($user_id);
        return new \WP_REST_Response([
            'authenticated'   => true,
            'user_id'         => $user_id,
            'roles'           => $roles,
            'is_premium'      => WPA_Roles::is_premium($user_id),
            'permissions'     => self::get_all_permissions($user_id),
        ], 200);
    }

    public static function rest_logout(\WP_REST_Request $request): \WP_REST_Response {
        $token = sanitize_text_field($_COOKIE[WPA_Sessions::COOKIE_NAME] ?? '');
        if ($token) WPA_Sessions::destroy($token);

        // Also clear WP auth cookie
        wp_logout();

        return new \WP_REST_Response(['logged_out' => true], 200);
    }

    /**
     * Log a login event to wp_wpa_login_history.
     * Keeps last 30 entries per user.
     */
    private static function log_login(int $user_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wpa_login_history';

        $ip = sanitize_text_field(
            $_SERVER['HTTP_X_FORWARDED_FOR'] ??
            $_SERVER['HTTP_X_REAL_IP'] ??
            $_SERVER['REMOTE_ADDR'] ?? ''
        );
        // Keep only first IP if forwarded list
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }

        $ua = substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $wpdb->insert($table, [
            'user_id'     => $user_id,
            'logged_in_at'=> current_time('mysql'),
            'ip'          => $ip,
            'user_agent'  => $ua,
        ], ['%d','%s','%s','%s']);

        // Prune to last 30 per user
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE user_id = %d AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM $table WHERE user_id = %d ORDER BY id DESC LIMIT 30
                ) t
            )",
            $user_id, $user_id
        ));
    }

    /**
     * RGPD — delete all plugin data when a WP user account is deleted.
     */
    public static function on_delete_user(int $user_id): void {
        global $wpdb;

        // Sessions
        WPA_Sessions::revoke_all($user_id);
        // Roles
        WPA_Roles::revoke_all($user_id);
        // Sync data
        WPA_Sync::delete_user_data($user_id);
        // Remove WP capabilities
        WPA_Capabilities::remove_all($user_id);
        // Rate limits (by user_id not possible — clean by email hash)
        $user = get_user_by('ID', $user_id);
        if ($user) {
            $key_hash = hash('sha256', 'magic_link_' . $user->user_email);
            $wpdb->delete($wpdb->prefix . 'wpa_rate_limits', ['key_hash' => $key_hash], ['%s']);
        }
        // User meta
        $meta_keys = ['_wpa_magic_token','_wpa_magic_expires','_wpa_magic_used',
                      '_wpa_welcome_sent','_wpa_sync_enabled','_wpa_warn_7d','_wpa_warn_1d'];
        foreach ($meta_keys as $key) {
            delete_user_meta($user_id, $key);
        }
    }

    public static function get_all_permissions(int $user_id): array {
        $permissions = [];
        foreach (array_keys(WPA_Roles::BUILTIN_PERMISSIONS) as $role) {
            foreach (WPA_Roles::BUILTIN_PERMISSIONS[$role] as $perm) {
                if ($perm !== '*' && WPA_Roles::can($user_id, $perm)) {
                    $permissions[] = $perm;
                }
            }
        }
        return array_unique($permissions);
    }
}
