<?php
/**
 * Module : RDR Performance — v1.1.0
 *
 * @package RDR_IT_Suite\Modules\Performance
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rdr_suite_module_loaded_rdr-performance', static function (): void {
    $s = static fn( string $key, mixed $default = null ) => RDR_Module_Settings::get( 'rdr-performance', $key, $default );

    // ── Emoji ────────────────────────────────────────────────────────
    if ( '1' === $s( 'disable_emoji', '1' ) ) {
        add_action( 'init', static function (): void {
            remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
            remove_action( 'admin_print_scripts', 'print_emoji_detection_script'    );
            remove_action( 'wp_print_styles',     'print_emoji_styles'              );
            remove_action( 'admin_print_styles',  'print_emoji_styles'              );
            remove_filter( 'the_content_feed',    'wp_staticize_emoji'              );
            remove_filter( 'comment_text_rss',    'wp_staticize_emoji'              );
            remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email'    );
        } );
    }

    // ── Embeds ───────────────────────────────────────────────────────
    if ( '1' === $s( 'disable_embeds', '0' ) ) {
        add_action( 'init', static function (): void {
            remove_action( 'rest_api_init',    'wp_oembed_register_route'           );
            remove_filter( 'oembed_dataparse', 'wp_filter_oembed_result', 10        );
            remove_action( 'wp_head',          'wp_oembed_add_discovery_links'      );
            remove_action( 'wp_head',          'wp_oembed_add_host_js'              );
            add_filter( 'embed_oembed_discover', '__return_false'                   );
        } );
    }

    // ── Query strings assets ─────────────────────────────────────────
    if ( '1' === $s( 'remove_query_string', '1' ) ) {
        $strip = static fn( string $src ) => strpos( $src, '?ver=' ) !== false ? remove_query_arg( 'ver', $src ) : $src;
        add_filter( 'script_loader_src', $strip, 15 );
        add_filter( 'style_loader_src',  $strip, 15 );
    }

    // ── Lazy-load iframes ────────────────────────────────────────────
    if ( '1' === $s( 'lazy_load_iframes', '1' ) ) {
        add_filter( 'the_content', static function ( string $content ): string {
            return preg_replace( '/<iframe(?![^>]*loading=)/i', '<iframe loading="lazy"', $content );
        } );
    }

    // ── DNS prefetch ─────────────────────────────────────────────────
    $prefetch_raw = $s( 'dns_prefetch', '' );
    if ( $prefetch_raw ) {
        $domains = array_filter( array_map( 'trim', explode( "\n", $prefetch_raw ) ) );
        add_action( 'wp_head', static function () use ( $domains ): void {
            foreach ( $domains as $domain ) {
                echo '<link rel="dns-prefetch" href="' . esc_attr( $domain ) . '">' . "\n";
            }
        }, 1 );
    }
} );
