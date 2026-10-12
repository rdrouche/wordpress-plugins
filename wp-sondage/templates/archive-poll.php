<?php
/**
 * Template archive : liste des sondages actifs — version améliorée
 */
if ( ! defined( 'ABSPATH' ) ) exit;
get_header();

global $wpdb;

$total_polls = (int) wp_count_posts( 'wps_poll' )->publish;
$total_votes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}wps_votes" );
$open_polls  = 0;
$all_ids     = get_posts( [ 'post_type' => 'wps_poll', 'post_status' => 'publish', 'posts_per_page' => -1, 'meta_query' => [ [ 'key' => '_wps_active', 'value' => '1' ] ], 'fields' => 'ids' ] );
foreach ( $all_ids as $id ) { if ( WPS_Ajax::poll_is_open( $id ) ) $open_polls++; }

$paged = get_query_var( 'paged', 1 );
$sort  = sanitize_key( $_GET['sort'] ?? 'date' );
$args  = [
    'post_type'      => 'wps_poll',
    'post_status'    => 'publish',
    'posts_per_page' => 12,
    'paged'          => $paged,
    'meta_query'     => [ [ 'key' => '_wps_active', 'value' => '1' ] ],
    'orderby'        => $sort === 'alpha' ? 'title' : 'date',
    'order'          => $sort === 'alpha' ? 'ASC' : 'DESC',
];
if ( is_tax( 'wps_category' ) ) {
    $args['tax_query'] = [ [ 'taxonomy' => 'wps_category', 'field' => 'slug', 'terms' => get_queried_object()->slug ] ];
}
$polls = new WP_Query( $args );
$cats  = get_terms( [ 'taxonomy' => 'wps_category', 'hide_empty' => true ] );
?>
<main class="wps-archive-wrap">

    <div class="wps-archive-hero">
        <div class="wps-archive-hero-stat"><strong><?php echo $total_polls; ?></strong><span><?php _e( 'Sondages', 'wp-sondage' ); ?></span></div>
        <div class="wps-archive-hero-stat"><strong><?php echo $open_polls; ?></strong><span><?php _e( 'Ouverts', 'wp-sondage' ); ?></span></div>
        <div class="wps-archive-hero-stat"><strong><?php echo number_format( $total_votes ); ?></strong><span><?php _e( 'Votes', 'wp-sondage' ); ?></span></div>
        <h1 style="flex:1 1 100%;margin:.75rem 0 0;font-size:1.5rem">
            <?php is_tax( 'wps_category' ) ? printf( __( 'Sondages — %s', 'wp-sondage' ), single_term_title( '', false ) ) : _e( 'Tous les sondages', 'wp-sondage' ); ?>
        </h1>
    </div>

    <div class="wps-archive-toolbar">
        <input type="search" id="wps-search" class="wps-archive-search"
               placeholder="<?php esc_attr_e( 'Rechercher…', 'wp-sondage' ); ?>">
        <select class="wps-archive-sort" id="wps-sort">
            <option value="date"  <?php selected($sort,'date'); ?>><?php _e('Plus récents','wp-sondage'); ?></option>
            <option value="alpha" <?php selected($sort,'alpha'); ?>><?php _e('A → Z','wp-sondage'); ?></option>
            <option value="votes" <?php selected($sort,'votes'); ?>><?php _e('Plus votés','wp-sondage'); ?></option>
        </select>
    </div>

    <?php if ( $cats && ! is_wp_error( $cats ) ) : ?>
    <nav class="wps-cat-nav">
        <a href="<?php echo get_post_type_archive_link('wps_poll'); ?>" class="wps-cat-link <?php echo ! is_tax() ? 'wps-cat-link--active' : ''; ?>"><?php _e('Tous','wp-sondage'); ?></a>
        <?php foreach ( $cats as $cat ) : ?>
        <a href="<?php echo esc_url(get_term_link($cat)); ?>" class="wps-cat-link <?php echo is_tax('wps_category',$cat->term_id) ? 'wps-cat-link--active' : ''; ?>">
            <?php echo esc_html($cat->name); ?> <span class="wps-cat-count"><?php echo (int)$cat->count; ?></span>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>

    <div class="wps-polls-grid" id="wps-polls-grid">
    <?php if ( $polls->have_posts() ) :
        while ( $polls->have_posts() ) : $polls->the_post();
            $id       = get_the_ID();
            $question = get_post_meta( $id, '_wps_question', true ) ?: get_the_title();
            $type     = get_post_meta( $id, '_wps_type', true );
            $is_open  = WPS_Ajax::poll_is_open( $id );
            $voted    = false; // état perso : jamais au rendu (cache)
            $end_date = get_post_meta( $id, '_wps_end_date', true );
            $votes    = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wps_votes WHERE poll_id=%d", $id) );
            $cats_p   = get_the_terms( $id, 'wps_category' );
    ?>
    <article class="wps-poll-card <?php echo !$is_open ? 'wps-card-closed' : ''; ?>"
             data-poll-id="<?php echo $id; ?>"
             data-question="<?php echo esc_attr(strtolower($question)); ?>"
             data-votes="<?php echo $votes; ?>">
        <a href="<?php the_permalink(); ?>" class="wps-card-link">
            <div class="wps-card-question"><?php echo esc_html($question); ?></div>
            <div class="wps-card-progress"><div class="wps-card-progress-fill" data-votes="<?php echo $votes; ?>" style="width:0%"></div></div>
            <div class="wps-card-meta">
                <span title="<?php echo $type==='checkbox' ? esc_attr__('Choix multiples','wp-sondage') : esc_attr__('Choix unique','wp-sondage'); ?>"><?php echo $type==='checkbox' ? '☑' : '◉'; ?></span>
                <span class="wps-card-votes"><?php printf(_n('%d vote','%d votes',$votes,'wp-sondage'),$votes); ?></span>
                <?php if ($end_date && strtotime($end_date)>time()) : ?>
                <span>📅 <?php echo date_i18n(get_option('date_format'),strtotime($end_date)); ?></span>
                <?php endif; ?>
                <?php if (!$is_open) : ?><span class="wps-badge-closed"><?php _e('Fermé','wp-sondage'); ?></span>
                <?php elseif ($voted) : ?><span class="wps-badge-voted">✅ <?php _e('Répondu','wp-sondage'); ?></span><?php endif; ?>
            </div>
        </a>
        <?php if ($cats_p && !is_wp_error($cats_p)) : ?>
        <div class="wps-card-cats">
            <?php foreach ($cats_p as $cat) : ?><a href="<?php echo esc_url(get_term_link($cat)); ?>" class="wps-card-cat-badge"><?php echo esc_html($cat->name); ?></a><?php endforeach; ?>
        </div>
        <?php endif; ?>
    </article>
    <?php endwhile; wp_reset_postdata();
    else : ?>
        <div class="wps-empty-state" style="grid-column:1/-1">
            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            <?php _e('Aucun sondage disponible.','wp-sondage'); ?>
        </div>
    <?php endif; ?>
    </div>

    <?php if ($polls->max_num_pages > 1) : ?>
    <div class="wps-pagination">
        <?php echo paginate_links(['total'=>$polls->max_num_pages,'current'=>$paged,'prev_text'=>'← '.__('Précédent','wp-sondage'),'next_text'=>__('Suivant','wp-sondage').' →']); ?>
    </div>
    <?php endif; ?>
</main>

<script>
(function(){
    var cards = document.querySelectorAll('.wps-poll-card');
    var maxV  = 0;
    cards.forEach(function(c){var v=parseInt(c.dataset.votes,10)||0; if(v>maxV)maxV=v;});
    if(maxV>0){cards.forEach(function(c){var b=c.querySelector('.wps-card-progress-fill');if(!b)return;var p=Math.round((parseInt(b.dataset.votes,10)||0)/maxV*100);setTimeout(function(){b.style.width=p+'%';},120);});}

    var search=document.getElementById('wps-search');
    if(search){search.addEventListener('input',function(){var q=this.value.toLowerCase().trim();cards.forEach(function(c){c.classList.toggle('wps-hidden',q&&!(c.dataset.question||'').includes(q));});});}

    var sortSel=document.getElementById('wps-sort');
    if(sortSel){sortSel.addEventListener('change',function(){var u=new URL(window.location.href);u.searchParams.set('sort',this.value);u.searchParams.delete('paged');window.location.href=u.toString();});}
})();
</script>
<?php get_footer(); ?>
