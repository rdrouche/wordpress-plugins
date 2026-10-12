/**
 * RDR Expert Directory — Public JS (Vanilla, sans jQuery)
 * Version: 1.1.0
 */
'use strict';

/* ============================================================
   UTILITAIRES GLOBAUX
   ============================================================ */
const rdrED = {
    esc(str) {
        return String(str || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    },
    post(action, params = {}) {
        const body = new FormData();
        body.append('action', action);
        body.append('nonce', RDR_ED.nonce);
        Object.entries(params).forEach(([k, v]) => {
            if (Array.isArray(v)) { v.forEach(item => body.append(k + '[]', item)); }
            else { body.append(k, v ?? ''); }
        });
        return fetch(RDR_ED.ajaxurl, { method: 'POST', body, credentials: 'same-origin' }).then(r => r.json());
    },
    debounce(fn, ms = 400) {
        let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    },
    slideDown(el, ms = 220) {
        el.style.display = 'block';
        const h = el.scrollHeight;
        el.style.cssText += `;overflow:hidden;max-height:0;transition:max-height ${ms}ms ease`;
        requestAnimationFrame(() => { el.style.maxHeight = h + 'px'; });
        setTimeout(() => { el.style.maxHeight = ''; el.style.overflow = ''; el.style.transition = ''; }, ms);
    },
    slideUp(el, ms = 200) {
        el.style.overflow = 'hidden';
        el.style.maxHeight = el.scrollHeight + 'px';
        el.style.transition = `max-height ${ms}ms ease`;
        requestAnimationFrame(() => { el.style.maxHeight = '0'; });
        setTimeout(() => { el.style.display = 'none'; el.style.maxHeight = ''; el.style.overflow = ''; el.style.transition = ''; }, ms);
    },
    getSecData() {
        try { const s = sessionStorage.getItem('rdr_ed_sec'); if (s) return JSON.parse(s); } catch (e) {}
        return window.RDR_ED_SEC || {};
    },
};

/* ============================================================
   MODULE — ANNUAIRE
   ============================================================ */
(function () {
    const wrap = document.querySelector('.rdr-ed-directory');
    if (!wrap) return;

    // Nonce depuis le data attribute (fraîchement généré, résistant au cache serveur)
    // Fallback sur RDR_ED.nonce si wp_localize_script est disponible
    const _nonce   = wrap.dataset.nonce   || (typeof RDR_ED !== 'undefined' ? RDR_ED.nonce : '');
    const _ajaxurl = wrap.dataset.ajaxurl || (typeof RDR_ED !== 'undefined' ? RDR_ED.ajaxurl : '/wp-admin/admin-ajax.php');

    const perPage = parseInt(wrap.dataset.perPage || 12);
    let page = 1;
    const el = id => wrap.querySelector('#' + id);
    const $grid   = el('rdr-ed-grid');
    const $info   = el('rdr-ed-results-info');
    const $pagi   = el('rdr-ed-pagination');
    const search  = el('rdr-ed-search');  // déclaré avant load() pour éviter TDZ

    load(1);
    if (search) search.addEventListener('input', rdrED.debounce(() => { page = 1; load(1); }));

    ['rdr-filter-skill','rdr-filter-country','rdr-filter-language','rdr-filter-type'].forEach(id => {
        const s = el(id);
        if (s) s.addEventListener('change', () => { page = 1; load(1); });
    });

    const reset = el('rdr-filter-reset');
    if (reset) reset.addEventListener('click', () => {
        if (search) search.value = '';
        ['rdr-filter-skill','rdr-filter-country','rdr-filter-language','rdr-filter-type'].forEach(id => {
            const s = el(id); if (s) s.value = '';
        });
        page = 1; load(1);
    });

    function filters() {
        return {
            search:   search ? search.value.trim() : '',
            skill:    parseInt(el('rdr-filter-skill')?.value)    || 0,
            country:  parseInt(el('rdr-filter-country')?.value)  || 0,
            language: parseInt(el('rdr-filter-language')?.value) || 0,
            type:     el('rdr-filter-type')?.value || '',
            per_page: perPage, page,
        };
    }

    function load(p) {
        page = p;
        skeleton();
        if ($pagi) $pagi.innerHTML = '';
        if ($info) $info.textContent = RDR_ED.i18n.loading;

        // Utiliser le nonce du data attribute (anti-cache)
        const body = new FormData();
        body.append('action', 'rdr_ed_load_experts');
        body.append('nonce', _nonce);
        Object.entries(filters()).forEach(([k, v]) => body.append(k, v ?? ''));
        fetch(_ajaxurl, { method: 'POST', body, credentials: 'same-origin' })
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.text().then(t => {
                    try { return JSON.parse(t); }
                    catch(e) { console.error('AJAX response not JSON:', t.substring(0,100)); throw e; }
                });
            })
            .then(res => {
            if (!res.success) { empty(); return; }
            const d = res.data;
            cards(d.cards);
            info(d.total, d.page, d.per_page);
            pagi(d.page, d.pages);
            }).catch(err => { console.error('rdr_ed_load_experts error:', err); empty(); });
    }

    function skeleton() {
        let h = '';
        for (let i = 0; i < 6; i++) h += `<div class="rdr-ed-card rdr-ed-skeleton" aria-hidden="true">
            <div class="rdr-ed-card-banner rdr-skel-block"></div>
            <div class="rdr-ed-card-body">
                <div class="rdr-ed-card-avatar-wrap"><div class="rdr-ed-card-avatar rdr-skel-circle"></div></div>
                <div class="rdr-skel-block" style="height:18px;width:60%;margin-bottom:8px;"></div>
                <div class="rdr-skel-block" style="height:13px;width:40%;margin-bottom:12px;"></div>
                <div class="rdr-skel-block" style="height:13px;width:90%;margin-bottom:6px;"></div>
                <div class="rdr-skel-block" style="height:13px;width:75%;margin-bottom:16px;"></div>
                <div class="rdr-ed-tags-row">
                    <div class="rdr-skel-block" style="height:22px;width:60px;border-radius:20px;"></div>
                    <div class="rdr-skel-block" style="height:22px;width:80px;border-radius:20px;"></div>
                </div>
            </div></div>`;
        $grid.innerHTML = h;
    }

    function empty() {
        $grid.innerHTML = `<p class="rdr-ed-no-results">${RDR_ED.i18n.no_results}</p>`;
        if ($info) $info.textContent = '';
    }

    function cards(list) {
        if (!list?.length) { empty(); return; }
        $grid.innerHTML = list.map((c, i) => {
            const banner = c.banner
                ? `<div class="rdr-ed-card-banner"><img src="${rdrED.esc(c.banner)}" alt="" loading="lazy"></div>`
                : '<div class="rdr-ed-card-banner"></div>';
            const avatar = c.photo
                ? `<img src="${rdrED.esc(c.photo)}" alt="${rdrED.esc(c.name)}" loading="lazy">`
                : '<span class="rdr-ed-avatar-placeholder">👤</span>';
            const tags = (c.skills||[]).map(s=>`<span class="rdr-ed-tag">${rdrED.esc(s.name)}</span>`).join('');
            const more = c.skills_extra > 0 ? `<span class="rdr-ed-tag-more">+${c.skills_extra}</span>` : '';
            const meta = [
                c.countries?.[0] ? `<span>📍 ${rdrED.esc(c.countries[0])}</span>` : '',
                c.languages?.[0] ? `<span>🗣️ ${rdrED.esc(c.languages[0])}</span>` : '',
            ].filter(Boolean).join('');
            const badge = c.type ? `<span class="rdr-ed-type-badge">${c.type==='independant'?'👤 Indépendant':'🏢 Entreprise'}</span>` : '';
            return `<article class="rdr-ed-card" role="listitem" style="animation-delay:${i*.04}s">
                ${banner}
                <div class="rdr-ed-card-body">
                    <div class="rdr-ed-card-avatar-wrap"><div class="rdr-ed-card-avatar">${avatar}</div></div>
                    <h3 class="rdr-ed-card-name">${rdrED.esc(c.name)}</h3>
                    ${c.title?`<p class="rdr-ed-card-title">${rdrED.esc(c.title)}</p>`:''}
                    <div class="rdr-ed-card-meta">${meta}${badge}</div>
                    ${c.excerpt?`<p class="rdr-ed-card-excerpt">${rdrED.esc(c.excerpt)}</p>`:''}
                    <div class="rdr-ed-tags-row">${tags}${more}</div>
                    <div class="rdr-ed-card-footer"><a href="${rdrED.esc(c.url)}" class="rdr-ed-card-link">Voir la fiche →</a></div>
                </div></article>`;
        }).join('');
    }

    function info(total, p, pp) {
        if (!$info) return;
        if (!total) { $info.textContent = 'Aucun expert trouvé.'; return; }
        const from = Math.min((p-1)*pp+1,total), to = Math.min(p*pp,total);
        $info.textContent = `${from}–${to} sur ${total} expert${total>1?'s':''}`;
    }

    function pagi(cur, total) {
        if (!$pagi||total<=1) { if($pagi) $pagi.innerHTML=''; return; }
        let h = `<button class="rdr-ed-page-btn" data-page="${cur-1}" ${cur===1?'disabled':''}>‹</button>`;
        for (let p=1;p<=total;p++) {
            if (total>7&&p>2&&p<total-1&&Math.abs(p-cur)>2) {
                if(p===3||p===total-2) h+=`<span class="rdr-ed-page-btn" style="cursor:default;pointer-events:none;">…</span>`;
                continue;
            }
            h+=`<button class="rdr-ed-page-btn${p===cur?' is-active':''}" data-page="${p}">${p}</button>`;
        }
        h+=`<button class="rdr-ed-page-btn" data-page="${cur+1}" ${cur===total?'disabled':''}>›</button>`;
        $pagi.innerHTML = h;
        $pagi.querySelectorAll('.rdr-ed-page-btn:not([disabled])').forEach(btn => {
            btn.addEventListener('click', () => {
                const p = parseInt(btn.dataset.page);
                if (!p||p===page) return;
                load(p);
                wrap.scrollIntoView({behavior:'smooth',block:'start'});
            });
        });
    }
})();

