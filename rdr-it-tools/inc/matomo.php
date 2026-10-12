<?php
/**
 * RDR-IT Tools — Matomo v2
 *
 * Injecte le code de tracking Matomo dans le footer.
 * Logique par rôle : si le rôle est coché (switch ON) → pas de tracking.
 * Les visiteurs non connectés sont toujours trackés.
 *
 * Options WordPress :
 *   rdrit_matomo_enabled        bool    Active l'injection
 *   rdrit_matomo_code           string  Code JS Matomo (sans balises <script>)
 *   rdrit_matomo_disabled_roles string  JSON array des rôles désactivés
 *                                       ex: ["administrator","editor"]
 *
 * Intégration plugin cookie :
 *   update_option('rdrit_matomo_enabled', 0)
 *   add_filter('rdrit_matomo_inject', '__return_false')
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_footer', 'rdrit_matomo_inject', 99 );
function rdrit_matomo_inject() {
    if ( ! (bool) get_option( 'rdrit_matomo_enabled', 0 ) ) return;

    $code = trim( get_option( 'rdrit_matomo_code', '' ) );
    if ( ! $code ) return;

    // Rôle désactivé → pas de tracking
    if ( rdrit_role_is_disabled( 'matomo' ) ) return;

    // Filtre pour plugin cookie tiers
    if ( ! apply_filters( 'rdrit_matomo_inject', true ) ) return;

    $code = preg_replace( '#<script[^>]*>#i', '', $code );
    $code = preg_replace( '#</script>#i',     '', $code );
    $code = trim( $code );

    echo "\n<!-- Matomo — RDR-IT Tools -->\n";
    echo "<script>\n" . $code . "\n</script>\n";
    echo "<!-- End Matomo -->\n";
}

/* ============================================================
   EXCLUSION CACHE LITESPEED pour les utilisateurs trackés/non-trackés
   ============================================================ */
add_action( 'wp_head', 'rdrit_matomo_litespeed_no_cache', 1 );
function rdrit_matomo_litespeed_no_cache() {
    if ( ! (bool) get_option( 'rdrit_matomo_enabled', 0 ) ) return;
    if ( ! is_user_logged_in() ) return;

    $raw   = get_option( 'rdrit_matomo_disabled_roles', '[]' );
    $roles = json_decode( $raw, true );
    if ( ! is_array( $roles ) || empty( $roles ) ) return;

    $user = wp_get_current_user();
    foreach ( (array) $user->roles as $role ) {
        if ( in_array( $role, $roles, true ) ) {
            do_action( 'litespeed_control_set_nocache', 'rdrit_matomo_role' );
            if ( ! headers_sent() ) {
                header( 'Cache-Control: no-store, no-cache, must-revalidate' );
            }
            return;
        }
    }
}
