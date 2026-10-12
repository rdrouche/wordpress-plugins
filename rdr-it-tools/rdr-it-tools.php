<?php
/**
 * Plugin Name: RDR-IT Tools
 * Plugin URI:  https://rdr-it.com
 * Description: Outils RDR-IT — badges, taxonomies dynamiques, articles similaires (moteur maison), Ko-fi, partage.
 * Version:     7.6.0
 * Author:      Romain D.
 * Author URI:  https://rdr-it.com
 * License:     GPL-2.0+
 * Text Domain: rdr-it-tools
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   HELPER GLOBAL — vérifier si un rôle est désactivé pour une feature
   Option : rdrit_{feature}_disabled_roles = JSON array de slugs de rôles
   ex: rdrit_ads_disabled_roles = ["administrator","editor"]
   Logique : toggle ON dans l'admin = rôle DÉSACTIVÉ (exclu des pubs/tracking)
   ============================================================ */
function rdrit_role_is_disabled( string $feature ): bool {
    if ( ! is_user_logged_in() ) return false;

    $option_key    = 'rdrit_' . $feature . '_disabled_roles';
    $disabled_raw  = get_option( $option_key, '[]' );
    $disabled_roles = json_decode( $disabled_raw, true );

    if ( ! is_array( $disabled_roles ) || empty( $disabled_roles ) ) return false;

    $user = wp_get_current_user();
    foreach ( (array) $user->roles as $role ) {
        if ( in_array( $role, $disabled_roles, true ) ) return true;
    }
    return false;
}

define( 'RDRIT_TOOLS_VERSION', '7.6.0' );
define( 'RDRIT_TOOLS_FILE', __FILE__ );
define( 'RDRIT_TOOLS_DIR',     plugin_dir_path( __FILE__ ) );
define( 'RDRIT_TOOLS_URL',     plugin_dir_url( __FILE__ ) );

/*
 * Ordre de chargement IMPORTANT :
 *  1. badges.php       — filtres contenu
 *  2. tax-manager.php  — détection + rendu taxonomies (définit rdrit_tax_*)
 *  3. related-engine.php — moteur articles similaires (définit rdrit_related_render)
 *  4. post-footer.php  — hooks GP + appel des blocs (utilise les 3 modules ci-dessus)
 *  5. settings-page.php — interface admin (utilise rdrit_tax_get_all, RDRIT_TAX_PALETTE)
 */
require_once RDRIT_TOOLS_DIR . 'inc/badges.php';
require_once RDRIT_TOOLS_DIR . 'inc/tax-manager.php';
require_once RDRIT_TOOLS_DIR . 'inc/related-engine.php';
require_once RDRIT_TOOLS_DIR . 'inc/post-footer.php';
require_once RDRIT_TOOLS_DIR . 'inc/feeds.php';
require_once RDRIT_TOOLS_DIR . 'inc/shortcodes.php';
require_once RDRIT_TOOLS_DIR . 'inc/mailer.php';
require_once RDRIT_TOOLS_DIR . 'inc/matomo.php';
require_once RDRIT_TOOLS_DIR . 'inc/tweaks.php';
require_once RDRIT_TOOLS_DIR . 'inc/lightbox.php';
require_once RDRIT_TOOLS_DIR . 'inc/quotes.php';
require_once RDRIT_TOOLS_DIR . 'inc/menu-sync.php';
require_once RDRIT_TOOLS_DIR . 'inc/html-blocks.php';
require_once RDRIT_TOOLS_DIR . 'inc/widget-visibility.php';
require_once RDRIT_TOOLS_DIR . 'inc/login-security/db.php';
require_once RDRIT_TOOLS_DIR . 'inc/login-security/geo.php';
require_once RDRIT_TOOLS_DIR . 'inc/login-security/brute-force.php';
require_once RDRIT_TOOLS_DIR . 'inc/login-security/adblock.php';
require_once RDRIT_TOOLS_DIR . 'inc/login-security/admin-tab.php';
require_once RDRIT_TOOLS_DIR . 'inc/adsense.php';
require_once RDRIT_TOOLS_DIR . 'admin/settings-page.php';

