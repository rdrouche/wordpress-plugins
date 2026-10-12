<?php
defined('ABSPATH') || exit;

class WPA_KofiWebhook {

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_route']);

        // Tell LiteSpeed Cache not to cache webhook REST endpoints
        add_filter('litespeed_is_not_cacheable', [self::class, 'litespeed_exclude']);
    }

    /**
     * Flush rewrite rules if the route is not found.
     * Call this from the "Update database" button if needed.
     */
    public static function litespeed_exclude(): bool {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_contains($uri, '/wpa/v1/kofi-webhook')
            || str_contains($uri, '/wpa/v1/stripe-webhook');
    }

    public static function flush_routes(): void {
        flush_rewrite_rules(false);
    }

    public static function register_route(): void {
        register_rest_route('wpa/v1', '/kofi-webhook', [
            [
                'methods'             => 'POST',
                'callback'            => [self::class, 'handle'],
                'permission_callback' => '__return_true',
            ],
            [
                // GET for diagnostics — returns endpoint status
                'methods'             => 'GET',
                'callback'            => [self::class, 'handle_get'],
                'permission_callback' => '__return_true',
            ],
        ]);

        register_rest_route('wpa/v1', '/stripe-webhook', [
            [
                'methods'             => 'POST',
                'callback'            => ['WPA_Stripe', 'handle_webhook'],
                'permission_callback' => '__return_true',
            ],
            [
                'methods'             => 'GET',
                'callback'            => [self::class, 'handle_get_stripe'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    public static function handle_get(\WP_REST_Request $request): \WP_REST_Response {
        $response = new \WP_REST_Response([
            'status'  => 'ok',
            'route'   => 'wpa/v1/kofi-webhook',
            'method'  => 'Use POST to send webhook payloads.',
            'version' => WPA_VERSION,
        ], 200);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->header('X-WPA-Webhook', 'active');
        return $response;
    }

    public static function handle_get_stripe(\WP_REST_Request $request): \WP_REST_Response {
        $response = new \WP_REST_Response([
            'status'  => 'ok',
            'route'   => 'wpa/v1/stripe-webhook',
            'method'  => 'Use POST to send webhook payloads.',
            'version' => WPA_VERSION,
        ], 200);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->header('X-WPA-Webhook', 'active');
        return $response;
    }

    // =========================================================================
    // Main handler
    // =========================================================================

    public static function handle(\WP_REST_Request $request): \WP_REST_Response {
        // Prevent LiteSpeed / Cloudflare from caching webhook responses
        nocache_headers();

        $body = $request->get_body_params();
        $raw  = $body['data'] ?? $request->get_body();
        $data = is_string($raw) ? json_decode(stripslashes($raw), true) : $raw;

        if (empty($data) || !is_array($data)) {
            self::log('unknown', '', 0, '', 'error', 'Invalid or empty payload');
            return new \WP_REST_Response(['error' => 'Invalid payload'], 400);
        }

        $is_simulation = !empty($data['_simulation']);

        // --- Verify Ko-fi token (skip for simulations) ---
        if (!$is_simulation) {
            $stored_token = get_option('wpa_kofi_token', '');
            $received     = $data['verification_token'] ?? '';
            if (!empty($stored_token) && !hash_equals($stored_token, $received)) {
                self::log('unknown', '', 0, '', 'error', 'Invalid verification token');
                return new \WP_REST_Response(['error' => 'Unauthorized'], 401);
            }
        }

        // --- Deduplication via message_id ---
        $message_id = sanitize_text_field($data['message_id'] ?? '');
        if ($message_id && !$is_simulation && self::already_processed($message_id)) {
            return new \WP_REST_Response(['status' => 'already_processed'], 200);
        }

        $type      = sanitize_text_field($data['type']  ?? '');
        $email     = sanitize_email($data['email']       ?? '');
        $amount    = (float) ($data['amount']            ?? 0);
        $currency  = sanitize_text_field($data['currency'] ?? 'EUR');
        $tier_name = sanitize_text_field($data['tier_name'] ?? '');
        $is_public = !empty($data['is_public']);
        $note      = $is_simulation ? 'simulation' : '';

        if (!is_email($email)) {
            self::log($type, $email, $amount, '', 'error', 'Invalid email');
            return new \WP_REST_Response(['error' => 'Invalid email'], 400);
        }

        $role = '';

        switch ($type) {

            // ------------------------------------------------------------------
            case 'Donation':
                $role = self::process_donation($email, $amount, $tier_name, $is_simulation ? 'admin_simulation' : 'kofi_donation');
                break;

            // ------------------------------------------------------------------
            case 'Subscription':
                $is_first   = !empty($data['is_first_subscription_payment']);
                $is_renewal = !empty($data['is_subscription_payment']) && !$is_first;
                $role       = self::process_subscription($email, $amount, $tier_name, $is_first, $is_renewal);
                break;

            // ------------------------------------------------------------------
            case 'Shop Order':
                $shop_items = $data['shop_items'] ?? [];
                $role       = self::process_shop_order($email, $amount, $shop_items, $is_simulation ? 'admin_simulation' : 'kofi_shop');
                break;

            // ------------------------------------------------------------------
            case 'Commission':
                self::log($type, $email, $amount, '', 'ignored', 'Commission — not handled', $data);
                return new \WP_REST_Response(['status' => 'ignored'], 200);

            // ------------------------------------------------------------------
            default:
                self::log($type, $email, $amount, '', 'ignored', "Unknown type: {$type}", $data);
                return new \WP_REST_Response(['status' => 'ignored'], 200);
        }

        // Mark message as processed
        if ($message_id && !$is_simulation) {
            self::mark_processed($message_id, $type);
        }

        self::log($type, $email, $amount, $role, $is_simulation ? 'simulation' : 'ok', $note, $data);

        $response = new \WP_REST_Response(['status' => 'ok', 'role' => $role ?: null], 200);
        $response->header('Cache-Control',                'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Cloudflare-CDN-Cache-Control', 'no-store');
        $response->header('CDN-Cache-Control',            'no-store');
        return $response;
    }

    // =========================================================================
    // Type handlers
    // =========================================================================

    /**
     * One-shot donation.
     * Priority: tier_name mapping → amount tier mapping.
     */
    private static function process_donation(string $email, float $amount, string $tier_name, string $source): string {
        $user_id    = self::get_or_create_user($email);
        if (!$user_id) return '';

        [$role, $expires_at] = self::resolve_role($amount, $tier_name);

        WPA_Roles::grant($user_id, $role, $source, $expires_at);
        WPA_MagicLink::request($email);

        return $role;
    }

    /**
     * Subscription — first payment or monthly renewal.
     * Renewal extends expiry by 35 days (grace period included).
     */
    private static function process_subscription(string $email, float $amount, string $tier_name, bool $is_first, bool $is_renewal): string {
        $user_id = self::get_or_create_user($email);
        if (!$user_id) return '';

        [$role, $expires_at] = self::resolve_role($amount, $tier_name);

        // Subscriptions always expire ~1 month from now (not lifetime)
        if ($expires_at === null) {
            $expires_at = date('Y-m-d H:i:s', strtotime('+35 days'));
        }

        WPA_Roles::grant($user_id, $role, 'kofi_subscription', $expires_at);

        // Only send magic link on very first payment
        if ($is_first) {
            WPA_MagicLink::request($email);
        }

        return $role;
    }

    /**
     * Shop order — map direct_link_code to a role.
     * Falls back to amount-based mapping if no code match found.
     */
    private static function process_shop_order(string $email, float $amount, array $shop_items, string $source): string {
        $user_id = self::get_or_create_user($email);
        if (!$user_id) return '';

        $role       = '';
        $expires_at = null;

        // Try to match a shop item code to a configured role
        $shop_map = get_option('wpa_shop_items', []); // [code => [role, months]]
        foreach ($shop_items as $item) {
            $code = sanitize_text_field($item['direct_link_code'] ?? '');
            if ($code && isset($shop_map[$code])) {
                $role       = $shop_map[$code]['role'];
                $months     = $shop_map[$code]['months'] ?? null;
                $expires_at = $months ? date('Y-m-d H:i:s', strtotime("+{$months} months")) : null;
                break;
            }
        }

        // Fallback to amount mapping
        if (empty($role)) {
            [$role, $expires_at] = self::resolve_role($amount, '');
        }

        WPA_Roles::grant($user_id, $role, $source, $expires_at);
        WPA_MagicLink::request($email);

        return $role;
    }

    // =========================================================================
    // Role resolution — tier_name first, then amount
    // =========================================================================

    /**
     * Resolve role and expiry.
     * 1. tier_name mapping (configurable in admin)
     * 2. amount tiers (configurable in admin)
     * Returns [role, expires_at|null]
     */
    private static function resolve_role(float $amount, string $tier_name): array {
        // 1. tier_name mapping
        if (!empty($tier_name)) {
            $tier_map = get_option('wpa_kofi_tier_names', []); // [tier_name => [role, months]]
            if (isset($tier_map[$tier_name])) {
                $cfg        = $tier_map[$tier_name];
                $months     = $cfg['months'] ?? null;
                $expires_at = $months ? date('Y-m-d H:i:s', strtotime("+{$months} months")) : null;
                return [$cfg['role'], $expires_at];
            }
        }

        // 2. Amount tiers
        $role       = WPA_Roles::role_from_amount($amount);
        $expires_at = WPA_Roles::expiry_from_amount($amount);

        return [$role, $expires_at];
    }

    // =========================================================================
    // Deduplication — persistent DB table, not transients (Redis can be flushed)
    // =========================================================================

    /**
     * Check if a message_id has already been processed.
     * Uses wp_wpa_processed_messages — survives Redis flushes and restarts.
     */
    private static function already_processed(string $message_id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wpa_processed_messages
             WHERE message_id = %s LIMIT 1",
            $message_id
        ));
    }

    /**
     * Mark a message_id as processed.
     * Prunes entries older than 30 days — Ko-fi retry window is much shorter.
     */
    private static function mark_processed(string $message_id, string $type = ''): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wpa_processed_messages';

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table (message_id, type, received_at)
             VALUES (%s, %s, NOW())",
            $message_id,
            $type
        ));

        // Prune entries older than 30 days (async — once every ~100 calls)
        if (rand(1, 100) === 1) {
            $wpdb->query(
                "DELETE FROM $table WHERE received_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
            );
        }
    }

    // =========================================================================
    // User creation
    // =========================================================================

    private static function get_or_create_user(string $email): int {
        $user = get_user_by('email', $email);
        if ($user) return $user->ID;

        $user_id = wp_create_user(
            $email,
            wp_generate_password(32, true, true),
            $email
        );

        if (is_wp_error($user_id)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[WPA] Failed to create user: ' . $user_id->get_error_message());
            }
            return 0;
        }

        $u = new \WP_User($user_id);
        $u->set_role('subscriber');

        return $user_id;
    }

    // =========================================================================
    // Logging — keeps last 200 rows
    // =========================================================================

    public static function log(
        string $type,
        string $email,
        float  $amount,
        string $role_granted,
        string $status,
        string $note    = '',
        ?array $payload = null
    ): void {
        self::log_raw($type, $email, $amount, $role_granted, $status, $note,
            $payload ? wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : null
        );
    }

    public static function log_raw(
        string  $type,
        string  $email,
        float   $amount,
        string  $role_granted,
        string  $status,
        string  $note           = '',
        ?string $payload_string = null
    ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wpa_webhook_log';

        $wpdb->insert($table, [
            'received_at'  => current_time('mysql'),
            'type'         => $type,
            'email'        => $email,
            'amount'       => $amount,
            'role_granted' => $role_granted,
            'status'       => $status,
            'note'         => $note,
            'payload'      => $payload_string,
        ], ['%s','%s','%s','%f','%s','%s','%s','%s']);

        $wpdb->query(
            "DELETE FROM $table WHERE id NOT IN (
                SELECT id FROM (SELECT id FROM $table ORDER BY id DESC LIMIT 200) t
            )"
        );
    }
}
