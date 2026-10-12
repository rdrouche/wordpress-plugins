<?php
/**
 * Template : page détail d'un sondage (injecté via the_content)
 * Variables : $poll_id, $question, $type, $results_ok, $is_open, $has_voted, $choices
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$tree      = WPS_Public::build_tree( (array) $choices );
$show_form = $is_open && ! $has_voted;
$show_res  = ( $has_voted || ! $is_open ) && $results_ok;
$results   = $show_res ? WPS_Ajax::get_results_data( $poll_id ) : null;
$can_peek  = $results_ok && $is_open && ! $has_voted;

// Lien retour archive — résolu par langue via Polylang
$archive_id  = class_exists( 'WPS_Archive_Page' ) ? WPS_Archive_Page::get_archive_page_id() : 0;
$archive_url = $archive_id ? get_permalink( $archive_id ) : '';

// Chaînes traduisibles via Polylang Strings
$lbl_vote        = apply_filters( 'wps_btn_vote_label',  __( 'Voter',                'wp-sondage' ) );
$lbl_see_results = apply_filters( 'wps_btn_see_results', __( 'Voir les résultats',   'wp-sondage' ) );
$lbl_back_vote   = apply_filters( 'wps_btn_back_vote',   __( '← Revenir au vote',   'wp-sondage' ) );
$lbl_back_arch   = apply_filters( 'wps_back_to_archive_label', __( '← Tous les sondages', 'wp-sondage' ) );
$lbl_closed      = apply_filters( 'wps_notice_closed',   __( '🔒 Ce sondage est maintenant fermé.',             'wp-sondage' ) );
$lbl_voted       = apply_filters( 'wps_notice_voted',    __( '✅ Vous avez déjà répondu à ce sondage.',         'wp-sondage' ) );
$lbl_private     = apply_filters( 'wps_notice_private',  __( '🔒 Les résultats de ce sondage sont privés.',     'wp-sondage' ) );
$lbl_peek_notice = apply_filters( 'wps_peek_notice',     __( 'Aperçu des résultats — votre vote n\'a pas encore été comptabilisé.', 'wp-sondage' ) );
?>

<?php if ( $archive_url ) : ?>
<div class="wps-back-link-wrap">
    <a class="wps-back-link" href="<?php echo esc_url( $archive_url ); ?>">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
        <?php echo esc_html( $lbl_back_arch ); ?>
    </a>
</div>
<?php endif; ?>

<div class="wps-poll wps-poll-single wps-theme-auto"
     data-poll-id="<?php echo esc_attr( $poll_id ); ?>"
     data-type="<?php echo esc_attr( $type ); ?>"
     data-results-public="<?php echo $results_ok ? '1' : '0'; ?>"
     data-hydrate="1">

    <div class="wps-question">
        <?php echo esc_html( $question ?: get_the_title() ); ?>
    </div>

    <?php if ( ! $is_open ) : ?>
        <p class="wps-notice wps-closed"><?php echo esc_html( $lbl_closed ); ?></p>
    <?php endif; ?>

    <?php if ( $show_form ) : ?>
    <div class="wps-panel wps-panel-form">
        <form class="wps-form" data-poll-id="<?php echo esc_attr( $poll_id ); ?>">
            <div class="wps-choices">
                <?php WPS_Public::render_choice_tree( $tree, $type, 0 ); ?>
            </div>
            <p class="wps-msg" role="alert" aria-live="polite"></p>
            <div class="wps-form-actions">
                <button type="submit" class="wps-btn-vote"><?php echo esc_html( $lbl_vote ); ?></button>
                <span class="wps-spinner" aria-hidden="true"></span>
                <?php if ( $can_peek ) : ?>
                <button type="button" class="wps-btn-peek" data-poll-id="<?php echo esc_attr( $poll_id ); ?>">
                    <?php echo esc_html( $lbl_see_results ); ?>
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <?php if ( $has_voted && $is_open ) : ?>
        <p class="wps-notice wps-voted"><?php echo esc_html( $lbl_voted ); ?></p>
    <?php endif; ?>

    <?php if ( $show_res && $results ) : ?>
    <div class="wps-panel wps-panel-results">
        <?php WPS_Public::render_results_html( $results ); ?>
    </div>
    <?php elseif ( ( $has_voted || ! $is_open ) && ! $results_ok ) : ?>
        <p class="wps-notice"><?php echo esc_html( $lbl_private ); ?></p>
    <?php endif; ?>

    <?php if ( $can_peek ) : ?>
    <div class="wps-panel wps-panel-peek" style="display:none" data-poll-id="<?php echo esc_attr( $poll_id ); ?>">
        <p class="wps-peek-notice"><?php echo esc_html( $lbl_peek_notice ); ?></p>
        <button type="button" class="wps-btn-back-vote" data-poll-id="<?php echo esc_attr( $poll_id ); ?>">
            <?php echo esc_html( $lbl_back_vote ); ?>
        </button>
    </div>
    <?php endif; ?>

    <?php if ( current_user_can( 'manage_options' ) ) : ?>
    <div class="wps-admin-actions">
        <button type="button" class="wps-btn-reset" data-poll-id="<?php echo esc_attr( $poll_id ); ?>">
            <?php _e( 'Réinitialiser les votes', 'wp-sondage' ); ?>
        </button>
    </div>
    <?php endif; ?>

</div>
