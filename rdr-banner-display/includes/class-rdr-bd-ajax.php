<?php
/**
 * AJAX: load banner + record stats.
 *
 * Performance optimisations:
 * - Static request-level cache for campaign_is_active() results
 * - Static request-level cache for item_within_caps() results
 * - get_post_meta() for banner meta (WP object cache aware)
 * - Composite DB indexes on stats tables (see migrations)
 * - Single INSERT per impression (ON DUPLICATE KEY UPDATE)
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

class RDR_BD_Ajax {

    /** @var array<int, bool> Request-level campaign status cache */
    private static array $campaign_cache = [];

    /** @var array<string, bool> Request-level item cap cache */
    private static array $cap_cache = [];

    public function init(): void {
        add_action( 'wp_ajax_rdr_bd_load',        [ $this, 'load_banner' ] );
        add_action( 'wp_ajax_nopriv_rdr_bd_load', [ $this, 'load_banner' ] );
        add_action( 'wp_ajax_rdr_bd_stat',        [ $this, 'record_stat' ] );
        add_action( 'wp_ajax_nopriv_rdr_bd_stat', [ $this, 'record_stat' ] );
    }

    // ── Load banner ───────────────────────────────────────────────────────────

    public function load_banner(): void {
        // Prevent any cache (LiteSpeed, Varnish, Cloudflare) from caching AJAX responses
        nocache_headers();

        if ( ! check_ajax_referer( 'rdr_bd_load', 'nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce.' ], 403 ); return;
        }

        $banner_id  = absint( $_POST['banner_id'] ?? 0 );
        $is_adblock = filter_var( $_POST['adblock'] ?? false, FILTER_VALIDATE_BOOLEAN );

        if ( ! $banner_id ) {
            wp_send_json_error( [ 'message' => 'Missing banner ID.' ], 400 ); return;
        }

        // get_post() uses WP object cache — zero SQL on cached sites
        $banner = get_post( $banner_id );
        if ( ! $banner || $banner->post_type !== RDR_BD_Post_Type::CPT || $banner->post_status !== 'publish' ) {
            wp_send_json_error( [ 'message' => 'Banner not found.' ], 404 ); return;
        }

        // get_post_meta() uses WP postmeta cache — subsequent calls are free
        $width  = (int) get_post_meta( $banner_id, '_rdr_bd_width',  true ) ?: 300;
        $height = (int) get_post_meta( $banner_id, '_rdr_bd_height', true ) ?: 250;

        if ( rdr_bd_is_bot() ) {
            wp_send_json_success( [ 'html' => '', 'is_bot' => true, 'width' => $width, 'height' => $height ] );
            return;
        }

        $no_ads        = rdr_bd_user_has_no_ads();
        $allowed_types = rdr_bd_no_ads_allowed_types();
        // rdr_bd_get_items() uses WP object cache internally
        $items         = rdr_bd_get_items( $banner_id );
        $mode          = get_post_meta( $banner_id, '_rdr_bd_mode', true ) ?: 'single';
        $eligible      = $this->filter_items( $items, $is_adblock, $no_ads, $allowed_types, $banner_id );

        $html        = '';
        $item_hash   = '';
        $campaign_id = 0;

        if ( ! empty( $eligible ) ) {
            $item        = ( $mode === 'random' ) ? $eligible[ array_rand( $eligible ) ] : reset( $eligible );
            $item_hash   = substr( md5( ( $item['type'] ?? '' ) . '|' . ( $item['content'] ?? '' ) ), 0, 16 );
            $campaign_id = (int) ( $item['campaign_id'] ?? 0 );
            $html        = rdr_bd_render_item( $item );
        }

        // Build debug info when no item was selected — always returned to help diagnose
        $debug = null;
        if ( empty( $html ) ) {
            $debug_items = [];
            foreach ( $items as $k => $item ) {
                $cid    = (int) ( $item['campaign_id'] ?? 0 );
                $active = ! $cid || $this->campaign_is_active( $cid );
                $debug_items[] = [
                    'index'            => $k,
                    'type'             => $item['type']             ?? '',
                    'for_no_ads'       => (bool) ( $item['for_no_ads']       ?? false ),
                    'adblock_only'     => (bool) ( $item['adblock_only']     ?? false ),
                    'force_on_adblock' => (bool) ( $item['force_on_adblock'] ?? false ),
                    'campaign_id'      => $cid,
                    'campaign_active'  => $active,
                ];
            }
            $debug = [
                'is_adblock'     => $is_adblock,
                'no_ads'         => $no_ads,
                'eligible_count' => count( $eligible ),
                'total_items'    => count( $items ),
                'items'          => $debug_items,
            ];
        }

        wp_send_json_success( [
            'html'        => $html,
            'item_hash'   => $item_hash,
            'campaign_id' => $campaign_id ?: null,
            'no_ads'      => $no_ads,
            'width'       => $width,
            'height'      => $height,
            'debug'       => $debug,
        ] );
    }

    // ── Record stat ───────────────────────────────────────────────────────────

    public function record_stat(): void {
        if ( ! check_ajax_referer( 'rdr_bd_load', 'nonce', false ) ) {
            wp_send_json_error( [], 403 ); return;
        }
        if ( get_option( 'rdr_bd_stats_enabled', '1' ) !== '1' ) {
            wp_send_json_success(); return;
        }

        $banner_id   = absint( $_POST['banner_id']   ?? 0 );
        $event_type  = sanitize_key( $_POST['event']        ?? 'impression' );
        $campaign_id = absint( $_POST['campaign_id']  ?? 0 );
        $is_adblock  = filter_var( $_POST['adblock']  ?? false, FILTER_VALIDATE_BOOLEAN );
        $item_hash   = substr( sanitize_text_field( $_POST['item_hash'] ?? '' ), 0, 16 );

        if ( ! $banner_id || ! in_array( $event_type, [ 'impression', 'click' ], true ) ) {
            wp_send_json_error( [], 400 ); return;
        }

        $queue = new RDR_BD_Queue();

        if ( $event_type === 'impression' ) {
            // Push to Redis queue → cron aggregates and writes to DB every 5 min.
            // Falls back to direct DB INSERT if Redis is unavailable.
            $queue->push_impression( $banner_id, $campaign_id, $item_hash, $is_adblock );
        } else {
            // Clicks: push to Redis queue too (flushed as bulk INSERT by cron).
            // Falls back to direct DB INSERT if Redis is unavailable.
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
            $queue->push_click( $banner_id, $campaign_id, $item_hash, $is_adblock, $ip, $ua );
        }

        wp_send_json_success();
    }

    // ── Filter items ──────────────────────────────────────────────────────────

    private function filter_items( array $items, bool $is_adblock, bool $no_ads, array $allowed_types, int $banner_id = 0 ): array {
        return array_values( array_filter( $items, function ( $item ) use ( $is_adblock, $no_ads, $allowed_types, $banner_id ) {
            $type             = $item['type']             ?? '';
            $for_no_ads       = (bool) ( $item['for_no_ads']       ?? false );
            $force_on_adblock = (bool) ( $item['force_on_adblock'] ?? false );
            $adblock_only     = (bool) ( $item['adblock_only']     ?? false );

            // Campaign gate — cached per request
            $item_campaign_id = (int) ( $item['campaign_id'] ?? 0 );
            if ( $item_campaign_id && ! $this->campaign_is_active( $item_campaign_id ) ) return false;

            // ── No-ads user ───────────────────────────────────────────────────
            if ( $no_ads ) {
                return $for_no_ads && in_array( $type, $allowed_types, true );
            }

            // ── Adblock user ──────────────────────────────────────────────────
            if ( $is_adblock ) {
                // Items reserved for no-ads users only → skip
                if ( $for_no_ads ) return false;
                // AdSense items: only serve if force_on_adblock is set
                if ( $type === 'adsense' && ! $force_on_adblock ) return false;
                // Non-AdSense items: always eligible for adblock users
                // (adblock_only items are also eligible here — that's the point)
                return true;
            }

            // ── Regular user (no adblock) ─────────────────────────────────────
            // Skip items reserved for specific audiences
            if ( $for_no_ads   ) return false; // no-ads users only
            if ( $adblock_only ) return false; // adblock users only

            // Item caps
            if ( $banner_id > 0 && ! $this->item_within_caps( $item, $banner_id ) ) return false;

            return true;
        } ) );
    }

    // ── Campaign active — request-level cache ─────────────────────────────────

    private function campaign_is_active( int $campaign_id ): bool {
        // Return cached result if already checked this request
        if ( isset( self::$campaign_cache[ $campaign_id ] ) ) {
            return self::$campaign_cache[ $campaign_id ];
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $campaign = $wpdb->get_row( $wpdb->prepare(
            "SELECT status, start_date, end_date, impression_cap, click_cap
             FROM {$wpdb->prefix}rdr_bd_campaigns WHERE id = %d",
            $campaign_id
        ) );

        // A campaign must be 'active' to serve items.
        // 'pending', 'paused', 'ended' all block the item.
        // If your campaign isn't serving, check its status in RDR Banners → Campaigns.
        if ( ! $campaign || $campaign->status !== 'active' ) {
            return self::$campaign_cache[ $campaign_id ] = false;
        }

        $now = current_time( 'mysql', true );
        if ( $campaign->start_date && $campaign->start_date > $now ) {
            return self::$campaign_cache[ $campaign_id ] = false;
        }
        if ( $campaign->end_date && $campaign->end_date < $now ) {
            // Auto-update status to 'ended' so the UI reflects reality
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update(
                $wpdb->prefix . 'rdr_bd_campaigns',
                [ 'status' => 'ended' ],
                [ 'id' => $campaign_id ],
                [ '%s' ], [ '%d' ]
            );
            return self::$campaign_cache[ $campaign_id ] = false;
        }

        // Impression cap — single aggregate query, hits idx_campaign_id
        if ( $campaign->impression_cap ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $imp = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT SUM(impressions) FROM {$wpdb->prefix}rdr_bd_stats_daily
                 WHERE campaign_id = %d",
                $campaign_id
            ) );
            if ( $imp >= (int) $campaign->impression_cap ) {
                return self::$campaign_cache[ $campaign_id ] = false;
            }
        }

        // Click cap — hits idx_campaign_id on rdr_bd_clicks
        if ( $campaign->click_cap ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $clk = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rdr_bd_clicks WHERE campaign_id = %d",
                $campaign_id
            ) );
            if ( $clk >= (int) $campaign->click_cap ) {
                return self::$campaign_cache[ $campaign_id ] = false;
            }
        }

        return self::$campaign_cache[ $campaign_id ] = true;
    }

    // ── Item caps — request-level cache ───────────────────────────────────────

    private function item_within_caps( array $item, int $banner_id ): bool {
        $imp_cap = (int) ( $item['impression_cap'] ?? 0 );
        $clk_cap = (int) ( $item['click_cap']      ?? 0 );

        // Fast-exit: no cap set — no SQL needed
        if ( ! $imp_cap && ! $clk_cap ) return true;

        $item_hash = substr( md5( ( $item['type'] ?? '' ) . '|' . ( $item['content'] ?? '' ) ), 0, 16 );
        $cache_key = $banner_id . ':' . $item_hash;

        if ( isset( self::$cap_cache[ $cache_key ] ) ) {
            return self::$cap_cache[ $cache_key ];
        }

        global $wpdb;

        if ( $imp_cap ) {
            // Composite index (banner_id, item_hash) ensures index-only scan
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $imp = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT SUM(impressions) FROM {$wpdb->prefix}rdr_bd_stats_daily
                 WHERE banner_id = %d AND item_hash = %s",
                $banner_id, $item_hash
            ) );
            if ( $imp >= $imp_cap ) {
                return self::$cap_cache[ $cache_key ] = false;
            }
        }

        if ( $clk_cap ) {
            // Composite index (banner_id, item_hash) on rdr_bd_clicks
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $clk = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}rdr_bd_clicks
                 WHERE banner_id = %d AND item_hash = %s",
                $banner_id, $item_hash
            ) );
            if ( $clk >= $clk_cap ) {
                return self::$cap_cache[ $cache_key ] = false;
            }
        }

        return self::$cap_cache[ $cache_key ] = true;
    }
}
