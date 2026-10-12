<?php
defined('ABSPATH') || exit;

/**
 * Page de paramètres du plugin RDR Expert Directory.
 *
 * Sections :
 *  1. Compétences — limite par expert, tags libres autorisés
 *  2. Notifications — activation par type, email admin, expéditeur
 *  3. Annuaire — résultats par page, ordre de tri
 *  4. Profil — champs obligatoires, options d'affichage
 */
class RDR_ED_Settings {

    const OPTION_KEY = 'rdr_ed_settings';

    public static function init(): void {
        add_action('admin_menu',  [self::class, 'add_page']);
        add_action('admin_init',  [self::class, 'register_settings']);
        add_action('admin_init',  [self::class, 'handle_save']);
    }

    /* ── Valeurs par défaut ── */
    public static function defaults(): array {
        return [
            /* Compétences */
            'skill_max'              => 5,      // Nb max de compétences par fiche
            'skill_allow_free_tags'  => 1,      // Expert peut proposer de nouveaux tags
            'skill_free_tags_review' => 1,      // Les nouveaux tags nécessitent validation

            /* Notifications */
            'notif_admin_submit'     => 1,      // Email admin à la soumission
            'notif_expert_approved'  => 1,      // Email expert à la validation
            'notif_expert_rejected'  => 1,      // Email expert au refus
            'notif_admin_email'      => '',     // Email admin (vide = admin_email WP)
            'notif_from_name'        => 'RDR-IT Annuaire',
            'notif_from_email'       => '',     // Vide = WP default

            /* Annuaire */
            'directory_per_page'     => 12,
            'directory_order'        => 'rand', // rand | date | name
            'directory_show_type'    => 1,      // Afficher badge indépendant/entreprise
            'directory_show_country' => 1,
            'directory_show_lang'    => 1,

            /* Profil */
            'profile_require_photo'  => 0,
            'profile_require_skills' => 1,
            'profile_require_desc'   => 0,
            'profile_page_id'        => 0,      // ID de la page formulaire

            /* Contact */
            'contact_form_enabled'   => 1,   // Formulaire de contact activable globalement
            'contact_email_reveal'   => 1,   // Affichage de l'email (click to reveal)

            /* Shortlink */
            'shortlink_enabled'      => 1,

            /* RankMath */
            'rankmath_default_noindex' => 0,    // Noindex par défaut sur les nouvelles fiches
        ];
    }

    /* ── Getter centralisé ── */
    public static function get(string $key, $fallback = null) {
        $opts = get_option(self::OPTION_KEY, []);
        $defs = self::defaults();
        if (array_key_exists($key, $opts)) return $opts[$key];
        if (array_key_exists($key, $defs)) return $defs[$key];
        return $fallback;
    }

    /* ── Setter batch ── */
    public static function save(array $data): void {
        $current = get_option(self::OPTION_KEY, []);
        update_option(self::OPTION_KEY, array_merge($current, $data));
    }

    /* ── Enregistrement WP Settings API ── */
    public static function register_settings(): void {
        register_setting(
            'rdr_ed_settings_group',
            self::OPTION_KEY,
            [
                'sanitize_callback' => [self::class, 'sanitize'],
                'default'           => self::defaults(),
            ]
        );
    }

    public static function sanitize(array $input): array {
        $clean = self::defaults();

        // Integers
        $ints = ['skill_max', 'directory_per_page', 'profile_page_id'];
        foreach ($ints as $k) {
            if (isset($input[$k])) $clean[$k] = max(1, (int) $input[$k]);
        }
        $clean['skill_max'] = min(50, max(1, (int) ($input['skill_max'] ?? 5)));
        $clean['directory_per_page'] = min(48, max(4, (int) ($input['directory_per_page'] ?? 12)));

        // Booleans (checkboxes)
        $bools = [
            'skill_allow_free_tags', 'skill_free_tags_review',
            'notif_admin_submit', 'notif_expert_approved', 'notif_expert_rejected',
            'directory_show_type', 'directory_show_country', 'directory_show_lang',
            'profile_require_photo', 'profile_require_skills', 'profile_require_desc',
            'contact_form_enabled', 'contact_email_reveal',
            'shortlink_enabled', 'rankmath_default_noindex',
        ];
        foreach ($bools as $k) {
            $clean[$k] = isset($input[$k]) ? 1 : 0;
        }

        // Strings / emails / select
        $clean['directory_order']   = in_array($input['directory_order'] ?? '', ['rand', 'date', 'name', 'modified'])
                                      ? $input['directory_order'] : 'rand';
        $clean['notif_admin_email']      = sanitize_email($input['notif_admin_email'] ?? '');
        $clean['notif_message_reminder'] = isset($input['notif_message_reminder']) ? 1 : 0;
        $clean['notif_reminder_hour']    = max(0, min(23, (int) ($input['notif_reminder_hour'] ?? 8)));
        $clean['notif_from_name']   = sanitize_text_field($input['notif_from_name'] ?? '');
        $clean['notif_from_email']  = sanitize_email($input['notif_from_email'] ?? '');

        return $clean;
    }

