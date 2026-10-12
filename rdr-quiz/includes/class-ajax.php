<?php
/**
 * Handlers AJAX — chargement quiz & soumission réponses
 * Compatible LiteSpeed Cache : tout est async, pas de cache sur les endpoints
 */
defined('ABSPATH') || exit;

class RDR_Quiz_Ajax {

    public static function init(): void {
        // Chargement d'un quiz (session)
        add_action('wp_ajax_rdr_load_quiz',        [self::class, 'load_quiz']);
        add_action('wp_ajax_nopriv_rdr_load_quiz', [self::class, 'load_quiz']);

        // Vérification d'une réponse (question par question)
        add_action('wp_ajax_rdr_check_answer',        [self::class, 'check_answer']);
        add_action('wp_ajax_nopriv_rdr_check_answer', [self::class, 'check_answer']);

        // Résultats finaux + stats
        add_action('wp_ajax_rdr_submit_quiz',        [self::class, 'submit_quiz']);
        add_action('wp_ajax_nopriv_rdr_submit_quiz', [self::class, 'submit_quiz']);

        // Liste quiz pour la page archive async
        add_action('wp_ajax_rdr_quiz_list',        [self::class, 'quiz_list']);
        add_action('wp_ajax_nopriv_rdr_quiz_list', [self::class, 'quiz_list']);

        // Refresh nonce (appelé si le JS détecte un nonce invalide)
        add_action('wp_ajax_rdr_refresh_nonce',        [self::class, 'refresh_nonce']);
        add_action('wp_ajax_nopriv_rdr_refresh_nonce', [self::class, 'refresh_nonce']);

        // Rendu HTML quiz pour modale
        add_action('wp_ajax_rdr_quiz_html',        [self::class, 'quiz_html']);
        add_action('wp_ajax_nopriv_rdr_quiz_html', [self::class, 'quiz_html']);
    }

    /* ──────────────────────────────────────────────────────────
       LOAD QUIZ — Renvoie les questions pour la session
    ────────────────────────────────────────────────────────── */
    public static function load_quiz(): void {
        check_ajax_referer('rdr_quiz_nonce', 'nonce');

        $quiz_id = absint($_POST['quiz_id'] ?? 0);
        if (! $quiz_id || get_post_type($quiz_id) !== 'rdr_quiz') {
            wp_send_json_error(['message' => __('Quiz introuvable', 'rdr-quiz')]);
        }

        $meta       = RDR_Quiz_AdminUI::get_quiz_meta($quiz_id);
        $q_ids      = get_post_meta($quiz_id, '_rdr_quiz_questions', true) ?: [];
        $q_count    = $meta['q_count'];
        $q_order    = $meta['q_order'];

        if (empty($q_ids)) {
            wp_send_json_error(['message' => __('Ce quiz ne contient aucune question.', 'rdr-quiz')]);
        }

        // Ordre
        if ($q_order === 'random') {
            shuffle($q_ids);
        }

        // Limite
        if ($q_count !== 'all' && intval($q_count) > 0) {
            $q_ids = array_slice($q_ids, 0, intval($q_count));
        }

        // Build questions (sans les bonnes réponses côté client)
        $questions = [];
        foreach ($q_ids as $qid) {
            $q = get_post($qid);
            if (! $q || $q->post_status !== 'publish') continue;

            $type    = get_post_meta($qid, '_rdr_answer_type', true) ?: 'radio';
            $answers = get_post_meta($qid, '_rdr_answers',     true) ?: [];

            $q_data = [
                'id'      => $qid,
                'title'   => wp_strip_all_tags($q->post_title),
                'content' => apply_filters('the_content', $q->post_content),
                'type'    => $type,
            ];

            // Propositions (sans corrects)
            switch ($type) {
                case 'yesno':
                    $q_data['choices'] = [
                        ['id' => 'yes', 'text' => __('Oui', 'rdr-quiz')],
                        ['id' => 'no',  'text' => __('Non', 'rdr-quiz')],
                    ];
                    break;

                case 'radio':
                case 'checkbox':
                    $choices = $answers['choices'] ?? [];
                    // Mélanger l'ordre des choix
                    $indexed = array_map(fn($i, $c) => ['id' => $i, 'text' => $c['text']], array_keys($choices), $choices);
                    shuffle($indexed);
                    $q_data['choices'] = $indexed;
                    break;

                case 'order':
                    $items = $answers['items'] ?? [];
                    $shuffled = $items;
                    shuffle($shuffled);
                    $q_data['items']         = $shuffled;
                    $q_data['correct_order'] = array_column($items, 'text'); // on envoie l'ordre correct pour vérif JS
                    // Non, on garde la vérification côté serveur via check_answer
                    $q_data['items']         = array_column($shuffled, 'text');
                    break;

                case 'free':
                    // Rien de supplémentaire
                    break;
            }

            $questions[] = $q_data;
        }

        wp_send_json_success([
            'quiz_id'    => $quiz_id,
            'title'      => get_the_title($quiz_id),
            'questions'  => $questions,
            'total'      => count($questions),
            'difficulty' => $meta['difficulty'],
            'duration'   => $meta['duration'],
        ]);
    }

