<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   TRACKING TABLE
   wp_rdrnl_tracking
   - One row per (subscriber, edition)
   - Pixel open + click timestamps
   - Reengagement token + expiry
   ============================================================ */

function rdrnl_tracking_table() {
    global $wpdb;
    return $wpdb->prefix . RDRNL_TRACK_TABLE;
}

function rdrnl_create_tracking_table() {
    global $wpdb;
    $t   = rdrnl_tracking_table();
    $col = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$t} (
        id                   BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        subscriber_id        BIGINT(20) UNSIGNED NOT NULL,
        edition_slug         VARCHAR(191)        NOT NULL DEFAULT '',
        pixel_token          VARCHAR(64)         NOT NULL DEFAULT '',
        sent_at              DATETIME            DEFAULT NULL,
        opened_at            DATETIME            DEFAULT NULL,
        last_click_at        DATETIME            DEFAULT NULL,
        open_count           INT UNSIGNED        NOT NULL DEFAULT 0,
        click_count          INT UNSIGNED        NOT NULL DEFAULT 0,
        reengagement_sent_at DATETIME            DEFAULT NULL,
        reengagement_token   VARCHAR(64)         DEFAULT NULL,
        reengagement_expiry  DATETIME            DEFAULT NULL,
        PRIMARY KEY          (id),
        UNIQUE KEY           sub_edition (subscriber_id, edition_slug),
        KEY                  pixel_token (pixel_token),
        KEY                  reengagement_token (reengagement_token),
        KEY                  subscriber_id (subscriber_id)
    ) {$col};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/* ── Create a tracking row for one recipient of one edition ── */
function rdrnl_tracking_create( $sub_id, $edition_slug ) {
    global $wpdb;

    // No tracking for test sends (sub_id = 0) or anonymous
    if ( ! intval($sub_id) ) return '';

    $token = wp_generate_password( 32, false );

    // INSERT IGNORE avoids duplicate entry on repeated sends to same subscriber+edition
    $wpdb->query( $wpdb->prepare(
        "INSERT IGNORE INTO " . rdrnl_tracking_table() .
        " (subscriber_id, edition_slug, pixel_token, sent_at) VALUES (%d, %s, %s, %s)",
        intval($sub_id),
        sanitize_text_field($edition_slug),
        $token,
        current_time('mysql')
    ));

    return $token;
}

function rdrnl_tracking_get_by_pixel( $token ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . rdrnl_tracking_table() . " WHERE pixel_token = %s",
        sanitize_text_field($token)
    ));
}

function rdrnl_tracking_get_by_reengagement( $token ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM " . rdrnl_tracking_table() . " WHERE reengagement_token = %s",
        sanitize_text_field($token)
    ));
}

/* ── Record an open ── */
function rdrnl_tracking_record_open( $row_id ) {
    global $wpdb;
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT opened_at FROM " . rdrnl_tracking_table() . " WHERE id = %d", $row_id
    ));
    $data = ['open_count' => 'open_count + 1'];
    $fmt  = [];
    if ( ! $existing || ! $existing->opened_at ) {
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . rdrnl_tracking_table() .
            " SET open_count = open_count + 1, opened_at = %s WHERE id = %d",
            current_time('mysql'), $row_id
        ));
    } else {
        $wpdb->query( $wpdb->prepare(
            "UPDATE " . rdrnl_tracking_table() . " SET open_count = open_count + 1 WHERE id = %d",
            $row_id
        ));
    }
}

/* ── Record a click ── */
function rdrnl_tracking_record_click( $sub_id ) {
    global $wpdb;
    $wpdb->query( $wpdb->prepare(
        "UPDATE " . rdrnl_tracking_table() .
        " SET click_count = click_count + 1, last_click_at = %s" .
        " WHERE subscriber_id = %d ORDER BY sent_at DESC LIMIT 1",
        current_time('mysql'), intval($sub_id)
    ));
}

/* ── Build pixel URL for a token ── */
function rdrnl_pixel_url( $token ) {
    return add_query_arg( [ 'rdrnl_track' => 'open', 'tk' => $token ], home_url('/') );
}

