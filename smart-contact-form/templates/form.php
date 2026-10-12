<?php
/**
 * Front-end form template.
 *
 * Available variables:
 *   $form_id   int
 *   $fields    array
 *   $nonce     string
 *   $site_key  string  reCAPTCHA v3 site key (may be empty)
 */
defined( 'ABSPATH' ) || exit;
?>
<form
    class="scf-form"
    id="scf-form-<?php echo esc_attr( $form_id ); ?>"
    data-form-id="<?php echo esc_attr( $form_id ); ?>"
    novalidate
    aria-live="polite">

    <input type="hidden" name="form_id" value="<?php echo esc_attr( $form_id ); ?>">
    <input type="hidden" name="scf_nonce" value="<?php echo esc_attr( $nonce ); ?>">

    <?php
    // Honeypot
    echo SCF_Antispam::honeypot_field( $form_id ); // phpcs:ignore WordPress.Security.EscapeOutput
    // Start-time
    echo SCF_Antispam::start_time_field(); // phpcs:ignore WordPress.Security.EscapeOutput
    ?>

    <?php foreach ( $fields as $field ) :
        $field_id    = 'scf-' . esc_attr( $form_id ) . '-' . esc_attr( $field['name'] );
        $required    = ! empty( $field['required'] );
        $placeholder = $field['placeholder'] ?? '';
    ?>
    <div class="scf-field scf-field--<?php echo esc_attr( $field['type'] ); ?>">
        <label for="<?php echo esc_attr( $field_id ); ?>">
            <?php echo esc_html( $field['label'] ); ?>
            <?php if ( $required ) : ?><span class="scf-required" aria-hidden="true">*</span><?php endif; ?>
        </label>

        <?php if ( 'textarea' === $field['type'] ) : ?>
            <textarea
                id="<?php echo esc_attr( $field_id ); ?>"
                name="<?php echo esc_attr( $field['name'] ); ?>"
                placeholder="<?php echo esc_attr( $placeholder ); ?>"
                rows="5"
                <?php echo $required ? 'required aria-required="true"' : ''; ?>
            ></textarea>

        <?php elseif ( 'select' === $field['type'] ) : ?>
            <select
                id="<?php echo esc_attr( $field_id ); ?>"
                name="<?php echo esc_attr( $field['name'] ); ?>"
                <?php echo $required ? 'required aria-required="true"' : ''; ?>>
                <option value=""><?php esc_html_e( '— Select —', 'smart-contact-form' ); ?></option>
                <?php foreach ( (array) ( $field['options'] ?? [] ) as $opt ) : ?>
                    <option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
                <?php endforeach; ?>
            </select>

        <?php else : ?>
            <input
                type="<?php echo esc_attr( $field['type'] ); ?>"
                id="<?php echo esc_attr( $field_id ); ?>"
                name="<?php echo esc_attr( $field['name'] ); ?>"
                placeholder="<?php echo esc_attr( $placeholder ); ?>"
                <?php echo $required ? 'required aria-required="true"' : ''; ?>
                value="">
        <?php endif; ?>

        <span class="scf-error" role="alert" aria-live="assertive"></span>
    </div>
    <?php endforeach; ?>

    <?php if ( $site_key ) : ?>
        <input type="hidden" name="scf_recaptcha_token" id="scf-recaptcha-token-<?php echo esc_attr( $form_id ); ?>">
    <?php endif; ?>

    <div class="scf-form-footer">
        <?php if ( $site_key ) : ?>
            <p class="scf-recaptcha-notice">
                <?php
                printf(
                    /* translators: 1: Privacy Policy URL, 2: Terms URL */
                    esc_html__( 'This site is protected by reCAPTCHA. %1$s and %2$s apply.', 'smart-contact-form' ),
                    '<a href="https://policies.google.com/privacy" target="_blank" rel="noopener">' . esc_html__( 'Privacy Policy', 'smart-contact-form' ) . '</a>',
                    '<a href="https://policies.google.com/terms" target="_blank" rel="noopener">' . esc_html__( 'Terms of Service', 'smart-contact-form' ) . '</a>'
                );
                ?>
            </p>
        <?php endif; ?>

        <button type="submit" class="scf-submit">
            <span class="scf-submit__text"><?php esc_html_e( 'Send Message', 'smart-contact-form' ); ?></span>
            <span class="scf-submit__spinner" aria-hidden="true"></span>
        </button>
    </div>

    <div class="scf-response" role="status" aria-live="polite"></div>
</form>
