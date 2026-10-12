<?php
/**
 * Template: rdrnl_edition archive — /newsletter/
 * Layout GP avec sidebar droite
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();
?>

<div id="content" class="site-content">

  <?php do_action( 'generate_before_main_content' ); ?>

  <header class="page-header">
    <?php do_action( 'generate_before_archive_title' ); ?>
    <h1 class="page-title">📧 Newsletter RDR-IT</h1>
    <p class="rdrnl-archive-desc">Récap hebdomadaire IT — chaque vendredi.</p>
    <div class="rdrnl-archive-actions">
      <?php echo do_shortcode('[rdrnl_subscribe title="" description="" style="inline"]'); ?>
      <a href="<?php echo esc_url(home_url('/feed/rdrnl-editions/')); ?>" class="rdrnl-rss-link" rel="nofollow">
        📡 Flux RSS
      </a>
    </div>
  </header>

  <?php if ( have_posts() ) : ?>
  <div class="rdrnl-edition-grid">
    <?php while ( have_posts() ) : the_post();
      $count = get_post_meta(get_the_ID(), '_rdrnl_item_count', true);
      $thumb = get_the_post_thumbnail_url(get_the_ID(), 'medium');
    ?>
    <article id="post-<?php the_ID(); ?>" <?php post_class('rdrnl-edition-card'); ?>>
      <a href="<?php the_permalink(); ?>" class="rdrnl-edition-card-link">
        <?php if ($thumb) : ?>
        <div class="rdrnl-edition-card-thumb" style="background-image:url('<?php echo esc_url($thumb); ?>')"></div>
        <?php endif; ?>
        <div class="rdrnl-edition-card-inner">
          <div class="rdrnl-edition-card-header">
            <span class="rdrnl-edition-badge">📧 Newsletter</span>
            <time datetime="<?php echo get_the_date('c'); ?>"><?php echo get_the_date('j F Y'); ?></time>
          </div>
          <h2 class="rdrnl-edition-card-title"><?php the_title(); ?></h2>
          <?php if (get_the_excerpt()) : ?>
          <p class="rdrnl-edition-card-excerpt"><?php echo get_the_excerpt(); ?></p>
          <?php endif; ?>
          <?php if ($count) : ?>
          <span class="rdrnl-edition-card-count"><?php echo intval($count); ?> ressource<?php echo $count > 1 ? 's' : ''; ?></span>
          <?php endif; ?>
        </div>
      </a>
    </article>
    <?php endwhile; ?>
  </div>

  <?php the_posts_pagination(['mid_size' => 2, 'prev_text' => '← Plus récentes', 'next_text' => 'Plus anciennes →']); ?>

  <?php else : ?>
  <p>Aucune édition publiée pour le moment.</p>
  <?php endif; ?>

  <?php do_action( 'generate_after_main_content' ); ?>

</div><!-- #content -->

<?php get_footer(); ?>
