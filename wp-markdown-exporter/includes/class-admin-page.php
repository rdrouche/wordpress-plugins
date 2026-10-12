<?php
/**
 * Admin page: "Markdown Export" under Tools menu.
 * Allows selecting post types, filtering by status, and bulk exporting.
 */
class WPME_Admin_Page {

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
        add_action( 'admin_post_wpme_export', [ __CLASS__, 'handle_export' ] );
    }

    public static function register_menu() {
        add_management_page(
            __( 'Markdown Export', 'wp-markdown-exporter' ),
            __( 'Markdown Export', 'wp-markdown-exporter' ),
            'edit_posts',
            'wpme-export',
            [ __CLASS__, 'render_page' ]
        );
    }

    public static function enqueue_assets( $hook ) {
        if ( $hook !== 'tools_page_wpme-export' ) {
            return;
        }
        // Inline styles — no external dependencies needed
        wp_add_inline_style( 'wp-admin', self::inline_css() );
    }

    public static function render_page() {
        $post_types = self::get_exportable_post_types();
        ?>
        <div class="wrap wpme-wrap">
            <h1 class="wpme-title">
                <span class="wpme-icon">⬇</span>
                <?php esc_html_e( 'Markdown Exporter', 'wp-markdown-exporter' ); ?>
            </h1>
            <p class="wpme-subtitle">
                <?php esc_html_e( 'Export vos posts, pages et CPTs vers des fichiers Markdown (.md) avec front matter YAML.', 'wp-markdown-exporter' ); ?>
            </p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpme-form">
                <?php wp_nonce_field( 'wpme_export_action', 'wpme_nonce' ); ?>
                <input type="hidden" name="action" value="wpme_export">

                <div class="wpme-card">
                    <h2><?php esc_html_e( '1. Sélectionner le type de contenu', 'wp-markdown-exporter' ); ?></h2>
                    <div class="wpme-post-types">
                        <?php foreach ( $post_types as $pt ) : ?>
                            <label class="wpme-checkbox-label">
                                <input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" checked>
                                <span class="wpme-pt-name"><?php echo esc_html( $pt->label ); ?></span>
                                <span class="wpme-pt-slug">(<?php echo esc_html( $pt->name ); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="wpme-card">
                    <h2><?php esc_html_e( '2. Filtres', 'wp-markdown-exporter' ); ?></h2>
                    <div class="wpme-row">
                        <label>
                            <?php esc_html_e( 'Statut', 'wp-markdown-exporter' ); ?>
                            <select name="post_status">
                                <option value="any"><?php esc_html_e( 'Tous', 'wp-markdown-exporter' ); ?></option>
                                <option value="publish"><?php esc_html_e( 'Publié', 'wp-markdown-exporter' ); ?></option>
                                <option value="draft"><?php esc_html_e( 'Brouillon', 'wp-markdown-exporter' ); ?></option>
                                <option value="private"><?php esc_html_e( 'Privé', 'wp-markdown-exporter' ); ?></option>
                                <option value="pending"><?php esc_html_e( 'En attente', 'wp-markdown-exporter' ); ?></option>
                            </select>
                        </label>

                        <label>
                            <?php esc_html_e( 'Limite (0 = tous)', 'wp-markdown-exporter' ); ?>
                            <input type="number" name="limit" value="0" min="0" step="1">
                        </label>
                    </div>
                </div>

                <div class="wpme-card">
                    <h2><?php esc_html_e( '3. Options', 'wp-markdown-exporter' ); ?></h2>
                    <div class="wpme-options">
                        <label class="wpme-checkbox-label">
                            <input type="checkbox" name="include_frontmatter" value="1" checked>
                            <?php esc_html_e( 'Front matter YAML (titre, date, auteur, taxonomies…)', 'wp-markdown-exporter' ); ?>
                        </label>
                        <label class="wpme-checkbox-label">
                            <input type="checkbox" name="include_featured_img" value="1" checked>
                            <?php esc_html_e( 'Image à la une', 'wp-markdown-exporter' ); ?>
                        </label>
                        <label class="wpme-checkbox-label">
                            <input type="checkbox" name="include_meta" value="1" checked>
                            <?php esc_html_e( 'Champs personnalisés (custom fields publics)', 'wp-markdown-exporter' ); ?>
                        </label>
                        <label class="wpme-checkbox-label">
                            <input type="checkbox" name="include_taxonomies" value="1" checked>
                            <?php esc_html_e( 'Taxonomies dans le front matter', 'wp-markdown-exporter' ); ?>
                        </label>
                    </div>
                </div>

                <div class="wpme-card">
                    <h2><?php esc_html_e( '4. Format d\'export', 'wp-markdown-exporter' ); ?></h2>
                    <div class="wpme-options">
                        <label class="wpme-checkbox-label">
                            <input type="radio" name="export_format" value="zip" checked>
                            <?php esc_html_e( 'ZIP (tous les fichiers .md)', 'wp-markdown-exporter' ); ?>
                        </label>
                        <label class="wpme-checkbox-label">
                            <input type="radio" name="export_format" value="single">
                            <?php esc_html_e( 'Fichier unique concaténé (.md)', 'wp-markdown-exporter' ); ?>
                        </label>
                    </div>
                </div>

                <div class="wpme-actions">
                    <?php submit_button(
                        __( '⬇ Exporter en Markdown', 'wp-markdown-exporter' ),
                        'primary large',
                        'submit',
                        false
                    ); ?>
                </div>
            </form>
        </div>
        <?php
    }

    public static function handle_export() {
        if (
            ! isset( $_POST['wpme_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpme_nonce'] ) ), 'wpme_export_action' )
        ) {
            wp_die( esc_html__( 'Nonce invalide.', 'wp-markdown-exporter' ) );
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Permission refusée.', 'wp-markdown-exporter' ) );
        }

        $post_types  = isset( $_POST['post_types'] ) ? array_map( 'sanitize_key', (array) $_POST['post_types'] ) : [ 'post' ];
        $post_status = isset( $_POST['post_status'] ) ? sanitize_key( $_POST['post_status'] ) : 'any';
        $limit       = isset( $_POST['limit'] ) ? max( 0, intval( $_POST['limit'] ) ) : 0;
        $format      = isset( $_POST['export_format'] ) ? sanitize_key( $_POST['export_format'] ) : 'zip';

        $options = [
            'include_frontmatter'  => ! empty( $_POST['include_frontmatter'] ),
            'include_featured_img' => ! empty( $_POST['include_featured_img'] ),
            'include_meta'         => ! empty( $_POST['include_meta'] ),
            'include_taxonomies'   => ! empty( $_POST['include_taxonomies'] ),
        ];

        $query_args = [
            'post_type'      => $post_types,
            'post_status'    => $post_status,
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];

        $query    = new WP_Query( $query_args );
        $post_ids = $query->posts;

        if ( empty( $post_ids ) ) {
            wp_safe_redirect( add_query_arg( 'wpme_notice', 'no_posts', admin_url( 'tools.php?page=wpme-export' ) ) );
            exit;
        }

        $date_str = gmdate( 'Y-m-d' );

        if ( $format === 'zip' ) {
            $zip_name = "markdown-export-{$date_str}.zip";
            WPME_Post_Exporter::download_zip( $post_ids, $options, $zip_name );
        } else {
            // Single concatenated .md file
            $combined = '';
            foreach ( $post_ids as $id ) {
                $combined .= WPME_Post_Exporter::export( $id, $options );
                $combined .= "\n\n---\n\n";
            }
            $filename = "markdown-export-{$date_str}.md";
            nocache_headers();
            header( 'Content-Type: text/markdown; charset=utf-8' );
            header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
            header( 'Content-Length: ' . strlen( $combined ) );
            echo $combined; // phpcs:ignore WordPress.Security.EscapeOutput
            exit;
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private static function get_exportable_post_types() {
        $args = [
            'public'  => true,
            '_builtin' => false,
        ];
        $custom = get_post_types( $args, 'objects' );
        $builtin = [
            get_post_type_object( 'post' ),
            get_post_type_object( 'page' ),
        ];
        return array_merge( $builtin, array_values( $custom ) );
    }

    private static function inline_css() {
        return '
        .wpme-wrap { max-width: 860px; }
        .wpme-title { display:flex; align-items:center; gap:10px; font-size:1.8em; margin-bottom:4px; }
        .wpme-icon { font-size:1.1em; }
        .wpme-subtitle { color:#666; margin-top:0; margin-bottom:24px; }
        .wpme-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:20px 24px; margin-bottom:20px; }
        .wpme-card h2 { font-size:1em; margin-top:0; margin-bottom:14px; color:#1d2327; }
        .wpme-post-types { display:flex; flex-wrap:wrap; gap:10px; }
        .wpme-checkbox-label { display:flex; align-items:center; gap:8px; cursor:pointer; padding:6px 0; }
        .wpme-checkbox-label input { margin:0; }
        .wpme-pt-slug { color:#999; font-size:.85em; }
        .wpme-options { display:flex; flex-direction:column; gap:4px; }
        .wpme-row { display:flex; flex-wrap:wrap; gap:20px; }
        .wpme-row label { display:flex; flex-direction:column; gap:4px; font-weight:500; }
        .wpme-row select, .wpme-row input[type="number"] { padding:6px 10px; border:1px solid #c3c4c7; border-radius:4px; }
        .wpme-actions { margin-top:8px; }
        ';
    }
}
