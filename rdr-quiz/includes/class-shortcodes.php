<?php
/**
 * Shortcodes front-end
 * [rdr_quiz id="123"]
 * [rdr_quiz_list]
 */
defined('ABSPATH') || exit;

class RDR_Quiz_Shortcodes {

    public static function init(): void {
        add_shortcode('rdr_quiz',      [self::class, 'render_quiz']);
        add_shortcode('rdr_quiz_list', [self::class, 'render_quiz_list']);
        add_shortcode('rdr_quiz_link', [self::class, 'render_quiz_link']);

        // Bloc Gutenberg
        add_action('init', [self::class, 'register_block']);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets']);
        add_action('wp_footer',          [self::class, 'print_difficulty_labels'], 5);
    }

    /* ─── ASSETS FRONT ──────────────────────────────────────── */
    public static function register_assets(): void {
        wp_register_style(
            'rdr-quiz-front',
            RDR_QUIZ_URL . 'assets/css/quiz.css',
            [],
            RDR_QUIZ_VERSION
        );
        wp_register_script(
            'rdr-quiz-front',
            RDR_QUIZ_URL . 'assets/js/quiz.js',
            [],
            RDR_QUIZ_VERSION,
            true
        );
        wp_register_script(
            'rdr-quiz-sortable',
            RDR_QUIZ_URL . 'assets/js/sortable.min.js',
            [],
            '1.15.2',
            true
        );

        // Assets modal — chargés uniquement si un bouton modal est détecté
        wp_register_style(
            'rdr-quiz-modal',
            RDR_QUIZ_URL . 'assets/css/quiz-modal.css',
            ['rdr-quiz-front'],
            RDR_QUIZ_VERSION
        );
        wp_register_script(
            'rdr-quiz-modal',
            RDR_QUIZ_URL . 'assets/js/quiz-modal.js',
            [],
            RDR_QUIZ_VERSION,
            true
        );
    }

