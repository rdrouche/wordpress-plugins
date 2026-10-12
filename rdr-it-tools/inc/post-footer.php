<?php
/**
 * RDR-IT Tools — Post Footer v5
 *
 * Utilise rdrit_zone_order() pour respecter l'ordre configurable
 * (taxes + _share + _related) independamment par zone.
 *
 * Hooks GeneratePress :
 *   TOP    -> generate_after_entry_header  (apres le titre)
 *   BOTTOM -> generate_after_entry_content (apres le contenu)
 *
 * Filtrage Ko-fi & Partage :
 *   rdrit_pf_share_disabled_cpts  CSV des CPT où Ko-fi+Partage sont desactives
 *   Meta post _rdrit_share_disabled  (1) pour desactiver sur un post precis
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   HOOKS GP
   ============================================================ */
add_action( 'generate_after_entry_header',  'rdrit_post_top_render',    20 );
add_action( 'generate_after_entry_content', 'rdrit_post_bottom_render', 10 );

function rdrit_post_top_render()    { if ( rdrit_pf_is_valid() ) rdrit_post_render_zone( 'top' ); }
function rdrit_post_bottom_render() { if ( rdrit_pf_is_valid() ) rdrit_post_render_zone( 'bottom' ); }

function rdrit_pf_is_valid(): bool {
    if ( ! is_singular() ) return false;
    $excluded = array( 'attachment','revision','nav_menu_item','custom_css',
                       'customize_changeset','wp_block','wp_template',
                       'wp_template_part','wp_navigation','wp_font_face','wp_font_family' );
    return ! in_array( get_post_type(), $excluded, true );
}

/* ============================================================
   RENDU D'UNE ZONE — respecte l'ordre configurable
   ============================================================ */
function rdrit_post_render_zone( string $zone ) {
    $opts      = rdrit_pf_opts();
    $post_id   = get_the_ID();
    $post_type = get_post_type( $post_id );
    $order     = rdrit_zone_order( $zone, $post_type );
    $taxes     = rdrit_tax_for_post_type( $post_type );

    ob_start();
    $has = false;

    foreach ( $order as $slug ) {

        /* ── Bloc _share ── */
        if ( $slug === '_share' ) {
            $share_pos = get_option( 'rdrit_pf_pos_share', 'bottom' );
            if ( $share_pos !== $zone ) continue;
            if ( ! $opts['kofi_enabled'] && ! $opts['share_enabled'] && ! $opts['gps_enabled'] ) continue;

            // Desactiver sur CPT specifiques
            $disabled_cpts = array_filter( explode( ',', get_option( 'rdrit_pf_share_disabled_cpts', '' ) ) );
            if ( in_array( $post_type, $disabled_cpts, true ) ) continue;

            // Desactiver sur ce post precis (meta box)
            if ( (int) get_post_meta( $post_id, '_rdrit_share_disabled', true ) === 1 ) continue;

            // Separateur global share
            echo rdrit_global_sep( 'share', $zone );

            echo '<div class="rdrit-share-bar">';
            if ( $opts['kofi_enabled'] )  rdrit_render_kofi( $opts );
            if ( $opts['gps_enabled'] )   rdrit_render_gps( $opts );
            if ( $opts['share_enabled'] ) rdrit_render_social_share( $opts );
            echo '</div>';
            $has = true;
            continue;
        }

        /* ── Bloc _related ── */
        if ( $slug === '_related' ) {
            $related_pos = get_option( 'rdrit_pf_pos_related', 'bottom' );
            if ( $related_pos !== $zone ) continue;
            if ( ! $opts['related_enabled'] ) continue;

            echo rdrit_global_sep( 'related', $zone );
            rdrit_related_render( $opts );
            $has = true;
            continue;
        }

        /* ── Taxonomie ── */
        if ( ! isset( $taxes[ $slug ] ) ) continue;
        $cfg = rdrit_tax_config( $slug );
        if ( $cfg['position'] !== $zone ) continue;
        if ( ! $cfg['enabled'] ) continue;

        $terms = get_the_terms( $post_id, $slug );
        if ( empty( $terms ) || is_wp_error( $terms ) ) continue;

        rdrit_tax_render_row( $terms, $cfg, $slug );
        $has = true;
    }

    $html = ob_get_clean();
    if ( $has && trim( $html ) ) {
        $class = ( $zone === 'top' ) ? 'rdrit-post-header-meta' : 'rdrit-post-footer';
        echo '<div class="' . $class . '">' . $html . '</div>';
    }
}

