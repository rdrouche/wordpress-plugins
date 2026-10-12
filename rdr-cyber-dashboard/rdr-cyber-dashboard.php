<?php
/**
 * Plugin Name:       RDR Cyber Dashboard
 * Plugin URI:        https://rdr-it.com
 * Description:       Dashboard cybersécurité — collecte CERT-FR/NVD/CISA, flux RSS, abonnements email scalables, préférences individuelles, notifications Web Push VAPID.
 * Version:           1.3.0
 * Author:            Romain Drouche — RDR-IT
 * Author URI:        https://rdr-it.com
 * License:           GPL-2.0+
 * Text Domain:       rdr-cyber-dashboard
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RDR_CYBER_VERSION',      '1.3.0' );
define( 'RDR_CYBER_PLUGIN_DIR',   plugin_dir_path( __FILE__ ) );
define( 'RDR_CYBER_PLUGIN_URL',   plugin_dir_url( __FILE__ ) );
define( 'RDR_CYBER_TABLE',        'rdr_cyber_items' );
define( 'RDR_CYBER_SUBS_TABLE',   'rdr_cyber_subscribers' );
define( 'RDR_CYBER_QUEUE_TABLE',  'rdr_cyber_email_queue' );
define( 'RDR_CYBER_PUSH_TABLE',   'rdr_cyber_push_subs' );

require_once RDR_CYBER_PLUGIN_DIR . 'includes/class-rdr-database.php';
require_once RDR_CYBER_PLUGIN_DIR . 'includes/class-rdr-collector.php';
require_once RDR_CYBER_PLUGIN_DIR . 'includes/class-rdr-cron.php';
require_once RDR_CYBER_PLUGIN_DIR . 'includes/class-rdr-admin-ctb.php';
require_once RDR_CYBER_PLUGIN_DIR . 'includes/class-rdr-feed.php';
require_once RDR_CYBER_PLUGIN_DIR . 'includes/class-rdr-email.php';
require_once RDR_CYBER_PLUGIN_DIR . 'includes/class-rdr-push.php';

/* ---------- Activation / Désactivation ---------- */
register_activation_hook( __FILE__, 'rdr_cyber_activate' );
function rdr_cyber_activate() {
    RDR_Database::install();
    RDR_Cron::schedule();
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'rdr_cyber_deactivate' );
function rdr_cyber_deactivate() {
    RDR_Cron::unschedule_all();
    flush_rewrite_rules();
}

/* ---------- cron_schedules DOIT être enregistré avant plugins_loaded ---------- */
add_filter( 'cron_schedules', array( 'RDR_Cron', 'add_schedules' ) );

add_action( 'plugins_loaded', function () {
    RDR_Cron::init();
    RDR_Admin_CTB::init();
    RDR_Feed::init();
    RDR_Email::init();
    RDR_Collector::init();
    RDR_Push::init();
} );

/* ---------- Shortcodes ---------- */
add_shortcode( 'rdr_cyber_dashboard',    'rdr_cyber_shortcode' );
add_shortcode( 'rdr_cyber_subscribe',    'rdr_cyber_subscribe_shortcode' );
add_shortcode( 'rdr_cyber_feed_builder', 'rdr_cyber_feed_builder_shortcode' );

function rdr_cyber_shortcode( $atts ) {
    $atts = shortcode_atts( array( 'per_page' => get_option('rdr_cyber_per_page',12) ), $atts );
    ob_start();
    include RDR_CYBER_PLUGIN_DIR . 'templates/page-dashboard.php';
    return ob_get_clean();
}

function rdr_cyber_subscribe_shortcode( $atts ) {
    ob_start();
    include RDR_CYBER_PLUGIN_DIR . 'templates/subscribe-form.php';
    return ob_get_clean();
}

function rdr_cyber_feed_builder_shortcode( $atts ) {
    ob_start();
    include RDR_CYBER_PLUGIN_DIR . 'templates/feed-builder.php';
    return ob_get_clean();
}

/* ---------- Assets frontend ---------- */
add_action( 'wp_enqueue_scripts', 'rdr_cyber_enqueue' );
function rdr_cyber_enqueue() {
    if ( ! is_page() ) return;
    global $post;
    if ( ! $post ) return;
    $sc = array( 'rdr_cyber_dashboard', 'rdr_cyber_subscribe', 'rdr_cyber_feed_builder' );
    $has = false;
    foreach ( $sc as $s ) if ( has_shortcode($post->post_content,$s) ) { $has=true; break; }
    if ( ! $has ) return;

    wp_enqueue_style( 'rdr-cyber-dashboard', RDR_CYBER_PLUGIN_URL.'assets/css/rdr-dashboard.css', array(), RDR_CYBER_VERSION );
    wp_enqueue_script( 'rdr-cyber-dashboard', RDR_CYBER_PLUGIN_URL.'assets/js/rdr-dashboard.js', array(), RDR_CYBER_VERSION, true );
    wp_localize_script( 'rdr-cyber-dashboard', 'rdrCyber', array(
        'ajaxurl'   => admin_url('admin-ajax.php'),
        'nonce'     => wp_create_nonce('rdr_cyber_nonce'),
        'feed_url'  => home_url('/feed/'.RDR_Feed::FEED_SLUG.'/'),
        'sw_url'    => home_url('/?rdr_cyber_sw=1'),
        'vapid_key' => RDR_Push::get_public_key_base64(),
        'push_on'   => RDR_Push::is_available() ? '1' : '0',
    ) );

    if ( RDR_Push::is_available() ) {
        wp_enqueue_script( 'rdr-cyber-push', RDR_CYBER_PLUGIN_URL.'assets/js/rdr-push.js', array('rdr-cyber-dashboard'), RDR_CYBER_VERSION, true );
    }
}

