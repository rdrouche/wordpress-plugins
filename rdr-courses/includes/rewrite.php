<?php
/**
 * Rewrite — URLs + injection front via hooks GP natifs
 */

/* ── URLs /course/slug-cours/slug-lecon/ ─────────────────── */
add_filter( 'post_type_link', function( $post_link, $post ) {
    if ( $post->post_type !== 'rdrc_lesson' ) return $post_link;
    $course_id = get_post_meta( $post->ID, '_rdrc_parent_course', true );
    if ( ! $course_id ) return $post_link;
    $course = get_post( $course_id );
    if ( ! $course ) return $post_link;
    return home_url( '/course/' . $course->post_name . '/' . $post->post_name . '/' );
}, 10, 2 );

add_action( 'init', function() {
    add_rewrite_rule(
        '^course/([^/]+)/([^/]+)/?$',
        'index.php?rdrc_course_slug=$matches[1]&rdrc_lesson_slug=$matches[2]',
        'top'
    );
} );

add_filter( 'query_vars', function( $vars ) {
    $vars[] = 'rdrc_course_slug';
    $vars[] = 'rdrc_lesson_slug';
    return $vars;
} );

add_action( 'parse_request', function( $wp ) {
    // On ne traite que les URL capturees par notre regle /course/x/y/
    if ( ! isset( $wp->query_vars['rdrc_lesson_slug'] ) ) return;

    $course_slug = isset( $wp->query_vars['rdrc_course_slug'] )
        ? sanitize_title( $wp->query_vars['rdrc_course_slug'] ) : '';
    $lesson_slug = sanitize_title( $wp->query_vars['rdrc_lesson_slug'] );

    // Toujours nettoyer nos query vars internes : sinon WP, ne sachant pas
    // les resoudre, retombe sur l'archive/blog global au lieu d'un vrai 404.
    unset( $wp->query_vars['rdrc_course_slug'], $wp->query_vars['rdrc_lesson_slug'] );

    // 1) Le cours parent doit exister (tous statuts consultables)
    $course = null;
    if ( $course_slug ) {
        $courses = get_posts( [
            'name'             => $course_slug,
            'post_type'        => 'rdrc_course',
            'post_status'      => [ 'publish', 'private', 'draft', 'pending', 'future' ],
            'posts_per_page'   => 1,
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ] );
        if ( ! empty( $courses ) ) $course = $courses[0];
    }

    if ( ! $course ) {
        // Cours inexistant -> vrai 404 (pas d'archive globale)
        rdrc_force_404( $wp );
        return;
    }

    // 2) La lecon doit exister
    $lessons = get_posts( [
        'name'             => $lesson_slug,
        'post_type'        => 'rdrc_lesson',
        'post_status'      => [ 'publish', 'private', 'draft', 'pending', 'future' ],
        'posts_per_page'   => 1,
        'no_found_rows'    => true,
        'suppress_filters' => false,
    ] );
    $lesson = ! empty( $lessons ) ? $lessons[0] : null;

    if ( ! $lesson ) {
        // Lecon inexistante -> vrai 404
        rdrc_force_404( $wp );
        return;
    }

    // 3) Coherence : la lecon appartient-elle bien a ce cours ?
    $parent = (int) get_post_meta( $lesson->ID, '_rdrc_parent_course', true );
    if ( $parent && $parent !== (int) $course->ID ) {
        // La lecon existe mais pas dans ce cours -> 404 (URL incoherente)
        rdrc_force_404( $wp );
        return;
    }

    // OK : on route vers la lecon
    $wp->query_vars['post_type']   = 'rdrc_lesson';
    $wp->query_vars['name']        = $lesson_slug;
    $wp->query_vars['post_status'] = [ 'publish', 'private' ];
} );

/**
 * Force un vrai 404 propre depuis parse_request.
 * On vide les query vars pour empecher WP de retomber sur le blog/l'archive,
 * puis on laisse WP::handle_404() faire son travail via un flag differe.
 */
function rdrc_force_404( $wp ) {
    // Vider toute resolution possible
    $wp->query_vars = [ 'error' => '404' ];

    add_action( 'wp', function() {
        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        nocache_headers();
    }, 1 );
}

/* ══════════════════════════════════════════════════════════
   BREADCRUMB — filtre Rank Math
   On reconstruit le fil UNIQUEMENT si RM a déjà ses items (tableau non vide).
   Structure RM : chaque item = [ 'text' => '...', 'url' => '...' ]
   ══════════════════════════════════════════════════════════ */
