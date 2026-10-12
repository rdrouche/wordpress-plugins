<?php
/**
 * Plugin Name:  RDR-IT Glossaire
 * Plugin URI:   https://rdr-it.com
 * Description:  Glossaire technique avec tooltips inline — compatible Polylang, async, dark/light.
 * Version:      1.0.0
 * Author:       Romain Drouche
 * Author URI:   https://rdr-it.com
 * Text Domain:  rdr-glossaire
 * Domain Path:  /languages
 * License:      GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RDRG_VERSION',   '1.3.2' );
define( 'RDRG_DIR',       plugin_dir_path( __FILE__ ) );
define( 'RDRG_URL',       plugin_dir_url( __FILE__ ) );
define( 'RDRG_CPT',       'rdr_glossaire' );
define( 'RDRG_OPTION',    'rdr_glossaire_settings' );

/**
 * Vérifie en toute sécurité si la page courante contient le shortcode [rdrg_glossaire].
 * get_post() peut retourner null si appelé trop tôt (ex: wp_enqueue_scripts).
 */
function rdrg_page_has_shortcode() {
    if ( ! is_page() ) return false;
    $post = get_post();
    if ( ! $post ) return false;
    return has_shortcode( $post->post_content, 'rdrg_glossaire' );
}

/**
 * Retourne l'extrait d'un terme glossaire — excerpt WP en priorité, sinon contenu tronqué.
 * Valeur unique utilisée partout (shortcode, REST API, Rank Math).
 */
function rdrg_term_excerpt( $post, int $words = 80 ): string {
    if ( ! $post ) return '';
    $text = $post->post_excerpt ?: wp_strip_all_tags( $post->post_content );
    return wp_trim_words( $text, $words, '…' );
}



/* ============================================================
   1. CHARGEMENT FICHIERS
   ============================================================ */
require_once RDRG_DIR . 'admin/admin-page.php';
require_once RDRG_DIR . 'seo/schema.php';

add_action( 'init', 'rdrg_register_cpt' );
function rdrg_register_cpt() {
    $labels = [
        'name'               => __( 'Glossaire', 'rdr-glossaire' ),
        'singular_name'      => __( 'Définition', 'rdr-glossaire' ),
        'add_new'            => __( 'Ajouter', 'rdr-glossaire' ),
        'add_new_item'       => __( 'Ajouter une définition', 'rdr-glossaire' ),
        'edit_item'          => __( 'Modifier la définition', 'rdr-glossaire' ),
        'search_items'       => __( 'Rechercher', 'rdr-glossaire' ),
        'not_found'          => __( 'Aucune définition trouvée.', 'rdr-glossaire' ),
        'menu_name'          => __( 'Glossaire', 'rdr-glossaire' ),
    ];

    register_post_type( RDRG_CPT, [
        'labels'              => $labels,
        'public'              => true,          // indexable
        'publicly_queryable'  => true,
        'show_ui'             => true,
        'show_in_menu'        => false,
        'show_in_rest'        => true,
        'capability_type'     => 'post',
        'supports'            => [ 'title', 'editor', 'excerpt', 'custom-fields' ],
        'has_archive'         => false,         // pas d'archive native WP
        'rewrite'             => [
            'slug'       => 'glossaire',
            'with_front' => false,
        ],
        'query_var'           => true,
    ] );
}





add_filter( 'single_template', 'rdrg_single_template' );
function rdrg_single_template( $template ) {
    if ( get_post_type() === RDRG_CPT ) {
        return RDRG_DIR . 'templates/glossaire-single.php';
    }
    return $template;
}

/* ============================================================
   4. SHORTCODE [rdrg_glossaire]
      Affiche la page index glossaire dans une page WP native.
      Polylang gère nativement la traduction de la page.
   ============================================================ */
add_action( 'init', 'rdrg_register_shortcode' );
function rdrg_register_shortcode() {
    add_shortcode( 'rdrg_glossaire', 'rdrg_shortcode_render' );
}

/* ============================================================
   4b. SHORTCODE [rdrg_switch]
       Insère un toggle glossaire inline, à l'endroit exact
       où le shortcode est placé dans le contenu.
       Indépendant de la config admin (position automatique).
   ============================================================ */
add_action( 'init', 'rdrg_register_switch_shortcode' );
function rdrg_register_switch_shortcode() {
    add_shortcode( 'rdrg_switch', 'rdrg_switch_shortcode_render' );
}