/* ============================================================
   MODULE — FORMULAIRE PROFIL
   ============================================================ */
(function () {
    const form = document.getElementById('rdr-expert-profile-form');
    if (!form) return;

    /* Type entreprise/indépendant */
    form.querySelectorAll('input[name="expert_type"]').forEach(r => {
        r.addEventListener('change', () => {
            const w = document.getElementById('rdr-company-wrap');
            if (w) w.style.display = r.value === 'entreprise' ? '' : 'none';
        });
    });

    /* Moyens de contact : toggle champs */
    form.querySelectorAll('.rdr-contact-method-check').forEach(cb => {
        cb.addEventListener('change', () => {
            cb.closest('.rdr-contact-method-card')?.classList.toggle('is-active', cb.checked);
            const f = document.getElementById('rdr-' + cb.value + '-fields');
            if (f) cb.checked ? rdrED.slideDown(f) : rdrED.slideUp(f);
        });
    });

    /* Compteur compétences */
    const cloud    = document.getElementById('rdr-skills-cloud');
    const skillMax = cloud ? parseInt(cloud.dataset.max || RDR_ED.skill_max || 5) : 5;
    const ctrNum   = document.getElementById('rdr-skill-count-num');
    const ctrWrap  = document.getElementById('rdr-skill-count');
    const formMsg  = document.getElementById('rdr-form-message');

    function updateCounter() {
        if (!cloud||!ctrNum) return;
        const n = cloud.querySelectorAll('input:checked').length;
        ctrNum.textContent = n;
        ctrWrap?.classList.toggle('is-max', n >= skillMax);
    }

    function showMsg(msg, type) {
        if (!formMsg) return;
        formMsg.className = `rdr-form-message is-${type}`;
        formMsg.innerHTML = msg; formMsg.style.display = '';
    }

    if (cloud) {
        cloud.addEventListener('change', e => {
            const cb = e.target; if (cb.type !== 'checkbox') return;
            const n = cloud.querySelectorAll('input:checked').length;
            if (cb.checked && n > skillMax) {
                cb.checked = false; cb.closest('.rdr-tag-check')?.classList.remove('is-active');
                showMsg(RDR_ED.i18n.skill_max, 'error');
                setTimeout(() => { if (formMsg) formMsg.style.display = 'none'; }, 3000);
                return;
            }
            cb.closest('.rdr-tag-check')?.classList.toggle('is-active', cb.checked);
            updateCounter();
            if (formMsg) formMsg.style.display = 'none';
        });
        updateCounter();
    }

    /* Tag libre */
    const freeBtn   = document.getElementById('rdr-free-skill-btn');
    const freeInput = document.getElementById('rdr-free-skill-input');
    const freeResult= document.getElementById('rdr-free-skill-result');
    if (freeBtn && freeInput) {
        freeBtn.addEventListener('click', () => {
            const name = freeInput.value.trim();
            if (!name||name.length<2||name.length>50) { if(freeResult) freeResult.innerHTML='<span style="color:var(--rdr-text-muted);">2 à 50 caractères.</span>'; return; }
            freeBtn.disabled=true; freeBtn.textContent='⏳';
            rdrED.post('rdr_ed_suggest_skill',{skill_name:name}).then(res=>{
                freeBtn.disabled=false; freeBtn.textContent='Proposer';
                if(res.success){
                    const d=res.data;
                    if(d.pending){ if(freeResult) freeResult.innerHTML=`<span style="color:var(--rdr-accent);">⏳ ${rdrED.esc(RDR_ED.i18n.skill_pending)}</span>`; }
                    else if(d.term_id&&cloud){
                        if(cloud.querySelectorAll('input:checked').length>=skillMax){ if(freeResult) freeResult.innerHTML=`<span style="color:var(--rdr-text-muted);">Limite atteinte.</span>`; }
                        else {
                            const lbl=document.createElement('label'); lbl.className='rdr-tag-check is-active';
                            lbl.innerHTML=`<input type="checkbox" name="expert_skills[]" value="${d.term_id}" checked><span>${rdrED.esc(d.name)}</span>`;
                            cloud.appendChild(lbl); updateCounter();
                            if(freeResult) freeResult.innerHTML='<span style="color:#00e5c0;">✓ Ajouté !</span>';
                            freeInput.value='';
                        }
                    }
                } else { if(freeResult) freeResult.innerHTML=`<span style="color:#ff5050;">${rdrED.esc(res.data?.message||'Erreur.')}</span>`; }
            });
        });
    }

    /* ── Upload photo/bannière via AJAX direct (sans wp.media) ── */
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.rdr-media-btn');
        if (!btn) return;
        const input = document.createElement('input');
        input.type = 'file'; input.accept = 'image/jpeg,image/png,image/webp,image/gif';
        input.style.display = 'none'; document.body.appendChild(input); input.click();
        input.addEventListener('change', function () {
            if (!input.files.length) { document.body.removeChild(input); return; }
            const file      = input.files[0];
            const mediaType = btn.dataset.mediaType || btn.dataset.target || 'photo';
            const field     = document.getElementById(btn.dataset.field);
            const wrap      = btn.closest('.rdr-media-wrap') || btn.closest('.rdr-media-upload') || btn.closest('.rdr-media-btn-row')?.parentElement;
            const preview   = wrap?.querySelector('.rdr-media-preview');
            const spinner   = wrap?.querySelector('.rdr-upload-spinner');
            if (file.size > 2 * 1024 * 1024) { alert('Image trop volumineuse (max 2 Mo).'); document.body.removeChild(input); return; }
            if (spinner) spinner.style.display = 'inline';
            btn.disabled = true;
            const fd = new FormData();
            fd.append('action',     'rdr_ed_upload_media');
            // Nonce : depuis le formulaire (fresh, anti-cache) en priorité
            const _uploadNonce = document.getElementById('rdr-expert-profile-form')?.dataset?.uploadNonce
                               || (typeof RDR_ED !== 'undefined' ? RDR_ED.nonce : '')
                               || wrap?.closest('[data-nonce]')?.dataset?.nonce || '';
            fd.append('nonce', _uploadNonce);
            fd.append('media_type', mediaType);
            fd.append('file',       file);
            fetch(typeof RDR_ED !== 'undefined' ? RDR_ED.ajaxurl : '/wp-admin/admin-ajax.php',
                  { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(res => {
                    if (spinner) spinner.style.display = 'none'; btn.disabled = false;
                    if (!res.success) { alert(res.data?.message || 'Erreur upload.'); return; }
                    if (field) field.value = res.data.attachment_id;
                    if (preview && res.data.thumb_url) preview.innerHTML = `<img src="${rdrED.esc(res.data.thumb_url)}" alt="" style="width:100%;height:100%;object-fit:cover;">`;
                })
                .catch(() => { if (spinner) spinner.style.display = 'none'; btn.disabled = false; alert("Erreur réseau lors de l\'upload."); });
            document.body.removeChild(input);
        });
    });

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.rdr-media-remove'); if (!btn) return;
        const wrap    = btn.closest('.rdr-media-wrap') || btn.closest('.rdr-media-upload');
        const fieldId  = btn.dataset.field;
        const field    = fieldId ? document.getElementById(fieldId) : wrap?.querySelector('input[type=hidden]');
        const preview = wrap?.querySelector('.rdr-media-preview');
        const attId   = field?.value;
        if (attId && confirm('Supprimer cette image ?')) {
            const fd = new FormData();
            fd.append('action','rdr_ed_delete_media');
            fd.append('nonce', typeof RDR_ED !== 'undefined' ? RDR_ED.nonce : '');
            fd.append('attachment_id', attId);
            fetch(typeof RDR_ED !== 'undefined' ? RDR_ED.ajaxurl : '/wp-admin/admin-ajax.php',
                  { method: 'POST', body: fd, credentials: 'same-origin' });
        }
        if (field)   field.value = '';
        if (preview) preview.innerHTML = '<span class="rdr-media-placeholder">\u{1F4F7}<br>Choisir</span>';
    });

    /* Annuler révision */
    const cancelBtn=document.getElementById('rdr-cancel-pending');
    if(cancelBtn) cancelBtn.addEventListener('click',()=>{
        if(!confirm('Annuler cette révision ?')) return;
        rdrED.post('rdr_ed_cancel_pending',{post_id:cancelBtn.dataset.post}).then(res=>{
            if(res.success){ const n=document.getElementById('rdr-pending-notice'); if(n) rdrED.slideUp(n); }
        });
    });

    /* Soumission */
    const sbBtn=document.getElementById('rdr-submit-btn');
    const sbText=sbBtn?.querySelector('.rdr-submit-text');
    const sbSpin=sbBtn?.querySelector('.rdr-submit-spinner');
    form.addEventListener('submit', e=>{
        e.preventDefault();
        // Synchroniser TOUS les éditeurs TinyMCE → textareas avant FormData
        if (typeof tinyMCE !== 'undefined') {
            tinyMCE.triggerSave(); // sauvegarde toutes les instances actives
        }
        if(sbBtn) sbBtn.disabled=true;
        if(sbText) sbText.style.display='none';
        if(sbSpin) sbSpin.style.display='';
        const fd=new FormData(form);
        fd.set('action','rdr_ed_save_profile'); fd.set('nonce',RDR_ED.nonce);
        fetch(RDR_ED.ajaxurl,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(res=>{
            if(sbBtn) sbBtn.disabled=false;
            if(sbText) sbText.style.display=''; if(sbSpin) sbSpin.style.display='none';
            showMsg(res.success?res.data.message:res.data?.message||'Erreur.', res.success?'success':'error');
            if(res.success&&formMsg) formMsg.scrollIntoView({behavior:'smooth',block:'center'});
        }).catch(()=>{
            if(sbBtn) sbBtn.disabled=false; if(sbText) sbText.style.display=''; if(sbSpin) sbSpin.style.display='none';
            showMsg('Erreur serveur.','error');
        });
    });
})();

