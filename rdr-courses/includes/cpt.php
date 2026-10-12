<?php
/**
 * CPT : rdrc_course (cours) + rdrc_lesson (leçons/sections)
 * Taxonomie : rdrc_category (catégories de cours)
 */

function rdrc_register_cpt() {

    // ── Cours ──────────────────────────────────────────
    register_post_type( 'rdrc_course', [
        'labels' => [
            'name'               => 'Cours',
            'singular_name'      => 'Cours',
            'add_new'            => 'Ajouter un cours',
            'add_new_item'       => 'Ajouter un cours',
            'edit_item'          => 'Modifier le cours',
            'new_item'           => 'Nouveau cours',
            'view_item'          => 'Voir le cours',
            'search_items'       => 'Rechercher un cours',
            'not_found'          => 'Aucun cours trouvé',
            'all_items'          => 'Tous les cours',
            'menu_name'          => 'Cours RDR-IT',
        ],
        'public'             => true,
        'has_archive'        => true,
        'rewrite'            => [ 'slug' => 'course', 'with_front' => false ],
        'supports'           => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields', 'revisions' ],
        'show_in_rest'       => true,
        'menu_icon'          => 'dashicons-welcome-learn-more',
        'menu_position'      => 20,
        'show_in_nav_menus'  => true,
    ]);

    // ── Leçons ─────────────────────────────────────────
    // Hiérarchique pour pouvoir avoir : Cours > Section > Leçon
    register_post_type( 'rdrc_lesson', [
        'labels' => [
            'name'               => 'Leçons',
            'singular_name'      => 'Leçon',
            'add_new'            => 'Ajouter une leçon',
            'add_new_item'       => 'Ajouter une leçon',
            'edit_item'          => 'Modifier la leçon',
            'new_item'           => 'Nouvelle leçon',
            'view_item'          => 'Voir la leçon',
            'search_items'       => 'Rechercher une leçon',
            'not_found'          => 'Aucune leçon trouvée',
            'all_items'          => 'Toutes les leçons',
            'menu_name'          => 'Leçons',
        ],
        'public'             => true,
        'hierarchical'       => false,
        'has_archive'        => false,
        'rewrite'            => [ 'slug' => 'lesson', 'with_front' => false ],
        'supports'           => [ 'title', 'editor', 'thumbnail', 'custom-fields', 'revisions' ],
        'show_in_rest'       => true,
        'menu_icon'          => 'dashicons-book-alt',
        'menu_position'      => 21,
        'show_in_nav_menus'  => false,
    ]);

    // ── Taxonomie cours ────────────────────────────────
    register_taxonomy( 'rdrc_cat', 'rdrc_course', [
        'labels' => [
            'name'              => 'Catégories de cours',
            'singular_name'     => 'Catégorie de cours',
            'add_new_item'      => 'Ajouter une catégorie',
            'edit_item'         => 'Modifier la catégorie',
            'new_item'          => 'Nouvelle catégorie',
            'all_items'         => 'Toutes les catégories',
        ],
        'hierarchical'      => true,
        'show_in_rest'      => true,
        'rewrite'           => [ 'slug' => 'cours-cat' ],
        'show_admin_column' => true,
    ]);

    // ── Taxonomie difficulté ───────────────────────────
    register_taxonomy( 'rdrc_level', 'rdrc_course', [
        'labels' => [
            'name'          => 'Niveaux',
            'singular_name' => 'Niveau',
            'all_items'     => 'Tous les niveaux',
            'add_new_item'  => 'Ajouter un niveau',
        ],
        'hierarchical'      => false,
        'show_in_rest'      => true,
        'rewrite'           => [ 'slug' => 'cours-niveau' ],
        'show_admin_column' => true,
    ]);
}
add_action( 'init', 'rdrc_register_cpt' );

/* ── Admin : colonnes et filtres pour les leçons ─────────── */

// Ajouter colonne "Cours" dans la liste des leçons
add_filter( 'manage_rdrc_lesson_posts_columns', function( $cols ) {
    $new = [];
    foreach ($cols as $k => $v) {
        $new[$k] = $v;
        if ($k === 'title') {
            $new['rdrc_parent_course'] = 'Cours';
        }
    }
    return $new;
});

add_action( 'manage_rdrc_lesson_posts_custom_column', function( $col, $post_id ) {
    if ( $col !== 'rdrc_parent_course' ) return;
    $course_id = get_post_meta($post_id, '_rdrc_parent_course', true);
    if ( $course_id ) {
        $course = get_post($course_id);
        if ($course) {
            echo '<a href="' . get_edit_post_link($course_id) . '">' . esc_html($course->post_title) . '</a>';
        }
    } else {
        echo '<span style="color:#999">—</span>';
    }
}, 10, 2);

// Dropdown filtre par cours dans la liste admin des leçons
add_action( 'restrict_manage_posts', function( $post_type ) {
    if ( $post_type !== 'rdrc_lesson' ) return;
    $courses = get_posts(['post_type'=>'rdrc_course','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC','post_status'=>'any']);
    if ( empty($courses) ) return;
    $selected = isset($_GET['rdrc_filter_course']) ? intval($_GET['rdrc_filter_course']) : 0;
    echo '<select name="rdrc_filter_course" id="rdrc_filter_course">';
    echo '<option value="">— Tous les cours —</option>';
    foreach ($courses as $c) {
        echo '<option value="' . $c->ID . '"' . selected($selected, $c->ID, false) . '>' . esc_html($c->post_title) . '</option>';
    }
    echo '</select>';
});

// Appliquer le filtre à la requête
add_filter( 'parse_query', function( $query ) {
    if ( ! is_admin() || ! $query->is_main_query() ) return;
    if ( $query->get('post_type') !== 'rdrc_lesson' ) return;
    if ( ! empty($_GET['rdrc_filter_course']) ) {
        $query->set('meta_query', [[
            'key'   => '_rdrc_parent_course',
            'value' => intval($_GET['rdrc_filter_course']),
        ]]);
    }
});

// Rendre la colonne "Cours" triable
add_filter( 'manage_edit-rdrc_lesson_sortable_columns', function( $cols ) {
    $cols['rdrc_parent_course'] = 'rdrc_parent_course';
    return $cols;
});
