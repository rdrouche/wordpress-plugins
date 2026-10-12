<?php
/**
 * Zones widgets cours/leçons
 * Compatible GeneratePress — inject via hooks GP natifs
 */

/* ── Enregistrement des sidebars ─────────────────────────── */
add_action( 'widgets_init', function() {
    $def = [
        'before_widget' => '<aside id="%1$s" class="widget rdrc-widget %2$s">',
        'after_widget'  => '</aside>',
        'before_title'  => '<h3 class="rdrc-widget-title">',
        'after_title'   => '</h3>',
    ];
    register_sidebar( $def + [
        'name'        => 'RDR-IT — Sidebar Cours & Leçons',
        'id'          => 'rdrc-sidebar-course',
        'description' => 'Sidebar affichée sur les pages cours et leçons.',
    ]);
    register_sidebar( $def + [
        'name'        => 'RDR-IT — Sous le contenu leçon',
        'id'          => 'rdrc-below-lesson',
        'description' => 'Injectée automatiquement sous chaque leçon.',
    ]);
    register_sidebar( $def + [
        'name'        => 'RDR-IT — Sous le contenu cours',
        'id'          => 'rdrc-below-course',
        'description' => 'Injectée automatiquement sous chaque cours.',
    ]);
});

/* Templates gèrent la sidebar et les zones directement */


/* ── Classe body pour le layout GP ──────────────────────── */
// GP utilise .right-sidebar sur le body pour activer son CSS de grille
// Sans cette classe, .inside-site-content n'est pas en grid et la sidebar passe en dessous
add_filter( 'body_class', function( $classes ) {
    if ( is_singular(['rdrc_lesson','rdrc_course']) || is_post_type_archive('rdrc_course') || is_tax(['rdrc_cat','rdrc_level']) ) {
        if ( is_active_sidebar('rdrc-sidebar-course') ) {
            // Retirer no-sidebar si présent, ajouter right-sidebar
            $classes = array_diff($classes, ['no-sidebar','left-sidebar']);
            $classes[] = 'right-sidebar';
        } else {
            $classes = array_diff($classes, ['right-sidebar','left-sidebar']);
            $classes[] = 'no-sidebar';
        }
    }
    return $classes;
});

/* ── Widget : Leçons de la section ───────────────────────── */
class RDRC_Section_Lessons_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct('rdrc_section_lessons', 'RDR-IT : Leçons de la section',
            ['description' => 'Leçons de la section courante (ou tout le cours).']);
    }
    public function widget( $args, $instance ) {
        if ( ! is_singular('rdrc_lesson') ) return;
        $course_id = get_post_meta(get_the_ID(), '_rdrc_parent_course', true);
        if ( ! $course_id ) return;
        $title = apply_filters('widget_title', $instance['title'] ?? '');
        echo $args['before_widget'];
        if ($title) echo $args['before_title'] . $title . $args['after_title'];
        echo do_shortcode('[rdrc_section_toc course_id="' . $course_id . '" show_title="1"]');
        echo $args['after_widget'];
    }
    public function form( $instance ) {
        $t = esc_attr($instance['title'] ?? 'Dans cette section');
        echo '<p><label>Titre<br><input class="widefat" name="' . $this->get_field_name('title') . '" type="text" value="' . $t . '"></label></p>';
    }
    public function update( $new, $old ) {
        return ['title' => sanitize_text_field($new['title'] ?? '')];
    }
}

/* ── Widget : Progression ────────────────────────────────── */
class RDRC_Progress_Widget extends WP_Widget {
    public function __construct() {
        parent::__construct('rdrc_progress_widget', 'RDR-IT : Progression du cours',
            ['description' => 'Barre de progression anonyme.']);
    }
    public function widget( $args, $instance ) {
        if ( ! is_singular(['rdrc_lesson','rdrc_course']) ) return;
        $title = apply_filters('widget_title', $instance['title'] ?? '');
        echo $args['before_widget'];
        if ($title) echo $args['before_title'] . $title . $args['after_title'];
        echo '<div id="rdrc-progress-widget-anchor" class="rdrc-progress-placeholder">';
        echo '<p class="rdrc-progress-loading">Chargement…</p>';
        echo '</div>';
        echo $args['after_widget'];
    }
    public function form( $instance ) {
        $t = esc_attr($instance['title'] ?? 'Ma progression');
        echo '<p><label>Titre<br><input class="widefat" name="' . $this->get_field_name('title') . '" type="text" value="' . $t . '"></label></p>';
    }
    public function update( $new, $old ) {
        return ['title' => sanitize_text_field($new['title'] ?? '')];
    }
}

add_action('widgets_init', function() {
    register_widget('RDRC_Section_Lessons_Widget');
    register_widget('RDRC_Progress_Widget');
});

/* ── Shortcodes ──────────────────────────────────────────── */
add_shortcode('rdrc_progress', function() {
    if ( ! is_singular(['rdrc_lesson','rdrc_course']) ) return '';
    return '<div id="rdrc-progress-widget-anchor" class="rdrc-progress-placeholder"></div>';
});


/* ── Layout GP natif — sidebar droite sur nos CPT ────────── */
// Sans template custom, GP décide du layout via son Customizer.
// Ce filtre force la sidebar droite si rdrc-sidebar-course est peuplée.
add_filter( 'generate_sidebar_layout', function( $layout ) {
    if ( is_singular(['rdrc_lesson','rdrc_course'])
         || is_post_type_archive('rdrc_course')
         || is_tax(['rdrc_cat','rdrc_level']) ) {
        return is_active_sidebar('rdrc-sidebar-course') ? 'right-sidebar' : 'no-sidebar';
    }
    return $layout;
} );