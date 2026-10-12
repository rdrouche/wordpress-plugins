<?php
/**
 * Plugin Name: WP Tuto Blocks
 * Plugin URI:  https://github.com/your-username/wp-tuto-blocks
 * Description: Blocs Gutenberg pour tutoriels : Spoiler, Badge pill, Markdown, Mermaid, Visibilité conditionnelle.
 * Version:     1.3.0
 * Author:      Votre Nom
 * License:     GPL-2.0-or-later
 * Text Domain: wp-tuto-blocks
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_TUTO_BLOCKS_VERSION', '1.3.0' );
define( 'WP_TUTO_BLOCKS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_TUTO_BLOCKS_URL', plugin_dir_url( __FILE__ ) );

require_once WP_TUTO_BLOCKS_DIR . 'includes/block-visibility.php';

/**
 * Enregistrement des blocs
 */
function wp_tuto_blocks_register() {
	register_block_type( WP_TUTO_BLOCKS_DIR . 'blocks/spoiler' );
	register_block_type( WP_TUTO_BLOCKS_DIR . 'blocks/markdown' );
	register_block_type( WP_TUTO_BLOCKS_DIR . 'blocks/mermaid' );
}
add_action( 'init', 'wp_tuto_blocks_register' );

/**
 * Assets éditeur
 */
function wp_tuto_blocks_editor_assets() {
	wp_enqueue_script(
		'wp-tuto-blocks-badge-format',
		WP_TUTO_BLOCKS_URL . 'formats/badge-format.js',
		array( 'wp-rich-text', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n' ),
		WP_TUTO_BLOCKS_VERSION
	);
	wp_enqueue_script(
		'wp-tuto-blocks-visibility',
		WP_TUTO_BLOCKS_URL . 'formats/block-visibility.js',
		array( 'wp-hooks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-compose', 'wp-i18n' ),
		WP_TUTO_BLOCKS_VERSION
	);
	wp_enqueue_style(
		'wp-tuto-blocks-badge',
		WP_TUTO_BLOCKS_URL . 'assets/badge.css',
		array(),
		WP_TUTO_BLOCKS_VERSION
	);
}
add_action( 'enqueue_block_editor_assets', 'wp_tuto_blocks_editor_assets' );

/**
 * Assets front-end
 */
function wp_tuto_blocks_frontend_assets() {
	wp_enqueue_script(
		'wp-tuto-blocks-frontend',
		WP_TUTO_BLOCKS_URL . 'assets/frontend.js',
		array(),
		WP_TUTO_BLOCKS_VERSION,
		true
	);
	wp_enqueue_style(
		'wp-tuto-blocks-badge',
		WP_TUTO_BLOCKS_URL . 'assets/badge.css',
		array(),
		WP_TUTO_BLOCKS_VERSION
	);
	wp_enqueue_style(
		'wp-tuto-blocks-visibility',
		WP_TUTO_BLOCKS_URL . 'assets/visibility.css',
		array(),
		WP_TUTO_BLOCKS_VERSION
	);
}
add_action( 'wp_enqueue_scripts', 'wp_tuto_blocks_frontend_assets' );