/**
 * Separateur global avant les blocs share/related
 * (configurable separement des separateurs de tax)
 */
function rdrit_global_sep( string $block, string $zone ): string {
    $type = get_option( 'rdrit_sep_' . $block . '_type', 'space' );
    $px   = (int) get_option( 'rdrit_sep_' . $block . '_px', 12 );
    return rdrit_sep_html( $type, $px );
}

function rdrit_sep_html( string $type, int $px ): string {
    $style = 'margin-bottom:' . $px . 'px;';
    switch ( $type ) {
        case 'hr':     return '<hr class="rdrit-sep rdrit-sep--hr" style="' . $style . '">';
        case 'dashed': return '<hr class="rdrit-sep rdrit-sep--dashed" style="' . $style . '">';
        case 'space':  return '<div class="rdrit-sep rdrit-sep--space" style="' . $style . '"></div>';
        default:       return '';
    }
}

/* ============================================================
   OPTIONS
   ============================================================ */
function rdrit_pf_opts(): array {
    return array(
        'kofi_enabled'    => (bool) get_option( 'rdrit_pf_kofi_enabled',    1 ),
        'share_enabled'   => (bool) get_option( 'rdrit_pf_share_enabled',   1 ),
        'related_enabled' => (bool) get_option( 'rdrit_pf_related_enabled', 1 ),
        'share_facebook'  => (bool) get_option( 'rdrit_pf_share_facebook',  1 ),
        'share_twitter'   => (bool) get_option( 'rdrit_pf_share_twitter',   1 ),
        'share_linkedin'  => (bool) get_option( 'rdrit_pf_share_linkedin',  1 ),
        'share_reddit'    => (bool) get_option( 'rdrit_pf_share_reddit',    1 ),
        'kofi_url'        => get_option( 'rdrit_pf_kofi_url',   'https://ko-fi.com/rdrit0980' ),
        'kofi_color'      => get_option( 'rdrit_pf_kofi_color', '#c0392b' ),
        'gps_enabled'     => (bool) get_option( 'rdrit_pf_gps_enabled', 0 ),
        'gps_domain'      => get_option( 'rdrit_pf_gps_domain', '' ),
        'gps_label'       => get_option( 'rdrit_pf_gps_label',  'Ajouter à vos sources préférées' ),
        'gps_color'       => get_option( 'rdrit_pf_gps_color',  '#4285f4' ),
        'gps_disabled_cpts' => get_option( 'rdrit_pf_gps_disabled_cpts', '' ),
        'related_count'   => (int)  get_option( 'rdrit_pf_related_count',  4 ),
        'related_title'   => get_option( 'rdrit_pf_related_title', '📚 Articles similaires' ),
        'related_layout'  => get_option( 'rdrit_pf_related_layout', 'cards-3' ),
        'related_mode'    => get_option( 'rdrit_pf_related_mode',   'sync' ),
    );
}

/* ============================================================
   KO-FI
   ============================================================ */
