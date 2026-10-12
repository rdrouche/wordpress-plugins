<?php
defined( 'ABSPATH' ) || exit;

class SCF_Ajax {

    public static function init(): void {
        add_action( 'wp_ajax_scf_submit',        [ __CLASS__, 'handle_submit' ] );
        add_action( 'wp_ajax_nopriv_scf_submit', [ __CLASS__, 'handle_submit' ] );
        add_action( 'wp_ajax_scf_load_form',        [ __CLASS__, 'handle_load' ] );
        add_action( 'wp_ajax_nopriv_scf_load_form', [ __CLASS__, 'handle_load' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    // ------------------------------------------------------------------
    // Enqueue front-end assets
    // ------------------------------------------------------------------

    public static function enqueue(): void {
        wp_enqueue_style(
            'scf-public',
            SCF_PLUGIN_URL . 'public/css/scf-public.css',
            [],
            SCF_VERSION
        );

        wp_enqueue_script(
            'scf-public',
            SCF_PLUGIN_URL . 'public/js/scf-public.js',
            [],
            SCF_VERSION,
            true
        );

        $site_key = get_option( 'scf_recaptcha_site_key', '' );

        if ( $site_key ) {
            wp_enqueue_script(
                'google-recaptcha',
                'https://www.google.com/recaptcha/api.js?render=' . esc_attr( $site_key ),
                [],
                null,
                true
            );
        }

        wp_localize_script( 'scf-public', 'SCF', [
            'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
            'recaptchaSite'  => $site_key,
            'i18n'           => [
                'sending'  => __( 'Sending…', 'smart-contact-form' ),
                'error'    => __( 'An error occurred. Please try again.', 'smart-contact-form' ),
            ],
        ] );
    }

    // ------------------------------------------------------------------
    // Load form HTML via AJAX
    // ------------------------------------------------------------------

    public static function handle_load(): void {
        $form_id = (int) ( $_POST['form_id'] ?? 0 );
        if ( ! $form_id ) wp_send_json_error( [ 'message' => __( 'Invalid form.', 'smart-contact-form' ) ] );

        // Resolve Polylang translation
        $form_id = SCF_Polylang::resolve_form_id( $form_id );

        $form_post = get_post( $form_id );
        if ( ! $form_post || SCF_Post_Type::POST_TYPE !== $form_post->post_type ) {
            wp_send_json_error( [ 'message' => __( 'Form not found.', 'smart-contact-form' ) ] );
        }

        $html = self::render_form( $form_id );
        wp_send_json_success( [ 'html' => $html ] );
    }

    // ------------------------------------------------------------------
    // Submit form via AJAX
    // ------------------------------------------------------------------

    public static function handle_submit(): void {
        // 1. Nonce
        if ( ! isset( $_POST['scf_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scf_nonce'] ) ), 'scf_submit_' . (int) $_POST['form_id'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'smart-contact-form' ) ] );
        }

        $form_id = (int) ( $_POST['form_id'] ?? 0 );
        $form_id = SCF_Polylang::resolve_form_id( $form_id );

        $form_post = get_post( $form_id );
        if ( ! $form_post || SCF_Post_Type::POST_TYPE !== $form_post->post_type ) {
            wp_send_json_error( [ 'message' => __( 'Form not found.', 'smart-contact-form' ) ] );
        }

        // 2. Anti-spam
        $antispam = new SCF_Antispam();
        $spam     = $antispam->check( $_POST, $form_id );

        // 3. Sanitize & validate fields
        $fields_config = get_post_meta( $form_id, '_scf_fields', true ) ?: self::default_fields();
        $data          = [];
        $errors        = [];

        foreach ( $fields_config as $field ) {
            $key   = $field['name'];
            $value = '';

            switch ( $field['type'] ) {
                case 'email':
                    $value = sanitize_email( $_POST[ $key ] ?? '' );
                    if ( $field['required'] && ! is_email( $value ) ) {
                        $errors[ $key ] = __( 'Please enter a valid email address.', 'smart-contact-form' );
                    }
                    break;
                case 'textarea':
                    $value = sanitize_textarea_field( wp_unslash( $_POST[ $key ] ?? '' ) );
                    if ( $field['required'] && empty( $value ) ) {
                        $errors[ $key ] = sprintf( __( '%s is required.', 'smart-contact-form' ), esc_html( $field['label'] ) );
                    }
                    break;
                default:
                    $value = sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
                    if ( $field['required'] && empty( $value ) ) {
                        $errors[ $key ] = sprintf( __( '%s is required.', 'smart-contact-form' ), esc_html( $field['label'] ) );
                    }
            }
            $data[ $key ] = $value;
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [ 'errors' => $errors ] );
        }

        // 4. Save submission (even spam, but flagged)
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'scf_submissions',
            [
                'form_id'    => $form_id,
                'lang'       => function_exists( 'pll_current_language' ) ? pll_current_language() : '',
                'fields'     => wp_json_encode( $data ),
                'ip'         => self::get_ip(),
                'user_agent' => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
                'spam_score' => $spam['score'],
                'status'     => $spam['passed'] ? 'new' : 'spam',
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
        );

        if ( ! $spam['passed'] ) {
            // Silent fail for spambots – don't reveal detection
            $success_msg = get_post_meta( $form_id, '_scf_success_message', true )
                ?: __( 'Thank you! Your message has been sent.', 'smart-contact-form' );
            wp_send_json_success( [ 'message' => $success_msg ] );
        }

        // 5. Send email notification
        $mailer = new SCF_Mailer();
        $mailer->send( $form_id, $data );

        // 6. Success
        $success_msg = get_post_meta( $form_id, '_scf_success_message', true )
            ?: __( 'Thank you! Your message has been sent.', 'smart-contact-form' );

        /**
         * Fires after a valid form submission.
         *
         * @param int   $form_id  Form post ID.
         * @param array $data     Sanitized field data.
         */
        do_action( 'scf_after_submission', $form_id, $data );

        wp_send_json_success( [ 'message' => $success_msg ] );
    }

    // ------------------------------------------------------------------
    // Render form HTML
    // ------------------------------------------------------------------

    public static function render_form( int $form_id ): string {
        $fields  = get_post_meta( $form_id, '_scf_fields', true ) ?: self::default_fields();
        $nonce   = wp_create_nonce( 'scf_submit_' . $form_id );
        $site_key = get_option( 'scf_recaptcha_site_key', '' );

        ob_start();
        include SCF_PLUGIN_DIR . 'templates/form.php';
        return ob_get_clean();
    }

    // ------------------------------------------------------------------
    // Default field set for new forms
    // ------------------------------------------------------------------

    public static function default_fields(): array {
        return [
            [ 'name' => 'scf_name',    'label' => __( 'Your Name', 'smart-contact-form' ),    'type' => 'text',     'required' => true,  'placeholder' => '' ],
            [ 'name' => 'scf_email',   'label' => __( 'Email Address', 'smart-contact-form' ), 'type' => 'email',    'required' => true,  'placeholder' => '' ],
            [ 'name' => 'scf_subject', 'label' => __( 'Subject', 'smart-contact-form' ),       'type' => 'text',     'required' => false, 'placeholder' => '' ],
            [ 'name' => 'scf_message', 'label' => __( 'Message', 'smart-contact-form' ),       'type' => 'textarea', 'required' => true,  'placeholder' => '' ],
        ];
    }

    private static function get_ip(): string {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                return sanitize_text_field( explode( ',', $_SERVER[ $key ] )[0] );
            }
        }
        return '0.0.0.0';
    }
}
