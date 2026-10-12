<?php
defined('ABSPATH') || exit;

/**
 * Intégration Polylang pour le plugin RDR Expert Directory.
 *
 * Gère :
 * - Enregistrement du CPT et des taxonomies comme traduisibles
 * - URLs multilingues pour les fiches (/en/expert/slug/, /es/expert/slug/)
 * - Traduction des slugs de base (expert, experts, competence, etc.)
 * - Formulaire de profil multilingue (champs traduits par langue)
 * - Synchronisation des meta non-traduisibles entre traductions
 * - Annuaire filtré par langue courante
 */
class RDR_ED_Polylang {

    /**
     * Champs meta qui sont TRADUITS (contenu textuel par langue).
     * Chaque langue a sa propre valeur.
     */
    const TRANSLATED_METAS = [
        '_expert_title',        // Titre / Poste
        '_expert_references',   // Références (texte enrichi)
        'post_content',         // Description principale (gérée via WP)
    ];

    /**
     * Champs meta qui sont PARTAGÉS entre toutes les traductions.
     * Une seule valeur commune (coordonnées, liens, etc.)
     */
    const SHARED_METAS = [
        '_expert_type',
        '_expert_firstname',
        '_expert_lastname',
        '_expert_company',
        '_expert_phone',
        '_expert_email',
        '_expert_phone_public',
        '_expert_email_public',
        '_expert_contact_methods',  // Tableau des moyens de contact autorisés
        '_expert_credly',
        '_expert_linkedin_profil',
        '_expert_linkedin_page',
        '_expert_website',
        '_expert_noindex',
        '_expert_form_enabled',
        '_profile_photo_id',
        '_banner_id',
        '_expert_status',
        '_expert_click_count',
        '_expert_contact_count',
        '_expert_pending_data',
        '_expert_versions',
    ];

    public static function init(): void {
        // Vérifier que Polylang est actif
        if (!self::is_polylang_active()) return;

        add_action('init',                    [self::class, 'register_strings'],       20);
        add_action('pll_init',                [self::class, 'register_post_types']);
        add_filter('pll_get_post_types',      [self::class, 'add_post_type'],          10, 2);
        add_filter('pll_get_taxonomies',      [self::class, 'add_taxonomies'],         10, 2);
        add_action('pll_save_post',           [self::class, 'sync_shared_metas'],      10, 3);
        add_filter('pll_copy_post_metas',     [self::class, 'filter_copied_metas'],    10, 2);
        add_filter('pll_translate_post_type_rewrite_slugs', [self::class, 'translate_slugs'], 10, 2);
        add_action('wp_ajax_rdr_ed_save_profile',    [self::class, 'before_save_profile'], 1);
        add_filter('rdr_ed_get_post_id_for_lang',    [self::class, 'get_translation'],      10, 2);

        // Annuaire : filtrer par langue
        add_action('wp_ajax_rdr_ed_load_experts',        [self::class, 'add_lang_to_query'], 1);
        add_action('wp_ajax_nopriv_rdr_ed_load_experts', [self::class, 'add_lang_to_query'], 1);

        // Admin : injecter les champs traduits dans le bon contexte
        add_action('add_meta_boxes', [self::class, 'add_lang_meta_box']);
    }

    /* ── Vérification Polylang ── */
    public static function is_polylang_active(): bool {
        return function_exists('pll_the_languages') || function_exists('PLL');
    }

    public static function get_current_lang(): string {
        if (function_exists('pll_current_language')) {
            return pll_current_language('slug') ?: get_locale();
        }
        return get_locale();
    }

    public static function get_default_lang(): string {
        if (function_exists('pll_default_language')) {
            return pll_default_language('slug');
        }
        return 'fr';
    }

    public static function get_languages(): array {
        if (function_exists('pll_languages_list')) {
            return pll_languages_list(['fields' => 'slug']);
        }
        return ['fr'];
    }

