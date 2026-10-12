<?php
defined('ABSPATH') || exit;

/**
 * Administration — RDR Expert Directory
 * Méta boxes, colonnes, pages admin, actions AJAX admin.
 *
 * init() n'est appelé que lorsque is_admin() = true (géré dans rdr-expert-directory.php).
 */
class RDR_ED_Admin {

    /* ============================================================
       INITIALISATION
       ============================================================ */
    public static function init(): void {
        add_action('add_meta_boxes',           [self::class, 'meta_boxes']);
        add_action('admin_menu',               [self::class, 'admin_menu']);
        add_action('save_post_expert_profile', [self::class, 'save_noindex']);

        add_filter('manage_expert_profile_posts_columns',         [self::class, 'columns']);
        add_action('manage_expert_profile_posts_custom_column',   [self::class, 'column_content'], 10, 2);
        add_filter('manage_edit-expert_profile_sortable_columns', [self::class, 'sortable_columns']);

        add_action('restrict_manage_posts', [self::class, 'filter_bar']);
        add_filter('parse_query',           [self::class, 'parse_filter']);

        add_action('wp_ajax_rdr_ed_validate',      [self::class, 'ajax_validate']);
        add_action('wp_ajax_rdr_ed_reject',        [self::class, 'ajax_reject']);
        add_action('wp_ajax_rdr_ed_toggle_status', [self::class, 'ajax_toggle_status']);
    }

    /* ============================================================
       MENU ADMIN
       ============================================================ */
    public static function admin_menu(): void {
        add_submenu_page(
            'edit.php?post_type=expert_profile',
            __('En attente de validation', 'rdr-expert-directory'),
            __('⏳ En attente', 'rdr-expert-directory'),
            RDR_ED_Capabilities::CAP_VALIDATE,
            'rdr-ed-pending',
            [self::class, 'page_pending']
        );
        add_submenu_page(
            'edit.php?post_type=expert_profile',
            __('Statistiques annuaire', 'rdr-expert-directory'),
            __('📊 Statistiques', 'rdr-expert-directory'),
            'manage_options',
            'rdr-ed-stats',
            [self::class, 'page_stats']
        );
        add_submenu_page(
            'edit.php?post_type=expert_profile',
            __('Parametres', 'rdr-expert-directory'),
            __('⚙️ Parametres', 'rdr-expert-directory'),
            'manage_options',
            'rdr-ed-settings',
            ['RDR_ED_Settings', 'render_page']
        );
    }

