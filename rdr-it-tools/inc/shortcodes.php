<?php
/**
 * RDR-IT Tools — Shortcodes Widget Sidebar v2
 * Support Polylang : detection automatique de la langue courante.
 *
 * [rdrit_latest]  — derniers articles
 * [rdrit_random]  — articles aleatoires
 *
 * Parametres :
 *   cpt      string  Slug CPT (defaut : post)
 *   count    int     Nombre de cartes 1-12 (defaut : 3)
 *   category string  Slug categorie/taxonomie (defaut : vide)
 *   mode     string  sync | async (defaut : sync)
 *   height   int     Hauteur skeleton async px (defaut : 280)
 *   title    string  Titre — supporte le format multilingue :
 *                    "fr:Derniers tutos|en:Latest|de:Letzte|es:Ultimos|it:Ultimi|pt:Ultimos"
 *                    Si la langue courante n'a pas de traduction -> titre vide
 *                    Si format simple (pas de ":") -> utilise tel quel
 *   cache    int     Minutes de cache (defaut : 60, 0 = desactive)
 *
 * Polylang :
 *   - La WP_Query est filtree automatiquement par Polylang (langue courante)
 *   - Les articles non traduits dans la langue courante ne s'affichent pas
 *   - La langue est incluse dans la cle de cache
 *   - Compatible Polylang Pro et gratuit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   SHORTCODES
   ============================================================ */
// Activer les shortcodes dans les widgets Texte/HTML
add_filter( 'widget_text',         'do_shortcode' );
add_filter( 'widget_text_content', 'do_shortcode' );

add_shortcode( 'rdrit_latest', 'rdrit_sc_latest' );
add_shortcode( 'rdrit_random', 'rdrit_sc_random' );

function rdrit_sc_latest( $atts ): string {
    return rdrit_sc_render( rdrit_sc_parse_atts( $atts ), 'latest' );
}
function rdrit_sc_random( $atts ): string {
    return rdrit_sc_render( rdrit_sc_parse_atts( $atts ), 'random' );
}

/* ============================================================
   PARSING ATTRIBUTS
   ============================================================ */
function rdrit_sc_parse_atts( $atts ): array {
    return shortcode_atts( array(
        'cpt'      => 'post',
        'count'    => 3,
        'category' => '',
        'mode'     => 'sync',
        'height'   => 280,
        'title'    => '',
        'cache'    => 60,
    ), $atts, 'rdrit_posts' );
}

/* ============================================================
   TITRE MULTILINGUE
   Formats acceptes :
     "fr:Derniers tutos|en:Latest tutorials|de:Letzte Tutorials"
     "Titre unique" (pas de ":" -> retourne tel quel)
   Retourne '' si langue courante absente et format multilingue detecte.
   ============================================================ */
function rdrit_sc_resolve_title( string $raw ): string {
    $raw = trim( $raw );
    if ( ! $raw ) return '';

    // Format simple : pas de pattern "xx:" au debut
    if ( ! preg_match( '/^[a-z]{2}:/i', $raw ) && strpos( $raw, '|' ) === false ) {
        return $raw;
    }

    // Parser "fr:Titre FR|en:Title EN|..."
    $translations = array();
    foreach ( explode( '|', $raw ) as $chunk ) {
        $chunk = trim( $chunk );
        if ( strpos( $chunk, ':' ) === false ) continue;
        list( $lang, $text ) = explode( ':', $chunk, 2 );
        $translations[ strtolower( trim( $lang ) ) ] = trim( $text );
    }

    if ( empty( $translations ) ) return $raw;

    // Langue courante via Polylang, sinon locale WP
    $current_lang = rdrit_sc_current_lang();

    // Retourner la traduction de la langue courante, ou vide si absente
    return $translations[ $current_lang ] ?? '';
}

/* ============================================================
   LANGUE COURANTE
   Polylang d'abord, fallback sur locale WP (2 premiers chars)
   ============================================================ */
function rdrit_sc_current_lang(): string {
    if ( function_exists( 'pll_current_language' ) ) {
        $lang = pll_current_language( 'slug' );
        if ( $lang ) return strtolower( $lang );
    }
    // Fallback locale WP : "fr_FR" -> "fr"
    $locale = get_locale();
    return strtolower( substr( $locale, 0, 2 ) );
}

/* ============================================================
   RENDU PRINCIPAL
   ============================================================ */
