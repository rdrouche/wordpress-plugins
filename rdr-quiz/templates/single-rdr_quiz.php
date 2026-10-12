<?php
/**
 * Template: page d'un quiz individuel
 * Pleine largeur, sans sidebar, centré — compatible GeneratePress
 */

add_filter('generate_sidebar_layout', fn() => 'no-sidebar');
add_filter('body_class', function(array $c): array {
    $c[] = 'rdr-fullwidth-page';
    return $c;
});

get_header(); ?>

<div class="site-content rdr-fullwidth-content" id="content">
    <div class="content-area" id="primary" style="width:100%;max-width:100%;float:none">
        <main class="site-main" id="main">
            <?php while (have_posts()) : the_post(); ?>
            <article id="post-<?php the_ID(); ?>" <?php post_class('rdr-quiz-page'); ?>>
                <div class="inside-article rdr-bare-article">
                    <div class="entry-content">

                        <!-- Breadcrumb -->
                        <nav class="rdr-breadcrumb" aria-label="Fil d'Ariane">
                            <a href="<?php echo esc_url(home_url('/')); ?>"><?php _e('Accueil', 'rdr-quiz'); ?></a>
                            <span aria-hidden="true">›</span>
                            <?php $archive_url = get_post_type_archive_link('rdr_quiz'); ?>
                            <?php if ($archive_url): ?>
                            <a href="<?php echo esc_url($archive_url); ?>"><?php _e('Quiz', 'rdr-quiz'); ?></a>
                            <span aria-hidden="true">›</span>
                            <?php endif; ?>
                            <span><?php the_title(); ?></span>
                        </nav>

                        <!-- Quiz principal -->
                        <?php echo do_shortcode('[rdr_quiz id="' . get_the_ID() . '"]'); ?>

                        <?php
                        // ── Tags des questions liées ───────────────────────────
                        // Option : masquer les tags (meta _rdr_quiz_hide_tags = 1)
                        // Permet d'utiliser les tags pour organiser sans les exposer
                        $hide_tags = get_post_meta(get_the_ID(), '_rdr_quiz_hide_tags', true);
                        $q_ids     = get_post_meta(get_the_ID(), '_rdr_quiz_questions', true) ?: [];
                        $all_tags  = [];
                        foreach ($q_ids as $qid) {
                            $q_tags = get_the_terms($qid, 'rdr_question_tag');
                            if ($q_tags && !is_wp_error($q_tags)) {
                                foreach ($q_tags as $t) $all_tags[$t->slug] = $t;
                            }
                        }
                        ?>

                        <?php if (!empty($all_tags)): ?>
                        <div class="rdr-quiz-tag-cloud <?php echo $hide_tags ? 'rdr-tags-hidden' : ''; ?>"
                             id="rdr-tag-cloud-<?php the_ID(); ?>"
                             data-hide="<?php echo $hide_tags ? '1' : '0'; ?>">
                            <div class="rdr-tag-cloud-header">
                                <span class="rdr-tag-cloud-label"><?php _e('Sujets abordés :', 'rdr-quiz'); ?></span>
                                <button type="button"
                                        class="rdr-tag-toggle-btn"
                                        data-target="rdr-tag-cloud-<?php the_ID(); ?>"
                                        aria-label="<?php esc_attr_e('Afficher / masquer les sujets', 'rdr-quiz'); ?>">
                                    <?php echo $hide_tags ? '👁 ' . __('Afficher', 'rdr-quiz') : '🙈 ' . __('Masquer', 'rdr-quiz'); ?>
                                </button>
                            </div>
                            <div class="rdr-tag-cloud-pills <?php echo $hide_tags ? 'rdr-tags-pills-hidden' : ''; ?>">
                                <?php foreach ($all_tags as $tag): ?>
                                <a href="<?php echo esc_url(add_query_arg('tag', $tag->slug, get_post_type_archive_link('rdr_quiz'))); ?>"
                                   class="rdr-tag-pill"><?php echo esc_html($tag->name); ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php // ── Catégories ──────────────────────────────────
                        $cats = get_the_terms(get_the_ID(), 'rdr_quiz_cat');
                        if ($cats && !is_wp_error($cats)): ?>
                        <div class="rdr-quiz-terms">
                            <span><?php _e('Catégorie :', 'rdr-quiz'); ?></span>
                            <?php foreach ($cats as $cat): ?>
                            <a href="<?php echo esc_url(get_term_link($cat)); ?>" class="rdr-quiz-term-link">
                                <?php echo esc_html($cat->name); ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php // ── Description longue (contenu éditeur optionnel)
                        if (get_the_content()): ?>
                        <div class="rdr-quiz-description"><?php the_content(); ?></div>
                        <?php endif; ?>

                    </div>
                </div>
            </article>
            <?php endwhile; ?>
        </main>
    </div>
</div>

<script>
// Toggle tags — inline pour éviter un fichier JS supplémentaire
(function() {
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.rdr-tag-toggle-btn');
        if (!btn) return;
        var cloud = document.getElementById(btn.dataset.target);
        if (!cloud) return;
        var pills  = cloud.querySelector('.rdr-tag-cloud-pills');
        var hidden = cloud.dataset.hide === '1';
        hidden = !hidden;
        cloud.dataset.hide = hidden ? '1' : '0';
        pills.classList.toggle('rdr-tags-pills-hidden', hidden);
        btn.innerHTML = hidden
            ? '👁 <?php echo esc_js(__('Afficher', 'rdr-quiz')); ?>'
            : '🙈 <?php echo esc_js(__('Masquer', 'rdr-quiz')); ?>';
    });
})();
</script>

<?php get_footer(); ?>
