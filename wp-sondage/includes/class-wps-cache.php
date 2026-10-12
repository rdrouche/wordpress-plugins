<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WPS_Cache — Compatibilité avec les caches de page (LiteSpeed, WP Rocket,
 * W3TC, WP Super Cache) et les CDN (Cloudflare, Fastly).
 *
 * PROBLÈME RÉSOLU :
 * Les nonces WordPress ont une durée de vie de 12-24h. Quand ils sont
 * écrits dans le HTML et que ce HTML est mis en cache, ils survivent à
 * leur propre expiration → toutes les requêtes AJAX échouent en 403
 * jusqu'à ce qu'on purge le cache manuellement.
 *
 * STRATÉGIE :
 * 1. Les endpoints en LECTURE SEULE (chargement de sondage, liste, aperçu)
 *    n'exigent aucun nonce — ce sont des données publiques, sans risque CSRF.
 * 2. Ces endpoints renvoient un nonce FRAIS dans leur réponse JSON.
 * 3. Les endpoints en ÉCRITURE (vote, reset) exigent ce nonce frais,
 *    jamais celui du HTML caché.
 * 4. Toutes les réponses AJAX portent des en-têtes no-store.
 */
class WPS_Cache {

    public static function init() {
        // En-têtes anti-cache sur toutes les réponses AJAX du plugin
        add_action( 'wp_ajax_nopriv_wps_vote',           [ __CLASS__, 'nocache_headers' ], 0 );
        add_action( 'wp_ajax_wps_vote',                  [ __CLASS__, 'nocache_headers' ], 0 );
        add_action( 'wp_ajax_nopriv_wps_load_poll',      [ __CLASS__, 'nocache_headers' ], 0 );
        add_action( 'wp_ajax_wps_load_poll',             [ __CLASS__, 'nocache_headers' ], 0 );
        add_action( 'wp_ajax_nopriv_wps_archive_load',   [ __CLASS__, 'nocache_headers' ], 0 );
        add_action( 'wp_ajax_wps_archive_load',          [ __CLASS__, 'nocache_headers' ], 0 );
        add_action( 'wp_ajax_nopriv_wps_peek_results',   [ __CLASS__, 'nocache_headers' ], 0 );
        add_action( 'wp_ajax_wps_peek_results',          [ __CLASS__, 'nocache_headers' ], 0 );
        add_action( 'wp_ajax_nopriv_wps_poll_state',     [ __CLASS__, 'nocache_headers' ], 0 );
        add_action( 'wp_ajax_wps_poll_state',            [ __CLASS__, 'nocache_headers' ], 0 );

        // Ne jamais mettre en cache admin-ajax.php (LiteSpeed / WP Rocket / W3TC)
        add_action( 'init', [ __CLASS__, 'exclude_ajax_from_cache' ], 1 );
    }

    /**
     * Envoie des en-têtes empêchant toute mise en cache de la réponse,
     * côté navigateur, proxy, CDN et plugin de cache.
     */
    public static function nocache_headers() {
        if ( headers_sent() ) return;

        nocache_headers(); // WordPress core
        header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0, private' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        // Cloudflare : demander explicitement de ne pas mettre en cache
        header( 'CDN-Cache-Control: no-store' );
        header( 'Cloudflare-CDN-Cache-Control: no-store' );
        // LiteSpeed
        header( 'X-LiteSpeed-Cache-Control: no-cache' );

        self::set_donotcache_constants();
    }

    /**
     * Constantes reconnues par la plupart des plugins de cache
     * pour exclure la requête courante.
     */
    public static function set_donotcache_constants() {
        if ( ! defined( 'DONOTCACHEPAGE' ) )   define( 'DONOTCACHEPAGE', true );
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
        if ( ! defined( 'DONOTCACHEDB' ) )     define( 'DONOTCACHEDB', true );
        if ( ! defined( 'DONOTMINIFY' ) )      define( 'DONOTMINIFY', true );
    }

    /**
     * Exclut admin-ajax.php du cache dès le chargement.
     */
    public static function exclude_ajax_from_cache() {
        if ( ! wp_doing_ajax() ) return;
        $action = $_REQUEST['action'] ?? '';
        if ( strpos( (string) $action, 'wps_' ) !== 0 ) return;
        self::set_donotcache_constants();
        // LiteSpeed : API d'exclusion
        do_action( 'litespeed_control_set_nocache', 'WP Sondage AJAX' );
    }

    /**
     * Génère un nonce frais pour les actions d'écriture.
     * Toujours renvoyé dans les réponses AJAX en lecture seule,
     * jamais écrit dans du HTML susceptible d'être mis en cache.
     */
    public static function fresh_write_nonce(): string {
        return wp_create_nonce( 'wps_write_action' );
    }

    /**
     * Vérifie un nonce d'écriture. Renvoie true si valide.
     * Tolère aussi l'ancien nonce 'wps_nonce_front' pour rétro-compatibilité.
     */
    public static function verify_write_nonce( string $nonce ): bool {
        if ( wp_verify_nonce( $nonce, 'wps_write_action' ) ) return true;
        // Rétro-compatibilité avec les pages encore en cache
        if ( wp_verify_nonce( $nonce, 'wps_nonce_front' ) ) return true;
        return false;
    }

    /**
     * Rate-limit basique pour les endpoints sans nonce.
     * Empêche l'abus des endpoints publics en lecture seule.
     *
     * @param string $bucket Nom du seau (ex. 'load_poll')
     * @param int    $max    Requêtes max dans la fenêtre
     * @param int    $window Fenêtre en secondes
     */
    public static function rate_limit_ok( string $bucket, int $max = 60, int $window = 60 ): bool {
        $ip  = self::client_ip_hash();
        $key = 'wps_rl_' . $bucket . '_' . substr( $ip, 0, 16 );

        $count = (int) get_transient( $key );
        if ( $count >= $max ) return false;

        set_transient( $key, $count + 1, $window );
        return true;
    }

    private static function client_ip_hash(): string {
        $keys = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
        foreach ( $keys as $k ) {
            if ( ! empty( $_SERVER[ $k ] ) ) {
                $ip = trim( explode( ',', $_SERVER[ $k ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return hash( 'sha256', $ip . wp_salt( 'nonce' ) );
                }
            }
        }
        return hash( 'sha256', 'unknown' . wp_salt( 'nonce' ) );
    }

    /**
     * Détecte si un plugin de cache de page est actif.
     * Sert à afficher un avertissement en admin.
     */
    public static function detect_cache_plugin(): array {
        $found = [];
        if ( defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed\Core' ) ) $found[] = 'LiteSpeed Cache';
        if ( defined( 'WP_ROCKET_VERSION' ) )                           $found[] = 'WP Rocket';
        if ( defined( 'W3TC' ) )                                        $found[] = 'W3 Total Cache';
        if ( function_exists( 'wp_cache_clear_cache' ) )                $found[] = 'WP Super Cache';
        if ( class_exists( 'autoptimizeMain' ) )                        $found[] = 'Autoptimize';
        if ( ! empty( $_SERVER['HTTP_CF_RAY'] ) )                        $found[] = 'Cloudflare';
        return $found;
    }
}
