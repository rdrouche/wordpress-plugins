/**
 * WP Sondage — Archive page JS (Vanilla)
 * Gère la liste AJAX des sondages sur la page WordPress native archive
 */
(function () {
    'use strict';

    var root = document.getElementById('wps-archive-root');
    if (!root) return;

    var AJAX     = (window.wpsData || {}).ajaxurl || '';
    var lang     = root.dataset.lang  || '';
    // Aucun nonce lu depuis le HTML : il serait périmé si la page vient
    // d'un cache. L'endpoint archive est en lecture seule (pas de CSRF).

    var content  = document.getElementById('wps-archive-content');
    var paginEl  = document.getElementById('wps-archive-pagination');
    var statOpen = document.getElementById('wps-stat-open');
    var searchEl = document.getElementById('wps-archive-search');
    var sortEl   = document.getElementById('wps-archive-sort');
    var catNav   = document.getElementById('wps-cat-nav');

    var state = { page: 1, sort: 'date', cat: '', search: '', loading: false };
    var searchTimer;

    /* ── Chargement initial ── */
    load();

    /* ── Événements ── */
    if (searchEl) {
        searchEl.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var v = this.value.trim();
            searchTimer = setTimeout(function () {
                if (v !== state.search) { state.search = v; state.page = 1; load(); }
            }, 320);
        });
    }

    if (sortEl) {
        sortEl.addEventListener('change', function () {
            state.sort = this.value; state.page = 1; load();
        });
    }

    if (catNav) {
        catNav.addEventListener('click', function (e) {
            var btn = e.target.closest('.wps-cat-link');
            if (!btn) return;
            catNav.querySelectorAll('.wps-cat-link').forEach(function (b) { b.classList.remove('wps-cat-link--active'); });
            btn.classList.add('wps-cat-link--active');
            state.cat  = btn.dataset.cat || '';
            state.page = 1;
            load();
        });
    }

    /* Délégation pagination */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('#wps-archive-pagination .page-numbers');
        if (!btn || !btn.dataset.page) return;
        state.page = parseInt(btn.dataset.page, 10);
        load();
        // Scroll doux vers le haut de la liste
        root.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    /* ── Chargement AJAX ── */
    function load() {
        if (state.loading) return;
        state.loading = true;

        showSkeleton();

        var body = new URLSearchParams({
            action: 'wps_archive_load',
            lang:   lang,
            page:   state.page,
            sort:   state.sort,
            cat:    state.cat,
            search: state.search,
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
        .then(function (r) { return r.json(); })
        .then(function (res) {
            state.loading = false;
            if (res && res.data && res.data.nonce && window.wpsData) {
                window.wpsData.nonce = res.data.nonce; // partagé avec wps-public.js
            }
            if (!res.success) { showError(); return; }
            retryCount = 0;

            var d = res.data;

            /* Contenu */
            content.innerHTML = d.html;

            /* Barres de popularité relatives */
            animateBars();

            /* Pagination */
            if (d.pagination) {
                paginEl.innerHTML = d.pagination;
                paginEl.style.display = '';
            } else {
                paginEl.innerHTML = '';
                paginEl.style.display = 'none';
            }

            /* Stat sondages ouverts */
            if (statOpen && d.open !== undefined) statOpen.textContent = d.open;

            /* Thème dark */
            applyTheme();
        })
        .catch(function () { state.loading = false; showError(); });
    }

    /* ── Skeleton ── */
    function showSkeleton() {
        var cards = '';
        for (var i = 0; i < 6; i++) {
            cards += '<div class="wps-poll-card wps-card-skeleton" aria-hidden="true">'
                + '<div class="wps-card-link">'
                + '<div class="wps-sk-line wps-sk-title"></div>'
                + '<div class="wps-sk-line wps-sk-bar"></div>'
                + '<div class="wps-sk-line wps-sk-meta"></div>'
                + '</div></div>';
        }
        content.innerHTML = '<div class="wps-polls-grid wps-archive-skeleton">' + cards + '</div>';
    }

    var retryCount = 0;
    function showError() {
        if (retryCount < 2) {
            retryCount++;
            setTimeout(load, 600 * retryCount);
            content.innerHTML = '<p class="wps-empty-state">Chargement…</p>';
            return;
        }
        content.innerHTML = '<p class="wps-empty-state">'
            + 'Impossible de charger les sondages. '
            + '<button type="button" class="wps-cat-link" onclick="location.reload()">Réessayer</button>'
            + '</p>';
    }

    /* ── Barres de popularité relatives ── */
    function animateBars() {
        var cards = Array.from(content.querySelectorAll('.wps-poll-card[data-votes]'));
        var maxV  = cards.reduce(function (m, c) { return Math.max(m, parseInt(c.dataset.votes, 10) || 0); }, 0);
        if (!maxV) return;
        cards.forEach(function (card) {
            var bar = card.querySelector('.wps-card-progress-fill');
            if (!bar) return;
            var pct = Math.round((parseInt(card.dataset.votes, 10) || 0) / maxV * 100);
            requestAnimationFrame(function () { requestAnimationFrame(function () { bar.style.width = pct + '%'; }); });
        });
    }

    /* ── Thème dark ── */
    var DARK_SEL = [
        'html.dark','html.dark-mode','html[data-theme="dark"]',
        'body.dark','body.dark-mode','body[data-theme="dark"]',
        'html[data-color-mode="dark"]','html[data-bs-theme="dark"]',
    ];
    function isDark() {
        return DARK_SEL.some(function (s) { try { return !!document.querySelector(s); } catch(e) { return false; } });
    }
    function applyTheme() {
        root.classList.toggle('wps-is-dark', isDark());
    }
    if ('MutationObserver' in window) {
        new MutationObserver(function (muts) {
            if (muts.some(function(m){ return ['class','data-theme','data-color-mode','data-bs-theme'].indexOf(m.attributeName) > -1; })) applyTheme();
        }).observe(document.documentElement, { attributes: true });
        new MutationObserver(applyTheme).observe(document.body, { attributes: true, attributeFilter: ['class','data-theme'] });
    }

})();
