<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mes préférences — Cyber Alertes</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0d1117;color:#e6edf3;font-family:'Segoe UI',Arial,sans-serif;min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px}
.pref-wrap{max-width:580px;width:100%}
.pref-header{text-align:center;margin-bottom:32px}
.pref-logo{font-size:24px;font-weight:800;color:#fff;text-decoration:none;display:block;margin-bottom:8px}
.pref-logo span{color:#2ea8ff}
.pref-title{font-size:22px;font-weight:700;color:#e6edf3;margin-bottom:4px}
.pref-email{color:#8b949e;font-size:13px}
.pref-card{background:#161b22;border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:24px 28px;margin-bottom:16px}
.pref-card h3{font-size:15px;font-weight:700;color:#e6edf3;margin-bottom:16px;padding-bottom:10px;border-bottom:1px solid rgba(255,255,255,.07)}
.pref-section-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:#484f58;margin-bottom:10px}
.radio-row{display:flex;gap:10px;margin-bottom:4px}
.radio-opt{flex:1;cursor:pointer}
.radio-opt input{display:none}
.radio-box{display:block;padding:12px 14px;background:#0d1117;border:1px solid rgba(255,255,255,.12);border-radius:8px;transition:.2s}
.radio-opt input:checked+.radio-box{border-color:#2ea8ff;background:rgba(46,168,255,.07)}
.radio-title{font-size:13px;font-weight:600;color:#e6edf3;display:block}
.radio-sub{font-size:11px;color:#484f58;display:block;margin-top:2px}
.select-input{width:100%;padding:10px 14px;background:#0d1117;border:1px solid rgba(255,255,255,.12);border-radius:8px;color:#e6edf3;font-size:14px;outline:none;transition:.2s}
.select-input:focus{border-color:#2ea8ff}
.sources-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.source-toggle{cursor:pointer;display:flex;align-items:center;gap:8px;padding:10px 12px;background:#0d1117;border:1px solid rgba(255,255,255,.08);border-radius:8px;transition:.2s}
.source-toggle input{display:none}
.source-toggle input:checked~.src-box{border-color:#2ea8ff!important}
.source-toggle:has(input:checked){border-color:rgba(46,168,255,.35);background:rgba(46,168,255,.05)}
.src-icon{width:8px;height:8px;border-radius:50%;background:#484f58;flex-shrink:0;transition:.2s}
.source-toggle:has(input:checked) .src-icon{background:#2ea8ff}
.src-name{font-size:12px;font-weight:600;color:#8b949e;transition:.2s}
.source-toggle:has(input:checked) .src-name{color:#e6edf3}
.save-btn{width:100%;padding:13px;background:#2ea8ff;color:#0d1117;border:none;border-radius:8px;font-size:15px;font-weight:700;cursor:pointer;transition:.2s;margin-top:8px}
.save-btn:hover{background:#1a96f0}
.notice{padding:12px 16px;border-radius:8px;font-size:13px;margin-bottom:16px}
.notice.success{background:rgba(46,204,113,.1);border:1px solid rgba(46,204,113,.3);color:#2ecc71}
.footer-links{text-align:center;margin-top:20px;font-size:12px;color:#484f58}
.footer-links a{color:#8b949e;text-decoration:none;margin:0 8px}
.all-toggle{display:flex;align-items:center;gap:8px;margin-bottom:10px;font-size:12px;color:#8b949e;cursor:pointer}
@media(max-width:480px){.sources-grid{grid-template-columns:1fr}.radio-row{flex-direction:column}}
</style>
</head>
<body>
<div class="pref-wrap">

    <div class="pref-header">
        <a class="pref-logo" href="<?php echo esc_url(home_url('/')); ?>"><?php echo esc_html(get_bloginfo('name')); ?> <span>⬡</span></a>
        <div class="pref-title">Mes préférences d'alerte</div>
        <div class="pref-email"><?php echo esc_html($sub['email']); ?></div>
    </div>

    <?php if (isset($saved) && $saved): ?>
    <div class="notice success">✓ Préférences enregistrées avec succès.</div>
    <?php endif; ?>

    <form method="post">
        <?php wp_nonce_field('rdr_prefs_'.$token,'rdr_prefs_nonce'); ?>

        <!-- Fréquence -->
        <div class="pref-card">
            <h3>📅 Fréquence de réception</h3>
            <div class="radio-row">
                <label class="radio-opt">
                    <input type="radio" name="frequency" value="daily" <?php checked($sub['frequency'],'daily'); ?>>
                    <span class="radio-box"><span class="radio-title">Quotidien</span><span class="radio-sub">Chaque matin à 8h</span></span>
                </label>
                <label class="radio-opt">
                    <input type="radio" name="frequency" value="weekly" <?php checked($sub['frequency'],'weekly'); ?>>
                    <span class="radio-box"><span class="radio-title">Hebdomadaire</span><span class="radio-sub">Chaque jeudi à 8h</span></span>
                </label>
            </div>
        </div>

        <!-- Sévérité minimale -->
        <div class="pref-card">
            <h3>🔴 Niveau de sévérité minimum</h3>
            <div class="pref-section-label" style="margin-bottom:10px">Ne recevoir que les alertes d'un niveau au moins égal à :</div>
            <select name="min_severity" class="select-input">
                <?php
                $sev_opts = array(''=>'Toutes les sévérités','medium'=>'Moyenne et plus','high'=>'Haute et critique','critical'=>'Critique uniquement');
                $cur_sev  = $sub['pref_min_severity']??'';
                foreach ($sev_opts as $val=>$label): ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($cur_sev,$val); ?>><?php echo esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- Sources -->
        <div class="pref-card">
            <h3>📡 Sources à inclure</h3>
            <label class="all-toggle">
                <input type="checkbox" id="rdr-src-all" style="width:14px;height:14px;accent-color:#2ea8ff;cursor:pointer">
                Tout sélectionner / désélectionner
            </label>
            <?php
            $all_sources = RDR_Collector::get_all_sources();
            $enabled_ids = get_option('rdr_cyber_sources_enabled', null);
            $active_sources = array();
            foreach ($all_sources as $s) {
                if ($enabled_ids===null && $s['default']) $active_sources[] = $s;
                elseif ($enabled_ids!==null && !empty($enabled_ids[$s['id']])) $active_sources[] = $s;
            }
            ?>
            <div class="sources-grid">
                <?php foreach ($active_sources as $src):
                    if ($sub_sources === null) $is_checked = true;
                    else $is_checked = in_array($src['source'], $sub_sources, true);
                ?>
                <label class="source-toggle">
                    <input type="checkbox" name="pref_sources[]" value="<?php echo esc_attr($src['source']); ?>" class="rdr-src-cb" <?php checked($is_checked); ?>>
                    <div class="src-icon"></div>
                    <span class="src-name"><?php echo esc_html($src['label']); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <?php if (empty($active_sources)): ?>
            <p style="color:#8b949e;font-size:13px">Aucune source active. Activez des sources dans l'admin du plugin.</p>
            <?php endif; ?>
        </div>

        <button type="submit" class="save-btn">Enregistrer mes préférences</button>
    </form>

    <div class="footer-links">
        <a href="<?php echo esc_url(home_url('/')); ?>">Retour au site</a>
        <a href="<?php echo esc_url(add_query_arg(array('rdr_cyber_unsub'=>$sub['unsubscribe_token']),home_url('/'))); ?>"
           onclick="return confirm('Confirmer le désabonnement ?')">Se désabonner</a>
    </div>

</div>

<script>
document.getElementById('rdr-src-all').addEventListener('change',function(){
    document.querySelectorAll('.rdr-src-cb').forEach(function(cb){cb.checked=this.checked;},this);
});
</script>
</body>
</html>
