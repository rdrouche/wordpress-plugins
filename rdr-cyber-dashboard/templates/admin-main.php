<?php
if ( ! defined( 'ABSPATH' ) ) exit;
$last = $stats['last_collected'] ? date_i18n('d/m/Y à H:i', strtotime($stats['last_collected'])) : 'Jamais';
$max_source = 0;
foreach ($stats['by_source'] as $s) if ((int)$s['cnt'] > $max_source) $max_source = (int)$s['cnt'];
$severity_colors = array('critical'=>'#ff4757','high'=>'#ff6b35','medium'=>'#f5a623','low'=>'#2ecc71','info'=>'#2ea8ff');
$next_collect = wp_next_scheduled(RDR_Cron::HOOK_COLLECT);
$next_collect_f = $next_collect ? date_i18n('d/m/Y à H:i', $next_collect) : 'Non planifiée';
$total_subs      = count(RDR_Database::get_subscribers('', true));
$total_push_subs = RDR_Database::count_push_subscriptions();
$queue_stats = RDR_Database::get_queue_stats();
$feed_url = home_url('/feed/'.RDR_Feed::FEED_SLUG.'/');
?>
<div class="wrap rdr-admin-wrap">

    <div class="rdr-admin-header">
        <h1 class="rdr-admin-title"><span style="color:var(--rdr-accent,#2ea8ff)">⬡</span> Cyber Dashboard — Vue d'ensemble</h1>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <span class="rdr-last-sync">Collecte : <span><?php echo esc_html($last); ?></span> · Suivante : <span><?php echo esc_html($next_collect_f); ?></span></span>
            <button id="rdr-collect-now" class="button button-primary" style="background:var(--rdr-accent);border-color:var(--rdr-accent)">↻ Collecter maintenant</button>
            <a href="<?php echo admin_url('admin.php?page=rdr-cyber-sources'); ?>" class="button">Sources</a>
            <a href="<?php echo admin_url('admin.php?page=rdr-cyber-settings'); ?>" class="button">Paramètres</a>
        </div>
    </div>

    <!-- Stats globales -->
    <div class="rdr-admin-grid">
        <div class="rdr-admin-card">
            <h3 class="rdr-admin-card-title">Statistiques globales</h3>
            <div class="rdr-stats-bar" style="grid-template-columns:1fr 1fr;gap:12px">
                <div class="rdr-stat-card rdr-accent"    style="padding:14px 16px"><span class="rdr-stat-label">Total</span><span class="rdr-stat-number"><?php echo number_format($stats['total']); ?></span></div>
                <div class="rdr-stat-card rdr-critical"  style="padding:14px 16px"><span class="rdr-stat-label">Critique/Haute</span><span class="rdr-stat-number"><?php echo $stats['critical']; ?></span></div>
                <div class="rdr-stat-card rdr-green"     style="padding:14px 16px"><span class="rdr-stat-label">Cette semaine</span><span class="rdr-stat-number"><?php echo $stats['this_week']; ?></span></div>
                <div class="rdr-stat-card"               style="padding:14px 16px"><span class="rdr-stat-label">Aujourd'hui</span><span class="rdr-stat-number"><?php echo $stats['today']; ?></span></div>
            </div>
        </div>

        <div class="rdr-admin-card">
            <h3 class="rdr-admin-card-title">Par source</h3>
            <?php if ($stats['by_source']): ?>
            <ul class="rdr-source-list">
                <?php foreach ($stats['by_source'] as $row): $pct = $max_source>0?round((int)$row['cnt']/$max_source*100):0; ?>
                <li class="rdr-source-row">
                    <span><?php echo esc_html($row['source']); ?></span>
                    <div class="rdr-source-bar-wrap"><div class="rdr-source-bar-fill" style="width:<?php echo $pct;?>%"></div></div>
                    <span class="rdr-source-count"><?php echo $row['cnt']; ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php else: ?><p style="color:var(--rdr-text-secondary);font-size:13px">Lancez une collecte.</p><?php endif; ?>
        </div>

        <div class="rdr-admin-card">
            <h3 class="rdr-admin-card-title">Par sévérité</h3>
            <?php $severity_labels=array('critical'=>'Critique','high'=>'Haute','medium'=>'Moyenne','low'=>'Faible','info'=>'Info'); ?>
            <?php foreach ($stats['by_severity'] as $row): $color=$severity_colors[$row['severity']]??'#8b949e'; $label=$severity_labels[$row['severity']]??ucfirst($row['severity']); $pct=$stats['total']>0?round((int)$row['cnt']/$stats['total']*100):0; ?>
            <div class="rdr-severity-row">
                <span class="rdr-badge-severity <?php echo esc_attr($row['severity']); ?>" style="min-width:72px;text-align:center"><?php echo esc_html($label); ?></span>
                <div class="rdr-source-bar-wrap"><div class="rdr-source-bar-fill" style="width:<?php echo $pct;?>%;background:<?php echo esc_attr($color); ?>"></div></div>
                <span class="rdr-source-count" style="color:<?php echo esc_attr($color); ?>"><?php echo $row['cnt']; ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="rdr-admin-card">
            <h3 class="rdr-admin-card-title">Services</h3>
            <div style="display:flex;flex-direction:column;gap:10px">
                <!-- Flux RSS -->
                <div style="background:var(--rdr-bg-surface);border:1px solid var(--rdr-border);border-radius:8px;padding:12px 14px">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--rdr-text-muted);margin-bottom:4px">📡 Flux RSS</div>
                    <a href="<?php echo esc_url($feed_url); ?>" target="_blank" style="color:var(--rdr-accent);font-size:12px;font-family:monospace;word-break:break-all"><?php echo esc_html($feed_url); ?></a>
                </div>
                <!-- Abonnés email -->
                <div style="background:var(--rdr-bg-surface);border:1px solid var(--rdr-border);border-radius:8px;padding:12px 14px">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--rdr-text-muted);margin-bottom:4px">📧 Abonnés email confirmés</div>
                    <span style="font-size:26px;font-weight:800;color:var(--rdr-accent-2,#00e5c0)"><?php echo $total_subs; ?></span>
                    <a href="<?php echo admin_url('admin.php?page=rdr-cyber-subscribers'); ?>" style="display:block;font-size:12px;color:var(--rdr-accent);margin-top:2px">Gérer →</a>
                </div>
                <!-- Abonnés push -->
                <div style="background:var(--rdr-bg-surface);border:1px solid var(--rdr-border);border-radius:8px;padding:12px 14px">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--rdr-text-muted);margin-bottom:4px">🔔 Abonnés push</div>
                    <span style="font-size:26px;font-weight:800;color:<?php echo RDR_Push::is_available() ? '#f5a623' : '#484f58'; ?>"><?php echo $total_push_subs; ?></span>
                    <?php if (!RDR_Push::is_available()): ?>
                    <span style="display:block;font-size:11px;color:var(--rdr-text-muted);margin-top:2px">Push non configuré — <a href="<?php echo admin_url('admin.php?page=rdr-cyber-settings'); ?>" style="color:var(--rdr-accent)">Configurer →</a></span>
                    <?php endif; ?>
                </div>
                <!-- File d'attente -->
                <div style="background:var(--rdr-bg-surface);border:1px solid var(--rdr-border);border-radius:8px;padding:12px 14px">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--rdr-text-muted);margin-bottom:6px">⚡ File d'envoi email</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">
                        <div style="font-size:11px;color:var(--rdr-text-muted)">En attente : <strong style="color:#f5a623"><?php echo $queue_stats['pending']; ?></strong></div>
                        <div style="font-size:11px;color:var(--rdr-text-muted)">En cours : <strong style="color:#2ea8ff"><?php echo $queue_stats['sending']; ?></strong></div>
                        <div style="font-size:11px;color:var(--rdr-text-muted)">Envoyés : <strong style="color:#2ecc71"><?php echo $queue_stats['sent']; ?></strong></div>
                        <div style="font-size:11px;color:var(--rdr-text-muted)">Échecs : <strong style="color:#ff4757"><?php echo $queue_stats['failed']; ?></strong></div>
                    </div>
                    <?php if ($queue_stats['pending'] > 0): ?>
                    <button id="rdr-process-queue" class="button" style="margin-top:8px;font-size:11px;padding:4px 10px">Traiter maintenant</button>
                    <?php endif; ?>
                </div>
                <!-- Shortcodes -->
                <div style="background:var(--rdr-bg-surface);border:1px solid var(--rdr-border);border-radius:8px;padding:12px 14px">
                    <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--rdr-text-muted);margin-bottom:6px">Shortcodes</div>
                    <code style="display:block;font-size:11px;color:var(--rdr-accent-2)">[rdr_cyber_dashboard]</code>
                    <code style="display:block;font-size:11px;color:var(--rdr-accent-2);margin-top:3px">[rdr_cyber_subscribe]</code>
                    <code style="display:block;font-size:11px;color:var(--rdr-accent-2);margin-top:3px">[rdr_cyber_feed_builder]</code>
                </div>
            </div>
        </div>
    </div>

</div>