add_filter( 'rank_math/frontend/breadcrumb/items', function( $items ) {
    // RM attend des tableaux indexés : [ ['Texte', 'url'], ['Texte2', ''] ]
    // $items peut être vide si RM n'a pas encore traité la page — on sort sans toucher
    if ( empty( $items ) || ! is_array( $items ) ) return $items;

    // Récupérer le premier item (Home) en toute sécurité
    $home = array_values( $items )[0];
    // S'assurer qu'il est bien indexé (certaines versions RM utilisent des clés string)
    if ( ! isset( $home[0] ) ) return $items;

    if ( is_singular( 'rdrc_lesson' ) ) {
        $lesson_id = get_the_ID();
        $course_id = get_post_meta( $lesson_id, '_rdrc_parent_course', true );
        if ( ! $course_id ) return $items;
        $course = get_post( $course_id );
        if ( ! $course ) return $items;
        $section = rdrc_get_lesson_section( $lesson_id, $course_id );

        // Format RM : [ [texte, url], [texte, url], ... ]
        $new   = [ $home ];
        $new[] = [ rdrc__('label_courses'), (string) get_post_type_archive_link( 'rdrc_course' ) ];
        $new[] = [ $course->post_title, get_permalink( $course_id ) ];
        if ( $section ) $new[] = [ $section, '' ];
        $new[] = [ get_the_title( $lesson_id ), '' ];
        return $new;
    }

    if ( is_singular( 'rdrc_course' ) ) {
        $new   = [ $home ];
        $new[] = [ rdrc__('label_courses'), (string) get_post_type_archive_link( 'rdrc_course' ) ];
        $new[] = [ get_the_title(), '' ];
        return $new;
    }

    return $items;
}, 20 );

/* ══════════════════════════════════════════════════════════
   BADGES + BOUTON — generate_after_entry_header
   ══════════════════════════════════════════════════════════ */

/* ── Leçon ─────────────────────────────────────────────── */
add_action( 'generate_after_entry_header', function() {
    if ( ! is_singular( 'rdrc_lesson' ) ) return;

    $lid         = get_the_ID();
    $course_id   = get_post_meta( $lid, '_rdrc_parent_course', true );
    $lesson_type = get_post_meta( $lid, '_rdrc_lesson_type', true ) ?: 'article';
    $duration    = get_post_meta( $lid, '_rdrc_lesson_duration', true );
    $is_free     = get_post_meta( $lid, '_rdrc_lesson_free', true );
    $course      = $course_id ? get_post( $course_id ) : null;
    $type_map    = [ 'article' => '📄', 'video' => '🎥', 'tp' => '🔧', 'quiz' => '❓' ];
    $type_lbl    = [ 'article' => rdrc__('badge_article'), 'video' => rdrc__('badge_video'), 'tp' => rdrc__('badge_tp'), 'quiz' => rdrc__('badge_quiz') ];
    $badge_clr   = $course_id ? ( get_post_meta( $course_id, '_rdrc_badge_color', true ) ?: '#2ea8ff' ) : '#2ea8ff';

    echo '<div class="rdrc-lesson-header-bar">';

    // Pill cours
    if ( $course ) {
        echo '<a href="' . esc_url( get_permalink( $course_id ) ) . '" class="rdrc-course-pill">';
        echo '<span class="rdrc-course-pill-icon">' . esc_html( get_post_meta( $course_id, '_rdrc_icon', true ) ?: '📚' ) . '</span>';
        echo '<span>' . esc_html( $course->post_title ) . '</span>';
        echo '</a>';
    }

    // Badges inline propres
    echo '<div class="rdrc-badges-row">';
    $icon = $type_map[ $lesson_type ] ?? '📄';
    $lbl  = $type_lbl[ $lesson_type ] ?? 'Article';
    echo '<span class="rdrc-chip rdrc-chip-type">' . $icon . ' ' . $lbl . '</span>';
    if ( $duration ) {
        echo '<span class="rdrc-chip rdrc-chip-dur">⏱ ' . esc_html( $duration ) . ' min</span>';
    }
    if ( $is_free === '1' ) {
        echo '<span class="rdrc-chip rdrc-chip-free">✅ ' . esc_html(rdrc__('badge_free')) . '</span>';
    }
    echo '</div>';

    echo '</div>'; // .rdrc-lesson-header-bar
} );

