<?php
/**
 * RDR-IT Tools — Tax Manager v2
 *
 * Options par taxonomie :
 *   rdrit_tax_{slug}_enabled    bool
 *   rdrit_tax_{slug}_position   top|bottom
 *   rdrit_tax_{slug}_icon       string
 *   rdrit_tax_{slug}_style      solid|pill|outline|flat|mono
 *   rdrit_tax_{slug}_color_mode auto|accent|gray|custom
 *   rdrit_tax_{slug}_color      #rrggbb
 *   rdrit_tax_{slug}_sep_type   none|space|hr|dashed
 *   rdrit_tax_{slug}_sep_px     int (margin-bottom en px, defaut 8)
 *
 * Options ordre de zones (slugs taxes + _share + _related separes par virgule) :
 *   rdrit_order_top             string
 *   rdrit_order_bottom          string
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RDRIT_TAX_HIDDEN', array(
    'nav_menu', 'link_category', 'post_format',
    'wp_theme', 'wp_template_part_area', 'wp_pattern_category',
    'wp_font_collection', 'wp_navigation',
) );

define( 'RDRIT_TAX_PALETTE', array(
    '#3b82f6','#10b981','#f59e0b','#ef4444',
    '#8b5cf6','#06b6d4','#ec4899','#14b8a6',
    '#f97316','#84cc16','#a855f7','#0ea5e9',
) );

/* Separateurs disponibles */
define( 'RDRIT_SEP_TYPES', array(
    'none'   => 'Aucun',
    'space'  => 'Espace',
    'hr'     => 'Ligne pleine',
    'dashed' => 'Pointilles',
) );

/* ============================================================
   DETECTION DYNAMIQUE
   ============================================================ */
function rdrit_tax_get_all(): array {
    static $cache = null;
    if ( $cache !== null ) return $cache;

    $all    = get_taxonomies( array(), 'objects' );
    $result = array();

    foreach ( $all as $slug => $tax ) {
        if ( in_array( $slug, RDRIT_TAX_HIDDEN, true ) ) continue;
        if ( ! $tax->public && ! $tax->show_ui ) continue;
        $result[ $slug ] = $tax;
    }

    uasort( $result, function( $a, $b ) {
        $core   = array( 'category', 'post_tag' );
        $a_core = in_array( $a->name, $core, true );
        $b_core = in_array( $b->name, $core, true );
        if ( $a_core && ! $b_core ) return -1;
        if ( ! $a_core && $b_core ) return 1;
        return strcmp( $a->label, $b->label );
    } );

    $cache = $result;
    return $result;
}

function rdrit_tax_for_post_type( string $post_type ): array {
    $all    = rdrit_tax_get_all();
    $result = array();
    foreach ( $all as $slug => $tax ) {
        if ( in_array( $post_type, (array) $tax->object_type, true ) ) {
            if ( (bool) get_option( 'rdrit_tax_' . $slug . '_enabled', 1 ) ) {
                $result[ $slug ] = $tax;
            }
        }
    }
    return $result;
}

/* ============================================================
   GESTION DE L'ORDRE PAR ZONE
   Retourne un tableau ordonne de slugs pour la zone donnee.
   Les elements sont des slugs de taxonomies + '_share' + '_related'.
   ============================================================ */
function rdrit_zone_order( string $zone, string $post_type = '' ): array {
    $saved = get_option( 'rdrit_order_' . $zone, '' );

    // Construire la liste complete des elements disponibles pour ce post_type
    $all_taxes = $post_type ? rdrit_tax_for_post_type( $post_type ) : rdrit_tax_get_all();
    $valid     = array_keys( $all_taxes );
    $valid[]   = '_share';
    $valid[]   = '_related';

    if ( $saved ) {
        $order = array_filter( explode( ',', $saved ), function($s) use ($valid) { return in_array( $s, $valid, true ); } );
        // Ajouter les nouveaux slugs en fin de liste
        foreach ( $valid as $v ) {
            if ( ! in_array( $v, $order, true ) ) $order[] = $v;
        }
        return array_values( $order );
    }

    return $valid;
}

