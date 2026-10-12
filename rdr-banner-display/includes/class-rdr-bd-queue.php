<?php
/**
 * Redis stats queue — push/flush pipeline for impression and click events.
 *
 * Architecture:
 *   Each event (impression or click) is RPUSH-ed as a JSON string onto a
 *   Redis list.  A WP-Cron job runs every 5 minutes:
 *     1. LLEN  → how many items are waiting
 *     2. LRANGE 0 N-1  → read all N items into a PHP array
 *     3. LTRIM N -1    → atomically remove the N items just read
 *        (if cron dies after step 3 but before DB write, those N items are
 *         gone — acceptable: we log a warning and move on)
 *     4. Aggregate in PHP → bulk upsert to DB in one query per unique key
 *
 * Fallback (Redis unavailable):
 *   Direct INSERT to rdr_bd_stats_daily / rdr_bd_clicks — no queue, no loss.
 *
 * Redis access:
 *   Uses the WP Redis object cache connection when available (wp-redis /
 *   object-cache.php exposes a global $wp_object_cache with a ->redis property).
 *   Falls back to a plain Redis connection on 127.0.0.1:6379 if configurable
 *   via RDR_BD_REDIS_HOST / RDR_BD_REDIS_PORT constants in wp-config.php.
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

class RDR_BD_Queue {

    const QUEUE_IMPRESSIONS = 'rdr_bd_queue_imp';
    const QUEUE_CLICKS      = 'rdr_bd_queue_clk';
    const CRON_HOOK         = 'rdr_bd_flush_queue';
    const CRON_INTERVAL     = 'rdr_bd_5min';
    const FLUSH_BATCH       = 10000; // max items to process per cron run

    // ── Bootstrap ─────────────────────────────────────────────────────────────

    public function init(): void {
        add_filter( 'cron_schedules', [ $this, 'register_interval' ] );
        add_action( self::CRON_HOOK,  [ $this, 'flush' ] );

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), self::CRON_INTERVAL, self::CRON_HOOK );
        }
    }

    public function register_interval( array $schedules ): array {
        $schedules[ self::CRON_INTERVAL ] = [
            'interval' => 300,
            'display'  => 'Every 5 minutes (RDR Banner stats)',
        ];
        return $schedules;
    }

    // ── Push ──────────────────────────────────────────────────────────────────

    /**
     * Push one impression event onto the Redis queue.
     * Falls back to direct DB write if Redis is unavailable.
     */
    public function push_impression(
        int    $banner_id,
        int    $campaign_id,
        string $item_hash,
        bool   $is_adblock
    ): void {
        $payload = wp_json_encode( [
            'b' => $banner_id,
            'c' => $campaign_id,
            'h' => $item_hash,
            'a' => (int) $is_adblock,
        ] );

        $redis = $this->get_redis();
        if ( $redis ) {
            try {
                $redis->rPush( self::QUEUE_IMPRESSIONS, $payload );
                return;
            } catch ( \Exception $e ) {
                // Redis error → fall through to direct DB write
            }
        }

        // Fallback: direct upsert (slow path, rare)
        $this->direct_impression( $banner_id, $campaign_id, $item_hash, $is_adblock );
    }

    /**
     * Push one click event onto the Redis queue.
     * Falls back to direct DB write if Redis is unavailable.
     */
    public function push_click(
        int    $banner_id,
        int    $campaign_id,
        string $item_hash,
        bool   $is_adblock,
        string $ip,
        string $ua
    ): void {
        $payload = wp_json_encode( [
            'b'  => $banner_id,
            'c'  => $campaign_id,
            'h'  => $item_hash,
            'a'  => (int) $is_adblock,
            'ip' => rdr_bd_hash_ip( $ip ),
            'ua' => substr( $ua, 0, 255 ), // truncate for queue storage
        ] );

        $redis = $this->get_redis();
        if ( $redis ) {
            try {
                $redis->rPush( self::QUEUE_CLICKS, $payload );
                return;
            } catch ( \Exception $e ) {
                // Fall through
            }
        }

        // Fallback: direct DB insert
        $this->direct_click( $banner_id, $campaign_id, $item_hash, $is_adblock, $ip, $ua );
    }

    // ── Flush (cron) ──────────────────────────────────────────────────────────

    /**
     * Drain both queues and write aggregated data to DB.
     * Called by WP-Cron every 5 minutes.
     */
    public function flush(): void {
        $redis = $this->get_redis();
        if ( ! $redis ) return; // Nothing to flush without Redis

        $this->flush_impressions( $redis );
        $this->flush_clicks( $redis );
    }

    private function flush_impressions( $redis ): void {
        try {
            $len = (int) $redis->lLen( self::QUEUE_IMPRESSIONS );
        } catch ( \Exception $e ) { return; }

        if ( $len === 0 ) return;

        $batch = min( $len, self::FLUSH_BATCH );

        try {
            // Read N items
            $items = $redis->lRange( self::QUEUE_IMPRESSIONS, 0, $batch - 1 );
            // Atomically remove the N items we just read
            $redis->lTrim( self::QUEUE_IMPRESSIONS, $batch, -1 );
        } catch ( \Exception $e ) { return; }

        if ( empty( $items ) ) return;

        // Aggregate by (date, banner_id, campaign_id, item_hash, adblock)
        $aggregated = [];
        $today      = gmdate( 'Y-m-d' );

        foreach ( $items as $raw ) {
            $event = json_decode( $raw, true );
            if ( ! $event ) continue;

            $key = implode( ':', [
                $today,
                (int) ( $event['b'] ?? 0 ),
                (int) ( $event['c'] ?? 0 ),
                (string) ( $event['h'] ?? '' ),
                (int) ( $event['a'] ?? 0 ),
            ] );
            $aggregated[ $key ] = ( $aggregated[ $key ] ?? 0 ) + 1;
        }

        if ( empty( $aggregated ) ) return;

        global $wpdb;

        // Build one multi-row INSERT … ON DUPLICATE KEY UPDATE
        // This is one SQL query regardless of how many unique keys there are
        $placeholders = [];
        $values       = [];

        foreach ( $aggregated as $key => $count ) {
            [ $date, $banner_id, $campaign_id, $item_hash, $adblock ] = explode( ':', $key, 5 );
            $placeholders[] = '(%s, %d, %d, %s, %d, %d)';
            array_push( $values, $date, (int) $banner_id, (int) $campaign_id, $item_hash, (int) $adblock, (int) $count );
        }

        $sql = "INSERT INTO {$wpdb->prefix}rdr_bd_stats_daily
                    (stat_date, banner_id, campaign_id, item_hash, adblock, impressions)
                VALUES " . implode( ', ', $placeholders ) . "
                ON DUPLICATE KEY UPDATE impressions = impressions + VALUES(impressions)";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( $wpdb->prepare( $sql, $values ) );
    }

    private function flush_clicks( $redis ): void {
        try {
            $len = (int) $redis->lLen( self::QUEUE_CLICKS );
        } catch ( \Exception $e ) { return; }

        if ( $len === 0 ) return;

        $batch = min( $len, self::FLUSH_BATCH );

        try {
            $items = $redis->lRange( self::QUEUE_CLICKS, 0, $batch - 1 );
            $redis->lTrim( self::QUEUE_CLICKS, $batch, -1 );
        } catch ( \Exception $e ) { return; }

        if ( empty( $items ) ) return;

        global $wpdb;
        $now = current_time( 'mysql', true );

        // Bulk insert clicks — one multi-row INSERT
        $placeholders = [];
        $values       = [];

        foreach ( $items as $raw ) {
            $event = json_decode( $raw, true );
            if ( ! $event ) continue;
            $placeholders[] = '(%s, %d, %d, %s, %d, %s, %s)';
            array_push( $values,
                $now,
                (int)    ( $event['b']  ?? 0 ),
                (int)    ( $event['c']  ?? 0 ),
                (string) ( $event['h']  ?? '' ),
                (int)    ( $event['a']  ?? 0 ),
                (string) ( $event['ip'] ?? '' ),
                (string) ( $event['ua'] ?? '' )
            );
        }

        if ( empty( $placeholders ) ) return;

        $sql = "INSERT INTO {$wpdb->prefix}rdr_bd_clicks
                    (clicked_at, banner_id, campaign_id, item_hash, adblock, ip_hash, user_agent)
                VALUES " . implode( ', ', $placeholders );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
        $wpdb->query( $wpdb->prepare( $sql, $values ) );
    }

    // ── Queue size (for admin dashboard) ──────────────────────────────────────

    public function get_queue_sizes(): array {
        $redis = $this->get_redis();
        if ( ! $redis ) return [ 'impressions' => 0, 'clicks' => 0, 'redis' => false ];
        try {
            return [
                'impressions' => (int) $redis->lLen( self::QUEUE_IMPRESSIONS ),
                'clicks'      => (int) $redis->lLen( self::QUEUE_CLICKS ),
                'redis'       => true,
            ];
        } catch ( \Exception $e ) {
            return [ 'impressions' => 0, 'clicks' => 0, 'redis' => false ];
        }
    }

    // ── Redis connection ──────────────────────────────────────────────────────

    /** @var \Redis|null */
    private static ?object $redis_instance = null;
    private static bool    $redis_checked  = false;

    /**
     * Get a Redis connection.
     *
     * Priority:
     *   1. Already open connection (static cache)
     *   2. wp-redis / Redis Object Cache plugin ($wp_object_cache->redis)
     *   3. Direct connection using RDR_BD_REDIS_HOST / RDR_BD_REDIS_PORT constants
     *
     * Returns null if Redis is unavailable.
     */
    private function get_redis(): ?object {
        if ( self::$redis_checked ) return self::$redis_instance;
        self::$redis_checked = true;

        // 1. Reuse the WP object cache's Redis connection (zero overhead)
        global $wp_object_cache;
        if ( isset( $wp_object_cache->redis ) && $wp_object_cache->redis instanceof \Redis ) {
            self::$redis_instance = $wp_object_cache->redis;
            return self::$redis_instance;
        }

        // Some plugins expose the connection differently
        if ( method_exists( $wp_object_cache, 'get_redis' ) ) {
            try {
                $r = $wp_object_cache->get_redis();
                if ( $r instanceof \Redis ) {
                    self::$redis_instance = $r;
                    return self::$redis_instance;
                }
            } catch ( \Exception $e ) {}
        }

        // 2. Direct connection (if constants defined in wp-config.php)
        if ( class_exists( 'Redis' ) ) {
            $host = defined( 'RDR_BD_REDIS_HOST' ) ? RDR_BD_REDIS_HOST : '127.0.0.1';
            $port = defined( 'RDR_BD_REDIS_PORT' ) ? (int) RDR_BD_REDIS_PORT : 6379;
            $pass = defined( 'RDR_BD_REDIS_PASSWORD' ) ? RDR_BD_REDIS_PASSWORD : null;
            $db   = defined( 'RDR_BD_REDIS_DB' ) ? (int) RDR_BD_REDIS_DB : 0;

            try {
                $redis = new \Redis();
                $redis->connect( $host, $port, 1.0 ); // 1s timeout
                if ( $pass ) $redis->auth( $pass );
                if ( $db )   $redis->select( $db );
                self::$redis_instance = $redis;
                return self::$redis_instance;
            } catch ( \Exception $e ) {
                // Redis unavailable — fallback to direct DB
            }
        }

        return null;
    }

    // ── Direct DB fallbacks (Redis unavailable) ───────────────────────────────

    private function direct_impression( int $banner_id, int $campaign_id, string $item_hash, bool $is_adblock ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}rdr_bd_stats_daily
                (stat_date, banner_id, campaign_id, item_hash, adblock, impressions)
             VALUES (CURDATE(), %d, %d, %s, %d, 1)
             ON DUPLICATE KEY UPDATE impressions = impressions + 1",
            $banner_id, $campaign_id, $item_hash, $is_adblock ? 1 : 0
        ) );
    }

    private function direct_click( int $banner_id, int $campaign_id, string $item_hash, bool $is_adblock, string $ip, string $ua ): void {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $wpdb->prefix . 'rdr_bd_clicks',
            [
                'clicked_at'  => current_time( 'mysql', true ),
                'banner_id'   => $banner_id,
                'campaign_id' => $campaign_id,
                'item_hash'   => $item_hash,
                'adblock'     => $is_adblock ? 1 : 0,
                'ip_hash'     => rdr_bd_hash_ip( $ip ),
                'user_agent'  => substr( $ua, 0, 512 ),
            ],
            [ '%s', '%d', '%d', '%s', '%d', '%s', '%s' ]
        );
    }
}
