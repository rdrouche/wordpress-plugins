<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* Weekly auto-send cron — checks day/hour, builds and queues the newsletter */
add_action( 'rdrnl_cron_weekly', 'rdrnl_cron_weekly_handler' );

function rdrnl_schedule_cron() {
    if ( ! wp_next_scheduled('rdrnl_cron_weekly') ) {
        wp_schedule_event( time(), 'hourly', 'rdrnl_cron_weekly' );
    }
    // Also schedule reengagement + cleanup crons
    rdrnl_schedule_reengagement_crons();
}

function rdrnl_clear_cron() {
    foreach (['rdrnl_cron_weekly','rdrnl_process_batch'] as $hook) {
        $ts = wp_next_scheduled($hook);
        if ($ts) wp_unschedule_event($ts, $hook);
    }
    rdrnl_clear_reengagement_crons();
}

function rdrnl_cron_weekly_handler() {
    $settings = get_option(RDRNL_OPTION, []);
    if (empty($settings['auto_send'])) return;

    $tz       = wp_timezone();
    $now      = new DateTimeImmutable('now', $tz);
    $cur_dow  = intval($now->format('N'));
    $cur_hour = intval($now->format('G'));

    $send_day  = intval($settings['send_day']  ?? 5);
    $send_hour = intval($settings['send_hour'] ?? 12);

    /* ── Thursday reminder (day before send day) ── */
    $reminder_dow = $send_day > 1 ? $send_day - 1 : 7; // day before, wrap Sun→Sat
    if ( $cur_dow === $reminder_dow && $cur_hour === $send_hour ) {
        // Check reminder not already sent this week
        $last_reminder = get_option('rdrnl_last_reminder_week', '');
        if ( $last_reminder !== $now->format('Y-W') ) {
            $draft = rdrnl_get_draft() ?: rdrnl_build_draft();
            rdrnl_send_reminder_email( $draft, $settings );
            update_option('rdrnl_last_reminder_week', $now->format('Y-W'));
        }
        return;
    }

    /* ── Send day ── */
    if ($cur_dow !== $send_day) return;
    if ($cur_hour !== $send_hour) return;

    // Check not already sent this week
    $log       = get_option(RDRNL_LOG_KEY, []);
    $this_week = $now->format('Y-W');
    foreach ($log as $e) {
        if (in_array($e['type'],['send','queued']) && isset($e['date'])
            && date('Y-W', strtotime($e['date'])) === $this_week) {
            return;
        }
    }

    // Skip if a batch is currently in progress
    if (rdrnl_get_queue_status()) return;

    $draft = rdrnl_get_draft() ?: rdrnl_build_draft();

    // Send even if no own posts — subscribers may have personal feeds
    // Only skip if draft is empty AND there are no subscribers with personal feeds
    if (rdrnl_draft_count($draft) < 1 && ! rdrnl_any_subscriber_has_feeds()) return;

    rdrnl_send_newsletter($draft);
    rdrnl_delete_draft();
}

/**
 * Check if at least one active subscriber has custom feeds configured.
 */
function rdrnl_any_subscriber_has_feeds() {
    $profiles = get_posts([
        'post_type'      => 'rdrnl_profile',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_query'     => [[
            'key'     => '_rdrnl_custom_feeds',
            'value'   => 'a:0:{}',
            'compare' => '!=',
        ]],
        'fields' => 'ids',
    ]);
    return ! empty($profiles);
}

/**
 * Send a reminder email to the admin the day before the newsletter.
 */
