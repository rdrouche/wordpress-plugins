<?php
/**
 * Custom capabilities for the plugin.
 *
 * Capabilities added:
 *   - rdr_bd_advertiser : user can create/manage their own campaigns (front-end).
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

class RDR_BD_Capabilities {

    const CAP_ADVERTISER = 'rdr_bd_advertiser';

    public function init(): void {
        // Front-end campaign submission (for advertisers)
        add_action( 'wp_ajax_rdr_bd_submit_campaign', [ $this, 'ajax_submit_own_campaign' ] );
    }

    /**
     * Add the advertiser capability to a role (e.g. called from settings).
     */
    public static function grant_advertiser( string $role_name = 'subscriber' ): void {
        $role = get_role( $role_name );
        if ( $role ) {
            $role->add_cap( self::CAP_ADVERTISER );
        }
    }

    /**
     * Remove the advertiser capability from a role.
     */
    public static function revoke_advertiser( string $role_name = 'subscriber' ): void {
        $role = get_role( $role_name );
        if ( $role ) {
            $role->remove_cap( self::CAP_ADVERTISER );
        }
    }

    /**
     * Front-end AJAX: authenticated advertiser submits a campaign request.
     * The campaign is created with status = 'pending' for admin review.
     */
    public function ajax_submit_own_campaign(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'Authentication required.' ], 401 );
        }

        if ( ! current_user_can( self::CAP_ADVERTISER ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        check_ajax_referer( 'rdr_bd_load', 'nonce' );

        $user_id = get_current_user_id();

        $title      = sanitize_text_field( $_POST['title']      ?? '' );
        $start_date = sanitize_text_field( $_POST['start_date'] ?? '' );
        $end_date   = sanitize_text_field( $_POST['end_date']   ?? '' );
        $budget     = ! empty( $_POST['budget'] ) ? (float) $_POST['budget'] : null;

        if ( ! $title ) {
            wp_send_json_error( [ 'message' => 'Campaign title is required.' ], 400 );
        }

        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $wpdb->prefix . 'rdr_bd_campaigns',
            [
                'title'         => $title,
                'status'        => 'pending',
                'owner_user_id' => $user_id,
                'start_date'    => $start_date ?: null,
                'end_date'      => $end_date   ?: null,
                'budget'        => $budget,
            ],
            [ '%s', '%s', '%d', '%s', '%s', '%f' ]
        );

        $campaign_id = $wpdb->insert_id;

        // Notify admin
        $admin_email = get_option( 'admin_email' );
        wp_mail(
            $admin_email,
            sprintf( '[%s] New campaign request: %s', get_bloginfo( 'name' ), $title ),
            sprintf(
                "A new campaign has been submitted by user #%d.\n\nTitle: %s\nBudget: %s\n\nReview it here: %s",
                $user_id,
                $title,
                $budget ? number_format( $budget, 2 ) . ' €' : 'N/A',
                admin_url( 'admin.php?page=rdr-bd-campaigns' )
            )
        );

        wp_send_json_success( [ 'id' => $campaign_id ] );
    }
}