    /* ──────────────────────────────────────────────────────────
       CHECK ANSWER — Vérifie UNE réponse (appelée après chaque question)
    ────────────────────────────────────────────────────────── */
    public static function check_answer(): void {
        // Pas de nonce strict : vérification réponse publique + stats anonymes
        // LiteSpeed peut mettre en cache les pages → nonce expire → stats perdues
        // On vérifie juste que c'est un vrai POST avec question_id valide
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(['message' => 'Méthode invalide']); return;
        }

        $question_id = absint($_POST['question_id'] ?? 0);
        // answer[] pour checkbox/order, answer pour scalaire
        $user_answer = $_POST['answer'] ?? null;

        if (! $question_id) {
            wp_send_json_error(['message' => 'Question invalide']);
        }

        $type        = get_post_meta($question_id, '_rdr_answer_type', true) ?: 'radio';
        $answers     = get_post_meta($question_id, '_rdr_answers',     true) ?: [];
        $explanation = get_post_meta($question_id, '_rdr_explanation', true) ?: '';
        $doc_link    = get_post_meta($question_id, '_rdr_doc_link',    true) ?: '';

        $is_correct   = false;
        $correct_data = [];

        switch ($type) {
            case 'yesno':
                $correct      = $answers['correct'] ?? 'yes';
                $is_correct   = ($user_answer === $correct);
                $correct_data = $correct;
                break;

            case 'radio':
                $choices = $answers['choices'] ?? [];
                // user_answer = choice id (index)
                $user_idx   = intval($user_answer);
                $correct_idx = null;
                foreach ($choices as $i => $c) {
                    if ($c['correct']) { $correct_idx = $i; break; }
                }
                $is_correct   = ($user_idx === $correct_idx);
                $correct_data = $correct_idx;
                break;

            case 'checkbox':
                $choices      = $answers['choices'] ?? [];
                $user_ids     = is_array($user_answer) ? array_map('intval', $user_answer) : [];
                $correct_ids  = [];
                foreach ($choices as $i => $c) {
                    if ($c['correct']) $correct_ids[] = $i;
                }
                sort($user_ids); sort($correct_ids);
                $is_correct   = ($user_ids === $correct_ids);
                $correct_data = $correct_ids;
                break;

            case 'order':
                $items      = $answers['items'] ?? [];
                $correct_order = array_column($items, 'text');
                $user_order    = is_array($user_answer) ? array_map('trim', $user_answer) : [];
                $is_correct    = ($user_order === $correct_order);
                $correct_data  = $correct_order;
                break;

            case 'free':
                $accepted  = array_map('strtolower', array_map('trim', $answers['accepted'] ?? []));
                $user_str  = strtolower(trim((string)$user_answer));
                $is_correct = in_array($user_str, $accepted);
                $correct_data = $answers['accepted'] ?? [];
                break;

            case 'fill':
                // user_answer est un tableau indexé : [trou0 => "réponse", trou1 => "réponse"]
                $user_fills   = is_array($user_answer) ? array_map(fn($v) => strtolower(trim((string)$v)), $user_answer) : [];
                $accepted_all = $answers['accepted'] ?? [];
                $nb_trous     = intval($answers['blanks'] ?? count($accepted_all));
                $all_correct  = true;
                $correct_data = [];
                for ($i = 0; $i < $nb_trous; $i++) {
                    $variants  = array_map('strtolower', array_map('trim', (array)($accepted_all[$i] ?? [])));
                    $user_val  = $user_fills[$i] ?? '';
                    $ok        = !empty($variants) && in_array($user_val, $variants);
                    if (!$ok) $all_correct = false;
                    $correct_data[] = ['accepted' => $accepted_all[$i] ?? [], 'user' => $user_fills[$i] ?? '', 'correct' => $ok];
                }
                $is_correct = $all_correct;
                break;
        }

