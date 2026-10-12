<?php
defined( 'ABSPATH' ) || exit;

class SCF_Polylang {

    public static function init(): void {
        if ( ! function_exists( 'pll_register_string' ) ) return;

        // Register option strings for translation in Polylang Strings screen
        pll_register_string( 'scf_success_message', get_option( 'scf_success_message_default', '' ), 'Smart Contact Form' );

        // Make our CPT translatable
        add_filter( 'pll_get_post_types', [ __CLASS__, 'add_post_type' ] );
    }

    public static function add_post_type( array $types ): array {
        $types[ SCF_Post_Type::POST_TYPE ] = SCF_Post_Type::POST_TYPE;
        return $types;
    }

    /**
     * Resolve the correct form ID for the current language.
     *
     * If Polylang is active, try to get the translated version of the form.
     * Falls back to the original ID if no translation exists.
     */
    public static function resolve_form_id( int $form_id ): int {
        if ( ! function_exists( 'pll_get_post' ) ) return $form_id;

        $translated = pll_get_post( $form_id );
        return $translated ?: $form_id;
    }

    /**
     * Get current language code (or empty string if Polylang inactive).
     */
    public static function current_lang(): string {
        return function_exists( 'pll_current_language' ) ? (string) pll_current_language() : '';
    }
}
