<?php
defined('ABSPATH') || exit;

/**
 * AJAX sécurisé — révélation contact + envoi message.
 * Utilise RDR_ED_Contact_Protection pour la protection anti-scraping.
 */
class RDR_ED_Ajax {

    public static function init(): void {
        add_action('wp_ajax_rdr_ed_reveal_contact',        [self::class, 'reveal_contact']);
        add_action('wp_ajax_nopriv_rdr_ed_reveal_contact', [self::class, 'reveal_contact']);
        add_action('wp_ajax_rdr_ed_send_message',          [self::class, 'send_message']);
        add_action('wp_ajax_nopriv_rdr_ed_send_message',   [self::class, 'send_message']);
    }

    /* ── Révéler email / téléphone / LinkedIn ── */
    public static function reveal_contact(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');

        // Protection multi-couches
        $errors = apply_filters('rdr_ed_contact_protection_checks', [
            'session_token'    => sanitize_text_field($_POST['sec_token'] ?? ''),
            'challenge_answer' => (int) ($_POST['sec_answer'] ?? 0),
            'challenge_token'  => sanitize_text_field($_POST['sec_ctoken'] ?? ''),
            'post_id'          => (int) ($_POST['post_id'] ?? 0),
        ]);

        if (!empty($errors)) {
            $code = $errors[0];
            wp_send_json_error([
                'message'   => RDR_ED_Contact_Protection::error_message($code),
                'code'      => $code,
            ], 429);
        }

        $post_id = (int) ($_POST['post_id'] ?? 0);
        $type    = in_array($_POST['type'] ?? '', ['email', 'phone', 'linkedin'], true) ? $_POST['type'] : '';

        if (!$post_id || !$type) {
            wp_send_json_error(['message' => __('Requête invalide.', 'rdr-expert-directory')]);
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'expert_profile' || $post->post_status !== 'publish') {
            wp_send_json_error(['message' => __('Fiche introuvable.', 'rdr-expert-directory')]);
        }

        // Vérifier que ce moyen de contact est autorisé par l'expert
        $allowed_methods = (array) get_post_meta($post_id, '_expert_contact_methods', true);
        if (!in_array($type, $allowed_methods, true)) {
            wp_send_json_error(['message' => __('Ce moyen de contact n\'est pas disponible.', 'rdr-expert-directory')]);
        }

        switch ($type) {
            case 'email':
                $public = get_post_meta($post_id, '_expert_email_public', true);
                if (!$public) wp_send_json_error(['message' => __('Email non disponible.', 'rdr-expert-directory')]);
                $value = get_post_meta($post_id, '_expert_email', true);
                break;
            case 'phone':
                $public = get_post_meta($post_id, '_expert_phone_public', true);
                if (!$public) wp_send_json_error(['message' => __('Téléphone non disponible.', 'rdr-expert-directory')]);
                $value = get_post_meta($post_id, '_expert_phone', true);
                break;
            case 'linkedin':
                $value = get_post_meta($post_id, '_expert_linkedin_profil', true)
                      ?: get_post_meta($post_id, '_expert_linkedin_page', true);
                break;
        }

        if (empty($value)) {
            wp_send_json_error(['message' => __('Information non renseignée.', 'rdr-expert-directory')]);
        }

        // Logger le contact reveal
        $count = (int) get_post_meta($post_id, '_expert_reveal_count_' . $type, true);
        update_post_meta($post_id, '_expert_reveal_count_' . $type, $count + 1);

        wp_send_json_success(['value' => esc_html($value), 'type' => $type]);
    }

