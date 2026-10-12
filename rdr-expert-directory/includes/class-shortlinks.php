<?php
defined('ABSPATH') || exit;

/**
 * Système de shortlinks pour les fiches experts.
 *
 * URL : /go/expert/{post_id}/
 * Compteur de clics stocké en meta : _expert_click_count
 * Sécurisation : rel="nofollow noopener noreferrer" target="_blank" sur tous les liens externes.
 */
class RDR_ED_Shortlinks {

    const REWRITE_TAG = 'rdr_expert_shortlink';

    public static function init(): void {
        add_action('init',           [self::class, 'add_rewrite']);
        add_filter('query_vars',     [self::class, 'add_query_var']);
        add_action('template_redirect', [self::class, 'handle_redirect']);
    }

    public static function add_rewrite(): void {
        add_rewrite_rule(
            '^go/expert/([0-9]+)/?$',
            'index.php?' . self::REWRITE_TAG . '=$matches[1]',
            'top'
        );
    }

    public static function add_query_var(array $vars): array {
        $vars[] = self::REWRITE_TAG;
        return $vars;
    }

    public static function handle_redirect(): void {
        $post_id = (int) get_query_var(self::REWRITE_TAG);
        if (!$post_id) return;

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'expert_profile' || $post->post_status !== 'publish') {
            wp_redirect(home_url('/experts/'), 302);
            exit;
        }

        // Incrémenter le compteur
        self::increment_click($post_id);

        // Rediriger vers la fiche
        wp_redirect(get_permalink($post_id), 301);
        exit;
    }

    public static function get_shortlink(int $post_id): string {
        return home_url('/go/expert/' . $post_id . '/');
    }

    public static function get_click_count(int $post_id): int {
        return (int) get_post_meta($post_id, '_expert_click_count', true);
    }

    private static function increment_click(int $post_id): void {
        $count = self::get_click_count($post_id);
        update_post_meta($post_id, '_expert_click_count', $count + 1);
    }

    /**
     * Génère un lien externe sécurisé.
     */
    public static function safe_external_link(string $url, string $label, string $class = ''): string {
        $url   = esc_url($url);
        $label = esc_html($label);
        $class = $class ? ' class="' . esc_attr($class) . '"' : '';
        return sprintf(
            '<a href="%s"%s rel="nofollow noopener noreferrer" target="_blank">%s</a>',
            $url, $class, $label
        );
    }
}
