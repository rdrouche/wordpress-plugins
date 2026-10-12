<?php
/**
 * Template : archive des fiches experts
 * Intégration GeneratePress (parent) — sidebar, hooks, structure identique au thème.
 *
 * Chargé via filter 'archive_template' dans rdr-expert-directory.php.
 * Priorité : thème enfant (archive-expert_profile.php) > plugin.
 *
 * Si l'archive WP native est utilisée plutôt qu'une page avec shortcode,
 * ce template prend le relai et affiche le shortcode [rdr_expert_directory]
 * dans la structure GP correcte avec sidebar.
 */
defined('ABSPATH') || exit;

get_header();

RDR_ED_GP_Compat::before_main_content();
?>

<div <?php RDR_ED_GP_Compat::do_attr('content'); ?>>
    <main <?php RDR_ED_GP_Compat::do_attr('main'); ?>>

        <?php RDR_ED_GP_Compat::before_content(); ?>

        <header class="page-header">
            <div class="inside-page-header">
                <h1 class="page-title">
                    <?php
                    $archive_title = post_type_archive_title('', false);
                    echo $archive_title ?: __('Annuaire des Experts IT', 'rdr-expert-directory');
                    ?>
                </h1>
                <?php
                $archive_desc = get_the_archive_description();
                if ($archive_desc) {
                    echo '<div class="archive-description">' . wp_kses_post($archive_desc) . '</div>';
                }
                ?>
            </div>
        </header>

        <div class="entry-content">
            <?php
            // Afficher le shortcode annuaire dans la structure GP
            // Récupère les paramètres depuis les settings
            $per_page = (int) RDR_ED_Settings::get('directory_per_page', 12);
            echo do_shortcode('[rdr_expert_directory per_page="' . $per_page . '" show_filters="true"]');
            ?>
        </div>

        <?php RDR_ED_GP_Compat::after_content(); ?>

    </main>
</div>

<?php
RDR_ED_GP_Compat::after_primary_content_area();
RDR_ED_GP_Compat::construct_sidebars();
RDR_ED_GP_Compat::after_main_content();
get_footer();
?>
