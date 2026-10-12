<?php
defined('ABSPATH') || exit;

/**
 * Suppression totale du profil expert — RDR Expert Directory
 *
 * Permet à un expert de supprimer définitivement toutes ses données :
 *  - Fiche expert (post + metas + révisions)
 *  - Médias uploadés (photos, bannières) + fichiers physiques
 *  - Messages reçus dans la messagerie interne
 *  - Versions archivées et données en attente
 *
 * Processus :
 *  1. L'expert clique "Supprimer mon profil" sur la page formulaire
 *  2. Une confirmation modale s'affiche avec avertissement clair
 *  3. L'expert doit taper "DeleteMyProfil" pour confirmer
 *  4. Suppression immédiate et irréversible
 *  5. Notification email à l'admin
 */
class RDR_ED_Profile_Deletion {

    const CONFIRM_PHRASE = 'DeleteMyProfil';

    public static function init(): void {
        add_action('wp_ajax_rdr_ed_delete_profile', [self::class, 'ajax_delete']);
    }

    /* ── AJAX : Suppression du profil ── */
    public static function ajax_delete(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');

        if (!RDR_ED_Capabilities::user_can_submit()) {
            wp_send_json_error(['message' => __('Accès refusé.', 'rdr-expert-directory')], 403);
        }

        // Vérifier la phrase de confirmation
        $confirm = sanitize_text_field($_POST['confirm_phrase'] ?? '');
        if ($confirm !== self::CONFIRM_PHRASE) {
            wp_send_json_error([
                'message' => sprintf(
                    __('Phrase de confirmation incorrecte. Tapez exactement : %s', 'rdr-expert-directory'),
                    '<strong>' . self::CONFIRM_PHRASE . '</strong>'
                )
            ]);
        }

        $user_id = get_current_user_id();
        $post    = RDR_ED_Profile_Form::get_user_profile($user_id);

        if (!$post) {
            wp_send_json_error(['message' => __('Aucune fiche trouvée.', 'rdr-expert-directory')]);
        }

        $post_id   = $post->ID;
        $post_title = $post->post_title;

        // 1. Supprimer les médias (photos, bannières) + fichiers physiques
        RDR_ED_Media::delete_user_media($user_id);

        // 2. Supprimer les messages de la messagerie interne
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . RDR_ED_Messaging::TABLE_NAME,
            ['post_id' => $post_id],
            ['%d']
        );

        // 3. Supprimer la fiche expert (post + toutes les metas + révisions WP)
        wp_delete_post($post_id, true); // force delete (bypass corbeille)

        // 4. Notifier l'admin
        self::notify_admin($user_id, $post_title);

        wp_send_json_success([
            'message'      => __('Votre profil et toutes vos données ont été supprimés définitivement.', 'rdr-expert-directory'),
            'redirect_url' => home_url('/'),
        ]);
    }

    /* ── Notification admin ── */
    private static function notify_admin(int $user_id, string $post_title): void {
        $user    = get_userdata($user_id);
        $admin   = get_option('admin_email');
        $subject = sprintf('[%s] Profil expert supprimé', get_bloginfo('name'));
        $body    = sprintf(
            "L'expert %s (%s) a supprimé son profil \"%s\" et toutes ses données.\n\nDate : %s",
            $user ? $user->display_name : "ID $user_id",
            $user ? $user->user_email   : '',
            $post_title,
            date_i18n(get_option('date_format') . ' H:i')
        );
        wp_mail($admin, $subject, $body);
    }
}
