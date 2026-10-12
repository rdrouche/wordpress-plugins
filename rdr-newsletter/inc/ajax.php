<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* ── Auth helper ── */
function rdrnl_check_nonce() {
    check_ajax_referer('rdrnl_admin','nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized',403);
}

/* ── Front AJAX subscribe handler is registered in shortcode.php.
   Profile-related front handlers (save_profile, validate_feed,
   request_profile_link) are registered in profile.php, next to their
   function definitions. Do NOT re-register them here to avoid duplicate
   hooks. ── */

/* ── Admin AJAX (connectés uniquement) ── */
add_action('wp_ajax_rdrnl_refresh_draft',      'rdrnl_ajax_refresh_draft');
add_action('wp_ajax_rdrnl_reset_draft',        'rdrnl_ajax_reset_draft');
add_action('wp_ajax_rdrnl_update_selection',   'rdrnl_ajax_update_selection');
add_action('wp_ajax_rdrnl_update_item_meta',   'rdrnl_ajax_update_item_meta');   // note + category
add_action('wp_ajax_rdrnl_add_manual_link',    'rdrnl_ajax_add_manual_link');
add_action('wp_ajax_rdrnl_remove_manual',      'rdrnl_ajax_remove_manual');
add_action('wp_ajax_rdrnl_save_message',       'rdrnl_ajax_save_message');
add_action('wp_ajax_rdrnl_save_subject',       'rdrnl_ajax_save_subject');
add_action('wp_ajax_rdrnl_save_week_label',    'rdrnl_ajax_save_week_label');
add_action('wp_ajax_rdrnl_fetch_og',           'rdrnl_ajax_fetch_og');
add_action('wp_ajax_rdrnl_send_test',          'rdrnl_ajax_send_test');
add_action('wp_ajax_rdrnl_send_now',           'rdrnl_ajax_send_now');
add_action('wp_ajax_rdrnl_preview',            'rdrnl_ajax_preview');
/* ── Subscriber admin actions ── */
add_action('wp_ajax_rdrnl_sub_status',         'rdrnl_ajax_sub_status');
add_action('wp_ajax_rdrnl_sub_delete',         'rdrnl_ajax_sub_delete');
add_action('wp_ajax_rdrnl_sub_promote',        'rdrnl_ajax_sub_promote');
add_action('wp_ajax_rdrnl_sub_add_manual',     'rdrnl_ajax_sub_add_manual');

/* ────────────────────────────── DRAFT ──────────────────────────────── */

function rdrnl_ajax_refresh_draft() {
    rdrnl_check_nonce();
    $draft = rdrnl_build_draft();
    wp_send_json_success(['draft'=>$draft,'count'=>rdrnl_draft_count($draft)]);
}

function rdrnl_ajax_reset_draft() {
    rdrnl_check_nonce();
    rdrnl_delete_draft();
    wp_send_json_success(['message' => 'Brouillon supprimé.']);
}

function rdrnl_ajax_update_selection() {
    rdrnl_check_nonce();
    $type     = sanitize_text_field($_POST['item_type']??'');
    $key      = sanitize_text_field($_POST['item_key']??'');
    $selected = (bool)intval($_POST['selected']??1);
    $draft    = rdrnl_get_draft();
    if (!$draft) wp_send_json_error('No draft');

    if ($type==='own_post') {
        foreach ($draft['own_posts'] as &$p) { if ((string)$p['id']===$key) { $p['selected']=$selected; break; } }
    } elseif ($type==='feed_item') {
        foreach ($draft['feed_sections'] as &$s) {
            foreach ($s['items'] as &$i) { if ($i['url']===$key) { $i['selected']=$selected; break 2; } }
        }
    }
    rdrnl_save_draft($draft);
    wp_send_json_success(['count'=>rdrnl_draft_count($draft)]);
}

/* Save note and/or category for any item */
function rdrnl_ajax_update_item_meta() {
    rdrnl_check_nonce();
    $type     = sanitize_text_field($_POST['item_type']??'');
    $key      = sanitize_text_field($_POST['item_key']??'');
    $note = rdrnl_kses_note($_POST['note']??'');
    $category = sanitize_text_field($_POST['category']??'');
    $draft    = rdrnl_get_draft();
    if (!$draft) wp_send_json_error('No draft');

    $updated = false;

    if ($type==='own_post') {
        foreach ($draft['own_posts'] as &$p) {
            if ((string)$p['id']===$key) { $p['note']=$note; $p['category']=$category; $updated=true; break; }
        }
    } elseif ($type==='feed_item') {
        foreach ($draft['feed_sections'] as &$s) {
            foreach ($s['items'] as &$i) {
                if ($i['url']===$key) { $i['note']=$note; $i['category']=$category; $updated=true; break 2; }
            }
        }
    } elseif ($type==='manual') {
        foreach ($draft['manual_links'] as &$l) {
            if ($l['url']===$key) { $l['note']=$note; $l['category']=$category; $updated=true; break; }
        }
    }

    if ($updated) rdrnl_save_draft($draft);
    wp_send_json_success(['updated'=>$updated]);
}

