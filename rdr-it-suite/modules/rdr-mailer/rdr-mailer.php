<?php
/**
 * RDR-IT Suite — Module Mailer
 *
 * Transposition fidèle de RDR-IT Tools mailer.php v2 + settings-page.php.
 * Pattern AJAX identique au source : rdrit_save_option / rdrit_nonce.
 *
 * Clés wp_options originales conservées (R4).
 *
 * @package RDR\Modules\RdrMailer
 * @version 1.4.0
 */

namespace RDR\Modules\RdrMailer;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

\add_action( 'rdr_suite_module_loaded_rdr-mailer', static function (): void {

    // Guard cohabitation (R2)
    if ( \class_exists( 'RDRIT_Mailer' ) ) {
        return;
    }

    // ── Hooks mail — identiques au source (R3) ────────────────────────────
    if ( ! \has_filter( 'pre_wp_mail', 'rdrit_mailjet_intercept' ) ) {
        \add_filter( 'pre_wp_mail', __NAMESPACE__ . '\\mailjet_intercept', 10, 2 );
    }
    if ( ! \has_action( 'phpmailer_init', 'rdrit_mailer_init' ) ) {
        \add_action( 'phpmailer_init', __NAMESPACE__ . '\\mailer_init' );
    }
    if ( ! \has_filter( 'wp_mail_from', 'rdrit_mail_from' ) ) {
        \add_filter( 'wp_mail_from', __NAMESPACE__ . '\\mail_from' );
    }
    if ( ! \has_filter( 'wp_mail_from_name', 'rdrit_mail_from_name' ) ) {
        \add_filter( 'wp_mail_from_name', __NAMESPACE__ . '\\mail_from_name' );
    }

    // ── AJAX save — même action que le source : rdrit_save_option ─────────
    // Guard : si le source est actif il a déjà enregistré ce handler.
    if ( ! \has_action( 'wp_ajax_rdrit_save_option', 'rdrit_ajax_save_option' ) ) {
        \add_action( 'wp_ajax_rdrit_save_option', __NAMESPACE__ . '\\ajax_save_option' );
    }

    // ── AJAX test d'envoi ─────────────────────────────────────────────────
    if ( ! \has_action( 'wp_ajax_rdrit_mail_test', 'rdrit_mail_test_ajax' ) ) {
        \add_action( 'wp_ajax_rdrit_mail_test', __NAMESPACE__ . '\\handle_test' );
    }

    // ── Assets ───────────────────────────────────────────────────────────
    \add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\enqueue_assets' );

} );

// ── Mailjet — copie conforme du source ───────────────────────────────────────

