<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   BATCH SEND SYSTEM
   
   Flow:
   1. rdrnl_send_newsletter() builds the queue and saves it
      to option RDRNL_QUEUE_KEY, then fires the first batch
      immediately via do_action().
   2. A WP Cron event 'rdrnl_process_batch' runs every 2 min
      and processes the next 50 recipients until the queue
      is empty.
   3. Each recipient gets their own personalised email
      (custom feeds + unique profile/unsub links).
   
   Timeout safety:
   - 50 emails per batch × ~0.3s per email ≈ 15s per batch
   - WP Cron is non-blocking (no HTTP timeout risk on front-end)
   - set_time_limit(120) on each batch as extra safety
   ============================================================ */

define( 'RDRNL_BATCH_SIZE', 50 );

add_action( 'rdrnl_process_batch', 'rdrnl_process_next_batch' );

/* ── Schedule the 2-min cron interval ── */
add_filter( 'cron_schedules', 'rdrnl_add_two_min_schedule' );
function rdrnl_add_two_min_schedule( $schedules ) {
    if ( ! isset( $schedules['rdrnl_two_minutes'] ) ) {
        $schedules['rdrnl_two_minutes'] = [
            'interval' => 120,
            'display'  => 'Every 2 minutes (RDR Newsletter batch)',
        ];
    }
    return $schedules;
}

/* ── Kick off a send: build queue and schedule batches ── */
function rdrnl_send_newsletter( $draft, $test_email = '' ) {
    $settings  = get_option( RDRNL_OPTION, [] );
    $from_name = $settings['from_name']  ?? get_bloginfo('name');
    $from_mail = $settings['from_email'] ?? get_option('admin_email');
    $subject   = $draft['subject'] ?? '[RDR-IT] Récap hebdo';

    // Publish web edition (only on real send, not test)
    if ( ! $test_email ) {
        rdrnl_publish_edition( $draft );
    }

    // Build recipient list
    if ( $test_email ) {
        $recipients = [ sanitize_email($test_email) ];
    } else {
        $recipients = rdrnl_get_active_emails();
    }

    $recipients = array_values( array_filter( $recipients, 'is_email' ) );
    if ( empty($recipients) ) {
        return new WP_Error( 'no_recipients', 'Aucun destinataire valide.' );
    }

    if ( $test_email ) {
        // Test: send immediately, no queue
        $row         = rdrnl_get_subscriber_by_email($test_email);
        $unsub_url   = '';
        $profile_url = '';
        $personal    = [];
        if ($row) {
            $unsub_url   = rdrnl_build_unsub_url($row->email);
            $profile_url = rdrnl_build_profile_url($row->id);
            $personal    = rdrnl_get_subscriber_feed_sections($row->id);
        }
        $edition_slug = sanitize_title($subject);
        $pixel_token = rdrnl_tracking_create($row ? $row->id : 0, $edition_slug);
        $pixel_on    = ! empty( $settings['tracking_pixel'] );
        $pixel_url   = ( $row && $pixel_on ) ? rdrnl_pixel_url($pixel_token) : '';
        $html = rdrnl_build_email_html($draft, $settings, $unsub_url, $profile_url, $personal, $pixel_url, $row ? $row->id : 0, $edition_slug);
        $ok   = wp_mail($test_email, $subject, $html, rdrnl_mail_headers($from_name, $from_mail));
        if ($ok) rdrnl_log_send($draft, 1, 'test');
        return $ok ? true : new WP_Error('send_failed','Envoi test échoué.');
    }

    // Real send: enqueue
    $queue = [
        'draft'      => $draft,
        'recipients' => $recipients,
        'offset'     => 0,
        'subject'    => $subject,
        'from_name'  => $from_name,
        'from_mail'  => $from_mail,
        'started_at' => time(),
        'sent'       => 0,
        'errors'     => 0,
    ];

    update_option( RDRNL_QUEUE_KEY, $queue, false );
    rdrnl_log_send($draft, count($recipients), 'queued');

    // Schedule recurring cron if not already scheduled
    if ( ! wp_next_scheduled('rdrnl_process_batch') ) {
        wp_schedule_event( time() + 120, 'rdrnl_two_minutes', 'rdrnl_process_batch' );
    }

    // Fire first batch immediately — direct action call, no cron dependency
    // This avoids the spawn_cron/loopback unreliability on some hosts
    rdrnl_process_next_batch();

    return true;
}

