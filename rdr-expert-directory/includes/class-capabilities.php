<?php
defined('ABSPATH') || exit;

/**
 * Gestion des capacités et du rôle Expert IT.
 *
 * Capacités custom déclarées :
 *  - CAP_VALIDATE   : valider/refuser les fiches (admin)
 *  - CAP_DEACTIVATE : désactiver une fiche manuellement (admin)
 *  - CAP_SKILLS     : gérer la taxonomie des compétences (admin)
 *  - CAP_SUBMIT     : soumettre/modifier sa propre fiche (expert)
 *
 * Rôle expert_it :
 *  Attribué automatiquement lors de l'activation du profil expert.
 *  Utilisable pour conditionner l'affichage des widgets WP.
 *
 * ⚠️  Authentification gérée par WPA (magic link).
 *     Le plugin ne génère aucun lien vers wp-login.php.
 *     Pour afficher un lien de connexion aux visiteurs non connectés,
 *     utiliser show_login="true" + login_url="/votre-page-wpa/" + login_text="...".
 */
class RDR_ED_Capabilities {

    /* ── Identifiant du rôle ── */
    const ROLE_SLUG  = 'expert_it';
    const ROLE_LABEL = 'Expert IT';

    /* ── Capacités custom (admin) ── */
    const CAP_VALIDATE   = 'wpa_validate_expert';
    const CAP_DEACTIVATE = 'wpa_deactivate_expert';
    const CAP_SKILLS     = 'wpa_manage_expert_skills';

    /* ── Capacité custom (expert front) ── */
    const CAP_SUBMIT = 'wpa_submit_expert_profile';

    /* ── capability_type du CPT (génère les caps CRUD standard WP) ── */
    const CAP_SINGULAR = 'expert_profile';
    const CAP_PLURAL   = 'expert_profiles';

    /* ── Capacités minimales du rôle expert_it ── */
    const EXPERT_CAPS = [
        'read'                              => true,
        'upload_files'                      => true,
        'create_expert_profiles'            => true,
        'edit_expert_profiles'              => true,
        'edit_published_expert_profiles'    => false,
        'delete_expert_profiles'            => false,
        'publish_expert_profiles'           => false,
        'read_private_expert_profiles'      => false,
        'assign_terms'                      => true,
        self::CAP_SUBMIT                    => true,
    ];

    /* Version de la migration des rôles */
    const MIGRATION_VERSION = '1.5.7'; // Incrémenté à chaque migration nécessaire // Incrémenté à chaque migration nécessaire
    const MIGRATION_OPTION  = 'rdr_ed_role_migration_v';

    public static function init(): void {
        add_shortcode('rdr_expert_activate', [self::class, 'shortcode_activate']);
        add_action('wp_ajax_rdr_ed_activate_profile', [self::class, 'ajax_activate_profile']);

        // Migration automatique : synchroniser rôle expert_it ↔ cap CAP_SUBMIT
        // La version dans la clé garantit qu'une nouvelle migration tourne si MIGRATION_VERSION change
        add_action('init', [self::class, 'migrate_roles'], 20);
    }

    public static function activate(): void {
        self::add_role();
        self::grant_admin_caps();
        RDR_ED_Taxonomy::seed_default_terms();
        self::create_pages();
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }

    private static function add_role(): void {
        remove_role(self::ROLE_SLUG);
        add_role(
            self::ROLE_SLUG,
            __(self::ROLE_LABEL, 'rdr-expert-directory'),
            self::EXPERT_CAPS
        );
    }

    /* ── Réparer les caps admin (méthode publique) ── */
    public static function repair_admin_caps(): void {
        self::grant_admin_caps();
    }

