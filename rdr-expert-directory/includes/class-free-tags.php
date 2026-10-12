<?php
defined('ABSPATH') || exit;

/**
 * Gestion des tags libres soumis par les experts.
 *
 * Workflow :
 *  1. Expert propose un tag libre dans le formulaire
 *  2. Si skill_free_tags_review=1 → stocké en "pending_skills" option
 *  3. Admin valide ou refuse depuis la page dédiée
 *  4. Si validé → crée le terme dans expert_skill
 *  5. Si skill_free_tags_review=0 → crée immédiatement
 */
class RDR_ED_Free_Tags {

    const PENDING_OPTION = 'rdr_ed_pending_skills';

    public static function init(): void {
        add_action('wp_ajax_rdr_ed_suggest_skill',       [self::class, 'ajax_suggest']);
        add_action('wp_ajax_rdr_ed_approve_skill',       [self::class, 'ajax_approve']);
        add_action('wp_ajax_rdr_ed_reject_skill',        [self::class, 'ajax_reject_skill']);
        add_action('wp_ajax_rdr_ed_load_pending_skills', [self::class, 'ajax_load_pending']);
        // Filtrer les nouvelles compétences lors de la soumission de fiche
        add_filter('rdr_ed_process_free_skills',         [self::class, 'process_free_skills'], 10, 2);
    }

    /**
     * Appelé lors de la soumission de profil pour traiter les tags libres.
     *
     * @param array $free_tags  Tableau de noms de tags soumis par l'expert (strings)
     * @param int   $user_id    Auteur
     * @return int[]            IDs de termes approuvés à affecter immédiatement
     */
    public static function process_free_skills(array $free_tags, int $user_id): array {
        $allow_free = (int) RDR_ED_Settings::get('skill_allow_free_tags');
        if (!$allow_free || empty($free_tags)) return [];

        $review  = (int) RDR_ED_Settings::get('skill_free_tags_review');
        $approved_ids = [];

        foreach ($free_tags as $tag_name) {
            $tag_name = sanitize_text_field($tag_name);
            if (strlen($tag_name) < 2 || strlen($tag_name) > 50) continue;

            // Vérifier si le terme existe déjà
            $existing = get_term_by('name', $tag_name, 'expert_skill');
            if ($existing) {
                $approved_ids[] = $existing->term_id;
                continue;
            }

            if (!$review) {
                // Créer immédiatement
                $result = wp_insert_term($tag_name, 'expert_skill');
                if (!is_wp_error($result)) {
                    $approved_ids[] = $result['term_id'];
                }
            } else {
                // Mettre en file d'attente de validation
                self::queue_pending($tag_name, $user_id);
            }
        }

        return $approved_ids;
    }

    /* ── File d'attente de compétences en attente ── */
    public static function queue_pending(string $name, int $user_id): void {
        $pending = get_option(self::PENDING_OPTION, []);
        $key     = md5(strtolower($name));

        if (isset($pending[$key])) return; // déjà en file

        $pending[$key] = [
            'name'       => $name,
            'user_id'    => $user_id,
            'submitted'  => current_time('mysql'),
            'status'     => 'pending',
        ];
        update_option(self::PENDING_OPTION, $pending);
    }

    public static function get_pending(): array {
        return array_filter(
            get_option(self::PENDING_OPTION, []),
            fn($item) => $item['status'] === 'pending'
        );
    }

    public static function count_pending(): int {
        return count(self::get_pending());
    }