/* ── Process one batch of 50 ── */
function rdrnl_process_next_batch() {
    $queue = get_option( RDRNL_QUEUE_KEY, null );
    if ( ! $queue || empty($queue['recipients']) ) {
        rdrnl_finish_batch_queue();
        return;
    }

    // PHP safety net
    if ( function_exists('set_time_limit') ) @set_time_limit(120);
    @ignore_user_abort(true);

    $settings    = get_option( RDRNL_OPTION, [] );
    $draft       = $queue['draft'];
    $offset      = intval( $queue['offset'] );
    $batch       = array_slice( $queue['recipients'], $offset, RDRNL_BATCH_SIZE );

    if ( empty($batch) ) {
        rdrnl_finish_batch_queue();
        return;
    }

    $from_name = $queue['from_name'];
    $from_mail = $queue['from_mail'];
    $subject   = $queue['subject'];
    $headers   = rdrnl_mail_headers($from_name, $from_mail);
    $sent      = 0;
    $errors    = 0;

    foreach ( $batch as $email ) {
        $row         = rdrnl_get_subscriber_by_email($email);
        $unsub_url   = $row ? rdrnl_build_unsub_url($email)      : '';
        $profile_url = $row ? rdrnl_build_profile_url($row->id)  : '';
        $personal    = $row ? rdrnl_get_subscriber_feed_sections($row->id) : [];

        // Determine edition slug for tracking
        $edition_slug = sanitize_title( $subject );

        // Create tracking row and get pixel token.
        // Pixel URL only emitted when the open-tracking pixel is enabled (CNIL toggle).
        // The tracking row is still created so click tracking + reengagement keep working.
        $pixel_token = rdrnl_tracking_create( $row ? $row->id : 0, $edition_slug );
        $pixel_on    = ! empty( $settings['tracking_pixel'] );
        $pixel_url   = ( $row && $pixel_on ) ? rdrnl_pixel_url($pixel_token) : '';

        $html = rdrnl_build_email_html(
            $draft, $settings, $unsub_url, $profile_url, $personal,
            $pixel_url, $row ? $row->id : 0, $edition_slug
        );

        if ( wp_mail($email, $subject, $html, $headers) ) {
            $sent++;
        } else {
            $errors++;
        }
    }

    // Update queue progress
    $queue['offset'] += count($batch);
    $queue['sent']   += $sent;
    $queue['errors'] += $errors;

    if ( $queue['offset'] >= count($queue['recipients']) ) {
        // All done
        rdrnl_finish_batch_queue( $queue );
    } else {
        // Save progress, next batch will be fired by the recurring cron
        update_option( RDRNL_QUEUE_KEY, $queue, false );
    }
}

function rdrnl_finish_batch_queue( $queue = null ) {
    // Clear recurring cron
    $ts = wp_next_scheduled('rdrnl_process_batch');
    if ($ts) wp_unschedule_event($ts, 'rdrnl_process_batch');

    // Update log entry
    if ($queue) {
        $log = get_option(RDRNL_LOG_KEY, []);
        foreach ($log as &$entry) {
            if ($entry['type'] === 'queued' && $entry['subject'] === $queue['subject']) {
                $entry['type']       = 'send';
                $entry['sent']       = $queue['sent'];
                $entry['errors']     = $queue['errors'];
                $entry['finished_at']= date('Y-m-d H:i');
                break;
            }
        }
        update_option(RDRNL_LOG_KEY, $log, false);
    }

    delete_option(RDRNL_QUEUE_KEY);
}

/* ── Helpers ── */

function rdrnl_build_unsub_url( $email ) {
    $token = rdrnl_get_or_create_unsub_token($email);
    return $token ? add_query_arg(['rdrnl_action'=>'unsubscribe','token'=>$token], home_url('/')) : '';
}

