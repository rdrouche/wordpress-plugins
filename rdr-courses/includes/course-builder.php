<?php
/**
 * Page constructeur de cours — tout en PHP inline
 * Zéro enqueue, zéro conflit Gutenberg
 * URL : /wp-admin/admin.php?page=rdrc-builder&course_id=XXX
 */

add_action( 'admin_menu', function() {
    add_submenu_page(
        'edit.php?post_type=rdrc_course',
        'Constructeur de cours',
        '📋 Constructeur',
        'edit_posts',
        'rdrc-builder',
        'rdrc_builder_render'
    );
});

// Lien rapide dans les actions de la liste
add_filter( 'post_row_actions', function( $actions, $post ) {
    if ( $post->post_type !== 'rdrc_course' ) return $actions;
    $url = admin_url('admin.php?page=rdrc-builder&course_id=' . $post->ID);
    $actions['rdrc_structure'] = '<a href="' . esc_url($url) . '" style="font-weight:600;">📋 Structure</a>';
    return $actions;
}, 10, 2 );

// AJAX save
add_action( 'wp_ajax_rdrc_save_structure', function() {
    check_ajax_referer('rdrc_builder_nonce', 'nonce');
    if ( !current_user_can('edit_posts') ) wp_send_json_error('unauthorized');

    $course_id = (int)($_POST['course_id'] ?? 0);
    $structure = json_decode( stripslashes($_POST['structure'] ?? '[]'), true );

    if ( !$course_id || !is_array($structure) ) wp_send_json_error('invalid');

    $structure = array_values($structure);
    update_post_meta( $course_id, '_rdrc_structure', $structure );
    foreach ( $structure as $item ) {
        if ( ($item['type']??'') === 'lesson' && !empty($item['lesson_id']) ) {
            update_post_meta( (int)$item['lesson_id'], '_rdrc_parent_course', $course_id );
        }
    }
    wp_send_json_success(['count' => count($structure)]);
});