function mailjet_intercept( $return, array $atts ) {
    if ( \get_option( 'rdrit_mail_mode', 'php' ) !== 'mailjet' ) {
        return $return;
    }

    $api_key        = \sanitize_text_field( \get_option( 'rdrit_mailjet_api_key', '' ) );
    $api_secret_raw = \get_option( 'rdrit_mailjet_api_secret', '' );
    $api_secret     = $api_secret_raw ? base64_decode( $api_secret_raw ) : '';

    if ( ! $api_key || ! $api_secret ) {
        return $return;
    }

    $from_email = \sanitize_email( \get_option( 'rdrit_mail_from_email', \get_option( 'admin_email' ) ) );
    $from_name  = \sanitize_text_field( \get_option( 'rdrit_mail_from_name', \get_bloginfo( 'name' ) ) );

    $to_raw     = is_array( $atts['to'] ) ? $atts['to'] : explode( ',', $atts['to'] );
    $recipients = [];
    foreach ( $to_raw as $addr ) {
        $addr = trim( $addr );
        if ( \is_email( $addr ) ) {
            $recipients[] = [ 'Email' => $addr ];
        }
    }
    if ( empty( $recipients ) ) {
        return false;
    }

    $headers = $atts['headers'] ?? [];
    if ( is_string( $headers ) ) {
        $headers = explode( "\n", $headers );
    }
    $is_html = false;
    foreach ( $headers as $h ) {
        if ( stripos( $h, 'Content-Type: text/html' ) !== false ) {
            $is_html = true;
            break;
        }
    }

    $message = $atts['message'] ?? '';
    $payload = [
        'Messages' => [ [
            'From'    => [ 'Email' => $from_email, 'Name' => $from_name ],
            'To'      => $recipients,
            'Subject' => $atts['subject'] ?? '',
        ] ],
    ];

    if ( $is_html ) {
        $payload['Messages'][0]['HTMLPart'] = $message;
        $payload['Messages'][0]['TextPart'] = \wp_strip_all_tags( $message );
    } else {
        $payload['Messages'][0]['TextPart'] = $message;
    }

    if ( ! empty( $atts['attachments'] ) ) {
        $files  = is_array( $atts['attachments'] ) ? $atts['attachments'] : [ $atts['attachments'] ];
        $attach = [];
        foreach ( $files as $file ) {
            if ( file_exists( $file ) ) {
                $attach[] = [
                    'ContentType'   => mime_content_type( $file ) ?: 'application/octet-stream',
                    'Filename'      => basename( $file ),
                    'Base64Content' => base64_encode( file_get_contents( $file ) ),
                ];
            }
        }
        if ( ! empty( $attach ) ) {
            $payload['Messages'][0]['Attachments'] = $attach;
        }
    }

    $response = \wp_remote_post( 'https://api.mailjet.com/v3.1/send', [
        'timeout' => 15,
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
        ],
        'body' => \wp_json_encode( $payload ),
    ] );

    if ( \is_wp_error( $response ) ) {
        \do_action( 'wp_mail_failed', new \WP_Error( 'mailjet_error', $response->get_error_message() ) );
        return false;
    }

    $code      = (int) \wp_remote_retrieve_response_code( $response );
    $resp_body = json_decode( \wp_remote_retrieve_body( $response ), true );
    $success   = $code === 200
        && isset( $resp_body['Messages'][0]['Status'] )
        && $resp_body['Messages'][0]['Status'] === 'success';

    if ( ! $success ) {
        $err = isset( $resp_body['Messages'][0]['Errors'] )
            ? \wp_json_encode( $resp_body['Messages'][0]['Errors'] )
            : ( $resp_body['ErrorMessage'] ?? "HTTP {$code}" );
        \do_action( 'wp_mail_failed', new \WP_Error( 'mailjet_error', $err ) );
        return false;
    }

    return true;
}

// ── SMTP — copie conforme + SMTPOptions SSL désactivé ────────────────────────

function mailer_init( \PHPMailer\PHPMailer\PHPMailer $phpmailer ): void {
    $mode       = \get_option( 'rdrit_mail_mode', 'php' );
    $from_email = \sanitize_email( \get_option( 'rdrit_mail_from_email', '' ) );
    $from_name  = \sanitize_text_field( \get_option( 'rdrit_mail_from_name', \get_bloginfo( 'name' ) ) );

    if ( $from_email ) {
        $phpmailer->From     = $from_email;
        $phpmailer->FromName = $from_name;
    }

    if ( $mode !== 'smtp' ) {
        return;
    }

    $host       = \sanitize_text_field( \get_option( 'rdrit_smtp_host', '' ) );
    $port       = (int) \get_option( 'rdrit_smtp_port', 587 );
    $encryption = \get_option( 'rdrit_smtp_encryption', 'tls' );
    $auth       = (bool) \get_option( 'rdrit_smtp_auth', 1 );
    $user       = \sanitize_text_field( \get_option( 'rdrit_smtp_user', '' ) );
    $pass_raw   = \get_option( 'rdrit_smtp_pass', '' );
    $pass       = $pass_raw ? base64_decode( $pass_raw ) : '';

    if ( ! $host ) {
        return;
    }

    $phpmailer->isSMTP();
    $phpmailer->Host     = $host;
    $phpmailer->Port     = $port;
    $phpmailer->SMTPAuth = $auth;
    $phpmailer->SMTPSecure = ( $encryption === 'ssl' )
        ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
        : ( $encryption === 'tls' ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : '' );

    // Vérification SSL désactivée en dur — non exposé comme option
    $phpmailer->SMTPOptions = [
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ],
    ];

    if ( $auth && $user ) {
        $phpmailer->Username = $user;
        $phpmailer->Password = $pass;
    }
}

// ── From filters — copie conforme du source ───────────────────────────────────

function mail_from( string $email ): string {
    $custom = \sanitize_email( \get_option( 'rdrit_mail_from_email', '' ) );
    return $custom ?: $email;
}

function mail_from_name( string $name ): string {
    $custom = \sanitize_text_field( \get_option( 'rdrit_mail_from_name', '' ) );
    return $custom ?: $name;
}

