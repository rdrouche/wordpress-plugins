<?php
/**
 * Tools & Diagnostics page.
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap">
    <h1><?php esc_html_e( '🔧 Smart Contact Form — Tools & Diagnostics', 'smart-contact-form' ); ?></h1>
    <hr class="wp-header-end">

    <?php if ( $message ) : ?>
        <div class="notice notice-<?php echo $all_ok ? 'success' : 'error'; ?> is-dismissible">
            <p><strong><?php echo esc_html( $message ); ?></strong></p>
            <?php if ( ! empty( $db_error ) ) : ?>
                <p style="font-family:monospace;font-size:.85em;background:#fff0f0;padding:8px;border-radius:4px;">
                    <?php echo esc_html( $db_error ); ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- DB Table Status -->
    <div class="scf-card" style="max-width:720px;margin-top:16px;">
        <div class="scf-card__header">
            <span class="dashicons dashicons-database"></span>
            <?php esc_html_e( 'Database Tables', 'smart-contact-form' ); ?>
        </div>
        <div class="scf-card__body">
            <table class="widefat" style="max-width:540px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Table (unprefixed)', 'smart-contact-form' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'smart-contact-form' ); ?></th>
                        <th><?php esc_html_e( 'Full DB name', 'smart-contact-form' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php global $wpdb; foreach ( $tables as $name => $exists ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $name ); ?></code></td>
                        <td>
                            <?php if ( $exists ) : ?>
                                <span style="color:#16a34a;font-weight:700;">✅ <?php esc_html_e( 'Exists', 'smart-contact-form' ); ?></span>
                            <?php else : ?>
                                <span style="color:#dc2626;font-weight:700;">❌ <?php esc_html_e( 'MISSING', 'smart-contact-form' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><code style="font-size:.85em;color:#6b7280;"><?php echo esc_html( $wpdb->prefix . $name ); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( ! $all_ok ) : ?>
                <div style="margin-top:16px;padding:12px 16px;background:#fef2f2;border:1px solid #fca5a5;border-radius:6px;">
                    <p style="margin:0 0 6px;color:#b91c1c;font-weight:600;">
                        ⚠️ <?php esc_html_e( 'One or more required tables are missing!', 'smart-contact-form' ); ?>
                    </p>
                    <p style="margin:0;font-size:.9em;">
                        <?php esc_html_e( 'This plugin tries two methods to create tables (dbDelta + raw SQL). If both fail, check that your DB user has CREATE TABLE privileges.', 'smart-contact-form' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" style="margin-top:16px;">
                <?php wp_nonce_field( 'scf_force_create_tables', 'scf_tools_nonce' ); ?>
                <button type="submit" name="scf_action" value="force_create"
                        class="button <?php echo $all_ok ? 'button-secondary' : 'button-primary'; ?>">
                    🛠 <?php esc_html_e( 'Force Create / Repair Tables', 'smart-contact-form' ); ?>
                </button>
                <span style="margin-left:8px;font-size:.85em;color:#6b7280;">
                    <?php esc_html_e( 'Safe to run anytime — uses CREATE TABLE IF NOT EXISTS as fallback.', 'smart-contact-form' ); ?>
                </span>
            </form>
        </div>
    </div>

    <!-- Environment Info -->
    <div class="scf-card" style="max-width:720px;margin-top:16px;">
        <div class="scf-card__header">
            <span class="dashicons dashicons-info-outline"></span>
            <?php esc_html_e( 'Environment Info', 'smart-contact-form' ); ?>
        </div>
        <div class="scf-card__body">
            <?php global $wpdb; ?>
            <table class="scf-meta-table" style="max-width:520px;">
                <?php
                $infos = [
                    __( 'Plugin version', 'smart-contact-form' )      => SCF_VERSION,
                    __( 'DB schema (stored)', 'smart-contact-form' )   => get_option( SCF_Install::DB_VERSION_OPTION, '—' ) . '  (target: ' . SCF_Install::DB_VERSION . ')',
                    __( 'Table prefix', 'smart-contact-form' )         => $wpdb->prefix,
                    __( 'Database name', 'smart-contact-form' )        => DB_NAME,
                    __( 'MySQL version', 'smart-contact-form' )        => $wpdb->db_version(),
                    __( 'WordPress version', 'smart-contact-form' )    => get_bloginfo( 'version' ),
                    __( 'PHP version', 'smart-contact-form' )          => phpversion(),
                    __( 'DB user', 'smart-contact-form' )              => DB_USER,
                    __( 'Akismet active', 'smart-contact-form' )       => function_exists( 'akismet_http_post' ) ? '✅ Yes' : '— No',
                    __( 'Polylang active', 'smart-contact-form' )      => function_exists( 'pll_current_language' ) ? '✅ Yes' : '— No',
                    __( 'reCAPTCHA configured', 'smart-contact-form' ) => get_option( 'scf_recaptcha_site_key' ) ? '✅ Yes' : '— No',
                ];
                foreach ( $infos as $label => $value ) : ?>
                <tr>
                    <th><?php echo esc_html( $label ); ?></th>
                    <td><code><?php echo esc_html( $value ); ?></code></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
    </div>

    <!-- Submissions Stats -->
    <div class="scf-card" style="max-width:720px;margin-top:16px;">
        <div class="scf-card__header">
            <span class="dashicons dashicons-chart-bar"></span>
            <?php esc_html_e( 'Submissions Stats', 'smart-contact-form' ); ?>
        </div>
        <div class="scf-card__body">
            <?php if ( SCF_Install::table_exists( 'scf_submissions' ) ) :
                global $wpdb;
                $counts = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$wpdb->prefix}scf_submissions GROUP BY status" );
                $total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}scf_submissions" );
            ?>
            <table class="scf-meta-table" style="max-width:280px;">
                <tr><th><?php esc_html_e( 'Total', 'smart-contact-form' ); ?></th><td><strong><?php echo $total; ?></strong></td></tr>
                <?php foreach ( $counts as $row ) : ?>
                <tr>
                    <th><?php echo esc_html( ucfirst( $row->status ) ); ?></th>
                    <td><?php echo (int) $row->n; ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php else : ?>
                <p style="color:#dc2626;">❌ <?php esc_html_e( 'Table not available yet.', 'smart-contact-form' ); ?></p>
            <?php endif; ?>
        </div>
    </div>

</div>