/* ── Build tracked click URL ── */
function rdrnl_click_url( $destination_url, $sub_id, $edition_slug ) {
    return add_query_arg( [
        'rdrnl_track' => 'click',
        'sub'         => intval($sub_id),
        'ed'          => urlencode($edition_slug),
        'url'         => urlencode($destination_url),
    ], home_url('/') );
}

/* ── Last activity date for a subscriber ── */
function rdrnl_subscriber_last_activity( $sub_id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT MAX(opened_at) AS last_open, MAX(last_click_at) AS last_click
         FROM " . rdrnl_tracking_table() . " WHERE subscriber_id = %d",
        intval($sub_id)
    ));
    if ( ! $row ) return null;
    $dates = array_filter( [ $row->last_open, $row->last_click ] );
    if ( empty($dates) ) return null;
    return max( array_map('strtotime', $dates) );
}

/* ============================================================
   URL HANDLERS  (pixel + click redirect)
   ============================================================ */
add_action( 'init', 'rdrnl_handle_tracking_urls', 5 );
function rdrnl_handle_tracking_urls() {
    $track = sanitize_text_field( $_GET['rdrnl_track'] ?? '' );
    if ( ! $track ) return;

    if ( $track === 'open' ) {
        $token = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['tk'] ?? '');
        if ( $token ) {
            $row = rdrnl_tracking_get_by_pixel( $token );
            if ( $row ) {
                rdrnl_tracking_record_open( $row->id );
            }
        }
        // Serve 1×1 transparent GIF
        header( 'Content-Type: image/gif' );
        header( 'Cache-Control: no-store, no-cache, must-revalidate' );
        header( 'Pragma: no-cache' );
        echo base64_decode(
            'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'
        );
        exit;
    }

    if ( $track === 'click' ) {
        $sub_id       = intval( $_GET['sub'] ?? 0 );
        $edition_slug = sanitize_text_field( urldecode( $_GET['ed'] ?? '' ) );
        $dest         = esc_url_raw( urldecode( $_GET['url'] ?? '' ) );

        // Security: only redirect to http(s) URLs, block javascript:/data:/etc.
        $scheme = parse_url( $dest, PHP_URL_SCHEME );
        if ( ! in_array( strtolower($scheme), ['http','https'], true ) ) {
            $dest = home_url('/');
        }

        // Security: block redirects to private/local addresses (SSRF protection)
        $host = parse_url( $dest, PHP_URL_HOST );
        if ( $host && rdrnl_is_private_host($host) ) {
            $dest = home_url('/');
        }

        if ( $sub_id ) {
            rdrnl_tracking_record_click( $sub_id );
        }

        wp_redirect( $dest ?: home_url('/'), 302 );
        exit;
    }

    if ( $track === 'reengagement' ) {
        $token = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['tk'] ?? '');
        if ( strlen($token) < 16 || strlen($token) > 64 ) {
            wp_redirect( home_url('/'), 302 );
            exit;
        }
        rdrnl_handle_reengagement_click( $token );
        exit;
    }
}

/* ============================================================
   REENGAGEMENT SYSTEM
   Cron: Tuesday 12:00 — detect 4-week inactive subscribers
   Cron: Wednesday 12:00 — clean up expired tokens → unsub
   ============================================================ */

add_action( 'rdrnl_cron_reengagement', 'rdrnl_cron_reengagement_handler' );
add_action( 'rdrnl_cron_cleanup',      'rdrnl_cron_cleanup_handler' );

/* ── Schedule both crons ── */
function rdrnl_schedule_reengagement_crons() {
    if ( ! wp_next_scheduled('rdrnl_cron_reengagement') ) {
        wp_schedule_event( time(), 'hourly', 'rdrnl_cron_reengagement' );
    }
    if ( ! wp_next_scheduled('rdrnl_cron_cleanup') ) {
        wp_schedule_event( time(), 'hourly', 'rdrnl_cron_cleanup' );
    }
}

function rdrnl_clear_reengagement_crons() {
    foreach ( ['rdrnl_cron_reengagement','rdrnl_cron_cleanup'] as $hook ) {
        $ts = wp_next_scheduled($hook);
        if ($ts) wp_unschedule_event($ts, $hook);
    }
}

