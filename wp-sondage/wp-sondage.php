<?php
/**
 * Plugin Name: WP Sondage
 * Plugin URI:  https://rdr-it.com/
 * Description: Plugin de sondage avancé : radio/checkbox, sous-questions conditionnelles, antiflood RGPD (sans cookie), lazy-loading, thème sombre/clair automatique, Polylang/WPML, reset admin.
 * Version:     1.7.0
 * Author:      WP Sondage
 * Text Domain: wp-sondage
 * Domain Path: /languages
 * License:     GPL-2.0+
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WPS_VERSION',    '1.7.0' );
define( 'WPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPS_PLUGIN_FILE', __FILE__ );

/* ── Autoload — ordre critique : les dépendances avant leurs utilisateurs ── */
require_once WPS_PLUGIN_DIR . 'includes/class-wps-cache.php';      // 0. avant tout
require_once WPS_PLUGIN_DIR . 'includes/class-wps-install.php';
require_once WPS_PLUGIN_DIR . 'includes/class-wps-post-type.php';
require_once WPS_PLUGIN_DIR . 'includes/class-wps-taxonomy.php';
require_once WPS_PLUGIN_DIR . 'includes/class-wps-antiflood.php';    // 1. avant ajax
require_once WPS_PLUGIN_DIR . 'includes/class-wps-ajax.php';         // 2. avant sync
require_once WPS_PLUGIN_DIR . 'includes/class-wps-sync.php';         // 3. avant metaboxes
require_once WPS_PLUGIN_DIR . 'includes/class-wps-metaboxes.php';    // 4. utilise WPS_Sync
require_once WPS_PLUGIN_DIR . 'includes/class-wps-shortcodes.php';
require_once WPS_PLUGIN_DIR . 'includes/class-wps-widget.php';
require_once WPS_PLUGIN_DIR . 'includes/class-wps-polylang.php';
require_once WPS_PLUGIN_DIR . 'includes/class-wps-archive-page.php';
require_once WPS_PLUGIN_DIR . 'admin/class-wps-admin.php';
require_once WPS_PLUGIN_DIR . 'admin/class-wps-stats.php';
require_once WPS_PLUGIN_DIR . 'public/class-wps-public.php';

register_activation_hook(  __FILE__, [ 'WPS_Install', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WPS_Install', 'deactivate' ] );

function wps_init() {
    // Compatibilité cache — doit s'initialiser en premier
    WPS_Cache::init();

    // Migration DB au runtime (idempotent — s'exécute uniquement si la version a changé)
    WPS_Install::maybe_upgrade();

    WPS_Post_Type::init();
    WPS_Taxonomy::init();
    WPS_Metaboxes::init();
    WPS_Ajax::init();
    WPS_Sync::init();
    WPS_Shortcodes::init();
    WPS_Admin::init();
    WPS_Stats::init();
    WPS_Archive_Page::init();
    WPS_Public::init();
    WPS_Polylang::init();
    load_plugin_textdomain( 'wp-sondage', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'wps_init' );

/** Chart.js uniquement sur la page de stats admin */
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( strpos( $hook, 'wps_stats' ) === false ) return;
    wp_enqueue_script( 'chartjs', 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js', [], '4.4.3', true );
} );

/** JS archive page — chargé uniquement sur la page archive configurée */
add_action( 'wp_enqueue_scripts', function () {
    if ( ! class_exists( 'WPS_Archive_Page' ) ) return;
    $page_id = WPS_Archive_Page::get_archive_page_id();
    if ( ! $page_id || ! is_page( $page_id ) ) return;
    wp_enqueue_script(
        'wps-archive',
        WPS_PLUGIN_URL . 'public/js/wps-archive.js',
        [ 'wps-public' ],  // dépend de wps-public → wpsData disponible
        WPS_VERSION, true
    );
} );

/**
 * Helper global : question traduite via filtre (Polylang/WPML).
 */
function wps_question( int $poll_id ): string {
    $q = (string) get_post_meta( $poll_id, '_wps_question', true );
    return (string) apply_filters( 'wps_poll_question', $q, $poll_id );
}

/**
 * Helper global : label de choix traduit via filtre.
 */
function wps_choice_label( string $label, int $choice_id ): string {
    return (string) apply_filters( 'wps_choice_label', $label, $choice_id );
}