function rdrg_switch_shortcode_render( $atts ) {
    $atts = shortcode_atts( [], $atts, 'rdrg_switch' );

    // Enqueue le CSS du switch inline
    wp_enqueue_style(
        'rdr-glossaire-float-css',
        RDRG_URL . 'assets/glossaire-float.css',
        [],
        RDRG_VERSION
    );

    // Le JS principal doit être chargé (tooltips + toggle logic)
    $settings = get_option( RDRG_OPTION, [] );
    wp_enqueue_script( 'rdr-glossaire-js', RDRG_URL . 'assets/glossaire.js', [], RDRG_VERSION, true );
    wp_localize_script( 'rdr-glossaire-js', 'rdrGlossaire', [
        'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
        'nonce'         => wp_create_nonce( 'rdrg_nonce' ),
        'restUrl'       => rest_url( 'rdr-glossaire/v1/' ),
        'restNonce'     => wp_create_nonce( 'wp_rest' ),
        'lang'          => function_exists( 'pll_current_language' ) ? pll_current_language() : '',
        'matchMode'     => $settings['match_mode'] ?? 'first_block',
        'showLink'      => ! empty( $settings['show_link'] ) ? '1' : '0',
        'floatSwitch'   => false,   // shortcode = inline, pas flottant
        'floatPosition' => 'under-title',
        'debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
        'i18n' => [
            'labelOn'      => rdrg_t( 'switch_label_on' ),
            'labelOff'     => rdrg_t( 'switch_label_off' ),
            'switchTitle'  => rdrg_t( 'switch_title' ),
            'switchAria'   => rdrg_t( 'switch_aria' ),
            'tooltipLearn' => rdrg_t( 'tooltip_learn_more' ),
            'openLink'     => rdrg_t( 'tooltip_open_link' ),
            'defPrefix'    => rdrg_t( 'definition_aria_prefix' ),
        ],
    ] );

    // Lire l'état depuis localStorage via JS — on génère le HTML initial
    // Le JS mettra à jour l'état au chargement
    $label_on  = esc_attr( rdrg_t( 'switch_label_on' ) );
    $label_off = esc_attr( rdrg_t( 'switch_label_off' ) );
    $title     = esc_attr( rdrg_t( 'switch_title' ) );
    $aria      = esc_attr( rdrg_t( 'switch_aria' ) );

    return '<div class="rdrg-switch-wrap rdrg-switch-shortcode" role="region" aria-label="Glossaire interactif"
                 data-label-on="' . $label_on . '"
                 data-label-off="' . $label_off . '">'
         . '<label class="rdrg-inline-switch" title="' . $title . '">'
         . '<input type="checkbox" id="rdrg-toggle-checkbox" aria-label="' . $aria . '">'
         . '<span class="rdrg-track"><span class="rdrg-thumb"></span></span>'
         . '<span class="rdrg-label" id="rdrg-switch-label">📚 Glossaire</span>'
         . '</label>'
         . '</div>';
}