/* ============================================================
   MODULE — CONTACT SINGLE PROFIL
   ============================================================ */
(function () {
    let lastReveal = 0;

    /* Révéler coordonnées */
    document.addEventListener('click', e => {
        const btn = e.target.closest('.rdr-contact-reveal-btn');
        if (!btn) return;
        if (Date.now() - lastReveal < 800) return;
        const postId=btn.dataset.post, type=btn.dataset.type;
        const wrap=btn.closest('.rdr-contact-reveal-wrap');
        const sec=rdrED.getSecData();
        btn.disabled=true; btn.textContent='⏳…';
        rdrED.post('rdr_ed_reveal_contact',{
            post_id:postId, type,
            sec_token:sec.session_token||'', sec_answer:sec.challenge_answer||0, sec_ctoken:sec.challenge_token||'',
        }).then(res=>{
            lastReveal=Date.now();
            if(res.success){
                const val=res.data.value; let html='';
                if(type==='email') html=`<a href="mailto:${rdrED.esc(val)}" class="rdr-contact-value">${rdrED.esc(val)}</a>`;
                else if(type==='phone') html=`<a href="tel:${rdrED.esc(val)}" class="rdr-contact-value">${rdrED.esc(val)}</a>`;
                else html=`<a href="${rdrED.esc(val)}" class="rdr-contact-value" rel="nofollow noopener noreferrer" target="_blank">Voir le profil →</a>`;
                html+=` <button class="button button-sm button-ghost rdr-copy-contact" data-value="${rdrED.esc(val)}">Copier</button>`;
                if(wrap) wrap.innerHTML=html;
            } else {
                btn.disabled=false;
                btn.textContent=type==='email'?'📧 Voir l\'email':type==='phone'?'📞 Voir le téléphone':'💼 LinkedIn';
            }
        }).catch(()=>{ btn.disabled=false; btn.textContent='Réessayer'; });
    });

    /* Copier */
    document.addEventListener('click', e => {
        const btn=e.target.closest('.rdr-copy-contact'); if(!btn) return;
        if(navigator.clipboard&&btn.dataset.value) navigator.clipboard.writeText(btn.dataset.value).then(()=>{
            const o=btn.textContent; btn.textContent='✓ Copié !'; setTimeout(()=>{ btn.textContent=o; },2000);
        });
    });

    /* Toggle formulaire de contact */
    const toggle=document.getElementById('rdr-contact-toggle');
    const body=document.getElementById('rdr-contact-form-body');
    if(toggle&&body) toggle.addEventListener('click',()=>{
        const open=body.style.display!=='none'&&body.style.display!=='';
        open ? rdrED.slideUp(body) : rdrED.slideDown(body);
        toggle.textContent=open?'📩 Envoyer un message':'✕ Fermer';
    });

    /* Formulaire de contact */
    const cf=document.getElementById('rdr-contact-form');
    if(cf) cf.addEventListener('submit',e=>{
        e.preventDefault();
        const sb=cf.querySelector('button[type="submit"]');
        const msg=document.getElementById('rdr-contact-msg');
        const sec=rdrED.getSecData();
        if(sb){ sb.disabled=true; sb.textContent='Envoi…'; }
        const fd=new FormData(cf);
        fd.set('action','rdr_ed_send_message'); fd.set('nonce',RDR_ED.nonce);
        fd.set('sec_token',sec.session_token||''); fd.set('sec_answer',sec.challenge_answer||0); fd.set('sec_ctoken',sec.challenge_token||'');
        fetch(RDR_ED.ajaxurl,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()).then(res=>{
            if(sb){ sb.disabled=false; sb.textContent='Envoyer'; }
            if(msg){ msg.className=`rdr-form-message is-${res.success?'success':'error'}`; msg.innerHTML=res.success?res.data.message:res.data?.message||'Erreur.'; msg.style.display=''; }
            if(res.success) cf.reset();
        }).catch(()=>{ if(sb){ sb.disabled=false; sb.textContent='Envoyer'; } });
    });

    /* Shortlink copy */
    const sl=document.getElementById('rdr-copy-shortlink');
    if(sl) sl.addEventListener('click',()=>{
        if(navigator.clipboard&&sl.dataset.url) navigator.clipboard.writeText(sl.dataset.url).then(()=>{
            const o=sl.innerHTML; sl.textContent='✅ Lien copié !'; setTimeout(()=>{ sl.innerHTML=o; },2000);
        });
    });

    /* Switch langue (formulaire profil) */
    document.querySelectorAll('.rdr-lang-switch-btn').forEach(a=>{
        a.addEventListener('click',e=>{
            const f=document.getElementById('rdr-expert-profile-form'); if(!f) return;
            const dirty=[...f.querySelectorAll('input:not([type=hidden]),textarea,select')].some(el=>el.value&&el.value!==el.defaultValue);
            if(dirty&&!confirm('Changer de langue ? Les modifications non enregistrées seront perdues.')) e.preventDefault();
        });
    });
})();

