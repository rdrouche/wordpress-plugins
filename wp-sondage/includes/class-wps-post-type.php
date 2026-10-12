<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPS_Post_Type {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        $labels = [
            'name'               => __( 'Sondages', 'wp-sondage' ),
            'singular_name'      => __( 'Sondage', 'wp-sondage' ),
            'add_new'            => __( 'Nouveau sondage', 'wp-sondage' ),
            'add_new_item'       => __( 'Créer un sondage', 'wp-sondage' ),
            'edit_item'          => __( 'Modifier le sondage', 'wp-sondage' ),
            'view_item'          => __( 'Voir le sondage', 'wp-sondage' ),
            'all_items'          => __( 'Tous les sondages', 'wp-sondage' ),
            'search_items'       => __( 'Rechercher', 'wp-sondage' ),
            'not_found'          => __( 'Aucun sondage trouvé.', 'wp-sondage' ),
            'not_found_in_trash' => __( 'Aucun sondage dans la corbeille.', 'wp-sondage' ),
        ];

        // has_archive = false : on utilise une page WP native comme archive.
        // Slug 'survey' court et propre, sans conflit avec une page "sondages".
        register_post_type( 'wps_poll', [
            'labels'        => $labels,
            'public'        => true,
            'show_in_menu'  => true,
            'menu_icon'     => 'dashicons-chart-bar',
            'menu_position' => 25,
            'supports'      => [ 'title', 'editor', 'thumbnail' ],
            'has_archive'   => false,
            'rewrite'       => [ 'slug' => 'survey' ],
            'show_in_rest'  => false,
        ] );
    }
}
