<?php
/**
 * Plugin Name:       RDR-IT Suite
 * Plugin URI:        https://rdr-it.com
 * Description:       Meta plugin RDR-IT — Gestionnaire centralisé de sous-plugins (modules) pour WordPress. Activez uniquement ce dont vous avez besoin.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Romain Drouche
 * Author URI:        https://rdr-it.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rdr-it-suite
 * Domain Path:       /languages
 *
 * @package RDR_IT_Suite
 */

defined( 'ABSPATH' ) || exit;

// ── Constantes ────────────────────────────────────────────────────────────────
define( 'RDR_SUITE_VERSION',   '1.2.0' );
define( 'RDR_SUITE_FILE',      __FILE__ );
define( 'RDR_SUITE_DIR',       plugin_dir_path( __FILE__ ) );
define( 'RDR_SUITE_URL',       plugin_dir_url( __FILE__ ) );
define( 'RDR_SUITE_MODULES',   RDR_SUITE_DIR . 'modules/' );
define( 'RDR_SUITE_OPTION',    'rdr_suite_active_modules' );

// ── Chargement du core ────────────────────────────────────────────────────────
require_once RDR_SUITE_DIR . 'includes/class-rdr-module-loader.php';
require_once RDR_SUITE_DIR . 'includes/class-rdr-module-registry.php';
require_once RDR_SUITE_DIR . 'includes/class-rdr-module-settings.php';
require_once RDR_SUITE_DIR . 'admin/class-rdr-admin.php';

/**
 * Point d'entrée principal.
 *
 * @return RDR_Module_Loader
 */
function rdr_suite(): RDR_Module_Loader {
    static $instance = null;
    if ( null === $instance ) {
        $instance = new RDR_Module_Loader();
    }
    return $instance;
}

// Démarrage
add_action( 'plugins_loaded', static function (): void {
    rdr_suite()->init();

    if ( is_admin() ) {
        ( new RDR_Admin() )->init();
    }
}, 5 );

// Activation / désactivation
register_activation_hook(   __FILE__, [ 'RDR_Module_Loader', 'on_activate'   ] );
register_deactivation_hook( __FILE__, [ 'RDR_Module_Loader', 'on_deactivate' ] );
