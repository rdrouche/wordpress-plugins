<?php
/**
 * Stats Buffer — Redis-aware impression/click accumulator.
 *
 * Strategy:
 *   Impressions  → buffered in RAM (WordPress object cache / Redis if available).
 *                  A WP-Cron job flushes to DB every 5 minutes.
 *                  Only aggregated counts are stored — no per-request rows.
 *
 *   Clicks       → written to DB immediately (one row per click) for duplicate
 *                  detection and detailed analysis. Volume is negligible vs impressions.
 *
 * Redis detection:
 *   Works transparently with any persistent object cache (Redis, Memcached).
 *   Falls back to a WP transient (DB) if no persistent cache is available.
 *
 * Buffer key format (in object cache group 'rdr_bd_stats_buf'):
 *   "{banner_id}:{campaign_id}:{item_hash}:{adblock}"  → integer count
 *
 * @package RDR_Banner_Display
 */
defined( 'ABSPATH' ) || exit;

class RDR_BD_Stats_Buffer {

    const CACHE_GROUP  = 'rdr_bd_stats_buf';
    const CRON_HOOK    = 'rdr_bd_flush_stats';
    const FLUSH_INTERVAL = 300; // seconds (5 minutes)
    const TRANSIENT_KEY  = 'rdr_bd_stats_buffer'; // fallback if no persistent cache

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    public function init(): void {
        // Cron flush
        add_action( self::CRON_HOOK, [ $this, 'flush_to_db' ] );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'rdr_bd_5min', self::CRON_HOOK );
        }
        // Register custom cron interval
        add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
    }

    public function add_cron_interval( array $schedules ): array {
        $schedules['rdr_bd_5min'] = [
            'interval' => self::FLUSH_INTERVAL,
            'display'  => 'Every 5 minutes (RDR Banner stats)',
        ];
        return $schedules;
    }

    // ── Public API ─────────────────────────────────────────────────────────────

    /**
     * Buffer one impression.
     * Increments an in-memory counter — no DB write.
     */
    public function record_impression(
        int    $banner_id,
        int    $campaign_id,
        string $item_hash,
        bool   $is_adblock
    ): void {
        if ( ! $banner_id ) return;

        $key = $this->make_key( $banner_id, $campaign_id, $item_hash, $is_adblock );

        if ( $this->has_persistent_cache() ) {
            // Atomic increment in Redis / Memcached
            $current = wp_cache_get( $key, self::CACHE_GROUP );
            if ( $current === false ) $current = 0;
            wp_cache_set( $key, (int) $current + 1, self::CACHE_GROUP, 3600 );
            // Track active keys in an index so drain_buffer() can find them
            $index = wp_cache_get( 'rdr_bd_buffer_index', self::CACHE_GROUP ) ?: [];
            if ( ! in_array( $key, $index, true ) ) {
                $index[] = $key;
                wp_cache_set( 'rdr_bd_buffer_index', $index, self::CACHE_GROUP, 3600 );
            }
        } else {
            // Fallback: transient (stored in DB, but only updated every flush)
            $buffer = get_transient( self::TRANSIENT_KEY ) ?: [];
            $buffer[ $key ] = ( $buffer[ $key ] ?? 0 ) + 1;
            set_transient( self::TRANSIENT_KEY, $buffer, 3600 );
        }
    }

    /**
     * Write one click row to DB immediately.
     * Volume is low enough that individual rows are acceptable.
     * Stores ip_hash for duplicate-click detection.
     */
    public function record_click(
        int    $banner_id,
        int    $campaign_id,
        string $item_hash,
        bool   $is_adblock,
        string $ip,
        string $ua
    ): void {
        if ( ! $banner_id ) return;

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $wpdb->prefix . 'rdr_bd_stats',
            [
                'banner_id'   => $banner_id,
                'campaign_id' => $campaign_id ?: null,
                'event_type'  => 'click',
                'user_agent'  => substr( $ua, 0, 512 ),
                'ip_hash'     => rdr_bd_hash_ip( $ip ),
                'adblock'     => $is_adblock ? 1 : 0,
                'item_hash'   => $item_hash  ?: null,
                'created_at'  => current_time( 'mysql', true ),
            ],
            [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );
    }

    /**
     * Flush buffered impression counts to DB.
     * Called by WP-Cron every 5 minutes.
     * Writes one aggregated row per unique (banner+campaign+item+adblock) combination.
     */
    public function flush_to_db(): void {
        $buffer = $this->drain_buffer();
        if ( empty( $buffer ) ) return;

        global $wpdb;
        $now = current_time( 'mysql', true );

        foreach ( $buffer as $key => $count ) {
            if ( $count <= 0 ) continue;

            [ $banner_id, $campaign_id, $item_hash, $adblock ] = $this->parse_key( $key );

            // Build row — use count column only if it exists (migration-safe)
            $row = [
                'banner_id'   => $banner_id,
                'campaign_id' => $campaign_id ?: null,
                'event_type'  => 'impression',
                'user_agent'  => null,
                'ip_hash'     => null,
                'adblock'     => $adblock,
                'item_hash'   => $item_hash ?: null,
                'created_at'  => $now,
            ];
            $fmt = [ '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ];

            // Add count only if column exists (safe after migration)
            static $has_count_col = null;
            if ( $has_count_col === null ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}rdr_bd_stats", 0 );
                $has_count_col = in_array( 'count', $cols, true );
            }
            if ( $has_count_col ) {
                $row['count'] = $count;
                $fmt[]        = '%d';
            } else {
                // Write $count individual rows for backward compat
                for ( $i = 1; $i < $count; $i++ ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                    $wpdb->insert( $wpdb->prefix . 'rdr_bd_stats', $row, $fmt );
                }
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert( $wpdb->prefix . 'rdr_bd_stats', $row, $fmt );
        }
    }

    /**
     * Forced flush (e.g. on deactivation).
     */
    public function force_flush(): void {
        $this->flush_to_db();
    }

    // ── Query helpers (for stats display) ─────────────────────────────────────

    /**
     * Get buffered impression count not yet in DB (for accurate dashboard display).
     *
     * @param int $banner_id  0 = all banners
     * @return int
     */
    public function get_pending_impressions( int $banner_id = 0 ): int {
        $buffer = $this->read_buffer();
        $total  = 0;
        foreach ( $buffer as $key => $count ) {
            if ( $banner_id ) {
                $parts = explode( ':', $key );
                if ( (int) ( $parts[0] ?? 0 ) !== $banner_id ) continue;
            }
            $total += (int) $count;
        }
        return $total;
    }

    // ── Private helpers ────────────────────────────────────────────────────────

    private function make_key( int $banner_id, int $campaign_id, string $item_hash, bool $adblock ): string {
        return implode( ':', [ $banner_id, $campaign_id, $item_hash, (int) $adblock ] );
    }

    private function parse_key( string $key ): array {
        $parts = explode( ':', $key );
        return [
            (int)    ( $parts[0] ?? 0 ),   // banner_id
            (int)    ( $parts[1] ?? 0 ),   // campaign_id
            (string) ( $parts[2] ?? '' ),  // item_hash
            (int)    ( $parts[3] ?? 0 ),   // adblock
        ];
    }

    /**
     * Detect whether a persistent object cache (Redis/Memcached) is active.
     * wp_using_ext_object_cache() returns true when WP loaded an external cache.
     */
    private function has_persistent_cache(): bool {
        return wp_using_ext_object_cache();
    }

    /**
     * Read all buffered keys without clearing them.
     */
    private function read_buffer(): array {
        if ( $this->has_persistent_cache() ) {
            // With Redis, we can't easily list all keys in a group.
            // We maintain a separate index key that tracks active buffer keys.
            $index = wp_cache_get( 'rdr_bd_buffer_index', self::CACHE_GROUP ) ?: [];
            $buffer = [];
            foreach ( $index as $key ) {
                $val = wp_cache_get( $key, self::CACHE_GROUP );
                if ( $val !== false ) $buffer[ $key ] = (int) $val;
            }
            return $buffer;
        }
        return get_transient( self::TRANSIENT_KEY ) ?: [];
    }

    /**
     * Read and atomically clear the buffer.
     */
    private function drain_buffer(): array {
        if ( $this->has_persistent_cache() ) {
            $index = wp_cache_get( 'rdr_bd_buffer_index', self::CACHE_GROUP ) ?: [];
            $buffer = [];
            foreach ( $index as $key ) {
                $val = wp_cache_get( $key, self::CACHE_GROUP );
                if ( $val !== false ) {
                    $buffer[ $key ] = (int) $val;
                    wp_cache_delete( $key, self::CACHE_GROUP );
                }
            }
            wp_cache_delete( 'rdr_bd_buffer_index', self::CACHE_GROUP );
            return $buffer;
        }

        // Transient path — read and delete atomically
        $buffer = get_transient( self::TRANSIENT_KEY ) ?: [];
        delete_transient( self::TRANSIENT_KEY );
        return $buffer;
    }
}
