<?php
/**
 * Custom Post Types : rdr_quiz + rdr_question
 * Taxonomies : rdr_quiz_cat (quiz), rdr_question_tag (questions)
 */
defined('ABSPATH') || exit;

class RDR_Quiz_CPT {

    public static function init(): void {
        add_action('init', [self::class, 'register_cpts']);
        add_action('init', [self::class, 'register_taxonomies']);
        // Dropdown de filtre par tag dans la liste admin des questions
        add_action('restrict_manage_posts', [self::class, 'admin_filter_by_tag']);
    }

    public static function register_cpts(): void {

        /* ── QUIZ ── */
        register_post_type('rdr_quiz', [
            'label'              => __('Quiz', 'rdr-quiz'),
            'labels'             => [
                'name'          => __('Quiz',              'rdr-quiz'),
                'singular_name' => __('Quiz',              'rdr-quiz'),
                'add_new'       => __('Ajouter',           'rdr-quiz'),
                'add_new_item'  => __('Nouveau Quiz',      'rdr-quiz'),
                'edit_item'     => __('Modifier le Quiz',  'rdr-quiz'),
                'all_items'     => __('Tous les Quiz',     'rdr-quiz'),
                'search_items'  => __('Chercher un Quiz',  'rdr-quiz'),
                'not_found'     => __('Aucun quiz trouvé', 'rdr-quiz'),
            ],
            'public'             => true,
            'publicly_queryable' => true,
            'show_in_rest'       => true,
            'supports'           => ['title', 'editor', 'thumbnail', 'excerpt', 'custom-fields'],
            'has_archive'        => false,   // Désactivé : utiliser une page WP + [rdr_quiz_list]
            'rewrite'            => ['slug' => 'quiz-it', 'with_front' => false],
            'menu_icon'          => 'dashicons-welcome-learn-more',
            'menu_position'      => 25,
            'show_in_menu'       => true,
        ]);

        /* ── QUESTION ── */
        register_post_type('rdr_question', [
            'label'         => __('Questions', 'rdr-quiz'),
            'labels'        => [
                'name'          => __('Questions',           'rdr-quiz'),
                'singular_name' => __('Question',            'rdr-quiz'),
                'add_new'       => __('Ajouter',             'rdr-quiz'),
                'add_new_item'  => __('Nouvelle Question',   'rdr-quiz'),
                'edit_item'     => __('Modifier la Question','rdr-quiz'),
                'all_items'     => __('Toutes les Questions','rdr-quiz'),
            ],
            'public'        => false,
            'show_ui'       => true,
            'show_in_rest'  => true,
            'supports'      => ['title', 'custom-fields'],
            'menu_icon'     => 'dashicons-editor-help',
            'show_in_menu'  => 'edit.php?post_type=rdr_quiz',
        ]);
    }

    public static function register_taxonomies(): void {

        /* ── Catégories Quiz (hiérarchique, publique) ── */
        register_taxonomy('rdr_quiz_cat', 'rdr_quiz', [
            'label'             => __('Catégories Quiz', 'rdr-quiz'),
            'hierarchical'      => true,
            'public'            => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'quiz-categorie'],
            'show_admin_column' => true,
        ]);

        /* ── Tags Questions (plat, filtrable, non public) ── */
        register_taxonomy('rdr_question_tag', 'rdr_question', [
            'label'  => __('Tags', 'rdr-quiz'),
            'labels' => [
                'name'              => __('Tags',              'rdr-quiz'),
                'singular_name'     => __('Tag',               'rdr-quiz'),
                'add_new_item'      => __('Ajouter un tag',    'rdr-quiz'),
                'new_item_name'     => __('Nouveau tag',       'rdr-quiz'),
                'all_items'         => __('Tous les tags',     'rdr-quiz'),
                'search_items'      => __('Chercher un tag',   'rdr-quiz'),
                'popular_items'     => __('Tags populaires',   'rdr-quiz'),
                'not_found'         => __('Aucun tag trouvé',  'rdr-quiz'),
            ],
            'hierarchical'      => false,
            'public'            => false,   // pas d'archive publique pour les questions
            'show_ui'           => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'meta_box_cb'       => null,    // widget tag standard (cloud + saisie libre)
            'rewrite'           => false,
        ]);
    }

    /**
     * Dropdown filtre par tag dans la liste admin des questions
     */
    public static function admin_filter_by_tag(string $post_type): void {
        if ($post_type !== 'rdr_question') return;

        $selected = isset($_GET['rdr_question_tag']) ? sanitize_text_field($_GET['rdr_question_tag']) : '';
        $tags     = get_terms(['taxonomy' => 'rdr_question_tag', 'hide_empty' => true, 'orderby' => 'name']);
        if (empty($tags) || is_wp_error($tags)) return;

        echo '<select name="rdr_question_tag" style="margin-right:6px">';
        echo '<option value="">' . esc_html__('Tous les tags', 'rdr-quiz') . '</option>';
        foreach ($tags as $tag) {
            printf(
                '<option value="%s"%s>%s (%d)</option>',
                esc_attr($tag->slug),
                selected($selected, $tag->slug, false),
                esc_html($tag->name),
                $tag->count
            );
        }
        echo '</select>';
    }
}
