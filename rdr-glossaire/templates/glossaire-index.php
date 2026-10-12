<?php
/**
 * Template page index du glossaire.
 * Rendu SSR complet (toutes les définitions dans le HTML) + filtre AJAX par lettre / recherche.
 * Utilisé automatiquement par rdrg_template_redirect() pour l'URL /glossaire/.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

get_header();

// ── Récupérer TOUTES les définitions publiées (SSR) ────────────────────────
// Langue : depuis Polylang si actif, sinon depuis le query var de la rewrite rule
$lang = function_exists( 'pll_current_language' ) ? pll_current_language() : get_query_var( 'lang', '' );

$query_args = [
    'post_type'      => RDRG_CPT,
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
    'meta_query'     => [
        'relation' => 'OR',
        [ 'key' => '_rdrg_term_enabled', 'value' => '1', 'compare' => '=' ],
        [ 'key' => '_rdrg_term_enabled', 'compare' => 'NOT EXISTS' ],
    ],
];
if ( $lang ) $query_args['lang'] = $lang;

$all_terms = get_posts( $query_args );

// ── Grouper par première lettre ────────────────────────────────────────────
$by_letter = [];
foreach ( $all_terms as $term ) {
    $first = strtoupper( mb_substr( $term->post_title, 0, 1 ) );
    // Chiffres → groupe #
    if ( ! ctype_alpha( $first ) ) $first = '#';
    $by_letter[ $first ][] = $term;
}
ksort( $by_letter );
$letters_available = array_keys( $by_letter );

$total = count( $all_terms );
$page_title = __( 'Glossaire IT', 'rdr-glossaire' );
?>

<div class="site-content" id="content">
<div class="content-area" id="primary">
<main class="site-main rdrg-glossaire-page" id="main">

    <!-- ── En-tête ─────────────────────────────────── -->
    <div class="rdrg-page-hero">
        <div class="rdrg-page-hero-inner">
            <div class="rdrg-breadcrumb">
                <a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php _e( 'Accueil', 'rdr-glossaire' ); ?></a>
                <span>›</span>
                <span><?php echo esc_html( $page_title ); ?></span>
            </div>
            <h1><?php echo esc_html( $page_title ); ?></h1>
            <p class="rdrg-hero-sub">
                <?php printf(
                    _n( '%d définition de termes techniques IT.', '%d définitions de termes techniques IT.', $total, 'rdr-glossaire' ),
                    $total
                ); ?>
            </p>

            <!-- Recherche live -->
            <div class="rdrg-search-wrap">
                <input type="search"
                       id="rdrg-search"
                       class="rdrg-search-input"
                       placeholder="<?php esc_attr_e( 'Rechercher un terme… ex: DHCP, DNS, VLAN', 'rdr-glossaire' ); ?>"
                       autocomplete="off"
                       aria-label="<?php esc_attr_e( 'Rechercher dans le glossaire', 'rdr-glossaire' ); ?>">
                <span class="rdrg-search-icon">🔍</span>
                <span class="rdrg-search-count" id="rdrg-search-count" aria-live="polite"></span>
            </div>
        </div>
    </div>

    <!-- ── Navigation alphabétique ─────────────────── -->
    <nav class="rdrg-alpha-nav" aria-label="<?php esc_attr_e( 'Navigation alphabétique', 'rdr-glossaire' ); ?>">
        <div class="rdrg-alpha-inner">
            <button class="rdrg-alpha-btn active" data-letter="all"><?php _e( 'Tout', 'rdr-glossaire' ); ?></button>
            <?php
            $alphabet = array_merge( ['#'], range( 'A', 'Z' ) );
            foreach ( $alphabet as $l ) :
                $available = in_array( $l, $letters_available, true );
            ?>
            <button class="rdrg-alpha-btn<?php echo $available ? '' : ' rdrg-alpha-empty'; ?>"
                    data-letter="<?php echo esc_attr( $l ); ?>"
                    <?php echo $available ? '' : 'disabled aria-disabled="true"'; ?>>
                <?php echo esc_html( $l ); ?>
            </button>
            <?php endforeach; ?>
        </div>
    </nav>

    <!-- ── Contenu glossaire (SSR) ─────────────────── -->
    <div class="rdrg-glossaire-wrap">
        <div class="rdrg-glossaire-content" id="rdrg-glossaire-content">

            <?php if ( empty( $all_terms ) ) : ?>
                <p class="rdrg-empty"><?php _e( 'Aucune définition publiée pour le moment.', 'rdr-glossaire' ); ?></p>
            <?php else : ?>

                <?php foreach ( $by_letter as $letter => $terms ) : ?>
                <section class="rdrg-letter-section" data-letter="<?php echo esc_attr( $letter ); ?>" id="lettre-<?php echo esc_attr( $letter ); ?>">
                    <h2 class="rdrg-letter-heading"><?php echo esc_html( $letter ); ?></h2>
                    <div class="rdrg-terms-grid">
                        <?php foreach ( $terms as $term ) :
                            $aliases = get_post_meta( $term->ID, '_rdrg_aliases', true );
                            $link    = get_post_meta( $term->ID, '_rdrg_link', true );
                            $excerpt = $term->post_excerpt ?: wp_trim_words( wp_strip_all_tags( $term->post_content ), 40, '…' );
                            $url     = get_permalink( $term->ID );
                        ?>
                        <article class="rdrg-term-card"
                                 data-term="<?php echo esc_attr( strtolower( $term->post_title ) ); ?>"
                                 data-aliases="<?php echo esc_attr( strtolower( $aliases ) ); ?>">
                            <h3 class="rdrg-term-title">
                                <a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $term->post_title ); ?></a>
                            </h3>
                            <?php if ( $aliases ) : ?>
                            <p class="rdrg-term-aliases">
                                <?php echo esc_html( $aliases ); ?>
                            </p>
                            <?php endif; ?>
                            <p class="rdrg-term-excerpt"><?php echo esc_html( $excerpt ); ?></p>
                            <div class="rdrg-term-footer">
                                <a href="<?php echo esc_url( $url ); ?>" class="rdrg-term-read-more">
                                    <?php _e( 'Lire la définition complète →', 'rdr-glossaire' ); ?>
                                </a>
                                <?php if ( $link ) : ?>
                                <a href="<?php echo esc_url( $link ); ?>" class="rdrg-term-ext-link">
                                    <?php _e( 'Article →', 'rdr-glossaire' ); ?>
                                </a>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>

                <!-- Message "aucun résultat" pour la recherche -->
                <p class="rdrg-no-results" id="rdrg-no-results" hidden>
                    <?php _e( 'Aucun terme ne correspond à votre recherche.', 'rdr-glossaire' ); ?>
                </p>

            <?php endif; ?>
        </div>
    </div>

</main>
</div>
</div>

<?php get_footer(); ?>
