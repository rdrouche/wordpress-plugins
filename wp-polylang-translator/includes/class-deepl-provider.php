<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPPT_DeepL_Provider implements WPPT_Provider_Interface {

    private $api_key;
    private $is_free;

    public function __construct() {
        $this->api_key = get_option( 'wppt_deepl_api_key', '' );
        $this->is_free = ( substr( trim( $this->api_key ), -3 ) === ':fx' );
    }

    public function get_label() {
        return 'DeepL';
    }

    public function is_configured() {
        return ! empty( trim( $this->api_key ) );
    }

    private function get_endpoint() {
        return $this->is_free
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';
    }

    /**
     * Map ISO 639-1 codes to DeepL language codes.
     */
    private function map_lang( $lang ) {
        $lang = strtoupper( $lang );
        $map  = array(
            'EN' => 'EN',
            'FR' => 'FR',
            'DE' => 'DE',
            'ES' => 'ES',
            'IT' => 'IT',
            'NL' => 'NL',
            'PL' => 'PL',
            'PT' => 'PT',
            'RU' => 'RU',
            'JA' => 'JA',
            'ZH' => 'ZH',
            'CS' => 'CS',
            'DA' => 'DA',
            'FI' => 'FI',
            'EL' => 'EL',
            'HU' => 'HU',
            'RO' => 'RO',
            'SK' => 'SK',
            'SL' => 'SL',
            'SV' => 'SV',
            'TR' => 'TR',
            'UK' => 'UK',
            'BG' => 'BG',
            'LT' => 'LT',
            'LV' => 'LV',
            'ET' => 'ET',
        );
        return isset( $map[ $lang ] ) ? $map[ $lang ] : $lang;
    }

    /**
     * Translate HTML text with DeepL, preserving tags.
     */
    public function translate( $text, $source, $target ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'deepl_not_configured', __( 'DeepL API key non configurée.', 'wp-polylang-translator' ) );
        }

        $source_lang = ! empty( trim( $source ) ) ? $this->map_lang( $source ) : '';
        $target_lang = $this->map_lang( $target );

        $body = array(
            'text'         => $text,
            'target_lang'  => $target_lang,
            'tag_handling' => 'html',
        );
        // Omit source_lang when empty to let DeepL auto-detect (works better on short text).
        if ( ! empty( $source_lang ) ) {
            $body['source_lang'] = $source_lang;
        }

        $response = wp_remote_post(
            $this->get_endpoint(),
            array(
                'headers' => array(
                    'Authorization' => 'DeepL-Auth-Key ' . $this->api_key,
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ),
                'body'    => http_build_query( $body ),
                'timeout' => 30,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true );

        if ( 456 === $code ) {
            return new WP_Error( 'deepl_quota_exceeded', __( 'Quota DeepL dépassé.', 'wp-polylang-translator' ) );
        }

        if ( 200 !== $code ) {
            $msg = isset( $data['message'] ) ? $data['message'] : $raw;
            return new WP_Error( 'deepl_error', sprintf( __( 'Erreur DeepL (%d): %s', 'wp-polylang-translator' ), $code, $msg ) );
        }

        if ( isset( $data['translations'][0]['text'] ) ) {
            return $data['translations'][0]['text'];
        }

        return new WP_Error( 'deepl_unexpected', __( 'Réponse DeepL inattendue.', 'wp-polylang-translator' ) );
    }
}
