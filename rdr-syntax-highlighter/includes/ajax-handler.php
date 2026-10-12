<?php
/**
 * Gestionnaire AJAX — Coloration syntaxique
 * 0 jQuery — endpoint natif WordPress admin-ajax.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_ajax_rdr_sh_highlight',        'rdr_sh_ajax_highlight' );
add_action( 'wp_ajax_nopriv_rdr_sh_highlight', 'rdr_sh_ajax_highlight' );

function rdr_sh_ajax_highlight() {
    if ( ! check_ajax_referer( 'rdr_sh_nonce', 'nonce', false ) ) {
        wp_send_json_error( array( 'message' => 'Nonce invalide' ), 403 );
    }

    $code         = isset( $_POST['code'] )         ? wp_unslash( $_POST['code'] )                     : '';
    $language     = isset( $_POST['language'] )     ? sanitize_key( $_POST['language'] )                : '';
    $line_numbers = isset( $_POST['line_numbers'] ) ? filter_var( $_POST['line_numbers'], FILTER_VALIDATE_BOOLEAN ) : true;
    $filename     = isset( $_POST['filename'] )     ? sanitize_file_name( wp_unslash( $_POST['filename'] ) ) : '';
    $block_id     = isset( $_POST['block_id'] )     ? sanitize_key( $_POST['block_id'] )                : '';

    if ( empty( $code ) ) {
        wp_send_json_error( array( 'message' => 'Code vide' ), 400 );
    }

    if ( strlen( $code ) > 200000 ) {
        wp_send_json_error( array( 'message' => 'Code trop long (max 200 Ko)' ), 413 );
    }

    $options      = get_option( 'rdr_sh_options', array() );
    $line_numbers = isset( $options['line_numbers'] ) ? (bool) $options['line_numbers'] : $line_numbers;

    // Clé de cache inclut le filename
    $cache_key = RDR_SH_Cache::make_key( $code, $language, $line_numbers, $filename );

    $cached = RDR_SH_Cache::get( $cache_key );
    if ( $cached !== false ) {
        wp_send_json_success( array( 'html' => $cached, 'cached' => true, 'block_id' => $block_id ) );
    }

    $html = RDR_SH_Highlighter::highlight( $code, $language, $line_numbers, $filename );

    RDR_SH_Cache::set( $cache_key, $html );

    wp_send_json_success( array( 'html' => $html, 'cached' => false, 'block_id' => $block_id ) );
}

add_action( 'wp_ajax_rdr_sh_flush_cache', 'rdr_sh_ajax_flush_cache' );

function rdr_sh_ajax_flush_cache() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => 'Permission refusée' ), 403 );
    }
    check_ajax_referer( 'rdr_sh_admin_nonce', 'nonce' );
    RDR_SH_Cache::flush_all();
    wp_send_json_success( array( 'message' => 'Cache vidé', 'stats' => RDR_SH_Cache::stats() ) );
}
