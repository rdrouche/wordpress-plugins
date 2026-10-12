<?php
defined('ABSPATH') || exit;

/**
 * WPA_SSO — Single Sign-On via JWT for cross-domain authentication.
 *
 * FLOW OVERVIEW
 * =============
 *
 * Same-domain (*.rdr-it.com)
 * ──────────────────────────
 *   1. User logs in on rdr-it.com via magic link
 *   2. Cookie wpa_session + WP auth cookie set on .rdr-it.com
 *   3. app.rdr-it.com reads cookie → GET /wpa/v1/api/me → user data
 *
 * Cross-domain (autre-app.com, *.app.rdr-it.com, etc.)
 * ─────────────────────────────────────────────────────
 *   1. app.autre.com redirects user to:
 *      GET https://rdr-it.com/wp-json/wpa/v1/sso/init?redirect=https://app.autre.com/callback
 *   2. If user is logged in → WP generates a short-lived JWT (5 min, single-use)
 *      and redirects to: https://app.autre.com/callback?jwt=eyJ...
 *   3. If user is NOT logged in → WP shows login page, then completes SSO flow
 *   4. app.autre.com calls:
 *      POST https://rdr-it.com/wp-json/wpa/v1/sso/verify  body: {"token":"eyJ..."}
 *   5. WP validates JWT → returns full user object
 *   6. app.autre.com creates its own session
 *
 * JWT STRUCTURE
 * =============
 *   Header : {"alg":"HS256","typ":"JWT"}
 *   Payload: {
 *     "sub": 35,                      // WP user ID
 *     "email": "romain@rdr-it.com",
 *     "iss": "https://rdr-it.com",    // issuer
 *     "aud": "https://app.autre.com", // intended audience (redirect domain)
 *     "iat": 1234567890,              // issued at
 *     "exp": 1234568190,              // expires at (iat + 300s)
 *     "jti": "uuid4",                 // unique token ID (prevents replay)
 *     "wpa": {
 *       "is_premium": true,
 *       "roles": ["premium_basic"],
 *       "permissions": ["no_ads","early_access"]
 *     }
 *   }
 *
 * SECURITY
 * ========
 *   - JWT signed with HMAC-SHA256 using AUTH_KEY from wp-config.php
 *   - 5-minute expiry (configurable via wpa_sso_jwt_ttl option)
 *   - Single-use: jti stored in transient after first use, replay rejected
 *   - redirect URL validated against allowlist (wpa_sso_allowed_origins)
 *   - /sso/init endpoint NOT cached (nocache_headers)
 *
 * ALLOWED ORIGINS
 * ===============
 *   Configure in Settings → SSO → Allowed origins (one URL per line).
 *   Wildcards supported: https://*.rdr-it.com matches all subdomains.
 *   Example:
 *     https://app.rdr-it.com
 *     https://*.app.rdr-it.com
 *     https://mon-autre-app.com
 *
 * PUBLIC ENDPOINTS (no admin API token required)
 * ================================================
 *   GET  /wp-json/wpa/v1/sso/init?redirect=URL
 *        Initiates SSO flow. Redirects with ?jwt= if logged in.
 *        Auth: user must be logged in (WP session or WPA cookie).
 *
 *   POST /wp-json/wpa/v1/sso/verify
 *        Validates a JWT. Returns user object or 401.
 *        Body: {"token": "eyJ..."}
 *        Auth: none (JWT is the credential)
 *
 *   GET  /wp-json/wpa/v1/api/me
 *        Returns current user from WPA cookie or Bearer JWT.
 *        Auth: Cookie wpa_session=xxx  OR  Authorization: Bearer eyJ...
 *
 * USAGE EXAMPLES
 * ==============
 *
 * JavaScript (app satellite) — initiate SSO:
 *   window.location.href =
 *     'https://rdr-it.com/wp-json/wpa/v1/sso/init'
 *     + '?redirect=' + encodeURIComponent(window.location.origin + '/callback');
 *
 * PHP (app satellite) — verify JWT on callback:
 *   $res = file_get_contents('https://rdr-it.com/wp-json/wpa/v1/sso/verify', false,
 *     stream_context_create(['http' => [
 *       'method'  => 'POST',
 *       'header'  => 'Content-Type: application/json',
 *       'content' => json_encode(['token' => $_GET['jwt']]),
 *     ]])
 *   );
 *   $user = json_decode($res, true)['user'] ?? null;
 *
 * Node.js (app satellite):
 *   const res = await fetch('https://rdr-it.com/wp-json/wpa/v1/sso/verify', {
 *     method: 'POST',
 *     headers: { 'Content-Type': 'application/json' },
 *     body: JSON.stringify({ token: new URL(location.href).searchParams.get('jwt') }),
 *   });
 *   const { user } = await res.json();
 *
 * /api/me — same-domain app with cookie:
 *   const res = await fetch('https://rdr-it.com/wp-json/wpa/v1/api/me', {
 *     credentials: 'include',   // sends cookies cross-origin
 *   });
 *
 * /api/me — with JWT Bearer:
 *   const res = await fetch('https://rdr-it.com/wp-json/wpa/v1/api/me', {
 *     headers: { Authorization: 'Bearer ' + jwt },
 *   });
 *
 * HOOKS & FILTERS
 * ===============
 *   filter  wpa_sso_jwt_ttl          int     JWT lifetime in seconds (default 300)
 *   filter  wpa_sso_allowed_origins  array   Allowed redirect domains
 *   filter  wpa_sso_jwt_payload      array   Modify JWT payload before signing
 *   action  wpa_sso_login            int     Fired after successful SSO verify ($user_id)
 */
