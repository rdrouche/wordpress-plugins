<?php
defined('ABSPATH') || exit;

/**
 * Messagerie interne — RDR Expert Directory
 *
 * Permet aux visiteurs de contacter un expert via un formulaire sécurisé
 * sans envoyer d'email. Les messages sont stockés en base (custom table)
 * et visibles dans l'admin de l'expert.
 *
 * Protection anti-bot :
 *   - Nonce WP
 *   - Honeypot
 *   - Rate limiting IP (session meta WP)
 *   - Akismet si disponible
 *   - Timing (délai minimum 3s)
 *
 * Table : {prefix}rdr_ed_messages
 * Colonnes : id, post_id, sender_name, sender_firstname, sender_email,
 *            sender_phone, message, status (new|read|spam|deleted),
 *            created_at, ip_hash
 */
class RDR_ED_Messaging {

    const TABLE_NAME          = 'rdr_ed_messages';
    const CRON_REMINDER_HOOK  = 'rdr_ed_message_reminder';
    const NOTIF_SENT_KEY      = 'rdr_ed_notif_sent_';  // + post_id
    const RATE_KEY   = 'rdr_ed_msg_rate_';
    const RATE_MAX   = 3;
    const RATE_WIN   = 600; // 10 minutes

    public static function init(): void {
        add_action('wp_ajax_rdr_ed_send_internal_message',        [self::class, 'ajax_send']);
        add_action('wp_ajax_nopriv_rdr_ed_send_internal_message', [self::class, 'ajax_send']);
        add_action('wp_ajax_rdr_ed_get_messages',                 [self::class, 'ajax_get']);
        add_action('wp_ajax_rdr_ed_mark_message',                 [self::class, 'ajax_mark']);
        add_action('admin_menu',              [self::class, 'admin_menu']);

        // Cron rappel messages non lus (quotidien, heure configurable)
        add_action(self::CRON_REMINDER_HOOK, [self::class, 'send_reminder_emails']);
        add_filter('cron_schedules',          [self::class, 'add_cron_schedule']);
        add_shortcode('rdr_expert_inbox',      [self::class, 'shortcode_inbox']);
        add_shortcode('rdr_expert_status',     [self::class, 'shortcode_status']);

        // Vider les messages supprimés (cron hebdomadaire)
        if (!wp_next_scheduled('rdr_ed_purge_messages')) {
            wp_schedule_event(time(), 'weekly', 'rdr_ed_purge_messages');
        }
        add_action('rdr_ed_purge_messages', [self::class, 'purge_deleted']);
    }

