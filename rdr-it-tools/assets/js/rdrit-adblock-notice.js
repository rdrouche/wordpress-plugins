/**
 * RDR-IT Tools — AdBlock Notice v3
 *
 * Clés localStorage séparées pour bannière et modale.
 *
 * Règles de dismiss :
 * - Fermer bannière  → bloque bannière (TTL), modale random reste active
 * - Fermer modale    → bloque modale (TTL) ET bannière
 * - Mode random actif → TTL modale ignoré (modale peut réapparaître malgré dismiss)
 *
 * Option "bannière page courante / modale pages suivantes uniquement" :
 * cfg.modalNextPageOnly = true → modale ne s'affiche pas sur la même page que la bannière
 */
(function () {
    'use strict';

    var cfg = window.rdritAbnConfig;
    if (!cfg) return;

    var KEY_BANNER = cfg.storageKey + '_banner';
    var KEY_MODAL  = cfg.storageKey + '_modal';

    /* ── Langue depuis <html lang="fr-FR"> ── */
    function getLang() {
        return (document.documentElement.getAttribute('lang') || 'fr')
            .toLowerCase().split(/[-_]/)[0];
    }

    /* ── Résoudre le message selon la langue ── */
    function resolveMsg(raw) {
        if (!raw) return '';
        try {
            var obj = JSON.parse(raw);
            if (obj && typeof obj === 'object') {
                var lang = getLang();
                return obj[lang] || obj['fr'] || obj[Object.keys(obj)[0]] || '';
            }
        } catch(e) {}
        return raw;
    }

    /* ── localStorage TTL ── */
    function isDismissed(key) {
        try {
            var raw = localStorage.getItem(key);
            if (!raw) return false;
            var data = JSON.parse(raw);
            if (Date.now() > data.exp) { localStorage.removeItem(key); return false; }
            return true;
        } catch(e) { return false; }
    }

    function setDismissed(key) {
        try {
            localStorage.setItem(key, JSON.stringify({
                exp: Date.now() + (cfg.storageTTL * 3600000)
            }));
        } catch(e) {}
    }

    /* ── Détection dark mode thème (classe body/html) + OS fallback ── */
    function isDark() {
        var el = document.documentElement;
        var body = document.body;
        // GeneratePress Premium : data-theme="dark" sur <html>
        if (el.getAttribute('data-theme') === 'dark') return true;
        // Classes communes des thèmes
        var darkClasses = ['dark-mode','dark','theme-dark','is-dark','night-mode'];
        for (var i = 0; i < darkClasses.length; i++) {
            if (el.classList.contains(darkClasses[i]) || body.classList.contains(darkClasses[i])) return true;
        }
        // Fallback OS
        return window.matchMedia && window.matchMedia('(prefers-color-scheme:dark)').matches;
    }

    function applyDarkMode() {
        var dark = isDark();
        var root = document.documentElement;
        if (dark) {
            root.style.setProperty('--abn-modal-bg',  '#1e293b');
            root.style.setProperty('--abn-modal-fg',  '#f1f5f9');
            root.style.setProperty('--abn-modal-h',   '#f8fafc');
            root.style.setProperty('--abn-modal-p',   '#cbd5e1');
            root.style.setProperty('--abn-overlay',   'rgba(0,0,0,.72)');
        } else {
            root.style.setProperty('--abn-modal-bg',  '#ffffff');
            root.style.setProperty('--abn-modal-fg',  '#1f2937');
            root.style.setProperty('--abn-modal-h',   '#111827');
            root.style.setProperty('--abn-modal-p',   '#374151');
            root.style.setProperty('--abn-overlay',   'rgba(0,0,0,.55)');
        }
    }

    // Observer les changements de classe/attribut sur <html> et <body>
    function watchDarkMode() {
        var observer = new MutationObserver(applyDarkMode);
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class','data-theme'] });
        observer.observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }

    /* ── CSS ── */
    function injectStyles() {
        if (document.getElementById('rdrit-abn-css')) return;
        applyDarkMode();
        watchDarkMode();
        var s = document.createElement('style');
        s.id = 'rdrit-abn-css';
        s.textContent = [
            ':root{',
            '--abn-banner-bg:' + cfg.bannerBg + ';',
            '--abn-banner-color:' + cfg.bannerColor + ';',
            '--abn-modal-bg:#ffffff;--abn-modal-fg:#1f2937;',
            '--abn-modal-h:#111827;--abn-modal-p:#374151;',
            '--abn-overlay:rgba(0,0,0,.55);}',
            /* Plus de media query — géré dynamiquement via applyDarkMode() */
            /* Bannière */
            '#rdrit-abn-banner{position:fixed;bottom:0;left:0;right:0;z-index:99998;',
            'padding:12px 20px;background:var(--abn-banner-bg);color:var(--abn-banner-color);',
            'font-size:.88rem;line-height:1.5;display:flex;align-items:center;',
            'justify-content:space-between;gap:12px;flex-wrap:wrap;',
            'transform:translateY(100%);transition:transform .4s cubic-bezier(.4,0,.2,1);}',
            '#rdrit-abn-banner.rdrit-abn-show{transform:translateY(0);}',
            '#rdrit-abn-banner a{color:var(--abn-banner-color);font-weight:600;text-decoration:underline;}',
            '#rdrit-abn-close-banner{background:rgba(255,255,255,.18);border:none;color:inherit;',
            'padding:5px 14px;border-radius:20px;cursor:pointer;font-size:.82rem;white-space:nowrap;flex-shrink:0;}',
            '#rdrit-abn-close-banner:hover{background:rgba(255,255,255,.3);}',
            /* Modale */
            '#rdrit-abn-overlay{position:fixed;inset:0;z-index:99999;',
            'background:var(--abn-overlay);display:flex;align-items:center;',
            'justify-content:center;padding:20px;',
            'opacity:0;transition:opacity .3s;pointer-events:none;}',
            '#rdrit-abn-overlay.rdrit-abn-show{opacity:1;pointer-events:all;}',
            '#rdrit-abn-modal{background:var(--abn-modal-bg);color:var(--abn-modal-fg);',
            'border-radius:14px;max-width:480px;width:100%;padding:32px;position:relative;',
            'max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3);}',
            '#rdrit-abn-modal h1,#rdrit-abn-modal h2,#rdrit-abn-modal h3{margin-top:0;color:var(--abn-modal-h);}',
            '#rdrit-abn-modal p{color:var(--abn-modal-p);line-height:1.6;margin:8px 0;}',
            '#rdrit-abn-modal a{color:#3b82f6;}',
            '#rdrit-abn-modal a.rdrit-abn-btn,#rdrit-abn-modal .rdrit-abn-btn{',
            'display:inline-block;margin-top:14px;padding:10px 24px;',
            'background:#dc2626;color:#fff!important;border-radius:8px;',
            'text-decoration:none;font-weight:600;}',
            '#rdrit-abn-modal a.rdrit-abn-btn:hover{background:#b91c1c;}',
            '#rdrit-abn-close-modal{position:absolute;top:12px;right:14px;',
            'background:none;border:none;font-size:1.4rem;cursor:pointer;',
            'color:#9ca3af;line-height:1;padding:4px;}',
            '#rdrit-abn-close-modal:hover{color:var(--abn-modal-fg);}',
        ].join('');
        document.head.appendChild(s);
    }

    /* ── Bannière ── */
    function showBanner() {
        if (!cfg.bannerEnabled) return;
        if (isDismissed(KEY_BANNER)) return;
        if (document.getElementById('rdrit-abn-banner')) return;
        injectStyles();

        var el = document.createElement('div');
        el.id = 'rdrit-abn-banner';
        el.setAttribute('role', 'complementary');
        el.innerHTML = '<div class="rdrit-abn-content">' + resolveMsg(cfg.bannerMsg) + '</div>'
            + '<button id="rdrit-abn-close-banner" aria-label="Fermer">\u00d7 Fermer</button>';
        document.body.appendChild(el);

        requestAnimationFrame(function() {
            requestAnimationFrame(function() { el.classList.add('rdrit-abn-show'); });
        });

        document.getElementById('rdrit-abn-close-banner').addEventListener('click', function() {
            el.classList.remove('rdrit-abn-show');
            setDismissed(KEY_BANNER);
            // Fermer bannière ne bloque PAS la modale random
        });
    }

    /* ── Modale ── */
    var _bannerShownThisPage = false;

    function showModal() {
        if (!cfg.modalEnabled) return;
        if (document.getElementById('rdrit-abn-overlay')) return;

        // Mode random : tirage 1/N
        if (cfg.modalRandom) {
            var chance = Math.max(1, cfg.modalChance);
            if (Math.floor(Math.random() * chance) !== 0) return;
            // Mode random : ignorer le TTL dismiss
            // (la modale peut réapparaître même si déjà fermée)
        } else {
            // Mode normal : respecter le TTL
            if (isDismissed(KEY_MODAL)) return;
        }

        // Option "pages suivantes uniquement" : ne pas afficher si bannière déjà sur cette page
        if (cfg.modalNextPageOnly && _bannerShownThisPage) return;

        injectStyles();

        var overlay = document.createElement('div');
        overlay.id = 'rdrit-abn-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.innerHTML = '<div id="rdrit-abn-modal">'
            + '<button id="rdrit-abn-close-modal" aria-label="Fermer">\u00d7</button>'
            + resolveMsg(cfg.modalMsg)
            + '</div>';
        document.body.appendChild(overlay);

        requestAnimationFrame(function() {
            requestAnimationFrame(function() { overlay.classList.add('rdrit-abn-show'); });
        });

        function closeModal() {
            overlay.classList.remove('rdrit-abn-show');
            setTimeout(function() { if (overlay.parentNode) overlay.remove(); }, 300);
            // Fermer modale → bloque modale ET bannière
            setDismissed(KEY_MODAL);
            setDismissed(KEY_BANNER);
        }

        document.getElementById('rdrit-abn-close-modal').addEventListener('click', closeModal);
        overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });
        document.addEventListener('keydown', function onKey(e) {
            if (e.key === 'Escape') { closeModal(); document.removeEventListener('keydown', onKey); }
        });
    }

    /* ── Planifier ── */
    function schedule() {
        if (cfg.bannerEnabled) {
            setTimeout(function() {
                _bannerShownThisPage = true;
                showBanner();
            }, cfg.bannerDelay * 1000);
        }

        if (cfg.modalEnabled) {
            var d = cfg.modalDelay * 1000;
            // Si bannière aussi active et option "pages suivantes" non cochée,
            // décaler la modale pour éviter l'affichage simultané
            if (cfg.bannerEnabled && !cfg.modalNextPageOnly) {
                d = Math.max(d, cfg.bannerDelay * 1000 + 2000);
            }
            setTimeout(showModal, d);
        }
    }

    /* ── Attendre la détection AdBlock ── */
    var _triggered = false;
    function trigger() {
        if (_triggered) return;
        _triggered = true;
        schedule();
    }

    var _orig = window.applyAdblockActions;
    window.applyAdblockActions = function() {
        trigger();
        if (typeof _orig === 'function') _orig.apply(this, arguments);
    };

    // Polling si isAdblockTrue déjà défini
    var _poll = 0;
    (function poll() {
        if (window.isAdblockTrue) { trigger(); return; }
        if (_triggered) return;
        if (++_poll < 80) setTimeout(poll, 100);
    })();

}());