function rdrit_render_kofi( array $opts ) {
    $color = sanitize_hex_color( $opts['kofi_color'] ) ?: '#c0392b';
    echo '<a href="' . esc_url( $opts['kofi_url'] ) . '" class="rdrit-kofi-btn" '
       . 'target="_blank" rel="noopener" title="Soutenir sur Ko-fi" '
       . 'style="--rdrit-kofi-color:' . $color . '">'
       . '<svg viewBox="0 0 24 24" fill="currentColor" width="17" height="17" aria-hidden="true">'
       . '<path d="M23.881 8.948c-.773-4.085-4.859-4.593-4.859-4.593H.723c-.604 0-.679.798-.679.798s-.082 7.324-.022 11.822c.164 2.424 2.586 2.672 2.586 2.672s8.267-.023 11.966-.049c2.438-.426 2.683-2.566 2.658-3.734 4.352.24 7.422-2.831 6.649-6.916zm-11.062 3.511c-1.246 1.453-4.011 3.976-4.011 3.976s-.121.119-.31.023c-.076-.057-.108-.09-.108-.09-.443-.441-3.368-3.049-4.034-3.954-.709-.965-1.041-2.7-.091-3.71.951-1.01 3.005-1.086 4.363.407 0 0 1.565-1.782 3.468-.963 1.904.82 1.832 3.011.723 4.311zm6.173.478c-.928.116-1.682.028-1.682.028V7.284h1.77s1.971.551 1.971 2.638c0 1.913-.985 2.667-2.059 3.015z"/>'
       . '</svg><span>Soutenir</span></a>';
}

/* ============================================================
   GOOGLE SOURCES PREFEREES
   https://www.google.com/preferences/source?q=DOMAINE
   Permet aux lecteurs d'ajouter le site a leurs sources
   preferees dans Google Actualites / Discover.
   ============================================================ */
function rdrit_render_gps( array $opts ) {
    /* Domaine : celui configure, sinon celui du site */
    $domain = trim( $opts['gps_domain'] );
    if ( $domain === '' ) {
        $domain = wp_parse_url( home_url(), PHP_URL_HOST );
        $domain = preg_replace( '/^www\./', '', (string) $domain );
    }
    if ( ! $domain ) return;

    /* Libelle traduisible via Polylang (groupe RDR-IT Post Footer) */
    $label = $opts['gps_label'];
    if ( function_exists( 'pll__' ) ) {
        $translated = pll__( $label );
        if ( $translated ) $label = $translated;
    }

    $url   = 'https://www.google.com/preferences/source?q=' . rawurlencode( $domain );
    $color = sanitize_hex_color( $opts['gps_color'] ) ?: '#4285f4';

    echo '<a href="' . esc_url( $url ) . '" class="rdrit-gps-btn" '
       . 'target="_blank" rel="noopener noreferrer nofollow" '
       . 'title="' . esc_attr( $label ) . '" '
       . 'style="--rdrit-gps-color:' . $color . '">'
       . rdrit_svg( 'google' )
       . '<span>' . esc_html( $label ) . '</span></a>';
}

/* Enregistrer le libelle pour traduction Polylang */
add_action( 'init', 'rdrit_pf_register_pll_strings' );
function rdrit_pf_register_pll_strings(): void {
    if ( ! function_exists( 'pll_register_string' ) ) return;
    pll_register_string(
        'pf_gps_label',
        get_option( 'rdrit_pf_gps_label', 'Ajouter à vos sources préférées' ),
        'RDR-IT Post Footer'
    );
}

/* ============================================================
   PARTAGE SOCIAL
   ============================================================ */
function rdrit_render_social_share( array $opts ) {
    global $wp;
    $url   = esc_url( home_url( add_query_arg( array(), $wp->request ) ) );
    $title = rawurlencode( get_the_title() );

    $networks = array(
        'facebook' => array( 'label' => 'Facebook',    'color' => '#1877f2', 'href' => 'https://www.facebook.com/sharer/sharer.php?u=' . $url,                 'icon' => 'facebook' ),
        'twitter'  => array( 'label' => 'Twitter / X', 'color' => '#000',    'href' => 'https://twitter.com/intent/tweet?url=' . $url . '&text=' . $title,    'icon' => 'twitter'  ),
        'linkedin' => array( 'label' => 'LinkedIn',    'color' => '#0077b5', 'href' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $url,          'icon' => 'linkedin' ),
        'reddit'   => array( 'label' => 'Reddit',      'color' => '#ff4500', 'href' => 'https://reddit.com/submit?url=' . $url . '&title=' . $title,           'icon' => 'reddit'   ),
    );

    echo '<div class="rdrit-share-btns">';
    foreach ( $networks as $key => $net ) {
        if ( ! $opts[ 'share_' . $key ] ) continue;
        echo '<a href="' . esc_url( $net['href'] ) . '" class="rdrit-share-btn rdrit-share--' . $key . '" '
           . 'target="_blank" rel="noopener noreferrer" title="Partager sur ' . esc_attr( $net['label'] ) . '" '
           . 'style="--rdrit-share-color:' . $net['color'] . '">'
           . rdrit_svg( $net['icon'] )
           . '<span>' . esc_html( $net['label'] ) . '</span></a>';
    }
    echo '</div>';
}