/* ============================================================
   CONFIG PAR TAX
   ============================================================ */
function rdrit_tax_config( string $slug ): array {
    return array(
        'enabled'      => (bool)  get_option( 'rdrit_tax_' . $slug . '_enabled',      1 ),
        'position'     =>         get_option( 'rdrit_tax_' . $slug . '_position',     'bottom' ),
        'icon'         =>         get_option( 'rdrit_tax_' . $slug . '_icon',         'tag' ),
        'style'        =>         get_option( 'rdrit_tax_' . $slug . '_style',        'solid' ),
        'color_mode'   =>         get_option( 'rdrit_tax_' . $slug . '_color_mode',   'auto' ),
        'color'        =>         get_option( 'rdrit_tax_' . $slug . '_color',        '#3b82f6' ),
        'sep_type'     =>         get_option( 'rdrit_tax_' . $slug . '_sep_type',     'space' ),
        'sep_px'       => (int)   get_option( 'rdrit_tax_' . $slug . '_sep_px',       8 ),
        'disable_link' => (bool)  get_option( 'rdrit_tax_' . $slug . '_disable_link', 0 ),
    );
}

/* ============================================================
   RENDU HTML — zone complete (respecte l'ordre)
   ============================================================ */
function rdrit_tax_render_zone( int $post_id, string $zone ) {
    $post_type = get_post_type( $post_id );
    $order     = rdrit_zone_order( $zone, $post_type );
    $taxes     = rdrit_tax_for_post_type( $post_type );
    $has       = false;

    ob_start();
    echo '<div class="rdrit-tax-block rdrit-tax-zone--' . esc_attr( $zone ) . '">';

    foreach ( $order as $slug ) {
        // _share et _related sont geres par post-footer.php
        if ( in_array( $slug, array( '_share', '_related' ), true ) ) continue;

        if ( ! isset( $taxes[ $slug ] ) ) continue;
        $cfg = rdrit_tax_config( $slug );
        if ( $cfg['position'] !== $zone ) continue;
        if ( ! $cfg['enabled'] ) continue;

        $terms = get_the_terms( $post_id, $slug );
        if ( empty( $terms ) || is_wp_error( $terms ) ) continue;

        $has = true;
        rdrit_tax_render_row( $terms, $cfg, $slug );
    }

    echo '</div>';
    $html = ob_get_clean();
    if ( $has ) echo $html;
}

/**
 * Rendu d'une ligne : separateur avant + icone + badges.
 */
function rdrit_tax_render_row( array $terms, array $cfg, string $slug ) {
    // Separateur
    $sep_style = 'margin-bottom:' . (int)$cfg['sep_px'] . 'px;';
    $sep_html  = '';
    switch ( $cfg['sep_type'] ) {
        case 'hr':
            $sep_html = '<hr class="rdrit-sep rdrit-sep--hr" style="' . $sep_style . '">';
            break;
        case 'dashed':
            $sep_html = '<hr class="rdrit-sep rdrit-sep--dashed" style="' . $sep_style . '">';
            break;
        case 'space':
            $sep_html = '<div class="rdrit-sep rdrit-sep--space" style="' . $sep_style . '"></div>';
            break;
        case 'none':
        default:
            break;
    }

    echo '<div class="rdrit-tax-row" data-tax="' . esc_attr( $slug ) . '">';
    if ( $sep_html ) echo $sep_html;
    echo '<div class="rdrit-tax-row-inner">';
    echo '<span class="rdrit-meta-icon">' . rdrit_svg( $cfg['icon'] ) . '</span>';
    echo '<div class="rdrit-meta-terms">';

    foreach ( $terms as $i => $term ) {
        $link     = get_term_link( $term );
        $css_var  = rdrit_tax_badge_css( $i, $cfg );
        $style_cl = 'rdrit-badge-style--' . esc_attr( $cfg['style'] );
        $link_disabled = ! empty( $cfg['disable_link'] );
        if ( $link_disabled || is_wp_error( $link ) ) {
            echo '<span class="rdrit-term-badge ' . $style_cl . '" style="' . $css_var . '">' . esc_html( $term->name ) . '</span>';
        } else {
            echo '<a href="' . esc_url( $link ) . '" class="rdrit-term-badge ' . $style_cl . '" style="' . $css_var . '">' . esc_html( $term->name ) . '</a>';
        }
    }
    echo '</div></div></div>';
}