/* ============================================================
   MODULE — EXPERTS MIS EN AVANT (featured)
   ============================================================ */
(function () {
    document.querySelectorAll('.rdr-featured-wrap').forEach(function (wrap) {
        const ajaxurl = wrap.dataset.ajaxurl || '/wp-admin/admin-ajax.php';
        const nonce   = wrap.dataset.nonce   || '';
        const limit   = parseInt(wrap.dataset.limit || 3);
        const grid    = wrap.querySelector('.rdr-featured-grid');
        if (!grid) return;

        const body = new FormData();
        body.append('action', 'rdr_ed_load_featured');
        body.append('nonce',  nonce);
        body.append('limit',  limit);

        fetch(ajaxurl, { method: 'POST', body, credentials: 'same-origin' })
            .then(r => r.text().then(t => { try { return JSON.parse(t); } catch(e) { return null; } }))
            .then(res => {
                if (!res || !res.success || !res.data.cards.length) {
                    grid.innerHTML = '';
                    return;
                }
                grid.innerHTML = res.data.cards.map(c => {
                    const skills = (c.skills || []).map(s =>
                        `<span class="rdr-featured-skill">${rdrED.esc(s)}</span>`
                    ).join('');
                    return `<a href="${rdrED.esc(c.url)}" class="rdr-featured-card">
                        <div class="rdr-featured-avatar">
                            ${c.photo ? `<img src="${rdrED.esc(c.photo)}" alt="" loading="lazy">` : '👤'}
                        </div>
                        <div class="rdr-featured-info">
                            <p class="rdr-featured-name">${rdrED.esc(c.name)}</p>
                            ${c.title ? `<p class="rdr-featured-title-line">${rdrED.esc(c.title)}</p>` : ''}
                            <div class="rdr-featured-skills">${skills}</div>
                        </div>
                    </a>`;
                }).join('');
            })
            .catch(() => { grid.innerHTML = ''; });
    });
})();

