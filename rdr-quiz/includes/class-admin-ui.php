<?php
/**
 * Interface d'administration — Meta boxes Quiz & Question
 * v1.2 : difficulté par question, filtre tag+difficulté dans le gestionnaire
 */
defined('ABSPATH') || exit;

class RDR_Quiz_AdminUI {

    public static function init(): void {
        add_action('add_meta_boxes',         [self::class, 'add_meta_boxes']);
        add_action('save_post',              [self::class, 'save_quiz_meta'],     10, 2);
        add_action('save_post',              [self::class, 'save_question_meta'], 10, 2);
        add_action('admin_enqueue_scripts',  [self::class, 'admin_assets']);
        add_action('wp_ajax_rdr_get_questions', [self::class, 'ajax_get_questions']);
    }

    /* ─── ASSETS ADMIN ───────────────────────────────────────── */
    public static function admin_assets(string $hook): void {
        $screen = get_current_screen();
        if (! $screen || ! in_array($screen->post_type, ['rdr_quiz', 'rdr_question'])) return;

        wp_enqueue_style('rdr-quiz-admin', RDR_QUIZ_URL . 'assets/css/admin.css', [], RDR_QUIZ_VERSION);
        wp_enqueue_script('rdr-quiz-admin', RDR_QUIZ_URL . 'assets/js/admin.js', ['jquery', 'jquery-ui-sortable'], RDR_QUIZ_VERSION, true);
        wp_localize_script('rdr-quiz-admin', 'rdrQuizAdmin', [
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('rdr_quiz_admin'),
            'i18n'          => [
                'add'        => __('Ajouter', 'rdr-quiz'),
                'remove'     => __('Retirer', 'rdr-quiz'),
                'no_results' => __('Aucune question trouvée', 'rdr-quiz'),
                'loading'    => __('Chargement…', 'rdr-quiz'),
            ],
        ]);
    }

    /* ─── META BOXES ─────────────────────────────────────────── */
    public static function add_meta_boxes(): void {
        add_meta_box('rdr_quiz_settings',  __('⚙️ Paramètres du Quiz',    'rdr-quiz'), [self::class, 'render_quiz_settings'],  'rdr_quiz', 'normal', 'high');
        add_meta_box('rdr_quiz_questions', __('📋 Questions du Quiz',     'rdr-quiz'), [self::class, 'render_quiz_questions'], 'rdr_quiz', 'normal', 'high');
        add_meta_box('rdr_quiz_stats_box', __('📊 Statistiques',          'rdr-quiz'), [self::class, 'render_quiz_stats'],     'rdr_quiz', 'side',   'default');
        add_meta_box('rdr_question_meta',  __('🎯 Contenu de la Question','rdr-quiz'), [self::class, 'render_question_meta'],  'rdr_question', 'normal', 'high');
    }

