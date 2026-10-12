/**
 * RDR Newsletter — front.js  v5
 * Vanilla JS — no jQuery dependency.
 * Handles: subscribe form, profile request form, profile feed editor.
 */
(function () {
    'use strict';

    var cfg = (typeof window.rdrnlFront !== 'undefined') ? window.rdrnlFront : {};
    var ajaxurl   = cfg.ajaxurl   || '';
    var nonce     = cfg.nonce     || '';
    var rdrnl_tok = cfg.rdrnl_tok || '';

    /* ── Helpers ── */
    function post(action, data, cb) {
        var body = new FormData();
        body.append('action', action);
        body.append('nonce', nonce);
        for (var k in data) body.append(k, data[k]);
        fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(cb)
            .catch(function(){ cb({ success: false, data: 'Erreur réseau.' }); });
    }

    function on(sel, evt, fn) {
        document.addEventListener(evt, function(e) {
            var t = e.target.closest(sel);
            if (t) fn.call(t, e);
        });
    }

    function el(id) { return document.getElementById(id); }

    function showMsg(el, text, type) {
        if (!el) return;
        el.className = 'rdrnl-ajax-msg rdrnl-notice rdrnl-notice-' + (type || 'info');
        el.textContent = text;
        el.style.display = 'block';
    }

    function setDisabled(btn, label, spinner) {
        btn.disabled = true;
        btn.innerHTML = spinner
            ? '<span class="rdrnl-front-spinner"></span> ' + label
            : label;
    }

    function setEnabled(btn, label) {
        btn.disabled = false;
        btn.textContent = label;
    }

    /* ════════════════════════════════════════════
       FORMULAIRE D'INSCRIPTION  [rdrnl_subscribe]
    ════════════════════════════════════════════ */

    on('#rdrnl-sub-btn', 'click', function() {
        var btn   = el('rdrnl-sub-btn');
        var email = (el('rdrnl-sub-email') || {}).value || '';
        var name  = (el('rdrnl-sub-name')  || {}).value || '';
        var msg   = el('rdrnl-sub-msg');

        email = email.trim();
        if (!email) {
            showMsg(msg, 'Veuillez saisir votre adresse email.', 'error');
            if (el('rdrnl-sub-email')) el('rdrnl-sub-email').focus();
            return;
        }

        setDisabled(btn, 'Envoi…', true);

        post('rdrnl_subscribe_ajax', { email: email, name: name }, function(res) {
            setEnabled(btn, res.status === 'waitlist' ? "Rejoindre la liste d\u2019attente" : "S\u2019inscrire");
            showMsg(msg, res.message || '', res.status);
            if (['pending','waitlist','active','already_active'].indexOf(res.status) !== -1) {
                var fields = document.querySelector('.rdrnl-form-fields');
                if (fields) fields.style.display = 'none';
            }
        });
    });

    /* ════════════════════════════════════════════
       SHORTCODE [rdrnl_profile_request] — bouton -sc
    ════════════════════════════════════════════ */

    on('#rdrnl-req-btn-sc', 'click', function() {
        var btn   = el('rdrnl-req-btn-sc');
        var email = (el('rdrnl-req-email-sc') || {}).value || '';
        var msg   = el('rdrnl-req-msg-sc');

        email = email.trim();
        if (!email) {
            showMsg(msg, 'Veuillez saisir votre adresse email.', 'error');
            return;
        }

        setDisabled(btn, 'Envoi…', true);

        post('rdrnl_request_profile_link', { email: email }, function(res) {
            setEnabled(btn, 'Recevoir mon lien');
            if (res.success) {
                showMsg(msg, res.data || 'Lien envoy\u00e9\u00a0! V\u00e9rifiez votre bo\u00eete mail.', 'ok');
                var inp = el('rdrnl-req-email-sc');
                if (inp) inp.disabled = true;
                btn.disabled = true;
            } else {
                showMsg(msg, res.data || 'Erreur.', 'error');
            }
        });
    });

    /* ════════════════════════════════════════════
       PAGE PROFIL — Demande de lien (page auth)
    ════════════════════════════════════════════ */

    on('#rdrnl-req-btn', 'click', function() {
        var btn   = el('rdrnl-req-btn');
        var email = (el('rdrnl-req-email') || {}).value || '';
        var msg   = el('rdrnl-req-msg');

        email = email.trim();
        if (!email) {
            showMsg(msg, 'Veuillez saisir votre adresse email.', 'error');
            return;
        }

        setDisabled(btn, 'Envoi…', true);

        post('rdrnl_request_profile_link', { email: email }, function(res) {
            setEnabled(btn, 'Recevoir mon lien');
            if (res.success) {
                showMsg(msg, res.data || 'Lien envoy\u00e9\u00a0!', 'ok');
                var inp = el('rdrnl-req-email');
                if (inp) inp.disabled = true;
                btn.disabled = true;
            } else {
                showMsg(msg, res.data || 'Erreur.', 'error');
            }
        });
    });

    /* ════════════════════════════════════════════
       PAGE PROFIL — Validation de flux (temps réel)
    ════════════════════════════════════════════ */

    var validateTimers = {};

    on('.rdrnl-feed-url', 'blur', function() {
        var slot = this.dataset.slot;
        var url  = this.value.trim();
        if (url) triggerValidate(slot, url, true);
    });

    on('.rdrnl-feed-url', 'input', function() {
        var slot = this.dataset.slot;
        var url  = this.value.trim();
        clearTimeout(validateTimers[slot]);
        var res = document.getElementById('rdrnl-check-' + slot);
        if (res) { res.textContent = ''; res.className = 'rdrnl-feed-check-result'; }
        var slotEl = this.closest('.rdrnl-feed-slot');
        if (slotEl) {
            slotEl.classList.toggle('has-feed',  url.length > 0);
            slotEl.classList.toggle('empty-slot', url.length === 0);
        }
    });

    on('.rdrnl-btn-check-feed', 'click', function() {
        var slot = this.dataset.slot;
        var inp  = document.querySelector('.rdrnl-feed-url[data-slot="' + slot + '"]');
        var url  = inp ? inp.value.trim() : '';
        if (url) triggerValidate(slot, url, true);
    });

    function triggerValidate(slot, url, force) {
        if (!force) {
            clearTimeout(validateTimers[slot]);
            validateTimers[slot] = setTimeout(function(){ doValidate(slot, url); }, 600);
        } else {
            doValidate(slot, url);
        }
    }

    function doValidate(slot, url) {
        var res = document.getElementById('rdrnl-check-' + slot);
        var btn = document.querySelector('.rdrnl-btn-check-feed[data-slot="' + slot + '"]');
        var icon = btn ? btn.querySelector('.rdrnl-check-icon') : null;

        if (res) { res.textContent = '\u23f3 V\u00e9rification\u2026'; res.className = 'rdrnl-feed-check-result'; }
        if (icon) icon.textContent = '\u23f3';

        post('rdrnl_validate_feed', { url: url, rdrnl_tok: rdrnl_tok }, function(r) {
            if (icon) icon.textContent = '\uD83D\uDD0D';
            if (res) {
                if (r.success) {
                    res.textContent = '\u2713 ' + (r.data.title || url);
                    res.className = 'rdrnl-feed-check-result ok';
                    var inp = document.querySelector('.rdrnl-feed-label');
                    if (inp && inp.value === '' && r.data.title) {
                        document.querySelectorAll('.rdrnl-feed-slot[data-slot="' + slot + '"] .rdrnl-feed-label')
                            .forEach(function(l){ if (!l.value) l.value = r.data.title; });
                    }
                } else {
                    res.textContent = '\u2717 ' + (r.data || 'Flux invalide');
                    res.className = 'rdrnl-feed-check-result error';
                }
            }
        });
    }

    /* ════════════════════════════════════════════
       PAGE PROFIL — Sauvegarde des préférences
    ════════════════════════════════════════════ */

    on('#rdrnl-save-profile', 'click', function() {
        var btn  = el('rdrnl-save-profile');
        var msg  = el('rdrnl-prof-msg');
        var feeds = [];

        document.querySelectorAll('.rdrnl-feed-slot').forEach(function(slot) {
            var url   = (slot.querySelector('.rdrnl-feed-url')   || {}).value || '';
            var label = (slot.querySelector('.rdrnl-feed-label') || {}).value || '';
            var limit = parseInt((slot.querySelector('.rdrnl-feed-limit') || {}).value || '5', 10);
            if (url.trim()) feeds.push({ url: url.trim(), label: label, limit: Math.min(limit, 10) });
        });

        setDisabled(btn, 'Enregistrement\u2026', true);

        // Serialize feeds for FormData (nested objects need manual serialization)
        var body = new FormData();
        body.append('action', 'rdrnl_save_profile');
        body.append('nonce', nonce);
        body.append('rdrnl_tok', rdrnl_tok);
        feeds.forEach(function(f, i) {
            body.append('feeds[' + i + '][url]',   f.url);
            body.append('feeds[' + i + '][label]', f.label);
            body.append('feeds[' + i + '][limit]', f.limit);
        });

        fetch(ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(res) {
                setEnabled(btn, 'Enregistrer mes pr\u00e9f\u00e9rences');
                if (res.success) {
                    showMsg(msg, '\u2713 ' + (res.data || 'Pr\u00e9f\u00e9rences enregistr\u00e9es\u00a0!'), 'ok');
                    window.scrollTo({ top: msg ? msg.getBoundingClientRect().top + window.scrollY - 80 : 0, behavior: 'smooth' });
                } else {
                    showMsg(msg, '\u2717 ' + (res.data || 'Erreur.'), 'error');
                }
            })
            .catch(function() {
                setEnabled(btn, 'Enregistrer mes pr\u00e9f\u00e9rences');
                showMsg(msg, 'Erreur r\u00e9seau.', 'error');
            });
    });

})();