/* ── Admin : forcer tirage experts mis en avant ── */
(function () {
    const btn = document.getElementById('rdr-refresh-featured');
    if (!btn) return;
    btn.addEventListener('click', function () {
        btn.disabled = true; btn.textContent = '⏳ Tirage…';
        const body = new FormData();
        body.append('action', 'rdr_ed_refresh_featured');
        body.append('nonce',  btn.dataset.nonce || (typeof RDR_ED_Admin !== 'undefined' ? RDR_ED_Admin.nonce : ''));
        fetch(typeof RDR_ED_Admin !== 'undefined' ? RDR_ED_Admin.ajaxurl : '/wp-admin/admin-ajax.php',
              { method: 'POST', body, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                btn.disabled = false;
                btn.textContent = res.success ? '✅ ' + res.data.message : '❌ Erreur';
            })
            .catch(() => { btn.disabled = false; btn.textContent = '❌ Erreur réseau'; });
    });
})();

/* ── Messagerie interne : formulaire front ── */
(function () {
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('#rdr-internal-msg-toggle');
        if (!btn) return;
        const form = document.getElementById('rdr-internal-msg-form');
        if (!form) return;
        const open = form.style.display !== 'none' && form.style.display !== '';
        open ? rdrED.slideUp(form) : rdrED.slideDown(form);
        btn.textContent = open ? '💬 ' + (btn.dataset.labelOpen || 'Envoyer un message') : '✕ Fermer';
    });

    const msgForm = document.getElementById('rdr-internal-contact-form');
    if (!msgForm) return;

    msgForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const sb  = msgForm.querySelector('[type="submit"]');
        const msg = document.getElementById('rdr-internal-msg-result');
        if (sb) { sb.disabled = true; sb.textContent = '⏳ Envoi…'; }

        const fd = new FormData(msgForm);
        fd.set('action', 'rdr_ed_send_internal_message');
        fd.set('nonce',  typeof RDR_ED !== 'undefined' ? RDR_ED.nonce : '');
        fd.set('_msg_time', Math.floor(Date.now() / 1000));

        fetch(typeof RDR_ED !== 'undefined' ? RDR_ED.ajaxurl : '/wp-admin/admin-ajax.php',
              { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (sb) { sb.disabled = false; sb.textContent = 'Envoyer'; }
                if (msg) {
                    msg.className = `rdr-form-message is-${res.success ? 'success' : 'error'}`;
                    msg.innerHTML = res.success ? res.data.message : (res.data?.message || 'Erreur.');
                    msg.style.display = '';
                }
                if (res.success) { msgForm.reset(); }
            })
            .catch(() => {
                if (sb) { sb.disabled = false; sb.textContent = 'Envoyer'; }
            });
    });
})();

