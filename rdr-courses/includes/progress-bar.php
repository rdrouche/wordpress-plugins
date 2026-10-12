<?php
/**
 * Barre de suivi de progression — shortcode [rdrc_progress_bar]
 *
 * STOCKAGE : localStorage uniquement, aucun cookie, aucun appel serveur.
 * Cle : rdrc_progress_<courseId>  ->  { "<lessonId>": <timestamp>, ... }
 * (meme cle que assets/js/courses.js : les deux restent synchronises)
 *
 * PLACEMENT : page (pleine largeur) ou widget (colonne etroite).
 * La mise en page s'adapte via container queries sur la largeur reelle
 * du conteneur, pas sur celle de l'ecran.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ══════════════════════════════════════════════════════════
   Contexte d'une lecon : module (section) + parcours (cours)
   ══════════════════════════════════════════════════════════ */
function rdrc_get_lesson_context( $lesson_id, $course_id ) {
    $structure = rdrc_get_course_structure( $course_id );

    $ctx = [
        'section_title'   => '',
        'section_lessons' => [],  // ids de la section courante
        'section_index'   => 0,   // position 1-based dans la section
        'course_lessons'  => [],  // ids de tout le parcours
        'course_index'    => 0,   // position 1-based dans le parcours
        'prev'            => null,
        'next'            => null,
    ];

    $current_section = '';
    $buckets         = [];   // titre section => [ids]
    $found_section   = null;
    $flat            = [];   // toutes les lecons visibles, dans l'ordre

    foreach ( $structure as $item ) {
        $type = $item['type'] ?? '';

        if ( $type === 'section' ) {
            $current_section = $item['title'] ?? '';
            if ( ! isset( $buckets[ $current_section ] ) ) $buckets[ $current_section ] = [];
            continue;
        }

        if ( $type !== 'lesson' || ! isset( $item['post'] ) ) continue;
        if ( ! rdrc_lesson_is_viewable( $item['post'] ) )     continue;

        $lid    = (int) $item['lesson_id'];
        $flat[] = [ 'id' => $lid, 'post' => $item['post'], 'section' => $current_section ];

        if ( ! isset( $buckets[ $current_section ] ) ) $buckets[ $current_section ] = [];
        $buckets[ $current_section ][] = $lid;

        if ( $lid === (int) $lesson_id ) $found_section = $current_section;
    }

    $ctx['course_lessons'] = array_column( $flat, 'id' );

    // Position dans le parcours + voisins
    foreach ( $flat as $i => $row ) {
        if ( $row['id'] !== (int) $lesson_id ) continue;

        $ctx['course_index'] = $i + 1;
        if ( $i > 0 ) {
            $ctx['prev'] = [
                'url'   => get_permalink( $flat[ $i - 1 ]['id'] ),
                'title' => get_the_title( $flat[ $i - 1 ]['id'] ),
            ];
        }
        if ( $i < count( $flat ) - 1 ) {
            $ctx['next'] = [
                'url'   => get_permalink( $flat[ $i + 1 ]['id'] ),
                'title' => get_the_title( $flat[ $i + 1 ]['id'] ),
            ];
        }
        break;
    }

    // Module courant
    if ( $found_section !== null && isset( $buckets[ $found_section ] ) ) {
        $ctx['section_title']   = $found_section;
        $ctx['section_lessons'] = $buckets[ $found_section ];
        $pos = array_search( (int) $lesson_id, $ctx['section_lessons'], true );
        $ctx['section_index']   = $pos === false ? 0 : $pos + 1;
    }

    return $ctx;
}

/* ══════════════════════════════════════════════════════════
   Shortcode [rdrc_progress_bar]
   ══════════════════════════════════════════════════════════ */
