<?php
/**
 * Plugin Name: RDR Banner Display
 * Plugin URI:  https://rdr-it.com
 * Description: Banner display system with adblock detection, bot filtering, campaign management, and statistics. Use shortcode [rdr-dif-ban id="XX"].
 * Version:     2.7.6
 * Author:      Romain Drouche
 * Author URI:  https://rdr-it.com
 * License:     GPL-2.0-or-later
 * Text Domain: rdr-banner-display
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ────────────────────────────────────────────────────────────────
define( 'RDR_BD_VERSION',     '2.7.6' );
define( 'RDR_BD_FILE',        __FILE__ );
define( 'RDR_BD_DIR',         plugin_dir_path( __FILE__ ) );
define( 'RDR_BD_URL',         plugin_dir_url( __FILE__ ) );
define( 'RDR_BD_BASENAME',    plugin_basename( __FILE__ ) );

// ── Autoload / includes ───────────────────────────────────────────────────────
require_once RDR_BD_DIR . 'includes/class-rdr-bd-activator.php';
require_once RDR_BD_DIR . 'includes/class-rdr-bd-post-type.php';
require_once RDR_BD_DIR . 'includes/class-rdr-bd-shortcode.php';
require_once RDR_BD_DIR . 'includes/class-rdr-bd-ajax.php';
require_once RDR_BD_DIR . 'includes/class-rdr-bd-stats.php';
require_once RDR_BD_DIR . 'includes/class-rdr-bd-migrations.php';
require_once RDR_BD_DIR . 'includes/class-rdr-bd-queue.php';
require_once RDR_BD_DIR . 'includes/class-rdr-bd-campaign.php';
require_once RDR_BD_DIR . 'includes/class-rdr-bd-capabilities.php';
require_once RDR_BD_DIR . 'includes/helpers.php';
require_once RDR_BD_DIR . 'admin/class-rdr-bd-admin.php';
require_once RDR_BD_DIR . 'includes/admin-ajax-extra.php';
require_once RDR_BD_DIR . 'includes/css-vars.php';

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__,   [ 'RDR_BD_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'RDR_BD_Activator', 'deactivate' ] );

// ── Bootstrap ─────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', 'rdr_bd_init' );

// Run DB migrations early (before rdr_bd_init which uses the tables)
add_action( 'plugins_loaded', [ 'RDR_BD_Migrations', 'run' ], 1 );

function rdr_bd_init(): void {
    // Post type & taxonomy
    ( new RDR_BD_Post_Type() )->init();

    // Shortcode
    ( new RDR_BD_Shortcode() )->init();

    // AJAX handlers (front + admin)
    ( new RDR_BD_Ajax() )->init();

    // Statistics
    ( new RDR_BD_Stats() )->init();

    // Redis stats queue (RPUSH per event, cron flushes to DB every 5 min)
    ( new RDR_BD_Queue() )->init();

    // Campaigns
    ( new RDR_BD_Campaign() )->init();

    // Capabilities
    ( new RDR_BD_Capabilities() )->init();

    // Admin
    if ( is_admin() ) {
        ( new RDR_BD_Admin() )->init();
    }

    // Public assets
    add_action( 'wp_enqueue_scripts', 'rdr_bd_enqueue_public' );
}

/**
 * Enqueue public-facing CSS and JS (no jQuery).
 */
function rdr_bd_enqueue_public(): void {
    wp_enqueue_style(
        'rdr-bd-public',
        RDR_BD_URL . 'public/css/rdr-bd-public.css',
        [],
        RDR_BD_VERSION
    );

    wp_enqueue_script(
        'rdr-bd-public',
        RDR_BD_URL . 'public/js/rdr-bd-public.js',
        [],          // no jQuery dependency
        RDR_BD_VERSION,
        true         // footer
    );

    wp_localize_script( 'rdr-bd-public', 'rdrBD', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'rdr_bd_load' ),
        // Delay (ms) before AJAX fires, to let adblock detection scripts initialise.
        // Bots: delay is ignored on the client side.
        'delay'   => (int) get_option( 'rdr_bd_adblock_delay', 2500 ),
    ] );
}
