<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Antiflood RGPD-compatible : stockage côté serveur uniquement.
 * Clé = hash(SHA-256, IP + User-Agent + salt) — aucune donnée personnelle en clair.
 */
class WPS_Antiflood {

    /**
     * Vérifie si le visiteur a déjà voté pour ce sondage.
     * Vérifie sur le poll_id reçu ET sur tous les IDs du groupe de traduction
     * (synchro multilingue : un visiteur qui vote en FR ne doit pas revoteR en EN).
     */
    public static function has_voted( int $poll_id ): bool {
        global $wpdb;
        $table   = $wpdb->prefix . 'wps_votes';
        $ip_hash = self::get_ip_hash();
        $ua_hash = self::get_ua_hash();
        $minutes = (int) get_option( 'wps_antiflood_minutes', 60 );
        $since   = date( 'Y-m-d H:i:s', time() - $minutes * 60 );

        // Inclure le sondage maître et toutes ses traductions dans la vérif
        $ids = [ $poll_id ];
        if ( class_exists( 'WPS_Sync' ) ) {
            $master = WPS_Sync::get_master_poll_id( $poll_id );
            $ids    = WPS_Sync::get_translation_group( $master );
            if ( ! in_array( $poll_id, $ids ) ) $ids[] = $poll_id;
        }

        $ids_ph = implode( ',', array_map( 'intval', $ids ) );
        $count  = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE poll_id IN ($ids_ph) AND ip_hash = %s AND ua_hash = %s AND voted_at > %s",
            $ip_hash, $ua_hash, $since
        ) );

        return (int) $count > 0;
    }

    /**
     * Enregistre uniquement l'entrée antiflood (pas le vote DB complet).
     * L'insertion DB réelle du vote est gérée dans WPS_Ajax::vote()
     * pour pouvoir y ajouter la colonne lang et le poll_id maître.
     *
     * Note : on stocke ici le poll_id ORIGINAL (pas le maître) pour que
     * la vérification has_voted() retrouve bien le vote par langue.
     */
    public static function record_vote( int $poll_id, array $choice_ids ) {
        // Le vrai insert est fait dans WPS_Ajax::vote() avec lang + master_id.
        // Cette méthode reste pour compatibilité mais n'insère plus.
        // Le has_voted() lit la table wps_votes qui contient désormais le master_id.
    }

    /* ── Helpers publics (utilisés par WPS_Ajax::vote) ── */

    public static function get_ip_hash(): string {
        return hash( 'sha256', self::get_ip() . wp_salt( 'auth' ) );
    }

    public static function get_ua_hash(): string {
        return hash( 'sha256', ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) . wp_salt( 'secure_auth' ) );
    }

    private static function get_ip(): string {
        $keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
        foreach ( $keys as $k ) {
            if ( ! empty( $_SERVER[ $k ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $k ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
            }
        }
        return '0.0.0.0';
    }

    /**
     * Détecte les bots / crawlers.
     */
    public static function is_bot(): bool {
        $ua = strtolower( $_SERVER['HTTP_USER_AGENT'] ?? '' );
        if ( empty( $ua ) ) return true;
        $bots = [ 'googlebot','bingbot','slurp','duckduckbot','baidu','yandex',
                  'sogou','exabot','facebot','ia_archiver','python-requests',
                  'wget','curl','scrapy','spider','crawler','bot','headless' ];
        foreach ( $bots as $b ) {
            if ( strpos( $ua, $b ) !== false ) return true;
        }
        return false;
    }
}
