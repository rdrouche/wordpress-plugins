<?php
/**
 * Stats queries — new two-table schema (stats_daily + clicks).
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

class RDR_BD_Stats {

    public function init(): void {
        add_action( 'rdr_bd_cleanup_stats', [ $this, 'cleanup_old_stats' ] );
        if ( ! wp_next_scheduled( 'rdr_bd_cleanup_stats' ) ) {
            wp_schedule_event( time(), 'daily', 'rdr_bd_cleanup_stats' );
        }
    }

    public function cleanup_old_stats(): void {
        $days = (int) get_option( 'rdr_bd_stats_retention_days', 90 );
        if ( $days <= 0 ) return;
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rdr_bd_stats_daily WHERE stat_date < CURDATE() - INTERVAL %d DAY",
            $days
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rdr_bd_clicks WHERE clicked_at < UTC_TIMESTAMP() - INTERVAL %d DAY",
            $days
        ) );
    }

    // ── Banner totals ─────────────────────────────────────────────────────────

    public function get_banner_totals( int $banner_id, int $days = 30 ): array {
        global $wpdb;

        $where_date = $days > 0
            ? $wpdb->prepare( 'AND stat_date >= CURDATE() - INTERVAL %d DAY', $days )
            : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $imp = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(impressions) FROM {$wpdb->prefix}rdr_bd_stats_daily
             WHERE banner_id = %d $where_date",
            $banner_id
        ) );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $imp_adblock = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(impressions) FROM {$wpdb->prefix}rdr_bd_stats_daily
             WHERE banner_id = %d AND adblock = 1 $where_date",
            $banner_id
        ) );

        $where_click = $days > 0
            ? $wpdb->prepare( 'AND clicked_at >= UTC_TIMESTAMP() - INTERVAL %d DAY', $days )
            : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $clk = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}rdr_bd_clicks
             WHERE banner_id = %d $where_click",
            $banner_id
        ) );

        return [
            'impressions'         => $imp,
            'clicks'              => $clk,
            'adblock_impressions' => $imp_adblock,
            'ctr'                 => $imp > 0 ? round( $clk / $imp * 100, 2 ) : 0.0,
        ];
    }

    // ── Banner daily ──────────────────────────────────────────────────────────

    public function get_banner_daily( int $banner_id, int $days = 30 ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $imp_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT stat_date AS date, SUM(impressions) AS total
             FROM {$wpdb->prefix}rdr_bd_stats_daily
             WHERE banner_id = %d AND stat_date >= CURDATE() - INTERVAL %d DAY
             GROUP BY stat_date ORDER BY stat_date ASC",
            $banner_id, $days
        ), ARRAY_A );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $clk_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(clicked_at) AS date, COUNT(*) AS total
             FROM {$wpdb->prefix}rdr_bd_clicks
             WHERE banner_id = %d AND clicked_at >= UTC_TIMESTAMP() - INTERVAL %d DAY
             GROUP BY DATE(clicked_at) ORDER BY date ASC",
            $banner_id, $days
        ), ARRAY_A );

        $by_date = [];
        foreach ( $imp_rows as $r ) {
            $by_date[ $r['date'] ] = [ 'date' => $r['date'], 'impressions' => (int) $r['total'], 'clicks' => 0 ];
        }
        foreach ( $clk_rows as $r ) {
            if ( ! isset( $by_date[ $r['date'] ] ) ) {
                $by_date[ $r['date'] ] = [ 'date' => $r['date'], 'impressions' => 0, 'clicks' => 0 ];
            }
            $by_date[ $r['date'] ]['clicks'] = (int) $r['total'];
        }

        ksort( $by_date );
        return array_values( $by_date );
    }

    // ── Global summary ────────────────────────────────────────────────────────

    public function get_global_summary( int $days = 30 ): array {
        global $wpdb;

        $where_d = $days > 0 ? $wpdb->prepare( 'WHERE stat_date >= CURDATE() - INTERVAL %d DAY', $days ) : 'WHERE 1=1';
        $where_c = $days > 0 ? $wpdb->prepare( 'WHERE clicked_at >= UTC_TIMESTAMP() - INTERVAL %d DAY', $days ) : 'WHERE 1=1';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $imp = (int) $wpdb->get_var( "SELECT SUM(impressions) FROM {$wpdb->prefix}rdr_bd_stats_daily $where_d" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $clk = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rdr_bd_clicks $where_c" );

        return [
            'impressions' => $imp,
            'clicks'      => $clk,
            'ctr'         => $imp > 0 ? round( $clk / $imp * 100, 2 ) : 0.0,
        ];
    }

    // ── Top banners ───────────────────────────────────────────────────────────

    public function get_top_banners( int $limit = 10, int $days = 30 ): array {
        global $wpdb;

        // stat_date / clicked_at are real column names inside subqueries — no alias prefix
        $imp_date_sql = $days > 0
            ? $wpdb->prepare( 'AND stat_date >= CURDATE() - INTERVAL %d DAY', $days )
            : '';
        $clk_date_sql = $days > 0
            ? $wpdb->prepare( 'AND clicked_at >= UTC_TIMESTAMP() - INTERVAL %d DAY', $days )
            : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT d.banner_id,
                    p.post_title,
                    d.impressions,
                    COALESCE(c.clicks, 0) AS clicks
             FROM (
                 SELECT banner_id, SUM(impressions) AS impressions
                 FROM {$wpdb->prefix}rdr_bd_stats_daily
                 WHERE 1=1 $imp_date_sql
                 GROUP BY banner_id
             ) d
             LEFT JOIN (
                 SELECT banner_id, COUNT(*) AS clicks
                 FROM {$wpdb->prefix}rdr_bd_clicks
                 WHERE 1=1 $clk_date_sql
                 GROUP BY banner_id
             ) c ON c.banner_id = d.banner_id
             LEFT JOIN {$wpdb->posts} p ON p.ID = d.banner_id
             ORDER BY d.impressions DESC
             LIMIT %d",
            $limit
        ), ARRAY_A );

        return $rows ?: [];
    }

    // ── Campaign stats ────────────────────────────────────────────────────────

    public function get_campaign_stats( int $campaign_id, int $days = 30 ): array {
        global $wpdb;
        $where_d = $days > 0 ? $wpdb->prepare( 'AND stat_date >= CURDATE() - INTERVAL %d DAY', $days ) : '';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $imp_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT banner_id, SUM(impressions) AS impressions
             FROM {$wpdb->prefix}rdr_bd_stats_daily
             WHERE campaign_id = %d $where_d GROUP BY banner_id",
            $campaign_id
        ), ARRAY_A );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $clk_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT banner_id, COUNT(*) AS clicks
             FROM {$wpdb->prefix}rdr_bd_clicks WHERE campaign_id = %d GROUP BY banner_id",
            $campaign_id
        ), ARRAY_A );

        $by_banner = [];
        foreach ( $imp_rows as $r ) {
            $by_banner[ $r['banner_id'] ] = [ 'banner_id' => $r['banner_id'], 'impressions' => (int) $r['impressions'], 'clicks' => 0 ];
        }
        foreach ( $clk_rows as $r ) {
            if ( ! isset( $by_banner[ $r['banner_id'] ] ) ) {
                $by_banner[ $r['banner_id'] ] = [ 'banner_id' => $r['banner_id'], 'impressions' => 0, 'clicks' => 0 ];
            }
            $by_banner[ $r['banner_id'] ]['clicks'] = (int) $r['clicks'];
        }

        // Attach banner titles
        if ( ! empty( $by_banner ) ) {
            $ids   = implode( ',', array_map( 'intval', array_keys( $by_banner ) ) );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $posts = $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($ids)", ARRAY_A );
            foreach ( $posts as $p ) {
                if ( isset( $by_banner[ $p['ID'] ] ) ) $by_banner[ $p['ID'] ]['banner_title'] = $p['post_title'];
            }
        }

        return array_values( $by_banner );
    }

    public function get_campaign_daily( int $campaign_id, int $days = 30 ): array {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $imp_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT stat_date AS date, SUM(impressions) AS total
             FROM {$wpdb->prefix}rdr_bd_stats_daily
             WHERE campaign_id = %d AND stat_date >= CURDATE() - INTERVAL %d DAY
             GROUP BY stat_date ORDER BY stat_date ASC",
            $campaign_id, $days
        ), ARRAY_A );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $clk_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(clicked_at) AS date, COUNT(*) AS total
             FROM {$wpdb->prefix}rdr_bd_clicks
             WHERE campaign_id = %d AND clicked_at >= UTC_TIMESTAMP() - INTERVAL %d DAY
             GROUP BY DATE(clicked_at) ORDER BY date ASC",
            $campaign_id, $days
        ), ARRAY_A );

        $by_date = [];
        foreach ( $imp_rows as $r ) {
            $by_date[ $r['date'] ] = [ 'date' => $r['date'], 'impressions' => (int) $r['total'], 'clicks' => 0 ];
        }
        foreach ( $clk_rows as $r ) {
            if ( ! isset( $by_date[ $r['date'] ] ) ) {
                $by_date[ $r['date'] ] = [ 'date' => $r['date'], 'impressions' => 0, 'clicks' => 0 ];
            }
            $by_date[ $r['date'] ]['clicks'] = (int) $r['total'];
        }
        ksort( $by_date );
        return array_values( $by_date );
    }

    // ── Reset banner standalone stats ─────────────────────────────────────────

    public function reset_banner_stats( int $banner_id ): int {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $d = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rdr_bd_stats_daily WHERE banner_id = %d AND campaign_id = 0",
            $banner_id
        ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $c = (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}rdr_bd_clicks WHERE banner_id = %d AND campaign_id = 0",
            $banner_id
        ) );
        return $d + $c;
    }
}
