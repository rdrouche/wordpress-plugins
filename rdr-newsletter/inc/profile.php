<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * Profile system — NO cookies, NO transients for auth.
 *
 * Flow:
 * 1. Token generated → stored in wp_rdrnl_profile_tokens table
 *    (sub_id, token, expires_at, used_at nullable)
 * 2. Every request to the profile page MUST carry ?rdrnl_tok=xxx in the URL
 * 3. Token validated against DB on each page load
 * 4. No redirect after token use — token stays in URL for the session duration
 * 5. Token expires after 24h from generation (configurable)
 */

/* ============================================================
   TOKEN TABLE  wp_rdrnl_profile_tokens
   ============================================================ */

function rdrnl_profile_token_table() {
    global $wpdb;
    return $wpdb->prefix . 'rdrnl_profile_tokens';
}

function rdrnl_create_profile_token_table() {
    global $wpdb;
    $t   = rdrnl_profile_token_table();
    $col = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$t} (
        id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        sub_id      BIGINT(20) UNSIGNED NOT NULL,
        token       VARCHAR(64)         NOT NULL,
        created_at  DATETIME            NOT NULL,
        expires_at  DATETIME            NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY  token (token),
        KEY         sub_id (sub_id),
        KEY         expires_at (expires_at)
    ) {$col};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

/* ── Generate and store a token ── */
function rdrnl_profile_generate_token( $sub_id ) {
    global $wpdb;

    // Ensure table exists
    rdrnl_create_profile_token_table();

    // Purge expired tokens for this subscriber first
    $wpdb->query( $wpdb->prepare(
        "DELETE FROM " . rdrnl_profile_token_table() . " WHERE sub_id = %d AND expires_at < NOW()",
        intval( $sub_id )
    ));

    $token      = bin2hex( random_bytes(32) ); // 64 hex chars, cryptographically random
    $expires_at = date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );

    $wpdb->insert( rdrnl_profile_token_table(), [
        'sub_id'     => intval( $sub_id ),
        'token'      => $token,
        'created_at' => current_time('mysql'),
        'expires_at' => $expires_at,
    ], ['%d','%s','%s','%s'] );

    return $token;
}

/* ── Validate a token from the URL — returns sub_id or 0 ── */
function rdrnl_profile_validate_token( $token ) {
    if ( empty($token) ) return 0;

    // Strict format check first (64 hex chars)
    if ( ! preg_match('/^[a-f0-9]{64}$/', $token) ) return 0;

    global $wpdb;

    // Ensure table exists (handles installs that skipped reactivation)
    rdrnl_create_profile_token_table();

    $row = $wpdb->get_row( $wpdb->prepare(
        "SELECT sub_id FROM " . rdrnl_profile_token_table() .
        " WHERE token = %s AND expires_at > NOW()",
        $token
    ));

    if ( ! $row ) return 0;

    // Verify subscriber is still active
    $sub = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, status FROM " . rdrnl_table() . " WHERE id = %d",
        intval( $row->sub_id )
    ));
    if ( ! $sub || $sub->status !== 'active' ) return 0;

    return intval( $row->sub_id );
}

/* ── Revoke all tokens for a subscriber (on unsub) ── */
function rdrnl_profile_revoke_tokens( $sub_id ) {
    global $wpdb;
    $wpdb->delete( rdrnl_profile_token_table(), ['sub_id' => intval($sub_id)], ['%d'] );
}

/* ── Get current subscriber from URL token — NO cookie, NO session ── */
function rdrnl_profile_current_sub() {
    $token = sanitize_text_field( $_GET['rdrnl_tok'] ?? '' );
    return rdrnl_profile_validate_token( $token );
}

/* ── Build profile access URL (token always in URL) ── */
function rdrnl_profile_build_url( $sub_id ) {
    $token = rdrnl_profile_generate_token( $sub_id );
    $post  = rdrnl_profile_get( $sub_id );
    if ( ! $post ) {
        rdrnl_profile_save_feeds( $sub_id, [] );
        $post = rdrnl_profile_get( $sub_id );
    }
    if ( ! $post ) return '';
    return add_query_arg( 'rdrnl_tok', $token, get_permalink( $post->ID ) );
}

/* Alias used by mailer/subscribers for compatibility */
function rdrnl_profile_get_url( $sub_id ) {
    return rdrnl_profile_build_url( $sub_id );
}

/* ── Deprecated: old token flow (now unused, kept for compat) ── */
function rdrnl_profile_validate_old_token( $token ) { return false; }

/* ============================================================
   CPT rdrnl_profile  (one per subscriber, private)
   ============================================================ */
add_action( 'init', 'rdrnl_register_profile_cpt' );
function rdrnl_register_profile_cpt() {
    register_post_type( 'rdrnl_profile', [
        'labels'             => ['name' => 'Profils newsletter', 'singular_name' => 'Profil newsletter'],
        'public'             => true,
        'publicly_queryable' => true,
        'show_ui'            => false,
        'show_in_menu'       => false,
        'query_var'          => false,
        'rewrite'            => ['slug' => 'editions/profil', 'with_front' => false],
        'capability_type'    => 'post',
        'has_archive'        => false,
        'hierarchical'       => false,
        'supports'           => ['title'],
    ]);
}

