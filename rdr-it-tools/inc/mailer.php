<?php
/**
 * RDR-IT Tools — Mailer v2
 *
 * Modes :
 *   php         wp_mail natif (sendmail/mail())
 *   smtp        PHPMailer SMTP
 *   mailjet     API REST Mailjet v3.1 via wp_remote_post()
 *
 * Options :
 *   rdrit_mail_mode            string  php | smtp | mailjet
 *   rdrit_mail_from_email      string  Adresse expediteur
 *   rdrit_mail_from_name       string  Nom expediteur
 *   rdrit_smtp_host            string  Serveur SMTP
 *   rdrit_smtp_port            int     Port (25, 465, 587)
 *   rdrit_smtp_encryption      string  none | ssl | tls
 *   rdrit_smtp_auth            bool    Authentification
 *   rdrit_smtp_user            string  Utilisateur SMTP
 *   rdrit_smtp_pass            string  Mot de passe SMTP (base64)
 *   rdrit_mailjet_api_key      string  Cle API Mailjet
 *   rdrit_mailjet_api_secret   string  Cle secrete Mailjet (base64)
 *   rdrit_mail_test_last       string  Resultat dernier test (JSON)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   MODE MAILJET — intercepte wp_mail() et envoie via API REST
   Doit etre hooké avant que PHPMailer soit initialisé.
   ============================================================ */
add_filter( 'pre_wp_mail', 'rdrit_mailjet_intercept', 10, 2 );
function rdrit_mailjet_intercept( $return, array $atts ) {
    if ( get_option( 'rdrit_mail_mode', 'php' ) !== 'mailjet' ) return $return;

    $api_key    = sanitize_text_field( get_option( 'rdrit_mailjet_api_key', '' ) );
    $api_secret_raw = get_option( 'rdrit_mailjet_api_secret', '' );
    $api_secret = $api_secret_raw ? base64_decode( $api_secret_raw ) : '';

    if ( ! $api_key || ! $api_secret ) return $return; // Fallback PHP si non configuré

    $from_email = sanitize_email( get_option( 'rdrit_mail_from_email', get_option('admin_email') ) );
    $from_name  = sanitize_text_field( get_option( 'rdrit_mail_from_name', get_bloginfo('name') ) );

    // Normaliser les destinataires
    $to = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', $atts['to'] );
    $recipients = array();
    foreach ( $to as $addr ) {
        $addr = trim( $addr );
        if ( is_email( $addr ) ) {
            $recipients[] = array( 'Email' => $addr );
        }
    }
    if ( empty( $recipients ) ) return false;

    // Corps du message
    $subject  = $atts['subject'] ?? '';
    $message  = $atts['message'] ?? '';
    $is_html  = false;

    // Detecter si HTML via les headers
    $headers = $atts['headers'] ?? array();
    if ( is_string( $headers ) ) $headers = explode( "\n", $headers );
    foreach ( $headers as $h ) {
        if ( stripos( $h, 'Content-Type: text/html' ) !== false ) {
            $is_html = true;
        }
    }

    $body = array(
        'Messages' => array(
            array(
                'From'    => array( 'Email' => $from_email, 'Name' => $from_name ),
                'To'      => $recipients,
                'Subject' => $subject,
            ),
        ),
    );

    if ( $is_html ) {
        $body['Messages'][0]['HTMLPart'] = $message;
        $body['Messages'][0]['TextPart'] = wp_strip_all_tags( $message );
    } else {
        $body['Messages'][0]['TextPart'] = $message;
    }

    // Pièces jointes
    if ( ! empty( $atts['attachments'] ) ) {
        $attachments = is_array( $atts['attachments'] ) ? $atts['attachments'] : array( $atts['attachments'] );
        $mj_attachments = array();
        foreach ( $attachments as $file ) {
            if ( file_exists( $file ) ) {
                $mj_attachments[] = array(
                    'ContentType'   => mime_content_type( $file ) ?: 'application/octet-stream',
                    'Filename'      => basename( $file ),
                    'Base64Content' => base64_encode( file_get_contents( $file ) ),
                );
            }
        }
        if ( ! empty( $mj_attachments ) ) {
            $body['Messages'][0]['Attachments'] = $mj_attachments;
        }
    }

    $response = wp_remote_post(
        'https://api.mailjet.com/v3.1/send',
        array(
            'timeout' => 15,
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
            ),
            'body' => wp_json_encode( $body ),
        )
    );

    if ( is_wp_error( $response ) ) {
        // Déclencher wp_mail_failed pour compatibilité
        do_action( 'wp_mail_failed', new WP_Error(
            'mailjet_error',
            $response->get_error_message()
        ) );
        return false;
    }

    $code = wp_remote_retrieve_response_code( $response );
    $resp_body = json_decode( wp_remote_retrieve_body( $response ), true );

    // Mailjet retourne 200 + status "success" par message
    $success = ( $code === 200 )
        && isset( $resp_body['Messages'][0]['Status'] )
        && $resp_body['Messages'][0]['Status'] === 'success';

    if ( ! $success ) {
        $error_msg = isset( $resp_body['Messages'][0]['Errors'] )
            ? wp_json_encode( $resp_body['Messages'][0]['Errors'] )
            : ( isset( $resp_body['ErrorMessage'] ) ? $resp_body['ErrorMessage'] : "HTTP {$code}" );

        do_action( 'wp_mail_failed', new WP_Error( 'mailjet_error', $error_msg ) );
        return false;
    }

    return true; // Court-circuite wp_mail() — envoi géré
}

