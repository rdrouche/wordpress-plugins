<?php
/**
 * RDR-IT Tools — Widget Visibility v3
 *
 * Panneau "Visibilité" dans l'éditeur de widgets Gutenberg (block widgets).
 * PHP : render_block filtre côté serveur.
 * JS  : plugin Gutenberg ajoute InspectorControls sur tous les blocs.
 *
 * Conditions :
 *   - Utilisateur : connecté/non connecté, rôle(s), capacité(s)
 *   - Page : home, single, page, archive, catégorie, tag, taxonomie,
 *             auteur, search, 404, date, sticky, post_type
 *   - Langue : Polylang
 *   - Device : mobile/tablette/desktop (CSS uniquement)
 *
 * Logique : ET ou OU, configurable par widget.
 * Mode : Afficher si / Masquer si, configurable par widget.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   ENREGISTRER rdritVisibility côté PHP pour tous les blocs
   Nécessaire pour que l'API REST WP accepte l'attribut à la sauvegarde.
   Sans ça, Gutenberg strip l'attribut au moment du save.
   ============================================================ */
add_filter( 'register_block_type_args', 'rdrit_wv_register_block_attr', 10, 2 );
function rdrit_wv_register_block_attr( array $args, string $block_name ): array {
    // Blocs qui utilisent ServerSideRender via block-renderer REST → skip
    $ssr_blocks = array(
        'core/calendar', 'core/latest-comments', 'core/rss',
        'core/archives', 'core/page-list', 'core/latest-posts',
        'core/navigation', 'core/loginout', 'core/categories',
        'core/query', 'core/comments', 'woocommerce/product-categories',
        // core/tag-cloud → rendu PHP direct, pas SSR ✅
        // core/search    → save:function, rendu client ✅
    );
    if ( in_array( $block_name, $ssr_blocks, true ) ) return $args;

    if ( ! isset( $args['attributes'] ) ) {
        $args['attributes'] = array();
    }
    $args['attributes']['rdritVisibility'] = array(
        'type'    => 'object',
        'default' => array(),
    );
    return $args;
}

/* ============================================================
   ENQUEUE JS — éditeur de widgets uniquement
   ============================================================ */
add_action( 'enqueue_block_assets', 'rdrit_wv_enqueue' );
function rdrit_wv_enqueue() {
    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    $is_widgets  = $screen && $screen->id === 'widgets';
    $is_customize = is_customize_preview();

    // Charger uniquement sur widgets.php et le customizer — pas partout dans l'admin
    if ( ! $is_widgets && ! $is_customize ) return;

    wp_enqueue_script(
        'rdrit-widget-visibility',
        RDRIT_TOOLS_URL . 'assets/js/rdrit-widget-visibility.js',
        array( 'wp-blocks', 'wp-hooks', 'wp-element', 'wp-components',
               'wp-block-editor', 'wp-i18n' ),
        RDRIT_TOOLS_VERSION,
        true
    );

    // Passer les données de config au JS
    $roles = array();
    foreach ( wp_roles()->roles as $slug => $data ) {
        $roles[ $slug ] = translate_user_role( $data['name'] );
    }

    $all_caps = array();
    foreach ( wp_roles()->roles as $rd ) {
        foreach ( array_keys( $rd['capabilities'] ) as $cap ) {
            $all_caps[ $cap ] = true;
        }
    }
    ksort( $all_caps );

    $langs = array();
    if ( function_exists( 'pll_languages_list' ) ) {
        foreach ( pll_languages_list( array( 'fields' => '' ) ) as $l ) {
            $langs[] = array( 'slug' => $l->slug, 'name' => $l->name );
        }
    }

    // Pages statiques
    $static_pages = get_posts( array(
        'post_type'      => 'page',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'lang'           => '', // toutes langues Polylang
    ) );
    $pages_list = array();
    foreach ( $static_pages as $p ) {
        $pages_list[] = array( 'id' => $p->ID, 'slug' => $p->post_name, 'title' => $p->post_title );
    }

    // CPT publics (hors post/page/attachment)
    $cpts = array();
    foreach ( get_post_types( array( 'public' => true, '_builtin' => false ), 'objects' ) as $pt ) {
        $cpts[] = array( 'slug' => $pt->name, 'label' => $pt->label );
    }
    // Ajouter post et page natifs
    array_unshift( $cpts,
        array( 'slug' => 'post',       'label' => 'Articles' ),
        array( 'slug' => 'page',       'label' => 'Pages' ),
        array( 'slug' => 'attachment', 'label' => 'Médias' )
    );

    // Taxonomies publiques avec leurs termes
    $taxonomies = array();
    foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
        $terms = get_terms( array(
            'taxonomy'   => $tax->name,
            'hide_empty' => false,
            'number'     => 200,
        ) );
        $terms_list = array();
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $terms_list[] = array( 'slug' => $term->slug, 'name' => $term->name );
            }
        }
        $taxonomies[] = array(
            'slug'   => $tax->name,
            'label'  => $tax->label,
            'terms'  => $terms_list,
        );
    }

    // Auteurs
    $authors = array();
    foreach ( get_users( array( 'capability' => array( 'edit_posts' ), 'number' => 100 ) ) as $u ) {
        $authors[] = array( 'slug' => $u->user_nicename, 'name' => $u->display_name );
    }

    wp_localize_script( 'rdrit-widget-visibility', 'rdritWVConfig', array(
        'roles'      => $roles,
        'caps'       => array_keys( $all_caps ),
        'langs'      => $langs,
        'pages'      => $pages_list,
        'cpts'       => $cpts,
        'taxonomies' => $taxonomies,
        'authors'    => $authors,
    ) );
}