    /* ── Création de la table à l'activation ── */
    public static function create_table(): void {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id       BIGINT(20) UNSIGNED NOT NULL,
            sender_name   VARCHAR(100) NOT NULL DEFAULT '',
            sender_firstname VARCHAR(100) NOT NULL DEFAULT '',
            sender_email  VARCHAR(200) NOT NULL DEFAULT '',
            sender_phone  VARCHAR(50)  NOT NULL DEFAULT '',
            message       TEXT         NOT NULL,
            status        ENUM('new','read','spam','deleted') NOT NULL DEFAULT 'new',
            created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ip_hash       VARCHAR(64)  NOT NULL DEFAULT '',
            PRIMARY KEY   (id),
            KEY post_id   (post_id),
            KEY status    (status),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /* ── AJAX : Envoyer un message ── */
    public static function ajax_send(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');

        // Honeypot
        if (!empty($_POST['website_internal'])) {
            wp_send_json_error(['message' => 'Bot detected.']);
        }

        // Timing anti-bot : vérifier que le formulaire a été affiché depuis au moins 1s
        // Tolérance de ±5s pour les désynchronisations d'horloge serveur/client
        $form_time = (int) ($_POST['_msg_time'] ?? 0);
        if ($form_time > 0) {
            $elapsed = time() - $form_time;
            if ($elapsed < 1 && $elapsed > -5) {
                // Soumis trop rapidement (et pas de désync serveur en avance)
                wp_send_json_error(['message' => __('Veuillez patienter un instant avant d\'envoyer.', 'rdr-expert-directory')]);
            }
            // Si $elapsed < -5 : l'horloge serveur est en retard de plus de 5s
            // → on laisse passer (cas rare, mieux vaut ne pas bloquer un vrai utilisateur)
        }

        // Rate limiting par IP (via options WP pour compatibilité Redis)
        $ip      = self::get_ip_hash();
        $rate_key = self::RATE_KEY . $ip;
        $rate    = (int) get_option($rate_key, 0);
        $rate_time_key = $rate_key . '_time';
        $rate_time = (int) get_option($rate_time_key, 0);

        if ($rate_time && (time() - $rate_time) > self::RATE_WIN) {
            // Fenêtre expirée, réinitialiser
            $rate = 0;
            update_option($rate_key, 0, false);
            update_option($rate_time_key, time(), false);
        } elseif ($rate >= self::RATE_MAX) {
            $remaining = self::RATE_WIN - (time() - $rate_time);
            wp_send_json_error(['message' => sprintf(
                __('Trop de messages. Réessayez dans %d minutes.', 'rdr-expert-directory'),
                ceil($remaining / 60)
            )]);
        }

        // Validation champs
        $post_id   = (int) ($_POST['post_id'] ?? 0);
        $lastname  = sanitize_text_field($_POST['msg_lastname']  ?? '');
        $firstname = sanitize_text_field($_POST['msg_firstname'] ?? '');
        $email     = sanitize_email($_POST['msg_email']          ?? '');
        $phone     = sanitize_text_field($_POST['msg_phone']     ?? '');
        $message   = sanitize_textarea_field($_POST['msg_message'] ?? '');

        $errors = [];
        if (!$lastname)           $errors[] = __('Votre nom est requis.', 'rdr-expert-directory');
        if (!$firstname)          $errors[] = __('Votre prénom est requis.', 'rdr-expert-directory');
        if (!is_email($email))    $errors[] = __('Email invalide.', 'rdr-expert-directory');
        if (strlen($message) < 10) $errors[] = __('Message trop court (10 caractères min).', 'rdr-expert-directory');
        if (strlen($message) > 3000) $errors[] = __('Message trop long.', 'rdr-expert-directory');

        if ($errors) {
            wp_send_json_error(['message' => implode('<br>', $errors)]);
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'expert_profile') {
            wp_send_json_error(['message' => __('Fiche introuvable.', 'rdr-expert-directory')]);
        }

        // Bloquer l'auto-message : l'expert ne peut pas s'écrire à lui-même
        if (is_user_logged_in() && (int) $post->post_author === get_current_user_id()) {
            wp_send_json_error(['message' => __('Vous ne pouvez pas vous envoyer un message.', 'rdr-expert-directory')]);
        }

        // Vérification Akismet si disponible
        if (self::is_spam_akismet($lastname . ' ' . $firstname, $email, $message)) {
            // Logger silencieusement comme spam sans informer le bot
            self::insert([
                'post_id'          => $post_id,
                'sender_name'      => $lastname,
                'sender_firstname' => $firstname,
                'sender_email'     => $email,
                'sender_phone'     => $phone,
                'message'          => $message,
                'status'           => 'spam',
                'ip_hash'          => $ip,
            ]);
            // Répondre comme si le message était envoyé (tromper le spam)
            wp_send_json_success(['message' => __('Votre message a été envoyé.', 'rdr-expert-directory')]);
        }

        // Insérer le message
        $inserted = self::insert([
            'post_id'          => $post_id,
            'sender_name'      => $lastname,
            'sender_firstname' => $firstname,
            'sender_email'     => $email,
            'sender_phone'     => $phone,
            'message'          => $message,
            'status'           => 'new',
            'ip_hash'          => $ip,
        ]);

        if (!$inserted) {
            wp_send_json_error(['message' => __('Erreur lors de l\'envoi.', 'rdr-expert-directory')]);
        }

        // Incrémenter le rate limit
        update_option($rate_key, $rate + 1, false);
        if (!$rate_time) update_option($rate_time_key, time(), false);

        // Notifier l'expert par email si activé
        if ((bool) RDR_ED_Settings::get('notif_internal_message', true)) {
            self::notify_expert($post_id, $lastname, $firstname, $email);
        }

        wp_send_json_success(['message' => __('Votre message a été envoyé à l\'expert.', 'rdr-expert-directory')]);
    }

    /* ── AJAX : Récupérer les messages (expert connecté) ── */
    public static function ajax_get(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('forbidden');

        $user_id = get_current_user_id();
        $post    = RDR_ED_Profile_Form::get_user_profile($user_id);
        if (!$post) wp_send_json_error(['message' => 'Aucune fiche.']);

        $status = sanitize_key($_POST['status'] ?? 'new');
        $page   = max(1, (int) ($_POST['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $rows  = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d AND status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $post->ID, $status, $limit, $offset
        ), ARRAY_A);

        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND status = %s",
            $post->ID, $status
        ));

        wp_send_json_success(['messages' => $rows, 'total' => $total, 'page' => $page]);
    }

