<?php
/**
 * Chargeur de modules (sous-plugins).
 *
 * @package RDR_IT_Suite
 */

defined( 'ABSPATH' ) || exit;

class RDR_Module_Loader {

    /** @var array<string, array<string, mixed>> Modules découverts */
    private array $discovered = [];

    /** @var string[] Slugs des modules actifs */
    private array $active = [];

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public function init(): void {
        $this->active     = (array) get_option( RDR_SUITE_OPTION, [] );
        $this->discovered = RDR_Module_Registry::discover();

        $this->load_active_modules();
    }

    public static function on_activate(): void {
        if ( false === get_option( RDR_SUITE_OPTION ) ) {
            update_option( RDR_SUITE_OPTION, [] );
        }
    }

    public static function on_deactivate(): void {
        // On conserve la config — l'utilisateur pourra réactiver.
    }

    // ── Modules ───────────────────────────────────────────────────────────────

    /**
     * Charge les fichiers des modules actifs.
     */
    private function load_active_modules(): void {
        foreach ( $this->active as $slug ) {
            if ( ! isset( $this->discovered[ $slug ] ) ) {
                continue;
            }

            $entry = $this->discovered[ $slug ]['entry'] ?? '';
            if ( $entry && file_exists( $entry ) ) {
                require_once $entry;

                // Appel du hook de démarrage du module s'il existe
                do_action( "rdr_suite_module_loaded_{$slug}" );
            }
        }
    }

    /**
     * Active un module et persiste le choix.
     */
    public function activate_module( string $slug ): bool {
        if ( ! isset( $this->discovered[ $slug ] ) ) {
            return false;
        }

        if ( ! in_array( $slug, $this->active, true ) ) {
            $this->active[] = $slug;
            update_option( RDR_SUITE_OPTION, $this->active );
        }

        return true;
    }

    /**
     * Désactive un module.
     */
    public function deactivate_module( string $slug ): bool {
        $key = array_search( $slug, $this->active, true );
        if ( false === $key ) {
            return false;
        }

        unset( $this->active[ $key ] );
        $this->active = array_values( $this->active );
        update_option( RDR_SUITE_OPTION, $this->active );

        return true;
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    public function get_discovered(): array { return $this->discovered; }
    public function get_active(): array     { return $this->active; }

    public function is_active( string $slug ): bool {
        return in_array( $slug, $this->active, true );
    }
}
