<?php
defined('ABSPATH') || exit;

class WPA_MagicLink {

    public const TOKEN_EXPIRY_HOURS = 24;

    // Defaults — overridden by wp_options if configured in admin
    public const RATE_LIMIT_MAX_DEFAULT    = 3;
    public const RATE_LIMIT_WINDOW_DEFAULT = 900;   // 15 min
    public const IP_RATE_LIMIT_MAX_DEFAULT = 10;
    public const IP_RATE_LIMIT_WIN_DEFAULT = 3600;  // 1 hour

    private static function email_limit_max(): int    { return (int) get_option('wpa_rl_email_max',    self::RATE_LIMIT_MAX_DEFAULT); }
    private static function email_limit_window(): int { return (int) get_option('wpa_rl_email_window', self::RATE_LIMIT_WINDOW_DEFAULT); }
    private static function ip_limit_max(): int       { return (int) get_option('wpa_rl_ip_max',       self::IP_RATE_LIMIT_MAX_DEFAULT); }
    private static function ip_limit_window(): int    { return (int) get_option('wpa_rl_ip_window',    self::IP_RATE_LIMIT_WIN_DEFAULT); }

    /**
     * Request a magic link for a given email.
     * Silently does nothing if email is unknown (no enumeration).
     * Returns false on rate limit, true otherwise.
     *
     * Test mode (wpa_test_mode = 1): bypasses rate limits, logs clearly.
     */
    public static function request(string $email): bool {
        $email     = sanitize_email($email);
        $test_mode = (bool) get_option('wpa_test_mode', false);
        $ip        = self::get_ip();
        $ua        = substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        if (!is_email($email)) {
            self::log_request($email, $ip, $ua, false, false, 'invalid_email');
            return false;
        }

        // Rate limiting — skipped in test mode
        if (!$test_mode && !self::check_rate_limit($email)) {
            self::log_request($email, $ip, $ua, false, false, 'rate_limit');
            return false;
        }

        // IP-level brute force detection (10 requests/hour from same IP)
        if (!$test_mode && !self::check_ip_rate_limit($ip)) {
            self::log_request($email, $ip, $ua, false, false, 'ip_rate_limit');
            return false;
        }

        $user         = get_user_by('email', $email);
        $email_exists = (bool) $user;

        if (!$user || !WPA_Roles::is_premium($user->ID)) {
            // Silent — do not reveal if email exists or is premium
            self::log_request($email, $ip, $ua, $email_exists, false, $user ? 'not_premium' : 'unknown_email');
            return true;
        }

        $token      = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $token);
        $expires_at = date('Y-m-d H:i:s', strtotime('+' . self::TOKEN_EXPIRY_HOURS . ' hours'));

        update_user_meta($user->ID, '_wpa_magic_token',   $token_hash);
        update_user_meta($user->ID, '_wpa_magic_expires', $expires_at);
        update_user_meta($user->ID, '_wpa_magic_used',    0);

        // Store redirect_to if provided (e.g. user was on a protected page)
        $redirect_to = sanitize_text_field($_POST['redirect_to'] ?? '');
        if ($redirect_to && wp_validate_redirect($redirect_to, '')) {
            update_user_meta($user->ID, '_wpa_redirect_to', $redirect_to);
        }

        $sent = self::send_email($user, $token);
        self::log_request($email, $ip, $ua, true, $sent, $sent ? '' : 'send_failed');

        // In test mode — also store token in transient for admin display
        if ($test_mode) {
            set_transient('wpa_test_last_link_' . md5($email),
                add_query_arg(['wpa_action' => 'magic_login', 'token' => rawurlencode($token)], home_url('/')),
                3600
            );
        }

