<?php
defined( 'ABSPATH' ) || exit;

/**
 * Multi-layer spam protection.
 *
 * Layers (in order):
 * 1. Nonce verification (WordPress core)
 * 2. Honeypot field
 * 3. Minimum fill time
 * 4. Rate limiting per IP + form
 * 5. reCAPTCHA v3 (if configured)
 * 6. Akismet (if active)
 */
class SCF_Antispam {

    /** Accumulated spam score (0 = clean, 100 = definite spam). */
    private int $score = 0;

    /** Human-readable reasons for a spam flag. */
    private array $reasons = [];

    /**
     * Run all checks.
     *
     * @param  array $post    Raw $_POST data.
     * @param  int   $form_id Form post ID.
     * @return array { passed: bool, score: int, reasons: string[] }
     */
    public function check( array $post, int $form_id ): array {
        $this->score   = 0;
        $this->reasons = [];

        $this->check_honeypot( $post );
        $this->check_fill_time( $post );
        $this->check_rate_limit( $form_id );
        $this->check_recaptcha( $post );
        $this->check_akismet( $post, $form_id );

        $passed = $this->score < 60;

        return [
            'passed'  => $passed,
            'score'   => $this->score,
            'reasons' => $this->reasons,
        ];
    }

    // -------------------------------------------------------------------------
    // Layer 2 – Honeypot
    // -------------------------------------------------------------------------

    private function check_honeypot( array $post ): void {
        // The honeypot field must exist AND be empty.
        $hp_key = 'scf_hp_' . ( $post['form_id'] ?? '0' );
        if ( ! isset( $post[ $hp_key ] ) || $post[ $hp_key ] !== '' ) {
            $this->flag( 100, 'honeypot' );
        }
    }

    /**
     * Generate the honeypot HTML for a given form.
     */
    public static function honeypot_field( int $form_id ): string {
        $key = 'scf_hp_' . $form_id;
        return sprintf(
            '<div class="scf-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;opacity:0;height:0;overflow:hidden;">'
            . '<label for="%1$s">%2$s</label>'
            . '<input type="text" id="%1$s" name="%1$s" value="" autocomplete="off" tabindex="-1">'
            . '</div>',
            esc_attr( $key ),
            esc_html__( 'Leave this field empty', 'smart-contact-form' )
        );
    }

    // -------------------------------------------------------------------------
    // Layer 3 – Minimum fill time
    // -------------------------------------------------------------------------

    private function check_fill_time( array $post ): void {
        $min   = (int) get_option( 'scf_min_fill_time', 3 );
        $start = (int) ( $post['scf_start_time'] ?? 0 );
        if ( $start && ( time() - $start ) < $min ) {
            $this->flag( 80, 'fill_time' );
        }
    }

    public static function start_time_field(): string {
        return sprintf(
            '<input type="hidden" name="scf_start_time" value="%d">',
            time()
        );
    }

    // -------------------------------------------------------------------------
    // Layer 4 – Rate limiting (DB-backed, per IP + form)
    // -------------------------------------------------------------------------

    private function check_rate_limit( int $form_id ): void {
        global $wpdb;

        $ip     = $this->get_ip();
        $max    = (int) get_option( 'scf_rate_limit_max', 5 );
        $window = (int) get_option( 'scf_rate_limit_window', 3600 );
        $table  = $wpdb->prefix . 'scf_rate_limit';
        $since  = gmdate( 'Y-m-d H:i:s', time() - $window );

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, hits, window_start FROM $table WHERE ip = %s AND form_id = %d",
            $ip, $form_id
        ) );

        if ( $row ) {
            if ( strtotime( $row->window_start ) < ( time() - $window ) ) {
                // Window expired – reset
                $wpdb->update( $table,
                    [ 'hits' => 1, 'window_start' => current_time( 'mysql', true ) ],
                    [ 'id' => $row->id ],
                    [ '%d', '%s' ], [ '%d' ]
                );
            } else {
                $wpdb->update( $table, [ 'hits' => $row->hits + 1 ], [ 'id' => $row->id ], [ '%d' ], [ '%d' ] );
                if ( ( $row->hits + 1 ) > $max ) {
                    $this->flag( 100, 'rate_limit' );
                }
            }
        } else {
            $wpdb->insert( $table,
                [ 'ip' => $ip, 'form_id' => $form_id, 'hits' => 1, 'window_start' => current_time( 'mysql', true ) ],
                [ '%s', '%d', '%d', '%s' ]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Layer 5 – reCAPTCHA v3
    // -------------------------------------------------------------------------

    private function check_recaptcha( array $post ): void {
        $secret = get_option( 'scf_recaptcha_secret_key', '' );
        if ( empty( $secret ) ) return;

        $token = sanitize_text_field( $post['scf_recaptcha_token'] ?? '' );
        if ( empty( $token ) ) {
            $this->flag( 60, 'recaptcha_missing_token' );
            return;
        }

        $response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', [
            'timeout' => 10,
            'body'    => [ 'secret' => $secret, 'response' => $token, 'remoteip' => $this->get_ip() ],
        ] );

        if ( is_wp_error( $response ) ) return; // Network failure: skip check

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $body['success'] ) ) {
            $this->flag( 70, 'recaptcha_failed' );
            return;
        }

        $threshold = (float) get_option( 'scf_recaptcha_threshold', 0.5 );
        $score     = (float) ( $body['score'] ?? 0 );
        if ( $score < $threshold ) {
            // Proportional score: low reCAPTCHA score = high spam score
            $spam_score = (int) round( ( 1 - $score ) * 80 );
            $this->flag( $spam_score, 'recaptcha_low_score:' . $score );
        }
    }

    // -------------------------------------------------------------------------
    // Layer 6 – Akismet
    // -------------------------------------------------------------------------

    private function check_akismet( array $post, int $form_id ): void {
        if ( ! function_exists( 'akismet_http_post' ) ) return;

        $api_key = get_option( 'wordpress_api_key', '' );
        if ( empty( $api_key ) ) return;

        $comment_data = [
            'blog'                 => get_option( 'home' ),
            'user_ip'              => $this->get_ip(),
            'user_agent'           => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referrer'             => $_SERVER['HTTP_REFERER'] ?? '',
            'permalink'            => '',
            'comment_type'         => 'contact-form',
            'comment_author'       => sanitize_text_field( $post['scf_name'] ?? '' ),
            'comment_author_email' => sanitize_email( $post['scf_email'] ?? '' ),
            'comment_content'      => sanitize_textarea_field( $post['scf_message'] ?? '' ),
        ];

        $response = akismet_http_post(
            http_build_query( $comment_data ),
            'rest.akismet.com',
            '/1.1/comment-check',
            443
        );

        if ( is_array( $response ) && isset( $response[1] ) ) {
            $body = trim( $response[1] );
            if ( 'true' === $body ) {
                $this->flag( 90, 'akismet' );
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function flag( int $score, string $reason ): void {
        $this->score    = max( $this->score, $score );
        $this->reasons[] = $reason;
    }

    private function get_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                return sanitize_text_field( explode( ',', $_SERVER[ $key ] )[0] );
            }
        }
        return '0.0.0.0';
    }
}
