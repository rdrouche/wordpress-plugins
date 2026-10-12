<?php
defined('ABSPATH') || exit;

/**
 * Custom Post Type : expert_profile
 *
 * Permaliens : /expert/{slug}/ (géré nativement par WP via 'rewrite' dans register_post_type)
 * Archive    : /experts/ (via has_archive)
 *
 * NOTE : on supprime l'add_rewrite_rule() manuel et le filtre post_type_link custom
 * qui étaient redondants avec la config register_post_type et causaient des conflits.
 * WP gère les rewrites nativement depuis que register_post_type est appelé sur 'init'.
 */
class RDR_ED_Post_Type {

    public static function init(): void {
        add_action('init', [self::class, 'register']);
    }

    public static function register(): void {
        register_post_type('expert_profile', [
            'labels' => [
                'name'               => __('Experts IT', 'rdr-expert-directory'),
                'singular_name'      => __('Expert IT', 'rdr-expert-directory'),
                'add_new'            => __('Ajouter un expert', 'rdr-expert-directory'),
                'add_new_item'       => __('Ajouter un expert', 'rdr-expert-directory'),
                'edit_item'          => __('Modifier l\'expert', 'rdr-expert-directory'),
                'view_item'          => __('Voir la fiche', 'rdr-expert-directory'),
                'all_items'          => __('Tous les experts', 'rdr-expert-directory'),
                'search_items'       => __('Rechercher un expert', 'rdr-expert-directory'),
                'not_found'          => __('Aucun expert trouvé.', 'rdr-expert-directory'),
                'not_found_in_trash' => __('Aucun expert dans la corbeille.', 'rdr-expert-directory'),
            ],
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => false,
            'menu_position'       => 25,
            'menu_icon'           => 'dashicons-businessperson',
            'capability_type'     => ['expert_profile', 'expert_profiles'],
            'map_meta_cap'        => true,
            'hierarchical'        => false,
            'rewrite'             => [
                'slug'       => 'expert',
                'with_front' => false,
                'feeds'      => false,
                'pages'      => false,
            ],
            'supports'            => ['title', 'editor', 'thumbnail', 'custom-fields'],
            'has_archive'         => 'experts',
            'query_var'           => true,
        ]);
    }
}