function rdrnl_build_profile_url( $sub_id ) {
    // Delegate to the canonical builder in profile.php which generates ONE
    // token and appends it as ?rdrnl_tok=. Previously this function generated
    // a second token and appended a legacy ?rdrnl_prof_token= param, creating
    // two DB tokens per email and an unused query arg.
    return rdrnl_profile_build_url( $sub_id );
}

function rdrnl_get_subscriber_feed_sections( $sub_id ) {
    $feeds = rdrnl_profile_get_feeds($sub_id);
    if (empty($feeds)) return [];
    $sections = [];
    foreach ($feeds as $feed) {
        $url   = $feed['url']   ?? '';
        $limit = intval($feed['limit'] ?? 5);
        $label = $feed['label'] ?? $url;
        if (!$url) continue;
        $items = rdrnl_parse_feed_cached($url, $limit);
        if (!empty($items)) {
            $sections[] = ['feed_label' => '⭐ '.$label, 'feed_url' => $url, 'items' => $items];
        }
    }
    return $sections;
}

function rdrnl_build_email_html($draft, $settings, $unsub_url='', $profile_url='', $personal_sections=[], $pixel_url='', $sub_id=0, $edition_slug='') {
    $categorized = rdrnl_draft_get_categorized($draft);
    ob_start();
    include RDRNL_DIR . 'templates/email.php';
    $html = ob_get_clean();
    // Wrap links for click tracking (only when sub_id is known)
    if ($sub_id) {
        $html = rdrnl_wrap_links_for_tracking($html, $sub_id, $edition_slug);
    }
    return $html;
}

function rdrnl_mail_headers($from_name, $from_mail) {
    return [
        'Content-Type: text/html; charset=UTF-8',
        "From: {$from_name} <{$from_mail}>",
    ];
}

function rdrnl_log_send($draft, $count, $type='send') {
    $log = get_option(RDRNL_LOG_KEY, []);
    array_unshift($log, [
        'date'       => date('Y-m-d H:i'),
        'type'       => $type,
        'subject'    => $draft['subject'] ?? '',
        'recipients' => $count,
        'items'      => rdrnl_draft_count($draft),
    ]);
    update_option(RDRNL_LOG_KEY, array_slice($log, 0, 50), false);
}

/* ── Queue status for admin display ── */
function rdrnl_get_queue_status() {
    $queue = get_option(RDRNL_QUEUE_KEY, null);
    if (!$queue) return null;
    $total   = count($queue['recipients']);
    $done    = $queue['offset'];
    $pct     = $total > 0 ? round(($done / $total) * 100) : 0;
    return [
        'active'    => true,
        'total'     => $total,
        'done'      => $done,
        'remaining' => $total - $done,
        'sent'      => $queue['sent'],
        'errors'    => $queue['errors'],
        'pct'       => $pct,
        'subject'   => $queue['subject'],
        'started'   => date('H:i', $queue['started_at']),
    ];
}

/**
 * Replace all <a href="..."> in email HTML with tracked redirect URLs.
 * Skips: unsubscribe links, pixel links, anchors (#), mailto:, tel:
 */
function rdrnl_wrap_links_for_tracking( $html, $sub_id, $edition_slug ) {
    return preg_replace_callback(
        '/<a([^>]*?)href=["\'](https?:[^\'">]+)["\']/i',
        function($matches) use ($sub_id, $edition_slug) {
            $attrs = $matches[1];
            $url   = $matches[2];

            // Skip internal newsletter links that must not be click-wrapped:
            // tracking pixel/redirect, unsubscribe, and profile links
            // (profile URLs carry ?rdrnl_tok=; the legacy ?rdrnl_prof_token=
            //  is kept here only for backward compatibility with old emails).
            if ( strpos($url, 'rdrnl_track')      !== false ||
                 strpos($url, 'rdrnl_action')     !== false ||
                 strpos($url, 'rdrnl_tok')        !== false ||
                 strpos($url, 'rdrnl_prof_token') !== false ) {
                return $matches[0];
            }

            $tracked = rdrnl_click_url($url, $sub_id, $edition_slug);
            return '<a' . $attrs . 'href="' . esc_attr($tracked) . '"';
        },
        $html
    );
}
