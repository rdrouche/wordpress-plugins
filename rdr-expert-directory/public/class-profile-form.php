<?php
defined('ABSPATH') || exit;

/**
 * Formulaire de profil expert (front).
 * Shortcode : [rdr_expert_profile_form]
 *
 * Gère :
 * - Création de la fiche (premier accès)
 * - Modification d'une fiche existante (révision en attente unique)
 * - Anti-bot honeypot maison (sans dépendance tierce)
 * - Upload photo profil + bannière via médias WP
 * - Éditeur TinyMCE pour la description
 */
class RDR_ED_Profile_Form {

    public static function init(): void {
        add_shortcode('rdr_expert_profile_form', [self::class, 'render']);
        add_action('wp_ajax_rdr_ed_save_profile',    [self::class, 'ajax_save']);
        add_action('wp_ajax_rdr_ed_cancel_pending',  [self::class, 'ajax_cancel']);
    }

    /**
     * Liste de tous les champs meta persistés.
     * Utilisé par le versioning pour l'archivage.
     */
    public static function get_meta_fields(): array {
        return [
            '_expert_type',          // 'independant' | 'entreprise'
            '_expert_firstname',
            '_expert_lastname',
            '_expert_phone',
            '_expert_email',
            '_expert_phone_public',  // 0|1 visible en front
            '_expert_email_public',  // 0|1 visible en front
            '_expert_references',
            '_expert_credly',
            '_expert_linkedin_profil',
            '_expert_linkedin_page',
            '_expert_website',
            '_expert_noindex',
            '_expert_contact_methods',
            '_expert_form_enabled',      // 1 ou 0 : formulaire activé par l'expert
            '_profile_photo_id',
            '_banner_id',
            '_expert_title',         // Titre/Poste
            '_expert_company',       // Nom entreprise si type=entreprise
        ];
    }

    /* ── Shortcode ── */
    public static function render(array $atts): string {
        if (!RDR_ED_Capabilities::user_can_submit()) {
            return '<div class="rdr-info-box rdr-info-warning">' .
                '<span class="rdr-info-icon">🔒</span>' .
                '<div><span class="rdr-info-title">' . __('Accès restreint', 'rdr-expert-directory') . '</span>' .
                '<div class="rdr-info-content">' . sprintf(
                    __('Vous devez être connecté avec un compte Expert IT pour accéder à cette page. <a href="%s">Se connecter</a>', 'rdr-expert-directory'),
                    wp_login_url(get_permalink())
                ) . '</div></div></div>';
        }

        // Récupérer la fiche de l'utilisateur courant
        $user_id = get_current_user_id();
        $post    = self::get_user_profile($user_id);
        $pending = $post ? RDR_ED_Versioning::get_pending($post->ID) : null;

        ob_start();
        include RDR_ED_DIR . 'public/templates/profile-form.php';
        return ob_get_clean();
    }

    /* ── Récupérer la fiche de l'utilisateur ── */
    public static function get_user_profile(int $user_id): ?WP_Post {
        $posts = get_posts([
            'post_type'      => 'expert_profile',
            'author'         => $user_id,
            'posts_per_page' => 1,
            'post_status'    => ['publish', 'pending', 'draft'],
        ]);
        return $posts[0] ?? null;
    }