    /* ── Save manuel (pour compat avec notre handle_save) ── */
    public static function handle_save(): void {
        // Géré nativement par register_setting + options.php
    }

    /* ── Page admin ── */
    public static function add_page(): void {
        add_submenu_page(
            'edit.php?post_type=expert_profile',
            __('Paramètres — Annuaire Expert', 'rdr-expert-directory'),
            __('⚙️ Paramètres', 'rdr-expert-directory'),
            'manage_options',
            'rdr-ed-settings',
            [self::class, 'render_page']
        );
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;

        // Feedback sauvegarde
        $saved = isset($_GET['settings-updated']) && $_GET['settings-updated'];
        $o     = function(string $k) { return self::get($k); }; // shortcut
        ?>
        <div class="wrap rdr-ed-settings-wrap">
            <h1>
                <span style="color:#2ea8ff;">⚙️</span>
                <?php _e('Paramètres — Annuaire Expert IT', 'rdr-expert-directory'); ?>
            </h1>

            <?php if ($saved): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php _e('Paramètres enregistrés.', 'rdr-expert-directory'); ?></p>
            </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('rdr_ed_settings_group'); ?>

                <div class="rdr-settings-grid">

                    <!-- ══ COMPÉTENCES ══ -->
                    <div class="rdr-settings-card">
                        <h2 class="rdr-settings-card-title">
                            <span>⚡</span> <?php _e('Compétences & Tags', 'rdr-expert-directory'); ?>
                        </h2>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="skill_max"><?php _e('Nombre maximum de compétences par fiche', 'rdr-expert-directory'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="skill_max"
                                        name="<?= self::OPTION_KEY; ?>[skill_max]"
                                        value="<?= (int) $o('skill_max'); ?>"
                                        min="1" max="50" step="1"
                                        class="small-text">
                                    <p class="description"><?php _e('Défaut : 5. Maximum absolu : 50.', 'rdr-expert-directory'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Tags libres', 'rdr-expert-directory'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            name="<?= self::OPTION_KEY; ?>[skill_allow_free_tags]"
                                            value="1" <?= checked($o('skill_allow_free_tags'), 1, false); ?>>
                                        <?php _e('Autoriser les experts à proposer de nouvelles compétences', 'rdr-expert-directory'); ?>
                                    </label>
                                    <br><br>
                                    <label>
                                        <input type="checkbox"
                                            name="<?= self::OPTION_KEY; ?>[skill_free_tags_review]"
                                            value="1" <?= checked($o('skill_free_tags_review'), 1, false); ?>>
                                        <?php _e('Les nouvelles compétences proposées nécessitent une validation admin avant d\'être visibles', 'rdr-expert-directory'); ?>
                                    </label>
                                    <p class="description"><?php _e('Si non coché, les tags libres sont créés immédiatement dans la taxonomie.', 'rdr-expert-directory'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- ══ NOTIFICATIONS ══ -->
                    <div class="rdr-settings-card">
                        <h2 class="rdr-settings-card-title">
                            <span>📬</span> <?php _e('Notifications email', 'rdr-expert-directory'); ?>
                        </h2>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php _e('Événements notifiés', 'rdr-expert-directory'); ?></th>
                                <td>
                                    <?php
                                    $notifs = [
                                        'notif_admin_submit'    => __('📥 Notifier l\'admin à chaque soumission de fiche', 'rdr-expert-directory'),
                                        'notif_expert_approved' => __('✅ Notifier l\'expert quand sa fiche est validée', 'rdr-expert-directory'),
                                        'notif_expert_rejected' => __('❌ Notifier l\'expert quand sa fiche est refusée', 'rdr-expert-directory'),
                                    ];
                                    foreach ($notifs as $key => $label): ?>
                                    <label style="display:block;margin-bottom:8px;">
                                        <input type="checkbox"
                                            name="<?= self::OPTION_KEY; ?>[<?= $key; ?>]"
                                            value="1" <?= checked($o($key), 1, false); ?>>
                                        <?= $label; ?>
                                    </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="notif_admin_email"><?php _e('Email administrateur', 'rdr-expert-directory'); ?></label>
                                </th>
                                <td>
                                    <input type="email" id="notif_admin_email"
                                        name="<?= self::OPTION_KEY; ?>[notif_admin_email]"
                                        value="<?= esc_attr($o('notif_admin_email')); ?>"
                                        class="regular-text"
                                        placeholder="<?= esc_attr(get_option('admin_email')); ?>">
                                    <p class="description"><?php _e('Laisser vide pour utiliser l\'email administrateur WordPress.', 'rdr-expert-directory'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Expéditeur des emails', 'rdr-expert-directory'); ?></th>
                                <td>
                                    <input type="text" id="notif_from_name"
                                        name="<?= self::OPTION_KEY; ?>[notif_from_name]"
                                        value="<?= esc_attr($o('notif_from_name')); ?>"
                                        class="regular-text"
                                        placeholder="RDR-IT Annuaire">
                                    <br><br>
                                    <input type="email" id="notif_from_email"
                                        name="<?= self::OPTION_KEY; ?>[notif_from_email]"
                                        value="<?= esc_attr($o('notif_from_email')); ?>"
                                        class="regular-text"
                                        placeholder="<?php _e('Laisser vide = défaut WordPress', 'rdr-expert-directory'); ?>">
                                    <p class="description"><?php _e('Nom et email affichés dans "De :" dans les emails envoyés.', 'rdr-expert-directory'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- ══ ANNUAIRE ══ -->
                    <div class="rdr-settings-card">
                        <h2 class="rdr-settings-card-title">
                            <span>📋</span> <?php _e('Annuaire public', 'rdr-expert-directory'); ?>
                        </h2>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row">
                                    <label for="directory_per_page"><?php _e('Fiches par page', 'rdr-expert-directory'); ?></label>
                                </th>
                                <td>
                                    <input type="number" id="directory_per_page"
                                        name="<?= self::OPTION_KEY; ?>[directory_per_page]"
                                        value="<?= (int) $o('directory_per_page'); ?>"
                                        min="4" max="48" step="1"
                                        class="small-text">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="directory_order"><?php _e('Ordre d\'affichage', 'rdr-expert-directory'); ?></label>
                                </th>
                                <td>
                                    <select id="directory_order" name="<?= self::OPTION_KEY; ?>[directory_order]">
                                        <option value="rand"     <?= selected($o('directory_order'), 'rand', false); ?>><?php _e('Aléatoire (à chaque chargement)', 'rdr-expert-directory'); ?></option>
                                        <option value="date"     <?= selected($o('directory_order'), 'date', false); ?>><?php _e('Plus récents en premier', 'rdr-expert-directory'); ?></option>
                                        <option value="name"     <?= selected($o('directory_order'), 'name', false); ?>><?php _e('Ordre alphabétique', 'rdr-expert-directory'); ?></option>
                                        <option value="modified" <?= selected($o('directory_order'), 'modified', false); ?>><?php _e('Dernière modification', 'rdr-expert-directory'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Informations affichées sur les cards', 'rdr-expert-directory'); ?></th>
                                <td>
                                    <?php
                                    $card_opts = [
                                        'directory_show_type'    => __('Badge Indépendant / Entreprise', 'rdr-expert-directory'),
                                        'directory_show_country' => __('Pays', 'rdr-expert-directory'),
                                        'directory_show_lang'    => __('Langue(s)', 'rdr-expert-directory'),
                                    ];
                                    foreach ($card_opts as $key => $label): ?>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox"
                                            name="<?= self::OPTION_KEY; ?>[<?= $key; ?>]"
                                            value="1" <?= checked($o($key), 1, false); ?>>
                                        <?= $label; ?>
                                    </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- ══ PROFIL ══ -->
                    <div class="rdr-settings-card">
                        <h2 class="rdr-settings-card-title">
                            <span>👤</span> <?php _e('Formulaire de profil', 'rdr-expert-directory'); ?>
                        </h2>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php _e('Champs obligatoires', 'rdr-expert-directory'); ?></th>
                                <td>
                                    <?php
                                    $required = [
                                        'profile_require_photo'  => __('Photo de profil obligatoire', 'rdr-expert-directory'),
                                        'profile_require_skills' => __('Au moins une compétence obligatoire', 'rdr-expert-directory'),
                                        'profile_require_desc'   => __('Description / présentation obligatoire', 'rdr-expert-directory'),
                                    ];
                                    foreach ($required as $key => $label): ?>
                                    <label style="display:block;margin-bottom:6px;">
                                        <input type="checkbox"
                                            name="<?= self::OPTION_KEY; ?>[<?= $key; ?>]"
                                            value="1" <?= checked($o($key), 1, false); ?>>
                                        <?= $label; ?>
                                    </label>
                                    <?php endforeach; ?>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- ══ CONTACT ══ -->
                    <div class="rdr-settings-card">
                        <h2 class="rdr-settings-card-title">
                            <span>📬</span> <?php _e('Contact & Email', 'rdr-expert-directory'); ?>
                        </h2>
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php _e('Révélation de l\'email', 'rdr-expert-directory'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            name="<?= self::OPTION_KEY; ?>[contact_email_reveal]"
                                            value="1" <?= checked($o('contact_email_reveal'), 1, false); ?>>
                                        <?php _e('Permettre aux experts d\'activer la révélation de leur email (click to reveal, protégé anti-bot)', 'rdr-expert-directory'); ?>
                                    </label>
                                    <p class="description"><?php _e('L\'email n\'est jamais affiché en clair dans le HTML. Il est révélé à la demande via AJAX sécurisé.', 'rdr-expert-directory'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Formulaire de contact', 'rdr-expert-directory'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            name="<?= self::OPTION_KEY; ?>[contact_form_enabled]"
                                            value="1" <?= checked($o('contact_form_enabled'), 1, false); ?>>
                                        <?php _e('Activer le formulaire de contact sur les fiches expert', 'rdr-expert-directory'); ?>
                                    </label>
                                    <p class="description">
                                        <?php _e('Le formulaire utilise <code>wp_mail()</code>. Désactivez-le si votre hébergement ou plan email ne le permet pas (ex: Mailjet gratuit avec limitations).', 'rdr-expert-directory'); ?>
                                        <br><?php _e('Chaque expert peut aussi désactiver le formulaire depuis son profil.', 'rdr-expert-directory'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <!-- ══ OPTIONS AVANCÉES ══ -->
                    <div class="rdr-settings-card">
                        <h2 class="rdr-settings-card-title">
                            <span>🔧</span> <?php _e('Options avancées', 'rdr-expert-directory'); ?>
                        </h2>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php _e('Shortlinks', 'rdr-expert-directory'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            name="<?= self::OPTION_KEY; ?>[shortlink_enabled]"
                                            value="1" <?= checked($o('shortlink_enabled'), 1, false); ?>>
                                        <?php _e('Activer les shortlinks (/go/expert/ID/) avec compteur de clics', 'rdr-expert-directory'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Référencement (RankMath)', 'rdr-expert-directory'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox"
                                            name="<?= self::OPTION_KEY; ?>[rankmath_default_noindex]"
                                            value="1" <?= checked($o('rankmath_default_noindex'), 1, false); ?>>
                                        <?php _e('Mettre toutes les nouvelles fiches en noindex par défaut', 'rdr-expert-directory'); ?>
                                    </label>
                                    <p class="description"><?php _e('L\'expert peut modifier cette option depuis son formulaire.', 'rdr-expert-directory'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Shortcode annuaire', 'rdr-expert-directory'); ?></th>
                                <td>
                                    <code>[rdr_expert_directory]</code>
                                    <br>
                                    <code>[rdr_expert_directory per_page="12" show_filters="true"]</code>
                                    <p class="description"><?php _e('Insérez ce shortcode sur la page de l\'annuaire.', 'rdr-expert-directory'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Shortcode formulaire', 'rdr-expert-directory'); ?></th>
                                <td>
                                    <code>[rdr_expert_profile_form]</code>
                                    <p class="description"><?php _e('Insérez ce shortcode sur la page "Mon profil Expert".', 'rdr-expert-directory'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Réinitialisation des règles de réécriture', 'rdr-expert-directory'); ?></th>
                                <td>
                                    <a href="<?= esc_url(admin_url('options-permalink.php')); ?>" class="button button-secondary">
                                        <?php _e('Aller dans Réglages > Permaliens et sauvegarder', 'rdr-expert-directory'); ?>
                                    </a>
                                    <p class="description"><?php _e('À faire après l\'activation du plugin si les URL /expert/ ou /go/expert/ ne fonctionnent pas.', 'rdr-expert-directory'); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>

                </div><!-- .rdr-settings-grid -->

                <?php submit_button(__('Enregistrer les paramètres', 'rdr-expert-directory')); ?>

            </form>
        </div><!-- .wrap -->
        <?php
    }
}