    /* ── AJAX : Suggérer une compétence (front) ── */
    public static function ajax_suggest(): void {
        check_ajax_referer('rdr_ed_nonce', 'nonce');
        if (!RDR_ED_Capabilities::user_can_submit()) wp_send_json_error('forbidden');

        $name = sanitize_text_field($_POST['skill_name'] ?? '');
        if (strlen($name) < 2 || strlen($name) > 50) {
            wp_send_json_error(['message' => __('Nom de compétence invalide (2-50 caractères).', 'rdr-expert-directory')]);
        }

        $user_id   = get_current_user_id();
        $review    = (int) RDR_ED_Settings::get('skill_free_tags_review');
        $existing  = get_term_by('name', $name, 'expert_skill');

        if ($existing) {
            wp_send_json_success([
                'term_id' => $existing->term_id,
                'name'    => $existing->name,
                'pending' => false,
                'message' => __('Cette compétence existe déjà et a été ajoutée à votre sélection.', 'rdr-expert-directory'),
            ]);
        }

        if (!$review) {
            $result = wp_insert_term($name, 'expert_skill');
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()]);
            }
            wp_send_json_success([
                'term_id' => $result['term_id'],
                'name'    => $name,
                'pending' => false,
                'message' => __('Compétence ajoutée.', 'rdr-expert-directory'),
            ]);
        } else {
            self::queue_pending($name, $user_id);
            wp_send_json_success([
                'term_id' => 0,
                'name'    => $name,
                'pending' => true,
                'message' => __('Votre compétence a été soumise pour validation. Elle sera ajoutée à votre fiche une fois approuvée.', 'rdr-expert-directory'),
            ]);
        }
    }

    /* ── AJAX : Approuver une compétence (admin) ── */
    public static function ajax_approve(): void {
        check_ajax_referer('rdr_ed_admin_nonce', 'nonce');
        if (!current_user_can(RDR_ED_Capabilities::CAP_SKILLS)) wp_send_json_error('forbidden');

        $key = sanitize_key($_POST['key'] ?? '');
        $pending = get_option(self::PENDING_OPTION, []);

        if (!isset($pending[$key])) wp_send_json_error('not_found');

        $name   = $pending[$key]['name'];
        $result = wp_insert_term($name, 'expert_skill');

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        $pending[$key]['status']      = 'approved';
        $pending[$key]['approved_at'] = current_time('mysql');
        $pending[$key]['term_id']     = $result['term_id'];
        update_option(self::PENDING_OPTION, $pending);

        wp_send_json_success([
            'term_id' => $result['term_id'],
            'name'    => $name,
            'message' => sprintf(__('Compétence "%s" créée.', 'rdr-expert-directory'), $name),
        ]);
    }

    /* ── AJAX : Refuser une compétence (admin) ── */
    public static function ajax_reject_skill(): void {
        check_ajax_referer('rdr_ed_admin_nonce', 'nonce');
        if (!current_user_can(RDR_ED_Capabilities::CAP_SKILLS)) wp_send_json_error('forbidden');

        $key     = sanitize_key($_POST['key'] ?? '');
        $pending = get_option(self::PENDING_OPTION, []);

        if (!isset($pending[$key])) wp_send_json_error('not_found');

        $pending[$key]['status']      = 'rejected';
        $pending[$key]['rejected_at'] = current_time('mysql');
        update_option(self::PENDING_OPTION, $pending);

        wp_send_json_success(['message' => __('Compétence refusée.', 'rdr-expert-directory')]);
    }

    /* ── AJAX : Charger les compétences en attente (admin) ── */
    public static function ajax_load_pending(): void {
        check_ajax_referer('rdr_ed_admin_nonce', 'nonce');
        if (!current_user_can(RDR_ED_Capabilities::CAP_SKILLS)) wp_send_json_error('forbidden');

        $pending = self::get_pending();
        $rows    = [];

        foreach ($pending as $key => $item) {
            $user   = get_userdata($item['user_id']);
            $rows[] = [
                'key'       => $key,
                'name'      => $item['name'],
                'user'      => $user ? $user->display_name : '?',
                'submitted' => date_i18n(get_option('date_format'), strtotime($item['submitted'])),
            ];
        }

        wp_send_json_success(['rows' => $rows, 'count' => count($rows)]);
    }

    /**
     * Rendu HTML de la section "compétences libres" dans la page admin des compétences.
     */
    public static function render_pending_admin(): void {
        $count = self::count_pending();
        if (!$count) return;
        ?>
        <div class="rdr-ed-pending-skills-box" style="margin-top:20px;background:#1c2128;border:1px solid #30363d;border-radius:8px;padding:16px;">
            <h3 style="margin:0 0 12px;color:#2ea8ff;">
                ⏳ <?php printf(
                    _n('%d compétence en attente de validation', '%d compétences en attente de validation', $count, 'rdr-expert-directory'),
                    $count
                ); ?>
            </h3>
            <div id="rdr-pending-skills-list">
                <p><?php _e('Chargement…', 'rdr-expert-directory'); ?></p>
            </div>
            <button type="button" class="button" id="rdr-load-pending-skills"
                data-nonce="<?= wp_create_nonce('rdr_ed_admin_nonce'); ?>">
                <?php _e('Afficher la liste', 'rdr-expert-directory'); ?>
            </button>
        </div>
        <?php
    }
}