/* ============================================================
   MODE SMTP — hook phpmailer_init
   ============================================================ */
add_action( 'phpmailer_init', 'rdrit_mailer_init' );
function rdrit_mailer_init( PHPMailer\PHPMailer\PHPMailer $phpmailer ) {
    $mode       = get_option( 'rdrit_mail_mode', 'php' );
    $from_email = sanitize_email( get_option( 'rdrit_mail_from_email', '' ) );
    $from_name  = sanitize_text_field( get_option( 'rdrit_mail_from_name', get_bloginfo('name') ) );

    if ( $from_email ) {
        $phpmailer->From     = $from_email;
        $phpmailer->FromName = $from_name;
    }

    if ( $mode !== 'smtp' ) return;

    $host       = sanitize_text_field( get_option( 'rdrit_smtp_host', '' ) );
    $port       = (int) get_option( 'rdrit_smtp_port', 587 );
    $encryption = get_option( 'rdrit_smtp_encryption', 'tls' );
    $auth       = (bool) get_option( 'rdrit_smtp_auth', 1 );
    $user       = sanitize_text_field( get_option( 'rdrit_smtp_user', '' ) );
    $pass_raw   = get_option( 'rdrit_smtp_pass', '' );
    $pass       = $pass_raw ? base64_decode( $pass_raw ) : '';

    if ( ! $host ) return;

    $phpmailer->isSMTP();
    $phpmailer->Host       = $host;
    $phpmailer->Port       = $port;
    $phpmailer->SMTPAuth   = $auth;
    $phpmailer->SMTPSecure = ( $encryption === 'ssl' )
        ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : ( ( $encryption === 'tls' ) ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '' );

    if ( $auth && $user ) {
        $phpmailer->Username = $user;
        $phpmailer->Password = $pass;
    }
}

/* ============================================================
   FILTRES FROM GLOBAL
   ============================================================ */
add_filter( 'wp_mail_from',      'rdrit_mail_from' );
add_filter( 'wp_mail_from_name', 'rdrit_mail_from_name' );

function rdrit_mail_from( string $email ): string {
    $custom = sanitize_email( get_option( 'rdrit_mail_from_email', '' ) );
    return $custom ?: $email;
}
function rdrit_mail_from_name( string $name ): string {
    $custom = sanitize_text_field( get_option( 'rdrit_mail_from_name', '' ) );
    return $custom ?: $name;
}

/* ============================================================
   TEST D'ENVOI (AJAX admin)
   ============================================================ */
add_action( 'wp_ajax_rdrit_mail_test', 'rdrit_mail_test_ajax' );
function rdrit_mail_test_ajax() {
    check_ajax_referer( 'rdrit_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $to = sanitize_email( $_POST['to'] ?? get_option('admin_email') );
    if ( ! is_email( $to ) ) wp_send_json_error( 'Adresse email invalide.' );

    $mode = get_option( 'rdrit_mail_mode', 'php' );

    $result = array(
        'time' => current_time( 'mysql' ),
        'to'   => $to,
        'mode' => $mode,
    );

    $errors = array();
    add_action( 'wp_mail_failed', function( WP_Error $e ) use ( &$errors ) {
        $errors[] = $e->get_error_message();
    } );

    $body = "Bonjour,\n\nCe mail confirme que la configuration fonctionne correctement.\n\n";
    $body .= "Mode : {$mode}\n";

    if ( $mode === 'smtp' ) {
        $body .= "Serveur : " . get_option('rdrit_smtp_host','—') . "\n";
        $body .= "Port : "    . get_option('rdrit_smtp_port','—') . "\n";
        $body .= "Chiffrement : " . get_option('rdrit_smtp_encryption','—') . "\n";
    } elseif ( $mode === 'mailjet' ) {
        $key = get_option('rdrit_mailjet_api_key','');
        $body .= "API Key : " . ( $key ? substr($key,0,6) . '****' : '—' ) . "\n";
        $body .= "Endpoint : https://api.mailjet.com/v3.1/send\n";
    }

    $body .= "\nEnvoye depuis : " . home_url() . "\n";
    $body .= "Date : " . current_time('d/m/Y H:i:s');

    $sent = wp_mail(
        $to,
        '[RDR-IT Tools] Test mail — ' . get_bloginfo('name'),
        $body
    );

    $result['success'] = $sent;
    $result['errors']  = $errors;

    update_option( 'rdrit_mail_test_last', wp_json_encode( $result ), false );

    if ( $sent ) {
        wp_send_json_success( array(
            'msg'    => "Mail envoye a <strong>{$to}</strong> via <em>{$mode}</em>",
            'result' => $result,
        ) );
    } else {
        $err = implode( ' / ', $errors ) ?: 'Erreur inconnue';
        wp_send_json_error( "Echec : {$err}" );
    }
}