function rdrg_shortcode_render( $atts ) {
    $atts = shortcode_atts( [], $atts, 'rdrg_glossaire' );

    $lang = function_exists( 'pll_current_language' ) ? pll_current_language() : '';

    // Requête SSR — tous les termes de la langue courante
    $query_args = [
        'post_type'      => RDRG_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => '_rdrg_term_enabled', 'value' => '1', 'compare' => '=' ],
            [ 'key' => '_rdrg_term_enabled', 'compare' => 'NOT EXISTS' ],
        ],
    ];
    if ( $lang ) $query_args['lang'] = $lang;

    $all_terms = get_posts( $query_args );

    // Grouper par première lettre
    $by_letter = [];
    foreach ( $all_terms as $term ) {
        $first = strtoupper( mb_substr( $term->post_title, 0, 1 ) );
        if ( ! ctype_alpha( $first ) ) $first = '#';
        $by_letter[ $first ][] = $term;
    }
    ksort( $by_letter );
    $letters_available = array_keys( $by_letter );
    $total             = count( $all_terms );

    // Enqueue assets
    wp_enqueue_style( 'rdr-glossaire-page-css', RDRG_URL . 'assets/glossaire-page.css', [], RDRG_VERSION );
    wp_enqueue_script( 'rdr-glossaire-index-js', RDRG_URL . 'assets/glossaire-index.js', [], RDRG_VERSION, true );

    ob_start();
    ?>
    <div class="rdrg-glossaire-shortcode">

        <!-- ── En-tête ─────────────────────────────────── -->
        <div class="rdrg-sc-header">
            <p class="rdrg-hero-sub">
                <?php printf(
                    _n(
                        rdrg_t( 'sc_count_singular' ) ?: '%d définition de termes techniques IT.',
                        rdrg_t( 'sc_count_plural' )   ?: '%d définitions de termes techniques IT.',
                        $total,
                        'rdr-glossaire'
                    ),
                    $total
                ); ?>
            </p>

            <!-- Recherche live -->
            <div class="rdrg-search-wrap">
                <input type="search"
                       id="rdrg-search"
                       class="rdrg-search-input"
                       placeholder="<?php echo esc_attr( rdrg_t( 'sc_search_placeholder' ) ?: 'Rechercher un terme… ex: DHCP, DNS, VLAN' ); ?>"
                       autocomplete="off"
                       aria-label="<?php echo esc_attr( rdrg_t( 'sc_search_aria' ) ?: 'Rechercher dans le glossaire' ); ?>">
                <span class="rdrg-search-icon">🔍</span>
                <span class="rdrg-search-count" id="rdrg-search-count" aria-live="polite"></span>
            </div>
        </div>

        <!-- ── Navigation alphabétique ─────────────────── -->
        <nav class="rdrg-alpha-nav rdrg-alpha-nav-inline" aria-label="<?php echo esc_attr( rdrg_t( 'sc_alpha_nav_aria' ) ?: 'Navigation alphabétique' ); ?>">
            <div class="rdrg-alpha-inner">
                <button class="rdrg-alpha-btn active" data-letter="all"><?php echo esc_html( rdrg_t( 'sc_all' ) ?: 'Tout' ); ?></button>
                <?php
                $alphabet = array_merge( ['#'], range( 'A', 'Z' ) );
                foreach ( $alphabet as $l ) :
                    $available = in_array( $l, $letters_available, true );
                ?>
                <button class="rdrg-alpha-btn<?php echo $available ? '' : ' rdrg-alpha-empty'; ?>"
                        data-letter="<?php echo esc_attr( $l ); ?>"
                        <?php echo $available ? '' : 'disabled aria-disabled="true"'; ?>>
                    <?php echo esc_html( $l ); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </nav>

        <!-- ── Contenu SSR ─────────────────────────────── -->
        <div class="rdrg-glossaire-content" id="rdrg-glossaire-content">
            <?php if ( empty( $all_terms ) ) : ?>
                <p class="rdrg-empty"><?php echo esc_html( rdrg_t( 'sc_no_terms' ) ?: 'Aucune définition publiée pour le moment.' ); ?></p>
            <?php else : ?>
                <?php foreach ( $by_letter as $letter => $terms ) : ?>
                <section class="rdrg-letter-section" data-letter="<?php echo esc_attr( $letter ); ?>" id="lettre-<?php echo esc_attr( $letter ); ?>">
                    <h2 class="rdrg-letter-heading"><?php echo esc_html( $letter ); ?></h2>
                    <div class="rdrg-terms-grid">
                        <?php foreach ( $terms as $term ) :
                            $aliases = get_post_meta( $term->ID, '_rdrg_aliases', true );
                            $link    = get_post_meta( $term->ID, '_rdrg_link', true );
                            $excerpt = rdrg_term_excerpt( $term );
                            $url     = get_permalink( $term->ID );
                        ?>
                        <article class="rdrg-term-card"
                                 data-term="<?php echo esc_attr( strtolower( $term->post_title ) ); ?>"
                                 data-aliases="<?php echo esc_attr( strtolower( $aliases ) ); ?>">
                            <h3 class="rdrg-term-title">
                                <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $term->post_title ); ?></a>
                            </h3>
                            <?php if ( $aliases ) : ?>
                            <p class="rdrg-term-aliases"><?php echo esc_html( $aliases ); ?></p>
                            <?php endif; ?>
                            <p class="rdrg-term-excerpt"><?php echo esc_html( $excerpt ); ?></p>
                            <div class="rdrg-term-footer">
                                <a href="<?php echo esc_url( $url ); ?>" class="rdrg-term-read-more">
                                    <?php echo esc_html( rdrg_t( 'sc_read_more' ) ?: 'Lire la définition complète →' ); ?>
                                </a>
                                <?php if ( $link ) : ?>
                                <a href="<?php echo esc_url( $link ); ?>" class="rdrg-term-ext-link">
                                    <?php echo esc_html( rdrg_t( 'sc_article_link' ) ?: 'Article →' ); ?>
                                </a>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>
                <p class="rdrg-no-results" id="rdrg-no-results" hidden>
                    <?php echo esc_html( rdrg_t( 'sc_no_results' ) ?: 'Aucun terme ne correspond à votre recherche.' ); ?>
                </p>
            <?php endif; ?>
        </div>

    </div>
    <?php
    return ob_get_clean();
}

