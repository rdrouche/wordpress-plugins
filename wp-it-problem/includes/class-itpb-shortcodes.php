<?php
/**
 * ITPB_Shortcodes
 *
 * Registers all front-end shortcodes.
 *
 * [itpb_list]        — List of problems (for integration in a native WP page)
 * [itpb_countdown]   — Standalone countdown for a single problem
 *
 * All shortcodes return HTML (never echo directly — shortcode API requirement).
 * Output is sanitized at each point of insertion.
 *
 * @package WP_IT_Problem
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class ITPB_Shortcodes {

    public function init(): void {
        add_shortcode( 'itpb_list',      [ $this, 'render_list' ] );
        add_shortcode( 'itpb_countdown', [ $this, 'render_countdown' ] );
    }

    // -----------------------------------------------------------------------
    // [itpb_list] — Problem list
    // -----------------------------------------------------------------------

    /**
     * Render a filterable list of IT problems.
     *
     * Attributes:
     *  limit        int     Number of problems per page. Default 10.
     *  infra        string  Comma-separated itpb_infra slugs to filter.
     *  difficulty   string  Comma-separated itpb_difficulty slugs to filter.
     *  status       string  'all' | 'revealed' | 'pending'. Default 'all'.
     *  orderby      string  'date' | 'reveal_at' | 'title'. Default 'date'.
     *  order        string  'ASC' | 'DESC'. Default 'DESC'.
     *  show_infra   bool    Show infra tags. Default true.
     *  show_status  bool    Show revealed/pending badge. Default true.
     *  show_excerpt bool    Show post excerpt. Default true.
     *
     * @param  array|string $atts
     * @return string
     */
    public function render_list( $atts ): string {
        $atts = shortcode_atts( [
            'limit'       => 10,
            'infra'       => '',
            'difficulty'  => '',
            'status'      => 'all',
            'orderby'     => 'date',
            'order'       => 'DESC',
            'show_infra'  => 'true',
            'show_status' => 'true',
            'show_excerpt'=> 'true',
        ], $atts, 'itpb_list' );

        // Sanitize
        $limit        = max( 1, min( 100, absint( $atts['limit'] ) ) );
        $infra_filter = array_filter( array_map( 'sanitize_key', explode( ',', $atts['infra'] ) ) );
        $diff_filter  = array_filter( array_map( 'sanitize_key', explode( ',', $atts['difficulty'] ) ) );
        $status       = in_array( $atts['status'], [ 'all', 'revealed', 'pending' ], true ) ? $atts['status'] : 'all';
        $orderby      = in_array( $atts['orderby'], [ 'date', 'reveal_at', 'title' ], true ) ? $atts['orderby'] : 'date';
        $order        = strtoupper( $atts['order'] ) === 'ASC' ? 'ASC' : 'DESC';
        $show_infra   = $atts['show_infra']   !== 'false';
        $show_status  = $atts['show_status']  !== 'false';
        $show_excerpt = $atts['show_excerpt'] !== 'false';

        // Pagination
        $paged = max( 1, (int) ( get_query_var( 'paged' ) ?: get_query_var( 'page' ) ?: 1 ) );

        // Build WP_Query args
        $query_args = [
            'post_type'      => ITPB_CPT_PROBLEM,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'paged'          => $paged,
            'no_found_rows'  => false,
        ];

        // Orderby
        switch ( $orderby ) {
            case 'reveal_at':
                $query_args['meta_key'] = ITPB_META_REVEAL_AT;
                $query_args['orderby']  = 'meta_value';
                $query_args['order']    = $order;
                break;
            case 'title':
                $query_args['orderby'] = 'title';
                $query_args['order']   = $order;
                break;
            default:
                $query_args['orderby'] = 'date';
                $query_args['order']   = $order;
        }

        // Taxonomy filters
        $tax_query = [];

        if ( ! empty( $infra_filter ) ) {
            $tax_query[] = [
                'taxonomy' => 'itpb_infra',
                'field'    => 'slug',
                'terms'    => $infra_filter,
            ];
        }

        if ( ! empty( $diff_filter ) ) {
            $tax_query[] = [
                'taxonomy' => 'itpb_difficulty',
                'field'    => 'slug',
                'terms'    => $diff_filter,
            ];
        }

        if ( ! empty( $tax_query ) ) {
            $tax_query['relation']  = 'AND';
            $query_args['tax_query'] = $tax_query;
        }

        // Status filter (revealed / pending)
        // We filter post-query because reveal is time-based meta logic
        /**
         * Filter the WP_Query args for [itpb_list].
         * Used by ITPB_Polylang to inject the current language.
         *
         * @param array $query_args
         * @since 1.0.0
         */
        $query_args = apply_filters( 'itpb_list_query_args', $query_args );

        $query = new \WP_Query( $query_args );
        $problems = $query->posts;

        if ( $status === 'revealed' ) {
            $problems = array_filter( $problems, fn( $p ) => itpb_is_revealed( $p->ID ) );
        } elseif ( $status === 'pending' ) {
            $problems = array_filter( $problems, fn( $p ) => ! itpb_is_revealed( $p->ID ) );
        }

        ob_start();

        echo '<div class="itpb-list-wrap">';

        if ( empty( $problems ) ) {
            echo '<p class="itpb-list-empty">' . esc_html( itpb_str( 'itpb_list_no_items' ) ) . '</p>';
        } else {
            echo '<div class="itpb-grid">';

            foreach ( $problems as $problem ) {
                $this->render_card(
                    $problem,
                    $show_infra,
                    $show_status,
                    $show_excerpt
                );
            }

            echo '</div>';

            // Pagination (uses WP native paginate_links — theme compatible)
            if ( $query->max_num_pages > 1 ) {
                echo '<nav class="itpb-pagination" aria-label="' . esc_attr__( 'Problems navigation', 'wp-it-problem' ) . '">';
                echo wp_kses_post( paginate_links( [
                    'total'   => $query->max_num_pages,
                    'current' => $paged,
                    'type'    => 'plain',
                ] ) );
                echo '</nav>';
            }
        }

        echo '</div><!-- .itpb-list-wrap -->';

        wp_reset_postdata();

        return ob_get_clean();
    }

    /**
     * Render a single problem as a card.
     *
     * @param \WP_Post $problem
     * @param bool     $show_infra
     * @param bool     $show_status
     * @param bool     $show_excerpt
     */
    private function render_card(
        \WP_Post $problem,
        bool $show_infra,
        bool $show_status,
        bool $show_excerpt
    ): void {
        $revealed  = itpb_is_revealed( $problem->ID );
        $reveal_at = itpb_get_reveal_at( $problem->ID );
        $permalink = get_permalink( $problem->ID );

        // Taxonomy terms
        $infra_terms = get_the_terms( $problem->ID, 'itpb_infra' );
        $diff_terms  = get_the_terms( $problem->ID, 'itpb_difficulty' );
        $diff        = ( $diff_terms && ! is_wp_error( $diff_terms ) ) ? $diff_terms[0] : null;

        // Clean excerpt — strip all HTML tags, decode entities, truncate to 130 chars
        $raw_excerpt = $problem->post_excerpt
            ? $problem->post_excerpt
            : $problem->post_content;
        $excerpt = wp_trim_words(
            wp_strip_all_tags( html_entity_decode( $raw_excerpt, ENT_QUOTES, 'UTF-8' ) ),
            22,   // ~130 chars
            '…'
        );

        $card_class = 'itpb-card' . ( $revealed ? ' itpb-card-revealed' : ' itpb-card-pending' );
        ?>
        <article class="<?php echo esc_attr( $card_class ); ?>">

            <?php /* ── Top bar : badge + infra tags ── */ ?>
            <div class="itpb-card-top">

                <?php if ( $show_status ) : ?>
                    <span class="itpb-badge <?php echo $revealed ? 'itpb-badge-revealed' : 'itpb-badge-pending'; ?>">
                        <?php if ( $revealed ) : ?>
                            &#10003; <?php echo esc_html( itpb_str( 'itpb_list_revealed_badge' ) ); ?>
                        <?php else : ?>
                            &#8987; <?php echo esc_html( itpb_str( 'itpb_list_pending_badge' ) ); ?>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>

                <?php if ( $show_infra && $infra_terms && ! is_wp_error( $infra_terms ) ) : ?>
                    <div class="itpb-card-infra-tags">
                        <?php foreach ( array_slice( $infra_terms, 0, 3 ) as $term ) : ?>
                            <span class="itpb-infra-tag"><?php echo esc_html( $term->name ); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>

            <?php /* ── Body : title + excerpt ── */ ?>
            <div class="itpb-card-body">
                <h3 class="itpb-card-title">
                    <a href="<?php echo esc_url( $permalink ); ?>">
                        <?php echo esc_html( get_the_title( $problem->ID ) ); ?>
                    </a>
                </h3>

                <?php if ( $show_excerpt && $excerpt ) : ?>
                    <p class="itpb-card-excerpt"><?php echo esc_html( $excerpt ); ?></p>
                <?php endif; ?>
            </div>

            <?php /* ── Footer : difficulty + reveal date ── */ ?>
            <div class="itpb-card-footer">

                <?php if ( $diff ) : ?>
                    <span class="itpb-meta-difficulty itpb-difficulty-<?php echo esc_attr( $diff->slug ); ?>">
                        <?php echo esc_html( $diff->name ); ?>
                    </span>
                <?php endif; ?>

                <?php if ( $reveal_at ) : ?>
                    <span class="itpb-card-date">
                        <time datetime="<?php echo esc_attr( $reveal_at->format( 'c' ) ); ?>">
                            <?php echo esc_html( $reveal_at->format( 'Y-m-d' ) ); ?>
                        </time>
                    </span>
                <?php endif; ?>

            </div>

            <?php /* ── Clickable overlay (whole card = link) ── */ ?>
            <a href="<?php echo esc_url( $permalink ); ?>" class="itpb-card-overlay" aria-hidden="true" tabindex="-1"></a>

        </article>
        <?php
    }

    // -----------------------------------------------------------------------
    // [itpb_countdown] — Standalone countdown
    // -----------------------------------------------------------------------

    /**
     * Render a standalone countdown for a specific problem.
     * Useful to embed a countdown anywhere on the site.
     *
     * Attributes:
     *  id   int  (required) Post ID of the it_problem.
     *
     * @param  array|string $atts
     * @return string
     */
    public function render_countdown( $atts ): string {
        $atts = shortcode_atts( [
            'id' => 0,
        ], $atts, 'itpb_countdown' );

        $problem_id = absint( $atts['id'] );

        if ( ! $problem_id ) {
            return $this->shortcode_error( __( '[itpb_countdown] requires an id attribute.', 'wp-it-problem' ) );
        }

        $problem = get_post( $problem_id );

        if ( ! $problem || $problem->post_type !== ITPB_CPT_PROBLEM || $problem->post_status !== 'publish' ) {
            return $this->shortcode_error( __( '[itpb_countdown] invalid problem ID.', 'wp-it-problem' ) );
        }

        $reveal_at = itpb_get_reveal_at( $problem_id );

        if ( ! $reveal_at ) {
            return '';
        }

        // Already revealed — show a simple "answered" notice
        if ( itpb_is_revealed( $problem_id ) ) {
            return sprintf(
                '<div class="itpb-countdown-wrap itpb-already-revealed"><p>%s <a href="%s">%s</a></p></div>',
                esc_html( itpb_str( 'itpb_countdown_revealed' ) ),
                esc_url( get_permalink( $problem_id ) ),
                esc_html( get_the_title( $problem_id ) )
            );
        }

        // Enqueue assets (the shortcode may appear outside a singular it_problem)
        if ( ! wp_script_is( 'itpb-frontend', 'enqueued' ) ) {
            wp_enqueue_style(
                'itpb-frontend',
                ITPB_URL . 'assets/css/frontend.css',
                [],
                ITPB_VERSION
            );
            wp_enqueue_script(
                'itpb-frontend',
                ITPB_URL . 'assets/js/frontend.js',
                [],
                ITPB_VERSION,
                true
            );
            wp_localize_script( 'itpb-frontend', 'itpbData', [
                'problemId' => $problem_id,
                'revealAt'  => $reveal_at->getTimestamp(),
                'restUrl'   => esc_url_raw( rest_url( 'itpb/v1/' ) ),
                'nonce'     => wp_create_nonce( 'wp_rest' ),
                'labels'    => [
                    'days'    => itpb_str( 'itpb_countdown_days' ),
                    'hours'   => itpb_str( 'itpb_countdown_hours' ),
                    'minutes' => itpb_str( 'itpb_countdown_minutes' ),
                    'seconds' => itpb_str( 'itpb_countdown_seconds' ),
                    'revealed'=> itpb_str( 'itpb_countdown_revealed' ),
                    'loading' => itpb_str( 'itpb_countdown_loading' ),
                    'answer'  => itpb_str( 'itpb_answer_title' ),
                ],
            ] );
        }

        // Server-side initial values
        $now     = time();
        $diff    = max( 0, $reveal_at->getTimestamp() - $now );
        $days    = floor( $diff / DAY_IN_SECONDS );
        $hours   = floor( ( $diff % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
        $minutes = floor( ( $diff % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
        $seconds = $diff % MINUTE_IN_SECONDS;

        ob_start();
        ?>
        <div
            class="itpb-countdown-wrap itpb-countdown-standalone"
            id="itpb-countdown-<?php echo esc_attr( $problem_id ); ?>"
            data-reveal-at="<?php echo esc_attr( $reveal_at->getTimestamp() ); ?>"
            data-problem-id="<?php echo esc_attr( $problem_id ); ?>"
            data-standalone="true"
            aria-live="polite"
        >
            <p class="itpb-countdown-problem-title">
                <a href="<?php echo esc_url( get_permalink( $problem_id ) ); ?>">
                    <?php echo esc_html( get_the_title( $problem_id ) ); ?>
                </a>
            </p>

            <div class="itpb-countdown-units">
                <div class="itpb-unit">
                    <span class="itpb-unit-value"><?php echo esc_html( $days ); ?></span>
                    <span class="itpb-unit-label"><?php itpb_e( 'itpb_countdown_days' ); ?></span>
                </div>
                <div class="itpb-unit-sep" aria-hidden="true">:</div>
                <div class="itpb-unit">
                    <span class="itpb-unit-value"><?php echo esc_html( str_pad( $hours,   2, '0', STR_PAD_LEFT ) ); ?></span>
                    <span class="itpb-unit-label"><?php itpb_e( 'itpb_countdown_hours' ); ?></span>
                </div>
                <div class="itpb-unit-sep" aria-hidden="true">:</div>
                <div class="itpb-unit">
                    <span class="itpb-unit-value"><?php echo esc_html( str_pad( $minutes, 2, '0', STR_PAD_LEFT ) ); ?></span>
                    <span class="itpb-unit-label"><?php itpb_e( 'itpb_countdown_minutes' ); ?></span>
                </div>
                <div class="itpb-unit-sep" aria-hidden="true">:</div>
                <div class="itpb-unit">
                    <span class="itpb-unit-value"><?php echo esc_html( str_pad( $seconds, 2, '0', STR_PAD_LEFT ) ); ?></span>
                    <span class="itpb-unit-label"><?php itpb_e( 'itpb_countdown_seconds' ); ?></span>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Render an error message — only shown to editors, silent to visitors.
     *
     * @param  string $message
     * @return string
     */
    private function shortcode_error( string $message ): string {
        if ( ! current_user_can( 'edit_posts' ) ) {
            return '';
        }

        return sprintf(
            '<p class="itpb-shortcode-error" style="color:#d63638; border:1px solid #d63638; padding:8px 12px; border-radius:4px;">%s</p>',
            esc_html( $message )
        );
    }
}
