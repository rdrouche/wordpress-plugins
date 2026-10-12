<?php
/**
 * Plugin Name: RDR Expert Directory
 * Plugin URI:  https://rdr-it.com
 * Description: Annuaire d'experts IT avec versioning, validation admin, tags libres, intégration premium WPA et paramètres configurables.
 * Version:     1.5.8
 * Author:      Romain Drouche
 * Author URI:  https://rdr-it.com
 * Text Domain: rdr-expert-directory
 * License:     GPL-2.0+
 */

defined('ABSPATH') || exit;

define('RDR_ED_VERSION', '1.5.8');
define('RDR_ED_FILE',    __FILE__);
define('RDR_ED_DIR',     plugin_dir_path(__FILE__));
define('RDR_ED_URL',     plugin_dir_url(__FILE__));
define('RDR_ED_SLUG',    'rdr-expert-directory');

/* ── Autoload ── */
$rdr_ed_includes = [
    'includes/class-gp-compat.php',       // Doit être chargé avant les templates
    'includes/class-settings.php',
    'includes/class-post-type.php',
    'includes/class-taxonomy.php',
    'includes/class-capabilities.php',
    'includes/class-versioning.php',
    'includes/class-premium.php',
    'includes/class-shortlinks.php',
    'includes/class-notifications.php',
    'includes/class-free-tags.php',
    'includes/class-rankmath.php',
    'includes/class-polylang.php',
    'includes/class-contact-protection.php',
    'admin/class-admin.php',
    'public/class-shortcode.php',
    'public/class-ajax.php',
    'public/class-profile-form.php',
    'includes/class-messaging.php',
    'includes/class-media.php',
    'includes/class-profile-deletion.php',
    'includes/class-featured.php',
];

foreach ($rdr_ed_includes as $file) {
    $path = RDR_ED_DIR . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

/* ── Bootstrap ── */
add_action('plugins_loaded', function () {
    RDR_ED_GP_Compat::init();
    RDR_ED_Settings::init();
    RDR_ED_Post_Type::init();
    RDR_ED_Taxonomy::init();
    RDR_ED_Capabilities::init();

    // Réparation préventive des caps admin
    // Toujours vérifier pour les admins WP (rôle 'administrator' dans la DB)
    // sans passer par current_user_can() qui peut être faux si les caps sont cassées
    if (is_admin()) {
        add_action('init', function () {
            if (!is_user_logged_in()) return;
            $user = wp_get_current_user();
            // Vérifier le rôle directement dans l'objet user (pas via les caps)
            if (in_array('administrator', (array) $user->roles, true)) {
                if (!current_user_can(RDR_ED_Capabilities::CAP_VALIDATE)) {
                    RDR_ED_Capabilities::repair_admin_caps();
                    // Recharger les caps de l'utilisateur courant
                    $user->get_role_caps();
                }
            }
        }, 1); // Priorité 1 = avant tous les autres hooks
    }
    RDR_ED_Versioning::init();
    RDR_ED_Premium::init();
    RDR_ED_Shortlinks::init();
    RDR_ED_Notifications::init();
    RDR_ED_Free_Tags::init();
    RDR_ED_RankMath::init();
    RDR_ED_Polylang::init();
    RDR_ED_Contact_Protection::init();
    if (is_admin()) {
        RDR_ED_Admin::init();
    }
    RDR_ED_Shortcode::init();
    RDR_ED_Ajax::init();
    RDR_ED_Profile_Form::init();
    RDR_ED_Messaging::init();
    RDR_ED_Media::init();
    RDR_ED_Profile_Deletion::init();
    RDR_ED_Featured::init();
});

/* ── Templates expert (single + archive) ── */
add_filter('single_template', function (string $template): string {
    if (is_singular('expert_profile')) {
        $plugin_tpl = RDR_ED_DIR . 'public/templates/single-expert_profile.php';
        $theme_tpl  = locate_template(['single-expert_profile.php']);
        return $theme_tpl ?: $plugin_tpl;
    }
    return $template;
});

add_filter('archive_template', function (string $template): string {
    if (is_post_type_archive('expert_profile')) {
        $plugin_tpl = RDR_ED_DIR . 'public/templates/archive-expert_profile.php';
        $theme_tpl  = locate_template(['archive-expert_profile.php']);
        return $theme_tpl ?: $plugin_tpl;
    }
    return $template;
});


add_filter('page_template', function (string $template): string {
    global $post;
    if (!$post) return $template;
    // Page "mon-profil-expert" : utilise le template GP intégré si dispo dans thème enfant
    if ($post->post_name === 'mon-profil-expert' || $post->post_name === 'my-expert-profile') {
        $theme_tpl = locate_template(['page-mon-profil-expert.php']);
        if ($theme_tpl) return $theme_tpl;
        // Sinon on laisse GP gérer normalement (shortcode dans le contenu WP)
    }
    return $template;
});

/* ── Activation / Désactivation ── */
register_activation_hook(__FILE__, function() {
    RDR_ED_Capabilities::activate();
    RDR_ED_Messaging::create_table();
    RDR_ED_Featured::schedule_cron();
    RDR_ED_Messaging::schedule_reminder();
});
register_deactivation_hook(__FILE__, function() {
    RDR_ED_Capabilities::deactivate();
    RDR_ED_Featured::unschedule_cron();
    RDR_ED_Messaging::unschedule_reminder();
});

/* ── Champs settings additionnels (Messagerie + Mise en avant) ── */
add_action('rdr_ed_settings_extra', ['RDR_ED_Featured', 'settings_fields']);

/* ── Expéditeur emails personnalisé ── */
add_filter('wp_mail_from', function (string $email): string {
    $c = RDR_ED_Settings::get('notif_from_email');
    return $c ?: $email;
});
add_filter('wp_mail_from_name', function (string $name): string {
    $c = RDR_ED_Settings::get('notif_from_name');
    return $c ?: $name;
});

/* ── Assets publics ── */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('rdr-ed-public', RDR_ED_URL . 'public/css/expert-directory.css', [], RDR_ED_VERSION);
    wp_enqueue_script('rdr-ed-public', RDR_ED_URL . 'public/js/expert-directory.js', [], RDR_ED_VERSION, true);
    wp_localize_script('rdr-ed-public', 'RDR_ED', [
        'ajaxurl'    => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('rdr_ed_nonce'),
        'skill_max'  => (int) RDR_ED_Settings::get('skill_max'),
        'allow_free' => (bool) RDR_ED_Settings::get('skill_allow_free_tags'),
        'i18n'       => [
            'loading'       => __('Chargement...', 'rdr-expert-directory'),
            'no_results'    => __('Aucun expert trouve.', 'rdr-expert-directory'),
            'send_ok'       => __('Message envoye !', 'rdr-expert-directory'),
            'send_error'    => __('Erreur.', 'rdr-expert-directory'),
            'skill_max'     => sprintf(__('Maximum %d competences.', 'rdr-expert-directory'), (int) RDR_ED_Settings::get('skill_max')),
            'skill_pending' => __('Competence soumise pour validation.', 'rdr-expert-directory'),
            'suggest_skill' => __('Proposer une competence...', 'rdr-expert-directory'),
        ],
    ]);
    if (is_page() || is_singular() || is_post_type_archive('expert_profile')) {
        wp_enqueue_media();
    }
});

