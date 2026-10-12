<?php
/**
 * Plugin Name: RDR-IT Quiz
 * Plugin URI:  https://rdr-it.com
 * Description: Système de quiz interactif — async, Polylang compatible, stats globales
 * Version:     1.5.5
 * Author:      Romain Drouche
 * Author URI:  https://rdr-it.com
 * Text Domain: rdr-quiz
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined('ABSPATH') || exit;

define('RDR_QUIZ_VERSION',  '1.5.5');
define('RDR_QUIZ_PATH',     plugin_dir_path(__FILE__));
define('RDR_QUIZ_URL',      plugin_dir_url(__FILE__));
define('RDR_QUIZ_BASENAME', plugin_basename(__FILE__));

/* ── Autoload ── */
require_once RDR_QUIZ_PATH . 'includes/class-cpt.php';
require_once RDR_QUIZ_PATH . 'includes/class-admin-ui.php';
require_once RDR_QUIZ_PATH . 'includes/class-ajax.php';
require_once RDR_QUIZ_PATH . 'includes/class-shortcodes.php';
require_once RDR_QUIZ_PATH . 'includes/class-stats.php';
require_once RDR_QUIZ_PATH . 'includes/class-polylang.php';

/* ── Bootstrap ── */
add_action('plugins_loaded', function () {
    load_plugin_textdomain('rdr-quiz', false, dirname(RDR_QUIZ_BASENAME) . '/languages');
    RDR_Quiz_CPT::init();
    RDR_Quiz_AdminUI::init();
    RDR_Quiz_Ajax::init();
    RDR_Quiz_Shortcodes::init();
    RDR_Quiz_Stats::init();
    RDR_Quiz_Polylang::init();
});

/* ════════════════════════════════════════════════════════════════
   SINGLE rdr_quiz
════════════════════════════════════════════════════════════════ */

/* 1. Assets JS/CSS */
add_action('wp_enqueue_scripts', function () {
    if (is_singular('rdr_quiz')) {
        RDR_Quiz_Shortcodes::enqueue_assets_public();
    }
    // Les assets modal sont enqueue depuis les shortcodes eux-mêmes
    // (rdr_quiz_link modal=yes, rdr_quiz_list modal=yes)
    // register_assets() est appelé au hook wp_enqueue_scripts via init()
});

/* 2. Breadcrumb Rank Math — injecter la page liste comme ancêtre
      Priorité de résolution de la page liste :
      a) Meta _rdr_quiz_archive_page_id définie sur le quiz
      b) Traduction Polylang de cette page dans la langue courante
      c) Détection automatique par contenu (fallback) */
/**
 * Breadcrumb Rank Math — injecter la page liste comme ancêtre du quiz.
 *
 * Rank Math utilise le hook 'rank_math/frontend/breadcrumb/items'.
 * Chaque item = ['text' => '...', 'url' => '...'] (format officiel RM).
 * On supporte aussi les variantes avec clés 'title'/'link' (anciennes versions).
 */
function rdr_quiz_inject_breadcrumb(array $crumbs): array {
    if (! is_singular('rdr_quiz')) return $crumbs;
    if (count($crumbs) < 2)        return $crumbs;

    $quiz_id   = get_queried_object_id();
    $list_page = rdr_quiz_get_list_page($quiz_id);
    if (! $list_page) return $crumbs;

    // Rank Math stocke ses items sous forme de tableau NUMÉRIQUE :
    // [ 0 => text affiché, 1 => url, 2 => is_current (bool) ]
    // Preuve : l'URL s'affichait comme texte avec l'ordre inverse
    $list_item = [
        $list_page['title'],  // [0] text affiché
        $list_page['url'],    // [1] url du lien
        false,                // [2] is_current = false
    ];

    // Insérer à l'avant-dernière position (avant le titre du quiz courant)
    $last_idx = count($crumbs) - 1;
    array_splice($crumbs, $last_idx, 0, [$list_item]);

    return $crumbs;
}
// Rank Math enregistre ce hook selon la version installée — on couvre tous les cas
add_filter('rank_math/frontend/breadcrumb/items', 'rdr_quiz_inject_breadcrumb', 10, 1);
add_filter('rank_math/breadcrumb/items',          'rdr_quiz_inject_breadcrumb', 10, 1);
add_filter('rank_math/breadcrumb/crumbs',         'rdr_quiz_inject_breadcrumb', 10, 1);

