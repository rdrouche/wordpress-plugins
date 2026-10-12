<?php
/**
 * Adds an "Export .md" row action to each post in list tables.
 */
class WPME_Row_Action {

    public static function init() {
        add_action( 'init', [ __CLASS__, 'register_hooks' ], 20 );
        add_action( 'admin_post_wpme_export_single', [ __CLASS__, 'handle' ] );
    }

    public static function register_hooks() {
        $post_types = get_post_types( [ 'show_ui' => true ], 'names' );

        foreach ( $post_types as $post_type ) {
            // page uses page_row_actions, others use post_row_actions
            $filter = ( $post_type === 'page' ) ? 'page_row_actions' : 'post_row_actions';
            add_filter( $filter, [ __CLASS__, 'add_row_action' ], 10, 2 );
        }
    }

    public static function add_row_action( $actions, $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return $actions;
        }

        $url = add_query_arg(
            [
                'action'  => 'wpme_export_single',
                'post_id' => $post->ID,
                'nonce'   => wp_create_nonce( 'wpme_single_' . $post->ID ),
            ],
            admin_url( 'admin-post.php' )
        );

        $actions['wpme_export'] = sprintf(
            '<a href="%s" title="%s">%s</a>',
            esc_url( $url ),
            esc_attr__( 'Télécharger ce post en Markdown', 'wp-markdown-exporter' ),
            esc_html__( 'Export .md', 'wp-markdown-exporter' )
        );

        return $actions;
    }

    public static function handle() {
        $post_id = isset( $_GET['post_id'] ) ? intval( $_GET['post_id'] ) : 0;
        $nonce   = isset( $_GET['nonce'] )   ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

        if ( ! $post_id || ! wp_verify_nonce( $nonce, 'wpme_single_' . $post_id ) ) {
            wp_die( esc_html__( 'Nonce invalide.', 'wp-markdown-exporter' ) );
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            wp_die( esc_html__( 'Permission refusée.', 'wp-markdown-exporter' ) );
        }

        $options = [
            'include_frontmatter'  => true,
            'include_featured_img' => true,
            'include_meta'         => true,
            'include_taxonomies'   => true,
        ];

        WPME_Post_Exporter::download( $post_id, $options );
    }
}
