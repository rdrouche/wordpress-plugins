<?php
defined('ABSPATH') || exit;

class RDR_ED_Notifications {

    public static function init(): void {}

    private static function get_admin_email(): string {
        $custom = RDR_ED_Settings::get('notif_admin_email');
        return ($custom && is_email($custom)) ? $custom : get_option('admin_email');
    }

    private static function send(string $to, string $subject, string $message): bool {
        return wp_mail($to, $subject, $message, ['Content-Type: text/plain; charset=UTF-8']);
    }

    public static function notify_admin_submission(int $post_id, array $data): void {
        $post   = get_post($post_id);
        if (!$post) return;
        $author = get_userdata($post->post_author);
        $subject = '[' . get_bloginfo('name') . '] Nouvelle fiche expert a valider : ' . $post->post_title;
        $msg  = "Bonjour,\n\nUne nouvelle fiche expert a ete soumise et est en attente de validation.\n\n";
        $msg .= "Expert : " . ($author ? $author->display_name : 'Inconnu') . "\n";
        $msg .= "Email  : " . ($author ? $author->user_email : '—') . "\n\n";
        $msg .= "Examiner : " . admin_url('post.php?post=' . $post_id . '&action=edit') . "\n\n";
        $msg .= "-- \n" . get_bloginfo('name');
        self::send(self::get_admin_email(), $subject, $msg);
    }

    public static function notify_expert_approved(int $post_id): void {
        $post   = get_post($post_id);
        $author = $post ? get_userdata($post->post_author) : null;
        if (!$author) return;
        $subject = '[' . get_bloginfo('name') . '] Votre fiche expert a ete publiee !';
        $msg  = "Bonjour " . $author->display_name . ",\n\n";
        $msg .= "Votre fiche expert a ete validee et est maintenant visible dans l'annuaire.\n\n";
        $msg .= "Voir votre fiche : " . get_permalink($post_id) . "\n\n";
        $msg .= "-- \n" . get_bloginfo('name');
        self::send($author->user_email, $subject, $msg);
    }

    public static function notify_expert_rejected(int $post_id, string $reason = ''): void {
        $post   = get_post($post_id);
        $author = $post ? get_userdata($post->post_author) : null;
        if (!$author) return;
        $subject = '[' . get_bloginfo('name') . '] Votre fiche expert necessite des modifications';
        $msg  = "Bonjour " . $author->display_name . ",\n\n";
        $msg .= "Votre fiche expert ne peut pas etre publiee en l'etat.\n\n";
        if ($reason) $msg .= "Motif : \"" . $reason . "\"\n\n";
        $msg .= "Modifier votre fiche : " . home_url('/mon-profil-expert/') . "\n\n";
        $msg .= "-- \n" . get_bloginfo('name');
        self::send($author->user_email, $subject, $msg);
    }
}