/* ── Cours ──────────────────────────────────────────────── */
add_action( 'generate_after_entry_header', function() {
    if ( ! is_singular( 'rdrc_course' ) ) return;

    $cid       = get_the_ID();
    $duration  = get_post_meta( $cid, '_rdrc_duration', true );
    $level     = get_post_meta( $cid, '_rdrc_level', true );
    $free      = get_post_meta( $cid, '_rdrc_free', true );
    $structure = get_post_meta( $cid, '_rdrc_structure', true ) ?: [];
    $nb        = count( array_filter( $structure, fn( $i ) => ( $i['type'] ?? '' ) === 'lesson' ) );
    $lvl_map   = [ 'debutant' => '🟢 ' . rdrc__('level_debutant'), 'intermediaire' => '🟡 ' . rdrc__('level_intermediaire'), 'avance' => '🔴 ' . rdrc__('level_avance') ];

    // Première leçon publiée
    $first_url = '';
    foreach ( $structure as $item ) {
        if ( ( $item['type'] ?? '' ) === 'lesson' && ! empty( $item['lesson_id'] ) ) {
            $l = get_post( $item['lesson_id'] );
            if ( $l && rdrc_lesson_is_viewable( $l ) ) { $first_url = get_permalink( $l->ID ); break; }
        }
    }

    echo '<div class="rdrc-course-header-bar">';

    // Badges sur une ligne
    echo '<div class="rdrc-badges-row">';
    if ( $nb )       echo '<span class="rdrc-chip rdrc-chip-neutral">📖 ' . $nb . ' leçon' . ( $nb > 1 ? 's' : '' ) . '</span>';
    if ( $duration ) echo '<span class="rdrc-chip rdrc-chip-neutral">⏱ ' . esc_html( $duration ) . '</span>';
    if ( $level && isset( $lvl_map[ $level ] ) ) echo '<span class="rdrc-chip rdrc-chip-level rdrc-level-' . esc_attr( $level ) . '">' . $lvl_map[ $level ] . '</span>';
    if ( $free === '1' ) echo '<span class="rdrc-chip rdrc-chip-free">✅ ' . esc_html(rdrc__('badge_free')) . '</span>';
    echo '</div>';

    // Bouton démarrer
    if ( $first_url ) {
        echo '<a href="' . esc_url( $first_url ) . '" class="rdrc-btn-start">' . esc_html(rdrc__('syllabus_start')) . '</a>';
    }

    echo '</div>'; // .rdrc-course-header-bar
} );

/* ══════════════════════════════════════════════════════════
   PROGRESSION — generate_before_entry_content
   Ancre PHP → JS la remplace avec les vraies données
   ══════════════════════════════════════════════════════════ */
add_action( 'generate_before_entry_content', function() {
    if ( ! is_singular( [ 'rdrc_lesson', 'rdrc_course' ] ) ) return;

    // Page de presentation d'un cours : vue parcours complete
    // (jauge globale + detail par module + bouton Reprendre).
    if ( is_singular( 'rdrc_course' ) ) {
        if ( ! apply_filters( 'rdrc_auto_course_progress', true ) ) return;
        echo rdrc_render_course_overview( get_the_ID() );
        return;
    }

    // Leçon : ancre minimaliste — le JS la remplace ou la cache
    echo '<div id="rdrc-progress-inline" class="rdrc-progress-placeholder" aria-hidden="true"></div>';
} );

/* ══════════════════════════════════════════════════════════
   CONTENU — the_content filter
   ══════════════════════════════════════════════════════════ */

/* ── Cours : syllabus après contenu ────────────────────── */
add_filter( 'the_content', function( $content ) {
    if ( ! is_singular( 'rdrc_course' ) || ! in_the_loop() || ! is_main_query() ) return $content;
    return $content . do_shortcode( '[rdrc_course_content id="' . get_the_ID() . '" show_meta="0"]' );
}, 20 );

/* ── Leçon : vidéo + wrapper + ressources ───────────────── */
add_filter( 'the_content', function( $content ) {
    if ( ! is_singular( 'rdrc_lesson' ) || ! in_the_loop() || ! is_main_query() ) return $content;

    $lid   = get_the_ID();
    $type  = get_post_meta( $lid, '_rdrc_lesson_type', true );
    $vurl  = get_post_meta( $lid, '_rdrc_video_url', true );

    $prefix = '';
    if ( $vurl && $type === 'video' ) {
        $embed = wp_oembed_get( $vurl, [ 'width' => 860 ] );
        if ( $embed ) $prefix = '<div class="rdrc-video-embed">' . $embed . '</div>';
    }

    return $prefix
        . '<div class="rdrc-lesson-content" data-lesson-id="' . $lid . '">' . $content . '</div>'
        . rdrc_render_resources( $lid );
}, 20 );

/* ══════════════════════════════════════════════════════════
   NAV + TOC — generate_after_entry_content (leçon seulement)
   ══════════════════════════════════════════════════════════ */
