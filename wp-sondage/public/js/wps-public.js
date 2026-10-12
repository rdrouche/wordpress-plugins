/**
 * WP Sondage — Frontend JS v2.0 — Vanilla JS, zéro jQuery
 */
(function () {
    'use strict';

    var DATA   = window.wpsData || {};
    var AJAX   = DATA.ajaxurl  || '';
    var I18N   = DATA.i18n     || {};

    // Le nonce du HTML peut être périmé si la page vient d'un cache (LSCache,
    // Cloudflare...). Chaque réponse AJAX renvoie un nonce frais : on l'adopte.
    var NONCE  = DATA.nonce || '';
    function adoptNonce(res) {
        if (res && res.data && res.data.nonce) NONCE = res.data.nonce;
    }

    /* ── POST helper (remplace $.post) ── */
    function post(params, done, fail) {
        var body = new URLSearchParams();
        body.append('nonce', NONCE);
        Object.keys(params).forEach(function(k) {
            var v = params[k];
            if (Array.isArray(v)) {
                v.forEach(function(item) { body.append(k + '[]', item); });
            } else {
                body.append(k, v);
            }
        });
        fetch(AJAX, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Cache-Control': 'no-cache',
            }
        })
            .then(function(r) { return r.json(); })
            .then(function(res) {
                adoptNonce(res);
                if (done) done(res);
            })
            .catch(fail || function() {});
    }

    /**
     * Récupère un nonce frais puis rejoue l'action.
     * Utilisé quand le serveur répond stale_nonce (page servie depuis un cache).
     */
    function retryWithFreshNonce(pollId, params, done, fail) {
        post({ action: 'wps_poll_state', poll_id: pollId }, function(res) {
            if (res && res.success && res.data && res.data.nonce) {
                NONCE = res.data.nonce;
                post(params, done, fail);
            } else if (fail) { fail(); }
        }, fail);
    }

    /* ── DOM helpers ── */
    function qs(sel, ctx)  { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }
    function closest(el, sel) {
        while (el && el !== document) {
            if (el.matches && el.matches(sel)) return el;
            el = el.parentElement;
        }
        return null;
    }
    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = String(str);
        return d.innerHTML;
    }
    function fadeOut(el, ms, cb) {
        el.style.transition = 'opacity ' + ms + 'ms';
        el.style.opacity = '0';
        setTimeout(function() { el.style.display = 'none'; el.style.transition = ''; el.style.opacity = ''; if (cb) cb(); }, ms);
    }
    function fadeIn(el, ms) {
        el.style.display = '';
        el.style.opacity = '0';
        el.style.transition = 'opacity ' + ms + 'ms';
        requestAnimationFrame(function() { el.style.opacity = '1'; });
        setTimeout(function() { el.style.transition = ''; }, ms);
    }
    function slideUp(el, ms, cb) {
        el.style.overflow = 'hidden';
        el.style.height   = el.offsetHeight + 'px';
        el.style.transition = 'height ' + ms + 'ms ease';
        requestAnimationFrame(function() { el.style.height = '0'; });
        setTimeout(function() {
            el.style.display = 'none';
            el.style.height  = '';
            el.style.transition = '';
            el.style.overflow = '';
            if (cb) cb();
        }, ms);
    }
    function slideDown(el, ms) {
        el.style.display  = '';
        el.style.overflow = 'hidden';
        el.style.height   = '0';
        el.style.transition = 'height ' + ms + 'ms ease';
        requestAnimationFrame(function() { el.style.height = el.scrollHeight + 'px'; });
        setTimeout(function() { el.style.height = ''; el.style.overflow = ''; el.style.transition = ''; }, ms);
    }

    /* ════════════════════════════════════════════
       THÈME SOMBRE — détection + MutationObserver
    ════════════════════════════════════════════ */
    var DARK_SEL = [
        'html.dark','html.dark-mode','html.night-mode','html.theme-dark',
        'body.dark','body.dark-mode','body.night-mode','body.theme-dark',
        'html[data-theme="dark"]','html[data-color-mode="dark"]',
        'html[data-bs-theme="dark"]','body[data-theme="dark"]',
    ];

    function isDark() {
        return DARK_SEL.some(function(s) {
            try { return !!document.querySelector(s); } catch(e) { return false; }
        });
    }

    function applyTheme(root) {
        var dark = isDark();
        qsa('.wps-poll.wps-theme-auto', root).forEach(function(el) {
            el.classList.add('wps-theme-resolved');
            el.classList.toggle('wps-is-dark', dark);
        });
        // Archive : appliquer sur toute la page
        var arch = qs('.wps-archive-wrap');
        if (arch) arch.classList.toggle('wps-is-dark', dark);
    }

    if ('MutationObserver' in window) {
        var mo = new MutationObserver(function(muts) {
            var rel = muts.some(function(m) {
                return ['class','data-theme','data-color-mode','data-bs-theme'].indexOf(m.attributeName) > -1;
            });
            if (rel) applyTheme();
        });
        mo.observe(document.documentElement, { attributes: true });
        mo.observe(document.body,            { attributes: true });
    }

    /* ════════════════════════════════════════════
       VOTE
    ════════════════════════════════════════════ */
    document.addEventListener('submit', function(e) {
        var form = closest(e.target, '.wps-form');
        if (!form) return;
        e.preventDefault();

        var poll     = closest(form, '.wps-poll');
        var pollId   = poll.dataset.pollId;
        var btn      = form.querySelector('.wps-btn-vote');
        var msg      = form.querySelector('.wps-msg');
        var spinner  = form.querySelector('.wps-spinner');
        var ids      = [];
        var seen     = {};

        form.querySelectorAll('.wps-choice-input:checked').forEach(function(inp) {
            if (!seen[inp.value]) { seen[inp.value] = 1; ids.push(inp.value); }
        });

        if (!ids.length) { showMsg(msg, I18N.pick_one, 'error'); return; }

        btn.disabled = true;
        if (spinner) spinner.classList.add('wps-spinner--active');
        if (msg) { msg.textContent = ''; msg.className = 'wps-msg'; }

        post({ action: 'wps_vote', poll_id: pollId, choice_ids: ids }, function(res) {
            if (res.success) {
                if (msg) showMsg(msg, res.data.message, 'success');
                var panelForm = closest(form, '.wps-panel-form') || form;
                fadeOut(panelForm, 250, function() {
                    if (spinner) spinner.classList.remove('wps-spinner--active');
                    poll.querySelector('.wps-panel-peek') && (poll.querySelector('.wps-panel-peek').style.display = 'none');
                    if (res.data.public && res.data.results) {
                        var panelRes = poll.querySelector('.wps-panel-results');
                        if (panelRes) { panelRes.innerHTML = ''; buildResults(panelRes, res.data.results); fadeIn(panelRes, 250); }
                        else          { var d = makeResultsPanel(res.data.results); poll.querySelector('.wps-admin-actions, .wps-more-link') ? poll.querySelector('.wps-admin-actions, .wps-more-link').before(d) : poll.appendChild(d); fadeIn(d, 250); }
                    } else {
                        var notice = document.createElement('p');
                        notice.className = 'wps-notice wps-voted';
                        notice.textContent = '✅ ' + I18N.vote_success;
                        panelForm.after(notice);
                    }
                    if (res.data.can_reset) { appendResetBtn(poll, pollId); }
                });
            } else if (res.data && res.data.code === 'stale_nonce' && !form.dataset.retried) {
                // Page servie depuis un cache : le nonce était périmé.
                // On en récupère un frais et on rejoue le vote une seule fois.
                form.dataset.retried = '1';
                retryWithFreshNonce(pollId,
                    { action: 'wps_vote', poll_id: pollId, choice_ids: ids },
                    function(r2) {
                        if (r2 && r2.success) { location.reload(); }
                        else {
                            showMsg(msg, (r2 && r2.data && r2.data.message) || I18N.vote_error, 'error');
                            btn.disabled = false;
                            if (spinner) spinner.classList.remove('wps-spinner--active');
                        }
                    },
                    function() {
                        showMsg(msg, I18N.vote_error, 'error');
                        btn.disabled = false;
                        if (spinner) spinner.classList.remove('wps-spinner--active');
                    }
                );
            } else {
                showMsg(msg, (res.data && res.data.message) || I18N.vote_error, 'error');
                btn.disabled = false;
                if (spinner) spinner.classList.remove('wps-spinner--active');
            }
        }, function() {
            showMsg(msg, I18N.vote_error, 'error');
            btn.disabled = false;
            if (spinner) spinner.classList.remove('wps-spinner--active');
        });
    });

    /* ════════════════════════════════════════════
       APERÇU RÉSULTATS (peek)
    ════════════════════════════════════════════ */
    document.addEventListener('click', function(e) {
        var btn = closest(e.target, '.wps-btn-peek');
        if (!btn) return;
        var poll       = closest(btn, '.wps-poll');
        var panelForm  = poll.querySelector('.wps-panel-form');
        var panelPeek  = poll.querySelector('.wps-panel-peek');
        var pollId     = btn.dataset.pollId;

        if (panelPeek.dataset.loaded) {
            fadeOut(panelForm, 180, function() { fadeIn(panelPeek, 180); });
            return;
        }

        btn.disabled = true;
        btn.textContent = I18N.loading;

        post({ action: 'wps_peek_results', poll_id: pollId }, function(res) {
            if (res.success && res.data.results) {
                var resDiv = document.createElement('div');
                resDiv.className = 'wps-results';
                buildResults(resDiv, res.data.results);
                panelPeek.querySelector('.wps-peek-notice').before(resDiv);
                panelPeek.dataset.loaded = '1';
                fadeOut(panelForm, 180, function() { fadeIn(panelPeek, 180); });
            } else {
                var msgEl = poll.querySelector('.wps-msg');
                if (msgEl) showMsg(msgEl, (res.data && res.data.message) || I18N.vote_error, 'error');
                btn.disabled = false;
                btn.textContent = I18N.see_results;
            }
        }, function() {
            btn.disabled = false;
            btn.textContent = I18N.see_results;
        });
    });

    /* ── Retour au formulaire ── */
    document.addEventListener('click', function(e) {
        var btn = closest(e.target, '.wps-btn-back-vote');
        if (!btn) return;
        var poll = closest(btn, '.wps-poll');
        fadeOut(poll.querySelector('.wps-panel-peek'), 180, function() {
            fadeIn(poll.querySelector('.wps-panel-form'), 180);
            var peekBtn = poll.querySelector('.wps-btn-peek');
            if (peekBtn) { peekBtn.disabled = false; peekBtn.textContent = I18N.see_results; }
        });
    });

    /* ════════════════════════════════════════════
       RESET
    ════════════════════════════════════════════ */
    function appendResetBtn(poll, pollId) {
        var wrap = poll.querySelector('.wps-admin-actions');
        if (!wrap) { wrap = document.createElement('div'); wrap.className = 'wps-admin-actions'; poll.appendChild(wrap); }
        var btn = document.createElement('button');
        btn.type = 'button'; btn.className = 'wps-btn-reset';
        btn.textContent = I18N.reset_poll;
        wrap.appendChild(btn);
    }

    document.addEventListener('click', function(e) {
        var btn = closest(e.target, '.wps-btn-reset');
        if (!btn) return;
        var poll   = closest(btn, '.wps-poll');
        var pollId = (poll && poll.dataset.pollId) || btn.dataset.pollId;
        if (!confirm(I18N.reset_confirm)) return;
        btn.disabled = true; btn.textContent = '…';
        post({ action: 'wps_reset_poll', poll_id: pollId }, function(res) {
            if (res.success) { location.reload(); }
            else { alert((res.data && res.data.message) || I18N.vote_error); btn.disabled = false; btn.textContent = I18N.reset_poll; }
        }, function() { btn.disabled = false; btn.textContent = I18N.reset_poll; });
    });

    /* ════════════════════════════════════════════
       SOUS-QUESTIONS CONDITIONNELLES
    ════════════════════════════════════════════ */
    document.addEventListener('change', function(e) {
        var inp = e.target;
        if (!inp.classList.contains('wps-choice-input')) return;
        var item = closest(inp, '.wps-choice-item');
        if (!item || !item.dataset.hasChildren) return;
        var sub  = item.querySelector(':scope > .wps-sub-choices');
        var poll = closest(inp, '.wps-poll');
        var type = poll ? poll.dataset.type : 'radio';

        if (inp.checked) { slideDown(sub, 180); }
        else             { slideUp(sub, 150, function() { sub.querySelectorAll('.wps-choice-input').forEach(function(i){ i.checked = false; }); }); }

        if (type === 'radio') {
            qsa('.wps-choice-item[data-has-children]', item.parentElement).forEach(function(sib) {
                if (sib === item) return;
                var s = sib.querySelector(':scope > .wps-sub-choices');
                if (s && s.style.display !== 'none') {
                    slideUp(s, 130, function() { s.querySelectorAll('.wps-choice-input').forEach(function(i){ i.checked = false; }); });
                }
            });
        }
    });

    /* ════════════════════════════════════════════
       LAZY-LOADING — skeleton + IntersectionObserver
    ════════════════════════════════════════════ */
    function lazyLoad() {
        var widgets = qsa('.wps-lazy-widget');
        if (!widgets.length) return;

        function load(el) {
            if (el.dataset.wpsLoading) return;
            el.dataset.wpsLoading = '1';

            var params = { action: 'wps_load_poll', poll_id: el.dataset.pollId || 0 };
            // Mode aléatoire : le serveur choisit parmi les candidats celui
            // que ce visiteur n'a pas encore rempli (hors cache).
            if (el.dataset.candidates) params.candidates = el.dataset.candidates;

            post(params, function(res) {
                if (res.success && res.data.html) {
                    var tmp = document.createElement('div');
                    tmp.innerHTML = res.data.html;
                    var newEl = tmp.firstElementChild;
                    el.replaceWith(newEl);
                    applyTheme(newEl.parentElement);
                    animateBars(newEl);
                } else {
                    el.remove();
                }
            }, function() {
                el.querySelector('.wps-skeleton-inner') && (el.querySelector('.wps-skeleton-inner').textContent = '⚠ Erreur');
                delete el.dataset.wpsLoading;
            });
        }

        if (!('IntersectionObserver' in window)) {
            widgets.forEach(load); return;
        }
        var obs = new IntersectionObserver(function(entries) {
            entries.forEach(function(en) { if (en.isIntersecting) { load(en.target); obs.unobserve(en.target); } });
        }, { rootMargin: '0px 0px 300px 0px' });
        widgets.forEach(function(w) { obs.observe(w); });
    }

    /* ════════════════════════════════════════════
       RÉSULTATS — helpers
    ════════════════════════════════════════════ */
    function makeResultsPanel(data) {
        var d = document.createElement('div');
        d.className = 'wps-panel wps-panel-results';
        buildResults(d, data);
        return d;
    }

    function buildResults(container, data) {
        var total     = data.total;
        var isCheckbox = data.poll_type === 'checkbox';
        var totalLabel = total + ' votant' + (total > 1 ? 's' : '');
        if (isCheckbox) {
            totalLabel += ' <span class="wps-total-note">— choix multiples, les % peuvent dépasser 100%</span>';
        }
        var html   = '<div class="wps-results" data-total="' + total + '" data-poll-type="' + (data.poll_type || 'radio') + '">'
            + '<p class="wps-total">' + totalLabel + '</p>';

        data.choices.filter(function(c){ return c.parent_id === 0; }).forEach(function(c) {
            html += rowHtml(c, false);
            var subs = data.choices.filter(function(s){ return s.parent_id === c.id; });
            if (subs.length) {
                html += '<div class="wps-sub-results">';
                subs.forEach(function(s){ html += rowHtml(s, true); });
                html += '</div>';
            }
        });
        html += '</div>';
        container.innerHTML = html;
        // Animer les barres après insertion
        requestAnimationFrame(function() { requestAnimationFrame(function() {
            container.querySelectorAll('.wps-result-bar[data-pct]').forEach(function(b) {
                b.style.width = b.dataset.pct + '%';
            });
        }); });
    }

    function rowHtml(c, isSub) {
        var cls = isSub ? 'wps-result-row wps-result-sub' : 'wps-result-row';
        return '<div class="' + cls + '">'
            + '<div class="wps-result-label">' + (isSub ? '↳ ' : '') + escHtml(c.label) + '</div>'
            + '<div class="wps-result-bar-wrap"><div class="wps-result-bar" data-pct="' + c.percent + '" style="width:0%"></div></div>'
            + '<div class="wps-result-pct">' + c.percent + '%</div>'
            + '<div class="wps-result-count">(' + c.count + ')</div>'
            + '</div>';
    }

    /* ════════════════════════════════════════════
       ANIMATION BARRES (PHP-rendered + AJAX)
    ════════════════════════════════════════════ */
    function animateBars(root) {
        (root || document).querySelectorAll('.wps-bar-animate[data-pct]').forEach(function(b) {
            if (b.dataset.animated) return;
            b.dataset.animated = '1';
            requestAnimationFrame(function() { requestAnimationFrame(function() { b.style.width = b.dataset.pct + '%'; }); });
        });
    }

    /* MutationObserver pour les widgets lazy-loadés */
    if ('MutationObserver' in window) {
        new MutationObserver(function(muts) {
            muts.forEach(function(m) {
                m.addedNodes.forEach(function(n) { if (n.nodeType === 1) animateBars(n); });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }


    /* ════════════════════════════════════════════
       HYDRATATION DE L'ÉTAT PERSONNEL
       Le HTML de la page single est mis en cache et identique pour tous.
       On interroge wps_poll_state (jamais mis en cache) pour savoir si CE
       visiteur a déjà voté, et on bascule l'affichage en conséquence.
    ════════════════════════════════════════════ */
    function hydrateState() {
        qsa('.wps-poll[data-hydrate="1"]').forEach(function(poll) {
            var pollId = poll.dataset.pollId;
            if (!pollId || poll.dataset.hydrated) return;
            poll.dataset.hydrated = '1';

            post({ action: 'wps_poll_state', poll_id: pollId }, function(res) {
                if (!res || !res.success) return;
                var d = res.data;

                // Bouton reset pour les admins
                if (d.can_reset && !poll.querySelector('.wps-btn-reset')) {
                    appendResetBtn(poll, pollId);
                }

                if (!d.has_voted) return; // formulaire déjà correct

                // Le visiteur a voté : masquer le formulaire
                var form = poll.querySelector('.wps-panel-form');
                if (form) form.style.display = 'none';
                var peek = poll.querySelector('.wps-panel-peek');
                if (peek) peek.style.display = 'none';

                // Message "déjà répondu"
                if (!poll.querySelector('.wps-voted')) {
                    var notice = document.createElement('p');
                    notice.className = 'wps-notice wps-voted';
                    notice.textContent = '\u2705 ' + (I18N.already_voted || I18N.vote_success);
                    var q = poll.querySelector('.wps-question');
                    if (q && q.nextSibling) poll.insertBefore(notice, q.nextSibling);
                    else poll.appendChild(notice);
                }

                // Résultats si disponibles
                if (d.results) {
                    var panel = poll.querySelector('.wps-panel-results');
                    if (!panel) {
                        panel = document.createElement('div');
                        panel.className = 'wps-panel wps-panel-results';
                        var anchor = poll.querySelector('.wps-admin-actions');
                        if (anchor) poll.insertBefore(panel, anchor);
                        else poll.appendChild(panel);
                    }
                    panel.innerHTML = '';
                    buildResults(panel, d.results);
                    panel.style.display = '';
                }
            });
        });
    }

    /* ════════════════════════════════════════════
       HELPERS UI
    ════════════════════════════════════════════ */
    function showMsg(el, msg, type) {
        if (!el) return;
        el.textContent = msg;
        el.className   = 'wps-msg wps-msg-' + type;
    }

    /* ── Init ── */
    document.addEventListener('DOMContentLoaded', function() {
        applyTheme();
        lazyLoad();
        animateBars();
        hydrateState();   // état perso, hors cache
    });

})();