    /* ─── QUIZ SETTINGS ──────────────────────────────────────── */
    public static function render_quiz_settings(\WP_Post $post): void {
        wp_nonce_field('rdr_quiz_save', 'rdr_quiz_nonce');
        $meta = self::get_quiz_meta($post->ID);
        ?>
        <div class="rdr-admin-grid">
            <div class="rdr-field">
                <label><?php _e('Nombre de questions', 'rdr-quiz'); ?></label>
                <select name="rdr_q_count">
                    <option value="all" <?php selected($meta['q_count'], 'all'); ?>><?php _e('Toutes', 'rdr-quiz'); ?></option>
                    <?php foreach ([5,6,7,8,9,10,15,20] as $n): ?>
                    <option value="<?php echo $n; ?>" <?php selected($meta['q_count'], $n); ?>><?php echo $n; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rdr-field">
                <label><?php _e('Ordre des questions', 'rdr-quiz'); ?></label>
                <select name="rdr_q_order">
                    <option value="random" <?php selected($meta['q_order'], 'random'); ?>><?php _e('Aléatoire', 'rdr-quiz'); ?></option>
                    <option value="fixed"  <?php selected($meta['q_order'], 'fixed'); ?>><?php _e('Fixe', 'rdr-quiz'); ?></option>
                </select>
            </div>
            <div class="rdr-field">
                <label><?php _e('Difficulté globale', 'rdr-quiz'); ?></label>
                <select name="rdr_difficulty">
                    <option value="debutant"      <?php selected($meta['difficulty'], 'debutant'); ?>><?php _e('Débutant', 'rdr-quiz'); ?></option>
                    <option value="intermediaire" <?php selected($meta['difficulty'], 'intermediaire'); ?>><?php _e('Intermédiaire', 'rdr-quiz'); ?></option>
                    <option value="avance"        <?php selected($meta['difficulty'], 'avance'); ?>><?php _e('Avancé', 'rdr-quiz'); ?></option>
                    <option value="expert"        <?php selected($meta['difficulty'], 'expert'); ?>><?php _e('Expert', 'rdr-quiz'); ?></option>
                </select>
            </div>
            <div class="rdr-field">
                <label><?php _e('Durée estimée (min)', 'rdr-quiz'); ?></label>
                <input type="number" name="rdr_duration" value="<?php echo esc_attr($meta['duration']); ?>" min="1" max="120">
            </div>
        </div>
        <div class="rdr-field rdr-field-full">
            <label><?php _e('Lien article associé (optionnel)', 'rdr-quiz'); ?></label>
            <input type="url" name="rdr_article_link" value="<?php echo esc_attr($meta['article_link']); ?>" placeholder="https://" style="width:100%">
        </div>
        <div class="rdr-field rdr-field-full">
            <label>
                <input type="checkbox" name="rdr_hide_tags" value="1" <?php checked($meta['hide_tags'], '1'); ?>>
                <?php _e('🙈 Masquer les «&nbsp;Sujets abordés&nbsp;» sur la page du quiz', 'rdr-quiz'); ?>
            </label>
            <p class="description"><?php _e('Utile si vous utilisez les tags uniquement pour organiser les questions en interne, sans les exposer aux visiteurs.', 'rdr-quiz'); ?></p>
        </div>
        <div class="rdr-field rdr-field-full">
            <label>
                <input type="checkbox" name="rdr_hide_from_list" value="1" <?php checked($meta['hide_from_list'], '1'); ?>>
                <?php _e('🚫 Masquer ce quiz de la page liste ([rdr_quiz_list])', 'rdr-quiz'); ?>
            </label>
            <p class="description"><?php _e("Le quiz reste accessible par son URL mais n'appara&icirc;t pas dans la liste publique.", 'rdr-quiz'); ?></p>
        </div>
        <div class="rdr-field rdr-field-full">
            <label><strong><?php _e('📄 Page liste associée (breadcrumb)', 'rdr-quiz'); ?></strong></label>
            <select name="rdr_archive_page_id" style="width:100%;max-width:500px">
                <option value=""><?php _e('— Détection automatique —', 'rdr-quiz'); ?></option>
                <?php
                // Toutes les pages publiées (on inclut les traductions Polylang)
                $pages = get_pages(['post_status' => 'publish', 'sort_column' => 'post_title']);
                foreach ($pages as $page):
                    $lang = '';
                    if (function_exists('pll_get_post_language')) {
                        $lang_code = pll_get_post_language($page->ID, 'slug');
                        if ($lang_code) $lang = ' [' . strtoupper($lang_code) . ']';
                    }
                ?>
                <option value="<?php echo $page->ID; ?>" <?php selected($meta['archive_page_id'], $page->ID); ?>>
                    <?php echo esc_html($page->post_title . $lang); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <p class="description"><?php _e("Sélectionnez la page qui contient le shortcode [rdr_quiz_list]. Utilisée pour construire le fil d'Ariane. Avec Polylang, choisissez la page dans la langue du quiz.", 'rdr-quiz'); ?></p>
        </div>
        <?php
    }

