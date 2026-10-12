<?php
/**
 * Shortcodes RDR-IT Courses
 *
 * [rdrc_course_grid]          — liste des cours en cards
 * [rdrc_course_content id=""] — sommaire d'un cours
 * [rdrc_lesson_list id=""]    — liste des leçons d'un cours
 */

/* ── [rdrc_course_grid] ─────────────────────────────────── */
add_shortcode( 'rdrc_course_grid', function( $atts ) {
    $atts = shortcode_atts([
        'category' => '',
        'count'    => 12,
        'columns'  => 3,
        'level'    => '',
    ], $atts );

    $args = [
        'post_type'      => 'rdrc_course',
        'posts_per_page' => intval($atts['count']),
        // 'private' inclus : WP filtre ensuite selon les droits du visiteur,
        // donc les cours prives ne sont visibles que par ceux qui peuvent les lire.
        'post_status'    => is_user_logged_in() ? [ 'publish', 'private' ] : 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    $tax_query = [];
    if ( $atts['category'] ) {
        $tax_query[] = ['taxonomy' => 'rdrc_cat', 'field' => 'slug', 'terms' => explode(',', $atts['category'])];
    }
    if ( $atts['level'] ) {
        $tax_query[] = ['taxonomy' => 'rdrc_level', 'field' => 'slug', 'terms' => explode(',', $atts['level'])];
    }
    if ( $tax_query ) {
        $args['tax_query'] = array_merge([['relation' => 'AND']], $tax_query);
    }

    $query = new WP_Query($args);
    if ( ! $query->have_posts() ) return '<p>Aucun cours disponible.</p>';

    $cols = max(1, min(4, intval($atts['columns'])));
    ob_start();
    ?>
    <div class="rdrc-course-grid rdrc-cols-<?php echo $cols; ?>">
    <?php while ($query->have_posts()): $query->the_post();
        $id         = get_the_ID();
        $thumb      = get_the_post_thumbnail_url($id, 'medium_large');
        $duration   = get_post_meta($id, '_rdrc_duration', true);
        $level      = get_post_meta($id, '_rdrc_level', true);
        $free       = get_post_meta($id, '_rdrc_free', true);
        $icon       = get_post_meta($id, '_rdrc_icon', true) ?: '📚';
        $badge_clr  = get_post_meta($id, '_rdrc_badge_color', true) ?: '#3b82f6';
        $structure  = get_post_meta($id, '_rdrc_structure', true) ?: [];
        $nb_lessons = count(array_filter($structure, fn($i) => ($i['type'] ?? '') === 'lesson'));

        $level_labels = ['debutant' => 'Débutant', 'intermediaire' => 'Intermédiaire', 'avance' => 'Avancé'];
        $level_label  = $level_labels[$level] ?? '';

        $cats = get_the_terms($id, 'rdrc_cat');
        $cat_html = '';
        if ($cats && !is_wp_error($cats)) {
            foreach ($cats as $c) {
                $cat_html .= '<a href="' . get_term_link($c) . '" class="rdrc-course-cat">' . esc_html($c->name) . '</a>';
            }
        }
    ?>
        <article class="rdrc-course-card">
            <?php if ($thumb): ?>
            <a href="<?php the_permalink(); ?>" class="rdrc-card-thumb">
                <img src="<?php echo esc_url($thumb); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
                <span class="rdrc-card-icon" style="background:<?php echo esc_attr($badge_clr); ?>"><?php echo $icon; ?></span>
                <?php if ($free === '1'): ?>
                <span class="rdrc-card-badge rdrc-badge-free">Gratuit</span>
                <?php endif; ?>
            </a>
            <?php else: ?>
            <a href="<?php the_permalink(); ?>" class="rdrc-card-thumb rdrc-card-thumb-placeholder" style="--badge-color:<?php echo esc_attr($badge_clr); ?>">
                <span class="rdrc-card-icon-big"><?php echo $icon; ?></span>
                <?php if ($free === '1'): ?>
                <span class="rdrc-card-badge rdrc-badge-free">Gratuit</span>
                <?php endif; ?>
            </a>
            <?php endif; ?>

            <div class="rdrc-card-body">
                <?php if ($cat_html): ?>
                <div class="rdrc-card-cats"><?php echo $cat_html; ?></div>
                <?php endif; ?>
                <h3 class="rdrc-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
                <?php $excerpt = get_the_excerpt(); if ($excerpt): ?>
                <p class="rdrc-card-excerpt"><?php echo esc_html(wp_trim_words($excerpt, 20)); ?></p>
                <?php endif; ?>
                <div class="rdrc-card-meta">
                    <?php if ($nb_lessons): ?>
                    <span class="rdrc-meta-item">📖 <?php echo $nb_lessons; ?> <?php echo esc_html(rdrc__($nb_lessons > 1 ? 'syllabus_lessons_pl' : 'syllabus_lessons')); ?></span>
                    <?php endif; ?>
                    <?php if ($duration): ?>
                    <span class="rdrc-meta-item">⏱ <?php echo esc_html($duration); ?></span>
                    <?php endif; ?>
                    <?php if ($level_label): ?>
                    <span class="rdrc-meta-item rdrc-level-<?php echo esc_attr($level); ?>"><?php echo esc_html($level_label); ?></span>
                    <?php endif; ?>
                </div>
                <a href="<?php the_permalink(); ?>" class="rdrc-card-cta">Accéder au cours →</a>
            </div>
        </article>
    <?php endwhile; wp_reset_postdata(); ?>
    </div>
    <?php
    return ob_get_clean();
});

/* ── [rdrc_course_content] — Sommaire d'un cours ─────────── */
add_shortcode( 'rdrc_course_content', function( $atts ) {
    $atts = shortcode_atts(['id' => 0, 'show_meta' => 1], $atts);
    $course_id = intval($atts['id']) ?: get_the_ID();
    if ( ! $course_id ) return '';

    $course    = get_post($course_id);
    if ( ! $course ) return '';

    $structure    = rdrc_get_course_structure($course_id);
    $duration     = get_post_meta($course_id, '_rdrc_duration', true);
    $level        = get_post_meta($course_id, '_rdrc_level', true);
    $free         = get_post_meta($course_id, '_rdrc_free', true);
    $icon         = get_post_meta($course_id, '_rdrc_icon', true) ?: '📚';
    $badge_clr    = get_post_meta($course_id, '_rdrc_badge_color', true) ?: '#3b82f6';
    $level_labels = ['debutant' => '🟢 Débutant', 'intermediaire' => '🟡 Intermédiaire', 'avance' => '🔴 Avancé'];
    $type_labels  = ['article' => 'Article', 'video' => 'Vidéo', 'tp' => 'TP', 'quiz' => 'Quiz'];

    $nb_lessons  = count(array_filter($structure, fn($i) => ($i['type'] ?? '') === 'lesson'));
    $nb_sections = count(array_filter($structure, fn($i) => ($i['type'] ?? '') === 'section'));

    // Première leçon
    $first_url = '';
    foreach ($structure as $item) {
        if ( ($item['type'] ?? '') === 'lesson' && isset($item['post']) && rdrc_lesson_is_viewable( $item['post'] ) ) {
            $first_url = get_permalink($item['post']->ID);
            break;
        }
    }

    ob_start();
    ?>
    <div class="rdrc-syllabus-wrap" data-rdrc-syllabus data-course-id="<?php echo (int) $course_id; ?>">

        <?php if (intval($atts['show_meta'])): ?>
        <div class="rdrc-syllabus-stats">
            <?php if ($nb_lessons): ?>
            <div class="rdrc-stat">
                <span class="rdrc-stat-number"><?php echo $nb_lessons; ?></span>
                <span class="rdrc-stat-label"><?php echo esc_html(rdrc__($nb_lessons > 1 ? 'syllabus_lessons_pl' : 'syllabus_lessons')); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($nb_sections): ?>
            <div class="rdrc-stat">
                <span class="rdrc-stat-number"><?php echo $nb_sections; ?></span>
                <span class="rdrc-stat-label"><?php echo esc_html(rdrc__($nb_sections > 1 ? 'syllabus_sections_pl' : 'syllabus_sections')); ?></span>
            </div>
            <?php endif; ?>
            <?php if ($duration): ?>
            <div class="rdrc-stat">
                <span class="rdrc-stat-number"><?php echo esc_html($duration); ?></span>
                <span class="rdrc-stat-label">durée</span>
            </div>
            <?php endif; ?>
            <?php if ($level && isset($level_labels[$level])): ?>
            <div class="rdrc-stat">
                <span class="rdrc-stat-number rdrc-stat-level"><?php echo $level_labels[$level]; ?></span>
            </div>
            <?php endif; ?>
            <?php if ($free === '1'): ?>
            <div class="rdrc-stat rdrc-stat-free">
                <span class="rdrc-stat-number">✅</span>
                <span class="rdrc-stat-label">Gratuit</span>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="rdrc-syllabus">
        <?php
        $lesson_num = 0;
        $in_section = false;

        foreach ($structure as $item) {

            if ( ($item['type'] ?? '') === 'section' ) {
                if ($in_section) echo '</div>'; // fermer section précédente
                $in_section = true;
                echo '<div class="rdrc-syl-section">';
                echo '<div class="rdrc-syl-section-header">';
                echo '<span class="rdrc-syl-section-icon">📁</span>';
                echo '<span class="rdrc-syl-section-title">' . esc_html($item['title'] ?? '') . '</span>';
                echo '</div>';
                echo '<div class="rdrc-syl-lessons">';
                continue;
            }

            if ( ($item['type'] ?? '') === 'lesson' && isset($item['post']) ) {
                $lesson_num++;
                $l      = $item['post'];
                $l_type = get_post_meta($l->ID, '_rdrc_lesson_type', true) ?: 'article';
                $l_dur  = get_post_meta($l->ID, '_rdrc_lesson_duration', true);
                $l_free = get_post_meta($l->ID, '_rdrc_lesson_free', true);
                $icon_l = rdrc_lesson_type_icon($l_type);
                $url    = rdrc_lesson_is_viewable( $l ) ? get_permalink($l->ID) : '#';
                $is_pub = rdrc_lesson_is_viewable( $l );
                ?>
                <a href="<?php echo esc_url($url); ?>" class="rdrc-syl-lesson<?php echo !$is_pub ? ' rdrc-syl-locked' : ''; ?>"
                   data-rdrc-syl-lesson data-lesson-id="<?php echo (int) $l->ID; ?>">
                    <span class="rdrc-syl-check" aria-hidden="true"></span>
                    <span class="rdrc-syl-num"><?php echo $lesson_num; ?></span>
                    <span class="rdrc-syl-icon"><?php echo $icon_l; ?></span>
                    <span class="rdrc-syl-title"><?php echo esc_html($l->post_title); ?></span>
                    <span class="rdrc-syl-meta">
                        <?php if ($l_free === '1'): ?><span class="rdrc-syl-badge-free">Gratuit</span><?php endif; ?>
                        <?php if ($l_dur): ?><span class="rdrc-syl-dur">⏱ <?php echo esc_html($l_dur); ?>min</span><?php endif; ?>
                        <?php if (!$is_pub): ?><span class="rdrc-syl-lock">🔒</span><?php endif; ?>
                    </span>
                </a>
                <?php
            }
        }

        if ($in_section) echo '</div></div>';
        ?>
        </div>

        <?php if ($first_url): ?>
        <div style="margin-top:20px;text-align:center;">
            <a href="<?php echo esc_url($first_url); ?>" class="rdrc-btn-start"><?php echo esc_html(rdrc__('syllabus_start')); ?></a>
        </div>
        <?php endif; ?>

    </div>
    <?php
    return ob_get_clean();
});

/* Note : le sommaire est injecté directement dans le template single-rdrc_course.php */