    /* ── Enregistrer le CPT comme traduisible ── */
    public static function register_post_types(): void {
        // Polylang Pro : register_post_type_for_translation
        if (function_exists('register_post_type_for_translation')) {
            register_post_type_for_translation('expert_profile');
        }
        if (function_exists('register_taxonomy_for_translation')) {
            register_taxonomy_for_translation('expert_skill');
            register_taxonomy_for_translation('expert_language');
            register_taxonomy_for_translation('expert_country');
        }
    }

    public static function add_post_type(array $post_types, bool $is_settings): array {
        $post_types['expert_profile'] = 'expert_profile';
        return $post_types;
    }

    public static function add_taxonomies(array $taxonomies, bool $is_settings): array {
        $taxonomies['expert_skill']    = 'expert_skill';
        $taxonomies['expert_language'] = 'expert_language';
        $taxonomies['expert_country']  = 'expert_country';
        return $taxonomies;
    }

    /* ── Slugs traduits ── */
    public static function translate_slugs(array $slugs, object $post_type): array {
        if ($post_type->name !== 'expert_profile') return $slugs;

        // Slugs par langue — à personnaliser via les paramètres ou pll_register_string
        $lang_slugs = [
            'fr' => 'expert',
            'en' => 'expert',
            'es' => 'experto',
            'de' => 'experte',
            'it' => 'esperto',
            'pt' => 'especialista',
            'ar' => 'expert',
        ];

        $current_lang = self::get_current_lang();
        if (isset($lang_slugs[$current_lang])) {
            $slugs['expert_profile'] = $lang_slugs[$current_lang];
        }

        return $slugs;
    }

    /* ── Enregistrer les chaînes traduisibles dans Polylang ── */
    public static function register_strings(): void {
        if (!function_exists('pll_register_string')) return;

        $strings = [
            // Annuaire
            ['rdr_ed_dir_title',      __('Annuaire des Experts IT', 'rdr-expert-directory'), true],
            ['rdr_ed_search_ph',      __('Rechercher un expert, une compétence…', 'rdr-expert-directory'), false],
            ['rdr_ed_all_skills',     __('Toutes les compétences', 'rdr-expert-directory'), false],
            ['rdr_ed_all_countries',  __('Tous les pays', 'rdr-expert-directory'), false],
            ['rdr_ed_all_languages',  __('Toutes les langues', 'rdr-expert-directory'), false],
            ['rdr_ed_all_types',      __('Tous les types', 'rdr-expert-directory'), false],
            ['rdr_ed_view_profile',   __('Voir la fiche', 'rdr-expert-directory'), false],
            ['rdr_ed_contact_btn',    __('Contacter cet expert', 'rdr-expert-directory'), false],
            ['rdr_ed_no_results',     __('Aucun expert trouvé.', 'rdr-expert-directory'), false],
            // Formulaire profil — labels champs
            ['rdr_ed_f_firstname',    __('Prénom *', 'rdr-expert-directory'), false],
            ['rdr_ed_f_lastname',     __('Nom *', 'rdr-expert-directory'), false],
            ['rdr_ed_f_title',        __('Titre / Poste', 'rdr-expert-directory'), false],
            ['rdr_ed_f_type',         __('Type *', 'rdr-expert-directory'), false],
            ['rdr_ed_f_independant',  __('Indépendant / Freelance', 'rdr-expert-directory'), false],
            ['rdr_ed_f_entreprise',   __('Entreprise', 'rdr-expert-directory'), false],
            ['rdr_ed_f_company',      __('Nom de l\'entreprise', 'rdr-expert-directory'), false],
            ['rdr_ed_f_skills',       __('Sélectionnez vos compétences *', 'rdr-expert-directory'), false],
            ['rdr_ed_f_languages',    __('Langues parlées', 'rdr-expert-directory'), false],
            ['rdr_ed_f_countries',    __('Pays / Zone géographique', 'rdr-expert-directory'), false],
            ['rdr_ed_f_description',  __('Description (texte enrichi)', 'rdr-expert-directory'), false],
            ['rdr_ed_f_references',   __('Références clients / Réalisations', 'rdr-expert-directory'), false],
            ['rdr_ed_f_contact_title',__('Moyens de contact', 'rdr-expert-directory'), false],
            ['rdr_ed_f_email',        __('Email de contact *', 'rdr-expert-directory'), false],
            ['rdr_ed_f_phone',        __('Téléphone *', 'rdr-expert-directory'), false],
            ['rdr_ed_f_noindex',      __('Ne pas indexer ma fiche dans les moteurs de recherche', 'rdr-expert-directory'), false],
            ['rdr_ed_f_submit_new',   __('Soumettre ma fiche', 'rdr-expert-directory'), false],
            ['rdr_ed_f_submit_edit',  __('Soumettre les modifications', 'rdr-expert-directory'), false],
            // Messages
            ['rdr_ed_pending_msg',    __('Révision en attente de validation', 'rdr-expert-directory'), false],
            ['rdr_ed_submit_ok',      __('Votre fiche a été soumise et est en attente de validation par notre équipe.', 'rdr-expert-directory'), true],
        ];

        foreach ($strings as [$name, $string, $multiline]) {
            pll_register_string($name, $string, 'RDR Expert Directory', $multiline);
        }
    }

