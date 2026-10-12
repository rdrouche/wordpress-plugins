<?php
defined('ABSPATH') || exit;

/**
 * Versioning des fiches expert.
 *
 * Règles :
 * - Une fiche publiée ne peut avoir qu'UNE seule révision en attente.
 * - L'expert peut annuler ou modifier sa révision en attente (tant qu'elle n'est pas validée).
 * - On conserve les 2 dernières versions validées (en meta sérialisée).
 * - Table meta : _expert_pending_data  → données JSON en attente
 *                _expert_versions      → tableau JSON des versions archivées [{data, validated_at, validated_by}]
 *                _expert_status        → 'active' | 'inactive' (contrôle admin)
 */
class RDR_ED_Versioning {

    const META_PENDING  = '_expert_pending_data';
    const META_VERSIONS = '_expert_versions';
    const META_STATUS   = '_expert_status';
    const MAX_VERSIONS  = 2;

    public static function init(): void {
        add_action('rdr_ed_profile_submitted',  [self::class, 'save_pending'],   10, 2);
        add_action('rdr_ed_profile_approved',   [self::class, 'apply_pending'],  10, 1);
        add_action('rdr_ed_profile_rejected',   [self::class, 'clear_pending'],  10, 1);
        add_action('rdr_ed_pending_cancelled',  [self::class, 'clear_pending'],  10, 1);
    }

    /* ── Sauvegarde des données en attente ── */
    public static function save_pending(int $post_id, array $data): void {
        $data['submitted_at'] = current_time('mysql');
        $data['submitted_by'] = get_current_user_id();
        update_post_meta($post_id, self::META_PENDING, wp_json_encode($data));
    }

    /* ── Application de la révision en attente ── */
    public static function apply_pending(int $post_id): void {
        $pending_json = get_post_meta($post_id, self::META_PENDING, true);
        if (!$pending_json) return;

        $pending = json_decode($pending_json, true);

        /* Archiver la version actuellement publiée avant d'écraser */
        self::archive_current_version($post_id);

        /* Appliquer les champs */
        self::apply_data_to_post($post_id, $pending);

        /* Nettoyer */
        delete_post_meta($post_id, self::META_PENDING);
    }

    /* ── Archiver la version courante ── */
    private static function archive_current_version(int $post_id): void {
        $versions = self::get_versions($post_id);

        $current_data = self::extract_current_data($post_id);
        $current_data['archived_at'] = current_time('mysql');

        array_unshift($versions, $current_data); // plus récente en premier
        $versions = array_slice($versions, 0, self::MAX_VERSIONS);

        update_post_meta($post_id, self::META_VERSIONS, wp_json_encode($versions));
    }

    /* ── Extraire les données courantes ── */
    public static function extract_current_data(int $post_id): array {
        $post = get_post($post_id);
        $fields = RDR_ED_Profile_Form::get_meta_fields();
        $data   = ['post_content' => $post ? $post->post_content : ''];

        foreach ($fields as $key) {
            $data[$key] = get_post_meta($post_id, $key, true);
        }

        // Taxonomies
        $data['_skills']    = wp_list_pluck(
            wp_get_post_terms($post_id, 'expert_skill'),
            'term_id'
        );
        $data['_languages'] = wp_list_pluck(
            wp_get_post_terms($post_id, 'expert_language'),
            'term_id'
        );
        $data['_countries'] = wp_list_pluck(
            wp_get_post_terms($post_id, 'expert_country'),
            'term_id'
        );

        return $data;
    }

    /* ── Appliquer un tableau de données à un post ── */
    public static function apply_data_to_post(int $post_id, array $data): void {
        global $wpdb;
        $fields = RDR_ED_Profile_Form::get_meta_fields();

        // Préparer le contenu
        $post_title   = isset($data['_title'])
            ? sanitize_text_field($data['_title'])
            : get_the_title($post_id);

        $post_content = '';
        if (isset($data['post_content'])) {
            $raw = str_replace("\r\n", "\n", $data['post_content']);
            $raw = str_replace("\r",    "\n", $raw);
            // Supprimer les \n entre balises block-level (évite <p>\n</p> à l'affichage)
            $raw = preg_replace('/(<\/(?:p|div|h[1-6]|ul|ol|li|blockquote)>)\n+(<(?:p|div|h[1-6]|ul|ol|li|blockquote))/i', '$1$2', $raw);
            $post_content = wp_kses_post($raw);
        }

        // Mettre à jour le post DIRECTEMENT via wpdb pour contourner les
        // vérifications de capacités WP (map_meta_cap sur le CPT custom).
        // wp_update_post() vérifie publish_expert_profiles / edit_published_expert_profiles
        // ce qui peut bloquer même un admin si ses caps ne sont pas correctement chargées.
        $wpdb->update(
            $wpdb->posts,
            [
                'post_title'   => $post_title,
                'post_content' => $post_content,
                'post_status'  => 'publish',
                'post_modified' => current_time('mysql'),
                'post_modified_gmt' => current_time('mysql', true),
            ],
            ['ID' => $post_id],
            ['%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        // Invalider les caches WP pour ce post
        clean_post_cache($post_id);
        wp_cache_delete($post_id, 'posts');

        // Marquer la fiche comme active après validation
        self::set_status($post_id, 'active');

        foreach ($fields as $key) {
            if (isset($data[$key])) {
                update_post_meta($post_id, $key, $data[$key]);
            }
        }

        // Taxonomies
        if (isset($data['_skills'])) {
            wp_set_post_terms($post_id, array_map('intval', (array) $data['_skills']), 'expert_skill');
        }
        if (isset($data['_languages'])) {
            wp_set_post_terms($post_id, array_map('intval', (array) $data['_languages']), 'expert_language');
        }
        if (isset($data['_countries'])) {
            wp_set_post_terms($post_id, array_map('intval', (array) $data['_countries']), 'expert_country');
        }

        // Photo profil (thumbnail)
        if (!empty($data['_profile_photo_id'])) {
            set_post_thumbnail($post_id, (int) $data['_profile_photo_id']);
        }
        if (!empty($data['_banner_id'])) {
            update_post_meta($post_id, '_banner_id', (int) $data['_banner_id']);
        }
    }

    /* ── Supprimer la révision en attente ── */
    public static function clear_pending(int $post_id): void {
        delete_post_meta($post_id, self::META_PENDING);
    }

    /* ── Getters ── */
    public static function get_pending(int $post_id): ?array {
        $json = get_post_meta($post_id, self::META_PENDING, true);
        return $json ? json_decode($json, true) : null;
    }

    public static function has_pending(int $post_id): bool {
        return (bool) get_post_meta($post_id, self::META_PENDING, true);
    }

    public static function get_versions(int $post_id): array {
        $json = get_post_meta($post_id, self::META_VERSIONS, true);
        return $json ? json_decode($json, true) : [];
    }

    public static function get_status(int $post_id): string {
        $status = get_post_meta($post_id, self::META_STATUS, true);
        return $status ?: 'active';
    }

    public static function set_status(int $post_id, string $status): void {
        update_post_meta($post_id, self::META_STATUS, in_array($status, ['active', 'inactive']) ? $status : 'active');
    }
}
