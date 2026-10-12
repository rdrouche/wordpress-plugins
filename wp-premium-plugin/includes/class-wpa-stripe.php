<?php
defined('ABSPATH') || exit;

/**
 * WPA_Stripe — Stripe Checkout integration.
 *
 * Flow:
 *   1. User clicks "Buy premium" button (shortcode [wpa_stripe_button])
 *   2. REST endpoint creates a Stripe Checkout Session
 *   3. User pays on Stripe hosted page
 *   4. Stripe sends webhook → grant role + magic link
 *
 * Supported Stripe events:
 *   checkout.session.completed   → one-time payment
 *   customer.subscription.created
 *   customer.subscription.updated
 *   customer.subscription.deleted → revoke role
 *   invoice.payment_succeeded    → subscription renewal
 *   invoice.payment_failed       → optional warning email
 *
 * Products are configured in WP admin → mapped to roles.
 * Uses Stripe API v2 (no SDK required — raw HTTP via wp_remote_post).
 */
class WPA_Stripe {

    public const API_BASE = 'https://api.stripe.com/v1';

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    // =========================================================================
    // REST routes
    // =========================================================================

    public static function register_routes(): void {
        // Create checkout session — called by JS button
        register_rest_route('wpa/v1', '/stripe/checkout', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'create_checkout_session'],
            'permission_callback' => '__return_true',
            'args'                => [
                'price_id' => ['required' => true, 'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        // Webhook endpoint — registered in Stripe dashboard
        register_rest_route('wpa/v1', '/stripe-webhook', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    // =========================================================================
    // Create Checkout Session
    // =========================================================================

    /**
     * Create a Stripe Checkout Session and return the URL.
     * The frontend redirects the user to Stripe's hosted page.
     */
    public static function create_checkout_session(\WP_REST_Request $request): \WP_REST_Response {
        $price_id = $request->get_param('price_id');

        if (!self::is_valid_price_id($price_id)) {
            return new \WP_REST_Response(['error' => __('Invalid price.', WPA_TEXT_DOMAIN)], 400);
        }

        $product_map = get_option('wpa_stripe_products', []);
        $product_cfg = $product_map[$price_id] ?? null;

        if (!$product_cfg) {
            return new \WP_REST_Response(['error' => __('Product not configured.', WPA_TEXT_DOMAIN)], 400);
        }

        $mode          = $product_cfg['mode'] ?? 'payment'; // 'payment' or 'subscription'
        $success_url   = add_query_arg('wpa_payment', 'success', home_url('/connexion-premium/'));
        $cancel_url    = add_query_arg('wpa_payment', 'cancel',  home_url('/'));

        $body = [
            'mode'                           => $mode,
            'line_items[0][price]'           => $price_id,
            'line_items[0][quantity]'        => '1',
            'success_url'                    => $success_url . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url'                     => $cancel_url,
            'customer_email'                 => '', // filled if user known
            'allow_promotion_codes'          => 'true',
            'billing_address_collection'     => 'auto',
        ];

        // Pre-fill email if we have a session
        $user_id = WPA_Sessions::current_user_id();
        if ($user_id) {
            $user = get_user_by('ID', $user_id);
            if ($user) $body['customer_email'] = $user->user_email;
        }

        $response = self::api_request('POST', '/checkout/sessions', $body);

        if (is_wp_error($response)) {
            return new \WP_REST_Response(['error' => $response->get_error_message()], 500);
        }

        if (!empty($response['error'])) {
            return new \WP_REST_Response(['error' => $response['error']['message'] ?? 'Stripe error'], 500);
        }

        return new \WP_REST_Response(['url' => $response['url']], 200);
    }

    // =========================================================================
    // Webhook handler
    // =========================================================================

    public static function handle_webhook(\WP_REST_Request $request): \WP_REST_Response {
        nocache_headers(); // prevent caching of webhook responses
        $payload   = $request->get_body();
        $sig       = $request->get_header('stripe-signature') ?? '';
        $secret    = get_option('wpa_stripe_webhook_secret', '');

        // Verify Stripe signature
        if (!empty($secret)) {
            $valid = self::verify_signature($payload, $sig, $secret);
            if (!$valid) {
                return new \WP_REST_Response(['error' => 'Invalid signature'], 400);
            }
        }

        $event = json_decode($payload, true);
        if (empty($event['type'])) {
            return new \WP_REST_Response(['error' => 'Invalid event'], 400);
        }

        // Deduplication — same as Ko-fi
        $event_id = sanitize_text_field($event['id'] ?? '');
        if ($event_id && self::already_processed($event_id)) {
            return new \WP_REST_Response(['status' => 'already_processed'], 200);
        }

        $type = $event['type'];
        $data = $event['data']['object'] ?? [];

        switch ($type) {

            // One-time payment completed
            case 'checkout.session.completed':
                if (($data['payment_status'] ?? '') === 'paid') {
                    self::handle_checkout_completed($data);
                }
                break;

            // Subscription created or renewed
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
                self::handle_subscription_active($data);
                break;

            // Subscription cancelled / expired
            case 'customer.subscription.deleted':
                self::handle_subscription_cancelled($data);
                break;

            // Invoice paid — subscription renewal
            case 'invoice.payment_succeeded':
                if (!empty($data['subscription'])) {
                    self::handle_invoice_paid($data);
                }
                break;

            // Invoice failed — optional warning
            case 'invoice.payment_failed':
                self::handle_invoice_failed($data);
                break;

            default:
                // Ignore unknown events
                return new \WP_REST_Response(['status' => 'ignored'], 200);
        }

        if ($event_id) self::mark_processed($event_id);

        return new \WP_REST_Response(['status' => 'ok'], 200);
    }

    // =========================================================================
    // Event handlers
    // =========================================================================

    private static function handle_checkout_completed(array $data): void {
        $email      = sanitize_email($data['customer_details']['email'] ?? $data['customer_email'] ?? '');
        $price_id   = $data['line_items']['data'][0]['price']['id'] ?? '';

        // Fetch line items if not expanded
        if (empty($price_id) && !empty($data['id'])) {
            $session  = self::api_request('GET', '/checkout/sessions/' . $data['id'] . '?expand[]=line_items');
            $price_id = $session['line_items']['data'][0]['price']['id'] ?? '';
        }

        if (!is_email($email)) return;

        [$role, $expires_at] = self::resolve_role_from_price($price_id);
        if (!$role) return;

        $user_id = self::get_or_create_user($email);
        if (!$user_id) return;

        // Store Stripe customer ID for future subscription management
        if (!empty($data['customer'])) {
            update_user_meta($user_id, '_wpa_stripe_customer_id', $data['customer']);
        }

        WPA_Roles::grant($user_id, $role, 'stripe_checkout', $expires_at);
        WPA_MagicLink::request($email);

        WPA_KofiWebhook::log(
            'stripe_checkout', $email,
            (float) (($data['amount_total'] ?? 0) / 100),
            $role, 'ok', ''
        );
    }

    private static function handle_subscription_active(array $data): void {
        $customer_id = $data['customer'] ?? '';
        $price_id    = $data['items']['data'][0]['price']['id'] ?? '';
        $status      = $data['status'] ?? '';

        if (!in_array($status, ['active', 'trialing'], true)) return;

        $email = self::email_from_customer($customer_id);
        if (!$email) return;

        [$role, ] = self::resolve_role_from_price($price_id);
        if (!$role) return;

        // Subscription expiry = current_period_end + 2 day grace
        $period_end = (int) ($data['current_period_end'] ?? 0);
        $expires_at = $period_end
            ? date('Y-m-d H:i:s', $period_end + 2 * DAY_IN_SECONDS)
            : null;

        $user_id = self::get_or_create_user($email);
        if (!$user_id) return;

        update_user_meta($user_id, '_wpa_stripe_customer_id',     $customer_id);
        update_user_meta($user_id, '_wpa_stripe_subscription_id', $data['id'] ?? '');

        WPA_Roles::grant($user_id, $role, 'stripe_subscription', $expires_at);
    }

    private static function handle_subscription_cancelled(array $data): void {
        $customer_id = $data['customer'] ?? '';
        $email       = self::email_from_customer($customer_id);
        if (!$email) return;

        $user = get_user_by('email', $email);
        if (!$user) return;

        // Revoke stripe-granted roles only
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}wpa_roles
             WHERE user_id = %d AND granted_by IN ('stripe_subscription', 'stripe_checkout')",
            $user->ID
        ));

        WPA_Sessions::revoke_all($user->ID);
    }

    private static function handle_invoice_paid(array $data): void {
        $customer_id    = $data['customer']     ?? '';
        $subscription_id = $data['subscription'] ?? '';
        $price_id       = $data['lines']['data'][0]['price']['id'] ?? '';

        $email = self::email_from_customer($customer_id);
        if (!$email) return;

        [$role, ] = self::resolve_role_from_price($price_id);
        if (!$role) return;

        // Fetch subscription to get current_period_end
        $sub        = self::api_request('GET', '/subscriptions/' . $subscription_id);
        $period_end = (int) ($sub['current_period_end'] ?? 0);
        $expires_at = $period_end
            ? date('Y-m-d H:i:s', $period_end + 2 * DAY_IN_SECONDS)
            : null;

        $user_id = self::get_or_create_user($email);
        if ($user_id) {
            WPA_Roles::grant($user_id, $role, 'stripe_subscription', $expires_at);
        }
    }

    private static function handle_invoice_failed(array $data): void {
        $customer_id = $data['customer'] ?? '';
        $email       = self::email_from_customer($customer_id);
        if (!$email) return;

        // Optional: send warning email via WPA_Mailer
        $user = get_user_by('email', $email);
        if (!$user) return;

        $subject = sprintf(__('[%s] Payment issue with your premium subscription', WPA_TEXT_DOMAIN), get_bloginfo('name'));
        $body    = sprintf(
            __("Hello,\n\nWe could not process your latest payment for <strong>%s</strong>.\n\nPlease update your payment method to keep your premium access:\n\n<a href=\"%s\">%s</a>\n\n— %s", WPA_TEXT_DOMAIN),
            get_bloginfo('name'),
            get_option('wpa_stripe_billing_portal_url', home_url('/')),
            get_option('wpa_stripe_billing_portal_url', home_url('/')),
            get_bloginfo('name')
        );

        WPA_Mailer::send($user->user_email, $subject, $body, 'html', 'stripe_payment_failed');
    }

    // =========================================================================
    // Role resolution from Stripe price_id
    // =========================================================================

    /**
     * Map a Stripe price_id to a [role, expires_at] pair.
     * Configured in admin — wpa_stripe_products option.
     *
     * wpa_stripe_products format:
     * [
     *   'price_xxxxx' => ['role' => 'premium_pro', 'mode' => 'payment',      'months' => 1],
     *   'price_yyyyy' => ['role' => 'premium_pro', 'mode' => 'subscription', 'months' => null],
     * ]
     */
    private static function resolve_role_from_price(string $price_id): array {
        $map = get_option('wpa_stripe_products', []);
        $cfg = $map[$price_id] ?? null;

        if (!$cfg) return ['', null];

        $role       = $cfg['role']   ?? '';
        $months     = $cfg['months'] ?? null;
        $expires_at = $months ? date('Y-m-d H:i:s', strtotime("+{$months} months")) : null;

        return [$role, $expires_at];
    }

    // =========================================================================
    // Stripe API helper — no SDK, raw wp_remote_post
    // =========================================================================

    public static function api_request(string $method, string $endpoint, array $body = []): array|\WP_Error {
        $secret_key = get_option('wpa_stripe_secret_key', '');

        if (empty($secret_key)) {
            return new \WP_Error('wpa_stripe', 'Stripe secret key not configured.');
        }

        $args = [
            'method'  => strtoupper($method),
            'headers' => [
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Stripe-Version'=> '2024-04-10',
            ],
            'timeout' => 15,
        ];

        if (!empty($body) && $method === 'POST') {
            $args['body'] = http_build_query($body);
        }

        $response = wp_remote_request(self::API_BASE . $endpoint, $args);

        if (is_wp_error($response)) return $response;

        $decoded = json_decode(wp_remote_retrieve_body($response), true);

        if (defined('WP_DEBUG') && WP_DEBUG && !empty($decoded['error'])) {
            error_log('[WPA Stripe] API error: ' . wp_json_encode($decoded['error']));
        }

        return $decoded ?: [];
    }

    // =========================================================================
    // Signature verification
    // =========================================================================

    /**
     * Verify Stripe webhook signature (HMAC SHA256).
     * Implements Stripe's signed payload spec manually — no SDK needed.
     */
    private static function verify_signature(string $payload, string $sig_header, string $secret): bool {
        $parts    = [];
        foreach (explode(',', $sig_header) as $part) {
            [$k, $v] = explode('=', $part, 2) + ['', ''];
            $parts[$k][] = $v;
        }

        $timestamp = $parts['t'][0]  ?? '';
        $signatures = $parts['v1']  ?? [];

        if (empty($timestamp) || empty($signatures)) return false;

        // Reject events older than 5 minutes
        if (abs(time() - (int) $timestamp) > 300) return false;

        $signed_payload = $timestamp . '.' . $payload;
        $expected       = hash_hmac('sha256', $signed_payload, $secret);

        foreach ($signatures as $sig) {
            if (hash_equals($expected, $sig)) return true;
        }

        return false;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private static function email_from_customer(string $customer_id): string {
        if (empty($customer_id)) return '';

        // Try usermeta first — avoids an API call
        global $wpdb;
        $user_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = '_wpa_stripe_customer_id' AND meta_value = %s LIMIT 1",
            $customer_id
        ));

        if ($user_id) {
            $user = get_user_by('ID', $user_id);
            return $user ? $user->user_email : '';
        }

        // Fallback — fetch from Stripe API
        $customer = self::api_request('GET', '/customers/' . $customer_id);
        return sanitize_email($customer['email'] ?? '');
    }

    private static function get_or_create_user(string $email): int {
        $user = get_user_by('email', $email);
        if ($user) return $user->ID;

        $user_id = wp_create_user($email, wp_generate_password(32, true, true), $email);
        if (is_wp_error($user_id)) return 0;

        $u = new \WP_User($user_id);
        $u->set_role('subscriber');
        return $user_id;
    }

    private static function is_valid_price_id(string $price_id): bool {
        return (bool) preg_match('/^price_[a-zA-Z0-9]+$/', $price_id);
    }

    private static function already_processed(string $event_id): bool {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wpa_processed_messages WHERE message_id = %s LIMIT 1",
            $event_id
        ));
    }

    private static function mark_processed(string $event_id): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}wpa_processed_messages (message_id, type) VALUES (%s, 'stripe')",
            $event_id
        ));
    }
}
