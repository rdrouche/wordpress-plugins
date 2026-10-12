<?php
/**
 * Plugin Name: RDR Bookmarks
 * Plugin URI:  https://example.com
 * Description: Favoris / À lire plus tard via localStorage. Polylang, RGPD, floating stack.
 * Version:     2.1.0
 * Author:      RDR
 * Text Domain: rdr-bookmarks
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'RDR_BM_VERSION', '2.1.0' );
define( 'RDR_BM_DIR',     plugin_dir_path( __FILE__ ) );
define( 'RDR_BM_URL',     plugin_dir_url( __FILE__ ) );

require_once RDR_BM_DIR . 'admin/rdr-bookmarks-admin.php';
require_once RDR_BM_DIR . 'admin/rdr-bookmarks-shortcode.php';

/* ── Activation ─────────────────────────────── */
register_activation_hook( __FILE__, function () {
    add_option( 'rdr_bm_enabled_cpts', [] );
    add_option( 'rdr_bm_emoji_favorite',  '★' );
    add_option( 'rdr_bm_emoji_read_later', '🕐' );
    add_option( 'rdr_bm_labels', [
        'favorite'   => 'Favoris',
        'read_later' => 'À lire',
        'search'     => 'Rechercher…',
        'consent'    => "Ce site utilise le stockage local (localStorage) pour sauvegarder vos favoris. Aucune donnée n'est transmise à un serveur. Acceptez-vous ?",
        'accept'     => 'Accepter',
        'decline'    => 'Refuser',
        'empty_fav'  => 'Aucun favori enregistré.',
        'empty_rl'   => 'Aucun article à lire enregistré.',
        'no_results' => 'Aucun résultat pour cette recherche.',
    ] );
    delete_transient( 'rdr_bm_shortcode_in_widgets' );
} );

/* ── Détection bot ──────────────────────────── */
function rdr_bm_is_bot(): bool {
    $ua = strtolower( $_SERVER['HTTP_USER_AGENT'] ?? '' );
    if ( empty( $ua ) ) return true;
    foreach ( [ 'bot','crawl','spider','slurp','mediapartners','adsbot',
                'facebookexternalhit','twitterbot','linkedinbot','whatsapp',
                'applebot','bingpreview','google-read-aloud','screaming frog',
                'lighthouse','pagespeed','gtmetrix' ] as $b ) {
        if ( str_contains( $ua, $b ) ) return true;
    }
    return false;
}

/* ── Shortcode présent dans un widget ?
   Résultat mis en cache (transient 12h).
   Invalidé quand les widgets sont sauvegardés. ── */
function rdr_bm_shortcode_in_widgets(): bool {
    $cached = get_transient( 'rdr_bm_shortcode_in_widgets' );
    if ( $cached !== false ) return (bool) $cached;

    $found    = false;
    $sidebars = wp_get_sidebars_widgets();

    foreach ( $sidebars as $sidebar_id => $widget_ids ) {
        if ( 'wp_inactive_widgets' === $sidebar_id ) continue;
        if ( ! is_array( $widget_ids ) || $found ) break;
        foreach ( $widget_ids as $widget_id ) {
            if ( ! preg_match( '/-(\d+)$/', $widget_id, $m ) ) continue;
            $num = (int) $m[1];
            foreach ( [ 'widget_text', 'widget_custom_html', 'widget_block' ] as $key ) {
                $all     = get_option( $key, [] );
                $content = $all[ $num ]['text'] ?? $all[ $num ]['content'] ?? '';
                if ( $content && ( str_contains( $content, 'rdr_bookmarks]' ) || str_contains( $content, 'rdr_bookmarks_page]' ) ) ) {
                    $found = true;
                    break 2;
                }
            }
        }
    }

    set_transient( 'rdr_bm_shortcode_in_widgets', $found ? '1' : '0', 12 * HOUR_IN_SECONDS );
    return $found;
}

/* Invalide le cache quand les widgets sont modifiés */
add_action( 'updated_option', function ( $option ) {
    if ( in_array( $option, [ 'sidebars_widgets', 'widget_text',
                               'widget_custom_html', 'widget_block' ], true ) ) {
        delete_transient( 'rdr_bm_shortcode_in_widgets' );
    }
} );