/* ============================================================
   6. RANK MATH — meta SEO pour le CPT et la page index
   ============================================================ */
add_filter( 'rank_math/frontend/description', 'rdrg_rank_math_desc' );
function rdrg_rank_math_desc( $desc ) {
    // Page index
    if ( ( is_page() && rdrg_page_has_shortcode() ) ) {
        return __( 'Glossaire des termes techniques IT — définitions de DHCP, DNS, Active Directory, Docker, Kubernetes et bien d\'autres concepts réseau, système et virtualisation.', 'rdr-glossaire' );
    }
    // Single terme : description = extrait ou début du contenu
    if ( is_singular( RDRG_CPT ) ) {
        global $post;
        return rdrg_term_excerpt( $post, 30 );
    }
    return $desc;
}

/* Titre Rank Math pour la page index */
add_filter( 'rank_math/frontend/title', 'rdrg_rank_math_title' );
function rdrg_rank_math_title( $title ) {
    if ( ( is_page() && rdrg_page_has_shortcode() ) ) {
        return __( 'Glossaire IT — Définitions des termes techniques', 'rdr-glossaire' ) . ' | ' . get_bloginfo( 'name' );
    }
    return $title;
}

/* ============================================================
   7. ENQUEUE ASSETS PAGES GLOSSAIRE
   ============================================================ */
add_action( 'wp_enqueue_scripts', 'rdrg_enqueue_pages' );
function rdrg_enqueue_pages() {
    $is_single = is_singular( RDRG_CPT );
    if ( ! $is_single ) return;

    wp_enqueue_style(
        'rdr-glossaire-page-css',
        RDRG_URL . 'assets/glossaire-page.css',
        [],
        RDRG_VERSION
    );
}

/* ============================================================
   8. LIEN "Glossaire" DANS LE MENU ADMIN — accès rapide
   ============================================================ */
add_filter( 'page_row_actions', 'rdrg_cpt_row_actions', 10, 2 );
function rdrg_cpt_row_actions( $actions, $post ) {
    if ( $post->post_type === RDRG_CPT && $post->post_status === 'publish' ) {
        $actions['view_front'] = '<a href="' . esc_url( get_permalink( $post->ID ) ) . '" target="_blank">' . __( 'Voir', 'rdr-glossaire' ) . '</a>';
    }
    return $actions;
}

/* ============================================================
   10. ENQUEUE FRONT-END TOOLTIPS (conditionnel)
   ============================================================ */
