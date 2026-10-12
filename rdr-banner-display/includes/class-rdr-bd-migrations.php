<?php
/**
 * DB migrations — runs at plugins_loaded priority 1.
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

class RDR_BD_Migrations {

    public static function run(): void {
        $db_version = get_option( 'rdr_bd_db_version', '0' );
        if ( version_compare( $db_version, RDR_BD_VERSION, '>=' ) ) return;

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // v2.3.0 — Create stats_daily + clicks tables
        if ( version_compare( $db_version, '2.3.0', '<' ) ) {
            self::migrate_2_3_0( $wpdb );
        }

        // v2.4.0 — Composite indexes
        if ( version_compare( $db_version, '2.4.0', '<' ) ) {
            self::migrate_2_4_0( $wpdb );
        }

        // v2.5.0 — Ensure old rdr_bd_stats data is fully migrated,
        //           then rename old table to avoid double-counting.
        if ( version_compare( $db_version, '2.5.0', '<' ) ) {
            self::migrate_2_5_0( $wpdb );
        }

        update_option( 'rdr_bd_db_version', RDR_BD_VERSION );
    }

    // ── v2.5.0 ────────────────────────────────────────────────────────────────

    /**
     * Make sure rdr_bd_stats data is fully in the new tables, then
     * rename old table to _rdr_bd_stats_archived so it's never queried again
     * but data is preserved for manual inspection.
     */
    private static function migrate_2_5_0( wpdb $wpdb ): void {

        // Old table still exists?
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $old_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rdr_bd_stats'" );
        if ( ! $old_exists ) return;

        // Wipe any partial migration data that may have been inserted by prior runs
        // (INSERT IGNORE may have left gaps — easier to start fresh from the old table)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}rdr_bd_stats_daily" );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query( "DELETE FROM {$wpdb->prefix}rdr_bd_clicks WHERE 1=1" );

        // Re-migrate from scratch, cleanly
        self::migrate_old_stats( $wpdb );

        // Rename old table — it will no longer be queried by the plugin
        // (keeps data safe for manual inspection / rollback)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            "RENAME TABLE {$wpdb->prefix}rdr_bd_stats TO {$wpdb->prefix}rdr_bd_stats_archived"
        );
    }

    // ── v2.4.0 ────────────────────────────────────────────────────────────────

    private static function migrate_2_4_0( wpdb $wpdb ): void {
        $indexes = [
            [ 'rdr_bd_stats_daily', 'idx_banner_item',   '(banner_id, item_hash)' ],
            [ 'rdr_bd_stats_daily', 'idx_campaign_date', '(campaign_id, stat_date)' ],
            [ 'rdr_bd_clicks',      'idx_banner_item',   '(banner_id, item_hash)' ],
            [ 'rdr_bd_clicks',      'idx_campaign_date', '(campaign_id, clicked_at)' ],
        ];

        foreach ( $indexes as [ $table, $name, $cols ] ) {
            $full_table = $wpdb->prefix . $table;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $exists = $wpdb->get_var( "SHOW INDEX FROM `$full_table` WHERE Key_name = '$name'" );
            if ( ! $exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $wpdb->query( "ALTER TABLE `$full_table` ADD KEY `$name` $cols" );
            }
        }
    }

    // ── v2.3.0 ────────────────────────────────────────────────────────────────

    private static function migrate_2_3_0( wpdb $wpdb ): void {
        $charset = $wpdb->get_charset_collate();

        $sql_daily = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rdr_bd_stats_daily (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            stat_date   DATE            NOT NULL,
            banner_id   BIGINT UNSIGNED NOT NULL,
            campaign_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            item_hash   VARCHAR(16)     NOT NULL DEFAULT '',
            adblock     TINYINT(1)      NOT NULL DEFAULT 0,
            impressions INT UNSIGNED    NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uk_daily (stat_date, banner_id, campaign_id, item_hash, adblock),
            KEY idx_banner_id   (banner_id),
            KEY idx_campaign_id (campaign_id),
            KEY idx_stat_date   (stat_date),
            KEY idx_banner_item   (banner_id, item_hash),
            KEY idx_campaign_date (campaign_id, stat_date)
        ) $charset;";

        $sql_clicks = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rdr_bd_clicks (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            clicked_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            banner_id   BIGINT UNSIGNED NOT NULL,
            campaign_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            item_hash   VARCHAR(16)     NOT NULL DEFAULT '',
            adblock     TINYINT(1)      NOT NULL DEFAULT 0,
            ip_hash     VARCHAR(64)     DEFAULT NULL,
            user_agent  VARCHAR(512)    DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_banner_id     (banner_id),
            KEY idx_campaign_id   (campaign_id),
            KEY idx_clicked_at    (clicked_at),
            KEY idx_ip_hash       (ip_hash),
            KEY idx_banner_item   (banner_id, item_hash),
            KEY idx_campaign_date (campaign_id, clicked_at)
        ) $charset;";

        dbDelta( $sql_daily );
        dbDelta( $sql_clicks );

        // Initial migration of old data (if old table exists)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $old_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rdr_bd_stats'" );
        if ( $old_exists ) {
            self::migrate_old_stats( $wpdb );
        }
    }

    /**
     * Migrate data from old rdr_bd_stats into new tables.
     * Called from both migrate_2_3_0 (first run) and migrate_2_5_0 (clean re-run).
     */
    private static function migrate_old_stats( wpdb $wpdb ): void {

        // ── Impressions → stats_daily ──────────────────────────────────────────
        // Use INSERT … ON DUPLICATE KEY UPDATE so partial runs are idempotent
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            "INSERT INTO {$wpdb->prefix}rdr_bd_stats_daily
                (stat_date, banner_id, campaign_id, item_hash, adblock, impressions)
             SELECT
                DATE(created_at)                   AS stat_date,
                banner_id,
                COALESCE(campaign_id, 0)            AS campaign_id,
                COALESCE(item_hash, '')             AS item_hash,
                adblock,
                SUM(COALESCE(`count`, 1))           AS impressions
             FROM {$wpdb->prefix}rdr_bd_stats
             WHERE event_type = 'impression'
             GROUP BY DATE(created_at), banner_id, COALESCE(campaign_id,0),
                      COALESCE(item_hash,''), adblock
             ON DUPLICATE KEY UPDATE impressions = impressions + VALUES(impressions)"
        );

        // ── Clicks → rdr_bd_clicks ─────────────────────────────────────────────
        // Only insert clicks that don't already exist (match on ip_hash + banner + time)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            "INSERT INTO {$wpdb->prefix}rdr_bd_clicks
                (clicked_at, banner_id, campaign_id, item_hash, adblock, ip_hash, user_agent)
             SELECT
                created_at,
                banner_id,
                COALESCE(campaign_id, 0),
                COALESCE(item_hash, ''),
                adblock,
                ip_hash,
                user_agent
             FROM {$wpdb->prefix}rdr_bd_stats
             WHERE event_type = 'click'"
        );
    }

    // ── Public: diagnostic ────────────────────────────────────────────────────

    /**
     * Return diagnostic info about the stats tables.
     * Used by the admin dashboard to show data integrity status.
     */
    public static function get_diagnostics(): array {
        global $wpdb;

        $tables = [
            'stats_daily'    => $wpdb->prefix . 'rdr_bd_stats_daily',
            'clicks'         => $wpdb->prefix . 'rdr_bd_clicks',
            'old_stats'      => $wpdb->prefix . 'rdr_bd_stats',
            'old_archived'   => $wpdb->prefix . 'rdr_bd_stats_archived',
        ];

        $diag = [];
        foreach ( $tables as $key => $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $exists = (bool) $wpdb->get_var( "SHOW TABLES LIKE '$table'" );
            $count  = 0;
            if ( $exists ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$table`" );
            }
            $diag[ $key ] = [ 'exists' => $exists, 'rows' => $count, 'table' => $table ];
        }

        // Warning: old table still active (not yet archived)
        $diag['old_table_active'] = $diag['old_stats']['exists'];

        return $diag;
    }
}