    /* ── Synchroniser les meta partagées entre traductions ── */
    // Hook pll_save_post signature : (int $post_id, WP_Post $post, array $translations)
    // $translations = ['fr' => post_id, 'en' => post_id, ...]
    public static function sync_shared_metas(int $post_id, WP_Post $post, array $translations): void {
        if ($post->post_type !== 'expert_profile') return;

        foreach ($translations as $tl_lang => $tl_post_id) {
            if ((int) $tl_post_id === $post_id) continue; // skip self

            foreach (self::SHARED_METAS as $meta_key) {
                $value = get_post_meta($post_id, $meta_key, true);
                update_post_meta((int) $tl_post_id, $meta_key, $value);
            }

            // Synchroniser aussi les taxonomies partagées
            foreach (['expert_skill', 'expert_language', 'expert_country'] as $tax) {
                $terms = wp_get_post_terms($post_id, $tax, ['fields' => 'ids']);
                if (is_wp_error($terms)) continue;
                $translated_terms = self::translate_terms($terms, $tax, $tl_lang);
                wp_set_post_terms((int) $tl_post_id, $translated_terms, $tax);
            }
        }
    }

    /**
     * Mapper des term_ids vers leurs traductions Polylang pour une langue donnée.
     */
    private static function translate_terms(array $term_ids, string $taxonomy, string $lang): array {
        if (!function_exists('pll_get_term')) return $term_ids;
        $translated = [];
        foreach ($term_ids as $tid) {
            $tl_tid = pll_get_term($tid, $lang);
            $translated[] = $tl_tid ?: $tid;
        }
        return array_filter($translated);
    }

    /* ── Contrôler quelles metas sont copiées à la création d'une traduction ── */
    public static function filter_copied_metas(array $metas, bool $sync): array {
        // À la création d'une traduction, copier les metas partagées
        // mais NE PAS écraser les metas traduites (elles partiront vides pour être remplies)
        $to_add = self::SHARED_METAS;
        return array_unique(array_merge($metas, $to_add));
    }

    /* ── Récupérer la traduction d'un post pour une langue ── */
    public static function get_translation(int $post_id, string $lang): int {
        if (!function_exists('pll_get_post')) return $post_id;
        $tl = pll_get_post($post_id, $lang);
        return $tl ?: $post_id;
    }

    /* ── Ajouter la langue courante à la requête AJAX annuaire ── */
    public static function add_lang_to_query(): void {
        // Polylang filtre automatiquement les requêtes WP_Query par langue
        // On force la langue depuis le paramètre AJAX si envoyé
        $lang = sanitize_key($_POST['lang'] ?? '');
        if ($lang && function_exists('pll_current_language')) {
            // Forcer la langue pour cette requête
            do_action('pll_language_defined', $lang, null);
        }
    }

