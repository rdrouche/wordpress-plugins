<?php
/**
 * RDR-IT Glossaire — Schema.org
 * DefinedTermSet sur /glossaire/
 * DefinedTerm    sur /glossaire/{slug}/
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   PAGE INDEX /glossaire/ → DefinedTermSet
   ============================================================ */
add_action( 'wp_head', 'rdrg_schema_index', 5 );
function rdrg_schema_index() {
    if ( ! get_query_var( 'rdrg_glossaire_index' ) ) return;

    $lang      = function_exists( 'pll_current_language' ) ? pll_current_language() : get_bloginfo( 'language' );
    $site_name = get_bloginfo( 'name' );
    $index_url = home_url( '/glossaire/' );
    $count     = wp_count_posts( RDRG_CPT )->publish;

    $schema = [
        '@context'    => 'https://schema.org',
        '@type'       => 'DefinedTermSet',
        '@id'         => $index_url . '#termset',
        'name'        => __( 'Glossaire IT', 'rdr-glossaire' ) . ' — ' . $site_name,
        'description' => sprintf(
            _n(
                'Glossaire de %d terme technique IT : réseau, système, virtualisation, sécurité.',
                'Glossaire de %d termes techniques IT : réseau, système, virtualisation, sécurité.',
                $count,
                'rdr-glossaire'
            ),
            $count
        ),
        'url'        => $index_url,
        'inLanguage' => $lang,
        'publisher'  => [
            '@type' => 'Organization',
            'name'  => $site_name,
            'url'   => home_url( '/' ),
        ],
    ];

    rdrg_print_schema( $schema );
}

/* ============================================================
   PAGE SINGLE /glossaire/{slug}/ → DefinedTerm
   ============================================================ */
add_action( 'wp_head', 'rdrg_schema_single', 5 );
function rdrg_schema_single() {
    if ( ! is_singular( RDRG_CPT ) ) return;

    global $post;

    $lang        = function_exists( 'pll_current_language' ) ? pll_current_language() : get_bloginfo( 'language' );
    $title       = get_the_title( $post->ID );
    $term_url    = get_permalink( $post->ID );
    $index_url   = home_url( '/glossaire/' );
    $aliases_raw = get_post_meta( $post->ID, '_rdrg_aliases', true );
    $link        = get_post_meta( $post->ID, '_rdrg_link', true );

    // Description : extrait en priorité, sinon début du contenu
    $description = $post->post_excerpt
        ?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '.' );

    $schema = [
        '@context'         => 'https://schema.org',
        '@type'            => 'DefinedTerm',
        '@id'              => $term_url . '#term',
        'name'             => $title,
        'description'      => $description,
        'url'              => $term_url,
        'inDefinedTermSet' => [
            '@type' => 'DefinedTermSet',
            '@id'   => $index_url . '#termset',
            'name'  => __( 'Glossaire IT', 'rdr-glossaire' ) . ' — ' . get_bloginfo( 'name' ),
            'url'   => $index_url,
        ],
        'inLanguage'       => $lang,
    ];

    // alternateName depuis les alias (exclure le titre lui-même)
    if ( $aliases_raw ) {
        $aliases = array_values( array_filter(
            array_map( 'trim', explode( ',', $aliases_raw ) ),
            fn( $a ) => $a !== '' && strtolower( $a ) !== strtolower( $title )
        ) );
        if ( count( $aliases ) === 1 ) {
            $schema['alternateName'] = $aliases[0];
        } elseif ( count( $aliases ) > 1 ) {
            $schema['alternateName'] = $aliases;
        }
    }

    // sameAs = URL de l'article associé
    if ( $link ) {
        $schema['sameAs'] = $link;
    }

    rdrg_print_schema( $schema );
}

/* ============================================================
   DÉSACTIVER LE SCHEMA AUTO RANK MATH SUR NOS PAGES
   (éviter les doublons JSON-LD)
   ============================================================ */
add_filter( 'rank_math/schema/display', function( $display ) {
    if ( is_singular( RDRG_CPT ) || get_query_var( 'rdrg_glossaire_index' ) ) {
        return false;
    }
    return $display;
} );

/* ============================================================
   HELPER
   ============================================================ */
function rdrg_print_schema( array $schema ) {
    echo '<script type="application/ld+json">'
        . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT )
        . '</script>' . "\n";
}
