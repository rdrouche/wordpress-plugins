<?php
defined( 'ABSPATH' ) || exit;

class SCF_Post_Type {

    const POST_TYPE = 'scf_form';

    public static function register(): void {
        register_post_type( self::POST_TYPE, [
            'labels'              => [
                'name'               => __( 'Contact Forms', 'smart-contact-form' ),
                'singular_name'      => __( 'Contact Form', 'smart-contact-form' ),
                'add_new'            => __( 'Add Form', 'smart-contact-form' ),
                'add_new_item'       => __( 'Add New Form', 'smart-contact-form' ),
                'edit_item'          => __( 'Edit Form', 'smart-contact-form' ),
                'view_item'          => __( 'View Form', 'smart-contact-form' ),
                'all_items'          => __( 'All Forms', 'smart-contact-form' ),
                'search_items'       => __( 'Search Forms', 'smart-contact-form' ),
            ],
            'public'              => false,
            'show_ui'             => true,
            'show_in_menu'        => false, // handled by our admin menu
            'capability_type'     => 'post',
            'supports'            => [ 'title' ],
            'has_archive'         => false,
            'rewrite'             => false,
        ] );
    }
}
