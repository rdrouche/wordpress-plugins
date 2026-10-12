<?php
/**
 * Saves banner items via a dedicated AJAX endpoint.
 *
 * Transport: the JS sends a multipart/form-data request where the items
 * are in a field called "items_json" as a plain JSON string, and the
 * nonce/post_id are separate fields.  multipart avoids ALL the URL-encoding
 * issues that broke previous URLSearchParams approaches (& = " in values).
 *
 * PHP reads the items_json field from $_POST — because multipart bodies
 * are parsed by PHP itself (not WordPress), and WordPress only calls
 * addslashes on $_POST after wp-settings.php loads, we strip slashes
 * once with wp_unslash() and json_decode directly.
 *
 * @package RDR_Banner_Display
 */
defined( 'ABSPATH' ) || exit;

class RDR_BD_Meta_Save {

    public function init(): void {
        add_action( 'wp_ajax_rdr_bd_save_items', [ $this, 'ajax_save_items' ] );
    }

    public function ajax_save_items(): void {

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
            return;
        }

        // Nonce is a regular POST field (safe — it's alphanumeric)
        if ( ! check_ajax_referer( 'rdr_bd_save_items', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 403 );
            return;
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || get_post_type( $post_id ) !== RDR_BD_Post_Type::CPT ) {
            wp_send_json_error( [ 'message' => 'Invalid post' ], 400 );
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
            return;
        }

        // ── Read items_json ───────────────────────────────────────────────────
        // Sent as a FormData field → PHP receives it in $_POST['items_json'].
        // WordPress slashes $_POST, so we MUST call wp_unslash first.
        // Then json_decode — no base64, no URL-encoding, no parse_str.
        $raw     = isset( $_POST['items_json'] ) ? wp_unslash( $_POST['items_json'] ) : '';
        $decoded = json_decode( $raw, true );

        if ( ! is_array( $decoded ) ) {
            wp_send_json_error( [
                'message'      => 'JSON decode failed',
                'json_error'   => json_last_error_msg(),
                'raw_length'   => strlen( $raw ),
                'raw_preview'  => substr( $raw, 0, 300 ),
            ], 400 );
            return;
        }

        // ── Sanitise each item ────────────────────────────────────────────────
        $items         = [];
        $allowed_types = [ 'html', 'adsense', 'shortcode' ];

        foreach ( $decoded as $item ) {
            if ( ! is_array( $item ) ) continue;

            $type    = sanitize_key( $item['type'] ?? '' );
            $content = $item['content'] ?? '';

            if ( ! in_array( $type, $allowed_types, true ) ) continue;
            if ( trim( $content ) === '' ) continue;

            // shortcode: strip HTML tags, keep shortcode text
            if ( $type === 'shortcode' ) {
                $content = wp_strip_all_tags( $content );
            }
            // html / adsense: verbatim (admin-gated)

            $items[] = [
                'type'             => $type,
                'content'          => $content,
                'campaign_id'      => absint( $item['campaign_id']      ?? 0 ), // 0 = standalone
                'impression_cap'   => absint( $item['impression_cap']   ?? 0 ),
                'click_cap'        => absint( $item['click_cap']        ?? 0 ),
                'for_no_ads'       => (bool) ( $item['for_no_ads']       ?? false ),
                'force_on_adblock' => (bool) ( $item['force_on_adblock'] ?? false ),
                'adblock_only'     => (bool) ( $item['adblock_only']     ?? false ),
            ];
        }

        // ── Store directly via wpdb to bypass ALL WP meta filters ─────────────
        // update_post_meta → sanitize_meta → registered sanitize_callback.
        // Even with a passthrough callback, other plugins may hook
        // sanitize_post_meta__rdr_bd_items and corrupt the value.
        // Using $wpdb->update directly skips all of that.
        global $wpdb;

        $json_value = wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        // Check if meta row exists
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key = '_rdr_bd_items'
             LIMIT 1",
            $post_id
        ) );

        if ( $exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $wpdb->postmeta,
                [ 'meta_value' => $json_value ],
                [ 'post_id' => $post_id, 'meta_key' => '_rdr_bd_items' ],
                [ '%s' ],
                [ '%d', '%s' ]
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $wpdb->postmeta,
                [ 'post_id' => $post_id, 'meta_key' => '_rdr_bd_items', 'meta_value' => $json_value ],
                [ '%d', '%s', '%s' ]
            );
        }

        // Clear WP object cache for this post's meta
        wp_cache_delete( $post_id, 'post_meta' );

        // Verify by reading back directly from DB (bypass cache / get_post_meta)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $stored_raw = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE post_id = %d AND meta_key = '_rdr_bd_items'
             LIMIT 1",
            $post_id
        ) );

        wp_send_json_success( [
            'saved'       => count( $items ),
            'stored_raw'  => $stored_raw,        // what's literally in the DB
            'json_valid'  => is_array( json_decode( $stored_raw, true ) ),
        ] );
    }
}
