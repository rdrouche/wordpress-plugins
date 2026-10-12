<?php
/**
 * Template: archive quiz — pleine largeur, sans sidebar, centré
 * Override GeneratePress sidebar via hook avant get_header()
 */

// ── Supprimer la sidebar GeneratePress ──────────────────────────────────────
add_filter('generate_sidebar_layout',      fn() => 'no-sidebar');
add_filter('generate_footer_widgets',      fn() => 0);  // optionnel — garder le footer normal
add_filter('body_class', function(array $c): array {
    $c[] = 'rdr-fullwidth-page';
    return $c;
});

get_header(); ?>

<div class="site-content rdr-fullwidth-content" id="content">
    <div class="content-area" id="primary" style="width:100%;max-width:100%;float:none">
        <main class="site-main" id="main">
            <div class="inside-article rdr-bare-article">
                <div class="entry-content">

                    <div class="rdr-archive-header">
                        <h1 class="rdr-archive-title">🧠 <?php _e('Quiz & Tests de connaissances', 'rdr-quiz'); ?></h1>
                        <p class="rdr-archive-subtitle">
                            <?php _e('Testez vos connaissances en administration système, virtualisation, Linux et Docker.', 'rdr-quiz'); ?>
                        </p>
                    </div>

                    <?php echo do_shortcode('[rdr_quiz_list]'); ?>

                </div><!-- .entry-content -->
            </div>
        </main>
    </div>
</div>

<?php get_footer(); ?>
