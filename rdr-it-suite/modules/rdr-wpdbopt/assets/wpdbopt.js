/**
 * WP DB Optimizer — wpdbopt.js
 * Vanilla JS, zéro jQuery.
 * Nonce : RDR_WPDBOPT.nonce (injecté par wp_localize_script côté PHP)
 */

/* ---------------------------------------------------------------
   Utilitaires globaux
--------------------------------------------------------------- */
const wpdbopt = {
    /** Appel AJAX générique */
    ajax( action, data = {} ) {
        const body = new URLSearchParams( {
            action,
            nonce: RDR_WPDBOPT.nonce,
            ...data,
        } );
        return fetch( RDR_WPDBOPT.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        } ).then( r => {
            if ( ! r.ok ) throw new Error( `HTTP ${r.status}` );
            return r.json();
        } ).then( json => {
            if ( ! json.success ) throw new Error( json.data?.message || RDR_WPDBOPT.i18n.error );
            return json.data;
        } );
    },

    /** Toast notification */
    toast( msg, type = 'info', duration = 3500 ) {
        const el = document.getElementById( 'rdr-wpdbopt-toast' );
        if ( ! el ) return;
        el.textContent = msg;
        el.className = `rdr-wpdbopt-toast rdr-wpdbopt-toast--${type} rdr-wpdbopt-toast--visible`;
        clearTimeout( wpdbopt._toastTimer );
        wpdbopt._toastTimer = setTimeout( () => {
            el.classList.remove( 'rdr-wpdbopt-toast--visible' );
        }, duration );
    },

    /** Téléchargement d'un fichier texte côté client */
    downloadText( content, filename, mime = 'application/json' ) {
        const blob = new Blob( [ content ], { type: mime } );
        const url  = URL.createObjectURL( blob );
        const a    = document.createElement( 'a' );
        a.href     = url;
        a.download = filename;
        document.body.appendChild( a );
        a.click();
        a.remove();
        URL.revokeObjectURL( url );
    },

    /** Pagination générique */
    buildPagination( container, total, perPage, currentPage, onPageChange ) {
        container.innerHTML = '';
        const totalPages = Math.ceil( total / perPage );
        if ( totalPages <= 1 ) return;

        const info = document.createElement( 'span' );
        info.textContent = `${total} résultat(s) — page ${currentPage}/${totalPages}`;
        container.appendChild( info );

        const prev = document.createElement( 'button' );
        prev.className = 'rdr-wpdbopt-page-btn';
        prev.textContent = '← Préc.';
        prev.disabled = currentPage <= 1;
        prev.addEventListener( 'click', () => onPageChange( currentPage - 1 ) );
        container.appendChild( prev );

        /* Pages proches */
        const start = Math.max( 1, currentPage - 2 );
        const end   = Math.min( totalPages, currentPage + 2 );
        for ( let p = start; p <= end; p++ ) {
            const btn = document.createElement( 'button' );
            btn.className = 'rdr-wpdbopt-page-btn' + ( p === currentPage ? ' rdr-wpdbopt-page-btn--active' : '' );
            btn.textContent = p;
            btn.addEventListener( 'click', () => onPageChange( p ) );
            container.appendChild( btn );
        }

        const next = document.createElement( 'button' );
        next.className = 'rdr-wpdbopt-page-btn';
        next.textContent = 'Suiv. →';
        next.disabled = currentPage >= totalPages;
        next.addEventListener( 'click', () => onPageChange( currentPage + 1 ) );
        container.appendChild( next );
    },
};

