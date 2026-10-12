<?php
/**
 * Registre de modules — scanne /modules/ et lit les en-têtes.
 *
 * Chaque sous-plugin DOIT avoir un fichier `module.json` à sa racine :
 *
 * {
 *   "slug":        "rdr-seo-tools",
 *   "name":        "SEO Tools",
 *   "description": "Outils SEO avancés pour WordPress.",
 *   "version":     "1.0.0",
 *   "author":      "Romain Drouche",
 *   "icon":        "🔍",
 *   "entry":       "rdr-seo-tools.php",
 *   "min_version": "1.0.0"
 * }
 *
 * @package RDR_IT_Suite
 */

defined( 'ABSPATH' ) || exit;

class RDR_Module_Registry {

    /**
     * Découvre tous les modules présents dans /modules/.
     *
     * @return array<string, array<string, mixed>> Indexé par slug.
     */
    public static function discover(): array {
        $modules    = [];
        $modules_dir = RDR_SUITE_MODULES;

        if ( ! is_dir( $modules_dir ) ) {
            return $modules;
        }

        $dirs = glob( $modules_dir . '*', GLOB_ONLYDIR );
        if ( ! $dirs ) {
            return $modules;
        }

        foreach ( $dirs as $dir ) {
            $json_file = $dir . '/module.json';

            if ( ! file_exists( $json_file ) ) {
                continue;
            }

            $data = json_decode( file_get_contents( $json_file ), true );

            if ( ! is_array( $data ) || empty( $data['slug'] ) ) {
                continue;
            }

            // Compatibilité version suite
            if ( ! empty( $data['min_version'] ) ) {
                if ( version_compare( RDR_SUITE_VERSION, $data['min_version'], '<' ) ) {
                    $data['incompatible'] = true;
                }
            }

            $slug            = sanitize_key( $data['slug'] );
            $data['dir']     = trailingslashit( $dir );
            $data['url']     = RDR_SUITE_URL . 'modules/' . basename( $dir ) . '/';
            $data['entry']   = $data['dir'] . ( $data['entry'] ?? "{$slug}.php" );

            $modules[ $slug ] = $data;
        }

        return $modules;
    }
}