function rdrnl_send_reminder_email( $draft, $settings ) {
    $to        = $settings['from_email'] ?? get_option('admin_email');
    $from_name = $settings['from_name']  ?? get_bloginfo('name');
    $from_mail = $settings['from_email'] ?? get_option('admin_email');
    $site      = get_bloginfo('name');

    $own_count  = count(array_filter($draft['own_posts'] ?? [], fn($p) => $p['selected']));
    $feed_count = 0;
    foreach ($draft['feed_sections'] ?? [] as $s) {
        $feed_count += count(array_filter($s['items'], fn($i) => $i['selected']));
    }
    $manual_count = count($draft['manual_links'] ?? []);
    $total        = $own_count + $feed_count + $manual_count;

    $compose_url = admin_url('admin.php?page=rdr-newsletter');
    $day_name    = (new DateTimeImmutable('tomorrow', wp_timezone()))->format('l');

    ob_start(); ?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="background:#0d1117;color:#e6edf3;font-family:sans-serif;font-size:15px;line-height:1.6;margin:0;padding:0">
<div style="max-width:560px;margin:40px auto;padding:0 16px">
<div style="background:#161b22;border-radius:12px;padding:32px 28px;border:1px solid rgba(255,255,255,0.08)">

  <div style="font-size:22px;font-weight:800;margin-bottom:24px">RDR<span style="color:#2ea8ff">-IT</span></div>

  <p style="margin-bottom:16px">Bonjour,</p>

  <p style="margin-bottom:20px;color:#c9d1d9">
    La newsletter <strong style="color:#e6edf3"><?php echo esc_html($site); ?></strong>
    est programmée pour partir <strong style="color:#fbbf24">demain <?php echo $day_name; ?></strong>.
  </p>

  <div style="background:#1c2128;border-radius:8px;padding:16px 20px;margin-bottom:24px">
    <p style="font-weight:600;color:#e6edf3;margin-bottom:10px">Contenu du brouillon actuel :</p>
    <table style="width:100%;font-size:13px;color:#c9d1d9">
      <tr>
        <td style="padding:4px 0">Articles RDR-IT</td>
        <td style="text-align:right;color:<?php echo $own_count > 0 ? '#2ea8ff' : '#484f58'; ?>">
          <strong><?php echo $own_count; ?></strong>
        </td>
      </tr>
      <tr>
        <td style="padding:4px 0">Items flux RSS</td>
        <td style="text-align:right;color:<?php echo $feed_count > 0 ? '#2ea8ff' : '#484f58'; ?>">
          <strong><?php echo $feed_count; ?></strong>
        </td>
      </tr>
      <tr>
        <td style="padding:4px 0">Liens manuels</td>
        <td style="text-align:right;color:<?php echo $manual_count > 0 ? '#2ea8ff' : '#484f58'; ?>">
          <strong><?php echo $manual_count; ?></strong>
        </td>
      </tr>
      <tr style="border-top:1px solid rgba(255,255,255,0.08)">
        <td style="padding:8px 0 4px;font-weight:600;color:#e6edf3">Total sélectionné</td>
        <td style="text-align:right;font-weight:700;font-size:16px;color:#2ea8ff;padding:8px 0 4px"><?php echo $total; ?></td>
      </tr>
    </table>

    <?php if ($total === 0): ?>
    <p style="margin-top:12px;font-size:12px;color:#fbbf24">
      ⚠️ Le brouillon est vide, mais les abonnés avec des flux RSS personnalisés recevront quand même leurs actualités.
    </p>
    <?php endif; ?>
  </div>

  <p style="text-align:center;margin:28px 0">
    <a href="<?php echo esc_url($compose_url); ?>"
       style="background:#2ea8ff;color:#0d1117;font-weight:700;font-size:14px;text-decoration:none;border-radius:8px;padding:12px 28px;display:inline-block">
      ✏️ Ouvrir la compose pour personnaliser
    </a>
  </p>

  <p style="font-size:12px;color:#8b949e;text-align:center">
    Si vous ne faites rien, la newsletter partira automatiquement demain avec le contenu actuel.
  </p>

</div>
</div>
</body></html>
<?php
    $body = ob_get_clean();

    wp_mail( $to,
        "[{$site}] Rappel — Newsletter demain : {$total} items",
        $body,
        [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_mail}>",
        ]
    );
}
