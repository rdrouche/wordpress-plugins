<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', 'rdrnl_admin_menu' );
function rdrnl_admin_menu() {
    add_menu_page( 'RDR Newsletter','Newsletter','manage_options','rdr-newsletter','rdrnl_page_compose','dashicons-email-alt',30);
    add_submenu_page('rdr-newsletter','Compose',   'Compose',    'manage_options','rdr-newsletter',            'rdrnl_page_compose');
    add_submenu_page('rdr-newsletter','Subscribers','Subscribers','manage_options','rdr-newsletter-subscribers','rdrnl_page_subscribers');
    add_submenu_page('rdr-newsletter','Settings',  'Settings',   'manage_options','rdr-newsletter-settings',   'rdrnl_page_settings');
    add_submenu_page('rdr-newsletter','Logs',        'Send History',  'manage_options','rdr-newsletter-logs',     'rdrnl_page_logs');
    add_submenu_page('rdr-newsletter','Tracking',    'Tracking',      'manage_options','rdr-newsletter-tracking', 'rdrnl_page_tracking');
    add_submenu_page('rdr-newsletter','Doc',        'Documentation', 'manage_options','rdr-newsletter-doc',      'rdrnl_page_doc');
    add_submenu_page('rdr-newsletter','Feed Rules', 'Feed Rules',    'manage_options','rdr-newsletter-feed-rules','rdrnl_page_feed_rules');
}

add_action( 'admin_enqueue_scripts', 'rdrnl_admin_assets' );
function rdrnl_admin_assets( $hook ) {
    if ( strpos($hook,'rdr-newsletter') === false ) return;
    // Version = filemtime → cache busted automatically on every plugin update
    $css_ver = RDRNL_VERSION . '.' . @filemtime( RDRNL_DIR . 'assets/admin.css' );
    $js_ver  = RDRNL_VERSION . '.' . @filemtime( RDRNL_DIR . 'assets/admin.js'  );
    wp_enqueue_style(  'rdrnl-admin', RDRNL_URL . 'assets/admin.css', [], $css_ver );
    wp_enqueue_script( 'rdrnl-admin', RDRNL_URL . 'assets/admin.js',  ['jquery'], $js_ver, true );
    wp_localize_script('rdrnl-admin','rdrnl',['nonce'=>wp_create_nonce('rdrnl_admin'),'ajaxurl'=>admin_url('admin-ajax.php')]);
}

