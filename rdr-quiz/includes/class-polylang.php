<?php
/**
 * Compatibilité Polylang
 * - CPT rdr_quiz et rdr_question traduits
 * - Taxonomie rdr_quiz_cat traduite
 * - Ajout automatique des langues
 */
defined('ABSPATH') || exit;

class RDR_Quiz_Polylang {

    public static function init(): void {
        add_action('init', [self::class, 'register_with_polylang'], 20);
        add_filter('pll_get_post_types',     [self::class, 'add_post_types'],  10, 2);
        add_filter('pll_get_taxonomies',     [self::class, 'add_taxonomies'],  10, 2);
    }

    public static function register_with_polylang(): void {
        if (! function_exists('pll_register_string')) return;

        // Chaînes traduisibles du plugin
        $strings = [
            'quiz_start'    => 'Démarrer le Quiz',
            'quiz_retry'    => 'Recommencer',
            'quiz_correct'  => '✅ Bonne réponse !',
            'quiz_wrong'    => '❌ Mauvaise réponse',
            'quiz_score'    => 'Votre score',
            'quiz_results'  => 'Voir mes résultats',
        ];

        foreach ($strings as $key => $value) {
            pll_register_string($key, $value, 'RDR-IT Quiz');
        }
    }

    /**
     * Rend les CPT traduisibles avec Polylang
     */
    public static function add_post_types(array $types, bool $is_settings): array {
        if ($is_settings) {
            // Dans les réglages Polylang, affiche les CPT
            $types['rdr_quiz']     = 'rdr_quiz';
            $types['rdr_question'] = 'rdr_question';
        }
        return $types;
    }

    /**
     * Rend la taxonomie traduisible
     */
    public static function add_taxonomies(array $taxonomies, bool $is_settings): array {
        if ($is_settings) {
            $taxonomies['rdr_quiz_cat'] = 'rdr_quiz_cat';
        }
        return $taxonomies;
    }
}
