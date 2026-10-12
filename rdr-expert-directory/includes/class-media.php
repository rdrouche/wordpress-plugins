<?php
defined('ABSPATH') || exit;

/**
 * Gestion des médias experts — RDR Expert Directory
 *
 * - Upload photo profil + bannière via AJAX (sans wp.media, sans accès admin)
 * - Stockage dans /wp-content/uploads/rdr-experts/{user_id}/
 * - Enregistrement en tant qu'attachment WP (post_parent = post expert)
 * - Meta _rdr_ed_uploader = user_id pour traçabilité
 * - Accès via page admin dédiée (hors médiathèque standard)
 * - Suppression des fichiers lors de la suppression du profil
 */
class RDR_ED_Media {

    const UPLOAD_SUBDIR  = 'rdr-experts';
    const MAX_SIZE_BYTES = 2 * 1024 * 1024; // 2 Mo
    const ALLOWED_TYPES  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    const META_UPLOADER  = '_rdr_ed_uploader';

    public static function init(): void {
        add_action('wp_ajax_rdr_ed_upload_media', [self::class, 'ajax_upload']);
        add_action('wp_ajax_rdr_ed_delete_media', [self::class, 'ajax_delete']);
        add_action('admin_menu',                  [self::class, 'admin_menu']);
    }