add_shortcode( 'rdrc_progress_bar', function( $atts ) {

    $atts = shortcode_atts( [
        'lesson_id'   => 0,
        'course_id'   => 0,
        'show_module' => 1,   // barre du module (section)
        'show_course' => 1,   // barre du parcours complet
        'show_nav'    => 1,   // liens precedent / suivant
        'show_mark'   => 1,   // bouton "Marquer comme lu"
        'layout'      => 'auto', // auto | compact | full
    ], $atts, 'rdrc_progress_bar' );

    // ── Resolution lecon / cours ──────────────────────────
    $lesson_id = (int) $atts['lesson_id'];
    if ( ! $lesson_id && is_singular( 'rdrc_lesson' ) ) $lesson_id = get_the_ID();

    $course_id = (int) $atts['course_id'];
    if ( ! $course_id && $lesson_id ) {
        $course_id = (int) get_post_meta( $lesson_id, '_rdrc_parent_course', true );
    }
    if ( ! $course_id && is_singular( 'rdrc_course' ) ) $course_id = get_the_ID();
    if ( ! $course_id ) return '';

    // Pas de lecon en contexte (page de presentation du cours) :
    // on bascule sur la vue "parcours" avec detail par module.
    if ( ! $lesson_id ) {
        return rdrc_render_course_overview( $course_id, $atts );
    }

    $course = get_post( $course_id );
    if ( ! $course ) return '';

    $ctx = rdrc_get_lesson_context( $lesson_id, $course_id );

    $nb_module = count( $ctx['section_lessons'] );
    $nb_course = count( $ctx['course_lessons'] );
    if ( ! $nb_course ) return '';

    // Assets a la demande (le shortcode peut vivre hors page cours)
    wp_enqueue_style( 'rdr-courses' );
    wp_enqueue_script( 'rdr-courses' );

    $show_module = $atts['show_module'] && $nb_module > 0 && $ctx['section_title'] !== '';
    $show_course = (bool) $atts['show_course'];
    $show_nav    = (bool) $atts['show_nav'];
    $show_mark   = (bool) $atts['show_mark'];

    $layout = in_array( $atts['layout'], [ 'auto', 'compact', 'full' ], true )
        ? $atts['layout'] : 'auto';

    $badge_clr = get_post_meta( $course_id, '_rdrc_badge_color', true ) ?: '';

    ob_start();
    ?>
    <div class="rdrc-pbar rdrc-pbar--<?php echo esc_attr( $layout ); ?>"
         data-rdrc-pbar
         data-course-id="<?php echo esc_attr( $course_id ); ?>"
         data-lesson-id="<?php echo esc_attr( $lesson_id ); ?>"
         data-module-ids="<?php echo esc_attr( implode( ',', $ctx['section_lessons'] ) ); ?>"
         data-course-ids="<?php echo esc_attr( implode( ',', $ctx['course_lessons'] ) ); ?>"
         <?php if ( $badge_clr ) : ?>style="--rdrc-pbar-accent:<?php echo esc_attr( $badge_clr ); ?>"<?php endif; ?>>

        <div class="rdrc-pbar__top">
            <div class="rdrc-pbar__crumb">
                <a href="<?php echo esc_url( get_permalink( $course_id ) ); ?>" class="rdrc-pbar__course">
                    <?php echo esc_html( $course->post_title ); ?>
                </a>
                <?php if ( $ctx['section_title'] ) : ?>
                    <span class="rdrc-pbar__sep" aria-hidden="true">›</span>
                    <span class="rdrc-pbar__section"><?php echo esc_html( $ctx['section_title'] ); ?></span>
                <?php endif; ?>
                <?php if ( $ctx['section_index'] && $nb_module ) : ?>
                    <span class="rdrc-pbar__pos"><?php echo (int) $ctx['section_index']; ?>/<?php echo (int) $nb_module; ?></span>
                <?php endif; ?>
            </div>

            <?php if ( $show_mark ) : ?>
            <button type="button" class="rdrc-pbar__mark" data-rdrc-mark aria-pressed="false"
                    data-txt-todo="<?php echo esc_attr( rdrc__( 'pbar_mark' ) ); ?>"
                    data-txt-done="<?php echo esc_attr( rdrc__( 'pbar_marked' ) ); ?>">
                <span class="rdrc-pbar__mark-icon" aria-hidden="true">✓</span>
                <span class="rdrc-pbar__mark-txt"><?php echo esc_html( rdrc__( 'pbar_mark' ) ); ?></span>
            </button>
            <?php endif; ?>
        </div>

        <?php if ( $show_module || $show_course ) : ?>
        <div class="rdrc-pbar__meters">

            <?php if ( $show_module ) : ?>
            <div class="rdrc-pbar__meter" data-rdrc-meter="module">
                <span class="rdrc-pbar__meter-label"><?php echo esc_html( rdrc__( 'pbar_module' ) ); ?></span>
                <span class="rdrc-pbar__track">
                    <span class="rdrc-pbar__fill" data-rdrc-fill style="width:0%"></span>
                </span>
                <span class="rdrc-pbar__meter-val">
                    <span data-rdrc-done>0</span>/<span data-rdrc-total><?php echo (int) $nb_module; ?></span>
                    <span class="rdrc-pbar__pct" data-rdrc-pct>(0%)</span>
                </span>
            </div>
            <?php endif; ?>

            <?php if ( $show_course ) : ?>
            <div class="rdrc-pbar__meter" data-rdrc-meter="course">
                <span class="rdrc-pbar__meter-label"><?php echo esc_html( rdrc__( 'pbar_course' ) ); ?></span>
                <span class="rdrc-pbar__track">
                    <span class="rdrc-pbar__fill" data-rdrc-fill style="width:0%"></span>
                </span>
                <span class="rdrc-pbar__meter-val">
                    <span data-rdrc-done>0</span>/<span data-rdrc-total><?php echo (int) $nb_course; ?></span>
                    <span class="rdrc-pbar__pct" data-rdrc-pct>(0%)</span>
                </span>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

        <?php if ( $show_nav && ( $ctx['prev'] || $ctx['next'] ) ) : ?>
        <div class="rdrc-pbar__nav">
            <?php if ( $ctx['prev'] ) : ?>
                <a href="<?php echo esc_url( $ctx['prev']['url'] ); ?>" class="rdrc-pbar__navlink rdrc-pbar__navlink--prev">
                    <span aria-hidden="true">‹</span>
                    <span class="rdrc-pbar__navtxt"><?php echo esc_html( $ctx['prev']['title'] ); ?></span>
                </a>
            <?php else : ?>
                <span class="rdrc-pbar__navlink is-disabled">
                    <span aria-hidden="true">‹</span>
                    <span class="rdrc-pbar__navtxt"><?php echo esc_html( rdrc__( 'pbar_start' ) ); ?></span>
                </span>
            <?php endif; ?>

            <?php if ( $ctx['next'] ) : ?>
                <a href="<?php echo esc_url( $ctx['next']['url'] ); ?>" class="rdrc-pbar__navlink rdrc-pbar__navlink--next">
                    <span class="rdrc-pbar__navtxt"><?php echo esc_html( $ctx['next']['title'] ); ?></span>
                    <span aria-hidden="true">›</span>
                </a>
            <?php else : ?>
                <span class="rdrc-pbar__navlink is-disabled rdrc-pbar__navlink--next">
                    <span class="rdrc-pbar__navtxt"><?php echo esc_html( rdrc__( 'pbar_end' ) ); ?></span>
                    <span aria-hidden="true">›</span>
                </span>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
    <?php
    return ob_get_clean();
} );



