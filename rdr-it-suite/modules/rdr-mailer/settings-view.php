<?php
/**
 * RDR-IT Suite — Module Mailer — Settings view
 *
 * Transposition fidèle du tab-mailer de settings-page.php.
 * Même pattern : data-key / data-nonce / classes rdrit-saveable-*.
 * La fonction JS save() est identique au source.
 *
 * @package RDR\Modules\RdrMailer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$nonce = wp_create_nonce( 'rdrit_nonce' );
$g     = fn( $k, $d = '' ) => get_option( $k, $d );

$mode          = $g( 'rdrit_mail_mode', 'php' );
$smtp_host     = $g( 'rdrit_smtp_host', '' );
$smtp_port     = $g( 'rdrit_smtp_port', 587 );
$smtp_enc      = $g( 'rdrit_smtp_encryption', 'tls' );
$smtp_auth     = (bool) $g( 'rdrit_smtp_auth', 1 );
$smtp_pass_set = (bool) $g( 'rdrit_smtp_pass', '' );
$smtp_user     = $g( 'rdrit_smtp_user', '' );

$mj_key        = $g( 'rdrit_mailjet_api_key', '' );
$mj_secret_set = (bool) $g( 'rdrit_mailjet_api_secret', '' );

$last_raw  = $g( 'rdrit_mail_test_last', '' );
$last_test = $last_raw ? json_decode( $last_raw, true ) : null;
?>

<div class="rdr-mailer-wrap">

    <!-- ── Expéditeur + Mode ───────────────────────────────────────────────── -->
    <div class="rdr-settings-group">
        <h3 class="rdr-settings-group__title">Configuration Mail</h3>
        <p style="color:var(--rdr-text-secondary);font-size:13px;margin:0 0 16px">
            Gestion de l'expéditeur et du mode d'envoi de <code>wp_mail()</code>.
        </p>

        <div class="rdr-field">
            <label class="rdr-field__label" for="rdrm_mail_mode">Mode d'envoi</label>
            <select id="rdrm_mail_mode"
                    class="rdr-input rdrm-saveable-select"
                    data-key="rdrit_mail_mode"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>"
                    style="max-width:240px">
                <option value="php"     <?php selected( $mode, 'php' ); ?>>PHP natif (mail())</option>
                <option value="smtp"    <?php selected( $mode, 'smtp' ); ?>>SMTP</option>
                <option value="mailjet" <?php selected( $mode, 'mailjet' ); ?>>Mailjet API</option>
            </select>
        </div>

        <div class="rdr-field rdr-field--row">
            <div>
                <label class="rdr-field__label" for="rdrm_from_email">Email expéditeur</label>
                <input type="email" id="rdrm_from_email"
                       class="rdr-input rdrm-saveable-text"
                       data-key="rdrit_mail_from_email"
                       data-nonce="<?php echo esc_attr( $nonce ); ?>"
                       placeholder="noreply@rdr-it.com"
                       value="<?php echo esc_attr( $g( 'rdrit_mail_from_email', '' ) ); ?>">
            </div>
            <div>
                <label class="rdr-field__label" for="rdrm_from_name">Nom expéditeur</label>
                <input type="text" id="rdrm_from_name"
                       class="rdr-input rdrm-saveable-text"
                       data-key="rdrit_mail_from_name"
                       data-nonce="<?php echo esc_attr( $nonce ); ?>"
                       placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>"
                       value="<?php echo esc_attr( $g( 'rdrit_mail_from_name', '' ) ); ?>">
            </div>
        </div>
    </div>

    <!-- ── SMTP ────────────────────────────────────────────────────────────── -->
    <div class="rdr-settings-group rdrm-smtp-section"
         style="<?php echo $mode === 'smtp' ? '' : 'display:none'; ?>">
        <h3 class="rdr-settings-group__title">Paramètres SMTP</h3>

        <div class="rdr-field rdr-field--row">
            <div>
                <label class="rdr-field__label" for="rdrm_smtp_host">Serveur SMTP</label>
                <input type="text" id="rdrm_smtp_host"
                       class="rdr-input rdrm-saveable-text"
                       data-key="rdrit_smtp_host"
                       data-nonce="<?php echo esc_attr( $nonce ); ?>"
                       placeholder="smtp.example.com"
                       value="<?php echo esc_attr( $smtp_host ); ?>"
                       autocomplete="off">
            </div>
            <div style="flex:0 0 120px">
                <label class="rdr-field__label" for="rdrm_smtp_port">Port</label>
                <input type="number" id="rdrm_smtp_port"
                       class="rdr-input rdrm-saveable-number"
                       data-key="rdrit_smtp_port"
                       data-nonce="<?php echo esc_attr( $nonce ); ?>"
                       min="1" max="65535"
                       value="<?php echo esc_attr( $smtp_port ); ?>">
            </div>
        </div>

        <div class="rdr-field rdr-field--row">
            <div>
                <label class="rdr-field__label" for="rdrm_smtp_enc">Chiffrement</label>
                <select id="rdrm_smtp_enc"
                        class="rdr-input rdrm-saveable-select"
                        data-key="rdrit_smtp_encryption"
                        data-nonce="<?php echo esc_attr( $nonce ); ?>">
                    <option value="tls"  <?php selected( $smtp_enc, 'tls' ); ?>>TLS / STARTTLS (587)</option>
                    <option value="ssl"  <?php selected( $smtp_enc, 'ssl' ); ?>>SSL (465)</option>
                    <option value="none" <?php selected( $smtp_enc, 'none' ); ?>>Aucun (25)</option>
                </select>
            </div>
            <div>
                <label class="rdr-field__label">Authentification</label>
                <label class="rdr-field__label--switch" style="margin-top:6px">
                    <span class="rdr-switch">
                        <input type="checkbox" id="rdrm_smtp_auth"
                               class="rdrm-saveable-check"
                               data-key="rdrit_smtp_auth"
                               data-nonce="<?php echo esc_attr( $nonce ); ?>"
                               value="1"
                               <?php checked( $smtp_auth ); ?>>
                        <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
                    </span>
                </label>
            </div>
        </div>

        <div class="rdrm-smtp-auth-fields rdr-field rdr-field--row"
             style="<?php echo $smtp_auth ? '' : 'display:none'; ?>">
            <div>
                <label class="rdr-field__label" for="rdrm_smtp_user">Identifiant SMTP</label>
                <input type="text" id="rdrm_smtp_user"
                       class="rdr-input rdrm-saveable-text"
                       data-key="rdrit_smtp_user"
                       data-nonce="<?php echo esc_attr( $nonce ); ?>"
                       value="<?php echo esc_attr( $smtp_user ); ?>"
                       autocomplete="off">
            </div>
            <div>
                <label class="rdr-field__label" for="rdrm_smtp_pass">Mot de passe SMTP</label>
                <input type="password" id="rdrm_smtp_pass"
                       class="rdr-input rdrm-smtp-pass"
                       data-key="rdrit_smtp_pass"
                       data-nonce="<?php echo esc_attr( $nonce ); ?>"
                       autocomplete="new-password"
                       placeholder="<?php echo $smtp_pass_set ? '•••••••• (laisser vide pour conserver)' : 'Non défini'; ?>">
            </div>
        </div>
    </div>

    <!-- ── Mailjet ─────────────────────────────────────────────────────────── -->
    <div class="rdr-settings-group rdrm-mailjet-section"
         style="<?php echo $mode === 'mailjet' ? '' : 'display:none'; ?>">
        <h3 class="rdr-settings-group__title">Paramètres Mailjet API</h3>
        <div class="rdr-alert rdr-alert--info" style="margin-bottom:16px">
            Envoi via <code>https://api.mailjet.com/v3.1/send</code> — sans SMTP.
            L'adresse expéditeur doit être
            <a href="https://app.mailjet.com/account/sender" target="_blank">vérifiée dans Mailjet</a>.
        </div>

        <div class="rdr-field rdr-field--row">
            <div>
                <label class="rdr-field__label" for="rdrm_mj_key">Clé API</label>
                <input type="text" id="rdrm_mj_key"
                       class="rdr-input rdrm-saveable-text"
                       data-key="rdrit_mailjet_api_key"
                       data-nonce="<?php echo esc_attr( $nonce ); ?>"
                       placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                       value="<?php echo esc_attr( $mj_key ); ?>"
                       autocomplete="off">
            </div>
            <div>
                <label class="rdr-field__label" for="rdrm_mj_secret">Clé secrète</label>
                <input type="password" id="rdrm_mj_secret"
                       class="rdr-input rdrm-mj-secret"
                       data-key="rdrit_mailjet_api_secret"
                       data-nonce="<?php echo esc_attr( $nonce ); ?>"
                       autocomplete="new-password"
                       placeholder="<?php echo $mj_secret_set ? '•••••••• (laisser vide pour conserver)' : 'Non définie'; ?>">
            </div>
        </div>
    </div>

    <!-- ── Test d'envoi ────────────────────────────────────────────────────── -->
    <div class="rdr-settings-group" style="margin-top:8px">
        <h3 class="rdr-settings-group__title">Test d'envoi</h3>
        <p style="font-size:13px;color:var(--rdr-text-secondary);margin:0 0 12px">
            Mode actif : <strong><?php echo esc_html( strtoupper( $mode ) ); ?></strong>
            <?php if ( $mode === 'smtp' && ! empty( $smtp_host ) ) : ?>
                — <?php echo esc_html( $smtp_host . ':' . $smtp_port ); ?>
            <?php elseif ( $mode === 'mailjet' && ! empty( $mj_key ) ) : ?>
                — Mailjet API
            <?php endif; ?>
        </p>

        <div class="rdr-field rdr-field--row">
            <input type="email" id="rdrm-test-to"
                   class="rdr-input"
                   value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"
                   placeholder="destinataire@example.com"
                   style="max-width:280px">
            <button type="button" id="rdrm-test-btn"
                    class="rdr-btn rdr-btn--ghost"
                    data-nonce="<?php echo esc_attr( $nonce ); ?>">
                ✉️ Envoyer un mail test
            </button>
        </div>

        <div id="rdrm-test-result" style="margin-top:8px;font-size:13px" aria-live="polite"></div>

        <?php if ( $last_test ) : ?>
            <p style="font-size:.78em;color:var(--rdr-text-muted);margin-top:8px">
                Dernier test : <?php echo esc_html( $last_test['time'] ?? '—' ); ?> →
                <?php if ( $last_test['success'] ?? false ) : ?>
                    <span style="color:#16a34a">✅ Succès</span>
                <?php else : ?>
                    <span style="color:#dc2626">❌ Échec</span>
                <?php endif; ?>
                <?php if ( ! empty( $last_test['errors'] ) ) : ?>
                    — <?php echo esc_html( implode( ', ', $last_test['errors'] ) ); ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>

</div><!-- .rdr-mailer-wrap -->
