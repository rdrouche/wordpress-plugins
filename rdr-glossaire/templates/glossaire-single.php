<?php
/**
 * Template page individuelle d'un terme du glossaire.
 * Intégré au layout GeneratePress natif via generate_do_template_part.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

global $post;

get_header();

$term_id  = $post->ID;
$title    = get_the_title();
$aliases  = get_post_meta( $term_id, '_rdrg_aliases', true );
$link     = get_post_meta( $term_id, '_rdrg_link', true );
$content  = apply_filters( 'the_content', get_the_content() );
$excerpt  = rdrg_term_excerpt( $post, 50 );

// Navigation prev/next ordre alphabétique
$lang = function_exists( 'pll_current_language' ) ? pll_current_language() : '';
$siblings_args = [
    'post_type'      => RDRG_CPT,
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
    'fields'         => 'ids',
    'meta_query'     => [
        'relation' => 'OR',
        [ 'key' => '_rdrg_term_enabled', 'value' => '1' ],
        [ 'key' => '_rdrg_term_enabled', 'compare' => 'NOT EXISTS' ],
    ],
];
if ( $lang ) $siblings_args['lang'] = $lang;
$all_ids = get_posts( $siblings_args );

$current_pos = array_search( $term_id, $all_ids, true );
$prev_id = $current_pos > 0 ? $all_ids[ $current_pos - 1 ] : null;
$next_id = $current_pos < count( $all_ids ) - 1 ? $all_ids[ $current_pos + 1 ] : null;

$glossaire_url = home_url( '/glossaire/' );
?>

<?php do_action( 'generate_before_main_content' ); ?>

<main <?php generate_do_attr( 'content' ); ?>>
    <?php do_action( 'generate_before_content' ); ?>

    <article <?php post_class(); ?> id="post-<?php the_ID(); ?>">
        <div class="inside-article">

            <!-- Breadcrumb -->
            <nav class="rdrg-breadcrumb" aria-label="Fil d'Ariane">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php _e( 'Accueil', 'rdr-glossaire' ); ?></a>
                <span>›</span>
                <a href="<?php echo esc_url( $glossaire_url ); ?>"><?php _e( 'Glossaire', 'rdr-glossaire' ); ?></a>
                <span>›</span>
                <span><?php echo esc_html( $title ); ?></span>
            </nav>

            <!-- En-tête -->
            <header class="entry-header rdrg-single-header">
                <div class="rdrg-single-letter-badge"><?php echo esc_html( strtoupper( mb_substr( $title, 0, 1 ) ) ); ?></div>
                <div>
                    <h1 class="entry-title rdrg-single-title"><?php echo esc_html( $title ); ?></h1>
                    <?php if ( $aliases ) : ?>
                    <p class="rdrg-single-aliases">
                        <span><?php _e( 'Alias :', 'rdr-glossaire' ); ?></span>
                        <?php echo esc_html( $aliases ); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </header>

            <div class="entry-content">

                <!-- Extrait mis en valeur -->
                <?php if ( $excerpt ) : ?>
                <div class="rdrg-single-excerpt">
                    <blockquote><?php echo esc_html( $excerpt ); ?></blockquote>
                </div>
                <?php endif; ?>

                <!-- Contenu complet -->
                <div class="rdrg-single-content">
                    <?php echo $content; ?>
                </div>

                <!-- Article associé -->
                <?php if ( $link ) : ?>
                <div class="rdrg-single-linked-article">
                    <span class="rdrg-link-icon">📄</span>
                    <div>
                        <strong><?php _e( 'Article associé', 'rdr-glossaire' ); ?></strong>
                        <a href="<?php echo esc_url( $link ); ?>" rel="noopener"><?php echo esc_url( $link ); ?></a>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Navigation prev/next -->
                <?php if ( $prev_id || $next_id ) : ?>
                <nav class="rdrg-term-nav" aria-label="<?php esc_attr_e( 'Termes précédent et suivant', 'rdr-glossaire' ); ?>">
                    <div class="rdrg-term-nav-inner">
                        <?php if ( $prev_id ) : ?>
                        <a href="<?php echo esc_url( get_permalink( $prev_id ) ); ?>" class="rdrg-nav-prev">
                            <span>←</span><span><?php echo esc_html( get_the_title( $prev_id ) ); ?></span>
                        </a>
                        <?php else : ?><span></span><?php endif; ?>

                        <a href="<?php echo esc_url( $glossaire_url ); ?>" class="rdrg-nav-index">☰ Index</a>

                        <?php if ( $next_id ) : ?>
                        <a href="<?php echo esc_url( get_permalink( $next_id ) ); ?>" class="rdrg-nav-next">
                            <span><?php echo esc_html( get_the_title( $next_id ) ); ?></span><span>→</span>
                        </a>
                        <?php else : ?><span></span><?php endif; ?>
                    </div>
                </nav>
                <?php endif; ?>

            </div><!-- .entry-content -->

        </div><!-- .inside-article -->
    </article>

    <?php do_action( 'generate_after_content' ); ?>
</main>

<?php
do_action( 'generate_after_main_content' );
get_sidebar();
get_footer();