    /* ─── QUIZ QUESTIONS LIST ────────────────────────────────── */
    public static function render_quiz_questions(\WP_Post $post): void {
        $linked = get_post_meta($post->ID, '_rdr_quiz_questions', true) ?: [];

        // Tags disponibles pour le filtre
        $tags = get_terms(['taxonomy' => 'rdr_question_tag', 'hide_empty' => true, 'orderby' => 'name']);

        // Toutes les questions (pour l'affichage initial)
        $all_questions = get_posts([
            'post_type'      => 'rdr_question',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        ]);
        ?>
        <div id="rdr-questions-manager">

            <!-- ── Barre de filtres / recherche ── -->
            <div class="rdr-questions-toolbar">

                <!-- Ligne 1 : recherche texte + ID -->
                <div class="rdr-toolbar-row">
                    <input type="text" id="rdr-q-search"
                        placeholder="<?php _e('Titre…', 'rdr-quiz'); ?>"
                        autocomplete="off">
                    <input type="number" id="rdr-q-search-id"
                        placeholder="ID"
                        min="1" style="width:80px">
                    <select id="rdr-q-filter-diff">
                        <option value=""><?php _e('Toutes difficultés', 'rdr-quiz'); ?></option>
                        <option value="debutant"><?php _e('Débutant', 'rdr-quiz'); ?></option>
                        <option value="intermediaire"><?php _e('Intermédiaire', 'rdr-quiz'); ?></option>
                        <option value="avance"><?php _e('Avancé', 'rdr-quiz'); ?></option>
                        <option value="expert"><?php _e('Expert', 'rdr-quiz'); ?></option>
                    </select>
                    <button type="button" id="rdr-q-apply-filters" class="button button-primary">
                        🔍 <?php _e('Filtrer', 'rdr-quiz'); ?>
                    </button>
                    <button type="button" id="rdr-q-reset-filters" class="button">
                        ✕ <?php _e('Reset', 'rdr-quiz'); ?>
                    </button>
                    <a href="<?php echo admin_url('post-new.php?post_type=rdr_question'); ?>" class="button button-secondary" target="_blank" style="margin-left:auto">
                        + <?php _e('Créer', 'rdr-quiz'); ?>
                    </a>
                </div>

                <!-- Ligne 2 : tags multi-sélection (ET) -->
                <?php if (!empty($tags) && !is_wp_error($tags)): ?>
                <div class="rdr-toolbar-tags" id="rdr-tag-pills-filter">
                    <span class="rdr-tag-filter-label"><?php _e('Tags (ET) :', 'rdr-quiz'); ?></span>
                    <?php foreach ($tags as $tag): ?>
                    <label class="rdr-tag-filter-pill">
                        <input type="checkbox" name="rdr_q_tags[]"
                               value="<?php echo esc_attr($tag->slug); ?>"
                               class="rdr-tag-checkbox">
                        <?php echo esc_html($tag->name); ?>
                        <span class="rdr-tag-count"><?php echo $tag->count; ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

            </div>

            <!-- ── Colonnes ── -->
            <div class="rdr-questions-columns">

                <!-- Disponibles -->
                <div class="rdr-q-available">
                    <div class="rdr-q-col-header">
                        <h4><?php _e('Questions disponibles', 'rdr-quiz'); ?></h4>
                        <span id="rdr-available-count" class="rdr-count-badge"></span>
                    </div>
                    <ul id="rdr-available-list">
                    <?php
                    $diff_labels = ['debutant'=>'Deb','intermediaire'=>'Int','avance'=>'Avd','expert'=>'Exp'];
                    foreach ($all_questions as $q):
                        if (in_array($q->ID, $linked)) continue;
                        $type   = get_post_meta($q->ID, '_rdr_answer_type', true) ?: 'radio';
                        $diff   = get_post_meta($q->ID, '_rdr_question_difficulty', true) ?: '';
                        $q_tags = wp_get_post_terms($q->ID, 'rdr_question_tag', ['fields' => 'slugs']);
                        $q_tags_str = implode(',', $q_tags);
                    ?>
                        <li data-id="<?php echo $q->ID; ?>"
                            data-title="<?php echo esc_attr(mb_strtolower($q->post_title)); ?>"
                            data-tags="<?php echo esc_attr($q_tags_str); ?>"
                            data-diff="<?php echo esc_attr($diff); ?>">
                            <div class="rdr-q-item-inner">
                                <div class="rdr-q-item-badges">
                                    <span class="rdr-q-type rdr-type-<?php echo $type; ?>"><?php echo self::type_label($type); ?></span>
                                    <?php if ($diff): ?><span class="rdr-q-diff rdr-diff-<?php echo $diff; ?>"><?php echo $diff_labels[$diff] ?? $diff; ?></span><?php endif; ?>
                                    <?php foreach (wp_get_post_terms($q->ID, 'rdr_question_tag') as $t): ?>
                                    <span class="rdr-q-tag-pill"><?php echo esc_html($t->name); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <span class="rdr-q-title"><?php echo esc_html($q->post_title); ?></span>
                            </div>
                            <button type="button" class="rdr-add-q button button-small" title="<?php _e('Ajouter', 'rdr-quiz'); ?>">+</button>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Sélectionnées -->
                <div class="rdr-q-selected">
                    <div class="rdr-q-col-header">
                        <h4><?php _e('Dans ce quiz', 'rdr-quiz'); ?></h4>
                        <span id="rdr-selected-count" class="rdr-count-badge"></span>
                    </div>
                    <ul id="rdr-selected-list" class="rdr-sortable">
                    <?php
                    foreach ($linked as $qid):
                        $q = get_post($qid); if (!$q) continue;
                        $type = get_post_meta($qid, '_rdr_answer_type', true) ?: 'radio';
                        $diff = get_post_meta($qid, '_rdr_question_difficulty', true) ?: '';
                    ?>
                        <li data-id="<?php echo $qid; ?>">
                            <span class="dashicons dashicons-move rdr-handle"></span>
                            <div class="rdr-q-item-inner">
                                <div class="rdr-q-item-badges">
                                    <span class="rdr-q-type rdr-type-<?php echo $type; ?>"><?php echo self::type_label($type); ?></span>
                                    <?php if ($diff): ?><span class="rdr-q-diff rdr-diff-<?php echo $diff; ?>"><?php echo $diff_labels[$diff] ?? $diff; ?></span><?php endif; ?>
                                </div>
                                <span class="rdr-q-title"><?php echo esc_html($q->post_title); ?></span>
                            </div>
                            <input type="hidden" name="rdr_quiz_questions[]" value="<?php echo $qid; ?>">
                            <button type="button" class="rdr-remove-q button button-small" title="<?php _e('Retirer', 'rdr-quiz'); ?>">×</button>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }

    /* ─── AJAX : renvoyer les questions filtrées ─────────────── */
    public static function ajax_get_questions(): void {
        check_ajax_referer('rdr_quiz_admin', 'nonce');

        // Multi-tags : JS envoie tags[] (tableau)
        $tags_raw  = [];
        if (!empty($_POST['tags']) && is_array($_POST['tags'])) {
            $tags_raw = array_map('sanitize_text_field', $_POST['tags']);
        }

        $diff      = sanitize_text_field($_POST['diff']      ?? '');
        $search    = sanitize_text_field($_POST['search']    ?? '');
        $search_id = absint($_POST['search_id']              ?? 0);
        $exclude   = !empty($_POST['exclude']) && is_array($_POST['exclude'])
                     ? array_map('absint', $_POST['exclude']) : [];

        // Recherche directe par ID
        if ($search_id) {
            $q = get_post($search_id);
            if ($q && $q->post_type === 'rdr_question' && $q->post_status === 'publish') {
                $type   = get_post_meta($q->ID, '_rdr_answer_type',         true) ?: 'radio';
                $qdiff  = get_post_meta($q->ID, '_rdr_question_difficulty', true) ?: '';
                wp_send_json_success([[
                    'id'    => $q->ID,
                    'title' => $q->post_title,
                    'type'  => $type,
                    'diff'  => $qdiff,
                    'tags'  => wp_get_post_terms($q->ID, 'rdr_question_tag', ['fields' => 'names']),
                    'slugs' => wp_get_post_terms($q->ID, 'rdr_question_tag', ['fields' => 'slugs']),
                ]]);
            } else {
                wp_send_json_success([]);
            }
            return;
        }

        $args = [
            'post_type'      => 'rdr_question',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];

        if (!empty($exclude)) {
            $args['post__not_in'] = $exclude;
        }

        // Multi-tags en mode ET : chaque tag sélectionné doit être présent
        if (!empty($tags_raw)) {
            $tax_query = ['relation' => 'AND'];
            foreach ($tags_raw as $slug) {
                $tax_query[] = [
                    'taxonomy' => 'rdr_question_tag',
                    'field'    => 'slug',
                    'terms'    => $slug,
                ];
            }
            $args['tax_query'] = $tax_query;
        }

        if ($diff) {
            $args['meta_query'] = [['key' => '_rdr_question_difficulty', 'value' => $diff]];
        }
        if ($search) {
            $args['s'] = $search;
        }

        $questions = get_posts($args);
        $result    = [];
        foreach ($questions as $q) {
            $type  = get_post_meta($q->ID, '_rdr_answer_type',         true) ?: 'radio';
            $qdiff = get_post_meta($q->ID, '_rdr_question_difficulty', true) ?: '';
            $result[] = [
                'id'    => $q->ID,
                'title' => $q->post_title,
                'type'  => $type,
                'diff'  => $qdiff,
                'tags'  => wp_get_post_terms($q->ID, 'rdr_question_tag', ['fields' => 'names']),
                'slugs' => wp_get_post_terms($q->ID, 'rdr_question_tag', ['fields' => 'slugs']),
            ];
        }

        wp_send_json_success($result);
    }

