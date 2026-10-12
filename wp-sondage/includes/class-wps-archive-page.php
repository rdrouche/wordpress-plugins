<?php
/**
 * WPS_Archive_Page
 *
 * Transforme une page WordPress native en archive des sondages.
 * Avantages vs CPT archive :
 *  - Intégration thème parfaite (header/footer/sidebar natifs)
 *  - Breadcrumb Rank Math : HOME >> [Page] >> Titre sondage ✓
 *  - Polylang : chaque langue a sa propre page traduite
 *  - Pas de rewrite rules à gérer
 *
 * Fonctionnement :
 *  1. L'admin déclare une page par langue dans Réglages → WP Sondage
 *  2. Sur cette page, the_content est remplacé par la liste des sondages
 *  3. Pagination et filtres en full AJAX (pas d'intérêt SEO)
 *  4. La page sondage individuelle déclare cette page comme parent
 *     via le filtre rank_math/breadcrumbs (pour le breadcrumb)
 */
class WPS_Archive_Page {

    public static function init() {
        add_filter( 'the_content',        [ __CLASS__, 'maybe_inject_archive' ] );
        add_filter( 'the_title',          [ __CLASS__, 'filter_archive_title' ], 10, 2 );

        // Breadcrumb Rank Math — déclarer la page archive comme parent du sondage
        add_filter( 'rank_math/breadcrumbs/items', [ __CLASS__, 'fix_breadcrumb' ], 20 );

        // AJAX — chargement de la liste
        add_action( 'wp_ajax_wps_archive_load',        [ __CLASS__, 'ajax_load' ] );
        add_action( 'wp_ajax_nopriv_wps_archive_load', [ __CLASS__, 'ajax_load' ] );
    }

    /* ════════════════════════════════════════
       DÉTECTION DE LA PAGE ARCHIVE
    ════════════════════════════════════════ */

    /**
     * Retourne l'ID de la page archive pour la langue courante.
     * Supporte Polylang : lit wps_archive_page_id_{lang}.
     * Fallback sur wps_archive_page_id (mono-langue).
     */
    public static function get_archive_page_id(): int {
        // Langue courante Polylang
        $lang = '';
        if ( function_exists( 'pll_current_language' ) ) {
            $lang = pll_current_language();
        }

        if ( $lang ) {
            $id = (int) get_option( 'wps_archive_page_id_' . $lang, 0 );
            if ( $id ) return $id;
        }

        return (int) get_option( 'wps_archive_page_id', 0 );
    }

    /**
     * Vérifie si la page courante est la page archive des sondages.
     */
    public static function is_archive_page(): bool {
        if ( ! is_page() || ! in_the_loop() || ! is_main_query() ) return false;
        $id = self::get_archive_page_id();
        return $id > 0 && get_the_ID() === $id;
    }

    /* ════════════════════════════════════════
       INJECTION DE LA LISTE
    ════════════════════════════════════════ */

    public static function maybe_inject_archive( string $content ): string {
        if ( ! self::is_archive_page() ) return $content;

        ob_start();
        self::render_archive_shell();
        $html = ob_get_clean();

        // On AJOUTE après le contenu existant de la page (description, etc.)
        return $content . $html;
    }

    public static function filter_archive_title( string $title, int $id ): string {
        // Ne pas modifier le title ici — Rank Math gère ça
        return $title;
    }

    /* ════════════════════════════════════════
       RENDU — coquille (shell chargé immédiatement)
    ════════════════════════════════════════ */

    private static function render_archive_shell() {
        global $wpdb;

        // Stats globales (légères)
        $total_votes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wps_votes" );
        $total_polls = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type='wps_poll' AND post_status='publish'"
        );

        // Catégories disponibles
        $cats = get_terms( [ 'taxonomy' => 'wps_category', 'hide_empty' => true ] );

        // Langue courante (pour filtrer les sondages traduits)
        $lang = function_exists( 'pll_current_language' ) ? pll_current_language() : '';
        ?>
        <div class="wps-archive-wrap wps-theme-auto"
             id="wps-archive-root"
             data-lang="<?php echo esc_attr( $lang ); ?>">