/* ============================================================
   CSS DEVICE — injecté une seule fois en front
   ============================================================ */
add_action( 'wp_head', 'rdrit_wv_device_css', 5 );
function rdrit_wv_device_css() {
    echo '<style id="rdrit-wv-device">'
        . '@media(max-width:767px){.rdrit-hide-mobile{display:none!important}}'
        . '@media(min-width:768px) and (max-width:1024px){.rdrit-hide-tablet{display:none!important}}'
        . '@media(min-width:1025px){.rdrit-hide-desktop{display:none!important}}'
        . '.rdrit-show-mobile-only,.rdrit-show-tablet-only,.rdrit-show-desktop-only{display:none!important}'
        . '@media(max-width:767px){.rdrit-show-mobile-only{display:block!important}}'
        . '@media(min-width:768px) and (max-width:1024px){.rdrit-show-tablet-only{display:block!important}}'
        . '@media(min-width:1025px){.rdrit-show-desktop-only{display:block!important}}'
        . '</style>' . "\n";
}

/* ============================================================
   ÉVALUATION DES CONDITIONS — PHP côté serveur
   ============================================================ */
function rdrit_wv_evaluate( array $conditions, string $logic ): bool {
    if ( empty( $conditions ) ) return true;
    $results = array();
    foreach ( $conditions as $cond ) {
        $results[] = rdrit_wv_check(
            sanitize_key( $cond['type']  ?? '' ),
            $cond['value'] ?? ''
        );
    }
    return $logic === 'AND'
        ? ! in_array( false, $results, true )
        : in_array( true, $results, true );
}

function rdrit_wv_check( string $type, $value ): bool {
    switch ( $type ) {
        case 'logged_in':     return is_user_logged_in();
        case 'logged_out':    return ! is_user_logged_in();
        case 'role':
            if ( ! is_user_logged_in() ) return false;
            $user  = wp_get_current_user();
            $roles = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string)$value)));
            foreach ( $roles as $r ) { if ( in_array($r, (array)$user->roles, true) ) return true; }
            return false;
        case 'capability':
            if ( ! is_user_logged_in() ) return false;
            $caps = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string)$value)));
            foreach ( $caps as $cap ) { if ( current_user_can($cap) ) return true; }
            return false;
        case 'is_home':        return is_home();
        case 'is_front_page':  return is_front_page();
        case 'is_single':      return is_single();
        case 'is_page':
            // Valeur vide = toutes les pages — acceptable car c'est un type précis
            return empty($value) ? is_page() : is_page($value);
        case 'is_archive':     return is_archive();
        case 'is_category':    return empty($value) ? is_category() : is_category($value);
        case 'is_tag':         return empty($value) ? is_tag() : is_tag($value);
        case 'is_tax':
            if ( empty($value) ) return is_tax();
            $p = explode(':', (string)$value, 2);
            return isset($p[1]) ? is_tax($p[0], $p[1]) : is_tax($p[0]);
        case 'is_author':      return empty($value) ? is_author() : is_author($value);
        case 'is_search':      return is_search();
        case 'is_404':         return is_404();
        case 'is_date':        return is_date();
        case 'is_sticky':      return is_singular() && is_sticky( get_the_ID() );
        case 'post_type':
        case 'post_type_single':
            if ( empty( $value ) ) return false; // Valeur requise — pas de match sur vide
            $types = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string)$value)));
            return empty( $types ) ? false : is_singular( $types );
        case 'post_type_archive':
            if ( empty( $value ) ) return false; // Valeur requise
            $types = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string)$value)));
            return empty( $types ) ? false : is_post_type_archive( $types );
        case 'language':
            if ( ! function_exists('pll_current_language') ) return false;
            $langs = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string)$value)));
            return in_array( pll_current_language(), $langs, true );
        default:
            return true;
    }
}

