<?php
/**
 * RDR-IT Login Security — Anti brute-force + Anti-bot v2
 */
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── HELPERS ── */

function rdrit_login_get_ip(): string {
    foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ) as $key ) {
        if ( ! empty( $_SERVER[ $key ] ) ) {
            $ip = trim( $_SERVER[ $key ] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) return $ip;
        }
    }
    return '0.0.0.0';
}

function rdrit_login_opt( string $key, $default = null ) {
    return get_option( 'rdrit_ls_' . $key, $default );
}

function rdrit_login_log( array $data ): void {
    global $wpdb;
    $table = $wpdb->prefix . 'rdrit_login_logs';
    $wpdb->insert( $table, array_merge( array(
        'event_time'   => current_time( 'mysql' ),
        'event_type'   => 'attempt',
        'result'       => 'fail',
        'ip'           => rdrit_login_get_ip(),
        'user_agent'   => sanitize_text_field( substr( $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500 ) ),
        'email'        => '',
        'pwd_hash'     => '',
        'country_code' => '',
        'country_name' => '',
        'city'         => '',
        'ban_until'    => null,
    ), $data ) );
}

/* ── ANTI-BOT : HONEYPOT + MATH CHALLENGE ── */

add_action( 'login_form', 'rdrit_login_antibot_fields' );
function rdrit_login_antibot_fields(): void {
    if ( ! (bool) rdrit_login_opt( 'antibot_enabled', 1 ) ) return;
    if ( ! session_id() ) @session_start();

    $a = wp_rand(1, 9);
    $b = wp_rand(1, 9);
    $_SESSION['rdrit_math_a'] = $a;
    $_SESSION['rdrit_math_b'] = $b;
    $token = wp_create_nonce( 'rdrit_login_challenge' );

    echo '<p class="login-antibot" style="margin:10px 0">';
    echo '<label for="rdrit_math">Vérification : ' . $a . ' + ' . $b . ' = ?</label><br>';
    echo '<input type="text" name="rdrit_math" id="rdrit_math" class="input" autocomplete="off" required style="width:80px">';
    echo '</p>';
    echo '<div style="display:none" aria-hidden="true">';
    echo '<input type="text" name="rdrit_hp_name" tabindex="-1" autocomplete="off">';
    echo '<input type="email" name="rdrit_hp_email" tabindex="-1" autocomplete="off">';
    echo '</div>';
    echo '<input type="hidden" name="rdrit_nonce" value="' . esc_attr($token) . '">';
}

add_action( 'login_head', function() {
    if ( ! (bool) rdrit_login_opt( 'antibot_enabled', 1 ) ) return;
    echo '<style>.login-antibot label{font-size:14px;color:#3c434a;font-weight:400}</style>';
} );

/* ── VÉRIFICATION À LA SOUMISSION ── */

