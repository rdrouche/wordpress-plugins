<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WPS_Sync — Synchronisation multilingue des votes + Snapshots
 *
 * SYNCHRONISATION VOTES :
 * Polylang lie les sondages traduits en groupes. Quand un visiteur vote
 * sur /en/survey/my-poll, ses votes sont redirigés vers le sondage maître
 * (version originale du groupe). Les choix sont mappés par sort_order
 * identique dans toutes les traductions → résultats centralisés.
 *
 * Condition : les traductions doivent avoir les réponses dans le MÊME ordre
 * (sort_order identique). C'est l'admin qui en est responsable.
 *
 * SNAPSHOTS :
 * Prennent une photo des résultats à un instant T pour comparaison temporelle.
 * Déclencheurs : manuel, fermeture automatique (date fin), clôture manuelle.
 */
class WPS_Sync {

    public static function init() {
        // Snapshot automatique à la clôture (fin de date ou fermeture manuelle via save_post)
        add_action( 'save_post_wps_poll',         [ __CLASS__, 'maybe_snapshot_on_close' ], 20, 2 );
        // Snapshot automatique quand la date de fin est dépassée (vérifié au chargement front)
        add_action( 'wps_poll_expired',            [ __CLASS__, 'snapshot_on_expire' ] );
        // AJAX
        add_action( 'wp_ajax_wps_snapshot_create', [ __CLASS__, 'ajax_create_snapshot' ] );
        add_action( 'wp_ajax_wps_snapshot_delete', [ __CLASS__, 'ajax_delete_snapshot' ] );
    }

    /* ════════════════════════════════════════
       SONDAGE MAÎTRE (Polylang)
    ════════════════════════════════════════ */

    /**
     * Trouve le poll_id maître pour un sondage donné.
     * Le maître est le sondage dans la langue "par défaut" Polylang,
     * ou le plus ancien du groupe si pas de langue par défaut.
     * Retourne $poll_id lui-même si pas de Polylang.
     */
    public static function get_master_poll_id( int $poll_id ): int {
        if ( ! function_exists( 'pll_get_post_translations' ) ) {
            return $poll_id;
        }

        // Option : l'admin peut désactiver la synchro pour ce sondage
        if ( get_post_meta( $poll_id, '_wps_sync_disabled', true ) === '1' ) {
            return $poll_id;
        }

        $translations = pll_get_post_translations( $poll_id ); // ['fr'=>123, 'en'=>456]
        if ( count( $translations ) <= 1 ) {
            return $poll_id;
        }

        // Préférence : langue par défaut Polylang
        if ( function_exists( 'pll_default_language' ) ) {
            $default_lang = pll_default_language();
            if ( isset( $translations[ $default_lang ] ) ) {
                return (int) $translations[ $default_lang ];
            }
        }

        // Fallback : le plus ancien (ID le plus petit)
        return (int) min( array_values( $translations ) );
    }

    /**
     * Obtient tous les poll_id du groupe de traduction.
     */
    public static function get_translation_group( int $poll_id ): array {
        if ( ! function_exists( 'pll_get_post_translations' ) ) {
            return [ $poll_id ];
        }
        $translations = pll_get_post_translations( $poll_id );
        return array_values( array_map( 'intval', $translations ) );
    }

