/**
 * RDR-IT Glossaire — Front-end JS
 * Chargement async des définitions via REST API + tooltips
 * Toggle on/off via localStorage (compatible LiteSpeed Cache)
 * Version: 1.0.1
 */
(function () {
    'use strict';

    /* ── rdrFloatingStack — registre partagé inter-plugins RDR ── */
    window.rdrFloatingStack = window.rdrFloatingStack || (function() {
        var entries = [];
        var GAP = 10, MARGIN = 24;

        function getBtBottom() {
            var btEl = document.querySelector(
                '.generate-back-to-top, #generate-back-to-top,' +
                'a[class*="back-to-top"], button[class*="back-to-top"],' +
                '[class*="scroll-to-top"]:not(.rdr-sh-switch):not(#rdrg-float-switch)'
            );
            if (btEl) {
                var cs = window.getComputedStyle(btEl);
                if (cs.position === 'fixed')
                    return (parseFloat(cs.bottom) || MARGIN) + (btEl.offsetHeight || 44) + GAP;
            }
            return MARGIN;
        }

        function reflow() {
            var right = entries.filter(function(e){ return e.side !== 'left'; });
            var left  = entries.filter(function(e){ return e.side === 'left'; });
            [right, left].forEach(function(group) {
                group.sort(function(a,b){ return a.priority - b.priority; });
                var side = group.length && group[0].side === 'left' ? 'left' : 'right';
                var cur  = getBtBottom();
                group.forEach(function(entry) {
                    var el = entry.el;
                    if (window.innerWidth < 768) { el.style.display = 'none'; return; }
                    el.style.display   = '';
                    el.style.position  = 'fixed';
                    el.style.bottom    = cur + 'px';
                    el.style.zIndex    = '9990';
                    if (side === 'left') { el.style.left = MARGIN+'px'; el.style.right = 'auto'; }
                    else                 { el.style.right = MARGIN+'px'; el.style.left = 'auto'; }
                    cur += el.offsetHeight + GAP;
                });
            });
        }

        window.addEventListener('resize', reflow, { passive: true });
        return {
            register:   function(el, side, priority) {
                for (var i = 0; i < entries.length; i++) { if (entries[i].el === el) return; }
                entries.push({ el: el, side: side || 'right', priority: priority || 50 });
                requestAnimationFrame(reflow);
            },
            unregister: function(el) {
                entries = entries.filter(function(e) { return e.el !== el; });
                requestAnimationFrame(reflow);
            },
            reflow: reflow
        };
    })();

    /* ── Config injectée par wp_localize_script ──────── */
    const cfg = window.rdrGlossaire || {};

    /* ── Config ─────────────────────────────────────────── */
    const LS_KEY        = 'rdrg_enabled';
    const CACHE_LANG    = cfg.lang || 'default';
    const CACHE_KEY     = 'rdrg_terms_cache_' + CACHE_LANG;   // clé par langue
    const CACHE_KEY_TS  = 'rdrg_terms_ts_' + CACHE_LANG;      // timestamp par langue
    const CACHE_TTL     = 10 * 60 * 1000;
    const TOGGLE_DELAY  = 50;

    /* ── Raccourcis i18n (fallbacks si wp_localize_script absent) ── */
    const i18n = Object.assign({
        labelOn:      '📚 Glossaire actif — survolez les termes techniques pour une définition',
        labelOff:     '📚 Glossaire désactivé',
        switchTitle:  'Activer / désactiver les tooltips glossaire',
        switchAria:   'Activer le glossaire interactif',
        tooltipLearn: 'En savoir plus →',
        openLink:     'Ouvrir le lien →',
        defPrefix:        'Définition :',
    }, (cfg.i18n || {}));

    /* ── State ───────────────────────────────────────── */
    let termsMap   = {};   // { 'dhcp': { title, definition, link }, ... }
    let isEnabled  = null; // null = pas encore initialisé
    let tooltipEl  = null; // nœud DOM du tooltip actif
    let hideTimer  = null;
    let loadingDone= false;

    /* ─────────────────────────────────────────────────────
       INIT
    ───────────────────────────────────────────────────── */
    function init() {
        // Lire l'état depuis localStorage (défaut: false = désactivé)
        isEnabled = localStorage.getItem(LS_KEY) === '1';

        // Injecter le switch sous le titre si on est dans un article
        injectToggleSwitch();

        if (isEnabled) {
            loadTermsAsync().then(applyToContent);
        }
    }

    /* ─────────────────────────────────────────────────────
       SWITCH TOGGLE
       Deux modes :
       - inline  : injecté sous le titre H1 de l'article (défaut)
       - float   : bouton flottant latéral via [rdrg_switch]
    ───────────────────────────────────────────────────── */
    function injectToggleSwitch() {
        if (cfg.floatSwitch) {
            injectFloatSwitch(cfg.floatPosition || 'left');
        } else {
            injectInlineSwitch();
        }
    }

    /* Switch inline — sous le titre H1 ou via shortcode [rdrg_switch] */
    function injectInlineSwitch() {
        // Cas 1 : shortcode déjà rendu en PHP — on l'initialise juste
        const scWrap = document.querySelector('.rdrg-switch-shortcode');
        if (scWrap) {
            const checkbox = scWrap.querySelector('#rdrg-toggle-checkbox');
            const label    = scWrap.querySelector('#rdrg-switch-label');
            if (checkbox) {
                checkbox.checked = isEnabled;
                checkbox.addEventListener('change', onToggle);
            }
            if (label) {
                const labelOn  = scWrap.dataset.labelOn  || i18n.labelOn;
                const labelOff = scWrap.dataset.labelOff || i18n.labelOff;
                label.textContent = isEnabled ? labelOn : labelOff;
            }
            return;
        }

        // Cas 2 : pas de shortcode — injecter sous le titre H1
        const entryTitle = document.querySelector('.entry-title, .page-title, h1.entry-title');
        if (!entryTitle) return;

        const wrap = document.createElement('div');
        wrap.className = 'rdrg-switch-wrap';
        wrap.setAttribute('role', 'region');
        wrap.setAttribute('aria-label', 'Glossaire interactif');

        const labelText = isEnabled ? i18n.labelOn : i18n.labelOff;
        wrap.innerHTML = `
            <label class="rdrg-inline-switch" title="${escHtml(i18n.switchTitle)}">
                <input type="checkbox"
                       id="rdrg-toggle-checkbox"
                       ${isEnabled ? 'checked' : ''}
                       aria-label="${escHtml(i18n.switchAria)}">
                <span class="rdrg-track"><span class="rdrg-thumb"></span></span>
                <span class="rdrg-label" id="rdrg-switch-label">${labelText}</span>
            </label>
        `;
        entryTitle.insertAdjacentElement('afterend', wrap);
        wrap.querySelector('#rdrg-toggle-checkbox').addEventListener('change', onToggle);
    }

    /* Switch flottant — pill identique au plugin rdr-syntax-highlighter */
    function injectFloatSwitch(position) {
        if (document.getElementById('rdrg-float-switch')) return;

        var isRight = (position !== 'floating-left');

        var wrap = document.createElement('div');
        wrap.id = 'rdrg-float-switch';
        wrap.classList.add(isRight ? 'rdrg-float-right' : 'rdrg-float-left');

        // Label PUIS toggle (comme le plugin coloration en mode floating)
        var toggleEl = document.createElement('button');
        toggleEl.className = 'rdrg-float-toggle' + (isEnabled ? ' is-on' : '');
        toggleEl.setAttribute('role', 'switch');
        toggleEl.setAttribute('aria-checked', isEnabled ? 'true' : 'false');
        toggleEl.setAttribute('type', 'button');
        toggleEl.setAttribute('aria-label', i18n.switchAria);
        toggleEl.innerHTML = '<span class="rdrg-float-knob"></span>';

        var labelEl = document.createElement('span');
        labelEl.className = 'rdrg-float-label';
        labelEl.textContent = '📚 Glossaire';

        // Ordre : label | toggle (comme rdr-sh en floating)
        wrap.appendChild(labelEl);
        wrap.appendChild(toggleEl);

        document.body.appendChild(wrap);
        // Enregistrement dans le stack partagé — priorité 50 (glossaire bas, coloration=60 au-dessus)
        var side = isRight ? 'right' : 'left';
        window.rdrFloatingStack.register(wrap, side, 50);

        toggleEl.addEventListener('click', function() {
            isEnabled = !isEnabled;
            localStorage.setItem(LS_KEY, isEnabled ? '1' : '0');
            toggleEl.classList.toggle('is-on', isEnabled);
            toggleEl.setAttribute('aria-checked', isEnabled ? 'true' : 'false');
            if (isEnabled) {
                if (!loadingDone) { loadTermsAsync().then(applyToContent); }
                else { applyToContent(); }
            } else {
                removeFromContent();
                hideTooltip();
            }
        });
    }


    function onToggle(e) {
        isEnabled = e.target.checked;
        localStorage.setItem(LS_KEY, isEnabled ? '1' : '0');

        const label = document.getElementById('rdrg-switch-label');
        if (label) {
            const wrap    = label.closest('[data-label-on]');
            const labelOn  = wrap ? wrap.dataset.labelOn  : i18n.labelOn;
            const labelOff = wrap ? wrap.dataset.labelOff : i18n.labelOff;
            label.textContent = isEnabled ? labelOn : labelOff;
        }

        if (isEnabled) {
            if (!loadingDone) {
                loadTermsAsync().then(applyToContent);
            } else {
                applyToContent();
            }
        } else {
            removeFromContent();
            hideTooltip();
        }
    }

    /* ─────────────────────────────────────────────────────
       CHARGEMENT ASYNC DES TERMES (REST API + sessionStorage)
    ───────────────────────────────────────────────────── */
    async function loadTermsAsync() {
        // 1. Vérifier sessionStorage
        const cached = getSessionCache();
        if (cached) {
            if (cfg.debug) console.log('[rdrg] Cache hit for lang:', CACHE_LANG);
            buildTermsMap(cached);
            loadingDone = true;
            return;
        }

        // 2. Charger depuis l'API REST
        try {
            if (cfg.debug) console.log('[rdrg] Fetching terms for lang:', cfg.lang || 'default');
            const lang   = cfg.lang ? `?lang=${encodeURIComponent(cfg.lang)}` : '';
            const url    = cfg.restUrl + 'terms' + lang;
            const headers= {};
            if (cfg.restNonce) headers['X-WP-Nonce'] = cfg.restNonce;

            const res  = await fetch(url, { headers, credentials: 'same-origin' });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);

            const data = await res.json();
            setSessionCache(data);
            buildTermsMap(data);
            loadingDone = true;

            if (cfg.debug) console.log('[rdrg] Loaded', Object.keys(termsMap).length, 'term entries');
        } catch (err) {
            if (cfg.debug) console.warn('[rdrg] Failed to load terms:', err);
        }
    }

    function buildTermsMap(data) {
        termsMap = {};
        if (!Array.isArray(data)) return;
        data.forEach(function (entry) {
            if (!entry.terms) return;
            entry.terms.forEach(function (term) {
                const key = term.toLowerCase().trim();
                if (key) termsMap[key] = {
                    title:        entry.title,
                    definition:   entry.definition,
                    link:         entry.link || '',
                    slug:         entry.slug,
                };
            });
        });
    }

    /* SessionStorage helpers */
    function getSessionCache() {
        try {
            const ts   = sessionStorage.getItem(CACHE_KEY_TS);
            const data = sessionStorage.getItem(CACHE_KEY);
            if (!ts || !data) return null;
            if (Date.now() - parseInt(ts, 10) > CACHE_TTL) return null;
            return JSON.parse(data);
        } catch (e) { return null; }
    }

    function setSessionCache(data) {
        try {
            sessionStorage.setItem(CACHE_KEY, JSON.stringify(data));
            sessionStorage.setItem(CACHE_KEY_TS, Date.now().toString());
        } catch (e) { /* quota */ }
    }

    /* ─────────────────────────────────────────────────────
       SCANNER ET WRAPPER LES TERMES DANS LE CONTENU
    ───────────────────────────────────────────────────── */
    function applyToContent() {
        if (!Object.keys(termsMap).length) return;

        const mode = cfg.matchMode || 'first_block'; // 'all' | 'first_article' | 'first_block'

        // Pour 'first_article' : un seul Set partagé pour tout le contenu
        const seenArticle = (mode === 'first_article') ? new Set() : null;

        const containers = document.querySelectorAll(
            '.entry-content p, .entry-content li, .entry-content td, .entry-content blockquote'
        );

        containers.forEach(function (el) {
            if (el.querySelector('.rdrg-term')) return; // déjà traité
            if (el.closest('.rdrg-switch-wrap')) return;
            if (el.closest('pre, code, .wp-block-code, .syntaxhighlighter')) return;

            wrapTermsInNode(el, mode, seenArticle);
        });

        bindTooltipEvents();
    }

    function removeFromContent() {
        document.querySelectorAll('.rdrg-term').forEach(function (span) {
            const text = document.createTextNode(span.textContent);
            span.parentNode.replaceChild(text, span);
        });
        document.querySelectorAll('.entry-content').forEach(function (el) {
            el.normalize();
        });
    }

    /**
     * Wrapper les termes dans un bloc (p, li, td…).
     * mode         : 'all' | 'first_article' | 'first_block'
     * seenArticle  : Set partagé sur tout l'article (utilisé uniquement en mode first_article)
     */
    function wrapTermsInNode(el, mode, seenArticle) {
        const termKeys = Object.keys(termsMap);
        if (!termKeys.length) return;

        const sorted  = termKeys.slice().sort((a, b) => b.length - a.length);
        const escaped = sorted.map(t => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
        const pattern = new RegExp('\\b(' + escaped.join('|') + ')\\b', 'gi');

        // Quel Set de déduplication utiliser ?
        // - 'all'           → null  (pas de dédup, tout est wrappé)
        // - 'first_article' → seenArticle (partagé entre tous les blocs)
        // - 'first_block'   → nouveau Set local à ce bloc
        const seen = (mode === 'all')
            ? null
            : (mode === 'first_article' ? seenArticle : new Set());

        walkTextNodes(el, pattern, seen);
    }

    function walkTextNodes(node, pattern, seen) {
        if (node.nodeType === Node.TEXT_NODE) {
            const text = node.nodeValue;
            if (!pattern.test(text)) return;
            pattern.lastIndex = 0;

            const frag = document.createDocumentFragment();
            let lastIndex = 0;
            let match;

            pattern.lastIndex = 0;
            while ((match = pattern.exec(text)) !== null) {
                const before = text.slice(lastIndex, match.index);
                if (before) frag.appendChild(document.createTextNode(before));

                const key  = match[0].toLowerCase();
                const info = termsMap[key];
                const slug = info ? info.slug : key; // clé canonique (alias → même slug)

                // Décider si on wrappe : info existe ET (pas de dédup OU pas encore vu)
                const shouldWrap = info && (seen === null || !seen.has(slug));

                if (shouldWrap) {
                    if (seen) seen.add(slug);
                    const span = document.createElement('span');
                    span.className = 'rdrg-term';
                    span.setAttribute('data-rdrg-key',  key);
                    span.setAttribute('tabindex',        '0');
                    span.setAttribute('role',            'button');
                    span.setAttribute('aria-label',      `${i18n.defPrefix} ${info.title}`);
                    span.textContent = match[0];
                    frag.appendChild(span);
                } else {
                    frag.appendChild(document.createTextNode(match[0]));
                }

                lastIndex = match.index + match[0].length;
            }

            if (lastIndex < text.length) {
                frag.appendChild(document.createTextNode(text.slice(lastIndex)));
            }

            node.parentNode.replaceChild(frag, node);
            return;
        }

        // Nœuds à ignorer complètement (on n'entre pas dedans)
        const skipFully = ['SCRIPT', 'STYLE', 'CODE', 'PRE', 'TEXTAREA', 'SELECT', 'BUTTON'];
        if (skipFully.includes(node.nodeName)) return;
        if (node.classList && node.classList.contains('rdrg-term')) return;

        // Cas spécial <a> : le terme est déjà un lien hypertexte.
        // On entre dans le nœud pour lire le texte, mais si le texte entier
        // correspond à un terme on marque l'<a> lui-même (data-rdrg-key + classe)
        // sans créer un <span> imbriqué (évite <a><span>...</span></a> invalide/cassé).
        if (node.nodeName === 'A') {
            const linkText = node.textContent.trim().toLowerCase();
            if (termsMap[linkText] && !node.classList.contains('rdrg-term')) {
                const info = termsMap[linkText];
                const slug = info.slug;
                if (seen === null || !seen.has(slug)) {
                    if (seen) seen.add(slug);
                    node.classList.add('rdrg-term', 'rdrg-term-link');
                    node.setAttribute('data-rdrg-key', linkText);
                    node.setAttribute('aria-label', `${i18n.defPrefix} ${info.title}`);
                    // Conserver le href existant — le tooltip s'affiche au survol
                    // mais le clic navigue normalement vers l'article lié
                }
            }
            return; // ne pas descendre davantage dans le <a>
        }

        Array.from(node.childNodes).forEach(function (child) {
            walkTextNodes(child, pattern, seen);
        });
    }

    /* ─────────────────────────────────────────────────────
       TOOLTIP
    ───────────────────────────────────────────────────── */
    function bindTooltipEvents() {
        // Délégation sur le contenu
        const content = document.querySelector('.entry-content');
        if (!content) return;

        // Eviter de binder plusieurs fois
        if (content._rdrg_bound) return;
        content._rdrg_bound = true;

        content.addEventListener('mouseover', function (e) {
            const term = e.target.closest('.rdrg-term');
            if (!term) return;
            clearTimeout(hideTimer);
            showTooltip(term);
        });

        content.addEventListener('mouseout', function (e) {
            const term = e.target.closest('.rdrg-term');
            if (!term) return;
            const to = e.relatedTarget;
            if (tooltipEl && tooltipEl.contains(to)) return;
            scheduleHide();
        });

        // Clic sur un terme-lien (<a class="rdrg-term">) :
        // bloquer la navigation — le tooltip est déjà visible au survol,
        // le lien est accessible via le bouton "Ouvrir le lien" dans le tooltip.
        content.addEventListener('click', function (e) {
            const term = e.target.closest('a.rdrg-term');
            if (!term) return;
            e.preventDefault();
            // Afficher/maintenir le tooltip au clic (utile tactile)
            clearTimeout(hideTimer);
            showTooltip(term);
        });

        // Accessibilité clavier
        content.addEventListener('focusin', function (e) {
            const term = e.target.closest('.rdrg-term');
            if (!term) return;
            clearTimeout(hideTimer);
            showTooltip(term);
        });

        content.addEventListener('focusout', function (e) {
            const term = e.target.closest('.rdrg-term');
            if (!term) return;
            scheduleHide();
        });

        // Fermer au clic en dehors
        document.addEventListener('click', function (e) {
            if (tooltipEl && !tooltipEl.contains(e.target) && !e.target.closest('.rdrg-term')) {
                hideTooltip();
            }
        });
    }

    function showTooltip(termEl) {
        if (!isEnabled) return;

        const key  = termEl.getAttribute('data-rdrg-key');
        const info = termsMap[key];
        if (!info) return;

        // Recycler ou créer le tooltip DOM
        if (!tooltipEl) {
            tooltipEl = document.createElement('div');
            tooltipEl.className = 'rdrg-tooltip';
            tooltipEl.setAttribute('role', 'tooltip');
            document.body.appendChild(tooltipEl);

            tooltipEl.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
            tooltipEl.addEventListener('mouseleave', scheduleHide);
        }

        // Injecter le contenu
        // Détecter si le terme est lui-même un lien hypertexte
        const isAnchor   = termEl.nodeName === 'A';
        const anchorHref = isAnchor ? termEl.getAttribute('href') : null;

        // Bouton "En savoir plus" (lien glossaire depuis la définition)
        const hasGlossaryLink = info.link && cfg.showLink !== '0';
        const learnHTML = hasGlossaryLink
            ? `<a class="rdrg-tooltip-link" href="${escHtml(info.link)}" target="_blank" rel="noopener">${escHtml(i18n.tooltipLearn)}</a>`
            : '';

        // Bouton "Ouvrir le lien" — uniquement si le terme est un <a>
        const openLinkHTML = anchorHref
            ? `<a class="rdrg-tooltip-link rdrg-tooltip-open-link" href="${escHtml(anchorHref)}">${escHtml(i18n.openLink)}</a>`
            : '';

        tooltipEl.innerHTML = `
            <div class="rdrg-tooltip-inner">
                <div class="rdrg-tooltip-title">${escHtml(info.title)}</div>
                <div class="rdrg-tooltip-def">${escHtml(info.definition)}</div>
                <div class="rdrg-tooltip-actions">${learnHTML}${openLinkHTML}</div>
                <button class="rdrg-tooltip-close" aria-label="Fermer">×</button>
            </div>
        `;

        tooltipEl.querySelector('.rdrg-tooltip-close').addEventListener('click', hideTooltip);

        // Positionner
        positionTooltip(termEl);

        // Animer
        tooltipEl.classList.remove('rdrg-tooltip-visible');
        requestAnimationFrame(function () {
            tooltipEl.classList.add('rdrg-tooltip-visible');
        });
    }

    function positionTooltip(termEl) {
        const rect   = termEl.getBoundingClientRect();
        const tWidth = 280;
        const margin = 8;
        const scrollY = window.scrollY || window.pageYOffset;
        const scrollX = window.scrollX || window.pageXOffset;

        // Position par défaut : au-dessus
        let top  = rect.top + scrollY - margin;
        let left = rect.left + scrollX + (rect.width / 2) - (tWidth / 2);

        // Ajuster si dépasse à droite
        if (left + tWidth > window.innerWidth - 16) {
            left = window.innerWidth - tWidth - 16;
        }
        if (left < 8) left = 8;

        // Si pas de place au-dessus → passer en dessous
        const toAbove = rect.top;
        if (toAbove < 120) {
            top = rect.bottom + scrollY + margin;
            tooltipEl.classList.add('rdrg-tooltip-below');
        } else {
            tooltipEl.classList.remove('rdrg-tooltip-below');
        }

        tooltipEl.style.width   = tWidth + 'px';
        tooltipEl.style.left    = left + 'px';
        tooltipEl.style.top     = top + 'px';
    }

    function scheduleHide() {
        hideTimer = setTimeout(hideTooltip, 250);
    }

    function hideTooltip() {
        if (!tooltipEl) return;
        tooltipEl.classList.remove('rdrg-tooltip-visible');
    }

    /* ─────────────────────────────────────────────────────
       UTILS
    ───────────────────────────────────────────────────── */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ─────────────────────────────────────────────────────
       DÉMARRAGE
    ───────────────────────────────────────────────────── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
