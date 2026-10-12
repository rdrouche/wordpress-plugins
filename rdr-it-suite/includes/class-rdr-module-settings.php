<?php
/**
 * Gestionnaire de settings par module.
 *
 * Chaque module dispose d'un namespace isolé dans wp_options :
 *   rdr_module_{slug}_settings  → tableau sérialisé
 *
 * Usage dans un module :
 *
 *   $val = RDR_Module_Settings::get( 'mon-module', 'ma_cle', 'defaut' );
 *   RDR_Module_Settings::set( 'mon-module', 'ma_cle', $nouvelle_valeur );
 *   RDR_Module_Settings::delete( 'mon-module', 'ma_cle' );
 *   RDR_Module_Settings::all( 'mon-module' );
 *
 * @package RDR_IT_Suite
 */

defined( 'ABSPATH' ) || exit;

class RDR_Module_Settings {

    // ── Option key ────────────────────────────────────────────────────────────

    private static function option_key( string $slug ): string {
        return 'rdr_module_' . sanitize_key( $slug ) . '_settings';
    }

    // ── API publique ──────────────────────────────────────────────────────────

    /**
     * Lit tous les settings d'un module.
     *
     * @return array<string, mixed>
     */
    public static function all( string $slug ): array {
        return (array) get_option( self::option_key( $slug ), [] );
    }

    /**
     * Lit une valeur.
     *
     * @param mixed $default
     * @return mixed
     */
    public static function get( string $slug, string $key, mixed $default = null ): mixed {
        $all = self::all( $slug );
        return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
    }

    /**
     * Écrit une valeur.
     */
    public static function set( string $slug, string $key, mixed $value ): void {
        $all         = self::all( $slug );
        $all[ $key ] = $value;
        update_option( self::option_key( $slug ), $all, false );
    }

    /**
     * Écrit plusieurs valeurs d'un coup.
     *
     * @param array<string, mixed> $values
     */
    public static function set_many( string $slug, array $values ): void {
        $all = array_merge( self::all( $slug ), $values );
        update_option( self::option_key( $slug ), $all, false );
    }

    /**
     * Supprime une clé.
     */
    public static function delete( string $slug, string $key ): void {
        $all = self::all( $slug );
        unset( $all[ $key ] );
        update_option( self::option_key( $slug ), $all, false );
    }

    /**
     * Supprime tous les settings d'un module.
     */
    public static function purge( string $slug ): void {
        delete_option( self::option_key( $slug ) );
    }
}
