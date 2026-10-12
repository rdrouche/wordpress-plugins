<?php
/**
 * Plugin Name: Polylang Auto Translator
 * Plugin URI:  https://github.com/your-repo/wp-polylang-translator
 * Description: Traduction automatique des articles via DeepL, Google Translate ou LibreTranslate, intégré à Polylang.
 * Version:     1.1.1
 * Author:      Your Name
 * License:     GPL-2.0+
 * Text Domain: wp-polylang-translator
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPPT_VERSION', '1.1.1' );
define( 'WPPT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPPT_URL', plugin_dir_url( __FILE__ ) );
define( 'WPPT_FILE', __FILE__ );

require_once WPPT_DIR . 'includes/class-provider-interface.php';
require_once WPPT_DIR . 'includes/class-deepl-provider.php';
require_once WPPT_DIR . 'includes/class-google-provider.php';
require_once WPPT_DIR . 'includes/class-google-free-provider.php';
require_once WPPT_DIR . 'includes/class-libretranslate-provider.php';
require_once WPPT_DIR . 'includes/class-libretranslate-free-provider.php';
require_once WPPT_DIR . 'includes/class-content-processor.php';
require_once WPPT_DIR . 'includes/class-translator.php';
require_once WPPT_DIR . 'includes/class-polylang-integration.php';
require_once WPPT_DIR . 'admin/class-settings.php';

function wppt_init() {
    if ( ! function_exists( 'pll_languages_list' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'Polylang Auto Translator nécessite que le plugin Polylang soit installé et activé.', 'wp-polylang-translator' )
                . '</p></div>';
        } );
        return;
    }

    WPPT_Settings::init();
    WPPT_Polylang_Integration::init();
}
add_action( 'plugins_loaded', 'wppt_init' );

register_activation_hook( __FILE__, 'wppt_activate' );
function wppt_activate() {
    $defaults = array(
        'primary_service'        => 'deepl',
        'deepl_api_key'          => '',
        'deepl_fallback'         => '1',
        'deepl_fallback_service' => 'google_free',
        'google_api_key'         => '',
        'libretranslate_url'     => '',
        'libretranslate_api_key' => '',
        'untranslated_words'     => "Active Directory\nDocker\nKubernetes\nGitHub\nLinux",
        'custom_translations'    => "FR:Sommaire|EN:Table of Contents\nFR:Lire aussi|EN:Read also",
        'auto_translate'         => '1',
        'translate_excerpt'      => '1',
        'translate_taxonomies'   => '1',
        'relink_internal_urls'   => '1',
        'post_types'             => array( 'post', 'page' ),
    );
    foreach ( $defaults as $key => $value ) {
        if ( false === get_option( 'wppt_' . $key ) ) {
            add_option( 'wppt_' . $key, $value );
        }
    }

    update_option( 'wppt_version', WPPT_VERSION );
}
