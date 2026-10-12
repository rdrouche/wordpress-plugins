<?php
/**
 * Module : RDR SEO Tools — v1.1.0
 *
 * @package RDR_IT_Suite\Modules\SeoTools
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rdr_suite_module_loaded_rdr-seo-tools', static function (): void {
    $s = static fn( string $key, mixed $default = null ) => RDR_Module_Settings::get( 'rdr-seo-tools', $key, $default );

    // ── Masquer la version WP ────────────────────────────────────────
    if ( '1' === $s( 'remove_version', '1' ) ) {
        add_filter( 'the_generator',   '__return_empty_string' );
        remove_action( 'wp_head',      'wp_generator' );

        add_filter( 'script_loader_src', static fn( string $src ) => remove_query_arg( 'ver', $src ), 15 );
        add_filter( 'style_loader_src',  static fn( string $src ) => remove_query_arg( 'ver', $src ), 15 );
    }

    // ── Noindex recherche ────────────────────────────────────────────
    if ( '1' === $s( 'noindex_search', '1' ) ) {
        add_action( 'wp_head', static function (): void {
            if ( is_search() ) {
                echo '<meta name="robots" content="noindex, follow">' . "\n";
            }
        } );
    }

    // ── Open Graph image par défaut ──────────────────────────────────
    $og_image = $s( 'og_default_image', '' );
    if ( $og_image ) {
        add_action( 'wp_head', static function () use ( $og_image ): void {
            if ( ! is_singular() ) return;
            global $post;
            if ( has_post_thumbnail( $post ) ) return;
            echo '<meta property="og:image" content="' . esc_url( $og_image ) . '">' . "\n";
        } );
    }

    // ── Meta author ──────────────────────────────────────────────────
    $author = $s( 'meta_author', '' );
    if ( $author ) {
        add_action( 'wp_head', static function () use ( $author ): void {
            echo '<meta name="author" content="' . esc_attr( $author ) . '">' . "\n";
        } );
    }

    // ── Canonical sans www ───────────────────────────────────────────
    if ( '1' === $s( 'canonical_www', '0' ) ) {
        add_action( 'template_redirect', static function (): void {
            if ( ! isset( $_SERVER['HTTP_HOST'] ) ) return;
            if ( str_starts_with( $_SERVER['HTTP_HOST'], 'www.' ) ) {
                $url = 'https://' . substr( $_SERVER['HTTP_HOST'], 4 ) . $_SERVER['REQUEST_URI'];
                wp_redirect( $url, 301 );
                exit;
            }
        } );
    }
} );