    private static function grant_admin_caps(): void {
        $caps = [
            'create_expert_profiles',
            'edit_expert_profiles',
            'edit_others_expert_profiles',
            'edit_published_expert_profiles',
            'publish_expert_profiles',
            'delete_expert_profiles',
            'delete_others_expert_profiles',
            'delete_published_expert_profiles',
            'read_private_expert_profiles',
            self::CAP_SKILLS,
            self::CAP_VALIDATE,
            self::CAP_DEACTIVATE,
            self::CAP_SUBMIT,
        ];

        // 1. Ajouter sur le RÔLE administrator (affecte tous les futurs admins)
        $admin_role = get_role('administrator');
        if ($admin_role) {
            foreach ($caps as $cap) {
                $admin_role->add_cap($cap);
            }
        }

        // 2. Forcer aussi sur TOUS les users ayant le rôle administrator
        //    (les caps usermeta peuvent masquer les caps du rôle)
        global $wpdb;
        $cap_key = $wpdb->get_blog_prefix() . 'capabilities';
        $admin_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = %s AND meta_value LIKE %s",
            $cap_key,
            '%"administrator"%'
        ));

        foreach ($admin_ids as $uid) {
            $user = get_userdata((int) $uid);
            if (!$user) continue;
            if (!in_array('administrator', (array) $user->roles, true)) continue;
            foreach ($caps as $cap) {
                // Seulement si la cap est explicitement refusée (false) dans les usermeta
                // On ne touche pas aux caps déjà vraies
                $user->add_cap($cap, true);
            }
        }
    }

    private static function create_pages(): void {
        $pages = [
            'experts' => [
                'title'   => __('Annuaire des Experts IT', 'rdr-expert-directory'),
                'content' => '[rdr_expert_directory]',
            ],
            'mon-profil-expert' => [
                'title'   => __('Mon profil Expert', 'rdr-expert-directory'),
                'content' => '[rdr_expert_profile_form]',
            ],
        ];

        foreach ($pages as $slug => $data) {
            if (!get_page_by_path($slug)) {
                wp_insert_post([
                    'post_title'   => $data['title'],
                    'post_name'    => $slug,
                    'post_content' => $data['content'],
                    'post_status'  => 'publish',
                    'post_type'    => 'page',
                ]);
            }
        }
    }

    public static function get_profile_page_url(): string {
        $page = get_page_by_path('mon-profil-expert')
             ?? get_page_by_path('my-expert-profile');
        return $page
            ? (get_permalink($page->ID) ?: home_url('/mon-profil-expert/'))
            : home_url('/mon-profil-expert/');
    }

    public static function get_directory_page_url(): string {
        return get_post_type_archive_link('expert_profile') ?: home_url('/experts/');
    }

    // =========================================================
    // HELPER CENTRALISÉ : vérification d'accès aux features expert
    //
    // Logique par défaut : OR (rôle OU capacité suffit)
    // require_both=true  : AND (rôle ET capacité requis)
    //
    // Paramètres (depuis les atts shortcode) :
    //   roles        : slug(s) de rôle séparés par virgule (ex: "expert_it,administrator")
    //                  Défaut : "expert_it"
    //   cap          : capacité requise (ex: "wpa_submit_expert_profile")
    //                  Défaut : CAP_SUBMIT
    //   require_both : "true" pour AND, tout autre valeur = OR
    //                  Défaut : "false" (OR)
    // =========================================================
    public static function user_has_expert_access(array $atts = []): bool {
        if (!is_user_logged_in()) return false;

        // Valeurs par défaut
        $roles_str    = trim($atts['roles'] ?? self::ROLE_SLUG);
        $cap          = trim($atts['cap']   ?? self::CAP_SUBMIT);
        $require_both = isset($atts['require_both'])
                        && filter_var($atts['require_both'], FILTER_VALIDATE_BOOLEAN);

        // Parser les rôles (liste CSV)
        $roles = array_filter(array_map('trim', explode(',', $roles_str)));

        // Vérifier les rôles : l'utilisateur a-t-il AU MOINS l'un des rôles ?
        $has_role = false;
        if (!empty($roles)) {
            $user = wp_get_current_user();
            foreach ($roles as $role) {
                if (in_array($role, (array) $user->roles, true)) {
                    $has_role = true;
                    break;
                }
            }
        }

        // Vérifier la capacité
        $has_cap = $cap ? current_user_can($cap) : false;

        // Appliquer la logique OR / AND
        return $require_both
            ? ($has_role && $has_cap)   // AND
            : ($has_role || $has_cap);  // OR
    }

    // =========================================================
    // SHORTCODE [rdr_expert_activate]
    //
    // Paramètres :
    //   roles          : rôle(s) autorisant l'accès (CSV, défaut: "expert_it")
    //   cap            : capacité autorisant l'accès (défaut: CAP_SUBMIT)
    //   require_both   : "true" = AND, sinon OR (défaut: false)
    //   label_activate : texte bouton activation (défaut: "Activer mon profil Expert")
    //   label_manage   : texte bouton gestion (défaut: "Gérer mon profil Expert")
    //   show_login     : "true" = afficher un lien si non connecté (défaut: false)
    //   login_text     : texte du lien connexion (défaut: "Accéder à l'espace Expert")
    //   login_url      : URL du lien connexion — pointer vers la page WPA magic link
    //
    // ⚠️  L'authentification est gérée par WPA (magic link).
    //     Le plugin ne génère JAMAIS de lien vers wp-login.php.
    //     Par défaut, rien n'est affiché aux visiteurs non connectés.
    // =========================================================
    public static function shortcode_activate(array $atts): string {
        $atts = shortcode_atts([
            'roles'          => self::ROLE_SLUG,
            'cap'            => self::CAP_SUBMIT,
            'require_both'   => 'false',
            'label_activate' => __('Activer mon profil Expert', 'rdr-expert-directory'),
            'label_manage'   => __('Gerer mon profil Expert', 'rdr-expert-directory'),
            'show_login'     => 'false',
            'login_text'     => __('Acceder a l\'espace Expert', 'rdr-expert-directory'),
            'login_url'      => '',
        ], $atts, 'rdr_expert_activate');

        // Non connecté
        if (!is_user_logged_in()) {
            if (filter_var($atts['show_login'], FILTER_VALIDATE_BOOLEAN) && !empty($atts['login_url'])) {
                return '<a href="' . esc_url($atts['login_url']) . '" class="button rdr-activate-btn rdr-activate-btn--login">' .
                       esc_html($atts['login_text']) . '</a>';
            }
            return ''; // Silencieux par défaut
        }

        $user_id  = get_current_user_id();
        $user     = wp_get_current_user();
        $has_role = in_array(self::ROLE_SLUG, (array) $user->roles, true);
        $has_cap  = current_user_can(self::CAP_SUBMIT);
        $url      = self::get_profile_page_url();

        // Déjà expert_it → lien vers gestion
        if ($has_role || $has_cap) {
            $post  = RDR_ED_Profile_Form::get_user_profile($user_id);
            $icon  = $post ? '&#x1F464;' : '&#x2728;';
            $label = esc_html($atts['label_manage']);
            return '<a href="' . esc_url($url) . '" class="button rdr-activate-btn rdr-activate-btn--active">' .
                   $icon . ' ' . $label . '</a>';
        }

        // Vérifier si l'utilisateur a accès au bouton d'activation
        // selon les paramètres roles/cap/require_both du shortcode
        if (!self::user_has_expert_access($atts)) {
            return ''; // Silencieux si pas d'accès
        }

        // Accès OK mais pas encore expert_it → bouton d'activation
        $nonce = wp_create_nonce('rdr_ed_nonce');
        $ajax  = esc_url(admin_url('admin-ajax.php'));

        return '<div class="rdr-activate-wrap">
            <button type="button" class="button button-lg rdr-activate-btn rdr-activate-btn--enable"
                id="rdr-activate-profile-btn"
                data-nonce="' . esc_attr($nonce) . '"
                data-ajaxurl="' . $ajax . '"
                data-redirect="' . esc_url($url) . '">
                &#x2728; ' . esc_html($atts['label_activate']) . '
            </button>
            <div id="rdr-activate-result" class="rdr-form-message" style="display:none;margin-top:8px;"></div>
        </div>';
    }

    /* ── AJAX : Activer le profil expert ── */
    public static function ajax_activate_profile(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Connexion requise.', 'rdr-expert-directory')]);
        }

        $user_id = get_current_user_id();
        $user    = get_userdata($user_id);
        if (!$user) {
            wp_send_json_error(['message' => __('Utilisateur introuvable.', 'rdr-expert-directory')]);
        }

        // Assigner le rôle expert_it ET la capacité CAP_SUBMIT
        $user->add_role(self::ROLE_SLUG);
        $user->add_cap(self::CAP_SUBMIT);

        wp_send_json_success([
            'message'  => __('Votre profil expert est active ! Redirection...', 'rdr-expert-directory'),
            'redirect' => self::get_profile_page_url(),
        ]);
    }

    /* ── Migration : assigner expert_it aux users qui ont CAP_SUBMIT ── */
    public static function migrate_roles(): void {
        // Ne tourner qu'une seule fois par version
        if (get_option(self::MIGRATION_OPTION . self::MIGRATION_VERSION, false)) return;

        // Réparer d'abord les caps admin (au cas où la migration précédente les aurait altérées)
        self::grant_admin_caps();

        // ⚠️  get_users(['capability' => ...]) n'est pas fiable dans WP.
        //     On passe par WP_User_Query avec meta_query sur wp_capabilities.
        //     Le meta 'wp_capabilities' (ou '{prefix}capabilities') contient un tableau sérialisé
        //     des caps de l'utilisateur. On cherche ceux qui ont CAP_SUBMIT.
        global $wpdb;
        $cap_key = $wpdb->get_blog_prefix() . 'capabilities';
        $cap_val = self::CAP_SUBMIT;

        $user_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
             WHERE meta_key = %s
             AND meta_value LIKE %s",
            $cap_key,
            '%' . $wpdb->esc_like($cap_val) . '%'
        ));

        $migrated = 0;
        foreach ($user_ids as $uid) {
            $uid  = (int) $uid;
            $user = get_userdata($uid);
            if (!$user) continue;

            // Ne JAMAIS modifier les administrateurs
            if (in_array('administrator', (array) $user->roles, true)) continue;

            // Assigner expert_it uniquement si absent
            if (!in_array(self::ROLE_SLUG, (array) $user->roles, true)) {
                $user->add_role(self::ROLE_SLUG);
                $migrated++;
            }
        }

        // Marquer la migration comme effectuée
        update_option(self::MIGRATION_OPTION . self::MIGRATION_VERSION, [
            'date'      => current_time('mysql'),
            'migrated'  => $migrated,
        ], false);

        error_log(sprintf(
            '[rdr-expert-directory] Migration v%s : %d utilisateur(s) → rôle %s. Caps admin restaurées.',
            self::MIGRATION_VERSION, $migrated, self::ROLE_SLUG
        ));
    }

        public static function user_can_manage(): bool {
        return current_user_can(self::CAP_VALIDATE);
    }

    public static function user_can_submit(): bool {
        return is_user_logged_in() && current_user_can(self::CAP_SUBMIT);
    }
}