/* 3. Injection quiz dans the_content */
add_filter('the_content', function (string $content): string {
    if (! is_singular('rdr_quiz')) return $content;
    if (get_the_ID() !== get_queried_object_id()) return $content;

    static $running = false;
    if ($running) return $content;
    $running = true;

    $id          = get_the_ID();
    $quiz        = RDR_Quiz_Shortcodes::render_quiz_direct($id);
    $tags_html   = rdr_quiz_tags_html($id);
    $terms_html  = rdr_quiz_terms_html($id);
    $description = $content ? '<div class="rdr-quiz-description">' . $content . '</div>' : '';

    $running = false;
    return $quiz . $tags_html . $terms_html . $description;
}, 20);

/* 4. Masquer le titre h1 GP */
add_filter('generate_post_title', function (bool $show): bool {
    if (is_singular('rdr_quiz')) return false;
    return $show;
});

/* ════════════════════════════════════════════════════════════════
   HELPERS
════════════════════════════════════════════════════════════════ */

/**
 * Résout la page liste pour un quiz donné.
 * Ordre de priorité :
 *   1. Meta archive_page_id définie sur le quiz → traduite via Polylang si dispo
 *   2. Détection automatique (transient 1h) — cherche [rdr_quiz_list] dans le contenu
 */
function rdr_quiz_get_list_page(int $quiz_id = 0): array|false {

    // Priorité 1 : page définie manuellement sur le quiz
    $page_id = $quiz_id ? (int) get_post_meta($quiz_id, '_rdr_quiz_archive_page_id', true) : 0;

    if ($page_id > 0) {
        // Polylang : obtenir la traduction dans la langue courante si disponible
        if (function_exists('pll_get_post') && function_exists('pll_current_language')) {
            $current_lang  = pll_current_language();
            $translated_id = $current_lang ? (int) pll_get_post($page_id, $current_lang) : 0;
            if ($translated_id > 0 && $translated_id !== $page_id) {
                $page_id = $translated_id;
            }
        }
        $post = get_post($page_id);
        if ($post instanceof WP_Post && $post->post_status === 'publish') {
            return ['url' => get_permalink($page_id), 'title' => $post->post_title];
        }
    }

    // Priorité 2 : détection automatique (cache 1h)
    $cache_key = 'rdr_quiz_list_page';
    // Avec Polylang, le cache est par langue
    if (function_exists('pll_current_language')) {
        $cache_key .= '_' . pll_current_language();
    }

    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    global $wpdb;

    // Si Polylang est actif, limiter aux pages de la langue courante
    $lang_join  = '';
    $lang_where = '';
    if (function_exists('pll_current_language') && function_exists('pll_get_post_language')) {
        $current_lang = pll_current_language();
        if ($current_lang) {
            // Utiliser la table des relations Polylang via les termes
            $lang_join  = " JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                            JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                            JOIN {$wpdb->terms} t ON t.term_id = tt.term_id";
            $lang_where = $wpdb->prepare(
                " AND tt.taxonomy = 'language' AND t.slug = %s",
                $current_lang
            );
        }
    }

    $page = $wpdb->get_row(
        "SELECT p.ID, p.post_title FROM {$wpdb->posts} p
         {$lang_join}
         WHERE p.post_status = 'publish'
           AND p.post_type   = 'page'
           AND p.post_content LIKE '%rdr_quiz_list%'
         {$lang_where}
         LIMIT 1"
    );

    if (! $page) {
        set_transient($cache_key, false, HOUR_IN_SECONDS);
        return false;
    }

    $result = ['url' => get_permalink($page->ID), 'title' => $page->post_title];
    set_transient($cache_key, $result, HOUR_IN_SECONDS);
    return $result;
}