function rdrc_builder_render() {
    if ( !current_user_can('edit_posts') ) return;

    $course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : 0;

    // Sans course_id : liste des cours
    if ( !$course_id ) {
        $courses = get_posts(['post_type'=>'rdrc_course','posts_per_page'=>-1,'post_status'=>['publish','draft'],'orderby'=>'title','order'=>'ASC']);
        echo '<div class="wrap"><h1>📋 Constructeur de cours</h1>';
        if ( !$courses ) { echo '<p>Aucun cours. <a href="'.admin_url('post-new.php?post_type=rdrc_course').'">Créer un cours</a></p></div>'; return; }
        echo '<p>Choisissez un cours :</p><table class="widefat"><thead><tr><th>Cours</th><th>Leçons</th><th>Action</th></tr></thead><tbody>';
        foreach ( $courses as $c ) {
            $s  = get_post_meta($c->ID,'_rdrc_structure',true) ?: [];
            $nb = count(array_filter($s, fn($i)=>($i['type']??'')==='lesson'));
            $url = admin_url('admin.php?page=rdrc-builder&course_id='.$c->ID);
            echo '<tr><td><strong>'.esc_html($c->post_title).'</strong> <span style="color:#888;">('.$c->post_status.')</span></td>';
            echo '<td>'.$nb.' leçon'.($nb!=1?'s':'').'</td>';
            echo '<td><a href="'.esc_url($url).'" class="button">📋 Gérer la structure</a></td></tr>';
        }
        echo '</tbody></table></div>';
        return;
    }

    $course = get_post($course_id);
    if ( !$course ) { echo '<div class="wrap"><p>Cours introuvable.</p></div>'; return; }

    // Charger la structure
    $structure = get_post_meta($course_id, '_rdrc_structure', true);
    $structure = is_array($structure) ? array_values($structure) : [];
    foreach ( $structure as &$item ) {
        if ( ($item['type']??'') === 'lesson' && !empty($item['lesson_id']) ) {
            $l = get_post((int)$item['lesson_id']);
            if ($l) {
                $item['lesson_title']  = $l->post_title;
                $item['lesson_status'] = $l->post_status;
                $item['lesson_type']   = get_post_meta($l->ID,'_rdrc_lesson_type',true) ?: 'article';
            }
        }
    }
    unset($item);
    $structure = array_values($structure);

    // Toutes les leçons
    $all_lessons = get_posts(['post_type'=>'rdrc_lesson','posts_per_page'=>-1,'post_status'=>'any','orderby'=>'title','order'=>'ASC']);

    $nonce         = wp_create_nonce('rdrc_builder_nonce');
    $ajax_url      = admin_url('admin-ajax.php');
    $edit_base     = admin_url('post.php?action=edit&post=');
    $new_lesson    = admin_url('post-new.php?post_type=rdrc_lesson&rdrc_course='.$course_id);
    $structure_js  = json_encode($structure, JSON_UNESCAPED_UNICODE);
    $lessons_js    = json_encode(array_map(function($l){
        return ['id'=>$l->ID,'title'=>$l->post_title,'status'=>$l->post_status,'type'=>get_post_meta($l->ID,'_rdrc_lesson_type',true)?:'article'];
    }, $all_lessons), JSON_UNESCAPED_UNICODE);

    $nb = count(array_filter($structure, fn($i)=>($i['type']??'')==='lesson'));
    $back_url = admin_url('edit.php?post_type=rdrc_course');
    $edit_course = get_edit_post_link($course_id);
    $view_course = get_permalink($course_id);
    ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<?php
// Charger les styles WP admin essentiels
wp_print_styles(['wp-admin','buttons','dashicons']);
?>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;font-size:13px;background:#f0f0f1;color:#1d2327;}
a{color:#2271b1;}

/* Layout */
.rdrcb-page{max-width:960px;margin:0 auto;padding:20px;}

/* Header */
.rdrcb-header{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px 20px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;}
.rdrcb-header-left{display:flex;flex-direction:column;gap:4px;}
.rdrcb-back{font-size:12px;color:#646970;text-decoration:none;}
.rdrcb-back:hover{color:#2271b1;}
.rdrcb-header h1{font-size:18px;font-weight:700;color:#1d2327;}
.rdrcb-header-links{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;}
.rdrcb-header-right{display:flex;align-items:center;gap:10px;}
#rdrcb-status{font-size:12px;font-style:italic;color:#646970;}

/* Body */
.rdrcb-body{display:grid;grid-template-columns:1fr 260px;gap:16px;align-items:start;}

/* Panel */
.rdrcb-panel{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;}
.rdrcb-panel h2{font-size:14px;font-weight:700;margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid #f0f0f1;}

/* Toolbar */
.rdrcb-toolbar{display:flex;gap:8px;margin-bottom:12px;}

/* Structure list */
.rdrcb-list{min-height:80px;border:2px dashed #ddd;border-radius:4px;padding:6px;}
.rdrcb-empty{text-align:center;padding:32px 16px;color:#999;font-style:italic;}

/* Items */
.rdrcb-item{display:flex;align-items:center;gap:8px;padding:8px 10px;margin:3px 0;border-radius:4px;cursor:default;border:1px solid transparent;}
.rdrcb-item-section{background:#f6f7f7;border-color:#c3c4c7;}
.rdrcb-item-lesson{background:#fff;border-color:#ddd;}
.rdrcb-item-lesson.draft{opacity:.7;}
.rdrcb-handle{cursor:grab;color:#bbb;font-size:16px;flex-shrink:0;}
.rdrcb-handle:active{cursor:grabbing;}
.rdrcb-icon{font-size:16px;flex-shrink:0;}
.rdrcb-item-section input[type=text]{flex:1;border:1px solid transparent;background:transparent;padding:2px 6px;font-size:13px;font-weight:600;border-radius:3px;color:#1d2327;}
.rdrcb-item-section input[type=text]:focus{border-color:#2271b1;background:#fff;outline:none;}
.rdrcb-name{flex:1;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.rdrcb-badge{font-size:10px;padding:2px 6px;border-radius:10px;flex-shrink:0;}
.rdrcb-badge.pub{background:#d7ede2;color:#1a6535;}
.rdrcb-badge.draft{background:#fef3cd;color:#7a4200;}
.rdrcb-actions{display:flex;gap:4px;align-items:center;flex-shrink:0;margin-left:auto;}
.rdrcb-del{background:none;border:none;cursor:pointer;color:#cc0000;font-size:14px;padding:2px 4px;border-radius:3px;}
.rdrcb-del:hover{background:#fce8e8;}
.rdrcb-ins{font-size:11px;padding:2px 6px;}

/* Sortable placeholder */
.rdrcb-placeholder{height:40px;background:#e8f3ff;border:2px dashed #72aee6;border-radius:4px;margin:3px 0;}

/* Sidebar */
.rdrcb-info-row{display:flex;justify-content:space-between;font-size:13px;padding:4px 0;border-bottom:1px solid #f6f7f7;}
.rdrcb-info-row:last-child{border:none;}
.rdrcb-help{font-size:12px;color:#646970;line-height:1.6;}
.rdrcb-help ol{padding-left:16px;margin-top:6px;}
.rdrcb-help li{margin:4px 0;}

/* Modal */
.rdrcb-modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100000;align-items:center;justify-content:center;}
.rdrcb-modal-bg.open{display:flex;}
.rdrcb-modal{background:#fff;border-radius:4px;width:620px;max-width:95vw;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 4px 24px rgba(0,0,0,.25);}
.rdrcb-modal-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #ddd;}
.rdrcb-modal-header h3{font-size:15px;font-weight:700;}
.rdrcb-modal-close{background:none;border:none;font-size:20px;cursor:pointer;color:#666;padding:0 4px;}
.rdrcb-modal-body{flex:1;overflow:hidden;padding:14px 18px;display:flex;flex-direction:column;gap:10px;}
.rdrcb-filter{width:100%;padding:6px 10px;border:1px solid #ddd;border-radius:3px;font-size:13px;}
.rdrcb-lesson-list{flex:1;overflow-y:auto;border:1px solid #ddd;border-radius:3px;min-height:200px;max-height:320px;}
.rdrcb-row-title{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.rdrcb-row-id{font-size:11px;color:#888;flex-shrink:0;font-family:monospace;}
.rdrcb-lesson-row{display:flex;align-items:center;gap:8px;padding:8px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;transition:background .1s;}
.rdrcb-lesson-row:last-child{border:none;}
.rdrcb-lesson-row:hover:not(.used){background:#f6f7f7;}
.rdrcb-lesson-row.selected{background:#e8f3ff;}
.rdrcb-lesson-row.used{opacity:.4;cursor:not-allowed;}
.rdrcb-lesson-row .title{flex:1;font-size:13px;}
.rdrcb-modal-footer{padding:12px 18px;border-top:1px solid #ddd;display:flex;justify-content:flex-end;gap:8px;}

/* Buttons WP style */
.button{display:inline-block;padding:4px 10px;font-size:13px;border:1px solid #2271b1;border-radius:3px;background:#fff;color:#2271b1;cursor:pointer;text-decoration:none;line-height:2;}
.button:hover{background:#f6f7f7;}
.button-primary{background:#2271b1;color:#fff;border-color:#2271b1;}
.button-primary:hover{background:#135e96;border-color:#135e96;color:#fff;}
.button-small{padding:2px 8px;font-size:11px;line-height:1.8;}
</style>
</head>
<body>
<div class="rdrcb-page">

    <div class="rdrcb-header">
        <div class="rdrcb-header-left">
            <a href="<?php echo esc_url($back_url); ?>" class="rdrcb-back">← Tous les cours</a>
            <h1>📋 <?php echo esc_html($course->post_title); ?></h1>
            <div class="rdrcb-header-links">
                <a href="<?php echo esc_url($edit_course); ?>" class="button button-small" target="_blank">✏️ Modifier le cours</a>
                <a href="<?php echo esc_url($new_lesson); ?>" class="button button-small" target="_blank">➕ Nouvelle leçon</a>
                <?php if ($view_course): ?><a href="<?php echo esc_url($view_course); ?>" class="button button-small" target="_blank">👁 Voir</a><?php endif; ?>
            </div>
        </div>
        <div class="rdrcb-header-right">
            <span id="rdrcb-status"></span>
            <button type="button" id="rdrcb-save" class="button button-primary">💾 Sauvegarder</button>
        </div>
    </div>

    <div class="rdrcb-body">
        <div class="rdrcb-panel">
            <h2>Structure du cours</h2>
            <div class="rdrcb-toolbar">
                <button type="button" id="rdrcb-add-section" class="button">📁 Ajouter une section</button>
                <button type="button" id="rdrcb-add-lesson" class="button button-primary">📄 Ajouter une leçon</button>
            </div>
            <div id="rdrcb-list" class="rdrcb-list">
                <p class="rdrcb-empty">Chargement…</p>
            </div>
        </div>

        <div>
            <div class="rdrcb-panel" style="margin-bottom:12px;">
                <h2>Résumé</h2>
                <div id="rdrcb-summary"></div>
            </div>
            <div class="rdrcb-panel">
                <h2>Aide</h2>
                <div class="rdrcb-help">
                    <ol>
                        <li>Ajoutez des <strong>sections</strong> (chapitres).</li>
                        <li>Ajoutez des <strong>leçons</strong> dans les sections.</li>
                        <li><strong>Glissez-déposez</strong> pour réordonner.</li>
                        <li>Cliquez <strong>Sauvegarder</strong> ou faites <strong>Ctrl+S</strong>.</li>
                    </ol>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Modal leçons -->
<div id="rdrcb-modal-bg" class="rdrcb-modal-bg">
    <div class="rdrcb-modal">
        <div class="rdrcb-modal-header">
            <h3>Choisir une leçon</h3>
            <button type="button" class="rdrcb-modal-close" id="rdrcb-modal-close">✕</button>
        </div>
        <div class="rdrcb-modal-body">
            <input type="text" class="rdrcb-filter" id="rdrcb-filter" placeholder="Filtrer par titre ou ID (ex: #123)…">
            <div class="rdrcb-lesson-list" id="rdrcb-lesson-list"></div>
        </div>
        <div class="rdrcb-modal-footer">
            <button type="button" class="button" id="rdrcb-modal-cancel">Annuler</button>
            <button type="button" class="button button-primary" id="rdrcb-modal-ok">Ajouter</button>
        </div>
    </div>
</div>

<script>
(function() {

var AJAX    = <?php echo json_encode($ajax_url); ?>;
var NONCE   = <?php echo json_encode($nonce); ?>;
var COURSE  = <?php echo (int)$course_id; ?>;
var EDITBASE = <?php echo json_encode($edit_base); ?>;
var ALL_LESSONS = <?php echo $lessons_js; ?>;
var structure   = <?php echo $structure_js; ?>;

var insertAt = -1;
var dirty    = false;

// ── DOM refs ──────────────────────────────────────────────
var $list    = document.getElementById('rdrcb-list');
var $modal   = document.getElementById('rdrcb-modal-bg');
var $filter  = document.getElementById('rdrcb-filter');
var $llist   = document.getElementById('rdrcb-lesson-list');
var $status  = document.getElementById('rdrcb-status');
var $summary = document.getElementById('rdrcb-summary');

// ── Init ──────────────────────────────────────────────────
structure.forEach(function(item, i) { item.order = i; });
render();

// ── RENDER ────────────────────────────────────────────────
function render() {
    $list.innerHTML = '';

    if (!structure.length) {
        $list.innerHTML = '<p class="rdrcb-empty">Aucune structure. Commencez par ajouter une section.</p>';
        updateSummary();
        return;
    }

    structure.forEach(function(item, idx) {
        var el = makeItem(item, idx);
        $list.appendChild(el);
    });

    initSortable();
    updateSummary();
}

function makeItem(item, idx) {
    var el = document.createElement('div');
    el.className = 'rdrcb-item ' + (item.type === 'section' ? 'rdrcb-item-section' : 'rdrcb-item-lesson' + (item.lesson_status !== 'publish' ? ' draft' : ''));
    el.dataset.idx = idx;

    if (item.type === 'section') {
        el.innerHTML =
            '<span class="rdrcb-handle dashicons dashicons-menu"></span>' +
            '<span class="rdrcb-icon">📁</span>' +
            '<input type="text" value="' + esc(item.title||'') + '" placeholder="Titre de la section…">' +
            '<div class="rdrcb-actions">' +
                '<button type="button" class="button button-small rdrcb-ins" data-idx="' + idx + '">+ Leçon ici</button>' +
                '<button type="button" class="rdrcb-del" data-idx="' + idx + '" title="Supprimer">✕</button>' +
            '</div>';
        el.querySelector('input').addEventListener('input', function() {
            structure[idx].title = this.value;
            markDirty();
        });
    } else {
        var icons = {article:'📄',video:'🎥',tp:'🔧',quiz:'❓'};
        var icon  = icons[item.lesson_type||'article'] || '📄';
        var pub   = item.lesson_status === 'publish';
        el.innerHTML =
            '<span class="rdrcb-handle dashicons dashicons-menu"></span>' +
            '<span class="rdrcb-icon">' + icon + '</span>' +
            '<span class="rdrcb-name">' + esc(item.lesson_title||'Leçon #'+item.lesson_id) + '</span>' +
            '<span class="rdrcb-badge ' + (pub?'pub':'draft') + '">' + (pub ? '✅ Publié' : '📝 Brouillon') + '</span>' +
            '<div class="rdrcb-actions">' +
                '<a href="' + EDITBASE + item.lesson_id + '" target="_blank" class="button button-small">✏️ Éditer</a>' +
                '<button type="button" class="rdrcb-del" data-idx="' + idx + '" title="Retirer">✕</button>' +
            '</div>';
    }
    return el;
}

// ── SORTABLE (natif HTML5) ─────────────────────────────────
function initSortable() {
    var items   = $list.querySelectorAll('.rdrcb-item');
    var dragged = null;

    items.forEach(function(item) {
        item.setAttribute('draggable', 'true');

        item.addEventListener('dragstart', function(e) {
            dragged = item;
            setTimeout(function() { item.style.opacity = '.4'; }, 0);
            e.dataTransfer.effectAllowed = 'move';
        });
        item.addEventListener('dragend', function() {
            item.style.opacity = '';
            document.querySelectorAll('.rdrcb-placeholder').forEach(function(p){p.remove();});
        });
        item.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
        });
        item.addEventListener('drop', function(e) {
            e.preventDefault();
            if (dragged && dragged !== item) {
                var items   = Array.from($list.querySelectorAll('.rdrcb-item'));
                var fromIdx = items.indexOf(dragged);
                var toIdx   = items.indexOf(item);
                var moved   = structure.splice(fromIdx, 1)[0];
                structure.splice(toIdx, 0, moved);
                structure.forEach(function(s,i){ s.order = i; });
                render();
                markDirty();
            }
        });
    });
}

// ── SECTION ───────────────────────────────────────────────
document.getElementById('rdrcb-add-section').addEventListener('click', function() {
    structure.push({type:'section', title:'', order: structure.length});
    render();
    var inputs = $list.querySelectorAll('.rdrcb-item-section input');
    if (inputs.length) inputs[inputs.length-1].focus();
    markDirty();
});

// ── LEÇON — boutons ───────────────────────────────────────
document.getElementById('rdrcb-add-lesson').addEventListener('click', function() {
    insertAt = -1;
    openModal();
});

$list.addEventListener('click', function(e) {
    var btn = e.target.closest('.rdrcb-ins');
    if (btn) { insertAt = parseInt(btn.dataset.idx, 10); openModal(); }
});

// ── SUPPRIMER ─────────────────────────────────────────────
$list.addEventListener('click', function(e) {
    var btn = e.target.closest('.rdrcb-del');
    if (!btn) return;
    var idx  = parseInt(btn.dataset.idx, 10);
    var item = structure[idx];
    if (!item) return;
    var label = item.type === 'section' ? (item.title||'cette section') : (item.lesson_title||'cette leçon');
    if (!confirm('Retirer "' + label + '" de la structure ?')) return;
    structure.splice(idx, 1);
    structure.forEach(function(s,i){ s.order = i; });
    render();
    markDirty();
});

// ── MODAL ─────────────────────────────────────────────────
var CHUNK     = 30;   // leçons affichées par défaut
var filterQ   = '';   // recherche en cours
var usedIds   = [];   // IDs déjà dans la structure

function openModal() {
    usedIds = structure.filter(function(i){return i.type==='lesson';}).map(function(i){return i.lesson_id;});
    filterQ = '';
    $filter.value = '';
    renderLessonList(false);
    $modal.classList.add('open');
    $filter.focus();
}

function filteredLessons() {
    var raw = $filter.value;           // valeur brute, pas de trim agressif
    var q   = raw.trim();

    if (!q) return ALL_LESSONS.slice(); // copie pour ne pas muter

    // --- Recherche par ID : #123 ou juste 123 (que des chiffres) ---
    // On accepte : "123", "#123", "# 123" (espace toléré après #)
    var idMatch = q.replace(/^#\s*/, '').match(/^(\d+)$/);
    if (idMatch) {
        var searchId = parseInt(idMatch[1], 10);
        var exact = ALL_LESSONS.filter(function(l) { return l.id === searchId; });
        if (exact.length) return exact;
        // Pas trouvé exactement → chercher ceux dont l'ID *contient* la chaîne
        var partial = ALL_LESSONS.filter(function(l) {
            return String(l.id).indexOf(idMatch[1]) !== -1;
        });
        return partial;
    }

    // --- Recherche par titre : insensible à la casse + aux accents ---
    var ql = q.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    return ALL_LESSONS.filter(function(l) {
        var title = l.title.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        return title.indexOf(ql) !== -1;
    });
}

function renderLessonList(showAll) {
    $llist.innerHTML = '';
    var lessons = filteredLessons();
    var total   = lessons.length;
    var limit   = showAll ? total : Math.min(CHUNK, total);
    var icons   = {article:'📄', video:'🎥', tp:'🔧', quiz:'❓'};

    for (var i = 0; i < limit; i++) {
        var l      = lessons[i];
        var isUsed = usedIds.indexOf(l.id) !== -1;
        var row    = document.createElement('div');
        row.className = 'rdrcb-lesson-row' + (isUsed ? ' used' : '');
        row.dataset.id     = l.id;
        row.dataset.title  = l.title;
        row.dataset.status = l.status;
        row.dataset.type   = l.type || 'article';
        row.innerHTML =
            '<span style="font-size:15px;flex-shrink:0">' + (icons[l.type] || '📄') + '</span>' +
            '<span class="rdrcb-row-title">' + esc(l.title) + '</span>' +
            '<span class="rdrcb-row-id">#' + l.id + '</span>' +
            '<span class="rdrcb-badge ' + (l.status === 'publish' ? 'pub' : 'draft') + '">' +
                (l.status === 'publish' ? '✅' : '📝') +
            '</span>';
        if (!isUsed) {
            // Utiliser une IIFE pour capturer `row` correctement (pas de closure bug)
            (function(r) {
                r.addEventListener('click', function() {
                    $llist.querySelectorAll('.selected').forEach(function(x) { x.classList.remove('selected'); });
                    r.classList.add('selected');
                });
            }(row));
        }
        $llist.appendChild(row);
    }

    if (!showAll && total > limit) {
        var more = document.createElement('div');
        more.style.cssText = 'padding:10px;text-align:center;border-top:1px solid #eee;';
        more.innerHTML =
            '<button type="button" class="button" id="rdrcb-load-more">' +
            'Afficher les ' + (total - limit) + ' suivantes' +
            '</button>' +
            '<span style="color:#888;font-size:11px;margin-left:8px;">' + total + ' résultat' + (total > 1 ? 's' : '') + '</span>';
        $llist.appendChild(more);
        document.getElementById('rdrcb-load-more').addEventListener('click', function() {
            renderLessonList(true);
        });
    } else if (total === 0) {
        var emptyMsg = filterQ ? 'Aucun résultat pour «\u00a0' + esc($filter.value) + '\u00a0».' : 'Aucune leçon disponible.';
        $llist.innerHTML = '<p style="padding:16px;text-align:center;color:#888;">' + emptyMsg + '</p>';
    } else {
        var info = document.createElement('div');
        info.style.cssText = 'padding:5px 12px;font-size:11px;color:#888;border-top:1px solid #f0f0f0;text-align:right;';
        info.textContent = total + ' leçon' + (total > 1 ? 's' : '');
        if (filterQ) info.textContent += ' pour cette recherche';
        $llist.appendChild(info);
    }
}

// Recherche live : debounce 120ms pour éviter le rendu à chaque frappe
var _filterTimer = null;
$filter.addEventListener('input', function() {
    filterQ = this.value;
    clearTimeout(_filterTimer);
    _filterTimer = setTimeout(function() {
        renderLessonList(filterQ.length > 0);
    }, 120);
});

// Vider la recherche avec Escape
$filter.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && this.value) {
        e.stopPropagation(); // ne pas fermer la modal
        this.value = '';
        filterQ = '';
        renderLessonList(false);
    }
});

function closeModal() {
    $modal.classList.remove('open');
    insertAt = -1; // RESET insertAt à la fermeture — bug fix
}
document.getElementById('rdrcb-modal-close').addEventListener('click', closeModal);
document.getElementById('rdrcb-modal-cancel').addEventListener('click', closeModal);
$modal.addEventListener('click', function(e) { if (e.target === $modal) closeModal(); });

document.getElementById('rdrcb-modal-ok').addEventListener('click', function() {
    var sel = $llist.querySelector('.selected');
    if (!sel) { alert('Sélectionnez une leçon dans la liste.'); return; }
    var newItem = {
        type:          'lesson',
        lesson_id:     parseInt(sel.dataset.id, 10),
        lesson_title:  sel.dataset.title,
        lesson_status: sel.dataset.status,
        lesson_type:   sel.dataset.type || 'article',
        order: 0
    };
    if (insertAt >= 0) structure.splice(insertAt + 1, 0, newItem);
    else structure.push(newItem);
    structure.forEach(function(s, i) { s.order = i; });
    closeModal();
    render();
    markDirty();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && $modal.classList.contains('open') && !$filter.value) closeModal();
    if ((e.ctrlKey || e.metaKey) && e.key === 's') { e.preventDefault(); save(); }
});

// ── SAVE ──────────────────────────────────────────────────
document.getElementById('rdrcb-save').addEventListener('click', save);

function save() {
    setStatus('saving');
    var xhr = new XMLHttpRequest();
    xhr.open('POST', AJAX, true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onload = function() {
        try {
            var res = JSON.parse(xhr.responseText);
            if (res.success) { setStatus('saved'); dirty = false; }
            else             { setStatus('error', res.data); }
        } catch(e) { setStatus('error', 'Reponse invalide'); }
    };
    xhr.onerror = function() { setStatus('error', 'Erreur reseau'); };
    xhr.send(
        'action=rdrc_save_structure' +
        '&nonce=' + encodeURIComponent(NONCE) +
        '&course_id=' + COURSE +
        '&structure=' + encodeURIComponent(JSON.stringify(structure))
    );
}

// Avertir si on quitte avec des changements non sauvegardes
window.addEventListener('beforeunload', function(e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
});

function setStatus(state, msg) {
    if (state === 'saving') { $status.textContent = 'Sauvegarde…'; $status.style.color = '#646970'; }
    if (state === 'saved')  { $status.textContent = '✅ Sauvegardé'; $status.style.color = '#1a6535'; }
    if (state === 'unsaved'){ $status.textContent = '● Non sauvegardé'; $status.style.color = '#d63638'; }
    if (state === 'error')  { $status.textContent = '❌ ' + (msg||'Erreur'); $status.style.color = '#d63638'; }
}

function markDirty() { dirty = true; setStatus('unsaved'); }

function updateSummary() {
    var s = structure.filter(function(i){return i.type==='section';}).length;
    var l = structure.filter(function(i){return i.type==='lesson'; }).length;
    $summary.innerHTML =
        '<div class="rdrcb-info-row"><span>Sections</span><strong>' + s + '</strong></div>' +
        '<div class="rdrcb-info-row"><span>Leçons</span><strong>' + l + '</strong></div>';
}

window.addEventListener('beforeunload', function(e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
});

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

})();
</script>
</body>
</html>
    <?php
    // On stoppe WP pour ne pas ajouter le footer admin
    exit;
}