add_action( 'wp_enqueue_scripts', 'rdrg_enqueue_front' );
function rdrg_enqueue_front() {
    $settings = get_option( RDRG_OPTION, [] );

    // 1. Glossaire désactivé globalement → rien
    if ( empty( $settings['enabled'] ) ) return;

    // 2. Archives, home, search, 404… → rien (singles uniquement)
    if ( ! is_singular() ) return;

    // 3. Vérifier que le post type courant est dans la liste autorisée
    $allowed_types = isset( $settings['post_types'] ) ? (array) $settings['post_types'] : [ 'post' ];
    $current_type  = get_post_type();
    if ( ! in_array( $current_type, $allowed_types, true ) ) return;

    // 4. Désactivé manuellement sur cet article via la meta box
    $post_id  = get_queried_object_id();
    $disabled = get_post_meta( $post_id, '_rdrg_disabled', true );
    if ( $disabled ) return;

    wp_enqueue_style(
        'rdr-glossaire-css',
        RDRG_URL . 'assets/glossaire.css',
        [],
        RDRG_VERSION
    );

    // CSS flottant si position floating
    $switch_pos = $settings['switch_position'] ?? 'under-title';
    if ( in_array( $switch_pos, [ 'floating-left', 'floating-right' ] ) ) {
        wp_enqueue_style(
            'rdr-glossaire-float-css',
            RDRG_URL . 'assets/glossaire-float.css',
            [ 'rdr-glossaire-css' ],
            RDRG_VERSION
        );
    }

    wp_enqueue_script(
        'rdr-glossaire-js',
        RDRG_URL . 'assets/glossaire.js',
        [],
        RDRG_VERSION,
        true // footer
    );

    wp_localize_script( 'rdr-glossaire-js', 'rdrGlossaire', [
        'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
        'nonce'         => wp_create_nonce( 'rdrg_nonce' ),
        'restUrl'       => rest_url( 'rdr-glossaire/v1/' ),
        'restNonce'     => wp_create_nonce( 'wp_rest' ),
        'lang'          => function_exists( 'pll_current_language' ) ? pll_current_language() : '',
        'matchMode'     => $settings['match_mode'] ?? 'first_block',
        'showLink'      => ! empty( $settings['show_link'] ) ? '1' : '0',
        'floatSwitch'   => in_array( $settings['switch_position'] ?? 'under-title', [ 'floating-left', 'floating-right' ] ),
        'floatPosition' => $settings['switch_position'] ?? 'under-title',
        'debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
        'i18n' => [
            'labelOn'      => rdrg_t( 'switch_label_on' ),
            'labelOff'     => rdrg_t( 'switch_label_off' ),
            'switchTitle'  => rdrg_t( 'switch_title' ),
            'switchAria'   => rdrg_t( 'switch_aria' ),
            'tooltipLearn' => rdrg_t( 'tooltip_learn_more' ),
            'openLink'     => rdrg_t( 'tooltip_open_link' ),
            'defPrefix'    => rdrg_t( 'definition_aria_prefix' ),
        ],
    ] );
}

/* ============================================================
   5. REST API — endpoint async GET /rdr-glossaire/v1/terms
      Retourne tous les termes + définitions en JSON
      Réponse mise en cache via transient (10 minutes)
   ============================================================ */
add_action( 'rest_api_init', 'rdrg_register_rest_routes' );
function rdrg_register_rest_routes() {
    register_rest_route( 'rdr-glossaire/v1', '/terms', [
        'methods'             => 'GET',
        'callback'            => 'rdrg_rest_get_terms',
        'permission_callback' => '__return_true',
        'args' => [
            'lang' => [
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ],
        ],
    ] );

    // Endpoint single term (pour le tooltip on-demand, optionnel)
    register_rest_route( 'rdr-glossaire/v1', '/term/(?P<slug>[a-zA-Z0-9_-]+)', [
        'methods'             => 'GET',
        'callback'            => 'rdrg_rest_get_single_term',
        'permission_callback' => '__return_true',
        'args' => [
            'lang' => [
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ],
        ],
    ] );
}

/**
 * Retourne tous les termes du glossaire.
 * Cache transient 10 min pour performance maximale.
 */
function rdrg_rest_get_terms( WP_REST_Request $request ) {
    $lang        = $request->get_param( 'lang' );
    $cache_key   = 'rdrg_terms_' . ( $lang ?: 'default' );
    $cached      = get_transient( $cache_key );

    if ( false !== $cached ) {
        return rest_ensure_response( $cached );
    }

    $terms = rdrg_fetch_terms( $lang );
    set_transient( $cache_key, $terms, 10 * MINUTE_IN_SECONDS );

    return rest_ensure_response( $terms );
}

/**
 * Retourne un terme unique par slug.
 */
function rdrg_rest_get_single_term( WP_REST_Request $request ) {
    $slug = $request->get_param( 'slug' );
    $lang = $request->get_param( 'lang' );

    $args = [
        'post_type'      => RDRG_CPT,
        'post_status'    => 'publish',
        'name'           => $slug,
        'posts_per_page' => 1,
    ];

    if ( $lang && function_exists( 'pll_get_post_translations' ) ) {
        $args['lang'] = $lang;
    }

    $q = new WP_Query( $args );
    if ( ! $q->have_posts() ) {
        return new WP_Error( 'not_found', 'Term not found', [ 'status' => 404 ] );
    }

    $p       = $q->posts[0];
    $aliases = get_post_meta( $p->ID, '_rdrg_aliases', true );
    $link    = get_post_meta( $p->ID, '_rdrg_link', true );

    return rest_ensure_response( [
        'id'         => $p->ID,
        'title'      => $p->post_title,
        'definition' => wp_strip_all_tags( $p->post_content ),
        'aliases'    => $aliases ? array_map( 'trim', explode( ',', $aliases ) ) : [],
        'link'       => $link ?: '',
        'slug'       => $p->post_name,
    ] );
}