/* ============================================================
   SVG REGISTRY
   ============================================================ */
function rdrit_svg( string $name ): string {
    static $icons = null;
    if ( $icons === null ) {
        $base = array(
            'folder'   => '<svg viewBox="0 0 512 512" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M464 128H272l-64-64H48C21.5 64 0 85.5 0 112v288c0 26.5 21.5 48 48 48h416c26.5 0 48-21.5 48-48V176c0-26.5-21.5-48-48-48z"/></svg>',
            'bookmark' => '<svg viewBox="0 0 384 512" width="13" height="15" fill="currentColor" aria-hidden="true"><path d="M0 512V48C0 21.5 21.5 0 48 0h288c26.5 0 48 21.5 48 48v464L192 400 0 512z"/></svg>',
            'archive'  => '<svg viewBox="0 0 512 512" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M32 448c0 17.7 14.3 32 32 32h384c17.7 0 32-14.3 32-32V160H32v288zm160-212c0-6.6 5.4-12 12-12h104c6.6 0 12 5.4 12 12v8c0 6.6-5.4 12-12 12H204c-6.6 0-12-5.4-12-12v-8zM480 32H32C14.3 32 0 46.3 0 64v48c0 8.8 7.2 16 16 16h480c8.8 0 16-7.2 16-16V64c0-17.7-14.3-32-32-32z"/></svg>',
            'tag'      => '<svg viewBox="0 0 640 512" width="16" height="15" fill="currentColor" aria-hidden="true"><path d="M497.941 225.941L286.059 14.059A48 48 0 0 0 252.118 0H48C21.49 0 0 21.49 0 48v204.118a48 48 0 0 0 14.059 33.941l211.882 211.882c18.744 18.745 49.136 18.746 67.882 0l204.118-204.118c18.745-18.745 18.745-49.137 0-67.882zM112 160c-26.51 0-48-21.49-48-48s21.49-48 48-48 48 21.49 48 48-21.49 48-48 48zm513.941 133.823L421.823 497.941c-18.745 18.745-49.137 18.745-67.882 0l-.36-.36L527.64 323.522c16.999-16.999 26.36-39.6 26.36-63.64s-9.362-46.641-26.36-63.64L331.397 0h48.721a48 48 0 0 1 33.941 14.059l211.882 211.882c18.745 18.745 18.745 49.137 0 67.882z"/></svg>',
            'hash'     => '<svg viewBox="0 0 448 512" width="14" height="15" fill="currentColor" aria-hidden="true"><path d="M440.667 182.109l7.143-40c1.313-7.355-4.342-14.109-11.813-14.109h-74.81l14.623-81.891C377.123 38.754 371.468 32 363.997 32h-40.632a12 12 0 0 0-11.813 9.891L296.175 128H197.54l14.623-81.891C213.477 38.754 207.822 32 200.35 32h-40.632a12 12 0 0 0-11.813 9.891L132.528 128H53.432a12 12 0 0 0-11.813 9.891l-7.143 40C33.163 185.253 38.818 192 46.289 192h74.81L98.242 320H19.146a12 12 0 0 0-11.813 9.891l-7.143 40C-1.123 377.245 4.532 384 12.003 384h74.81L72.19 465.891C70.877 473.246 76.532 480 84.003 480h40.632a12 12 0 0 0 11.813-9.891L151.826 384h98.634l-14.624 81.891C234.523 473.246 240.178 480 247.65 480h40.632a12 12 0 0 0 11.813-9.891L315.474 384h79.096a12 12 0 0 0 11.813-9.891l7.143-40c1.313-7.355-4.342-14.109-11.813-14.109h-74.81l22.857-128h79.096a12 12 0 0 0 11.813-9.891zM261.889 320h-98.634l22.857-128h98.634l-22.857 128z"/></svg>',
            'server'   => '<svg viewBox="0 0 512 512" width="15" height="15" fill="currentColor" aria-hidden="true"><path d="M480 160H32c-17.673 0-32-14.327-32-32V64c0-17.673 14.327-32 32-32h448c17.673 0 32 14.327 32 32v64c0 17.673-14.327 32-32 32zm-48-88c-13.255 0-24 10.745-24 24s10.745 24 24 24 24-10.745 24-24-10.745-24-24-24zm-64 0c-13.255 0-24 10.745-24 24s10.745 24 24 24 24-10.745 24-24-10.745-24-24-24zm112 248H32c-17.673 0-32-14.327-32-32v-64c0-17.673 14.327-32 32-32h448c17.673 0 32 14.327 32 32v64c0 17.673-14.327 32-32 32zm-48-88c-13.255 0-24 10.745-24 24s10.745 24 24 24 24-10.745 24-24-10.745-24-24-24zm-64 0c-13.255 0-24 10.745-24 24s10.745 24 24 24 24-10.745 24-24-10.745-24-24-24zm112 248H32c-17.673 0-32-14.327-32-32v-64c0-17.673 14.327-32 32-32h448c17.673 0 32 14.327 32 32v64c0 17.673-14.327 32-32 32zm-48-88c-13.255 0-24 10.745-24 24s10.745 24 24 24 24-10.745 24-24-10.745-24-24-24zm-64 0c-13.255 0-24 10.745-24 24s10.745 24 24 24 24-10.745 24-24-10.745-24-24-24z"/></svg>',
            'facebook' => '<svg viewBox="0 0 448 512" width="17" height="17" fill="currentColor" aria-hidden="true"><path d="M400 32H48A48 48 0 0 0 0 80v352a48 48 0 0 0 48 48h137.25V327.69h-63V256h63v-54.64c0-62.15 37-96.48 93.67-96.48 27.14 0 55.52 4.84 55.52 4.84v61h-31.27c-30.81 0-40.42 19.12-40.42 38.73V256h68.78l-11 71.69h-57.78V480H400a48 48 0 0 0 48-48V80a48 48 0 0 0-48-48z"/></svg>',
            'twitter'  => '<svg viewBox="0 0 512 512" width="17" height="17" fill="currentColor" aria-hidden="true"><path d="M389.2 48h70.6L305.6 224.2 487 464H345L233.7 318.6 106.5 464H35.8L200.7 275.5 26.8 48H172.4L272.9 180.9 389.2 48zM364.4 421.8h39.1L151.1 88h-42L364.4 421.8z"/></svg>',
            'linkedin' => '<svg viewBox="0 0 448 512" width="17" height="17" fill="currentColor" aria-hidden="true"><path d="M416 32H31.9C14.3 32 0 46.5 0 64.3v383.4C0 465.5 14.3 480 31.9 480H416c17.6 0 32-14.5 32-32.3V64.3c0-17.8-14.4-32.3-32-32.3zM135.4 416H69V202.2h66.5V416zm-33.2-243c-21.3 0-38.5-17.3-38.5-38.5S80.9 96 102.2 96c21.2 0 38.5 17.3 38.5 38.5 0 21.3-17.2 38.5-38.5 38.5zm282.1 243h-66.4V312c0-24.8-.5-56.7-34.5-56.7-34.6 0-39.9 27-39.9 54.9V416h-66.4V202.2h63.7v29.2h.9c8.9-16.8 30.6-34.5 62.9-34.5 67.2 0 79.7 44.3 79.7 101.9V416z"/></svg>',
            'google'   => '<svg viewBox="0 0 488 512" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M488 261.8C488 403.3 391.1 504 248 504 110.8 504 0 393.2 0 256S110.8 8 248 8c66.8 0 123 24.5 166.3 64.9l-67.5 64.9C258.5 52.6 94.3 116.6 94.3 256c0 86.5 69.1 156.6 153.7 156.6 98.2 0 135-70.4 140.8-106.9H248v-85.3h236.1c2.3 12.7 3.9 24.9 3.9 41.4z"/></svg>',
            'reddit'   => '<svg viewBox="0 0 448 512" width="17" height="17" fill="currentColor" aria-hidden="true"><path d="M283.2 345.5c2.7 2.7 2.7 6.8 0 9.2-24.5 24.5-93.8 24.6-118.4 0-2.7-2.4-2.7-6.5 0-9.2 2.4-2.4 6.5-2.4 8.9 0 18.7 19.2 81 19.6 100.5 0 2.4-2.3 6.6-2.3 9 0zm-91.3-53.8c0-14.9-11.9-26.8-26.5-26.8-14.9 0-26.8 11.9-26.8 26.8 0 14.6 11.9 26.5 26.8 26.5 14.6 0 26.5-11.9 26.5-26.5zm90.7-26.8c-14.6 0-26.5 11.9-26.5 26.8 0 14.6 11.9 26.5 26.5 26.5 14.9 0 26.8-11.9 26.8-26.5 0-14.9-11.9-26.8-26.8-26.8zM448 80v352c0 26.5-21.5 48-48 48H48c-26.5 0-48-21.5-48-48V80c0-26.5 21.5-48 48-48h352c26.5 0 48 21.5 48 48zm-99.7 140.6c-10.1 0-19 4.2-25.6 10.7-24.1-16.7-56.5-27.4-92.5-28.6l18.7-84.2 59.5 13.4c0 14.6 11.9 26.5 26.5 26.5 14.9 0 26.8-12.2 26.8-26.8 0-14.6-11.9-26.8-26.8-26.8-10.4 0-19.3 6.2-23.8 14.9l-65.7-14.6c-3.3-.9-6.5 1.5-7.4 4.8l-20.5 92.8c-35.7 1.5-67.8 12.2-91.9 28.9-6.5-6.8-15.8-11-25.9-11-37.5 0-49.8 50.4-15.5 67.5-1.2 5.4-1.8 11-1.8 16.7 0 56.5 63.7 102.3 141.9 102.3 78.5 0 142.2-45.8 142.2-102.3 0-5.7-.6-11.6-2.1-17 33.6-17.2 21.2-67.2-16.1-67.2z"/></svg>',
        );
        $extra = apply_filters( 'rdrit_extra_icons', array() );
        $icons = array_merge( $base, $extra );
    }
    return $icons[ $name ] ?? '';
}

