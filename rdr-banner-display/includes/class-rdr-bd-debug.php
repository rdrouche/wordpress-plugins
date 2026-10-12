<?php
/**
 * TEMPORARY DEBUG — remove before production.
 * Logs exactly what arrives in $_POST and what gets stored.
 * Enable by adding  define('RDR_BD_DEBUG', true);  to wp-config.php
 *
 * @package RDR_Banner_Display
 */
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'RDR_BD_DEBUG' ) || ! RDR_BD_DEBUG ) return;

add_action( 'save_post_rdr_banner', function( int $post_id ) {

    if ( ! isset( $_POST['rdr_bd_nonce'] ) ) return;

    $log_file = WP_CONTENT_DIR . '/rdr-bd-debug.log';
    $ts       = date( 'Y-m-d H:i:s' );

    $lines = [];
    $lines[] = "=== [$ts] save_post #$post_id ===";

    // 1. Raw hidden field value
    $raw = $_POST['rdr_bd_items_json'] ?? '(missing)';
    $lines[] = "POST rdr_bd_items_json length : " . strlen( $raw );
    $lines[] = "POST rdr_bd_items_json (first 200) : " . substr( $raw, 0, 200 );

    // 2. After wp_unslash
    $unslashed = wp_unslash( $raw );
    $lines[] = "After wp_unslash length : " . strlen( $unslashed );
    $lines[] = "After wp_unslash (first 200) : " . substr( $unslashed, 0, 200 );

    // 3. After preg_replace (base64 clean)
    $b64 = preg_replace( '/[^A-Za-z0-9+\/=]/', '', $unslashed );
    $lines[] = "After preg_replace length : " . strlen( $b64 );

    // 4. base64_decode
    $json_raw = $b64 ? base64_decode( $b64, true ) : false;
    $lines[] = "base64_decode result : " . ( $json_raw === false ? 'FALSE (invalid base64)' : substr( $json_raw, 0, 300 ) );

    // 5. json_decode
    $decoded = $json_raw ? json_decode( $json_raw, true ) : null;
    $lines[] = "json_decode result : " . ( $decoded === null ? 'NULL (json error: ' . json_last_error_msg() . ')' : print_r( $decoded, true ) );

    // 6. What's actually stored in DB after save
    add_action( 'updated_post_meta', function( $meta_id, $obj_id, $meta_key, $meta_value ) use ( $post_id, &$lines, $log_file ) {
        if ( $obj_id !== $post_id || $meta_key !== '_rdr_bd_items' ) return;
        $lines[] = "updated_post_meta _rdr_bd_items : " . substr( $meta_value, 0, 500 );
        file_put_contents( $log_file, implode( "\n", $lines ) . "\n\n", FILE_APPEND );
    }, 10, 4 );

    add_action( 'added_post_meta', function( $meta_id, $obj_id, $meta_key, $meta_value ) use ( $post_id, &$lines, $log_file ) {
        if ( $obj_id !== $post_id || $meta_key !== '_rdr_bd_items' ) return;
        $lines[] = "added_post_meta _rdr_bd_items : " . substr( $meta_value, 0, 500 );
        file_put_contents( $log_file, implode( "\n", $lines ) . "\n\n", FILE_APPEND );
    }, 10, 4 );

    // Fallback write after 1 second (in case hooks don't fire)
    register_shutdown_function( function() use ( &$lines, $log_file ) {
        if ( count( $lines ) > 6 ) return; // already written
        $lines[] = "(shutdown fallback — meta hooks did not fire)";
        file_put_contents( $log_file, implode( "\n", $lines ) . "\n\n", FILE_APPEND );
    } );

}, 1 ); // priority 1 = before our save_post hook at default priority 10
