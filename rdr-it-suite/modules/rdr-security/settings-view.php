<?php
/**
 * Réglages — Module Security
 *
 * @package RDR_IT_Suite\Modules\Security
 */

defined( 'ABSPATH' ) || exit;

$hide_wp_version      = $settings['hide_wp_version']       ?? '1';
$disable_rest_anon    = $settings['disable_rest_anon']      ?? '0';
$disable_xmlrpc       = $settings['disable_xmlrpc']         ?? '1';
$remove_x_powered     = $settings['remove_x_powered']       ?? '1';
$login_error_generic  = $settings['login_error_generic']    ?? '1';
$disable_file_edit    = $settings['disable_file_edit']      ?? '0';
?>

<div class="rdr-settings-group">
    <h2 class="rdr-settings-group__title">
        <?php esc_html_e( 'Masquage & obfuscation', 'rdr-it-suite' ); ?>
    </h2>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[hide_wp_version]" value="1" <?php checked( '1', $hide_wp_version ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Masquer la version WordPress', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Supprime la balise <meta name="generator"> et les références de version dans les flux RSS.', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[remove_x_powered]" value="1" <?php checked( '1', $remove_x_powered ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Supprimer l\'en-tête X-Powered-By', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Évite d\'exposer la version PHP dans les en-têtes HTTP.', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[login_error_generic]" value="1" <?php checked( '1', $login_error_generic ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Généraliser les erreurs de connexion', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Remplace "Identifiant inconnu" / "Mot de passe incorrect" par un message générique pour ne pas révéler les comptes existants.', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>
</div>

<div class="rdr-settings-group">
    <h2 class="rdr-settings-group__title">
        <?php esc_html_e( 'Services WordPress', 'rdr-it-suite' ); ?>
    </h2>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[disable_xmlrpc]" value="1" <?php checked( '1', $disable_xmlrpc ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Désactiver XML-RPC', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Bloque /xmlrpc.php, vecteur fréquent de brute-force. Incompatible avec Jetpack, l\'app mobile WP, et WooCommerce Mobile.', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[disable_rest_anon]" value="1" <?php checked( '1', $disable_rest_anon ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Bloquer l\'API REST pour les utilisateurs non connectés', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Retourne une erreur 401 sur /wp-json/ pour les anonymes. Peut casser des plugins tiers (Elementor, WooCommerce, etc.).', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[disable_file_edit]" value="1" <?php checked( '1', $disable_file_edit ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Désactiver l\'éditeur de fichiers WP Admin', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Définit DISALLOW_FILE_EDIT à true. Recommandé en production — désactive Apparence → Éditeur et Extensions → Éditeur.', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>
</div>
