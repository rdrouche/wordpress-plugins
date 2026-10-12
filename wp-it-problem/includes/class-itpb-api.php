<?php
/**
 * ITPB_Api
 *
 * REST API endpoints consumed by frontend.js.
 *
 * Namespace: itpb/v1
 *
 * Endpoints:
 *  GET  /itpb/v1/status/{id}   — reveal status + time_left for a problem
 *  GET  /itpb/v1/answer/{id}   — rendered answer HTML (only when revealed)
 *
 * Both endpoints are public (no auth required) but rate-limited via
 * a lightweight transient counter to avoid hammering on high-traffic sites.
 *
 * The status endpoint is polled by JS every 10s near reveal time.
 * The answer endpoint is called once when status.revealed flips to true.
 *
 * @package WP_IT_Problem
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class ITPB_Api {

    const NAMESPACE = 'itpb/v1';

    /** Max requests per IP per minute for public endpoints */
    const RATE_LIMIT = 30;

    public function init(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    // -----------------------------------------------------------------------
    // Route registration
    // -----------------------------------------------------------------------

    public function register_routes(): void {
        // GET /itpb/v1/status/{id}
        register_rest_route( self::NAMESPACE, '/status/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_status' ],
            'permission_callback' => '__return_true',  // public
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // GET /itpb/v1/answer/{id}
        register_rest_route( self::NAMESPACE, '/answer/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_answer' ],
            'permission_callback' => '__return_true',  // public — content gated inside callback
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => fn( $v ) => is_numeric( $v ) && $v > 0,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );
    }

    // -----------------------------------------------------------------------
    // GET /itpb/v1/status/{id}
    // -----------------------------------------------------------------------

    /**
     * Return the reveal status for a problem.
     *
     * Response shape:
     * {
     *   "revealed":  bool,
     *   "reveal_at": int|null,   // Unix timestamp UTC
     *   "time_left": int,        // seconds remaining (0 when revealed)
     *   "cached":    bool        // debug: came from transient
     * }
     *
     * @param  \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_status( \WP_REST_Request $request ) {
        $problem_id = $request->get_param( 'id' );

        // Rate limit
        $rl = $this->check_rate_limit( 'status_' . $problem_id );
        if ( is_wp_error( $rl ) ) {
            return $rl;
        }

        // Validate the post
        $problem = get_post( $problem_id );
        if (
            ! $problem
            || $problem->post_type !== ITPB_CPT_PROBLEM
            || $problem->post_status !== 'publish'
        ) {
            return new \WP_Error(
                'itpb_not_found',
                __( 'Problem not found.', 'wp-it-problem' ),
                [ 'status' => 404 ]
            );
        }

        // Try transient cache first
        $cached = ITPB_Cron::get_cached_status( $problem_id );
        if ( $cached !== null ) {
            $cached['cached'] = true;
            return $this->json_response( $cached );
        }

        // Compute fresh status
        $reveal_at = itpb_get_reveal_at( $problem_id );
        $now       = time();

        if ( ! $reveal_at ) {
            $status = [
                'revealed'  => false,
                'reveal_at' => null,
                'time_left' => 0,
                'cached'    => false,
            ];
        } else {
            $ts       = $reveal_at->getTimestamp();
            $revealed = $now >= $ts;
            $status   = [
                'revealed'  => $revealed,
                'reveal_at' => $ts,
                'time_left' => $revealed ? 0 : max( 0, $ts - $now ),
                'cached'    => false,
            ];
        }

        // Cache it
        ITPB_Cron::set_cached_status( $problem_id, $status );

        return $this->json_response( $status );
    }

    // -----------------------------------------------------------------------
    // GET /itpb/v1/answer/{id}
    // -----------------------------------------------------------------------

    /**
     * Return the rendered answer HTML for a problem.
     *
     * Only responds when the answer is revealed AND published.
     * The HTML is the full WP content pipeline output (same as page render).
     *
     * Response shape:
     * {
     *   "revealed": true,
     *   "html":     string   // sanitized HTML
     * }
     *
     * Error when not yet revealed:
     * {
     *   "revealed":  false,
     *   "time_left": int
     * }
     *
     * @param  \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_answer( \WP_REST_Request $request ) {
        $problem_id = $request->get_param( 'id' );

        // Rate limit
        $rl = $this->check_rate_limit( 'answer_' . $problem_id );
        if ( is_wp_error( $rl ) ) {
            return $rl;
        }

        // Validate the problem
        $problem = get_post( $problem_id );
        /** @see ITPB_Polylang::filter_api_by_language() */
        $problem = apply_filters( 'itpb_api_problem_post', $problem, $request );
        if (
            ! $problem
            || $problem->post_type !== ITPB_CPT_PROBLEM
            || $problem->post_status !== 'publish'
        ) {
            return new \WP_Error(
                'itpb_not_found',
                __( 'Problem not found.', 'wp-it-problem' ),
                [ 'status' => 404 ]
            );
        }

        // Not yet revealed
        if ( ! itpb_is_revealed( $problem_id ) ) {
            $reveal_at = itpb_get_reveal_at( $problem_id );
            $ts        = $reveal_at ? $reveal_at->getTimestamp() : null;

            return $this->json_response( [
                'revealed'  => false,
                'time_left' => $ts ? max( 0, $ts - time() ) : 0,
            ], 403 );
        }

        // Get the linked answer
        $answer = itpb_get_answer_post( $problem_id );

        if ( ! $answer ) {
            // Revealed by date but no answer post — cron lag or orphan
            return $this->json_response( [
                'revealed' => true,
                'html'     => sprintf(
                    '<p class="itpb-cron-lag-msg">%s</p>',
                    esc_html__( 'The answer is being published. Please refresh in a moment.', 'wp-it-problem' )
                ),
            ] );
        }

        if ( $answer->post_status !== 'publish' ) {
            // Revealed but cron hasn't flipped yet — trigger manually
            ( new ITPB_Cron() )->reveal_problem( $problem_id );
            // Re-fetch
            $answer = itpb_get_answer_post( $problem_id );
        }

        // Render the answer content through the full WP pipeline.
        // Use a static guard to prevent any re-entrant the_content call
        // (other plugins may also hook the_content and call apply_filters again).
        static $rendering = false;
        if ( $rendering ) {
            return $this->json_response( [
                'revealed' => true,
                'html'     => wpautop( wp_kses_post( $answer->post_content ?? '' ) ),
            ] );
        }
        $rendering = true;

        global $post;
        $original_post = $post;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $post = $answer;
        setup_postdata( $post );

        $html = apply_filters( 'the_content', $answer->post_content );

        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $post      = $original_post;
        $rendering = false;
        wp_reset_postdata();

        // Wrap in the same markup as the server-rendered version
        // so CSS selectors match
        $wrapped = sprintf(
            '<div class="itpb-answer-header"><span class="itpb-answer-badge itpb-badge-revealed">&#10003; %s</span></div><div class="itpb-answer-content">%s</div>',
            esc_html( itpb_str( 'itpb_answer_title' ) ),
            $html   // already through the_content pipeline — trusted
        );

        return $this->json_response( [
            'revealed' => true,
            'html'     => $wrapped,
        ] );
    }

    // -----------------------------------------------------------------------
    // Rate limiting
    // -----------------------------------------------------------------------

    /**
     * Simple transient-based rate limiter per IP.
     * Allows RATE_LIMIT requests per IP per minute per key.
     *
     * @param  string $key  Unique key for the endpoint + resource.
     * @return true|\WP_Error
     */
    private function check_rate_limit( string $key ): true|\WP_Error {
        // Admins bypass rate limiting
        if ( current_user_can( 'edit_posts' ) ) {
            return true;
        }

        $ip       = $this->get_client_ip();
        $tkey     = 'itpb_rl_' . md5( $ip . '_' . $key );
        $count    = (int) get_transient( $tkey );

        if ( $count >= self::RATE_LIMIT ) {
            return new \WP_Error(
                'itpb_rate_limit',
                __( 'Too many requests. Please slow down.', 'wp-it-problem' ),
                [ 'status' => 429 ]
            );
        }

        // Increment — TTL resets to 60s on each hit within the window
        // (simple sliding window, good enough for this use case)
        set_transient( $tkey, $count + 1, MINUTE_IN_SECONDS );

        return true;
    }

    /**
     * Get the client IP, respecting common reverse proxy headers.
     * Not used for auth — only for rate limiting (best-effort).
     *
     * @return string
     */
    private function get_client_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP',   // Cloudflare
            'HTTP_X_FORWARDED_FOR',    // General proxies / LiteSpeed
            'HTTP_X_REAL_IP',          // nginx
            'REMOTE_ADDR',
        ];

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                // X-Forwarded-For may contain a comma-separated list
                $ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return 'unknown';
    }

    // -----------------------------------------------------------------------
    // Response helper
    // -----------------------------------------------------------------------

    /**
     * Build a WP_REST_Response with appropriate cache headers.
     *
     * @param  array $data
     * @param  int   $status  HTTP status code.
     * @return \WP_REST_Response
     */
    private function json_response( array $data, int $status = 200 ): \WP_REST_Response {
        $response = new \WP_REST_Response( $data, $status );

        // Status endpoint: short cache for CDN/browser (10s)
        // Answer endpoint: longer cache once revealed (1h)
        $max_age = isset( $data['html'] ) ? 3600 : 10;

        // Only cache revealed responses
        if ( isset( $data['revealed'] ) && ! $data['revealed'] ) {
            $max_age = 0;
        }

        $response->header( 'Cache-Control', "public, max-age={$max_age}" );
        $response->header( 'X-ITPB-Version', ITPB_VERSION );

        return $response;
    }
}
