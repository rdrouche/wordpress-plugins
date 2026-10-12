/**
 * RDR Banner Display — Admin JS  v2.7.2
 *
 * Save: native HTML form fields (name="rdr_bd_item[N][field]").
 * JS only: syncs textarea → base64 hidden on submit, handles add/remove rows.
 *
 * @package RDR_Banner_Display
 */
( function () {
    'use strict';

    // Wait for DOM ready — meta boxes are injected after initial parse
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

    function init() {

        // ── Meta box context ──────────────────────────────────────────────────
        const metaBox = document.querySelector( '.rdr-bd-meta-box[data-post-id]' );
        window.rdrBDCampaigns = metaBox ? safeJson( metaBox.dataset.campaigns, [] ) : [];

        const wrapper = document.getElementById( 'rdr-bd-items-wrapper' );
        const addBtn  = document.getElementById( 'rdr-bd-add-item' );

        // ── Item counter ──────────────────────────────────────────────────────
        // Count PHP-rendered rows so new JS rows get non-colliding indexes.
        let itemCounter = wrapper ? wrapper.querySelectorAll( '.rdr-bd-item-row' ).length : 0;

        // ── Add row ───────────────────────────────────────────────────────────
        if ( addBtn && wrapper ) {
            addBtn.addEventListener( 'click', function () {
                wrapper.insertAdjacentHTML( 'beforeend', buildItemRow( itemCounter ) );
                itemCounter++;
                // Scroll new row into view
                const rows = wrapper.querySelectorAll( '.rdr-bd-item-row' );
                rows[ rows.length - 1 ]?.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
            } );
        }

        // ── Remove row (delegated) ────────────────────────────────────────────
        if ( wrapper ) {
            wrapper.addEventListener( 'click', function ( e ) {
                const btn = e.target.closest( '.rdr-bd-remove-item' );
                if ( btn ) {
                    btn.closest( '.rdr-bd-item-row' )?.remove();
                }
            } );
        }

        // ── Sync textarea → base64 before submit ──────────────────────────────
        // Content field uses base64 to survive WordPress addslashes() on $_POST.
        function syncBase64() {
            if ( ! wrapper ) return;
            wrapper.querySelectorAll( '.rdr-bd-item-row' ).forEach( function ( row ) {
                const textarea = row.querySelector( '.rdr-bd-item-content' );
                const hidden   = row.querySelector( '.rdr-bd-content-b64' );
                if ( textarea && hidden ) {
                    try {
                        hidden.value = btoa( unescape( encodeURIComponent( textarea.value ) ) );
                    } catch ( e ) {
                        hidden.value = '';
                    }
                }
            } );
        }

        // Hook sync on all save paths
        document.addEventListener( 'submit', syncBase64, true );
        document.querySelectorAll( '#publish, #save-post, [name="save"]' ).forEach( function ( btn ) {
            btn.addEventListener( 'mousedown', syncBase64 );
            btn.addEventListener( 'click', syncBase64 );
        } );
        window.addEventListener( 'beforeunload', syncBase64 );

        // ── Preview button ────────────────────────────────────────────────────
        const previewBtn = document.getElementById( 'rdr-bd-preview-btn' );
        const previewOut = document.getElementById( 'rdr-bd-preview-output' );

        if ( previewBtn && previewOut && metaBox ) {
            previewBtn.addEventListener( 'click', async function () {
                const cfg    = window.rdrBDAdmin || {};
                const postId = parseInt( metaBox.dataset.postId, 10 ) || 0;
                previewBtn.disabled    = true;
                previewBtn.textContent = 'Loading…';
                try {
                    const body = new URLSearchParams( { action: 'rdr_bd_preview', nonce: cfg.nonce || '', banner_id: String( postId ) } );
                    const res  = await fetch( cfg.ajaxUrl, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } );
                    const data = await res.json();
                    if ( data.success ) {
                        const d = data.data;
                        let out = `<div class="rdr-bd-preview-result"><p><strong>Items in DB: ${ d.count }</strong></p>`;
                        out += `<details><summary>Raw JSON</summary><pre style="white-space:pre-wrap;word-break:break-all;font-size:11px;">${ escHtml( d.raw_meta ) }</pre></details>`;
                        d.items.forEach( function ( item ) {
                            out += `<div class="rdr-bd-preview-item">`;
                            out += `<p><strong>[${ item.index }] ${ item.type }</strong>`;
                            if ( item.campaign_id ) out += ` · campaign #${ item.campaign_id }`;
                            out += ` · no_ads=${ item.for_no_ads ? '✓' : '✗' }`;
                            out += ` · adblock_only=${ item.adblock_only ? '✓' : '✗' }`;
                            out += ` · force_adblock=${ item.force_on_adblock ? '✓' : '✗' }`;
                            out += `</p><details><summary>Content</summary><pre style="white-space:pre-wrap;font-size:11px;">${ escHtml( item.content ) }</pre></details></div>`;
                        } );
                        out += `</div>`;
                        previewOut.innerHTML = out;
                    } else {
                        previewOut.innerHTML = `<p style="color:red;">Error: ${ JSON.stringify( data.data ) }</p>`;
                    }
                    previewOut.style.display = 'block';
                } catch ( err ) {
                    previewOut.innerHTML = `<p style="color:red;">Fetch error: ${ err.message }</p>`;
                    previewOut.style.display = 'block';
                }
                previewBtn.disabled    = false;
                previewBtn.textContent = '👁 Preview stored content';
            } );
        }

        // ── Campaigns page ────────────────────────────────────────────────────
        const modal      = document.getElementById( 'rdr-bd-campaign-modal' );
        const newCampBtn = document.getElementById( 'rdr-bd-new-campaign' );
        const saveCampBtn= document.getElementById( 'rdr-bd-save-campaign' );
        const cancelBtn  = document.getElementById( 'rdr-bd-cancel-campaign' );

        if ( modal ) {
            function openModal( data ) {
                data = data || {};
                document.getElementById( 'rdr-bd-modal-title' ).textContent       = data.id ? 'Edit Campaign' : 'New Campaign';
                document.getElementById( 'rdr-bd-campaign-id' ).value             = data.id || 0;
                document.getElementById( 'rdr-bd-campaign-title' ).value          = data.title || '';
                document.getElementById( 'rdr-bd-campaign-status' ).value         = data.status || 'pending';
                document.getElementById( 'rdr-bd-campaign-owner' ).value          = data.owner_user_id || '';
                document.getElementById( 'rdr-bd-campaign-budget' ).value         = data.budget || '';
                document.getElementById( 'rdr-bd-campaign-impression-cap' ).value = data.impression_cap || '';
                document.getElementById( 'rdr-bd-campaign-click-cap' ).value      = data.click_cap || '';
                document.getElementById( 'rdr-bd-campaign-start' ).value = data.start_date ? data.start_date.slice( 0, 16 ) : '';
                document.getElementById( 'rdr-bd-campaign-end' ).value   = data.end_date   ? data.end_date.slice( 0, 16 )   : '';
                modal.style.display = 'flex';
            }
            function closeModal() { modal.style.display = 'none'; }

            if ( newCampBtn ) newCampBtn.addEventListener( 'click', function () { openModal(); } );
            if ( cancelBtn )  cancelBtn.addEventListener(  'click', closeModal );
            modal.addEventListener( 'click', function ( e ) { if ( e.target === modal ) closeModal(); } );

            document.querySelectorAll( '.rdr-bd-edit-campaign' ).forEach( function ( btn ) {
                btn.addEventListener( 'click', function () {
                    try { openModal( JSON.parse( btn.dataset.campaign ) ); } catch (_) {}
                } );
            } );
            document.querySelectorAll( '.rdr-bd-delete-campaign' ).forEach( function ( btn ) {
                btn.addEventListener( 'click', function () {
                    if ( ! confirm( 'Delete this campaign? This cannot be undone.' ) ) return;
                    ajaxPost( 'rdr_bd_delete_campaign', { id: btn.dataset.id } ).then( function ( res ) {
                        if ( res.success ) btn.closest( 'tr' )?.remove();
                        else alert( 'Error deleting campaign.' );
                    } );
                } );
            } );

            if ( saveCampBtn ) {
                saveCampBtn.addEventListener( 'click', function () {
                    const payload = {
                        id:             document.getElementById( 'rdr-bd-campaign-id' ).value,
                        title:          document.getElementById( 'rdr-bd-campaign-title' ).value.trim(),
                        status:         document.getElementById( 'rdr-bd-campaign-status' ).value,
                        owner_user_id:  document.getElementById( 'rdr-bd-campaign-owner' ).value,
                        budget:         document.getElementById( 'rdr-bd-campaign-budget' ).value,
                        impression_cap: document.getElementById( 'rdr-bd-campaign-impression-cap' ).value,
                        click_cap:      document.getElementById( 'rdr-bd-campaign-click-cap' ).value,
                        start_date:     document.getElementById( 'rdr-bd-campaign-start' ).value,
                        end_date:       document.getElementById( 'rdr-bd-campaign-end' ).value,
                    };
                    if ( ! payload.title ) { alert( 'Campaign title is required.' ); return; }
                    saveCampBtn.disabled = true; saveCampBtn.textContent = 'Saving…';
                    ajaxPost( 'rdr_bd_save_campaign', payload ).then( function ( res ) {
                        saveCampBtn.disabled = false; saveCampBtn.textContent = 'Save Campaign';
                        if ( res.success ) { closeModal(); window.location.reload(); }
                        else alert( 'Error saving campaign.' );
                    } );
                } );
            }
        }

        // ── Settings ──────────────────────────────────────────────────────────
        document.querySelectorAll( '.rdr-bd-role-cap' ).forEach( function ( cb ) {
            cb.addEventListener( 'change', function () {
                ajaxPost( 'rdr_bd_toggle_role_cap', { role: cb.dataset.role, grant: cb.checked ? 1 : 0 } )
                    .then( function ( res ) { if ( ! res.success ) { cb.checked = ! cb.checked; alert( 'Error.' ); } } );
            } );
        } );
        document.querySelectorAll( '.rdr-bd-reset-stats-btn' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                if ( ! confirm( 'Reset standalone stats for this banner?' ) ) return;
                ajaxPost( 'rdr_bd_reset_stats', { banner_id: btn.dataset.bannerId } ).then( function ( res ) {
                    if ( res.success ) { alert( 'Done. ' + res.data.deleted + ' row(s) deleted.' ); window.location.reload(); }
                    else alert( 'Error.' );
                } );
            } );
        } );

        // ── Stats page ────────────────────────────────────────────────────────
        if ( typeof window.rdrBDCampaignDaily !== 'undefined' ) {
            var canvas = document.getElementById( 'rdr-bd-chart-campaign' );
            var msg    = document.getElementById( 'rdr-bd-chart-campaign-msg' );
            if ( canvas ) {
                if ( window.rdrBDCampaignDaily.length ) {
                    if ( msg ) msg.style.display = 'none';
                    canvas.style.display = 'block';
                    renderLineChartOn( canvas, window.rdrBDCampaignDaily );
                } else {
                    canvas.style.display = 'none';
                    if ( msg ) msg.textContent = 'No daily data for selected period.';
                }
            }
        }
        if ( typeof window.rdrBDStats !== 'undefined' ) {
            var sc = window.rdrBDStats;
            ajaxPost( 'rdr_bd_admin_stats', { banner_id: sc.bannerId, days: sc.days } ).then( function ( res ) {
                if ( ! res.success ) return;
                var d = res.data;
                if ( sc.bannerId && d.totals ) {
                    setText( 'stat-impressions', fmt( d.totals.impressions ) );
                    setText( 'stat-clicks',      fmt( d.totals.clicks ) );
                    setText( 'stat-ctr',         d.totals.ctr + '%' );
                    setText( 'stat-adblock',     fmt( d.totals.adblock_impressions ) );
                    renderLineChart( d.daily || [] );
                } else if ( d.summary ) {
                    setText( 'stat-impressions', fmt( d.summary.impressions ) );
                    setText( 'stat-clicks',      fmt( d.summary.clicks ) );
                    setText( 'stat-ctr',         d.summary.ctr + '%' );
                    renderTopTable( d.top_banners || [] );
                    renderLineChart( [] );
                }
            } );
        }

    } // end init()

    // ── Row builder ───────────────────────────────────────────────────────────
    function buildItemRow( n ) {
        var campaignOpts = buildCampaignOptions( 0 );
        return '<div class="rdr-bd-item-row">' +
            '<div class="rdr-bd-item-header">' +
                '<select name="rdr_bd_item[' + n + '][type]" class="rdr-bd-item-type">' +
                    '<option value="html">HTML</option>' +
                    '<option value="adsense">Google AdSense</option>' +
                    '<option value="shortcode">WordPress Shortcode</option>' +
                '</select>' +
                '<label class="rdr-bd-item-flag" title="Show ONLY to users with the no-ads capability">' +
                    '<input type="checkbox" name="rdr_bd_item[' + n + '][for_no_ads]" value="1"> No-ads only' +
                '</label>' +
                '<label class="rdr-bd-item-flag rdr-bd-flag--adblock-only" title="Show ONLY to users with an adblocker. Regular users will NOT see this item.">' +
                    '<input type="checkbox" name="rdr_bd_item[' + n + '][adblock_only]" value="1"> Adblock only' +
                '</label>' +
                '<label class="rdr-bd-item-flag rdr-bd-flag--force-adblock" title="AdSense: force display even when adblocker detected">' +
                    '<input type="checkbox" name="rdr_bd_item[' + n + '][force_on_adblock]" value="1"> Force if adblock' +
                '</label>' +
                '<div class="rdr-bd-item-caps">' +
                    '<label class="rdr-bd-cap-label" title="Stop after N impressions"><span>Imp. cap</span>' +
                        '<input type="number" class="small-text" name="rdr_bd_item[' + n + '][impression_cap]" min="0" placeholder="∞">' +
                    '</label>' +
                    '<label class="rdr-bd-cap-label" title="Stop after N clicks"><span>Click cap</span>' +
                        '<input type="number" class="small-text" name="rdr_bd_item[' + n + '][click_cap]" min="0" placeholder="∞">' +
                    '</label>' +
                '</div>' +
                '<button type="button" class="rdr-bd-remove-item button-link-delete">&#x2715; Remove</button>' +
            '</div>' +
            '<div class="rdr-bd-item-campaign">' +
                '<label class="rdr-bd-item-campaign-label">Campaign: ' +
                    '<select name="rdr_bd_item[' + n + '][campaign_id]">' + campaignOpts + '</select>' +
                '</label>' +
            '</div>' +
            '<input type="hidden" name="rdr_bd_item[' + n + '][content_b64]" class="rdr-bd-content-b64" value="">' +
            '<textarea class="rdr-bd-item-content" rows="6" placeholder="Paste your HTML / AdSense code / shortcode here"></textarea>' +
        '</div>';
    }

    function buildCampaignOptions( selectedId ) {
        var campaigns = window.rdrBDCampaigns || [];
        var opts = '<option value="0"' + ( selectedId === 0 ? ' selected' : '' ) + '>— Standalone —</option>';
        campaigns.forEach( function ( c ) {
            var label    = c.status !== 'active' ? escHtml( c.title ) + ' [' + c.status + ']' : escHtml( c.title );
            var selected = parseInt( c.id, 10 ) === selectedId ? ' selected' : '';
            opts += '<option value="' + c.id + '"' + selected + '>' + label + '</option>';
        } );
        return opts;
    }

    // ── Charts ────────────────────────────────────────────────────────────────
    function renderLineChartOn( canvas, rows ) {
        if ( ! canvas || ! rows.length ) return;
        canvas.style.display = 'block';
        var W=canvas.offsetWidth||800, H=canvas.offsetHeight||200;
        canvas.width=W; canvas.height=H;
        var ctx=canvas.getContext('2d');
        var pad={top:20,right:20,bottom:40,left:55};
        var cW=W-pad.left-pad.right, cH=H-pad.top-pad.bottom;
        var imps=rows.map(function(r){return r.impressions;}),
            clicks=rows.map(function(r){return r.clicks;}),
            labels=rows.map(function(r){return r.date;});
        var maxV=Math.max.apply(null,imps.concat(clicks).concat([1]));
        ctx.clearRect(0,0,W,H);
        for(var i=0;i<=4;i++){var y=pad.top+(cH/4)*i;ctx.strokeStyle='rgba(0,0,0,.07)';ctx.lineWidth=1;ctx.beginPath();ctx.moveTo(pad.left,y);ctx.lineTo(W-pad.right,y);ctx.stroke();ctx.fillStyle='#646970';ctx.font='10px system-ui';ctx.textAlign='right';ctx.fillText(fmt(Math.round(maxV*(1-i/4))),pad.left-6,y+4);}
        function xPos(idx){return pad.left+(idx/Math.max(rows.length-1,1))*cW;}
        function yPos(v){return pad.top+cH-(v/maxV)*cH;}
        function drawLine(vals,color){ctx.beginPath();ctx.strokeStyle=color;ctx.lineWidth=2;ctx.lineJoin='round';vals.forEach(function(v,idx){if(idx===0)ctx.moveTo(xPos(idx),yPos(v));else ctx.lineTo(xPos(idx),yPos(v));});ctx.stroke();}
        drawLine(imps,'#2271b1'); drawLine(clicks,'#00a32a');
        var step=Math.ceil(labels.length/10);
        ctx.fillStyle='#646970';ctx.font='10px system-ui';ctx.textAlign='center';
        labels.forEach(function(l,idx){if(idx%step===0)ctx.fillText(l.slice(5),xPos(idx),H-8);});
        ctx.fillStyle='#2271b1';ctx.fillRect(pad.left,4,12,4);ctx.fillStyle='#646970';ctx.textAlign='left';ctx.font='10px system-ui';ctx.fillText('Impressions',pad.left+16,10);
        ctx.fillStyle='#00a32a';ctx.fillRect(pad.left+110,4,12,4);ctx.fillStyle='#646970';ctx.fillText('Clicks',pad.left+126,10);
    }
    function renderLineChart( rows ) {
        var canvas=document.getElementById('rdr-bd-chart'), msg=document.getElementById('rdr-bd-chart-msg');
        if(!canvas)return;
        if(!rows.length){canvas.style.display='none';if(msg)msg.textContent='No daily data for the selected period.';return;}
        if(msg)msg.style.display='none';
        renderLineChartOn(canvas,rows);
    }
    function renderTopTable( rows ) {
        var tbody=document.querySelector('#rdr-bd-top-table tbody');
        if(!tbody)return;
        if(!rows.length){tbody.innerHTML='<tr><td colspan="5" style="text-align:center;padding:20px;">No data yet.</td></tr>';return;}
        tbody.innerHTML=rows.map(function(r){var ctr=r.impressions>0?(r.clicks/r.impressions*100).toFixed(2):'0.00';return'<tr><td><strong>'+escHtml(r.post_title)+'</strong> <code>#'+r.banner_id+'</code></td><td>'+fmt(r.impressions)+'</td><td>'+fmt(r.clicks)+'</td><td>'+ctr+'%</td><td><a href="?page=rdr-bd-stats&banner_id='+r.banner_id+'">Detail</a></td></tr>';}).join('');
    }

    // ── Utilities ─────────────────────────────────────────────────────────────
    function ajaxPost( action, data ) {
        data = data || {};
        var cfg=window.rdrBDAdmin||{};
        var body=new URLSearchParams(Object.assign({action:action,nonce:cfg.nonce||''},data));
        return fetch(cfg.ajaxUrl||'/wp-admin/admin-ajax.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body.toString()})
            .then(function(r){return r.json();}).catch(function(){return{success:false};});
    }
    function setText(id,val){var el=document.getElementById(id);if(el)el.textContent=val;}
    function fmt(n){return Number(n).toLocaleString();}
    function escHtml(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}
    function safeJson(str,fallback){try{return JSON.parse(str||'null')||fallback;}catch(e){return fallback;}}

}() );