/* ===============================================================
   ONGLETS
=============================================================== */
function initTabs() {
    const tabs   = document.querySelectorAll( '.rdr-wpdbopt-tab' );
    const panels = document.querySelectorAll( '.rdr-wpdbopt-tabpanel' );

    tabs.forEach( tab => {
        tab.addEventListener( 'click', () => {
            tabs.forEach( t => {
                t.classList.remove( 'rdr-wpdbopt-tab--active' );
                t.setAttribute( 'aria-selected', 'false' );
            } );
            panels.forEach( p => {
                p.hidden = true;
                p.classList.remove( 'rdr-wpdbopt-tabpanel--active' );
            } );

            tab.classList.add( 'rdr-wpdbopt-tab--active' );
            tab.setAttribute( 'aria-selected', 'true' );
            const target = document.getElementById( 'tab-' + tab.dataset.tab );
            if ( target ) {
                target.hidden = false;
                target.classList.add( 'rdr-wpdbopt-tabpanel--active' );
            }
        } );
    } );
}

/* ===============================================================
   ONGLET 1 — Options
=============================================================== */
const optState = { page: 1, perPage: 50, total: 0, selected: new Set() };

function initOptionsTab() {
    loadOptionsStats();
    loadOptions();

    document.getElementById( 'wpdbopt-wpdbopt-search-btn' )
        .addEventListener( 'click', () => { optState.page = 1; optState.selected.clear(); loadOptions(); } );

    document.getElementById( 'wpdbopt-opt-search' )
        .addEventListener( 'keydown', e => { if ( e.key === 'Enter' ) { optState.page = 1; loadOptions(); } } );

    document.getElementById( 'wpdbopt-autoload-filter' )
        .addEventListener( 'change', () => { optState.page = 1; optState.selected.clear(); loadOptions(); } );

    /* Export téléchargement */
    document.getElementById( 'wpdbopt-export-json-dl' ).addEventListener( 'click', () => exportOptions( 'json', false ) );
    document.getElementById( 'wpdbopt-export-csv-dl'  ).addEventListener( 'click', () => exportOptions( 'csv',  false ) );
    /* Export serveur */
    document.getElementById( 'wpdbopt-export-json-save' ).addEventListener( 'click', () => exportOptions( 'json', true ) );
    document.getElementById( 'wpdbopt-export-csv-save'  ).addEventListener( 'click', () => exportOptions( 'csv',  true ) );

    /* Bulk */
    document.getElementById( 'wpdbopt-check-all' ).addEventListener( 'change', e => toggleAllOpt( e.target.checked ) );
    document.getElementById( 'wpdbwpdbopt-bulk-autoload-on'  ).addEventListener( 'click', () => bulkAutoload( 'yes' ) );
    document.getElementById( 'wpdbwpdbopt-bulk-autoload-off' ).addEventListener( 'click', () => bulkAutoload( 'no' ) );
    document.getElementById( 'wpdbwpdbopt-bulk-delete'       ).addEventListener( 'click', () => bulkDeleteOptions() );
}

function loadOptionsStats() {
    wpdbopt.ajax( 'rdr_wpdbopt_get_options_stats' ).then( data => {
        document.getElementById( 'stat-total-count'   ).textContent = data.total_count;
        document.getElementById( 'stat-total-size'    ).textContent = data.total_size;
        document.getElementById( 'stat-autoload-yes'  ).textContent = data.autoload_yes;
        document.getElementById( 'stat-autoload-no'   ).textContent = data.autoload_no;
        document.getElementById( 'stat-autoload-size' ).textContent = data.autoload_size;
    } ).catch( err => wpdbopt.toast( err.message, 'error' ) );
}

function loadOptions() {
    const tbody  = document.getElementById( 'wpdbopt-tbody' );
    const search = document.getElementById( 'wpdbopt-opt-search' ).value.trim();
    const autoload = document.getElementById( 'wpdbopt-autoload-filter' ).value;

    tbody.innerHTML = `<tr><td colspan="6" class="rdr-wpdbopt-empty">${ RDR_WPDBOPT.i18n.loading }</td></tr>`;

    wpdbopt.ajax( 'rdr_wpdbopt_get_options_list', {
        page: optState.page,
        per_page: optState.perPage,
        search,
        autoload,
    } ).then( data => {
        optState.total = data.total;
        renderOptionsRows( data.rows );
        wpdbopt.buildPagination(
            document.getElementById( 'wpdbopt-pagination' ),
            data.total, optState.perPage, optState.page,
            p => { optState.page = p; loadOptions(); }
        );
        updateBulkBar( 'opt' );
    } ).catch( err => {
        tbody.innerHTML = `<tr><td colspan="6" class="rdr-wpdbopt-empty" style="color:#fc8181">${ err.message }</td></tr>`;
    } );
}

