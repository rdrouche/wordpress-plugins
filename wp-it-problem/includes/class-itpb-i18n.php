<?php
/**
 * ITPB_I18n
 *
 * Loads the plugin text domain and registers static UI strings
 * for Polylang's string translations interface.
 *
 * All user-facing strings that live outside PHP templates
 * (JS-rendered labels, email subjects, shortcode defaults)
 * are registered here so translators find them in one place.
 *
 * Convention: every string key is prefixed with "itpb_" to avoid
 * collisions in Polylang's global string list.
 *
 * @package WP_IT_Problem
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class ITPB_I18n {

    /**
     * Hook registration — called from itpb_boot().
     */
    public function init(): void {
        add_action( 'init', [ $this, 'load_textdomain' ] );

        // Register Polylang strings after init so pll_register_string is available
        add_action( 'init', [ $this, 'register_pll_strings' ], 20 );
    }

    // -----------------------------------------------------------------------
    // Text domain
    // -----------------------------------------------------------------------

    /**
     * Load the plugin text domain.
     * MO files live in /languages/wp-it-problem-{locale}.mo
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'wp-it-problem',
            false,
            dirname( ITPB_BASENAME ) . '/languages'
        );
    }

    // -----------------------------------------------------------------------
    // Polylang string registration
    // -----------------------------------------------------------------------

    /**
     * Register all static strings that Polylang should make translatable
     * in Appearance → Languages → String translations.
     *
     * Only runs when pll_register_string() is available (Polylang active).
     */
    public function register_pll_strings(): void {
        if ( ! function_exists( 'pll_register_string' ) ) {
            return;
        }

        $group = 'WP IT Problem';

        foreach ( self::get_registered_strings() as $key => $default ) {
            pll_register_string( $key, $default, $group );
        }
    }

    /**
     * Central list of all static translatable strings.
     *
     * Keys are used as the first argument to pll_register_string()
     * and as lookup keys in itpb_str().
     *
     * @return array<string, string>
     */
    public static function get_registered_strings(): array {
        return [
            // Countdown block
            'itpb_countdown_title'        => 'Answer coming in',
            'itpb_countdown_days'         => 'days',
            'itpb_countdown_hours'        => 'hours',
            'itpb_countdown_minutes'      => 'minutes',
            'itpb_countdown_seconds'      => 'seconds',
            'itpb_countdown_revealed'     => 'The answer is now available.',
            'itpb_countdown_loading'      => 'Loading answer\u2026',

            // Answer block
            'itpb_answer_title'           => 'Answer',
            'itpb_answer_no_answer'       => 'No answer has been published yet.',

            // Problem meta
            'itpb_meta_infra_label'       => 'Infrastructure',
            'itpb_meta_difficulty_label'  => 'Difficulty',
            'itpb_meta_reveal_label'      => 'Reveal date',

            // Difficulty levels
            'itpb_difficulty_beginner'    => 'Beginner',
            'itpb_difficulty_intermediate'=> 'Intermediate',
            'itpb_difficulty_expert'      => 'Expert',

            // Shortcode [itpb_list]
            'itpb_list_revealed_badge'    => 'Answered',
            'itpb_list_pending_badge'     => 'Pending',
            'itpb_list_no_items'          => 'No problems found.',

            // Admin notices
            'itpb_admin_answer_created'   => 'Answer post created and linked.',
            'itpb_admin_db_updated'       => 'Database updated successfully.',
        ];
    }
}

// ---------------------------------------------------------------------------
// Global helper — used throughout the codebase instead of __()
// Falls back to pll__() when Polylang is active, otherwise uses __().
// ---------------------------------------------------------------------------

/**
 * Translate an ITPB registered string.
 *
 * Usage:  itpb_str( 'itpb_countdown_days' )
 *
 * @param  string $key     Key from ITPB_I18n::get_registered_strings().
 * @param  string $default Fallback if key not found.
 * @return string
 */
function itpb_str( string $key, string $default = '' ): string {
    $strings = ITPB_I18n::get_registered_strings();
    $value   = $strings[ $key ] ?? $default;

    if ( function_exists( 'pll__' ) ) {
        return pll__( $value );
    }

    // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
    return __( $value, 'wp-it-problem' );
}

/**
 * Echo-wrapper for itpb_str().
 *
 * @param  string $key
 * @param  string $default
 */
function itpb_e( string $key, string $default = '' ): void {
    echo esc_html( itpb_str( $key, $default ) );
}