    /* ── AJAX : Upload d'un fichier ── */
    public static function ajax_upload(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');

        if (!RDR_ED_Capabilities::user_can_submit()) {
            wp_send_json_error(['message' => __('Accès refusé.', 'rdr-expert-directory')], 403);
        }

        $user_id = get_current_user_id();
        $type    = sanitize_key($_POST['media_type'] ?? ''); // 'photo' | 'banner'

        if (!in_array($type, ['photo', 'banner'], true)) {
            wp_send_json_error(['message' => __('Type de média invalide.', 'rdr-expert-directory')]);
        }

        if (empty($_FILES['file'])) {
            wp_send_json_error(['message' => __('Aucun fichier reçu.', 'rdr-expert-directory')]);
        }

        $file = $_FILES['file'];

        // Vérification taille
        if ($file['size'] > self::MAX_SIZE_BYTES) {
            wp_send_json_error(['message' => sprintf(
                __('Fichier trop volumineux (max %s Mo).', 'rdr-expert-directory'),
                self::MAX_SIZE_BYTES / 1024 / 1024
            )]);
        }

        // Vérification type MIME réel (pas juste l'extension)
        $finfo    = finfo_open(FILEINFO_MIME_TYPE);
        $mimetype = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimetype, self::ALLOWED_TYPES, true)) {
            wp_send_json_error(['message' => __('Format non autorisé (JPG, PNG, WebP, GIF uniquement).', 'rdr-expert-directory')]);
        }

        // Dossier de destination : /uploads/rdr-experts/{user_id}/
        add_filter('upload_dir', [self::class, 'custom_upload_dir']);
        $_POST['_rdr_ed_user_id'] = $user_id;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Autoriser temporairement les types image pour cet upload
        // (wp_handle_upload filtre les mimes selon le rôle WP par défaut)
        $allowed_mimes_filter = function(array $mimes): array {
            $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
            $mimes['png']          = 'image/png';
            $mimes['webp']         = 'image/webp';
            $mimes['gif']          = 'image/gif';
            return $mimes;
        };
        add_filter('upload_mimes', $allowed_mimes_filter, 999);

        // test_form:false = ne pas vérifier le referer
        // unique_filename_callback = s'assurer d'un nom unique
        $overrides = ['test_form' => false];

        $uploaded = wp_handle_upload($file, $overrides);

        remove_filter('upload_mimes', $allowed_mimes_filter, 999);
        remove_filter('upload_dir', [self::class, 'custom_upload_dir']);

        if (isset($uploaded['error'])) {
            wp_send_json_error(['message' => $uploaded['error']]);
        }

        // Récupérer la fiche expert de l'utilisateur
        $post    = RDR_ED_Profile_Form::get_user_profile($user_id);
        $post_id = $post ? $post->ID : 0;

        // Créer l'attachment WP
        $attachment_id = wp_insert_attachment([
            'guid'           => $uploaded['url'],
            'post_mime_type' => $uploaded['type'],
            'post_title'     => sanitize_file_name(pathinfo($uploaded['file'], PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
            'post_parent'    => $post_id,
            'post_author'    => $user_id,
        ], $uploaded['file'], $post_id);

        if (is_wp_error($attachment_id)) {
            wp_send_json_error(['message' => $attachment_id->get_error_message()]);
        }

        // Générer les thumbnails
        $metadata = wp_generate_attachment_metadata($attachment_id, $uploaded['file']);
        wp_update_attachment_metadata($attachment_id, $metadata);

        // Méta traçabilité : qui a uploadé
        update_post_meta($attachment_id, self::META_UPLOADER, $user_id);
        update_post_meta($attachment_id, '_rdr_ed_media_type', $type);

        // Mettre à jour la fiche avec l'ID de l'attachment
        if ($post_id) {
            $meta_key = $type === 'photo' ? '_profile_photo_id' : '_banner_id';

            // Supprimer l'ancien fichier si il existe
            $old_id = (int) get_post_meta($post_id, $meta_key, true);
            if ($old_id && $old_id !== $attachment_id) {
                wp_delete_attachment($old_id, true);
            }

            update_post_meta($post_id, $meta_key, $attachment_id);
        }

        // URL pour preview
        $thumb_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
        $full_url  = wp_get_attachment_image_url($attachment_id, 'medium');

        wp_send_json_success([
            'attachment_id' => $attachment_id,
            'thumb_url'     => $thumb_url,
            'full_url'      => $full_url,
            'message'       => __('Image uploadée avec succès.', 'rdr-expert-directory'),
        ]);
    }

    /* ── AJAX : Supprimer un média ── */
    public static function ajax_delete(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');

        if (!RDR_ED_Capabilities::user_can_submit()) {
            wp_send_json_error('forbidden');
        }

        $attachment_id = (int) ($_POST['attachment_id'] ?? 0);
        $user_id       = get_current_user_id();

        // Vérifier que le média appartient bien à cet utilisateur
        $uploader = (int) get_post_meta($attachment_id, self::META_UPLOADER, true);
        if ($uploader !== $user_id && !current_user_can(RDR_ED_Capabilities::CAP_VALIDATE)) {
            wp_send_json_error('forbidden');
        }

        wp_delete_attachment($attachment_id, true);
        wp_send_json_success(['message' => __('Image supprimée.', 'rdr-expert-directory')]);
    }

    /* ── Filtre : dossier d'upload personnalisé ── */
    public static function custom_upload_dir(array $dirs): array {
        $user_id = (int) ($_POST['_rdr_ed_user_id'] ?? get_current_user_id());
        $subdir  = '/' . self::UPLOAD_SUBDIR . '/' . $user_id;

        $dirs['subdir'] = $subdir;
        $dirs['path']   = $dirs['basedir'] . $subdir;
        $dirs['url']    = $dirs['baseurl'] . $subdir;

        // Créer le dossier s'il n'existe pas
        if (!is_dir($dirs['path'])) {
            wp_mkdir_p($dirs['path']);
            // Protéger avec un .htaccess pour éviter le listing
            file_put_contents($dirs['path'] . '/.htaccess', "Options -Indexes\n");
        }

        return $dirs;
    }

    /* ── Admin : liste des médias experts ── */
    public static function admin_menu(): void {
        add_submenu_page(
            'edit.php?post_type=expert_profile',
            __('Médias experts', 'rdr-expert-directory'),
            __('🖼️ Médias', 'rdr-expert-directory'),
            'manage_options',
            'rdr-ed-media',
            [self::class, 'page_media']
        );
    }

    public static function page_media(): void {
        global $wpdb;

        // Récupérer tous les attachments uploadés par le plugin
        $attachments = $wpdb->get_results(
            "SELECT p.ID, p.post_title, p.post_parent, p.post_author, p.post_date, pm.meta_value as uploader, pm2.meta_value as media_type
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm  ON pm.post_id  = p.ID AND pm.meta_key  = '_rdr_ed_uploader'
             LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = '_rdr_ed_media_type'
             WHERE p.post_type = 'attachment'
             AND pm.meta_value IS NOT NULL
             ORDER BY p.post_date DESC
             LIMIT 100"
        );
        ?>
        <div class="wrap">
            <h1><?php _e('Médias experts', 'rdr-expert-directory'); ?></h1>
            <p class="description">
                <?php _e('Ces médias sont stockés dans /uploads/rdr-experts/ et liés aux fiches experts.', 'rdr-expert-directory'); ?>
            </p>

            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th style="width:60px;"><?php _e('Aperçu', 'rdr-expert-directory'); ?></th>
                    <th><?php _e('Fichier', 'rdr-expert-directory'); ?></th>
                    <th><?php _e('Type', 'rdr-expert-directory'); ?></th>
                    <th><?php _e('Uploadé par', 'rdr-expert-directory'); ?></th>
                    <th><?php _e('Fiche liée', 'rdr-expert-directory'); ?></th>
                    <th><?php _e('Date', 'rdr-expert-directory'); ?></th>
                    <th><?php _e('Actions', 'rdr-expert-directory'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($attachments as $att) :
                    $user       = get_userdata($att->uploader);
                    $parent     = $att->post_parent ? get_post($att->post_parent) : null;
                    $thumb      = wp_get_attachment_image($att->ID, [50, 50]);
                    $type_label = $att->media_type === 'photo' ? '👤 Photo' : '🖼️ Bannière';
                ?>
                <tr>
                    <td><?= $thumb; ?></td>
                    <td><?= esc_html($att->post_title); ?></td>
                    <td><?= $type_label; ?></td>
                    <td><?= $user ? esc_html($user->display_name) . '<br><small>' . esc_html($user->user_email) . '</small>' : '#' . $att->uploader; ?></td>
                    <td><?= $parent ? '<a href="' . esc_url(get_edit_post_link($parent->ID)) . '">' . esc_html($parent->post_title) . '</a>' : '—'; ?></td>
                    <td><?= date_i18n(get_option('date_format'), strtotime($att->post_date)); ?></td>
                    <td>
                        <a href="<?= esc_url(wp_get_attachment_url($att->ID)); ?>" target="_blank" class="button button-small">
                            <?php _e('Voir', 'rdr-expert-directory'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ── Supprimer tous les médias d'un utilisateur ── */
    public static function delete_user_media(int $user_id): void {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type = 'attachment' AND pm.meta_key = %s AND pm.meta_value = %d",
            self::META_UPLOADER, $user_id
        ));
        foreach ($ids as $id) {
            wp_delete_attachment((int) $id, true);
        }
    }
}
