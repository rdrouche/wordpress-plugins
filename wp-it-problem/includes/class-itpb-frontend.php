<?php
/**
 * ITPB_Frontend
 *
 * Handles all front-end output for it_problem single pages:
 *  - Appends the answer block (or countdown block) after the problem content
 *  - Enqueues CSS + JS only where needed
 *  - Provides the data payload for the JS countdown / AJAX reveal
 *
 * No output buffering. No template overrides.
 * Works with any theme — content is injected via the_content filter.
 *
 * @package WP_IT_Problem
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class ITPB_Frontend {

    public function init(): void {
        // Append answer/countdown block to problem content
        add_filter( 'the_content', [ $this, 'append_answer_block' ], 20 );

        // Enqueue assets only on relevant pages
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

        // Prevent it_answer single pages from being accessed directly
        // before reveal (redirect to parent problem)
        add_action( 'template_redirect', [ $this, 'guard_answer_direct_access' ] );

        // Breadcrumb — inject archive page between home and problem title
        add_filter( 'rank_math/frontend/breadcrumb/items', [ $this, 'inject_breadcrumb_crumb' ], 10, 2 );
        add_filter( 'wpseo_breadcrumb_links',              [ $this, 'inject_yoast_breadcrumb' ], 10, 1 );
    }

    // -----------------------------------------------------------------------
    // Asset enqueue
    // -----------------------------------------------------------------------

    public function enqueue_assets(): void {
        $problem_id = null;

        if ( is_singular( ITPB_CPT_PROBLEM ) ) {
            $problem_id = get_the_ID();
        }

        // CSS doit aussi être chargé sur les pages qui contiennent
        // le shortcode [itpb_list] ou [itpb_countdown]
        $load_css = $problem_id !== null || $this->current_page_has_itpb_shortcode();

        if ( ! $load_css ) {
            return;
        }

        wp_enqueue_style(
            'itpb-frontend',
            ITPB_URL . 'assets/css/frontend.css',
            [],
            ITPB_VERSION
        );

        // JS countdown uniquement sur les singles it_problem non révélés
        if (
            $problem_id
            && ! itpb_is_revealed( $problem_id )
            && get_option( 'itpb_show_countdown', true )
        ) {
            wp_enqueue_script(
                'itpb-frontend',
                ITPB_URL . 'assets/js/frontend.js',
                [],           // No jQuery dependency
                ITPB_VERSION,
                true          // Footer
            );

            // Pass data to JS
            wp_localize_script( 'itpb-frontend', 'itpbData', $this->get_js_data( $problem_id ) );
        }
    }

    /**
     * Check if the current page's post content contains an ITPB shortcode.
     * Used to decide whether to load the CSS on non-singular pages.
     *
     * @return bool
     */
    private function current_page_has_itpb_shortcode(): bool {
        global $post;

        if ( ! $post instanceof \WP_Post ) {
            return false;
        }

        return has_shortcode( $post->post_content, 'itpb_list' )
            || has_shortcode( $post->post_content, 'itpb_countdown' );
    }

    /**
     * Build the data object passed to frontend.js via wp_localize_script.
     *
     * @param  int $problem_id
     * @return array
     */
    private function get_js_data( int $problem_id ): array {
        $reveal_at  = itpb_get_reveal_at( $problem_id );
        $timestamp  = $reveal_at ? $reveal_at->getTimestamp() : 0;

        return [
            'problemId'   => $problem_id,
            'revealAt'    => $timestamp,                              // Unix timestamp UTC
            'restUrl'     => esc_url_raw( rest_url( 'itpb/v1/' ) ),  // Base REST URL
            'nonce'       => wp_create_nonce( 'wp_rest' ),
            'labels'      => [
                'days'      => itpb_str( 'itpb_countdown_days' ),
                'hours'     => itpb_str( 'itpb_countdown_hours' ),
                'minutes'   => itpb_str( 'itpb_countdown_minutes' ),
                'seconds'   => itpb_str( 'itpb_countdown_seconds' ),
                'revealed'  => itpb_str( 'itpb_countdown_revealed' ),
                'loading'   => itpb_str( 'itpb_countdown_loading' ),
                'answer'    => itpb_str( 'itpb_answer_title' ),
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Content filter — appends answer / countdown block
    // -----------------------------------------------------------------------

    /**
     * Append the answer block (or countdown) after the problem content.
     *
     * Only fires on:
     *  - it_problem single pages
     *  - Main query (not widgets, not REST, not custom loops)
     *
     * @param  string $content
     * @return string
     */
    public function append_answer_block( string $content ): string {
        // Static re-entrancy guard — prevents infinite loop if any downstream
        // hook calls apply_filters('the_content') while we're already running
        static $running = false;
        if ( $running ) {
            return $content;
        }

        // Only on it_problem posts
        if ( get_post_type() !== ITPB_CPT_PROBLEM ) {
            return $content;
        }

        // Only in the main loop — avoids firing in sidebars / related posts
        if ( ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $problem_id = get_the_ID();
        if ( ! $problem_id ) {
            return $content;
        }

        $running = true;
        $block   = $this->render_block( $problem_id );
        $running = false;

        return $content . $block;
    }

    /**
     * Render either the answer block or the countdown block.
     *
     * @param  int $problem_id
     * @return string  HTML (already escaped)
     */
    private function render_block( int $problem_id ): string {
        $reveal_at = itpb_get_reveal_at( $problem_id );

        // No reveal date set at all — show nothing
        if ( ! $reveal_at ) {
            return '';
        }

        if ( itpb_is_revealed( $problem_id ) ) {
            return $this->render_answer_block( $problem_id );
        }

        // Not yet revealed
        if ( get_option( 'itpb_show_countdown', true ) ) {
            return $this->render_countdown_block( $problem_id, $reveal_at );
        }

        return '';
    }

    // -----------------------------------------------------------------------
    // Answer block (revealed)
    // -----------------------------------------------------------------------

    /**
     * Render the answer block with the full it_answer post content.
     *
     * The answer content goes through apply_filters('the_content') so all
     * block rendering, syntax highlighting etc. fires normally.
     *
     * @param  int $problem_id
     * @return string
     */
    private function render_answer_block( int $problem_id ): string {
        $answer = itpb_get_answer_post( $problem_id );

        if ( ! $answer || $answer->post_status !== 'publish' ) {
            return $this->render_cron_lag_notice();
        }

        // CRITICAL: remove our own filter BEFORE calling apply_filters('the_content')
        // to prevent infinite recursion (our hook calls the_content → our hook → …)
        remove_filter( 'the_content', [ $this, 'append_answer_block' ], 20 );

        // Switch global post to the answer so blocks render correctly
        global $post;
        $original_post = $post;
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $post = $answer;
        setup_postdata( $post );

        $answer_content = apply_filters( 'the_content', $answer->post_content );

        // Restore global post and re-hook our filter
        // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
        $post = $original_post;
        wp_reset_postdata();
        add_filter( 'the_content', [ $this, 'append_answer_block' ], 20 );

        // Reveal log: first view timestamp
        if ( ! get_post_meta( $answer->ID, '_itpb_first_view_at', true ) ) {
            update_post_meta( $answer->ID, '_itpb_first_view_at', current_time( 'mysql', true ) );
        }

        ob_start();
        ?>
        <div class="itpb-answer-wrap" id="itpb-answer-zone" data-problem-id="<?php echo esc_attr( $problem_id ); ?>">
            <div class="itpb-answer-header">
                <span class="itpb-answer-badge itpb-badge-revealed">
                    &#10003; <?php itpb_e( 'itpb_answer_title' ); ?>
                </span>
            </div>
            <div class="itpb-answer-content">
                <?php
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo $answer_content;
                ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Tiny notice shown when the cron has not run yet but reveal_at has passed.
     * Disappears on next page load once cron fires (usually < 1 minute).
     *
     * @return string
     */
    private function render_cron_lag_notice(): string {
        return sprintf(
            '<div class="itpb-answer-wrap itpb-cron-lag" id="itpb-answer-zone">
                <p class="itpb-cron-lag-msg">%s</p>
            </div>',
            esc_html__( 'The answer is being published. Please refresh in a moment.', 'wp-it-problem' )
        );
    }

    // -----------------------------------------------------------------------
    // Countdown block (not yet revealed)
    // -----------------------------------------------------------------------

    /**
     * Render the countdown block.
     * The JS in frontend.js targets #itpb-countdown and #itpb-answer-zone.
     *
     * @param  int                $problem_id
     * @param  \DateTimeImmutable $reveal_at   UTC reveal datetime
     * @return string
     */
    private function render_countdown_block( int $problem_id, \DateTimeImmutable $reveal_at ): string {
        $ts = $reveal_at->getTimestamp();

        // Server-side initial values (JS will take over immediately on DOMContentLoaded)
        $now      = time();
        $diff     = max( 0, $ts - $now );
        $days     = floor( $diff / DAY_IN_SECONDS );
        $hours    = floor( ( $diff % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
        $minutes  = floor( ( $diff % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
        $seconds  = $diff % MINUTE_IN_SECONDS;

        ob_start();
        ?>
        <div
            class="itpb-countdown-wrap"
            id="itpb-countdown"
            data-reveal-at="<?php echo esc_attr( $ts ); ?>"
            data-problem-id="<?php echo esc_attr( $problem_id ); ?>"
            aria-live="polite"
            aria-label="<?php esc_attr_e( 'Answer countdown', 'wp-it-problem' ); ?>"
        >
            <div class="itpb-countdown-header">
                <span class="itpb-answer-badge itpb-badge-pending">
                    &#8987; <?php itpb_e( 'itpb_countdown_title' ); ?>
                </span>
            </div>

            <div class="itpb-countdown-units">
                <div class="itpb-unit" id="itpb-unit-days">
                    <span class="itpb-unit-value"><?php echo esc_html( $days ); ?></span>
                    <span class="itpb-unit-label"><?php itpb_e( 'itpb_countdown_days' ); ?></span>
                </div>
                <div class="itpb-unit-sep" aria-hidden="true">:</div>
                <div class="itpb-unit" id="itpb-unit-hours">
                    <span class="itpb-unit-value"><?php echo esc_html( str_pad( $hours,   2, '0', STR_PAD_LEFT ) ); ?></span>
                    <span class="itpb-unit-label"><?php itpb_e( 'itpb_countdown_hours' ); ?></span>
                </div>
                <div class="itpb-unit-sep" aria-hidden="true">:</div>
                <div class="itpb-unit" id="itpb-unit-minutes">
                    <span class="itpb-unit-value"><?php echo esc_html( str_pad( $minutes, 2, '0', STR_PAD_LEFT ) ); ?></span>
                    <span class="itpb-unit-label"><?php itpb_e( 'itpb_countdown_minutes' ); ?></span>
                </div>
                <div class="itpb-unit-sep" aria-hidden="true">:</div>
                <div class="itpb-unit" id="itpb-unit-seconds">
                    <span class="itpb-unit-value"><?php echo esc_html( str_pad( $seconds, 2, '0', STR_PAD_LEFT ) ); ?></span>
                    <span class="itpb-unit-label"><?php itpb_e( 'itpb_countdown_seconds' ); ?></span>
                </div>
            </div>

            <?php
            // Reveal date in human-readable form (fallback for no-JS)
            printf(
                '<p class="itpb-reveal-date">%s <time datetime="%s">%s UTC</time></p>',
                esc_html__( 'Answer on:', 'wp-it-problem' ),
                esc_attr( $reveal_at->format( 'c' ) ),
                esc_html( $reveal_at->format( 'Y-m-d H:i' ) )
            );
            ?>
        </div>

        <?php
        // Hidden placeholder — JS will inject the answer content here on reveal
        ?>
        <div class="itpb-answer-wrap itpb-answer-hidden" id="itpb-answer-zone" data-problem-id="<?php echo esc_attr( $problem_id ); ?>" aria-hidden="true"></div>
        <?php

        return ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // Breadcrumb — Rank Math + Yoast
    // -----------------------------------------------------------------------

    /**
     * Inject the archive page crumb into Rank Math breadcrumb items.
     *
     * Before: RDR-IT » Problème n°1
     * After:  RDR-IT » Problèmes IT » Problème n°1
     *
     * @param  array $items    Breadcrumb items [ [label, url], … ]
     * @param  mixed $context  Rank Math breadcrumb object
     * @return array
     */
    public function inject_breadcrumb_crumb( array $items, $context ): array {
        if ( ! is_singular( ITPB_CPT_PROBLEM ) ) {
            return $items;
        }

        $crumb = $this->get_archive_crumb();
        if ( ! $crumb ) {
            return $items;
        }

        // Rank Math items format: [ 0 => label, 1 => url ]
        // Insert after index 0 (home) and before last (current post title)
        array_splice( $items, 1, 0, [ $crumb ] );

        return $items;
    }

    /**
     * Inject the archive page crumb into Yoast SEO breadcrumb links.
     *
     * @param  array $links  [ ['text' => …, 'url' => …], … ]
     * @return array
     */
    public function inject_yoast_breadcrumb( array $links ): array {
        if ( ! is_singular( ITPB_CPT_PROBLEM ) ) {
            return $links;
        }

        $crumb = $this->get_archive_crumb();
        if ( ! $crumb ) {
            return $links;
        }

        // Yoast format: [ 'text' => …, 'url' => … ]
        $yoast_crumb = [ 'text' => $crumb[0], 'url' => $crumb[1] ];
        array_splice( $links, 1, 0, [ $yoast_crumb ] );

        return $links;
    }

    /**
     * Build the [ label, url ] crumb from the configured archive page.
     * Resolves the translated version via Polylang if active.
     * Returns null if no archive page is configured.
     *
     * @return array{0: string, 1: string}|null
     */
    private function get_archive_crumb(): ?array {
        $page_id = (int) get_option( 'itpb_archive_page_id', 0 );

        if ( ! $page_id ) {
            return null;
        }

        // Polylang — resolve to the current language translation
        if ( function_exists( 'pll_get_post' ) && function_exists( 'pll_current_language' ) ) {
            $lang          = pll_current_language();
            $translated_id = $lang ? pll_get_post( $page_id, $lang ) : 0;
            // Fallback to original if no translation exists for this language
            $page_id       = $translated_id ?: $page_id;
        }

        $page = get_post( $page_id );

        if ( ! $page || $page->post_status !== 'publish' ) {
            return null;
        }

        return [ get_the_title( $page_id ), get_permalink( $page_id ) ];
    }

    // -----------------------------------------------------------------------
    // Guard — block direct access to it_answer before reveal
    // -----------------------------------------------------------------------

    /**
     * Redirect visitors away from it_answer single pages that are not yet public.
     * Admins can still preview.
     */
    public function guard_answer_direct_access(): void {
        if ( ! is_singular( ITPB_CPT_ANSWER ) ) {
            return;
        }

        $answer = get_queried_object();

        if ( ! $answer instanceof \WP_Post ) {
            return;
        }

        // Admins bypass the guard (allows preview)
        if ( current_user_can( 'edit_posts' ) ) {
            return;
        }

        // Published = accessible
        if ( $answer->post_status === 'publish' ) {
            return;
        }

        // Not yet revealed — redirect to parent problem or home
        $problem = itpb_get_problem_post( $answer->ID );
        $redirect = $problem ? get_permalink( $problem->ID ) : home_url( '/' );

        wp_safe_redirect( $redirect, 302 );
        exit;
    }
}