/* ---------- Assets admin ---------- */
add_action( 'admin_enqueue_scripts', 'rdr_cyber_admin_enqueue' );
function rdr_cyber_admin_enqueue( $hook ) {
    if ( strpos($hook,'rdr-cyber') === false ) return;
    wp_enqueue_style(  'rdr-cyber-admin',    RDR_CYBER_PLUGIN_URL.'assets/css/rdr-dashboard.css', array(), RDR_CYBER_VERSION );
    wp_enqueue_script( 'rdr-cyber-admin-js', RDR_CYBER_PLUGIN_URL.'assets/js/rdr-dashboard.js',  array(), RDR_CYBER_VERSION, true );
    wp_localize_script( 'rdr-cyber-admin-js', 'rdrCyber',
        array('ajaxurl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('rdr_cyber_nonce'),
              'feed_url'=>home_url('/feed/'.RDR_Feed::FEED_SLUG.'/'),
              'push_on'=>'0','sw_url'=>'','vapid_key'=>'') );
    wp_localize_script( 'rdr-cyber-admin-js', 'rdrCyberAdmin',
        array('ajaxurl'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('rdr_cyber_admin_nonce')) );
}

/* ---------- AJAX : fetch items (front) ---------- */
add_action( 'wp_ajax_rdr_cyber_fetch',        'rdr_cyber_ajax_fetch' );
add_action( 'wp_ajax_nopriv_rdr_cyber_fetch', 'rdr_cyber_ajax_fetch' );
function rdr_cyber_ajax_fetch() {
    check_ajax_referer('rdr_cyber_nonce','nonce');
    $search   = sanitize_text_field($_POST['search']   ??'');
    $source   = sanitize_text_field($_POST['source']   ??'');
    $severity = sanitize_text_field($_POST['severity'] ??'');
    $type     = sanitize_text_field($_POST['item_type']??'');
    $page     = max(1,intval($_POST['page']??1));
    $per_page = intval(get_option('rdr_cyber_per_page',12));

    $data  = RDR_Database::query_items($search,$source,$severity,$type,$page,$per_page);
    $total = RDR_Database::count_items($search,$source,$severity,$type);
    $pages = $total > 0 ? (int)ceil($total/$per_page) : 0;

    ob_start();
    foreach ($data as $item) { include RDR_CYBER_PLUGIN_DIR.'templates/card.php'; }
    wp_send_json_success(array('html'=>ob_get_clean(),'total'=>$total,'pages'=>$pages,'current'=>$page));
}