/* ============================================================
   HELPERS
   ============================================================ */
function rdrit_tax_badge_css( int $index, array $cfg ): string {
    if ( $cfg['style'] === 'mono' ) return '';
    switch ( $cfg['color_mode'] ) {
        case 'auto':    return '--rdrit-badge-color:' . RDRIT_TAX_PALETTE[ $index % count( RDRIT_TAX_PALETTE ) ] . ';';
        case 'accent':  return '--rdrit-badge-color:var(--rdr-accent,#2271b1);';
        case 'gray':    return '--rdrit-badge-color:#6b7280;';
        case 'custom':
            $color = sanitize_hex_color( $cfg['color'] ) ?: '#3b82f6';
            return '--rdrit-badge-color:' . $color . ';';
        default: return '--rdrit-badge-color:#3b82f6;';
    }
}

function rdrit_tax_icon_choices(): array {
    return array(
        'tag'      => 'Tag',
        'hash'     => 'Hashtag',
        'folder'   => 'Dossier',
        'bookmark' => 'Signet',
        'archive'  => 'Archive',
        'server'   => 'Serveur',
        'star'     => 'Etoile',
        'globe'    => 'Globe',
        'lock'     => 'Cadenas',
        'tool'     => 'Outil',
        'chip'     => 'Puce',
        'network'  => 'Reseau',
    );
}

