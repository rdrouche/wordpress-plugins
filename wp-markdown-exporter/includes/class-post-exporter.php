<?php
/**
 * Handles converting a WP_Post into a Markdown string and serving it as a download.
 */
class WPME_Post_Exporter {

    /**
     * Build the full Markdown content for a post.
     *
     * @param WP_Post|int $post
     * @param array       $options {
     *     @type bool $include_frontmatter  Include YAML front matter block.
     *     @type bool $include_meta         Include custom fields.
     *     @type bool $include_featured_img Include featured image.
     *     @type bool $include_taxonomies   Include taxonomies.
     * }
     * @return string
     */
    public static function export( $post, array $options = [] ) {
        if ( is_int( $post ) ) {
            $post = get_post( $post );
        }

        if ( ! $post instanceof WP_Post ) {
            return '';
        }

        $defaults = [
            'include_frontmatter'  => true,
            'include_meta'         => true,
            'include_featured_img' => true,
            'include_taxonomies'   => true,
        ];
        $options  = wp_parse_args( $options, $defaults );

        $output = '';

        // 1. YAML Front matter
        if ( $options['include_frontmatter'] ) {
            $output .= self::build_frontmatter( $post, $options );
        }

        // 2. Title
        $output .= '# ' . get_the_title( $post ) . "\n\n";

        // 3. Featured image
        if ( $options['include_featured_img'] && has_post_thumbnail( $post ) ) {
            $img_url = get_the_post_thumbnail_url( $post, 'full' );
            $alt     = get_the_title( $post );
            $output .= "![{$alt}]({$img_url})\n\n";
        }

        // 4. Content
        // On utilise wpautop() uniquement (pas apply_filters complet) pour éviter
        // les effets de bord des plugins tiers. On veut le contenu brut fidèle.
        $raw_content = $post->post_content;

        // Résoudre les shortcodes WordPress natifs uniquement
        $raw_content = do_shortcode( $raw_content );

        // Convertir les blocs Gutenberg en HTML propre
        if ( function_exists( 'do_blocks' ) ) {
            $raw_content = do_blocks( $raw_content );
        }

        // Appliquer wpautop pour transformer les sauts de ligne en <p>
        $html_content = wpautop( $raw_content );

        $output .= WPME_HTML_To_Markdown::convert( $html_content );
        $output .= "\n\n";

        // 5. Custom fields / meta
        if ( $options['include_meta'] ) {
            $meta_output = self::build_meta_section( $post );
            if ( $meta_output ) {
                $output .= $meta_output;
            }
        }

        return $output;
    }

    /**
     * Stream a Markdown file download for a post.
     *
     * @param WP_Post|int $post
     * @param array       $options
     */
    public static function download( $post, array $options = [] ) {
        if ( is_int( $post ) ) {
            $post = get_post( $post );
        }

        $content  = self::export( $post, $options );
        $filename = sanitize_title( $post->post_title ) . '.md';

        if ( empty( $filename ) || $filename === '.md' ) {
            $filename = 'post-' . $post->ID . '.md';
        }

        nocache_headers();
        header( 'Content-Type: text/markdown; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $content ) );
        echo $content;
        exit;
    }

    /**
     * Stream a ZIP download containing multiple posts as Markdown files.
     *
     * @param int[]  $post_ids
     * @param array  $options
     * @param string $zip_filename
     */
    public static function download_zip( array $post_ids, array $options = [], $zip_filename = 'export.zip' ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_die( esc_html__( 'ZipArchive PHP extension is required for bulk export.', 'wp-markdown-exporter' ) );
        }

        $tmp = wp_tempnam( 'wpme_' ) . '.zip';
        $zip = new ZipArchive();

        if ( $zip->open( $tmp, ZipArchive::CREATE ) !== true ) {
            wp_die( esc_html__( 'Could not create ZIP file.', 'wp-markdown-exporter' ) );
        }

        foreach ( $post_ids as $id ) {
            $post = get_post( $id );
            if ( ! $post ) {
                continue;
            }
            $content  = self::export( $post, $options );
            $filename = sanitize_title( $post->post_title );
            if ( empty( $filename ) ) {
                $filename = 'post-' . $post->ID;
            }
            $zip->addFromString( $filename . '.md', $content );
        }

        $zip->close();

        nocache_headers();
        header( 'Content-Type: application/zip' );
        header( 'Content-Disposition: attachment; filename="' . $zip_filename . '"' );
        header( 'Content-Length: ' . filesize( $tmp ) );
        readfile( $tmp );
        unlink( $tmp );
        exit;
    }

    // ─── Private helpers ────────────────────────────────────────────────────────

    private static function build_frontmatter( WP_Post $post, array $options ) {
        $author    = get_the_author_meta( 'display_name', $post->post_author );
        $date      = get_the_date( 'Y-m-d', $post );
        $modified  = get_the_modified_date( 'Y-m-d', $post );
        $permalink = get_permalink( $post );
        $status    = $post->post_status;
        $type      = $post->post_type;
        $slug      = $post->post_name;
        $excerpt   = has_excerpt( $post ) ? wp_strip_all_tags( $post->post_excerpt ) : '';

        $fm  = "---\n";
        $fm .= 'title: "' . addslashes( get_the_title( $post ) ) . "\"\n";
        $fm .= "date: {$date}\n";
        $fm .= "modified: {$modified}\n";
        $fm .= "author: \"{$author}\"\n";
        $fm .= "slug: \"{$slug}\"\n";
        $fm .= "status: {$status}\n";
        $fm .= "type: {$type}\n";
        $fm .= "permalink: \"{$permalink}\"\n";

        if ( $excerpt ) {
            $fm .= "excerpt: \"{$excerpt}\"\n";
        }

        // Taxonomies
        if ( $options['include_taxonomies'] ) {
            $taxonomies = get_object_taxonomies( $post->post_type );
            foreach ( $taxonomies as $taxonomy ) {
                $terms = get_the_terms( $post, $taxonomy );
                if ( $terms && ! is_wp_error( $terms ) ) {
                    $names = array_map( fn( $t ) => $t->name, $terms );
                    $fm   .= "{$taxonomy}:\n";
                    foreach ( $names as $name ) {
                        $fm .= "  - \"{$name}\"\n";
                    }
                }
            }
        }

        $fm .= "---\n\n";
        return $fm;
    }

    private static function build_meta_section( WP_Post $post ) {
        // Skip internal WP meta keys (prefixed with _)
        $meta = get_post_meta( $post->ID );
        if ( empty( $meta ) ) {
            return '';
        }

        $public_meta = [];
        foreach ( $meta as $key => $values ) {
            if ( substr( $key, 0, 1 ) === '_' ) {
                continue; // skip private/internal
            }
            $public_meta[ $key ] = count( $values ) === 1 ? $values[0] : $values;
        }

        if ( empty( $public_meta ) ) {
            return '';
        }

        $output  = "\n---\n\n";
        $output .= "## Custom Fields\n\n";
        $output .= "| Field | Value |\n";
        $output .= "| --- | --- |\n";

        foreach ( $public_meta as $key => $value ) {
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'strval', $value ) );
            }
            $value   = wp_strip_all_tags( strval( $value ) );
            $output .= "| {$key} | {$value} |\n";
        }

        return $output . "\n";
    }
}
