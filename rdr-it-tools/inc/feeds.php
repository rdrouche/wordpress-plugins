<?php
/**
 * RDR-IT Tools — Feeds v1
 *
 * Injecte dynamiquement les CPT publics dans le flux RSS principal.
 * La liste des CPT inclus est configurable via wp_options.
 *
 * Option : rdrit_feed_cpts  (CSV des slugs actifs, ex: "post,blog,domotique")
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'request', 'rdrit_feed_with_cpt' );
function rdrit_feed_with_cpt( array $qv ): array {
    if ( ! isset( $qv['feed'] ) || isset( $qv['post_type'] ) ) return $qv;

    $saved = get_option( 'rdrit_feed_cpts', '' );

    if ( $saved !== '' ) {
        $cpts = array_filter( array_map( 'trim', explode( ',', $saved ) ) );
        if ( ! empty( $cpts ) ) {
            $qv['post_type'] = $cpts;
        }
    }

    return $qv;
}

/**
 * Polylang — forcer la langue du feed selon l'URL.
 *
 * Polylang détecte la langue pour les pages normales mais pas
 * toujours pour les feeds (/en/feed/, /fr/feed/).
 * Ce hook lit la langue courante Polylang et la force sur la query.
 */
add_action( 'pre_get_posts', 'rdrit_feed_polylang_lang', 1 );
function rdrit_feed_polylang_lang( WP_Query $query ): void {
    if ( ! $query->is_feed() ) return;
    if ( ! function_exists( 'pll_current_language' ) ) return;

    $lang = pll_current_language();
    if ( ! $lang ) return;

    $query->set( 'lang', $lang );
}

/**
 * Retourne tous les CPT publics eligibles pour le feed.
 * Exclut les types internes WP.
 */
function rdrit_feed_get_eligible_cpts(): array {
    $excluded = array(
        'attachment', 'revision', 'nav_menu_item', 'custom_css',
        'customize_changeset', 'wp_block', 'wp_template',
        'wp_template_part', 'wp_navigation', 'wp_font_face', 'wp_font_family',
    );

    $all = get_post_types( array( 'public' => true ), 'objects' );
    $result = array();

    foreach ( $all as $slug => $obj ) {
        if ( in_array( $slug, $excluded, true ) ) continue;
        $result[ $slug ] = $obj;
    }

    return $result;
}
