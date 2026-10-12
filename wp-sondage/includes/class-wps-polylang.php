<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WPS_Polylang — Compatibilité Polylang & WPML.
 *
 * Stratégie :
 * 1. Lie le CPT wps_poll et la taxonomie wps_category à Polylang.
 * 2. Enregistre les chaînes traduisibles (questions + labels de réponses).
 * 3. Branche les filtres wps_poll_question et wps_choice_label pour que
 *    les rendus PHP ET les réponses AJAX soient toujours dans la bonne langue.
 * 4. Réenregistre automatiquement après chaque sauvegarde d'un sondage.
 * 5. Compatible WPML via icl_register_string / icl_t.
 */
class WPS_Polylang {

    private static bool $pll  = false;
    private static bool $wpml = false;

    public static function init() {
        self::$pll  = function_exists( 'pll_register_string' ) || defined( 'POLYLANG_VERSION' );
        self::$wpml = defined( 'ICL_SITEPRESS_VERSION' );

        if ( ! self::$pll && ! self::$wpml ) return; // rien à faire

        /* ── Liaison des types avec les plugins de traduction ── */
        if ( self::$pll ) {
            add_filter( 'pll_get_post_types',  [ __CLASS__, 'pll_add_post_type' ] );
            add_filter( 'pll_get_taxonomies',  [ __CLASS__, 'pll_add_taxonomy' ] );
            add_action( 'init',                [ __CLASS__, 'register_strings' ], 20 );
        }

        if ( self::$wpml ) {
            add_action( 'init', [ __CLASS__, 'register_strings' ], 20 );
        }

        /* ── Ré-enregistrement automatique à chaque sauvegarde ── */
        add_action( 'save_post_wps_poll', [ __CLASS__, 'register_poll_strings' ], 20, 1 );

        /* ── Filtres de traduction branchés sur les helpers globaux ── */
        add_filter( 'wps_poll_question',         [ __CLASS__, 'translate_question' ], 10, 2 );
        add_filter( 'wps_choice_label',          [ __CLASS__, 'translate_choice' ],   10, 2 );
        add_filter( 'wps_back_to_archive_label', [ __CLASS__, 'translate_back_label' ] );
        // Chaînes UI — chacune via son propre filtre
        $ui_filters = [
            'wps_btn_vote_label',  'wps_btn_see_results',  'wps_btn_see_detail',
            'wps_btn_back_vote',   'wps_notice_closed',    'wps_notice_voted',
            'wps_notice_private',  'wps_peek_notice',
        ];
        foreach ( $ui_filters as $filter_key ) {
            // Utiliser une méthode statique nommée plutôt qu'une closure
            // pour éviter les problèmes de parsing sur certaines versions PHP
            add_filter( $filter_key, function( $str ) use ( $filter_key ) {
                return WPS_Polylang::translate_ui( $filter_key, $str );
            } );
        }

        /* ── Traduction des labels dans les données AJAX de résultats ── */
        add_filter( 'wps_results_data', [ __CLASS__, 'translate_results_data' ], 10, 1 );
    }

    /* ════════════════════════════════════════
       POLYLANG — Liaison CPT / Taxonomie
    ════════════════════════════════════════ */

    public static function pll_add_post_type( array $types ): array {
        $types['wps_poll'] = 'wps_poll';
        return $types;
    }

    public static function pll_add_taxonomy( array $taxos ): array {
        $taxos['wps_category'] = 'wps_category';
        return $taxos;
    }

    /* ════════════════════════════════════════
       ENREGISTREMENT DES CHAÎNES
    ════════════════════════════════════════ */

