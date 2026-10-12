<?php
/**
 * Plugin Name: WP Markdown Exporter
 * Plugin URI:  https://github.com/your-repo/wp-markdown-exporter
 * Description: Export any post type (CPT inclus) vers Markdown depuis l'administration WordPress.
 * Version:     1.0.0
 * Author:      Your Name
 * License:     GPL-2.0+
 * Text Domain: wp-markdown-exporter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPME_VERSION', '1.0.0' );
define( 'WPME_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPME_URL', plugin_dir_url( __FILE__ ) );

require_once WPME_DIR . 'includes/class-html-to-markdown.php';
require_once WPME_DIR . 'includes/class-post-exporter.php';
require_once WPME_DIR . 'includes/class-admin-page.php';
require_once WPME_DIR . 'includes/class-bulk-export.php';
require_once WPME_DIR . 'includes/class-row-action.php';

add_action( 'plugins_loaded', [ 'WPME_Admin_Page', 'init' ] );
add_action( 'plugins_loaded', [ 'WPME_Bulk_Export', 'init' ] );
add_action( 'plugins_loaded', [ 'WPME_Row_Action', 'init' ] );
