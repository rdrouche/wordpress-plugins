<?php
/**
 * Plugin Name:       Smart Contact Form
 * Plugin URI:        https://github.com/your-repo/smart-contact-form
 * Description:       Formulaires de contact robustes avec protection anti-spam multicouche (Honeypot, reCAPTCHA v3, Akismet, Rate Limiting), soumission AJAX, et compatibilité Polylang.
 * Version:           1.0.0
 * Author:            Your Name
 * License:           GPL-2.0+
 * Text Domain:       smart-contact-form
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SCF_VERSION',     '1.0.0' );
define( 'SCF_PLUGIN_FILE', __FILE__ );
define( 'SCF_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'SCF_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'SCF_PLUGIN_BASE', plugin_basename( __FILE__ ) );

// Autoload classes
spl_autoload_register( function ( $class ) {
    $prefix = 'SCF\\';
    $base   = SCF_PLUGIN_DIR . 'includes/';
    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) return;
    $relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
    $file = $base . 'class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
    if ( file_exists( $file ) ) require $file;
} );

/**
 * Main plugin bootstrap.
 */
final class Smart_Contact_Form {

    private static $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->load_textdomain();
        $this->load_dependencies();
        SCF_Install::maybe_upgrade();
        $this->init_hooks();
    }

    private function load_textdomain(): void {
        load_plugin_textdomain( 'smart-contact-form', false, dirname( SCF_PLUGIN_BASE ) . '/languages' );
    }

    private function load_dependencies(): void {
        require_once SCF_PLUGIN_DIR . 'includes/class-scf-install.php';
        require_once SCF_PLUGIN_DIR . 'includes/class-scf-post-type.php';
        require_once SCF_PLUGIN_DIR . 'includes/class-scf-shortcode.php';
        require_once SCF_PLUGIN_DIR . 'includes/class-scf-ajax.php';
        require_once SCF_PLUGIN_DIR . 'includes/class-scf-antispam.php';
        require_once SCF_PLUGIN_DIR . 'includes/class-scf-mailer.php';
        require_once SCF_PLUGIN_DIR . 'includes/class-scf-polylang.php';
        if ( is_admin() ) {
            require_once SCF_PLUGIN_DIR . 'admin/class-scf-admin.php';
        }
    }

    private function init_hooks(): void {
        register_activation_hook( SCF_PLUGIN_FILE, [ 'SCF_Install', 'activate' ] );
        register_deactivation_hook( SCF_PLUGIN_FILE, [ 'SCF_Install', 'deactivate' ] );

        add_action( 'init', [ 'SCF_Post_Type', 'register' ] );
        add_action( 'init', [ 'SCF_Shortcode', 'register' ] );
        add_action( 'init', [ 'SCF_Polylang', 'init' ] );
        SCF_Ajax::init();
        if ( is_admin() ) SCF_Admin::init();
    }
}

// Boot.
function smart_contact_form(): Smart_Contact_Form {
    return Smart_Contact_Form::instance();
}
add_action( 'plugins_loaded', 'smart_contact_form' );