        return true;
    }

    /**
     * Log a magic link request.
     */
    private static function log_request(
        string $email,
        string $ip,
        string $ua,
        bool   $email_exists,
        bool   $sent,
        string $blocked_reason = ''
    ): void {
        global $wpdb;
        // Prune to 500 entries
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wpa_magic_link_log");
        if ($count >= 500) {
            $wpdb->query("DELETE FROM {$wpdb->prefix}wpa_magic_link_log ORDER BY id ASC LIMIT 50");
        }
        $wpdb->insert($wpdb->prefix . 'wpa_magic_link_log', [
            'requested_at'   => current_time('mysql'),
            'email'          => $email,
            'ip'             => $ip,
            'user_agent'     => $ua,
            'email_exists'   => $email_exists ? 1 : 0,
            'sent'           => $sent ? 1 : 0,
            'blocked_reason' => $blocked_reason,
        ], ['%s','%s','%s','%s','%d','%d','%s']);
    }

    /**
     * Get client IP.
     */
    private static function get_ip(): string {
        $ip = sanitize_text_field(
            $_SERVER['HTTP_X_FORWARDED_FOR'] ??
            $_SERVER['HTTP_X_REAL_IP'] ??
            $_SERVER['REMOTE_ADDR'] ?? ''
        );
        if (strpos($ip, ',') !== false) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return $ip;
    }

    /**
     * IP-level rate limit — max 10 requests per hour per IP.
     */
    private static function check_ip_rate_limit(string $ip): bool {
        global $wpdb;
        $table    = $wpdb->prefix . 'wpa_rate_limits';
        $key_hash = hash('sha256', 'ip_magic_' . $ip);
        $window   = date('Y-m-d H:i:s', time() - self::ip_limit_window());

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, hits, window_start FROM $table WHERE key_hash = %s", $key_hash
        ));
        if (!$row) {
            $wpdb->insert($table, ['key_hash' => $key_hash, 'hits' => 1, 'window_start' => current_time('mysql')], ['%s','%d','%s']);
            return true;
        }
        if ($row->window_start < $window) {
            $wpdb->update($table, ['hits' => 1, 'window_start' => current_time('mysql')], ['id' => $row->id], ['%d','%s'], ['%d']);
            return true;
        }
        if ((int) $row->hits >= self::ip_limit_max()) return false;
        $wpdb->update($table, ['hits' => $row->hits + 1], ['id' => $row->id], ['%d'], ['%d']);
        return true;
    }

    /**
     * Validate a magic link token.
     * Returns user_id on success, null on failure.
     * Token is invalidated on first use.
     */
    public static function validate(string $raw_token): ?int {
        if (empty($raw_token)) return null;

        $token_hash = hash('sha256', sanitize_text_field($raw_token));

        // Search users with this token hash
        $users = get_users([
            'meta_key'   => '_wpa_magic_token',
            'meta_value' => $token_hash,
            'number'     => 1,
        ]);

        if (empty($users)) return null;

        $user    = $users[0];
        $expires = get_user_meta($user->ID, '_wpa_magic_expires', true);
        $used    = (int) get_user_meta($user->ID, '_wpa_magic_used', true);

        // Check not used and not expired
        if ($used || strtotime($expires) < time()) {
            self::clear_token($user->ID);
            return null;
        }

        // Mark as used — single use only
        update_user_meta($user->ID, '_wpa_magic_used', 1);
        self::clear_token($user->ID);

        return $user->ID;
    }

    /**
     * Send magic link email — HTML with plain text fallback.
     *
     * Two strategies to avoid link-rewriting by security gateways:
     * 1. HTML button (main CTA)
     * 2. Plain text link written without <a> tags, split to avoid auto-rewriting
     */
    private static function send_email(\WP_User $user, string $token): bool {
        $link = add_query_arg([
            'wpa_action' => 'magic_login',
            'token'      => rawurlencode($token),
        ], home_url('/'));

        $ip = self::get_ip();
        $ua = substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        return WPA_Mailer::send_magic_link($user, $link, $ip, $ua);
    }

    /**
     * Rate limit by email — stored in DB, Redis-safe.
     * Window resets after RATE_LIMIT_WINDOW seconds.
     */
    private static function check_rate_limit(string $email): bool {
        global $wpdb;
        $table    = $wpdb->prefix . 'wpa_rate_limits';
        $key_hash = hash('sha256', 'magic_link_' . $email);
        $now      = current_time('mysql');
        $window   = date('Y-m-d H:i:s', time() - self::email_limit_window());

        // Get current row
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, hits, window_start FROM $table WHERE key_hash = %s",
            $key_hash
        ));

        if (!$row) {
            // First request
            $wpdb->insert($table, ['key_hash' => $key_hash, 'hits' => 1, 'window_start' => $now], ['%s','%d','%s']);
            return true;
        }

        // Window expired — reset
        if ($row->window_start < $window) {
            $wpdb->update($table, ['hits' => 1, 'window_start' => $now], ['id' => $row->id], ['%d','%s'], ['%d']);
            return true;
        }

        // Within window — check limit
        if ((int) $row->hits >= self::email_limit_max()) return false;

        $wpdb->update($table, ['hits' => $row->hits + 1], ['id' => $row->id], ['%d'], ['%d']);
        return true;
    }

    /**
     * Clear magic token meta from user.
     */
    private static function clear_token(int $user_id): void {
        delete_user_meta($user_id, '_wpa_magic_token');
        delete_user_meta($user_id, '_wpa_magic_expires');
        // Keep _wpa_magic_used for a short audit trail
    }
}