/* Invalider les caches quand une page est modifiée */
add_action('save_post_page', function () {
    // Supprimer tous les transients de liste (toutes langues)
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rdr_quiz_list_page%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_rdr_quiz_list_page%'");
});

/**
 * Tags des questions — affichage statique selon le réglage admin.
 * Pas de bouton toggle : le comportement est défini par _rdr_quiz_hide_tags.
 */
function rdr_quiz_tags_html(int $id): string {
    $hide_tags = get_post_meta($id, '_rdr_quiz_hide_tags', true) === '1';
    if ($hide_tags) return '';  // masqué en admin → invisible, pas d'interaction

    $q_ids    = get_post_meta($id, '_rdr_quiz_questions', true) ?: [];
    $all_tags = [];
    foreach ($q_ids as $qid) {
        $q_tags = get_the_terms($qid, 'rdr_question_tag');
        if ($q_tags && ! is_wp_error($q_tags)) {
            foreach ($q_tags as $t) $all_tags[$t->slug] = $t;
        }
    }
    if (empty($all_tags)) return '';

    $list_page = rdr_quiz_get_list_page($id);
    $base_url  = $list_page ? $list_page['url'] : home_url('/quiz/');

    $html = '<div class="rdr-quiz-tag-cloud">';
    $html .= '<span class="rdr-tag-cloud-label">' . __('Sujets abordés :', 'rdr-quiz') . '</span>';
    $html .= '<div class="rdr-tag-cloud-pills">';
    foreach ($all_tags as $tag) {
        $url   = add_query_arg('tag', $tag->slug, $base_url);
        $html .= '<a href="' . esc_url($url) . '" class="rdr-tag-pill">' . esc_html($tag->name) . '</a>';
    }
    return $html . '</div></div>';
}

/**
 * Catégories du quiz.
 */
function rdr_quiz_terms_html(int $id): string {
    $cats = get_the_terms($id, 'rdr_quiz_cat');
    if (! $cats || is_wp_error($cats)) return '';

    $html = '<div class="rdr-quiz-terms"><span>' . __('Catégorie :', 'rdr-quiz') . '</span>';
    foreach ($cats as $cat) {
        $html .= '<a href="' . esc_url(get_term_link($cat)) . '" class="rdr-quiz-term-link">'
               . esc_html($cat->name) . '</a>';
    }
    return $html . '</div>';
}

/* ════════════════════════════════════════════════════════════════
   UPGRADE SILENCIEUX
════════════════════════════════════════════════════════════════ */
add_action('admin_init', function () {
    if (get_option('rdr_quiz_db_version') !== '1.4') {
        RDR_Quiz_Stats::create_table();
        update_option('rdr_quiz_db_version', '1.4');
    }
    if (get_option('rdr_quiz_rewrite_version') !== '1.5.2') {
        flush_rewrite_rules();
        update_option('rdr_quiz_rewrite_version', '1.5.2');
    }
    // Vider les transients breadcrumb mis en cache (false) par les anciennes versions
    if (get_option('rdr_quiz_cache_version') !== '1.6') {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%rdr_quiz_list_page%'");
        update_option('rdr_quiz_cache_version', '1.6');
    }
});

/* ── Activation ── */
register_activation_hook(__FILE__, function () {
    RDR_Quiz_Stats::create_table();
    RDR_Quiz_CPT::register_cpts();
    RDR_Quiz_CPT::register_taxonomies();
    flush_rewrite_rules();
    update_option('rdr_quiz_db_version', '1.4');
    update_option('rdr_quiz_rewrite_version', '1.5.2');
});

register_deactivation_hook(__FILE__, function () { flush_rewrite_rules(); });