/* ── Meta helpers ── */
function rdrnl_profile_get( $sub_id ) {
    $posts = get_posts([
        'post_type'      => 'rdrnl_profile',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'meta_key'       => '_rdrnl_sub_id',
        'meta_value'     => intval($sub_id),
    ]);
    return $posts ? $posts[0] : null;
}

function rdrnl_profile_get_feeds( $sub_id ) {
    $post = rdrnl_profile_get( $sub_id );
    if ( ! $post ) return [];
    $feeds = get_post_meta( $post->ID, '_rdrnl_custom_feeds', true );
    return is_array($feeds) ? $feeds : [];
}

function rdrnl_profile_save_feeds( $sub_id, $feeds ) {
    $feeds = array_slice( (array)$feeds, 0, 3 );
    $post  = rdrnl_profile_get( $sub_id );

    if ( ! $post ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM ".rdrnl_table()." WHERE id=%d", $sub_id) );
        if ( ! $row ) return false;
        $pid = wp_insert_post([
            'post_type'   => 'rdrnl_profile',
            'post_title'  => 'Profil #' . $sub_id,
            'post_status' => 'publish',
            'post_name'   => rdrnl_generate_profile_slug(),
        ]);
        if ( is_wp_error($pid) ) return false;
        update_post_meta( $pid, '_rdrnl_sub_id', $sub_id );
        $post = get_post($pid);
    }

    update_post_meta( $post->ID, '_rdrnl_custom_feeds', $feeds );
    return true;
}

/* ============================================================
   TEMPLATE — Token validated on every page load
   ============================================================ */
add_filter( 'single_template', 'rdrnl_profile_template' );
function rdrnl_profile_template( $template ) {
    if ( get_post_type() === 'rdrnl_profile' ) {
        $custom = RDRNL_DIR . 'templates/profile.php';
        if ( file_exists($custom) ) return $custom;
    }
    return $template;
}

/* ── Remove the old cookie/redirect handler — no longer needed ── */
// (previously add_action 'template_redirect' rdrnl_handle_profile_token)
// Now: template reads ?rdrnl_tok directly, no redirect, no cookie

/* ============================================================
   SEND PROFILE EMAIL
   ============================================================ */
function rdrnl_send_profile_email( $sub_id ) {
    global $wpdb;
    $row = $wpdb->get_row( $wpdb->prepare("SELECT * FROM ".rdrnl_table()." WHERE id=%d", $sub_id) );
    if ( ! $row || $row->status !== 'active' ) return false;

    $settings  = get_option( RDRNL_OPTION, [] );
    $url       = rdrnl_profile_build_url( $sub_id );
    $subject   = $settings['profile_subject'] ?? 'Votre lien de personnalisation RDR-IT';
    $from_name = $settings['from_name']  ?? get_bloginfo('name');
    $from_mail = $settings['from_email'] ?? get_option('admin_email');
    $body      = rdrnl_profile_email_html( $row->name, $url );

    return wp_mail( $row->email, $subject, $body, [
        'Content-Type: text/html; charset=UTF-8',
        "From: {$from_name} <{$from_mail}>",
    ]);
}

function rdrnl_profile_email_html( $name, $url ) {
    $greeting = $name ? 'Bonjour ' . esc_html($name) . ',' : 'Bonjour,';
    ob_start(); ?>
<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"></head>
<body style="background:#0d1117;color:#e6edf3;font-family:sans-serif;font-size:15px;line-height:1.6;margin:0;padding:0">
<div style="max-width:520px;margin:40px auto;padding:0 16px">
<div style="background:#161b22;border-radius:12px;padding:32px 28px;border:1px solid rgba(255,255,255,0.08)">
  <div style="font-size:22px;font-weight:800;margin-bottom:20px">RDR<span style="color:#2ea8ff">-IT</span></div>
  <p style="margin-bottom:12px"><?php echo $greeting; ?></p>
  <p style="margin-bottom:12px;color:#c9d1d9">Voici votre lien de personnalisation de la newsletter. Il est valable <strong style="color:#e6edf3">24 heures</strong>.</p>
  <p style="margin-bottom:20px;color:#c9d1d9">Vous pouvez y ajouter jusqu'à <strong style="color:#e6edf3">3 flux RSS</strong> personnels.</p>
  <p style="text-align:center;margin:28px 0">
    <a href="<?php echo esc_url($url); ?>" style="background:#2ea8ff;color:#0d1117;font-weight:700;font-size:15px;text-decoration:none;border-radius:8px;padding:12px 28px;display:inline-block">Personnaliser ma newsletter</a>
  </p>
  <p style="font-size:12px;color:#8b949e">Ce lien expire dans 24h. Vous pouvez en redemander un depuis n'importe quel email reçu.</p>
  <hr style="border:none;border-top:1px solid rgba(255,255,255,0.07);margin:20px 0">
  <p style="font-size:12px;color:#484f58">Lien : <?php echo esc_url($url); ?></p>
</div></div>
</body></html>
<?php return ob_get_clean();
}