/* ── Tuesday 12:00 — send reengagement emails ── */
function rdrnl_cron_reengagement_handler() {
    $tz       = wp_timezone();
    $now      = new DateTimeImmutable('now', $tz);
    $cur_dow  = intval($now->format('N')); // 2 = Tuesday
    $cur_hour = intval($now->format('G'));

    if ( $cur_dow !== 2 || $cur_hour !== 12 ) return;

    // Already ran this week?
    $last = get_option('rdrnl_reengagement_last_run', 0);
    if ( date('Y-W', $last) === $now->format('Y-W') ) return;

    $sent = rdrnl_send_reengagement_batch();
    update_option('rdrnl_reengagement_last_run', time());

    if ($sent > 0) {
        rdrnl_log_reengagement( $sent, 'reengagement_sent' );
    }
}

/* ── Wednesday 12:00 — cleanup expired tokens ── */
function rdrnl_cron_cleanup_handler() {
    $tz       = wp_timezone();
    $now      = new DateTimeImmutable('now', $tz);
    $cur_dow  = intval($now->format('N')); // 3 = Wednesday
    $cur_hour = intval($now->format('G'));

    if ( $cur_dow !== 3 || $cur_hour !== 12 ) return;

    $last = get_option('rdrnl_cleanup_last_run', 0);
    if ( date('Y-W', $last) === $now->format('Y-W') ) return;

    $removed = rdrnl_cleanup_expired_reengagements();
    update_option('rdrnl_cleanup_last_run', time());

    if ($removed > 0) {
        rdrnl_log_reengagement( $removed, 'auto_unsubscribed' );
    }
}