add_filter( 'authenticate', 'rdrit_login_check', 1, 3 );
function rdrit_login_check( $user, string $username, string $password ) {
    if ( empty( $username ) && empty( $password ) ) return $user;

    $ip           = rdrit_login_get_ip();
    $ban_duration = (int) rdrit_login_opt( 'ban_duration', 15 );
    $antibot      = (bool) rdrit_login_opt( 'antibot_enabled', 1 );

    global $wpdb;
    $table = $wpdb->prefix . 'rdrit_login_logs';

    // Vérifier si l'IP est actuellement bannie
    $ban_until = $wpdb->get_var( $wpdb->prepare(
        "SELECT ban_until FROM $table
         WHERE event_type='ban' AND ip=%s AND ban_until > %s
         ORDER BY ban_until DESC LIMIT 1",
        $ip, current_time('mysql')
    ) );

    if ( $ban_until ) {
        // Logger la tentative pendant le ban
        rdrit_login_log( array(
            'event_type' => 'attempt',
            'result'     => 'blocked',
            'ip'         => $ip,
            'email'      => sanitize_text_field( $username ),
        ) );

        // Prolonger le ban à chaque nouvelle tentative pendant le ban
        $new_ban_until = date( 'Y-m-d H:i:s', current_time('timestamp') + $ban_duration * 60 );
        $wpdb->query( $wpdb->prepare(
            "UPDATE $table SET ban_until=%s
             WHERE event_type='ban' AND ip=%s AND ban_until > %s",
            $new_ban_until, $ip, current_time('mysql')
        ) );

        // 403 + page blanche — pas de formulaire de login
        status_header( 403 );
        nocache_headers();
        $remaining = human_time_diff( current_time('timestamp'), strtotime($new_ban_until) );
        die( '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Accès refusé</title>'
            . '<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f1f1f1}'
            . '.box{background:#fff;border-radius:8px;padding:40px;text-align:center;max-width:400px;box-shadow:0 2px 8px rgba(0,0,0,.1)}'
            . 'h1{color:#dc2626;font-size:1.4em;margin:0 0 12px}p{color:#374151;margin:0}'
            . 'small{color:#9ca3af;font-size:.85em}'
            . '</style></head><body>'
            . '<div class="box"><h1>Accès temporairement bloqué</h1>'
            . '<p>Trop de tentatives de connexion depuis votre adresse IP.</p>'
            . '<p style="margin-top:10px"><small>Réessayez dans ' . esc_html($remaining) . '</small></p>'
            . '</div></body></html>'
        );
    }

    // Anti-bot
    if ( $antibot && isset( $_POST['rdrit_nonce'] ) ) {
        if ( ! empty( $_POST['rdrit_hp_name'] ) || ! empty( $_POST['rdrit_hp_email'] ) ) {
            rdrit_login_log( array(
                'event_type' => 'attempt',
                'result'     => 'bot',
                'ip'         => $ip,
                'email'      => sanitize_text_field( $username ),
            ) );
            return new WP_Error( 'rdrit_bot', '<strong>Erreur de vérification.</strong>' );
        }

        if ( ! session_id() ) @session_start();
        $expected  = ( $_SESSION['rdrit_math_a'] ?? 0 ) + ( $_SESSION['rdrit_math_b'] ?? 0 );
        $submitted = (int) ( $_POST['rdrit_math'] ?? -999 );
        if ( $submitted !== $expected ) {
            rdrit_login_log( array(
                'event_type' => 'attempt',
                'result'     => 'bot',
                'ip'         => $ip,
                'email'      => sanitize_text_field( $username ),
            ) );
            return new WP_Error( 'rdrit_captcha', '<strong>Calcul incorrect.</strong> Veuillez réessayer.' );
        }
    }

    return $user;
}

/* ── APRÈS L'AUTHENTIFICATION : LOGGER LE RÉSULTAT ── */

add_action( 'wp_login_failed', 'rdrit_login_on_failure' );
function rdrit_login_on_failure( string $username ): void {
    $ip           = rdrit_login_get_ip();
    $max_attempts = (int) rdrit_login_opt( 'max_attempts', 5 );
    $ban_duration = (int) rdrit_login_opt( 'ban_duration', 15 );
    $window       = (int) rdrit_login_opt( 'window_minutes', 30 );

    $geo = array( 'country_code' => '', 'country_name' => '', 'city' => '' );
    if ( (bool) rdrit_login_opt( 'geo_enabled', 1 ) ) {
        $geo = rdrit_geo_lookup( $ip );
    }

    // Hash du mot de passe — le champ WP s'appelle 'pwd' sur la page login
    $pwd_hash = '';
    if ( ! empty( $_POST['pwd'] ) ) {
        $pwd_hash = hash( 'sha256', $_POST['pwd'] );
    }

    // Enregistrer la tentative
    rdrit_login_log( array(
        'event_type'   => 'attempt',
        'result'       => 'fail',
        'ip'           => $ip,
        'email'        => sanitize_text_field( $username ), // ← text_field, pas sanitize_email
        'pwd_hash'     => $pwd_hash,
        'country_code' => $geo['country_code'],
        'country_name' => $geo['country_name'],
        'city'         => $geo['city'],
    ) );

    // Compter les échecs récents
    global $wpdb;
    $table = $wpdb->prefix . 'rdrit_login_logs';
    $since = date( 'Y-m-d H:i:s', current_time('timestamp') - $window * 60 );
    $count = (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM $table
         WHERE ip=%s AND result='fail' AND event_type='attempt' AND event_time > %s",
        $ip, $since
    ) );

    if ( $count >= $max_attempts ) {
        $ban_until = date( 'Y-m-d H:i:s', current_time('timestamp') + $ban_duration * 60 );

        // Vérifier s'il y a déjà un ban actif pour cette IP → UPDATE plutôt qu'INSERT
        $existing_ban_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM $table
             WHERE event_type='ban' AND ip=%s AND ban_until > %s
             ORDER BY id DESC LIMIT 1",
            $ip, current_time('mysql')
        ) );

        if ( $existing_ban_id ) {
            // Prolonger le ban existant
            $wpdb->update(
                $table,
                array( 'ban_until' => $ban_until ),
                array( 'id' => $existing_ban_id )
            );
        } else {
            // Créer un nouveau ban
            rdrit_login_log( array(
                'event_type'   => 'ban',
                'result'       => 'blocked',
                'ip'           => $ip,
                'email'        => sanitize_text_field( $username ),
                'country_code' => $geo['country_code'],
                'country_name' => $geo['country_name'],
                'city'         => $geo['city'],
                'ban_until'    => $ban_until,
            ) );
        }
    }
}

