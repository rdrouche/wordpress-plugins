<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class RDR_Admin_CTB {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
        add_action( 'admin_post_rdr_cyber_save_sources', array( __CLASS__, 'save_sources' ) );
    }

    public static function add_menu() {
        add_menu_page(
            'RDR Cyber Dashboard',
            'Cyber Dashboard',
            'manage_options',
            'rdr-cyber-dashboard',
            array( __CLASS__, 'page_main' ),
            'dashicons-shield',
            80
        );
        add_submenu_page( 'rdr-cyber-dashboard', "Vue d'ensemble",  "Vue d'ensemble",  'manage_options', 'rdr-cyber-dashboard',   array( __CLASS__, 'page_main' ) );
        add_submenu_page( 'rdr-cyber-dashboard', 'Sources',          'Sources',          'manage_options', 'rdr-cyber-sources',      array( __CLASS__, 'page_sources' ) );
        add_submenu_page( 'rdr-cyber-dashboard', 'Abonnés',          'Abonnés',          'manage_options', 'rdr-cyber-subscribers',  array( __CLASS__, 'page_subscribers' ) );
        add_submenu_page( 'rdr-cyber-dashboard', 'Paramètres',       'Paramètres',       'manage_options', 'rdr-cyber-settings',     array( __CLASS__, 'page_settings' ) );
    }

    public static function register_settings() {
        register_setting( 'rdr_cyber_options', 'rdr_cyber_interval',    array( 'sanitize_callback' => array( __CLASS__, 'sanitize_interval' ), 'default' => 'twicedaily' ) );
        register_setting( 'rdr_cyber_options', 'rdr_cyber_nvd_key',     array( 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ) );
        register_setting( 'rdr_cyber_options', 'rdr_cyber_purge_days',  array( 'sanitize_callback' => 'absint', 'default' => 90 ) );
        register_setting( 'rdr_cyber_options', 'rdr_cyber_per_page',    array( 'sanitize_callback' => 'absint', 'default' => 12 ) );
        register_setting( 'rdr_cyber_options', 'rdr_cyber_from_email',  array( 'sanitize_callback' => 'sanitize_email', 'default' => get_option('admin_email') ) );
        register_setting( 'rdr_cyber_options', 'rdr_cyber_from_name',   array( 'sanitize_callback' => 'sanitize_text_field', 'default' => get_bloginfo('name') ) );
        register_setting( 'rdr_cyber_options', 'rdr_cyber_site_url',    array( 'sanitize_callback' => 'esc_url_raw', 'default' => home_url() ) );
        /* Web Push */
        register_setting( 'rdr_cyber_options', 'rdr_cyber_push_enabled', array( 'sanitize_callback' => 'absint', 'default' => 0 ) );
        register_setting( 'rdr_cyber_options', 'rdr_cyber_push_on_collect', array( 'sanitize_callback' => 'absint', 'default' => 1 ) );
    }

    public static function sanitize_interval( $val ) {
        $val = sanitize_text_field( $val );
        if ( $val !== get_option( 'rdr_cyber_interval', 'twicedaily' ) ) {
            RDR_Cron::reschedule_collect( $val );
        }
        return $val;
    }

    /* --- Pages --- */
    public static function page_main() {
        $stats = RDR_Database::get_stats();
        include RDR_CYBER_PLUGIN_DIR . 'templates/admin-main.php';
    }

    public static function page_sources() {
        if ( isset( $_POST['rdr_save_sources'] ) && check_admin_referer( 'rdr_cyber_sources_nonce' ) ) {
            $sources = RDR_Collector::get_all_sources();
            $enabled = array();
            foreach ( $sources as $src ) {
                $enabled[ $src['id'] ] = isset( $_POST['source_' . $src['id']] ) ? 1 : 0;
            }
            update_option( 'rdr_cyber_sources_enabled', $enabled );
            echo '<div class="notice notice-success is-dismissible"><p>Sources mises à jour.</p></div>';
        }
        include RDR_CYBER_PLUGIN_DIR . 'templates/admin-sources.php';
    }

    public static function page_subscribers() {
        if ( isset( $_GET['delete_sub'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( $_GET['_wpnonce'], 'rdr_delete_sub' ) ) {
            RDR_Database::delete_subscriber( intval( $_GET['delete_sub'] ) );
            echo '<div class="notice notice-success is-dismissible"><p>Abonné supprimé.</p></div>';
        }
        $subscribers = RDR_Database::get_subscribers();
        include RDR_CYBER_PLUGIN_DIR . 'templates/admin-subscribers.php';
    }

    public static function page_settings() {
        if ( isset( $_POST['rdr_cyber_purge'] ) && check_admin_referer( 'rdr_cyber_purge_nonce' ) ) {
            $days    = get_option( 'rdr_cyber_purge_days', 90 );
            $deleted = RDR_Database::purge( $days );
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf( '%d entrée(s) supprimée(s).', $deleted ) . '</p></div>';
        }
        include RDR_CYBER_PLUGIN_DIR . 'templates/admin-settings.php';
    }
}
