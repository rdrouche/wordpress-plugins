<?php
/**
 * Campaign management: CRUD helpers and REST-like admin AJAX endpoints.
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

class RDR_BD_Campaign {

    public function init(): void {
        add_action( 'wp_ajax_rdr_bd_save_campaign',   [ $this, 'ajax_save' ] );
        add_action( 'wp_ajax_rdr_bd_delete_campaign', [ $this, 'ajax_delete' ] );
        add_action( 'wp_ajax_rdr_bd_get_campaigns',   [ $this, 'ajax_list' ] );
    }

    // ── AJAX: List ────────────────────────────────────────────────────────────

    public function ajax_list(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [], 403 );
        }
        check_ajax_referer( 'rdr_bd_admin', 'nonce' );

        wp_send_json_success( $this->get_all() );
    }

    // ── AJAX: Save (create / update) ──────────────────────────────────────────

    public function ajax_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [], 403 );
        }
        check_ajax_referer( 'rdr_bd_admin', 'nonce' );

        $id = absint( $_POST['id'] ?? 0 );

        $data = [
            'title'          => sanitize_text_field( $_POST['title']          ?? '' ),
            'status'         => sanitize_key( $_POST['status']                ?? 'pending' ),
            'owner_user_id'  => absint( $_POST['owner_user_id']               ?? 0 ) ?: null,
            'start_date'     => sanitize_text_field( $_POST['start_date']     ?? '' ) ?: null,
            'end_date'       => sanitize_text_field( $_POST['end_date']       ?? '' ) ?: null,
            'budget'         => ! empty( $_POST['budget'] ) ? (float) $_POST['budget'] : null,
            'impression_cap' => ! empty( $_POST['impression_cap'] ) ? absint( $_POST['impression_cap'] ) : null,
            'click_cap'      => ! empty( $_POST['click_cap'] )      ? absint( $_POST['click_cap'] )      : null,
        ];

        if ( ! in_array( $data['status'], [ 'pending', 'active', 'paused', 'ended' ], true ) ) {
            $data['status'] = 'pending';
        }

        global $wpdb;

        if ( $id ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $wpdb->prefix . 'rdr_bd_campaigns',
                $data,
                [ 'id' => $id ],
                [ '%s', '%s', '%d', '%s', '%s', '%f', '%d', '%d' ],
                [ '%d' ]
            );
            wp_send_json_success( [ 'id' => $id ] );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $wpdb->prefix . 'rdr_bd_campaigns',
                $data,
                [ '%s', '%s', '%d', '%s', '%s', '%f', '%d', '%d' ]
            );
            wp_send_json_success( [ 'id' => $wpdb->insert_id ] );
        }
    }

    // ── AJAX: Delete ──────────────────────────────────────────────────────────

    public function ajax_delete(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [], 403 );
        }
        check_ajax_referer( 'rdr_bd_admin', 'nonce' );

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( [], 400 );
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( $wpdb->prefix . 'rdr_bd_campaigns',       [ 'id' => $id ], [ '%d' ] );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( $wpdb->prefix . 'rdr_bd_campaign_banners', [ 'campaign_id' => $id ], [ '%d' ] );

        wp_send_json_success();
    }

    // ── Public helpers ────────────────────────────────────────────────────────

    /**
     * Return all campaigns with impression/click totals.
     * Uses the new rdr_bd_stats_daily + rdr_bd_clicks tables.
     */
    public function get_all(): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $campaigns = $wpdb->get_results(
            "SELECT c.*,
                    u.display_name AS owner_name,
                    COALESCE(d.impressions, 0) AS impressions,
                    COALESCE(k.clicks, 0)      AS clicks
             FROM {$wpdb->prefix}rdr_bd_campaigns c
             LEFT JOIN {$wpdb->users} u
                    ON u.ID = c.owner_user_id
             LEFT JOIN (
                 SELECT campaign_id, SUM(impressions) AS impressions
                 FROM {$wpdb->prefix}rdr_bd_stats_daily
                 GROUP BY campaign_id
             ) d ON d.campaign_id = c.id
             LEFT JOIN (
                 SELECT campaign_id, COUNT(*) AS clicks
                 FROM {$wpdb->prefix}rdr_bd_clicks
                 GROUP BY campaign_id
             ) k ON k.campaign_id = c.id
             GROUP BY c.id
             ORDER BY c.created_at DESC",
            ARRAY_A
        );

        return $campaigns ?: [];
    }

    /**
     * Get a single campaign by ID.
     */
    public function get( int $id ): ?array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}rdr_bd_campaigns WHERE id = %d",
                $id
            ),
            ARRAY_A
        );

        return $row ?: null;
    }
}
