<?php
/**
 * Template : archive cours — structure HTML GP exacte
 */
if ( ! defined('ABSPATH') ) exit;

$has_sidebar = is_active_sidebar('rdrc-sidebar-course');
$cats = get_terms(['taxonomy'=>'rdrc_cat','hide_empty'=>true]);

get_header();
?>

<div class="container grid-container">

<div id="content" class="content-area">
<main id="main" class="site-main" <?php generate_do_microdata('main'); ?>>

    <header class="rdrc-archive-header">
        <h1 class="rdrc-archive-title">📚 Tous les cours</h1>
        <p class="rdrc-archive-desc">Cours et tutoriels IT approfondis — administration système, réseau, virtualisation, sécurité.</p>
        <?php if ($cats && !is_wp_error($cats)): ?>
        <div class="rdrc-filter-bar">
            <a href="<?php echo get_post_type_archive_link('rdrc_course'); ?>"
               class="rdrc-filter-btn <?php echo !is_tax()?'rdrc-filter-active':''; ?>">Tous</a>
            <?php foreach ($cats as $cat): ?>
            <a href="<?php echo get_term_link($cat); ?>"
               class="rdrc-filter-btn <?php echo is_tax('rdrc_cat',$cat->term_id)?'rdrc-filter-active':''; ?>">
                <?php echo esc_html($cat->name); ?>
                <span class="rdrc-filter-count"><?php echo $cat->count; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </header>

    <?php if (have_posts()): ?>
    <div class="rdrc-course-grid rdrc-cols-3">
    <?php while (have_posts()): the_post();
        $id = get_the_ID();
        $thumb     = get_the_post_thumbnail_url($id,'medium_large');
        $duration  = get_post_meta($id,'_rdrc_duration',true);
        $level     = get_post_meta($id,'_rdrc_level',true);
        $free      = get_post_meta($id,'_rdrc_free',true);
        $icon      = get_post_meta($id,'_rdrc_icon',true) ?: '📚';
        $badge_clr = get_post_meta($id,'_rdrc_badge_color',true) ?: '#3b82f6';
        $s         = get_post_meta($id,'_rdrc_structure',true) ?: [];
        $nb        = count(array_filter($s,fn($i)=>($i['type']??'')==='lesson'));
        $lvl_lbl   = ['debutant'=>'Débutant','intermediaire'=>'Intermédiaire','avance'=>'Avancé'];
        $post_cats = get_the_terms($id,'rdrc_cat');
    ?>
        <article class="rdrc-course-card">
            <?php if ($thumb): ?>
            <a href="<?php the_permalink(); ?>" class="rdrc-card-thumb">
                <img src="<?php echo esc_url($thumb); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy">
                <span class="rdrc-card-icon" style="background:<?php echo esc_attr($badge_clr); ?>"><?php echo $icon; ?></span>
                <?php if ($free==='1'): ?><span class="rdrc-card-badge rdrc-badge-free">Gratuit</span><?php endif; ?>
            </a>
            <?php else: ?>
            <a href="<?php the_permalink(); ?>" class="rdrc-card-thumb rdrc-card-thumb-placeholder" style="--badge-color:<?php echo esc_attr($badge_clr); ?>">
                <span class="rdrc-card-icon-big"><?php echo $icon; ?></span>
                <?php if ($free==='1'): ?><span class="rdrc-card-badge rdrc-badge-free">Gratuit</span><?php endif; ?>
            </a>
            <?php endif; ?>
            <div class="rdrc-card-body">
                <?php if ($post_cats && !is_wp_error($post_cats)): ?>
                <div class="rdrc-card-cats">
                    <?php foreach ($post_cats as $c): ?>
                    <a href="<?php echo get_term_link($c); ?>" class="rdrc-course-cat"><?php echo esc_html($c->name); ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <h2 class="rdrc-card-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
                <?php $ex = get_the_excerpt(); if ($ex): ?>
                <p class="rdrc-card-excerpt"><?php echo esc_html(wp_trim_words($ex,18)); ?></p>
                <?php endif; ?>
                <div class="rdrc-card-meta">
                    <?php if ($nb): ?><span class="rdrc-meta-item">📖 <?php echo $nb; ?> leçon<?php echo $nb>1?'s':''; ?></span><?php endif; ?>
                    <?php if ($duration): ?><span class="rdrc-meta-item">⏱ <?php echo esc_html($duration); ?></span><?php endif; ?>
                    <?php if ($level && isset($lvl_lbl[$level])): ?><span class="rdrc-meta-item rdrc-level-<?php echo esc_attr($level); ?>"><?php echo $lvl_lbl[$level]; ?></span><?php endif; ?>
                </div>
                <a href="<?php the_permalink(); ?>" class="rdrc-card-cta">Accéder au cours →</a>
            </div>
        </article>
    <?php endwhile; ?>
    </div>
    <?php the_posts_pagination(['mid_size'=>2]); ?>
    <?php else: ?>
    <p>Aucun cours disponible.</p>
    <?php endif; ?>

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
