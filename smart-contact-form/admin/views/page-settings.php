<?php defined( 'ABSPATH' ) || exit; ?>
<div class="wrap">
    <h1><?php esc_html_e( 'Smart Contact Form – Settings', 'smart-contact-form' ); ?></h1>

    <form method="post">
        <?php wp_nonce_field( 'scf_save_settings', 'scf_settings_nonce' ); ?>

        <h2><?php esc_html_e( 'reCAPTCHA v3', 'smart-contact-form' ); ?></h2>
        <p class="description">
            <?php printf(
                /* translators: %s: Google reCAPTCHA admin URL */
                esc_html__( 'Get your keys at %s. Choose reCAPTCHA v3.', 'smart-contact-form' ),
                '<a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">google.com/recaptcha/admin</a>'
            ); ?>
        </p>
        <table class="form-table">
            <tr>
                <th><label for="scf_recaptcha_site_key"><?php esc_html_e( 'Site Key', 'smart-contact-form' ); ?></label></th>
                <td><input type="text" id="scf_recaptcha_site_key" name="scf_recaptcha_site_key" value="<?php echo esc_attr( get_option( 'scf_recaptcha_site_key' ) ); ?>" class="large-text"></td>
            </tr>
            <tr>
                <th><label for="scf_recaptcha_secret_key"><?php esc_html_e( 'Secret Key', 'smart-contact-form' ); ?></label></th>
                <td><input type="text" id="scf_recaptcha_secret_key" name="scf_recaptcha_secret_key" value="<?php echo esc_attr( get_option( 'scf_recaptcha_secret_key' ) ); ?>" class="large-text"></td>
            </tr>
            <tr>
                <th><label for="scf_recaptcha_threshold"><?php esc_html_e( 'Score Threshold', 'smart-contact-form' ); ?></label></th>
                <td>
                    <input type="number" id="scf_recaptcha_threshold" name="scf_recaptcha_threshold" value="<?php echo esc_attr( get_option( 'scf_recaptcha_threshold', '0.5' ) ); ?>" min="0" max="1" step="0.05" style="width:80px;">
                    <p class="description"><?php esc_html_e( '0.0 = very likely bot, 1.0 = very likely human. Recommended: 0.5', 'smart-contact-form' ); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Rate Limiting', 'smart-contact-form' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="scf_rate_limit_max"><?php esc_html_e( 'Max Submissions', 'smart-contact-form' ); ?></label></th>
                <td>
                    <input type="number" id="scf_rate_limit_max" name="scf_rate_limit_max" value="<?php echo esc_attr( get_option( 'scf_rate_limit_max', 5 ) ); ?>" min="1" max="100" style="width:80px;">
                    <p class="description"><?php esc_html_e( 'Maximum number of submissions per IP per time window.', 'smart-contact-form' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="scf_rate_limit_window"><?php esc_html_e( 'Time Window (seconds)', 'smart-contact-form' ); ?></label></th>
                <td>
                    <input type="number" id="scf_rate_limit_window" name="scf_rate_limit_window" value="<?php echo esc_attr( get_option( 'scf_rate_limit_window', 3600 ) ); ?>" min="60" style="width:100px;">
                    <p class="description"><?php esc_html_e( 'Default: 3600 (1 hour)', 'smart-contact-form' ); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="scf_min_fill_time"><?php esc_html_e( 'Minimum Fill Time (seconds)', 'smart-contact-form' ); ?></label></th>
                <td>
                    <input type="number" id="scf_min_fill_time" name="scf_min_fill_time" value="<?php echo esc_attr( get_option( 'scf_min_fill_time', 3 ) ); ?>" min="1" max="30" style="width:80px;">
                    <p class="description"><?php esc_html_e( 'Submissions faster than this are flagged as bots.', 'smart-contact-form' ); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e( 'Global Email Settings', 'smart-contact-form' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="scf_from_name"><?php esc_html_e( 'From Name', 'smart-contact-form' ); ?></label></th>
                <td><input type="text" id="scf_from_name" name="scf_from_name" value="<?php echo esc_attr( get_option( 'scf_from_name' ) ); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="scf_from_email"><?php esc_html_e( 'From Email', 'smart-contact-form' ); ?></label></th>
                <td><input type="email" id="scf_from_email" name="scf_from_email" value="<?php echo esc_attr( get_option( 'scf_from_email' ) ); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="scf_admin_email"><?php esc_html_e( 'Default Recipient', 'smart-contact-form' ); ?></label></th>
                <td><input type="email" id="scf_admin_email" name="scf_admin_email" value="<?php echo esc_attr( get_option( 'scf_admin_email' ) ); ?>" class="regular-text"></td>
            </tr>
        </table>

        <?php submit_button( __( 'Save Settings', 'smart-contact-form' ) ); ?>
    </form>
</div>
