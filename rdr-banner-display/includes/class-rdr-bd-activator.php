<?php
/**
 * Plugin activation / deactivation.
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

class RDR_BD_Activator {

    public static function activate(): void {
        self::create_tables();
        self::set_default_options();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    private static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // ── Daily aggregated impressions ───────────────────────────────────────
        // One row per (date, banner, campaign, item, adblock).
        // count is incremented atomically via ON DUPLICATE KEY UPDATE — no race
        // condition, no buffer, no data loss on cache flush.
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
            KEY idx_banner_id        (banner_id),
            KEY idx_campaign_id      (campaign_id),
            KEY idx_stat_date        (stat_date),
            KEY idx_banner_item      (banner_id, item_hash),
            KEY idx_campaign_date    (campaign_id, stat_date)
        ) $charset;";

        // ── Click detail log ───────────────────────────────────────────────────
        // One row per click — kept for duplicate detection and detailed analysis.
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

        // ── Campaigns ─────────────────────────────────────────────────────────
        $sql_campaigns = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rdr_bd_campaigns (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title           VARCHAR(255)    NOT NULL,
            status          ENUM('pending','active','paused','ended') NOT NULL DEFAULT 'pending',
            owner_user_id   BIGINT UNSIGNED DEFAULT NULL,
            start_date      DATETIME        DEFAULT NULL,
            end_date        DATETIME        DEFAULT NULL,
            budget          DECIMAL(10,2)   DEFAULT NULL,
            impression_cap  INT UNSIGNED    DEFAULT NULL,
            click_cap       INT UNSIGNED    DEFAULT NULL,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status    (status),
            KEY idx_owner     (owner_user_id),
            KEY idx_start_date (start_date),
            KEY idx_end_date   (end_date)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_daily );
        dbDelta( $sql_clicks );
        dbDelta( $sql_campaigns );

        update_option( 'rdr_bd_db_version', RDR_BD_VERSION );
    }

    private static function set_default_options(): void {
        $defaults = [
            'rdr_bd_stats_enabled'        => '1',
            'rdr_bd_stats_retention_days' => '90',
            'rdr_bd_default_width'        => '300',
            'rdr_bd_default_height'       => '250',
            'rdr_bd_no_ads_key'           => 'wpa_no_ads',
            'rdr_bd_no_ads_mode'          => 'capability',
            'rdr_bd_no_ads_allowed_types' => [ 'html' ],
            'rdr_bd_skeleton_bg'          => '#1c2128',
            'rdr_bd_skeleton_shine'       => '#2ea8ff',
            'rdr_bd_adblock_delay'        => '2500',
        ];
        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                update_option( $key, $value, false );
            }
        }
    }
}
