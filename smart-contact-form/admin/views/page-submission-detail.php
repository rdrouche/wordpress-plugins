<?php
/**
 * Submission detail view.
 *
 * Variables:
 *   $sub          object   DB row
 *   $fields_data  array    Decoded JSON fields
 *   $form_fields  array    Form field definitions
 *   $reply_log    array    Previous replies
 *   $notice       string
 */
defined( 'ABSPATH' ) || exit;

$status_labels = [
    'new'     => __( 'New', 'smart-contact-form' ),
    'read'    => __( 'Read', 'smart-contact-form' ),
    'replied' => __( 'Replied', 'smart-contact-form' ),
    'spam'    => __( 'Spam', 'smart-contact-form' ),
];
$badge_colors = [
    'new'     => '#2563eb',
    'read'    => '#6b7280',
    'replied' => '#16a34a',
    'spam'    => '#dc2626',
];

$back_url   = admin_url( 'admin.php?page=scf-submissions' );
$nonce      = wp_create_nonce( 'scf_submission_' . $sub->id );
$to_email   = $fields_data['scf_email'] ?? '';
$to_name    = $fields_data['scf_name'] ?? '';
$form_title = get_the_title( $sub->form_id ) ?: '#' . $sub->form_id;

// Build label map from form field definitions
$label_map = [];
foreach ( $form_fields as $f ) {
    $label_map[ $f['name'] ] = $f['label'];
}