class WPA_SSO {

    private const ALGO         = 'sha256';
    private const DEFAULT_TTL  = 300; // 5 minutes

    // =========================================================================
    // Boot
    // =========================================================================

    public static function init(): void {
        add_action('rest_api_init', [self::class, 'register_routes']);
    }

    public static function register_routes(): void {
        $ns = 'wpa/v1';

        // SSO init — redirect flow (no admin token required)
        register_rest_route($ns, '/sso/init', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'endpoint_sso_init'],
            'permission_callback' => '__return_true',
            'args'                => [
                'redirect' => [
                    'required'          => true,
                    'sanitize_callback' => 'esc_url_raw',
                ],
            ],
        ]);

        // SSO verify — validate JWT (no admin token required)
        register_rest_route($ns, '/sso/verify', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'endpoint_sso_verify'],
            'permission_callback' => '__return_true',
        ]);

        // /api/me — current user via cookie or Bearer JWT (no admin token required)
        register_rest_route($ns, '/api/me', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'endpoint_me'],
            'permission_callback' => '__return_true',
        ]);
    }

    // =========================================================================
    // Endpoint — GET /wpa/v1/sso/init?redirect=URL
    // =========================================================================

    public static function endpoint_sso_init(\WP_REST_Request $request): void {
        nocache_headers();

        $redirect = esc_url_raw($request->get_param('redirect'));

        // Validate redirect URL against allowlist
        $origin_error = self::validate_origin($redirect);
        if ($origin_error) {
            wp_die(esc_html($origin_error), 'SSO Error', ['response' => 403]);
        }

        // Resolve current user — WP session or WPA cookie
        $user_id = self::resolve_user();

        if (!$user_id) {
            // Not logged in — send to magic link login page with redirect_to
            $login_url = add_query_arg(
                'redirect_to',
                urlencode(rest_url('wpa/v1/sso/init') . '?redirect=' . urlencode($redirect)),
                home_url('/connexion-premium/')
            );
            wp_safe_redirect($login_url);
            exit;
        }

        // Generate JWT and redirect
        $jwt          = self::generate_jwt($user_id, $redirect);
        $redirect_url = add_query_arg('jwt', rawurlencode($jwt), $redirect);

        wp_safe_redirect($redirect_url);
        exit;
    }

    // =========================================================================
    // Endpoint — POST /wpa/v1/sso/verify  body: {"token":"eyJ..."}
    // =========================================================================

    public static function endpoint_sso_verify(\WP_REST_Request $request): \WP_REST_Response {
        nocache_headers();

        $body  = $request->get_json_params();
        $token = sanitize_text_field($body['token'] ?? '');

        if (empty($token)) {
            return self::json(['error' => 'token is required'], 400);
        }

        $payload = self::validate_jwt($token);

        if (is_wp_error($payload)) {
            return self::json([
                'error'   => $payload->get_error_message(),
                'code'    => $payload->get_error_code(),
            ], 401);
        }

        $user = get_user_by('ID', (int) $payload['sub']);
        if (!$user) {
            return self::json(['error' => 'User not found'], 404);
        }

        // Mark JWT as used (single-use via transient)
        set_transient('wpa_sso_jti_' . $payload['jti'], 1, self::get_ttl() + 60);

        do_action('wpa_sso_login', $user->ID);

        return self::json([
            'valid' => true,
            'user'  => WPA_API::format_user_public($user),
            'jwt'   => [
                'issued_at'  => $payload['iat'],
                'expires_at' => $payload['exp'],
                'audience'   => $payload['aud'] ?? '',
            ],
        ]);
    }

    // =========================================================================
    // Endpoint — GET /wpa/v1/api/me
    // =========================================================================

    public static function endpoint_me(\WP_REST_Request $request): \WP_REST_Response {
        nocache_headers();

        $user_id = null;

        // Priority 1: Authorization: Bearer {JWT}
        $auth = $request->get_header('Authorization') ?? '';
        if (str_starts_with($auth, 'Bearer ')) {
            $token   = trim(substr($auth, 7));
            $payload = self::validate_jwt($token);
            if (!is_wp_error($payload)) {
                $user_id = (int) $payload['sub'];
            }
        }

        // Priority 2: WPA session cookie
        if (!$user_id) {
            $cookie  = sanitize_text_field($_COOKIE[WPA_Sessions::COOKIE_NAME] ?? '');
            $user_id = $cookie ? WPA_Sessions::validate($cookie) : null;
        }

        // Priority 3: WP native auth cookie
        if (!$user_id && is_user_logged_in()) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            return self::json(['error' => 'Not authenticated', 'code' => 'unauthenticated'], 401);
        }

        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return self::json(['error' => 'User not found'], 404);
        }

        return self::json(['user' => WPA_API::format_user_public($user)]);
    }

    // =========================================================================
    // JWT — generate
    // =========================================================================

    public static function generate_jwt(int $user_id, string $audience = ''): string {
        $now    = time();
        $ttl    = self::get_ttl();
        $jti    = wp_generate_uuid4();
        $user   = get_user_by('ID', $user_id);

        $payload = [
            'iss'   => home_url('/'),
            'aud'   => $audience ? self::get_origin($audience) : '',
            'sub'   => $user_id,
            'email' => $user ? $user->user_email : '',
            'iat'   => $now,
            'exp'   => $now + $ttl,
            'jti'   => $jti,
            'wpa'   => [
                'is_premium'  => WPA_Roles::is_premium($user_id),
                'roles'       => WPA_Roles::get_roles($user_id),
                'permissions' => WPA_Roles::get_user_permissions($user_id),
            ],
        ];

        $payload = apply_filters('wpa_sso_jwt_payload', $payload, $user_id);

        $header       = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload_enc  = self::b64url(json_encode($payload));
        $signature    = self::b64url(hash_hmac(self::ALGO, "$header.$payload_enc", self::secret(), true));

        return "$header.$payload_enc.$signature";
    }

    // =========================================================================
    // JWT — validate
    // =========================================================================

    public static function validate_jwt(string $token): array|\WP_Error {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return new \WP_Error('wpa_jwt_malformed', 'Malformed JWT');
        }

        [$header_enc, $payload_enc, $sig_enc] = $parts;

        // Verify signature
        $expected = self::b64url(hash_hmac(self::ALGO, "$header_enc.$payload_enc", self::secret(), true));
        if (!hash_equals($expected, $sig_enc)) {
            return new \WP_Error('wpa_jwt_invalid_sig', 'Invalid JWT signature');
        }

        $payload = json_decode(self::b64url_decode($payload_enc), true);
        if (!is_array($payload)) {
            return new \WP_Error('wpa_jwt_invalid_payload', 'Invalid JWT payload');
        }

        // Expiry
        if (empty($payload['exp']) || time() > $payload['exp']) {
            return new \WP_Error('wpa_jwt_expired', 'JWT has expired');
        }

        // Issuer
        if (empty($payload['iss']) || rtrim($payload['iss'], '/') !== rtrim(home_url('/'), '/')) {
            return new \WP_Error('wpa_jwt_invalid_issuer', 'Invalid JWT issuer');
        }

        // Single-use check
        $jti = sanitize_text_field($payload['jti'] ?? '');
        if ($jti && get_transient('wpa_sso_jti_' . $jti)) {
            return new \WP_Error('wpa_jwt_replayed', 'JWT already used');
        }

        return $payload;
    }

    // =========================================================================
    // Origin validation
    // =========================================================================

    private static function validate_origin(string $url): string {
        if (empty($url)) return 'redirect parameter is required';

        $origin  = self::get_origin($url);
        $allowed = self::get_allowed_origins();

        // Always allow same site
        if (str_starts_with($url, home_url('/'))) return '';

        foreach ($allowed as $pattern) {
            $pattern = trim($pattern);
            if (empty($pattern)) continue;
            if (self::origin_matches($origin, $pattern)) return '';
        }

        return sprintf('Redirect origin "%s" is not in the SSO allowlist. Add it in Settings → SSO.', $origin);
    }

    private static function origin_matches(string $origin, string $pattern): bool {
        $pattern_origin = self::get_origin($pattern);
        if (empty($pattern_origin)) $pattern_origin = $pattern;

        // Exact match
        if ($origin === $pattern_origin) return true;

        // Wildcard: https://*.rdr-it.com
        if (str_contains($pattern_origin, '*')) {
            $regex = '#^' . str_replace(['.', '*'], ['\.', '[^.]+'], $pattern_origin) . '$#';
            return (bool) preg_match($regex, $origin);
        }

        return false;
    }

    private static function get_origin(string $url): string {
        $parsed = parse_url($url);
        if (!$parsed) return '';
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host'] ?? '';
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        return "$scheme://$host$port";
    }

    private static function get_allowed_origins(): array {
        $raw     = get_option('wpa_sso_allowed_origins', '');
        $lines   = array_filter(array_map('trim', explode("\n", $raw)));
        $origins = apply_filters('wpa_sso_allowed_origins', $lines);
        return (array) $origins;
    }

    // =========================================================================
    // User resolution
    // =========================================================================

    private static function resolve_user(): ?int {
        // WP native session
        if (is_user_logged_in()) return get_current_user_id();

        // WPA session cookie
        $cookie  = sanitize_text_field($_COOKIE[WPA_Sessions::COOKIE_NAME] ?? '');
        $user_id = $cookie ? WPA_Sessions::validate($cookie) : null;
        if ($user_id) return $user_id;

        return null;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private static function secret(): string {
        return defined('AUTH_KEY') ? AUTH_KEY : wp_salt('auth');
    }

    private static function get_ttl(): int {
        return (int) apply_filters('wpa_sso_jwt_ttl', get_option('wpa_sso_jwt_ttl', self::DEFAULT_TTL));
    }

    private static function b64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 3 - (3 + strlen($data)) % 4));
    }

    private static function json(array $data, int $status = 200): \WP_REST_Response {
        $response = new \WP_REST_Response($data, $status);
        $response->header('Content-Type', 'application/json; charset=UTF-8');
        $response->header('Cache-Control', 'no-store, no-cache');
        $response->header('X-WPA-Version', WPA_VERSION);
        // CORS — allow any origin for /api/me and /sso/verify (credentials handled by caller)
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin && self::validate_origin($origin) === '') {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Credentials', 'true');
            $response->header('Vary', 'Origin');
        }
        return $response;
    }
}