/* ── Enqueue front-end ──────────────────────── */
add_action( 'wp_enqueue_scripts', function () {
    if ( rdr_bm_is_bot() ) return;

    $enabled_cpts  = (array) get_option( 'rdr_bm_enabled_cpts', [] );
    $show_switches = is_singular() && in_array( get_post_type(), $enabled_cpts, true );

    // CSS + JS nécessaires si : CPT activé OU shortcode OU boutons nav actifs
    $show = $show_switches;

    // Boutons nav = chargement sur toutes les pages
    if ( ! $show && 'none' !== get_option( 'rdr_bm_nav_display', 'both' ) ) {
        $show = true;
    }

    if ( ! $show ) {
        global $post;
        if ( isset( $post->post_content )
             && has_shortcode( $post->post_content, 'rdr_bookmarks' ) ) {
            $show = true;
        }
    }

    if ( ! $show ) {
        $show = rdr_bm_shortcode_in_widgets();
    }

    if ( ! $show ) return;

    // CSS en defer (non bloquant) — les boutons trigger ont leurs styles inline
    wp_enqueue_style(
        'rdr-bookmarks',
        RDR_BM_URL . 'assets/rdr-bookmarks-front.css',
        [],
        RDR_BM_VERSION
    );

    wp_enqueue_script(
        'rdr-bookmarks',
        RDR_BM_URL . 'assets/rdr-bookmarks-front.js',
        [],
        RDR_BM_VERSION,
        true   // footer
    );

    $label_defaults = [
        'favorite'   => 'Favoris',
        'read_later' => 'À lire',
        'search'     => 'Rechercher…',
        'consent'    => "Ce site utilise le stockage local (localStorage) pour sauvegarder vos favoris. Aucune donnée n'est transmise à un serveur. Acceptez-vous ?",
        'accept'     => 'Accepter',
        'decline'    => 'Refuser',
        'empty_fav'  => 'Aucun favori enregistré.',
        'empty_rl'   => 'Aucun article à lire enregistré.',
        'no_results' => 'Aucun résultat pour cette recherche.',
    ];
    $labels = array_merge( $label_defaults, (array) get_option( 'rdr_bm_labels', [] ) );
    if ( function_exists( 'pll__' ) ) {
        foreach ( $labels as $k => $v ) { $labels[ $k ] = pll__( $v ); }
    }

    wp_localize_script( 'rdr-bookmarks', 'rdrBM', [
        'postId'           => $show_switches ? (string) get_the_ID() : '',
        'postTitle'        => $show_switches ? get_the_title() : '',
        'postUrl'          => $show_switches ? get_permalink() : '',
        'postThumb'        => $show_switches ? ( get_the_post_thumbnail_url( null, 'thumbnail' ) ?: '' ) : '',
        'lang'             => function_exists( 'pll_current_language' ) ? pll_current_language() : get_locale(),
        'labels'           => $labels,
        'emojiFavorite'    => get_option( 'rdr_bm_emoji_favorite',  '★' ),
        'emojiReadLater'   => get_option( 'rdr_bm_emoji_read_later', '🕐' ),
        'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
        'nonce'            => wp_create_nonce( 'rdr_bm_fetch' ),
        'switchSide'       => get_option( 'rdr_bm_switch_side', 'right' ),
        'priorityFavorite' => (int) get_option( 'rdr_bm_priority_favorite', 40 ),
        'priorityReadLater'=> (int) get_option( 'rdr_bm_priority_read_later', 30 ),
        'showSwitches'      => $show_switches ? '1' : '0',
        'navBehavior'       => get_option( 'rdr_bm_nav_behavior', 'auto' ),
        'navPageUrl'        => get_option( 'rdr_bm_nav_page_url', '' ),
        'showReadingMode'   => ( $show_switches && (int) get_option( 'rdr_bm_reading_mode', 1 ) ) ? '1' : '0',
        'readingLabel'      => get_option( 'rdr_bm_reading_label', 'Mode lecture' ),
        'readingEmoji'      => get_option( 'rdr_bm_reading_emoji', '📖' ),
        'priorityReading'   => (int) get_option( 'rdr_bm_priority_reading', 20 ),
    ] );
} );

/* ── Boutons de navigation ─────────────────────
   Deux modes d'injection configurables en admin :
   1. Hook action  (generate_inside_navigation, etc.)
   2. Filtre       wp_nav_menu_items (universel, tous thèmes)
   Styles inline — aucune dépendance au CSS externe.
   ────────────────────────────────────────────── */