function rdrit_sc_render( array $atts, string $mode_query ): string {
    $cpt      = sanitize_key( $atts['cpt'] );
    $count    = max( 1, min( 12, (int) $atts['count'] ) );
    $category = sanitize_text_field( $atts['category'] );
    $mode     = in_array( $atts['mode'], array( 'sync', 'async' ), true ) ? $atts['mode'] : 'sync';
    $height   = max( 100, (int) $atts['height'] );
    $cache    = max( 0, (int) $atts['cache'] );

    // Resoudre le titre multilingue ici (cote serveur, pour sync ET pour passer au placeholder async)
    $title = rdrit_sc_resolve_title( (string) $atts['title'] );

    if ( $mode === 'async' ) {
        return rdrit_sc_async_placeholder( $cpt, $count, $category, $mode_query, $height, $title, $cache );
    }

    $posts = rdrit_sc_get_posts( $cpt, $count, $category, $mode_query, $cache );
    return rdrit_sc_build_html( $posts, $title, $cpt );
}

/* ============================================================
   WP_QUERY + CACHE
   La langue est incluse dans la cle de cache pour que chaque
   version linguistique ait son propre cache.
   Polylang filtre automatiquement via pre_get_posts -> rien a faire.
   ============================================================ */
function rdrit_sc_get_posts( string $cpt, int $count, string $category, string $mode, int $cache ): array {
    $lang      = rdrit_sc_current_lang();
    $cache_key = 'rdrit_sc_' . md5( $cpt . $count . $category . $mode . $lang );

    if ( $cache > 0 ) {
        $cached = get_transient( $cache_key );
        /* Un cache vide est ignore : on relance la requete plutot que
           de renvoyer du vide pendant toute la duree du TTL. */
        if ( $cached !== false && is_array( $cached ) && ! empty( $cached ) ) {
            return $cached;
        }
    }

    $args = array(
        'post_type'      => $cpt,
        'post_status'    => 'publish',
        'posts_per_page' => $count,
        'no_found_rows'  => true,
        'fields'         => 'ids',
    );

    if ( $category ) {
        $tax = rdrit_sc_guess_taxonomy( $cpt );
        $args['tax_query'] = array( array(
            'taxonomy' => $tax,
            'field'    => 'slug',
            'terms'    => $category,
        ) );
    }

    if ( $mode === 'random' ) {
        $args['orderby'] = 'rand';
    } else {
        $args['orderby'] = 'date';
        $args['order']   = 'DESC';
    }

    $query = new WP_Query( $args );
    $ids   = $query->posts;

    $posts = array();
    foreach ( $ids as $id ) {
        $thumb_id  = get_post_thumbnail_id( $id );
        $thumb     = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'medium' ) : '';
        $tax_slug  = rdrit_sc_guess_taxonomy( $cpt );
        $terms     = get_the_terms( $id, $tax_slug );
        $cat_label = ( $terms && ! is_wp_error( $terms ) ) ? $terms[0]->name : '';

        $posts[] = array(
            'id'    => $id,
            'title' => get_the_title( $id ),
            'url'   => get_permalink( $id ),
            'thumb' => $thumb,
            'date'  => get_the_date( 'j M Y', $id ),
            'cat'   => $cat_label,
        );
    }

    /* Ne jamais mettre en cache un resultat vide sur la duree complete :
       un echec ponctuel (BDD, Polylang, taxonomie absente) figerait
       l'affichage a vide pendant tout le TTL. */
    if ( $cache > 0 && ! empty( $posts ) ) {
        set_transient( $cache_key, $posts, $cache * MINUTE_IN_SECONDS );
    }

    return $posts;
}

/* ============================================================
   DETECTION TAXONOMIE PAR CPT
   ============================================================ */
function rdrit_sc_guess_taxonomy( string $cpt ): string {
    $known = array(
        'post'            => 'category',
        'blog'            => 'category',
        'domotique'       => 'cat-domo',
        'howto'           => 'category',
        'troubleshooting' => 'category',
    );
    if ( isset( $known[ $cpt ] ) ) return $known[ $cpt ];

    $taxes = get_object_taxonomies( $cpt, 'objects' );
    foreach ( $taxes as $tax ) {
        if ( $tax->public && $tax->hierarchical ) return $tax->name;
    }
    foreach ( $taxes as $tax ) {
        if ( $tax->public ) return $tax->name;
    }
    return 'category';
}

/* ============================================================
   HTML
   ============================================================ */
