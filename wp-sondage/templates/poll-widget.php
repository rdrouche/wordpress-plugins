<?php
/**
 * Template : widget shortcode (version compacte)
 * Variables : $poll_id, $question, $type, $results_ok, $is_open, $has_voted, $choices
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$tree      = WPS_Public::build_tree( (array) $choices );
$show_form = $is_open && ! $has_voted;
$show_res  = ( $has_voted || ! $is_open ) && $results_ok;
$results   = $show_res ? WPS_Ajax::get_results_data( $poll_id ) : null;
$can_peek  = $results_ok && $is_open && ! $has_voted;

// Chaînes traduisibles via Polylang Strings
$lbl_vote        = apply_filters( 'wps_btn_vote_label',  __( 'Voter',              'wp-sondage' ) );
$lbl_see_results = apply_filters( 'wps_btn_see_results', __( 'Voir les résultats', 'wp-sondage' ) );
$lbl_see_detail  = apply_filters( 'wps_btn_see_detail',  __( 'Voir le détail →',  'wp-sondage' ) );
$lbl_back_vote   = apply_filters( 'wps_btn_back_vote',   __( '← Revenir au vote', 'wp-sondage' ) );
$lbl_closed      = apply_filters( 'wps_notice_closed',   __( '🔒 Sondage fermé',  'wp-sondage' ) );
$lbl_voted       = apply_filters( 'wps_notice_voted',    __( '✅ Déjà répondu',   'wp-sondage' ) );
$lbl_peek_notice = apply_filters( 'wps_peek_notice',     __( 'Aperçu — vote non comptabilisé', 'wp-sondage' ) );
?>
<div class="wps-poll wps-poll-widget wps-theme-auto"
     data-poll-id="<?php echo esc_attr( $poll_id ); ?>"
     data-type="<?php echo esc_attr( $type ); ?>"
     data-results-public="<?php echo $results_ok ? '1' : '0'; ?>">

    <div class="wps-question"><?php echo esc_html( $question ); ?></div>

    <?php if ( ! $is_open ) : ?>
        <p class="wps-notice wps-closed"><?php echo esc_html( $lbl_closed ); ?></p>
    <?php endif; ?>

    <!-- Formulaire -->
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

    <!-- Résultats (après vote ou fermé) -->
    <?php if ( $show_res && $results ) : ?>
    <div class="wps-panel wps-panel-results">
        <div class="wps-results" data-total="<?php echo (int) $results['total']; ?>">
            <p class="wps-total">
                <?php printf( _n( '%d vote', '%d votes', $results['total'], 'wp-sondage' ), $results['total'] ); ?>
            </p>
            <?php
            $top_choices = array_filter( $results['choices'], fn( $c ) => $c['parent_id'] === 0 );
            foreach ( $top_choices as $choice ) :
                $label = wps_choice_label( $choice['label'], $choice['id'] );
                $pct   = (float) $choice['percent'];
            ?>
            <div class="wps-result-row">
                <div class="wps-result-label"><?php echo esc_html( $label ); ?></div>
                <div class="wps-result-bar-wrap">
                    <div class="wps-result-bar wps-bar-animate" data-pct="<?php echo $pct; ?>" style="width:0%"></div>
                </div>
                <div class="wps-result-pct"><?php echo $pct; ?>%</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Résultats en aperçu (non-votant) -->
    <?php if ( $can_peek ) : ?>
    <div class="wps-panel wps-panel-peek" style="display:none" data-poll-id="<?php echo esc_attr( $poll_id ); ?>">
        <p class="wps-peek-notice"><?php echo esc_html( $lbl_peek_notice ); ?></p>
        <button type="button" class="wps-btn-back-vote" data-poll-id="<?php echo esc_attr( $poll_id ); ?>">
            ← <?php _e( 'Revenir au vote', 'wp-sondage' ); ?>
        </button>
    </div>
    <?php endif; ?>

    <?php if ( $results_ok && ( $has_voted || ! $is_open ) && ! $results ) : ?>
        <p class="wps-notice"><?php _e( '🔒 Résultats privés.', 'wp-sondage' ); ?></p>
    <?php endif; ?>

    <?php $permalink = get_permalink( $poll_id ); if ( $permalink ) : ?>
    <a class="wps-more-link" href="<?php echo esc_url( $permalink ); ?>">
        <?php echo esc_html( $lbl_see_detail ); ?>
    </a>
    <?php endif; ?>

</div>
