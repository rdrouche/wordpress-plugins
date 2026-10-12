/**
 * rdr-bookmarks-front.js  v1.1.0
 * ============================================================
 * - Switches flottants intégrés dans window.rdrFloatingStack
 * - Tooltip RGPD inline ancré sur le switch cliqué
 * - Modale shortcode : onglets Favoris / À lire + recherche JS
 * - Emojis configurables depuis l'admin WP
 * - Détection bot gérée côté PHP (pas de chargement si bot)
 *
 * localStorage :
 *   rdr_bm_consent           → 'yes' | 'no'
 *   rdr_bm_favorites_{lang}  → [{id,title,url,thumb}, …]
 *   rdr_bm_read_later_{lang} → [{id,title,url,thumb}, …]
 * ============================================================
 */
(function () {
    'use strict';

    var cfg = window.rdrBM;
    if (!cfg) return;

    var lang      = cfg.lang            || 'default';
    var postId    = cfg.postId          || '';
    var labels    = cfg.labels          || {};
    var ajaxUrl   = cfg.ajaxUrl         || '';
    var nonce     = cfg.nonce           || '';
    var emoFav    = cfg.emojiFavorite   || '★';
    var emoRL     = cfg.emojiReadLater  || '🕐';

    var KEY_CONSENT = 'rdr_bm_consent';
    var KEY_FAV     = 'rdr_bm_favorites_'  + lang;
    var KEY_RL      = 'rdr_bm_read_later_' + lang;

    /* ════════════════════════════════════════
       LOCALSTORAGE HELPERS
       ════════════════════════════════════════ */
    function lsGet(key) {
        try { return JSON.parse(localStorage.getItem(key) || 'null'); } catch (e) { return null; }
    }
    function lsSet(key, val) {
        try { localStorage.setItem(key, JSON.stringify(val)); } catch (e) {}
    }
    function getList(key)        { return lsGet(key) || []; }
    function hasItem(key, id)    { return getList(key).some(function (i) { return i.id === id; }); }
    function addItem(key, item)  { var l = getList(key); if (!hasItem(key, item.id)) { l.push(item); lsSet(key, l); } }
    function removeItem(key, id) { lsSet(key, getList(key).filter(function (i) { return i.id !== id; })); }
    function toggleItem(key, item) {
        if (hasItem(key, item.id)) { removeItem(key, item.id); return false; }
        addItem(key, item); return true;
    }

    /* ════════════════════════════════════════
       CONSENTEMENT RGPD
       Tooltip inline ancré sur l'élément déclencheur
       ════════════════════════════════════════ */
    function hasConsent()     { return lsGet(KEY_CONSENT) === 'yes'; }
    function refusedConsent() { return lsGet(KEY_CONSENT) === 'no';  }

    /**
     * Affiche le tooltip de consentement ancré visuellement sur `anchorEl`.
     * @param {HTMLElement} anchorEl  - l'élément qui a déclenché l'action
     * @param {Function}    onAccept
     * @param {Function}    [onDecline]
     */
    function askConsent(anchorEl, onAccept, onDecline) {
        // Un seul tooltip à la fois
        var existing = document.getElementById('rdr-bm-consent-tip');
        if (existing) { existing.remove(); }

        var tip = document.createElement('div');
        tip.id        = 'rdr-bm-consent-tip';
        tip.className = 'rdr-bm-consent-tip';
        tip.setAttribute('role', 'dialog');
        tip.setAttribute('aria-modal', 'false');
        tip.setAttribute('aria-label', 'Consentement stockage local');

        tip.innerHTML =
            '<p class="rdr-bm-consent-text">' +
                escHtml(labels.consent || "Ce site utilise le stockage local pour vos favoris. Aucune donnée n'est transmise. Acceptez-vous ?") +
            '</p>' +
            '<div class="rdr-bm-consent-btns">' +
                '<button class="rdr-bm-consent-accept">' + escHtml(labels.accept  || 'Accepter') + '</button>' +
                '<button class="rdr-bm-consent-decline">' + escHtml(labels.decline || 'Refuser')  + '</button>' +
            '</div>' +
            '<span class="rdr-bm-consent-arrow"></span>';

        document.body.appendChild(tip);
        positionTooltip(tip, anchorEl);

        // Recalcul si scroll/resize
        var _repos = function () { positionTooltip(tip, anchorEl); };
        window.addEventListener('resize', _repos, { passive: true });

        function cleanup() {
            window.removeEventListener('resize', _repos);
            tip.remove();
        }

        tip.querySelector('.rdr-bm-consent-accept').addEventListener('click', function () {
            lsSet(KEY_CONSENT, 'yes');
            cleanup();
            if (typeof onAccept === 'function') onAccept();
        });

        tip.querySelector('.rdr-bm-consent-decline').addEventListener('click', function () {
            lsSet(KEY_CONSENT, 'no');
            cleanup();
            if (typeof onDecline === 'function') onDecline();
        });

        // Fermeture clic extérieur
        setTimeout(function () {
            document.addEventListener('click', function outsideClick(e) {
                if (!tip.contains(e.target) && e.target !== anchorEl) {
                    cleanup();
                    document.removeEventListener('click', outsideClick);
                }
            });
        }, 50);

        // Focus premier bouton pour accessibilité
        tip.querySelector('.rdr-bm-consent-accept').focus();
    }

    /**
     * Positionne le tooltip à gauche de l'élément ancre (switches fixes = right side).
     * Si côté gauche, positionne à droite de l'ancre.
     */
    function positionTooltip(tip, anchor) {
        var rect   = anchor.getBoundingClientRect();
        var tipW   = tip.offsetWidth  || 280;
        var tipH   = tip.offsetHeight || 120;
        var margin = 12;
        var side   = (cfg.switchSide === 'left') ? 'left' : 'right';

        var top  = rect.top + (rect.height / 2) - (tipH / 2);
        var left;

        if (side === 'right') {
            left = rect.left - tipW - margin;
            if (left < 8) left = rect.right + margin; // fallback droite si pas de place
        } else {
            left = rect.right + margin;
            if (left + tipW > window.innerWidth - 8) left = rect.left - tipW - margin;
        }

        // Reste dans la fenêtre verticalement
        top = Math.max(8, Math.min(top, window.innerHeight - tipH - 8));

        tip.style.position = 'fixed';
        tip.style.top      = Math.round(top) + 'px';
        tip.style.left     = Math.round(left) + 'px';
    }

    /* ════════════════════════════════════════
       DONNÉES ARTICLE COURANT
       ════════════════════════════════════════ */
    var currentItem = {
        id:    postId,
        title: cfg.postTitle || '',
        url:   cfg.postUrl   || '',
        thumb: cfg.postThumb || '',
    };

    /* ════════════════════════════════════════
       SWITCHES FLOTTANTS
       ════════════════════════════════════════ */
    function createSwitch(type, labelText, keyLS, emoIcon) {
        var sw = document.createElement('div');
        sw.className = 'rdr-bm-switch rdr-bm-switch--' + type;
        sw.setAttribute('role', 'switch');
        sw.setAttribute('aria-checked', 'false');
        sw.setAttribute('tabindex', '0');
        sw.setAttribute('title', labelText);

        sw.innerHTML =
            '<span class="rdr-bm-sw-icon">' + emoIcon + '</span>' +
            '<span class="rdr-bm-sw-label">' + escHtml(labelText) + '</span>' +
            '<span class="rdr-bm-sw-track"><span class="rdr-bm-sw-thumb"></span></span>';

        // État initial (si consentement déjà accordé)
        if (postId && hasConsent() && hasItem(keyLS, postId)) {
            sw.classList.add('is-on');
            sw.setAttribute('aria-checked', 'true');
        }

        function toggle(e) {
            if (e) e.stopPropagation();
            if (refusedConsent()) return;

            if (!hasConsent()) {
                askConsent(sw, function () { performToggle(); });
                return;
            }
            performToggle();
        }

        function performToggle() {
            if (!postId) return;
            var isOn = toggleItem(keyLS, currentItem);
            sw.classList.toggle('is-on', isOn);
            sw.setAttribute('aria-checked', String(isOn));
            updateCounts();
        }

        sw.addEventListener('click', toggle);
        sw.addEventListener('keydown', function (e) {
            if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); toggle(e); }
        });

        return sw;
    }

    function initSwitches() {
        // showSwitches=1 uniquement sur les singuliers CPT activés (défini côté PHP)
        if (cfg.showSwitches !== '1') return;

        var side    = cfg.switchSide         || 'right';
        var prioFav = parseInt(cfg.priorityFavorite,  10) || 40;
        var prioRL  = parseInt(cfg.priorityReadLater, 10) || 30;

        /* Initialisation défensive du floating stack */
        window.rdrFloatingStack = window.rdrFloatingStack || (function () {
            var entries = [], GAP = 10, MARGIN = 24;

            function getBtBottom() {
                var btEl = document.querySelector(
                    '.generate-back-to-top, #generate-back-to-top,' +
                    'a[class*="back-to-top"], button[class*="back-to-top"],' +
                    '[class*="scroll-to-top"]:not(.rdr-sh-switch):not(.rdr-glossaire-switch):not(.rdr-bm-switch)'
                );
                if (btEl) {
                    var cs = window.getComputedStyle(btEl);
                    if (cs.position === 'fixed')
                        return (parseFloat(cs.bottom) || MARGIN) + (btEl.offsetHeight || 44) + GAP;
                }
                return MARGIN;
            }

            function reflow() {
                var right = entries.filter(function (e) { return e.side !== 'left'; });
                var left  = entries.filter(function (e) { return e.side === 'left'; });
                [right, left].forEach(function (group) {
                    group.sort(function (a, b) { return a.priority - b.priority; });
                    var s   = group.length && group[0].side === 'left' ? 'left' : 'right';
                    var cur = getBtBottom();
                    group.forEach(function (entry) {
                        var el = entry.el;
                        if (window.innerWidth < 768) { el.style.display = 'none'; return; }
                        el.style.display  = '';
                        el.style.position = 'fixed';
                        el.style.bottom   = cur + 'px';
                        el.style.zIndex   = '9990';
                        if (s === 'left') { el.style.left = MARGIN + 'px'; el.style.right = 'auto'; }
                        else              { el.style.right = MARGIN + 'px'; el.style.left  = 'auto'; }
                        cur += el.offsetHeight + GAP;
                    });
                });
            }

            window.addEventListener('resize', reflow, { passive: true });
            return {
                register:   function (el, s, p) { for (var i = 0; i < entries.length; i++) { if (entries[i].el === el) return; } entries.push({ el: el, side: s || 'right', priority: p || 50 }); requestAnimationFrame(reflow); },
                unregister: function (el) { entries = entries.filter(function (e) { return e.el !== el; }); requestAnimationFrame(reflow); },
                reflow:     reflow
            };
        })();

        var swFav = createSwitch('favorite',   labels.favorite   || 'Favoris', KEY_FAV, emoFav);
        var swRL  = createSwitch('read_later', labels.read_later || 'À lire',  KEY_RL,  emoRL);

        document.body.appendChild(swFav);
        document.body.appendChild(swRL);

        window.rdrFloatingStack.register(swFav, side, prioFav);
        window.rdrFloatingStack.register(swRL,  side, prioRL);
    }

    /* ════════════════════════════════════════
       COMPTEURS SHORTCODE
       ════════════════════════════════════════ */
    function updateCounts() {
        var fc = getList(KEY_FAV).length;
        var rc = getList(KEY_RL).length;
        document.querySelectorAll('[data-list="favorites"]').forEach(function (el) { el.textContent = fc; });
        document.querySelectorAll('[data-list="read_later"]').forEach(function (el) { el.textContent = rc; });
        // Bouton combiné nav (total des deux)
        document.querySelectorAll('[data-list="combined"]').forEach(function (el) { el.textContent = fc + rc; });
    }

    /* ════════════════════════════════════════
       MODALE + ONGLETS + RECHERCHE
       ════════════════════════════════════════ */
    var _postCache  = {};
    var _searchTerm = '';

    function fetchPosts(ids, callback) {
        if (!ids.length) { callback([]); return; }

        var toFetch   = ids.filter(function (id) { return !_postCache[id]; });
        var fromCache = ids.filter(function (id) { return  _postCache[id]; }).map(function (id) { return _postCache[id]; });

        if (!toFetch.length) { callback(fromCache); return; }

        var fd = new FormData();
        fd.append('action', 'rdr_bm_fetch_posts');
        fd.append('nonce', nonce);
        fd.append('ids', JSON.stringify(toFetch));

        fetch(ajaxUrl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && Array.isArray(data.data)) {
                    data.data.forEach(function (p) { _postCache[p.id] = p; });
                }
                var ordered = ids.map(function (id) { return _postCache[id]; }).filter(Boolean);
                callback(ordered);
            })
            .catch(function () { callback(fromCache); });
    }

    /**
     * Filtre les posts selon le terme de recherche (titre).
     */
    function filterPosts(posts, term) {
        if (!term) return posts;
        var t = term.toLowerCase();
        return posts.filter(function (p) {
            return p.title.toLowerCase().indexOf(t) !== -1;
        });
    }

    function renderCards(panelEl, allPosts) {
        var cardsEl    = panelEl.querySelector('.rdr-bm-cards');
        var emptyEl    = panelEl.querySelector('.rdr-bm-empty');
        var noResultEl = panelEl.querySelector('.rdr-bm-no-results');

        var posts = filterPosts(allPosts, _searchTerm);

        cardsEl.innerHTML = '';
        emptyEl.hidden    = true;
        noResultEl.hidden = true;

        if (!allPosts.length) {
            emptyEl.hidden = false;
            return;
        }
        if (!posts.length) {
            noResultEl.hidden = false;
            return;
        }

        posts.forEach(function (p) {
            var card       = document.createElement('a');
            card.className = 'rdr-bm-card';
            card.href      = p.url;
            card.setAttribute('target', '_self');

            var titleHtml = _searchTerm
                ? highlightText(escHtml(p.title), _searchTerm)
                : escHtml(p.title);

            card.innerHTML =
                (p.thumb
                    ? '<img class="rdr-bm-card-thumb" src="' + p.thumb + '" alt="" loading="lazy">'
                    : '<div class="rdr-bm-card-thumb rdr-bm-card-thumb--placeholder"></div>') +
                '<div class="rdr-bm-card-body">' +
                '  <span class="rdr-bm-card-title">' + titleHtml + '</span>' +
                '  <span class="rdr-bm-card-date">'  + escHtml(p.date || '') + '</span>' +
                '</div>';

            cardsEl.appendChild(card);
        });
    }

    /** Entoure les occurrences du terme dans le texte HTML déjà échappé */
    function highlightText(safeHtml, term) {
        var escaped = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return safeHtml.replace(
            new RegExp('(' + escaped + ')', 'gi'),
            '<mark class="rdr-bm-hl">$1</mark>'
        );
    }

    function loadPanel(panelEl, listKey) {
        var cardsEl = panelEl.querySelector('.rdr-bm-cards');
        var loadEl  = panelEl.querySelector('.rdr-bm-loading');
        var emptyEl = panelEl.querySelector('.rdr-bm-empty');
        var list    = getList(listKey);
        var ids     = list.map(function (i) { return parseInt(i.id, 10); });

        if (!ids.length) {
            cardsEl.innerHTML  = '';
            emptyEl.hidden     = false;
            loadEl.hidden      = true;
            return;
        }

        loadEl.hidden  = false;
        emptyEl.hidden = true;

        fetchPosts(ids, function (posts) {
            loadEl.hidden = true;
            // Stocke les posts dans le panel pour re-filtrage ultérieur
            panelEl._allPosts = posts;
            renderCards(panelEl, posts);
        });
    }

    function initModal() {
        var modal = document.getElementById('rdr-bm-modal');
        if (!modal) return;

        var triggers  = document.querySelectorAll('.rdr-bm-trigger-btn');
        var closeBtn  = modal.querySelector('.rdr-bm-modal-close');
        var tabs      = modal.querySelectorAll('.rdr-bm-tab');
        var panels    = modal.querySelectorAll('.rdr-bm-panel');
        var searchInp = modal.querySelector('.rdr-bm-search');
        var clearBtn  = modal.querySelector('.rdr-bm-search-clear');

        var currentTab = 'favorites';

        /* ── Ouvrir / fermer ── */
        function openModal(tabName) {
            modal.hidden = false;
            document.body.classList.add('rdr-bm-modal-open');
            _searchTerm = '';
            if (searchInp) { searchInp.value = ''; }
            if (clearBtn)  { clearBtn.hidden = true; }
            activateTab(tabName || 'favorites');
            closeBtn.focus();
        }

        function closeModal() {
            modal.hidden = true;
            document.body.classList.remove('rdr-bm-modal-open');
        }

        /* ── Onglets ── */
        function activateTab(tabName) {
            currentTab = tabName;
            tabs.forEach(function (t) {
                var active = t.dataset.tab === tabName;
                t.classList.toggle('active', active);
                t.setAttribute('aria-selected', String(active));
            });
            panels.forEach(function (p) {
                var active = p.dataset.panel === tabName;
                p.classList.toggle('active', active);
                p.hidden = !active;
                if (active) {
                    // Re-filtre si term en cours, sinon charge
                    if (p._allPosts) {
                        renderCards(p, p._allPosts);
                    } else {
                        loadPanel(p, tabName === 'favorites' ? KEY_FAV : KEY_RL);
                    }
                }
            });
        }

        /* ── Recherche ── */
        if (searchInp) {
            searchInp.addEventListener('input', function () {
                _searchTerm = searchInp.value.trim();
                if (clearBtn) clearBtn.hidden = !_searchTerm;

                // Re-render le panel actif uniquement
                panels.forEach(function (p) {
                    if (!p.classList.contains('active')) return;
                    if (p._allPosts) {
                        renderCards(p, p._allPosts);
                    }
                });
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                _searchTerm = '';
                if (searchInp) { searchInp.value = ''; searchInp.focus(); }
                clearBtn.hidden = true;
                panels.forEach(function (p) {
                    if (p._allPosts) renderCards(p, p._allPosts);
                });
            });
        }

        /* ── Event listeners ── */
        triggers.forEach(function (btn) {
            btn.addEventListener('click', function () { openModal(btn.dataset.tab); });
        });

        // Boutons nav (injectés par PHP dans la barre de navigation)
        // Comportement : modale si disponible, sinon redirection vers la page configurée
        var navBehavior = cfg.navBehavior || 'auto';
        var navPageUrl  = cfg.navPageUrl  || '';
        var modalExists = !!document.getElementById('rdr-bm-modal');

        document.querySelectorAll('.rdr-bm-nav-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tab = btn.dataset.tab || 'favorites';

                // Mode "page" — toujours rediriger
                if (navBehavior === 'page' && navPageUrl) {
                    window.location.href = navPageUrl + '#rdr-bm';
                    return;
                }

                // Mode "modal" ou "auto" avec modale disponible — ouvrir directement
                if (navBehavior === 'modal' || (navBehavior === 'auto' && modalExists)) {
                    openModal(tab);
                    return;
                }

                // Mode "auto" sans modale — rediriger vers la page
                if (navPageUrl) {
                    window.location.href = navPageUrl + '#rdr-bm';
                }
            });
        });

        tabs.forEach(function (t) {
            t.addEventListener('click', function () { activateTab(t.dataset.tab); });
        });

        closeBtn.addEventListener('click', closeModal);

        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) closeModal();
        });
    }

    /* ════════════════════════════════════════
       HELPERS
       ════════════════════════════════════════ */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ════════════════════════════════════════
       MODE LECTURE
       Switch flottant — masque la sidebar,
       étend le contenu sur la largeur libérée.
       Pas de localStorage : reset à chaque page.
       ════════════════════════════════════════ */
    function initReadingMode() {
        if (cfg.showReadingMode !== '1') return;

        var label    = cfg.readingLabel || 'Mode lecture';
        var emoIcon  = cfg.readingEmoji || '📖';
        var side     = cfg.switchSide   || 'right';
        var priority = parseInt(cfg.priorityReading, 10) || 20;

        var sw = document.createElement('div');
        sw.className = 'rdr-bm-switch rdr-bm-switch--reading';
        sw.setAttribute('role', 'switch');
        sw.setAttribute('aria-checked', 'false');
        sw.setAttribute('tabindex', '0');
        sw.setAttribute('title', label);

        sw.innerHTML =
            '<span class="rdr-bm-sw-icon">' + emoIcon + '</span>' +
            '<span class="rdr-bm-sw-label">' + escHtml(label) + '</span>' +
            '<span class="rdr-bm-sw-track"><span class="rdr-bm-sw-thumb"></span></span>';

        function toggle(e) {
            if (e) e.stopPropagation();
            var isOn = document.body.classList.toggle('rdr-bm-reading');
            sw.classList.toggle('is-on', isOn);
            sw.setAttribute('aria-checked', String(isOn));
        }

        sw.addEventListener('click', toggle);
        sw.addEventListener('keydown', function (e) {
            if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); toggle(e); }
        });

        document.body.appendChild(sw);
        window.rdrFloatingStack.register(sw, side, priority);
    }

    /* ════════════════════════════════════════
       SHORTCODE PAGE [rdr_bookmarks_page]
       Onglets + recherche inline dans le contenu.
       Même logique que la modale mais sans overlay.
       ════════════════════════════════════════ */
    function initPage() {
        var page = document.getElementById('rdr-bm-page');
        if (!page) return;

        var tabs      = page.querySelectorAll('.rdr-bm-page-tab');
        var panels    = page.querySelectorAll('.rdr-bm-page-panel');
        var searchInp = page.querySelector('.rdr-bm-page-search');
        var clearBtn  = page.querySelector('.rdr-bm-search-clear');
        var pageSearchTerm = '';
        var currentTab = 'favorites';

        function activateTab(tabName) {
            currentTab = tabName;
            tabs.forEach(function (t) {
                var active = t.dataset.tab === tabName;
                t.classList.toggle('active', active);
                t.setAttribute('aria-selected', String(active));
            });
            panels.forEach(function (p) {
                var active = p.dataset.panel === tabName;
                p.classList.toggle('active', active);
                p.hidden = !active;
                if (active) {
                    if (p._allPosts) {
                        renderCards(p, p._allPosts);
                    } else {
                        loadPanel(p, tabName === 'favorites' ? KEY_FAV : KEY_RL);
                    }
                }
            });
        }

        tabs.forEach(function (t) {
            t.addEventListener('click', function () { activateTab(t.dataset.tab); });
        });

        if (searchInp) {
            searchInp.addEventListener('input', function () {
                pageSearchTerm = searchInp.value.trim();
                _searchTerm = pageSearchTerm;
                if (clearBtn) clearBtn.hidden = !pageSearchTerm;
                panels.forEach(function (p) {
                    if (p.classList.contains('active') && p._allPosts) {
                        renderCards(p, p._allPosts);
                    }
                });
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                pageSearchTerm = '';
                _searchTerm = '';
                if (searchInp) { searchInp.value = ''; searchInp.focus(); }
                clearBtn.hidden = true;
                panels.forEach(function (p) {
                    if (p._allPosts) renderCards(p, p._allPosts);
                });
            });
        }

        // Charge le premier panneau
        activateTab('favorites');

        // Met à jour les compteurs dans les onglets page
        function updatePageCounts() {
            var fc = getList(KEY_FAV).length;
            var rc = getList(KEY_RL).length;
            page.querySelectorAll('[data-list="favorites"]').forEach(function (el) { el.textContent = fc; });
            page.querySelectorAll('[data-list="read_later"]').forEach(function (el) { el.textContent = rc; });
        }
        updatePageCounts();
    }

    /* ════════════════════════════════════════
       INIT
       ════════════════════════════════════════ */
    function init() {
        initSwitches();
        initReadingMode();
        initModal();
        initPage();
        updateCounts();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