function rdrit_sc_build_html( array $posts, string $title, string $cpt ): string {
    if ( empty( $posts ) ) {
        return '<p class="rdrit-sc-empty"></p>'; // vide silencieux si aucun article
    }

    // ID unique pour le bloc (utile pour JS tiers comme Q2W3, Fixed Widget, etc.)
    static $sc_instance = 0;
    $sc_instance++;
    $block_id = 'rdrit-sc-block-' . $sc_instance;

    ob_start();
    ?>
    <div class="rdrit-sc-widget" id="<?php echo esc_attr( $block_id ); ?>">
        <?php if ( $title ) : ?>
        <h3 class="rdrit-sc-title"><?php echo esc_html( $title ); ?></h3>
        <?php endif; ?>
        <ul class="rdrit-sc-list">
        <?php foreach ( $posts as $p ) : ?>
            <li class="rdrit-sc-item" id="rdrit-sc-post-<?php echo (int) $p['id']; ?>">
                <a href="<?php echo esc_url( $p['url'] ); ?>" class="rdrit-sc-card"
                   id="rdrit-sc-link-<?php echo (int) $p['id']; ?>">
                    <div class="rdrit-sc-thumb">
                        <?php if ( $p['thumb'] ) : ?>
                        <img src="<?php echo esc_url( $p['thumb'] ); ?>"
                             alt="<?php echo esc_attr( $p['title'] ); ?>"
                             loading="lazy" decoding="async"
                             width="80" height="80">
                        <?php else : ?>
                        <div class="rdrit-sc-noimg" aria-hidden="true">📄</div>
                        <?php endif; ?>
                    </div>
                    <div class="rdrit-sc-info">
                        <?php if ( $p['cat'] ) : ?>
                        <span class="rdrit-sc-cat"><?php echo esc_html( $p['cat'] ); ?></span>
                        <?php endif; ?>
                        <span class="rdrit-sc-name"><?php echo esc_html( $p['title'] ); ?></span>
                        <span class="rdrit-sc-date"><?php echo esc_html( $p['date'] ); ?></span>
                    </div>
                </a>
            </li>
        <?php endforeach; ?>
        </ul>
    </div>
    <?php
    return ob_get_clean();
}

/* ============================================================
   MODE ASYNC — placeholder skeleton
   Le titre est deja resolu cote serveur avant d'etre passe au JS.
   ============================================================ */
function rdrit_sc_async_placeholder( string $cpt, int $count, string $category,
                                     string $mode_query, int $height,
                                     string $title, int $cache ): string {
    static $instance = 0;
    $instance++;
    $uid = 'rdrit-sc-' . $instance . '-' . wp_unique_id();

    // La langue est deja resolue : on passe le titre final + la langue au handler AJAX
    $data = array(
        'cpt'      => $cpt,
        'count'    => $count,
        'category' => $category,
        'mode'     => $mode_query,
        'title'    => $title,             // Titre deja traduit
        'cache'    => $cache,
        'lang'     => rdrit_sc_current_lang(), // Pour que le handler AJAX utilise la bonne langue
        'nonce'    => wp_create_nonce( 'rdrit_sc_ajax' ),
    );

    ob_start();
    ?>
    <div id="<?php echo esc_attr( $uid ); ?>"
         class="rdrit-sc-async-wrap rdrit-sc-skeleton"
         style="min-height:<?php echo (int) $height; ?>px"
         data-rdrit-sc="<?php echo esc_attr( wp_json_encode( $data ) ); ?>"
         aria-busy="true">
        <?php for ( $i = 0; $i < min( $count, 3 ); $i++ ) : ?>
        <div class="rdrit-sc-skeleton-item">
            <div class="rdrit-sc-skeleton-thumb rdrit-pulse"></div>
            <div class="rdrit-sc-skeleton-lines">
                <div class="rdrit-pulse rdrit-pulse--sm"></div>
                <div class="rdrit-pulse rdrit-pulse--md"></div>
                <div class="rdrit-pulse rdrit-pulse--xs"></div>
            </div>
        </div>
        <?php endfor; ?>
    </div>
    <?php
    return ob_get_clean();
}

/* ============================================================
   ENDPOINT AJAX
   Reçoit la langue en parametre pour forcer pll_switch_language
   si le contexte AJAX ne la detecte pas automatiquement.
   ============================================================ */
add_action( 'wp_ajax_rdrit_sc_load',        'rdrit_sc_ajax_handler' );
add_action( 'wp_ajax_nopriv_rdrit_sc_load', 'rdrit_sc_ajax_handler' );

