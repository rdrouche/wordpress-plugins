<?php
defined('ABSPATH') || exit;

/**
 * Système de mise en avant des experts — "Experts du jour"
 *
 * - Un pourcentage configurable (admin) des experts premium actifs sont sélectionnés
 * - Renouvellement automatique à 6h00 chaque matin via WP-Cron
 * - Stockage dans wp_options (PAS de transients pour compatibilité Redis)
 * - Shortcode [rdr_featured_experts] avec chargement AJAX + skeleton
 * - Invisible aux robots d'indexation (noindex + pas de rendu SSR)
 * - Shortcode utilisable dans les widgets
 */
class RDR_ED_Featured {

    const OPTION_KEY     = 'rdr_ed_featured_experts';   // IDs des experts mis en avant
    const OPTION_UPDATED = 'rdr_ed_featured_updated';   // Timestamp du dernier tirage
    const CRON_HOOK      = 'rdr_ed_refresh_featured';
    const CRON_TIME      = '06:00:00';                  // Heure de renouvellement

    public static function init(): void {
        add_shortcode('rdr_featured_experts', [self::class, 'shortcode']);

        // AJAX chargement des experts mis en avant
        add_action('wp_ajax_rdr_ed_load_featured',        [self::class, 'ajax_load']);
        add_action('wp_ajax_nopriv_rdr_ed_load_featured', [self::class, 'ajax_load']);

        // Cron
        add_action(self::CRON_HOOK, [self::class, 'refresh']);
        add_filter('cron_schedules',  [self::class, 'add_cron_schedule']);

        // Paramètre admin dans la page settings (ajouté via hook)
        add_action('rdr_ed_settings_extra', [self::class, 'settings_fields']);
    }

