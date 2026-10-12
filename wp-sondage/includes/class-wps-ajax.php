<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPS_Ajax {

    public static function init() {
        // Vote
        add_action( 'wp_ajax_wps_vote',        [ __CLASS__, 'vote' ] );
        add_action( 'wp_ajax_nopriv_wps_vote', [ __CLASS__, 'vote' ] );
        // Lazy-load d'un widget
        add_action( 'wp_ajax_wps_load_poll',        [ __CLASS__, 'load_poll' ] );
        add_action( 'wp_ajax_nopriv_wps_load_poll', [ __CLASS__, 'load_poll' ] );
        // Résultats live
        add_action( 'wp_ajax_wps_results',        [ __CLASS__, 'results' ] );
        add_action( 'wp_ajax_nopriv_wps_results', [ __CLASS__, 'results' ] );
        // Reset (admin uniquement)
        add_action( 'wp_ajax_wps_reset_poll', [ __CLASS__, 'reset_poll' ] );
        // Aperçu résultats sans voter
        add_action( 'wp_ajax_wps_peek_results',        [ __CLASS__, 'peek_results' ] );
        add_action( 'wp_ajax_nopriv_wps_peek_results', [ __CLASS__, 'peek_results' ] );
        // État personnel du visiteur (a-t-il voté ?) — jamais rendu en HTML caché
        add_action( 'wp_ajax_wps_poll_state',        [ __CLASS__, 'poll_state' ] );
        add_action( 'wp_ajax_nopriv_wps_poll_state', [ __CLASS__, 'poll_state' ] );
    }

    /* ── Vote ── */
    public static function vote() {
        // Nonce d'écriture : accepte le nonce frais servi par AJAX
        // ET l'ancien nonce du HTML (rétro-compat pages en cache)
        $nonce = sanitize_text_field( $_POST['nonce'] ?? '' );
        if ( ! WPS_Cache::verify_write_nonce( $nonce ) ) {
            wp_send_json_error( [
                'code'    => 'stale_nonce',
                'message' => __( 'Session expirée, rechargez la page.', 'wp-sondage' ),
            ] );
        }

        $poll_id    = (int) ( $_POST['poll_id'] ?? 0 );
        $choice_ids = array_map( 'intval', (array) ( $_POST['choice_ids'] ?? [] ) );

        if ( ! $poll_id || empty( $choice_ids ) ) {
            wp_send_json_error( [ 'message' => __( 'Données invalides.', 'wp-sondage' ) ] );
        }

        // Vérif sondage actif
        if ( ! self::poll_is_open( $poll_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Ce sondage est fermé.', 'wp-sondage' ) ] );
        }

        // Antiflood
        if ( WPS_Antiflood::has_voted( $poll_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Vous avez déjà répondu à ce sondage.', 'wp-sondage' ) ] );
        }

        // Vérif que les choix appartiennent bien à ce sondage
        global $wpdb;
        $table     = $wpdb->prefix . 'wps_choices';
        $valid_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $table WHERE poll_id = %d", $poll_id
        ) );
        $choice_ids = array_intersect( $choice_ids, array_map( 'intval', $valid_ids ) );

        if ( empty( $choice_ids ) ) {
            wp_send_json_error( [ 'message' => __( 'Choix invalide.', 'wp-sondage' ) ] );
        }

        // ── Synchronisation multilingue ──
        // On redirige le vote vers le sondage maître et on mappe les choix
        $master_id  = class_exists( 'WPS_Sync' ) ? WPS_Sync::get_master_poll_id( $poll_id ) : $poll_id;
        $lang       = class_exists( 'WPS_Sync' ) ? WPS_Sync::current_lang() : '';

        if ( $master_id !== $poll_id && class_exists( 'WPS_Sync' ) ) {
            // Mapper les IDs de choix du sondage courant vers ceux du maître
            $choice_ids = WPS_Sync::map_choices_to_master( $choice_ids, $poll_id, $master_id );
        }

        WPS_Antiflood::record_vote( $poll_id, $choice_ids );

        // Insérer le vote sur le sondage maître avec la langue
        $wpdb->insert(
            $wpdb->prefix . 'wps_votes',
            [
                'poll_id'    => $master_id,
                'choice_ids' => implode( ',', array_map( 'intval', $choice_ids ) ),
                'ip_hash'    => WPS_Antiflood::get_ip_hash(),
                'ua_hash'    => WPS_Antiflood::get_ua_hash(),
                'lang'       => $lang,
                'voted_at'   => current_time( 'mysql' ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        wp_send_json_success( [
            'message'   => __( 'Merci pour votre réponse !', 'wp-sondage' ),
            'results'   => self::get_results_data( $master_id ),
            'public'    => get_post_meta( $poll_id, '_wps_results', true ) === 'public',
            'can_reset' => current_user_can( 'manage_options' ),
        ] );
    }

    /* ── Lazy load d'un widget shortcode ── */
    /**
     * Chargement d'un sondage (lecture seule, données publiques).
     * AUCUN nonce requis : le nonce du HTML caché serait périmé.
     * Protection par rate-limit à la place.
     * Renvoie un nonce d'écriture FRAIS pour le vote.
     */
    public static function load_poll() {
        if ( ! WPS_Cache::rate_limit_ok( 'load_poll', 120, 60 ) ) {
            wp_send_json_error( [ 'code' => 'rate_limit' ] );
        }

        // Bots → réponse vide (côté AJAX uniquement, pas au rendu)
        if ( WPS_Antiflood::is_bot() ) {
            wp_send_json_success( [ 'html' => '', 'nonce' => '' ] );
        }

        $poll_id = (int) ( $_POST['poll_id'] ?? 0 );

        // Mode aléatoire : le serveur reçoit une liste de candidats et choisit
        // le premier que CE visiteur n'a pas encore rempli. Ce filtrage ne peut
        // pas se faire au rendu (il serait figé dans le cache de page).
        if ( ! $poll_id && ! empty( $_POST['candidates'] ) ) {
            $candidates = array_filter( array_map( 'intval',
                explode( ',', sanitize_text_field( $_POST['candidates'] ) )
            ) );
            shuffle( $candidates );
            foreach ( $candidates as $cid ) {
                if ( ! self::poll_is_open( $cid ) )      continue;
                if ( WPS_Antiflood::has_voted( $cid ) )  continue;
                $poll_id = $cid;
                break;
            }
            // Tous déjà répondus → ne rien afficher
            if ( ! $poll_id ) {
                wp_send_json_success( [ 'html' => '', 'nonce' => WPS_Cache::fresh_write_nonce() ] );
            }
        }

        if ( ! $poll_id ) wp_send_json_error( [ 'code' => 'bad_id' ] );

        ob_start();
        WPS_Public::render_poll_widget( $poll_id );
        $html = ob_get_clean();

        wp_send_json_success( [
            'html'  => $html,
            'nonce' => WPS_Cache::fresh_write_nonce(),
        ] );
    }

    /**
     * État personnel du visiteur pour un sondage.
     * Permet au front de savoir s'il a déjà voté sans que cette
     * information soit figée dans le HTML mis en cache.
     */
    public static function poll_state() {
        if ( ! WPS_Cache::rate_limit_ok( 'poll_state', 120, 60 ) ) {
            wp_send_json_error( [ 'code' => 'rate_limit' ] );
        }

        $poll_id = (int) ( $_POST['poll_id'] ?? 0 );
        if ( ! $poll_id ) wp_send_json_error( [ 'code' => 'bad_id' ] );

        $results_ok = get_post_meta( $poll_id, '_wps_results', true ) === 'public';
        $is_open    = self::poll_is_open( $poll_id );
        $has_voted  = WPS_Antiflood::has_voted( $poll_id );

        $payload = [
            'has_voted'   => $has_voted,
            'is_open'     => $is_open,
            'results_ok'  => $results_ok,
            'can_reset'   => current_user_can( 'manage_options' ),
            'nonce'       => WPS_Cache::fresh_write_nonce(),
        ];

        // Joindre les résultats si le visiteur y a droit
        if ( $results_ok && ( $has_voted || ! $is_open ) ) {
            $master = class_exists( 'WPS_Sync' ) ? WPS_Sync::get_master_poll_id( $poll_id ) : $poll_id;
            $payload['results'] = self::get_results_data( $master );
        }

        wp_send_json_success( $payload );
    }

    /* ── Résultats bruts ── */
    public static function results() {
        if ( ! WPS_Cache::rate_limit_ok( 'results', 60, 60 ) ) {
            wp_send_json_error( [ 'code' => 'rate_limit' ] );
        }
        $poll_id = (int) ( $_POST['poll_id'] ?? 0 );
        if ( ! $poll_id ) wp_send_json_error();

        $public = get_post_meta( $poll_id, '_wps_results', true ) === 'public';
        if ( ! $public && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Résultats privés.', 'wp-sondage' ) ] );
        }

        wp_send_json_success( self::get_results_data( $poll_id ) );
    }

    /* ── Aperçu des résultats sans voter ── */
    public static function peek_results() {
        if ( ! WPS_Cache::rate_limit_ok( 'peek', 60, 60 ) ) {
            wp_send_json_error( [ 'code' => 'rate_limit' ] );
        }

        if ( WPS_Antiflood::is_bot() ) {
            wp_send_json_error( [ 'code' => 'bot' ] );
        }

        $poll_id = (int) ( $_POST['poll_id'] ?? 0 );
        if ( ! $poll_id ) wp_send_json_error( [ 'message' => __( 'Sondage invalide.', 'wp-sondage' ) ] );

        // Vérifier que les résultats sont publics
        if ( get_post_meta( $poll_id, '_wps_results', true ) !== 'public' ) {
            wp_send_json_error( [ 'message' => __( 'Résultats privés.', 'wp-sondage' ) ] );
        }

        // Vérifier que le sondage est ouvert (sinon les résultats s'affichent déjà normalement)
        if ( ! self::poll_is_open( $poll_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Ce sondage est fermé.', 'wp-sondage' ) ] );
        }

        $master = class_exists( 'WPS_Sync' ) ? WPS_Sync::get_master_poll_id( $poll_id ) : $poll_id;
        wp_send_json_success( [
            'results' => self::get_results_data( $master ),
            'is_peek' => true,
            'nonce'   => WPS_Cache::fresh_write_nonce(),
        ] );
    }

    /* ── Reset tous les votes d'un sondage (admin uniquement) ── */
    public static function reset_poll() {
        $nonce = sanitize_text_field( $_POST['nonce'] ?? '' );
        if ( ! WPS_Cache::verify_write_nonce( $nonce ) ) {
            wp_send_json_error( [ 'code' => 'stale_nonce', 'message' => __( 'Session expirée, rechargez la page.', 'wp-sondage' ) ] );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Permission refusée.', 'wp-sondage' ) ] );
        }

        $poll_id        = (int) ( $_POST['poll_id'] ?? 0 );
        $with_snapshot  = ! empty( $_POST['with_snapshot'] );
        $snapshot_label = sanitize_text_field( $_POST['snapshot_label'] ?? '' );

        if ( ! $poll_id ) wp_send_json_error( [ 'message' => __( 'Sondage invalide.', 'wp-sondage' ) ] );

        global $wpdb;
        $master_id = class_exists( 'WPS_Sync' ) ? WPS_Sync::get_master_poll_id( $poll_id ) : $poll_id;

        // Snapshot avant suppression si demandé
        $snap_id = null;
        if ( $with_snapshot && class_exists( 'WPS_Sync' ) ) {
            $label   = $snapshot_label ?: sprintf(
                __( 'Avant remise à zéro — %s', 'wp-sondage' ),
                wp_date( 'd/m/Y H:i' )
            );
            $snap_id = WPS_Sync::create_snapshot( $master_id, $label, 'reset_before' );

            // Réinitialiser les flags de snapshot automatique
            delete_post_meta( $master_id, '_wps_closed_snapshot_taken' );
            delete_post_meta( $master_id, '_wps_expire_snapshot_taken' );
        }

        // Supprimer les votes du sondage maître (et toutes ses traductions si synchro)
        $ids_to_delete = [ $master_id ];
        if ( class_exists( 'WPS_Sync' ) ) {
            $ids_to_delete = WPS_Sync::get_translation_group( $master_id );
        }
        $ids_ph = implode( ',', array_map( 'intval', $ids_to_delete ) );
        $deleted = $wpdb->query( "DELETE FROM {$wpdb->prefix}wps_votes WHERE poll_id IN ($ids_ph)" );

        if ( $deleted === false ) {
            wp_send_json_error( [ 'message' => __( 'Erreur lors de la réinitialisation.', 'wp-sondage' ) ] );
        }

        wp_send_json_success( [
            'message'     => sprintf( _n( '%d vote(s) supprimé(s).', '%d votes supprimés.', (int) $deleted, 'wp-sondage' ), (int) $deleted ),
            'snapshot_id' => $snap_id,
        ] );
    }

    /* ── Helpers ── */

    public static function poll_is_open( int $poll_id ): bool {
        $post = get_post( $poll_id );
        if ( ! $post || $post->post_status !== 'publish' ) return false;
        if ( get_post_meta( $poll_id, '_wps_active', true ) !== '1' ) return false;
        if ( get_post_meta( $poll_id, '_wps_closed', true ) === '1' ) return false;
        $end = get_post_meta( $poll_id, '_wps_end_date', true );
        if ( $end && strtotime( $end ) < time() ) {
            // Déclencher le snapshot d'expiration (une seule fois)
            do_action( 'wps_poll_expired', $poll_id );
            return false;
        }
        return true;
    }

    public static function get_results_data( int $poll_id ): array {
        global $wpdb;
        $choices_table = $wpdb->prefix . 'wps_choices';
        $votes_table   = $wpdb->prefix . 'wps_votes';

        $choices = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM $choices_table WHERE poll_id = %d ORDER BY parent_id, sort_order", $poll_id )
        );

        // Décompte des votes par choix
        $vote_rows = $wpdb->get_results(
            $wpdb->prepare( "SELECT choice_ids FROM $votes_table WHERE poll_id = %d", $poll_id )
        );

        // Compter les votes par choix ET le nombre total de votants
        $counts        = [];  // choice_id → nombre de votants ayant coché ce choix
        $voter_total   = count( $vote_rows ); // nombre de bulletins (votants uniques par sondage)

        foreach ( $vote_rows as $row ) {
            $voted_ids = array_map( 'intval', array_filter( explode( ',', $row->choice_ids ) ) );
            foreach ( $voted_ids as $cid ) {
                $counts[ $cid ] = ( $counts[ $cid ] ?? 0 ) + 1;
            }
        }

        // Compter les votants par choix parent (pour calculer le % des sous-choix)
        // Un votant "parent" = quelqu'un qui a coché au moins un enfant de ce parent
        $parent_voters = []; // parent_id → set de bulletins ayant voté un enfant

        // Construire la map parent → enfants
        $parent_map = []; // child_id → parent_id
        foreach ( $choices as $c ) {
            if ( $c->parent_id > 0 ) {
                $parent_map[ (int) $c->id ] = (int) $c->parent_id;
            }
        }

        foreach ( $vote_rows as $idx => $row ) {
            $voted_ids = array_map( 'intval', array_filter( explode( ',', $row->choice_ids ) ) );
            foreach ( $voted_ids as $cid ) {
                if ( isset( $parent_map[ $cid ] ) ) {
                    $pid = $parent_map[ $cid ];
                    if ( ! isset( $parent_voters[ $pid ] ) ) $parent_voters[ $pid ] = [];
                    $parent_voters[ $pid ][ $idx ] = true;
                }
            }
        }

        $total     = $voter_total;
        $poll_type = get_post_meta( $poll_id, '_wps_type', true ) ?: 'radio';

        $data = [];
        foreach ( $choices as $c ) {
            $cid = (int) $c->id;
            $pid = (int) $c->parent_id;
            $cnt = $counts[ $cid ] ?? 0;

            if ( $pid === 0 ) {
                // Choix top-level.
                // • radio   : cnt/total * 100 → somme = 100%
                // • checkbox : cnt/total * 100 → somme peut dépasser 100%,
                //              ce comportement est intentionnel et attendu.
                //              La barre représente la proportion de votants
                //              ayant coché ce choix, pas une fraction exclusive.
                $pct = $total > 0 ? round( $cnt / $total * 100, 1 ) : 0;
            } else {
                // Sous-choix : % sur les votants ayant répondu au parent.
                $parent_voter_count = isset( $parent_voters[ $pid ] )
                    ? count( $parent_voters[ $pid ] )
                    : ( $counts[ $pid ] ?? 0 );
                $pct = $parent_voter_count > 0 ? round( $cnt / $parent_voter_count * 100, 1 ) : 0;
            }

            $data[] = [
                'id'        => $cid,
                'parent_id' => $pid,
                'label'     => $c->label,
                'count'     => $cnt,
                'percent'   => $pct,
            ];
        }

        $result = [ 'choices' => $data, 'total' => $total, 'poll_type' => $poll_type ];

        /**
         * Filtre sur les données de résultats — utilisé par Polylang/WPML
         * pour traduire les labels de choix avant envoi AJAX.
         *
         * @param array $result { 'choices' => array, 'total' => int }
         */
        return (array) apply_filters( 'wps_results_data', $result );
    }
}
