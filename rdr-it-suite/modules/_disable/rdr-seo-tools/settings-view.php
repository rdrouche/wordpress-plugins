<?php
/**
 * Réglages — Module SEO Tools
 * Inclus dans module-settings-wrap.php
 * Variables : $slug, $module, $settings
 *
 * @package RDR_IT_Suite\Modules\SeoTools
 */

defined( 'ABSPATH' ) || exit;

$remove_version    = $settings['remove_version']    ?? '1';
$og_default_image  = $settings['og_default_image']  ?? '';
$meta_author       = $settings['meta_author']        ?? get_bloginfo( 'name' );
$noindex_search    = $settings['noindex_search']     ?? '1';
$canonical_www     = $settings['canonical_www']      ?? '0';
?>

<div class="rdr-settings-group">
    <h2 class="rdr-settings-group__title">
        <?php esc_html_e( 'Sécurité & confidentialité', 'rdr-it-suite' ); ?>
    </h2>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[remove_version]" value="1"
                    <?php checked( '1', $remove_version ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Masquer la version WordPress', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Supprime le paramètre ?ver= des scripts/styles et la balise meta generator.', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[noindex_search]" value="1"
                    <?php checked( '1', $noindex_search ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Noindex sur les pages de recherche', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Ajoute noindex, follow sur toutes les pages ?s= pour éviter le contenu dupliqué.', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>

    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[canonical_www]" value="1"
                    <?php checked( '1', $canonical_www ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong><?php esc_html_e( 'Forcer canonical sans www', 'rdr-it-suite' ); ?></strong>
                <small><?php esc_html_e( 'Redirige www.domaine.com → domaine.com en 301.', 'rdr-it-suite' ); ?></small>
            </span>
        </label>
    </div>
</div>

<div class="rdr-settings-group">
    <h2 class="rdr-settings-group__title">
        <?php esc_html_e( 'Open Graph / Réseaux sociaux', 'rdr-it-suite' ); ?>
    </h2>

    <div class="rdr-field">
        <label class="rdr-field__label" for="seo_og_default_image">
            <?php esc_html_e( 'Image Open Graph par défaut (URL)', 'rdr-it-suite' ); ?>
        </label>
        <input
            type="url"
            id="seo_og_default_image"
            name="settings[og_default_image]"
            class="rdr-input"
            value="<?php echo esc_url( $og_default_image ); ?>"
            placeholder="https://rdr-it.com/wp-content/uploads/og-default.jpg"
        >
        <p class="rdr-field__help"><?php esc_html_e( 'Utilisée quand l\'article n\'a pas d\'image mise en avant.', 'rdr-it-suite' ); ?></p>
    </div>

    <div class="rdr-field">
        <label class="rdr-field__label" for="seo_meta_author">
            <?php esc_html_e( 'Auteur par défaut (meta author)', 'rdr-it-suite' ); ?>
        </label>
        <input
            type="text"
            id="seo_meta_author"
            name="settings[meta_author]"
            class="rdr-input"
            value="<?php echo esc_attr( $meta_author ); ?>"
            placeholder="Romain Drouche"
        >
    </div>
</div>
