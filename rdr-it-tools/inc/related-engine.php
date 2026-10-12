<?php
/**
 * RDR-IT Related Posts Engine
 *
 * Moteur maison — sans YARPP.
 * Algorithme : score = catégories (×3) + tags (×2) + mots titre (×1)
 * Cache      : WP Transients, durée configurable (défaut 24h)
 * Modes      : synchrone ou AJAX lazy-loading
 * CPT        : liste dynamique, activation/désactivation par CPT et par post
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   CONSTANTES
   ============================================================ */
define( 'RDRIT_REL_CACHE_PREFIX', 'rdrit_rel_' );
define( 'RDRIT_REL_CACHE_TTL',    DAY_IN_SECONDS ); // surchargé par option

/* ============================================================
   HOOKS D'AFFICHAGE — appelés depuis post-footer.php
   ============================================================ */

/**
 * Point d'entrée principal — appelé par post-footer.php
 */
function rdrit_related_render( array $opts ) {
    $post_id  = get_the_ID();
    $post_type = get_post_type( $post_id );

    /* Vérifier si désactivé au niveau du CPT */
    $disabled_cpts = rdrit_rel_disabled_cpts();
    if ( in_array( $post_type, $disabled_cpts, true ) ) return;

    /* Vérifier si désactivé sur ce post spécifique */
    if ( get_post_meta( $post_id, '_rdrit_related_disabled', true ) === '1' ) return;

    $title  = esc_html( $opts['related_title'] );
    $layout = $opts['related_layout'];
    $count  = (int) $opts['related_count'];
    $mode   = $opts['related_mode']; // sync | ajax

    echo '<div class="rdrit-related-wrap rdrit-related--' . esc_attr( $layout ) . '">';
    echo '<h3 class="rdrit-related-title">' . $title . '</h3>';

    if ( $mode === 'ajax' ) {
        /* Lazy loading AJAX — placeholder avec spinner */
        echo '<div class="rdrit-related-lazy"'
           . ' data-post-id="' . esc_attr( $post_id ) . '"'
           . ' data-count="'   . esc_attr( $count )   . '"'
           . ' data-layout="'  . esc_attr( $layout )  . '"'
           . ' data-nonce="'   . esc_attr( wp_create_nonce('rdrit_related_nonce') ) . '">'
           . '<div class="rdrit-related-spinner"><span></span><span></span><span></span></div>'
           . '</div>';
    } else {
        /* Synchrone */
        $posts = rdrit_related_get_posts( $post_id, $count );
        echo rdrit_related_render_posts( $posts, $layout );
    }

    echo '</div>';
}

/* ============================================================
   AJAX ENDPOINT
   ============================================================ */
add_action( 'wp_ajax_rdrit_get_related',        'rdrit_ajax_related' );
add_action( 'wp_ajax_nopriv_rdrit_get_related', 'rdrit_ajax_related' );

function rdrit_ajax_related() {
    /* Empecher toute mise en cache de la reponse AJAX
       (Cloudflare / LiteSpeed peuvent cacher admin-ajax.php) */
    nocache_headers();

    /* Verification du nonce NON BLOQUANTE.
       Cet endpoint est public et en lecture seule : il n'expose que des
       articles deja publies, aucune modification d'etat. Il n'y a donc
       aucun risque CSRF.
       Avec un cache agressif (LiteSpeed + Cloudflare APO), la page HTML
       reste en cache plusieurs jours alors que le nonce expire au bout de
       12-24h : un check bloquant renverrait -1 et afficherait du vide. */
    check_ajax_referer( 'rdrit_related_nonce', 'nonce', false );

    $post_id = absint( $_POST['post_id'] ?? 0 );
    $count   = min( 12, max( 1, absint( $_POST['count'] ?? 4 ) ) );
    $layout  = sanitize_key( $_POST['layout'] ?? 'cards-3' );

    if ( ! $post_id ) wp_send_json_error('invalid');

    /* Le post doit exister et etre publie */
    if ( get_post_status( $post_id ) !== 'publish' ) wp_send_json_error('not_published');

    $posts = rdrit_related_get_posts( $post_id, $count );
    $html  = rdrit_related_render_posts( $posts, $layout );

    wp_send_json_success( array( 'html' => $html ) );
}

/* ============================================================
   ALGORITHME DE SCORING
   ============================================================ */

/**
 * Retourne les posts similaires triés par score décroissant.
 * Utilise le cache transient WP.
 *
 * @param int $post_id
 * @param int $count
 * @return WP_Post[]
 */
function rdrit_related_get_posts( int $post_id, int $count ): array {
    $cache_key = RDRIT_REL_CACHE_PREFIX . $post_id . '_' . $count;
    $cached    = get_transient( $cache_key );

    /* Le cache stocke des IDs (leger, fiable) et non des objets WP_Post
       serialises qui peuvent depasser la taille max d'une option. */
    if ( false !== $cached ) {
        if ( ! is_array( $cached ) ) return array();
        if ( empty( $cached ) )      return array();

        /* Retro-compat : anciens transients contenant des objets WP_Post */
        if ( $cached[0] instanceof WP_Post ) return $cached;

        $cached_posts = get_posts( array(
            'post__in'            => array_map( 'absint', $cached ),
            'posts_per_page'      => count( $cached ),
            'post_type'           => 'any',
            'post_status'         => 'publish',
            'ignore_sticky_posts' => true,
            'orderby'             => 'post__in',
        ) );
        /* Si les posts ont ete depublies/supprimes entre-temps, on purge
           le cache et on recalcule au lieu de renvoyer du vide */
        if ( empty( $cached_posts ) ) {
            delete_transient( $cache_key );
        } else {
            return $cached_posts;
        }
    }

    $post_type = get_post_type( $post_id );

    /* ── Récupérer les termes du post courant ── */
    $cat_taxonomy = rdrit_rel_cat_taxonomy( $post_type );
    $tag_taxonomy = rdrit_rel_tag_taxonomy( $post_type );

    $cat_ids = rdrit_rel_term_ids( $post_id, $cat_taxonomy );
    $tag_ids = rdrit_rel_term_ids( $post_id, $tag_taxonomy );

    /* Mots-clés du titre (≥4 chars, dédoublonnés) */
    $title_words = rdrit_rel_title_words( $post_id );

    /* ── Pool de candidats ── */
    $pool_args = array(
        'post_type'           => $post_type,
        'post_status'         => 'publish',
        'post__not_in'        => array( $post_id ),
        'posts_per_page'      => 100,
        'ignore_sticky_posts' => true,
        'fields'              => 'ids',
    );

    /* Filtre par termes (OR) si disponibles */
    if ( ! empty( $cat_ids ) || ! empty( $tag_ids ) ) {
        $tax_query = array( 'relation' => 'OR' );
        if ( ! empty( $cat_ids ) && $cat_taxonomy ) {
            $tax_query[] = array( 'taxonomy' => $cat_taxonomy, 'field' => 'term_id', 'terms' => $cat_ids );
        }
        if ( ! empty( $tag_ids ) && $tag_taxonomy ) {
            $tax_query[] = array( 'taxonomy' => $tag_taxonomy, 'field' => 'term_id', 'terms' => $tag_ids );
        }
        $pool_args['tax_query'] = $tax_query;
    }

    $pool = get_posts( $pool_args );

    /* Si pool trop petit, élargir sans filtre taxo */
    if ( count( $pool ) < $count ) {
        $extra = get_posts( array_merge( $pool_args, array(
            'posts_per_page' => 50,
            'post__not_in'   => array_merge( array( $post_id ), $pool ),
            'orderby'        => 'date',
            'fields'         => 'ids',
        ) + array_diff_key( $pool_args, array('tax_query'=>0) ) ) );
        unset( $extra['tax_query'] );
        // Faire une requête propre sans tax_query
        $extra_args = $pool_args;
        unset( $extra_args['tax_query'] );
        $extra_args['posts_per_page'] = 50;
        $extra_args['post__not_in']   = array_merge( array( $post_id ), $pool );
        $extra_args['orderby']        = 'date';
        $pool = array_merge( $pool, get_posts( $extra_args ) );
    }

    /* ── Scoring ── */
    $scored = array();
    foreach ( $pool as $cid ) {
        $score = 0;

        /* Catégories communes × 3 */
        if ( $cat_taxonomy && ! empty( $cat_ids ) ) {
            $c_ids  = rdrit_rel_term_ids( $cid, $cat_taxonomy );
            $common = count( array_intersect( $cat_ids, $c_ids ) );
            $score += $common * 3;
        }

        /* Tags communs × 2 */
        if ( $tag_taxonomy && ! empty( $tag_ids ) ) {
            $t_ids  = rdrit_rel_term_ids( $cid, $tag_taxonomy );
            $common = count( array_intersect( $tag_ids, $t_ids ) );
            $score += $common * 2;
        }

        /* Mots titre communs × 1 */
        if ( ! empty( $title_words ) ) {
            $c_words = rdrit_rel_title_words( $cid );
            $common  = count( array_intersect( $title_words, $c_words ) );
            $score  += $common;
        }

        if ( $score > 0 ) {
            $scored[ $cid ] = $score;
        }
    }

    /* Trier par score DESC, puis date DESC pour égalité */
    arsort( $scored );
    $top_ids = array_slice( array_keys( $scored ), 0, $count );

    /* Compléter si pas assez de résultats scorés */
    if ( count( $top_ids ) < $count ) {
        $remaining = $count - count( $top_ids );
        $fallback  = array_diff( $pool, $top_ids );
        $top_ids   = array_merge( $top_ids, array_slice( $fallback, 0, $remaining ) );
    }

    if ( empty( $top_ids ) ) {
        /* Resultat vide : cache TRES court (5 min) pour ne pas figer
           un echec temporaire pendant toute la duree du TTL normal */
        set_transient( $cache_key, array(), 5 * MINUTE_IN_SECONDS );
        return array();
    }

    $posts = get_posts( array(
        'post__in'            => $top_ids,
        'posts_per_page'      => $count,
        'post_type'           => $post_type,
        'post_status'         => 'publish',
        'ignore_sticky_posts' => true,
        'orderby'             => 'post__in',
    ) );

    /* Stocker uniquement les IDs dans le transient */
    set_transient( $cache_key, wp_list_pluck( $posts, 'ID' ), rdrit_rel_cache_ttl() );
    return $posts;
}

/* ============================================================
   RENDU HTML
   ============================================================ */
function rdrit_related_render_posts( array $posts, string $layout ): string {
    if ( empty( $posts ) ) {
        return '<p class="rdrit-related-empty">Aucun article similaire trouvé.</p>';
    }

    if ( $layout === 'text' ) {
        $html = '<ul class="rdrit-rel-list">';
        foreach ( $posts as $p ) {
            $html .= '<li class="rdrit-rel-item">'
                   . '<a href="' . esc_url( get_permalink($p) ) . '">' . esc_html( get_the_title($p) ) . '</a>'
                   . '<span class="rdrit-rel-date">📅 ' . get_the_date('d/m/Y', $p) . '</span>'
                   . '</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    /* Layouts cartes */
    $html = '<div class="rdrit-related-grid">';
    foreach ( $posts as $p ) {
        $thumb = get_the_post_thumbnail_url( $p->ID, 'medium' );
        $cats  = get_the_category( $p->ID );
        $cat   = $cats ? esc_html( $cats[0]->name ) : '';

        $html .= '<a href="' . esc_url( get_permalink($p) ) . '" class="rdrit-rel-card">';

        if ( $thumb ) {
            $html .= '<img src="' . esc_url($thumb) . '" alt="' . esc_attr( get_the_title($p) ) . '" loading="lazy">';
        } else {
            $html .= '<div class="rdrit-rel-card-noimg">📄</div>';
        }

        $html .= '<div class="rdrit-rel-card-body">';
        if ( $cat ) $html .= '<span class="rdrit-rel-card-cat">' . $cat . '</span>';
        $html .= '<div class="rdrit-rel-card-title">' . esc_html( get_the_title($p) ) . '</div>';
        $html .= '<div class="rdrit-rel-card-date">📅 ' . get_the_date('d/m/Y', $p) . '</div>';
        $html .= '</div></a>';
    }
    $html .= '</div>';
    return $html;
}

/* ============================================================
   HELPERS ALGORITHME
   ============================================================ */
function rdrit_rel_cat_taxonomy( string $post_type ): string {
    $map = array(
        'domotique' => 'cat-domo',
    );
    return isset( $map[$post_type] ) ? $map[$post_type] : 'category';
}

function rdrit_rel_tag_taxonomy( string $post_type ): string {
    $map = array(
        'domotique' => 'tag-domo',
    );
    return isset( $map[$post_type] ) ? $map[$post_type] : 'post_tag';
}

function rdrit_rel_term_ids( int $post_id, string $taxonomy ): array {
    if ( ! $taxonomy ) return array();
    $terms = get_the_terms( $post_id, $taxonomy );
    if ( ! $terms || is_wp_error($terms) ) return array();
    return wp_list_pluck( $terms, 'term_id' );
}

function rdrit_rel_title_words( int $post_id ): array {
    $title = get_the_title( $post_id );
    $title = strtolower( remove_accents( $title ) );
    $words = preg_split('/[\s\-_\/]+/', $title);
    $stop  = array('le','la','les','de','du','des','un','une','en','et','ou','au','aux',
                   'ce','se','sa','son','ses','par','sur','pour','dans','avec','sans',
                   'the','a','an','in','on','of','to','and','or','is','are','for');
    $words = array_filter( $words, function($w) use ($stop) { return strlen($w) >= 4 && !in_array($w, $stop, true); } );
    return array_unique( array_values($words) );
}

function rdrit_rel_cache_ttl(): int {
    $hours = (int) get_option('rdrit_rel_cache_hours', 24);
    return $hours * HOUR_IN_SECONDS;
}

function rdrit_rel_disabled_cpts(): array {
    $saved = get_option('rdrit_rel_disabled_cpts', '');
    return $saved ? array_filter( explode(',', $saved) ) : array();
}

/* ============================================================
   INVALIDATION CACHE à la mise à jour d'un post
   ============================================================ */
add_action( 'save_post',   'rdrit_related_flush_cache' );
add_action( 'deleted_post','rdrit_related_flush_cache' );

function rdrit_related_flush_cache( int $post_id ) {
    /* Flush le cache du post lui-même */
    for ( $i = 1; $i <= 12; $i++ ) {
        delete_transient( RDRIT_REL_CACHE_PREFIX . $post_id . '_' . $i );
    }
    /* Note : on ne flush pas les caches des autres posts qui pourraient
       pointer vers celui-ci — TTL de 24h est suffisant pour ce cas */
}

/* ============================================================
   META BOX — désactivation par post
   ============================================================ */
add_action( 'add_meta_boxes', 'rdrit_related_meta_box' );

function rdrit_related_meta_box() {
    /* Récupérer tous les CPT activés dynamiquement */
    $cpts = rdrit_rel_get_active_cpts();
    foreach ( $cpts as $cpt ) {
        add_meta_box(
            'rdrit_related_disable',
            '📚 Articles similaires RDR-IT',
            'rdrit_related_meta_box_cb',
            $cpt,
            'side',
            'low'
        );
    }
}

function rdrit_related_meta_box_cb( WP_Post $post ) {
    wp_nonce_field( 'rdrit_related_meta', 'rdrit_related_nonce_field' );
    $disabled = get_post_meta( $post->ID, '_rdrit_related_disabled', true );
    echo '<label style="display:flex;align-items:center;gap:8px;cursor:pointer;">';
    echo '<input type="checkbox" name="rdrit_related_disabled" value="1" ' . checked($disabled,'1',false) . '>';
    echo '<span>Désactiver les articles similaires sur ce post</span>';
    echo '</label>';
    if ( $disabled === '1' ) {
        echo '<p style="margin:.5em 0 0;font-size:.82em;color:#b91c1c;">⚠️ Articles similaires masqués.</p>';
    }
}

add_action( 'save_post', 'rdrit_related_save_meta' );
function rdrit_related_save_meta( int $post_id ) {
    if ( ! isset($_POST['rdrit_related_nonce_field']) ) return;
    if ( ! wp_verify_nonce($_POST['rdrit_related_nonce_field'], 'rdrit_related_meta') ) return;
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    $val = isset($_POST['rdrit_related_disabled']) ? '1' : '0';
    update_post_meta( $post_id, '_rdrit_related_disabled', $val );
}

/* ============================================================
   LISTE DYNAMIQUE DES CPT ACTIVÉS
   ============================================================ */
function rdrit_rel_get_active_cpts(): array {
    /* Tous les CPT publics enregistrés */
    $all = get_post_types( array('public'=>true, 'show_ui'=>true), 'names' );
    $all = array_values( array_diff( $all, array('attachment','revision','nav_menu_item','custom_css','customize_changeset','wp_block','wp_template','wp_template_part','wp_navigation') ) );

    /* Filtrés par la liste désactivée en admin */
    $disabled = rdrit_rel_disabled_cpts();
    return array_values( array_diff($all, $disabled) );
}

/* ============================================================
   ENQUEUE JS FRONT (AJAX lazy)
   ============================================================ */
add_action( 'wp_enqueue_scripts', 'rdrit_related_enqueue' );
function rdrit_related_enqueue() {
    if ( ! is_singular() ) return;
    $opts = rdrit_pf_opts();
    if ( ! $opts['related_enabled'] || $opts['related_mode'] !== 'ajax' ) return;

    // Enregistrer sans dépendance jQuery — le JS utilise fetch() natif
    wp_register_script(
        'rdrit-related-lazy',
        false, // inline, pas de fichier externe
        array(),
        null,
        true // footer
    );
    wp_enqueue_script( 'rdrit-related-lazy' );
    if ( (bool) get_option( 'rdrit_front_debug', 0 ) ) {
        wp_add_inline_script( 'rdrit-related-lazy', 'window.rdritRelDebug=true;', 'before' );
    }
    wp_add_inline_script( 'rdrit-related-lazy', rdrit_related_lazy_js() );
}

function rdrit_related_lazy_js(): string {
    $ajax_url = admin_url('admin-ajax.php');
    return <<<JS
document.addEventListener('DOMContentLoaded',function(){
    var targets = document.querySelectorAll('.rdrit-related-lazy');
    if(!targets.length) return;

    var observer = new IntersectionObserver(function(entries,obs){
        entries.forEach(function(entry){
            if(!entry.isIntersecting) return;
            obs.unobserve(entry.target);
            var el = entry.target;
            function relFail(reason){
                if(window.rdritRelDebug){
                    console.warn('[RDRIT Related] Echec :', reason, el);
                }
                el.innerHTML='';
                var wrap = el.closest('.rdrit-related-wrap');
                if(wrap) wrap.style.display='none';
            }
            fetch('{$ajax_url}', {
                method:'POST',
                headers:{'Content-Type':'application/x-www-form-urlencoded'},
                credentials:'same-origin',
                cache:'no-store',
                body: new URLSearchParams({
                    action:  'rdrit_get_related',
                    nonce:   el.dataset.nonce,
                    post_id: el.dataset.postId,
                    count:   el.dataset.count,
                    layout:  el.dataset.layout
                })
            })
            .then(function(r){
                return r.text().then(function(txt){
                    if(txt==='-1'||txt==='0'){ throw new Error('nonce_or_permission'); }
                    try { return JSON.parse(txt); }
                    catch(e){ throw new Error('invalid_json: '+txt.slice(0,120)); }
                });
            })
            .then(function(d){
                if(d && d.success && d.data && d.data.html){
                    el.innerHTML = d.data.html;
                    el.classList.add('rdrit-loaded');
                } else {
                    relFail('reponse vide');
                }
            })
            .catch(function(err){ relFail(err && err.message ? err.message : 'reseau'); });
        });
    },{rootMargin:'200px'});

    targets.forEach(function(t){ observer.observe(t); });
});
JS;
}

/* ============================================================
   CSS SPINNER LAZY
   ============================================================ */
add_action('wp_head','rdrit_related_spinner_css');
function rdrit_related_spinner_css(){
    if(!is_singular()) return;
    $opts = rdrit_pf_opts();
    if(!$opts['related_enabled'] || $opts['related_mode']!=='ajax') return;
    echo '<style>
.rdrit-related-spinner{display:flex;gap:6px;justify-content:center;padding:20px}
.rdrit-related-spinner span{width:8px;height:8px;border-radius:50%;background:#2271b1;animation:rdrit-bounce 1.2s infinite ease-in-out both}
.rdrit-related-spinner span:nth-child(1){animation-delay:-.32s}
.rdrit-related-spinner span:nth-child(2){animation-delay:-.16s}
@keyframes rdrit-bounce{0%,80%,100%{transform:scale(0);opacity:.4}40%{transform:scale(1);opacity:1}}
</style>';
}
