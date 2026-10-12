<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function rdrnl_table() {
    global $wpdb;
    return $wpdb->prefix . RDRNL_TABLE;
}

function rdrnl_create_table() {
    global $wpdb;
    $table      = rdrnl_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        email        VARCHAR(191) NOT NULL,
        name         VARCHAR(191) DEFAULT '',
        status       ENUM('active','pending','waitlist','unsubscribed') NOT NULL DEFAULT 'pending',
        token        VARCHAR(64) DEFAULT NULL,
        token_expiry DATETIME DEFAULT NULL,
        subscribed_at DATETIME DEFAULT NULL,
        confirmed_at  DATETIME DEFAULT NULL,
        ip           VARCHAR(64) DEFAULT '',
        PRIMARY KEY  (id),
        UNIQUE KEY   email (email),
        KEY          status (status),
        KEY          token (token)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/* ── Getters ── */

function rdrnl_get_subscriber_by_email( $email ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . rdrnl_table() . " WHERE email = %s",
        sanitize_email( $email )
    ) );
}

function rdrnl_get_subscriber_by_token( $token ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . rdrnl_table() . " WHERE token = %s",
        sanitize_text_field( $token )
    ) );
}

function rdrnl_count_active() {
    global $wpdb;
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . rdrnl_table() . " WHERE status = 'active'" );
}

function rdrnl_count_waitlist() {
    global $wpdb;
    return (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . rdrnl_table() . " WHERE status = 'waitlist'" );
}

function rdrnl_get_active_emails() {
    global $wpdb;
    return $wpdb->get_col( "SELECT email FROM " . rdrnl_table() . " WHERE status = 'active'" );
}

function rdrnl_get_all_subscribers( $status = null, $limit = 200, $offset = 0 ) {
    global $wpdb;
    $where = $status ? $wpdb->prepare( " WHERE status = %s", $status ) : '';
    return $wpdb->get_results(
        "SELECT * FROM " . rdrnl_table() . $where . " ORDER BY id DESC LIMIT " . intval($limit) . " OFFSET " . intval($offset)
    );
}

/* ── Actions ── */

function rdrnl_insert_subscriber( $email, $name = '', $status = 'pending', $token = null ) {
    global $wpdb;
    return $wpdb->insert( rdrnl_table(), [
        'email'         => sanitize_email( $email ),
        'name'          => sanitize_text_field( $name ),
        'status'        => $status,
        'token'         => $token,
        'token_expiry'  => $token ? date('Y-m-d H:i:s', strtotime('+24 hours') ) : null,
        'subscribed_at' => current_time('mysql'),
        'ip'            => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
    ], ['%s','%s','%s','%s','%s','%s','%s'] );
}

function rdrnl_confirm_subscriber( $id ) {
    global $wpdb;
    return $wpdb->update( rdrnl_table(), [
        'status'       => 'active',
        'token'        => null,
        'token_expiry' => null,
        'confirmed_at' => current_time('mysql'),
    ], ['id' => $id], ['%s','%s','%s','%s'], ['%d'] );
}

function rdrnl_update_subscriber_status( $id, $status ) {
    global $wpdb;
    return $wpdb->update( rdrnl_table(), ['status' => $status], ['id' => $id], ['%s'], ['%d'] );
}

function rdrnl_delete_subscriber( $id ) {
    global $wpdb;
    return $wpdb->delete( rdrnl_table(), ['id' => $id], ['%d'] );
}

function rdrnl_unsubscribe_by_token( $token ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM " . rdrnl_table() . " WHERE token = %s",
        sanitize_text_field($token)
    ));
    $result = $wpdb->update(
        rdrnl_table(),
        ['status' => 'unsubscribed', 'token' => null],
        ['token'  => sanitize_text_field( $token )],
        ['%s','%s'], ['%s']
    );
    if ( $result ) {
        // Revoke all profile access tokens
        if ( $row ) {
            rdrnl_profile_revoke_tokens( $row->id );
        }
        // Auto-promote next person in waitlist
        rdrnl_maybe_promote_waitlist();
    }
    return $result;
}

/**
 * Promote the oldest waitlist subscriber to active if below the limit.
 * Called automatically on every unsubscription.
 */
function rdrnl_maybe_promote_waitlist() {
    $settings = get_option( RDRNL_OPTION, [] );
    $max      = intval( $settings['max_subscribers'] ?? 0 );

    // If no limit, always promote
    if ( $max === 0 || rdrnl_count_active() < $max ) {
        $promoted = rdrnl_promote_from_waitlist();
        if ( $promoted ) {
            rdrnl_notify_waitlist_promoted( $promoted );
        }
    }
}

/**
 * Send notification email to a newly promoted subscriber.
 */
function rdrnl_notify_waitlist_promoted( $subscriber ) {
    $settings  = get_option( RDRNL_OPTION, [] );
    $from_name = $settings['from_name']  ?? get_bloginfo('name');
    $from_mail = $settings['from_email'] ?? get_option('admin_email');
    $subject   = 'Vous êtes maintenant inscrit(e) à ' . get_bloginfo('name');

    $greeting = $subscriber->name ? 'Bonjour ' . esc_html($subscriber->name) . ',' : 'Bonjour,';

    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="background:#0d1117;color:#e6edf3;font-family:sans-serif;font-size:15px;line-height:1.6;margin:0;padding:0">'
        . '<div style="max-width:520px;margin:40px auto;padding:0 16px">'
        . '<div style="background:#161b22;border-radius:12px;padding:32px 28px;border:1px solid rgba(255,255,255,0.08)">'
        . '<div style="font-size:22px;font-weight:800;margin-bottom:20px">RDR<span style="color:#2ea8ff">-IT</span></div>'
        . '<p>' . $greeting . '</p>'
        . '<p>Bonne nouvelle ! Une place s\'est lib&eacute;r&eacute;e et vous venez d\'&ecirc;tre ajout&eacute;(e) &agrave; la liste des abonn&eacute;s de la newsletter <strong>' . esc_html(get_bloginfo('name')) . '</strong>.</p>'
        . '<p>Vous recevrez le prochain r&eacute;cap hebdomadaire vendredi.</p>'
        . '<p style="font-size:12px;color:#8b949e;margin-top:20px">Vous &eacute;tiez en liste d\'attente depuis votre inscription.</p>'
        . '</div></div></body></html>';

    wp_mail( $subscriber->email, $subject, $body, [
        'Content-Type: text/html; charset=UTF-8',
        "From: {$from_name} <{$from_mail}>",
    ]);
}

/* ── Promote oldest waitlist entry to active ── */
function rdrnl_promote_from_waitlist() {
    global $wpdb;
    $row = $wpdb->get_row( "SELECT * FROM " . rdrnl_table() . " WHERE status='waitlist' ORDER BY id ASC LIMIT 1" );
    if ( $row ) {
        rdrnl_update_subscriber_status( $row->id, 'active' );
        return $row;
    }
    return null;
}

/* ── Generate unsubscribe token for a subscriber ── */
function rdrnl_get_or_create_unsub_token( $email ) {
    global $wpdb;
    $row = rdrnl_get_subscriber_by_email( $email );
    if ( ! $row ) return '';
    if ( $row->token ) return $row->token;
    $token = wp_generate_password( 32, false );
    $wpdb->update( rdrnl_table(), ['token' => $token], ['id' => $row->id], ['%s'], ['%d'] );
    return $token;
}