/**
 * Helper interne : récupère les termes strictement dans la langue courante.
 * Si Polylang est actif, seuls les termes traduits dans $lang sont retournés.
 * Les termes sans traduction sont silencieusement ignorés.
 */
function rdrg_fetch_terms( $lang = '' ) {
    $args = [
        'post_type'      => RDRG_CPT,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
    ];

    // Filtre strict : uniquement les posts dans la langue demandée
    if ( $lang && function_exists( 'pll_get_post_language' ) ) {
        $args['lang'] = $lang;
    }

    $query = new WP_Query( $args );
    $terms = [];

    foreach ( $query->posts as $p ) {
        $aliases    = get_post_meta( $p->ID, '_rdrg_aliases', true );
        $link       = get_post_meta( $p->ID, '_rdrg_link', true );
        $definition = rdrg_term_excerpt( $p );

        $match_terms = [ strtolower( $p->post_title ) ];
        if ( $aliases ) {
            foreach ( explode( ',', $aliases ) as $alias ) {
                $a = trim( strtolower( $alias ) );
                if ( $a ) $match_terms[] = $a;
            }
        }

        $terms[] = [
            'id'         => $p->ID,
            'title'      => $p->post_title,
            'definition' => $definition,
            'terms'      => $match_terms,
            'link'       => $link ?: '',
            'slug'       => $p->post_name,
        ];
    }

    return $terms;
}

/* ============================================================
   6. VIDER LE CACHE TRANSIENT quand un glossaire est modifié
   ============================================================ */
add_action( 'save_post_' . RDRG_CPT, 'rdrg_clear_cache' );
add_action( 'delete_post',            'rdrg_clear_cache_on_delete' );

function rdrg_clear_cache() {
    // Vider tous les transients de langue
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rdrg_terms_%'"
    );
    $wpdb->query(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_rdrg_terms_%'"
    );
}

function rdrg_clear_cache_on_delete( $post_id ) {
    if ( get_post_type( $post_id ) === RDRG_CPT ) {
        rdrg_clear_cache();
    }
}

/* ============================================================
   7. META BOX ARTICLE — Désactiver le glossaire sur cet article
   ============================================================ */
add_action( 'add_meta_boxes', 'rdrg_add_meta_box' );
function rdrg_add_meta_box() {
    $settings = get_option( RDRG_OPTION, [] );
    if ( empty( $settings['enabled'] ) ) return;

    $post_types = isset( $settings['post_types'] ) ? (array) $settings['post_types'] : [ 'post' ];
    foreach ( $post_types as $pt ) {
        add_meta_box(
            'rdrg-toggle',
            __( '📚 Glossaire RDR-IT', 'rdr-glossaire' ),
            'rdrg_meta_box_render',
            $pt,
            'side',
            'default'
        );
    }
}

function rdrg_meta_box_render( $post ) {
    wp_nonce_field( 'rdrg_meta_nonce', 'rdrg_meta_nonce_field' );
    $disabled = get_post_meta( $post->ID, '_rdrg_disabled', true );
    ?>
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
        <input type="checkbox"
               name="rdrg_disabled"
               value="1"
               <?php checked( $disabled, '1' ); ?>
               style="width:16px;height:16px;">
        <span><?php _e( 'Désactiver les tooltips sur cet article', 'rdr-glossaire' ); ?></span>
    </label>
    <p style="margin-top:8px;font-size:11px;color:#666;">
        <?php _e( 'Par défaut, les tooltips glossaire sont actifs sur tous les articles.', 'rdr-glossaire' ); ?>
    </p>
    <?php
}