    /* ─── QUIZ STATS BOX ─────────────────────────────────────── */
    public static function render_quiz_stats(\WP_Post $post): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rdr_quiz_stats';
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE quiz_id = %d", $post->ID));
        ?>
        <div class="rdr-stats-box">
            <p><strong><?php _e('Tentatives', 'rdr-quiz'); ?> :</strong> <?php echo $row ? number_format_i18n($row->attempts) : 0; ?></p>
            <p><strong><?php _e('Score moyen', 'rdr-quiz'); ?> :</strong> <?php echo $row && $row->attempts > 0 ? round($row->total_score / $row->attempts, 1) . ' %' : '—'; ?></p>
            <hr>
            <p><strong><?php _e('Shortcode quiz', 'rdr-quiz'); ?> :</strong><br>
                <code>[rdr_quiz id="<?php echo $post->ID; ?>"]</code></p>
            <p><strong><?php _e('CTA / lien vers ce quiz', 'rdr-quiz'); ?> :</strong><br>
                <code>[rdr_quiz_link id="<?php echo $post->ID; ?>"]</code></p>
            <p class="description"><?php _e('Options&nbsp;: label="Texte" style="button|inline|card"', 'rdr-quiz'); ?></p>
        </div>
        <?php
    }

    /* ─── QUESTION META ──────────────────────────────────────── */
    public static function render_question_meta(\WP_Post $post): void {
        wp_nonce_field('rdr_question_save', 'rdr_question_nonce');
        $type        = get_post_meta($post->ID, '_rdr_answer_type',         true) ?: 'radio';
        $answers     = get_post_meta($post->ID, '_rdr_answers',             true) ?: [];
        $explanation = get_post_meta($post->ID, '_rdr_explanation',         true) ?: '';
        $doc_link    = get_post_meta($post->ID, '_rdr_doc_link',            true) ?: '';
        $q_diff      = get_post_meta($post->ID, '_rdr_question_difficulty', true) ?: '';
        ?>
        <div id="rdr-question-editor">

            <!-- Corps de la question (optionnel — pour code, image, texte long) -->
            <div class="rdr-field rdr-field-full" style="margin-bottom:20px">
                <label><strong><?php _e('📝 Corps de la question (optionnel)', 'rdr-quiz'); ?></strong></label>
                <p class="description" style="margin-bottom:6px">
                    <?php _e('Utilisez cet éditeur pour ajouter du code, des images ou un contexte détaillé. Le titre reste le texte principal de la question.', 'rdr-quiz'); ?>
                </p>
                <?php
                $q_body = get_post_meta($post->ID, '_rdr_question_body', true) ?: '';
                wp_editor($q_body, 'rdr_question_body', [
                    'textarea_name' => 'rdr_question_body',
                    'media_buttons' => true,
                    'textarea_rows' => 8,
                    'teeny'         => false,
                    'tinymce'       => [
                        'toolbar1' => 'bold,italic,underline,|,bullist,numlist,|,link,|,code,|,undo,redo',
                    ],
                ]);
                ?>
            </div>

            <!-- Type de réponse + Difficulté sur la même ligne -->
            <div class="rdr-admin-grid rdr-admin-grid-2col" style="margin-bottom:16px">
                <div class="rdr-field">
                    <label><strong><?php _e('Type de réponse', 'rdr-quiz'); ?></strong></label>
                    <div class="rdr-type-selector">
                        <?php
                        $types = [
                            'yesno'    => ['label' => 'Oui / Non',           'icon' => '✅'],
                            'radio'    => ['label' => 'Choix unique (radio)', 'icon' => '🔘'],
                            'checkbox' => ['label' => 'Choix multiple',       'icon' => '☑️'],
                            'order'    => ['label' => 'Ordre à glisser',      'icon' => '↕️'],
                            'free'     => ['label' => 'Saisie libre (CLI)',   'icon' => '⌨️'],
                            'fill'     => ['label' => 'Phrase à trou',        'icon' => '✏️'],
                        ];
                        foreach ($types as $key => $info): ?>
                        <label class="rdr-type-btn <?php echo $type === $key ? 'active' : ''; ?>">
                            <input type="radio" name="rdr_answer_type" value="<?php echo $key; ?>" <?php checked($type, $key); ?> hidden>
                            <span><?php echo $info['icon']; ?><br><?php echo esc_html($info['label']); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="rdr-field">
                    <label><strong><?php _e('Difficulté de cette question', 'rdr-quiz'); ?></strong></label>
                    <select name="rdr_question_difficulty" style="width:100%">
                        <option value=""><?php _e('— Non définie —', 'rdr-quiz'); ?></option>
                        <option value="debutant"      <?php selected($q_diff, 'debutant'); ?>><?php _e('Débutant', 'rdr-quiz'); ?></option>
                        <option value="intermediaire" <?php selected($q_diff, 'intermediaire'); ?>><?php _e('Intermédiaire', 'rdr-quiz'); ?></option>
                        <option value="avance"        <?php selected($q_diff, 'avance'); ?>><?php _e('Avancé', 'rdr-quiz'); ?></option>
                        <option value="expert"        <?php selected($q_diff, 'expert'); ?>><?php _e('Expert', 'rdr-quiz'); ?></option>
                    </select>
                    <p class="description"><?php _e('Utilisé pour filtrer les questions dans le gestionnaire de quiz.', 'rdr-quiz'); ?></p>
                </div>
            </div>

            <!-- Réponses dynamiques -->
            <div id="rdr-answers-container">
                <?php self::render_answers_ui($type, $answers); ?>
            </div>

            <!-- Explication -->
            <div class="rdr-field rdr-field-full" style="margin-top:20px">
                <label><strong><?php _e('💡 Explication (affichée dans les résultats)', 'rdr-quiz'); ?></strong></label>
                <?php
                wp_editor($explanation, 'rdr_explanation', [
                    'textarea_name' => 'rdr_explanation',
                    'media_buttons' => false,
                    'textarea_rows' => 5,
                    'teeny'         => true,
                ]);
                ?>
            </div>

            <!-- Lien doc -->
            <div class="rdr-field rdr-field-full">
                <label><?php _e('🔗 Lien vers article ou documentation (optionnel)', 'rdr-quiz'); ?></label>
                <input type="url" name="rdr_doc_link" value="<?php echo esc_attr($doc_link); ?>" placeholder="https://" style="width:100%">
            </div>
        </div>

        <script>var rdrAnswerTemplates = <?php echo wp_json_encode(self::get_answer_templates()); ?>;</script>
        <?php
    }

    /* ─── RENDER ANSWERS UI ──────────────────────────────────── */
    public static function render_answers_ui(string $type, array $answers): void {
        match($type) {
            'yesno'    => print(self::render_yesno($answers)),
            'radio',
            'checkbox' => print(self::render_choices($type, $answers)),
            'order'    => print(self::render_order($answers)),
            'free'     => print(self::render_free($answers)),
            'fill'     => print(self::render_fill($answers)),
            default    => null,
        };
    }

    private static function render_yesno(array $answers): string {
        $correct = $answers['correct'] ?? 'yes';
        return '<div class="rdr-yesno-field">'
             . '<p>' . __('Bonne réponse :', 'rdr-quiz') . '</p>'
             . '<label><input type="radio" name="rdr_answers[correct]" value="yes" ' . checked($correct, 'yes', false) . '> ' . __('Oui', 'rdr-quiz') . '</label>'
             . '<label><input type="radio" name="rdr_answers[correct]" value="no"  ' . checked($correct, 'no',  false) . '> ' . __('Non', 'rdr-quiz') . '</label>'
             . '</div>';
    }

    private static function render_choices(string $type, array $answers): string {
        $choices    = $answers['choices'] ?? [['text' => '', 'correct' => false]];
        $input_type = $type === 'radio' ? 'radio' : 'checkbox';
        $html = '<div class="rdr-choices-list" id="rdr-choices-sortable">';
        $html .= '<p>' . __('Propositions (cochez la/les bonne(s) réponse(s)) :', 'rdr-quiz') . '</p>';
        foreach ($choices as $i => $choice) {
            $html .= '<div class="rdr-choice-row">';
            $html .= '<span class="dashicons dashicons-move rdr-handle"></span>';
            $html .= '<input type="' . $input_type . '" name="rdr_answers[correct][]" value="' . $i . '" ' . ($choice['correct'] ? 'checked' : '') . '>';
            $html .= '<input type="text" name="rdr_answers[choices][' . $i . '][text]" value="' . esc_attr($choice['text']) . '" placeholder="' . __('Texte de la proposition…', 'rdr-quiz') . '">';
            $html .= '<button type="button" class="button rdr-remove-choice">×</button>';
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '<button type="button" class="button rdr-add-choice" data-type="' . $type . '">+ ' . __('Ajouter une proposition', 'rdr-quiz') . '</button>';
        return $html;
    }

    private static function render_order(array $answers): string {
        $items = $answers['items'] ?? [['text' => '']];
        $html  = '<div class="rdr-order-field"><p>' . __('Éléments dans le bon ordre (de haut en bas) :', 'rdr-quiz') . '</p>';
        $html .= '<ul id="rdr-order-sortable">';
        foreach ($items as $i => $item) {
            $html .= '<li class="rdr-order-item">';
            $html .= '<span class="dashicons dashicons-menu rdr-handle"></span>';
            $html .= '<input type="text" name="rdr_answers[items][' . $i . '][text]" value="' . esc_attr($item['text']) . '" placeholder="' . __('Élément…', 'rdr-quiz') . '">';
            $html .= '<button type="button" class="button rdr-remove-order">×</button>';
            $html .= '</li>';
        }
        $html .= '</ul><button type="button" class="button rdr-add-order">+ ' . __('Ajouter un élément', 'rdr-quiz') . '</button></div>';
        return $html;
    }

    private static function render_free(array $answers): string {
        $accepted = implode("\n", $answers['accepted'] ?? ['']);
        return '<div class="rdr-free-field">'
             . '<p>' . __('Réponses acceptées (une par ligne, insensible à la casse) :', 'rdr-quiz') . '</p>'
             . '<textarea name="rdr_answers[accepted_raw]" rows="5" style="width:100%;font-family:monospace">' . esc_textarea($accepted) . '</textarea>'
             . '<p class="description">' . __('Ex: docker ps / docker container ls', 'rdr-quiz') . '</p>'
             . '</div>';
    }

    private static function render_fill(array $answers): string {
        $template = $answers['template'] ?? '';
        $blanks   = intval($answers['blanks'] ?? 1);
        $accepted = $answers['accepted'] ?? [['']];

        $html  = '<div class="rdr-fill-field">';
        $html .= '<p>' . __('Rédigez la phrase avec <code>___</code> (3 underscores) à la place de chaque mot manquant :', 'rdr-quiz') . '</p>';
        $html .= '<input type="text" name="rdr_answers[template]" value="' . esc_attr($template) . '"'
               . ' placeholder="' . __('Ex : La commande ___ permet de lister les conteneurs Docker.', 'rdr-quiz') . '"'
               . ' style="width:100%;margin-bottom:12px">';

        $html .= '<p><strong>' . __('Réponses acceptées par trou', 'rdr-quiz') . '</strong></p>';
        $html .= '<p class="description">' . __('Plusieurs variantes acceptées par trou (une par ligne). Insensible à la casse.', 'rdr-quiz') . '</p>';

        // Détecter le nb de trous dans le template
        $trous = max(1, substr_count($template, '___'));
        $html .= '<div id="rdr-fill-blanks">';
        for ($i = 0; $i < $trous; $i++) {
            $vals = implode("
", (array)($accepted[$i] ?? ['']));
            $html .= '<div class="rdr-fill-blank-row">';
            $html .= '<label>' . sprintf(__('Trou %d :', 'rdr-quiz'), $i + 1) . '</label>';
            $html .= '<textarea name="rdr_answers[accepted][' . $i . ']" rows="2" style="width:100%;font-family:monospace">' . esc_textarea($vals) . '</textarea>';
            $html .= '</div>';
        }
        $html .= '</div>';
        $html .= '<p class="description" style="margin-top:6px">'
               . __('💡 Après avoir modifié la phrase, sauvegardez pour actualiser le nombre de trous.', 'rdr-quiz')
               . '</p>';
        $html .= '</div>';
        return $html;
    }

    private static function get_answer_templates(): array {
        return [
            'yesno'    => self::render_yesno([]),
            'radio'    => self::render_choices('radio', []),
            'checkbox' => self::render_choices('checkbox', []),
            'order'    => self::render_order([]),
            'free'     => self::render_free([]),
            'fill'     => self::render_fill([]),
        ];
    }

    /* ─── SAVE QUIZ ──────────────────────────────────────────── */
    public static function save_quiz_meta(int $post_id, \WP_Post $post): void {
        if (
            ! isset($_POST['rdr_quiz_nonce']) ||
            ! wp_verify_nonce($_POST['rdr_quiz_nonce'], 'rdr_quiz_save') ||
            wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) ||
            $post->post_type !== 'rdr_quiz'
        ) return;

        foreach ([
            '_rdr_quiz_q_count'    => sanitize_text_field($_POST['rdr_q_count']    ?? 'all'),
            '_rdr_quiz_q_order'    => sanitize_text_field($_POST['rdr_q_order']    ?? 'random'),
            '_rdr_quiz_difficulty' => sanitize_text_field($_POST['rdr_difficulty'] ?? 'debutant'),
            '_rdr_quiz_duration'   => absint($_POST['rdr_duration']                ?? 10),
            '_rdr_quiz_article'    => esc_url_raw($_POST['rdr_article_link']       ?? ''),
            '_rdr_quiz_hide_tags'       => isset($_POST['rdr_hide_tags'])       ? '1' : '0',
            '_rdr_quiz_hide_from_list'  => isset($_POST['rdr_hide_from_list'])  ? '1' : '0',
            '_rdr_quiz_archive_page_id' => absint($_POST['rdr_archive_page_id'] ?? 0),
        ] as $key => $val) {
            update_post_meta($post_id, $key, $val);
        }

        $questions = isset($_POST['rdr_quiz_questions']) ? array_map('absint', $_POST['rdr_quiz_questions']) : [];
        update_post_meta($post_id, '_rdr_quiz_questions', $questions);
    }

    /* ─── SAVE QUESTION ──────────────────────────────────────── */
    public static function save_question_meta(int $post_id, \WP_Post $post): void {
        if (
            ! isset($_POST['rdr_question_nonce']) ||
            ! wp_verify_nonce($_POST['rdr_question_nonce'], 'rdr_question_save') ||
            wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) ||
            $post->post_type !== 'rdr_question'
        ) return;

        $type = sanitize_key($_POST['rdr_answer_type'] ?? 'radio');
        update_post_meta($post_id, '_rdr_answer_type', $type);
        update_post_meta($post_id, '_rdr_answers',     self::sanitize_answers($type, $_POST['rdr_answers'] ?? []));
        update_post_meta($post_id, '_rdr_explanation', wp_kses_post($_POST['rdr_explanation']       ?? ''));
        update_post_meta($post_id, '_rdr_doc_link',    esc_url_raw($_POST['rdr_doc_link']           ?? ''));

        // Corps de question (éditeur riche)
        update_post_meta($post_id, '_rdr_question_body', wp_kses_post($_POST['rdr_question_body'] ?? ''));

        // Difficulté de la question
        $q_diff = sanitize_key($_POST['rdr_question_difficulty'] ?? '');
        update_post_meta($post_id, '_rdr_question_difficulty', $q_diff);
    }

    private static function sanitize_answers(string $type, array $raw): array {
        return match($type) {
            'yesno'  => ['correct' => in_array($raw['correct'] ?? '', ['yes', 'no']) ? $raw['correct'] : 'yes'],
            'radio', 'checkbox' => (function() use ($raw) {
                $choices  = [];
                $corrects = (array)($raw['correct'] ?? []);
                foreach ($raw['choices'] ?? [] as $i => $c) {
                    $choices[] = ['text' => sanitize_text_field($c['text'] ?? ''), 'correct' => in_array((string)$i, $corrects)];
                }
                return ['choices' => $choices];
            })(),
            'order'  => ['items'    => array_map(fn($i) => ['text' => sanitize_text_field($i['text'] ?? '')], $raw['items'] ?? [])],
            'free'   => ['accepted' => array_values(array_filter(array_map('trim', explode("\n", $raw['accepted_raw'] ?? ''))))],
            default  => [],
        };
    }

    /* ─── HELPERS ────────────────────────────────────────────── */
    public static function get_quiz_meta(int $post_id): array {
        return [
            'q_count'          => get_post_meta($post_id, '_rdr_quiz_q_count',        true) ?: 'all',
            'q_order'          => get_post_meta($post_id, '_rdr_quiz_q_order',        true) ?: 'random',
            'difficulty'       => get_post_meta($post_id, '_rdr_quiz_difficulty',     true) ?: 'debutant',
            'duration'         => get_post_meta($post_id, '_rdr_quiz_duration',       true) ?: 10,
            'article_link'     => get_post_meta($post_id, '_rdr_quiz_article',        true) ?: '',
            'hide_tags'        => get_post_meta($post_id, '_rdr_quiz_hide_tags',      true) ?: '0',
            'hide_from_list'   => get_post_meta($post_id, '_rdr_quiz_hide_from_list', true) ?: '0',
            'archive_page_id'  => (int)(get_post_meta($post_id, '_rdr_quiz_archive_page_id', true) ?: 0),
        ];
    }

    public static function type_label(string $type): string {
        return ['yesno'=>'O/N','radio'=>'QCU','checkbox'=>'QCM','order'=>'TRI','free'=>'CLI','fill'=>'GAP'][$type] ?? '?';
    }
}