function rdrnl_ajax_add_manual_link() {
    rdrnl_check_nonce();
    $url  = esc_url_raw($_POST['url']??'');
    if (empty($url)) wp_send_json_error('No URL');

    $og = rdrnl_fetch_og($url) ?: ['url'=>$url,'title'=>$url,'description'=>'','image'=>'','site_name'=>''];
    $og['added_at'] = time();
    $og['note']     = sanitize_textarea_field($_POST['note']??'');
    $og['category'] = sanitize_text_field($_POST['category']??'');
    if (!empty($_POST['title']))       $og['title']       = sanitize_text_field($_POST['title']);
    if (!empty($_POST['description'])) $og['description'] = sanitize_textarea_field($_POST['description']);

    $draft = rdrnl_get_draft();
    if (!$draft) wp_send_json_error('No draft. Refresh first.');

    foreach ($draft['manual_links'] as $ml) {
        if ($ml['url']===$url) wp_send_json_error('Already in draft.');
    }

    $draft['manual_links'][] = $og;
    rdrnl_save_draft($draft);

    // Render the item HTML server-side so the alt_links panel is included
    $settings = get_option(RDRNL_OPTION, []);
    $cats     = $settings['categories'] ?? [];
    ob_start();
    rdrnl_render_item($og, 'manual', $og['url'], $cats, true);
    $html = ob_get_clean();

    wp_send_json_success(['link'=>$og,'html'=>$html,'count'=>rdrnl_draft_count($draft)]);
}

function rdrnl_ajax_remove_manual() {
    rdrnl_check_nonce();
    $url   = esc_url_raw($_POST['url']??'');
    $draft = rdrnl_get_draft();
    if (!$draft) wp_send_json_error('No draft');
    $draft['manual_links'] = array_values(array_filter($draft['manual_links'],fn($l)=>$l['url']!==$url));
    rdrnl_save_draft($draft);
    wp_send_json_success(['count'=>rdrnl_draft_count($draft)]);
}

function rdrnl_ajax_save_message() {
    rdrnl_check_nonce();
    $draft = rdrnl_get_draft();
    if (!$draft) wp_send_json_error('No draft');
    $draft['personal_message'] = wp_kses_post($_POST['message']??'');
    rdrnl_save_draft($draft);
    wp_send_json_success();
}

function rdrnl_ajax_save_subject() {
    rdrnl_check_nonce();
    $draft = rdrnl_get_draft();
    if (!$draft) wp_send_json_error('No draft');
    $draft['subject'] = sanitize_text_field($_POST['subject']??'');
    rdrnl_save_draft($draft);
    wp_send_json_success();
}

function rdrnl_ajax_save_week_label() {
    rdrnl_check_nonce();
    $draft = rdrnl_get_draft();
    if (!$draft) wp_send_json_error('No draft');
    $draft['week_label'] = sanitize_text_field($_POST['week_label'] ?? '');
    rdrnl_save_draft($draft);
    wp_send_json_success();
}

function rdrnl_ajax_fetch_og() {
    rdrnl_check_nonce();
    $url = esc_url_raw($_POST['url']??'');
    if (empty($url)) wp_send_json_error('No URL');
    $og = rdrnl_fetch_og($url);
    $og ? wp_send_json_success($og) : wp_send_json_error('Could not fetch metadata.');
}