/* ============================================================
   RENDU FRONT — approche Jetpack
   
   Deux filtres complémentaires :
   
   1. sidebars_widgets — retire le widget de la liste AVANT que WP
      génère les wrappers <aside>. C'est la clé pour éviter les
      wrappers vides. Scanne le HTML des widget_block en base.
   
   2. render_block — pour les classes CSS device sur le contenu.
   ============================================================ */

// 1. Retirer les widgets de la sidebar avant génération des wrappers
// Hook sur 'wp' pour s'assurer que $wp_query est initialisé
// avant d'évaluer les conditions de page (is_singular, post_type, etc.)
// Jetpack utilise template_redirect pour la même raison.
add_action( 'wp', 'rdrit_wv_init_filter' );
function rdrit_wv_init_filter() {
    add_filter( 'sidebars_widgets', 'rdrit_wv_sidebars_widgets' );
}
function rdrit_wv_sidebars_widgets( array $sidebars ): array {
    if ( is_admin() ) return $sidebars;
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return $sidebars;
    if ( wp_doing_ajax() ) return $sidebars;
    if ( ! (bool) get_option( 'rdrit_wv_enabled', 1 ) ) return $sidebars;

    // Cache par widget_id — évite de recalculer le même widget
    // mais PAS de cache global "done" car sidebars_widgets peut être appelé
    // avant que $wp_query soit initialisé (conditions de page renverraient false)
    static $cache = array();

    foreach ( $sidebars as $sidebar_id => $widget_ids ) {
        if ( $sidebar_id === 'wp_inactive_widgets' ) continue;
        if ( ! is_array( $widget_ids ) ) continue;
        foreach ( $widget_ids as $key => $widget_id ) {
            if ( ! isset( $cache[ $widget_id ] ) ) {
                $cache[ $widget_id ] = rdrit_wv_should_hide_widget( $widget_id );
            }
            if ( $cache[ $widget_id ] ) {
                unset( $sidebars[ $sidebar_id ][ $key ] );
            }
        }
    }
    return $sidebars;
}

/**
 * Détermine si un widget block doit être masqué.
 * Format WP des block widgets : "block-{number}" (ex: block-3)
 * Stocké dans wp_options sous la clé "widget_block".
 */
function rdrit_wv_should_hide_widget( string $widget_id ): bool {
    $debug = (bool) get_option( 'rdrit_wv_debug', 0 );

    // Cache statique — évite de recalculer sur les appels multiples de sidebars_widgets
    static $cache = array();
    if ( isset( $cache[ $widget_id ] ) ) return $cache[ $widget_id ];

    // Les block widgets ont le format "block-{N}" — vérifier exactement
    if ( ! preg_match( '/^block-(\d+)$/', $widget_id, $m ) ) {
        if ( $debug ) rdrit_wv_debug_log( "Widget $widget_id ignoré (pas un block widget)" );
        return $cache[ $widget_id ] = false;
    }

    $number    = (int) $m[1];
    $instances = get_option( 'widget_block', array() );

    if ( ! isset( $instances[ $number ]['content'] ) ) {
        if ( $debug ) rdrit_wv_debug_log( "Widget $widget_id : pas de contenu en base" );
        return false;
    }

    $content = $instances[ $number ]['content'];
    if ( empty( $content ) || ! has_blocks( $content ) ) {
        if ( $debug ) rdrit_wv_debug_log( "Widget $widget_id : pas de blocs" );
        return false;
    }
    if ( strpos( $content, 'rdritVisibility' ) === false ) {
        if ( $debug ) rdrit_wv_debug_log( "Widget $widget_id : pas de rdritVisibility" );
        return false;
    }

    if ( $debug ) {
        rdrit_wv_debug_log( "Widget $widget_id : rdritVisibility trouvé, évaluation..." );
        // Afficher le contenu brut pour voir comment il est stocké
        $preview = substr( $content, 0, 300 );
        rdrit_wv_debug_log( "Widget $widget_id contenu (300 chars): " . $preview );
    }

    $blocks = parse_blocks( $content );

    if ( $debug ) {
        foreach ( $blocks as $bi => $b ) {
            $rdrit_vis = $b['attrs']['rdritVisibility'] ?? null;
            rdrit_wv_debug_log( sprintf(
                "Widget $widget_id bloc[%d] name=%s rdritVisibility=%s",
                $bi,
                $b['blockName'] ?? 'null',
                $rdrit_vis ? json_encode( $rdrit_vis ) : 'absent'
            ) );
        }
    }

    $result = rdrit_wv_blocks_should_hide( $blocks, $debug );

    if ( $debug ) rdrit_wv_debug_log( "Widget $widget_id : résultat hide=" . ($result ? 'true' : 'false') );
    $cache[ $widget_id ] = $result;
    return $result;
}

