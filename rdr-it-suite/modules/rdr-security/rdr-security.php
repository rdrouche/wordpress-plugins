<?php
/**
 * Module : RDR Security — v1.1.0
 *
 * @package RDR_IT_Suite\Modules\Security
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rdr_suite_module_loaded_rdr-security', static function (): void {
    $s = static fn( string $key, mixed $default = null ) => RDR_Module_Settings::get( 'rdr-security', $key, $default );

    // ── Masquer version WP ───────────────────────────────────────────
    if ( '1' === $s( 'hide_wp_version', '1' ) ) {
        add_filter( 'the_generator', '__return_empty_string' );
        remove_action( 'wp_head', 'wp_generator' );
    }

    // ── X-Powered-By ────────────────────────────────────────────────
    if ( '1' === $s( 'remove_x_powered', '1' ) ) {
        add_action( 'send_headers', static function (): void {
            if ( function_exists( 'header_remove' ) ) {
                header_remove( 'X-Powered-By' );
            }
        } );
    }

    // ── Erreurs login génériques ─────────────────────────────────────
    if ( '1' === $s( 'login_error_generic', '1' ) ) {
        add_filter( 'login_errors', static fn() =>
            __( 'Identifiants incorrects. Veuillez réessayer.', 'rdr-it-suite' )
        );
    }

    // ── XML-RPC ──────────────────────────────────────────────────────
    if ( '1' === $s( 'disable_xmlrpc', '1' ) ) {
        add_filter( 'xmlrpc_enabled', '__return_false' );
        add_filter( 'xmlrpc_methods', static fn() => [] );
    }

    // ── REST API anonyme ─────────────────────────────────────────────
    if ( '1' === $s( 'disable_rest_anon', '0' ) ) {
        add_filter( 'rest_authentication_errors', static function ( mixed $result ): mixed {
            if ( ! is_wp_error( $result ) && ! is_user_logged_in() ) {
                return new WP_Error(
                    'rest_not_logged_in',
                    __( 'Authentification requise.', 'rdr-it-suite' ),
                    [ 'status' => 401 ]
                );
            }
            return $result;
        } );
    }

    // ── Éditeur de fichiers admin ────────────────────────────────────
    if ( '1' === $s( 'disable_file_edit', '0' ) ) {
        if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
            define( 'DISALLOW_FILE_EDIT', true );
        }
    }
} );