/* ── Find inactive subscribers and send reengagement email ── */
function rdrnl_send_reengagement_batch() {
    global $wpdb;

    $four_weeks_ago = date( 'Y-m-d H:i:s', strtotime('-28 days') );
    $sub_table      = rdrnl_table();
    $track_table    = rdrnl_tracking_table();
    $sent           = 0;

    // Active subscribers who:
    // A) Have tracking data but no open AND no click in 4 weeks
    // B) Never had any tracking data and subscribed more than 4 weeks ago
    // AND have not already received a pending reengagement email

    $candidates = $wpdb->get_results( "
        SELECT s.id, s.email, s.name, s.subscribed_at
        FROM {$sub_table} s
        WHERE s.status = 'active'
        AND NOT EXISTS (
            SELECT 1 FROM {$track_table} t
            WHERE t.subscriber_id = s.id
              AND t.reengagement_token IS NOT NULL
              AND t.reengagement_expiry > NOW()
        )
        AND (
            /* Never had any activity tracked at all and subscribed 4+ weeks ago */
            (
                NOT EXISTS (SELECT 1 FROM {$track_table} t2 WHERE t2.subscriber_id = s.id)
                AND s.subscribed_at <= '{$four_weeks_ago}'
            )
            OR
            /* Has tracking but no open AND no click in last 4 weeks */
            (
                EXISTS (SELECT 1 FROM {$track_table} t3 WHERE t3.subscriber_id = s.id)
                AND NOT EXISTS (
                    SELECT 1 FROM {$track_table} t4
                    WHERE t4.subscriber_id = s.id
                      AND (
                          (t4.opened_at IS NOT NULL AND t4.opened_at >= '{$four_weeks_ago}')
                          OR
                          (t4.last_click_at IS NOT NULL AND t4.last_click_at >= '{$four_weeks_ago}')
                      )
                )
            )
        )
        LIMIT 100
    " );

    if ( empty($candidates) ) return 0;

    $settings  = get_option( RDRNL_OPTION, [] );
    $from_name = $settings['from_name']  ?? get_bloginfo('name');
    $from_mail = $settings['from_email'] ?? get_option('admin_email');

    foreach ( $candidates as $sub ) {
        $token   = wp_generate_password( 32, false );
        $expiry  = date( 'Y-m-d H:i:s', strtotime('+14 days') );
        $confirm_url = add_query_arg([
            'rdrnl_track' => 'reengagement',
            'tk'          => $token,
        ], home_url('/'));

        // Store token in tracking table (linked to latest tracking row, or insert a stub)
        $latest = $wpdb->get_row( $wpdb->prepare(
            "SELECT id FROM {$track_table} WHERE subscriber_id = %d ORDER BY sent_at DESC LIMIT 1",
            $sub->id
        ));

        if ( $latest ) {
            $wpdb->update( $track_table, [
                'reengagement_sent_at' => current_time('mysql'),
                'reengagement_token'   => $token,
                'reengagement_expiry'  => $expiry,
            ], ['id' => $latest->id], ['%s','%s','%s'], ['%d'] );
        } else {
            // No tracking row yet — create a stub row to hold the token
            $wpdb->insert( $track_table, [
                'subscriber_id'        => $sub->id,
                'edition_slug'         => '__reengagement__',
                'pixel_token'          => wp_generate_password(32,false),
                'reengagement_sent_at' => current_time('mysql'),
                'reengagement_token'   => $token,
                'reengagement_expiry'  => $expiry,
            ], ['%d','%s','%s','%s','%s','%s'] );
        }

        // Send email
        $html = rdrnl_reengagement_email_html( $sub, $confirm_url, $expiry, $settings );
        $ok   = wp_mail( $sub->email,
            'Toujours là ? Confirmez votre abonnement à ' . get_bloginfo('name'),
            $html,
            [
                'Content-Type: text/html; charset=UTF-8',
                "From: {$from_name} <{$from_mail}>",
            ]
        );

        if ($ok) $sent++;
    }

    return $sent;
}

/* ── Reengagement email HTML ── */
function rdrnl_reengagement_email_html( $sub, $confirm_url, $expiry, $settings ) {
    $greeting   = $sub->name ? 'Bonjour ' . esc_html($sub->name) . ',' : 'Bonjour,';
    $site       = get_bloginfo('name');
    $site_url   = home_url();
    $expiry_fmt = date_i18n('j F Y', strtotime($expiry));
    $unsub_url  = rdrnl_build_unsub_url($sub->email);

    ob_start(); ?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="background:#0d1117;color:#e6edf3;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.6;margin:0;padding:0">
<div style="max-width:520px;margin:40px auto;padding:0 16px">
<div style="background:#161b22;border-radius:12px;padding:32px 28px;border:1px solid rgba(255,255,255,0.08)">

  <div style="font-size:22px;font-weight:800;margin-bottom:24px">RDR<span style="color:#2ea8ff">-IT</span></div>

  <p style="margin-bottom:12px"><?php echo $greeting; ?></p>

  <p style="margin-bottom:12px;color:#c9d1d9">
    Nous n'avons détecté aucune ouverture ni clic de votre part dans nos emails depuis
    <strong style="color:#e6edf3">4 semaines</strong>.
  </p>

  <p style="margin-bottom:24px;color:#c9d1d9">
    Pour continuer à recevoir le récap hebdomadaire <strong style="color:#e6edf3"><?php echo esc_html($site); ?></strong>,
    cliquez simplement sur le bouton ci-dessous. Sans action de votre part avant le
    <strong style="color:#fbbf24"><?php echo $expiry_fmt; ?></strong>,
    votre abonnement sera automatiquement supprimé.
  </p>

  <p style="text-align:center;margin:32px 0">
    <a href="<?php echo esc_url($confirm_url); ?>"
       style="background:#2ea8ff;color:#0d1117;font-weight:700;font-size:16px;text-decoration:none;border-radius:8px;padding:14px 32px;display:inline-block">
      ✅ Oui, je veux rester abonné(e)
    </a>
  </p>

  <hr style="border:none;border-top:1px solid rgba(255,255,255,0.07);margin:24px 0">

  <p style="font-size:12px;color:#8b949e;margin-bottom:8px">
    Si vous souhaitez vous désinscrire dès maintenant :
    <a href="<?php echo esc_url($unsub_url); ?>" style="color:#8b949e">Se désinscrire</a>
  </p>
  <p style="font-size:12px;color:#484f58">
    Ou copiez ce lien : <?php echo esc_url($confirm_url); ?>
  </p>

</div>
</div>
</body></html>
<?php return ob_get_clean();
}

/* ── Handle reengagement confirmation click ── */
function rdrnl_handle_reengagement_click( $token ) {
    global $wpdb;
    $track_table = rdrnl_tracking_table();

    $row = rdrnl_tracking_get_by_reengagement( $token );

    if ( ! $row ) {
        // Token invalid
        wp_redirect( add_query_arg([
            'rdrnl_status'  => 'reengagement_invalid',
            'rdrnl_message' => urlencode('Ce lien est invalide ou a déjà été utilisé.'),
        ], home_url('/') ), 302 );
        exit;
    }

    if ( strtotime($row->reengagement_expiry) < time() ) {
        wp_redirect( add_query_arg([
            'rdrnl_status'  => 'reengagement_expired',
            'rdrnl_message' => urlencode('Ce lien a expiré. Votre abonnement a été supprimé.'),
        ], home_url('/') ), 302 );
        exit;
    }

    // Valid — mark as confirmed, clear token
    $wpdb->update( $track_table, [
        'reengagement_token'  => null,
        'reengagement_expiry' => null,
        // Record a "click" to reset the inactivity counter
        'last_click_at'       => current_time('mysql'),
        'click_count'         => $row->click_count + 1,
    ], ['id' => $row->id], ['%s','%s','%s','%d'], ['%d'] );

    // Log the reengagement
    rdrnl_log_reengagement( 1, 'reengaged', $row->subscriber_id );

    wp_redirect( add_query_arg([
        'rdrnl_status'  => 'reengagement_ok',
        'rdrnl_message' => urlencode('Parfait ! Votre abonnement a été confirmé. Vous continuerez à recevoir le récap hebdomadaire.'),
    ], home_url('/') ), 302 );
    exit;
}

/* ── Wednesday cleanup — expire pending reengagements ── */
function rdrnl_cleanup_expired_reengagements() {
    global $wpdb;
    $track_table = rdrnl_tracking_table();
    $sub_table   = rdrnl_table();

    // Find tracking rows with expired reengagement tokens
    $expired = $wpdb->get_results(
        "SELECT subscriber_id FROM {$track_table}
         WHERE reengagement_token IS NOT NULL
           AND reengagement_expiry IS NOT NULL
           AND reengagement_expiry < NOW()"
    );

    if ( empty($expired) ) return 0;

    $removed = 0;
    foreach ( $expired as $row ) {
        $sub_id = intval($row->subscriber_id);

        // Mark as unsubscribed
        rdrnl_update_subscriber_status( $sub_id, 'unsubscribed' );

        // Clear reengagement token
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$track_table}
             SET reengagement_token = NULL, reengagement_expiry = NULL
             WHERE subscriber_id = %d",
            $sub_id
        ));

        // Auto-promote from waitlist
        rdrnl_maybe_promote_waitlist();
        $removed++;
    }

    return $removed;
}