/* ============================================================
   AJAX HANDLERS
   ============================================================ */

/* Save profile feeds */
add_action('wp_ajax_nopriv_rdrnl_save_profile', 'rdrnl_ajax_save_profile');
add_action('wp_ajax_rdrnl_save_profile',        'rdrnl_ajax_save_profile');
function rdrnl_ajax_save_profile() {
    check_ajax_referer('rdrnl_profile_nonce', 'nonce');

    // Auth: token from POST (sent by JS alongside the form data)
    $token  = sanitize_text_field( $_POST['rdrnl_tok'] ?? '' );
    $sub_id = rdrnl_profile_validate_token( $token );
    if ( ! $sub_id ) {
        wp_send_json_error('Lien expiré ou invalide. Demandez un nouveau lien depuis votre dernier email reçu.');
    }

    $raw_feeds = $_POST['feeds'] ?? [];
    $feeds     = [];

    foreach ( array_slice((array)$raw_feeds, 0, 3) as $f ) {
        $url   = esc_url_raw( $f['url'] ?? '' );
        $limit = min( intval($f['limit'] ?? 5), 10 );
        if ( ! $url ) continue;

        $permitted = rdrnl_feed_url_permitted($url);
        if ( is_wp_error($permitted) ) wp_send_json_error($permitted->get_error_message());

        $check = rdrnl_validate_feed_url($url);
        if ( ! $check['ok'] ) wp_send_json_error('Flux invalide : ' . $check['error']);

        $feeds[] = [
            'url'   => $url,
            'label' => sanitize_text_field($f['label'] ?? $check['title']),
            'limit' => $limit,
        ];
    }

    rdrnl_profile_save_feeds($sub_id, $feeds);
    wp_send_json_success('Vos préférences ont été enregistrées.');
}

/* Validate a feed URL */
add_action('wp_ajax_nopriv_rdrnl_validate_feed', 'rdrnl_ajax_validate_feed');
add_action('wp_ajax_rdrnl_validate_feed',        'rdrnl_ajax_validate_feed');
function rdrnl_ajax_validate_feed() {
    check_ajax_referer('rdrnl_profile_nonce', 'nonce');
    $token = sanitize_text_field( $_POST['rdrnl_tok'] ?? '' );
    if ( ! rdrnl_profile_validate_token($token) ) wp_send_json_error('Lien invalide.');
    $url    = esc_url_raw($_POST['url'] ?? '');
    $permitted = rdrnl_feed_url_permitted($url);
    if ( is_wp_error($permitted) ) wp_send_json_error($permitted->get_error_message());
    $result = rdrnl_validate_feed_url($url);
    $result['ok'] ? wp_send_json_success($result) : wp_send_json_error($result['error']);
}

/* Request new profile link */
add_action('wp_ajax_nopriv_rdrnl_request_profile_link', 'rdrnl_ajax_request_profile_link');
add_action('wp_ajax_rdrnl_request_profile_link',        'rdrnl_ajax_request_profile_link');
function rdrnl_ajax_request_profile_link() {
    // Accept either the front nonce (subscribe form context) or the profile
    // nonce (profile page / request shortcode context) so this endpoint works
    // regardless of which shortcode rendered on the page.
    $nonce = $_POST['nonce'] ?? '';
    if ( ! wp_verify_nonce($nonce, 'rdrnl_front')
         && ! wp_verify_nonce($nonce, 'rdrnl_profile_nonce') ) {
        wp_send_json_error('Nonce invalide. Rechargez la page et réessayez.');
    }
    $email = sanitize_email($_POST['email'] ?? '');
    if ( ! is_email($email) ) wp_send_json_error('Email invalide.');
    $row = rdrnl_get_subscriber_by_email($email);
    if ( ! $row || $row->status !== 'active' ) wp_send_json_error('Email non trouvé ou abonnement inactif.');
    rdrnl_send_profile_email($row->id);
    wp_send_json_success('Un lien vous a été envoyé. Vérifiez votre boîte mail.');
}

/* ── Revoke tokens on unsubscribe ── */
add_action('rdrnl_subscriber_unsubscribed', 'rdrnl_profile_revoke_tokens');

/* ============================================================
   MIGRATION — rename existing sub-N slugs to random slugs
   Safe to run multiple times (idempotent)
   ============================================================ */
function rdrnl_migrate_profile_slugs() {
    $profiles = get_posts([
        'post_type'      => 'rdrnl_profile',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);

    foreach ( $profiles as $pid ) {
        $post = get_post($pid);
        // Only rename posts that still have the old sub-N pattern
        if ( $post && preg_match('/^sub-\d+$/', $post->post_name) ) {
            $new_slug = rdrnl_generate_profile_slug();
            wp_update_post([
                'ID'        => $pid,
                'post_name' => $new_slug,
            ]);
        }
    }
}