/* ── Suppression profil (DeleteMyProfil) ── */
(function () {
    const btn = document.getElementById('rdr-delete-profile-btn');
    if (!btn) return;

    const modal    = document.getElementById('rdr-delete-modal');
    const cancelBtn= document.getElementById('rdr-delete-cancel');
    const confirmBtn=document.getElementById('rdr-delete-confirm');
    const input    = document.getElementById('rdr-delete-confirm-input');
    const result   = document.getElementById('rdr-delete-result');
    const PHRASE   = 'DeleteMyProfil';

    btn.addEventListener('click', () => { if (modal) modal.style.display = 'flex'; });
    if (cancelBtn) cancelBtn.addEventListener('click', () => { if (modal) modal.style.display = 'none'; if (input) input.value = ''; });

    if (confirmBtn) confirmBtn.addEventListener('click', () => {
        if (!input || input.value !== PHRASE) {
            if (result) { result.textContent = `Tapez exactement : ${PHRASE}`; result.className = 'rdr-form-message is-error'; result.style.display = ''; }
            return;
        }
        confirmBtn.disabled = true; confirmBtn.textContent = '⏳ Suppression…';
        const fd = new FormData();
        fd.append('action',         'rdr_ed_delete_profile');
        fd.append('nonce',          typeof RDR_ED !== 'undefined' ? RDR_ED.nonce : '');
        fd.append('confirm_phrase', input.value);
        fetch(typeof RDR_ED !== 'undefined' ? RDR_ED.ajaxurl : '/wp-admin/admin-ajax.php',
              { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    confirmBtn.disabled = false; confirmBtn.textContent = 'Confirmer la suppression';
                    if (result) { result.innerHTML = res.data?.message || 'Erreur.'; result.className = 'rdr-form-message is-error'; result.style.display = ''; }
                    return;
                }
                if (result) { result.textContent = res.data.message; result.className = 'rdr-form-message is-success'; result.style.display = ''; }
                setTimeout(() => { window.location.href = res.data.redirect_url || '/'; }, 2000);
            })
            .catch(() => { confirmBtn.disabled = false; confirmBtn.textContent = 'Confirmer la suppression'; });
    });
})();

