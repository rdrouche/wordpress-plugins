<?php
/**
 * Partial : Section champs traduits du formulaire profil.
 * Inclus dans profile-form.php quand Polylang est actif.
 *
 * Variables disponibles : $current_lang, $languages, $d (données courantes)
 */
defined('ABSPATH') || exit;

$lang_names = [
    'fr' => '🇫🇷 Français',
    'en' => '🇬🇧 English',
    'es' => '🇪🇸 Español',
    'de' => '🇩🇪 Deutsch',
    'it' => '🇮🇹 Italiano',
    'pt' => '🇵🇹 Português',
    'ar' => '🇸🇦 العربية',
    'nl' => '🇳🇱 Nederlands',
];
?>
<div class="rdr-form-section rdr-lang-notice">
    <div class="rdr-info-box rdr-info-info" style="margin:0;">
        <span class="rdr-info-icon">🌐</span>
        <div>
            <span class="rdr-info-title">
                <?php printf(
                    __('Vous éditez la version : %s', 'rdr-expert-directory'),
                    '<strong>' . esc_html($lang_names[$current_lang] ?? strtoupper($current_lang)) . '</strong>'
                ); ?>
            </span>
            <div class="rdr-info-content">
                <?php _e('Les champs ci-dessous (Titre, Description, Références) sont spécifiques à cette langue. Vos informations de contact et liens sont partagés entre toutes les versions.', 'rdr-expert-directory'); ?>
                <?php if (count($languages) > 1): ?>
                <br>
                <?php _e('Langues disponibles :', 'rdr-expert-directory'); ?>
                <?php foreach ($languages as $lang):
                    if ($lang === $current_lang) continue;
                    // Utiliser la traduction de la page courante si Polylang disponible
                    // sinon fallback sur la page du formulaire avec paramètre lang
                    $current_page_id = get_queried_object_id();
                    if ($current_page_id && function_exists('pll_get_post')) {
                        $tl_page_id = pll_get_post($current_page_id, $lang);
                        $lang_url   = $tl_page_id ? get_permalink($tl_page_id) : null;
                    } else {
                        $lang_url = null;
                    }
                    // Fallback : URL de la page formulaire avec ?lang=
                    if (!$lang_url) {
                        $form_page = RDR_ED_Capabilities::get_profile_page_url();
                        $lang_url  = add_query_arg('lang', $lang, $form_page);
                    }
                ?>
                <a href="<?= esc_url($lang_url); ?>" class="rdr-lang-switch-btn">
                    <?= esc_html($lang_names[$lang] ?? strtoupper($lang)); ?>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
