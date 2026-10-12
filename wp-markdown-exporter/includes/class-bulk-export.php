<?php
/**
 * Adds a "Export en Markdown" bulk action to all post list tables.
 */
class WPME_Bulk_Export {

    public static function init() {
        // Hook for every registered post type
        add_action( 'init', [ __CLASS__, 'register_hooks' ], 20 );
        add_action( 'admin_post_wpme_bulk_action', [ __CLASS__, 'handle' ] );
    }

    public static function register_hooks() {
        $post_types = get_post_types( [ 'show_ui' => true ], 'names' );

        foreach ( $post_types as $post_type ) {
            add_filter( "bulk_actions-edit-{$post_type}", [ __CLASS__, 'add_bulk_action' ] );
            add_filter( "handle_bulk_actions-edit-{$post_type}", [ __CLASS__, 'handle_bulk_redirect' ], 10, 3 );
        }

        add_action( 'admin_notices', [ __CLASS__, 'bulk_action_notice' ] );
    }

    public static function add_bulk_action( $bulk_actions ) {
        $bulk_actions['wpme_export_md'] = __( 'Exporter en Markdown', 'wp-markdown-exporter' );
        return $bulk_actions;
    }

    /**
     * WordPress calls this filter after processing the bulk action.
     * We redirect to our own handler to stream the file download.
     */
    public static function handle_bulk_redirect( $redirect_to, $doaction, $post_ids ) {
        if ( $doaction !== 'wpme_export_md' ) {
            return $redirect_to;
        }

        if ( empty( $post_ids ) ) {
            return $redirect_to;
        }

        // Store post IDs in a transient, redirect to our handler
        $token = wp_generate_password( 16, false );
        set_transient( 'wpme_bulk_' . $token, $post_ids, 60 );

        return add_query_arg(
            [
                'action' => 'wpme_bulk_action',
                'token'  => $token,
                'nonce'  => wp_create_nonce( 'wpme_bulk_' . $token ),
            ],
            admin_url( 'admin-post.php' )
        );
    }

    public static function handle() {
        $token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
        $nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';

        if ( ! wp_verify_nonce( $nonce, 'wpme_bulk_' . $token ) ) {
            wp_die( esc_html__( 'Nonce invalide.', 'wp-markdown-exporter' ) );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Permission refusée.', 'wp-markdown-exporter' ) );
        }

        $post_ids = get_transient( 'wpme_bulk_' . $token );
        delete_transient( 'wpme_bulk_' . $token );

        if ( empty( $post_ids ) || ! is_array( $post_ids ) ) {
            wp_die( esc_html__( 'Aucun post trouvé pour cet export.', 'wp-markdown-exporter' ) );
        }

        $options = [
            'include_frontmatter'  => true,
            'include_featured_img' => true,
            'include_meta'         => true,
            'include_taxonomies'   => true,
        ];

        $date_str = gmdate( 'Y-m-d' );
        $zip_name = "markdown-export-{$date_str}.zip";

        WPME_Post_Exporter::download_zip( $post_ids, $options, $zip_name );
    }

    public static function bulk_action_notice() {
        // Nothing to do here — download triggers immediately
    }
}