/* ---------- AJAX : collecte manuelle (admin) ---------- */
add_action( 'wp_ajax_rdr_cyber_collect', 'rdr_cyber_ajax_collect' );
function rdr_cyber_ajax_collect() {
    check_ajax_referer('rdr_cyber_admin_nonce','nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $result = RDR_Collector::collect_all();
    wp_send_json_success(array('inserted'=>$result));
}

/* ---------- AJAX : abonnement email (front) ---------- */
add_action( 'wp_ajax_rdr_cyber_subscribe',        'rdr_cyber_ajax_subscribe' );
add_action( 'wp_ajax_nopriv_rdr_cyber_subscribe', 'rdr_cyber_ajax_subscribe' );
function rdr_cyber_ajax_subscribe() {
    check_ajax_referer('rdr_cyber_nonce','nonce');
    $email     = sanitize_email(      $_POST['email']    ??'');
    $name      = sanitize_text_field( $_POST['name']     ??'');
    $frequency = sanitize_text_field( $_POST['frequency']??'weekly');
    if (!is_email($email)) wp_send_json_error(array('message'=>'Adresse email invalide.'));
    $result = RDR_Email::subscribe($email,$name,$frequency);
    if (is_wp_error($result)) wp_send_json_error(array('message'=>$result->get_error_message()));
    wp_send_json_success(array('message'=>'Un email de confirmation a été envoyé à '.esc_html($email).'.'));
}

/* ---------- AJAX : test file d'attente email (admin) ---------- */
add_action( 'wp_ajax_rdr_cyber_test_queue', 'rdr_cyber_ajax_test_queue' );
function rdr_cyber_ajax_test_queue() {
    check_ajax_referer('rdr_cyber_admin_nonce','nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    RDR_Email::process_queue();
    $stats = RDR_Database::get_queue_stats();
    wp_send_json_success(array('stats'=>$stats));
}

/* ---------- AJAX : email de test (admin) ---------- */
add_action( 'wp_ajax_rdr_cyber_test_email', 'rdr_cyber_ajax_test_email' );
function rdr_cyber_ajax_test_email() {
    check_ajax_referer('rdr_cyber_admin_nonce','nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $to   = sanitize_email($_POST['to'] ?? get_option('admin_email'));
    $sent = RDR_Email::raw_mail($to, '[Test] Digest cyber — '.get_bloginfo('name'), RDR_Email::build_test_digest());
    if ($sent) wp_send_json_success(array('message'=>'Email de test envoyé à '.esc_html($to).'.'));
    else       wp_send_json_error(array('message'=>"Échec de l'envoi. Vérifiez la configuration wp_mail."));
}

/* ---------- AJAX : abonnement push (front) ---------- */
add_action( 'wp_ajax_rdr_cyber_push_subscribe',        'rdr_cyber_ajax_push_subscribe' );
add_action( 'wp_ajax_nopriv_rdr_cyber_push_subscribe', 'rdr_cyber_ajax_push_subscribe' );
function rdr_cyber_ajax_push_subscribe() {
    check_ajax_referer('rdr_cyber_nonce','nonce');
    $endpoint = esc_url_raw(        $_POST['endpoint'] ??'');
    $p256dh   = sanitize_text_field($_POST['p256dh']   ??'');
    $auth     = sanitize_text_field($_POST['auth']     ??'');
    if (!$endpoint || !$p256dh || !$auth) wp_send_json_error(array('message'=>'Données manquantes.'));
    $ok = RDR_Database::save_push_subscription($endpoint,$p256dh,$auth);
    if ($ok) wp_send_json_success(array('message'=>'Abonnement push enregistré.'));
    else     wp_send_json_error(array('message'=>"Erreur lors de l'enregistrement."));
}

/* ---------- AJAX : désabonnement push (front) ---------- */
add_action( 'wp_ajax_rdr_cyber_push_unsubscribe',        'rdr_cyber_ajax_push_unsubscribe' );
add_action( 'wp_ajax_nopriv_rdr_cyber_push_unsubscribe', 'rdr_cyber_ajax_push_unsubscribe' );
function rdr_cyber_ajax_push_unsubscribe() {
    check_ajax_referer('rdr_cyber_nonce','nonce');
    $endpoint = esc_url_raw($_POST['endpoint'] ??'');
    if (!$endpoint) wp_send_json_error(array('message'=>'Endpoint manquant.'));
    RDR_Database::delete_push_subscription($endpoint);
    wp_send_json_success(array('message'=>'Désabonné des notifications push.'));
}

/* ---------- AJAX : test push (admin) ---------- */
add_action( 'wp_ajax_rdr_cyber_push_test', 'rdr_cyber_ajax_push_test' );
function rdr_cyber_ajax_push_test() {
    check_ajax_referer('rdr_cyber_admin_nonce','nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    if (!RDR_Push::is_available()) wp_send_json_error(array('message'=>'Web Push non disponible (OpenSSL + clés VAPID requis).'));
    $count = RDR_Push::send_all(array(
        'title' => 'Test — '.get_bloginfo('name'),
        'body'  => "Notification de test depuis l'administration.",
        'url'   => home_url('/'),
    ));
    wp_send_json_success(array('message'=>$count.' notification(s) envoyée(s).','sent'=>$count));
}

/* ---------- AJAX : génération clés VAPID (admin) ---------- */
add_action( 'wp_ajax_rdr_cyber_vapid_generate', 'rdr_cyber_ajax_vapid_generate' );
function rdr_cyber_ajax_vapid_generate() {
    check_ajax_referer('rdr_cyber_admin_nonce','nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');
    $keys = RDR_Push::generate_vapid_keys();
    if (!$keys) wp_send_json_error(array('message'=>'Impossible de générer les clés VAPID (OpenSSL P-256 requis).'));
    update_option('rdr_cyber_vapid_public',  $keys['public']);
    update_option('rdr_cyber_vapid_private', $keys['private']);
    wp_send_json_success(array('message'=>'Clés VAPID générées avec succès.','public'=>$keys['public']));
}

/* ---------- AJAX : payload push (GET/POST par le SW) ---------- */
add_action( 'wp_ajax_rdr_cyber_push_payload',        'rdr_cyber_ajax_push_payload' );
add_action( 'wp_ajax_nopriv_rdr_cyber_push_payload', 'rdr_cyber_ajax_push_payload' );
function rdr_cyber_push_payload() {
    $payload = get_option('rdr_cyber_push_last_payload', array());
    if (!$payload) {
        $items = RDR_Database::get_recent_items(1,'critical');
        if ($items) {
            $item    = $items[0];
            $payload = array(
                'title' => mb_substr(wp_strip_all_tags($item['source'].' — '.$item['title']),0,60),
                'body'  => mb_substr(wp_strip_all_tags($item['description']),0,100),
                'url'   => $item['link'] ?: home_url('/'),
            );
        } else {
            $payload = array('title'=>get_bloginfo('name').' Cyber','body'=>'Nouvelles alertes disponibles.','url'=>home_url('/'));
        }
    }
    wp_send_json($payload);
}