/**
 * Génère le HTML des boutons nav (utilisé par les deux modes).
 */
function rdr_bm_nav_buttons_html(): string {
    $nav_display = get_option( 'rdr_bm_nav_display', 'both' );
    if ( 'none' === $nav_display ) return '';

    $emo_fav = get_option( 'rdr_bm_emoji_favorite',  '★' );
    $emo_rl  = get_option( 'rdr_bm_emoji_read_later', '🕐' );

    $label_defaults = [ 'favorite' => 'Favoris', 'read_later' => 'À lire' ];
    $labels = array_merge( $label_defaults, (array) get_option( 'rdr_bm_labels', [] ) );
    if ( function_exists( 'pll__' ) ) {
        foreach ( $labels as $k => $v ) { $labels[ $k ] = pll__( $v ); }
    }

    $s_btn =
        'display:inline-flex;align-items:center;gap:.3rem;' .
        'padding:.3em .8em;' .
        'background:var(--rdr-sh-sw-bg,rgba(22,27,34,.95));' .
        'color:var(--rdr-sh-sw-text,#8b949e);' .
        'border:1.5px solid var(--rdr-sh-sw-border,rgba(255,255,255,.10));' .
        'border-radius:6px;cursor:pointer;' .
        'font-size:13px;font-weight:600;line-height:1.6;' .
        'white-space:nowrap;vertical-align:middle;' .
        'transition:color .2s,border-color .2s;';

    $s_badge =
        'display:inline-flex;align-items:center;justify-content:center;' .
        'min-width:16px;height:16px;padding:0 4px;' .
        'background:var(--rdr-sh-sw-track-on,#2ea8ff);' .
        'color:#fff;border:none;border-radius:999px;' .
        'font-size:10px;font-weight:700;line-height:1;';

    $wrap = '<div class="rdr-bm-nav-wrap" style="display:inline-flex;align-items:center;gap:6px;margin:0 4px;">';

    if ( in_array( $nav_display, [ 'both', 'favorites' ], true ) ) {
        $wrap .= '<button class="rdr-bm-nav-btn" data-tab="favorites"'
            . ' style="' . esc_attr( $s_btn ) . '"'
            . ' aria-label="' . esc_attr( $labels['favorite'] ) . '"'
            . ' title="' . esc_attr( $labels['favorite'] ) . '">'
            . '<span style="font-size:14px;line-height:1">' . esc_html( $emo_fav ) . '</span>'
            . '<span class="rdr-bm-nav-count" style="' . esc_attr( $s_badge ) . '" data-list="favorites">0</span>'
            . '</button>';
    }

    if ( in_array( $nav_display, [ 'both', 'read_later' ], true ) ) {
        $wrap .= '<button class="rdr-bm-nav-btn" data-tab="read_later"'
            . ' style="' . esc_attr( $s_btn ) . '"'
            . ' aria-label="' . esc_attr( $labels['read_later'] ) . '"'
            . ' title="' . esc_attr( $labels['read_later'] ) . '">'
            . '<span style="font-size:14px;line-height:1">' . esc_html( $emo_rl ) . '</span>'
            . '<span class="rdr-bm-nav-count" style="' . esc_attr( $s_badge ) . '" data-list="read_later">0</span>'
            . '</button>';
    }

    if ( 'combined' === $nav_display ) {
        $wrap .= '<button class="rdr-bm-nav-btn" data-tab="favorites"'
            . ' style="' . esc_attr( $s_btn ) . '"'
            . ' aria-label="' . esc_attr( $labels['favorite'] . ' / ' . $labels['read_later'] ) . '">'
            . '<span style="font-size:14px;line-height:1">' . esc_html( $emo_fav ) . '</span>'
            . '<span class="rdr-bm-nav-count rdr-bm-nav-count--combined" style="' . esc_attr( $s_badge ) . '" data-list="combined">0</span>'
            . '</button>';
    }

    $wrap .= '</div>';
    return $wrap;
}