    /* ============================================================
       SAUVEGARDE NOINDEX
       ============================================================ */
    public static function save_noindex(int $post_id): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!isset($_POST['rdr_ed_noindex_nonce'])) return;
        if (!wp_verify_nonce($_POST['rdr_ed_noindex_nonce'], 'rdr_ed_noindex_' . $post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        update_post_meta($post_id, '_expert_noindex', isset($_POST['expert_noindex']) ? '1' : '0');
    }

    /* ============================================================
       META BOXES
       ============================================================ */
    public static function meta_boxes(): void {
        $s = 'expert_profile';
        add_meta_box('rdr_ed_workflow', __('Workflow Expert IT', 'rdr-expert-directory'),   [self::class, 'mb_workflow'], $s, 'side',   'high');
        add_meta_box('rdr_ed_versions', __('Historique versions', 'rdr-expert-directory'),  [self::class, 'mb_versions'], $s, 'normal', 'default');
        add_meta_box('rdr_ed_contact',  __('Coordonnees (lecture)', 'rdr-expert-directory'),[self::class, 'mb_contact'],  $s, 'normal', 'default');
        add_meta_box('rdr_ed_premium',  __('Statut Premium', 'rdr-expert-directory'),       [self::class, 'mb_premium'],  $s, 'side',   'default');
        add_meta_box('rdr_ed_rankmath', __('Indexation (noindex)', 'rdr-expert-directory'), [self::class, 'mb_rankmath'], $s, 'side',   'default');
    }

    /* ── Workflow ── */
    public static function mb_workflow(WP_Post $post): void {
        $has_pending = RDR_ED_Versioning::has_pending($post->ID);
        $status      = RDR_ED_Versioning::get_status($post->ID);
        $pending     = RDR_ED_Versioning::get_pending($post->ID);
        $nonce       = wp_create_nonce('rdr_ed_admin_nonce');
        ?>
        <p>
            <strong><?php _e('Statut fiche :', 'rdr-expert-directory'); ?></strong>
            <span class="rdr-status-badge rdr-status-<?= esc_attr($status); ?>">
                <?= $status === 'active' ? '&#x2705; Active' : '&#x1F534; Inactive'; ?>
            </span>
        </p>
        <p>
            <button type="button" class="button button-small rdr-toggle-status"
                data-post="<?= esc_attr($post->ID); ?>"
                data-nonce="<?= esc_attr($nonce); ?>">
                <?= $status === 'active'
                    ? __('Desactiver la fiche', 'rdr-expert-directory')
                    : __('Reactiver la fiche', 'rdr-expert-directory'); ?>
            </button>
        </p>
        <?php if ($has_pending) : ?>
        <hr>
        <p><strong><?php _e('Revision en attente', 'rdr-expert-directory'); ?></strong></p>
        <?php if (!empty($pending['submitted_at'])) : ?>
        <p class="description"><?php printf(
            __('Soumise le %s', 'rdr-expert-directory'),
            date_i18n(get_option('date_format') . ' H:i', strtotime($pending['submitted_at']))
        ); ?></p>
        <?php endif; ?>
        <div style="display:flex;gap:8px;margin-top:8px;">
            <button type="button" class="button button-primary rdr-validate-btn"
                data-post="<?= esc_attr($post->ID); ?>"
                data-nonce="<?= esc_attr($nonce); ?>">
                &#x2705; <?php _e('Valider', 'rdr-expert-directory'); ?>
            </button>
            <button type="button" class="button rdr-reject-btn"
                data-post="<?= esc_attr($post->ID); ?>"
                data-nonce="<?= esc_attr($nonce); ?>">
                &#x274C; <?php _e('Refuser', 'rdr-expert-directory'); ?>
            </button>
        </div>
        <div id="rdr-reject-reason" style="display:none;margin-top:8px;">
            <textarea id="rdr-reject-text" class="widefat" rows="3"
                placeholder="<?php esc_attr_e('Motif du refus...', 'rdr-expert-directory'); ?>"></textarea>
            <button type="button" class="button button-secondary rdr-reject-confirm"
                data-post="<?= esc_attr($post->ID); ?>"
                data-nonce="<?= esc_attr($nonce); ?>"
                style="margin-top:4px;">
                <?php _e('Confirmer le refus', 'rdr-expert-directory'); ?>
            </button>
        </div>
        <?php else : ?>
        <p class="description"><?php _e('Aucune revision en attente.', 'rdr-expert-directory'); ?></p>
        <?php endif; ?>
        <?php
    }

    /* ── Versions archivées ── */
    public static function mb_versions(WP_Post $post): void {
        $versions = RDR_ED_Versioning::get_versions($post->ID);
        $pending  = RDR_ED_Versioning::get_pending($post->ID);

        if ($pending) {
            echo '<h4>' . __('Contenu en attente', 'rdr-expert-directory') . '</h4>';
            echo '<table class="widefat striped" style="margin-bottom:16px;">';
            echo '<tr><th>' . __('Champ', 'rdr-expert-directory') . '</th><th>' . __('Valeur', 'rdr-expert-directory') . '</th></tr>';
            foreach ($pending as $k => $v) {
                if (in_array($k, ['submitted_at', 'submitted_by'], true)) continue;
                $display = is_array($v) ? implode(', ', $v) : wp_strip_all_tags((string) $v);
                // Champs rich text : afficher le HTML, pas esc_html
                $rich_fields = ['post_content', '_expert_references', '_expert_description'];
                $cell_value  = in_array($k, $rich_fields, true)
                    ? '<div style="max-height:80px;overflow:auto;font-size:12px;">' . wp_kses_post($display) . '</div>'
                    : esc_html($display);
                echo '<tr><td><code>' . esc_html($k) . '</code></td><td>' . $cell_value . '</td></tr>';
            }
            echo '</table>';
        }

        if (empty($versions)) {
            echo '<p class="description">' . __('Aucune version archivee.', 'rdr-expert-directory') . '</p>';
            return;
        }

        echo '<h4>' . __('Versions archivees', 'rdr-expert-directory') . '</h4>';
        foreach ($versions as $i => $version) {
            $date = isset($version['archived_at'])
                ? date_i18n(get_option('date_format'), strtotime($version['archived_at']))
                : '?';
            echo '<details style="margin-bottom:8px;">';
            echo '<summary>' . sprintf(__('Version %d — %s', 'rdr-expert-directory'), $i + 1, $date) . '</summary>';
            echo '<table class="widefat striped" style="margin-top:6px;">';
            foreach ($version as $k => $v) {
                if ($k === 'archived_at') continue;
                $display = is_array($v) ? implode(', ', $v) : wp_strip_all_tags((string) $v);
                // Champs rich text : afficher le HTML, pas esc_html
                $rich_fields = ['post_content', '_expert_references', '_expert_description'];
                $cell_value  = in_array($k, $rich_fields, true)
                    ? '<div style="max-height:80px;overflow:auto;font-size:12px;">' . wp_kses_post($display) . '</div>'
                    : esc_html($display);
                echo '<tr><td><code>' . esc_html($k) . '</code></td><td>' . $cell_value . '</td></tr>';
            }
            echo '</table></details>';
        }
    }

    /* ── Contact ── */
    public static function mb_contact(WP_Post $post): void {
        $phone = get_post_meta($post->ID, '_expert_phone', true);
        $email = get_post_meta($post->ID, '_expert_email', true);
        echo '<table class="widefat">';
        echo '<tr><th>' . __('Telephone', 'rdr-expert-directory') . '</th><td>';
        echo $phone ? '<a href="tel:' . esc_attr($phone) . '">' . esc_html($phone) . '</a>' : '&mdash;';
        echo '</td></tr>';
        echo '<tr><th>' . __('Email', 'rdr-expert-directory') . '</th><td>';
        echo $email ? '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>' : '&mdash;';
        echo '</td></tr>';
        echo '</table>';
    }

    /* ── Premium ── */
    public static function mb_premium(WP_Post $post): void {
        $author_id = (int) $post->post_author;
        $is_prem   = RDR_ED_Premium::is_premium($author_id);
        $expiry    = RDR_ED_Premium::get_expiry_label($author_id);

        if ($is_prem) {
            echo '<p><span style="color:#00e5c0;">&#x2705; ' . __('Premium actif', 'rdr-expert-directory') . '</span>';
            if ($expiry) {
                echo '<br><span class="description">' . sprintf(__('Expire le %s', 'rdr-expert-directory'), $expiry) . '</span>';
            }
            echo '</p>';
        } else {
            echo '<p><span style="color:#e53e3e;">&#x274C; ' . __('Non premium &mdash; fiche masquee en front', 'rdr-expert-directory') . '</span></p>';
        }
    }

    /* ── RankMath / noindex ── */
    public static function mb_rankmath(WP_Post $post): void {
        $noindex = get_post_meta($post->ID, '_expert_noindex', true);
        wp_nonce_field('rdr_ed_noindex_' . $post->ID, 'rdr_ed_noindex_nonce');
        echo '<label>';
        echo '<input type="checkbox" name="expert_noindex" value="1" ' . checked($noindex, '1', false) . '>';
        echo ' ' . __('Ne pas indexer cette fiche (noindex)', 'rdr-expert-directory');
        echo '</label>';
    }

    /* ============================================================
       COLONNES LISTE ADMIN
       ============================================================ */
    public static function columns(array $cols): array {
        return [
            'cb'            => $cols['cb'],
            'title'         => __('Expert', 'rdr-expert-directory'),
            'expert_type'   => __('Type', 'rdr-expert-directory'),
            'expert_prem'   => __('Premium', 'rdr-expert-directory'),
            'expert_status' => __('Statut', 'rdr-expert-directory'),
            'expert_pend'   => __('Revision', 'rdr-expert-directory'),
            'expert_clicks' => __('Clics', 'rdr-expert-directory'),
            'date'          => $cols['date'],
        ];
    }

    public static function column_content(string $col, int $post_id): void {
        switch ($col) {
            case 'expert_type':
                echo esc_html(get_post_meta($post_id, '_expert_type', true) ?: '&mdash;');
                break;
            case 'expert_prem':
                echo RDR_ED_Premium::is_premium((int) get_post_field('post_author', $post_id)) ? '&#x2705;' : '&#x274C;';
                break;
            case 'expert_status':
                $s = RDR_ED_Versioning::get_status($post_id);
                echo $s === 'active'
                    ? '<span style="color:#00e5c0;">&#x25CF; Active</span>'
                    : '<span style="color:#e53e3e;">&#x25CF; Inactive</span>';
                break;
            case 'expert_pend':
                echo RDR_ED_Versioning::has_pending($post_id)
                    ? '<span style="color:#f6ad55;">&#x23F3; En attente</span>'
                    : '&mdash;';
                break;
            case 'expert_clicks':
                echo (int) get_post_meta($post_id, '_expert_click_count', true);
                break;
        }
    }

    public static function sortable_columns(array $cols): array {
        $cols['expert_clicks'] = 'expert_clicks';
        return $cols;
    }

    /* ============================================================
       FILTRE BARRE + PARSE QUERY
       ============================================================ */
    public static function filter_bar(string $post_type): void {
        global $pagenow;
        if ($post_type !== 'expert_profile' || $pagenow !== 'edit.php') return;

        $f = sanitize_key($_GET['expert_pending_filter'] ?? '');
        echo '<select name="expert_pending_filter">';
        echo '<option value="">'            . __('Toutes les fiches',          'rdr-expert-directory') . '</option>';
        echo '<option value="pending" '    . selected($f, 'pending',  false) . '>' . __('En attente de validation', 'rdr-expert-directory') . '</option>';
        echo '<option value="active" '     . selected($f, 'active',   false) . '>' . __('Actives',                  'rdr-expert-directory') . '</option>';
        echo '<option value="inactive" '   . selected($f, 'inactive', false) . '>' . __('Inactives',                'rdr-expert-directory') . '</option>';
        echo '</select>';
    }

    public static function parse_filter(WP_Query $query): void {
        global $pagenow;
        if (!is_admin() || $pagenow !== 'edit.php') return;
        if (($query->query['post_type'] ?? '') !== 'expert_profile') return;

        // Tri par clics
        if ($query->get('orderby') === 'expert_clicks') {
            $query->set('meta_key', '_expert_click_count');
            $query->set('orderby',  'meta_value_num');
        }

        // Filtre statut/révision
        $filter = sanitize_key($_GET['expert_pending_filter'] ?? '');
        if ($filter === 'pending') {
            $query->set('meta_query', [['key' => '_expert_pending_data', 'compare' => 'EXISTS']]);
        } elseif (in_array($filter, ['active', 'inactive'], true)) {
            $query->set('meta_query', [['key' => '_expert_status', 'value' => $filter]]);
        }
    }

    /* ============================================================
       PAGE — EN ATTENTE
       ============================================================ */
    public static function page_pending(): void {
        $posts          = get_posts(['post_type' => 'expert_profile', 'posts_per_page' => -1,
                                     'meta_query' => [['key' => '_expert_pending_data', 'compare' => 'EXISTS']]]);
        $pending_skills = RDR_ED_Free_Tags::get_pending();
        $total          = count($posts) + count($pending_skills);

        echo '<div class="wrap">';
        echo '<h1>' . __('Elements en attente de validation', 'rdr-expert-directory');
        if ($total) echo ' <span class="awaiting-mod">' . $total . '</span>';
        echo '</h1>';

        // Fiches
        echo '<h2 style="margin-top:20px;">' . __('Fiches experts', 'rdr-expert-directory')
           . ' <span class="awaiting-mod">' . count($posts) . '</span></h2>';

        if (empty($posts)) {
            echo '<p>' . __('Aucune fiche en attente.', 'rdr-expert-directory') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
               . '<th>' . __('Expert', 'rdr-expert-directory')  . '</th>'
               . '<th>' . __('Auteur', 'rdr-expert-directory')  . '</th>'
               . '<th>' . __('Soumis le', 'rdr-expert-directory') . '</th>'
               . '<th style="width:160px;">' . __('Actions', 'rdr-expert-directory') . '</th>'
               . '</tr></thead><tbody>';

            foreach ($posts as $p) {
                $pdata  = RDR_ED_Versioning::get_pending($p->ID);
                $date   = !empty($pdata['submitted_at'])
                    ? date_i18n(get_option('date_format') . ' H:i', strtotime($pdata['submitted_at']))
                    : '&mdash;';
                $author = get_userdata($p->post_author);
                $nonce  = wp_create_nonce('rdr_ed_admin_nonce');
                $title  = $p->post_title ?: __('(sans titre)', 'rdr-expert-directory');

                echo '<tr>';
                echo '<td><a href="' . esc_url(get_edit_post_link($p->ID)) . '"><strong>' . esc_html($title) . '</strong></a></td>';
                echo '<td>' . ($author ? esc_html($author->display_name) : '&mdash;') . '</td>';
                echo '<td>' . $date . '</td>';
                echo '<td style="display:flex;gap:6px;">'
                   . '<button class="button button-primary button-small rdr-validate-btn" data-post="' . esc_attr($p->ID) . '" data-nonce="' . esc_attr($nonce) . '">&#x2705; ' . __('Valider', 'rdr-expert-directory') . '</button>'
                   . '<a href="' . esc_url(get_edit_post_link($p->ID)) . '" class="button button-small">' . __('Voir', 'rdr-expert-directory') . '</a>'
                   . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }

        // Competences
        echo '<h2 style="margin-top:32px;">' . __('Competences proposees', 'rdr-expert-directory')
           . ' <span class="awaiting-mod">' . count($pending_skills) . '</span></h2>';

        if (empty($pending_skills)) {
            echo '<p>' . __('Aucune competence en attente.', 'rdr-expert-directory') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped"><thead><tr>'
               . '<th>' . __('Competence', 'rdr-expert-directory') . '</th>'
               . '<th>' . __('Propose par', 'rdr-expert-directory') . '</th>'
               . '<th>' . __('Date', 'rdr-expert-directory') . '</th>'
               . '<th style="width:180px;">' . __('Actions', 'rdr-expert-directory') . '</th>'
               . '</tr></thead><tbody>';

            foreach ($pending_skills as $key => $skill) {
                $su    = get_userdata($skill['user_id']);
                $nonce = wp_create_nonce('rdr_ed_admin_nonce');
                echo '<tr>'
                   . '<td><strong>' . esc_html($skill['name']) . '</strong></td>'
                   . '<td>' . ($su ? esc_html($su->display_name) : '&mdash;') . '</td>'
                   . '<td>' . date_i18n(get_option('date_format'), strtotime($skill['submitted'])) . '</td>'
                   . '<td style="display:flex;gap:6px;">'
                   . '<button class="button button-primary button-small rdr-approve-skill" data-key="' . esc_attr($key) . '" data-nonce="' . esc_attr($nonce) . '">&#x2705; ' . __('Approuver', 'rdr-expert-directory') . '</button>'
                   . '<button class="button button-small rdr-reject-skill" data-key="' . esc_attr($key) . '" data-nonce="' . esc_attr($nonce) . '">&#x274C; ' . __('Refuser', 'rdr-expert-directory') . '</button>'
                   . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        echo '</div>';
    }

    /* ============================================================
       PAGE — STATISTIQUES
       ============================================================ */
    public static function page_stats(): void {
        $total    = wp_count_posts('expert_profile');
        $post_ids = get_posts(['post_type' => 'expert_profile', 'posts_per_page' => -1, 'fields' => 'ids']);
        $clicks   = $reveals = $contacts = $premium = 0;

        foreach ($post_ids as $pid) {
            $clicks   += (int) get_post_meta($pid, '_expert_click_count', true);
            $reveals  += (int) get_post_meta($pid, '_expert_reveal_count_email', true);
            $reveals  += (int) get_post_meta($pid, '_expert_reveal_count_phone', true);
            $reveals  += (int) get_post_meta($pid, '_expert_reveal_count_linkedin', true);
            $contacts += (int) get_post_meta($pid, '_expert_contact_count', true);
            if (RDR_ED_Premium::is_premium((int) get_post_field('post_author', $pid))) {
                $premium++;
            }
        }

        $top = get_posts([
            'post_type' => 'expert_profile', 'posts_per_page' => 5,
            'meta_key'  => '_expert_click_count', 'meta_type' => 'NUMERIC',
            'orderby'   => 'meta_value_num', 'order' => 'DESC',
        ]);

        echo '<div class="wrap">';
        echo '<h1>' . __('Statistiques de l\'annuaire', 'rdr-expert-directory') . '</h1>';
        echo '<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:20px;">';

        foreach ([
            ['📋', __('Fiches publiees',     'rdr-expert-directory'), (int)$total->publish, '#2ea8ff'],
            ['⭐', __('Experts premium',      'rdr-expert-directory'), $premium,             '#f6ad55'],
            ['⏳', __('En attente',           'rdr-expert-directory'), (int)$total->pending, '#8b949e'],
            ['👁️', __('Vues shortlink',       'rdr-expert-directory'), $clicks,              '#00e5c0'],
            ['📧', __('Revelations contact',  'rdr-expert-directory'), $reveals,             '#7c3aed'],
            ['📩', __('Messages envoyes',     'rdr-expert-directory'), $contacts,            '#10b981'],
        ] as [$icon, $label, $value, $color]) {
            echo '<div style="background:#1c2128;border:1px solid #30363d;border-radius:8px;padding:20px;text-align:center;">'
               . '<div style="font-size:24px;margin-bottom:4px;">' . $icon . '</div>'
               . '<div style="font-size:28px;font-weight:700;color:' . esc_attr($color) . ';line-height:1;">' . number_format_i18n($value) . '</div>'
               . '<div style="color:#8b949e;font-size:12px;margin-top:4px;">' . esc_html($label) . '</div>'
               . '</div>';
        }
        echo '</div>';

        if (!empty($top)) {
            echo '<h2 style="margin-top:32px;">' . __('Top 5 experts (vues shortlink)', 'rdr-expert-directory') . '</h2>';
            echo '<table class="wp-list-table widefat fixed striped" style="max-width:600px;">';
            echo '<thead><tr>'
               . '<th>' . __('Expert', 'rdr-expert-directory') . '</th>'
               . '<th style="width:80px;text-align:right;">' . __('Vues', 'rdr-expert-directory') . '</th>'
               . '<th style="width:80px;text-align:right;">' . __('Revel.', 'rdr-expert-directory') . '</th>'
               . '<th style="width:80px;text-align:right;">' . __('Messages', 'rdr-expert-directory') . '</th>'
               . '</tr></thead><tbody>';

            foreach ($top as $p) {
                $pc = (int) get_post_meta($p->ID, '_expert_click_count', true);
                $pr = (int) get_post_meta($p->ID, '_expert_reveal_count_email', true)
                   + (int) get_post_meta($p->ID, '_expert_reveal_count_phone', true)
                   + (int) get_post_meta($p->ID, '_expert_reveal_count_linkedin', true);
                $pm = (int) get_post_meta($p->ID, '_expert_contact_count', true);
                echo '<tr>'
                   . '<td><a href="' . esc_url(get_edit_post_link($p->ID)) . '">' . esc_html($p->post_title) . '</a></td>'
                   . '<td style="text-align:right;">' . number_format_i18n($pc) . '</td>'
                   . '<td style="text-align:right;">' . number_format_i18n($pr) . '</td>'
                   . '<td style="text-align:right;">' . number_format_i18n($pm) . '</td>'
                   . '</tr>';
            }
            echo '</tbody></table>';
        }
        echo '</div>';
    }

    /* ============================================================
       AJAX
       ============================================================ */
    public static function ajax_validate(): void {
        check_ajax_referer('rdr_ed_admin_nonce', 'nonce');
        if (!current_user_can(RDR_ED_Capabilities::CAP_VALIDATE)) wp_send_json_error('forbidden');
        $post_id = (int) ($_POST['post_id'] ?? 0);
        if (!$post_id) wp_send_json_error('invalid_post');
        do_action('rdr_ed_profile_approved', $post_id);
        wp_send_json_success(['message' => __('Fiche validee et publiee.', 'rdr-expert-directory')]);
    }

    public static function ajax_reject(): void {
        check_ajax_referer('rdr_ed_admin_nonce', 'nonce');
        if (!current_user_can(RDR_ED_Capabilities::CAP_VALIDATE)) wp_send_json_error('forbidden');
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $reason  = sanitize_textarea_field($_POST['reason'] ?? '');
        do_action('rdr_ed_profile_rejected', $post_id, $reason);
        wp_send_json_success(['message' => __('Revision refusee.', 'rdr-expert-directory')]);
    }

    public static function ajax_toggle_status(): void {
        check_ajax_referer('rdr_ed_admin_nonce', 'nonce');
        if (!current_user_can(RDR_ED_Capabilities::CAP_VALIDATE)) wp_send_json_error('forbidden');
        $post_id = (int) ($_POST['post_id'] ?? 0);
        $new     = RDR_ED_Versioning::get_status($post_id) === 'active' ? 'inactive' : 'active';
        RDR_ED_Versioning::set_status($post_id, $new);
        wp_send_json_success(['status' => $new]);
    }
}