// ── AJAX save_option — même pattern que le source ─────────────────────────────
// Appelé par la fonction JS save(key, value, nonce) identique au source.

function ajax_save_option(): void {
    \check_ajax_referer( 'rdrit_nonce', 'nonce' );

    if ( ! \current_user_can( 'manage_options' ) ) {
        \wp_send_json_error( 'Unauthorized' );
    }

    $key   = \sanitize_key( $_POST['option_key'] ?? '' );
    $value = $_POST['value'] ?? '';

    // Sécurité : uniquement les clés rdrit_
    if ( strpos( $key, 'rdrit_' ) !== 0 ) {
        \wp_send_json_error( 'Clé non autorisée' );
        return;
    }

    // Les mots de passe arrivent déjà encodés en base64 depuis le JS
    // (btoa côté client) — on stocke tel quel, pas de double encodage.
    $value = \sanitize_text_field( $value );
    \update_option( $key, $value );

    \wp_send_json_success();
}

// ── AJAX test d'envoi — même pattern que le source ───────────────────────────

function handle_test(): void {
    \check_ajax_referer( 'rdrit_nonce', 'nonce' );

    if ( ! \current_user_can( 'manage_options' ) ) {
        \wp_send_json_error( 'Unauthorized' );
    }

    $to = \sanitize_email( $_POST['to'] ?? \get_option( 'admin_email' ) );
    if ( ! \is_email( $to ) ) {
        \wp_send_json_error( 'Adresse email invalide.' );
    }

    $mode   = \get_option( 'rdrit_mail_mode', 'php' );
    $errors = [];

    \add_action( 'wp_mail_failed', static function ( \WP_Error $e ) use ( &$errors ): void {
        $errors[] = $e->get_error_message();
    } );

    $body  = "Bonjour,\n\nCe mail confirme que la configuration fonctionne correctement.\n\n";
    $body .= "Mode : {$mode}\n";
    if ( $mode === 'smtp' ) {
        $body .= 'Serveur : '     . \get_option( 'rdrit_smtp_host', '—' ) . "\n";
        $body .= 'Port : '        . \get_option( 'rdrit_smtp_port', '—' ) . "\n";
        $body .= 'Chiffrement : ' . \get_option( 'rdrit_smtp_encryption', '—' ) . "\n";
    } elseif ( $mode === 'mailjet' ) {
        $key   = \get_option( 'rdrit_mailjet_api_key', '' );
        $body .= 'API Key : ' . ( $key ? substr( $key, 0, 6 ) . '****' : '—' ) . "\n";
        $body .= "Endpoint : https://api.mailjet.com/v3.1/send\n";
    }
    $body .= "\nEnvoyé depuis : " . \home_url() . "\n";
    $body .= 'Date : ' . \current_time( 'd/m/Y H:i:s' );

    $sent = \wp_mail( $to, '[RDR-IT Suite] Test mail — ' . \get_bloginfo( 'name' ), $body );

    $result = [
        'time'    => \current_time( 'mysql' ),
        'to'      => $to,
        'mode'    => $mode,
        'success' => $sent,
        'errors'  => $errors,
    ];

    \update_option( 'rdrit_mail_test_last', \wp_json_encode( $result ), false );

    if ( $sent ) {
        \wp_send_json_success( [
            'msg'    => "Mail envoyé à <strong>{$to}</strong> via <em>{$mode}</em>",
            'result' => $result,
        ] );
    } else {
        \wp_send_json_error( implode( ' / ', $errors ) ?: 'Erreur inconnue' );
    }
}

// ── Assets ────────────────────────────────────────────────────────────────────

function enqueue_assets( string $hook ): void {
    if ( strpos( $hook, 'rdr-mailer' ) === false ) {
        return;
    }

    \wp_enqueue_style(
        'rdr-mailer-css',
        \RDR_SUITE_URL . 'modules/rdr-mailer/assets/admin.css',
        [],
        '1.4.0'
    );

    \wp_enqueue_script(
        'rdr-mailer-js',
        \RDR_SUITE_URL . 'modules/rdr-mailer/assets/admin.js',
        [],
        '1.4.0',
        true
    );

    // Nonce identique au source — rdrit_nonce
    \wp_localize_script( 'rdr-mailer-js', 'RDR_MAILER', [
        'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
        'nonce'   => \wp_create_nonce( 'rdrit_nonce' ),
    ] );
}
