<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * LibreTranslate provider (self-hosted or public).
 * API is similar to Google Translate (JSON POST).
 */
class WPPT_LibreTranslate_Provider extends WPPT_Google_Provider {

    public function __construct() {
        $base_url = rtrim( get_option( 'wppt_libretranslate_url', '' ), '/' );
        $this->endpoint = $base_url . '/translate';
        $this->api_key  = get_option( 'wppt_libretranslate_api_key', '' );
    }

    public function get_label() {
        return 'LibreTranslate';
    }

    public function is_configured() {
        $url = get_option( 'wppt_libretranslate_url', '' );
        return ! empty( trim( $url ) );
    }

    public function translate( $text, $source, $target ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'libretranslate_not_configured', __( 'URL LibreTranslate non configurée.', 'wp-polylang-translator' ) );
        }

        // LibreTranslate uses 'auto' for source auto-detection.
        $src = ! empty( trim( $source ) ) ? $this->map_lang( $source ) : 'auto';

        $payload = array(
            'q'      => $text,
            'source' => $src,
            'target' => $this->map_lang( $target ),
            'format' => 'html',
        );

        if ( ! empty( $this->api_key ) ) {
            $payload['api_key'] = $this->api_key;
        }

        $response = wp_remote_post(
            $this->endpoint,
            array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( $payload ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( 200 !== $code ) {
            $msg = isset( $data['error'] ) ? $data['error'] : $raw;
            return new WP_Error( 'libretranslate_error', sprintf( __( 'Erreur LibreTranslate (%d): %s', 'wp-polylang-translator' ), $code, $msg ) );
        }

        if ( isset( $data['translatedText'] ) ) {
            return $data['translatedText'];
        }

        return new WP_Error( 'libretranslate_unexpected', __( 'Réponse LibreTranslate inattendue.', 'wp-polylang-translator' ) );
    }
}
