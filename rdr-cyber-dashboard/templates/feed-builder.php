<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$all_sources = RDR_Collector::get_all_sources();
$enabled     = get_option('rdr_cyber_sources_enabled', null);
$sources     = array();
foreach ($all_sources as $s) {
    if ($enabled === null && $s['default'])     $sources[] = $s;
    elseif ($enabled !== null && !empty($enabled[$s['id']])) $sources[] = $s;
}
/* Dédoublonnage par nom de source */
$unique_sources = array();
$seen = array();
foreach ($sources as $s) {
    if (!isset($seen[$s['source']])) { $unique_sources[] = $s; $seen[$s['source']] = true; }
}

$feed_base = home_url('/feed/'.RDR_Feed::FEED_SLUG.'/');
?>
<div class="rdr-fb-wrap" id="rdr-fb-wrap">

    <div class="rdr-fb-card">
        <div class="rdr-fb-header">
            <span class="rdr-fb-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/>
                </svg>
            </span>
            <div>
                <h3 class="rdr-fb-title">Générateur de flux RSS</h3>
                <p class="rdr-fb-subtitle">Personnalisez votre flux et abonnez-vous dans votre agrégateur favori.</p>
            </div>
        </div>

        <div class="rdr-fb-form">

            <div class="rdr-fb-field">
                <label class="rdr-fb-label" for="fb-source">Source</label>
                <select id="fb-source" class="rdr-fb-select">
                    <option value="">Toutes les sources</option>
                    <?php foreach ($unique_sources as $src): ?>
                    <option value="<?php echo esc_attr($src['source']); ?>"><?php echo esc_html($src['source']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="rdr-fb-field">
                <label class="rdr-fb-label" for="fb-severity">Sévérité minimale</label>
                <select id="fb-severity" class="rdr-fb-select">
                    <option value="">Toutes</option>
                    <option value="critical">Critique</option>
                    <option value="high">Haute</option>
                    <option value="medium">Moyenne</option>
                    <option value="low">Faible</option>
                </select>
            </div>

            <div class="rdr-fb-field">
                <label class="rdr-fb-label" for="fb-limit">Nombre d'entrées</label>
                <select id="fb-limit" class="rdr-fb-select">
                    <option value="10">10 entrées</option>
                    <option value="25" selected>25 entrées</option>
                    <option value="50">50 entrées (max)</option>
                </select>
            </div>

        </div>

        <div class="rdr-fb-result">
            <label class="rdr-fb-label">Votre URL de flux RSS :</label>
            <div class="rdr-fb-url-row">
                <code id="rdr-fb-url" class="rdr-fb-url"><?php echo esc_html($feed_base); ?></code>
                <button id="rdr-fb-copy" class="rdr-fb-copy" title="Copier">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    Copier
                </button>
                <a id="rdr-fb-open" href="<?php echo esc_url($feed_base); ?>" target="_blank" class="rdr-fb-open">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                    Ouvrir
                </a>
            </div>
            <div class="rdr-fb-apps">
                <span class="rdr-fb-app-label">Ajouter à :</span>
                <a id="rdr-fb-feedly"  href="#" target="_blank" class="rdr-fb-app-btn">Feedly</a>
                <a id="rdr-fb-inoreader" href="#" target="_blank" class="rdr-fb-app-btn">Inoreader</a>
                <a id="rdr-fb-newsblur"  href="#" target="_blank" class="rdr-fb-app-btn">NewsBlur</a>
            </div>
        </div>

    </div>

    <!-- Aperçu en direct -->
    <div class="rdr-fb-preview-wrap">
        <div class="rdr-fb-preview-header">
            <span class="rdr-fb-preview-title">Aperçu des dernières entrées</span>
            <button id="rdr-fb-refresh" class="rdr-fb-refresh-btn">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-3.5"/></svg>
                Actualiser
            </button>
        </div>
        <div id="rdr-fb-preview-list" class="rdr-fb-preview-list">
            <div class="rdr-fb-loading">Chargement…</div>
        </div>
    </div>

</div>

<style>
.rdr-fb-wrap{max-width:680px;margin:0 auto;display:flex;flex-direction:column;gap:16px}
.rdr-fb-card{background:var(--rdr-bg-card,#1c2128);border:1px solid var(--rdr-border,rgba(255,255,255,.07));border-radius:14px;padding:24px 26px;box-shadow:var(--rdr-shadow-card,0 2px 8px rgba(0,0,0,.3))}
.rdr-fb-header{display:flex;align-items:flex-start;gap:14px;margin-bottom:20px}
.rdr-fb-icon{width:46px;height:46px;background:rgba(255,152,0,.08);border:1px solid rgba(255,152,0,.2);border-radius:10px;display:flex;align-items:center;justify-content:center;color:#ff9800;flex-shrink:0}
.rdr-fb-title{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:var(--rdr-text-primary,#e6edf3);margin:0 0 4px}
.rdr-fb-subtitle{font-size:12px;color:var(--rdr-text-secondary,#8b949e);margin:0}
.rdr-fb-form{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px}
.rdr-fb-field{}
.rdr-fb-label{display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--rdr-text-muted,#484f58);margin-bottom:6px}
.rdr-fb-select{width:100%;padding:9px 12px;background:var(--rdr-bg-surface,#161b22);border:1px solid var(--rdr-border-strong,rgba(255,255,255,.15));border-radius:8px;color:var(--rdr-text-primary,#e6edf3);font-size:13px;outline:none;cursor:pointer;transition:border-color .2s}
.rdr-fb-select:focus{border-color:var(--rdr-accent,#2ea8ff)}
.rdr-fb-result{background:var(--rdr-bg-surface,#161b22);border:1px solid var(--rdr-border,rgba(255,255,255,.07));border-radius:10px;padding:14px 16px}
.rdr-fb-url-row{display:flex;align-items:center;gap:8px;margin-top:6px;margin-bottom:12px;flex-wrap:wrap}
.rdr-fb-url{flex:1;font-size:12px;color:var(--rdr-accent-2,#00e5c0);word-break:break-all;background:var(--rdr-bg-primary,#0d1117);padding:8px 12px;border-radius:6px;border:1px solid rgba(0,229,192,.15)}
.rdr-fb-copy,.rdr-fb-open{display:inline-flex;align-items:center;gap:5px;padding:7px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:.2s;white-space:nowrap}
.rdr-fb-copy{background:rgba(46,168,255,.12);color:var(--rdr-accent,#2ea8ff);border:1px solid rgba(46,168,255,.25)}
.rdr-fb-copy:hover,.rdr-fb-copy.copied{background:rgba(46,168,255,.25)}
.rdr-fb-open{background:rgba(255,152,0,.1);color:#ff9800;border:1px solid rgba(255,152,0,.25)}
.rdr-fb-open:hover{background:rgba(255,152,0,.2)}
.rdr-fb-apps{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.rdr-fb-app-label{font-size:11px;color:var(--rdr-text-muted);font-weight:700;text-transform:uppercase;letter-spacing:.5px}
.rdr-fb-app-btn{display:inline-block;padding:4px 10px;border:1px solid var(--rdr-border);border-radius:5px;font-size:11px;font-weight:600;color:var(--rdr-text-secondary);text-decoration:none;transition:.2s}
.rdr-fb-app-btn:hover{border-color:var(--rdr-accent);color:var(--rdr-accent)}
.rdr-fb-preview-wrap{background:var(--rdr-bg-card,#1c2128);border:1px solid var(--rdr-border,rgba(255,255,255,.07));border-radius:14px;padding:16px 20px}
.rdr-fb-preview-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.rdr-fb-preview-title{font-size:13px;font-weight:700;color:var(--rdr-text-secondary)}
.rdr-fb-refresh-btn{display:inline-flex;align-items:center;gap:5px;padding:5px 10px;background:transparent;border:1px solid var(--rdr-border);border-radius:6px;color:var(--rdr-text-muted);font-size:11px;cursor:pointer;transition:.2s}
.rdr-fb-refresh-btn:hover{border-color:var(--rdr-accent);color:var(--rdr-accent)}
.rdr-fb-preview-list{display:flex;flex-direction:column;gap:8px;max-height:320px;overflow-y:auto}
.rdr-fb-loading{text-align:center;color:var(--rdr-text-muted);font-size:13px;padding:20px}
.rdr-fb-item{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;background:var(--rdr-bg-surface,#161b22);border-radius:8px;border:1px solid var(--rdr-border)}
.rdr-fb-item-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0;margin-top:4px}
.rdr-fb-item-title{font-size:12px;font-weight:600;color:var(--rdr-text-primary);flex:1}
.rdr-fb-item-title a{color:inherit;text-decoration:none}
.rdr-fb-item-title a:hover{color:var(--rdr-accent)}
.rdr-fb-item-meta{font-size:10px;color:var(--rdr-text-muted);margin-top:2px}
@media(max-width:500px){.rdr-fb-form{grid-template-columns:1fr}}
</style>

<script>
(function(){
    var baseUrl = '<?php echo esc_js($feed_base); ?>';
    var srcEl   = document.getElementById('fb-source');
    var sevEl   = document.getElementById('fb-severity');
    var limEl   = document.getElementById('fb-limit');
    var urlEl   = document.getElementById('rdr-fb-url');
    var copyBtn = document.getElementById('rdr-fb-copy');
    var openBtn = document.getElementById('rdr-fb-open');
    var fedBtn  = document.getElementById('rdr-fb-feedly');
    var inoBtn  = document.getElementById('rdr-fb-inoreader');
    var nblBtn  = document.getElementById('rdr-fb-newsblur');

    function buildUrl(){
        var p = new URLSearchParams();
        if (srcEl.value)  p.set('source',  srcEl.value);
        if (sevEl.value)  p.set('severity',sevEl.value);
        if (limEl.value)  p.set('limit',   limEl.value);
        var qs  = p.toString();
        return baseUrl + (qs ? '?'+qs : '');
    }

    function update(){
        var url = buildUrl();
        urlEl.textContent = url;
        openBtn.href      = url;
        fedBtn.href       = 'https://feedly.com/i/subscription/feed/'+encodeURIComponent(url);
        inoBtn.href       = 'https://www.inoreader.com/search/feeds/'+encodeURIComponent(url);
        nblBtn.href       = 'http://www.newsblur.com/?url='+encodeURIComponent(url);
        loadPreview(url);
    }

    function loadPreview(url){
        var list = document.getElementById('rdr-fb-preview-list');
        if (!list) return;
        list.innerHTML = '<div class="rdr-fb-loading">Chargement…</div>';
        fetch(url+'#preview')
        .then(function(r){ return r.text(); })
        .then(function(txt){
            var parser = new DOMParser();
            var doc    = parser.parseFromString(txt,'text/xml');
            var items  = doc.querySelectorAll('item');
            if (!items.length){ list.innerHTML='<div class="rdr-fb-loading">Aucun résultat.</div>'; return; }
            var html = '';
            var sevColors = {critical:'#ff4757',high:'#ff6b35',medium:'#f5a623',low:'#2ecc71',info:'#2ea8ff'};
            Array.from(items).slice(0,8).forEach(function(item){
                var title   = (item.querySelector('title')||{}).textContent||'';
                var link    = (item.querySelector('link')||{}).textContent||'#';
                var cats    = item.querySelectorAll('category');
                var sev     = cats.length>1 ? cats[1].textContent.toLowerCase() : 'info';
                var source  = cats.length>0 ? cats[0].textContent : '';
                var date    = (item.querySelector('pubDate')||{}).textContent||'';
                var color   = sevColors[sev]||'#8b949e';
                var d       = date ? new Date(date).toLocaleDateString('fr-FR',{day:'2-digit',month:'2-digit',year:'numeric'}) : '';
                html += '<div class="rdr-fb-item"><div class="rdr-fb-item-dot" style="background:'+color+'"></div>'
                    +'<div><div class="rdr-fb-item-title"><a href="'+link+'" target="_blank" rel="noopener">'+escH(title.replace(/^\[(CRITICAL|HIGH|MEDIUM|LOW|INFO|[A-Z]+)\]\s*/,''))+'</a></div>'
                    +'<div class="rdr-fb-item-meta">'+escH(source)+(d?' · '+d:'')+'</div></div></div>';
            });
            list.innerHTML = html;
        })
        .catch(function(){ list.innerHTML='<div class="rdr-fb-loading">Impossible de charger l\'aperçu.</div>'; });
    }

    function escH(str){ var d=document.createElement('div'); d.textContent=str; return d.innerHTML; }

    [srcEl,sevEl,limEl].forEach(function(el){ if(el) el.addEventListener('change',update); });

    copyBtn && copyBtn.addEventListener('click',function(){
        navigator.clipboard.writeText(buildUrl()).then(function(){
            copyBtn.textContent='✓ Copié !';
            copyBtn.classList.add('copied');
            setTimeout(function(){ copyBtn.innerHTML='<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copier'; copyBtn.classList.remove('copied'); },2000);
        }).catch(function(){ prompt('Copiez cette URL :',buildUrl()); });
    });

    document.getElementById('rdr-fb-refresh') && document.getElementById('rdr-fb-refresh').addEventListener('click',function(){ update(); });

    update();
})();
</script>