function rdrnl_ajax_send_test() {
    rdrnl_check_nonce();
    $email = sanitize_email($_POST['email']??'');
    if (!is_email($email)) wp_send_json_error('Adresse email invalide.');
    $draft = rdrnl_get_draft();
    if (!$draft) wp_send_json_error("Aucun brouillon. Cliquez sur Refresh d'abord.");
    if (rdrnl_draft_count($draft) < 1) wp_send_json_error('Le brouillon est vide — aucun item sélectionné.');

    try {
        $r = rdrnl_send_newsletter($draft, $email);
        if (is_wp_error($r)) {
            wp_send_json_error($r->get_error_message());
        }
        wp_send_json_success('Test envoyé à ' . $email);
    } catch (\Throwable $e) {
        error_log('[RDR Newsletter] send_test error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        wp_send_json_error('Erreur : ' . $e->getMessage());
    }
}

function rdrnl_ajax_send_now() {
    rdrnl_check_nonce();
    $draft = rdrnl_get_draft();
    if (!$draft) wp_send_json_error('Aucun brouillon.');
    if (rdrnl_draft_count($draft) < 1) wp_send_json_error('Aucun item sélectionné.');

    try {
        $r = rdrnl_send_newsletter($draft);
        if (is_wp_error($r)) {
            wp_send_json_error($r->get_error_message());
        }
        rdrnl_delete_draft();
        wp_send_json_success('Newsletter mise en file d\'envoi.');
    } catch (\Throwable $e) {
        error_log('[RDR Newsletter] send_now error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        wp_send_json_error('Erreur : ' . $e->getMessage());
    }
}

function rdrnl_ajax_preview() {
    rdrnl_check_nonce();
    $draft = rdrnl_get_draft();
    if (!$draft) wp_send_json_error('No draft.');
    $settings = get_option(RDRNL_OPTION,[]);
    $html = rdrnl_build_email_html($draft,$settings,'#unsub');
    wp_send_json_success(['html'=>$html]);
}

/* ─────────────────────────── SUBSCRIBERS ───────────────────────────── */

function rdrnl_ajax_sub_status() {
    rdrnl_check_nonce();
    $id     = intval($_POST['id']??0);
    $status = sanitize_text_field($_POST['status']??'');
    if (!in_array($status,['active','pending','waitlist','unsubscribed'])) wp_send_json_error('Invalid status');
    rdrnl_update_subscriber_status($id,$status);
    wp_send_json_success(['active'=>rdrnl_count_active(),'waitlist'=>rdrnl_count_waitlist()]);
}

function rdrnl_ajax_sub_delete() {
    rdrnl_check_nonce();
    $id = intval($_POST['id']??0);
    rdrnl_delete_subscriber($id);
    wp_send_json_success(['active'=>rdrnl_count_active(),'waitlist'=>rdrnl_count_waitlist()]);
}

function rdrnl_ajax_sub_promote() {
    rdrnl_check_nonce();
    $settings = get_option(RDRNL_OPTION,[]);
    $max      = intval($settings['max_subscribers']??0);
    $active   = rdrnl_count_active();
    if ($max>0 && $active>=$max) wp_send_json_error('Limit reached. Increase the max or remove a subscriber first.');
    $promoted = rdrnl_promote_from_waitlist();
    if (!$promoted) wp_send_json_error('No one in the waitlist.');
    wp_send_json_success(['email'=>$promoted->email,'active'=>rdrnl_count_active(),'waitlist'=>rdrnl_count_waitlist()]);
}

function rdrnl_ajax_sub_add_manual() {
    rdrnl_check_nonce();
    $email = sanitize_email($_POST['email']??'');
    $name  = sanitize_text_field($_POST['name']??'');
    if (!is_email($email)) wp_send_json_error('Invalid email.');
    $existing = rdrnl_get_subscriber_by_email($email);
    if ($existing) wp_send_json_error('Email already exists (status: '.$existing->status.').');

    $settings = get_option(RDRNL_OPTION,[]);
    $max      = intval($settings['max_subscribers']??0);
    $active   = rdrnl_count_active();
    $status   = ($max>0 && $active>=$max) ? 'waitlist' : 'active';

    rdrnl_insert_subscriber($email,$name,$status);
    wp_send_json_success(['status'=>$status,'active'=>rdrnl_count_active(),'waitlist'=>rdrnl_count_waitlist()]);
}

/* ─── Alt links per item ─────────────────────────────────── */
add_action('wp_ajax_rdrnl_update_alt_links', 'rdrnl_ajax_update_alt_links');
function rdrnl_ajax_update_alt_links() {
    rdrnl_check_nonce();
    $type      = sanitize_text_field($_POST['item_type'] ?? '');
    $key       = sanitize_text_field($_POST['item_key']  ?? '');
    $raw_links = $_POST['alt_links'] ?? [];
    $draft     = rdrnl_get_draft();
    if (!$draft) wp_send_json_error('No draft');

    // Sanitize each alt link: url + label
    $alt_links = [];
    foreach ((array)$raw_links as $al) {
        $url   = esc_url_raw(trim($al['url']   ?? ''));
        $label = sanitize_text_field(trim($al['label'] ?? ''));
        if ($url) $alt_links[] = ['url' => $url, 'label' => $label ?: $url];
    }

    $updated = false;
    if ($type === 'own_post') {
        foreach ($draft['own_posts'] as &$p) {
            if ((string)$p['id'] === $key) { $p['alt_links'] = $alt_links; $updated = true; break; }
        }
    } elseif ($type === 'feed_item') {
        foreach ($draft['feed_sections'] as &$s) {
            foreach ($s['items'] as &$i) {
                if ($i['url'] === $key) { $i['alt_links'] = $alt_links; $updated = true; break 2; }
            }
        }
    } elseif ($type === 'manual') {
        foreach ($draft['manual_links'] as &$l) {
            if ($l['url'] === $key) { $l['alt_links'] = $alt_links; $updated = true; break; }
        }
    }

    if ($updated) rdrnl_save_draft($draft);
    wp_send_json_success(['updated' => $updated]);
}

/* ─── Queue status for compose page ─────────────────────── */
add_action('wp_ajax_rdrnl_queue_status', 'rdrnl_ajax_queue_status');
function rdrnl_ajax_queue_status() {
    rdrnl_check_nonce();
    $status = rdrnl_get_queue_status();
    wp_send_json_success($status ?: ['active' => false]);
}
