<?php
/**
 * Template : leçon — structure HTML GP exacte
 */
if ( ! defined('ABSPATH') ) exit;

$lesson_id   = get_the_ID();
$course_id   = get_post_meta($lesson_id, '_rdrc_parent_course', true);
$lesson_type = get_post_meta($lesson_id, '_rdrc_lesson_type', true) ?: 'article';
$video_url   = get_post_meta($lesson_id, '_rdrc_video_url', true);
$duration    = get_post_meta($lesson_id, '_rdrc_lesson_duration', true);
$is_free     = get_post_meta($lesson_id, '_rdrc_lesson_free', true);
$course      = $course_id ? get_post($course_id) : null;
$badge_clr   = $course_id ? (get_post_meta($course_id, '_rdrc_badge_color', true) ?: '#3b82f6') : '#3b82f6';
$adjacent    = $course_id ? rdrc_get_adjacent_lessons($lesson_id, $course_id) : ['prev'=>null,'next'=>null];
$section     = $course_id ? rdrc_get_lesson_section($lesson_id, $course_id) : '';
$type_labels = ['article'=>'📄 Article','video'=>'🎥 Vidéo','tp'=>'🔧 TP','quiz'=>'❓ Quiz'];
$has_sidebar = is_active_sidebar('rdrc-sidebar-course');

get_header();
?>

<div class="container grid-container">

<div id="content" class="content-area">
<main id="main" class="site-main" <?php generate_do_microdata('main'); ?>>

    <div class="rdrc-lesson-hero" style="--badge:<?php echo esc_attr($badge_clr); ?>">
        <nav class="rdrc-breadcrumb">
            <a href="<?php echo home_url('/'); ?>">Accueil</a><span>›</span>
            <a href="<?php echo get_post_type_archive_link('rdrc_course'); ?>">Cours</a>
            <?php if ($course): ?>
            <span>›</span>
            <a href="<?php echo get_permalink($course_id); ?>"><?php echo esc_html($course->post_title); ?></a>
            <?php if ($section): ?><span>›</span><span><?php echo esc_html($section); ?></span><?php endif; ?>
            <?php endif; ?>
        </nav>
        <div class="rdrc-lesson-hero-inner">
            <?php if ($course): ?>
            <a href="<?php echo get_permalink($course_id); ?>" class="rdrc-lesson-course-pill">
                <?php echo get_post_meta($course_id,'_rdrc_icon',true) ?: '📚'; ?>
                <?php echo esc_html($course->post_title); ?>
            </a>
            <?php endif; ?>
            <h1 class="rdrc-lesson-title"><?php the_title(); ?></h1>
            <div class="rdrc-lesson-badges">
                <span class="rdrc-badge"><?php echo $type_labels[$lesson_type] ?? '📄 Article'; ?></span>
                <?php if ($duration): ?><span class="rdrc-badge">⏱ <?php echo esc_html($duration); ?></span><?php endif; ?>
                <?php if ($is_free==='1'): ?><span class="rdrc-badge rdrc-badge-free">✅ Gratuit</span><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="rdrc-lesson-body">
        <?php if ($video_url && $lesson_type==='video'): ?>
        <div class="rdrc-video-embed"><?php echo wp_oembed_get($video_url,['width'=>860]); ?></div>
        <?php endif; ?>

        <div class="rdrc-lesson-content entry-content" data-lesson-id="<?php echo $lesson_id; ?>">
            <?php the_content(); ?>
        </div>

        <?php echo rdrc_render_resources($lesson_id); ?>

        <?php if (is_active_sidebar('rdrc-below-lesson')): ?>
        <div class="rdrc-widget-zone"><?php dynamic_sidebar('rdrc-below-lesson'); ?></div>
        <?php endif; ?>

        <nav class="rdrc-lesson-nav">
            <div class="rdrc-nav-prev">
                <?php if ($adjacent['prev']): ?>
                <a href="<?php echo get_permalink($adjacent['prev']['lesson_id']); ?>" class="rdrc-nav-btn">
                    <span class="rdrc-nav-lbl">⬅ Précédente</span>
                    <span class="rdrc-nav-title"><?php echo esc_html($adjacent['prev']['post']->post_title); ?></span>
                </a>
                <?php elseif ($course): ?>
                <a href="<?php echo get_permalink($course_id); ?>" class="rdrc-nav-btn">
                    <span class="rdrc-nav-lbl">⬅ Retour au cours</span>
                    <span class="rdrc-nav-title"><?php echo esc_html($course->post_title); ?></span>
                </a>
                <?php endif; ?>
            </div>
            <div class="rdrc-nav-center">
                <?php if ($course): ?>
                <a href="<?php echo get_permalink($course_id); ?>" class="rdrc-back-course-link">📋 Sommaire</a>
                <?php endif; ?>
            </div>
            <div class="rdrc-nav-next">
                <?php if ($adjacent['next']): ?>
                <a href="<?php echo get_permalink($adjacent['next']['lesson_id']); ?>" class="rdrc-nav-btn rdrc-nav-next-btn">
                    <span class="rdrc-nav-lbl">Suivante ➡</span>
                    <span class="rdrc-nav-title"><?php echo esc_html($adjacent['next']['post']->post_title); ?></span>
                </a>
                <?php else: ?>
                <a href="<?php echo $course ? get_permalink($course_id) : '#'; ?>" class="rdrc-nav-btn">
                    <span class="rdrc-nav-lbl">🎉 Fin du cours</span>
                    <span class="rdrc-nav-title">Retour au sommaire</span>
                </a>
                <?php endif; ?>
            </div>
        </nav>

        <?php if ($course_id): ?>
        <div class="rdrc-toc-widget">
            <button class="rdrc-toc-toggle" aria-expanded="false">
                📋 Sommaire : <?php echo esc_html($course ? $course->post_title : ''); ?>
                <span class="rdrc-toc-arrow">▼</span>
            </button>
            <div class="rdrc-toc-body" hidden>
                <?php echo rdrc_render_toc($course_id, $lesson_id); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

</main>
</div><!-- #content -->

<?php if ($has_sidebar): ?>
<div id="right-sidebar" class="widget-area sidebar is-right-sidebar">
    <div class="inside-right-sidebar">
        <?php dynamic_sidebar('rdrc-sidebar-course'); ?>
    </div>
</div>
<?php endif; ?>

</div><!-- .container -->

<?php get_footer(); ?>