    /* ── Envoyer un message de contact ── */
    public static function send_message(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');

        // Vérification globale : formulaire de contact activé ?
        if (!(bool) RDR_ED_Settings::get('contact_form_enabled', true)) {
            wp_send_json_error(['message' => __('Le formulaire de contact est désactivé.', 'rdr-expert-directory')], 403);
        }

        // Honeypot
        if (!empty($_POST['website_confirm'])) {
            wp_send_json_error(['message' => 'Bot detected.']);
        }

        // Timing anti-bot
        $form_time = (int) ($_POST['_msg_time'] ?? 0);
        if ($form_time && (time() - $form_time) < 3) {
            wp_send_json_error(['message' => __('Veuillez patienter quelques secondes.', 'rdr-expert-directory')]);
        }

        // Protection session
        $sec_errors = apply_filters('rdr_ed_contact_protection_checks', [
            'session_token'    => sanitize_text_field($_POST['sec_token'] ?? ''),
            'challenge_answer' => (int) ($_POST['sec_answer'] ?? 0),
            'challenge_token'  => sanitize_text_field($_POST['sec_ctoken'] ?? ''),
            'post_id'          => (int) ($_POST['post_id'] ?? 0),
        ]);
        if (!empty($sec_errors)) {
            wp_send_json_error(['message' => RDR_ED_Contact_Protection::error_message($sec_errors[0])]);
        }

        $post_id    = (int) ($_POST['post_id'] ?? 0);
        $from_name  = sanitize_text_field($_POST['contact_name'] ?? '');
        $from_email = sanitize_email($_POST['contact_email'] ?? '');
        $subject    = sanitize_text_field($_POST['contact_subject'] ?? '');
        $message    = sanitize_textarea_field($_POST['contact_message'] ?? '');

        // Validations
        $errors = [];
        if (!$from_name)               $errors[] = __('Votre nom est requis.', 'rdr-expert-directory');
        if (!is_email($from_email))    $errors[] = __('Email invalide.', 'rdr-expert-directory');
        if (!$subject)                 $errors[] = __('Le sujet est requis.', 'rdr-expert-directory');
        if (strlen($message) < 20)    $errors[] = __('Message trop court (20 caractères min).', 'rdr-expert-directory');
        if (strlen($message) > 3000)  $errors[] = __('Message trop long (3000 max).', 'rdr-expert-directory');

        if ($errors) {
            wp_send_json_error(['message' => implode('<br>', $errors)]);
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'expert_profile') {
            wp_send_json_error(['message' => __('Fiche introuvable.', 'rdr-expert-directory')]);
        }

        // Vérifier que l'expert a laissé le formulaire activé
        $form_enabled_expert = get_post_meta($post_id, '_expert_form_enabled', true);
        if ($form_enabled_expert === '0') {
            wp_send_json_error(['message' => __('Le formulaire de contact n\'est pas disponible pour cet expert.', 'rdr-expert-directory')], 403);
        }

        // Vérifier que le contact form est autorisé
        $allowed = (array) get_post_meta($post_id, '_expert_contact_methods', true);
        if (!in_array('email', $allowed, true) && !in_array('form', $allowed, true)) {
            // Permettre toujours le formulaire s'il n'y a aucune méthode configurée
        }

        $to_email = get_post_meta($post_id, '_expert_email', true);
        if (!$to_email || !is_email($to_email)) {
            $author   = get_userdata($post->post_author);
            $to_email = $author ? $author->user_email : '';
        }
        if (!$to_email) {
            wp_send_json_error(['message' => __('Impossible d\'envoyer : coordonnées manquantes.', 'rdr-expert-directory')]);
        }

        $mail_subject = '[' . get_bloginfo('name') . ' — Contact Expert] ' . $subject;
        $mail_body    = "Bonjour,\n\nMessage via votre fiche expert sur " . get_bloginfo('name') . "\n\n";
        $mail_body   .= "De : $from_name <$from_email>\nObjet : $subject\n\nMessage :\n$message\n\n";
        $mail_body   .= "---\nVotre fiche : " . get_permalink($post_id);

        $sent = wp_mail($to_email, $mail_subject, $mail_body, [
            'Content-Type: text/plain; charset=UTF-8',
            "Reply-To: $from_name <$from_email>",
        ]);

        if ($sent) {
            $c = (int) get_post_meta($post_id, '_expert_contact_count', true);
            update_post_meta($post_id, '_expert_contact_count', $c + 1);
            wp_send_json_success(['message' => __('Message envoyé ! L\'expert vous répondra par email.', 'rdr-expert-directory')]);
        } else {
            wp_send_json_error(['message' => __('Envoi échoué. Réessayez plus tard.', 'rdr-expert-directory')]);
        }
    }
}
