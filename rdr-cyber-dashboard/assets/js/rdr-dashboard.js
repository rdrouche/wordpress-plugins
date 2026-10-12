/* RDR Cyber Dashboard v1.3.0 — vanilla JS, zéro dépendance */
'use strict';

(function () {

    /* ================================================================
       DASHBOARD — état et logique
       ================================================================ */
    var state = {
        page: 1, search: '', source: '', severity: '', type: '',
        total: 0, pages: 1, timer: null,
    };

    function dashInit() {
        bindDashEvents();
        fetchItems();
    }

    function bindDashEvents() {
        var searchEl = document.getElementById('rdr-search');
        if (searchEl) {
            searchEl.addEventListener('input', function () {
                clearTimeout(state.timer);
                state.timer = setTimeout(function () {
                    state.search = searchEl.value;
                    state.page   = 1;
                    fetchItems();
                }, 350);
            });
        }

        document.addEventListener('change', function (e) {
            if (!e.target.classList.contains('rdr-filter-select')) return;
            var id = e.target.id;
            if      (id === 'rdr-filter-source')   state.source   = e.target.value;
            else if (id === 'rdr-filter-severity')  state.severity = e.target.value;
            else if (id === 'rdr-filter-type')      state.type     = e.target.value;
            state.page = 1;
            fetchItems();
        });

        document.addEventListener('click', function (e) {
            if (e.target.id !== 'rdr-reset') return;
            state.search = ''; state.source = ''; state.severity = ''; state.type = ''; state.page = 1;
            var searchEl = document.getElementById('rdr-search');
            if (searchEl) searchEl.value = '';
            document.querySelectorAll('.rdr-filter-select').forEach(function (s) { s.value = ''; });
            fetchItems();
        });

        document.addEventListener('click', function (e) {
            if (!e.target.classList.contains('rdr-tag')) return;
            var tag = e.target.textContent.trim();
            state.search = tag; state.page = 1;
            var searchEl = document.getElementById('rdr-search');
            if (searchEl) searchEl.value = tag;
            fetchItems();
        });

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.rdr-page-btn');
            if (!btn || btn.disabled || btn.classList.contains('active')) return;
            var p = parseInt(btn.dataset.page, 10);
            if (p >= 1 && p <= state.pages) {
                state.page = p;
                fetchItems();
                var grid = document.getElementById('rdr-cards-grid');
                if (grid) {
                    var top = grid.getBoundingClientRect().top + window.pageYOffset - 80;
                    window.scrollTo({ top: top, behavior: 'smooth' });
                }
            }
        });
    }

    function fetchItems() {
        var grid  = document.getElementById('rdr-cards-grid');
        var count = document.getElementById('rdr-result-count');
        var pager = document.getElementById('rdr-pagination');
        if (!grid) return;

        grid.innerHTML = '<div class="rdr-loading"><div class="rdr-spinner"></div><span>Chargement…</span></div>';
        if (pager) pager.innerHTML = '';

        ajaxPost(rdrCyber.ajaxurl, {
            action: 'rdr_cyber_fetch', nonce: rdrCyber.nonce,
            search: state.search, source: state.source,
            severity: state.severity, item_type: state.type, page: state.page,
        }).then(function (res) {
            if (!res.success) { grid.innerHTML = '<div class="rdr-empty"><p>Erreur de chargement.</p></div>'; return; }
            state.total = res.data.total; state.pages = res.data.pages; state.page = res.data.current;
            if (count) count.innerHTML = '<strong>' + res.data.total + '</strong> résultat' + (res.data.total !== 1 ? 's' : '');
            if (!res.data.html || !res.data.html.trim()) {
                grid.innerHTML = '<div class="rdr-empty"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg><p>Aucun résultat trouvé.</p></div>';
            } else {
                grid.innerHTML = res.data.html;
                animateCards();
            }
            if (pager) renderPagination(pager, state.page, state.pages);
        }).catch(function () {
            grid.innerHTML = '<div class="rdr-empty"><p>Erreur réseau. Veuillez réessayer.</p></div>';
        });
    }

    function animateCards() {
        document.querySelectorAll('.rdr-card').forEach(function (card, i) {
            card.style.opacity = '0'; card.style.transform = 'translateY(12px)';
            setTimeout(function () {
                card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                card.style.opacity = '1'; card.style.transform = 'translateY(0)';
            }, i * 40);
        });
    }

    function renderPagination(pager, current, total) {
        if (total <= 1) return;
        var html = '';
        html += '<button class="rdr-page-btn" data-page="' + (current - 1) + '"' + (current <= 1 ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 18l-6-6 6-6"/></svg></button>';
        buildPageRange(current, total).forEach(function (p) {
            if (p === '...') html += '<span style="padding:0 4px;color:var(--rdr-text-muted)">…</span>';
            else html += '<button class="rdr-page-btn' + (p === current ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>';
        });
        html += '<button class="rdr-page-btn" data-page="' + (current + 1) + '"' + (current >= total ? ' disabled' : '') + '><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg></button>';
        pager.innerHTML = html;
    }

    function buildPageRange(current, total) {
        var pages = [];
        if (total <= 7) { for (var i = 1; i <= total; i++) pages.push(i); return pages; }
        pages.push(1);
        if (current > 3) pages.push('...');
        var start = Math.max(2, current - 1), end = Math.min(total - 1, current + 1);
        for (var j = start; j <= end; j++) pages.push(j);
        if (current < total - 2) pages.push('...');
        pages.push(total);
        return pages;
    }

    /* ================================================================
       ADMIN — bouton collecte manuelle
       ================================================================ */
    document.addEventListener('click', function (e) {
        if (e.target.id !== 'rdr-collect-now') return;
        var btn = e.target;
        btn.disabled = true; btn.textContent = 'Collecte en cours…';
        var adminNonce = (typeof rdrCyberAdmin !== 'undefined') ? rdrCyberAdmin.nonce : '';
        ajaxPost(rdrCyber.ajaxurl, { action: 'rdr_cyber_collect', nonce: adminNonce })
        .then(function (res) {
            var notice = document.createElement('span');
            notice.style.cssText = 'margin-left:12px;color:var(--rdr-accent-2,#00e5c0);font-size:13px';
            notice.textContent   = res.success
                ? (res.data.inserted + ' nouveau(x) élément(s) collecté(s).')
                : 'Erreur lors de la collecte.';
            btn.insertAdjacentElement('afterend', notice);
            setTimeout(function () { location.reload(); }, 2200);
        }).catch(function () {
            btn.disabled = false; btn.textContent = '↻ Collecter maintenant';
        });
    });

    /* ADMIN — traitement file d'attente */
    document.addEventListener('click', function (e) {
        if (e.target.id !== 'rdr-process-queue') return;
        var btn = e.target;
        btn.disabled = true; btn.textContent = 'Traitement…';
        var adminNonce = (typeof rdrCyberAdmin !== 'undefined') ? rdrCyberAdmin.nonce : '';
        ajaxPost(rdrCyber.ajaxurl, { action: 'rdr_cyber_test_queue', nonce: adminNonce })
        .then(function (res) {
            if (res.success) {
                var s = res.data.stats;
                btn.textContent = '✓ Traité — ' + s.pending + ' restant(s)';
                setTimeout(function () { location.reload(); }, 1500);
            } else {
                btn.disabled = false; btn.textContent = 'Traiter maintenant';
            }
        }).catch(function () {
            btn.disabled = false; btn.textContent = 'Traiter maintenant';
        });
    });

    /* ADMIN — email de test */
    document.addEventListener('click', function (e) {
        if (e.target.id !== 'rdr-send-test-email') return;
        var btn   = e.target;
        var toEl  = document.getElementById('rdr-test-email-to');
        var msgEl = document.getElementById('rdr-test-email-msg');
        var to    = toEl ? toEl.value : '';
        btn.disabled = true; btn.textContent = 'Envoi…';
        var adminNonce = (typeof rdrCyberAdmin !== 'undefined') ? rdrCyberAdmin.nonce : '';
        ajaxPost(rdrCyber.ajaxurl, { action: 'rdr_cyber_test_email', nonce: adminNonce, to: to })
        .then(function (res) {
            btn.disabled = false; btn.textContent = 'Envoyer l\'email de test';
            if (msgEl) {
                msgEl.style.display = 'block';
                msgEl.style.color   = res.success ? '#2ecc71' : '#ff4757';
                msgEl.textContent   = res.success ? res.data.message : (res.data ? res.data.message : 'Erreur inconnue.');
            }
        }).catch(function () {
            btn.disabled = false; btn.textContent = 'Envoyer l\'email de test';
            if (msgEl) { msgEl.style.display='block'; msgEl.style.color='#ff4757'; msgEl.textContent='Erreur réseau.'; }
        });
    });

    /* ADMIN — push de test */
    document.addEventListener('click', function (e) {
        if (e.target.id !== 'rdr-send-test-push') return;
        var btn   = e.target;
        var msgEl = document.getElementById('rdr-test-push-msg');
        btn.disabled = true; btn.textContent = 'Envoi push…';
        var adminNonce = (typeof rdrCyberAdmin !== 'undefined') ? rdrCyberAdmin.nonce : '';
        ajaxPost(rdrCyber.ajaxurl, { action: 'rdr_cyber_push_test', nonce: adminNonce })
        .then(function (res) {
            btn.disabled = false; btn.textContent = 'Envoyer le push de test';
            if (msgEl) {
                msgEl.style.display = 'block';
                msgEl.style.color   = res.success ? '#2ecc71' : '#ff4757';
                msgEl.textContent   = res.success ? res.data.message : (res.data ? res.data.message : 'Erreur inconnue.');
            }
        }).catch(function () {
            btn.disabled = false; btn.textContent = 'Envoyer le push de test';
            if (msgEl) { msgEl.style.display='block'; msgEl.style.color='#ff4757'; msgEl.textContent='Erreur réseau.'; }
        });
    });

    /* ADMIN — génération clés VAPID */
    function handleVapidBtn(btnId) {
        document.addEventListener('click', function (e) {
            if (e.target.id !== btnId) return;
            var btn    = e.target;
            var msgEl  = document.getElementById('rdr-vapid-msg');
            var ok     = btnId === 'rdr-vapid-regen' ? confirm('Régénérer les clés VAPID invalide tous les abonnements push existants. Continuer ?') : true;
            if (!ok) return;
            btn.disabled = true; btn.textContent = 'Génération…';
            var adminNonce = (typeof rdrCyberAdmin !== 'undefined') ? rdrCyberAdmin.nonce : '';
            ajaxPost(rdrCyber.ajaxurl, { action: 'rdr_cyber_vapid_generate', nonce: adminNonce })
            .then(function (res) {
                btn.disabled = false; btn.textContent = btnId === 'rdr-vapid-regen' ? '↺ Régénérer les clés' : '⚡ Générer les clés VAPID';
                if (msgEl) {
                    msgEl.style.display = 'block';
                    msgEl.style.color   = res.success ? '#2ecc71' : '#ff4757';
                    msgEl.textContent   = res.success ? (res.data.message + ' Rechargez la page.') : (res.data ? res.data.message : 'Erreur inconnue.');
                }
                if (res.success) setTimeout(function () { location.reload(); }, 1800);
            }).catch(function () {
                btn.disabled = false;
                if (msgEl) { msgEl.style.display='block'; msgEl.style.color='#ff4757'; msgEl.textContent='Erreur réseau.'; }
            });
        });
    }
    handleVapidBtn('rdr-vapid-generate');
    handleVapidBtn('rdr-vapid-regen');

    /* ================================================================
       FORMULAIRE D'ABONNEMENT EMAIL
       ================================================================ */
    var subForm = document.getElementById('rdr-subscribe-form');
    if (subForm) {
        subForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var email     = (document.getElementById('rdr-sub-email') || {}).value || '';
            var name      = (document.getElementById('rdr-sub-name')  || {}).value || '';
            var freqEl    = subForm.querySelector('input[name="frequency"]:checked');
            var frequency = freqEl ? freqEl.value : 'weekly';
            var msgEl     = document.getElementById('rdr-sub-message');
            var btnText   = document.getElementById('rdr-sub-btn-text');
            var btnSpin   = document.getElementById('rdr-sub-btn-spinner');
            var submitBtn = document.getElementById('rdr-sub-submit');

            if (!email) { showSubMsg(msgEl, 'Veuillez saisir une adresse email.', 'error'); return; }

            submitBtn.disabled = true;
            if (btnText) btnText.style.display = 'none';
            if (btnSpin) btnSpin.style.display  = 'flex';

            ajaxPost(rdrCyber.ajaxurl, {
                action: 'rdr_cyber_subscribe', nonce: rdrCyber.nonce,
                email: email, name: name, frequency: frequency,
            }).then(function (res) {
                submitBtn.disabled = false;
                if (btnText) btnText.style.display = 'inline';
                if (btnSpin) btnSpin.style.display  = 'none';
                if (res.success) {
                    showSubMsg(msgEl, res.data.message, 'success');
                    subForm.reset();
                } else {
                    showSubMsg(msgEl, res.data.message || 'Une erreur est survenue.', 'error');
                }
            }).catch(function () {
                submitBtn.disabled = false;
                if (btnText) btnText.style.display = 'inline';
                if (btnSpin) btnSpin.style.display  = 'none';
                showSubMsg(msgEl, 'Erreur réseau. Veuillez réessayer.', 'error');
            });
        });
    }

    function showSubMsg(el, text, type) {
        if (!el) return;
        el.textContent   = text;
        el.className     = 'rdr-sub-message ' + type;
        el.style.display = 'block';
    }

    /* ================================================================
       UTILITAIRE : fetch POST
       ================================================================ */
    function ajaxPost(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString(),
        }).then(function (r) {
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        });
    }

    /* ================================================================
       DOM READY
       ================================================================ */
    function onReady(fn) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
        else fn();
    }

    onReady(function () {
        if (document.getElementById('rdr-cards-grid')) dashInit();
    });

})();
