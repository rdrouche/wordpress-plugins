<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPS_Shortcodes {

    public static function init() {
        add_shortcode( 'wps_poll',   [ __CLASS__, 'sc_poll' ] );
        add_shortcode( 'wps_random', [ __CLASS__, 'sc_random' ] );
    }

    /**
     * [wps_poll id="123"]
     * [wps_poll id="123" lazy="1"]
     */
    public static function sc_poll( $atts ) {
        // NOTE : pas de détection de bot ici.
        // Le rendu est mis en cache : si un bot déclenchait un rendu vide,
        // cette page vide serait servie à tous les visiteurs suivants.
        // Le filtrage des bots se fait côté AJAX (wps_load_poll), non caché.
        $atts = shortcode_atts( [ 'id' => 0, 'lazy' => '1' ], $atts, 'wps_poll' );
        $id   = (int) $atts['id'];
        if ( ! $id ) return '';

        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'wps_poll' || $post->post_status !== 'publish' ) return '';
        if ( get_post_meta( $id, '_wps_active', true ) !== '1' ) return '';

        if ( $atts['lazy'] === '1' ) {
            return self::lazy_placeholder( $id );
        }

        // lazy="0" : le sondage est rendu côté serveur AVEC l'état personnel
        // du visiteur. Cette page ne peut donc pas être mise en cache.
        WPS_Cache::set_donotcache_constants();

        ob_start();
        WPS_Public::render_poll_widget( $id );
        return ob_get_clean();
    }

    /**
     * [wps_random category="slug" lazy="1"]
     * Affiche un sondage ouvert au hasard que le visiteur n'a pas encore répondu.
     */
    public static function sc_random( $atts ) {
        // Filtrage bot déporté côté AJAX (voir sc_poll)
        $atts = shortcode_atts( [ 'category' => '', 'lazy' => '1' ], $atts, 'wps_random' );

        $args = [
            'post_type'      => 'wps_poll',
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'orderby'        => 'rand',
            'meta_query'     => [
                [ 'key' => '_wps_active', 'value' => '1' ],
                [ 'key' => '_wps_closed', 'value' => '0' ],
            ],
        ];

        if ( $atts['category'] ) {
            $args['tax_query'] = [ [
                'taxonomy' => 'wps_category',
                'field'    => 'slug',
                'terms'    => $atts['category'],
            ] ];
        }

        $polls = get_posts( $args );

        // Liste des candidats (sans tenir compte de has_voted : c'est un état
        // personnel qui ne doit jamais être figé dans le HTML mis en cache).
        $candidates = [];
        foreach ( $polls as $poll ) {
            $end = get_post_meta( $poll->ID, '_wps_end_date', true );
            if ( $end && strtotime( $end ) < time() ) continue;
            $candidates[] = (int) $poll->ID;
        }

        if ( ! $candidates ) return '';

        if ( $atts['lazy'] === '1' ) {
            // Le serveur envoie la liste des candidats, le JS demande à l'AJAX
            // lequel afficher (filtrage has_voted fait hors cache).
            return self::lazy_placeholder( 0, $candidates );
        }

        // Mode non-lazy : filtrage serveur → page non cacheable
        WPS_Cache::set_donotcache_constants();
        foreach ( $candidates as $cid ) {
            if ( WPS_Antiflood::has_voted( $cid ) ) continue;
            ob_start();
            WPS_Public::render_poll_widget( $cid );
            return ob_get_clean();
        }
        return '';
    }

    /**
     * Placeholder de chargement différé.
     * IMPORTANT : aucun nonce n'est écrit ici — il serait périmé une fois
     * la page mise en cache. Le nonce d'écriture est fourni par la réponse
     * AJAX de wps_load_poll, qui n'est jamais mise en cache.
     *
     * @param int   $poll_id    ID du sondage, ou 0 pour un tirage aléatoire
     * @param array $candidates IDs candidats (mode aléatoire)
     */
    private static function lazy_placeholder( int $poll_id, array $candidates = [] ): string {
        $attrs = 'data-poll-id="' . esc_attr( $poll_id ) . '"';
        if ( $candidates ) {
            $attrs .= ' data-candidates="' . esc_attr( implode( ',', $candidates ) ) . '"';
        }
        return sprintf(
            '<div class="wps-lazy-widget wps-theme-auto" %s role="status" aria-busy="true" aria-label="%s">
                <div class="wps-skeleton-inner" aria-hidden="true">
                    <div class="wps-skeleton-line wps-skeleton-line--title"></div>
                    <div class="wps-skeleton-line wps-skeleton-line--full"></div>
                    <div class="wps-skeleton-line wps-skeleton-line--med"></div>
                    <div class="wps-skeleton-line wps-skeleton-line--full"></div>
                    <div class="wps-skeleton-line wps-skeleton-line--short"></div>
                    <div class="wps-skeleton-btn"></div>
                </div>
            </div>',
            $attrs,
            esc_attr__( 'Chargement du sondage…', 'wp-sondage' )
        );
    }
}