add_action( 'init', function () {
    if ( rdr_bm_is_bot() ) return;

    $nav_display = get_option( 'rdr_bm_nav_display', 'both' );
    if ( 'none' === $nav_display ) return;

    $nav_hook     = sanitize_text_field( get_option( 'rdr_bm_nav_hook', 'generate_inside_navigation' ) );
    $nav_pos      = get_option( 'rdr_bm_nav_position', 'after' );
    $nav_priority = (int) get_option( 'rdr_bm_nav_priority', 9 );

    /* ── Mode filtre wp_nav_menu_items ── */
    if ( 'wp_nav_menu_items' === $nav_hook ) {
        add_filter( 'wp_nav_menu_items', function ( $items, $args ) use ( $nav_pos ) {
            $target_location = apply_filters( 'rdr_bm_nav_menu_location', 'primary' );
            if ( isset( $args->theme_location ) && $args->theme_location !== $target_location ) {
                return $items;
            }
            $btn_html = '<li class="menu-item rdr-bm-menu-item" style="display:flex;align-items:center;">'
                . rdr_bm_nav_buttons_html()
                . '</li>';
            return ( 'before' === $nav_pos )
                ? $btn_html . $items
                : $items . $btn_html;
        }, $nav_priority, 2 );
        return;
    }

    /* ── Mode hook action (generate_inside_navigation, etc.) ── */
    add_action( $nav_hook, function () {
        if ( rdr_bm_is_bot() ) return;
        echo rdr_bm_nav_buttons_html();
    }, $nav_priority );
} );


/* ── Modale globale dans wp_footer ─────────────
   Injectée une seule fois, partagée par les boutons
   nav ET les boutons shortcode.
   N'est injectée que si la nav est active ET que
   la page ne contient pas déjà le shortcode
   (le shortcode injecte sa propre modale).
   ────────────────────────────────────────────── */
add_action( 'wp_footer', function () {
    if ( rdr_bm_is_bot() ) return;

    $nav_display = get_option( 'rdr_bm_nav_display', 'both' );
    if ( 'none' === $nav_display ) return;

    // Si la page contient le shortcode, il a déjà injecté la modale
    global $post;
    if ( isset( $post->post_content )
         && has_shortcode( $post->post_content, 'rdr_bookmarks' ) ) return;
    // Idem si dans un widget
    if ( rdr_bm_shortcode_in_widgets() ) return;

    $label_defaults = [
        'favorite'   => 'Favoris',
        'read_later' => 'À lire',
        'search'     => 'Rechercher…',
        'empty_fav'  => 'Aucun favori enregistré.',
        'empty_rl'   => 'Aucun article à lire enregistré.',
        'no_results' => 'Aucun résultat pour cette recherche.',
    ];
    $labels  = array_merge( $label_defaults, (array) get_option( 'rdr_bm_labels', [] ) );
    $emo_fav = get_option( 'rdr_bm_emoji_favorite',  '★' );
    $emo_rl  = get_option( 'rdr_bm_emoji_read_later', '🕐' );
    if ( function_exists( 'pll__' ) ) {
        foreach ( $labels as $k => $v ) { $labels[ $k ] = pll__( $v ); }
    }
    ?>
    <div class="rdr-bm-modal-overlay" id="rdr-bm-modal"
         role="dialog" aria-modal="true"
         aria-label="<?= esc_attr( $labels['favorite'] . ' / ' . $labels['read_later'] ) ?>"
         hidden>
        <div class="rdr-bm-modal" id="rdr-bm-wrap">
            <div class="rdr-bm-tabs" role="tablist">
                <button class="rdr-bm-tab active" role="tab" aria-selected="true"
                        data-tab="favorites" id="rdr-tab-favorites" aria-controls="rdr-panel-favorites">
                    <span><?= esc_html( $emo_fav ) ?></span>
                    <?= esc_html( $labels['favorite'] ) ?>
                    <span class="rdr-bm-tab-count" data-list="favorites">0</span>
                </button>
                <button class="rdr-bm-tab" role="tab" aria-selected="false"
                        data-tab="read_later" id="rdr-tab-read_later" aria-controls="rdr-panel-read_later">
                    <span><?= esc_html( $emo_rl ) ?></span>
                    <?= esc_html( $labels['read_later'] ) ?>
                    <span class="rdr-bm-tab-count" data-list="read_later">0</span>
                </button>
                <button class="rdr-bm-modal-close" aria-label="Fermer">✕</button>
            </div>
            <div class="rdr-bm-search-wrap">
                <span class="rdr-bm-search-icon">🔍</span>
                <input type="search" class="rdr-bm-search" id="rdr-bm-search"
                       placeholder="<?= esc_attr( $labels['search'] ) ?>"
                       autocomplete="off" aria-label="<?= esc_attr( $labels['search'] ) ?>">
                <button class="rdr-bm-search-clear" aria-label="Effacer" hidden>✕</button>
            </div>
            <div class="rdr-bm-panel active" id="rdr-panel-favorites"
                 role="tabpanel" aria-labelledby="rdr-tab-favorites" data-panel="favorites">
                <div class="rdr-bm-loading" hidden><span class="rdr-bm-spinner"></span></div>
                <div class="rdr-bm-cards" aria-live="polite"></div>
                <p class="rdr-bm-empty"     hidden><?= esc_html( $labels['empty_fav'] ) ?></p>
                <p class="rdr-bm-no-results" hidden><?= esc_html( $labels['no_results'] ) ?></p>
            </div>
            <div class="rdr-bm-panel" id="rdr-panel-read_later"
                 role="tabpanel" aria-labelledby="rdr-tab-read_later" data-panel="read_later" hidden>
                <div class="rdr-bm-loading" hidden><span class="rdr-bm-spinner"></span></div>
                <div class="rdr-bm-cards" aria-live="polite"></div>
                <p class="rdr-bm-empty"     hidden><?= esc_html( $labels['empty_rl'] ) ?></p>
                <p class="rdr-bm-no-results" hidden><?= esc_html( $labels['no_results'] ) ?></p>
            </div>
        </div>
    </div>
    <?php
} );

