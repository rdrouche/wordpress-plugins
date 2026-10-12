<?php
defined('ABSPATH') || exit;

/**
 * Intégration premium WPA (WP Premium Access).
 *
 * Détecte si un utilisateur a un accès premium actif via :
 *   apply_filters('wpa_get_user_expiry', null, $user_id)
 *
 * Retour possible : null | int (timestamp) | string (date) | DateTimeInterface
 *
 * Si WPA n'est pas installé ou retourne une erreur, l'utilisateur
 * est considéré comme non-premium.
 *
 * Note : WPA peut appeler des méthodes internes depuis sa closure de filtre.
 * L'appel est enveloppé dans un try/catch pour éviter tout crash fatal.
 */
class RDR_ED_Premium {

    /** Cache en mémoire pour éviter les appels répétés sur le même $user_id */
    private static array $cache = [];

    public static function init(): void {
        add_action('pre_get_posts', [self::class, 'pre_get_posts']);
        // Vider le cache à chaque sauvegarde d'utilisateur
        add_action('profile_update', [self::class, 'clear_cache']);
    }

    /* ── Récupérer la date d'expiration depuis WPA ── */
    private static function get_expiry(int $user_id) {
        // Filtre WPA — wrappé en try/catch car certaines versions
        // de WPA appellent des méthodes privées depuis leur callback
        try {
            return apply_filters('wpa_get_user_expiry', null, $user_id);
        } catch (\Throwable $e) {
            // WPA indisponible ou erreur interne → non premium
            return null;
        }
    }

    /**
     * Vérifie si un utilisateur est actuellement premium.
     */
    public static function is_premium(int $user_id): bool {
        if (!$user_id) return false;

        // Cache mémoire (durée d'une requête)
        if (isset(self::$cache[$user_id])) {
            return self::$cache[$user_id];
        }

        $expiry = self::get_expiry($user_id);
        $result = self::expiry_is_active($expiry);
        self::$cache[$user_id] = $result;

        return $result;
    }

    /**
     * Retourne la date d'expiration formatée, ou null si non premium.
     */
    public static function get_expiry_label(int $user_id): ?string {
        $expiry = self::get_expiry($user_id);
        if (!$expiry) return null;

        if ($expiry instanceof \DateTimeInterface) {
            return $expiry->format('d/m/Y');
        }
        if (is_numeric($expiry)) {
            return date('d/m/Y', (int) $expiry);
        }
        if (is_string($expiry)) {
            $ts = strtotime($expiry);
            return $ts ? date('d/m/Y', $ts) : $expiry;
        }
        return null;
    }

    /**
     * Évalue si une valeur d'expiration est dans le futur.
     */
    private static function expiry_is_active($expiry): bool {
        if (is_null($expiry)) return false;

        if ($expiry instanceof \DateTimeInterface) {
            return $expiry->getTimestamp() > time();
        }
        if (is_numeric($expiry)) {
            return (int) $expiry > time();
        }
        if (is_string($expiry)) {
            $ts = strtotime($expiry);
            return $ts !== false && $ts > time();
        }
        return false;
    }

    /**
     * Vider le cache pour un utilisateur (après mise à jour profil/abonnement).
     */
    public static function clear_cache(int $user_id = 0): void {
        if ($user_id) {
            unset(self::$cache[$user_id]);
        } else {
            self::$cache = [];
        }
    }

    /**
     * Adapte les requêtes WP_Query front pour l'annuaire public.
     */
    public static function pre_get_posts(WP_Query $query): void {
        if (is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'expert_profile') return;

        $query->set('post_status', 'publish');
        $query->set('has_password', false);
    }

    /**
     * Filtre une liste de WP_Post pour ne garder que les experts premium + actifs.
     */
    public static function filter_posts(array $posts): array {
        return array_values(array_filter($posts, function (\WP_Post $post) {
            if (RDR_ED_Versioning::get_status($post->ID) !== 'active') {
                return false;
            }
            return self::is_premium((int) $post->post_author);
        }));
    }
}
