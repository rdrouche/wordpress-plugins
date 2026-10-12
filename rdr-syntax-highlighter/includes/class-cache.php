<?php
/**
 * RDR_SH_Cache — Système de cache pour la coloration syntaxique
 *
 * Stratégie à 2 niveaux :
 *  1. Transient WordPress (rapide, en base ou object-cache si Redis/Memcached)
 *  2. Fichier sur disque dans /wp-content/cache/rdr-syntax-highlighter/
 *     (survit aux vidages de transients, utile avec LiteSpeed)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RDR_SH_Cache {

    const CACHE_GROUP  = 'rdr_sh';
    const TRANSIENT_PF = 'rdr_sh_';  // Préfixe transient

    /* --------------------------------------------------------
       RÉPERTOIRE DE CACHE
       -------------------------------------------------------- */
    public static function maybe_create_cache_dir() {
        if ( ! file_exists( RDR_SH_CACHE_DIR ) ) {
            wp_mkdir_p( RDR_SH_CACHE_DIR );

            // Créer un .htaccess pour bloquer l'accès direct
            $htaccess = RDR_SH_CACHE_DIR . '.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( $htaccess, "Deny from all\n" );
            }

            // Créer un index.php vide
            $index = RDR_SH_CACHE_DIR . 'index.php';
            if ( ! file_exists( $index ) ) {
                file_put_contents( $index, "<?php // Silence is golden.\n" );
            }
        }
    }

    /* --------------------------------------------------------
       CLÉ DE CACHE
       -------------------------------------------------------- */
    public static function make_key( $code, $language, $line_numbers, $filename = ''  ) {
        return md5( $code . '|' . $language . '|' . ( $line_numbers ? '1' : '0' ) . '|' . $filename );
    }

    /* --------------------------------------------------------
       LIRE LE CACHE
       -------------------------------------------------------- */
    public static function get( $key ) {
        // 1. Transient
        $value = get_transient( self::TRANSIENT_PF . $key );
        if ( $value !== false ) {
            return $value;
        }

        // 2. Fichier disque
        $file = RDR_SH_CACHE_DIR . $key . '.html';
        if ( file_exists( $file ) ) {
            $options = get_option( 'rdr_sh_options', array() );
            $ttl     = isset( $options['cache_ttl'] ) ? (int) $options['cache_ttl'] : 604800;

            // Vérifier la fraîcheur du fichier
            if ( ( time() - filemtime( $file ) ) < $ttl ) {
                $html = file_get_contents( $file );
                if ( $html !== false ) {
                    // Re-stocker en transient pour les prochaines requêtes
                    set_transient( self::TRANSIENT_PF . $key, $html, $ttl );
                    return $html;
                }
            }
        }

        return false;
    }

    /* --------------------------------------------------------
       ÉCRIRE LE CACHE
       -------------------------------------------------------- */
    public static function set( $key, $html ) {
        $options = get_option( 'rdr_sh_options', array() );
        $ttl     = isset( $options['cache_ttl'] ) ? (int) $options['cache_ttl'] : 604800;

        // 1. Transient
        set_transient( self::TRANSIENT_PF . $key, $html, $ttl );

        // 2. Fichier disque
        self::maybe_create_cache_dir();
        $file = RDR_SH_CACHE_DIR . $key . '.html';
        file_put_contents( $file, $html, LOCK_EX );
    }

    /* --------------------------------------------------------
       VIDER LE CACHE
       -------------------------------------------------------- */

    /**
     * Vider tous les fichiers de cache disque
     */
    public static function flush_all() {
        if ( ! is_dir( RDR_SH_CACHE_DIR ) ) {
            return;
        }

        $files = glob( RDR_SH_CACHE_DIR . '*.html' );
        if ( $files ) {
            foreach ( $files as $file ) {
                unlink( $file );
            }
        }

        // Supprimer les transients (via wpdb pour être sûr)
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_" . self::TRANSIENT_PF . "%' OR option_name LIKE '_transient_timeout_" . self::TRANSIENT_PF . "%'"
        );
    }

    /**
     * Vider le cache pour une clé spécifique
     */
    public static function delete( $key ) {
        delete_transient( self::TRANSIENT_PF . $key );
        $file = RDR_SH_CACHE_DIR . $key . '.html';
        if ( file_exists( $file ) ) {
            unlink( $file );
        }
    }

    /**
     * Statistiques du cache (pour la page admin)
     */
    public static function stats() {
        $count = 0;
        $size  = 0;

        if ( is_dir( RDR_SH_CACHE_DIR ) ) {
            $files = glob( RDR_SH_CACHE_DIR . '*.html' );
            if ( $files ) {
                $count = count( $files );
                foreach ( $files as $file ) {
                    $size += filesize( $file );
                }
            }
        }

        return array(
            'count' => $count,
            'size'  => self::format_size( $size ),
        );
    }

    private static function format_size( $bytes ) {
        if ( $bytes < 1024 ) return $bytes . ' B';
        if ( $bytes < 1048576 ) return round( $bytes / 1024, 1 ) . ' KB';
        return round( $bytes / 1048576, 2 ) . ' MB';
    }
}
