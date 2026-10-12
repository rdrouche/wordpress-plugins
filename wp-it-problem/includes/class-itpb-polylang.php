<?php
/**
 * ITPB_Polylang
 *
 * Polylang integration for WP IT Problem.
 *
 * Responsibilities:
 *  1. Register both CPTs as translatable in Polylang
 *  2. Keep the it_problem ↔ it_answer meta link consistent across languages
 *     (when an answer is duplicated/translated, re-link to its translated problem)
 *  3. Ensure taxonomy terms (itpb_infra, itpb_difficulty) are translatable
 *  4. Filter REST status/answer endpoints to respect current language context
 *  5. Shortcode [itpb_list] language filter
 *
 * Only loaded when function_exists('pll_register_string') — checked in itpb_boot().
 *
 * @package WP_IT_Problem
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class ITPB_Polylang {

    public function init(): void {
        // Register CPTs and taxonomies as translatable
        add_filter( 'pll_get_post_types',       [ $this, 'register_post_types' ], 10, 2 );
        add_filter( 'pll_get_taxonomies',        [ $this, 'register_taxonomies' ], 10, 2 );

        // Re-link answer ↔ problem after a post is duplicated to a new language
        // Polylang fires 'pll_save_post' after saving a translation
        add_action( 'pll_save_post', [ $this, 'sync_answer_link_on_translation' ], 10, 3 );

        // When a new translation of it_answer is created, link it to the
        // translated it_problem (not the original-language problem)
        add_action( 'pll_save_post', [ $this, 'sync_problem_link_on_translation' ], 10, 3 );

        // Filter [itpb_list] to current language
        add_filter( 'itpb_list_query_args', [ $this, 'filter_list_by_language' ] );

        // Filter REST status/answer responses — return 404 for posts in wrong language
        add_filter( 'itpb_api_problem_post', [ $this, 'filter_api_by_language' ], 10, 2 );
    }

    // -----------------------------------------------------------------------
    // CPT + Taxonomy registration
    // -----------------------------------------------------------------------

    /**
     * Tell Polylang to treat both CPTs as translatable.
     *
     * @param  array $post_types  Existing list of translatable post types.
     * @param  bool  $is_settings True when called from the settings screen.
     * @return array
     */
    public function register_post_types( array $post_types, bool $is_settings ): array {
        $post_types[ ITPB_CPT_PROBLEM ] = ITPB_CPT_PROBLEM;
        $post_types[ ITPB_CPT_ANSWER  ] = ITPB_CPT_ANSWER;
        return $post_types;
    }

    /**
     * Tell Polylang to treat both taxonomies as translatable.
     *
     * @param  array $taxonomies
     * @param  bool  $is_settings
     * @return array
     */
    public function register_taxonomies( array $taxonomies, bool $is_settings ): array {
        $taxonomies['itpb_infra']      = 'itpb_infra';
        $taxonomies['itpb_difficulty'] = 'itpb_difficulty';
        return $taxonomies;
    }

    // -----------------------------------------------------------------------
    // Cross-link sync on translation
    // -----------------------------------------------------------------------

    /**
     * When an it_problem is saved in a new language, check if its linked
     * it_answer also has a translation — if so, update the meta to point
     * to the translated answer.
     *
     * @param int      $post_id   The it_problem post ID just saved.
     * @param \WP_Post $post
     * @param array    $languages Array of language slugs the post was saved for.
     */
    public function sync_answer_link_on_translation( int $post_id, \WP_Post $post, array $languages ): void {
        if ( $post->post_type !== ITPB_CPT_PROBLEM ) {
            return;
        }

        $original_answer_id = (int) get_post_meta( $post_id, ITPB_META_ANSWER_ID, true );
        if ( ! $original_answer_id ) {
            return;
        }

        $current_lang = pll_get_post_language( $post_id );
        if ( ! $current_lang ) {
            return;
        }

        // Get the translated answer for the current language
        $translated_answer_id = pll_get_post( $original_answer_id, $current_lang );

        if ( $translated_answer_id && $translated_answer_id !== $original_answer_id ) {
            // Update the problem to point to its language-specific answer
            update_post_meta( $post_id, ITPB_META_ANSWER_ID, $translated_answer_id );

            // Ensure the back-link is set on the translated answer
            update_post_meta( $translated_answer_id, ITPB_META_PROBLEM_ID, $post_id );
        }
    }

    /**
     * When an it_answer is saved in a new language, point its _itpb_problem_id
     * to the translated problem (same language), not the original.
     *
     * @param int      $post_id
     * @param \WP_Post $post
     * @param array    $languages
     */
    public function sync_problem_link_on_translation( int $post_id, \WP_Post $post, array $languages ): void {
        if ( $post->post_type !== ITPB_CPT_ANSWER ) {
            return;
        }

        $original_problem_id = (int) get_post_meta( $post_id, ITPB_META_PROBLEM_ID, true );
        if ( ! $original_problem_id ) {
            return;
        }

        $current_lang = pll_get_post_language( $post_id );
        if ( ! $current_lang ) {
            return;
        }

        // Get the translated problem for the current language
        $translated_problem_id = pll_get_post( $original_problem_id, $current_lang );

        if ( $translated_problem_id && $translated_problem_id !== $original_problem_id ) {
            // Back-link: answer → translated problem
            update_post_meta( $post_id, ITPB_META_PROBLEM_ID, $translated_problem_id );

            // Forward-link: translated problem → this answer
            update_post_meta( $translated_problem_id, ITPB_META_ANSWER_ID, $post_id );
        }
    }

    // -----------------------------------------------------------------------
    // [itpb_list] language filter
    // -----------------------------------------------------------------------

    /**
     * Add the current Polylang language to WP_Query args used by [itpb_list].
     * This filter is applied inside ITPB_Shortcodes::render_list().
     *
     * @param  array $args  WP_Query args.
     * @return array
     */
    public function filter_list_by_language( array $args ): array {
        $lang = pll_current_language();
        if ( $lang ) {
            $args['lang'] = $lang;
        }
        return $args;
    }

    // -----------------------------------------------------------------------
    // REST API language filter
    // -----------------------------------------------------------------------

    /**
     * When the REST status/answer endpoint fetches a problem, verify it belongs
     * to the language inferred from the Accept-Language header or ?lang= param.
     *
     * If the post is in a different language, return null → the endpoint sends 404.
     *
     * @param  \WP_Post|null    $problem
     * @param  \WP_REST_Request $request
     * @return \WP_Post|null
     */
    public function filter_api_by_language( ?\WP_Post $problem, \WP_REST_Request $request ): ?\WP_Post {
        if ( ! $problem ) {
            return null;
        }

        // Respect explicit ?lang= query param (Polylang REST convention)
        $requested_lang = sanitize_key( $request->get_param( 'lang' ) ?? '' );

        if ( ! $requested_lang ) {
            // No explicit lang — allow any language (don't block)
            return $problem;
        }

        $post_lang = pll_get_post_language( $problem->ID );

        if ( $post_lang && $post_lang !== $requested_lang ) {
            // Try to serve the correct translation instead of 404
            $translated_id = pll_get_post( $problem->ID, $requested_lang );
            if ( $translated_id ) {
                return get_post( $translated_id );
            }
            return null;
        }

        return $problem;
    }

    // -----------------------------------------------------------------------
    // Static helpers
    // -----------------------------------------------------------------------

    /**
     * Get the current language slug, or empty string if Polylang unavailable.
     *
     * @return string
     */
    public static function current_language(): string {
        if ( function_exists( 'pll_current_language' ) ) {
            return (string) pll_current_language();
        }
        return '';
    }

    /**
     * Get the translated version of a post ID for the current (or given) language.
     * Returns the original ID if no translation exists.
     *
     * @param  int    $post_id
     * @param  string $lang     Language slug. Defaults to current language.
     * @return int
     */
    public static function get_translated_id( int $post_id, string $lang = '' ): int {
        if ( ! function_exists( 'pll_get_post' ) ) {
            return $post_id;
        }

        $lang        = $lang ?: self::current_language();
        $translated  = $lang ? pll_get_post( $post_id, $lang ) : 0;

        return $translated ?: $post_id;
    }
}
