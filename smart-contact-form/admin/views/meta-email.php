<?php defined( 'ABSPATH' ) || exit; ?>
<table class="form-table">
    <tr>
        <th><label for="_scf_recipient_email"><?php esc_html_e( 'Recipient Email', 'smart-contact-form' ); ?></label></th>
        <td>
            <input type="email" id="_scf_recipient_email" name="_scf_recipient_email" value="<?php echo esc_attr( $recipient ); ?>" class="regular-text">
            <p class="description"><?php esc_html_e( 'Leave empty to use the global admin email.', 'smart-contact-form' ); ?></p>
        </td>
    </tr>
    <tr>
        <th><label for="_scf_email_subject"><?php esc_html_e( 'Email Subject', 'smart-contact-form' ); ?></label></th>
        <td>
            <input type="text" id="_scf_email_subject" name="_scf_email_subject" value="<?php echo esc_attr( $subject ); ?>" class="large-text">
            <p class="description"><?php esc_html_e( 'Use {scf_name}, {scf_email} etc. as placeholders.', 'smart-contact-form' ); ?></p>
        </td>
    </tr>
    <tr>
        <th><?php esc_html_e( 'Send Copy to Submitter', 'smart-contact-form' ); ?></th>
        <td>
            <label>
                <input type="checkbox" name="_scf_send_copy" value="1" <?php checked( $send_copy, '1' ); ?>>
                <?php esc_html_e( 'Send a confirmation email to the person who submitted the form', 'smart-contact-form' ); ?>
            </label>
        </td>
    </tr>
    <tr>
        <th><label for="_scf_copy_subject"><?php esc_html_e( 'Confirmation Subject', 'smart-contact-form' ); ?></label></th>
        <td><input type="text" id="_scf_copy_subject" name="_scf_copy_subject" value="<?php echo esc_attr( $copy_subject ); ?>" class="large-text"></td>
    </tr>
    <tr>
        <th><label for="_scf_copy_body"><?php esc_html_e( 'Confirmation Body', 'smart-contact-form' ); ?></label></th>
        <td>
            <textarea id="_scf_copy_body" name="_scf_copy_body" rows="6" class="large-text"><?php echo esc_textarea( $copy_body ); ?></textarea>
            <p class="description"><?php esc_html_e( 'Plain text. Use {scf_name}, {scf_email} etc.', 'smart-contact-form' ); ?></p>
        </td>
    </tr>
</table>
