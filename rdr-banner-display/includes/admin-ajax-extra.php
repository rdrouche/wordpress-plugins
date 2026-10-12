<?php
/**
 * Additional admin AJAX hooks that extend the main admin class.
 * Hooked via plugins_loaded after the admin class is instantiated.
 *
 * We use a standalone function to keep the admin class clean and testable.
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_ajax_rdr_bd_toggle_role_cap', 'rdr_bd_ajax_toggle_role_cap' );

function rdr_bd_ajax_toggle_role_cap(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [], 403 );
    }

    check_ajax_referer( 'rdr_bd_admin', 'nonce' );

    $role_name = sanitize_key( $_POST['role']  ?? '' );
    $grant     = (bool) absint( $_POST['grant'] ?? 0 );

    if ( ! $role_name ) {
        wp_send_json_error( [], 400 );
    }

    $role = get_role( $role_name );
    if ( ! $role ) {
        wp_send_json_error( [ 'message' => 'Role not found.' ], 404 );
    }

    if ( $grant ) {
        $role->add_cap( RDR_BD_Capabilities::CAP_ADVERTISER );
    } else {
        $role->remove_cap( RDR_BD_Capabilities::CAP_ADVERTISER );
    }

    wp_send_json_success();
}
