<?php
/**
 * Ressources complémentaires des leçons
 * Types : internal (card), external (lien nofollow), youtube (embed), file (lien)
 * Stockées en post_meta _rdrc_resources : tableau JSON
 */

/* ── Meta-box ────────────────────────────────────────────── */
add_action( 'add_meta_boxes', function() {
    add_meta_box(
        'rdrc_resources',
        '🔗 Ressources complémentaires',
        'rdrc_mb_resources',
        'rdrc_lesson',
        'normal',
        'default'
    );
});

function rdrc_mb_resources( $post ) {
    wp_nonce_field('rdrc_save_resources','rdrc_resources_nonce');
    $resources = get_post_meta($post->ID, '_rdrc_resources', true);
    $resources = is_array($resources) ? $resources : [];
    $json      = json_encode($resources, JSON_UNESCAPED_UNICODE);
    // Tous les posts publiés pour l'autocomplete interne
    $internal  = get_posts(['post_status'=>'publish','posts_per_page'=>-1,'post_type'=>['post','page','rdrc_lesson','rdrc_course'],'orderby'=>'title','order'=>'ASC']);
    $internal_json = json_encode(array_map(fn($p)=>['id'=>$p->ID,'title'=>$p->post_title,'url'=>get_permalink($p->ID),'thumb'=>get_the_post_thumbnail_url($p->ID,'thumbnail')?:''], $internal), JSON_UNESCAPED_UNICODE);
    ?>
    <div id="rdrc-resources-app">
        <div id="rdrc-resources-list"></div>
        <div class="rdrc-res-toolbar">
            <button type="button" class="button rdrc-res-add" data-type="internal">🔗 Lien interne</button>
            <button type="button" class="button rdrc-res-add" data-type="external">🌐 Lien externe</button>
            <button type="button" class="button rdrc-res-add" data-type="youtube">🎥 Vidéo YouTube</button>
            <button type="button" class="button rdrc-res-add" data-type="file">📁 Fichier</button>
        </div>
    </div>
    <input type="hidden" name="rdrc_resources_json" id="rdrc-resources-json" value="<?php echo esc_attr($json); ?>">

    <style>
    #rdrc-resources-app{padding:4px 0;}
    .rdrc-res-toolbar{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;}
    .rdrc-res-row{display:flex;align-items:center;gap:8px;padding:8px;margin:4px 0;background:#f6f7f7;border:1px solid #ddd;border-radius:4px;}
    .rdrc-res-row .rdrc-res-type{font-size:16px;flex-shrink:0;}
    .rdrc-res-row input[type=text],.rdrc-res-row input[type=url]{flex:1;padding:4px 6px;border:1px solid #ddd;border-radius:3px;font-size:12px;}
    .rdrc-res-row .rdrc-res-del{background:none;border:none;cursor:pointer;color:#c00;font-size:16px;}
    .rdrc-res-row .rdrc-res-label{font-size:11px;color:#666;flex-shrink:0;min-width:50px;}
    .rdrc-res-autocomplete{position:relative;}
    .rdrc-ac-list{position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #ddd;border-radius:3px;max-height:200px;overflow-y:auto;z-index:1000;box-shadow:0 4px 12px rgba(0,0,0,.1);}
    .rdrc-ac-item{padding:6px 10px;cursor:pointer;font-size:12px;display:flex;align-items:center;gap:6px;border-bottom:1px solid #f0f0f0;}
    .rdrc-ac-item:hover{background:#f0f7ff;}
    .rdrc-ac-item img{width:30px;height:30px;object-fit:cover;border-radius:2px;flex-shrink:0;}
    </style>

    <script>
    (function(){
    var ALL_INTERNAL = <?php echo $internal_json; ?>;
    var resources    = <?php echo $json; ?>;
    var $list        = document.getElementById('rdrc-resources-list');
    var $json        = document.getElementById('rdrc-resources-json');

    function sync(){ $json.value = JSON.stringify(resources); }
    function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    function render(){
        $list.innerHTML = '';
        resources.forEach(function(res,idx){
            var row = document.createElement('div');
            row.className = 'rdrc-res-row';
            var icons = {internal:'🔗',external:'🌐',youtube:'🎥',file:'📁'};
            var html  = '<span class="rdrc-res-type">' + (icons[res.type]||'🔗') + '</span>';

            if(res.type === 'internal'){
                html += '<div class="rdrc-res-autocomplete" style="flex:1;">'
                     +  '<input type="text" class="rdrc-ac-input" data-idx="'+idx+'" placeholder="Titre de l\'article…" value="'+esc(res.title||'')+'">'
                     +  '<div class="rdrc-ac-list" style="display:none;"></div>'
                     +  '</div>'
                     +  '<input type="url" class="rdrc-res-url" data-idx="'+idx+'" placeholder="URL" value="'+esc(res.url||'')+'" style="max-width:200px;">';
            } else if(res.type === 'external'){
                html += '<span class="rdrc-res-label">Titre</span><input type="text" class="rdrc-res-title" data-idx="'+idx+'" placeholder="Nom du lien" value="'+esc(res.title||'')+'">'
                     +  '<span class="rdrc-res-label">URL</span><input type="url" class="rdrc-res-url" data-idx="'+idx+'" placeholder="https://…" value="'+esc(res.url||'')+'">';
            } else if(res.type === 'youtube'){
                html += '<span class="rdrc-res-label">Titre</span><input type="text" class="rdrc-res-title" data-idx="'+idx+'" placeholder="Titre de la vidéo" value="'+esc(res.title||'')+'">'
                     +  '<span class="rdrc-res-label">URL YouTube</span><input type="url" class="rdrc-res-url" data-idx="'+idx+'" placeholder="https://youtube.com/watch?v=…" value="'+esc(res.url||'')+'">';
            } else if(res.type === 'file'){
                html += '<span class="rdrc-res-label">Titre</span><input type="text" class="rdrc-res-title" data-idx="'+idx+'" placeholder="Nom du fichier" value="'+esc(res.title||'')+'">'
                     +  '<span class="rdrc-res-label">URL</span><input type="url" class="rdrc-res-url" data-idx="'+idx+'" placeholder="https://…/fichier.pdf" value="'+esc(res.url||'')+'">';
            }

            html += '<button type="button" class="rdrc-res-del" data-idx="'+idx+'" title="Supprimer">✕</button>';
            row.innerHTML = html;
            $list.appendChild(row);
        });

        // Autocomplete interne
        $list.querySelectorAll('.rdrc-ac-input').forEach(function(inp){
            var $ac  = inp.nextElementSibling;
            var idx  = parseInt(inp.dataset.idx, 10);
            inp.addEventListener('input', function(){
                var q = this.value.toLowerCase();
                resources[idx].title = this.value;
                sync();
                if(q.length < 2){ $ac.style.display='none'; return; }
                var matches = ALL_INTERNAL.filter(function(p){ return p.title.toLowerCase().indexOf(q) !== -1; }).slice(0,10);
                if(!matches.length){ $ac.style.display='none'; return; }
                $ac.innerHTML = matches.map(function(p){
                    return '<div class="rdrc-ac-item" data-id="'+p.id+'" data-title="'+esc(p.title)+'" data-url="'+esc(p.url)+'">'
                         + (p.thumb ? '<img src="'+esc(p.thumb)+'">' : '<span style="width:30px;text-align:center;">📄</span>')
                         + '<span>'+esc(p.title)+'</span></div>';
                }).join('');
                $ac.style.display = 'block';
                $ac.querySelectorAll('.rdrc-ac-item').forEach(function(item){
                    item.addEventListener('click', function(){
                        inp.value = item.dataset.title;
                        inp.nextElementSibling.nextElementSibling.value = item.dataset.url;
                        resources[idx].title = item.dataset.title;
                        resources[idx].url   = item.dataset.url;
                        $ac.style.display = 'none';
                        sync();
                    });
                });
            });
            document.addEventListener('click', function(e){ if(!inp.contains(e.target) && !$ac.contains(e.target)) $ac.style.display='none'; });
        });

        // Champs titre/url
        $list.querySelectorAll('.rdrc-res-title').forEach(function(inp){
            inp.addEventListener('input', function(){ resources[parseInt(this.dataset.idx,10)].title = this.value; sync(); });
        });
        $list.querySelectorAll('.rdrc-res-url').forEach(function(inp){
            inp.addEventListener('input', function(){ resources[parseInt(this.dataset.idx,10)].url = this.value; sync(); });
        });

        // Supprimer
        $list.querySelectorAll('.rdrc-res-del').forEach(function(btn){
            btn.addEventListener('click', function(){
                resources.splice(parseInt(this.dataset.idx,10), 1);
                render(); sync();
            });
        });
    }

    // Ajouter
    document.querySelectorAll('.rdrc-res-add').forEach(function(btn){
        btn.addEventListener('click', function(){
            resources.push({type: this.dataset.type, title:'', url:''});
            render(); sync();
        });
    });

    render();
    })();
    </script>
    <?php
}

add_action( 'save_post', function($post_id) {
    if ( get_post_type($post_id) !== 'rdrc_lesson' ) return;
    if ( !isset($_POST['rdrc_resources_nonce']) || !wp_verify_nonce($_POST['rdrc_resources_nonce'],'rdrc_save_resources') ) return;
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( !current_user_can('edit_post',$post_id) ) return;
    if ( !isset($_POST['rdrc_resources_json']) ) return;

    $resources = json_decode(stripslashes($_POST['rdrc_resources_json']), true);
    if ( !is_array($resources) ) return;
    // Nettoyer
    $clean = [];
    foreach ($resources as $r) {
        if ( empty($r['url']) && empty($r['title']) ) continue;
        $clean[] = [
            'type'  => sanitize_key($r['type']  ?? 'external'),
            'title' => sanitize_text_field($r['title'] ?? ''),
            'url'   => esc_url_raw($r['url']    ?? ''),
        ];
    }
    update_post_meta($post_id, '_rdrc_resources', $clean);
});

/* ── Front : rendu des ressources (onglet async) ────────── */
function rdrc_render_resources( $lesson_id ) {
    $resources = get_post_meta($lesson_id, '_rdrc_resources', true);
    if ( !is_array($resources) || empty($resources) ) return '';

    $internals = array_filter($resources, fn($r) => $r['type'] === 'internal');
    $externals = array_filter($resources, fn($r) => $r['type'] === 'external');
    $videos    = array_filter($resources, fn($r) => $r['type'] === 'youtube');
    $files     = array_filter($resources, fn($r) => $r['type'] === 'file');

    ob_start();
    ?>
    <div class="rdrc-resources-tab" id="rdrc-resources-tab">
        <button class="rdrc-resources-toggle" aria-expanded="false">
            🔗 Ressources complémentaires
            <span class="rdrc-res-count"><?php echo count($resources); ?></span>
            <span class="rdrc-res-arrow">▼</span>
        </button>
        <div class="rdrc-resources-body" hidden>

            <?php if (!empty($videos)): ?>
            <div class="rdrc-res-section">
                <h4>🎥 Vidéos</h4>
                <div class="rdrc-res-videos">
                <?php foreach ($videos as $r):
                    $vid_id = rdrc_youtube_id($r['url']);
                    if (!$vid_id) continue;
                ?>
                    <div class="rdrc-res-video-wrap">
                        <?php if ($r['title']): ?><p class="rdrc-res-video-title"><?php echo esc_html($r['title']); ?></p><?php endif; ?>
                        <div class="rdrc-res-video-embed">
                            <iframe loading="lazy" referrerpolicy="no-referrer"
                                src="https://www.youtube-nocookie.com/embed/<?php echo esc_attr($vid_id); ?>"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                allowfullscreen title="<?php echo esc_attr($r['title']); ?>"></iframe>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($internals)): ?>
            <div class="rdrc-res-section">
                <h4>🔗 Articles liés</h4>
                <div class="rdrc-res-cards">
                <?php foreach ($internals as $r):
                    if (!$r['url']) continue;
                    $pid   = url_to_postid($r['url']);
                    $thumb = $pid ? get_the_post_thumbnail_url($pid,'medium') : '';
                    $desc  = $pid ? wp_trim_words(get_post_field('post_excerpt',$pid) ?: get_post_field('post_content',$pid), 15) : '';
                ?>
                    <a href="<?php echo esc_url($r['url']); ?>" class="rdrc-res-card" target="_blank" rel="noopener">
                        <?php if ($thumb): ?>
                        <div class="rdrc-res-card-img"><img src="<?php echo esc_url($thumb); ?>" alt="" loading="lazy"></div>
                        <?php endif; ?>
                        <div class="rdrc-res-card-body">
                            <span class="rdrc-res-card-title"><?php echo esc_html($r['title'] ?: $r['url']); ?></span>
                            <?php if ($desc): ?><span class="rdrc-res-card-desc"><?php echo esc_html($desc); ?></span><?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($externals) || !empty($files)): ?>
            <div class="rdrc-res-section rdrc-res-links">
                <?php if (!empty($externals)): ?>
                <h4>🌐 Liens externes</h4>
                <ul>
                <?php foreach ($externals as $r): if (!$r['url']) continue; ?>
                    <li><a href="<?php echo esc_url($r['url']); ?>" target="_blank" rel="nofollow noopener noreferrer">
                        <?php echo esc_html($r['title'] ?: $r['url']); ?> ↗
                    </a></li>
                <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if (!empty($files)): ?>
                <h4>📁 Fichiers</h4>
                <ul>
                <?php foreach ($files as $r): if (!$r['url']) continue;
                    $ext = strtoupper(pathinfo(parse_url($r['url'],PHP_URL_PATH), PATHINFO_EXTENSION));
                ?>
                    <li><a href="<?php echo esc_url($r['url']); ?>" target="_blank" rel="noopener">
                        📥 <?php echo esc_html($r['title'] ?: basename($r['url'])); ?>
                        <?php if ($ext): ?><span class="rdrc-res-ext"><?php echo esc_html($ext); ?></span><?php endif; ?>
                    </a></li>
                <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
    </div>
    <?php
    return ob_get_clean();
}

function rdrc_youtube_id( $url ) {
    if ( preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/', $url, $m) ) {
        return $m[1];
    }
    return '';
}