function rdrit_sc_ajax_handler() {
    /* Empecher toute mise en cache de la reponse AJAX
       (Cloudflare / LiteSpeed peuvent cacher admin-ajax.php) */
    nocache_headers();

    /* Verification du nonce NON BLOQUANTE — endpoint public en lecture seule.
       Avec un cache agressif, le nonce dans le HTML cache expire (12-24h)
       avant la page elle-meme : un check bloquant renverrait du vide. */
    check_ajax_referer( 'rdrit_sc_ajax', 'nonce', false );

    $cpt      = sanitize_key( $_POST['cpt']      ?? 'post' );
    $count    = max( 1, min( 12, (int) ( $_POST['count'] ?? 3 ) ) );
    $category = sanitize_text_field( $_POST['category'] ?? '' );
    $mode     = in_array( $_POST['mode'] ?? '', array( 'latest', 'random' ), true )
                ? $_POST['mode'] : 'latest';
    $title    = sanitize_text_field( $_POST['title'] ?? '' ); // Deja traduit cote serveur
    $cache    = max( 0, (int) ( $_POST['cache'] ?? 60 ) );
    $lang     = sanitize_key( $_POST['lang'] ?? '' );

    // Forcer la langue Polylang si disponible
    // (les requetes AJAX admin-ajax.php n'ont pas toujours le bon contexte de langue)
    if ( $lang && function_exists( 'pll_switch_language' ) ) {
        pll_switch_language( $lang );
    }

    $posts = rdrit_sc_get_posts( $cpt, $count, $category, $mode, $cache );
    $html  = rdrit_sc_build_html( $posts, $title, $cpt );

    wp_send_json_success( array( 'html' => $html ) );
}

/* ============================================================
   ENQUEUE CSS + JS
   ============================================================ */
add_action( 'wp_enqueue_scripts', 'rdrit_sc_enqueue' );
function rdrit_sc_enqueue() {
    wp_enqueue_style(
        'rdrit-sc',
        RDRIT_TOOLS_URL . 'assets/css/rdrit-sc.css',
        array(),
        RDRIT_TOOLS_VERSION
    );

    wp_enqueue_script(
        'rdrit-sc-async',
        RDRIT_TOOLS_URL . 'assets/js/rdrit-sc-async.js',
        array(),
        RDRIT_TOOLS_VERSION,
        true // footer
    );

    if ( (bool) get_option( 'rdrit_front_debug', 0 ) ) {
        wp_add_inline_script( 'rdrit-sc-async', 'window.rdritScDebug=true;', 'before' );
    }
    wp_localize_script( 'rdrit-sc-async', 'rdritScConfig', array(
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
    ) );

}

/* ============================================================
   INVALIDATION CACHE sur save_post
   ============================================================ */
add_action( 'save_post', 'rdrit_sc_flush_cache' );
function rdrit_sc_flush_cache( int $post_id ) {
    if ( wp_is_post_revision( $post_id ) ) return;
    global $wpdb;
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_rdrit_sc_%'
            OR option_name LIKE '_transient_timeout_rdrit_sc_%'"
    );
}

/* ============================================================
   SHORTCODE [rdrit_localstorage]
   Page dédiée — affiche le tableau des données localStorage.
   A placer dans une page WP (ex: /mes-preferences).

   Parametres :
     title      string  Titre du bloc (defaut : "Mes préférences")
     prefix     string  Filtrer sur un prefixe de cle (defaut : vide = tout)
     labels     string  JSON de traduction des cles
                        '{"rdrit_theme":"Thème","rdrit_glossary":"Glossaire"}'
     empty_msg  string  Message si aucune donnee (defaut : message par defaut)
   ============================================================ */
add_shortcode( 'rdrit_localstorage',      'rdrit_sc_localstorage' );
add_shortcode( 'rdrit_localstorage_link', 'rdrit_sc_localstorage_link' );