add_action( 'wp_login', 'rdrit_login_on_success', 10, 2 );
function rdrit_login_on_success( string $user_login, WP_User $user ): void {
    $ip  = rdrit_login_get_ip();
    $geo = (bool) rdrit_login_opt( 'geo_enabled', 1 )
        ? rdrit_geo_lookup( $ip )
        : array( 'country_code' => '', 'country_name' => '', 'city' => '' );

    rdrit_login_log( array(
        'event_type'   => 'attempt',
        'result'       => 'success',
        'ip'           => $ip,
        'email'        => $user->user_email,
        'country_code' => $geo['country_code'],
        'country_name' => $geo['country_name'],
        'city'         => $geo['city'],
    ) );
}

/* ── MOT DE PASSE OUBLIÉ ── */

// Masquer le lien — plusieurs sélecteurs pour couvrir tous les thèmes WP login
add_action( 'login_footer', 'rdrit_login_hide_lost_password' );
function rdrit_login_hide_lost_password(): void {
    if ( ! (bool) rdrit_login_opt( 'hide_lost_password', 0 ) ) return;
    // Cibler toutes les occurrences possibles du lien lost-password
    echo '<style>
        #nav a[href*="lost-password"],
        #backtoblog + p a[href*="lost-password"],
        .login #nav a[href*="lost-password"],
        p.forgetmenot + p a,
        a[href*="lost-password"] {
            display:none!important;
        }
        /* Masquer aussi le paragraphe #nav vide */
        #nav:empty { display:none!important; }
    </style>
    <script>
    document.querySelectorAll("a[href*=\'lost-password\']").forEach(function(a){
        a.style.setProperty("display","none","important");
        var p = a.parentElement;
        if(p && p.children.length === 1) p.style.setProperty("display","none","important");
    });
    </script>';
}

// Logger les demandes de reset password
add_action( 'lostpassword_post', 'rdrit_login_log_pwd_reset' );
function rdrit_login_log_pwd_reset(): void {
    if ( ! (bool) rdrit_login_opt( 'log_pwd_reset', 1 ) ) return;
    $login = sanitize_text_field( $_POST['user_login'] ?? '' );
    $ip    = rdrit_login_get_ip();
    $geo   = (bool) rdrit_login_opt( 'geo_enabled', 1 )
        ? rdrit_geo_lookup( $ip )
        : array( 'country_code' => '', 'country_name' => '', 'city' => '' );

    rdrit_login_log( array(
        'event_type'   => 'pwd_reset',
        'result'       => 'attempt',
        'ip'           => $ip,
        'email'        => $login,
        'country_code' => $geo['country_code'],
        'country_name' => $geo['country_name'],
        'city'         => $geo['city'],
    ) );
}

/* ── NETTOYAGE AUTOMATIQUE ── */

add_action( 'rdrit_login_cleanup', 'rdrit_login_do_cleanup' );
function rdrit_login_do_cleanup(): void {
    global $wpdb;
    $table     = $wpdb->prefix . 'rdrit_login_logs';
    $retention = (int) rdrit_login_opt( 'log_retention_days', 90 );
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM $table WHERE event_time < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $retention
    ) );
}

if ( ! wp_next_scheduled( 'rdrit_login_cleanup' ) ) {
    wp_schedule_event( time(), 'daily', 'rdrit_login_cleanup' );
}