    /** Enregistre les chaînes de l'UI statique dans Polylang */
    public static function register_strings() {
        if ( ! function_exists( 'pll_register_string' ) ) return;

        // Chaînes de l'interface utilisateur — toutes traduisibles via Polylang Strings
        $ui_strings = [
            'wps_back_to_archive_label' => __( '← Tous les sondages',   'wp-sondage' ),
            'wps_btn_vote_label'        => __( 'Voter',                  'wp-sondage' ),
            'wps_btn_see_results'       => __( 'Voir les résultats',     'wp-sondage' ),
            'wps_btn_see_detail'        => __( 'Voir le détail →',       'wp-sondage' ),
            'wps_btn_back_vote'         => __( '← Revenir au vote',      'wp-sondage' ),
            'wps_notice_closed'         => __( '🔒 Ce sondage est maintenant fermé.', 'wp-sondage' ),
            'wps_notice_voted'          => __( '✅ Vous avez déjà répondu à ce sondage.', 'wp-sondage' ),
            'wps_notice_private'        => __( '🔒 Les résultats de ce sondage sont privés.', 'wp-sondage' ),
            'wps_peek_notice'           => __( "Aperçu des résultats — votre vote n'a pas encore été comptabilisé.", 'wp-sondage' ),
        ];
        foreach ( $ui_strings as $name => $value ) {
            pll_register_string( $name, $value, 'WP Sondage — Interface' );
        }

        $ids = get_posts( [
            'post_type'      => 'wps_poll',
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ] );
        foreach ( $ids as $id ) {
            self::register_poll_strings( $id );
        }
    }

    /** Enregistre les chaînes d'un seul sondage. */
    public static function register_poll_strings( int $poll_id ) {
        $question = (string) get_post_meta( $poll_id, '_wps_question', true );
        if ( $question ) {
            self::reg_string( 'wps_question_' . $poll_id, $question, 'WP Sondage — Questions' );
        }

        global $wpdb;
        $choices = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, label FROM {$wpdb->prefix}wps_choices WHERE poll_id = %d",
            $poll_id
        ) );
        foreach ( $choices as $c ) {
            self::reg_string( 'wps_choice_' . $c->id, $c->label, 'WP Sondage — Réponses' );
        }
    }

    private static function reg_string( string $name, string $value, string $context ) {
        if ( self::$pll && function_exists( 'pll_register_string' ) ) {
            pll_register_string( $name, $value, $context );
        }
        if ( self::$wpml && function_exists( 'icl_register_string' ) ) {
            icl_register_string( 'WP Sondage', $name, $value );
        }
    }

    /* ════════════════════════════════════════
       TRADUCTION — filtres wps_*
    ════════════════════════════════════════ */

    public static function translate_question( string $question, int $poll_id ): string {
        return self::t( 'wps_question_' . $poll_id, $question );
    }

    public static function translate_choice( string $label, int $choice_id ): string {
        return self::t( 'wps_choice_' . $choice_id, $label );
    }

    public static function translate_back_label( string $label ): string {
        return self::t( 'wps_back_to_archive_label', $label );
    }

    /**
     * Traduit une chaîne UI générique identifiée par sa clé.
     * Appelé via les filtres wps_btn_*, wps_notice_*, etc.
     */
    public static function translate_ui( string $key, string $fallback ): string {
        return self::t( $key, $fallback );
    }

    /**
     * Traduit les labels de choix dans le tableau de données AJAX.
     * Branché sur le filtre wps_results_data dans WPS_Ajax::get_results_data().
     */
    public static function translate_results_data( array $data ): array {
        foreach ( $data['choices'] as &$c ) {
            $c['label'] = self::t( 'wps_choice_' . $c['id'], $c['label'] );
        }
        return $data;
    }

    private static function t( string $key, string $fallback ): string {
        if ( self::$pll && function_exists( 'pll__' ) ) {
            $translated = pll__( $key );
            if ( $translated && $translated !== $key ) return $translated;
        }
        if ( self::$wpml && function_exists( 'icl_t' ) ) {
            $translated = icl_t( 'WP Sondage', $key, $fallback );
            if ( $translated ) return $translated;
        }
        return $fallback;
    }
}
