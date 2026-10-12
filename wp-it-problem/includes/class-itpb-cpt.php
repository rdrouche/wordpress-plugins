<?php
/**
 * ITPB_Cpt
 *
 * Registers the two custom post types and their taxonomies.
 *
 * CPTs:
 *  - it_problem  : the question, always public
 *  - it_answer   : the answer, private until cron flips it to publish
 *
 * Taxonomies:
 *  - itpb_infra      : Infrastructure (Windows, Linux, Docker…) — hierarchical
 *  - itpb_difficulty : Difficulty level — flat, fixed terms seeded on activation
 *
 * @package WP_IT_Problem
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class ITPB_Cpt {

    /**
     * Fixed difficulty slugs — seeded once on activation.
     * Translated via itpb_str() on output, stored in English in DB.
     */
    public const DIFFICULTY_TERMS = [
        'beginner'     => 'Beginner',
        'intermediate' => 'Intermediate',
        'expert'       => 'Expert',
    ];

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------

    public function init(): void {
        add_action( 'init', [ $this, 'register_post_types' ], 0 );
        add_action( 'init', [ $this, 'register_taxonomies' ], 0 );

        // Seed difficulty terms once (idempotent)
        add_action( 'init', [ $this, 'seed_difficulty_terms' ], 5 );

        // Ensure it_answer is excluded from standard searches / sitemaps
        add_filter( 'pre_get_posts', [ $this, 'exclude_answers_from_search' ] );

        // Hide it_answer from REST browse (only accessible via /itpb/v1/ endpoints)
        add_filter( 'rest_it_answer_query', [ $this, 'restrict_answer_rest_query' ], 10, 2 );
    }

    // -----------------------------------------------------------------------
    // Post types
    // -----------------------------------------------------------------------

    /**
     * Register both CPTs.
     * Called directly from ITPB_Install::activate() before flush_rewrite_rules().
     */
    public function register_post_types(): void {
        $this->register_problem();
        $this->register_answer();
    }

    private function register_problem(): void {
        $labels = [
            'name'                  => _x( 'IT Problems', 'post type general name', 'wp-it-problem' ),
            'singular_name'         => _x( 'IT Problem', 'post type singular name', 'wp-it-problem' ),
            'add_new'               => __( 'Add New', 'wp-it-problem' ),
            'add_new_item'          => __( 'Add New Problem', 'wp-it-problem' ),
            'edit_item'             => __( 'Edit Problem', 'wp-it-problem' ),
            'new_item'              => __( 'New Problem', 'wp-it-problem' ),
            'view_item'             => __( 'View Problem', 'wp-it-problem' ),
            'view_items'            => __( 'View Problems', 'wp-it-problem' ),
            'search_items'          => __( 'Search Problems', 'wp-it-problem' ),
            'not_found'             => __( 'No problems found.', 'wp-it-problem' ),
            'not_found_in_trash'    => __( 'No problems found in trash.', 'wp-it-problem' ),
            'all_items'             => __( 'All Problems', 'wp-it-problem' ),
            'menu_name'             => __( 'IT Problems', 'wp-it-problem' ),
            'name_admin_bar'        => __( 'IT Problem', 'wp-it-problem' ),
        ];

        $args = [
            'labels'              => $labels,
            'description'         => __( 'IT problems with a scheduled answer reveal.', 'wp-it-problem' ),
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => true,
            'show_in_admin_bar'   => true,
            'show_in_rest'        => true,   // Gutenberg support
            'query_var'           => true,
            'rewrite'             => [
                'slug'       => 'it-problem',
                'with_front' => false,
            ],
            // No archive — integration via [itpb_list] shortcode in a native page
            'has_archive'         => false,
            'hierarchical'        => false,
            'menu_position'       => 25,
            'menu_icon'           => 'dashicons-sos',
            'supports'            => [ 'title', 'editor', 'thumbnail', 'comments', 'revisions' ],
            'taxonomies'          => [ 'itpb_infra', 'itpb_difficulty' ],
            'capability_type'     => 'post',
            'delete_with_user'    => false,
        ];

        register_post_type( ITPB_CPT_PROBLEM, $args );
    }

    private function register_answer(): void {
        $labels = [
            'name'                  => _x( 'IT Answers', 'post type general name', 'wp-it-problem' ),
            'singular_name'         => _x( 'IT Answer', 'post type singular name', 'wp-it-problem' ),
            'add_new'               => __( 'Add New', 'wp-it-problem' ),
            'add_new_item'          => __( 'Add New Answer', 'wp-it-problem' ),
            'edit_item'             => __( 'Edit Answer', 'wp-it-problem' ),
            'new_item'              => __( 'New Answer', 'wp-it-problem' ),
            'view_item'             => __( 'View Answer', 'wp-it-problem' ),
            'search_items'          => __( 'Search Answers', 'wp-it-problem' ),
            'not_found'             => __( 'No answers found.', 'wp-it-problem' ),
            'not_found_in_trash'    => __( 'No answers found in trash.', 'wp-it-problem' ),
            'all_items'             => __( 'All Answers', 'wp-it-problem' ),
            'menu_name'             => __( 'IT Answers', 'wp-it-problem' ),
        ];

        $args = [
            'labels'              => $labels,
            'description'         => __( 'Answers linked to IT Problems. Published automatically on reveal date.', 'wp-it-problem' ),
            // Publicly queryable so the answer permalink works after reveal,
            // but excluded from search and sitemaps (see filters below).
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            // Nested under IT Problems menu
            'show_in_menu'        => 'edit.php?post_type=' . ITPB_CPT_PROBLEM,
            'show_in_nav_menus'   => false,
            'show_in_admin_bar'   => false,
            'show_in_rest'        => true,   // Gutenberg support
            'query_var'           => true,
            'rewrite'             => [
                'slug'       => 'it-answer',
                'with_front' => false,
            ],
            'has_archive'         => false,
            'hierarchical'        => false,
            'supports'            => [ 'title', 'editor', 'revisions' ],
            'capability_type'     => 'post',
            'delete_with_user'    => false,
            // Exclude from XML sitemaps (WP 5.5+)
            'sitemap_excluded'    => true,
        ];

        register_post_type( ITPB_CPT_ANSWER, $args );
    }

    // -----------------------------------------------------------------------
    // Taxonomies
    // -----------------------------------------------------------------------

    public function register_taxonomies(): void {
        $this->register_infra();
        $this->register_difficulty();
    }

    private function register_infra(): void {
        $labels = [
            'name'              => _x( 'Infrastructure', 'taxonomy general name', 'wp-it-problem' ),
            'singular_name'     => _x( 'Infrastructure', 'taxonomy singular name', 'wp-it-problem' ),
            'search_items'      => __( 'Search Infrastructure', 'wp-it-problem' ),
            'all_items'         => __( 'All Infrastructure', 'wp-it-problem' ),
            'parent_item'       => __( 'Parent Infrastructure', 'wp-it-problem' ),
            'parent_item_colon' => __( 'Parent Infrastructure:', 'wp-it-problem' ),
            'edit_item'         => __( 'Edit Infrastructure', 'wp-it-problem' ),
            'update_item'       => __( 'Update Infrastructure', 'wp-it-problem' ),
            'add_new_item'      => __( 'Add New Infrastructure', 'wp-it-problem' ),
            'new_item_name'     => __( 'New Infrastructure Name', 'wp-it-problem' ),
            'menu_name'         => __( 'Infrastructure', 'wp-it-problem' ),
        ];

        register_taxonomy( 'itpb_infra', [ ITPB_CPT_PROBLEM ], [
            'labels'            => $labels,
            'hierarchical'      => true,   // tree like categories (Linux > Ubuntu)
            'public'            => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => false,
            'rewrite'           => [ 'slug' => 'infra', 'with_front' => false ],
            'query_var'         => true,
        ] );
    }

    private function register_difficulty(): void {
        $labels = [
            'name'          => _x( 'Difficulty', 'taxonomy general name', 'wp-it-problem' ),
            'singular_name' => _x( 'Difficulty', 'taxonomy singular name', 'wp-it-problem' ),
            'all_items'     => __( 'All Difficulties', 'wp-it-problem' ),
            'edit_item'     => __( 'Edit Difficulty', 'wp-it-problem' ),
            'update_item'   => __( 'Update Difficulty', 'wp-it-problem' ),
            'add_new_item'  => __( 'Add New Difficulty', 'wp-it-problem' ),
            'new_item_name' => __( 'New Difficulty Name', 'wp-it-problem' ),
            'menu_name'     => __( 'Difficulty', 'wp-it-problem' ),
        ];

        register_taxonomy( 'itpb_difficulty', [ ITPB_CPT_PROBLEM ], [
            'labels'            => $labels,
            'hierarchical'      => false,   // flat: Beginner / Intermediate / Expert
            'public'            => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'show_in_nav_menus' => false,
            'rewrite'           => [ 'slug' => 'difficulty', 'with_front' => false ],
            'query_var'         => true,
            // Prevent adding terms from the post edit screen (fixed vocabulary)
            'meta_box_cb'       => [ $this, 'difficulty_meta_box' ],
        ] );
    }

    // -----------------------------------------------------------------------
    // Difficulty terms seeding
    // -----------------------------------------------------------------------

    /**
     * Insert the three fixed difficulty terms if they don't exist yet.
     * Idempotent — safe to call on every init.
     */
    public function seed_difficulty_terms(): void {
        if ( ! taxonomy_exists( 'itpb_difficulty' ) ) {
            return;
        }

        foreach ( self::DIFFICULTY_TERMS as $slug => $name ) {
            if ( ! term_exists( $slug, 'itpb_difficulty' ) ) {
                wp_insert_term( $name, 'itpb_difficulty', [ 'slug' => $slug ] );
            }
        }
    }

    /**
     * Custom meta box for difficulty: radio buttons instead of checkboxes
     * (only one difficulty per problem makes sense).
     *
     * @param \WP_Post $post
     */
    public function difficulty_meta_box( \WP_Post $post ): void {
        $terms   = get_terms( [ 'taxonomy' => 'itpb_difficulty', 'hide_empty' => false ] );
        $current = wp_get_post_terms( $post->ID, 'itpb_difficulty', [ 'fields' => 'slugs' ] );
        $current = $current[0] ?? '';

        echo '<div class="itpb-difficulty-radios">';
        // "None" option
        printf(
            '<label><input type="radio" name="tax_input[itpb_difficulty][]" value="0" %s> %s</label><br>',
            checked( '', $current, false ),
            esc_html__( '— None —', 'wp-it-problem' )
        );

        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                printf(
                    '<label><input type="radio" name="tax_input[itpb_difficulty][]" value="%s" %s> %s</label><br>',
                    esc_attr( $term->slug ),
                    checked( $term->slug, $current, false ),
                    esc_html( $term->name )
                );
            }
        }
        echo '</div>';
    }

    // -----------------------------------------------------------------------
    // Filters
    // -----------------------------------------------------------------------

    /**
     * Exclude it_answer from front-end search results.
     *
     * @param \WP_Query $query
     */
    public function exclude_answers_from_search( \WP_Query $query ): void {
        if ( is_admin() || ! $query->is_search() || ! $query->is_main_query() ) {
            return;
        }

        $post_types = $query->get( 'post_type' );

        // If no specific post_type filter, WP defaults to 'post'; safe to return
        if ( empty( $post_types ) || $post_types === 'post' ) {
            return;
        }

        if ( is_array( $post_types ) ) {
            $query->set( 'post_type', array_diff( $post_types, [ ITPB_CPT_ANSWER ] ) );
        }
    }

    /**
     * Restrict REST API browse for it_answer: non-admins only see published posts.
     *
     * @param  array            $args
     * @param  \WP_REST_Request $request
     * @return array
     */
    public function restrict_answer_rest_query( array $args, \WP_REST_Request $request ): array {
        if ( ! current_user_can( 'edit_posts' ) ) {
            $args['post_status'] = 'publish';
        }

        return $args;
    }
}
