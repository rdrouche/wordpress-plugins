<?php
defined( 'ABSPATH' ) || exit;

class SCF_Install {

    const DB_VERSION_OPTION = 'scf_db_version';
    const DB_VERSION        = '1.4';
    const TABLES            = [ 'scf_submissions', 'scf_rate_limit' ];

    /**
     * Transient key — stores 'ok' when all tables exist.
     * Expires after 24 h; invalidated explicitly after create/repair.
     */
    const CACHE_KEY = 'scf_tables_ok';

    /** In-process static cache: avoids repeated transient reads in the same request. */
    private static ?bool $tables_ok_cache = null;

    // ------------------------------------------------------------------
    // Lifecycle hooks
    // ------------------------------------------------------------------

    public static function activate(): void {
        self::create_tables();
        self::set_defaults();
        self::invalidate_cache();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        self::invalidate_cache();
        flush_rewrite_rules();
    }

    // ------------------------------------------------------------------
    // Boot check — called once per request from the main plugin constructor.
    //
    // Fast path (normal operation, tables exist):
    //   1. Static variable check  → 0 queries
    //   2. Transient hit          → 1 autoloaded option read (already in memory)
    //   3. DB version option hit  → 1 autoloaded option read (already in memory)
    //
    // Slow path (first boot after install / tables missing):
    //   Runs SHOW TABLES + CREATE TABLE, then sets the transient.
    // ------------------------------------------------------------------

    public static function maybe_upgrade(): void {
        // 1. In-process static cache (zero overhead after first call this request)
        if ( self::$tables_ok_cache === true ) {
            return;
        }

        // 2. Transient cache (autoloaded → already in WP option memory, no extra query)
        if ( get_transient( self::CACHE_KEY ) === 'ok' ) {
            self::$tables_ok_cache = true;
            return;
        }

        // 3. Slow path: check DB version option (autoloaded, cheap)
        if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
            // Version matches — trust it and set the transient so next request is fast
            self::prime_cache();
            return;
        }

        // 4. Actually check tables and create if missing
        self::create_tables();
        self::set_defaults();
        // cache only if all tables now exist
        if ( self::all_tables_exist_in_db() ) {
            self::prime_cache();
        }
    }

    // ------------------------------------------------------------------
    // Force-create (Tools page button) — always bypasses cache
    // ------------------------------------------------------------------

    /** @return array<string, bool> */
    public static function force_create(): array {
        self::invalidate_cache();
        delete_option( self::DB_VERSION_OPTION );

        self::create_tables();
        self::set_defaults();

        $result = [];
        foreach ( self::TABLES as $t ) {
            $result[ $t ] = self::table_exists_in_db( $t );
        }

        if ( ! in_array( false, $result, true ) ) {
            self::prime_cache();
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Table existence — two flavours
    // ------------------------------------------------------------------

    /**
     * Fast check: uses the in-process + transient cache.
     * Use this for runtime guards (antispam, admin pages).
     */
    public static function all_tables_exist(): bool {
        if ( self::$tables_ok_cache === true ) {
            return true;
        }
        if ( get_transient( self::CACHE_KEY ) === 'ok' ) {
            self::$tables_ok_cache = true;
            return true;
        }
        return self::all_tables_exist_in_db();
    }

    /**
     * Slow check: hits the DB with SHOW TABLES.
     * Used only during install/repair and on the Tools page.
     */
    public static function all_tables_exist_in_db(): bool {
        foreach ( self::TABLES as $t ) {
            if ( ! self::table_exists_in_db( $t ) ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Single-table DB check (for the Tools diagnostic page).
     * Public alias keeps the Tools view working.
     */
    public static function table_exists( string $name ): bool {
        return self::table_exists_in_db( $name );
    }

    private static function table_exists_in_db( string $name ): bool {
        global $wpdb;
        $full = $wpdb->prefix . $name;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) === $full;
    }

    // ------------------------------------------------------------------
    // Cache helpers
    // ------------------------------------------------------------------

    private static function prime_cache(): void {
        self::$tables_ok_cache = true;
        set_transient( self::CACHE_KEY, 'ok', DAY_IN_SECONDS );
    }

    public static function invalidate_cache(): void {
        self::$tables_ok_cache = null;
        delete_transient( self::CACHE_KEY );
    }

    // ------------------------------------------------------------------
    // Table creation (dbDelta + raw SQL fallback)
    // ------------------------------------------------------------------

    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        if ( ! self::table_exists_in_db( 'scf_submissions' ) ) {
            self::create_submissions_table( $charset );
        }
        if ( ! self::table_exists_in_db( 'scf_rate_limit' ) ) {
            self::create_rate_limit_table( $charset );
        }

        update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
    }

    private static function create_submissions_table( string $charset ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'scf_submissions';

        $sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_id bigint(20) unsigned NOT NULL DEFAULT '0',
  lang varchar(10) NOT NULL DEFAULT '',
  fields longtext NOT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  user_agent text NOT NULL,
  spam_score tinyint(4) NOT NULL DEFAULT '0',
  status varchar(20) NOT NULL DEFAULT 'new',
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  KEY form_id (form_id),
  KEY created_at (created_at),
  KEY status (status)
) {$charset};";

        dbDelta( $sql );

        // Raw fallback if dbDelta silently failed
        if ( ! self::table_exists_in_db( 'scf_submissions' ) ) {
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "CREATE TABLE IF NOT EXISTS {$table} (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    form_id bigint(20) unsigned NOT NULL DEFAULT '0',
                    lang varchar(10) NOT NULL DEFAULT '',
                    fields longtext NOT NULL,
                    ip varchar(45) NOT NULL DEFAULT '',
                    user_agent text NOT NULL,
                    spam_score tinyint(4) NOT NULL DEFAULT '0',
                    status varchar(20) NOT NULL DEFAULT 'new',
                    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY form_id (form_id),
                    KEY created_at (created_at),
                    KEY status (status)
                ) {$charset}"
            );
        }
    }

    private static function create_rate_limit_table( string $charset ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'scf_rate_limit';

        $sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ip varchar(45) NOT NULL DEFAULT '',
  form_id bigint(20) unsigned NOT NULL DEFAULT '0',
  hits smallint(6) NOT NULL DEFAULT '1',
  window_start datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY ip_form (ip,form_id)
) {$charset};";

        dbDelta( $sql );

        if ( ! self::table_exists_in_db( 'scf_rate_limit' ) ) {
            $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
                "CREATE TABLE IF NOT EXISTS {$table} (
                    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    ip varchar(45) NOT NULL DEFAULT '',
                    form_id bigint(20) unsigned NOT NULL DEFAULT '0',
                    hits smallint(6) NOT NULL DEFAULT '1',
                    window_start datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY ip_form (ip, form_id)
                ) {$charset}"
            );
        }
    }

    // ------------------------------------------------------------------

    private static function set_defaults(): void {
        $defaults = [
            'scf_recaptcha_site_key'   => '',
            'scf_recaptcha_secret_key' => '',
            'scf_recaptcha_threshold'  => '0.5',
            'scf_rate_limit_max'       => '5',
            'scf_rate_limit_window'    => '3600',
            'scf_min_fill_time'        => '3',
            'scf_admin_email'          => get_option( 'admin_email' ),
            'scf_from_name'            => get_option( 'blogname' ),
            'scf_from_email'           => get_option( 'admin_email' ),
        ];
        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                add_option( $key, $value );
            }
        }
    }
}