add_action( 'save_post', 'rdrg_save_meta_box' );
function rdrg_save_meta_box( $post_id ) {
    if ( ! isset( $_POST['rdrg_meta_nonce_field'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['rdrg_meta_nonce_field'], 'rdrg_meta_nonce' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

    if ( isset( $_POST['rdrg_disabled'] ) && $_POST['rdrg_disabled'] == '1' ) {
        update_post_meta( $post_id, '_rdrg_disabled', '1' );
    } else {
        delete_post_meta( $post_id, '_rdrg_disabled' );
    }
}

/* ============================================================
   8. AJAX TOGGLE GLOBAL (depuis le switch sous le titre en front)
      Stocké en localStorage côté client — pas de state serveur
      (compatible LiteSpeed Cache)
   ============================================================ */
// Note : le toggle front-end est 100% localStorage (comme le dark/light mode).
// Pas d'AJAX nécessaire pour le toggle utilisateur.
// L'AJAX ci-dessous est gardé pour usage futur / debug.

add_action( 'wp_ajax_rdrg_toggle',        'rdrg_ajax_toggle' );
add_action( 'wp_ajax_nopriv_rdrg_toggle', 'rdrg_ajax_toggle' );
function rdrg_ajax_toggle() {
    check_ajax_referer( 'rdrg_nonce', 'nonce' );
    wp_send_json_success( [ 'ok' => true ] );
}

/* ============================================================
   9. ACTIVATION / DÉSACTIVATION PLUGIN
   ============================================================ */
register_activation_hook( __FILE__, 'rdrg_activate' );
function rdrg_activate() {
    // Créer les options par défaut
    if ( ! get_option( RDRG_OPTION ) ) {
        update_option( RDRG_OPTION, [
            'enabled'     => 0,
            'post_types'  => [ 'post' ],
            'tooltip_pos' => 'top',
            'show_link'   => 1,
        ] );
    }
    // Forcer le flush des rewrite rules
    rdrg_register_cpt();
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'rdrg_deactivate' );
function rdrg_deactivate() {
    rdrg_clear_cache();
    flush_rewrite_rules();
}

/* ============================================================
   10. POLYLANG — Chaînes traduisibles du plugin
   ============================================================ */

/**
 * Toutes les chaînes front-end traduisibles.
 * Clé => valeur par défaut (français).
 * Enregistrées dans Polylang via pll_register_string().
 * Récupérées via rdrg_t() qui utilise pll__() si Polylang est actif.
 */
define( 'RDRG_STRINGS', [
    // Tooltips
    'switch_label_on'        => '📚 Glossaire actif — survolez les termes techniques pour une définition',
    'switch_label_off'       => '📚 Glossaire désactivé',
    'switch_title'           => 'Activer / désactiver les tooltips glossaire',
    'switch_aria'            => 'Activer le glossaire interactif',
    'tooltip_learn_more'     => 'En savoir plus →',
    'tooltip_open_link'      => 'Ouvrir le lien →',
    'definition_aria_prefix' => 'Définition :',
    // Shortcode page index
    'sc_count_singular'      => '%d définition de termes techniques IT.',
    'sc_count_plural'        => '%d définitions de termes techniques IT.',
    'sc_search_placeholder'  => 'Rechercher un terme… ex: DHCP, DNS, VLAN',
    'sc_search_aria'         => 'Rechercher dans le glossaire',
    'sc_alpha_nav_aria'      => 'Navigation alphabétique',
    'sc_all'                 => 'Tout',
    'sc_no_terms'            => 'Aucune définition publiée pour le moment.',
    'sc_no_results'          => 'Aucun terme ne correspond à votre recherche.',
    'sc_read_more'           => 'Lire la définition complète →',
    'sc_article_link'        => 'Article →',
] );

/* Enregistrement dans Polylang au bon moment */
add_action( 'init', 'rdrg_register_pll_strings' );
function rdrg_register_pll_strings() {
    if ( ! function_exists( 'pll_register_string' ) ) return;
    foreach ( RDRG_STRINGS as $key => $value ) {
        pll_register_string( $key, $value, 'RDR-IT Glossaire' );
    }
}

/**
 * Helper : retourne la chaîne traduite si Polylang est actif,
 * sinon retourne la valeur par défaut française.
 *
 * @param string $key  Clé dans RDRG_STRINGS
 * @return string
 */
function rdrg_t( $key ) {
    $strings = RDRG_STRINGS;
    $default = $strings[ $key ] ?? $key;

    if ( function_exists( 'pll__' ) ) {
        $translated = pll__( $default );
        // pll__() retourne la chaîne originale si pas de traduction — on garde le défaut
        return ( $translated && $translated !== $default ) ? $translated : $default;
    }

    return $default;
}

/* Compatibilité CPT Polylang */
add_filter( 'pll_get_post_types', 'rdrg_pll_post_types' );
function rdrg_pll_post_types( $post_types ) {
    $settings = get_option( RDRG_OPTION, [] );
    if ( ! empty( $settings['polylang'] ) ) {
        $post_types[ RDRG_CPT ] = RDRG_CPT;
    }
    return $post_types;
}
