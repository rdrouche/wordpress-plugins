<?php
/**
 * Template : page "Mon profil Expert" (formulaire profil front)
 * Intégration GeneratePress — sidebar disponible.
 *
 * Chargé via page-mon-profil-expert.php dans le thème enfant,
 * ou via le shortcode [rdr_expert_profile_form] dans une page WP normale.
 *
 * Ce template est optionnel : si l'admin utilise simplement la page WP
 * avec le shortcode, ça marche sans ce fichier.
 * Ce fichier est utile si on veut contrôler la mise en page GP
 * (ex: forcer no-sidebar sur cette page depuis le thème enfant).
 */
defined('ABSPATH') || exit;

get_header();

RDR_ED_GP_Compat::before_main_content();
?>

<div <?php RDR_ED_GP_Compat::do_attr('content'); ?>>
    <main <?php RDR_ED_GP_Compat::do_attr('main'); ?>>

        <?php RDR_ED_GP_Compat::before_content(); ?>

        <?php while (have_posts()) : the_post(); ?>

        <article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>

            <?php RDR_ED_GP_Compat::before_entry_title(); ?>

            <header class="entry-header">
                <?php the_title('<h1 class="entry-title">', '</h1>'); ?>
            </header>

            <?php RDR_ED_GP_Compat::after_entry_title(); ?>

            <div class="entry-content">
                <?php
                // Contenu de la page (le shortcode [rdr_expert_profile_form] est ici)
                the_content();
                ?>
            </div>

            <?php RDR_ED_GP_Compat::after_entry_content(); ?>

        </article>

        <?php endwhile; ?>

        <?php RDR_ED_GP_Compat::after_content(); ?>

    </main>
</div>

<?php
RDR_ED_GP_Compat::after_primary_content_area();
RDR_ED_GP_Compat::construct_sidebars();
RDR_ED_GP_Compat::after_main_content();
get_footer();
?>
