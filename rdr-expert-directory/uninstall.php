<?php
/**
 * Uninstall script — RDR Expert Directory
 * Exécuté par WP quand l'admin clique "Supprimer" depuis la liste des plugins.
 *
 * Supprime :
 *  - L'option de paramètres
 *  - Les posts expert_profile et leurs meta
 *  - Les termes des taxonomies custom
 *  - Les transients du plugin
 *  - Le rôle expert_it
 *  - Le sel du token de contact
 *  - La file d'attente des compétences en attente
 *
 * NE supprime PAS :
 *  - Les pages créées (/experts/, /mon-profil-expert/) — l'admin les supprime manuellement
 *  - Les capacités admin (retirées automatiquement avec le rôle)
 *  - Les médias uploadés (photos de profil, bannières) — appartiennent à la médiathèque
 *
 * GDPR : l'utilisateur garde le contrôle de ses données via le tableau de bord WP.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/* ── Option de paramètres ── */
delete_option('rdr_ed_settings');
delete_option('rdr_ed_pending_skills');  // File compétences
delete_option('rdr_ed_token_salt');      // Sel HMAC contact protection

/* ── Posts et meta ── */
$posts = get_posts([
    'post_type'      => 'expert_profile',
    'posts_per_page' => -1,
    'post_status'    => 'any',
    'fields'         => 'ids',
]);

foreach ($posts as $post_id) {
    wp_delete_post($post_id, true); // force delete (bypass corbeille)
}

/* ── Termes des taxonomies ── */
$taxonomies = ['expert_skill', 'expert_language', 'expert_country'];
foreach ($taxonomies as $tax) {
    $terms = get_terms(['taxonomy' => $tax, 'hide_empty' => false, 'fields' => 'ids']);
    if (!is_wp_error($terms)) {
        foreach ($terms as $term_id) {
            wp_delete_term($term_id, $tax);
        }
    }
}

/* ── Transients du plugin ── */
global $wpdb;
// Supprimer tous les transients avec le préfixe rdr_ed_
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_rdr_ed_%'
        OR option_name LIKE '_transient_timeout_rdr_ed_%'"
);

/* ── Rôle expert_it (= RDR_ED_Capabilities::ROLE_SLUG) ── */
remove_role('expert_it');

/* ── Capacités admin ── */
$admin = get_role('administrator');
if ($admin) {
    // Capacités correspondant aux constantes RDR_ED_Capabilities::CAP_*
    $caps = [
        'create_expert_profiles', 'edit_expert_profiles',
        'edit_others_expert_profiles', 'edit_published_expert_profiles',
        'publish_expert_profiles', 'delete_expert_profiles',
        'delete_others_expert_profiles', 'delete_published_expert_profiles',
        'read_private_expert_profiles',
        'rdr_manage_expert_skills',   // CAP_SKILLS
        'rdr_validate_expert',        // CAP_VALIDATE
        'rdr_deactivate_expert',      // CAP_DEACTIVATE
        'rdr_submit_expert_profile',  // CAP_SUBMIT
    ];
    foreach ($caps as $cap) {
        $admin->remove_cap($cap);
    }
}

/* ── Flush rewrite rules ── */
flush_rewrite_rules();