/* ── Marquage messages front ── */
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.rdr-mark-msg-front');
    if (!btn) return;
    const msgId = btn.dataset.id;
    const status= btn.dataset.status;
    const nonce = btn.dataset.nonce;
    const card  = btn.closest('.rdr-msg-card');

    const fd = new FormData();
    fd.append('action',  'rdr_ed_mark_message');
    fd.append('nonce',   nonce || (typeof RDR_ED !== 'undefined' ? RDR_ED.nonce : ''));
    fd.append('msg_id',  msgId);
    fd.append('status',  status);

    fetch(typeof RDR_ED !== 'undefined' ? RDR_ED.ajaxurl : '/wp-admin/admin-ajax.php',
          { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(r => r.json())
        .then(res => {
            if (res.success && card) {
                card.style.opacity = '0.4';
                setTimeout(() => card.remove(), 400);
            }
        });
});

/* ── Activation profil expert ── */
(function () {
    const btn = document.getElementById('rdr-activate-profile-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        btn.disabled = true; btn.textContent = '⏳ Activation…';
        const result = document.getElementById('rdr-activate-result');
        const fd = new FormData();
        fd.append('action', 'rdr_ed_activate_profile');
        fd.append('nonce',  btn.dataset.nonce);
        fetch(btn.dataset.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (result) {
                    result.textContent = res.success ? res.data.message : (res.data?.message || 'Erreur.');
                    result.className = 'rdr-form-message is-' + (res.success ? 'success' : 'error');
                    result.style.display = '';
                }
                if (res.success) {
                    btn.style.display = 'none';
                    setTimeout(() => { window.location.href = res.data.redirect || btn.dataset.redirect || '/'; }, 1500);
                } else {
                    btn.disabled = false;
                    btn.textContent = '✨ ' + (btn.dataset.label || 'Activer mon profil Expert');
                }
            })
            .catch(() => { btn.disabled = false; });
    });
})();
