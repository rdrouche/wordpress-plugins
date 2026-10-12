<?php
/**
 * RDR-IT Login Security — Onglet admin v2
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function rdrit_login_admin_tab( string $nonce ): void {
    global $wpdb;
    $table = $wpdb->prefix . 'rdrit_login_logs';

    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
    if ( ! $table_exists ) {
        echo '<div class="rdrit-section"><p style="color:#dc2626">Table de logs non créée — désactivez/réactivez le plugin.</p></div>';
        return;
    }

    $stats = $wpdb->get_row("SELECT
        COUNT(*)                   AS total,
        SUM(result='fail')         AS fails,
        SUM(result='success')      AS successes,
        SUM(result='blocked')      AS blocked,
        SUM(result='bot')          AS bots,
        SUM(event_type='ban')      AS bans,
        SUM(event_type='pwd_reset') AS pwd_resets,
        COUNT(DISTINCT ip)         AS unique_ips
        FROM $table");

    $top_ips = $wpdb->get_results(
        "SELECT ip, country_code, country_name, city, COUNT(*) AS cnt,
                SUM(result='fail') AS fails, MAX(event_time) AS last_seen
         FROM $table WHERE result IN('fail','blocked','bot')
         GROUP BY ip,country_code,country_name,city ORDER BY fails DESC LIMIT 10");

    $daily = $wpdb->get_results(
        "SELECT DATE(event_time) AS day,
                SUM(result='fail') AS fails,
                SUM(result='blocked') AS blocked,
                SUM(event_type='ban') AS bans,
                SUM(result='success') AS successes
         FROM $table WHERE event_time > DATE_SUB(NOW(), INTERVAL 30 DAY)
         GROUP BY DATE(event_time) ORDER BY day ASC");

    $active_bans = $wpdb->get_results($wpdb->prepare(
        "SELECT ip,country_code,country_name,city,ban_until,email
         FROM $table WHERE event_type='ban' AND ban_until > %s ORDER BY ban_until DESC",
        current_time('mysql')));

    $logs = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC LIMIT 50");
    $pwd_logs = $wpdb->get_results("SELECT * FROM $table WHERE event_type='pwd_reset' ORDER BY id DESC LIMIT 20");
    ?>
    <div class="rdrit-section">
        <h2>⚙️ Configuration</h2>
        <p style="font-size:.82em;color:#6b7280;margin:0 0 12px">Les modifications sont sauvegardées automatiquement.</p>

        <?php rdrit_tr('rdrit_ls_antibot_enabled',   'Activer anti-bot (honeypot + calcul)', 'Ajoute un champ caché et un calcul mathématique sur la page de login.', $nonce); ?>
        <?php rdrit_tr('rdrit_ls_geo_enabled',        'Activer la géolocalisation IP', 'Enregistre le pays et la ville. Fichier local mmdb dans <code>wp-content/rdrit-geoip/GeoLite2-City.mmdb</code>, sinon fallback ip-api.com.', $nonce); ?>
        <?php rdrit_tr('rdrit_ls_log_pwd_reset',      'Logger les demandes de reset password', '', $nonce); ?>

        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:12px">
            <?php
            $num_fields = array(
                'rdrit_ls_max_attempts'      => array('Tentatives avant ban',      '5',  'min=1 max=20'),
                'rdrit_ls_ban_duration'      => array('Durée du ban (minutes)',     '15', 'min=1 max=10080'),
                'rdrit_ls_window_minutes'    => array('Fenêtre de comptage (min)', '30', 'min=5 max=1440'),
                'rdrit_ls_log_retention_days'=> array('Rétention logs (jours)',    '90', 'min=7 max=3650'),
            );
            foreach ($num_fields as $key => [$label, $default, $extra]) :
                $val = esc_attr(get_option($key, $default));
            ?>
            <div style="display:flex;flex-direction:column;gap:4px">
                <label style="font-size:13px;font-weight:500;color:#374151"><?= esc_html($label) ?></label>
                <input type="number" value="<?= $val ?>"
                       <?= $extra ?>
                       style="width:90px;padding:5px 8px;border:1px solid #d1d5db;border-radius:6px"
                       oninput="rdritSaveNum('<?= esc_js($key) ?>', this.value, '<?= esc_js($nonce) ?>')">
            </div>
            <?php endforeach; ?>
        </div>
        <p style="font-size:.75em;color:#9ca3af;margin:8px 0 0">
            GeoIP : placer <code>GeoLite2-City.mmdb</code> dans <code><?= esc_html(WP_CONTENT_DIR) ?>/rdrit-geoip/</code>
            <?php
            $mmdb = WP_CONTENT_DIR . '/rdrit-geoip/GeoLite2-City.mmdb';
            if (file_exists($mmdb)) {
                echo '<span style="color:#16a34a;font-weight:500"> — Fichier détecté ✓ (' . size_format(filesize($mmdb)) . ')</span>';
            } else {
                echo '<span style="color:#dc2626"> — Fichier absent (fallback ip-api.com actif)</span>';
            }
            ?>
        </p>
    </div>

    <div class="rdrit-section">
        <h2>📊 Statistiques globales</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-top:8px">
        <?php
        $cards = array(
            array('Total',       (int)$stats->total,      '#374151'),
            array('Échecs',      (int)$stats->fails,      '#dc2626'),
            array('Succès',      (int)$stats->successes,  '#16a34a'),
            array('Bloqués',     (int)$stats->blocked,    '#d97706'),
            array('Bots',        (int)$stats->bots,       '#7c3aed'),
            array('Bans',        (int)$stats->bans,       '#dc2626'),
            array('Reset pwd',   (int)$stats->pwd_resets, '#0369a1'),
            array('IPs uniques', (int)$stats->unique_ips, '#374151'),
        );
        foreach ($cards as [$label, $value, $color]) : ?>
            <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px;text-align:center">
                <div style="font-size:22px;font-weight:600;color:<?= $color ?>"><?= number_format($value) ?></div>
                <div style="font-size:11px;color:#6b7280;margin-top:2px"><?= esc_html($label) ?></div>
            </div>
        <?php endforeach; ?>
        </div>
    </div>

    <?php if (!empty($daily)) : ?>
    <div class="rdrit-section">
        <h2>📈 Activité — 30 derniers jours</h2>
        <canvas id="rdrit-login-chart" height="100"></canvas>
        <script>
        (function(){
            var el = document.getElementById('rdrit-login-chart');
            if(!el) return;
            function init(){
                if(!window.Chart){ setTimeout(init,300); return; }
                new Chart(el,{type:'bar',data:{
                    labels:<?= json_encode(array_column($daily,'day')) ?>,
                    datasets:[
                        {label:'Échecs',  data:<?= json_encode(array_map('intval',array_column($daily,'fails'))) ?>,     backgroundColor:'rgba(220,38,38,.7)'},
                        {label:'Bloqués', data:<?= json_encode(array_map('intval',array_column($daily,'blocked'))) ?>,   backgroundColor:'rgba(217,119,6,.7)'},
                        {label:'Bans',    data:<?= json_encode(array_map('intval',array_column($daily,'bans'))) ?>,      backgroundColor:'rgba(124,58,237,.7)'},
                        {label:'Succès',  data:<?= json_encode(array_map('intval',array_column($daily,'successes'))) ?>, backgroundColor:'rgba(22,163,74,.7)'},
                    ]
                },options:{responsive:true,scales:{x:{stacked:true},y:{stacked:true,beginAtZero:true}}}});
            }
            init();
        })();
        </script>
    </div>
    <?php endif; ?>

    <?php if (!empty($active_bans)) : ?>
    <div class="rdrit-section">
        <h2>🚫 Bans actifs (<?= count($active_bans) ?>)</h2>
        <table class="wp-list-table widefat fixed striped" style="font-size:13px">
            <thead><tr><th>IP</th><th>Pays</th><th>Identifiant</th><th>Ban jusqu'au</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($active_bans as $ban) : ?>
            <tr>
                <td><code><?= esc_html($ban->ip) ?></code></td>
                <td><?= esc_html($ban->country_code) ?> <?= esc_html($ban->country_name) ?></td>
                <td><?= esc_html($ban->email) ?></td>
                <td><?= esc_html($ban->ban_until) ?></td>
                <td><button class="button button-small rdrit-unban"
                            data-ip="<?= esc_attr($ban->ip) ?>"
                            data-nonce="<?= wp_create_nonce('rdrit_unban') ?>">Débannir</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($top_ips)) : ?>
    <div class="rdrit-section">
        <h2>⚠️ Top IPs suspectes</h2>
        <table class="wp-list-table widefat fixed striped" style="font-size:13px">
            <thead><tr><th>IP</th><th>Pays</th><th>Total</th><th>Échecs</th><th>Dernière vue</th></tr></thead>
            <tbody>
            <?php foreach ($top_ips as $row) : ?>
            <tr>
                <td><code><?= esc_html($row->ip) ?></code></td>
                <td><?= esc_html($row->country_code) ?> <?= esc_html($row->country_name) ?></td>
                <td><?= (int)$row->cnt ?></td>
                <td style="color:#dc2626;font-weight:500"><?= (int)$row->fails ?></td>
                <td><?= esc_html($row->last_seen) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="rdrit-section">
        <h2>📋 Derniers événements</h2>
        <table class="wp-list-table widefat fixed striped" style="font-size:12px">
            <thead><tr>
                <th style="width:135px">Date</th>
                <th style="width:55px">Type</th>
                <th style="width:60px">Résultat</th>
                <th style="width:115px">IP</th>
                <th style="width:45px">Pays</th>
                <th>Identifiant</th>
                <th>Hash pwd</th>
            </tr></thead>
            <tbody>
            <?php
            $rc = array('success'=>'#16a34a','fail'=>'#dc2626','blocked'=>'#d97706','bot'=>'#7c3aed');
            foreach ($logs as $log) :
                $c = $rc[$log->result] ?? '#374151';
            ?>
            <tr>
                <td><?= esc_html($log->event_time) ?></td>
                <td><span style="font-size:10px;background:#f3f4f6;padding:1px 4px;border-radius:3px"><?= esc_html($log->event_type) ?></span></td>
                <td><span style="color:<?= $c ?>;font-weight:500"><?= esc_html($log->result) ?></span></td>
                <td><code style="font-size:11px"><?= esc_html($log->ip) ?></code></td>
                <td><?= esc_html($log->country_code) ?></td>
                <td><?= esc_html($log->email) ?></td>
                <td style="font-family:monospace;font-size:10px;color:#9ca3af"><?= $log->pwd_hash ? esc_html(substr($log->pwd_hash,0,16)).'…' : '' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (!empty($pwd_logs)) : ?>
    <div class="rdrit-section">
        <h2>🔑 Demandes de reset password</h2>
        <table class="wp-list-table widefat fixed striped" style="font-size:13px">
            <thead><tr><th>Date</th><th>IP</th><th>Pays</th><th>Identifiant</th></tr></thead>
            <tbody>
            <?php foreach ($pwd_logs as $log) : ?>
            <tr>
                <td><?= esc_html($log->event_time) ?></td>
                <td><code><?= esc_html($log->ip) ?></code></td>
                <td><?= esc_html($log->country_code) ?> <?= esc_html($log->country_name) ?></td>
                <td><?= esc_html($log->email) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <script>
    var _rdritNumTimer = {};
    function rdritSaveNum(key, val, nonce){
        clearTimeout(_rdritNumTimer[key]);
        _rdritNumTimer[key] = setTimeout(function(){
            fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:'action=rdrit_save_option&option_key='+encodeURIComponent(key)+'&value='+encodeURIComponent(val)+'&nonce='+encodeURIComponent(nonce)
            }).then(function(r){return r.json();}).then(function(d){
                if(d.success){
                    var notice = document.getElementById('rdrit-notice') || {style:{}};
                    if(notice.style) notice.style.display='block';
                }
            });
        }, 600);
    }
    document.addEventListener('DOMContentLoaded',function(){
        document.querySelectorAll('.rdrit-unban').forEach(function(btn){
            btn.addEventListener('click',function(){
                var ip=this.dataset.ip, nonce=this.dataset.nonce, row=this.closest('tr');
                fetch(ajaxurl,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
                    body:'action=rdrit_unban_ip&ip='+encodeURIComponent(ip)+'&_wpnonce='+encodeURIComponent(nonce)
                }).then(r=>r.json()).then(function(d){ if(d.success) row.remove(); });
            });
        });
    });
    </script>
    <?php
}

add_action('wp_ajax_rdrit_unban_ip','rdrit_login_unban_ajax');
function rdrit_login_unban_ajax(): void {
    check_ajax_referer('rdrit_unban');
    if(!current_user_can('manage_options')) wp_send_json_error('Non autorisé');
    $ip = sanitize_text_field($_POST['ip'] ?? '');
    if(!$ip) wp_send_json_error('IP manquante');
    global $wpdb;
    $table = $wpdb->prefix . 'rdrit_login_logs';
    $wpdb->query($wpdb->prepare(
        "UPDATE $table SET ban_until=NULL WHERE event_type='ban' AND ip=%s", $ip
    ));
    wp_send_json_success();
}