function rdrit_wv_blocks_should_hide( array $blocks, bool $debug = false ): bool {
    foreach ( $blocks as $block ) {
        $vis = $block['attrs']['rdritVisibility'] ?? array();

        if ( ! empty( $vis['conditions'] ) ) {
            $conditions = $vis['conditions'];
            $logic      = $vis['logic'] ?? 'OR';
            $mode       = $vis['mode']  ?? 'show';

            $non_device = array_values( array_filter( $conditions, function($c) {
                return ( $c['type'] ?? '' ) !== 'device';
            }));

            if ( ! empty( $non_device ) ) {
                $pass = rdrit_wv_evaluate( $non_device, $logic );
                $show = ( $mode === 'show' ) ? $pass : ! $pass;

                if ( $debug ) {
                    foreach ( $non_device as $c ) {
                        $single = rdrit_wv_check( $c['type'] ?? '', $c['value'] ?? '' );
                        $extra  = '';
                        if ( in_array( $c['type'] ?? '', array('post_type_single','post_type'), true ) ) {
                            global $wp_query, $post;
                            $extra = sprintf(
                                ' [wp_query->is_singular=%s post_type=%s queried=%s]',
                                ( $wp_query && $wp_query->is_singular ) ? 'true' : 'false',
                                $post ? $post->post_type : 'null',
                                is_singular( $c['value'] ?? '' ) ? 'true' : 'false'
                            );
                        }
                        if ( $c['type'] === 'role' ) {
                            $user  = is_user_logged_in() ? wp_get_current_user() : null;
                            $extra = sprintf(
                                ' [logged_in=%s user_roles=%s value_received=%s]',
                                is_user_logged_in() ? 'true' : 'false',
                                $user ? implode(',', (array)$user->roles) : 'N/A',
                                json_encode( $c['value'] ?? '' )
                            );
                        }
                        if ( $c['type'] === 'capability' ) {
                            $extra = sprintf(
                                ' [logged_in=%s cap_check=%s value_received=%s]',
                                is_user_logged_in() ? 'true' : 'false',
                                ( is_user_logged_in() && ! empty($c['value']) )
                                    ? ( current_user_can( is_array($c['value']) ? $c['value'][0] : $c['value'] ) ? 'true' : 'false' )
                                    : 'N/A',
                                json_encode( $c['value'] ?? '' )
                            );
                        }
                        rdrit_wv_debug_log( sprintf(
                            '  Condition type=%s value=%s → %s%s',
                            $c['type'] ?? '',
                            json_encode( $c['value'] ?? '' ),
                            $single ? 'true' : 'false',
                            $extra
                        ) );
                    }
                    rdrit_wv_debug_log( "  pass=$pass, mode=$mode, show=" . ($show ? 'true' : 'false') );
                }

                if ( ! $show ) return true;
            }
        }

        if ( ! empty( $block['innerBlocks'] ) ) {
            if ( rdrit_wv_blocks_should_hide( $block['innerBlocks'], $debug ) ) return true;
        }
    }
    return false;
}

/**
 * Émet un commentaire HTML de debug (visible dans le source).
 * Uniquement pour les admins.
 */