    /**
     * Mappe les choice_ids d'un sondage traduit vers les choice_ids du maître.
     * Correspondance par sort_order (les admins doivent garder le même ordre).
     *
     * @param array $choice_ids  IDs des choix du sondage courant
     * @param int   $from_poll   ID du sondage courant
     * @param int   $master_poll ID du sondage maître
     * @return array IDs des choix correspondants dans le sondage maître
     */
    public static function map_choices_to_master( array $choice_ids, int $from_poll, int $master_poll ): array {
        if ( $from_poll === $master_poll ) return $choice_ids;

        global $wpdb;
        $table = $wpdb->prefix . 'wps_choices';

        // Choix du sondage courant (avec leur sort_order)
        $from_choices = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, sort_order, parent_id FROM $table WHERE poll_id = %d ORDER BY parent_id, sort_order",
            $from_poll
        ) );
        // Choix du maître
        $master_choices = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, sort_order, parent_id FROM $table WHERE poll_id = %d ORDER BY parent_id, sort_order",
            $master_poll
        ) );

        // Map : sort_order → id pour les choix du maître
        $master_map = [];
        foreach ( $master_choices as $mc ) {
            // Clé composite sort_order:parent_sort_order pour tenir compte de l'arbo
            $parent_order = 0;
            if ( $mc->parent_id ) {
                foreach ( $master_choices as $p ) {
                    if ( $p->id == $mc->parent_id ) { $parent_order = $p->sort_order; break; }
                }
            }
            $key = $parent_order . ':' . $mc->sort_order;
            $master_map[ $key ] = (int) $mc->id;
        }

        // Map inverse : id → clé pour les choix du sondage courant
        $from_map = [];
        foreach ( $from_choices as $fc ) {
            $parent_order = 0;
            if ( $fc->parent_id ) {
                foreach ( $from_choices as $p ) {
                    if ( $p->id == $fc->parent_id ) { $parent_order = $p->sort_order; break; }
                }
            }
            $key = $parent_order . ':' . $fc->sort_order;
            $from_map[ (int) $fc->id ] = $key;
        }

        // Traduction
        $mapped = [];
        foreach ( $choice_ids as $cid ) {
            $cid = (int) $cid;
            $key = $from_map[ $cid ] ?? null;
            if ( $key && isset( $master_map[ $key ] ) ) {
                $mapped[] = $master_map[ $key ];
            } else {
                // Fallback : garder l'ID original (sondage non synchronisable)
                $mapped[] = $cid;
            }
        }

        return array_unique( $mapped );
    }

    /**
     * Langue courante (pour stocker dans la colonne lang du vote).
     */
    public static function current_lang(): string {
        if ( function_exists( 'pll_current_language' ) ) {
            return (string) pll_current_language();
        }
        return '';
    }

    /* ════════════════════════════════════════
       RÉSULTATS AGRÉGÉS (toutes langues)
    ════════════════════════════════════════ */

    /**
     * Calcule les résultats pour un sondage en agrégeant toutes les traductions.
     * Retourne les résultats par langue ET le total global.
     */
    public static function get_results_multilang( int $poll_id ): array {
        $master_id   = self::get_master_poll_id( $poll_id );
        $group       = self::get_translation_group( $master_id );
        $synced      = count( $group ) > 1 && ! get_post_meta( $master_id, '_wps_sync_disabled', true );

        // Résultats globaux (sondage maître = tous les votes centralisés)
        $global = WPS_Ajax::get_results_data( $master_id );

        if ( ! $synced || ! function_exists( 'pll_get_post_translations' ) ) {
            return [
                'global'  => $global,
                'by_lang' => [],
                'synced'  => false,
            ];
        }

        // Résultats par langue
        $by_lang     = [];
        $translations = pll_get_post_translations( $master_id ); // ['fr'=>123,'en'=>456]

        foreach ( $translations as $lang => $pid ) {
            global $wpdb;
            $vt = $wpdb->prefix . 'wps_votes';

            // Votes dans cette langue uniquement
            $vote_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM $vt WHERE poll_id = %d AND lang = %s",
                $master_id, $lang
            ) );

            $by_lang[ $lang ] = [
                'poll_id'    => $pid,
                'lang'       => $lang,
                'vote_count' => $vote_count,
                'percent'    => $global['total'] > 0
                    ? round( $vote_count / $global['total'] * 100, 1 )
                    : 0,
            ];
        }

        return [
            'global'  => $global,
            'by_lang' => $by_lang,
            'synced'  => true,
            'master'  => $master_id,
            'group'   => $group,
        ];
    }

    /* ════════════════════════════════════════
       SNAPSHOTS
    ════════════════════════════════════════ */

    /**
     * Crée un snapshot des résultats actuels.
     *
     * @param int    $poll_id      ID du sondage (maître de préférence)
     * @param string $label        Libellé du snapshot
     * @param string $trigger_type 'manual', 'auto_close', 'date_expire', 'reset_before'
     */
    public static function create_snapshot( int $poll_id, string $label = '', string $trigger_type = 'manual' ): int {
        global $wpdb;

        $master_id = self::get_master_poll_id( $poll_id );
        $results   = WPS_Ajax::get_results_data( $master_id );

        if ( ! $label ) {
            $label = sprintf(
                __( 'Snapshot %s', 'wp-sondage' ),
                wp_date( 'd/m/Y H:i' )
            );
        }

        $wpdb->insert(
            $wpdb->prefix . 'wps_snapshots',
            [
                'poll_id'      => $master_id,
                'label'        => substr( $label, 0, 255 ),
                'snapshot_at'  => current_time( 'mysql' ),
                'trigger_type' => $trigger_type,
                'total_votes'  => $results['total'],
                'choices_json' => wp_json_encode( $results['choices'] ),
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%s' ]
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Récupère les snapshots d'un sondage.
     */
    public static function get_snapshots( int $poll_id ): array {
        global $wpdb;
        $master_id = self::get_master_poll_id( $poll_id );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wps_snapshots
             WHERE poll_id = %d ORDER BY snapshot_at DESC",
            $master_id
        ) ) ?: [];
    }

    /**
     * Supprime un snapshot.
     */
    public static function delete_snapshot( int $snapshot_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            $wpdb->prefix . 'wps_snapshots',
            [ 'id' => $snapshot_id ],
            [ '%d' ]
        );
    }

    /**
     * Snapshot automatique quand un sondage est clôturé manuellement.
     */
    public static function maybe_snapshot_on_close( int $post_id, \WP_Post $post ) {
        if ( $post->post_status !== 'publish' ) return;

        $closed_before = get_post_meta( $post_id, '_wps_closed_snapshot_taken', true );
        $is_closed     = get_post_meta( $post_id, '_wps_closed', true ) === '1';

        if ( $is_closed && ! $closed_before ) {
            self::create_snapshot( $post_id,
                __( 'Clôture manuelle', 'wp-sondage' ),
                'auto_close'
            );
            update_post_meta( $post_id, '_wps_closed_snapshot_taken', '1' );
        }

        // Reset le flag si le sondage est rouvert
        if ( ! $is_closed ) {
            delete_post_meta( $post_id, '_wps_closed_snapshot_taken' );
        }
    }

    /**
     * Snapshot automatique à l'expiration de la date de fin.
     * Appelé via do_action('wps_poll_expired', $poll_id) dans poll_is_open().
     */
    public static function snapshot_on_expire( int $poll_id ) {
        $taken = get_post_meta( $poll_id, '_wps_expire_snapshot_taken', true );
        if ( $taken ) return;

        self::create_snapshot( $poll_id,
            __( 'Expiration automatique', 'wp-sondage' ),
            'date_expire'
        );
        update_post_meta( $poll_id, '_wps_expire_snapshot_taken', '1' );
    }

    /* ════════════════════════════════════════
       AJAX handlers
    ════════════════════════════════════════ */

    public static function ajax_create_snapshot() {
        check_ajax_referer( 'wps_stats_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $poll_id = (int) ( $_POST['poll_id'] ?? 0 );
        $label   = sanitize_text_field( $_POST['label'] ?? '' );

        if ( ! $poll_id ) wp_send_json_error( [ 'message' => __( 'Sondage invalide.', 'wp-sondage' ) ] );

        $snap_id = self::create_snapshot( $poll_id, $label, 'manual' );

        wp_send_json_success( [
            'snapshot_id' => $snap_id,
            'message'     => __( 'Snapshot créé avec succès.', 'wp-sondage' ),
        ] );
    }

    public static function ajax_delete_snapshot() {
        check_ajax_referer( 'wps_stats_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error();

        $snap_id = (int) ( $_POST['snapshot_id'] ?? 0 );
        if ( ! $snap_id ) wp_send_json_error();

        self::delete_snapshot( $snap_id );
        wp_send_json_success( [ 'message' => __( 'Snapshot supprimé.', 'wp-sondage' ) ] );
    }
}