    /** Charger les assets modal (appelé quand un bouton modal est présent) */
    public static function enqueue_modal_assets(): void {
        self::enqueue_assets();
        if (! wp_style_is('rdr-quiz-modal', 'enqueued')) {
            wp_enqueue_style('rdr-quiz-modal');
        }
        if (! wp_script_is('rdr-quiz-modal', 'enqueued')) {
            wp_enqueue_script('rdr-quiz-modal');
            wp_localize_script('rdr-quiz-modal', 'rdrQuizModal', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('rdr_quiz_nonce'),
            ]);
        }
    }

    public static function enqueue_assets_public(): void { self::enqueue_assets(); }

    private static function enqueue_assets(): void {
        wp_enqueue_style('rdr-quiz-front');
        wp_enqueue_script('rdr-quiz-sortable');
        wp_enqueue_script('rdr-quiz-front');
        wp_localize_script('rdr-quiz-front', 'rdrQuiz', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('rdr_quiz_nonce'),
            'i18n'     => self::get_i18n(),
        ]);
    }

    /* ─── RENDU DIRECT (sans enqueue — assets déjà chargés) ─── */
    public static function render_quiz_direct(int $id): string {
        return self::render_quiz(['id' => $id]);
    }

    /* ─── SHORTCODE QUIZ ────────────────────────────────────── */
    public static function render_quiz(array $atts): string {
        $atts = shortcode_atts(['id' => 0], $atts);
        $id   = absint($atts['id']);

        if (! $id || get_post_type($id) !== 'rdr_quiz') {
            return '<p class="rdr-quiz-error">' . esc_html__('Quiz introuvable.', 'rdr-quiz') . '</p>';
        }

        self::enqueue_assets();

        $meta       = RDR_Quiz_AdminUI::get_quiz_meta($id);
        $stats      = RDR_Quiz_Stats::get($id);
        $thumbnail  = get_the_post_thumbnail_url($id, 'large');
        $excerpt    = get_the_excerpt($id);
        $diff_label = self::diff_label($meta['difficulty']);
        $diff_color = self::diff_color($meta['difficulty']);
        $q_ids      = get_post_meta($id, '_rdr_quiz_questions', true) ?: [];
        $q_total    = count($q_ids);
        $q_display  = $meta['q_count'] !== 'all' ? min(intval($meta['q_count']), $q_total) : $q_total;

        ob_start();
        ?>
        <div class="rdr-quiz-wrapper" data-quiz-id="<?php echo $id; ?>">

            <!-- ── INTRO ── -->
            <div class="rdr-quiz-intro" id="rdr-quiz-intro-<?php echo $id; ?>">
                <?php if ($thumbnail): ?>
                <div class="rdr-quiz-hero" style="background-image:url('<?php echo esc_url($thumbnail); ?>')"></div>
                <?php endif; ?>

                <div class="rdr-quiz-intro-body">
                    <div class="rdr-quiz-badges">
                        <span class="rdr-badge rdr-badge-diff" style="--diff-color:<?php echo $diff_color; ?>"><?php echo esc_html($diff_label); ?></span>
                        <span class="rdr-badge">⏱ <?php echo $meta['duration']; ?> min</span>
                        <span class="rdr-badge">❓ <?php echo $q_display; ?> <?php _e('questions', 'rdr-quiz'); ?></span>
                        <?php if ($stats['attempts'] > 0): ?>
                        <span class="rdr-badge rdr-badge-stat">📊 <?php printf(__('%d tentatives — %.1f%% réussite', 'rdr-quiz'), $stats['attempts'], $stats['avg']); ?></span>
                        <?php endif; ?>
                    </div>

                    <h2 class="rdr-quiz-title"><?php echo esc_html(get_the_title($id)); ?></h2>
                    <?php if ($excerpt): ?>
                    <p class="rdr-quiz-excerpt"><?php echo esc_html($excerpt); ?></p>
                    <?php endif; ?>

                    <?php if ($meta['article_link']): ?>
                    <a href="<?php echo esc_url($meta['article_link']); ?>" class="rdr-quiz-article-link" target="_blank" rel="noopener">
                        📖 <?php _e("Lire l\'article associé", 'rdr-quiz'); ?>
                    </a>
                    <?php endif; ?>

                    <button class="rdr-btn rdr-btn-start" data-quiz-id="<?php echo $id; ?>">
                        🚀 <?php _e('Démarrer le Quiz', 'rdr-quiz'); ?>
                    </button>
                </div>
            </div>

            <!-- ── QUIZ PLAYER (injecté en JS) ── -->
            <div class="rdr-quiz-player" id="rdr-quiz-player-<?php echo $id; ?>" hidden>
                <div class="rdr-quiz-progress-bar">
                    <div class="rdr-progress-fill" id="rdr-progress-<?php echo $id; ?>"></div>
                </div>
                <div class="rdr-quiz-progress-text" id="rdr-progress-text-<?php echo $id; ?>"></div>
                <div class="rdr-quiz-question-area" id="rdr-question-area-<?php echo $id; ?>"></div>
            </div>

            <!-- ── RÉSULTATS (injecté en JS) ── -->
            <div class="rdr-quiz-results" id="rdr-quiz-results-<?php echo $id; ?>" hidden></div>

            <!-- ── LOADER ── -->
            <div class="rdr-quiz-loader" id="rdr-quiz-loader-<?php echo $id; ?>" hidden>
                <div class="rdr-spinner"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ─── SHORTCODE LISTE QUIZ ──────────────────────────────── */
    public static function render_quiz_list(array $atts): string {
        $atts      = shortcode_atts(['per_page' => 12, 'cat' => '', 'diff' => '', 'modal' => 'no'], $atts, 'rdr_quiz_list');
        $use_modal = ($atts['modal'] === 'yes');

        if ($use_modal) {
            self::enqueue_modal_assets();
        } else {
            self::enqueue_assets();
        }

        // Catégories pour filtre
        $cats = get_terms(['taxonomy' => 'rdr_quiz_cat', 'hide_empty' => true]);

        // Tags pour filtre
        $tags = get_terms(['taxonomy' => 'rdr_question_tag', 'hide_empty' => true, 'orderby' => 'name']);

        ob_start();
        ?>
        <div class="rdr-quiz-archive" id="rdr-quiz-archive" data-modal="<?php echo $use_modal ? '1' : '0'; ?>">

            <div class="rdr-quiz-filters">
                <select id="rdr-filter-cat" class="rdr-filter-select">
                    <option value=""><?php _e('Toutes les catégories', 'rdr-quiz'); ?></option>
                    <?php foreach ($cats as $cat): ?>
                    <option value="<?php echo esc_attr($cat->slug); ?>"><?php echo esc_html($cat->name); ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="rdr-filter-diff" class="rdr-filter-select">
                    <option value=""><?php _e('Toutes les difficultés', 'rdr-quiz'); ?></option>
                    <option value="debutant"><?php _e('Débutant', 'rdr-quiz'); ?></option>
                    <option value="intermediaire"><?php _e('Intermédiaire', 'rdr-quiz'); ?></option>
                    <option value="avance"><?php _e('Avancé', 'rdr-quiz'); ?></option>
                    <option value="expert"><?php _e('Expert', 'rdr-quiz'); ?></option>
                </select>

                <?php if (!empty($tags) && !is_wp_error($tags)): ?>
                <select id="rdr-filter-tag" class="rdr-filter-select">
                    <option value=""><?php _e('Tous les sujets', 'rdr-quiz'); ?></option>
                    <?php foreach ($tags as $tag): ?>
                    <option value="<?php echo esc_attr($tag->slug); ?>"><?php echo esc_html($tag->name); ?> (<?php echo $tag->count; ?>)</option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>

            <div class="rdr-quiz-grid" id="rdr-quiz-grid">
                <?php /* Skeletons affichés pendant le premier chargement AJAX */ ?>
                <?php for ($i = 0; $i < 6; $i++): ?>
                <div class="rdr-quiz-card rdr-skeleton-card" aria-hidden="true">
                    <div class="rdr-quiz-card-body">
                        <div class="rdr-skeleton rdr-skeleton-badges"></div>
                        <div class="rdr-skeleton rdr-skeleton-title"></div>
                        <div class="rdr-skeleton rdr-skeleton-text"></div>
                        <div class="rdr-skeleton rdr-skeleton-text rdr-skeleton-text-short"></div>
                        <div class="rdr-skeleton rdr-skeleton-btn"></div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <div class="rdr-quiz-pagination" id="rdr-quiz-pagination"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ─── DIFFICULTY LABELS ─────────────────────────────────── */
    public static function diff_label(string $d): string {
        return [
            'debutant'      => __('Débutant',      'rdr-quiz'),
            'intermediaire' => __('Intermédiaire', 'rdr-quiz'),
            'avance'        => __('Avancé',        'rdr-quiz'),
            'expert'        => __('Expert',        'rdr-quiz'),
        ][$d] ?? ucfirst($d);
    }

    public static function diff_color(string $d): string {
        return [
            'debutant'      => '#22c55e',
            'intermediaire' => '#f59e0b',
            'avance'        => '#f97316',
            'expert'        => '#ef4444',
        ][$d] ?? '#6b7280';
    }

    /* ─── i18n pour JS ──────────────────────────────────────── */
    private static function get_i18n(): array {
        return [
            'question'       => __('Question', 'rdr-quiz'),
            'of'             => __('sur', 'rdr-quiz'),
            'validate'       => __('Valider', 'rdr-quiz'),
            'next'           => __('Question suivante →', 'rdr-quiz'),
            'results'        => __('Voir mes résultats', 'rdr-quiz'),
            'correct'        => __('✅ Bonne réponse !', 'rdr-quiz'),
            'wrong'          => __('❌ Mauvaise réponse', 'rdr-quiz'),
            'explanation'    => __('Explication', 'rdr-quiz'),
            'learn_more'     => __('En savoir plus →', 'rdr-quiz'),
            'your_score'     => __('Votre score', 'rdr-quiz'),
            'retry'          => __('🔄 Recommencer', 'rdr-quiz'),
            'share'          => __('Partager', 'rdr-quiz'),
            'good_answers'   => __('bonnes réponses', 'rdr-quiz'),
            'review'         => __('📝 Revoir les réponses', 'rdr-quiz'),
            'correct_was'    => __('La bonne réponse était :', 'rdr-quiz'),
            'type_answer'    => __('Tapez votre commande...', 'rdr-quiz'),
            'drag_order'     => __('Glissez les éléments dans le bon ordre', 'rdr-quiz'),
            'loading'        => __('Chargement…', 'rdr-quiz'),
            'error_load'     => __('Erreur lors du chargement du quiz.', 'rdr-quiz'),
            'debutant'       => __('Débutant', 'rdr-quiz'),
            'intermediaire'  => __('Intermédiaire', 'rdr-quiz'),
            'avance'         => __('Avancé', 'rdr-quiz'),
            'expert'         => __('Expert', 'rdr-quiz'),
            'attempts'       => __('tentatives', 'rdr-quiz'),
            'avg_score'      => __('score moyen', 'rdr-quiz'),
            'questions'      => __('questions', 'rdr-quiz'),
            'start'          => __('Démarrer →', 'rdr-quiz'),
            'no_quiz'        => __('Aucun quiz disponible pour le moment.', 'rdr-quiz'),
        ];
    }

    public static function print_difficulty_labels(): void {
        // Colors map for JS
        echo '<script>window.rdrDiffColors=' . wp_json_encode([
            'debutant'      => '#22c55e',
            'intermediaire' => '#f59e0b',
            'avance'        => '#f97316',
            'expert'        => '#ef4444',
        ]) . ';</script>';
    }
    /* ─── SHORTCODE / BLOC : lien CTA vers un quiz ─────────── */
    /* Usage :
       [rdr_quiz_link id="42"]
       [rdr_quiz_link id="42" label="Tester mes connaissances" style="button"]
       [rdr_quiz_link id="42" style="card"]
       [rdr_quiz_link id="42" style="inline"]
    */
    public static function render_quiz_link(array $atts): string {
        $atts = shortcode_atts([
            'id'    => 0,
            'label' => '',
            'style' => 'button',   // button | card | inline
            'modal' => 'no',       // yes | no — ouvrir dans une modale
        ], $atts, 'rdr_quiz_link');

        $id = absint($atts['id']);
        if (! $id || get_post_type($id) !== 'rdr_quiz' || get_post_status($id) !== 'publish') {
            return '';
        }

        $use_modal = ($atts['modal'] === 'yes');
        if ($use_modal) {
            self::enqueue_modal_assets();
        } else {
            self::enqueue_assets();
        }

        $url   = get_permalink($id);
        $title = get_the_title($id);
        $meta  = RDR_Quiz_AdminUI::get_quiz_meta($id);
        $label = $atts['label'] ?: sprintf(__('Tester ses connaissances : %s', 'rdr-quiz'), $title);

        $diff_labels = [
            'debutant'      => __('Débutant',      'rdr-quiz'),
            'intermediaire' => __('Intermédiaire', 'rdr-quiz'),
            'avance'        => __('Avancé',        'rdr-quiz'),
            'expert'        => __('Expert',        'rdr-quiz'),
        ];
        $diff_colors = [
            'debutant'      => '#22c55e',
            'intermediaire' => '#f59e0b',
            'avance'        => '#f97316',
            'expert'        => '#ef4444',
        ];

        $q_ids  = get_post_meta($id, '_rdr_quiz_questions', true) ?: [];
        $q_count = count($q_ids);
        $diff_label = $diff_labels[$meta['difficulty']] ?? $meta['difficulty'];
        $diff_color = $diff_colors[$meta['difficulty']] ?? '#6b7280';

        ob_start();

        switch ($atts['style']) {

            /* ── BUTTON ─ simple CTA ─────────────────────────── */
            case 'button':
            default:
                $meta_html = '<span class="rdr-quiz-cta-meta">'
                    . '<span style="color:' . esc_attr($diff_color) . '">' . esc_html($diff_label) . '</span>'
                    . ' &middot; ' . (int)$q_count . ' ' . esc_html__('questions', 'rdr-quiz')
                    . ' &middot; ' . (int)$meta['duration'] . ' min'
                    . '</span>';

                if ($use_modal) {
                    echo '<div class="rdr-quiz-cta-btn-wrap">'
                        . '<button type="button" class="rdr-quiz-modal-btn" data-quiz-id="' . esc_attr($id) . '">'
                        . '🧠 ' . esc_html($label)
                        . $meta_html
                        . '</button>'
                        . '</div>';
                } else {
                    echo '<div class="rdr-quiz-cta-btn-wrap">'
                        . '<a href="' . esc_url($url) . '" class="rdr-quiz-cta-btn">'
                        . '🧠 ' . esc_html($label)
                        . $meta_html
                        . '</a>'
                        . '</div>';
                }
                break;

            /* ── CARD ─ bloc riche ───────────────────────────── */
            case 'card':
                $excerpt   = get_the_excerpt($id);
                $thumbnail = get_the_post_thumbnail_url($id, 'medium');
                $stats     = RDR_Quiz_Stats::get($id);
                ?>
                <div class="rdr-quiz-cta-card">
                    <?php if ($thumbnail): ?>
                    <div class="rdr-quiz-cta-card-img" style="background-image:url('<?php echo esc_url($thumbnail); ?>')"></div>
                    <?php endif; ?>
                    <div class="rdr-quiz-cta-card-body">
                        <div class="rdr-quiz-cta-card-badges">
                            <span class="rdr-badge rdr-badge-diff" style="--diff-color:<?php echo $diff_color; ?>"><?php echo esc_html($diff_label); ?></span>
                            <span class="rdr-badge">❓ <?php echo $q_count; ?> <?php _e('questions', 'rdr-quiz'); ?></span>
                            <span class="rdr-badge">⏱ <?php echo $meta['duration']; ?> min</span>
                            <?php if ($stats['attempts'] > 0): ?>
                            <span class="rdr-badge">📊 <?php printf(__('%d tentatives · %.0f%%', 'rdr-quiz'), $stats['attempts'], $stats['avg']); ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 class="rdr-quiz-cta-card-title"><?php echo esc_html($title); ?></h3>
                        <?php if ($excerpt): ?>
                        <p class="rdr-quiz-cta-card-excerpt"><?php echo esc_html($excerpt); ?></p>
                        <?php endif; ?>
                        <a href="<?php echo esc_url($url); ?>" class="rdr-btn rdr-btn-start-cta">
                            🚀 <?php echo esc_html($label ?: __('Démarrer le Quiz', 'rdr-quiz')); ?>
                        </a>
                    </div>
                </div>
                <?php
                break;

            /* ── INLINE ─ lien texte discret ────────────────── */
            case 'inline':
                ?>
                <span class="rdr-quiz-cta-inline">
                    🧠 <a href="<?php echo esc_url($url); ?>"><?php echo esc_html($label); ?></a>
                    <span class="rdr-quiz-cta-inline-meta">(<?php echo esc_html($diff_label); ?> · <?php echo $q_count; ?> <?php _e('questions', 'rdr-quiz'); ?>)</span>
                </span>
                <?php
                break;
        }

        return ob_get_clean();
    }

    /* ─── BLOC GUTENBERG ─────────────────────────────────────── */
    public static function register_block(): void {
        if (! function_exists('register_block_type')) return;

        // Récupérer tous les quiz publiés pour l'inspecteur
        $quizzes = get_posts([
            'post_type'      => 'rdr_quiz',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);
        $quiz_options = array_map(fn($q) => ['value' => $q->ID, 'label' => $q->post_title], $quizzes);

        // Script du bloc (inline — pas besoin d'un fichier séparé)
        $block_js = <<<JS
(function(blocks, element, blockEditor, components) {
    var el         = element.createElement;
    var InspectorControls = blockEditor.InspectorControls;
    var PanelBody  = components.PanelBody;
    var SelectControl = components.SelectControl;
    var quizOptions = [{ value: '', label: '— Choisir un quiz —' }].concat(
        JSON.parse(document.getElementById('rdr-quiz-options-json')?.textContent || '[]')
    );

    blocks.registerBlockType('rdr-quiz/quiz-link', {
        title: '🧠 Lien Quiz RDR-IT',
        icon: 'welcome-learn-more',
        category: 'embed',
        attributes: {
            quizId: { type: 'string', default: '' },
            style:  { type: 'string', default: 'button' },
            label:  { type: 'string', default: '' },
        },
        edit: function(props) {
            var attrs = props.attributes;
            return [
                el(InspectorControls, { key: 'controls' },
                    el(PanelBody, { title: 'Paramètres du quiz', initialOpen: true },
                        el(SelectControl, {
                            label: 'Quiz',
                            value: attrs.quizId,
                            options: quizOptions,
                            onChange: function(val) { props.setAttributes({ quizId: val }); }
                        }),
                        el(SelectControl, {
                            label: 'Style',
                            value: attrs.style,
                            options: [
                                { value: 'button', label: 'Bouton CTA' },
                                { value: 'card',   label: 'Carte riche' },
                                { value: 'inline', label: 'Lien inline' },
                            ],
                            onChange: function(val) { props.setAttributes({ style: val }); }
                        }),
                        el(components.TextControl, {
                            label: 'Label personnalisé (optionnel)',
                            value: attrs.label,
                            onChange: function(val) { props.setAttributes({ label: val }); }
                        })
                    )
                ),
                el('div', { className: 'rdr-quiz-block-preview' },
                    attrs.quizId
                        ? el('div', { className: 'rdr-quiz-block-placeholder' },
                            el('span', {}, '🧠 '),
                            el('strong', {}, attrs.label || 'Quiz sélectionné : #' + attrs.quizId),
                            el('em', { style: { marginLeft: '8px', color: '#666' } }, '[style: ' + attrs.style + ']')
                          )
                        : el('div', { className: 'rdr-quiz-block-placeholder rdr-quiz-block-empty' },
                            '🧠 Choisissez un quiz dans le panneau de droite'
                          )
                )
            ];
        },
        save: function() { return null; } // rendu serveur via render_callback
    });
}(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components));
JS;

        wp_register_script(
            'rdr-quiz-block',
            '',   // inline — on va wp_add_inline_script
            ['wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components'],
            RDR_QUIZ_VERSION,
            false
        );
        // Injecter les données quiz + le JS du bloc
        wp_add_inline_script('rdr-quiz-block',
            'document.addEventListener("DOMContentLoaded",function(){' .
            'var s=document.createElement("script");' .
            'document.head.appendChild(s);});'
        );

        // Approche plus simple : enregistrer via register_block_type avec render_callback
        register_block_type('rdr-quiz/quiz-link', [
            'editor_script'   => 'rdr-quiz-block',
            'attributes'      => [
                'quizId' => ['type' => 'string', 'default' => ''],
                'style'  => ['type' => 'string', 'default' => 'button'],
                'label'  => ['type' => 'string', 'default' => ''],
            ],
            'render_callback' => [self::class, 'render_block'],
        ]);

        // Passer les quiz au JS via un élément DOM caché dans l'éditeur
        add_action('admin_footer', function() use ($quiz_options) {
            if (! get_current_screen() || get_current_screen()->base !== 'post') return;
            echo '<script id="rdr-quiz-options-json" type="application/json">'
               . wp_json_encode($quiz_options)
               . '</script>';
            // Enqueue le script du bloc inline maintenant qu'on a les données
            echo '<script>' . self::get_block_script($quiz_options) . '</script>';
        });
    }

    private static function get_block_script(array $quiz_options): string {
        // Encodage JSON sécurisé des options quiz
        $options_json = wp_json_encode($quiz_options, JSON_HEX_APOS | JSON_HEX_QUOT);

        // Labels JS sans apostrophes problématiques (utiliser guillemets doubles partout)
        $lbl_quiz     = wp_json_encode(__('Quiz',                'rdr-quiz'));
        $lbl_style    = wp_json_encode(__("Style d\'affichage",  'rdr-quiz'));
        $lbl_label    = wp_json_encode(__('Label (optionnel)',   'rdr-quiz'));
        $lbl_btn      = wp_json_encode(__('Bouton CTA',         'rdr-quiz'));
        $lbl_card     = wp_json_encode(__('Carte riche',        'rdr-quiz'));
        $lbl_inline   = wp_json_encode(__('Lien inline',        'rdr-quiz'));
        $lbl_choose   = wp_json_encode(__('Choisir un quiz',    'rdr-quiz'));
        $lbl_panel    = wp_json_encode(__('Selectionnez un quiz dans le panneau', 'rdr-quiz'));

        return <<<JSEOF
(function() {
    if (!window.wp || !window.wp.blocks) return;
    var el   = window.wp.element.createElement;
    var IP   = window.wp.blockEditor.InspectorControls;
    var PB   = window.wp.components.PanelBody;
    var Sel  = window.wp.components.SelectControl;
    var Txt  = window.wp.components.TextControl;
    var opts = [{value:"", label:{$lbl_choose}}].concat({$options_json});

    if (window.wp.blocks.getBlockType("rdr-quiz/quiz-link")) return;

    window.wp.blocks.registerBlockType("rdr-quiz/quiz-link", {
        title: "\u{1F9E0} Lien Quiz",
        icon:  "welcome-learn-more",
        category: "embed",
        attributes: {
            quizId: {type:"string", default:""},
            style:  {type:"string", default:"button"},
            label:  {type:"string", default:""}
        },
        edit: function(props) {
            var a = props.attributes;
            return [
                el(IP, {key:"ctrl"},
                    el(PB, {title:{$lbl_quiz}, initialOpen:true},
                        el(Sel, {
                            label: {$lbl_quiz},
                            value: a.quizId,
                            options: opts,
                            onChange: function(v){ props.setAttributes({quizId:v}); }
                        }),
                        el(Sel, {
                            label: {$lbl_style},
                            value: a.style,
                            options: [
                                {value:"button", label:{$lbl_btn}},
                                {value:"card",   label:{$lbl_card}},
                                {value:"inline", label:{$lbl_inline}}
                            ],
                            onChange: function(v){ props.setAttributes({style:v}); }
                        }),
                        el(Txt, {
                            label: {$lbl_label},
                            value: a.label,
                            onChange: function(v){ props.setAttributes({label:v}); }
                        })
                    )
                ),
                el("div", {className:"rdr-quiz-block-preview components-placeholder"},
                    a.quizId
                        ? el("div", {className:"rdr-quiz-block-inner"},
                              el("span", {style:{fontSize:"1.5rem"}}, "\u{1F9E0} "),
                              el("strong", {}, a.label || "Quiz #" + a.quizId),
                              el("em", {style:{marginLeft:"8px", opacity:0.6}}, " [" + a.style + "]")
                          )
                        : el("div", {className:"rdr-quiz-block-inner rdr-quiz-block-empty"},
                              {$lbl_panel}
                          )
                )
            ];
        },
        save: function(){ return null; }
    });
})();
JSEOF;
    }

    public static function render_block(array $attrs): string {
        return self::render_quiz_link([
            'id'    => $attrs['quizId'] ?? 0,
            'label' => $attrs['label']  ?? '',
            'style' => $attrs['style']  ?? 'button',
        ]);
    }

}