function rdrit_sc_localstorage( $atts ): string {
    $atts = shortcode_atts( array(
        'title'     => 'Mes préférences',
        'prefix'    => '',
        'labels'    => '',
        'empty_msg' => 'Aucune préférence enregistrée.',
    ), $atts, 'rdrit_localstorage' );

    $title     = sanitize_text_field( $atts['title'] );
    $prefix    = sanitize_text_field( $atts['prefix'] );
    $labels    = wp_kses_post( $atts['labels'] );
    $empty_msg = sanitize_text_field( $atts['empty_msg'] );

    static $ls_instance = 0;
    $ls_instance++;
    $uid = 'rdrit-ls-' . $ls_instance;

    ob_start();
    ?>
    <div id="<?php echo esc_attr($uid); ?>" class="rdrit-ls-page">
        <?php if ( $title ) : ?>
        <h2 class="rdrit-ls-page-title"><?php echo esc_html($title); ?></h2>
        <?php endif; ?>

        <div id="<?php echo esc_attr($uid); ?>-content" class="rdrit-ls-page-content">
            <!-- Rempli par JS -->
        </div>

        <div id="<?php echo esc_attr($uid); ?>-actions" class="rdrit-ls-page-actions" style="display:none">
            <button class="rdrit-ls-reset-all">🗑️ Tout effacer</button>
        </div>
    </div>

    <style id="rdrit-ls-page-css">
    .rdrit-ls-page{margin:1.5rem 0}
    .rdrit-ls-page-title{font-size:1.1rem;font-weight:700;margin:0 0 1rem;padding-bottom:.6rem;border-bottom:2px solid var(--rdrit-border,#e5e7eb)}
    .rdrit-ls-table{width:100%;border-collapse:collapse;font-size:.9rem;margin-bottom:1rem}
    .rdrit-ls-table th{text-align:left;padding:.45rem .7rem;font-size:.76rem;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;border-bottom:1.5px solid var(--rdrit-border,#e5e7eb)}
    .rdrit-ls-table td{padding:.55rem .7rem;border-bottom:1px solid var(--rdrit-border,#f3f4f6);vertical-align:middle}
    .rdrit-ls-table tr:last-child td{border-bottom:none}
    .rdrit-ls-table tr:hover td{background:rgba(0,0,0,.02)}
    .rdrit-ls-key{font-family:monospace;font-size:.83rem;color:#374151;font-weight:600}
    .rdrit-ls-val{color:#111;word-break:break-word;max-width:300px}
    .rdrit-ls-del{background:none;border:1.5px solid #fca5a5;cursor:pointer;color:#ef4444;padding:3px 10px;border-radius:5px;font-size:.8rem;font-weight:600;transition:background .15s,color .15s}
    .rdrit-ls-del:hover{background:#ef4444;color:#fff}
    .rdrit-ls-page-actions{margin-top:.5rem}
    .rdrit-ls-reset-all{background:#fee2e2;color:#dc2626;border:1.5px solid #fca5a5;border-radius:6px;padding:.4em 1em;font-size:.85rem;font-weight:700;cursor:pointer;transition:background .15s}
    .rdrit-ls-reset-all:hover{background:#dc2626;color:#fff}
    .rdrit-ls-empty{color:#9ca3af;font-style:italic;font-size:.9rem;padding:.5rem 0}
    [data-theme="dark"] .rdrit-ls-key{color:#d1d5db}
    [data-theme="dark"] .rdrit-ls-val{color:#f9fafb}
    [data-theme="dark"] .rdrit-ls-table th{color:#9ca3af}
    [data-theme="dark"] .rdrit-ls-table tr:hover td{background:rgba(255,255,255,.03)}
    </style>

    <script>
    (function(){
        var uid       = <?php echo wp_json_encode($uid); ?>;
        var prefix    = <?php echo wp_json_encode($prefix); ?>;
        var emptyMsg  = <?php echo wp_json_encode($empty_msg); ?>;
        var labels    = {};
        try{ labels = JSON.parse(<?php echo wp_json_encode($labels ?: '{}'); ?>); }catch(e){}

        function getKeys(){
            var keys = [];
            for(var i=0;i<localStorage.length;i++){
                var k = localStorage.key(i);
                if(!prefix || k.indexOf(prefix)===0) keys.push(k);
            }
            return keys.sort();
        }

        function getLabel(key){
            return labels[key] || key.replace(/_/g,' ').replace(/\w/g,function(c){return c.toUpperCase();});
        }

        function formatValue(val){
            try{
                var p = JSON.parse(val);
                if(typeof p==='boolean') return p?'✅ Activé':'❌ Désactivé';
                if(typeof p==='number')  return String(p);
                if(typeof p==='object'&&p!==null)
                    return '<code style="font-size:.78rem;word-break:break-all">'+JSON.stringify(p).substring(0,120)+'</code>';
            }catch(e){}
            if(val==='true'||val==='1')  return '✅ Activé';
            if(val==='false'||val==='0') return '❌ Désactivé';
            return val.length>120 ? val.substring(0,120)+'…' : val;
        }

        function render(){
            var content = document.getElementById(uid+'-content');
            var actions = document.getElementById(uid+'-actions');
            if(!content) return;

            var keys = getKeys();

            if(keys.length===0){
                content.innerHTML = '<p class="rdrit-ls-empty">'+emptyMsg+'</p>';
                if(actions) actions.style.display='none';
                return;
            }

            if(actions) actions.style.display='block';

            var html = '<table class="rdrit-ls-table"><thead><tr>'
                     + '<th>Préférence</th><th>Valeur</th><th></th>'
                     + '</tr></thead><tbody>';
            keys.forEach(function(key){
                var val = localStorage.getItem(key)||'';
                html += '<tr>'
                      + '<td class="rdrit-ls-key">'+getLabel(key)+'</td>'
                      + '<td class="rdrit-ls-val">'+formatValue(val)+'</td>'
                      + '<td><button class="rdrit-ls-del" data-key="'+key+'" title="Supprimer">🗑 Supprimer</button></td>'
                      + '</tr>';
            });
            html += '</tbody></table>';
            content.innerHTML = html;

            content.querySelectorAll('.rdrit-ls-del').forEach(function(btn){
                btn.addEventListener('click',function(){
                    localStorage.removeItem(this.dataset.key);
                    render();
                });
            });
        }

        document.addEventListener('DOMContentLoaded',function(){
            render();
            var btnAll = document.querySelector('#'+uid+'-actions .rdrit-ls-reset-all');
            if(btnAll){
                btnAll.addEventListener('click',function(){
                    if(!confirm('Effacer toutes les préférences ?')) return;
                    if(prefix){
                        getKeys().forEach(function(k){ localStorage.removeItem(k); });
                    } else {
                        localStorage.clear();
                    }
                    render();
                });
            }
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}

/* ============================================================
   SHORTCODE [rdrit_localstorage_link]
   Affiche un lien/bouton vers la page de gestion des préférences.
   Masqué si localStorage est vide (verifie au DOMContentLoaded).

   Parametres :
     url        string  URL de la page dédiée (OBLIGATOIRE)
     label      string  Texte du lien (defaut : "⚙️ Mes préférences")
     prefix     string  Filtrer sur un prefixe de cle pour la detection
     class      string  Classe CSS supplementaire
   ============================================================ */
function rdrit_sc_localstorage_link( $atts ): string {
    $atts = shortcode_atts( array(
        'url'    => '',
        'label'  => '⚙️ Mes préférences',
        'prefix' => '',
        'class'  => '',
    ), $atts, 'rdrit_localstorage_link' );

    $url    = esc_url( $atts['url'] );
    $label  = sanitize_text_field( $atts['label'] );
    $prefix = sanitize_text_field( $atts['prefix'] );
    $class  = sanitize_html_class( $atts['class'] );

    if ( ! $url ) return '';

    static $link_instance = 0;
    $link_instance++;
    $uid = 'rdrit-ls-link-' . $link_instance;

    ob_start();
    ?>
    <a id="<?php echo esc_attr($uid); ?>"
       href="<?php echo $url; ?>"
       class="rdrit-ls-link <?php echo esc_attr($class); ?>"
       style="display:none">
        <?php echo esc_html($label); ?>
    </a>
    <style>
    .rdrit-ls-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.88rem;font-weight:600;color:inherit;text-decoration:none;padding:.4em 1em;border-radius:6px;border:1.5px solid var(--rdrit-border,#d1d5db);transition:border-color .15s,color .15s,transform .15s}
    .rdrit-ls-link:hover{border-color:#2271b1;color:#2271b1;transform:translateY(-1px)}
    </style>
    <script>
    (function(){
        var uid    = <?php echo wp_json_encode($uid); ?>;
        var prefix = <?php echo wp_json_encode($prefix); ?>;

        function hasData(){
            for(var i=0;i<localStorage.length;i++){
                var k = localStorage.key(i);
                if(!prefix || k.indexOf(prefix)===0) return true;
            }
            return false;
        }

        document.addEventListener('DOMContentLoaded',function(){
            var el = document.getElementById(uid);
            if(el && hasData()) el.style.display='inline-flex';
        });
    })();
    </script>
    <?php
    return ob_get_clean();
}
