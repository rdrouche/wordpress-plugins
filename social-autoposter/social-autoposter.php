<?php
/**
 * Plugin Name: Social AutoPoster
 * Plugin URI:  https://example.com/social-autoposter
 * Description: Publiez automatiquement vos articles WordPress sur LinkedIn et Facebook avec planification et messages personnalisés.
 * Version:     1.0.0
 * Author:      Votre Nom
 * License:     GPL-2.0+
 * Text Domain: social-autoposter
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SAP_VERSION',    '1.0.0' );
define( 'SAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SAP_TABLE',      'sap_scheduled_posts' );

// ─── Load modules ────────────────────────────────────────────────────────────
require_once SAP_PLUGIN_DIR . 'includes/class-sap-db.php';
require_once SAP_PLUGIN_DIR . 'includes/class-sap-scheduler.php';
require_once SAP_PLUGIN_DIR . 'includes/class-sap-linkedin.php';
require_once SAP_PLUGIN_DIR . 'includes/class-sap-facebook.php';
require_once SAP_PLUGIN_DIR . 'admin/class-sap-admin.php';

// ─── Activation / Deactivation ───────────────────────────────────────────────
register_activation_hook( __FILE__,   [ 'SAP_DB', 'create_table' ] );
register_deactivation_hook( __FILE__, [ 'SAP_Scheduler', 'clear_all_events' ] );

// ─── Boot ────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    SAP_Admin::init();
    SAP_Scheduler::init();
} );