/* Extra SVG icons */
add_filter( 'rdrit_extra_icons', function( $icons ) {
    $icons['star']    = '<svg viewBox="0 0 576 512" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M259.3 17.8L194 150.2 47.9 171.5c-26.2 3.8-36.7 36.1-17.7 54.6l105.7 103-25 145.5c-4.5 26.3 23.2 46 46.4 33.7L288 439.6l130.7 68.7c23.2 12.2 50.9-7.4 46.4-33.7l-25-145.5 105.7-103c19-18.5 8.5-50.8-17.7-54.6L382 150.2 316.7 17.8c-11.7-23.6-45.6-23.9-57.4 0z"/></svg>';
    $icons['globe']   = '<svg viewBox="0 0 496 512" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M248 8C111 8 0 119 0 256s111 248 248 248 248-111 248-248S385 8 248 8zm193.2 152h-82.5c-9-44.4-24.1-82.6-44.5-107.8 47.7 11.4 87.3 44 127 107.8zM248 56c27.7 0 64.3 46.8 78.5 104h-157C183.7 102.8 220.3 56 248 56zM32 256c0-13.7 1.4-27.1 4-40h96.1c-1.3 13.1-2.1 26.5-2.1 40s.8 26.9 2.1 40H36c-2.6-12.9-4-26.3-4-40zm22.8 88h82.5c9 44.4 24.1 82.6 44.5 107.8-47.7-11.4-87.3-44-127-107.8zm82.5-176H54.8c39.7-63.8 79.3-96.4 127-107.8-20.4 25.2-35.5 63.4-44.5 107.8zM248 456c-27.7 0-64.3-46.8-78.5-104h157c-14.2 57.2-50.8 104-78.5 104zm96.2-152H151.8c-1.5-12.9-2.3-26.3-2.3-40 0-13.6.8-27.1 2.3-40h192.4c1.5 12.9 2.3 26.4 2.3 40 0 13.7-.8 27.1-2.3 40zm6.1 143.8c20.4-25.2 35.5-63.4 44.5-107.8h82.5c-39.7 63.8-79.3 96.4-127 107.8zm52.6-143.8c1.3-13.1 2.1-26.5 2.1-40s-.8-26.9-2.1-40H460c2.6 12.9 4 26.3 4 40s-1.4 27.1-4 40h-57.1z"/></svg>';
    $icons['lock']    = '<svg viewBox="0 0 448 512" width="13" height="15" fill="currentColor" aria-hidden="true"><path d="M400 224h-24v-72C376 68.2 307.8 0 224 0S72 68.2 72 152v72H48c-26.5 0-48 21.5-48 48v192c0 26.5 21.5 48 48 48h352c26.5 0 48-21.5 48-48V272c0-26.5-21.5-48-48-48zm-104 0H152v-72c0-39.7 32.3-72 72-72s72 32.3 72 72v72z"/></svg>';
    $icons['tool']    = '<svg viewBox="0 0 512 512" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M501.1 395.7L384 278.6c-23.1-23.1-57.6-27.6-85.4-13.9L192 158.1V96L64 0 0 64l96 128h62.1l106.6 106.6c-13.6 27.8-9.2 62.3 13.9 85.4l117.1 117.1c14.6 14.6 38.2 14.6 52.7 0l52.7-52.7c14.5-14.6 14.5-38.2 0-52.7zM331.7 225c28.3 0 54.9 11 74.9 31l19.4 19.4c15.8-6.9 30.8-16.5 43.8-29.5 37.1-37.1 49.7-89.3 37.1-136.7-2.2-8.9-13.4-12.1-20.1-5.4l-48.4 48.4c-6.3 6.3-16.5 6.3-22.8 0L372.4 123c-6.3-6.3-6.3-16.5 0-22.8l48.4-48.4c6.7-6.7 3.4-17.9-5.4-20.1-47.4-12.6-99.6.1-136.7 37.1-28.5 28.5-41.9 66.1-41.2 103.6l82.1 82.1c8.1-1.9 16.5-2.9 24.1-2.9l-11.7 11.7 10.8-40.8 68.1 68.1-40.8 10.8 11.7-11.7c-.1 7.5-1.1 15.9-3 24.1L261.8 159.8c-.3-7.4.8-23.7 3.7-35.9zm-7.3 234.8l-17.3-17.3c-6.3-6.3-6.3-16.5 0-22.8l75.4-75.4c6.3-6.3 16.5-6.3 22.8 0l17.3 17.3c6.3 6.3 6.3 16.5 0 22.8L347.2 459.8c-6.3 6.3-16.5 6.3-22.8 0z"/></svg>';
    $icons['chip']    = '<svg viewBox="0 0 512 512" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M416 48v416c0 26.5-21.5 48-48 48H144c-26.5 0-48-21.5-48-48V48c0-26.5 21.5-48 48-48h224c26.5 0 48 21.5 48 48zm-272 80v96h224V128H144zm0 160v32h32v-32h-32zm64 0v32h32v-32h-32zm64 0v32h32v-32h-32zm64 0v32h32v-32h-32zm-192 64v32h32v-32h-32zm64 0v32h32v-32h-32zm64 0v32h32v-32h-32zm64 0v32h32v-32h-32z"/></svg>';
    $icons['network'] = '<svg viewBox="0 0 640 512" width="17" height="15" fill="currentColor" aria-hidden="true"><path d="M640 264v-16c0-8.8-7.2-16-16-16H344v-40h72c17.7 0 32-14.3 32-32V32c0-17.7-14.3-32-32-32H224c-17.7 0-32 14.3-32 32v128c0 17.7 14.3 32 32 32h72v40H16c-8.8 0-16 7.2-16 16v16c0 8.8 7.2 16 16 16h104v40H64c-17.7 0-32 14.3-32 32v128c0 17.7 14.3 32 32 32h160c17.7 0 32-14.3 32-32V352c0-17.7-14.3-32-32-32h-56v-40h304v40h-56c-17.7 0-32 14.3-32 32v128c0 17.7 14.3 32 32 32h160c17.7 0 32-14.3 32-32V352c0-17.7-14.3-32-32-32h-56v-40h104c8.8 0 16-7.2 16-16zM256 128V64h128v64H256zm-64 320H96v-64h96v64zm352 0h-96v-64h96v64z"/></svg>';
    return $icons;
} );
