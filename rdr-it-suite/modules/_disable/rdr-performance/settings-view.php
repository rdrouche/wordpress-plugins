<?php
/**
 * Réglages — Module Performance
 *
 * @package RDR_IT_Suite\Modules\Performance
 */

defined( 'ABSPATH' ) || exit;

$disable_emoji       = $settings['disable_emoji']        ?? '1';
$disable_embeds      = $settings['disable_embeds']       ?? '0';
$remove_query_string = $settings['remove_query_string']  ?? '1';
$dns_prefetch        = $settings['dns_prefetch']         ?? '';
$lazy_load_iframes   = $settings['lazy_load_iframes']    ?? '1';
?>

<div class="rdr-settings-group">
    <h2 class="rdr-settings-group__title">
        <?php esc_html_e( 'Scripts & assets', 'rdr-it-suite' ); ?>
    </h2>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[disable_emoji]" value="1" <?php checked( '1', $disable_emoji ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Désactiver le script emoji WordPress', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Supprime wp-emoji-release.min.js (~50 KB) — les emoji CSS natifs restent fonctionnels.', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[disable_embeds]" value="1" <?php checked( '1', $disable_embeds ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Désactiver le script oEmbed / embeds', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Supprime wp-embed.min.js. Attention : les iframes YouTube/Vimeo insérées via l\'éditeur ne s\'afficheront plus automatiquement.', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[remove_query_string]" value="1" <?php checked( '1', $remove_query_string ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Supprimer les query strings des assets', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Retire ?ver= des URL CSS/JS pour améliorer le cache CDN.', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[lazy_load_iframes]" value="1" <?php checked( '1', $lazy_load_iframes ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Lazy-load des iframes', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Ajoute loading="lazy" sur toutes les balises &lt;iframe&gt; du contenu.', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>
</div>

<div class="rdr-settings-group">
    <h2 class="rdr-settings-group__title">
        <?php esc_html_e( 'Réseau', 'rdr-it-suite' ); ?>
    </h2>

    <div class="rdr-field">
        <label class="rdr-field__label" for="perf_dns_prefetch">
            <?php esc_html_e( 'Domaines DNS prefetch (un par ligne)', 'rdr-it-suite' ); ?>
        </label>
        <textarea
            id="perf_dns_prefetch"
            name="settings[dns_prefetch]"
            class="rdr-input rdr-input--textarea"
            rows="4"
            placeholder="//fonts.googleapis.com&#10;//cdn.jsdelivr.net"
        ><?php echo esc_textarea( $dns_prefetch ); ?></textarea>
        <p class="rdr-field__help"><?php esc_html_e( 'Injecte des balises <link rel="dns-prefetch"> dans le <head>.', 'rdr-it-suite' ); ?></p>
    </div>
</div>