    /* ── Meta box admin : indicateur de langue et champs traduits ── */
    public static function add_lang_meta_box(): void {
        if (!self::is_polylang_active()) return;
        add_meta_box(
            'rdr_ed_polylang_info',
            '🌐 ' . __('Traduction — Champs multilingues', 'rdr-expert-directory'),
            [self::class, 'render_lang_meta_box'],
            'expert_profile',
            'side',
            'default'
        );
    }

    public static function render_lang_meta_box(WP_Post $post): void {
        if (!function_exists('pll_get_post_translations')) return;

        $translations = pll_get_post_translations($post->ID);
        $current_lang = function_exists('pll_get_post_language') ? pll_get_post_language($post->ID, 'slug') : '?';

        echo '<p><strong>' . __('Langue de cette fiche :', 'rdr-expert-directory') . '</strong> <code>' . esc_html($current_lang) . '</code></p>';
        echo '<p class="description">' . __('Champs traduits (spécifiques à cette langue) :', 'rdr-expert-directory') . '</p>';
        echo '<ul style="margin:0;padding-left:16px;font-size:12px;color:#8b949e;">';
        echo '<li>Titre / Poste</li>';
        echo '<li>Description (contenu)</li>';
        echo '<li>Références</li>';
        echo '</ul>';
        echo '<p class="description" style="margin-top:8px;">' . __('Champs partagés entre toutes les langues :', 'rdr-expert-directory') . '</p>';
        echo '<ul style="margin:0;padding-left:16px;font-size:12px;color:#8b949e;">';
        echo '<li>Nom, Prénom, Type</li>';
        echo '<li>Email, Téléphone, Liens</li>';
        echo '<li>Photo, Bannière</li>';
        echo '<li>Statut, Premium</li>';
        echo '</ul>';

        if (count($translations) > 1) {
            echo '<hr><p><strong>' . __('Versions linguistiques :', 'rdr-expert-directory') . '</strong></p>';
            foreach ($translations as $lang => $pid) {
                $flag  = function_exists('pll_get_post_language') ? '' : '';
                $label = strtoupper($lang) . ' — ' . ($pid === $post->ID ? '<em>actuelle</em>' : '<a href="' . get_edit_post_link($pid) . '">' . __('modifier', 'rdr-expert-directory') . '</a>');
                echo '<p style="margin:2px 0;font-size:12px;">' . $flag . $label . '</p>';
            }
        }
    }

    /**
     * Avant la sauvegarde du profil front : récupérer le bon post_id
     * selon la langue soumise.
     */
    public static function before_save_profile(): void {
        if (!self::is_polylang_active()) return;
        // La langue est envoyée depuis le formulaire front
        $lang = sanitize_key($_POST['current_lang'] ?? '');
        if ($lang && function_exists('pll_current_language')) {
            // Rien à faire : Polylang gère ça via les query vars
        }
    }

    /**
     * Retourne l'URL de la fiche dans une langue donnée.
     */
    public static function get_permalink_for_lang(int $post_id, string $lang): string {
        // Permet à d'autres plugins d'overrider la résolution de traduction
        $tl_id = (int) apply_filters('rdr_ed_get_post_id_for_lang', $post_id, $lang);
        return get_permalink($tl_id) ?: '';
    }

    /**
     * Génère les balises hreflang pour le <head> de la fiche.
     * À appeler depuis wp_head.
     */
    public static function output_hreflang(int $post_id): void {
        if (!function_exists('pll_get_post_translations')) return;
        $translations = pll_get_post_translations($post_id);
        foreach ($translations as $lang => $pid) {
            $url = get_permalink($pid);
            if ($url) {
                printf(
                    '<link rel="alternate" hreflang="%s" href="%s">' . PHP_EOL,
                    esc_attr($lang),
                    esc_url($url)
                );
            }
        }
        // x-default = langue par défaut
        $default_lang = self::get_default_lang();
        if (isset($translations[$default_lang])) {
            printf(
                '<link rel="alternate" hreflang="x-default" href="%s">' . PHP_EOL,
                esc_url(get_permalink($translations[$default_lang]))
            );
        }
    }
}