        // Enregistrer la stat par question (RGPD — pas de données perso)
        RDR_Quiz_Stats::record_question($question_id, $is_correct);

        wp_send_json_success([
            'correct'      => $is_correct,
            'correct_data' => $correct_data,
            'explanation'  => wp_kses_post($explanation),
            'doc_link'     => esc_url($doc_link),
            'doc_label'    => __('En savoir plus →', 'rdr-quiz'),
        ]);
    }

    /* ──────────────────────────────────────────────────────────
       SUBMIT QUIZ — Score final + enregistrement stats
    ────────────────────────────────────────────────────────── */
    public static function submit_quiz(): void {
        // Pas de nonce strict : enregistrement stats anonymes (RGPD — pas de données perso)
        // Incompatible avec LiteSpeed Cache qui cache le nonce intégré dans la page
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(['message' => 'Méthode invalide']); return;
        }

        $quiz_id = absint($_POST['quiz_id'] ?? 0);
        $score   = absint($_POST['score']   ?? 0);
        $total   = absint($_POST['total']   ?? 1);

        // Validation : le quiz doit exister et être publié
        if (
            $quiz_id && $total > 0 &&
            get_post_type($quiz_id) === 'rdr_quiz' &&
            get_post_status($quiz_id) === 'publish'
        ) {
            $pct = min(100, max(0, round(($score / $total) * 100)));
            RDR_Quiz_Stats::record($quiz_id, $pct);
        }

        wp_send_json_success(['recorded' => true]);
    }

    /* ──────────────────────────────────────────────────────────
       QUIZ LIST — Page archive async
    ────────────────────────────────────────────────────────── */
    public static function quiz_list(): void {
        // Pas de nonce nécessaire pour la liste publique
        $cat   = sanitize_text_field($_POST['cat']    ?? '');
        $diff  = sanitize_text_field($_POST['diff']   ?? '');
        $tag   = sanitize_text_field($_POST['tag']    ?? '');
        $paged = max(1, absint($_POST['paged']         ?? 1));

        $args = [
            'post_type'      => 'rdr_quiz',
            'post_status'    => 'publish',
            'posts_per_page' => 12,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
            // Exclure les quiz masqués de la liste publique
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'relation' => 'OR',
                    ['key' => '_rdr_quiz_hide_from_list', 'compare' => 'NOT EXISTS'],
                    ['key' => '_rdr_quiz_hide_from_list', 'value'   => '0'],
                ],
            ],
        ];

        $tax_query = [];
        if ($cat) {
            $tax_query[] = ['taxonomy' => 'rdr_quiz_cat', 'field' => 'slug', 'terms' => $cat];
        }
        if ($diff) {
            $args['meta_query'][] = ['key' => '_rdr_quiz_difficulty', 'value' => $diff];
        }

        // Filtre par tag : cherche les quiz contenant des questions taggées
        // Fix sérialisation WP : _rdr_quiz_questions est a:N:{i:0;i:ID;...} donc LIKE sur ;i:ID;
        if ($tag) {
            $tagged_q_ids = get_posts([
                'post_type'      => 'rdr_question',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'tax_query'      => [['taxonomy' => 'rdr_question_tag', 'field' => 'slug', 'terms' => $tag]],
            ]);
            if (!empty($tagged_q_ids)) {
                global $wpdb;
                $quiz_ids = [];
                foreach ($tagged_q_ids as $qid) {
                    // WP stocke les IDs comme entiers : a:2:{i:0;i:123;i:1;i:456;}
                    // On cherche soit ";i:QID;" (milieu) soit "{i:0;i:QID;" (début)
                    $pattern = '%' . $wpdb->esc_like(';i:' . intval($qid) . ';') . '%';
                    $rows = $wpdb->get_col($wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta}
                         WHERE meta_key = '_rdr_quiz_questions'
                         AND meta_value LIKE %s",
                        $pattern
                    ));
                    $quiz_ids = array_merge($quiz_ids, $rows);
                }
                $quiz_ids = array_unique(array_map('intval', $quiz_ids));
                if (empty($quiz_ids)) {
                    wp_send_json_success(['quizzes' => [], 'total' => 0, 'max_pages' => 0, 'paged' => $paged]);
                    return;
                }
                $args['post__in'] = $quiz_ids;
            } else {
                // Tag existe mais aucune question taguée → liste vide
                wp_send_json_success(['quizzes' => [], 'total' => 0, 'max_pages' => 0, 'paged' => $paged]);
                return;
            }
        }
        if ($tax_query) {
            $args['tax_query'] = $tax_query;
        }

        $query = new WP_Query($args);
        $quizzes = [];

        while ($query->have_posts()) {
            $query->the_post();
            $id   = get_the_ID();
            $meta = RDR_Quiz_AdminUI::get_quiz_meta($id);

            global $wpdb;
            $table = $wpdb->prefix . 'rdr_quiz_stats';
            $stat  = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE quiz_id = %d", $id));

            $q_ids = get_post_meta($id, '_rdr_quiz_questions', true) ?: [];

            $quizzes[] = [
                'id'          => $id,
                'title'       => get_the_title(),
                'excerpt'     => html_entity_decode(strip_tags(get_the_excerpt()), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'url'         => get_permalink(),
                'thumbnail'   => get_the_post_thumbnail_url($id, 'medium') ?: '',
                'difficulty'  => $meta['difficulty'],
                'duration'    => $meta['duration'],
                'q_count'     => count($q_ids),
                'attempts'    => $stat ? intval($stat->attempts) : 0,
                'avg_score'   => ($stat && $stat->attempts > 0) ? round($stat->total_score / $stat->attempts, 1) : null,
                'categories'  => wp_get_post_terms($id, 'rdr_quiz_cat', ['fields' => 'names']),
                'tags'        => self::get_quiz_question_tags($id),
            ];
        }
        wp_reset_postdata();

        wp_send_json_success([
            'quizzes'   => $quizzes,
            'total'     => $query->found_posts,
            'max_pages' => $query->max_num_pages,
            'paged'     => $paged,
        ]);
    }
    /**
     * Retourne les tags uniques de toutes les questions d'un quiz
     */
    private static function get_quiz_question_tags(int $quiz_id): array {
        $q_ids = get_post_meta($quiz_id, '_rdr_quiz_questions', true) ?: [];
        if (empty($q_ids)) return [];

        $tags = [];
        foreach ($q_ids as $qid) {
            $q_tags = get_the_terms($qid, 'rdr_question_tag');
            if ($q_tags && !is_wp_error($q_tags)) {
                foreach ($q_tags as $t) {
                    $tags[$t->slug] = $t->name;
                }
            }
        }
        return array_values(array_map(
            fn($slug, $name) => ['slug' => $slug, 'name' => $name],
            array_keys($tags), $tags
        ));
    }

    /** Retourne les tags uniques de toutes les questions d'un quiz */
    private static function get_question_tags(int $quiz_id): array {
        $q_ids = get_post_meta($quiz_id, '_rdr_quiz_questions', true) ?: [];
        $tags  = [];
        foreach ($q_ids as $qid) {
            $t = get_the_terms($qid, 'rdr_question_tag');
            if ($t && !is_wp_error($t)) {
                foreach ($t as $term) $tags[$term->slug] = $term->name;
            }
        }
        return array_values(array_map(
            fn($slug, $name) => ['slug' => $slug, 'name' => $name],
            array_keys($tags), $tags
        ));
    }

    /* ──────────────────────────────────────────────────────────
       REFRESH NONCE — Permet au JS de renouveler un nonce expiré
       (utile avec LiteSpeed Cache qui cache les pages avec nonces)
    ────────────────────────────────────────────────────────── */
    public static function refresh_nonce(): void {
        wp_send_json_success([
            'nonce' => wp_create_nonce('rdr_quiz_nonce'),
        ]);
    }

    /* ──────────────────────────────────────────────────────────
       QUIZ HTML — Renvoie le HTML du shortcode pour la modale
    ────────────────────────────────────────────────────────── */
    public static function quiz_html(): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error('invalid_method'); return;
        }

        $quiz_id = absint($_POST['quiz_id'] ?? 0);
        if (! $quiz_id
            || get_post_type($quiz_id) !== 'rdr_quiz'
            || get_post_status($quiz_id) !== 'publish') {
            wp_send_json_error('invalid_quiz'); return;
        }

        // Générer le HTML du shortcode
        $html = RDR_Quiz_Shortcodes::render_quiz_direct($quiz_id);

        wp_send_json_success(['html' => $html]);
    }

}