/* ── Assets admin ── */
add_action('admin_enqueue_scripts', function (string $hook) {
    $pt = get_post_type() ?: ($_GET['post_type'] ?? '');
    if ($pt !== 'expert_profile' && strpos($hook, 'rdr-ed') === false && strpos($hook, 'expert') === false) return;
    wp_enqueue_style('rdr-ed-admin', RDR_ED_URL . 'admin/css/admin.css', [], RDR_ED_VERSION);
    wp_enqueue_script('rdr-ed-admin', RDR_ED_URL . 'admin/js/admin.js', ['jquery'], RDR_ED_VERSION, true);
    wp_localize_script('rdr-ed-admin', 'RDR_ED_Admin', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('rdr_ed_admin_nonce'),
    ]);
});

/* ── Admin bar badge en attente ── */
add_action('admin_bar_menu', function (WP_Admin_Bar $bar) {
    if (!current_user_can(RDR_ED_Capabilities::CAP_VALIDATE)) return;
    $posts  = count(get_posts(['post_type' => 'expert_profile', 'posts_per_page' => -1, 'meta_key' => '_expert_pending_data', 'meta_compare' => 'EXISTS', 'fields' => 'ids']));
    $skills = RDR_ED_Free_Tags::count_pending();
    $total  = $posts + $skills;
    if (!$total) return;
    $bar->add_node([
        'id'    => 'rdr-ed-pending',
        'title' => '⏳ Experts : ' . $total . ' en attente',
        'href'  => admin_url('edit.php?post_type=expert_profile&page=rdr-ed-pending'),
    ]);
}, 100);

/* ── Routage des notifications (contrôlé par paramètres) ── */
add_action('rdr_ed_profile_submitted_notify', function (int $post_id, array $data) {
    if ((int) RDR_ED_Settings::get('notif_admin_submit')) {
        RDR_ED_Notifications::notify_admin_submission($post_id, $data);
    }
}, 10, 2);

add_action('rdr_ed_profile_approved', function (int $post_id) {
    if ((int) RDR_ED_Settings::get('notif_expert_approved')) {
        RDR_ED_Notifications::notify_expert_approved($post_id);
    }
});

add_action('rdr_ed_profile_rejected', function (int $post_id, string $reason = '') {
    if ((int) RDR_ED_Settings::get('notif_expert_rejected')) {
        RDR_ED_Notifications::notify_expert_rejected($post_id, $reason);
    }
}, 10, 2);

/* ── AJAX admin : forcer tirage experts mis en avant ── */
add_action('wp_ajax_rdr_ed_refresh_featured', function () {
    check_ajax_referer('rdr_ed_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden');
    RDR_ED_Featured::refresh();
    $count = count(get_option(RDR_ED_Featured::OPTION_KEY, []));
    wp_send_json_success(['count' => $count, 'message' => sprintf(
        __('%d expert(s) mis en avant.', 'rdr-expert-directory'), $count
    )]);
});

/* ── Reset rate limit annuaire public ── */
add_action('rdr_ed_reset_rate', function (string $key) {
    delete_option($key);
});