/* ── Log reengagement actions ── */
function rdrnl_log_reengagement( $count, $type, $sub_id = null ) {
    $log = get_option( RDRNL_LOG_KEY, [] );
    array_unshift( $log, [
        'date'        => date('Y-m-d H:i'),
        'type'        => $type,
        'subject'     => $type,
        'recipients'  => $count,
        'items'       => 0,
        'subscriber'  => $sub_id,
    ]);
    update_option( RDRNL_LOG_KEY, array_slice($log, 0, 100), false );
}

/* ============================================================
   STATS HELPERS  (for admin display)
   ============================================================ */

function rdrnl_tracking_stats_for_edition( $edition_slug ) {
    global $wpdb;
    $t = rdrnl_tracking_table();
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT
            COUNT(*)                                AS total_sent,
            SUM(opened_at IS NOT NULL)              AS total_opened,
            SUM(last_click_at IS NOT NULL)          AS total_clicked,
            SUM(open_count)                         AS total_opens,
            SUM(click_count)                        AS total_clicks
         FROM {$t} WHERE edition_slug = %s",
        $edition_slug
    ));
}

function rdrnl_tracking_pending_reengagement_count() {
    global $wpdb;
    return (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT subscriber_id) FROM " . rdrnl_tracking_table() .
        " WHERE reengagement_token IS NOT NULL AND reengagement_expiry > NOW()"
    );
}
