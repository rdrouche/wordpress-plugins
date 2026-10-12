<?php
defined('ABSPATH') || exit;

/**
 * Intégration RankMath pour les fiches expert.
 *
 * - Injecte le meta noindex si _expert_noindex = '1'
 * - Applique le noindex par défaut si configuré dans les paramètres
 * - Compatible avec RankMath Pro et Free
 */
class RDR_ED_RankMath {

    public static function init(): void {
        // RankMath : override robots meta
        add_filter('rank_math/frontend/robots', [self::class, 'filter_robots']);

        // Fallback si RankMath absent : balise meta classique
        add_action('wp_head', [self::class, 'fallback_meta'], 1);

        // Appliquer noindex par défaut à la création de fiche
        add_action('rdr_ed_profile_created', [self::class, 'apply_default_noindex']);

        // Empêcher l'indexation des fiches de membres non-premium
        add_filter('rank_math/frontend/robots', [self::class, 'noindex_non_premium']);
    }

    /**
     * Filter RankMath robots pour les fiches expert.
     */
    public static function filter_robots(array $robots): array {
        if (!is_singular('expert_profile')) return $robots;

        global $post;
        if (!$post) return $robots;

        $noindex = get_post_meta($post->ID, '_expert_noindex', true);
        if ($noindex === '1') {
            $robots['index']  = 'noindex';
            $robots['follow'] = 'nofollow';
        }

        return $robots;
    }

    /**
     * Masquer les fiches des experts non-premium des moteurs de recherche.
     */
    public static function noindex_non_premium(array $robots): array {
        if (!is_singular('expert_profile')) return $robots;

        global $post;
        if (!$post) return $robots;

        $author_id = (int) $post->post_author;
        if (!RDR_ED_Premium::is_premium($author_id)) {
            $robots['index'] = 'noindex';
        }

        return $robots;
    }

    /**
     * Fallback : balise <meta name="robots"> si RankMath absent.
     */
    public static function fallback_meta(): void {
        // Si RankMath est actif, il gère déjà
        if (class_exists('RankMath') || class_exists('RankMathPro')) return;
        if (!is_singular('expert_profile')) return;

        global $post;
        if (!$post) return;

        $noindex   = get_post_meta($post->ID, '_expert_noindex', true);
        $author_id = (int) $post->post_author;
        $is_prem   = RDR_ED_Premium::is_premium($author_id);

        if ($noindex === '1' || !$is_prem) {
            echo '<meta name="robots" content="noindex,nofollow">' . PHP_EOL;
        }
    }

    /**
     * Appliquer le noindex par défaut lors de la création.
     */
    public static function apply_default_noindex(int $post_id): void {
        if ((int) RDR_ED_Settings::get('rankmath_default_noindex') === 1) {
            update_post_meta($post_id, '_expert_noindex', '1');
        }
    }
}
