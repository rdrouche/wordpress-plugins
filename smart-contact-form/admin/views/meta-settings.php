<?php defined( 'ABSPATH' ) || exit; ?>
<table class="form-table">
    <tr>
        <th><label for="scf_success_message"><?php esc_html_e( 'Success Message', 'smart-contact-form' ); ?></label></th>
        <td>
            <input type="text" id="scf_success_message" name="_scf_success_message" value="<?php echo esc_attr( $success ); ?>" class="large-text">
            <p class="description"><?php esc_html_e( 'Shown to the user after a successful submission.', 'smart-contact-form' ); ?></p>
        </td>
    </tr>
</table>