/* ── Activation : options par défaut ── */
register_activation_hook( __FILE__, 'rdrit_tools_activate' );
function rdrit_tools_activate() {
    $defaults = array(
        'rdrit_badges_enabled'          => 1,
        'rdrit_badges_git_redirect'     => 1,
        'rdrit_badges_p_blocks_enabled' => 1,
        'rdrit_pf_kofi_enabled'         => 1,
        'rdrit_pf_kofi_url'             => 'https://ko-fi.com/rdrit0980',
        'rdrit_pf_kofi_color'           => '#c0392b',
        'rdrit_pf_share_enabled'        => 1,
        'rdrit_pf_share_facebook'       => 1,
        'rdrit_pf_share_twitter'        => 1,
        'rdrit_pf_share_linkedin'       => 1,
        'rdrit_pf_share_reddit'         => 1,
        'rdrit_pf_pos_share'            => 'bottom',
        'rdrit_pf_related_enabled'      => 1,
        'rdrit_pf_related_count'        => 4,
        'rdrit_pf_related_layout'       => 'cards-3',
        'rdrit_pf_related_mode'         => 'sync',
        'rdrit_pf_related_title'        => '📚 Articles similaires',
        'rdrit_pf_pos_related'          => 'bottom',
        'rdrit_rel_cache_hours'         => 24,
        'rdrit_rel_disabled_cpts'       => '',
        // Feeds
        'rdrit_feed_cpts'               => 'post',
        // Ko-fi & Partage — filtrage par CPT
        'rdrit_pf_share_disabled_cpts' => '',
        // Shortcodes sidebar (pas d'option globale, tout est en parametre shortcode)
        // Mailer
        'rdrit_mail_mode'             => 'php',
        'rdrit_mail_from_email'       => '',
        'rdrit_mail_from_name'        => '',
        'rdrit_smtp_host'             => '',
        'rdrit_smtp_port'             => 587,
        'rdrit_smtp_encryption'       => 'tls',
        'rdrit_smtp_auth'             => 1,
        'rdrit_smtp_user'             => '',
        'rdrit_smtp_pass'             => '',
        'rdrit_mailjet_api_key'       => '',
        'rdrit_mailjet_api_secret'    => '',
        // Matomo
        'rdrit_matomo_enabled'        => 0,
        'rdrit_matomo_code'           => '',
        'rdrit_matomo_disabled_roles'  => '[]',
        // Tweaks
        'rdrit_tweak_cloudflare_ssl'  => 1,
        'rdrit_tweak_disable_autoupdate' => 1,
        'rdrit_tweak_image_sizes'     => 1,
        'rdrit_tweak_jetpack_css'     => 1,
        'rdrit_tweak_block_css'       => 1,
        'rdrit_tweak_jquery_migrate'  => 1,
        'rdrit_tweak_page_parents'    => 1,
        // Citations colorées
        'rdrit_quotes_enabled'          => 1,
        'rdrit_wv_enabled'              => 1,
        'rdrit_adblock_debug'               => 0,
        'rdrit_adblock_notice_enabled'      => 0,
        'rdrit_adblock_notice_banner'       => 1,
        'rdrit_adblock_notice_modal'        => 0,
        'rdrit_adblock_notice_banner_delay' => 2,
        'rdrit_adblock_notice_modal_delay'  => 5,
        'rdrit_adblock_notice_modal_random' => 0,
        'rdrit_adblock_notice_modal_chance' => 5,
        'rdrit_adblock_notice_ttl'          => 24,
        'rdrit_adblock_notice_modal_next_page' => 0,
        'rdrit_adblock_notice_banner_bg'    => '#1e3a5f',
        'rdrit_adblock_notice_banner_color' => '#ffffff',
        'rdrit_tweak_jquery_front'          => 'off',
        'rdrit_tweak_missed_posts'          => 0,
        'rdrit_front_debug'                 => 0,
        'rdrit_pf_gps_enabled'              => 0,
        'rdrit_pf_gps_domain'               => '',
        'rdrit_pf_gps_label'                => 'Ajouter à vos sources préférées',
        'rdrit_pf_gps_color'                => '#4285f4',
        'rdrit_ls_antibot_enabled'      => 1,
        'rdrit_ls_geo_enabled'          => 1,
        'rdrit_ls_hide_lost_password'   => 0,
        'rdrit_ls_log_pwd_reset'        => 1,
        'rdrit_ls_max_attempts'         => 5,
        'rdrit_ls_ban_duration'         => 15,
        'rdrit_ls_window_minutes'       => 30,
        'rdrit_ls_log_retention_days'   => 90,
        'rdrit_wv_debug'                => 0,
        'rdrit_wv_litespeed'            => 1,
        'rdrit_quotes_force_important'  => 1,
        'rdrit_quotes_colors'  => '',
        // Lightbox
        'rdrit_lightbox_enabled'  => 0,
        'rdrit_lightbox_scope'    => '.inside-article, .entry-content, .post-content',
        'rdrit_lightbox_gallery'  => 'article',
        'rdrit_lightbox_loop'     => 1,
        'rdrit_lightbox_zoomable' => 1,
        'rdrit_adblock_disabled_roles'     => '[]',
        'rdrit_ads_disabled_roles'     => '["administrator","editor","author"]',
        'rdrit_matomo_disabled_roles'  => '["administrator","editor","author"]',
        // AdSense & AdBlock
        'rdrit_adblock_disabled_roles'     => '[]',
        'rdrit_ads_disabled_roles'    => '[]',
        'rdrit_ads_enabled'             => 0,
        'rdrit_ads_client'              => '',
        'rdrit_ads_lazy_enabled'        => 1,
        'rdrit_ads_lazy_margin'         => 1000,
        'rdrit_ads_lazy_selector'       => '#main',
        'rdrit_adblock_enabled'         => 1,
        'rdrit_adblock_action'          => 'both',
        'rdrit_adblock_banner_msg'      => 'Ce site vit grace a la publicite. Merci de desactiver votre bloqueur de pubs !',
        'rdrit_adblock_banner_color'    => '#1e40af',
        'rdrit_adblock_img_url'         => 'https://static.rdr-it.com/images/rdr-img-message.png',
        'rdrit_adblock_img_selector'    => '.wp-block-image > .fancybox',
        'rdrit_ads_amp_enabled'         => 1,
        'rdrit_ads_excluded_roles'      => array(),
    );
    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( $key ) ) add_option( $key, $value );
    }
}

/* ── Désactivation : flush cache transient ── */
register_deactivation_hook( __FILE__, 'rdrit_tools_deactivate' );
function rdrit_tools_deactivate() {
    global $wpdb;
    $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rdrit_rel_%' OR option_name LIKE '_transient_timeout_rdrit_rel_%'" );
}
