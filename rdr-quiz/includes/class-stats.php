<?php
/**
 * Statistiques — deux tables :
 *   {prefix}_rdr_quiz_stats    : agrégats par quiz (attempts, total_score)
 *   {prefix}_rdr_quiz_history  : historique anonyme (quiz_id, score_pct, answered_at)
 *   {prefix}_rdr_question_stats: agrégats par question (correct, total)
 *
 * Aucune donnée personnelle n'est stockée (RGPD).
 */
defined('ABSPATH') || exit;

class RDR_Quiz_Stats {

    public static function init(): void {
        // Sous-page stats dans le menu Quiz
        add_action('admin_menu', [self::class, 'add_stats_page']);
        // AJAX reset
        add_action('wp_ajax_rdr_reset_stats', [self::class, 'ajax_reset']);
    }

    /* ════════════════════════════════════════════════════════
       CRÉATION DES TABLES
    ════════════════════════════════════════════════════════ */
    public static function create_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Table agrégats quiz
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rdr_quiz_stats (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            quiz_id     BIGINT UNSIGNED NOT NULL,
            attempts    BIGINT UNSIGNED NOT NULL DEFAULT 0,
            total_score DECIMAL(12,2)   NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY quiz_id (quiz_id)
        ) {$charset};");

        // Historique anonyme
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rdr_quiz_history (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            quiz_id     BIGINT UNSIGNED NOT NULL,
            score_pct   TINYINT UNSIGNED NOT NULL DEFAULT 0,
            answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY quiz_id (quiz_id),
            KEY answered_at (answered_at)
        ) {$charset};");

