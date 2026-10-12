/**
 * RDR Admin Menu Organizer
 *
 * Drag & drop :
 *   - Items source → groupes        : HTML5 drag
 *   - Items dans groupes → reorder  : pointer drag (mousedown)
 *   - Groupes → reorder             : pointer drag (mousedown sur handle)
 *
 * Zone "non groupés" : items placés ici apparaissent dans le menu
 * sans séparateur de groupe (à la fin, comme avant).
 */

( function () {
    'use strict';

    const CFG   = window.RDR_MENU || {};
    const AJAX  = CFG.ajaxUrl || '/wp-admin/admin-ajax.php';
    const NONCE = CFG.nonce   || '';
    const I18N  = CFG.i18n    || {};

    // ── État ─────────────────────────────────────────────────────────────
    let groups      = [];   // [{id, label, items:[slug]}]
    let ungrouped   = [];   // [slug] — items hors groupes (affichés sans séparateur)
    let nativeItems = [];

    // Drag HTML5 (items depuis source vers groupe/ungrouped)
    let h5drag = { slug: null, fromGroup: null }; // fromGroup=null=source, 'ungrouped'=zone libre

    // Drag pointer (reorder groupes + reorder items dans groupe)
    let pdrag = {
        active : false,
        mode   : null,   // 'group' | 'item'
        id     : null,   // groupId ou slug
        groupId: null,   // pour mode=item
        ghost  : null,
        srcEl  : null,
        startY : 0,
    };

    // ── DOM ───────────────────────────────────────────────────────────────
    const elSource     = document.getElementById( 'rdr-amo-source'       );
    const elGroups     = document.getElementById( 'rdr-amo-groups'       );
    const elPreview    = document.getElementById( 'rdr-amo-preview-menu' );
    const elToast      = document.getElementById( 'rdr-amo-toast'        );
    const elUngrouped  = document.getElementById( 'rdr-amo-ungrouped'    );
    const btnSave      = document.getElementById( 'rdr-amo-save'         );
    const btnReset     = document.getElementById( 'rdr-amo-reset'        );
    const btnAdd       = document.getElementById( 'rdr-amo-add-group'    );

    // ── POST ──────────────────────────────────────────────────────────────
    function post( payload ) {
        const body = new URLSearchParams( { nonce: NONCE, ...payload } );
        return fetch( AJAX, {
            method : 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body   : body.toString(),
        } ).then( r => r.json() );
    }

    // ── Init ─────────────────────────────────────────────────────────────
    function init() {
        nativeItems = CFG.nativeMenu || [];

        const saved = CFG.config || {};
        groups    = clone( saved.groups    || [] );
        ungrouped = clone( saved.ungrouped || [] );

        if ( ! groups.length && ! ungrouped.length ) {
            const def = defaultGroups( nativeItems );
            groups    = def.groups;
            ungrouped = def.ungrouped;
        }

        renderAll();

        btnSave.addEventListener(  'click', save    );
        btnReset.addEventListener( 'click', reset   );
        btnAdd.addEventListener(   'click', addGroup );

        // Pointer events globaux
        document.addEventListener( 'mousemove', onPMove );
        document.addEventListener( 'mouseup',   onPUp   );
    }

    // ── Défaut intelligent ────────────────────────────────────────────────
    function defaultGroups( items ) {
        const rules = [
            { id: 'grp_content',    label: 'Contenu',
              match: [ 'edit.php', 'edit.php?post_type=page', 'edit-comments.php', 'upload.php' ] },
            { id: 'grp_appearance', label: 'Apparence & Extensions',
              match: [ 'themes.php', 'plugins.php', 'widgets.php', 'nav-menus.php' ] },
            { id: 'grp_users',      label: 'Utilisateurs & Reglages',
              match: [ 'users.php', 'profile.php', 'options-general.php', 'tools.php' ] },
        ];

        const gMap = {};
        rules.forEach( r => { gMap[ r.id ] = { ...r, items: [] }; } );

        const ung = [];

        items.forEach( item => {
            let placed = false;
            for ( const rule of rules ) {
                if ( rule.match.includes( item.slug ) ) {
                    gMap[ rule.id ].items.push( item.slug );
                    placed = true;
                    break;
                }
            }
            // Non matchés → ungrouped (Tableau de bord, index.php, etc.)
            if ( ! placed ) ung.push( item.slug );
        } );

        return {
            groups   : Object.values( gMap ).filter( g => g.items.length ),
            ungrouped: ung,
        };
    }

    // ── Tous les slugs déjà placés ────────────────────────────────────────
    function allPlaced() {
        const s = new Set( ungrouped );
        groups.forEach( g => g.items.forEach( sl => s.add( sl ) ) );
        return s;
    }

    // ── Rendu global ──────────────────────────────────────────────────────
    function renderAll() {
        renderSource();
        renderGroups();
        renderUngrouped();
        renderPreview();
    }

    // ── Source ────────────────────────────────────────────────────────────
    function renderSource() {
        const placed = allPlaced();
        elSource.innerHTML = '';
        nativeItems.forEach( item => {
            const li = mk( 'li', 'rdr-amo-item' );
            li.dataset.slug = item.slug;
            li.draggable    = true;
            li.appendChild( makeIcon( item.icon ) );
            const lbl = mk( 'span', 'rdr-amo-item__label' );
            lbl.textContent = item.label || item.slug;
            li.appendChild( lbl );
            const h = mk( 'span', 'rdr-amo-item__handle' );
            h.textContent = '::';
            li.appendChild( h );

            if ( placed.has( item.slug ) ) li.classList.add( 'rdr-item--placed' );

            li.addEventListener( 'dragstart', e => {
                h5drag = { slug: item.slug, fromGroup: null };
                li.classList.add( 'rdr-dragging' );
                e.dataTransfer.effectAllowed = 'move';
            } );
            li.addEventListener( 'dragend', () => {
                li.classList.remove( 'rdr-dragging' );
                clearHL();
            } );

            elSource.appendChild( li );
        } );
    }

    // ── Zone ungrouped ────────────────────────────────────────────────────
    function renderUngrouped() {
        if ( ! elUngrouped ) return;
        const ul = elUngrouped.querySelector( '.rdr-amo-ungroup-list' );
        if ( ! ul ) return;
        ul.innerHTML = '';

        if ( ! ungrouped.length ) {
            const empty = mk( 'li', 'rdr-amo-group__empty' );
            empty.textContent = 'Glisser des elements ici';
            ul.appendChild( empty );
        } else {
            ungrouped.forEach( ( slug, idx ) => {
                const native = findItem( slug );
                if ( ! native ) return;
                const li = makeGroupItemEl( native, 'ungrouped', idx );
                ul.appendChild( li );
            } );
        }

        // Drop zone
        ul.addEventListener( 'dragover', e => {
            if ( ! h5drag.slug ) return;
            e.preventDefault();
            ul.classList.add( 'rdr-drag-over' );
        } );
        ul.addEventListener( 'dragleave', e => {
            if ( ! ul.contains( e.relatedTarget ) ) ul.classList.remove( 'rdr-drag-over' );
        } );
        ul.addEventListener( 'drop', e => {
            e.preventDefault();
            ul.classList.remove( 'rdr-drag-over' );
            if ( ! h5drag.slug ) return;
            const { slug, fromGroup } = h5drag;
            removeFromSource( slug, fromGroup );
            if ( ! ungrouped.includes( slug ) ) ungrouped.push( slug );
            renderAll();
        } );
    }

    // ── Groupes ───────────────────────────────────────────────────────────
    function renderGroups() {
        elGroups.innerHTML = '';
        if ( ! groups.length ) {
            elGroups.innerHTML = '<div class="rdr-amo-empty-state">Aucun groupe.</div>';
            return;
        }
        groups.forEach( g => elGroups.appendChild( makeGroupEl( g ) ) );
    }

    // ── Aperçu ────────────────────────────────────────────────────────────
    function renderPreview() {
        elPreview.innerHTML = '';

        groups.forEach( g => {
            if ( ! g.items.length ) return;
            const box = mk( 'div', 'rdr-amo-preview__group' );
            const lbl = mk( 'div', 'rdr-amo-preview__group-label' );
            lbl.textContent = g.label || '(sans nom)';
            const ul = document.createElement( 'ul' );
            g.items.forEach( slug => {
                const li = document.createElement( 'li' );
                li.textContent = ( findItem( slug ) || { label: slug } ).label;
                ul.appendChild( li );
            } );
            box.appendChild( lbl );
            box.appendChild( ul );
            elPreview.appendChild( box );
        } );

        if ( ungrouped.length ) {
            const box = mk( 'div', 'rdr-amo-preview__group' );
            const lbl = mk( 'div', 'rdr-amo-preview__group-label' );
            lbl.textContent = '(non groupes)';
            lbl.style.color = 'var(--rdr-text-muted)';
            const ul = document.createElement( 'ul' );
            ungrouped.forEach( slug => {
                const li = document.createElement( 'li' );
                li.textContent = ( findItem( slug ) || { label: slug } ).label;
                ul.appendChild( li );
            } );
            box.appendChild( lbl );
            box.appendChild( ul );
            elPreview.appendChild( box );
        }
    }

    // ── Construire un item dans un groupe ou ungrouped ────────────────────
    function makeGroupItemEl( itemData, groupId, idx ) {
        const li = mk( 'li', 'rdr-amo-item rdr-amo-item--in-group' );
        li.dataset.slug    = itemData.slug;
        li.dataset.groupId = groupId;

        // Handle pointer drag (reorder)
        const grip = mk( 'span', 'rdr-amo-item__grip dashicons dashicons-move' );
        grip.title = 'Reordonner';
        grip.addEventListener( 'mousedown', e => {
            if ( e.button !== 0 ) return;
            e.preventDefault();
            e.stopPropagation();
            startPDrag( e, 'item', itemData.slug, groupId, li );
        } );

        li.appendChild( grip );
        li.appendChild( makeIcon( itemData.icon ) );

        const lbl = mk( 'span', 'rdr-amo-item__label' );
        lbl.textContent = itemData.label || itemData.slug;
        li.appendChild( lbl );

        const btn = document.createElement( 'button' );
        btn.type        = 'button';
        btn.className   = 'rdr-amo-item__remove';
        btn.title       = 'Retirer';
        btn.textContent = 'x';
        btn.addEventListener( 'click', e => {
            e.stopPropagation();
            removeSlugFromGroup( groupId, itemData.slug );
            renderAll();
        } );
        li.appendChild( btn );

        return li;
    }

    // ── Construire un groupe ──────────────────────────────────────────────
    function makeGroupEl( group ) {
        const wrap = mk( 'div', 'rdr-amo-group' );
        wrap.dataset.groupId = group.id;

        // ── Header avec handle pointer drag ──────────────────────────────
        const header = mk( 'div', 'rdr-amo-group__header' );

        const grip = mk( 'span', 'rdr-amo-group__drag-handle dashicons dashicons-move' );
        grip.title = 'Deplacer le groupe';
        // Pointer drag sur le grip uniquement
        grip.addEventListener( 'mousedown', e => {
            if ( e.button !== 0 ) return;
            e.preventDefault();
            e.stopPropagation();
            startPDrag( e, 'group', group.id, null, wrap );
        } );

        const nameEl = document.createElement( 'input' );
        nameEl.type        = 'text';
        nameEl.className   = 'rdr-amo-group__name';
        nameEl.value       = group.label;
        nameEl.placeholder = I18N.groupName || 'Nom du groupe';
        nameEl.addEventListener( 'input', () => {
            const g = groups.find( g => g.id === group.id );
            if ( g ) { g.label = nameEl.value; renderPreview(); }
        } );

        const del = document.createElement( 'button' );
        del.type      = 'button';
        del.className = 'rdr-amo-group__delete';
        del.title     = 'Supprimer';
        del.innerHTML = '<span class="dashicons dashicons-trash"></span>';
        del.addEventListener( 'click', () => {
            if ( ! confirm( 'Supprimer ce groupe ?' ) ) return;
            groups = groups.filter( g => g.id !== group.id );
            renderAll();
        } );

        header.appendChild( grip );
        header.appendChild( nameEl );
        header.appendChild( del );

        // ── Liste items ───────────────────────────────────────────────────
        const ul = document.createElement( 'ul' );
        ul.className       = 'rdr-amo-group__items';
        ul.dataset.groupId = group.id;

        const current = groups.find( g => g.id === group.id );
        const items   = current ? current.items : [];

        if ( ! items.length ) {
            const empty = mk( 'li', 'rdr-amo-group__empty' );
            empty.textContent = I18N.noItems || 'Glisser des elements ici';
            ul.appendChild( empty );
        } else {
            items.forEach( ( slug, idx ) => {
                const native = findItem( slug );
                if ( ! native ) return;
                ul.appendChild( makeGroupItemEl( native, group.id, idx ) );
            } );
        }

        // Drop zone HTML5 (depuis source)
        ul.addEventListener( 'dragover', e => {
            if ( ! h5drag.slug ) return;
            e.preventDefault();
            ul.classList.add( 'rdr-drag-over' );
        } );
        ul.addEventListener( 'dragleave', e => {
            if ( ! ul.contains( e.relatedTarget ) ) ul.classList.remove( 'rdr-drag-over' );
        } );
        ul.addEventListener( 'drop', e => {
            e.preventDefault();
            ul.classList.remove( 'rdr-drag-over' );
            if ( ! h5drag.slug ) return;
            const { slug, fromGroup } = h5drag;
            removeFromSource( slug, fromGroup );
            const g = groups.find( g => g.id === group.id );
            if ( g && ! g.items.includes( slug ) ) g.items.push( slug );
            renderAll();
        } );

        wrap.appendChild( header );
        wrap.appendChild( ul );
        return wrap;
    }

    // ── Pointer drag : start ──────────────────────────────────────────────
    function startPDrag( e, mode, id, groupId, srcEl ) {
        const rect  = srcEl.getBoundingClientRect();
        const ghost = srcEl.cloneNode( true );

        ghost.style.cssText = [
            'position:fixed',
            'pointer-events:none',
            'z-index:999999',
            'width:' + rect.width + 'px',
            'left:' + rect.left + 'px',
            'top:' + rect.top + 'px',
            'opacity:0.8',
            'box-shadow:0 8px 24px rgba(0,0,0,0.5)',
            'border:1px solid var(--rdr-accent)',
            'border-radius:8px',
            'margin:0',
            'background:var(--rdr-bg-card)',
        ].join( ';' );
        document.body.appendChild( ghost );
        srcEl.style.opacity = '0.3';

        pdrag = {
            active : true,
            mode   : mode,
            id     : id,
            groupId: groupId,
            ghost  : ghost,
            srcEl  : srcEl,
            startY : e.clientY,
            offsetY: e.clientY - rect.top,
        };
    }

    // ── Pointer drag : move ───────────────────────────────────────────────
    function onPMove( e ) {
        if ( ! pdrag.active ) return;
        pdrag.ghost.style.top  = ( e.clientY - pdrag.offsetY ) + 'px';
        pdrag.ghost.style.left = e.clientX - pdrag.ghost.offsetWidth / 2 + 'px';

        // Highlight la cible potentielle
        pdrag.ghost.style.display = 'none';
        const under = document.elementFromPoint( e.clientX, e.clientY );
        pdrag.ghost.style.display = '';
        clearHL();

        if ( pdrag.mode === 'group' ) {
            const targetWrap = under && under.closest( '.rdr-amo-group' );
            if ( targetWrap && targetWrap.dataset.groupId !== pdrag.id ) {
                targetWrap.classList.add( 'rdr-group--drag-target' );
            }
        } else if ( pdrag.mode === 'item' ) {
            const targetLi = under && under.closest( '.rdr-amo-item--in-group' );
            if ( targetLi && targetLi.dataset.slug !== pdrag.id ) {
                targetLi.classList.add( 'rdr-item--drop-target' );
            }
        }
    }

    // ── Pointer drag : end ────────────────────────────────────────────────
    function onPUp( e ) {
        if ( ! pdrag.active ) return;

        pdrag.ghost.style.display = 'none';
        const under = document.elementFromPoint( e.clientX, e.clientY );
        pdrag.ghost.remove();
        pdrag.srcEl.style.opacity = '';
        clearHL();

        if ( pdrag.mode === 'group' ) {
            const targetWrap = under && under.closest( '.rdr-amo-group' );
            if ( targetWrap ) {
                const toId   = targetWrap.dataset.groupId;
                const fromIdx = groups.findIndex( g => g.id === pdrag.id );
                const toIdx   = groups.findIndex( g => g.id === toId );
                if ( fromIdx !== -1 && toIdx !== -1 && fromIdx !== toIdx ) {
                    const moved = groups.splice( fromIdx, 1 )[ 0 ];
                    groups.splice( toIdx, 0, moved );
                    renderGroups();
                    renderPreview();
                }
            }
        } else if ( pdrag.mode === 'item' ) {
            const targetLi = under && under.closest( '.rdr-amo-item--in-group' );
            if ( targetLi ) {
                const tSlug   = targetLi.dataset.slug;
                const tGroup  = targetLi.dataset.groupId;
                const srcSlug = pdrag.id;
                const srcGroup= pdrag.groupId;

                if ( tGroup === srcGroup && tSlug !== srcSlug ) {
                    // Reorder dans le même groupe ou ungrouped
                    const arr = tGroup === 'ungrouped'
                        ? ungrouped
                        : ( groups.find( g => g.id === tGroup ) || {} ).items;

                    if ( arr ) {
                        const fi = arr.indexOf( srcSlug );
                        const ti = arr.indexOf( tSlug );
                        if ( fi !== -1 && ti !== -1 ) {
                            arr.splice( fi, 1 );
                            arr.splice( ti, 0, srcSlug );
                            renderAll();
                        }
                    }
                }
            }
        }

        pdrag = { active: false, mode: null, id: null, groupId: null, ghost: null, srcEl: null };
    }

    // ── Helpers état ──────────────────────────────────────────────────────
    function removeFromSource( slug, fromGroup ) {
        if ( fromGroup === null ) return; // vient du panel source — rien à retirer
        if ( fromGroup === 'ungrouped' ) {
            ungrouped = ungrouped.filter( s => s !== slug );
        } else {
            removeSlugFromGroup( fromGroup, slug );
        }
    }

    function removeSlugFromGroup( groupId, slug ) {
        if ( groupId === 'ungrouped' ) {
            ungrouped = ungrouped.filter( s => s !== slug );
            return;
        }
        const g = groups.find( g => g.id === groupId );
        if ( g ) g.items = g.items.filter( s => s !== slug );
    }

    // ── Save ─────────────────────────────────────────────────────────────
    function save() {
        btnSave.disabled    = true;
        btnSave.textContent = I18N.saving || 'Enregistrement...';

        post( {
            action: 'rdr_amo_save',
            config: JSON.stringify( { groups: groups, ungrouped: ungrouped } ),
        } )
        .then( data => {
            if ( data.success ) toast( I18N.saved || 'OK', 'success' );
            else { toast( ( data.data && data.data.message ) || I18N.error || 'Erreur', 'error' ); console.error( data ); }
        } )
        .catch( err => { toast( I18N.error || 'Erreur', 'error' ); console.error( err ); } )
        .finally( () => { btnSave.disabled = false; btnSave.textContent = 'Enregistrer'; } );
    }

    // ── Reset ─────────────────────────────────────────────────────────────
    function reset() {
        if ( ! confirm( I18N.resetConfirm || 'Reinitialiser ?' ) ) return;
        post( { action: 'rdr_amo_reset' } )
        .then( data => {
            if ( data.success ) {
                const def = defaultGroups( nativeItems );
                groups    = def.groups;
                ungrouped = def.ungrouped;
                renderAll();
                toast( I18N.resetDone || 'OK', 'success' );
            } else toast( I18N.error || 'Erreur', 'error' );
        } )
        .catch( err => { toast( I18N.error || 'Erreur', 'error' ); console.error( err ); } );
    }

    function addGroup() {
        const label = prompt( I18N.groupName || 'Nom du groupe', 'Nouveau groupe' );
        if ( label === null ) return;
        groups.push( { id: 'grp_' + Date.now(), label: label.trim() || 'Groupe', items: [] } );
        renderGroups();
        renderPreview();
    }

    // ── Toast ─────────────────────────────────────────────────────────────
    let toastTimer;
    function toast( msg, type ) {
        elToast.textContent = msg;
        elToast.className   = 'rdr-amo-toast rdr-toast--visible rdr-toast--' + ( type || 'success' );
        clearTimeout( toastTimer );
        toastTimer = setTimeout( () => { elToast.className = 'rdr-amo-toast'; }, 3000 );
    }

    // ── Helpers UI ────────────────────────────────────────────────────────
    function findItem( slug ) { return nativeItems.find( i => i.slug === slug ) || null; }
    function clearHL() {
        document.querySelectorAll( '.rdr-drag-over,.rdr-group--drag-target,.rdr-item--drop-target' )
            .forEach( n => n.classList.remove( 'rdr-drag-over', 'rdr-group--drag-target', 'rdr-item--drop-target' ) );
    }
    function mk( tag, cls ) { const e = document.createElement( tag ); if ( cls ) e.className = cls; return e; }
    function makeIcon( ic ) {
        const s = mk( 'span', 'rdr-amo-item__icon' );
        s.innerHTML = ( ic && ic.startsWith( 'dashicons-' ) )
            ? '<span class="dashicons ' + String(ic).replace(/"/g,'') + '"></span>'
            : '<span class="dashicons dashicons-admin-generic"></span>';
        return s;
    }
    function clone( o ) { return JSON.parse( JSON.stringify( o ) ); }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();
