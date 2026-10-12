<?php
defined('ABSPATH') || exit;

/**
 * WPA REST API — read-only endpoints secured by API tokens.
 *
 * Base URL : /wp-json/wpa/v1/api/
 *
 * Authentication : Bearer token in Authorization header
 *   Authorization: Bearer wpa_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
 *
 * All responses : Content-Type: application/json
 *
 * Endpoints:
 *   GET /wpa/v1/api/users          — list all WP users with WPA data
 *   GET /wpa/v1/api/users/{id}     — single user by WP user ID
 *   GET /wpa/v1/api/users/email/{email} — single user by email
 *   GET /wpa/v1/api/status         — API status + version
 */
class WPA_API {

    private const TOKEN_PREFIX  = 'wpa_';
    private const TOKEN_BYTES   = 32; // 64 hex chars

    // =========================================================================
    // Boot
    // =========================================================================

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        $ns = 'wpa/v1';

        register_rest_route($ns, '/api/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'endpoint_status'],
            'permission_callback' => [self::class, 'check_token'],
        ]);

        register_rest_route($ns, '/api/users', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'endpoint_users'],
            'permission_callback' => [self::class, 'check_token'],
            'args'                => [
                'page'     => ['default' => 1,   'sanitize_callback' => 'absint'],
                'per_page' => ['default' => 50,  'sanitize_callback' => 'absint'],
                'search'   => ['default' => '',   'sanitize_callback' => 'sanitize_text_field'],
                'premium'  => ['default' => '',   'sanitize_callback' => 'sanitize_text_field'],
            ],
        ]);

        register_rest_route($ns, '/api/users/(?P<id>\d+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'endpoint_user_by_id'],
            'permission_callback' => [self::class, 'check_token'],
            'args'                => [
                'id' => ['validate_callback' => fn($v) => is_numeric($v) && $v > 0],
            ],
        ]);

        register_rest_route($ns, '/api/users/email/(?P<email>[^/]+)', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'endpoint_user_by_email'],
            'permission_callback' => [self::class, 'check_token'],
        ]);
    }

    // =========================================================================
    // Authentication — Bearer token
    // =========================================================================

    public static function check_token(\WP_REST_Request $request): bool|\WP_Error {
        // Extract Bearer token from Authorization header
        $auth = $request->get_header('Authorization') ?? '';

        if (empty($auth)) {
            // Also accept ?api_token= query param (less secure, for testing)
            $auth = 'Bearer ' . ($request->get_param('api_token') ?? '');
        }

        if (!str_starts_with($auth, 'Bearer ')) {
            return new \WP_Error(
                'wpa_missing_token',
                'Authorization header required. Use: Authorization: Bearer wpa_xxx',
                ['status' => 401]
            );
        }

        $raw_token = trim(substr($auth, 7));

        if (empty($raw_token) || !str_starts_with($raw_token, self::TOKEN_PREFIX)) {
            return new \WP_Error(
                'wpa_invalid_token_format',
                'Invalid token format. Token must start with wpa_',
                ['status' => 401]
            );
        }

        $token_hash = self::hash_token($raw_token);

        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}wpa_api_tokens
             WHERE token_hash = %s AND is_active = 1",
            $token_hash
        ));

        if (!$row) {
            return new \WP_Error(
                'wpa_token_invalid',
                'Invalid or revoked API token.',
                ['status' => 401]
            );
        }

        // Update last_used timestamp
        $wpdb->update(
            $wpdb->prefix . 'wpa_api_tokens',
            ['last_used' => current_time('mysql')],
            ['id' => $row->id],
            ['%s'],
            ['%d']
        );

        return true;
    }

    // =========================================================================
    // Endpoints
    // =========================================================================

    public static function endpoint_status(\WP_REST_Request $request): \WP_REST_Response {
        return self::json_response([
            'status'     => 'ok',
            'plugin'     => 'WP Premium Access',
            'version'    => WPA_VERSION,
            'api'        => 'v1',
            'endpoints'  => [
                'GET /wpa/v1/api/status',
                'GET /wpa/v1/api/users',
                'GET /wpa/v1/api/users/{id}',
                'GET /wpa/v1/api/users/email/{email}',
            ],
        ]);
    }

    public static function endpoint_users(\WP_REST_Request $request): \WP_REST_Response {
        $page     = max(1, (int) $request->get_param('page'));
        $per_page = min(200, max(1, (int) $request->get_param('per_page')));
        $search   = sanitize_text_field($request->get_param('search'));
        $premium  = $request->get_param('premium'); // 'true'|'false'|''

        $args = [
            'number'  => $per_page,
            'offset'  => ($page - 1) * $per_page,
            'orderby' => 'ID',
            'order'   => 'ASC',
        ];

        if ($search) {
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        // Filter premium only
        if ($premium === 'true') {
            global $wpdb;
            $premium_ids = $wpdb->get_col(
                "SELECT DISTINCT user_id FROM {$wpdb->prefix}wpa_roles
                 WHERE expires_at IS NULL OR expires_at > NOW()"
            );
            if (empty($premium_ids)) {
                return self::json_response(['users' => [], 'total' => 0, 'page' => $page, 'per_page' => $per_page]);
            }
            $args['include'] = $premium_ids;
        }

        $query      = new WP_User_Query($args);
        $users      = $query->get_results();
        $total      = $query->get_total();

        $data = array_map([self::class, 'format_user'], $users);

        return self::json_response([
            'users'    => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
        ]);
    }

    public static function endpoint_user_by_id(\WP_REST_Request $request): \WP_REST_Response {
        $user = get_user_by('ID', (int) $request->get_param('id'));

        if (!$user) {
            return self::json_response(['error' => 'User not found'], 404);
        }

        return self::json_response(['user' => self::format_user($user)]);
    }

    public static function endpoint_user_by_email(\WP_REST_Request $request): \WP_REST_Response {
        $email = rawurldecode($request->get_param('email'));
        $email = sanitize_email($email);
        $user  = get_user_by('email', $email);

        if (!$user) {
            return self::json_response(['error' => 'User not found'], 404);
        }

        return self::json_response(['user' => self::format_user($user)]);
    }

    // =========================================================================
    // User formatter
    // =========================================================================

    private static function format_user(\WP_User $user): array {
        $user_id    = $user->ID;
        // WP admins are returned as premium in API responses
        $is_wp_admin = $user->has_cap('manage_options');
        $is_premium  = $is_wp_admin || WPA_Roles::is_premium($user_id);

        // WPA roles with expiry
        global $wpdb;
        $wpa_roles = $wpdb->get_results($wpdb->prepare(
            "SELECT role, expires_at, granted_at, granted_by
             FROM {$wpdb->prefix}wpa_roles
             WHERE user_id = %d
             ORDER BY granted_at DESC",
            $user_id
        ));

        $active_roles   = [];
        $expired_roles  = [];

        foreach ($wpa_roles as $r) {
            $is_active = $r->expires_at === null || strtotime($r->expires_at) > time();
            $entry = [
                'role'       => $r->role,
                'granted_at' => $r->granted_at,
                'granted_by' => $r->granted_by,
                'expires_at' => $r->expires_at,
                'is_active'  => $is_active,
                'is_lifetime'=> $r->expires_at === null,
            ];
            $is_active ? $active_roles[] = $entry : $expired_roles[] = $entry;
        }

        // Nearest expiry
        $nearest_expiry = null;
        foreach ($active_roles as $r) {
            if ($r['expires_at'] === null) { $nearest_expiry = null; break; }
            if ($nearest_expiry === null || $r['expires_at'] < $nearest_expiry) {
                $nearest_expiry = $r['expires_at'];
            }
        }

        // WPA permissions (from active roles)
        $permissions = WPA_Roles::get_user_permissions($user_id);

        // WP native capabilities (active ones only)
        $wp_caps = array_keys(array_filter($user->allcaps));
        sort($wp_caps);

        return [
            'id'            => $user_id,
            'email'         => $user->user_email,
            'first_name'    => get_user_meta($user_id, 'first_name', true) ?: '',
            'last_name'     => get_user_meta($user_id, 'last_name', true) ?: '',
            'display_name'  => $user->display_name,
            'username'      => $user->user_login,
            'registered_at' => $user->user_registered,
            'wp_roles'      => $user->roles,
            'wp_caps'       => $wp_caps,
            'wpa'           => [
                'is_premium'     => $is_premium,
                'is_wp_admin'    => $is_wp_admin,
                'is_active'      => $is_premium && (
                    $nearest_expiry === null || strtotime($nearest_expiry) > time()
                ),
                'expires_at'     => $nearest_expiry,
                'is_lifetime'    => $is_premium && $nearest_expiry === null,
                'roles'          => $active_roles,
                'roles_expired'  => $expired_roles,
                'permissions'    => $permissions,
            ],
        ];
    }

    // =========================================================================
    // Public user formatter — used by SSO /api/me and /sso/verify
    // Same as format_user but callable from WPA_SSO without circular dep
    // =========================================================================

    public static function format_user_public(\WP_User $user): array {
        return self::format_user($user);
    }

    // =========================================================================
    // Token management — called from admin
    // =========================================================================

    public static function generate_token(string $description, int $created_by): array {
        $raw        = self::TOKEN_PREFIX . bin2hex(random_bytes(self::TOKEN_BYTES));
        $token_hash = self::hash_token($raw);

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'wpa_api_tokens',
            [
                'token_hash'  => $token_hash,
                'description' => sanitize_text_field($description),
                'created_by'  => $created_by,
                'is_active'   => 1,
            ],
            ['%s', '%s', '%d', '%d']
        );

        return [
            'id'          => $wpdb->insert_id,
            'token'       => $raw, // shown ONCE — never stored in plain text
            'description' => $description,
        ];
    }

    public static function revoke_token(int $id): bool {
        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'wpa_api_tokens',
            ['is_active' => 0],
            ['id' => $id],
            ['%d'],
            ['%d']
        );
    }

    public static function delete_token(int $id): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            $wpdb->prefix . 'wpa_api_tokens',
            ['id' => $id],
            ['%d']
        );
    }

    public static function get_tokens(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT id, description, created_at, last_used, created_by, is_active
             FROM {$wpdb->prefix}wpa_api_tokens
             ORDER BY created_at DESC"
        ) ?: [];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private static function hash_token(string $raw): string {
        return hash('sha256', $raw);
    }

    private static function json_response(array $data, int $status = 200): \WP_REST_Response {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Content-Type', 'application/json; charset=UTF-8');
        $response->header('X-WPA-Version', WPA_VERSION);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate');
        return $response;
    }
}
