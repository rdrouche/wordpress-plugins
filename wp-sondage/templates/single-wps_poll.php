<?php
/**
 * Template : single-wps_poll.php
 * Copier ce fichier dans votre thème enfant pour personnaliser l'affichage.
 * Le plugin injecte automatiquement le sondage via the_content si ce template n'existe pas dans le thème.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header(); ?>

<main id="wps-single-main">
    <?php while ( have_posts() ) : the_post(); ?>
    <article id="post-<?php the_ID(); ?>" <?php post_class( 'wps-single-article' ); ?>>

        <header class="wps-single-header">
            <h1 class="wps-single-title"><?php the_title(); ?></h1>

            <?php
            $cats = get_the_terms( get_the_ID(), 'wps_category' );
            if ( $cats && ! is_wp_error( $cats ) ) :
            ?>
            <div class="wps-single-cats">
                <?php foreach ( $cats as $cat ) : ?>
                <a href="<?php echo esc_url( get_term_link( $cat ) ); ?>" class="wps-cat-badge">
                    <?php echo esc_html( $cat->name ); ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </header>

        <?php
        // Description (contenu de l'éditeur)
        $content = get_the_content();
        if ( $content ) :
        ?>
        <div class="wps-single-description entry-content">
            <?php the_content(); ?>
        </div>
        <?php endif; ?>

        <?php
        // Rendu du sondage
        WPS_Public::render_poll_single( get_the_ID() );
        ?>

    </article>
    <?php endwhile; ?>
</main>

<style>
.wps-single-article   { max-width: 720px; margin: 2rem auto; padding: 0 1rem; }
.wps-single-header    { margin-bottom: 1.5rem; }
.wps-single-title     { font-size: 1.75rem; margin-bottom: .5rem; }
.wps-single-cats      { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .5rem; }
.wps-cat-badge {
    padding: .2rem .7rem;
    border-radius: 99px;
    background: rgba(13,110,253,.1);
    color: #0d6efd;
    font-size: .8rem;
    text-decoration: none;
    font-weight: 500;
}
.wps-cat-badge:hover { background: rgba(13,110,253,.2); }
.wps-single-description { margin-bottom: 1.5rem; line-height: 1.7; }
</style>

<?php get_footer(); ?>
