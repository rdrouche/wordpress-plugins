<?php
defined('ABSPATH') || exit;

/**
 * Shortcode annuaire public : [rdr_expert_directory]
 * Attributs :
 *   per_page="12"
 *   show_filters="true"
 */
class RDR_ED_Shortcode {

    public static function init(): void {
        add_shortcode('rdr_expert_directory', [self::class, 'render']);
        add_action('wp_ajax_rdr_ed_load_experts',        [self::class, 'ajax_load']);
        add_action('wp_ajax_nopriv_rdr_ed_load_experts', [self::class, 'ajax_load']);
    }

    public static function render(array $atts): string {
        // Valeur par défaut depuis les paramètres (peut être surchargée par l'attribut shortcode)
        $default_per_page = (int) RDR_ED_Settings::get('directory_per_page', 12);
        $atts = shortcode_atts([
            'per_page'     => $default_per_page,
            'show_filters' => 'true',
        ], $atts, 'rdr_expert_directory');

        $show_filters = filter_var($atts['show_filters'], FILTER_VALIDATE_BOOLEAN);

        // Récupérer les termes pour les filtres
        $skills    = get_terms(['taxonomy' => 'expert_skill',    'hide_empty' => true]);
        $languages = get_terms(['taxonomy' => 'expert_language', 'hide_empty' => true]);
        $countries = get_terms(['taxonomy' => 'expert_country',  'hide_empty' => true]);

        ob_start();
        ?>
        <div class="rdr-ed-directory"
            data-per-page="<?= (int) $atts['per_page']; ?>"
            data-ajaxurl="<?= esc_url(admin_url('admin-ajax.php')); ?>"
            data-nonce="<?= esc_attr(wp_create_nonce('rdr_ed_nonce')); ?>">

            <?php if ($show_filters): ?>
            <!-- ── Barre de filtres ── -->
            <div class="rdr-ed-filters">
                <div class="rdr-ed-search-wrap">
                    <input type="search"
                           id="rdr-ed-search"
                           class="rdr-ed-search"
                           placeholder="<?php esc_attr_e('Rechercher un expert, une compétence…', 'rdr-expert-directory'); ?>"
                           autocomplete="off">
                    <svg class="rdr-ed-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>
                    </svg>
                </div>
                <div class="rdr-ed-filter-row">
                    <select id="rdr-filter-skill" class="rdr-ed-select">
                        <option value=""><?php _e('Toutes les compétences', 'rdr-expert-directory'); ?></option>
                        <?php foreach ((array) $skills as $term): ?>
                        <option value="<?= (int) $term->term_id; ?>"><?= esc_html($term->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="rdr-filter-country" class="rdr-ed-select">
                        <option value=""><?php _e('Tous les pays', 'rdr-expert-directory'); ?></option>
                        <?php foreach ((array) $countries as $term): ?>
                        <option value="<?= (int) $term->term_id; ?>"><?= esc_html($term->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="rdr-filter-language" class="rdr-ed-select">
                        <option value=""><?php _e('Toutes les langues', 'rdr-expert-directory'); ?></option>
                        <?php foreach ((array) $languages as $term): ?>
                        <option value="<?= (int) $term->term_id; ?>"><?= esc_html($term->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select id="rdr-filter-type" class="rdr-ed-select">
                        <option value=""><?php _e('Tous les types', 'rdr-expert-directory'); ?></option>
                        <option value="independant"><?php _e('Indépendant', 'rdr-expert-directory'); ?></option>
                        <option value="entreprise"><?php _e('Entreprise', 'rdr-expert-directory'); ?></option>
                    </select>
                    <button type="button" id="rdr-filter-reset" class="button button-ghost button-sm">
                        <?php _e('Réinitialiser', 'rdr-expert-directory'); ?>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── Résultats ── -->
            <div class="rdr-ed-results-info" id="rdr-ed-results-info" aria-live="polite"></div>
            <div class="rdr-ed-grid" id="rdr-ed-grid" role="list">
                <!-- Skeleton initial -->
                <?php for ($i = 0; $i < 6; $i++): ?>
                <div class="rdr-ed-card rdr-ed-skeleton" aria-hidden="true">
                    <div class="rdr-ed-card-banner rdr-skel-block"></div>
                    <div class="rdr-ed-card-body">
                        <div class="rdr-ed-card-avatar-wrap">
                            <div class="rdr-ed-card-avatar rdr-skel-circle"></div>
                        </div>
                        <div class="rdr-skel-block" style="height:18px;width:60%;margin-bottom:8px;"></div>
                        <div class="rdr-skel-block" style="height:13px;width:40%;margin-bottom:12px;"></div>
                        <div class="rdr-skel-block" style="height:13px;width:90%;margin-bottom:6px;"></div>
                        <div class="rdr-skel-block" style="height:13px;width:75%;margin-bottom:16px;"></div>
                        <div class="rdr-ed-tags-row">
                            <div class="rdr-skel-block" style="height:22px;width:60px;border-radius:20px;"></div>
                            <div class="rdr-skel-block" style="height:22px;width:80px;border-radius:20px;"></div>
                            <div class="rdr-skel-block" style="height:22px;width:50px;border-radius:20px;"></div>
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Pagination -->
            <div class="rdr-ed-pagination" id="rdr-ed-pagination"></div>

        </div>
        <?php
        return ob_get_clean();
    }

    /* ── AJAX : Charger les experts ── */
    public static function ajax_load(): void {
        // Action publique de lecture — pas de nonce obligatoire (page potentiellement en cache)
        // Sécurité minimale : vérifier que c'est bien une requête POST WP
        if (!defined('DOING_AJAX') || !DOING_AJAX) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $per_page = min(48, max(1, (int) ($_POST['per_page'] ?? 12)));
        $page     = max(1, (int) ($_POST['page'] ?? 1));
        $search   = sanitize_text_field($_POST['search'] ?? '');
        $skill    = (int) ($_POST['skill']    ?? 0);
        $country  = (int) ($_POST['country']  ?? 0);
        $language = (int) ($_POST['language'] ?? 0);
        $type     = sanitize_key($_POST['type'] ?? '');
        if (!in_array($type, ['independant', 'entreprise', ''], true)) $type = '';

        $order_setting = RDR_ED_Settings::get('directory_order', 'rand');
        $orderby_map   = ['rand' => 'rand', 'date' => 'date', 'name' => 'title', 'modified' => 'modified'];
        $orderby       = $orderby_map[$order_setting] ?? 'rand';
        $order         = in_array($orderby, ['date', 'modified'], true) ? 'DESC' : '';

        $args = [
            'post_type'      => 'expert_profile',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => $orderby,
            'has_password'   => false,
            'no_found_rows'  => true,
        ];
        if ($order) $args['order'] = $order;
        if ($search) $args['s'] = $search;

        $tax_query = ['relation' => 'AND'];
        if ($skill)    $tax_query[] = ['taxonomy' => 'expert_skill',    'terms' => [$skill],    'field' => 'term_id'];
        if ($country)  $tax_query[] = ['taxonomy' => 'expert_country',  'terms' => [$country],  'field' => 'term_id'];
        if ($language) $tax_query[] = ['taxonomy' => 'expert_language', 'terms' => [$language], 'field' => 'term_id'];
        if (count($tax_query) > 1) $args['tax_query'] = $tax_query;

        if ($type) {
            $args['meta_query'] = [['key' => '_expert_type', 'value' => $type]];
        }

        $query = new WP_Query($args);
        $posts = $query->posts;

        // Filtrer : admin → toutes les fiches actives ; sinon → premium + actif
        $user_is_admin = is_user_logged_in() && current_user_can(RDR_ED_Capabilities::CAP_VALIDATE);

        $posts = array_values(array_filter($posts, function (WP_Post $p) use ($user_is_admin): bool {
            $status = RDR_ED_Versioning::get_status($p->ID);
            if ($status === 'inactive') return false;
            if ($user_is_admin)        return true;

            // Vérification premium avec protection contre les erreurs WPA
            try {
                return RDR_ED_Premium::is_premium((int) $p->post_author);
            } catch (\Throwable $e) {
                return false;
            }
        }));

        $total = count($posts);
        $paged = array_slice($posts, ($page - 1) * $per_page, $per_page);
        $pages = $per_page > 0 ? (int) ceil($total / $per_page) : 1;

        $cards = [];
        foreach ($paged as $p) {
            try {
                $cards[] = self::build_card_data($p);
            } catch (\Throwable $e) {
                // Ignorer silencieusement les cartes qui échouent
            }
        }

        wp_send_json_success([
            'cards'    => $cards,
            'total'    => $total,
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $per_page,
        ]);
    }

        /* ── Construire les données d'une carte ── */
    private static function build_card_data(WP_Post $post): array {
        $post_id = $post->ID;

        // Photo profil
        $photo_id  = (int) get_post_meta($post_id, '_profile_photo_id', true);
        $photo_url = $photo_id ? wp_get_attachment_image_url($photo_id, 'thumbnail') : '';
        if (!$photo_url) $photo_url = get_avatar_url($post->post_author, ['size' => 100]);

        // Bannière
        $banner_id  = (int) get_post_meta($post_id, '_banner_id', true);
        $banner_url = $banner_id ? wp_get_attachment_image_url($banner_id, 'medium_large') : '';

        // Compétences (max 5 en card)
        $skills = wp_get_post_terms($post_id, 'expert_skill');
        if (is_wp_error($skills)) $skills = [];
        $skill_tags = [];
        foreach (array_slice($skills, 0, 5) as $term) {
            $skill_tags[] = ['name' => $term->name, 'slug' => $term->slug];
        }
        $skills_extra = max(0, count($skills) - 5);

        // Pays + Langue
        $countries = wp_get_post_terms($post_id, 'expert_country');
        $languages = wp_get_post_terms($post_id, 'expert_language');
        if (is_wp_error($countries)) $countries = [];
        if (is_wp_error($languages)) $languages = [];

        // Extrait
        $excerpt = wp_trim_words(wp_strip_all_tags($post->post_content), 20, '…');

        return [
            'id'           => $post_id,
            'url'          => get_permalink($post_id),
            'shortlink'    => RDR_ED_Shortlinks::get_shortlink($post_id),
            'name'         => get_the_title($post_id),
            'title'        => get_post_meta($post_id, '_expert_title', true),
            'type'         => get_post_meta($post_id, '_expert_type', true),
            'company'      => get_post_meta($post_id, '_expert_company', true),
            'excerpt'      => $excerpt,
            'photo'        => esc_url($photo_url),
            'banner'       => esc_url($banner_url),
            'skills'       => $skill_tags,
            'skills_extra' => $skills_extra,
            'countries'    => wp_list_pluck($countries, 'name'),
            'languages'    => wp_list_pluck($languages, 'name'),
        ];
    }
}
