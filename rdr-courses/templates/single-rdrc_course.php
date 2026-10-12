<?php
/**
 * Template : cours — structure HTML GP exacte
 */
if ( ! defined('ABSPATH') ) exit;

$course_id  = get_the_ID();
$icon       = get_post_meta($course_id, '_rdrc_icon', true) ?: '📚';
$duration   = get_post_meta($course_id, '_rdrc_duration', true);
$level      = get_post_meta($course_id, '_rdrc_level', true);
$free       = get_post_meta($course_id, '_rdrc_free', true);
$badge_clr  = get_post_meta($course_id, '_rdrc_badge_color', true) ?: '#3b82f6';
$structure  = get_post_meta($course_id, '_rdrc_structure', true) ?: [];
$nb_lessons = count(array_filter($structure, fn($i) => ($i['type']??'') === 'lesson'));
$cats       = get_the_terms($course_id, 'rdrc_cat');
$level_lbl  = ['debutant'=>'🟢 Débutant','intermediaire'=>'🟡 Intermédiaire','avance'=>'🔴 Avancé'];
$has_sidebar = is_active_sidebar('rdrc-sidebar-course');

$first_url = '';
foreach ($structure as $item) {
    if (($item['type']??'') === 'lesson' && !empty($item['lesson_id'])) {
        $l = get_post($item['lesson_id']);
        if ($l && $l->post_status === 'publish') { $first_url = get_permalink($l->ID); break; }
    }
}

get_header();
?>

<div class="container grid-container">

<div id="content" class="content-area">
<main id="main" class="site-main" <?php generate_do_microdata('main'); ?>>

    <div class="rdrc-course-hero" style="--hero-color:<?php echo esc_attr($badge_clr); ?>">
        <?php if (has_post_thumbnail()): ?>
        <div class="rdrc-course-hero-bg">
            <?php the_post_thumbnail('large'); ?>
            <div class="rdrc-course-hero-overlay"></div>
        </div>
        <?php endif; ?>
        <div class="rdrc-course-hero-content">
            <nav class="rdrc-breadcrumb rdrc-bc-light">
                <a href="<?php echo home_url('/'); ?>">Accueil</a><span>›</span>
                <a href="<?php echo get_post_type_archive_link('rdrc_course'); ?>">Cours</a>
                <?php if ($cats && !is_wp_error($cats)): ?>
                <span>›</span>
                <a href="<?php echo get_term_link($cats[0]); ?>"><?php echo esc_html($cats[0]->name); ?></a>
                <?php endif; ?>
            </nav>
            <div class="rdrc-course-hero-icon" style="background:<?php echo esc_attr($badge_clr); ?>"><?php echo $icon; ?></div>
            <h1 class="rdrc-course-title"><?php the_title(); ?></h1>
            <?php if (has_excerpt()): ?>
            <p class="rdrc-course-intro"><?php the_excerpt(); ?></p>
            <?php endif; ?>
            <div class="rdrc-course-badges">
                <?php if ($nb_lessons): ?><span class="rdrc-badge-hero">📖 <?php echo $nb_lessons; ?> leçon<?php echo $nb_lessons>1?'s':''; ?></span><?php endif; ?>
                <?php if ($duration): ?><span class="rdrc-badge-hero">⏱ <?php echo esc_html($duration); ?></span><?php endif; ?>
                <?php if ($level && isset($level_lbl[$level])): ?><span class="rdrc-badge-hero"><?php echo $level_lbl[$level]; ?></span><?php endif; ?>
                <?php if ($free==='1'): ?><span class="rdrc-badge-hero rdrc-badge-hero-free">✅ Gratuit</span><?php endif; ?>
            </div>
            <?php if ($first_url): ?>
            <a href="<?php echo esc_url($first_url); ?>" class="rdrc-btn-start">🚀 Commencer le cours</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="rdrc-course-body">
        <?php if (get_the_content()): ?>
        <div class="rdrc-course-description entry-content"><?php the_content(); ?></div>
        <?php endif; ?>
        <div class="rdrc-course-syllabus">
            <h2 class="rdrc-section-heading">📋 Contenu du cours</h2>
            <?php echo do_shortcode('[rdrc_course_content id="' . $course_id . '" show_meta="0"]'); ?>
        </div>
        <?php if (is_active_sidebar('rdrc-below-course')): ?>
        <div class="rdrc-widget-zone"><?php dynamic_sidebar('rdrc-below-course'); ?></div>
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
