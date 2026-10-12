<?php
/**
 * ITPB_Install
 *
 * Handles plugin activation, database table creation/migration,
 * default options, and deactivation cleanup.
 *
 * @package WP_IT_Problem
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class ITPB_Install {

    // -----------------------------------------------------------------------
    // Schema version history
    // Bump ITPB_DB_VERSION in the main file when adding/altering tables.
    // -----------------------------------------------------------------------

    /**
     * Runs on plugin activation (register_activation_hook).
     * Safe to call multiple times — uses IF NOT EXISTS and dbDelta.
     */
    public static function activate(): void {
        self::run_upgrade();
        self::seed_default_options();

        // Register CPTs before flushing so rewrite rules are correct
        // We call the CPT register directly to avoid needing the full boot cycle
        if ( ! post_type_exists( ITPB_CPT_PROBLEM ) ) {
            ( new ITPB_Cpt() )->register_post_types();
        }

        flush_rewrite_rules();

        /**
         * Fires after the plugin has been fully activated.
         *
         * @since 1.0.0
         */
        do_action( 'itpb_activated' );
    }

    /**
     * Runs on plugin deactivation.
     * Does NOT drop tables or delete options — that is uninstall.php's job.
     */
    public static function deactivate(): void {
        // Remove the scheduled cron event
        $timestamp = wp_next_scheduled( 'itpb_daily_reveal' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'itpb_daily_reveal' );
        }

        flush_rewrite_rules();

        /**
         * Fires after the plugin has been deactivated.
         *
         * @since 1.0.0
         */
        do_action( 'itpb_deactivated' );
    }

    /**
     * Creates or upgrades DB tables and stores the current db_version.
     * Called on activation AND on plugins_loaded when version mismatch detected.
     */
    public static function run_upgrade(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        // -------------------------------------------------------------------
        // Table: wp_itpb_subscriptions
        // Stores per-user/per-email notification preferences per problem.
        // Kept for future use (email notify on reveal).  No harm having it now.
        // -------------------------------------------------------------------
        $sql_subscriptions = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}itpb_subscriptions (
            id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            problem_id    BIGINT UNSIGNED NOT NULL,
            user_id       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            email         VARCHAR(200)    NOT NULL DEFAULT '',
            notify_reveal TINYINT(1)      NOT NULL DEFAULT 1,
            created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY   (id),
            KEY idx_problem  (problem_id),
            KEY idx_user     (user_id),
            UNIQUE KEY uniq_problem_email (problem_id, email)
        ) $charset;";

        dbDelta( $sql_subscriptions );

        // -------------------------------------------------------------------
        // Table: wp_itpb_reveal_log
        // Audit trail: when an answer was revealed and by what trigger.
        // -------------------------------------------------------------------
        $sql_reveal_log = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}itpb_reveal_log (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            problem_id  BIGINT UNSIGNED NOT NULL,
            answer_id   BIGINT UNSIGNED NOT NULL,
            revealed_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            trigger_src VARCHAR(50)     NOT NULL DEFAULT 'cron',
            PRIMARY KEY (id),
            KEY idx_problem (problem_id)
        ) $charset;";

        dbDelta( $sql_reveal_log );

        // Persist the current DB schema version
        update_option( 'itpb_db_version', ITPB_DB_VERSION, false );
    }

    // -----------------------------------------------------------------------
    // Default options
    // -----------------------------------------------------------------------

    /**
     * Seeds default plugin options on first activation.
     * Uses add_option so existing values are never overwritten.
     */
    private static function seed_default_options(): void {
        $defaults = [
            // How many days after problem creation the answer reveals by default
            'itpb_default_reveal_days' => 7,

            // Whether the countdown block is shown to guests
            'itpb_show_countdown'      => true,

            // Polylang: languages to keep in sync (populated by ITPB_Polylang)
            'itpb_pll_sync'            => [],
        ];

        foreach ( $defaults as $key => $value ) {
            add_option( $key, $value, '', false );
        }
    }

    // -----------------------------------------------------------------------
    // Utility — called from admin "Fix database" button if needed
    // -----------------------------------------------------------------------

    /**
     * Drop and recreate all ITPB tables.
     * Destructive — only exposed to admins via explicit action.
     */
    public static function reset_tables(): void {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'itpb_subscriptions',
            $wpdb->prefix . 'itpb_reveal_log',
        ];

        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( "DROP TABLE IF EXISTS `$table`" );
        }

        delete_option( 'itpb_db_version' );
        self::run_upgrade();
    }

    /**
     * Return an array with table health info (used on a future diagnostic page).
     *
     * @return array<string, bool>
     */
    public static function check_tables(): array {
        global $wpdb;

        $tables = [
            'itpb_subscriptions' => $wpdb->prefix . 'itpb_subscriptions',
            'itpb_reveal_log'    => $wpdb->prefix . 'itpb_reveal_log',
        ];

        $result = [];
        foreach ( $tables as $key => $table ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $exists          = (bool) $wpdb->get_var( "SHOW TABLES LIKE '$table'" );
            $result[ $key ]  = $exists;
        }

        return $result;
    }
}
