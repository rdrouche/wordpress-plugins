<?php
/**
 * Plugin Name:       RDR Syntax Highlighter
 * Plugin URI:        https://rdr-it.com
 * Description:       Coloration syntaxique à la demande — chargement async, cache serveur, numéros de ligne, compatible LiteSpeed & dark/light theme.
 * Version:           1.0.0
 * Author:            Romain Drouche
 * Author URI:        https://rdr-it.com
 * License:           GPL-2.0+
 * Text Domain:       rdr-syntax-highlighter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'RDR_SH_VERSION',   '1.0.0' );
define( 'RDR_SH_DIR',       plugin_dir_path( __FILE__ ) );
define( 'RDR_SH_URL',       plugin_dir_url( __FILE__ ) );
define( 'RDR_SH_CACHE_DIR', WP_CONTENT_DIR . '/cache/rdr-syntax-highlighter/' );

/* ============================================================
   INCLUDES
   ============================================================ */
require_once RDR_SH_DIR . 'includes/class-highlighter.php';
require_once RDR_SH_DIR . 'includes/class-cache.php';
require_once RDR_SH_DIR . 'includes/ajax-handler.php';
require_once RDR_SH_DIR . 'includes/admin.php';

/* ============================================================
   INIT PLUGIN
   ============================================================ */
function rdr_sh_init() {
    // Créer le répertoire de cache si besoin
    RDR_SH_Cache::maybe_create_cache_dir();
}
add_action( 'init', 'rdr_sh_init' );

/* ============================================================
   ENQUEUE FRONTEND ASSETS
   ============================================================ */
function rdr_sh_enqueue_assets() {
    // CSS toujours chargé (styles de base des blocs code + switch)
    wp_enqueue_style(
        'rdr-sh-style',
        RDR_SH_URL . 'assets/css/rdr-sh.css',
        array(),
        RDR_SH_VERSION
    );

    // JS principal — gère le switch, le lazy-load AJAX et l'UI
    wp_enqueue_script(
        'rdr-sh-script',
        RDR_SH_URL . 'assets/js/rdr-sh.js',
        array(),
        RDR_SH_VERSION,
        true // footer
    );

    $options = get_option( 'rdr_sh_options', array() );

    // Label traduit via Polylang si dispo
    $switch_label = rdr_sh_get_label();

    wp_localize_script( 'rdr-sh-script', 'rdrSH', array(
        'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
        'nonce'          => wp_create_nonce( 'rdr_sh_nonce' ),
        'showLineNumbers'=> isset( $options['line_numbers'] ) ? (bool) $options['line_numbers'] : true,
        'switchLabel'    => $switch_label,
        'switchPosition' => isset( $options['switch_position'] ) ? $options['switch_position'] : 'floating-right',
        'isSingular'     => is_singular() ? true : false,
        'isEnabledCpt'   => rdr_sh_is_enabled_cpt() ? true : false,
    ) );
}
add_action( 'wp_enqueue_scripts', function() {
    // Toujours enqueue le CSS (pour les shortcodes en footer etc.)
    // Le JS est enqueued uniquement sur les CPT activés OU partout si shortcode/none
    $options  = get_option( 'rdr_sh_options', array() );
    $position = $options['switch_position'] ?? 'floating-right';
    // shortcode-only et none : enqueue partout (le shortcode peut être n'importe où)
    $always   = in_array( $position, array( 'shortcode-only', 'none' ), true );
    if ( $always || rdr_sh_is_enabled_cpt() || is_singular() ) {
        rdr_sh_enqueue_assets();
    }
    // CSS toujours pour les shortcodes en footer
    wp_enqueue_style( 'rdr-sh-style', RDR_SH_URL . 'assets/css/rdr-sh.css', array(), RDR_SH_VERSION );
} );


/* ============================================================
   ENQUEUE BLOCK EDITOR SCRIPT (Gutenberg — sélecteur de langue)
   ============================================================ */
function rdr_sh_enqueue_block_editor_assets() {
    wp_enqueue_script(
        'rdr-sh-block-picker',
        RDR_SH_URL . 'assets/js/block-language-picker.js',
        array( 'wp-blocks', 'wp-hooks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-i18n' ),
        RDR_SH_VERSION,
        true
    );
}
add_action( 'enqueue_block_editor_assets', 'rdr_sh_enqueue_block_editor_assets' );

/* ============================================================
   ACTIVATION / DÉSACTIVATION
   ============================================================ */

/* ============================================================
   BODY CLASS — CPT activé (pour ciblage CSS)
   ============================================================ */
add_filter( 'body_class', function( $classes ) {
    if ( rdr_sh_is_enabled_cpt() ) {
        $classes[] = 'rdr-sh-enabled-cpt';
    }
    return $classes;
} );

register_activation_hook( __FILE__, function() {
    RDR_SH_Cache::maybe_create_cache_dir();
    add_option( 'rdr_sh_options', array(
        'default_theme'  => 'tomorrow-night',
        'line_numbers'   => true,
        'switch_label'   => '🎨 Coloration',
        'switch_position'=> 'floating-right', // floating-right | floating-left | under-title | none
        'cache_ttl'      => 604800,     // 7 jours en secondes
    ) );
} );

register_deactivation_hook( __FILE__, function() {
    RDR_SH_Cache::flush_all();
} );
