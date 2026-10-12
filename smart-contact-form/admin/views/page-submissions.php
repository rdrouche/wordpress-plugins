<?php
/**
 * Submissions list view.
 * Variables: $rows, $total, $per_page, $page, $counts, $filter_status, $notice
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
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Submissions', 'smart-contact-form' ); ?></h1>
    <hr class="wp-header-end">

    <?php
    $notice_map = [
        'deleted' => [ 'success', __( 'Submission deleted.', 'smart-contact-form' ) ],
        'updated' => [ 'success', __( 'Status updated.', 'smart-contact-form' ) ],
        'replied' => [ 'success', __( 'Reply sent successfully.', 'smart-contact-form' ) ],
    ];
    if ( $notice && isset( $notice_map[ $notice ] ) ) :
    ?>
        <div class="notice notice-<?php echo esc_attr( $notice_map[ $notice ][0] ); ?> is-dismissible">
            <p><?php echo esc_html( $notice_map[ $notice ][1] ); ?></p>
        </div>
    <?php endif; ?>

    <ul class="subsubsub">
        <li>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions' ) ); ?>"
               <?php echo ( '' === $filter_status ) ? 'class="current"' : ''; ?>>
                <?php esc_html_e( 'All', 'smart-contact-form' ); ?>
                <span class="count">(<?php echo esc_html( $counts['all'] ?? 0 ); ?>)</span>
            </a> |
        </li>
        <?php $sl_keys = array_keys( $status_labels ); foreach ( $status_labels as $slug => $label ) : ?>
            <li>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions&status=' . $slug ) ); ?>"
                   <?php echo ( $filter_status === $slug ) ? 'class="current"' : ''; ?>>
                    <?php echo esc_html( $label ); ?>
                    <span class="count">(<?php echo esc_html( $counts[ $slug ] ?? 0 ); ?>)</span>
                </a>
                <?php echo ( $slug !== end( $sl_keys ) ) ? ' |' : ''; ?>
            </li>
        <?php endforeach; ?>
    </ul>

    <?php if ( empty( $rows ) ) : ?>
        <p style="margin-top:2rem;color:#6b7280;"><?php esc_html_e( 'No submissions found.', 'smart-contact-form' ); ?></p>
    <?php else : ?>
    <table class="wp-list-table widefat fixed striped scf-submissions-table">
        <thead>
            <tr>
                <th style="width:40px;"><?php esc_html_e( 'ID', 'smart-contact-form' ); ?></th>
                <th><?php esc_html_e( 'From', 'smart-contact-form' ); ?></th>
                <th><?php esc_html_e( 'Subject / Message', 'smart-contact-form' ); ?></th>
                <th style="width:100px;"><?php esc_html_e( 'Form', 'smart-contact-form' ); ?></th>
                <th style="width:60px;"><?php esc_html_e( 'Score', 'smart-contact-form' ); ?></th>
                <th style="width:85px;"><?php esc_html_e( 'Status', 'smart-contact-form' ); ?></th>
                <th style="width:120px;"><?php esc_html_e( 'Date', 'smart-contact-form' ); ?></th>
                <th style="width:190px;"><?php esc_html_e( 'Actions', 'smart-contact-form' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $rows as $row ) :
            $fd    = json_decode( $row->fields, true ) ?: [];
            $name  = $fd['scf_name'] ?? '—';
            $email = $fd['scf_email'] ?? '';
            $subj  = $fd['scf_subject'] ?? '';
            $msg   = $fd['scf_message'] ?? '';
            $score = (int) $row->spam_score;
            $sc    = $score >= 60 ? '#dc2626' : ( $score >= 30 ? '#d97706' : '#16a34a' );
            $is_new = 'new' === $row->status;
            $nonce  = wp_create_nonce( 'scf_submission_' . $row->id );
        ?>
        <tr style="<?php echo $is_new ? 'font-weight:700;background:#f0f4ff;' : ''; ?>">
            <td><?php echo esc_html( $row->id ); ?></td>
            <td>
                <strong><?php echo esc_html( $name ); ?></strong><br>
                <?php if ( $email ) : ?>
                <a href="mailto:<?php echo esc_attr( $email ); ?>" style="font-size:.85em;color:#4f46e5;"><?php echo esc_html( $email ); ?></a>
                <?php endif; ?>
            </td>
            <td>
                <?php if ( $subj ) : ?><em style="font-size:.88em;display:block;"><?php echo esc_html( $subj ); ?></em><?php endif; ?>
                <span style="color:#6b7280;font-size:.85em;"><?php echo esc_html( wp_trim_words( $msg, 10 ) ); ?></span>
            </td>
            <td style="font-size:.85em;"><?php echo esc_html( get_the_title( $row->form_id ) ?: '#' . $row->form_id ); ?></td>
            <td><span style="color:<?php echo esc_attr( $sc ); ?>;font-weight:700;"><?php echo esc_html( $score ); ?></span></td>
            <td>
                <span class="scf-badge" style="background:<?php echo esc_attr( $badge_colors[ $row->status ] ?? '#6b7280' ); ?>;">
                    <?php echo esc_html( $status_labels[ $row->status ] ?? $row->status ); ?>
                </span>
            </td>
            <td style="font-size:.85em;"><?php echo esc_html( get_date_from_gmt( $row->created_at, 'd/m/Y H:i' ) ); ?></td>
            <td class="scf-row-actions">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions&view=' . $row->id ) ); ?>"
                   class="button button-small" title="<?php esc_attr_e( 'View & Reply', 'smart-contact-form' ); ?>">
                    👁 <?php esc_html_e( 'View', 'smart-contact-form' ); ?>
                </a>
                <?php if ( 'new' === $row->status ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions&action=mark_read&id=' . $row->id . '&_wpnonce=' . $nonce ) ); ?>"
                       class="button button-small" title="<?php esc_attr_e( 'Mark as read', 'smart-contact-form' ); ?>">✓</a>
                <?php elseif ( in_array( $row->status, [ 'read', 'replied' ], true ) ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions&action=mark_new&id=' . $row->id . '&_wpnonce=' . $nonce ) ); ?>"
                       class="button button-small" title="<?php esc_attr_e( 'Mark as unread', 'smart-contact-form' ); ?>">↩</a>
                <?php endif; ?>
                <?php if ( 'spam' !== $row->status ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions&action=mark_spam&id=' . $row->id . '&_wpnonce=' . $nonce ) ); ?>"
                       class="button button-small scf-btn-spam" title="<?php esc_attr_e( 'Mark as spam', 'smart-contact-form' ); ?>">🚫</a>
                <?php else : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions&action=mark_ham&id=' . $row->id . '&_wpnonce=' . $nonce ) ); ?>"
                       class="button button-small" title="<?php esc_attr_e( 'Not spam', 'smart-contact-form' ); ?>">✅</a>
                <?php endif; ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=scf-submissions&action=delete&id=' . $row->id . '&_wpnonce=' . $nonce ) ); ?>"
                   class="button button-small scf-btn-delete"
                   onclick="return confirm('<?php esc_attr_e( 'Delete this submission?', 'smart-contact-form' ); ?>');"
                   title="<?php esc_attr_e( 'Delete', 'smart-contact-form' ); ?>">🗑</a>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php
    $num_pages = ceil( $total / $per_page );
    if ( $num_pages > 1 ) :
        echo '<div class="tablenav bottom"><div class="tablenav-pages">';
        $base = admin_url( 'admin.php?page=scf-submissions' . ( $filter_status ? '&status=' . $filter_status : '' ) . '&paged=%#%' );
        echo paginate_links( [ 'base' => $base, 'format' => '', 'current' => $page, 'total' => $num_pages ] );
        echo '</div></div>';
    endif;
    ?>
    <?php endif; ?>
</div>
