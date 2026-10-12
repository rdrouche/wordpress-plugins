<?php
/**
 * Outputs a tiny inline <style> block into <head> to feed
 * the skeleton CSS variables from plugin settings.
 * Hooked at wp_head priority 5 so the public stylesheet can use them.
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', 'rdr_bd_output_css_vars', 5 );

function rdr_bd_output_css_vars(): void {
    $bg    = sanitize_hex_color( get_option( 'rdr_bd_skeleton_bg',    '#1c2128' ) );
    $shine = sanitize_hex_color( get_option( 'rdr_bd_skeleton_shine', '#2ea8ff' ) );

    // Convert hex shine to rgba with low opacity for the shimmer
    list( $r, $g, $b ) = sscanf( ltrim( $shine, '#' ), '%02x%02x%02x' );
    $shine_rgba = "rgba({$r},{$g},{$b},0.15)";

    printf(
        '<style id="rdr-bd-vars">:root{--rdr-bd-skel-bg:%s;--rdr-bd-skel-shine:%s;}</style>' . "\n",
        esc_attr( $bg ),
        esc_attr( $shine_rgba )
    );
}