    /* ── AJAX : Sauvegarder ── */
    public static function ajax_save(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');

        if (!RDR_ED_Capabilities::user_can_submit()) {
            wp_send_json_error(['message' => __('Accès refusé.', 'rdr-expert-directory')]);
        }

        // Anti-bot honeypot
        if (!empty($_POST['website_url'])) {
            wp_send_json_error(['message' => 'Bot detected.']);
        }

        // Anti-bot timing (formulaire soumis trop vite)
        $form_time = (int) ($_POST['_form_time'] ?? 0);
        if ($form_time && (time() - $form_time) < 3) {
            wp_send_json_error(['message' => __('Soumission trop rapide. Merci de réessayer.', 'rdr-expert-directory')]);
        }

        $user_id = get_current_user_id();
        $post    = self::get_user_profile($user_id);

        // Validation champs obligatoires
        $firstname = sanitize_text_field($_POST['expert_firstname'] ?? '');
        $lastname  = sanitize_text_field($_POST['expert_lastname'] ?? '');
        if (!$firstname || !$lastname) {
            wp_send_json_error(['message' => __('Nom et prénom sont obligatoires.', 'rdr-expert-directory')]);
        }

        // Vérification : une seule révision en attente à la fois
        if ($post && RDR_ED_Versioning::has_pending($post->ID)) {
            // Permettre la modification de la révision en attente (écrase)
            // Aucune erreur, on continue
        }

        // Normaliser le contenu HTML des éditeurs TinyMCE
        // TinyMCE (mode Visuel) envoie des CRLF (\r\n) qui doivent être normalisés
        $normalize_editor = static function(string $raw): string {
            // 1. Normaliser les line endings Windows → Unix
            $raw = str_replace("\r\n", "\n", $raw);
            $raw = str_replace("\r",    "\n", $raw);
            // 2. Supprimer les \n entre balises block-level
            //    (TinyMCE génère <p>texte</p>\n<p>texte</p> → wpautop crée <p>\n</p>)
            $raw = preg_replace('/(<\/(?:p|div|h[1-6]|ul|ol|li|blockquote)>)\n+(<(?:p|div|h[1-6]|ul|ol|li|blockquote))/i', '$1$2', $raw);
            // 3. Appliquer le filtre WP (sécurité HTML)
            return wp_kses_post($raw);
        };

        // Construction des données
        $data = [
            '_title'                  => $firstname . ' ' . $lastname,
            '_expert_firstname'       => $firstname,
            '_expert_lastname'        => $lastname,
            '_expert_type'            => in_array($_POST['expert_type'] ?? '', ['independant', 'entreprise']) ? $_POST['expert_type'] : 'independant',
            '_expert_company'         => sanitize_text_field($_POST['expert_company'] ?? ''),
            '_expert_title'           => sanitize_text_field($_POST['expert_title'] ?? ''),
            '_expert_references'      => $normalize_editor($_POST['expert_references'] ?? ''),
            '_expert_phone'           => sanitize_text_field($_POST['expert_phone'] ?? ''),
            '_expert_email'           => sanitize_email($_POST['expert_email'] ?? ''),
            '_expert_phone_public'    => isset($_POST['expert_phone_public']) ? '1' : '0',
            '_expert_email_public'    => isset($_POST['expert_email_public']) ? '1' : '0',
            '_expert_credly'          => esc_url_raw($_POST['expert_credly'] ?? ''),
            '_expert_linkedin_profil' => esc_url_raw($_POST['expert_linkedin_profil'] ?? ''),
            '_expert_linkedin_page'   => esc_url_raw($_POST['expert_linkedin_page'] ?? ''),
            '_expert_website'         => esc_url_raw($_POST['expert_website'] ?? ''),
            '_expert_contact_methods'  => array_filter(array_map('sanitize_key', (array)($_POST['expert_contact_methods'] ?? ['email']))),
            '_expert_form_enabled'     => isset($_POST['expert_form_enabled']) ? '1' : '0',
            '_expert_noindex'         => isset($_POST['expert_noindex']) ? '1' : '0',
            '_profile_photo_id'       => (int) ($_POST['profile_photo_id'] ?? 0),
            '_banner_id'              => (int) ($_POST['banner_id'] ?? 0),
            // Taxonomies
            '_skills_raw'             => array_map('intval', (array) ($_POST['expert_skills'] ?? [])),
            '_free_skills'            => array_filter(array_map('sanitize_text_field', (array) ($_POST['expert_free_skills'] ?? []))),
            '_languages'              => array_map('intval', (array) ($_POST['expert_languages'] ?? [])),
            '_countries'              => array_map('intval', (array) ($_POST['expert_countries'] ?? [])),
            // Description (normalisée)
            'post_content'            => $normalize_editor($_POST['expert_description'] ?? ''),
        ];

        if (!$post) {
            // Création première fiche en mode pending
            $post_id = wp_insert_post([
                'post_type'   => 'expert_profile',
                'post_title'  => sanitize_text_field($data['_title']),
                'post_status' => 'pending',
                'post_author' => $user_id,
            ]);
            if (is_wp_error($post_id)) {
                wp_send_json_error(['message' => $post_id->get_error_message()]);
            }
            // Statut 'inactive' jusqu'à la première validation admin
            // (set_status 'active' est fait dans apply_pending au moment de la validation)
            RDR_ED_Versioning::set_status($post_id, 'inactive');
            // Déclencher le hook de première création (ex: noindex par défaut)
            do_action('rdr_ed_profile_created', $post_id);
        } else {
            $post_id = $post->ID;
        }

        // Traiter les tags libres (propose + validation)
        $approved_free_ids = apply_filters('rdr_ed_process_free_skills', $data['_free_skills'] ?? [], $user_id);
        // Fusionner les IDs approuvés avec la sélection normale
        $data['_skills'] = array_values(array_unique(array_merge(
            array_map('intval', $data['_skills_raw'] ?? []),
            $approved_free_ids
        )));
        // Respecter la limite max
        $skill_max = (int) RDR_ED_Settings::get('skill_max', 5);
        $data['_skills'] = array_slice($data['_skills'], 0, $skill_max);
        unset($data['_skills_raw'], $data['_free_skills']);

        // Sauvegarder en révision en attente
        do_action('rdr_ed_profile_submitted', $post_id, $data);

        // Notifier l'admin
        do_action('rdr_ed_profile_submitted_notify', $post_id, $data);

        wp_send_json_success([
            'message' => __('Votre fiche a été soumise et est en attente de validation par notre équipe.', 'rdr-expert-directory'),
        ]);
    }

    /* ── AJAX : Annuler la révision en attente ── */
    public static function ajax_cancel(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');

        if (!RDR_ED_Capabilities::user_can_submit()) {
            wp_send_json_error(['message' => __('Accès refusé.', 'rdr-expert-directory')]);
        }

        $post_id = (int) ($_POST['post_id'] ?? 0);
        $post    = get_post($post_id);

        // Vérifier que l'auteur est bien l'utilisateur courant
        if (!$post || (int) $post->post_author !== get_current_user_id()) {
            wp_send_json_error(['message' => __('Accès refusé.', 'rdr-expert-directory')]);
        }

        do_action('rdr_ed_pending_cancelled', $post_id);

        wp_send_json_success(['message' => __('Révision annulée.', 'rdr-expert-directory')]);
    }
}