/* ── Mode lecture : injection CSS dans <head> ── */
add_action( 'wp_head', function () {
    if ( rdr_bm_is_bot() ) return;
    if ( ! (int) get_option( 'rdr_bm_reading_mode', 1 ) ) return;

    $enabled_cpts = (array) get_option( 'rdr_bm_enabled_cpts', [] );
    if ( ! is_singular() || ! in_array( get_post_type(), $enabled_cpts, true ) ) return;

    $sidebar = sanitize_text_field( get_option( 'rdr_bm_reading_sidebar_sel', '#right-sidebar' ) );
    $content = sanitize_text_field( get_option( 'rdr_bm_reading_content_sel', '#primary' ) );

    // Génère uniquement les règles nécessaires — sélecteurs configurables
    printf(
        '<style id="rdr-bm-reading-css">' .
        'body.rdr-bm-reading %1$s{display:none!important;width:0!important;min-width:0!important;padding:0!important;margin:0!important}' .
        'body.rdr-bm-reading %2$s{width:100%%!important;max-width:100%%!important;flex:1 1 100%%!important;margin-left:0!important;margin-right:0!important}' .
        '</style>' . "
",
        esc_attr( $sidebar ),
        esc_attr( $content )
    );
} );

/* ── AJAX : posts sauvegardés ───────────────── */
add_action( 'wp_ajax_nopriv_rdr_bm_fetch_posts', 'rdr_bm_ajax_fetch_posts' );
add_action( 'wp_ajax_rdr_bm_fetch_posts',        'rdr_bm_ajax_fetch_posts' );

function rdr_bm_ajax_fetch_posts(): void {
    check_ajax_referer( 'rdr_bm_fetch', 'nonce' );

    $ids = json_decode( wp_unslash( $_POST['ids'] ?? '[]' ), true );
    if ( ! is_array( $ids ) ) wp_send_json_error( 'bad_ids' );

    $ids = array_filter( array_map( 'absint', $ids ) );
    if ( empty( $ids ) ) wp_send_json_success( [] );

    $enabled_cpts = (array) get_option( 'rdr_bm_enabled_cpts', [] );
    $q = new WP_Query( [
        'post__in'       => $ids,
        'post_type'      => empty( $enabled_cpts ) ? 'any' : $enabled_cpts,
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'orderby'        => 'post__in',
    ] );

    $results = [];
    foreach ( $q->posts as $p ) {
        $results[] = [
            'id'    => $p->ID,
            'title' => get_the_title( $p ),
            'url'   => get_permalink( $p ),
            'thumb' => get_the_post_thumbnail_url( $p, 'thumbnail' ) ?: '',
            'type'  => get_post_type( $p ),
            'date'  => get_the_date( '', $p ),
        ];
    }
    wp_send_json_success( $results );
}

/* ── Traductions ────────────────────────────── */
add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( 'rdr-bookmarks', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );
