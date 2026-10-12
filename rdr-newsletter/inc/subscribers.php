<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Subscribe a new email
 * Returns: array [ 'status' => 'pending'|'waitlist'|'already_active'|'already_pending'|'error', 'message' => '' ]
 */
function rdrnl_subscribe( $email, $name = '' ) {
    $email = sanitize_email( $email );
    $name  = sanitize_text_field( $name );

    if ( ! is_email( $email ) ) {
        return [ 'status' => 'error', 'message' => "Adresse email invalide." ];
    }

    $existing = rdrnl_get_subscriber_by_email( $email );

    if ( $existing ) {
        if ( $existing->status === 'active' ) {
            return [ 'status' => 'already_active', 'message' => "Vous êtes déjà abonné(e) à la newsletter." ];
        }
        if ( $existing->status === 'pending' ) {
            // Resend confirmation
            rdrnl_send_confirmation_email( $existing->email, $existing->name, $existing->token );
            return [ 'status' => 'already_pending', 'message' => "Un email de confirmation vous a été renvoyé. Vérifiez votre boîte mail." ];
        }
        if ( $existing->status === 'unsubscribed' ) {
            // Re-subscribe flow
            $token = wp_generate_password( 32, false );
            global $wpdb;
            $wpdb->update( rdrnl_table(), [
                'status'        => 'pending',
                'name'          => $name,
                'token'         => $token,
                'token_expiry'  => date('Y-m-d H:i:s', strtotime('+24 hours')),
                'subscribed_at' => current_time('mysql'),
            ], ['id' => $existing->id], ['%s','%s','%s','%s','%s'], ['%d'] );
            rdrnl_send_confirmation_email( $email, $name, $token );
            return [ 'status' => 'pending', 'message' => "Vérifiez votre boîte mail pour confirmer votre inscription." ];
        }
    }

    // New subscriber — check limit
    $settings = get_option( RDRNL_OPTION, [] );
    $max      = intval( $settings['max_subscribers'] ?? 0 );
    $active   = rdrnl_count_active();

    $token  = wp_generate_password( 32, false );
    $status = 'pending'; // will become active or waitlist after confirmation

    // Store destination status in a transient keyed by token
    $dest_status = ( $max > 0 && $active >= $max ) ? 'waitlist' : 'active';
    set_transient( 'rdrnl_dest_' . $token, $dest_status, DAY_IN_SECONDS );

    $result = rdrnl_insert_subscriber( $email, $name, 'pending', $token );

    if ( ! $result ) {
        return [ 'status' => 'error', 'message' => "Une erreur est survenue. Veuillez réessayer." ];
    }

    rdrnl_send_confirmation_email( $email, $name, $token );

    return [ 'status' => 'pending', 'message' => "Vérifiez votre boîte mail pour confirmer votre inscription." ];
}

/**
 * Confirm subscription via token
 */
function rdrnl_confirm( $token ) {
    $token = sanitize_text_field( $token );
    $row   = rdrnl_get_subscriber_by_token( $token );

    if ( ! $row ) {
        return [ 'status' => 'invalid', 'message' => "Ce lien de confirmation est invalide ou a expiré." ];
    }

    // Check expiry
    if ( $row->token_expiry && strtotime( $row->token_expiry ) < time() ) {
        return [ 'status' => 'expired', 'message' => "Ce lien de confirmation a expiré. Veuillez vous réinscrire." ];
    }

    // Determine final status
    $dest = get_transient( 'rdrnl_dest_' . $token );
    if ( ! $dest ) {
        // Fallback: check current capacity
        $settings = get_option( RDRNL_OPTION, [] );
        $max      = intval( $settings['max_subscribers'] ?? 0 );
        $active   = rdrnl_count_active();
        $dest     = ( $max > 0 && $active >= $max ) ? 'waitlist' : 'active';
    }
    delete_transient( 'rdrnl_dest_' . $token );

    global $wpdb;
    $wpdb->update( rdrnl_table(), [
        'status'       => $dest,
        'token'        => rdrnl_get_or_create_unsub_token( $row->email ), // reuse token as unsub token
        'token_expiry' => null,
        'confirmed_at' => current_time('mysql'),
    ], ['id' => $row->id], ['%s','%s','%s','%s'], ['%d'] );

    if ( $dest === 'waitlist' ) {
        return [ 'status' => 'waitlist', 'message' => "Votre email est confirm&eacute;. Vous avez &eacute;t&eacute; ajout&eacute;(e) &agrave; la liste d'attente et serez notifi&eacute;(e) d&egrave;s qu'une place se lib&egrave;re." ];
    }

    // Active — send welcome email with profile link
    rdrnl_send_welcome_email( $row->id, $row->email, $row->name );

    return [ 'status' => 'active', 'message' => "Vous &ecirc;tes maintenant abonn&eacute;(e) ! Vous recevrez le prochain r&eacute;cap hebdomadaire vendredi." ];
}

/**
 * Welcome email sent after double opt-in confirmation (active subscribers only).
 * Includes a direct profile personalisation link.
 */
function rdrnl_send_welcome_email( $sub_id, $email, $name ) {
    $settings  = get_option( RDRNL_OPTION, [] );
    $from_name = $settings['from_name']  ?? get_bloginfo('name');
    $from_mail = $settings['from_email'] ?? get_option('admin_email');
    $site      = get_bloginfo('name');
    $site_url  = home_url();

    // Canonical profile URL — generates one token and appends ?rdrnl_tok=.
    // (Previously this added a second, unused ?rdrnl_prof_token= param.)
    $profile_url = rdrnl_profile_get_url( $sub_id );

    $unsub_url  = rdrnl_build_unsub_url( $email );
    $greeting   = $name ? 'Bonjour ' . esc_html($name) . ' !' : 'Bonjour !';
    $subject    = 'Bienvenue sur la newsletter ' . $site;

    ob_start(); ?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="background:#0d1117;color:#e6edf3;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;margin:0;padding:0">
<div style="max-width:560px;margin:40px auto;padding:0 16px">
<div style="background:#161b22;border-radius:12px;padding:32px 28px;border:1px solid rgba(255,255,255,0.08)">

  <div style="font-size:22px;font-weight:800;margin-bottom:24px">RDR<span style="color:#2ea8ff">-IT</span></div>

  <p style="margin-bottom:16px"><?php echo $greeting; ?></p>

  <p style="margin-bottom:16px;color:#c9d1d9">
    Votre inscription à la newsletter <strong style="color:#e6edf3"><?php echo esc_html($site); ?></strong> est confirmée.
    Chaque vendredi à midi, vous recevrez un récap des actualités IT et des nouveaux tutoriels publiés sur le site.
  </p>

  <div style="background:#1c2128;border-radius:8px;padding:20px;margin:24px 0;border:1px solid rgba(46,168,255,0.15)">
    <p style="margin-bottom:10px;font-weight:600;color:#e6edf3">✨ Personnalisez votre récap</p>
    <p style="margin-bottom:16px;font-size:13px;color:#8b949e">
      Ajoutez jusqu'à 3 flux RSS personnels&nbsp;: vos blogs préférés, vos sources IT,
      vos veilles techniques. Ils seront intégrés à votre récap chaque semaine, en plus du contenu éditorial.
    </p>
    <p style="text-align:center">
      <a href="<?php echo esc_url($profile_url); ?>"
         style="background:#2ea8ff;color:#0d1117;font-weight:700;font-size:14px;text-decoration:none;border-radius:8px;padding:11px 24px;display:inline-block">
        Configurer mes flux RSS &rarr;
      </a>
    </p>
    <p style="font-size:11px;color:#484f58;text-align:center;margin-top:12px">
      Ce lien est valable 24h. Vous pouvez en redemander un depuis chaque email reçu.
    </p>
  </div>

  <p style="font-size:13px;color:#8b949e;margin-bottom:6px">
    En attendant, retrouvez les dernières éditions en ligne&nbsp;:
    <a href="<?php echo esc_url($site_url . '/newsletter/'); ?>" style="color:#2ea8ff">
      <?php echo esc_html($site_url . '/newsletter/'); ?>
    </a>
  </p>

  <hr style="border:none;border-top:1px solid rgba(255,255,255,0.07);margin:24px 0">

  <p style="font-size:12px;color:#484f58">
    Vous recevez cet email car vous venez de confirmer votre inscription.
    <a href="<?php echo esc_url($unsub_url); ?>" style="color:#484f58">Se désinscrire</a>
  </p>

</div>
</div>
</body></html>
<?php
    $body = ob_get_clean();

    wp_mail( $email, $subject, $body, [
        'Content-Type: text/html; charset=UTF-8',
        "From: {$from_name} <{$from_mail}>",
    ]);
}

/**
 * Send double opt-in confirmation email
 */
function rdrnl_send_confirmation_email( $email, $name, $token ) {
    $settings = get_option( RDRNL_OPTION, [] );
    $confirm_url = add_query_arg( [ 'rdrnl_action' => 'confirm', 'token' => $token ], home_url('/') );

    $subject  = $settings['confirm_subject'] ?? ( 'Confirm your subscription to ' . get_bloginfo('name') );
    $from_name= $settings['from_name']  ?? get_bloginfo('name');
    $from_mail= $settings['from_email'] ?? get_option('admin_email');

    $body = rdrnl_confirmation_email_html( $name, $confirm_url, $settings );

    wp_mail( $email, $subject, $body, [
        'Content-Type: text/html; charset=UTF-8',
        "From: {$from_name} <{$from_mail}>",
    ]);
}

function rdrnl_confirmation_email_html( $name, $confirm_url, $settings ) {
    $site   = get_bloginfo('name');
    $custom = $settings['confirm_message'] ?? '';
    $greeting = $name ? 'Hi ' . esc_html( $name ) . ',' : 'Hi,';
    ob_start();
    ?><!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="background:#0d1117;color:#e6edf3;font-family:sans-serif;font-size:15px;line-height:1.6;margin:0;padding:0">
<div style="max-width:520px;margin:40px auto;padding:0 16px">
  <div style="background:#161b22;border-radius:12px;padding:32px 28px;border:1px solid rgba(255,255,255,0.08)">
    <div style="font-size:22px;font-weight:800;margin-bottom:20px">RDR<span style="color:#2ea8ff">-IT</span></div>
    <p><?php echo $greeting; ?></p>
    <p>Thanks for subscribing to the <strong><?php echo esc_html($site); ?></strong> weekly recap.</p>
    <?php if ( $custom ) : ?><p><?php echo wp_kses_post( $custom ); ?></p><?php endif; ?>
    <p>Click the button below to confirm your email address. This link is valid for 24 hours.</p>
    <p style="text-align:center;margin:28px 0">
      <a href="<?php echo esc_url($confirm_url); ?>" style="background:#2ea8ff;color:#0d1117;font-weight:700;font-size:15px;text-decoration:none;border-radius:8px;padding:12px 28px;display:inline-block">Confirm my subscription</a>
    </p>
    <p style="font-size:12px;color:#8b949e">If you did not subscribe, ignore this email.</p>
    <hr style="border:none;border-top:1px solid rgba(255,255,255,0.07);margin:20px 0">
    <p style="font-size:12px;color:#484f58">Or copy this link: <?php echo esc_url($confirm_url); ?></p>
  </div>
</div>
</body></html><?php
    return ob_get_clean();
}

/* ── Handle confirmation & unsubscribe via GET ── */
add_action( 'init', 'rdrnl_handle_url_actions' );
function rdrnl_handle_url_actions() {
    $action = sanitize_text_field( $_GET['rdrnl_action'] ?? '' );
    $token  = sanitize_text_field( $_GET['token'] ?? '' );

    if ( ! $action || ! $token ) return;

    if ( $action === 'confirm' ) {
        $result = rdrnl_confirm( $token );
        wp_safe_redirect( add_query_arg( [
            'rdrnl_status'  => $result['status'],
            'rdrnl_message' => urlencode( $result['message'] ),
        ], home_url('/') ) );
        exit;
    }

    if ( $action === 'unsubscribe' ) {
        rdrnl_unsubscribe_by_token( $token );
        wp_safe_redirect( add_query_arg( [
            'rdrnl_status'  => 'unsubscribed',
            'rdrnl_message' => urlencode( 'Vous avez été désinscrit(e) avec succès.' ),
        ], home_url('/') ) );
        exit;
    }
}