        // Agrégats par question
        dbDelta("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}rdr_question_stats (
            id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            question_id BIGINT UNSIGNED NOT NULL,
            total       BIGINT UNSIGNED NOT NULL DEFAULT 0,
            correct     BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY question_id (question_id)
        ) {$charset};");
    }

    /* ════════════════════════════════════════════════════════
       ENREGISTREMENT
    ════════════════════════════════════════════════════════ */

    /** Enregistre le score final d'une tentative */
    public static function record(int $quiz_id, float $pct): void {
        global $wpdb;

        // Agrégat quiz
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rdr_quiz_stats WHERE quiz_id = %d", $quiz_id
        ));
        if ($exists) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}rdr_quiz_stats
                 SET attempts = attempts + 1, total_score = total_score + %f
                 WHERE quiz_id = %d",
                $pct, $quiz_id
            ));
        } else {
            $wpdb->insert("{$wpdb->prefix}rdr_quiz_stats", [
                'quiz_id' => $quiz_id, 'attempts' => 1, 'total_score' => $pct,
            ], ['%d', '%d', '%f']);
        }

        // Historique anonyme
        $wpdb->insert("{$wpdb->prefix}rdr_quiz_history", [
            'quiz_id'     => $quiz_id,
            'score_pct'   => min(100, max(0, (int) round($pct))),
            'answered_at' => current_time('mysql'),
        ], ['%d', '%d', '%s']);
    }

    /** Enregistre le résultat d'une question (appelé depuis Ajax::check_answer) */
    public static function record_question(int $question_id, bool $correct): void {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}rdr_question_stats WHERE question_id = %d", $question_id
        ));
        if ($exists) {
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}rdr_question_stats
                 SET total = total + 1, correct = correct + %d
                 WHERE question_id = %d",
                $correct ? 1 : 0, $question_id
            ));
        } else {
            $wpdb->insert("{$wpdb->prefix}rdr_question_stats", [
                'question_id' => $question_id,
                'total'       => 1,
                'correct'     => $correct ? 1 : 0,
            ], ['%d', '%d', '%d']);
        }
    }

    /* ════════════════════════════════════════════════════════
       LECTURE
    ════════════════════════════════════════════════════════ */

    public static function get(int $quiz_id): array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}rdr_quiz_stats WHERE quiz_id = %d", $quiz_id
        ));
        if (! $row) return ['attempts' => 0, 'avg' => null];
        return [
            'attempts' => intval($row->attempts),
            'avg'      => $row->attempts > 0 ? round($row->total_score / $row->attempts, 1) : null,
        ];
    }

    /** Stats globales pour le tableau de bord */
    public static function get_global(): array {
        global $wpdb;

        $agg = $wpdb->get_row(
            "SELECT SUM(attempts) as total_attempts, SUM(total_score) as sum_score,
                    COUNT(quiz_id) as quiz_count
             FROM {$wpdb->prefix}rdr_quiz_stats"
        );

        $total_attempts = intval($agg->total_attempts ?? 0);
        $global_avg     = $total_attempts > 0
            ? round((float)($agg->sum_score ?? 0) / $total_attempts, 1)
            : null;

        return [
            'quiz_count'     => intval($agg->quiz_count ?? 0),
            'total_attempts' => $total_attempts,
            'global_avg'     => $global_avg,
        ];
    }

    /** Stats de tous les quiz (triés par tentatives) */
    public static function get_all_quiz_stats(): array {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT s.*, s.total_score / NULLIF(s.attempts,0) AS avg_score
             FROM {$wpdb->prefix}rdr_quiz_stats s
             ORDER BY s.attempts DESC"
        );
    }

    /** Stats de toutes les questions d'un quiz */
    public static function get_question_stats(int $quiz_id): array {
        global $wpdb;
        $q_ids = get_post_meta($quiz_id, '_rdr_quiz_questions', true) ?: [];
        if (empty($q_ids)) return [];

        $ids_sql = implode(',', array_map('intval', $q_ids));
        $rows = $wpdb->get_results(
            "SELECT qs.*, qs.correct / NULLIF(qs.total,0) * 100 AS pct_correct
             FROM {$wpdb->prefix}rdr_question_stats qs
             WHERE qs.question_id IN ({$ids_sql})"
        );

        // Indexer par question_id
        $indexed = [];
        foreach ($rows as $r) $indexed[intval($r->question_id)] = $r;
        return $indexed;
    }

    /** Historique agrégé par jour (30 derniers jours par défaut) */
    public static function get_history(int $days = 30, int $quiz_id = 0): array {
        global $wpdb;

        $where = $quiz_id ? $wpdb->prepare("AND quiz_id = %d", $quiz_id) : '';

        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(answered_at) AS day,
                    COUNT(*) AS attempts,
                    ROUND(AVG(score_pct),1) AS avg_score
             FROM {$wpdb->prefix}rdr_quiz_history
             WHERE answered_at >= DATE_SUB(NOW(), INTERVAL %d DAY) {$where}
             GROUP BY DATE(answered_at)
             ORDER BY day ASC",
            $days
        ));
    }

    /* ════════════════════════════════════════════════════════
       PAGE ADMIN STATS
    ════════════════════════════════════════════════════════ */

    public static function add_stats_page(): void {
        add_submenu_page(
            'edit.php?post_type=rdr_quiz',
            __('📊 Statistiques Quiz', 'rdr-quiz'),
            __('📊 Statistiques',      'rdr-quiz'),
            'edit_posts',
            'rdr-quiz-stats',
            [self::class, 'render_stats_page']
        );
    }

    public static function render_stats_page(): void {
        if (! current_user_can('edit_posts')) wp_die(__('Accès refusé', 'rdr-quiz'));

        // Action reset
        $reset_done = false;
        $reset_quiz = 0;
        if (
            isset($_POST['rdr_reset_nonce']) &&
            wp_verify_nonce($_POST['rdr_reset_nonce'], 'rdr_reset_stats') &&
            current_user_can('manage_options')
        ) {
            $reset_quiz = absint($_POST['reset_quiz_id'] ?? 0);
            self::do_reset($reset_quiz);
            $reset_done = true;
        }

        $global    = self::get_global();
        $quiz_rows = self::get_all_quiz_stats();
        $history   = self::get_history(30);
        $quiz_filter = absint($_GET['quiz_id'] ?? 0);
        $q_stats   = $quiz_filter ? self::get_question_stats($quiz_filter) : [];
        $q_ids     = $quiz_filter ? (get_post_meta($quiz_filter, '_rdr_quiz_questions', true) ?: []) : [];

        ?>
        <div class="wrap rdr-stats-wrap">
            <h1>📊 <?php _e('Statistiques Quiz', 'rdr-quiz'); ?></h1>

            <?php if ($reset_done): ?>
            <div class="notice notice-success is-dismissible">
                <p><?php echo $reset_quiz
                    ? sprintf(__('Stats du quiz #%d remises à zéro.', 'rdr-quiz'), $reset_quiz)
                    : __('Toutes les statistiques ont été remises à zéro.', 'rdr-quiz'); ?></p>
            </div>
            <?php endif; ?>

            <!-- ── KPI globaux ── -->
            <div class="rdr-stats-kpi-row">
                <div class="rdr-kpi-card">
                    <span class="rdr-kpi-icon">🎯</span>
                    <span class="rdr-kpi-num"><?php echo number_format_i18n($global['total_attempts']); ?></span>
                    <span class="rdr-kpi-label"><?php _e('Tentatives totales', 'rdr-quiz'); ?></span>
                </div>
                <div class="rdr-kpi-card">
                    <span class="rdr-kpi-icon">📈</span>
                    <span class="rdr-kpi-num"><?php echo $global['global_avg'] !== null ? $global['global_avg'] . ' %' : '—'; ?></span>
                    <span class="rdr-kpi-label"><?php _e('Score moyen global', 'rdr-quiz'); ?></span>
                </div>
                <div class="rdr-kpi-card">
                    <span class="rdr-kpi-icon">🧠</span>
                    <span class="rdr-kpi-num"><?php echo $global['quiz_count']; ?></span>
                    <span class="rdr-kpi-label"><?php _e('Quiz avec données', 'rdr-quiz'); ?></span>
                </div>
            </div>

            <!-- ── Historique 30j ── -->
            <div class="rdr-stats-section">
                <h2><?php _e('Activité — 30 derniers jours', 'rdr-quiz'); ?></h2>
                <?php if (empty($history)): ?>
                <p class="rdr-stats-empty"><?php _e('Aucune donnée sur cette période.', 'rdr-quiz'); ?></p>
                <?php else: ?>
                <div class="rdr-history-chart-wrap">
                    <canvas id="rdr-history-chart" height="80"></canvas>
                </div>
                <table class="widefat striped rdr-stats-table">
                    <thead>
                        <tr>
                            <th><?php _e('Date', 'rdr-quiz'); ?></th>
                            <th><?php _e('Tentatives', 'rdr-quiz'); ?></th>
                            <th><?php _e('Score moyen', 'rdr-quiz'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_reverse($history) as $h): ?>
                        <tr>
                            <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($h->day))); ?></td>
                            <td><?php echo intval($h->attempts); ?></td>
                            <td>
                                <div class="rdr-pct-bar-wrap">
                                    <div class="rdr-pct-bar" style="width:<?php echo (float)$h->avg_score; ?>%"></div>
                                    <span><?php echo number_format((float)$h->avg_score, 1); ?> %</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- ── Stats par quiz ── -->
            <div class="rdr-stats-section">
                <h2><?php _e('Stats par quiz', 'rdr-quiz'); ?></h2>
                <?php if (empty($quiz_rows)): ?>
                <p class="rdr-stats-empty"><?php _e("Aucun quiz joué pour l\'instant.", 'rdr-quiz'); ?></p>
                <?php else: ?>
                <table class="widefat striped rdr-stats-table">
                    <thead>
                        <tr>
                            <th><?php _e('Quiz', 'rdr-quiz'); ?></th>
                            <th><?php _e('Tentatives', 'rdr-quiz'); ?></th>
                            <th style="width:220px"><?php _e('Score moyen', 'rdr-quiz'); ?></th>
                            <th><?php _e('Questions', 'rdr-quiz'); ?></th>
                            <th><?php _e('Actions', 'rdr-quiz'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($quiz_rows as $row):
                        $qid   = intval($row->quiz_id);
                        $title = get_the_title($qid) ?: sprintf(__('Quiz #%d (supprimé)', 'rdr-quiz'), $qid);
                        $avg   = $row->attempts > 0 ? round((float)$row->avg_score, 1) : null;
                        $q_count = count(get_post_meta($qid, '_rdr_quiz_questions', true) ?: []);
                        $edit_url = get_edit_post_link($qid);
                    ?>
                        <tr>
                            <td>
                                <?php if ($edit_url): ?>
                                <a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html($title); ?></a>
                                <?php else: echo esc_html($title); endif; ?>
                            </td>
                            <td><strong><?php echo number_format_i18n(intval($row->attempts)); ?></strong></td>
                            <td>
                                <?php if ($avg !== null): ?>
                                <div class="rdr-pct-bar-wrap">
                                    <div class="rdr-pct-bar <?php echo $avg >= 80 ? 'rdr-bar-green' : ($avg >= 50 ? 'rdr-bar-orange' : 'rdr-bar-red'); ?>"
                                         style="width:<?php echo $avg; ?>%"></div>
                                    <span><?php echo $avg; ?> %</span>
                                </div>
                                <?php else: echo '—'; endif; ?>
                            </td>
                            <td>
                                <?php if ($q_count): ?>
                                <a href="<?php echo esc_url(add_query_arg(['page' => 'rdr-quiz-stats', 'quiz_id' => $qid], admin_url('edit.php?post_type=rdr_quiz'))); ?>">
                                    <?php printf(__('Voir %d questions ↓', 'rdr-quiz'), $q_count); ?>
                                </a>
                                <?php else: echo '—'; endif; ?>
                            </td>
                            <td>
                                <?php if (current_user_can('manage_options')): ?>
                                <form method="post" style="display:inline"
                                      onsubmit="return confirm('<?php esc_attr_e('Remettre à zéro les stats de ce quiz ?', 'rdr-quiz'); ?>')">
                                    <?php wp_nonce_field('rdr_reset_stats', 'rdr_reset_nonce'); ?>
                                    <input type="hidden" name="reset_quiz_id" value="<?php echo $qid; ?>">
                                    <button type="submit" class="button button-small rdr-btn-reset">
                                        🗑 <?php _e('RAZ', 'rdr-quiz'); ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (current_user_can('manage_options')): ?>
                <div class="rdr-stats-global-reset">
                    <form method="post"
                          onsubmit="return confirm('<?php esc_attr_e('Supprimer TOUTES les statistiques ? Cette action est irréversible.', 'rdr-quiz'); ?>')">
                        <?php wp_nonce_field('rdr_reset_stats', 'rdr_reset_nonce'); ?>
                        <input type="hidden" name="reset_quiz_id" value="0">
                        <button type="submit" class="button button-link-delete">
                            🗑 <?php _e('Remettre à zéro TOUTES les statistiques', 'rdr-quiz'); ?>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>

            <!-- ── Stats par question (si quiz sélectionné) ── -->
            <?php if ($quiz_filter && !empty($q_ids)): ?>
            <div class="rdr-stats-section">
                <h2>
                    <?php printf(__('Questions du quiz : %s', 'rdr-quiz'), esc_html(get_the_title($quiz_filter))); ?>
                    <a href="<?php echo esc_url(admin_url('edit.php?post_type=rdr_quiz&page=rdr-quiz-stats')); ?>"
                       class="page-title-action" style="margin-left:12px">← <?php _e('Retour', 'rdr-quiz'); ?></a>
                </h2>
                <table class="widefat striped rdr-stats-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th><?php _e('Question', 'rdr-quiz'); ?></th>
                            <th><?php _e('Type', 'rdr-quiz'); ?></th>
                            <th><?php _e('Réponses', 'rdr-quiz'); ?></th>
                            <th style="width:220px"><?php _e('Taux de réussite', 'rdr-quiz'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($q_ids as $i => $qid):
                        $q      = get_post($qid);
                        if (!$q) continue;
                        $type   = get_post_meta($qid, '_rdr_answer_type', true) ?: 'radio';
                        $qs     = $q_stats[$qid] ?? null;
                        $total  = $qs ? intval($qs->total)   : 0;
                        $pct    = $qs && $total > 0 ? round((float)$qs->pct_correct, 1) : null;
                    ?>
                        <tr>
                            <td><?php echo $i + 1; ?></td>
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link($qid)); ?>">
                                    <?php echo esc_html($q->post_title); ?>
                                </a>
                            </td>
                            <td>
                                <span class="rdr-q-type rdr-type-<?php echo $type; ?>">
                                    <?php echo RDR_Quiz_AdminUI::type_label($type); ?>
                                </span>
                            </td>
                            <td><?php echo $total ?: '—'; ?></td>
                            <td>
                                <?php if ($pct !== null): ?>
                                <div class="rdr-pct-bar-wrap">
                                    <div class="rdr-pct-bar <?php echo $pct >= 80 ? 'rdr-bar-green' : ($pct >= 50 ? 'rdr-bar-orange' : 'rdr-bar-red'); ?>"
                                         style="width:<?php echo $pct; ?>%"></div>
                                    <span><?php echo $pct; ?> %</span>
                                </div>
                                <?php else: ?>
                                <span style="color:#aaa"><?php _e('Pas encore répondue', 'rdr-quiz'); ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // Chart.js inline — mini graphique historique
        if (!empty($history)):
            $labels = wp_json_encode(array_map(fn($h) => $h->day, $history));
            $data_a = wp_json_encode(array_map(fn($h) => intval($h->attempts), $history));
            $data_s = wp_json_encode(array_map(fn($h) => (float)$h->avg_score, $history));
        ?>
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
        <script>
        (function() {
            var ctx = document.getElementById('rdr-history-chart');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo $labels; ?>,
                    datasets: [
                        {
                            label: '<?php echo esc_js(__('Tentatives', 'rdr-quiz')); ?>',
                            data: <?php echo $data_a; ?>,
                            backgroundColor: 'rgba(34,113,177,0.5)',
                            borderColor: 'rgba(34,113,177,0.9)',
                            borderWidth: 1,
                            yAxisID: 'yA',
                        },
                        {
                            label: '<?php echo esc_js(__('Score moyen %', 'rdr-quiz')); ?>',
                            data: <?php echo $data_s; ?>,
                            type: 'line',
                            borderColor: '#22c55e',
                            backgroundColor: 'transparent',
                            pointRadius: 3,
                            borderWidth: 2,
                            tension: 0.3,
                            yAxisID: 'yS',
                        }
                    ]
                },
                options: {
                    responsive: true,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'top' } },
                    scales: {
                        yA: { type: 'linear', position: 'left',  beginAtZero: true, title: { display: true, text: 'Tentatives' } },
                        yS: { type: 'linear', position: 'right', beginAtZero: true, max: 100,
                              title: { display: true, text: 'Score %' }, grid: { drawOnChartArea: false } }
                    }
                }
            });
        })();
        </script>
        <?php endif; ?>
        <?php
    }

    /* ════════════════════════════════════════════════════════
       RESET
    ════════════════════════════════════════════════════════ */
    private static function do_reset(int $quiz_id = 0): void {
        global $wpdb;

        if ($quiz_id) {
            $wpdb->delete("{$wpdb->prefix}rdr_quiz_stats",   ['quiz_id' => $quiz_id], ['%d']);
            $wpdb->delete("{$wpdb->prefix}rdr_quiz_history", ['quiz_id' => $quiz_id], ['%d']);

            // Stats questions de ce quiz
            $q_ids = get_post_meta($quiz_id, '_rdr_quiz_questions', true) ?: [];
            if (!empty($q_ids)) {
                $ids = implode(',', array_map('intval', $q_ids));
                $wpdb->query("DELETE FROM {$wpdb->prefix}rdr_question_stats WHERE question_id IN ({$ids})");
            }
        } else {
            // Reset global
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rdr_quiz_stats");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rdr_quiz_history");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rdr_question_stats");
        }
    }

    /** AJAX reset (depuis la page stats) */
    public static function ajax_reset(): void {
        check_ajax_referer('rdr_quiz_admin', 'nonce');
        if (! current_user_can('manage_options')) wp_send_json_error('forbidden');
        self::do_reset(absint($_POST['quiz_id'] ?? 0));
        wp_send_json_success();
    }
}