    /* ── AJAX : Marquer un message (lu, spam, supprimé) ── */
    public static function ajax_mark(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('forbidden');

        $msg_id = (int) ($_POST['msg_id'] ?? 0);
        $status = sanitize_key($_POST['status'] ?? '');

        if (!in_array($status, ['read', 'spam', 'deleted', 'new'], true)) {
            wp_send_json_error('invalid_status');
        }

        $user_id = get_current_user_id();
        $post    = RDR_ED_Profile_Form::get_user_profile($user_id);
        if (!$post) wp_send_json_error('forbidden');

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Vérifier que le message appartient bien à l'expert
        $msg = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND post_id = %d",
            $msg_id, $post->ID
        ));

        if (!$msg) wp_send_json_error('not_found');

        $wpdb->update($table, ['status' => $status], ['id' => $msg_id]);
        wp_send_json_success(['status' => $status]);
    }

    /* ── Menu admin : boîte de réception ── */
    public static function admin_menu(): void {
        // Compter les nouveaux messages pour le badge
        $count = self::count_new_for_current_user();
        $label = __('📨 Messagerie', 'rdr-expert-directory');
        if ($count) $label .= ' <span class="awaiting-mod">' . $count . '</span>';

        add_menu_page(
            __('Messagerie Expert', 'rdr-expert-directory'),
            $label,
            RDR_ED_Capabilities::CAP_SUBMIT,
            'rdr-ed-inbox',
            [self::class, 'page_inbox'],
            'dashicons-email-alt',
            26
        );
    }

    /* ── Shortcode [rdr_expert_inbox] ── */
    public static function shortcode_inbox(array $atts): string {
        if (!is_user_logged_in() || !current_user_can(RDR_ED_Capabilities::CAP_SUBMIT)) {
            return '<div class="rdr-info-box rdr-info-warning">'
                 . '<span class="rdr-info-icon">🔒</span>'
                 . '<div class="rdr-info-content">' . __('Accès réservé aux experts connectés.', 'rdr-expert-directory') . '</div>'
                 . '</div>';
        }

        $user_id = get_current_user_id();
        $post    = RDR_ED_Profile_Form::get_user_profile($user_id);

        if (!$post) {
            return '<p class="description">' . __('Vous n\'avez pas encore de fiche expert.', 'rdr-expert-directory') . '</p>';
        }

        if (!RDR_ED_Premium::is_premium($user_id)) {
            return '<div class="rdr-info-box rdr-info-warning">'
                 . '<span class="rdr-info-icon">💎</span>'
                 . '<div><span class="rdr-info-title">' . __('Messagerie réservée aux experts premium', 'rdr-expert-directory') . '</span>'
                 . '<div class="rdr-info-content">' . __('Passez à un compte premium pour accéder à votre messagerie.', 'rdr-expert-directory') . '</div>'
                 . '</div></div>';
        }

        global $wpdb;
        $table     = $wpdb->prefix . self::TABLE_NAME;
        $post_id   = $post->ID;
        $tab       = sanitize_key($_GET['msg_tab'] ?? 'new');
        $valid_tabs= ['new', 'read', 'spam', 'deleted'];
        if (!in_array($tab, $valid_tabs, true)) $tab = 'new';

        $labels = [
            'new'     => __('Nouveaux', 'rdr-expert-directory'),
            'read'    => __('Lus', 'rdr-expert-directory'),
            'spam'    => 'Spam',
            'deleted' => __('Supprimés', 'rdr-expert-directory'),
        ];

        $counts = [];
        foreach ($valid_tabs as $s) {
            $counts[$s] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND status = %s", $post_id, $s
            ));
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d AND status = %s ORDER BY created_at DESC LIMIT 20",
            $post_id, $tab
        ), ARRAY_A);

        ob_start();
        ?>
        <div class="rdr-inbox-wrap">
            <h2 style="margin-bottom:16px;">📨 <?php _e('Mes messages', 'rdr-expert-directory'); ?>
                <?php if ($counts['new']) : ?>
                <span style="background:var(--rdr-accent);color:#fff;border-radius:10px;padding:2px 8px;font-size:13px;font-weight:600;">
                    <?= $counts['new']; ?>
                </span>
                <?php endif; ?>
            </h2>

            <!-- Onglets -->
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:20px;border-bottom:1px solid var(--rdr-border);padding-bottom:8px;">
                <?php foreach ($labels as $s => $label) :
                    $url = add_query_arg('msg_tab', $s);
                    $active = $tab === $s; ?>
                <a href="<?= esc_url($url); ?>"
                    style="padding:7px 16px;border-radius:7px;font-size:13px;font-weight:600;text-decoration:none;background:<?= $active ? 'var(--rdr-accent)' : 'var(--rdr-bg-card)'; ?>;color:<?= $active ? '#fff' : 'var(--rdr-text-secondary)'; ?>;">
                    <?= $label; ?>
                    <?php if ($counts[$s]) : ?>
                    <span style="background:rgba(255,255,255,0.25);border-radius:8px;padding:1px 5px;font-size:11px;"><?= $counts[$s]; ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if (empty($rows)) : ?>
            <p class="description" style="text-align:center;padding:24px 0;color:var(--rdr-text-muted);">
                <?php _e('Aucun message dans cette catégorie.', 'rdr-expert-directory'); ?>
            </p>
            <?php else : ?>
            <div style="display:flex;flex-direction:column;gap:14px;">
                <?php foreach ($rows as $row) :
                    $nonce = wp_create_nonce('rdr_ed_nonce'); ?>
                <div class="rdr-msg-card" style="background:var(--rdr-bg-card);border:1px solid var(--rdr-border);border-radius:10px;padding:18px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
                        <div>
                            <strong style="color:var(--rdr-text-primary);"><?= esc_html($row['sender_firstname'] . ' ' . $row['sender_name']); ?></strong>
                            <span style="color:var(--rdr-text-muted);font-size:12px;margin-left:8px;">
                                <?= date_i18n(get_option('date_format') . ' H:i', strtotime($row['created_at'])); ?>
                            </span>
                            <?php if ($tab === 'new') : ?>
                            <span style="background:var(--rdr-accent);color:#fff;border-radius:8px;padding:1px 6px;font-size:11px;margin-left:6px;"><?php _e('Nouveau', 'rdr-expert-directory'); ?></span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;gap:6px;flex-shrink:0;">
                            <?php if ($tab === 'new') : ?>
                            <button class="button button-sm rdr-mark-msg-front" data-id="<?= $row['id']; ?>" data-status="read" data-nonce="<?= $nonce; ?>">
                                ✓ <?php _e('Marquer lu', 'rdr-expert-directory'); ?>
                            </button>
                            <?php endif; ?>
                            <?php if ($tab !== 'deleted') : ?>
                            <button class="button button-sm button-muted rdr-mark-msg-front" data-id="<?= $row['id']; ?>" data-status="deleted" data-nonce="<?= $nonce; ?>">
                                🗑️ <?php _e('Supprimer', 'rdr-expert-directory'); ?>
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="display:flex;gap:16px;font-size:12px;margin-bottom:12px;">
                        <?php if ($row['sender_email']) : ?>
                        <a href="mailto:<?= esc_attr($row['sender_email']); ?>" style="color:var(--rdr-accent);text-decoration:none;">
                            ✉️ <?= esc_html($row['sender_email']); ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($row['sender_phone']) : ?>
                        <a href="tel:<?= esc_attr($row['sender_phone']); ?>" style="color:var(--rdr-accent);text-decoration:none;">
                            📞 <?= esc_html($row['sender_phone']); ?>
                        </a>
                        <?php endif; ?>
                    </div>
                    <div style="background:var(--rdr-bg-surface);border:1px solid var(--rdr-border);border-radius:6px;padding:12px;font-size:14px;line-height:1.6;white-space:pre-wrap;">
                        <?= esc_html($row['message']); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

        public static function page_inbox(): void {
        if (!is_user_logged_in()) return;
        $user_id = get_current_user_id();
        $post    = RDR_ED_Profile_Form::get_user_profile($user_id);

        if (!$post) {
            echo '<div class="wrap"><p>' . __('Vous n\'avez pas encore de fiche expert.', 'rdr-expert-directory') . '</p></div>';
            return;
        }

        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_NAME;
        $tab     = sanitize_key($_GET['msg_status'] ?? 'new');
        $page    = max(1, (int) ($_GET['paged'] ?? 1));
        $limit   = 20;
        $offset  = ($page - 1) * $limit;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE post_id = %d AND status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $post->ID, $tab, $limit, $offset
        ), ARRAY_A);

        $counts = [];
        foreach (['new','read','spam','deleted'] as $s) {
            $counts[$s] = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND status = %s", $post->ID, $s
            ));
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Messagerie', 'rdr-expert-directory'); ?></h1>
            <nav class="nav-tab-wrapper" style="margin-bottom:16px;">
                <?php foreach (['new' => __('Nouveaux','rdr-expert-directory'), 'read' => __('Lus','rdr-expert-directory'), 'spam' => 'Spam', 'deleted' => __('Supprimés','rdr-expert-directory')] as $s => $label): ?>
                <a href="<?= esc_url(add_query_arg(['page' => 'rdr-ed-inbox', 'msg_status' => $s])); ?>"
                    class="nav-tab <?= $tab === $s ? 'nav-tab-active' : ''; ?>">
                    <?= $label; ?> (<?= $counts[$s]; ?>)
                </a>
                <?php endforeach; ?>
            </nav>

            <?php if (empty($rows)): ?>
            <p><?php _e('Aucun message.', 'rdr-expert-directory'); ?></p>
            <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr>
                    <th><?php _e('Expéditeur', 'rdr-expert-directory'); ?></th>
                    <th><?php _e('Email', 'rdr-expert-directory'); ?></th>
                    <th><?php _e('Téléphone', 'rdr-expert-directory'); ?></th>
                    <th><?php _e('Message', 'rdr-expert-directory'); ?></th>
                    <th><?php _e('Date', 'rdr-expert-directory'); ?></th>
                    <th><?php _e('Actions', 'rdr-expert-directory'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?= esc_html($row['sender_firstname'] . ' ' . $row['sender_name']); ?></strong></td>
                    <td><a href="mailto:<?= esc_attr($row['sender_email']); ?>"><?= esc_html($row['sender_email']); ?></a></td>
                    <td><?= esc_html($row['sender_phone'] ?: '—'); ?></td>
                    <td><?= esc_html(wp_trim_words($row['message'], 15)); ?>
                        <details><summary><?php _e('Lire tout', 'rdr-expert-directory'); ?></summary>
                        <?= nl2br(esc_html($row['message'])); ?></details>
                    </td>
                    <td><?= date_i18n(get_option('date_format') . ' H:i', strtotime($row['created_at'])); ?></td>
                    <td>
                        <?php $nonce = wp_create_nonce('rdr_ed_admin_nonce'); ?>
                        <?php if ($tab !== 'read'): ?>
                        <button class="button button-small rdr-mark-msg" data-id="<?= $row['id']; ?>" data-status="read" data-nonce="<?= $nonce; ?>"><?php _e('Lu', 'rdr-expert-directory'); ?></button>
                        <?php endif; ?>
                        <?php if ($tab !== 'spam'): ?>
                        <button class="button button-small rdr-mark-msg" data-id="<?= $row['id']; ?>" data-status="spam" data-nonce="<?= $nonce; ?>">Spam</button>
                        <?php endif; ?>
                        <?php if ($tab !== 'deleted'): ?>
                        <button class="button button-small rdr-mark-msg" data-id="<?= $row['id']; ?>" data-status="deleted" data-nonce="<?= $nonce; ?>"><?php _e('Supprimer', 'rdr-expert-directory'); ?></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ── Helpers ── */
    private static function insert(array $data): bool {
        global $wpdb;
        return (bool) $wpdb->insert($wpdb->prefix . self::TABLE_NAME, $data);
    }

    private static function get_ip_hash(): string {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
        foreach ($keys as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = trim(explode(',', $_SERVER[$k])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return hash('sha256', $ip . AUTH_SALT);
                }
            }
        }
        return hash('sha256', 'unknown' . AUTH_SALT);
    }

    private static function count_new_for_current_user(): int {
        if (!is_user_logged_in()) return 0;
        $post = RDR_ED_Profile_Form::get_user_profile(get_current_user_id());
        if (!$post) return 0;
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_NAME . " WHERE post_id = %d AND status = 'new'",
            $post->ID
        ));
    }

    private static function is_spam_akismet(string $name, string $email, string $content): bool {
        if (!function_exists('akismet_http_post') || !defined('AKISMET_VERSION')) return false;

        $params = [
            'blog'                 => get_option('siteurl'),
            'user_ip'              => preg_replace('/[^0-9.,: ]/', '', $_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent'           => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'referrer'             => $_SERVER['HTTP_REFERER'] ?? '',
            'permalink'            => get_the_permalink(),
            'comment_type'         => 'contact-form',
            'comment_author'       => $name,
            'comment_author_email' => $email,
            'comment_content'      => $content,
        ];

        $response = akismet_http_post(
            http_build_query($params),
            'rest.akismet.com',
            '/1.1/comment-check',
            443,
            'rdr-expert-directory'
        );

        return isset($response[1]) && trim($response[1]) === 'true';
    }

    private static function notify_expert(int $post_id, string $lastname, string $firstname, string $email): void {
        $post   = get_post($post_id);
        $author = $post ? get_userdata($post->post_author) : null;
        if (!$author) return;

        $subject = sprintf('[%s] Nouveau message de %s %s', get_bloginfo('name'), $firstname, $lastname);
        $body    = "Bonjour " . $author->display_name . ",\n\n"
                 . "Vous avez reçu un nouveau message de $firstname $lastname ($email).\n\n"
                 . "Consultez votre messagerie : " . admin_url('admin.php?page=rdr-ed-inbox') . "\n\n"
                 . "-- \n" . get_bloginfo('name');

        wp_mail($author->user_email, $subject, $body);
    }


    /* ── Shortcode [rdr_expert_status] — widget compact ── */
    // =========================================================
    // SHORTCODE [rdr_expert_status]
    //
    // Paramètres :
    //   roles        : rôle(s) autorisant l'affichage (CSV, défaut: "expert_it")
    //   cap          : capacité autorisant l'affichage (défaut: CAP_SUBMIT)
    //   require_both : "true" = AND, sinon OR (défaut: false)
    //   inbox_page   : slug de la page messagerie (défaut: "messagerie-expert")
    //   show_login   : "true" = afficher un lien si non connecté (défaut: false)
    //   login_text   : texte du lien connexion
    //   login_url    : URL WPA magic link (⚠️  pas wp-login.php)
    // =========================================================
    public static function shortcode_status(array $atts): string {
        $atts = shortcode_atts([
            'roles'        => RDR_ED_Capabilities::ROLE_SLUG,
            'cap'          => RDR_ED_Capabilities::CAP_SUBMIT,
            'require_both' => 'false',
            'inbox_page'   => 'messagerie-expert',
            'show_login'   => 'false',
            'login_text'   => __('Acceder a l\'espace Expert', 'rdr-expert-directory'),
            'login_url'    => '',
        ], $atts, 'rdr_expert_status');

        // Non connecté — silencieux par défaut (WPA gère l'auth via magic link)
        if (!is_user_logged_in()) {
            if (filter_var($atts['show_login'], FILTER_VALIDATE_BOOLEAN) && !empty($atts['login_url'])) {
                return '<a href="' . esc_url($atts['login_url']) . '" class="button rdr-activate-btn rdr-activate-btn--login">'
                     . esc_html($atts['login_text']) . '</a>';
            }
            return '';
        }

        // Vérification accès (rôle OU cap, configurable)
        if (!RDR_ED_Capabilities::user_has_expert_access($atts)) {
            return '';
        }

        // Données du profil et messages
        $user_id  = get_current_user_id();
        $post     = RDR_ED_Profile_Form::get_user_profile($user_id);
        $is_prem  = RDR_ED_Premium::is_premium($user_id);

        $unread = 0;
        if ($post && $is_prem) {
            global $wpdb;
            $unread = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::TABLE_NAME .
                " WHERE post_id = %d AND status = 'new'", $post->ID
            ));
        }

        $has_pending = $post && RDR_ED_Versioning::has_pending($post->ID);
        $profile_url = RDR_ED_Capabilities::get_profile_page_url();
        $inbox_slug  = sanitize_title($atts['inbox_page'] ?? 'messagerie-expert');
        $inbox_obj   = get_page_by_path($inbox_slug);
        $inbox_url   = $inbox_obj ? get_permalink($inbox_obj->ID) : $profile_url;

        ob_start(); ?>
        <div class="rdr-expert-status-widget">
            <?php if ($unread > 0) : ?>
            <a href="<?= esc_url($inbox_url); ?>" class="rdr-esw-item rdr-esw-messages">
                <span class="rdr-esw-icon">📨</span>
                <span class="rdr-esw-label"><?php printf(
                    _n('%d message non lu', '%d messages non lus', $unread, 'rdr-expert-directory'), $unread
                ); ?></span>
                <span class="rdr-esw-badge"><?= $unread; ?></span>
            </a>
            <?php endif; ?>
            <?php if ($has_pending) : ?>
            <a href="<?= esc_url($profile_url); ?>" class="rdr-esw-item rdr-esw-pending">
                <span class="rdr-esw-icon">⏳</span>
                <span class="rdr-esw-label"><?php _e('Revision en attente de validation', 'rdr-expert-directory'); ?></span>
            </a>
            <?php endif; ?>
            <a href="<?= esc_url($profile_url); ?>" class="rdr-esw-item rdr-esw-profile">
                <span class="rdr-esw-icon">👤</span>
                <span class="rdr-esw-label"><?php _e('Mon profil expert', 'rdr-expert-directory'); ?></span>
                <?php if ($post) : ?>
                <span class="rdr-esw-status rdr-esw-status--<?= RDR_ED_Versioning::get_status($post->ID); ?>">
                    <?= RDR_ED_Versioning::get_status($post->ID) === 'active'
                        ? '&#x2705; ' . __('Actif', 'rdr-expert-directory')
                        : '&#x1F534; ' . __('Inactif', 'rdr-expert-directory'); ?>
                </span>
                <?php endif; ?>
            </a>
        </div>
        <?php return ob_get_clean();
    }

        /* ── Cron schedule ── */
    public static function add_cron_schedule(array $schedules): array {
        $schedules['rdr_ed_daily_reminder'] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => __('Une fois par jour (rappel messages)', 'rdr-expert-directory'),
        ];
        return $schedules;
    }

    /* ── Programmer le cron rappel ── */
    public static function schedule_reminder(): void {
        if (!wp_next_scheduled(self::CRON_REMINDER_HOOK)) {
            $hour   = (int) RDR_ED_Settings::get('notif_reminder_hour', 8);
            $now    = current_time('timestamp');
            $today  = mktime($hour, 0, 0, date('n', $now), date('j', $now), date('Y', $now));
            $next   = $now < $today ? $today : $today + DAY_IN_SECONDS;
            wp_schedule_event($next, 'rdr_ed_daily_reminder', self::CRON_REMINDER_HOOK);
        }
    }

    public static function unschedule_reminder(): void {
        $ts = wp_next_scheduled(self::CRON_REMINDER_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_REMINDER_HOOK);
    }

    /* ── Envoyer les emails de rappel ── */
    public static function send_reminder_emails(): void {
        if (!(bool) RDR_ED_Settings::get('notif_message_reminder', true)) return;

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Récupérer tous les post_id ayant des messages non lus
        $post_ids = $wpdb->get_col(
            "SELECT DISTINCT post_id FROM {$table} WHERE status = 'new'"
        );

        foreach ($post_ids as $post_id) {
            $post_id = (int) $post_id;
            $post    = get_post($post_id);
            if (!$post || $post->post_type !== 'expert_profile') continue;

            $author  = get_userdata($post->post_author);
            if (!$author) continue;

            $notif_key   = self::NOTIF_SENT_KEY . $post_id;
            $notif_data  = get_option($notif_key, []);
            $last_sent   = (int) ($notif_data['sent_at']    ?? 0);
            $last_count  = (int) ($notif_data['msg_count']  ?? 0);

            // Compter les messages non lus créés APRÈS le dernier rappel
            $new_since = $last_sent
                ? $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                     WHERE post_id = %d AND status = 'new'
                     AND UNIX_TIMESTAMP(created_at) > %d",
                    $post_id, $last_sent
                  ))
                : $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND status = 'new'",
                    $post_id
                  ));

            $new_since = (int) $new_since;

            // N'envoyer que s'il y a des NOUVEAUX messages depuis le dernier rappel
            if ($new_since === 0) continue;

            $total_unread = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND status = 'new'",
                $post_id
            ));

            // Envoyer l'email
            $inbox_page = get_page_by_path('messagerie-expert');
            $inbox_url  = $inbox_page
                ? get_permalink($inbox_page->ID)
                : RDR_ED_Capabilities::get_profile_page_url();

            $subject = sprintf(
                '[%s] Vous avez %d message(s) non lu(s)',
                get_bloginfo('name'), $total_unread
            );
            $body = sprintf(
                "Bonjour %s,

Vous avez %d nouveau(x) message(s) non lu(s) dans votre messagerie expert.

Accédez à votre messagerie : %s

-- 
%s",
                $author->display_name,
                $total_unread,
                $inbox_url,
                get_bloginfo('name')
            );

            wp_mail($author->user_email, $subject, $body);

            // Enregistrer l'envoi (timestamp + count au moment de l'envoi)
            update_option($notif_key, [
                'sent_at'   => time(),
                'msg_count' => $total_unread,
            ], false);
        }
    }

        public static function purge_deleted(): void {
        global $wpdb;
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}" . self::TABLE_NAME .
            " WHERE status = 'deleted' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
    }
}
