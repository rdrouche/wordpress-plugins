<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$all_sources = RDR_Collector::get_all_sources();
$enabled     = get_option('rdr_cyber_sources_enabled', null);

/* groupement par source */
$groups = array();
foreach ($all_sources as $src) {
    $groups[$src['group']][] = $src;
}
?>
<div class="wrap rdr-admin-wrap">
    <div class="rdr-admin-header">
        <h1 class="rdr-admin-title"><span style="color:var(--rdr-accent,#2ea8ff)">⬡</span> Sources de données</h1>
        <a href="<?php echo admin_url('admin.php?page=rdr-cyber-dashboard'); ?>" class="button">← Vue d'ensemble</a>
    </div>

    <div class="rdr-admin-card" style="margin-bottom:16px">
        <div style="color:var(--rdr-text-secondary);font-size:13px">
            Activez ou désactivez les sources de collecte. Les modifications prennent effet à la prochaine collecte automatique ou manuelle.
        </div>
    </div>

    <form method="post">
        <?php wp_nonce_field('rdr_cyber_sources_nonce'); ?>
        <input type="hidden" name="rdr_save_sources" value="1">

        <?php foreach ($groups as $group_name => $sources): ?>
        <div class="rdr-admin-card" style="margin-bottom:16px">
            <h3 class="rdr-admin-card-title"><?php echo esc_html($group_name); ?></h3>
            <?php foreach ($sources as $src): ?>
            <?php
            /* Si pas encore de réglage, utiliser le default */
            if ($enabled === null) $is_on = $src['default'];
            else $is_on = !empty($enabled[$src['id']]);
            $sev_colors = array('critical'=>'#ff4757','high'=>'#ff6b35','medium'=>'#f5a623','low'=>'#2ecc71','info'=>'#2ea8ff',''=>'#8b949e');
            $sev_color  = $sev_colors[$src['severity']] ?? '#8b949e';
            ?>
            <div style="display:flex;align-items:center;gap:16px;padding:12px 0;border-bottom:1px solid var(--rdr-border);flex-wrap:wrap">
                <label class="rdr-toggle-wrap" style="display:flex;align-items:center;gap:10px;cursor:pointer;flex:1;min-width:200px">
                    <input type="checkbox"
                           name="source_<?php echo esc_attr($src['id']); ?>"
                           id="src_<?php echo esc_attr($src['id']); ?>"
                           <?php checked($is_on); ?>
                           style="width:16px;height:16px;accent-color:var(--rdr-accent,#2ea8ff);cursor:pointer">
                    <span style="font-weight:600;font-size:13px;color:var(--rdr-text-primary)"><?php echo esc_html($src['label']); ?></span>
                </label>
                <div style="display:flex;gap:8px;align-items:center;flex-shrink:0">
                    <?php if ($src['severity']): ?>
                    <span style="background:<?php echo $sev_color; ?>22;color:<?php echo $sev_color; ?>;border:1px solid <?php echo $sev_color; ?>44;border-radius:4px;padding:2px 8px;font-size:10px;font-weight:700;text-transform:uppercase"><?php echo esc_html($src['severity'] ?: 'auto'); ?></span>
                    <?php endif; ?>
                    <span style="font-size:10px;text-transform:uppercase;background:var(--rdr-bg-accent);color:var(--rdr-accent);border:1px solid rgba(46,168,255,.2);border-radius:4px;padding:2px 7px;font-weight:700"><?php echo esc_html($src['type']); ?></span>
                    <?php if ($src['url'] !== 'nvd_api'): ?>
                    <a href="<?php echo esc_url($src['url']); ?>" target="_blank" rel="noopener" style="font-size:11px;color:var(--rdr-text-muted)" title="Voir le flux RSS">↗</a>
                    <?php else: ?>
                    <span style="font-size:11px;color:var(--rdr-text-muted)">API</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <div style="margin-top:8px">
            <button type="submit" class="button button-primary" style="background:var(--rdr-accent);border-color:var(--rdr-accent);padding:8px 20px;font-size:14px">
                Enregistrer les sources
            </button>
        </div>
    </form>
</div>