// Default reply subject
$original_subject = $fields_data['scf_subject'] ?? '';
$default_re       = $original_subject ? 'Re: ' . $original_subject : sprintf( __( 'Re: Your message on %s', 'smart-contact-form' ), get_option( 'blogname' ) );
?>
<div class="wrap scf-detail-wrap">

    <h1>
        <a href="<?php echo esc_url( $back_url ); ?>" class="scf-back-link">← <?php esc_html_e( 'Submissions', 'smart-contact-form' ); ?></a>
        <?php
        /* translators: %d: submission ID */
        printf( esc_html__( 'Submission #%d', 'smart-contact-form' ), (int) $sub->id );
        ?>
        <span class="scf-badge" style="background:<?php echo esc_attr( $badge_colors[ $sub->status ] ?? '#6b7280' ); ?>;vertical-align:middle;font-size:.65em;margin-left:8px;">
            <?php echo esc_html( $status_labels[ $sub->status ] ?? $sub->status ); ?>
        </span>
    </h1>
    <hr class="wp-header-end">

    <?php if ( $notice === 'replied' ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Reply sent successfully.', 'smart-contact-form' ); ?></p></div>
    <?php endif; ?>

    <div class="scf-detail-grid">

        <!-- ===== LEFT: Message + Reply ===== -->
        <div class="scf-detail-main">

            <!-- Message card -->
            <div class="scf-card">
                <div class="scf-card__header">
                    <span class="dashicons dashicons-email-alt"></span>
                    <?php esc_html_e( 'Message', 'smart-contact-form' ); ?>
                </div>
                <div class="scf-card__body">
                    <table class="scf-detail-table">
                        <?php foreach ( $fields_data as $key => $val ) :
                            $label = $label_map[ $key ] ?? $key;
                        ?>
                        <tr>
                            <th><?php echo esc_html( $label ); ?></th>
                            <td><?php echo nl2br( esc_html( $val ) ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </div>

            <!-- Reply card -->
            <?php if ( is_email( $to_email ) ) : ?>
            <div class="scf-card">
                <div class="scf-card__header">
                    <span class="dashicons dashicons-editor-help"></span>
                    <?php
                    printf(
                        /* translators: %s: recipient name and email */
                        esc_html__( 'Reply to %s', 'smart-contact-form' ),
                        '<strong>' . esc_html( $to_name ) . '</strong> &lt;' . esc_html( $to_email ) . '&gt;'
                    );
                    ?>
                </div>
                <div class="scf-card__body">
                    <form method="post" action="">
                        <?php wp_nonce_field( 'scf_reply_' . $sub->id, 'scf_reply_nonce' ); ?>
                        <input type="hidden" name="sub_id" value="<?php echo esc_attr( $sub->id ); ?>">

                        <p>
                            <label for="reply_subject"><strong><?php esc_html_e( 'Subject', 'smart-contact-form' ); ?></strong></label><br>
                            <input type="text" id="reply_subject" name="reply_subject"
                                   value="<?php echo esc_attr( $default_re ); ?>"
                                   class="large-text" required>
                        </p>
                        <p>
                            <label for="reply_body"><strong><?php esc_html_e( 'Message', 'smart-contact-form' ); ?></strong></label><br>
                            <textarea id="reply_body" name="reply_body" rows="8" class="large-text" required
                                      placeholder="<?php esc_attr_e( 'Write your reply here…', 'smart-contact-form' ); ?>"></textarea>
                        </p>
                        <p class="scf-reply-footer">
                            <button type="submit" class="button button-primary">
                                ✉ <?php esc_html_e( 'Send Reply', 'smart-contact-form' ); ?>
                            </button>
                            <span class="scf-reply-hint">
                                <?php
                                printf(
                                    /* translators: %s: from name and email */
                                    esc_html__( 'Sent from: %s', 'smart-contact-form' ),
                                    esc_html( get_option( 'scf_from_name' ) . ' <' . get_option( 'scf_from_email' ) . '>' )
                                );
                                ?>
                            </span>
                        </p>
                    </form>
                </div>
            </div>
            <?php else : ?>
            <div class="scf-card">
                <div class="scf-card__body">
                    <p style="color:#6b7280;"><em><?php esc_html_e( 'No email address found — cannot send a reply.', 'smart-contact-form' ); ?></em></p>
                </div>
            </div>
            <?php endif; ?>

            <!-- Reply history -->
            <?php if ( ! empty( $reply_log ) ) : ?>
            <div class="scf-card">
                <div class="scf-card__header">
                    <span class="dashicons dashicons-backup"></span>
                    <?php esc_html_e( 'Reply History', 'smart-contact-form' ); ?>
                    <span class="scf-count-badge"><?php echo count( $reply_log ); ?></span>
                </div>
                <div class="scf-card__body">
                    <?php foreach ( array_reverse( $reply_log ) as $r ) : ?>
                    <div class="scf-reply-log-entry">
                        <div class="scf-reply-log-meta">
                            <strong><?php echo esc_html( $r['subject'] ); ?></strong>
                            <span>— <?php echo esc_html( $r['by'] ); ?>, <?php echo esc_html( $r['date'] ); ?></span>
                        </div>
                        <div class="scf-reply-log-body"><?php echo nl2br( esc_html( $r['body'] ) ); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /.scf-detail-main -->

        <!-- ===== RIGHT: Meta sidebar ===== -->
        <div class="scf-detail-sidebar">

            <!-- Quick actions -->
            <div class="scf-card">
                <div class="scf-card__header"><?php esc_html_e( 'Actions', 'smart-contact-form' ); ?></div>
                <div class="scf-card__body scf-sidebar-actions">
                    <?php if ( 'new' === $sub->status ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions&action=mark_read&id=' . $sub->id . '&ref=detail&_wpnonce=' . $nonce ) ); ?>"
                           class="button button-secondary scf-full-btn">✓ <?php esc_html_e( 'Mark as Read', 'smart-contact-form' ); ?></a>
                    <?php elseif ( in_array( $sub->status, [ 'read', 'replied' ], true ) ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions&action=mark_new&id=' . $sub->id . '&ref=detail&_wpnonce=' . $nonce ) ); ?>"
                           class="button button-secondary scf-full-btn">↩ <?php esc_html_e( 'Mark as Unread', 'smart-contact-form' ); ?></a>
                    <?php endif; ?>

                    <?php if ( 'spam' !== $sub->status ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions&action=mark_spam&id=' . $sub->id . '&ref=detail&_wpnonce=' . $nonce ) ); ?>"
                           class="button button-secondary scf-full-btn scf-btn-spam">🚫 <?php esc_html_e( 'Mark as Spam', 'smart-contact-form' ); ?></a>
                    <?php else : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions&action=mark_ham&id=' . $sub->id . '&ref=detail&_wpnonce=' . $nonce ) ); ?>"
                           class="button button-secondary scf-full-btn">✅ <?php esc_html_e( 'Not Spam', 'smart-contact-form' ); ?></a>
                    <?php endif; ?>

                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions&action=delete&id=' . $sub->id . '&_wpnonce=' . $nonce ) ); ?>"
                       class="button button-secondary scf-full-btn scf-btn-delete"
                       onclick="return confirm('<?php esc_attr_e( 'Delete this submission?', 'smart-contact-form' ); ?>');">
                        🗑 <?php esc_html_e( 'Delete', 'smart-contact-form' ); ?>
                    </a>
                </div>
            </div>

            <!-- Meta info -->
            <div class="scf-card">
                <div class="scf-card__header"><?php esc_html_e( 'Details', 'smart-contact-form' ); ?></div>
                <div class="scf-card__body">
                    <table class="scf-meta-table">
                        <tr><th><?php esc_html_e( 'Form', 'smart-contact-form' ); ?></th><td><?php echo esc_html( $form_title ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'Date', 'smart-contact-form' ); ?></th><td><?php echo esc_html( get_date_from_gmt( $sub->created_at, get_option('date_format') . ' ' . get_option('time_format') ) ); ?></td></tr>
                        <tr><th><?php esc_html_e( 'IP', 'smart-contact-form' ); ?></th><td><code><?php echo esc_html( $sub->ip ); ?></code></td></tr>
                        <?php if ( $sub->lang ) : ?>
                        <tr><th><?php esc_html_e( 'Language', 'smart-contact-form' ); ?></th><td><?php echo esc_html( strtoupper( $sub->lang ) ); ?></td></tr>
                        <?php endif; ?>
                        <tr>
                            <th><?php esc_html_e( 'Spam Score', 'smart-contact-form' ); ?></th>
                            <td>
                                <?php
                                $score = (int) $sub->spam_score;
                                $sc    = $score >= 60 ? '#dc2626' : ( $score >= 30 ? '#d97706' : '#16a34a' );
                                ?>
                                <span style="color:<?php echo esc_attr( $sc ); ?>;font-weight:700;"><?php echo esc_html( $score ); ?>/100</span>
                            </td>
                        </tr>
                        <tr><th><?php esc_html_e( 'User Agent', 'smart-contact-form' ); ?></th><td style="font-size:.8em;word-break:break-all;"><?php echo esc_html( $sub->user_agent ); ?></td></tr>
                    </table>
                </div>
            </div>

        </div><!-- /.scf-detail-sidebar -->
    </div><!-- /.scf-detail-grid -->
</div>