/* ============================================================
   CSS FRONT-END
   ============================================================ */
add_action( 'wp_head', 'rdrit_post_footer_css' );
function rdrit_post_footer_css() {
    if ( ! is_singular() ) return;
    ?>
<style id="rdrit-pf-css">
.rdrit-post-footer,.rdrit-post-header-meta{display:flex;flex-direction:column;font-size:.92rem}
.rdrit-post-footer{margin-top:1.75rem;padding-top:1.5rem;border-top:2px solid var(--rdrit-border,#e5e7eb)}
.rdrit-post-header-meta{margin-top:.75rem;margin-bottom:1.25rem;padding:.6rem 0;border-bottom:1px dashed var(--rdrit-border,#e5e7eb)}
/* Tax rows */
.rdrit-tax-block{display:flex;flex-direction:column}
.rdrit-tax-row{display:flex;flex-direction:column}
.rdrit-tax-row-inner{display:flex;align-items:center;gap:.55rem;flex-wrap:wrap}
.rdrit-meta-icon{flex-shrink:0;color:var(--rdrit-icon,#9ca3af);display:flex;align-items:center;line-height:1}
.rdrit-meta-terms{display:flex;flex-wrap:wrap;gap:.35rem}
/* Separateurs */
.rdrit-sep{border:none;margin:0}
.rdrit-sep--hr{border-top:1px solid var(--rdrit-border,#e5e7eb)}
.rdrit-sep--dashed{border-top:1px dashed var(--rdrit-border,#e5e7eb)}
.rdrit-sep--space{display:block}
/* Badges */
.rdrit-term-badge{display:inline-flex;align-items:center;text-decoration:none;font-size:.76rem;font-weight:600;transition:opacity .15s,transform .15s;line-height:1}
.rdrit-term-badge:hover{opacity:.82;transform:translateY(-1px)}
.rdrit-badge-style--solid  {background:var(--rdrit-badge-color,#3b82f6);color:#fff;padding:.28em .7em;border-radius:4px}
.rdrit-badge-style--pill   {background:var(--rdrit-badge-color,#3b82f6);color:#fff;padding:.28em .85em;border-radius:50px}
.rdrit-badge-style--outline{background:transparent;color:var(--rdrit-badge-color,#3b82f6);border:1.5px solid var(--rdrit-badge-color,#3b82f6);padding:.24em .65em;border-radius:4px}
.rdrit-badge-style--flat   {background:color-mix(in srgb,var(--rdrit-badge-color,#3b82f6) 12%,transparent);color:var(--rdrit-badge-color,#3b82f6);padding:.28em .7em;border-radius:4px}
.rdrit-badge-style--mono   {background:#f3f4f6;color:#374151;border:1px solid #d1d5db;padding:.28em .75em;border-radius:6px;font-family:monospace;font-size:.8rem}
/* Share */
.rdrit-share-bar{display:flex;align-items:center;flex-wrap:wrap;gap:.65rem}
.rdrit-kofi-btn{display:inline-flex;align-items:center;gap:.4rem;background:var(--rdrit-kofi-color,#c0392b);color:#fff;padding:.42em 1em;border-radius:50px;text-decoration:none;font-weight:700;font-size:.84rem;transition:filter .15s,transform .15s}
.rdrit-kofi-btn:hover{filter:brightness(1.12);transform:translateY(-1px);color:#fff}
.rdrit-gps-btn{display:inline-flex;align-items:center;gap:.42rem;padding:.4em .95em;border-radius:50px;text-decoration:none;font-size:.82rem;font-weight:600;background:color-mix(in srgb,var(--rdrit-gps-color,#4285f4) 10%,transparent);color:var(--rdrit-gps-color,#4285f4);border:1.5px solid color-mix(in srgb,var(--rdrit-gps-color,#4285f4) 30%,transparent);transition:background .15s,color .15s,transform .15s}
.rdrit-gps-btn:hover{background:var(--rdrit-gps-color,#4285f4);color:#fff;transform:translateY(-1px)}
.rdrit-share-btns{display:flex;gap:.45rem;flex-wrap:wrap}
.rdrit-share-btn{display:inline-flex;align-items:center;gap:.38rem;padding:.4em .85em;border-radius:6px;text-decoration:none;font-size:.8rem;font-weight:600;background:color-mix(in srgb,var(--rdrit-share-color) 10%,transparent);color:var(--rdrit-share-color);border:1.5px solid color-mix(in srgb,var(--rdrit-share-color) 28%,transparent);transition:background .15s,transform .15s,color .15s}
.rdrit-share-btn:hover{background:var(--rdrit-share-color);color:#fff;transform:translateY(-1px)}
/* Related */
.rdrit-related-wrap{margin-top:.1rem}
.rdrit-related-title{font-size:1rem;margin:0 0 .85rem;padding-bottom:.5rem;border-bottom:2px solid var(--rdrit-border,#e5e7eb)}
.rdrit-rel-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:.5rem}
.rdrit-rel-item{display:flex;align-items:baseline;gap:.5rem}
.rdrit-rel-item a{font-weight:600;color:inherit;text-decoration:none;font-size:.9rem}
.rdrit-rel-item a:hover{text-decoration:underline}
.rdrit-rel-date{font-size:.78rem;color:#9ca3af;white-space:nowrap}
.rdrit-related-grid{display:grid;gap:1rem}
.rdrit-related--cards-2 .rdrit-related-grid{grid-template-columns:repeat(2,1fr)}
.rdrit-related--cards-3 .rdrit-related-grid{grid-template-columns:repeat(3,1fr)}
.rdrit-related--cards-4 .rdrit-related-grid{grid-template-columns:repeat(4,1fr)}
.rdrit-rel-card{display:flex;flex-direction:column;border:1px solid var(--rdrit-border,#e5e7eb);border-radius:10px;overflow:hidden;text-decoration:none;color:inherit;transition:box-shadow .15s,transform .15s}
.rdrit-rel-card:hover{box-shadow:0 4px 18px rgba(0,0,0,.1);transform:translateY(-2px)}
.rdrit-rel-card img{width:100%;height:120px;object-fit:cover;display:block}
.rdrit-rel-card-noimg{width:100%;height:70px;background:linear-gradient(135deg,#1e293b,#334155);display:flex;align-items:center;justify-content:center;font-size:1.4rem}
.rdrit-rel-card-body{padding:.6rem .7rem;flex:1;display:flex;flex-direction:column;gap:.18rem}
.rdrit-rel-card-cat{font-size:.69rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em}
.rdrit-rel-card-title{font-size:.82rem;font-weight:600;line-height:1.4}
.rdrit-rel-card-date{font-size:.73rem;color:#9ca3af;margin-top:auto;padding-top:.2rem}
.rdrit-related-empty{color:#9ca3af;font-size:.85rem;margin:0}
/* Dark */
[data-theme="dark"] .rdrit-post-footer,[data-theme="dark"] .rdrit-post-header-meta{--rdrit-border:#374151;--rdrit-icon:#6b7280}
[data-theme="dark"] .rdrit-badge-style--mono{background:#1f2937;color:#d1d5db;border-color:#374151}
[data-theme="dark"] .rdrit-rel-card{background:#1f2937;--rdrit-border:#374151}
[data-theme="dark"] .rdrit-rel-card-title{color:#f3f4f6}
/* Responsive */
@media(max-width:700px){
    .rdrit-related--cards-3 .rdrit-related-grid,.rdrit-related--cards-4 .rdrit-related-grid{grid-template-columns:repeat(2,1fr)}
    .rdrit-share-btn span{display:none}
    .rdrit-share-btn{padding:.48em .6em}
}
@media(max-width:480px){
    .rdrit-related--cards-2 .rdrit-related-grid,.rdrit-related--cards-3 .rdrit-related-grid,.rdrit-related--cards-4 .rdrit-related-grid{grid-template-columns:1fr}
}
</style>
    <?php
}

/* ============================================================
   META BOX — Désactiver Ko-fi & Partage sur un post précis
   ============================================================ */
add_action( 'add_meta_boxes', 'rdrit_share_meta_box_register' );
function rdrit_share_meta_box_register() {
    // Sur tous les CPT publics sauf les exclus
    $excluded = array( 'attachment','revision','nav_menu_item','custom_css',
                       'customize_changeset','wp_block','wp_template',
                       'wp_template_part','wp_navigation' );
    $cpts = get_post_types( array( 'public' => true ), 'names' );
    foreach ( $cpts as $cpt ) {
        if ( in_array( $cpt, $excluded, true ) ) continue;
        add_meta_box(
            'rdrit_share_meta',
            '🔧 RDR-IT Tools',
            'rdrit_share_meta_box_render',
            $cpt,
            'side',
            'default'
        );
    }
}

function rdrit_share_meta_box_render( WP_Post $post ) {
    wp_nonce_field( 'rdrit_share_meta_nonce', '_rdrit_share_nonce' );
    $disabled = (int) get_post_meta( $post->ID, '_rdrit_share_disabled', true );
    ?>
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.9em">
        <input type="checkbox" name="_rdrit_share_disabled" value="1" <?php checked( $disabled, 1 ); ?>>
        Désactiver Ko-fi &amp; Partage sur ce post
    </label>
    <?php
}

add_action( 'save_post', 'rdrit_share_meta_box_save' );
function rdrit_share_meta_box_save( int $post_id ) {
    if ( ! isset( $_POST['_rdrit_share_nonce'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['_rdrit_share_nonce'], 'rdrit_share_meta_nonce' ) ) return;
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) return;
    if ( ! current_user_can( 'edit_post', $post_id ) ) return;

    if ( ! empty( $_POST['_rdrit_share_disabled'] ) ) {
        update_post_meta( $post_id, '_rdrit_share_disabled', 1 );
    } else {
        delete_post_meta( $post_id, '_rdrit_share_disabled' );
    }
}
