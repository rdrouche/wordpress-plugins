<?php
defined('ABSPATH') || exit;

class WPA_Sessions {

    public const MAX_SESSIONS  = 3;
    public const SESSION_DAYS  = 30;
    public const COOKIE_NAME   = 'wpa_session';
    public const COOKIE_SECURE = true; // set false for local dev

    /**
     * Create a new session for a user.
     * Enforces max 3 sessions — removes oldest if limit reached (FIFO).
     * Returns raw token to be sent once (never stored in plain text).
     */
    public static function create(int $user_id): string {
        global $wpdb;
        $table = $wpdb->prefix . 'wpa_sessions';

        // Enforce session limit
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE user_id = %d AND expires_at > NOW()",
            $user_id
        ));

        if ($count >= self::MAX_SESSIONS) {
            // Remove oldest session (FIFO)
            $oldest_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE user_id = %d ORDER BY created_at ASC LIMIT 1",
                $user_id
            ));
            if ($oldest_id) {
                $wpdb->delete($table, ['id' => (int) $oldest_id], ['%d']);
            }
        }

        // Generate cryptographically secure token
        $raw_token  = bin2hex(random_bytes(32)); // 64 hex chars
        $token_hash = hash('sha256', $raw_token);
        $expires_at = date('Y-m-d H:i:s', strtotime('+' . self::SESSION_DAYS . ' days'));

        $wpdb->insert($table, [
            'user_id'    => $user_id,
            'token_hash' => $token_hash,
            'expires_at' => $expires_at,
            'created_at' => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s']);

        return $raw_token;
    }

    /**
     * Validate a session token from cookie.
     * Refreshes expiry on each valid request (rolling 30 days).
     * Returns user_id or null.
     */
    public static function validate(string $raw_token): ?int {
        global $wpdb;
        $table      = $wpdb->prefix . 'wpa_sessions';
        $token_hash = hash('sha256', $raw_token);

        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id, expires_at FROM $table
             WHERE token_hash = %s AND expires_at > NOW()",
            $token_hash
        ));

        if (!$session) return null;

        // Rolling expiry — extend on each use
        $new_expiry = date('Y-m-d H:i:s', strtotime('+' . self::SESSION_DAYS . ' days'));
        $wpdb->update($table, ['expires_at' => $new_expiry], ['id' => $session->id], ['%s'], ['%d']);

        return (int) $session->user_id;
    }

    /**
     * Get current user_id from cookie.
     */
    public static function current_user_id(): ?int {
        $token = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!$token) return null;
        return self::validate(sanitize_text_field($token));
    }

    /**
     * Set session cookie (httpOnly, Secure, SameSite=Strict).
     */
    public static function set_cookie(string $raw_token): void {
        $expires = time() + (self::SESSION_DAYS * DAY_IN_SECONDS);
        // Use header() directly for full SameSite control
        $secure   = self::COOKIE_SECURE ? '; Secure' : '';
        $domain   = COOKIE_DOMAIN ?: '';
        setcookie(
            self::COOKIE_NAME,
            $raw_token,
            [
                'expires'  => $expires,
                'path'     => COOKIEPATH,
                'domain'   => $domain,
                'secure'   => self::COOKIE_SECURE,
                'httponly' => true,
                'samesite' => 'Strict',
            ]
        );
    }

    /**
     * Destroy current session (logout).
     */
    public static function destroy(string $raw_token): void {
        global $wpdb;
        $token_hash = hash('sha256', $raw_token);
        $wpdb->delete($wpdb->prefix . 'wpa_sessions', ['token_hash' => $token_hash], ['%s']);
        self::clear_cookie();
    }

    /**
     * Clear session cookie.
     */
    public static function clear_cookie(): void {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - 3600,
            'path'     => COOKIEPATH,
            'domain'   => COOKIE_DOMAIN ?: '',
            'secure'   => self::COOKIE_SECURE,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    /**
     * Revoke all sessions for a user (e.g. subscription cancelled).
     */
    public static function revoke_all(int $user_id): void {
        global $wpdb;
        $wpdb->delete($wpdb->prefix . 'wpa_sessions', ['user_id' => $user_id], ['%d']);
    }

    /**
     * Get active sessions list for a user (for account page).
     */
    public static function get_active(int $user_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, created_at, expires_at FROM {$wpdb->prefix}wpa_sessions
             WHERE user_id = %d AND expires_at > NOW()
             ORDER BY created_at DESC",
            $user_id
        ));
    }

    /**
     * Purge all expired sessions (called via WP Cron).
     */
    public static function purge_expired(): void {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->prefix}wpa_sessions WHERE expires_at <= NOW()");
    }
}