function renderOptionsRows( rows ) {
    const tbody = document.getElementById( 'wpdbopt-tbody' );
    if ( ! rows.length ) {
        tbody.innerHTML = `<tr><td colspan="6" class="rdr-wpdbopt-empty">Aucune option trouvée.</td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map( row => {
        const isProtected = row.is_protected;
        const alBool      = row.autoload_bool;
        const cbDisabled  = isProtected ? 'disabled' : '';

        return `<tr data-name="${ escAttr( row.option_name ) }" class="${ optState.selected.has( row.option_name ) ? 'is-selected' : '' }">
            <td><input type="checkbox" class="wpdbopt-row-check" value="${ escAttr( row.option_name ) }" ${ cbDisabled } ${ optState.selected.has( row.option_name ) ? 'checked' : '' }></td>
            <td><span class="rdr-wpdbopt-opt-name">${ escHtml( row.option_name ) }</span></td>
            <td>
                ${ isProtected
                    ? `<span class="rdr-wpdbopt-badge rdr-wpdbopt-badge--protected" title="Option protégée">🔒</span>`
                    : `<span class="rdr-wpdbopt-badge ${ alBool ? 'rdr-wpdbopt-badge--on' : 'rdr-wpdbopt-badge--off' }"
                         data-action="toggle-autoload"
                         data-name="${ escAttr( row.option_name ) }"
                         data-autoload="${ alBool ? 'yes' : 'no' }"
                         title="Cliquer pour basculer">${ alBool ? 'ON' : 'OFF' }</span>`
                }
            </td>
            <td>${ escHtml( row.size_human ) }</td>
            <td><span class="rdr-wpdbopt-value-preview" title="${ escAttr( row.value_preview ) }">${ escHtml( row.value_preview ) }</span></td>
            <td class="rdr-wpdbopt-actions">
                ${ isProtected
                    ? `<span class="rdr-wpdbopt-badge rdr-wpdbopt-badge--protected" title="Option protégée — non supprimable">🛡️</span>`
                    : `<button class="rdr-wpdbopt-btn-icon rdr-wpdbopt-btn-icon--del" data-action="delete-option" data-name="${ escAttr( row.option_name ) }" title="Supprimer">🗑️</button>`
                }
            </td>
        </tr>`;
    } ).join( '' );

    /* Délégation d'événements */
    tbody.querySelectorAll( 'input[type="checkbox"].wpdbopt-row-check' ).forEach( cb => {
        cb.addEventListener( 'change', () => {
            if ( cb.checked ) optState.selected.add( cb.value );
            else              optState.selected.delete( cb.value );
            cb.closest( 'tr' ).classList.toggle( 'is-selected', cb.checked );
            updateBulkBar( 'opt' );
        } );
    } );

    tbody.querySelectorAll( '[data-action="toggle-autoload"]' ).forEach( el => {
        el.addEventListener( 'click', () => toggleAutoload( el ) );
    } );

    tbody.querySelectorAll( '[data-action="delete-option"]' ).forEach( el => {
        el.addEventListener( 'click', () => deleteOption( el.dataset.name ) );
    } );
}

function toggleAutoload( el ) {
    if ( ! confirm( RDR_WPDBOPT.i18n.confirm_autoload ) ) return;
    const name       = el.dataset.name;
    const current    = el.dataset.autoload; // 'yes' | 'no'
    const newAutoload = current === 'yes' ? 'no' : 'yes';

    wpdbopt.ajax( 'rdr_wpdbopt_toggle_autoload', { option_name: name, autoload: newAutoload } )
        .then( () => {
            wpdbopt.toast( `Autoload de « ${ name } » → ${ newAutoload.toUpperCase() }`, 'success' );
            loadOptions();
            loadOptionsStats();
        } )
        .catch( err => wpdbopt.toast( err.message, 'error' ) );
}

function deleteOption( name ) {
    if ( ! confirm( RDR_WPDBOPT.i18n.confirm_delete ) ) return;
    wpdbopt.ajax( 'rdr_wpdbopt_delete_option', { option_name: name } )
        .then( () => {
            wpdbopt.toast( `Option « ${ name } » supprimée.`, 'success' );
            optState.selected.delete( name );
            loadOptions();
            loadOptionsStats();
        } )
        .catch( err => wpdbopt.toast( err.message, 'error' ) );
}

function toggleAllOpt( checked ) {
    document.querySelectorAll( '.wpdbopt-row-check:not(:disabled)' ).forEach( cb => {
        cb.checked = checked;
        if ( checked ) optState.selected.add( cb.value );
        else           optState.selected.delete( cb.value );
        cb.closest( 'tr' ).classList.toggle( 'is-selected', checked );
    } );
    updateBulkBar( 'opt' );
}

function bulkAutoload( value ) {
    if ( ! optState.selected.size ) return;
    const names = [ ...optState.selected ];
    Promise.all( names.map( n => wpdbopt.ajax( 'rdr_wpdbopt_toggle_autoload', { option_name: n, autoload: value } ) ) )
        .then( () => {
            wpdbopt.toast( `Autoload → ${ value.toUpperCase() } pour ${ names.length } option(s).`, 'success' );
            optState.selected.clear();
            loadOptions();
            loadOptionsStats();
        } )
        .catch( err => wpdbopt.toast( err.message, 'error' ) );
}

function bulkDeleteOptions() {
    if ( ! optState.selected.size ) return;
    if ( ! confirm( `Supprimer ${ optState.selected.size } option(s) ? ${ RDR_WPDBOPT.i18n.confirm_delete }` ) ) return;
    const names = [ ...optState.selected ];
    Promise.all( names.map( n => wpdbopt.ajax( 'rdr_wpdbopt_delete_option', { option_name: n } ) ) )
        .then( () => {
            wpdbopt.toast( `${ names.length } option(s) supprimée(s).`, 'success' );
            optState.selected.clear();
            loadOptions();
            loadOptionsStats();
        } )
        .catch( err => wpdbopt.toast( err.message, 'error' ) );
}

function exportOptions( format, saveServer ) {
    const autoload = document.getElementById( 'wpdbopt-autoload-filter' ).value;
    const search   = document.getElementById( 'wpdbopt-opt-search' ).value.trim();

    if ( saveServer ) {
        wpdbopt.ajax( 'rdr_wpdbopt_save_export_file', { format, autoload } )
            .then( d => wpdbopt.toast( `Exporté → ${ d.filename } (${ d.size })`, 'success', 6000 ) )
            .catch( err => wpdbopt.toast( err.message, 'error' ) );
    } else {
        wpdbopt.ajax( 'rdr_wpdbopt_export_options', { format, autoload, search } )
            .then( d => {
                const mime = format === 'csv' ? 'text/csv' : 'application/json';
                wpdbopt.downloadText( d.data, `options-export.${ format }`, mime );
                wpdbopt.toast( RDR_WPDBOPT.i18n.exported, 'success' );
            } )
            .catch( err => wpdbopt.toast( err.message, 'error' ) );
    }
}

/* ===============================================================
   ONGLET 2 — Métas orphelines
=============================================================== */
const orphanState = { page: 1, perPage: 50, total: 0, selected: new Set() };

function initOrphansTab() {
    loadMetaStats();
    document.getElementById( 'orphan-load-btn' ).addEventListener( 'click', () => {
        orphanState.page = 1; orphanState.selected.clear(); loadOrphans();
    } );
    document.getElementById( 'orphan-delete-all-btn' ).addEventListener( 'click', () => deleteAllOrphans() );
    document.getElementById( 'orphan-check-all' ).addEventListener( 'change', e => toggleAllOrphan( e.target.checked ) );
    document.getElementById( 'orphan-bulk-delete' ).addEventListener( 'click', () => bulkDeleteOrphans() );
}

function loadMetaStats() {
    wpdbopt.ajax( 'rdr_wpdbopt_get_meta_stats' ).then( data => {
        [ 'post', 'term', 'user' ].forEach( t => {
            document.getElementById( `ms-${ t }-total`   ).textContent = data[ t ].total;
            document.getElementById( `ms-${ t }-orphans` ).textContent = data[ t ].orphans;
            document.getElementById( `ms-${ t }-keys`    ).textContent = data[ t ].keys_count;
            document.getElementById( `ms-${ t }-size`    ).textContent = data[ t ].size;
        } );
    } ).catch( err => wpdbopt.toast( err.message, 'error' ) );
}

function loadOrphans() {
    const type  = document.getElementById( 'orphan-type' ).value;
    const tbody = document.getElementById( 'orphan-tbody' );
    tbody.innerHTML = `<tr><td colspan="6" class="rdr-wpdbopt-empty">${ RDR_WPDBOPT.i18n.loading }</td></tr>`;

    wpdbopt.ajax( 'rdr_wpdbopt_get_orphan_meta', {
        meta_type: type,
        page: orphanState.page,
        per_page: orphanState.perPage,
    } ).then( data => {
        orphanState.total = data.total;
        renderOrphanRows( data.rows );
        wpdbopt.buildPagination(
            document.getElementById( 'orphan-pagination' ),
            data.total, orphanState.perPage, orphanState.page,
            p => { orphanState.page = p; loadOrphans(); }
        );
        updateBulkBar( 'orphan' );
    } ).catch( err => {
        tbody.innerHTML = `<tr><td colspan="6" class="rdr-wpdbopt-empty" style="color:#fc8181">${ err.message }</td></tr>`;
    } );
}

function renderOrphanRows( rows ) {
    const tbody = document.getElementById( 'orphan-tbody' );
    if ( ! rows.length ) {
        tbody.innerHTML = `<tr><td colspan="6" class="rdr-wpdbopt-empty">✅ Aucune méta orpheline trouvée.</td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map( row => `
        <tr data-id="${ row.meta_id }" class="${ orphanState.selected.has( row.meta_id ) ? 'is-selected' : '' }">
            <td><input type="checkbox" class="orphan-row-check" value="${ row.meta_id }" ${ orphanState.selected.has( row.meta_id ) ? 'checked' : '' }></td>
            <td>${ escHtml( row.meta_id ) }</td>
            <td>${ escHtml( row.object_id ) }</td>
            <td><span class="rdr-wpdbopt-meta-key">${ escHtml( row.meta_key ) }</span></td>
            <td><span class="rdr-wpdbopt-value-preview" title="${ escAttr( row.meta_value_preview ) }">${ escHtml( row.meta_value_preview ) }</span></td>
            <td>
                <button class="rdr-wpdbopt-btn-icon rdr-wpdbopt-btn-icon--del" data-action="delete-orphan" data-id="${ row.meta_id }" title="Supprimer">🗑️</button>
            </td>
        </tr>
    ` ).join( '' );

    tbody.querySelectorAll( 'input[type="checkbox"].orphan-row-check' ).forEach( cb => {
        cb.addEventListener( 'change', () => {
            if ( cb.checked ) orphanState.selected.add( cb.value );
            else              orphanState.selected.delete( cb.value );
            cb.closest( 'tr' ).classList.toggle( 'is-selected', cb.checked );
            updateBulkBar( 'orphan' );
        } );
    } );

    tbody.querySelectorAll( '[data-action="delete-orphan"]' ).forEach( el => {
        el.addEventListener( 'click', () => deleteOrphanById( el.dataset.id ) );
    } );
}

function deleteOrphanById( id ) {
    if ( ! confirm( RDR_WPDBOPT.i18n.confirm_delete ) ) return;
    const type = document.getElementById( 'orphan-type' ).value;
    wpdbopt.ajax( 'rdr_wpdbopt_delete_orphan_meta', { meta_type: type, meta_ids: [ id ] } )
        .then( d => {
            wpdbopt.toast( `${ d.deleted } méta supprimée.`, 'success' );
            orphanState.selected.delete( id );
            loadOrphans();
            loadMetaStats();
        } )
        .catch( err => wpdbopt.toast( err.message, 'error' ) );
}

function deleteAllOrphans() {
    const type = document.getElementById( 'orphan-type' ).value;
    if ( ! confirm( `Supprimer TOUTES les métas orphelines de type « ${ type } » ? ${ RDR_WPDBOPT.i18n.confirm_delete }` ) ) return;
    wpdbopt.ajax( 'rdr_wpdbopt_delete_orphan_meta', { meta_type: type, delete_all: '1' } )
        .then( d => {
            wpdbopt.toast( `${ d.deleted } méta(s) orpheline(s) supprimée(s).`, 'success' );
            orphanState.selected.clear();
            loadOrphans();
            loadMetaStats();
        } )
        .catch( err => wpdbopt.toast( err.message, 'error' ) );
}

function toggleAllOrphan( checked ) {
    document.querySelectorAll( '.orphan-row-check' ).forEach( cb => {
        cb.checked = checked;
        if ( checked ) orphanState.selected.add( cb.value );
        else           orphanState.selected.delete( cb.value );
        cb.closest( 'tr' ).classList.toggle( 'is-selected', checked );
    } );
    updateBulkBar( 'orphan' );
}

function bulkDeleteOrphans() {
    if ( ! orphanState.selected.size ) return;
    if ( ! confirm( `Supprimer ${ orphanState.selected.size } méta(s) ? ${ RDR_WPDBOPT.i18n.confirm_delete }` ) ) return;
    const type = document.getElementById( 'orphan-type' ).value;
    wpdbopt.ajax( 'rdr_wpdbopt_delete_orphan_meta', {
        meta_type: type,
        meta_ids: [ ...orphanState.selected ],
    } ).then( d => {
        wpdbopt.toast( `${ d.deleted } méta(s) supprimée(s).`, 'success' );
        orphanState.selected.clear();
        loadOrphans();
        loadMetaStats();
    } ).catch( err => wpdbopt.toast( err.message, 'error' ) );
}

/* ===============================================================
   ONGLET 3 — Métas par clé
=============================================================== */
const keyState = { page: 1, perPage: 50, total: 0 };

function initKeysTab() {
    document.getElementById( 'key-load-btn' ).addEventListener( 'click', () => {
        keyState.page = 1; loadMetaByKey();
    } );
    document.getElementById( 'key-search' ).addEventListener( 'keydown', e => {
        if ( e.key === 'Enter' ) { keyState.page = 1; loadMetaByKey(); }
    } );
}

function loadMetaByKey() {
    const type   = document.getElementById( 'key-type' ).value;
    const search = document.getElementById( 'key-search' ).value.trim();
    const tbody  = document.getElementById( 'key-tbody' );
    tbody.innerHTML = `<tr><td colspan="5" class="rdr-wpdbopt-empty">${ RDR_WPDBOPT.i18n.loading }</td></tr>`;

    wpdbopt.ajax( 'rdr_wpdbopt_get_meta_by_key', {
        meta_type: type,
        search,
        page: keyState.page,
        per_page: keyState.perPage,
    } ).then( data => {
        keyState.total = data.total;
        renderKeyRows( data.rows, type );
        wpdbopt.buildPagination(
            document.getElementById( 'key-pagination' ),
            data.total, keyState.perPage, keyState.page,
            p => { keyState.page = p; loadMetaByKey(); }
        );
    } ).catch( err => {
        tbody.innerHTML = `<tr><td colspan="5" class="rdr-wpdbopt-empty" style="color:#fc8181">${ err.message }</td></tr>`;
    } );
}

function renderKeyRows( rows, type ) {
    const tbody = document.getElementById( 'key-tbody' );
    if ( ! rows.length ) {
        tbody.innerHTML = `<tr><td colspan="5" class="rdr-wpdbopt-empty">Aucune clé trouvée.</td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map( row => `
        <tr>
            <td><span class="rdr-wpdbopt-meta-key">${ escHtml( row.meta_key ) }</span></td>
            <td>${ escHtml( row.count ) }</td>
            <td>${ escHtml( row.size_human ) }</td>
            <td>
                ${ row.is_protected
                    ? `<span class="rdr-wpdbopt-badge rdr-wpdbopt-badge--protected">🛡️ Protégée</span>`
                    : `<span class="rdr-wpdbopt-badge rdr-wpdbopt-badge--off">Non</span>`
                }
            </td>
            <td>
                ${ row.is_protected
                    ? `<span class="rdr-wpdbopt-badge rdr-wpdbopt-badge--protected" title="Clé protégée">🛡️</span>`
                    : `<button class="rdr-wpdbopt-btn-icon rdr-wpdbopt-btn-icon--del"
                              data-action="delete-key"
                              data-key="${ escAttr( row.meta_key ) }"
                              data-type="${ escAttr( type ) }"
                              data-count="${ row.count }"
                              title="Supprimer toutes les métas avec cette clé">🗑️ (${ row.count })</button>`
                }
            </td>
        </tr>
    ` ).join( '' );

    tbody.querySelectorAll( '[data-action="delete-key"]' ).forEach( el => {
        el.addEventListener( 'click', () => {
            const key   = el.dataset.key;
            const count = el.dataset.count;
            const mType = el.dataset.type;
            if ( ! confirm( `Supprimer ${ count } méta(s) avec la clé « ${ key } » ? ${ RDR_WPDBOPT.i18n.confirm_delete }` ) ) return;
            wpdbopt.ajax( 'rdr_wpdbopt_delete_meta_by_key', { meta_type: mType, meta_key: key } )
                .then( d => {
                    wpdbopt.toast( `${ d.deleted } méta(s) supprimée(s).`, 'success' );
                    loadMetaByKey();
                    loadMetaStats();
                } )
                .catch( err => wpdbopt.toast( err.message, 'error' ) );
        } );
    } );
}

/* ===============================================================
   BARRE BULK
=============================================================== */
function updateBulkBar( prefix ) {
    const state = prefix === 'opt' ? optState : orphanState;
    const bar   = document.getElementById( `${ prefix }-bulk` );
    const count = document.getElementById( `${ prefix }-selected-count` );
    if ( ! bar ) return;
    bar.style.display = state.selected.size ? 'flex' : 'none';
    if ( count ) count.textContent = `${ state.selected.size } sélectionnée(s)`;
}

/* ===============================================================
   HELPERS DOM
=============================================================== */
function escHtml( str ) {
    return String( str ?? '' )
        .replace( /&/g, '&amp;' )
        .replace( /</g, '&lt;' )
        .replace( />/g, '&gt;' )
        .replace( /"/g, '&quot;' );
}
function escAttr( str ) { return escHtml( str ); }

/* ===============================================================
   INIT
=============================================================== */
document.addEventListener( 'DOMContentLoaded', () => {
    initTabs();
    initOptionsTab();
    initOrphansTab();
    initKeysTab();
} );
