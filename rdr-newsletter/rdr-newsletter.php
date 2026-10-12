<?php
/**
 * Plugin Name: RDR Newsletter Recap
 * Plugin URI:  https://rdr-it.com
 * Description: Weekly newsletter — RSS curation, own posts selector, categories, subscriber personalisation, web edition CPT+RSS, double opt-in, waitlist.
 * Version:     5.1.0
 * Author:      Romain Drouche
 * Text Domain: rdr-newsletter
 * License:     GPL v2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RDRNL_VERSION',   '5.1.0' );
define( 'RDRNL_DIR',       plugin_dir_path( __FILE__ ) );
define( 'RDRNL_URL',       plugin_dir_url( __FILE__ ) );
define( 'RDRNL_OPTION',    'rdrnl_settings' );
define( 'RDRNL_DRAFT_KEY', 'rdrnl_current_draft' );
define( 'RDRNL_LOG_KEY',   'rdrnl_send_log' );
define( 'RDRNL_TABLE',     'rdrnl_subscribers' );
define( 'RDRNL_QUEUE_KEY',  'rdrnl_send_queue' );
define( 'RDRNL_TRACK_TABLE', 'rdrnl_tracking' );

require_once RDRNL_DIR . 'inc/db.php';
require_once RDRNL_DIR . 'inc/security.php';
require_once RDRNL_DIR . 'inc/opengraph.php';
require_once RDRNL_DIR . 'inc/feed-cache.php';
require_once RDRNL_DIR . 'inc/feeds.php';
require_once RDRNL_DIR . 'inc/draft.php';
require_once RDRNL_DIR . 'inc/tracking.php';
require_once RDRNL_DIR . 'inc/mailer.php';
require_once RDRNL_DIR . 'inc/scheduler.php';
require_once RDRNL_DIR . 'inc/subscribers.php';
require_once RDRNL_DIR . 'inc/profile.php';
require_once RDRNL_DIR . 'inc/edition.php';
require_once RDRNL_DIR . 'inc/shortcode.php';
require_once RDRNL_DIR . 'inc/admin.php';
require_once RDRNL_DIR . 'inc/ajax.php';

register_activation_hook(   __FILE__, 'rdrnl_activate' );

// Ensure tables exist even if plugin was updated without reactivation
add_action( 'admin_init', 'rdrnl_ensure_tables' );
function rdrnl_ensure_tables() {
    $v = get_option('rdrnl_db_version', '0');
    if ( version_compare($v, RDRNL_VERSION, '<') ) {
        rdrnl_create_table();
        rdrnl_create_tracking_table();
        rdrnl_create_profile_token_table();
        rdrnl_migrate_profile_slugs();
        // CPT rewrite slugs may have changed between versions — register them
        // and flush once so /editions/ and profile URLs resolve without the
        // admin having to manually re-save permalinks.
        rdrnl_register_edition_cpt();
        rdrnl_register_profile_cpt();
        flush_rewrite_rules();
        update_option('rdrnl_db_version', RDRNL_VERSION);
    }
}
register_deactivation_hook( __FILE__, 'rdrnl_deactivate' );

function rdrnl_activate() {
    rdrnl_create_table();
    rdrnl_create_tracking_table();
    rdrnl_create_profile_token_table();
    rdrnl_migrate_profile_slugs();   // rename sub-N slugs to random
    rdrnl_register_edition_cpt();
    rdrnl_register_profile_cpt();
    flush_rewrite_rules();
    $defaults = [
        'feeds'            => [],
        'from_name'        => get_bloginfo( 'name' ),
        'from_email'       => get_option( 'admin_email' ),
        'subject_prefix'   => '[RDR-IT] Récap hebdo —',
        'auto_send'        => 0,
        'send_day'         => 5,
        'send_hour'        => 12,
        'post_types'       => ['post'],
        'items_per_feed'   => 5,
        'max_subscribers'  => 0,
        'categories'       => ['AdminSys', 'Réseau', 'Cyber', 'Linux', 'Virtualisation', 'Logiciels', 'Domotique'],
        'confirm_subject'  => 'Confirmez votre inscription à ' . get_bloginfo('name'),
        'confirm_message'  => '',
        'publish_editions' => 1,
        'profile_subject'  => 'Votre lien de personnalisation RDR-IT',
        'tracking_pixel'   => 1,   // Pixel d'ouverture — désactivable (conformité CNIL)
    ];
    if ( ! get_option( RDRNL_OPTION ) ) add_option( RDRNL_OPTION, $defaults );
    rdrnl_schedule_cron();
}

function rdrnl_deactivate() {
    rdrnl_clear_cron();
    flush_rewrite_rules();
}