function rdrit_wv_debug_log( string $msg ): void {
    if ( ! current_user_can( 'manage_options' ) ) return;
    static $header_done = false;
    if ( ! $header_done ) {
        add_action( 'wp_head', function() {
            global $post;
            $ctx = array(
                'post_type'   => $post ? $post->post_type : 'N/A',
                'is_singular' => is_singular() ? 'true' : 'false',
                'is_archive'  => is_archive() ? 'true' : 'false',
                'is_home'     => is_home() ? 'true' : 'false',
            );
            echo "<!-- RDRIT Widget Visibility DEBUG -->\n";
            echo "<!-- WV CTX: post_type=" . esc_html( $ctx['post_type'] )
                . " singular=" . $ctx['is_singular']
                . " archive=" . $ctx['is_archive']
                . " -->\n";
        }, 999 );
        $header_done = true;
    }
    add_action( 'wp_footer', function() use ( $msg ) {
        echo "<!-- WV: " . esc_html( $msg ) . " -->\n";
    }, 999 );
}

// 2. render_block — uniquement pour les classes CSS device
add_filter( 'render_block', 'rdrit_wv_render_device', 10, 2 );
function rdrit_wv_render_device( string $content, array $block ): string {
    $vis    = $block['attrs']['rdritVisibility'] ?? array();
    $device = $vis['device'] ?? array();
    $mode   = $vis['mode']   ?? 'show';

    if ( empty( $device ) || empty( $content ) ) return $content;

    $map = array(
        'mobile'  => array( 'show' => 'rdrit-show-mobile-only',  'hide' => 'rdrit-hide-mobile'  ),
        'tablet'  => array( 'show' => 'rdrit-show-tablet-only',  'hide' => 'rdrit-hide-tablet'  ),
        'desktop' => array( 'show' => 'rdrit-show-desktop-only', 'hide' => 'rdrit-hide-desktop' ),
    );
    $classes = array();
    foreach ( $device as $d ) {
        if ( isset( $map[$d][$mode] ) ) $classes[] = $map[$d][$mode];
    }
    if ( ! $classes ) return $content;

    // Injecter sur le premier tag du bloc
    $patched = preg_replace(
        '/(<[a-z][a-z0-9]*\s[^>]*class=")/',
        '$1' . esc_attr( implode(' ', $classes) ) . ' ',
        $content, 1
    );
    return $patched ?: $content;
}

/* ============================================================
   LiteSpeed — no-cache pour utilisateurs connectés
   
   Limitation connue sans ESI :
   - Connecté → no-cache → conditions évaluées en temps réel ✅
   - Anonyme → cache actif → "afficher si non connecté" ✅
   - Anonyme → cache actif → "masquer si non connecté" ❌ (nécessite ESI)
   
   Sans LiteSpeed ESI (Community), il est impossible de servir
   un contenu différent aux anonymes selon les conditions utilisateur
   tout en gardant le cache actif.
   ============================================================ */
add_action( 'wp_head', 'rdrit_wv_litespeed_nocache', 0 );
function rdrit_wv_litespeed_nocache() {
    if ( ! (bool) get_option( 'rdrit_wv_litespeed', 1 ) ) return;

    // Uniquement pour les connectés — les anonymes gardent le cache
    if ( ! is_user_logged_in() ) return;

    $user_condition_types = array( 'logged_in', 'logged_out', 'role', 'capability' );
    $widget_blocks = get_option( 'widget_block', array() );

    foreach ( $widget_blocks as $instance ) {
        if ( empty( $instance['content'] ) ) continue;
        if ( strpos( $instance['content'], 'rdritVisibility' ) === false ) continue;

        if ( preg_match_all(
            '/<!--\s*wp:[^\s>]+\s+(\{[^>]+\})\s*(?:\/)?-->/s',
            $instance['content'],
            $matches
        ) ) {
            foreach ( $matches[1] as $json_str ) {
                $attrs = json_decode( $json_str, true );
                if ( ! is_array( $attrs ) ) continue;
                $vis = $attrs['rdritVisibility'] ?? array();
                if ( empty( $vis['conditions'] ) ) continue;
                foreach ( $vis['conditions'] as $c ) {
                    if ( in_array( $c['type'] ?? '', $user_condition_types, true ) ) {
                        do_action( 'litespeed_control_set_nocache', 'rdrit_wv_user_cond' );
                        return;
                    }
                }
            }
        }
    }
}