add_action( 'generate_after_entry_content', function() {
    if ( ! is_singular( 'rdrc_lesson' ) ) return;

    $lid       = get_the_ID();
    $course_id = get_post_meta( $lid, '_rdrc_parent_course', true );
    if ( ! $course_id ) return;

    $course   = get_post( $course_id );
    $adjacent = rdrc_get_adjacent_lessons( $lid, $course_id );
    ?>
    <nav class="rdrc-lesson-nav">
        <div class="rdrc-nav-prev">
            <?php if ( $adjacent['prev'] ): ?>
            <a href="<?php echo get_permalink( $adjacent['prev']['lesson_id'] ); ?>" class="rdrc-nav-btn">
                <span class="rdrc-nav-lbl">⬅ <?php rdrc_e('btn_prev_lesson'); ?></span>
                <span class="rdrc-nav-title"><?php echo esc_html( $adjacent['prev']['post']->post_title ); ?></span>
            </a>
            <?php else: ?>
            <a href="<?php echo get_permalink( $course_id ); ?>" class="rdrc-nav-btn">
                <span class="rdrc-nav-lbl">⬅ <?php rdrc_e('btn_back_to_course'); ?></span>
                <span class="rdrc-nav-title"><?php echo esc_html( $course->post_title ); ?></span>
            </a>
            <?php endif; ?>
        </div>
        <div class="rdrc-nav-center">
            <a href="<?php echo get_permalink( $course_id ); ?>" class="rdrc-back-course-link">📋 <?php rdrc_e('btn_summary'); ?></a>
        </div>
        <div class="rdrc-nav-next">
            <?php if ( $adjacent['next'] ): ?>
            <a href="<?php echo get_permalink( $adjacent['next']['lesson_id'] ); ?>" class="rdrc-nav-btn rdrc-nav-next-btn">
                <span class="rdrc-nav-lbl"><?php rdrc_e('btn_next_lesson'); ?> ➡</span>
                <span class="rdrc-nav-title"><?php echo esc_html( $adjacent['next']['post']->post_title ); ?></span>
            </a>
            <?php else: ?>
            <a href="<?php echo get_permalink( $course_id ); ?>" class="rdrc-nav-btn">
                <span class="rdrc-nav-lbl">🎉 <?php rdrc_e('btn_end_course'); ?></span>
                <span class="rdrc-nav-title">Retour au sommaire</span>
            </a>
            <?php endif; ?>
        </div>
    </nav>
    <?php
    echo '<div class="rdrc-toc-widget">';
    echo '<button class="rdrc-toc-toggle" aria-expanded="false">';
    echo '📋 ' . esc_html(rdrc__('toc_title_prefix')) . ' ' . esc_html( $course->post_title ) . ' <span class="rdrc-toc-arrow">▼</span>';
    echo '</button>';
    echo '<div class="rdrc-toc-body" hidden>' . rdrc_render_toc( $course_id, $lid ) . '</div>';
    echo '</div>';
} );

/* ── Sidebar — remplacer la sidebar GP native par la nôtre ── */
// generate_sidebar = hook GP qui appelle dynamic_sidebar($id)
// On filtre l'ID pour substituer notre zone à la zone native
add_filter( 'generate_sidebar_id', function( $id ) {
    if ( is_singular( [ 'rdrc_lesson', 'rdrc_course' ] )
         || is_post_type_archive( 'rdrc_course' )
         || is_tax( [ 'rdrc_cat', 'rdrc_level' ] ) ) {
        if ( is_active_sidebar( 'rdrc-sidebar-course' ) ) {
            return 'rdrc-sidebar-course'; // remplace sidebar-1 (ou autre)
        }
    }
    return $id;
} );

/* ── Archive : filtres catégories ───────────────────────── */
add_action( 'generate_before_content', function() {
    if ( ! is_post_type_archive( 'rdrc_course' ) && ! is_tax( [ 'rdrc_cat', 'rdrc_level' ] ) ) return;
    $cats = get_terms( [ 'taxonomy' => 'rdrc_cat', 'hide_empty' => true ] );
    echo '<div class="rdrc-archive-intro"><div class="rdrc-filter-bar">';
    echo '<a href="' . get_post_type_archive_link( 'rdrc_course' ) . '" class="rdrc-filter-btn' . ( ! is_tax() ? ' rdrc-filter-active' : '' ) . '">Tous</a>';
    if ( $cats && ! is_wp_error( $cats ) ) {
        foreach ( $cats as $cat ) {
            $active = is_tax( 'rdrc_cat', $cat->term_id ) ? ' rdrc-filter-active' : '';
            echo '<a href="' . esc_url( get_term_link( $cat ) ) . '" class="rdrc-filter-btn' . $active . '">';
            echo esc_html( $cat->name ) . '<span class="rdrc-filter-count">' . $cat->count . '</span></a>';
        }
    }
    echo '</div></div>';
} );

/* ── Flush ───────────────────────────────────────────────── */
add_action( 'admin_init', function() {
    if ( get_option( 'rdrc_flush_needed' ) ) { flush_rewrite_rules(); delete_option( 'rdrc_flush_needed' ); }
} );
