<?php
defined( 'ABSPATH' ) || exit;

class SCF_Mailer {

    public function send( int $form_id, array $data ): bool {
        $to         = get_post_meta( $form_id, '_scf_recipient_email', true ) ?: get_option( 'scf_admin_email', get_option( 'admin_email' ) );
        $from_name  = get_option( 'scf_from_name', get_option( 'blogname' ) );
        $from_email = get_option( 'scf_from_email', get_option( 'admin_email' ) );

        $sender_email = sanitize_email( $data['scf_email'] ?? '' );
        $sender_name  = sanitize_text_field( $data['scf_name'] ?? '' );

        $subject_template = get_post_meta( $form_id, '_scf_email_subject', true )
            ?: /* translators: %s: site name */ sprintf( __( 'New message from %s', 'smart-contact-form' ), get_option( 'blogname' ) );

        $subject = $this->interpolate( $subject_template, $data );

        // HTML body
        $body = $this->build_html_body( $form_id, $data );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
        ];

        if ( $sender_email && is_email( $sender_email ) ) {
            $headers[] = "Reply-To: {$sender_name} <{$sender_email}>";
        }

        $sent = wp_mail( $to, $subject, $body, $headers );

        // Optional: send confirmation to the submitter
        $send_copy = get_post_meta( $form_id, '_scf_send_copy', true );
        if ( $send_copy && $sender_email && is_email( $sender_email ) ) {
            $copy_subject  = get_post_meta( $form_id, '_scf_copy_subject', true )
                ?: __( 'We received your message', 'smart-contact-form' );
            $copy_template = get_post_meta( $form_id, '_scf_copy_body', true )
                ?: __( "Hello {scf_name},\n\nThank you for contacting us. We'll get back to you as soon as possible.", 'smart-contact-form' );
            $copy_body = nl2br( esc_html( $this->interpolate( $copy_template, $data ) ) );

            wp_mail( $sender_email, $copy_subject, '<p>' . $copy_body . '</p>', $headers );
        }

        return $sent;
    }

    private function build_html_body( int $form_id, array $data ): string {
        $fields = get_post_meta( $form_id, '_scf_fields', true ) ?: SCF_Ajax::default_fields();
        $rows   = '';
        foreach ( $fields as $field ) {
            $value = nl2br( esc_html( $data[ $field['name'] ] ?? '' ) );
            $rows .= "<tr>
                <td style='padding:8px 12px;background:#f4f4f5;font-weight:600;width:30%;vertical-align:top;'>" . esc_html( $field['label'] ) . "</td>
                <td style='padding:8px 12px;'>{$value}</td>
            </tr>";
        }

        $site = esc_html( get_option( 'blogname' ) );
        $url  = esc_url( home_url() );

        return "<!DOCTYPE html><html><body style='font-family:Arial,sans-serif;color:#333;max-width:600px;margin:auto;'>
            <div style='background:#1a1a2e;padding:20px;text-align:center;'>
                <h1 style='color:#fff;margin:0;font-size:20px;'>{$site}</h1>
            </div>
            <div style='padding:24px;background:#fff;'>
                <h2 style='color:#1a1a2e;'>" . esc_html__( 'New Contact Form Submission', 'smart-contact-form' ) . "</h2>
                <table cellpadding='0' cellspacing='0' style='width:100%;border-collapse:collapse;'>{$rows}</table>
            </div>
            <div style='padding:12px;background:#f4f4f5;text-align:center;font-size:12px;color:#888;'>
                " . sprintf( esc_html__( 'Sent via %s', 'smart-contact-form' ), "<a href='{$url}'>{$site}</a>" ) . "
            </div>
        </body></html>";
    }

    private function interpolate( string $template, array $data ): string {
        foreach ( $data as $key => $value ) {
            $template = str_replace( '{' . $key . '}', $value, $template );
        }
        return $template;
    }
}
