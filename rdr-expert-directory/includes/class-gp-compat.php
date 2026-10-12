<?php
defined('ABSPATH') || exit;

/**
 * Compatibilité GeneratePress — RDR Expert Directory.
 *
 * IMPORTANT : Ce fichier NE déclare PAS de fonctions globales.
 * La raison : les plugins sont chargés avant les thèmes. Déclarer
 * generate_do_attr() ici causerait un "Cannot redeclare" fatal quand
 * GeneratePress déclare ensuite la même fonction dans theme-functions.php.
 *
 * Stratégie : des méthodes statiques encapsulent les appels aux fonctions GP.
 * Au moment où les templates sont rendus, GP est déjà chargé et ses fonctions
 * sont disponibles. Les méthodes ci-dessous fournissent un fallback HTML minimal
 * pour les cas où GP n'est pas le thème actif.
 *
 * Usage dans les templates :
 *   RDR_ED_GP_Compat::before_main_content();
 *   RDR_ED_GP_Compat::do_attr('content');
 *   RDR_ED_GP_Compat::do_attr('main');
 *   RDR_ED_GP_Compat::construct_sidebars();
 *   RDR_ED_GP_Compat::after_main_content();
 */
class RDR_ED_GP_Compat {

    public static function init(): void {
        // Rien à faire au chargement — les méthodes sont appelées à la demande
    }

    /**
     * Détecte si GeneratePress est le thème actif (parent ou enfant).
     */
    public static function is_gp(): bool {
        static $gp = null;
        if ($gp === null) {
            $theme  = wp_get_theme();
            $parent = $theme->parent();
            $gp = (
                $theme->get_template() === 'generatepress' ||
                ($parent && $parent->get_template() === 'generatepress') ||
                function_exists('generate_do_attr')
            );
        }
        return $gp;
    }

    /**
     * Équivalent de do_action('generate_before_main_content').
     * Ouvre la structure de contenu GP ou émet un fallback HTML.
     */
    public static function before_main_content(): void {
        if (self::is_gp()) {
            do_action('generate_before_main_content');
        } else {
            // Fallback structure HTML standard WP
            echo '<div id="content" class="site-content"><div class="inside-site-info">';
        }
    }

    /**
     * Équivalent de generate_do_attr($context).
     * Émet les attributs id/class sur un élément HTML.
     */
    public static function do_attr(string $context): void {
        if (self::is_gp() && function_exists('generate_do_attr')) {
            generate_do_attr($context);
        } else {
            // Fallback : attributs id/class standard
            $defaults = [
                'content' => 'id="primary" class="content-area"',
                'main'    => 'id="main" class="site-main"',
            ];
            echo ' ' . ($defaults[$context] ?? '');
        }
    }

    /**
     * Équivalent de do_action('generate_before_content').
     */
    public static function before_content(): void {
        if (self::is_gp()) {
            do_action('generate_before_content');
        }
    }

    /**
     * Équivalent de do_action('generate_before_entry_title').
     */
    public static function before_entry_title(): void {
        if (self::is_gp()) {
            do_action('generate_before_entry_title');
        }
    }

    /**
     * Équivalent de do_action('generate_after_entry_title').
     */
    public static function after_entry_title(): void {
        if (self::is_gp()) {
            do_action('generate_after_entry_title');
        }
    }

    /**
     * Équivalent de do_action('generate_after_entry_content').
     */
    public static function after_entry_content(): void {
        if (self::is_gp()) {
            do_action('generate_after_entry_content');
        }
    }

    /**
     * Équivalent de do_action('generate_after_content').
     */
    public static function after_content(): void {
        if (self::is_gp()) {
            do_action('generate_after_content');
        }
    }

    /**
     * Équivalent de do_action('generate_after_primary_content_area').
     * Doit être appelé après la balise </div> fermant #primary.
     */
    public static function after_primary_content_area(): void {
        if (self::is_gp()) {
            do_action('generate_after_primary_content_area');
        }
    }

    /**
     * Équivalent de generate_construct_sidebars().
     * Affiche la sidebar si GP est actif, sinon no-op.
     */
    public static function construct_sidebars(): void {
        if (self::is_gp() && function_exists('generate_construct_sidebars')) {
            generate_construct_sidebars();
        }
        // Fallback : rien — sans GP, pas de sidebar configurée
    }

    /**
     * Équivalent de do_action('generate_after_main_content').
     * Ferme la structure de contenu GP ou le fallback HTML.
     */
    public static function after_main_content(): void {
        if (self::is_gp()) {
            do_action('generate_after_main_content');
        } else {
            echo '</div></div>'; // Ferme le fallback before_main_content()
        }
    }
}
