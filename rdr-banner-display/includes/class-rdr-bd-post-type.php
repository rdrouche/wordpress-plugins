<?php
/**
 * Registers the rdr_banner custom post type and its meta fields.
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

class RDR_BD_Post_Type {

    const CPT = 'rdr_banner';

    public function init(): void {
        add_action( 'init', [ $this, 'register_post_type' ] );
        add_action( 'init', [ $this, 'register_meta' ] );
    }

    public function register_post_type(): void {
        $labels = [
            'name'               => 'Banners',
            'singular_name'      => 'Banner',
            'menu_name'          => 'RDR Banners',
            'add_new'            => 'Add Banner',
            'add_new_item'       => 'Add New Banner',
            'edit_item'          => 'Edit Banner',
            'new_item'           => 'New Banner',
            'view_item'          => 'View Banner',
            'search_items'       => 'Search Banners',
            'not_found'          => 'No banners found',
            'not_found_in_trash' => 'No banners in trash',
        ];

        register_post_type( self::CPT, [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'show_in_rest'        => false,
            'capability_type'     => 'post',
            'map_meta_cap'        => true,
            'supports'            => [ 'title' ],
            'rewrite'             => false,
            'query_var'           => false,
        ] );
    }

    /**
     * Register post meta fields.
     *
     * IMPORTANT: _rdr_bd_items stores a JSON blob that contains raw HTML and
     * AdSense script tags. It MUST NOT use sanitize_text_field (which calls
     * wp_strip_all_tags and would destroy the content).  We use a passthrough
     * callback that only validates the value is a string — actual sanitisation
     * is done explicitly in save_meta_box() before update_post_meta() is called.
     */
    public function register_meta(): void {

        // ── Simple scalar fields ──────────────────────────────────────────────

        register_post_meta( self::CPT, '_rdr_bd_width', [
            'type'              => 'integer',
            'single'            => true,
            'default'           => 300,
            'sanitize_callback' => 'absint',
            'show_in_rest'      => false,
        ] );

        register_post_meta( self::CPT, '_rdr_bd_height', [
            'type'              => 'integer',
            'single'            => true,
            'default'           => 250,
            'sanitize_callback' => 'absint',
            'show_in_rest'      => false,
        ] );

        register_post_meta( self::CPT, '_rdr_bd_mode', [
            'type'              => 'string',
            'single'            => true,
            'default'           => 'single',
            'sanitize_callback' => 'sanitize_key',
            'show_in_rest'      => false,
        ] );

        // _rdr_bd_campaign_id removed in v2.0.0 — campaign is now per-item inside _rdr_bd_items JSON.

        register_post_meta( self::CPT, '_rdr_bd_note', [
            'type'              => 'string',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => 'sanitize_textarea_field',
            'show_in_rest'      => false,
        ] );

        // ── Items JSON — NO tag-stripping sanitize callback ───────────────────
        // Content is already sanitised in save_meta_box() before this point.
        // The callback here is a simple type-cast to string to satisfy WP's API
        // without mangling HTML/script content.
        register_post_meta( self::CPT, '_rdr_bd_items', [
            'type'              => 'string',
            'single'            => true,
            'default'           => '[]',
            'sanitize_callback' => static function ( $value ): string {
                // Only ensure it is a string — do NOT call sanitize_text_field,
                // strip_tags, or any function that removes HTML content.
                return is_string( $value ) ? $value : '';
            },
            'show_in_rest'      => false,
        ] );
    }
}
