<?php
/**
 * Template: rdrnl_edition single — /editions/{slug}/
 * Utilise les hooks GP natifs pour le layout avec sidebar.
 * Identique au comportement d'un article standard du site.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

// Tell GP this needs right-sidebar (belt + suspenders)
add_filter( 'generate_sidebar_layout', function(){ return 'right-sidebar'; }, 99 );
?>

<?php do_action( 'generate_before_main_content' ); ?>

<?php while ( have_posts() ) : the_post(); ?>

<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
<div class="inside-article">

    <header class="entry-header">
        <?php do_action( 'generate_before_entry_title' ); ?>
        <div class="rdrnl-edition-meta">
            <span class="rdrnl-edition-badge">📧 Newsletter</span>
            <time datetime="<?php echo get_the_date('c'); ?>"><?php echo get_the_date('j F Y'); ?></time>
        </div>
        <h1 class="entry-title"><?php the_title(); ?></h1>
        <?php if ( get_the_excerpt() ) : ?>
        <p class="rdrnl-edition-excerpt"><?php echo get_the_excerpt(); ?></p>
        <?php endif; ?>
    </header>

    <?php do_action( 'generate_after_entry_header' ); ?>

    <div class="entry-content rdrnl-edition-content">
        <?php the_content(); ?>
    </div>

    <?php do_action( 'generate_after_entry_content' ); ?>

    <footer class="entry-footer rdrnl-edition-footer">
        <div class="rdrnl-edition-subscribe">
            <p>Recevez ce récap chaque semaine directement dans votre boîte mail.</p>
            <?php echo do_shortcode('[rdrnl_subscribe title="" description="" style="inline"]'); ?>
        </div>
        <p class="rdrnl-edition-feed-link">
            <a href="<?php echo esc_url(home_url('/feed/rdrnl-editions/')); ?>" rel="nofollow">
                📡 S'abonner via RSS
            </a>
        </p>
    </footer>

    <?php do_action( 'generate_after_entry' ); ?>

</div>
</article>

<?php endwhile; ?>

<?php do_action( 'generate_after_main_content' ); ?>

<?php get_footer(); ?>
