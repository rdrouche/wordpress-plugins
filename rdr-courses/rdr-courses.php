<?php
/**
 * Plugin Name: RDR-IT Courses
 * Plugin URI:  https://rdr-it.com
 * Description: Gestion simplifiee des cours pour RDR-IT. CPT, navigation auto, cards, shortcodes.
 * Version:     1.1.0
 * Author:      RDR-IT
 * Text Domain: rdr-courses
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RDRC_VERSION', '1.1.0' );
define( 'RDRC_DIR',     plugin_dir_path( __FILE__ ) );
define( 'RDRC_URL',     plugin_dir_url( __FILE__ ) );

require_once RDRC_DIR . 'includes/cpt.php';
require_once RDRC_DIR . 'includes/meta-boxes.php';
require_once RDRC_DIR . 'includes/resources.php';
require_once RDRC_DIR . 'includes/progress.php';
require_once RDRC_DIR . 'includes/i18n.php';
require_once RDRC_DIR . 'includes/progress-bar.php';
require_once RDRC_DIR . 'includes/navigation.php';
require_once RDRC_DIR . 'includes/shortcodes.php';
require_once RDRC_DIR . 'includes/widgets.php';
require_once RDRC_DIR . 'includes/course-builder.php';
require_once RDRC_DIR . 'includes/rewrite.php';

/**
 * Enqueue front assets — priorité 5 pour être avant progress.php (priorité 10 par défaut)
 * wp_localize_script doit être appelé APRÈS wp_enqueue_script sur le même handle
 */
add_action( 'wp_enqueue_scripts', function() {
    // Toujours ENREGISTRER : le shortcode [rdrc_progress_bar] peut vivre
    // hors page cours (widget de sidebar) et doit pouvoir les demander.
    wp_register_style(  'rdr-courses', RDRC_URL . 'assets/css/courses.css', [], RDRC_VERSION );
    wp_register_script( 'rdr-courses', RDRC_URL . 'assets/js/courses.js', [], RDRC_VERSION, true );

    // Donnees de synchronisation disponibles PARTOUT ou le script est charge
    // (page cours, lecon, mais aussi page "Mes formations" via shortcode).
    wp_localize_script( 'rdr-courses', 'rdrcSync', [
        'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'rdrc_progress' ),
        'loggedIn' => is_user_logged_in(),
    ] );

    if ( ! rdrc_is_course_page() ) return;
    wp_enqueue_style(  'rdr-courses' );
    wp_enqueue_script( 'rdr-courses' );
}, 5 ); // priorité 5 — avant le wp_localize_script de progress.php (priorité 10)

/**
 * Helper : est-on sur une page cours/leçon ?
 */
function rdrc_is_course_page() {
    return is_singular('rdrc_lesson')
        || is_singular('rdrc_course')
        || is_post_type_archive('rdrc_course')
        || is_tax(['rdrc_cat','rdrc_level']);
}

register_activation_hook( __FILE__, function() {
    rdrc_register_cpt();
    flush_rewrite_rules();
    update_option('rdrc_flush_needed', 1);
} );
register_deactivation_hook( __FILE__, function() {
    wp_clear_scheduled_hook('rdrc_cleanup_progress');
    flush_rewrite_rules();
});
