<?php
/**
 * Plugin Name:       WP IT Problem
 * Plugin URI:        https://rdr-it.com
 * Description:       Publish IT problems with a scheduled answer reveal. Supports rich content, infra context, countdown and AJAX reveal.
 * Version:           1.0.6
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            RDR-IT
 * Author URI:        https://rdr-it.com
 * License:           Proprietary
 * Text Domain:       wp-it-problem
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define( 'ITPB_VERSION',     '1.0.6' );
define( 'ITPB_DB_VERSION',  '1' );
define( 'ITPB_FILE',        __FILE__ );
define( 'ITPB_DIR',         plugin_dir_path( __FILE__ ) );
define( 'ITPB_URL',         plugin_dir_url( __FILE__ ) );
define( 'ITPB_BASENAME',    plugin_basename( __FILE__ ) );

// CPT slugs — single source of truth
define( 'ITPB_CPT_PROBLEM', 'it_problem' );
define( 'ITPB_CPT_ANSWER',  'it_answer' );

// Meta keys — single source of truth
define( 'ITPB_META_REVEAL_AT',   '_itpb_reveal_at' );   // datetime string (Y-m-d H:i:s), stored on it_problem
define( 'ITPB_META_ANSWER_ID',   '_itpb_answer_id' );   // int, stored on it_problem → points to it_answer
define( 'ITPB_META_PROBLEM_ID',  '_itpb_problem_id' );  // int, stored on it_answer  → back-ref to it_problem
define( 'ITPB_META_INFRA',       '_itpb_infra' );       // json array, stored on it_problem
define( 'ITPB_META_DIFFICULTY',  '_itpb_difficulty' );  // string, stored on it_problem

// ---------------------------------------------------------------------------
// Autoloader — simple class-map based on filename convention
// All classes follow:  class-itpb-{slug}.php  →  ITPB_{Slug}
// ---------------------------------------------------------------------------

spl_autoload_register( function ( string $class ): void {
    // Only handle ITPB_ prefixed classes
    if ( strpos( $class, 'ITPB_' ) !== 0 ) {
        return;
    }

    // ITPB_Foo_Bar  →  class-itpb-foo-bar.php
    $slug = strtolower( str_replace( [ 'ITPB_', '_' ], [ '', '-' ], $class ) );
    $file = ITPB_DIR . 'includes/class-itpb-' . $slug . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
} );

// ---------------------------------------------------------------------------
// Activation / Deactivation / Uninstall hooks
// ---------------------------------------------------------------------------

register_activation_hook( ITPB_FILE, [ 'ITPB_Install', 'activate' ] );
register_deactivation_hook( ITPB_FILE, [ 'ITPB_Install', 'deactivate' ] );
// Uninstall is handled via uninstall.php (keeps bootstrap lean)

// ---------------------------------------------------------------------------
// Bootstrap — fires after all plugins are loaded
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'itpb_boot', 20 );

/**
 * Instantiate all plugin components.
 * Priority 20 → after WP Premium Access (prio 10) so WPA hooks are ready.
 */
function itpb_boot(): void {
    // i18n must be first
    ( new ITPB_I18n() )->init();

    // Core
    ( new ITPB_Cpt() )->init();
    ( new ITPB_Meta() )->init();
    ( new ITPB_Cron() )->init();

    // Front-end (skip on pure REST/CLI contexts for performance)
    if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
        ( new ITPB_Frontend() )->init();
        ( new ITPB_Shortcodes() )->init();
    }

    // REST API — always
    ( new ITPB_Api() )->init();

    // Admin
    if ( is_admin() ) {
        ( new ITPB_Admin() )->init();
    }

    // Polylang — only if Polylang is active
    if ( function_exists( 'pll_register_string' ) ) {
        ( new ITPB_Polylang() )->init();
    }

    /**
     * Fires after all ITPB components are initialised.
     * Third-party plugins (e.g. WP Premium Access gate) can hook here.
     *
     * @since 1.0.0
     */
    do_action( 'itpb_loaded' );
}

// ---------------------------------------------------------------------------
// DB upgrade on plugin update (runs on every admin request, negligible cost)
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', 'itpb_maybe_upgrade', 15 );

function itpb_maybe_upgrade(): void {
    if ( get_option( 'itpb_db_version' ) !== ITPB_DB_VERSION ) {
        ITPB_Install::run_upgrade();
    }
}

// ---------------------------------------------------------------------------
// Public helper functions — thin wrappers used across the codebase
// ---------------------------------------------------------------------------

/**
 * Return the UTC datetime at which a problem's answer is revealed.
 * Returns null if not set or invalid.
 *
 * @param  int $problem_id  Post ID of the it_problem.
 * @return \DateTimeImmutable|null
 */
function itpb_get_reveal_at( int $problem_id ): ?\DateTimeImmutable {
    $raw = get_post_meta( $problem_id, ITPB_META_REVEAL_AT, true );

    if ( ! $raw ) {
        return null;
    }

    try {
        return new \DateTimeImmutable( $raw, new \DateTimeZone( 'UTC' ) );
    } catch ( \Exception ) {
        return null;
    }
}

/**
 * Whether the answer for a given problem is revealed right now.
 *
 * @param  int $problem_id  Post ID of the it_problem.
 * @return bool
 */
function itpb_is_revealed( int $problem_id ): bool {
    $reveal_at = itpb_get_reveal_at( $problem_id );

    if ( $reveal_at === null ) {
        return false;
    }

    $now = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );

    return $now >= $reveal_at;
}

/**
 * Return the it_answer post linked to an it_problem, or null.
 *
 * @param  int $problem_id
 * @return \WP_Post|null
 */
function itpb_get_answer_post( int $problem_id ): ?\WP_Post {
    $answer_id = (int) get_post_meta( $problem_id, ITPB_META_ANSWER_ID, true );

    if ( ! $answer_id ) {
        return null;
    }

    $post = get_post( $answer_id );

    return ( $post instanceof \WP_Post && $post->post_type === ITPB_CPT_ANSWER )
        ? $post
        : null;
}

/**
 * Return the it_problem post linked to an it_answer, or null.
 *
 * @param  int $answer_id
 * @return \WP_Post|null
 */
function itpb_get_problem_post( int $answer_id ): ?\WP_Post {
    $problem_id = (int) get_post_meta( $answer_id, ITPB_META_PROBLEM_ID, true );

    if ( ! $problem_id ) {
        return null;
    }

    $post = get_post( $problem_id );

    return ( $post instanceof \WP_Post && $post->post_type === ITPB_CPT_PROBLEM )
        ? $post
        : null;
}