            <!-- Stats + titre -->
            <div class="wps-archive-hero">
                <div class="wps-archive-hero-stat">
                    <strong><?php echo number_format( $total_polls ); ?></strong>
                    <span><?php _e( 'Sondages', 'wp-sondage' ); ?></span>
                </div>
                <div class="wps-archive-hero-stat">
                    <strong id="wps-stat-open">—</strong>
                    <span><?php _e( 'Ouverts', 'wp-sondage' ); ?></span>
                </div>
                <div class="wps-archive-hero-stat">
                    <strong><?php echo number_format( $total_votes ); ?></strong>
                    <span><?php _e( 'Votes', 'wp-sondage' ); ?></span>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="wps-archive-toolbar">
                <input type="search"
                       id="wps-archive-search"
                       class="wps-archive-search"
                       placeholder="<?php esc_attr_e( 'Rechercher…', 'wp-sondage' ); ?>"
                       autocomplete="off">
                <select class="wps-archive-sort" id="wps-archive-sort">
                    <option value="date"><?php _e( 'Plus récents', 'wp-sondage' ); ?></option>
                    <option value="votes"><?php _e( 'Plus votés', 'wp-sondage' ); ?></option>
                    <option value="alpha"><?php _e( 'A → Z', 'wp-sondage' ); ?></option>
                </select>
            </div>

            <!-- Filtres catégories -->
            <?php if ( $cats && ! is_wp_error( $cats ) ) : ?>
            <nav class="wps-cat-nav" id="wps-cat-nav" aria-label="<?php esc_attr_e( 'Catégories', 'wp-sondage' ); ?>">
                <button class="wps-cat-link wps-cat-link--active" data-cat="" type="button">
                    <?php _e( 'Tous', 'wp-sondage' ); ?>
                </button>
                <?php foreach ( $cats as $cat ) : ?>
                <button class="wps-cat-link" data-cat="<?php echo esc_attr( $cat->slug ); ?>" type="button">
                    <?php echo esc_html( $cat->name ); ?>
                    <span class="wps-cat-count"><?php echo (int) $cat->count; ?></span>
                </button>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>

            <!-- Zone de contenu AJAX -->
            <div id="wps-archive-content">
                <!-- Skeleton initial — 6 cartes -->
                <div class="wps-polls-grid wps-archive-skeleton">
                    <?php for ( $i = 0; $i < 6; $i++ ) : ?>
                    <div class="wps-poll-card wps-card-skeleton">
                        <div class="wps-card-link" aria-hidden="true">
                            <div class="wps-sk-line wps-sk-title"></div>
                            <div class="wps-sk-line wps-sk-bar"></div>
                            <div class="wps-sk-line wps-sk-meta"></div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
            </div>

            <!-- Pagination AJAX -->
            <div id="wps-archive-pagination" class="wps-pagination" style="display:none"></div>

        </div>
        <?php
    }

    /* ════════════════════════════════════════
       AJAX — chargement de la liste
    ════════════════════════════════════════ */

    public static function ajax_load() {
        // Lecture seule publique : pas de nonce (il serait périmé en cache).
        if ( ! WPS_Cache::rate_limit_ok( 'archive', 90, 60 ) ) {
            wp_send_json_error( [ 'code' => 'rate_limit' ] );
        }

        // Filtrage bot ici (réponse AJAX jamais mise en cache)
        if ( WPS_Antiflood::is_bot() ) {
            wp_send_json_success( [ 'html' => '', 'pagination' => '', 'open' => 0 ] );
        }

        $page   = max( 1, (int) ( $_POST['page']   ?? 1 ) );
        $sort   = sanitize_key(  $_POST['sort']   ?? 'date' );
        $cat    = sanitize_text_field( $_POST['cat'] ?? '' );
        $search = sanitize_text_field( $_POST['search'] ?? '' );
        $lang   = sanitize_key( $_POST['lang'] ?? '' );
        $per    = 12;

        $args = [
            'post_type'      => 'wps_poll',
            'post_status'    => 'publish',
            'posts_per_page' => $per,
            'paged'          => $page,
            'meta_query'     => [ [ 'key' => '_wps_active', 'value' => '1' ] ],
        ];

        // Tri
        switch ( $sort ) {
            case 'alpha': $args['orderby'] = 'title'; $args['order'] = 'ASC'; break;
            case 'votes': $args['orderby'] = 'date';  $args['order'] = 'DESC'; break; // trié côté PHP après
            default:      $args['orderby'] = 'date';  $args['order'] = 'DESC';
        }

        // Filtre catégorie
        if ( $cat ) {
            $args['tax_query'] = [ [ 'taxonomy' => 'wps_category', 'field' => 'slug', 'terms' => $cat ] ];
        }

        // Filtre recherche (sur la question)
        if ( $search ) {
            // Cherche dans le méta _wps_question
            $args['meta_query'][] = [
                'key'     => '_wps_question',
                'value'   => $search,
                'compare' => 'LIKE',
            ];
            // Et dans le titre (OR)
            add_filter( 'posts_where', function( $where ) use ( $search ) {
                global $wpdb;
                $where .= $wpdb->prepare(
                    " OR {$wpdb->posts}.post_title LIKE %s",
                    '%' . $wpdb->esc_like( $search ) . '%'
                );
                return $where;
            } );
        }

        // Filtre langue Polylang
        if ( $lang && function_exists( 'pll_get_post_language' ) ) {
            add_filter( 'pll_get_post_language', '__return_true' );
        }

        global $wpdb;
        $query = new WP_Query( $args );

        // Trier par votes si demandé (sous-requête)
        $posts = $query->posts;
        if ( $sort === 'votes' && $posts ) {
            $ids       = array_map( fn( $p ) => $p->ID, $posts );
            $ids_ph    = implode( ',', array_map( 'intval', $ids ) );
            $vote_cnts = $wpdb->get_results(
                "SELECT poll_id, COUNT(*) AS cnt FROM {$wpdb->prefix}wps_votes WHERE poll_id IN ($ids_ph) GROUP BY poll_id"
            );
            $vc = [];
            foreach ( $vote_cnts as $r ) $vc[ $r->poll_id ] = (int) $r->cnt;
            usort( $posts, fn( $a, $b ) => ( $vc[ $b->ID ] ?? 0 ) <=> ( $vc[ $a->ID ] ?? 0 ) );
        }

        // Compter les ouverts (pour le stat hero)
        $all_ids    = get_posts( [ 'post_type'=>'wps_poll','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids','meta_query'=>[ ['key'=>'_wps_active','value'=>'1'] ] ] );
        $open_count = 0;
        foreach ( $all_ids as $id ) { if ( WPS_Ajax::poll_is_open( $id ) ) $open_count++; }

        ob_start();

        if ( $posts ) {
            echo '<div class="wps-polls-grid">';
            foreach ( $posts as $post ) {
                $id       = $post->ID;
                $question = get_post_meta( $id, '_wps_question', true ) ?: $post->post_title;
                $is_open  = WPS_Ajax::poll_is_open( $id );
                $voted    = WPS_Antiflood::has_voted( $id );
                $end_date = get_post_meta( $id, '_wps_end_date', true );
                $type     = get_post_meta( $id, '_wps_type', true );
                $votes    = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}wps_votes WHERE poll_id=%d", $id
                ) );
                $cats_p   = get_the_terms( $id, 'wps_category' );
                $purl     = get_permalink( $id );
                $cls      = ! $is_open ? 'wps-poll-card wps-card-closed' : 'wps-poll-card';
                ?>
                <article class="<?php echo $cls; ?>"
                         data-poll-id="<?php echo $id; ?>"
                         data-question="<?php echo esc_attr( mb_strtolower( $question ) ); ?>"
                         data-votes="<?php echo $votes; ?>">
                    <a href="<?php echo esc_url( $purl ); ?>" class="wps-card-link">
                        <div class="wps-card-question"><?php echo esc_html( $question ); ?></div>
                        <div class="wps-card-progress">
                            <div class="wps-card-progress-fill" style="width:0%" data-votes="<?php echo $votes; ?>"></div>
                        </div>
                        <div class="wps-card-meta">
                            <span title="<?php echo $type==='checkbox' ? esc_attr__('Choix multiples','wp-sondage') : esc_attr__('Choix unique','wp-sondage'); ?>">
                                <?php echo $type==='checkbox' ? '☑' : '◉'; ?>
                            </span>
                            <span class="wps-card-votes">
                                <?php printf( _n('%d vote','%d votes',$votes,'wp-sondage'), $votes ); ?>
                            </span>
                            <?php if ( $end_date && strtotime($end_date) > time() ) : ?>
                            <span>📅 <?php echo date_i18n( get_option('date_format'), strtotime($end_date) ); ?></span>
                            <?php endif; ?>
                            <?php if ( ! $is_open ) : ?>
                            <span class="wps-badge-closed"><?php _e('Fermé','wp-sondage'); ?></span>
                            <?php elseif ( $voted ) : ?>
                            <span class="wps-badge-voted">✅ <?php _e('Répondu','wp-sondage'); ?></span>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php if ( $cats_p && ! is_wp_error( $cats_p ) ) : ?>
                    <div class="wps-card-cats">
                        <?php foreach ( $cats_p as $cat ) : ?>
                        <span class="wps-card-cat-badge"><?php echo esc_html($cat->name); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </article>
                <?php
            }
            echo '</div>';
        } else {
            echo '<div class="wps-empty-state">';
            echo '<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>';
            echo '<p>' . __( 'Aucun sondage disponible.', 'wp-sondage' ) . '</p>';
            echo '</div>';
        }

        $grid_html = ob_get_clean();

        // Pagination
        $total_pages = $query->max_num_pages;
        $pag_html    = '';
        if ( $total_pages > 1 ) {
            ob_start();
            for ( $p = 1; $p <= $total_pages; $p++ ) {
                $cls = $p === $page ? 'page-numbers current' : 'page-numbers';
                echo '<button class="' . $cls . '" data-page="' . $p . '" type="button">' . $p . '</button> ';
            }
            $pag_html = ob_get_clean();
        }

        wp_send_json_success( [
            'html'       => $grid_html,
            'pagination' => $pag_html,
            'open'       => $open_count,
            'total'      => $query->found_posts,
            'nonce'      => WPS_Cache::fresh_write_nonce(),
        ] );
    }

    /* ════════════════════════════════════════
       BREADCRUMB RANK MATH
       Injecte la page archive comme parent dans le fil d'Ariane.

       Format Rank Math pour chaque item :
         ['url' => '...', 'title' => '...', 'hide_title' => false]

       Résultat : HOME >> [Page archive] >> Titre du sondage
    ════════════════════════════════════════ */

    public static function fix_breadcrumb( array $items ): array {
        if ( ! is_singular( 'wps_poll' ) ) return $items;

        $archive_id = self::get_archive_page_id();
        if ( ! $archive_id ) return $items;

        $archive_page = get_post( $archive_id );
        if ( ! $archive_page || $archive_page->post_status !== 'publish' ) return $items;

        $archive_url   = (string) get_permalink( $archive_id );
        $archive_title = (string) get_the_title( $archive_id );

        // Vérifier si la page archive est déjà présente (éviter les doublons)
        foreach ( $items as $item ) {
            $item_url = trim( $item['url'] ?? $item['uri'] ?? '', '/' );
            if ( $item_url && $item_url === trim( $archive_url, '/' ) ) {
                return $items;
            }
        }

        // Construire l'item au format attendu par Rank Math
        $archive_item = [
            'url'        => $archive_url,
            'title'      => $archive_title,   // ← Rank Math utilise 'title', pas 'text'
            'hide_title' => false,
        ];

        // Insérer avant le dernier item (= titre du sondage courant)
        // [Home, Titre sondage] → [Home, Page archive, Titre sondage]
        if ( count( $items ) >= 2 ) {
            array_splice( $items, count( $items ) - 1, 0, [ $archive_item ] );
        } else {
            $items[] = $archive_item;
        }

        return $items;
    }
}