    /* ── Programmer le cron à l'activation ── */
    public static function schedule_cron(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Prochain 6h00
            $next = self::next_6am();
            wp_schedule_event($next, 'daily_6am', self::CRON_HOOK);
        }
    }

    public static function unschedule_cron(): void {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ($ts) wp_unschedule_event($ts, self::CRON_HOOK);
    }

    public static function add_cron_schedule(array $schedules): array {
        $schedules['daily_6am'] = [
            'interval' => DAY_IN_SECONDS,
            'display'  => __('Une fois par jour à 6h00', 'rdr-expert-directory'),
        ];
        return $schedules;
    }

    private static function next_6am(): int {
        $now      = current_time('timestamp');
        $today_6  = mktime(6, 0, 0, date('n', $now), date('j', $now), date('Y', $now));
        return $now < $today_6 ? $today_6 : $today_6 + DAY_IN_SECONDS;
    }

    /* ── Tirage des experts mis en avant ── */
    public static function refresh(): void {
        $percentage = max(1, min(100, (int) RDR_ED_Settings::get('featured_percentage', 20)));

        // Récupérer tous les experts premium actifs publiés
        $all = get_posts([
            'post_type'      => 'expert_profile',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        $eligible = array_filter($all, function(int $id): bool {
            return RDR_ED_Versioning::get_status($id) === 'active'
                && RDR_ED_Premium::is_premium((int) get_post_field('post_author', $id));
        });
        $eligible = array_values($eligible);

        if (empty($eligible)) {
            update_option(self::OPTION_KEY, [], false);
            update_option(self::OPTION_UPDATED, time(), false);
            return;
        }

        // Nombre à mettre en avant
        $count = max(1, (int) ceil(count($eligible) * $percentage / 100));

        // Tirage aléatoire (Fisher-Yates)
        shuffle($eligible);
        $featured = array_slice($eligible, 0, $count);

        update_option(self::OPTION_KEY, $featured, false);
        update_option(self::OPTION_UPDATED, time(), false);
    }

    /* ── Récupérer les IDs mis en avant ── */
    public static function get_featured_ids(): array {
        $ids = get_option(self::OPTION_KEY, []);

        // Si jamais initialisé ou vide, faire un tirage à la demande
        if (empty($ids)) {
            self::refresh();
            $ids = get_option(self::OPTION_KEY, []);
        }
        return (array) $ids;
    }

    /* ── Shortcode [rdr_featured_experts] ── */
    public static function shortcode(array $atts): string {
        $atts = shortcode_atts([
            'limit' => 3,   // Nombre d'experts à afficher
            'title' => __('Experts du jour', 'rdr-expert-directory'),
        ], $atts, 'rdr_featured_experts');

        // Ne rien afficher aux robots (pas de SSR)
        // La détection se fait côté client via le JS
        $nonce   = wp_create_nonce('rdr_ed_nonce');
        $ajaxurl = admin_url('admin-ajax.php');

        ob_start();
        ?>
        <div class="rdr-featured-wrap"
            data-ajaxurl="<?= esc_url($ajaxurl); ?>"
            data-nonce="<?= esc_attr($nonce); ?>"
            data-limit="<?= (int) $atts['limit']; ?>"
            aria-live="polite">
            <?php if ($atts['title']) : ?>
            <h3 class="rdr-featured-title"><?= esc_html($atts['title']); ?></h3>
            <?php endif; ?>
            <!-- Skeleton initial — remplacé par AJAX -->
            <div class="rdr-featured-grid" id="rdr-featured-grid-<?= uniqid(); ?>">
                <?php for ($i = 0; $i < (int) $atts['limit']; $i++) : ?>
                <div class="rdr-featured-card rdr-featured-skeleton" aria-hidden="true">
                    <div class="rdr-featured-avatar rdr-skel-circle"></div>
                    <div class="rdr-skel-block" style="height:14px;width:70%;margin:8px auto 4px;"></div>
                    <div class="rdr-skel-block" style="height:11px;width:50%;margin:0 auto;"></div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ── AJAX : Charger les experts mis en avant ── */
    public static function ajax_load(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');

        // Vérifier si c'est un robot (User-Agent)
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $bots = ['googlebot', 'bingbot', 'slurp', 'duckduckbot', 'baiduspider',
                 'yandexbot', 'facebot', 'ia_archiver', 'msnbot', 'ahrefsbot',
                 'semrushbot', 'dotbot', 'petalbot', 'bytespider'];
        foreach ($bots as $bot) {
            if (str_contains($ua, $bot)) {
                wp_send_json_success(['cards' => [], 'bot' => true]);
            }
        }

        $limit = min(20, max(1, (int) ($_POST['limit'] ?? 3)));
        $ids   = self::get_featured_ids();

        if (empty($ids)) {
            wp_send_json_success(['cards' => [], 'updated' => 0]);
        }

        // Mélanger pour affichage aléatoire côté widget
        shuffle($ids);
        $ids = array_slice($ids, 0, $limit);

        $cards = [];
        foreach ($ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_status !== 'publish') continue;

            $photo_id  = (int) get_post_meta($post_id, '_profile_photo_id', true);
            $photo_url = $photo_id
                ? wp_get_attachment_image_url($photo_id, 'thumbnail')
                : get_avatar_url($post->post_author, ['size' => 80]);

            $skills = wp_get_post_terms($post_id, 'expert_skill');
            if (is_wp_error($skills)) $skills = [];

            $cards[] = [
                'id'    => $post_id,
                'url'   => get_permalink($post_id),
                'name'  => get_the_title($post_id),
                'title' => (string) get_post_meta($post_id, '_expert_title', true),
                'photo' => esc_url($photo_url),
                'skills'=> array_map(fn($t) => $t->name, array_slice($skills, 0, 3)),
            ];
        }

        wp_send_json_success([
            'cards'   => $cards,
            'updated' => (int) get_option(self::OPTION_UPDATED, 0),
        ]);
    }

    /* ── Champs de paramètres additionnels ── */
    public static function settings_fields(): void {
        $pct = (int) RDR_ED_Settings::get('featured_percentage', 20);
        $notif          = (bool) RDR_ED_Settings::get('notif_internal_message', true);
        $reminder       = (bool) RDR_ED_Settings::get('notif_message_reminder', true);
        $reminder_hour  = (int)  RDR_ED_Settings::get('notif_reminder_hour', 8);
        $updated = (int) get_option(self::OPTION_UPDATED, 0);
        ?>
        <div class="rdr-settings-card">
            <h2 class="rdr-settings-card-title">
                <span>⭐</span> <?php _e('Experts mis en avant', 'rdr-expert-directory'); ?>
            </h2>
            <table class="form-table">
                <tr>
                    <th><?php _e('Pourcentage d\'experts mis en avant', 'rdr-expert-directory'); ?></th>
                    <td>
                        <input type="number" name="rdr_ed_settings[featured_percentage]"
                            value="<?= $pct; ?>" min="1" max="100" class="small-text"> %
                        <p class="description">
                            <?php printf(__('Ex: 20%% = 1 expert sur 5 sera mis en avant. Renouvelé chaque jour à 6h00.', 'rdr-expert-directory'), ''); ?>
                        </p>
                        <?php if ($updated) : ?>
                        <p class="description">
                            <?php printf(__('Dernier tirage : %s', 'rdr-expert-directory'),
                                date_i18n(get_option('date_format') . ' H:i', $updated)); ?>
                        </p>
                        <?php endif; ?>
                        <button type="button" class="button button-small" id="rdr-refresh-featured"
                            data-nonce="<?= wp_create_nonce('rdr_ed_admin_nonce'); ?>">
                            <?php _e('Forcer le tirage maintenant', 'rdr-expert-directory'); ?>
                        </button>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Notification immediate', 'rdr-expert-directory'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rdr_ed_settings[notif_internal_message]"
                                value="1" <?= checked($notif, true, false); ?>>
                            <?php _e('Envoyer un email immediat a l\'expert quand il recoit un message', 'rdr-expert-directory'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Rappel quotidien messages non lus', 'rdr-expert-directory'); ?></th>
                    <td>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="rdr_ed_settings[notif_message_reminder]"
                                value="1" <?= checked($reminder, true, false); ?>>
                            <?php _e('Envoyer un rappel quotidien si messages non lus', 'rdr-expert-directory'); ?>
                        </label>
                        <label>
                            <?php _e('Heure d\'envoi :', 'rdr-expert-directory'); ?>
                            <select name="rdr_ed_settings[notif_reminder_hour]" style="width:auto;margin-left:8px;">
                                <?php for ($h = 0; $h <= 23; $h++) : ?>
                                <option value="<?= $h; ?>" <?= selected($reminder_hour, $h, false); ?>>
                                    <?= sprintf('%02d:00', $h); ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </label>
                        <p class="description" style="margin-top:6px;">
                            <?php _e('Un seul rappel par tranche de nouveaux messages. Si l\'expert a deja ete notifie pour les memes messages, aucun nouveau mail ne sera envoye.', 'rdr-expert-directory'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }
}