add_action('admin_post_rdrnl_save_settings','rdrnl_save_settings');
function rdrnl_save_settings() {
    check_admin_referer('rdrnl_settings_nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $feeds = [];
    $urls  = $_POST['feed_url']??[];
    $limits = $_POST['feed_limit'] ?? [];
    foreach ($urls as $i=>$url) {
        $url = esc_url_raw(trim($url));
        if (!$url) continue;
        if ( ! filter_var($url, FILTER_VALIDATE_URL) ) continue;
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ( ! in_array($scheme, ['http','https'], true) ) continue;
        $feeds[] = [
            'url'    => $url,
            'label'  => sanitize_text_field($_POST['feed_label'][$i] ?? ''),
            'active' => isset($_POST['feed_active'][$i]) ? 1 : 0,
            'limit'  => min(20, max(1, intval($limits[$i] ?? 5))),
        ];
    }

    // Categories: textarea, one per line
    $cats_raw = sanitize_textarea_field($_POST['categories']??'');
    $cats = array_filter(array_map('trim', explode("\n",$cats_raw)));

    $s = get_option(RDRNL_OPTION,[]);
    $s['feeds']           = $feeds;
    $s['from_name']       = sanitize_text_field($_POST['from_name']??'');
    $s['from_email']      = sanitize_email($_POST['from_email']??'');
    $s['subject_prefix']  = sanitize_text_field($_POST['subject_prefix']??'');
    $s['auto_send']       = intval($_POST['auto_send']??0);
    $s['send_day']        = intval($_POST['send_day']??5);
    $s['send_hour']       = intval($_POST['send_hour']??12);
    $s['items_per_feed']  = intval($_POST['items_per_feed']??5);
    $s['post_types']      = array_map('sanitize_text_field',(array)($_POST['post_types']??['post']));
    $s['max_subscribers'] = intval($_POST['max_subscribers']??0);
    $s['categories']      = array_values($cats);
    $s['confirm_subject'] = sanitize_text_field($_POST['confirm_subject']??'');
    $s['confirm_message'] = wp_kses_post($_POST['confirm_message']??'');
    $s['tracking_pixel']  = isset($_POST['tracking_pixel']) ? 1 : 0;

    update_option(RDRNL_OPTION,$s);
    wp_redirect(admin_url('admin.php?page=rdr-newsletter-settings&saved=1'));
    exit;
}

/* ══════════════════════════════════════════════════
   COMPOSE PAGE
══════════════════════════════════════════════════ */
function rdrnl_page_compose() {
    $draft    = rdrnl_get_draft();
    $settings = get_option(RDRNL_OPTION,[]);
    $cats     = $settings['categories'] ?? [];
    $has      = !empty($draft);
    $count    = $draft ? rdrnl_draft_count($draft) : 0;
    $active   = rdrnl_count_active();
    ?>
    <div class="wrap rdrnl-wrap">
    <h1 class="rdrnl-page-title">
      <span class="dashicons dashicons-email-alt"></span> Newsletter Recap
      <?php if($count>0):?><span class="rdrnl-badge" id="rdrnl-count-badge"><?php echo $count;?> items</span><?php endif;?>
    </h1>

    <?php if(!$has):?>
    <div class="rdrnl-empty-state">
      <p>No draft yet. Click <strong>Refresh</strong> to load this week's content.</p>
      <button class="button button-primary" id="rdrnl-btn-refresh"><span class="dashicons dashicons-update"></span> Refresh &amp; Load</button>
    </div>
    <?php else:?>

    <!-- Toolbar -->
    <div class="rdrnl-toolbar">
      <div class="rdrnl-toolbar-left">
        <button class="button" id="rdrnl-btn-refresh"><span class="dashicons dashicons-update"></span> Refresh</button>
        <button class="button" id="rdrnl-btn-preview"><span class="dashicons dashicons-visibility"></span> Preview</button>
        <button class="button rdrnl-btn-danger" id="rdrnl-btn-reset" title="Supprimer le brouillon et repartir de zéro">
          <span class="dashicons dashicons-trash"></span> Reset brouillon
        </button>
      </div>
      <div class="rdrnl-toolbar-right">
        <input type="email" id="rdrnl-test-email" placeholder="test@example.com" value="<?php echo esc_attr($settings['from_email']??'');?>">
        <button class="button" id="rdrnl-btn-test"><span class="dashicons dashicons-email"></span> Send Test</button>
        <button class="button button-primary" id="rdrnl-btn-send" <?php echo $count<1?'disabled':'';?>>
          <span class="dashicons dashicons-yes"></span> Send to <?php echo $active;?> subscribers
        </button>
      </div>
    </div>

    <!-- Subject -->
    <div class="rdrnl-card rdrnl-subject-bar">
      <label for="rdrnl-subject"><strong>Subject</strong></label>
      <input type="text" id="rdrnl-subject" value="<?php echo esc_attr($draft['subject']??'');?>">
      <span class="rdrnl-autosave-hint" id="rdrnl-subject-saved"></span>
    </div>

    <div class="rdrnl-card rdrnl-subject-bar" style="margin-top:8px">
      <label for="rdrnl-week-label"><strong>Date / label email</strong></label>
      <input type="text" id="rdrnl-week-label"
             value="<?php echo esc_attr($draft['week_label'] ?? '');?>"
             placeholder="ex: Semaine du 24 mars 2026"
             style="flex:1">
      <button type="button" id="rdrnl-btn-week-auto" class="button" title="Remplir automatiquement avec la date de cette semaine">
        ↺ Auto
      </button>
      <span class="rdrnl-autosave-hint" id="rdrnl-week-label-saved"></span>
    </div>

    <div class="rdrnl-layout">
    <div class="rdrnl-main">

      <!-- Personal message -->
      <div class="rdrnl-card">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-edit"></span><h3>Personal message <span class="rdrnl-optional">(optional)</span></h3></div>
        <textarea id="rdrnl-personal-msg" rows="4" placeholder="Intro for this week…"><?php echo esc_textarea($draft['personal_message']??'');?></textarea>
        <span class="rdrnl-autosave-hint">Saved automatically</span>
      </div>

      <!-- Own posts -->
      <?php if(!empty($draft['own_posts'])):?>
      <div class="rdrnl-card">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-admin-post"></span>
          <h3>New on RDR-IT <span class="rdrnl-section-count"><?php echo count(array_filter($draft['own_posts'],fn($p)=>$p['selected']));?> selected</span></h3>
        </div>
        <div class="rdrnl-item-list">
          <?php foreach($draft['own_posts'] as $post): rdrnl_render_item($post,'own_post',(string)$post['id'],$cats); endforeach;?>
        </div>
      </div>
      <?php endif;?>

      <!-- Manual links -->
      <div class="rdrnl-card" id="rdrnl-manual-card">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-admin-links"></span>
          <h3>Liens &amp; articles <span class="rdrnl-section-count" id="rdrnl-manual-count"><?php echo count($draft['manual_links']??[]);?> ajouté<?php echo count($draft['manual_links']??[])>1?'s':'';?></span></h3><div style="margin-left:auto;display:flex;gap:6px"><button class="button" id="rdrnl-btn-add-post" title="Choisir un article"><span class="dashicons dashicons-admin-post"></span> Article du site</button></div>
        </div>
        <div class="rdrnl-add-link-form">
          <div class="rdrnl-add-link-row">
            <input type="url" id="rdrnl-manual-url" placeholder="https://…">
            <button class="button" id="rdrnl-btn-fetch-og"><span class="dashicons dashicons-search"></span> Fetch info</button>
          </div>
          <div id="rdrnl-og-preview" class="rdrnl-og-preview" style="display:none">
            <img id="rdrnl-og-img" src="" alt="" style="display:none">
            <div class="rdrnl-og-body">
              <input type="text" id="rdrnl-og-title" placeholder="Title">
              <textarea id="rdrnl-og-desc" rows="2" placeholder="Description"></textarea>
              <div class="rdrnl-og-site" id="rdrnl-og-site"></div>
            </div>
          </div>
          <!-- Note + category for manual link -->
          <div id="rdrnl-og-extra" style="display:none">
            <textarea id="rdrnl-manual-note" rows="2" placeholder="Your note / observation (optional — will appear in the email)"></textarea>
            <select id="rdrnl-manual-cat">
              <option value="">— Category —</option>
              <?php foreach($cats as $c): ?><option value="<?php echo esc_attr($c);?>"><?php echo esc_html($c);?></option><?php endforeach;?>
            </select>
          </div>
          <button class="button button-primary" id="rdrnl-btn-add-manual" disabled><span class="dashicons dashicons-plus"></span> Add to newsletter</button>
        </div>
        <div class="rdrnl-item-list" id="rdrnl-manual-list">
          <?php foreach($draft['manual_links']??[] as $link): rdrnl_render_item($link,'manual',$link['url'],$cats,true); endforeach;?>
        </div>
      </div>

      <!-- Feed sections -->
      <?php foreach($draft['feed_sections']??[] as $si=>$section):
        $sel = count(array_filter($section['items'],fn($i)=>$i['selected']));
      ?>
      <div class="rdrnl-card rdrnl-feed-section">
        <div class="rdrnl-card-header rdrnl-feed-header" data-toggle="feed-<?php echo $si;?>">
          <span class="dashicons dashicons-rss"></span>
          <h3><?php echo esc_html($section['feed_label']);?> <span class="rdrnl-section-count rdrnl-feed-count"><?php echo $sel;?> selected</span></h3>
          <span class="dashicons dashicons-arrow-down-alt2 rdrnl-collapse-icon"></span>
        </div>
        <div class="rdrnl-item-list" id="feed-<?php echo $si;?>">
          <?php foreach($section['items'] as $item): rdrnl_render_item($item,'feed_item',$item['url'],$cats); endforeach;?>
        </div>
      </div>
      <?php endforeach;?>

    </div><!-- .rdrnl-main -->

    <!-- Sidebar -->
    <div class="rdrnl-sidebar">
      <div class="rdrnl-card rdrnl-summary-card">
        <h3>Summary</h3>
        <div class="rdrnl-summary-row"><span>Total selected</span><strong id="rdrnl-count-badge-sidebar"><?php echo $count;?></strong></div>
        <div class="rdrnl-summary-row"><span>Active subscribers</span><strong><?php echo $active;?></strong></div>
        <div class="rdrnl-summary-row"><span>Waitlist</span><strong><?php echo rdrnl_count_waitlist();?></strong></div>
        <hr>
        <div class="rdrnl-summary-row"><span>Draft updated</span><small><?php echo date('d/m H:i',$draft['updated_at']??time());?></small></div>
      </div>
    </div>

    </div><!-- .rdrnl-layout -->
    <?php endif;?>

    <!-- Preview modal -->
    <div id="rdrnl-preview-modal" class="rdrnl-modal" style="display:none">
      <div class="rdrnl-modal-overlay"></div>
      <div class="rdrnl-modal-box">
        <div class="rdrnl-modal-header"><h3>Email Preview</h3><button class="rdrnl-modal-close button-link"><span class="dashicons dashicons-no"></span></button></div>
        <div class="rdrnl-modal-body"><iframe id="rdrnl-preview-frame" src="about:blank"></iframe></div>
      </div>
    </div>

    <div id="rdrnl-toast" class="rdrnl-toast" style="display:none"></div>
    </div>
    <?php
}

/* ── Render a single item row (shared by own posts, feeds, manual) ── */
function rdrnl_render_item( $item, $type, $key, $cats = [], $is_manual = false ) {
    $selected = $item['selected'] ?? true;
    $note     = $item['note']     ?? '';
    $category = $item['category'] ?? '';
    $url      = $item['url']      ?? '';
    ?>
    <div class="rdrnl-item<?php echo $selected?'':' rdrnl-item-deselected';?>" data-type="<?php echo esc_attr($type);?>" data-key="<?php echo esc_attr($key);?>">
      <?php if(!empty($item['image'])): ?>
      <img class="rdrnl-item-thumb" src="<?php echo esc_url($item['image']);?>" alt="">
      <?php else: ?>
      <div class="rdrnl-item-thumb rdrnl-no-thumb">📰</div>
      <?php endif;?>

      <div class="rdrnl-item-body">
        <div class="rdrnl-item-title">
          <a href="<?php echo esc_url($url);?>" target="_blank"><?php echo esc_html($item['title']??'');?></a>
          <?php if(!empty($item['is_new'])): ?><span class="rdrnl-new-badge">NEW</span><?php endif;?>
        </div>
        <?php if(!empty($item['description'])): ?>
        <div class="rdrnl-item-desc"><?php echo esc_html($item['description']);?></div>
        <?php endif;?>
        <div class="rdrnl-item-meta">
          <?php if(!empty($item['date'])): ?><?php echo esc_html($item['date']);?><?php endif;?>
        </div>

        <!-- Note + Category (inline collapsible) -->
        <div class="rdrnl-item-meta-fields">
          <button type="button" class="rdrnl-toggle-meta button-link">
            <span class="dashicons dashicons-edit-large"></span>
            <?php echo ($note||$category)?'Edit note/category':'Add note/category';?>
          </button>
          <div class="rdrnl-meta-panel" style="<?php echo ($note||$category)?'':'display:none';?>">
            <textarea class="rdrnl-note-field" rows="2" placeholder="Note / observation (visible in the email)"><?php echo esc_textarea($note);?></textarea>
            <select class="rdrnl-cat-field">
              <option value="">— Category —</option>
              <?php foreach($cats as $c): ?>
              <option value="<?php echo esc_attr($c);?>" <?php selected($category,$c);?>><?php echo esc_html($c);?></option>
              <?php endforeach;?>
            </select>
            <button type="button" class="button rdrnl-save-meta">Save</button>
            <?php if($note): ?><span class="rdrnl-note-preview"><?php echo esc_html(mb_substr($note,0,80)).(mb_strlen($note)>80?'…':'');?></span><?php endif;?>
          </div>
        </div>
      </div>

      <div class="rdrnl-item-actions">
        <?php if($is_manual): ?>
        <button class="rdrnl-remove-manual button-link" data-url="<?php echo esc_attr($url);?>" title="Remove"><span class="dashicons dashicons-trash"></span></button>
        <?php else: ?>
        <label class="rdrnl-toggle">
          <input type="checkbox" class="rdrnl-select-item" <?php checked($selected);?>>
          <span class="rdrnl-toggle-track"><span class="rdrnl-toggle-thumb"></span></span>
        </label>
        <?php endif;?>
      </div>
    </div>
    <?php
}

/* ══════════════════════════════════════════════════
   SUBSCRIBERS PAGE
══════════════════════════════════════════════════ */
function rdrnl_page_subscribers() {
    $settings   = get_option(RDRNL_OPTION,[]);
    $max        = intval($settings['max_subscribers']??0);
    $active     = rdrnl_count_active();
    $waitlist   = rdrnl_count_waitlist();
    $all        = rdrnl_get_all_subscribers(null,500);
    ?>
    <div class="wrap rdrnl-wrap">
    <h1><span class="dashicons dashicons-groups"></span> Subscribers</h1>

    <div class="rdrnl-sub-stats">
      <div class="rdrnl-stat-box">
        <span class="rdrnl-stat-num" id="stat-active"><?php echo $active;?></span>
        <span>Active<?php if($max>0): echo ' / '.$max; endif;?></span>
        <?php if($max>0 && $active>=$max): ?><span class="rdrnl-badge-full">FULL</span><?php endif;?>
      </div>
      <div class="rdrnl-stat-box">
        <span class="rdrnl-stat-num" id="stat-waitlist"><?php echo $waitlist;?></span>
        <span>Waitlist</span>
      </div>
      <?php if($max>0): ?>
      <div class="rdrnl-stat-box">
        <a href="<?php echo admin_url('admin.php?page=rdr-newsletter-settings');?>" class="button button-small">Change limit (<?php echo $max;?>)</a>
      </div>
      <div class="rdrnl-stat-box">
        <button class="button" id="rdrnl-promote-btn">Promote from waitlist</button>
      </div>
      <?php else: ?>
      <div class="rdrnl-stat-box"><span class="rdrnl-muted">No subscriber limit set</span></div>
      <?php endif;?>
      <div class="rdrnl-stat-box">
        <span class="rdrnl-stat-num" style="font-size:20px"><?php echo rdrnl_tracking_pending_reengagement_count();?></span>
        <span>En attente<br>r&eacute;engagement</span>
      </div>
    </div>

    <!-- Add manually -->
    <div class="rdrnl-card" style="margin-bottom:16px">
      <div class="rdrnl-card-header"><span class="dashicons dashicons-plus-alt"></span><h3>Add subscriber manually</h3></div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <input type="text"  id="rdrnl-add-name"  placeholder="Name (optional)" style="width:160px">
        <input type="email" id="rdrnl-add-email" placeholder="email@example.com" style="width:220px">
        <button class="button button-primary" id="rdrnl-add-sub-btn">Add</button>
        <span id="rdrnl-add-sub-msg" style="font-size:13px"></span>
      </div>
    </div>

    <!-- Table -->
    <table class="widefat striped" id="rdrnl-sub-table">
      <thead><tr><th>#</th><th>Email</th><th>Name</th><th>Status</th><th>Subscribed</th><th>Confirmed</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach($all as $sub): ?>
        <tr id="sub-row-<?php echo $sub->id;?>">
          <td><?php echo $sub->id;?></td>
          <td><?php echo esc_html($sub->email);?></td>
          <td><?php echo esc_html($sub->name);?></td>
          <td><span class="rdrnl-log-type rdrnl-log-<?php echo esc_attr($sub->status);?>"><?php echo esc_html($sub->status);?></span></td>
          <td><?php echo esc_html($sub->subscribed_at?date('d/m/Y',strtotime($sub->subscribed_at)):'—');?></td>
          <td><?php echo esc_html($sub->confirmed_at?date('d/m/Y',strtotime($sub->confirmed_at)):'—');?></td>
          <td class="rdrnl-sub-actions">
            <?php if($sub->status!=='active'): ?>
            <button class="button button-small rdrnl-sub-activate" data-id="<?php echo $sub->id;?>">Activate</button>
            <?php endif;?>
            <?php if($sub->status!=='unsubscribed'): ?>
            <button class="button button-small rdrnl-sub-unsub" data-id="<?php echo $sub->id;?>">Unsub</button>
            <?php endif;?>
            <button class="button button-small rdrnl-sub-del" data-id="<?php echo $sub->id;?>">Delete</button>
          </td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
    <div id="rdrnl-toast" class="rdrnl-toast" style="display:none"></div>
    </div>
    <?php
}

/* ══════════════════════════════════════════════════
   SETTINGS PAGE
══════════════════════════════════════════════════ */
function rdrnl_page_settings() {
    $s    = get_option(RDRNL_OPTION,[]);
    $feeds= $s['feeds']??[];
    $days = [1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'];
    $cpts = get_post_types(['public'=>true],'objects');
    $cats = implode("\n",$s['categories']??[]);
    ?>
    <div class="wrap rdrnl-wrap">
    <h1><span class="dashicons dashicons-admin-settings"></span> Newsletter Settings</h1>
    <?php if(isset($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>Settings saved.</p></div><?php endif;?>

    <form method="post" action="<?php echo admin_url('admin-post.php');?>">
    <?php wp_nonce_field('rdrnl_settings_nonce');?>
    <input type="hidden" name="action" value="rdrnl_save_settings">

    <div class="rdrnl-settings-grid">

      <div class="rdrnl-card">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-email-alt"></span><h3>Email config</h3></div>
        <table class="form-table rdrnl-form-table">
          <tr><th>From name</th><td><input type="text" name="from_name" value="<?php echo esc_attr($s['from_name']??'');?>" class="regular-text"></td></tr>
          <tr><th>From email</th><td><input type="email" name="from_email" value="<?php echo esc_attr($s['from_email']??'');?>" class="regular-text"></td></tr>
          <tr><th>Subject prefix</th><td><input type="text" name="subject_prefix" value="<?php echo esc_attr($s['subject_prefix']??'');?>" class="regular-text"></td></tr>
        </table>
      </div>

      <div class="rdrnl-card">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-clock"></span><h3>Automatic scheduling</h3></div>
        <table class="form-table rdrnl-form-table">
          <tr><th>Auto-send</th><td><label><input type="checkbox" name="auto_send" value="1" <?php checked($s['auto_send']??0);?>> Enable weekly auto-send</label></td></tr>
          <tr><th>Day</th><td><select name="send_day"><?php foreach($days as $n=>$l): ?><option value="<?php echo $n;?>" <?php selected($s['send_day']??5,$n);?>><?php echo $l;?></option><?php endforeach;?></select></td></tr>
          <tr><th>Hour</th><td><select name="send_hour"><?php for($h=0;$h<24;$h++): ?><option value="<?php echo $h;?>" <?php selected($s['send_hour']??12,$h);?>><?php printf('%02d:00',$h);?></option><?php endfor;?></select> <span class="description"><?php echo wp_timezone_string();?></span></td></tr>
        </table>
      </div>

      <div class="rdrnl-card">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-groups"></span><h3>Subscriber limit</h3></div>
        <table class="form-table rdrnl-form-table">
          <tr>
            <th>Max subscribers</th>
            <td>
              <input type="number" name="max_subscribers" value="<?php echo intval($s['max_subscribers']??0);?>" min="0" style="width:90px">
              <p class="description">0 = unlimited. Set to 1000 for Mailjet free tier. Subscribers over the limit go to waitlist.</p>
            </td>
          </tr>
        </table>
        <p class="description" style="padding:0 10px 10px">Shortcode: <code>[rdrnl_subscribe]</code></p>
      </div>

      <div class="rdrnl-card">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-tag"></span><h3>Newsletter categories</h3></div>
        <p class="description" style="margin-bottom:8px">One category per line. Used to tag and group items in the email.</p>
        <textarea name="categories" rows="8" class="large-text"><?php echo esc_textarea($cats);?></textarea>
      </div>

      <div class="rdrnl-card">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-admin-post"></span><h3>Content</h3></div>
        <table class="form-table rdrnl-form-table">
          <tr><th>Post types</th><td><?php foreach($cpts as $slug=>$obj): ?><label style="display:block;margin-bottom:4px"><input type="checkbox" name="post_types[]" value="<?php echo esc_attr($slug);?>" <?php checked(in_array($slug,$s['post_types']??['post']));?>><?php echo esc_html($obj->labels->singular_name);?> <code><?php echo $slug;?></code></label><?php endforeach;?></td></tr>
          <tr><th>Items per feed</th><td><input type="number" name="items_per_feed" value="<?php echo intval($s['items_per_feed']??5);?>" min="1" max="20" style="width:70px"></td></tr>
        </table>
      </div>

      <div class="rdrnl-card">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-privacy"></span><h3>Confidentialité &amp; suivi</h3></div>
        <table class="form-table rdrnl-form-table">
          <tr>
            <th>Pixel d'ouverture</th>
            <td>
              <label>
                <input type="checkbox" name="tracking_pixel" value="1" <?php checked($s['tracking_pixel']??1);?>>
                Activer le pixel de suivi des ouvertures
              </label>
              <p class="description">
                Le pixel permet de mesurer le taux d'ouverture des emails. Il dépose une image
                invisible qui, en France, relève du suivi soumis au consentement (recommandations CNIL).
                <br><strong>Décochez cette case pour désactiver totalement le pixel</strong> et n'envoyer
                aucun traceur d'ouverture. Le suivi des clics (redirection) reste actif indépendamment ;
                il est nécessaire au bon fonctionnement des liens et au réengagement.
              </p>
            </td>
          </tr>
        </table>
      </div>

      <div class="rdrnl-card">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-yes-alt"></span><h3>Double opt-in email</h3></div>
        <table class="form-table rdrnl-form-table">
          <tr><th>Subject</th><td><input type="text" name="confirm_subject" value="<?php echo esc_attr($s['confirm_subject']??'');?>" class="regular-text"></td></tr>
          <tr><th>Custom message</th><td><textarea name="confirm_message" rows="3" class="large-text"><?php echo esc_textarea($s['confirm_message']??'');?></textarea><p class="description">Shown above the confirmation button.</p></td></tr>
        </table>
      </div>

      <div class="rdrnl-card rdrnl-full-width">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-rss"></span><h3>RSS Feeds</h3></div>
        <p class="description" style="margin-bottom:8px">
          <strong>Nb articles</strong> : nombre d'articles récupérés par défaut pour ce flux (max 20).
        </p>
        <div id="rdrnl-feeds-list">
          <?php if(empty($feeds)) $feeds=[['url'=>'','label'=>'','active'=>1,'limit'=>5]];
          foreach($feeds as $i=>$feed): ?>
          <div class="rdrnl-feed-row">
            <label class="rdrnl-feed-active" title="Activer/désactiver">
              <input type="checkbox" name="feed_active[<?php echo $i;?>]" value="1" <?php checked($feed['active']??1);?>>
            </label>
            <input type="url"  name="feed_url[<?php echo $i;?>]"
                   value="<?php echo esc_attr($feed['url']);?>"
                   placeholder="https://example.com/feed" class="regular-text">
            <input type="text" name="feed_label[<?php echo $i;?>]"
                   value="<?php echo esc_attr($feed['label']);?>"
                   placeholder="Label (ex: Korben)" style="width:140px">
            <input type="number" name="feed_limit[<?php echo $i;?>]"
                   value="<?php echo intval($feed['limit']??5);?>"
                   min="1" max="20" style="width:60px" title="Nombre d'articles">
            <button type="button" class="button rdrnl-remove-feed" title="Supprimer">
              <span class="dashicons dashicons-trash"></span>
            </button>
          </div>
          <?php endforeach;?>
        </div>
        <button type="button" class="button" id="rdrnl-add-feed">
          <span class="dashicons dashicons-plus"></span> Ajouter un flux
        </button>
        <p class="description" style="margin-top:6px">
          Le champ numérique définit combien d'articles sont récupérés pour ce flux spécifique.
        </p>
      </div>

    </div>
    <p class="submit"><button type="submit" class="button button-primary button-large">Save settings</button></p>
    </form>
    </div>
    <?php
}

/* ══════════════════════════════════════════════════
   LOGS PAGE
══════════════════════════════════════════════════ */
function rdrnl_page_logs() {
    // Handle delete actions
    if ( isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['idx']) ) {
        check_admin_referer('rdrnl_delete_log');
        $log = get_option(RDRNL_LOG_KEY,[]);
        $idx = intval($_GET['idx']);
        if (isset($log[$idx])) {
            array_splice($log, $idx, 1);
            update_option(RDRNL_LOG_KEY, $log, false);
        }
        wp_redirect(admin_url('admin.php?page=rdr-newsletter-logs&deleted=1'));
        exit;
    }
    if ( isset($_GET['action']) && $_GET['action'] === 'clear_tests' ) {
        check_admin_referer('rdrnl_delete_log');
        $log = get_option(RDRNL_LOG_KEY,[]);
        $log = array_values(array_filter($log, fn($e) => $e['type'] !== 'test'));
        update_option(RDRNL_LOG_KEY, $log, false);
        wp_redirect(admin_url('admin.php?page=rdr-newsletter-logs&cleared=1'));
        exit;
    }

    $log      = get_option(RDRNL_LOG_KEY,[]);
    $per_page = 20;
    $page     = max(1, intval($_GET['paged'] ?? 1));
    $total    = count($log);
    $offset   = ($page - 1) * $per_page;
    $slice    = array_slice($log, $offset, $per_page, true); // preserve keys for delete index
    $pages    = (int) ceil($total / $per_page);
    $base_url = admin_url('admin.php?page=rdr-newsletter-logs');
    ?>
    <div class="wrap rdrnl-wrap">
    <h1><span class="dashicons dashicons-list-view"></span> Send History</h1>

    <?php if(isset($_GET['deleted'])): ?><div class="notice notice-success is-dismissible"><p>Entrée supprimée.</p></div><?php endif;?>
    <?php if(isset($_GET['cleared'])): ?><div class="notice notice-success is-dismissible"><p>Envois de test supprimés.</p></div><?php endif;?>

    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <p style="color:#888;font-size:13px"><?php echo $total; ?> entrée<?php echo $total>1?'s':'';?> au total</p>
      <a href="<?php echo esc_url(wp_nonce_url($base_url.'&action=clear_tests','rdrnl_delete_log'));?>" class="button">
        Supprimer tous les tests
      </a>
    </div>

    <?php if(empty($log)): ?><p>Aucun envoi enregistré.</p><?php else: ?>
    <table class="widefat striped">
      <thead>
        <tr><th>Date</th><th>Type</th><th>Sujet</th><th>Destinataires</th><th>Items</th><th>Action</th></tr>
      </thead>
      <tbody>
        <?php foreach($slice as $real_idx => $e): ?>
        <tr>
          <td><?php echo esc_html($e['date']);?></td>
          <td><span class="rdrnl-log-type rdrnl-log-<?php echo esc_attr($e['type']);?>"><?php echo esc_html($e['type']);?></span></td>
          <td><?php echo esc_html($e['subject']);?></td>
          <td><?php echo intval($e['recipients']);?></td>
          <td><?php echo intval($e['items']);?></td>
          <td>
            <a href="<?php echo esc_url(wp_nonce_url($base_url.'&action=delete&idx='.$real_idx,'rdrnl_delete_log'));?>"
               class="button button-small"
               onclick="return confirm('Supprimer cette entrée ?')">
              Supprimer
            </a>
          </td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>

    <?php if($pages > 1): ?>
    <div class="tablenav bottom" style="margin-top:10px">
      <div class="tablenav-pages">
        <?php for($p=1;$p<=$pages;$p++): ?>
        <a href="<?php echo esc_url($base_url.'&paged='.$p);?>"
           class="button<?php echo $p===$page?' button-primary':'';?>"
           style="margin:0 2px">
          <?php echo $p; ?>
        </a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif;?>
    </div>
    <?php
}

/* ══════════════════════════════════════════════════
   ARTICLE SELECTOR MODAL (injected in compose page footer)
══════════════════════════════════════════════════ */
add_action('admin_footer', 'rdrnl_article_selector_modal');
function rdrnl_article_selector_modal() {
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id,'rdr-newsletter') === false) return;
    $settings  = get_option(RDRNL_OPTION,[]);
    $cats      = $settings['categories'] ?? [];
    ?>
    <div id="rdrnl-picker-modal" class="rdrnl-modal" style="display:none">
      <div class="rdrnl-modal-overlay"></div>
      <div class="rdrnl-modal-box rdrnl-picker-box">
        <div class="rdrnl-modal-header">
          <h3>Ajouter un article</h3>
          <button class="rdrnl-modal-close button-link"><span class="dashicons dashicons-no"></span></button>
        </div>
        <div class="rdrnl-picker-search">
          <input type="search" id="rdrnl-picker-q" placeholder="Rechercher par titre…" autocomplete="off">
          <span class="rdrnl-spinner" id="rdrnl-picker-spinner" style="display:none"></span>
        </div>
        <div id="rdrnl-picker-results" class="rdrnl-picker-results">
          <p class="rdrnl-picker-hint">Tapez au moins 2 caractères pour chercher</p>
        </div>
        <!-- Note + category for selected article -->
        <div class="rdrnl-picker-meta" id="rdrnl-picker-meta" style="display:none">
          <hr style="margin:12px 0">
          <div id="rdrnl-picker-selected-preview" class="rdrnl-picker-selected-preview"></div>
          <textarea id="rdrnl-picker-note" rows="2" placeholder="Note / observation (optionnel — visible dans l'email)"></textarea>
          <select id="rdrnl-picker-cat">
            <option value="">— Catégorie —</option>
            <?php foreach($cats as $c):?><option value="<?php echo esc_attr($c);?>"><?php echo esc_html($c);?></option><?php endforeach;?>
          </select>
          <button class="button button-primary" id="rdrnl-picker-add"><span class="dashicons dashicons-plus"></span> Ajouter à la newsletter</button>
        </div>
      </div>
    </div>
    <?php
}

/* ══════════════════════════════════════════════════
   TRACKING PAGE
══════════════════════════════════════════════════ */
function rdrnl_page_tracking() {
    global $wpdb;
    $track_table = rdrnl_tracking_table();
    $sub_table   = rdrnl_table();

    // Global stats
    $global = $wpdb->get_row(
        "SELECT
            COUNT(DISTINCT subscriber_id)               AS unique_recipients,
            SUM(opened_at IS NOT NULL)                  AS unique_openers,
            SUM(last_click_at IS NOT NULL)              AS unique_clickers,
            SUM(open_count)                             AS total_opens,
            SUM(click_count)                            AS total_clicks,
            COUNT(DISTINCT edition_slug)                AS editions_tracked
         FROM {$track_table}
         WHERE edition_slug != '__reengagement__'"
    );

    $open_rate  = $global->unique_recipients > 0
        ? round(($global->unique_openers  / $global->unique_recipients) * 100, 1) : 0;
    $click_rate = $global->unique_recipients > 0
        ? round(($global->unique_clickers / $global->unique_recipients) * 100, 1) : 0;

    // Per-edition stats
    $editions = $wpdb->get_results(
        "SELECT
            edition_slug,
            COUNT(DISTINCT subscriber_id)              AS sent,
            SUM(opened_at IS NOT NULL)                 AS opened,
            SUM(last_click_at IS NOT NULL)             AS clicked,
            MIN(sent_at)                               AS first_sent
         FROM {$track_table}
         WHERE edition_slug != '__reengagement__'
         GROUP BY edition_slug
         ORDER BY first_sent DESC
         LIMIT 20"
    );

    // Reengagement stats
    $reeng_pending  = rdrnl_tracking_pending_reengagement_count();
    $reeng_total    = (int)$wpdb->get_var(
        "SELECT COUNT(DISTINCT subscriber_id) FROM {$track_table}
         WHERE reengagement_sent_at IS NOT NULL"
    );
    // Number of subscribers successfully re-engaged (opened/clicked after a reengagement email)
    $reeng_ok = (int)$wpdb->get_var(
        "SELECT COUNT(DISTINCT subscriber_id) FROM {$track_table}
         WHERE reengagement_sent_at IS NOT NULL
           AND ( opened_at > reengagement_sent_at OR last_click_at > reengagement_sent_at )"
    );

    // Inactive subscribers (no activity 28d, no pending reengagement)
    $inactive_count = (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM {$sub_table} s
         WHERE s.status = 'active'
         AND NOT EXISTS (
             SELECT 1 FROM {$track_table} t
             WHERE t.subscriber_id = s.id
               AND t.reengagement_token IS NOT NULL
               AND t.reengagement_expiry > NOW()
         )
         AND (
             NOT EXISTS (SELECT 1 FROM {$track_table} t2 WHERE t2.subscriber_id = s.id
                         AND t2.edition_slug != '__reengagement__')
             OR NOT EXISTS (
                 SELECT 1 FROM {$track_table} t3
                 WHERE t3.subscriber_id = s.id
                   AND (t3.opened_at >= DATE_SUB(NOW(),INTERVAL 28 DAY)
                        OR t3.last_click_at >= DATE_SUB(NOW(),INTERVAL 28 DAY))
             )
         )"
    );
    ?>
    <?php
    $settings_now = get_option(RDRNL_OPTION, []);
    $pixel_on_now = ! empty($settings_now['tracking_pixel']);
    ?>
    <div class="wrap rdrnl-wrap">
    <h1><span class="dashicons dashicons-chart-bar"></span> Tracking</h1>

    <?php if ( $pixel_on_now ) : ?>
    <div class="notice notice-info" style="margin:12px 0">
      <p>
        <span class="dashicons dashicons-visibility" style="color:#2271b1"></span>
        Le <strong>pixel d'ouverture est activé</strong>. Les taux d'ouverture ci-dessous sont mesurés.
        Pour le désactiver (conformité CNIL), rendez-vous dans
        <a href="<?php echo esc_url(admin_url('admin.php?page=rdr-newsletter-settings'));?>">Settings → Confidentialité &amp; suivi</a>.
      </p>
    </div>
    <?php else : ?>
    <div class="notice notice-warning" style="margin:12px 0">
      <p>
        <span class="dashicons dashicons-hidden" style="color:#996800"></span>
        Le <strong>pixel d'ouverture est désactivé</strong>. Les taux d'ouverture ne sont plus collectés
        et resteront à zéro pour les nouveaux envois. Le suivi des clics reste actif.
      </p>
    </div>
    <?php endif; ?>

    <!-- Global stats cards -->
    <div class="rdrnl-track-cards">
      <div class="rdrnl-track-card">
        <div class="rdrnl-track-num"><?php echo $global->editions_tracked ?? 0;?></div>
        <div class="rdrnl-track-label">Éditions trackées</div>
      </div>
      <div class="rdrnl-track-card">
        <div class="rdrnl-track-num"><?php echo $global->unique_recipients ?? 0;?></div>
        <div class="rdrnl-track-label">Destinataires uniques</div>
      </div>
      <div class="rdrnl-track-card rdrnl-track-card-accent">
        <div class="rdrnl-track-num"><?php echo $open_rate;?>%</div>
        <div class="rdrnl-track-label">Taux d'ouverture moyen</div>
        <div class="rdrnl-track-sub"><?php echo $global->unique_openers ?? 0;?> ouvreurs uniques</div>
      </div>
      <div class="rdrnl-track-card rdrnl-track-card-accent">
        <div class="rdrnl-track-num"><?php echo $click_rate;?>%</div>
        <div class="rdrnl-track-label">Taux de clic moyen</div>
        <div class="rdrnl-track-sub"><?php echo $global->unique_clickers ?? 0;?> cliqueurs uniques</div>
      </div>
      <div class="rdrnl-track-card <?php echo $inactive_count > 0 ? 'rdrnl-track-card-warn' : '';?>">
        <div class="rdrnl-track-num"><?php echo $inactive_count;?></div>
        <div class="rdrnl-track-label">Inactifs (28j, sans réengagement en cours)</div>
      </div>
      <div class="rdrnl-track-card">
        <div class="rdrnl-track-num"><?php echo $reeng_pending;?></div>
        <div class="rdrnl-track-label">Réengagements en attente</div>
        <div class="rdrnl-track-sub">Prochain nettoyage : mercredi 12h</div>
      </div>
    </div>

    <p class="description" style="margin:12px 0 20px">
      ⚠️ Le taux d'ouverture est approximatif (~60-70% fiable) — Apple Mail Privacy ouvre les pixels automatiquement.
      Le taux de clic est le plus fiable des deux indicateurs.
    </p>

    <!-- Per-edition table -->
    <h2 style="font-size:16px;margin-bottom:12px">Par édition</h2>
    <?php if(empty($editions)):?><p>Aucune donnée de tracking pour le moment.</p>
    <?php else:?>
    <table class="widefat striped">
      <thead>
        <tr>
          <th>Édition</th><th>Envoyé</th>
          <th>Ouvertures uniques</th><th>Taux ouv.</th>
          <th>Clics uniques</th><th>Taux clic</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach($editions as $ed):
        $or = $ed->sent > 0 ? round(($ed->opened/$ed->sent)*100,1) : 0;
        $cr = $ed->sent > 0 ? round(($ed->clicked/$ed->sent)*100,1) : 0;
      ?>
        <tr>
          <td><code><?php echo esc_html($ed->edition_slug);?></code></td>
          <td><?php echo intval($ed->sent);?></td>
          <td><?php echo intval($ed->opened);?></td>
          <td>
            <div class="rdrnl-mini-bar-wrap">
              <div class="rdrnl-mini-bar" style="width:<?php echo min($or,100);?>%"></div>
              <span><?php echo $or;?>%</span>
            </div>
          </td>
          <td><?php echo intval($ed->clicked);?></td>
          <td>
            <div class="rdrnl-mini-bar-wrap rdrnl-mini-bar-click">
              <div class="rdrnl-mini-bar" style="width:<?php echo min($cr,100);?>%"></div>
              <span><?php echo $cr;?>%</span>
            </div>
          </td>
          <td><?php echo esc_html(substr($ed->first_sent,0,10));?></td>
        </tr>
      <?php endforeach;?>
      </tbody>
    </table>
    <?php endif;?>

    </div>
    <?php
}

/* ══════════════════════════════════════════════════
   DOCUMENTATION PAGE
══════════════════════════════════════════════════ */
function rdrnl_page_doc() {
    $site = home_url();
    ?>
    <div class="wrap rdrnl-wrap">
    <h1><span class="dashicons dashicons-book"></span> Documentation — RDR Newsletter</h1>

    <div class="rdrnl-doc-grid">

      <!-- SHORTCODES -->
      <div class="rdrnl-doc-card">
        <h2>📋 Shortcodes</h2>

        <h3>[rdrnl_subscribe]</h3>
        <p>Formulaire d'inscription à la newsletter. À placer sur n'importe quelle page.</p>
        <div class="rdrnl-doc-code">[rdrnl_subscribe
  title="Recevoir le récap hebdomadaire"
  description="Chaque vendredi — actus IT + nouveaux tutos."
  show_name="yes"
  button="S'inscrire"
  style="card"
]</div>
        <table class="rdrnl-doc-table">
          <tr><th>Paramètre</th><th>Valeurs</th><th>Défaut</th></tr>
          <tr><td><code>style</code></td><td><code>card</code> / <code>inline</code> / <code>minimal</code></td><td><code>card</code></td></tr>
          <tr><td><code>show_name</code></td><td><code>yes</code> / <code>no</code></td><td><code>yes</code></td></tr>
          <tr><td><code>title</code></td><td>Texte libre</td><td>Défini dans settings</td></tr>
          <tr><td><code>button</code></td><td>Texte du bouton</td><td><code>S'inscrire</code></td></tr>
        </table>

        <h3 style="margin-top:20px">Reset brouillon</h3>
        <p>Bouton <strong>Reset brouillon</strong> dans la toolbar. Supprime le brouillon en cours (sélections, notes, liens) et repart de zéro. La liste des flux RSS et des articles récents sera rechargée au prochain Refresh.</p>

        <h3 style="margin-top:20px">Article du site</h3>
        <p>Bouton <strong>"Article du site"</strong> dans la section Liens &amp; articles. Recherche par titre sur tous les CPT configurés (Polylang FR). Permet d'ajouter n'importe quel article même s'il date de plus de 7 jours.</p>

        <h3 style="margin-top:20px">[rdrnl_newsletter_page]</h3>
        <p><strong>Recommandé pour la page archive.</strong> Crée une vraie page WP avec ce shortcode — GP gère automatiquement le layout + sidebar. La page <code>/newsletter/slug/</code> continue de fonctionner via le CPT.</p>
        <div class="rdrnl-doc-code">[rdrnl_newsletter_page per_page="12" columns="2"]</div>
        <p class="description">Créez une page WP → collez le shortcode → définissez-la comme page de la newsletter dans les settings.</p>

        <h3 style="margin-top:16px">[rdrnl_newsletter]</h3>
        <p>Affiche la liste des éditions publiées sous forme de grille de cartes. Utilisable sur n'importe quelle page ou widget.</p>
        <div class="rdrnl-doc-code">[rdrnl_newsletter count="6" columns="2" show_excerpt="yes" show_count="yes"]</div>
        <table class="rdrnl-doc-table">
          <tr><th>Paramètre</th><th>Défaut</th><th>Description</th></tr>
          <tr><td><code>count</code></td><td>6</td><td>Nombre d'éditions à afficher</td></tr>
          <tr><td><code>columns</code></td><td>2</td><td>Colonnes : 1, 2, 3 ou 4</td></tr>
          <tr><td><code>show_excerpt</code></td><td>yes</td><td>Afficher l'extrait</td></tr>
          <tr><td><code>show_count</code></td><td>yes</td><td>Afficher le nombre de ressources</td></tr>
        </table>

        <h3 style="margin-top:20px">[rdrnl_profile_request]</h3>
        <p>Formulaire standalone pour qu'un abonné reçoive son lien de personnalisation par email. Réservé aux abonnés confirmés.</p>
        <div class="rdrnl-doc-code">[rdrnl_profile_request
  title="Accéder à mes préférences"
  description="Entrez votre email pour recevoir votre lien."
  button="Recevoir mon lien"
  style="card"
]</div>
      </div>

      <!-- FLUX UTILISATEUR -->
      <div class="rdrnl-doc-card">
        <h2>🔄 Flux complet abonné</h2>
        <ol class="rdrnl-doc-steps">
          <li><strong>Inscription</strong> — shortcode <code>[rdrnl_subscribe]</code></li>
          <li><strong>Email double opt-in</strong> — lien de confirmation (valable 24h)</li>
          <li><strong>Confirmation</strong> → statut <code>active</code> ou <code>waitlist</code> si liste pleine</li>
          <li><strong>Email de bienvenue</strong> — envoyé automatiquement, contient le lien de personnalisation</li>
          <li><strong>Newsletter hebdo</strong> — chaque vendredi à 12h (configurable)</li>
          <li><strong>Dans chaque email</strong> — bouton "Gérer mes préférences" avec token 24h</li>
          <li><strong>Page profil</strong> — 3 slots RSS personnalisés maximum, 10 articles max par flux</li>
        </ol>

        <h3 style="margin-top:16px">Réengagement automatique</h3>
        <ol class="rdrnl-doc-steps">
          <li><strong>Mardi 12h</strong> — détection des abonnés sans ouverture ET sans clic depuis 28 jours</li>
          <li><strong>Email de réengagement</strong> — lien de confirmation valable 14 jours</li>
          <li><strong>Sans réponse</strong> → <strong>mercredi 12h</strong> : suppression automatique</li>
          <li><strong>Promotion waitlist</strong> — le premier en attente est promu automatiquement</li>
        </ol>
      </div>

      <!-- URLS & PAGES -->
      <div class="rdrnl-doc-card">
        <h2>🔗 URLs et pages</h2>
        <table class="rdrnl-doc-table">
          <tr><th>URL</th><th>Description</th></tr>
          <tr>
            <td>Page WP avec <code>[rdrnl_newsletter_page]</code></td>
            <td>Archive des éditions — layout GP complet avec sidebar</td>
          </tr>
          <tr>
            <td><code><?php echo esc_html($site); ?>/newsletter/{slug}/</code></td>
            <td>Édition individuelle (ex: <code>semaine-12-2026</code>)</td>
          </tr>
          <tr>
            <td><code><?php echo esc_html($site); ?>/feed/rdrnl-editions/</code></td>
            <td>Flux RSS des éditions</td>
          </tr>
          <tr>
            <td><code><?php echo esc_html($site); ?>/editions/profil/{slug}/</code></td>
            <td>Page profil abonné (accès par token uniquement)</td>
          </tr>
          <tr>
            <td><code>?rdrnl_action=confirm&amp;token=xxx</code></td>
            <td>Confirmation double opt-in</td>
          </tr>
          <tr>
            <td><code>?rdrnl_action=unsubscribe&amp;token=xxx</code></td>
            <td>Désinscription</td>
          </tr>
          <tr>
            <td><code>?rdrnl_track=open&amp;tk=xxx</code></td>
            <td>Pixel de tracking d'ouverture</td>
          </tr>
          <tr>
            <td><code>?rdrnl_track=click&amp;sub=ID&amp;url=xxx</code></td>
            <td>Redirect de tracking de clic</td>
          </tr>
          <tr>
            <td><code>?rdrnl_track=reengagement&amp;tk=xxx</code></td>
            <td>Confirmation réengagement</td>
          </tr>
        </table>

        <p class="rdrnl-doc-notice">⚠️ <strong>Indispensable après activation</strong> : aller dans <strong>Réglages → Permaliens</strong> et cliquer Enregistrer pour activer toutes les URLs ci-dessus.</p>
      </div>

      <!-- CRONS -->
      <div class="rdrnl-doc-card">
        <h2>⏰ Tâches planifiées (WP Cron)</h2>
        <table class="rdrnl-doc-table">
          <tr><th>Hook</th><th>Fréquence</th><th>Rôle</th></tr>
          <tr>
            <td><code>rdrnl_cron_weekly</code></td>
            <td>Toutes les heures<br><em>(déclenche vendredi 12h)</em></td>
            <td>Envoi automatique de la newsletter</td>
          </tr>
          <tr>
            <td><code>rdrnl_process_batch</code></td>
            <td>Toutes les 2 min<br><em>(pendant l'envoi)</em></td>
            <td>Traitement par lot de 50 emails</td>
          </tr>
          <tr>
            <td><code>rdrnl_cron_reengagement</code></td>
            <td>Toutes les heures<br><em>(déclenche mardi 12h)</em></td>
            <td>Détection inactifs 28j → email réengagement</td>
          </tr>
          <tr>
            <td><code>rdrnl_cron_cleanup</code></td>
            <td>Toutes les heures<br><em>(déclenche mercredi 12h)</em></td>
            <td>Suppression abonnés sans réponse (14j)</td>
          </tr>
        </table>
        <p class="rdrnl-doc-notice">ℹ️ WP Cron est déclenché par les visites. Sur un site peu fréquenté, utiliser un <strong>vrai cron système</strong> : <code>*/5 * * * * curl <?php echo esc_html($site); ?>/wp-cron.php</code></p>
      </div>

      <!-- TABLES BDD -->
      <div class="rdrnl-doc-card">
        <h2>🗄️ Tables base de données</h2>

        <h3>wp_rdrnl_subscribers</h3>
        <p>Un abonné par ligne. Statuts : <code>active</code> / <code>pending</code> / <code>waitlist</code> / <code>unsubscribed</code></p>

        <h3 style="margin-top:12px">wp_rdrnl_tracking</h3>
        <p>Une ligne par couple (abonné × édition). Stocke pixel token, opened_at, last_click_at, open/click count, token de réengagement.</p>

        <h3 style="margin-top:12px">CPT WordPress</h3>
        <table class="rdrnl-doc-table">
          <tr><th>CPT</th><th>Slug URL</th><th>Rôle</th></tr>
          <tr><td><code>rdrnl_edition</code></td><td><code>/newsletter/</code></td><td>Éditions publiées (version web)</td></tr>
          <tr><td><code>rdrnl_profile</code></td><td><code>/editions/profil/</code></td><td>Profils abonnés (privés)</td></tr>
        </table>
      </div>

      <!-- TRACKING -->
      <div class="rdrnl-doc-card">
        <h2>📊 Tracking</h2>
        <p><strong>Ouverture (pixel)</strong> — approximatif (~60-70%). Apple Mail Privacy Protection ouvre automatiquement les pixels côté serveur depuis iOS 15, ce qui peut fausser les taux à la hausse.</p>
        <p style="margin-top:8px"><strong>Clics</strong> — fiable. Tous les liens de l'email passent par un redirect tracké. Exception : liens de désinscription, profil et pixel eux-mêmes.</p>
        <p style="margin-top:8px"><strong>Inactivité</strong> — déclenchée si <em>aucune ouverture ET aucun clic</em> depuis 28 jours. Un abonné qui clique sans ouvrir (ou inversement) n'est pas considéré inactif.</p>
      </div>

      <!-- INSTALLATION -->
      <div class="rdrnl-doc-card rdrnl-doc-card-full">
        <h2>🚀 Checklist d'installation</h2>
        <ul class="rdrnl-doc-checklist">
          <li><input type="checkbox"> Uploader et activer le plugin</li>
          <li><input type="checkbox"> <strong>Réglages → Permaliens → Enregistrer</strong> (flush rewrite rules)</li>
          <li><input type="checkbox"> <strong>Newsletter → Settings</strong> : configurer From name, From email, limite abonnés, catégories, flux RSS</li>
          <li><input type="checkbox"> Configurer SMTP (plugin WP Mail SMTP ou similar) pour fiabiliser l'envoi</li>
          <li><input type="checkbox"> Placer <code>[rdrnl_subscribe]</code> sur une page (sidebar, pied de page, page dédiée)</li>
          <li><input type="checkbox"> Optionnel : placer <code>[rdrnl_profile_request]</code> sur une page "Mes préférences newsletter"</li>
          <li><input type="checkbox"> Créer un premier brouillon via <strong>Newsletter → Compose → Refresh</strong></li>
          <li><input type="checkbox"> Tester avec "Send Test" sur votre adresse email</li>
          <li><input type="checkbox"> Vérifier <strong>Newsletter → Tracking</strong> après le premier vrai envoi</li>
        </ul>
      </div>

    </div><!-- .rdrnl-doc-grid -->

    </div><!-- .wrap -->

    <style>
    .rdrnl-doc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(480px,1fr)); gap:16px; margin-top:20px; }
    .rdrnl-doc-card { background:#fff; border:1px solid #e0e0e0; border-radius:8px; padding:20px 24px; }
    .rdrnl-doc-card-full { grid-column:1/-1; }
    .rdrnl-doc-card h2 { font-size:15px; font-weight:700; color:#1a1a2e; margin-bottom:14px; padding-bottom:10px; border-bottom:2px solid #f0f0f0; }
    .rdrnl-doc-card h3 { font-size:13px; font-weight:600; color:#1a1a2e; margin-bottom:6px; }
    .rdrnl-doc-card p  { font-size:13px; color:#555; line-height:1.6; margin-bottom:8px; }
    .rdrnl-doc-code { background:#f6f8fa; border:1px solid #e0e0e0; border-radius:6px; padding:12px 14px; font-family:monospace; font-size:12px; color:#1a1a2e; white-space:pre; overflow-x:auto; margin-bottom:12px; }
    .rdrnl-doc-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:8px; }
    .rdrnl-doc-table th { background:#f6f8fa; padding:6px 10px; text-align:left; font-weight:600; color:#555; border-bottom:1px solid #e0e0e0; }
    .rdrnl-doc-table td { padding:6px 10px; border-bottom:1px solid #f5f5f5; color:#333; vertical-align:top; }
    .rdrnl-doc-table tr:last-child td { border-bottom:none; }
    .rdrnl-doc-steps { font-size:13px; color:#555; padding-left:20px; line-height:1.9; }
    .rdrnl-doc-steps li { margin-bottom:2px; }
    .rdrnl-doc-checklist { list-style:none; padding:0; }
    .rdrnl-doc-checklist li { font-size:13px; color:#333; padding:6px 0; border-bottom:1px solid #f5f5f5; display:flex; align-items:center; gap:10px; }
    .rdrnl-doc-checklist input[type=checkbox] { flex-shrink:0; width:16px; height:16px; cursor:pointer; }
    .rdrnl-doc-notice { background:#fff8e1; border:1px solid #ffe082; border-radius:6px; padding:10px 14px; font-size:12px; color:#555; margin-top:12px; }
    @media(max-width:900px){ .rdrnl-doc-grid{ grid-template-columns:1fr; } }
    </style>
    <?php
}

/* ══════════════════════════════════════════════════
   FEED RULES PAGE  (security: domain allow/blocklist)
══════════════════════════════════════════════════ */
function rdrnl_page_feed_rules() {
    $rules  = rdrnl_get_feed_rules();
    $saved  = isset($_GET['saved']);

    // Collect all subscriber feeds for visibility
    global $wpdb;
    $profiles = get_posts(['post_type'=>'rdrnl_profile','post_status'=>'publish','posts_per_page'=>-1,'fields'=>'ids']);
    $all_feeds = [];
    foreach ($profiles as $pid) {
        $feeds = get_post_meta($pid,'_rdrnl_custom_feeds',true);
        $sub_id = get_post_meta($pid,'_rdrnl_sub_id',true);
        $sub    = $sub_id ? $wpdb->get_row($wpdb->prepare("SELECT email FROM ".rdrnl_table()." WHERE id=%d",$sub_id)) : null;
        foreach ((array)$feeds as $f) {
            if (empty($f['url'])) continue;
            $domain = rdrnl_extract_domain(parse_url($f['url'],PHP_URL_HOST) ?: '');
            $permitted = rdrnl_feed_url_permitted($f['url']);
            $all_feeds[] = [
                'url'       => $f['url'],
                'label'     => $f['label'] ?? '',
                'domain'    => $domain,
                'email'     => $sub->email ?? '—',
                'allowed'   => !is_wp_error($permitted),
                'reason'    => is_wp_error($permitted) ? $permitted->get_error_message() : '',
            ];
        }
    }
    ?>
    <div class="wrap rdrnl-wrap">
    <h1><span class="dashicons dashicons-shield"></span> Feed Rules — Sécurité des flux RSS</h1>

    <?php if($saved):?><div class="notice notice-success is-dismissible"><p>Règles sauvegardées.</p></div><?php endif;?>

    <div class="rdrnl-settings-grid">

      <!-- Mode + lists -->
      <div class="rdrnl-card rdrnl-full-width">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-admin-network"></span><h3>Règles de filtrage des domaines</h3></div>
        <form method="post" action="<?php echo admin_url('admin-post.php');?>">
          <?php wp_nonce_field('rdrnl_feed_rules_nonce');?>
          <input type="hidden" name="action" value="rdrnl_save_feed_rules">

          <table class="form-table rdrnl-form-table">
            <tr>
              <th>Mode</th>
              <td>
                <label style="margin-right:20px">
                  <input type="radio" name="mode" value="blacklist" <?php checked($rules['mode'],'blacklist');?>>
                  <strong>Blacklist</strong> — tous les domaines autorisés, sauf ceux bloqués <em>(recommandé)</em>
                </label>
                <label>
                  <input type="radio" name="mode" value="whitelist" <?php checked($rules['mode'],'whitelist');?>>
                  <strong>Whitelist</strong> — seuls les domaines listés sont autorisés
                </label>
              </td>
            </tr>
            <tr>
              <th>Domaines bloqués</th>
              <td>
                <textarea name="blocked" rows="8" class="large-text" style="font-family:monospace"><?php echo esc_textarea(implode("\n",$rules['blocked']));?></textarea>
                <p class="description">Un domaine par ligne. Exemples : <code>spam.com</code>, <code>*.malicious.net</code>. Les sous-domaines sont automatiquement inclus.</p>
              </td>
            </tr>
            <tr>
              <th>Domaines autorisés <span style="font-weight:400">(whitelist)</span></th>
              <td>
                <textarea name="allowed" rows="8" class="large-text" style="font-family:monospace"><?php echo esc_textarea(implode("\n",$rules['allowed']));?></textarea>
                <p class="description">Utilisé uniquement en mode whitelist.</p>
              </td>
            </tr>
          </table>
          <p class="submit"><button type="submit" class="button button-primary">Enregistrer les règles</button></p>
        </form>
      </div>

      <!-- Subscriber feeds visibility -->
      <div class="rdrnl-card rdrnl-full-width">
        <div class="rdrnl-card-header"><span class="dashicons dashicons-rss"></span>
          <h3>Flux RSS des abonnés <span class="rdrnl-section-count"><?php echo count($all_feeds);?> flux configurés</span></h3>
        </div>
        <?php if(empty($all_feeds)):?>
        <p class="rdrnl-muted">Aucun abonné n'a encore configuré de flux personnalisé.</p>
        <?php else:?>
        <table class="widefat striped">
          <thead>
            <tr><th>Abonné</th><th>Domaine</th><th>URL du flux</th><th>Label</th><th>Statut</th><th>Action</th></tr>
          </thead>
          <tbody>
            <?php foreach($all_feeds as $f):?>
            <tr>
              <td><?php echo esc_html($f['email']);?></td>
              <td><code><?php echo esc_html($f['domain']);?></code></td>
              <td style="max-width:200px;word-break:break-all"><a href="<?php echo esc_url($f['url']);?>" target="_blank" rel="nofollow"><?php echo esc_html($f['url']);?></a></td>
              <td><?php echo esc_html($f['label']);?></td>
              <td>
                <?php if($f['allowed']):?>
                <span class="rdrnl-log-type rdrnl-log-active">✓ Autorisé</span>
                <?php else:?>
                <span class="rdrnl-log-type rdrnl-log-unsubscribed" title="<?php echo esc_attr($f['reason']);?>">✗ Bloqué</span>
                <?php endif;?>
              </td>
              <td>
                <?php if($f['allowed']):?>
                <button class="button button-small rdrnl-quick-block" data-domain="<?php echo esc_attr($f['domain']);?>">
                  Bloquer ce domaine
                </button>
                <?php else:?>
                <button class="button button-small rdrnl-quick-allow" data-domain="<?php echo esc_attr($f['domain']);?>">
                  Autoriser ce domaine
                </button>
                <?php endif;?>
              </td>
            </tr>
            <?php endforeach;?>
          </tbody>
        </table>
        <p class="description" style="margin-top:8px">Les flux bloqués ne sont pas récupérés lors de l'envoi des emails.</p>
        <?php endif;?>
      </div>

    </div><!-- .rdrnl-settings-grid -->
    </div>

    <script>
    (function(){
      // Quick block/allow buttons fill the textarea and submit
      document.querySelectorAll('.rdrnl-quick-block').forEach(function(btn){
        btn.addEventListener('click', function(){
          var domain = this.dataset.domain;
          var ta = document.querySelector('textarea[name="blocked"]');
          var current = ta.value.trim();
          if (!current.split('\n').includes(domain)) {
            ta.value = current ? current + '\n' + domain : domain;
          }
          ta.scrollIntoView({behavior:'smooth'});
          ta.style.borderColor = '#f85149';
          setTimeout(function(){ ta.style.borderColor = ''; }, 2000);
        });
      });
      document.querySelectorAll('.rdrnl-quick-allow').forEach(function(btn){
        btn.addEventListener('click', function(){
          var domain = this.dataset.domain;
          // Remove from blocked
          var tb = document.querySelector('textarea[name="blocked"]');
          tb.value = tb.value.split('\n').filter(function(d){ return d.trim() !== domain; }).join('\n');
          // Add to allowed
          var ta = document.querySelector('textarea[name="allowed"]');
          var current = ta.value.trim();
          if (!current.split('\n').includes(domain)) {
            ta.value = current ? current + '\n' + domain : domain;
          }
          ta.scrollIntoView({behavior:'smooth'});
          ta.style.borderColor = '#2ea8ff';
          setTimeout(function(){ ta.style.borderColor = ''; }, 2000);
        });
      });
    })();
    </script>
    <?php
}