/* ══════════════════════════════════════════════════════════
   Vue "parcours" — utilisee par [rdrc_progress_bar] sur la
   page de presentation d'un cours (aucune lecon en contexte).
   Jauge globale + detail par module + bouton Reprendre.
   ══════════════════════════════════════════════════════════ */
function rdrc_render_course_overview( $course_id, $atts = [] ) {

    $course = get_post( $course_id );
    if ( ! $course ) return '';

    // Regroupement des lecons visibles par module, dans l'ordre
    $modules  = [];   // [ ['title'=>..., 'lessons'=>[ ['id'=>..,'url'=>..], ... ]], ... ]
    $flat     = [];
    $current  = null;

    foreach ( rdrc_get_course_structure( $course_id ) as $item ) {
        $type = $item['type'] ?? '';

        if ( $type === 'section' ) {
            $modules[] = [ 'title' => $item['title'] ?? '', 'lessons' => [] ];
            $current   = count( $modules ) - 1;
            continue;
        }

        if ( $type !== 'lesson' || ! isset( $item['post'] ) ) continue;
        if ( ! rdrc_lesson_is_viewable( $item['post'] ) )     continue;

        $lid = (int) $item['lesson_id'];
        $row = [ 'id' => $lid, 'url' => get_permalink( $lid ) ];

        // Lecons hors section : module implicite sans titre
        if ( $current === null ) {
            $modules[] = [ 'title' => '', 'lessons' => [] ];
            $current   = count( $modules ) - 1;
        }
        $modules[ $current ]['lessons'][] = $row;
        $flat[] = $row;
    }

    if ( empty( $flat ) ) return '';

    $modules = array_values( array_filter( $modules, function ( $m ) {
        return ! empty( $m['lessons'] );
    } ) );

    wp_enqueue_style( 'rdr-courses' );
    wp_enqueue_script( 'rdr-courses' );

    $show_modules = ! isset( $atts['show_module'] ) || $atts['show_module'];
    $badge_clr    = get_post_meta( $course_id, '_rdrc_badge_color', true ) ?: '';
    $nb           = count( $flat );

    ob_start();
    ?>
    <div class="rdrc-pbar rdrc-pbar--course"
         data-rdrc-course-overview
         data-course-id="<?php echo esc_attr( $course_id ); ?>"
         <?php if ( $badge_clr ) : ?>style="--rdrc-pbar-accent:<?php echo esc_attr( $badge_clr ); ?>"<?php endif; ?>>

        <script type="application/json" data-rdrc-co-data><?php
            echo wp_json_encode( [ 'flat' => $flat, 'modules' => $modules ] );
        ?></script>

        <div class="rdrc-pbar__top">
            <div class="rdrc-pbar__crumb">
                <span class="rdrc-pbar__course"><?php echo esc_html( rdrc__( 'pbar_course' ) ); ?></span>
                <span class="rdrc-pbar__sep" aria-hidden="true">›</span>
                <span class="rdrc-pbar__section" data-rdrc-co-state><?php
                    echo esc_html( rdrc__( 'mycourses_cta_start' ) );
                ?></span>
            </div>

            <a href="<?php echo esc_url( $flat[0]['url'] ); ?>"
               class="rdrc-pbar__resume" data-rdrc-co-cta
               data-txt-start="<?php echo esc_attr( rdrc__( 'mycourses_cta_start' ) ); ?>"
               data-txt-resume="<?php echo esc_attr( rdrc__( 'mycourses_cta_resume' ) ); ?>"
               data-txt-review="<?php echo esc_attr( rdrc__( 'mycourses_cta_review' ) ); ?>"
               data-url-first="<?php echo esc_url( $flat[0]['url'] ); ?>">
                <?php echo esc_html( rdrc__( 'mycourses_cta_start' ) ); ?>
            </a>
        </div>

        <div class="rdrc-pbar__meters">
            <div class="rdrc-pbar__meter" data-rdrc-co-total>
                <span class="rdrc-pbar__meter-label"><?php echo esc_html( rdrc__( 'pbar_course' ) ); ?></span>
                <span class="rdrc-pbar__track">
                    <span class="rdrc-pbar__fill" data-rdrc-fill style="width:0%"></span>
                </span>
                <span class="rdrc-pbar__meter-val">
                    <span data-rdrc-done>0</span>/<span data-rdrc-total><?php echo (int) $nb; ?></span>
                    <span class="rdrc-pbar__pct" data-rdrc-pct>(0%)</span>
                </span>
            </div>
        </div>

        <?php if ( $show_modules && count( $modules ) > 1 ) : ?>
        <div class="rdrc-pbar__modules">
            <?php foreach ( $modules as $i => $mod ) : ?>
            <div class="rdrc-pbar__meter rdrc-pbar__meter--mod" data-rdrc-co-mod="<?php echo (int) $i; ?>">
                <span class="rdrc-pbar__meter-label rdrc-pbar__modname"><?php
                    echo esc_html( $mod['title'] ?: sprintf( 'Module %d', $i + 1 ) );
                ?></span>
                <span class="rdrc-pbar__track">
                    <span class="rdrc-pbar__fill" data-rdrc-fill style="width:0%"></span>
                </span>
                <span class="rdrc-pbar__meter-val">
                    <span data-rdrc-done>0</span>/<span data-rdrc-total><?php echo count( $mod['lessons'] ); ?></span>
                    <span class="rdrc-pbar__pct" data-rdrc-pct>(0%)</span>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="rdrc-pbar__nav rdrc-pbar__nav--reset" hidden data-rdrc-co-resetwrap>
            <button type="button" class="rdrc-pbar__reset" data-rdrc-co-reset><?php
                echo esc_html( rdrc__( 'pbar_reset' ) );
            ?></button>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/* ══════════════════════════════════════════════════════════
   Shortcode [rdrc_my_courses]
   Vue d'ensemble de tous les parcours + avancement.
   Le PHP rend la structure, le JS calcule depuis localStorage.
   ══════════════════════════════════════════════════════════ */
add_shortcode( 'rdrc_my_courses', function( $atts ) {

    $atts = shortcode_atts( [
        'count'       => -1,
        'category'    => '',
        'level'       => '',
        'columns'     => 3,
        'orderby'     => 'title',   // title | date | menu_order
        'order'       => 'ASC',
        'show_stats'  => 1,         // bandeau de synthese en haut
        'show_filter' => 1,         // filtres En cours / Termines / Non commences
        'empty_text'  => '',        // message si aucun cours
    ], $atts, 'rdrc_my_courses' );

    $args = [
        'post_type'      => 'rdrc_course',
        'posts_per_page' => (int) $atts['count'],
        'post_status'    => is_user_logged_in() ? [ 'publish', 'private' ] : 'publish',
        'orderby'        => in_array( $atts['orderby'], [ 'title', 'date', 'menu_order' ], true ) ? $atts['orderby'] : 'title',
        'order'          => strtoupper( $atts['order'] ) === 'DESC' ? 'DESC' : 'ASC',
    ];

    $tax_query = [];
    if ( $atts['category'] ) {
        $tax_query[] = [ 'taxonomy' => 'rdrc_cat', 'field' => 'slug', 'terms' => array_map( 'trim', explode( ',', $atts['category'] ) ) ];
    }
    if ( $atts['level'] ) {
        $tax_query[] = [ 'taxonomy' => 'rdrc_level', 'field' => 'slug', 'terms' => array_map( 'trim', explode( ',', $atts['level'] ) ) ];
    }
    if ( $tax_query ) {
        if ( count( $tax_query ) > 1 ) $tax_query['relation'] = 'AND';
        $args['tax_query'] = $tax_query;
    }

    $courses = get_posts( $args );

    if ( empty( $courses ) ) {
        $msg = $atts['empty_text'] ?: rdrc__( 'mycourses_empty' );
        return '<p class="rdrc-mycourses__empty">' . esc_html( $msg ) . '</p>';
    }

    wp_enqueue_style( 'rdr-courses' );
    wp_enqueue_script( 'rdr-courses' );

    $cols = max( 1, min( 4, (int) $atts['columns'] ) );

    ob_start();
    ?>
    <div class="rdrc-mycourses" data-rdrc-mycourses style="--rdrc-mc-cols:<?php echo (int) $cols; ?>">

        <?php if ( $atts['show_stats'] ) : ?>
        <div class="rdrc-mycourses__stats" data-rdrc-mc-stats>
            <div class="rdrc-mc-stat">
                <span class="rdrc-mc-stat__num" data-rdrc-mc-started>0</span>
                <span class="rdrc-mc-stat__lbl"><?php echo esc_html( rdrc__( 'mycourses_started' ) ); ?></span>
            </div>
            <div class="rdrc-mc-stat">
                <span class="rdrc-mc-stat__num" data-rdrc-mc-done>0</span>
                <span class="rdrc-mc-stat__lbl"><?php echo esc_html( rdrc__( 'mycourses_completed' ) ); ?></span>
            </div>
            <div class="rdrc-mc-stat">
                <span class="rdrc-mc-stat__num" data-rdrc-mc-lessons>0</span>
                <span class="rdrc-mc-stat__lbl"><?php echo esc_html( rdrc__( 'mycourses_lessons_done' ) ); ?></span>
            </div>
            <div class="rdrc-mc-stat">
                <span class="rdrc-mc-stat__num" data-rdrc-mc-global>0%</span>
                <span class="rdrc-mc-stat__lbl"><?php echo esc_html( rdrc__( 'mycourses_global' ) ); ?></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if ( $atts['show_filter'] ) : ?>
        <div class="rdrc-mycourses__filters" data-rdrc-mc-filters>
            <button type="button" class="rdrc-mc-filter is-active" data-filter="all"><?php echo esc_html( rdrc__( 'mycourses_f_all' ) ); ?></button>
            <button type="button" class="rdrc-mc-filter" data-filter="progress"><?php echo esc_html( rdrc__( 'mycourses_f_progress' ) ); ?></button>
            <button type="button" class="rdrc-mc-filter" data-filter="done"><?php echo esc_html( rdrc__( 'mycourses_f_done' ) ); ?></button>
            <button type="button" class="rdrc-mc-filter" data-filter="todo"><?php echo esc_html( rdrc__( 'mycourses_f_todo' ) ); ?></button>
        </div>
        <?php endif; ?>

        <div class="rdrc-mycourses__grid">
        <?php foreach ( $courses as $course ) :

            $cid     = $course->ID;
            $lessons = rdrc_get_lessons_list( $cid );   // deja filtre sur la visibilite
            if ( empty( $lessons ) ) continue;

            // Charge utile pour le JS : id + url de chaque lecon, dans l'ordre
            $payload = [];
            foreach ( $lessons as $item ) {
                $payload[] = [
                    'id'  => (int) $item['lesson_id'],
                    'url' => get_permalink( (int) $item['lesson_id'] ),
                ];
            }

            $icon      = get_post_meta( $cid, '_rdrc_icon', true ) ?: '📚';
            $badge_clr = get_post_meta( $cid, '_rdrc_badge_color', true ) ?: '';
            $level     = get_post_meta( $cid, '_rdrc_level', true );
            $duration  = get_post_meta( $cid, '_rdrc_duration', true );
            $lvl_map   = [
                'debutant'      => rdrc__( 'level_debutant' ),
                'intermediaire' => rdrc__( 'level_intermediaire' ),
                'avance'        => rdrc__( 'level_avance' ),
            ];
            $nb = count( $payload );
            ?>
            <article class="rdrc-mc-card"
                     data-rdrc-mc-card
                     data-course-id="<?php echo esc_attr( $cid ); ?>"
                     data-status="todo"
                     <?php if ( $badge_clr ) : ?>style="--rdrc-pbar-accent:<?php echo esc_attr( $badge_clr ); ?>"<?php endif; ?>>

                <script type="application/json" data-rdrc-mc-lessons><?php
                    echo wp_json_encode( $payload );
                ?></script>

                <a href="<?php echo esc_url( get_permalink( $cid ) ); ?>" class="rdrc-mc-card__thumb">
                    <?php if ( has_post_thumbnail( $cid ) ) : ?>
                        <?php echo get_the_post_thumbnail( $cid, 'medium', [ 'loading' => 'lazy', 'alt' => '' ] ); ?>
                    <?php else : ?>
                        <span class="rdrc-mc-card__icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
                    <?php endif; ?>
                    <span class="rdrc-mc-card__ribbon" data-rdrc-mc-ribbon hidden>✓</span>
                </a>

                <div class="rdrc-mc-card__body">
                    <h3 class="rdrc-mc-card__title">
                        <a href="<?php echo esc_url( get_permalink( $cid ) ); ?>"><?php echo esc_html( $course->post_title ); ?></a>
                    </h3>

                    <div class="rdrc-mc-card__meta">
                        <span>📖 <?php echo (int) $nb; ?> <?php
                            echo esc_html( rdrc__( $nb > 1 ? 'syllabus_lessons_pl' : 'syllabus_lessons' ) );
                        ?></span>
                        <?php if ( $duration ) : ?><span>⏱ <?php echo esc_html( $duration ); ?></span><?php endif; ?>
                        <?php if ( $level && isset( $lvl_map[ $level ] ) ) : ?>
                            <span class="rdrc-level-<?php echo esc_attr( $level ); ?>"><?php echo esc_html( $lvl_map[ $level ] ); ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="rdrc-mc-card__progress">
                        <span class="rdrc-pbar__track">
                            <span class="rdrc-pbar__fill" data-rdrc-mc-fill style="width:0%"></span>
                        </span>
                        <span class="rdrc-mc-card__val">
                            <span data-rdrc-mc-done>0</span>/<?php echo (int) $nb; ?>
                            <span class="rdrc-pbar__pct" data-rdrc-mc-pct>(0%)</span>
                        </span>
                    </div>

                    <a href="<?php echo esc_url( get_permalink( $cid ) ); ?>"
                       class="rdrc-mc-card__cta" data-rdrc-mc-cta
                       data-txt-start="<?php echo esc_attr( rdrc__( 'mycourses_cta_start' ) ); ?>"
                       data-txt-resume="<?php echo esc_attr( rdrc__( 'mycourses_cta_resume' ) ); ?>"
                       data-txt-review="<?php echo esc_attr( rdrc__( 'mycourses_cta_review' ) ); ?>"
                       data-url-course="<?php echo esc_url( get_permalink( $cid ) ); ?>">
                        <?php echo esc_html( rdrc__( 'mycourses_cta_start' ) ); ?>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
        </div>

        <p class="rdrc-mycourses__none" data-rdrc-mc-none hidden><?php
            echo esc_html( rdrc__( 'mycourses_none_filter' ) );
        ?></p>
    </div>
    <?php
    return ob_get_clean();
} );

/* Shortcodes actifs dans les widgets classiques (texte / HTML) */
add_filter( 'widget_text', 'do_shortcode', 11 );
