<?php
/**
 * Admin: menu pages, banner meta boxes, settings, campaigns, stats.
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

class RDR_BD_Admin {

    public function init(): void {
        add_action( 'admin_menu',           [ $this, 'register_menus' ] );
        add_action( 'admin_enqueue_scripts',[ $this, 'enqueue_assets' ] );
        add_action( 'add_meta_boxes',       [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post',            [ $this, 'save_meta_box' ] );
        add_action( 'admin_init',           [ $this, 'register_settings' ] );

        // Admin AJAX for stats charts
        add_action( 'wp_ajax_rdr_bd_admin_stats',   [ $this, 'ajax_admin_stats' ] );

        // Reset banner stats (impressions + clicks only, not campaign rows)
        add_action( 'wp_ajax_rdr_bd_reset_stats', [ $this, 'ajax_reset_stats' ] );

        // Admin AJAX: preview banner (bypasses adblock/bot logic, renders raw)
        add_action( 'wp_ajax_rdr_bd_preview', [ $this, 'ajax_preview' ] );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public function register_menus(): void {
        add_menu_page(
            'RDR Banners',
            'RDR Banners',
            'manage_options',
            'rdr-bd',
            [ $this, 'page_dashboard' ],
            'dashicons-megaphone',
            25
        );

        add_submenu_page( 'rdr-bd', 'Dashboard',  'Dashboard',  'manage_options', 'rdr-bd',            [ $this, 'page_dashboard' ] );
        add_submenu_page( 'rdr-bd', 'Banners',    'Banners',    'manage_options', 'edit.php?post_type=rdr_banner' );
        add_submenu_page( 'rdr-bd', 'Add Banner', 'Add Banner', 'manage_options', 'post-new.php?post_type=rdr_banner' );
        add_submenu_page( 'rdr-bd', 'Campaigns',  'Campaigns',  'manage_options', 'rdr-bd-campaigns',  [ $this, 'page_campaigns' ] );
        add_submenu_page( 'rdr-bd', 'Statistics', 'Statistics', 'manage_options', 'rdr-bd-stats',      [ $this, 'page_stats' ] );
        add_submenu_page( 'rdr-bd', 'Settings',   'Settings',   'manage_options', 'rdr-bd-settings',   [ $this, 'page_settings' ] );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        $rdr_pages = [ 'toplevel_page_rdr-bd', 'rdr-banners_page_rdr-bd-campaigns',
                       'rdr-banners_page_rdr-bd-stats', 'rdr-banners_page_rdr-bd-settings' ];

        $is_banner_edit = false;
        if ( in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            // post-new.php: post_type is in $_GET
            // post.php: must check the actual post's type
            $post_type = $_GET['post_type'] ?? '';
            if ( ! $post_type && ! empty( $_GET['post'] ) ) {
                $post_type = get_post_type( (int) $_GET['post'] );
            }
            $is_banner_edit = ( $post_type === RDR_BD_Post_Type::CPT );
        }

        if ( ! in_array( $hook, $rdr_pages, true ) && ! $is_banner_edit ) {
            return;
        }

        wp_enqueue_style(
            'rdr-bd-admin',
            RDR_BD_URL . 'admin/css/rdr-bd-admin.css',
            [],
            RDR_BD_VERSION
        );

        wp_enqueue_script(
            'rdr-bd-admin',
            RDR_BD_URL . 'admin/js/rdr-bd-admin.js',
            [ 'wp-util' ],
            RDR_BD_VERSION,
            true
        );

        wp_localize_script( 'rdr-bd-admin', 'rdrBDAdmin', [
            'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
            'nonce'           => wp_create_nonce( 'rdr_bd_admin' ),
            'saveItemsNonce'  => wp_create_nonce( 'rdr_bd_save_items' ),
            'postId'          => (int) ( $_GET['post'] ?? $_POST['post_ID'] ?? 0 ),
        ] );
    }

    // ── Meta boxes ────────────────────────────────────────────────────────────

    public function add_meta_boxes(): void {
        add_meta_box(
            'rdr_bd_settings',
            'Banner Settings',
            [ $this, 'render_meta_box' ],
            RDR_BD_Post_Type::CPT,
            'normal',
            'high'
        );
    }

    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'rdr_bd_save_meta', 'rdr_bd_nonce' );

        $width  = (int) get_post_meta( $post->ID, '_rdr_bd_width',  true ) ?: 300;
        $height = (int) get_post_meta( $post->ID, '_rdr_bd_height', true ) ?: 250;
        $mode   = get_post_meta( $post->ID, '_rdr_bd_mode', true ) ?: 'single';
        $note   = get_post_meta( $post->ID, '_rdr_bd_note', true ) ?: '';

        // rdr_bd_get_items() uses get_post_meta() + wp_cache — no raw wpdb needed here
        $items  = rdr_bd_get_items( $post->ID );
        global $wpdb;

        // Campaigns list — passed to item rows for per-item campaign dropdown
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $campaigns = $wpdb->get_results(
            "SELECT id, title, status FROM {$wpdb->prefix}rdr_bd_campaigns ORDER BY title ASC",
            ARRAY_A
        ) ?: [];

        include RDR_BD_DIR . 'admin/views/meta-box-banner.php';
    }

    public function save_meta_box( int $post_id ): void {
        if ( ! isset( $_POST['rdr_bd_nonce'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['rdr_bd_nonce'], 'rdr_bd_save_meta' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( get_post_type( $post_id ) !== RDR_BD_Post_Type::CPT ) return;

        update_post_meta( $post_id, '_rdr_bd_width',  absint( $_POST['rdr_bd_width']  ?? 300 ) );
        update_post_meta( $post_id, '_rdr_bd_height', absint( $_POST['rdr_bd_height'] ?? 250 ) );
        update_post_meta( $post_id, '_rdr_bd_mode',   in_array( $_POST['rdr_bd_mode'] ?? '', [ 'single', 'random' ] ) ? $_POST['rdr_bd_mode'] : 'single' );
        // Note: campaign_id is now per-item (inside _rdr_bd_items JSON), not per-banner.

        // Note field (plain text memo for admin)
        update_post_meta( $post_id, '_rdr_bd_note', sanitize_textarea_field( wp_unslash( $_POST['rdr_bd_note'] ?? '' ) ) );

        // ── Items — read native POST fields, write directly via $wpdb ──────────
        // Each item is submitted as rdr_bd_item[N][field].
        // Content uses a base64-encoded hidden field to survive WP's addslashes.
        // No JS JSON serialisation — 100% native HTML form, always reliable.
        if ( ! isset( $_POST['rdr_bd_item'] ) && ! isset( $_POST['rdr_bd_nonce'] ) ) {
            return; // not our form submit
        }

        $raw_items     = isset( $_POST['rdr_bd_item'] ) ? (array) $_POST['rdr_bd_item'] : [];
        $allowed_types = [ 'html', 'adsense', 'shortcode' ];
        $items         = [];

        // Re-index to strip JS-added rows that may have string keys
        $raw_items = array_values( $raw_items );

        foreach ( $raw_items as $item ) {
            if ( ! is_array( $item ) ) continue;

            $type = sanitize_key( $item['type'] ?? '' );
            if ( ! in_array( $type, $allowed_types, true ) ) continue;

            // Content: decode from base64 (survives WP slashing intact)
            // JS syncs textarea → hidden base64 field before submit.
            // Fallback: read raw textarea value through wp_unslash.
            if ( ! empty( $item['content_b64'] ) ) {
                $content = base64_decode( sanitize_text_field( $item['content_b64'] ), true );
                if ( $content === false ) {
                    $content = wp_unslash( $item['content'] ?? '' );
                }
            } else {
                $content = wp_unslash( $item['content'] ?? '' );
            }

            if ( trim( $content ) === '' ) continue;
            if ( $type === 'shortcode' ) $content = wp_strip_all_tags( $content );

            $items[] = [
                'type'             => $type,
                'content'          => $content,
                'campaign_id'      => absint( $item['campaign_id']      ?? 0 ),
                'impression_cap'   => absint( $item['impression_cap']   ?? 0 ),
                'click_cap'        => absint( $item['click_cap']        ?? 0 ),
                'for_no_ads'       => ! empty( $item['for_no_ads'] ),
                'adblock_only'     => ! empty( $item['adblock_only'] ),
                'force_on_adblock' => ! empty( $item['force_on_adblock'] ),
            ];
        }

        $json_value = wp_json_encode( $items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_rdr_bd_items' LIMIT 1",
            $post_id
        ) );

        if ( $exists ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $wpdb->postmeta,
                [ 'meta_value' => $json_value ],
                [ 'post_id' => $post_id, 'meta_key' => '_rdr_bd_items' ],
                [ '%s' ], [ '%d', '%s' ]
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert(
                $wpdb->postmeta,
                [ 'post_id' => $post_id, 'meta_key' => '_rdr_bd_items', 'meta_value' => $json_value ],
                [ '%d', '%s', '%s' ]
            );
        }
        // Properly invalidate all WP caches for this post (postmeta, object cache, etc.)
        clean_post_cache( $post_id );
        // Also invalidate our own per-request cache
        wp_cache_delete( 'items_' . $post_id, 'rdr_bd' );
    }

    // ── Settings ──────────────────────────────────────────────────────────────

    public function register_settings(): void {
        $fields = [
            'rdr_bd_stats_enabled'        => 'sanitize_text_field',
            'rdr_bd_stats_retention_days' => 'absint',
            'rdr_bd_default_width'        => 'absint',
            'rdr_bd_default_height'       => 'absint',
            'rdr_bd_no_ads_key'           => 'sanitize_key',
            'rdr_bd_no_ads_mode'          => 'sanitize_key',
            'rdr_bd_no_ads_allowed_types' => [ $this, 'sanitize_types_array' ],
            'rdr_bd_skeleton_bg'          => 'sanitize_hex_color',
            'rdr_bd_skeleton_shine'       => 'sanitize_hex_color',
            'rdr_bd_adblock_delay'        => 'absint',
        ];

        foreach ( $fields as $key => $cb ) {
            register_setting( 'rdr_bd_settings_group', $key, [ 'sanitize_callback' => $cb ] );
        }
    }

    public function sanitize_types_array( $input ): array {
        if ( ! is_array( $input ) ) return [ 'html' ];
        $allowed = [ 'html', 'shortcode' ];
        return array_values( array_intersect( (array) $input, $allowed ) );
    }

    // ── Admin AJAX: stats ─────────────────────────────────────────────────────

    public function ajax_admin_stats(): void {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [], 403 );
        check_ajax_referer( 'rdr_bd_admin', 'nonce' );

        $stats     = new RDR_BD_Stats();
        $banner_id = absint( $_POST['banner_id'] ?? 0 );
        $days      = absint( $_POST['days'] ?? 30 );

        if ( $banner_id ) {
            wp_send_json_success( [
                'totals' => $stats->get_banner_totals( $banner_id, $days ),
                'daily'  => $stats->get_banner_daily(  $banner_id, $days ),
            ] );
        } else {
            wp_send_json_success( [
                'summary'     => $stats->get_global_summary( $days ),
                'top_banners' => $stats->get_top_banners( 10, $days ),
            ] );
        }
    }

    // ── Page renderers ────────────────────────────────────────────────────────

    /**
     * AJAX preview: returns raw rendered HTML for a banner (admin only).
     * Useful for testing without front-end JS / adblock detection.
     */
    public function ajax_reset_stats(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [], 403 ); return;
        }
        check_ajax_referer( 'rdr_bd_admin', 'nonce' );

        $banner_id = absint( $_POST['banner_id'] ?? 0 );
        if ( ! $banner_id ) { wp_send_json_error( [ 'message' => 'Missing banner ID' ], 400 ); return; }

        $stats   = new RDR_BD_Stats();
        $deleted = $stats->reset_banner_stats( $banner_id );
        wp_send_json_success( [ 'deleted' => $deleted ] );
    }

    public function ajax_preview(): void {
        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( [], 403 );
            return;
        }
        check_ajax_referer( 'rdr_bd_admin', 'nonce' );

        $banner_id = absint( $_POST['banner_id'] ?? 0 );
        if ( ! $banner_id ) {
            wp_send_json_error( [ 'message' => 'No banner ID' ], 400 );
            return;
        }

        // Read raw from DB for display, and decoded items for render
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $raw   = $wpdb->get_var( $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_rdr_bd_items' LIMIT 1",
            $banner_id
        ) ) ?? '';
        $items = rdr_bd_get_items( $banner_id );

        $rendered = [];
        foreach ( $items as $i => $item ) {
            $rendered[] = [
                'index'            => $i,
                'type'             => $item['type']             ?? '',
                'content'          => $item['content']          ?? '',
                'campaign_id'      => $item['campaign_id']      ?? 0,
                'for_no_ads'       => $item['for_no_ads']       ?? false,
                'adblock_only'     => $item['adblock_only']     ?? false,
                'force_on_adblock' => $item['force_on_adblock'] ?? false,
                'impression_cap'   => $item['impression_cap']   ?? 0,
                'click_cap'        => $item['click_cap']        ?? 0,
                'html'             => rdr_bd_render_item( $item ),
            ];
        }

        wp_send_json_success( [
            'raw_meta'  => $raw,
            'items'     => $rendered,
            'count'     => count( $items ),
        ] );
    }

    public function page_dashboard(): void  { include RDR_BD_DIR . 'admin/views/page-dashboard.php'; }
    public function page_campaigns(): void  { include RDR_BD_DIR . 'admin/views/page-campaigns.php'; }
    public function page_stats(): void      { include RDR_BD_DIR . 'admin/views/page-stats.php'; }
    public function page_settings(): void   { include RDR_BD_DIR . 'admin/views/page-settings.php'; }
}
