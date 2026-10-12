<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPS_Taxonomy {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register' ] );
    }

    public static function register() {
        $labels = [
            'name'              => __( 'Catégories de sondages', 'wp-sondage' ),
            'singular_name'     => __( 'Catégorie', 'wp-sondage' ),
            'search_items'      => __( 'Rechercher', 'wp-sondage' ),
            'all_items'         => __( 'Toutes les catégories', 'wp-sondage' ),
            'parent_item'       => __( 'Catégorie parente', 'wp-sondage' ),
            'parent_item_colon' => __( 'Catégorie parente :', 'wp-sondage' ),
            'edit_item'         => __( 'Modifier', 'wp-sondage' ),
            'update_item'       => __( 'Mettre à jour', 'wp-sondage' ),
            'add_new_item'      => __( 'Nouvelle catégorie', 'wp-sondage' ),
            'new_item_name'     => __( 'Nom', 'wp-sondage' ),
            'menu_name'         => __( 'Catégories', 'wp-sondage' ),
        ];

        register_taxonomy( 'wps_category', 'wps_poll', [
            'labels'            => $labels,
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_admin_column' => true,
            'rewrite'           => [ 'slug' => 'survey-cat' ],
            'show_in_rest'      => false,
        ] );
    }
}
