<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPS_Install {

    public static function activate() {
        self::create_tables();
        self::set_defaults();
        self::maybe_upgrade();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        /* ── Votes ── */
        $votes = $wpdb->prefix . 'wps_votes';
        $sql_votes = "CREATE TABLE IF NOT EXISTS $votes (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id     BIGINT UNSIGNED NOT NULL,
            choice_ids  TEXT            NOT NULL,
            ip_hash     VARCHAR(64)     NOT NULL,
            ua_hash     VARCHAR(64)     NOT NULL DEFAULT '',
            lang        VARCHAR(10)     NOT NULL DEFAULT '',
            voted_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY poll_id  (poll_id),
            KEY ip_hash  (ip_hash),
            KEY voted_at (voted_at),
            KEY lang     (lang)
        ) $charset;";

        /* ── Choix (answers) ── */
        $choices = $wpdb->prefix . 'wps_choices';
        $sql_choices = "CREATE TABLE IF NOT EXISTS $choices (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id     BIGINT UNSIGNED NOT NULL,
            parent_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
            label       TEXT            NOT NULL,
            sort_order  SMALLINT        NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY poll_id   (poll_id),
            KEY parent_id (parent_id)
        ) $charset;";

        /* ── Snapshots (historique / comparaison temporelle) ── */
        $snaps = $wpdb->prefix . 'wps_snapshots';
        $sql_snaps = "CREATE TABLE IF NOT EXISTS $snaps (
            id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poll_id      BIGINT UNSIGNED NOT NULL,
            label        VARCHAR(255)    NOT NULL DEFAULT '',
            snapshot_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            trigger_type VARCHAR(32)     NOT NULL DEFAULT 'manual',
            total_votes  INT UNSIGNED    NOT NULL DEFAULT 0,
            choices_json LONGTEXT        NOT NULL,
            PRIMARY KEY (id),
            KEY poll_id     (poll_id),
            KEY snapshot_at (snapshot_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql_votes );
        dbDelta( $sql_choices );
        dbDelta( $sql_snaps );

        update_option( 'wps_db_version', WPS_VERSION );
    }

    public static function set_defaults() {
        $defaults = [
            'wps_antiflood_minutes' => 60,
            'wps_results_default'   => 'public',
        ];
        foreach ( $defaults as $key => $val ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $val );
            }
        }
    }

    /** Migrations futures — compare version en DB avec constante plugin. */
    public static function maybe_upgrade() {
        $db_ver = get_option( 'wps_db_version', '0' );
        if ( version_compare( $db_ver, WPS_VERSION, '<' ) ) {
            self::create_tables(); // dbDelta est idempotent, ajoute les colonnes manquantes
            self::maybe_add_lang_column(); // migration explicite colonne lang
            update_option( 'wps_db_version', WPS_VERSION );
        }
    }

    /**
     * Ajoute la colonne lang à wps_votes si elle n'existe pas.
     * Nécessaire pour les sites qui mettent à jour depuis < 1.6.0.
     */
    private static function maybe_add_lang_column() {
        global $wpdb;
        $table   = $wpdb->prefix . 'wps_votes';
        $columns = $wpdb->get_col( "DESCRIBE $table" );
        if ( ! in_array( 'lang', $columns, true ) ) {
            $wpdb->query( "ALTER TABLE $table ADD COLUMN lang VARCHAR(10) NOT NULL DEFAULT '' AFTER ua_hash, ADD KEY lang (lang)" );
        }
    }
}
