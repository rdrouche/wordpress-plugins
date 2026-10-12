<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Google Translate provider.
 * Uses the Google Cloud Translation API v2 (Basic).
 * Note: LibreTranslate uses the same call structure, so both share this base approach.
 */
class WPPT_Google_Provider implements WPPT_Provider_Interface {

    protected $api_key;
    protected $endpoint = 'https://translation.googleapis.com/language/translate/v2';

    public function __construct() {
        $this->api_key = get_option( 'wppt_google_api_key', '' );
    }

    public function get_label() {
        return 'Google Translate';
    }

    public function is_configured() {
        return ! empty( trim( $this->api_key ) );
    }

    /**
     * Map locale codes (e.g. fr_FR) to BCP-47 codes.
     */
    protected function map_lang( $lang ) {
        $lang = strtolower( str_replace( '_', '-', $lang ) );
        // Google typically uses the base code only.
        $parts = explode( '-', $lang );
        return $parts[0];
    }

    public function translate( $text, $source, $target ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'google_not_configured', __( 'Clé API Google Translate non configurée.', 'wp-polylang-translator' ) );
        }

        $url = add_query_arg( array( 'key' => $this->api_key ), $this->endpoint );

        $src_mapped = ! empty( trim( $source ) ) ? $this->map_lang( $source ) : '';
        $payload = array(
            'q'      => $text,
            'target' => $this->map_lang( $target ),
            'format' => 'html',
        );
        // Omit source when empty — Google auto-detects better without an explicit empty value.
        if ( ! empty( $src_mapped ) ) {
            $payload['source'] = $src_mapped;
        }
        $body = wp_json_encode( $payload );

        $response = wp_remote_post(
            $url,
            array(
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => $body,
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
            $msg = isset( $data['error']['message'] ) ? $data['error']['message'] : $raw;
            return new WP_Error( 'google_error', sprintf( __( 'Erreur Google Translate (%d): %s', 'wp-polylang-translator' ), $code, $msg ) );
        }

        if ( isset( $data['data']['translations'][0]['translatedText'] ) ) {
            return html_entity_decode( $data['data']['translations'][0]['translatedText'], ENT_QUOTES, 'UTF-8' );
        }

        return new WP_Error( 'google_unexpected', __( 'Réponse Google Translate inattendue.', 'wp-polylang-translator' ) );
    }
}
