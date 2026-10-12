/**
 * RDR Syntax Highlighter — v1.8
 * 0 jQuery — Vanilla JS uniquement
 */
(function () {
    'use strict';

    /* ============================================================
       REGISTRE GLOBAL SWITCHS FLOTTANTS (partagé inter-plugins RDR)
       ============================================================ */
    window.rdrFloatingStack = window.rdrFloatingStack || (function() {
        var entries = [], GAP = 10, MARGIN = 24;
        function getBtBottom() {
            var btEl = document.querySelector(
                '.generate-back-to-top, #generate-back-to-top,' +
                'a[class*="back-to-top"], button[class*="back-to-top"],' +
                '[class*="scroll-to-top"]:not(.rdr-sh-switch):not(.rdr-glossaire-switch)'
            );
            if (btEl) {
                var cs = window.getComputedStyle(btEl);
                if (cs.position === 'fixed')
                    return (parseFloat(cs.bottom) || MARGIN) + (btEl.offsetHeight || 44) + GAP;
            }
            return MARGIN;
        }
        function reflow() {
            [
                entries.filter(function(e){ return e.side !== 'left'; }),
                entries.filter(function(e){ return e.side === 'left'; })
            ].forEach(function(group) {
                group.sort(function(a, b){ return a.priority - b.priority; });
                var side = group.length && group[0].side === 'left' ? 'left' : 'right';
                var cur  = getBtBottom();
                group.forEach(function(entry) {
                    var el = entry.el;
                    if (window.innerWidth < 768) { el.style.display = 'none'; return; }
                    el.style.display  = '';
                    el.style.position = 'fixed';
                    el.style.bottom   = cur + 'px';
                    el.style.zIndex   = '9990';
                    if (side === 'left') { el.style.left = MARGIN+'px'; el.style.right = 'auto'; }
                    else                 { el.style.right = MARGIN+'px'; el.style.left = 'auto'; }
                    cur += el.offsetHeight + GAP;
                });
            });
        }
        window.addEventListener('resize', reflow, { passive: true });
        return {
            register:   function(el, side, prio) {
                for (var i = 0; i < entries.length; i++) { if (entries[i].el === el) return; }
                entries.push({ el: el, side: side || 'right', priority: prio || 50 });
                requestAnimationFrame(reflow);
            },
            unregister: function(el) {
                entries = entries.filter(function(e){ return e.el !== el; });
                requestAnimationFrame(reflow);
            },
            reflow: reflow
        };
    })();

    /* ============================================================
       CONFIG
       ============================================================ */
    var cfg = window.rdrSH || {};
    cfg.ajaxUrl         = cfg.ajaxUrl         || '';
    cfg.nonce           = cfg.nonce           || '';
    cfg.showLineNumbers = !!(cfg.showLineNumbers === true || cfg.showLineNumbers === 'true'
                          || cfg.showLineNumbers === 1    || cfg.showLineNumbers === '1');
    cfg.switchPosition  = cfg.switchPosition  || 'floating-right';
    cfg.switchLabel     = cfg.switchLabel     || '🎨 Coloration';
    cfg.isSingular      = !!(cfg.isSingular   === true || cfg.isSingular   === 'true'
                          || cfg.isSingular   === 1    || cfg.isSingular   === '1');
    cfg.isEnabledCpt    = !!(cfg.isEnabledCpt === true || cfg.isEnabledCpt === 'true'
                          || cfg.isEnabledCpt === 1    || cfg.isEnabledCpt === '1');

    var LS_KEY     = 'rdr_syntax_hl';
    var isEnabled  = localStorage.getItem(LS_KEY) === 'true';
    var observer   = null;

    /*
     * SESSION TOKEN — clé de génération courante.
     * Incrémenté à chaque stopHighlight().
     * Chaque fetch capture le token au moment où il démarre.
     * Si le token a changé quand le .then() s'exécute → résultat ignoré.
     * Résout le cas où la réponse arrive APRÈS le toggle OFF.
     */
    var sessionToken = 0;

    /* ============================================================
       TROUVER LES BLOCS
       ============================================================ */
    function findBlocks() {
        var result = [], seen = typeof Set !== 'undefined' ? new Set() : null;
        function track(el) { if (seen) seen.add(el); }
        function known(el) { return seen ? seen.has(el) : false; }
        function add(el) {
            if (!el || known(el)) return;
            if (el.dataset.rdrHighlighted) return; // déjà colorisé
            if (el.closest && el.closest('.syntaxhighlighter, .wp-block-syntaxhighlighter-code')) return;
            track(el);
            result.push(el);
        }
        document.querySelectorAll('.wp-block-code').forEach(function(w) {
            var pre = w.tagName === 'PRE' ? w : w.querySelector('pre');
            if (pre) add(pre); else add(w);
        });
        document.querySelectorAll('.entry-content, .post-content, .hentry, article').forEach(function(area) {
            area.querySelectorAll('pre').forEach(function(pre) {
                if (!pre.closest('.wp-block-code') &&
                    !pre.closest('.syntaxhighlighter') &&
                    !pre.closest('.wp-block-syntaxhighlighter-code')) { add(pre); }
            });
        });
        return result;
    }

    /* ============================================================
       BOUTON COPIER NATIF — sur les <pre> non colorisés
       Injecté au chargement ET après stopHighlight().
       Idempotent : data-rdr-copy-bound évite les doublons.
       ============================================================ */
    function initNativeCopyButtons() {
        findBlocks().forEach(function(block) {
            if (block.dataset.rdrCopyBound) return;
            block.dataset.rdrCopyBound = '1';

            /* Créer le bouton */
            var btn = document.createElement('button');
            btn.className  = 'rdr-sh-native-copy';
            btn.type       = 'button';
            btn.title      = 'Copier le code';
            btn.setAttribute('aria-label', 'Copier le code');
            btn.innerHTML  =
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="13" height="13"' +
                ' fill="currentColor" aria-hidden="true">' +
                '<path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14' +
                'c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>' +
                '<span>Copier</span>';

            /* Position relative sur le bloc pour que le bouton soit absolu */
            var existingPos = window.getComputedStyle(block).position;
            if (existingPos === 'static') block.style.position = 'relative';

            block.appendChild(btn);

            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                var codeEl  = block.querySelector('code');
                var rawCode = (codeEl ? codeEl.textContent : block.textContent) || '';
                /* Exclure le texte du bouton lui-même */
                rawCode = rawCode.replace(/Copier|Copié\s*!\s*/g, '').trim();
                if (!rawCode) return;
                doCopy(rawCode, btn);
            });
        });
    }

    /* ============================================================
       INIT
       ============================================================ */
    function init() {
        var blocks     = findBlocks();
        var shouldShow = (cfg.isEnabledCpt && cfg.isSingular) || blocks.length > 0;
        if (!shouldShow) return;
        setupSwitch();
        /* Bouton copier sur les blocs natifs (toujours, même sans coloration) */
        initNativeCopyButtons();
        if (isEnabled && blocks.length > 0) startLazyHighlight();
    }

    /* ============================================================
       SWITCH
       ============================================================ */
    function setupSwitch() {
        var pos = cfg.switchPosition;
        if (pos === 'under-title') {
            var ph = document.getElementById('rdr-sh-under-title');
            if (ph && !ph.querySelector('.rdr-sh-switch__toggle')) {
                ph.appendChild(buildSwitchEl('under-title'));
            }
        }
        if (pos === 'floating-right' || pos === 'floating-left') {
            if (!document.getElementById('rdr-sh-switch')) {
                var side = pos === 'floating-left' ? 'left' : 'right';
                var sw   = buildSwitchEl(pos);
                sw.id    = 'rdr-sh-switch';
                document.body.appendChild(sw);
                window.rdrFloatingStack.register(sw, side, 60);
            }
        }
        bindAllSwitches();
    }

    function buildSwitchEl(pos) {
        var wrap = document.createElement('div');
        wrap.className = 'rdr-sh-switch';
        var isFloating = (pos === 'floating-right' || pos === 'floating-left');
        if (pos === 'floating-right') { wrap.classList.add('rdr-sh-switch--floating', 'rdr-sh-switch--right'); }
        if (pos === 'floating-left')  { wrap.classList.add('rdr-sh-switch--floating', 'rdr-sh-switch--left');  }
        if (pos === 'under-title')    { wrap.classList.add('rdr-sh-switch--glossaire'); }
        var toggleHtml =
            '<button class="rdr-sh-switch__toggle rdr-sh-glossaire-btn" role="switch"' +
            ' aria-checked="' + (isEnabled ? 'true' : 'false') + '"' +
            ' type="button" aria-label="Activer la coloration syntaxique">' +
            '<span class="rdr-sh-switch__knob"></span></button>';
        var labelHtml = '<span class="rdr-sh-switch__label">' + escHTML(cfg.switchLabel) + '</span>';
        wrap.innerHTML = isFloating ? (labelHtml + toggleHtml) : (toggleHtml + labelHtml);
        updateUI(wrap, isEnabled);
        return wrap;
    }

    function bindAllSwitches() {
        document.querySelectorAll('.rdr-sh-switch__toggle:not([data-rdr-sh-bound])').forEach(function(btn) {
            btn.setAttribute('data-rdr-sh-bound', '1');
            btn.setAttribute('aria-checked', isEnabled ? 'true' : 'false');
            if (isEnabled) btn.classList.add('rdr-sh-switch__toggle--on');
            btn.addEventListener('click', function() {
                isEnabled = !isEnabled;
                localStorage.setItem(LS_KEY, isEnabled ? 'true' : 'false');
                syncAllSwitches(isEnabled);
                isEnabled ? startLazyHighlight() : stopHighlight();
            });
        });
    }

    function syncAllSwitches(enabled) {
        document.querySelectorAll('.rdr-sh-switch__toggle').forEach(function(btn) {
            btn.setAttribute('aria-checked', enabled ? 'true' : 'false');
            btn.classList.toggle('rdr-sh-switch__toggle--on', enabled);
        });
        document.querySelectorAll('.rdr-sh-switch').forEach(function(sw) {
            sw.classList.toggle('rdr-sh-switch--active', enabled);
        });
    }

    function updateUI(wrap, enabled) {
        var btn = wrap.querySelector('.rdr-sh-switch__toggle');
        if (btn) {
            btn.setAttribute('aria-checked', enabled ? 'true' : 'false');
            btn.classList.toggle('rdr-sh-switch__toggle--on', enabled);
        }
        wrap.classList.toggle('rdr-sh-switch--active', enabled);
    }

    /* ============================================================
       API SHORTCODE
       ============================================================ */
    window.rdrSH = window.rdrSH || {};
    window.rdrSH._shortcodeSwitches = window.rdrSH._shortcodeSwitches || [];
    window.rdrSH.registerShortcodeSwitch = function(el) {
        window.rdrSH._shortcodeSwitches.push(el);
        var btn = el.querySelector('.rdr-sh-switch__toggle');
        if (btn) {
            btn.setAttribute('aria-checked', isEnabled ? 'true' : 'false');
            btn.classList.toggle('rdr-sh-switch__toggle--on', isEnabled);
        }
        bindAllSwitches();
    };

    /* ============================================================
       LAZY HIGHLIGHT
       ============================================================ */
    function startLazyHighlight() {
        var blocks = findBlocks();
        if (!blocks.length) return;
        if (observer) observer.disconnect();
        observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    observer.unobserve(entry.target);
                    highlightBlock(entry.target);
                }
            });
        }, { rootMargin: '200px 0px', threshold: 0 });
        blocks.forEach(function(b) { observer.observe(b); });
    }

    /* ============================================================
       STOP HIGHLIGHT
       Ordre critique :
        1. Invalider le sessionToken → tous les fetch en cours sont ignorés
        2. Supprimer loaders du DOM (synchrone)
        3. Nettoyer data-rdr-loading (synchrone)
        4. Abort des fetch (déclenche .catch en microtask, mais token invalide → ignorés)
        5. Restaurer les blocs colorisés
       ============================================================ */
    function stopHighlight() {
        if (observer) { observer.disconnect(); observer = null; }

        /* Invalider la session — les .then() en vol seront ignorés */
        sessionToken++;

        /* Supprimer TOUS les loaders du DOM immédiatement */
        document.querySelectorAll('.rdr-sh-loader').forEach(function(l) { l.remove(); });

        /* Libérer les blocs en cours de chargement */
        document.querySelectorAll('[data-rdr-loading]').forEach(function(b) {
            delete b.dataset.rdrLoading;
        });

        /* Abort (les callbacks async ne feront rien grâce au token) */
        var ctrls = abortCtrls.slice();
        abortCtrls = [];
        ctrls.forEach(function(ctrl) { try { ctrl.abort(); } catch(e) {} });

        /* Restaurer les blocs colorisés
           Le snapshot stocké est propre : sans data-rdr-copy-bound
           ni bouton .rdr-sh-native-copy → initNativeCopyButtons() les rebindera */
        document.querySelectorAll('[data-rdr-original]').forEach(function(b) {
            var html = b.dataset.rdrOriginal;
            if (html && b.parentNode) {
                var tmp = document.createElement('div');
                tmp.innerHTML = html;
                if (tmp.firstChild) b.parentNode.replaceChild(tmp.firstChild, b);
            }
        });

        /* Les blocs restaurés sont de nouveaux nœuds → réinjecter les boutons copier */
        initNativeCopyButtons();
    }

    /* ============================================================
       HIGHLIGHT AJAX
       ============================================================ */
    /*
     * abortCtrls est remplacé par un tableau simple.
     * On n'utilise plus AbortController pour le nettoyage principal —
     * le sessionToken est la source de vérité.
     * AbortController reste utilisé pour libérer la connexion réseau.
     */
    var abortCtrls = [];

    function highlightBlock(block) {
        if (block.dataset.rdrHighlighted || block.dataset.rdrLoading) return;
        var codeEl  = block.querySelector('code') || block;
        var rawCode = (codeEl.innerText || codeEl.textContent || '').trim();
        if (!rawCode) return;

        /* Capturer le token au moment du démarrage */
        var myToken  = sessionToken;

        var language = detectLanguage(block);
        var filename = detectFilename(block);

        /* Sauvegarder l'état "propre" du bloc AVANT tout marquage
           → on retire data-rdr-copy-bound et le bouton natif du snapshot
              pour que la restauration soit propre */
        var cleanSnapshot = (function() {
            var clone = block.cloneNode(true);
            delete clone.dataset.rdrCopyBound;
            delete clone.dataset.rdrLoading;
            var nativeBtn = clone.querySelector('.rdr-sh-native-copy');
            if (nativeBtn) nativeBtn.remove();
            return clone.outerHTML;
        })();

        block.dataset.rdrLoading = 'true';
        var loaderEl = showLoader(block);

        var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        if (ctrl) abortCtrls.push(ctrl);

        var fd = new FormData();
        fd.append('action',       'rdr_sh_highlight');
        fd.append('nonce',        cfg.nonce);
        fd.append('code',         rawCode);
        fd.append('language',     language);
        fd.append('line_numbers', cfg.showLineNumbers ? '1' : '0');
        fd.append('filename',     filename);

        var opts = { method: 'POST', body: fd };
        if (ctrl) opts.signal = ctrl.signal;

        fetch(cfg.ajaxUrl, opts)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (ctrl) abortCtrls = abortCtrls.filter(function(c){ return c !== ctrl; });

                /* Si le token a changé → stopHighlight() a été appelé entre temps → on ignore */
                if (sessionToken !== myToken) return;

                /* Nettoyer le loader */
                removeLoaderEl(loaderEl);
                delete block.dataset.rdrLoading;

                if (data.success && data.data && data.data.html) {
                    replaceBlock(block, data.data.html, rawCode, cleanSnapshot);
                }
            })
            .catch(function() {
                if (ctrl) abortCtrls = abortCtrls.filter(function(c){ return c !== ctrl; });
                /* Token invalide ou erreur réseau → nettoyer silencieusement */
                removeLoaderEl(loaderEl);
                if (block.dataset && sessionToken === myToken) {
                    delete block.dataset.rdrLoading;
                }
            });
    }

    function replaceBlock(orig, html, rawCode, cleanSnapshot) {
        var tmp = document.createElement('div');
        tmp.innerHTML = html;
        var nb = tmp.firstChild;
        if (!nb) return;

        /* Utiliser le snapshot propre passé par highlightBlock()
           (sans data-rdr-copy-bound ni .rdr-sh-native-copy)
           Si absent, le calculer ici en fallback */
        if (!cleanSnapshot) {
            var clone = orig.cloneNode(true);
            var nativeBtn = clone.querySelector('.rdr-sh-native-copy');
            if (nativeBtn) nativeBtn.remove();
            delete clone.dataset.rdrCopyBound;
            delete clone.dataset.rdrLoading;
            cleanSnapshot = clone.outerHTML;
        }

        nb.dataset.rdrOriginal    = cleanSnapshot;
        nb.dataset.rdrHighlighted = 'true';
        nb.dataset.rdrRawCode     = rawCode;
        if (orig.parentNode) orig.parentNode.replaceChild(nb, orig);
        applyTheme(nb);
        initCopyBtn(nb);
        initFilenameButtons(nb);
        document.dispatchEvent(new CustomEvent('rdr_sh_highlighted', { detail: { block: nb } }));
    }

    /* ============================================================
       DÉTECTION FILENAME
       ============================================================ */
    function detectFilename(block) {
        var codeEl = block.querySelector('code') || block;
        var parent = block.closest ? block.closest('[data-rdr-filename]') : null;
        var fn = block.dataset.rdrFilename || codeEl.dataset.rdrFilename ||
                 (parent && parent.dataset.rdrFilename) ||
                 block.dataset.filename    || codeEl.dataset.filename    || '';
        if (fn) return fn.trim();
        var classes = (block.className + ' ' + codeEl.className);
        var m = classes.match(/\bfile-([^\s]+)/i);
        if (m) return m[1];
        var code = (codeEl.innerText || '').trim();
        var firstLines = code.split('\n').slice(0, 3).join('\n');
        m = firstLines.match(/(?:#|\/\/|<!--)\s*filename:\s*([^\s\n>]+)/i);
        if (m) return m[1].trim();
        return '';
    }

    /* ============================================================
       DÉTECTION LANGAGE
       ============================================================ */
    function detectLanguage(block) {
        var codeEl = block.querySelector('code');
        var dl = block.dataset.rdrLang || (codeEl && codeEl.dataset.rdrLang) || '';
        if (dl) return normalize(dl);
        var sources = [block.className || '', (codeEl && codeEl.className) || '',
                       (block.parentElement && block.parentElement.className) || ''];
        for (var i = 0; i < sources.length; i++) {
            var m = sources[i].match(/(?:language|lang)-([a-zA-Z0-9_\-]+)/i);
            if (m) return normalize(m[1]);
            m = sources[i].match(/brush:\s*([a-zA-Z0-9_]+)/i);
            if (m) return normalize(m[1]);
        }
        var da = block.dataset.lang || block.dataset.language ||
                 (codeEl && (codeEl.dataset.lang || codeEl.dataset.language)) || '';
        if (da) return normalize(da);
        return autoDetect((codeEl || block).innerText || '');
    }

    function normalize(l) {
        var map = { ps:'powershell',ps1:'powershell',posh:'powershell',pwsh:'powershell',
                    shell:'bash',sh:'bash',zsh:'bash',yml:'yaml',
                    js:'javascript',mjs:'javascript',ts:'typescript',
                    py:'python',rb:'ruby',plaintext:'text',plain:'text',txt:'text' };
        return map[(l||'').toLowerCase().trim()] || (l||'').toLowerCase().trim();
    }

    function autoDetect(code) {
        var s = [
            {l:'powershell',s:scorePS(code)},{l:'bash',s:scoreBash(code)},
            {l:'yaml',s:scoreYaml(code)},{l:'json',s:scoreJson(code)},
            {l:'php',s:scorePHP(code)},{l:'sql',s:scoreSQL(code)},
            {l:'javascript',s:scoreJS(code)},{l:'python',s:scorePy(code)},
            {l:'html',s:scoreHTML(code)},{l:'css',s:scoreCSS(code)}
        ];
        s.sort(function(a,b){return b.s-a.s;});
        return s[0].s >= 3 ? s[0].l : '';
    }

    function scorePS(c)   { var s=0; if(/^\$[A-Za-z_]/m.test(c))s+=2; if(/\b[A-Z][a-z]+-[A-Z][a-zA-Z]+\b/.test(c))s+=3; if(/\b(foreach|param|begin|process|end)\b/i.test(c))s+=2; if(/-(?:eq|ne|lt|gt|le|ge|and|or|not)\b/.test(c))s+=2; if(/Write-(?:Host|Output|Error|Warning)/i.test(c))s+=3; if(/@\(|@\{/.test(c))s+=2; return s; }
    function scoreBash(c) { var s=0; if(/^#!\//.test(c))s+=5; if(/\b(echo|grep|sed|awk|systemctl|apt|yum|dnf|curl|wget)\b/.test(c))s+=2; if(/\$\{?[A-Za-z_]/.test(c))s+=1; if(/\b(if|then|fi|elif|done|while|for|case|esac)\b/.test(c))s+=1; if(/\[\[|\]\]/.test(c))s+=2; return s; }
    function scoreYaml(c) { var lines=c.split('\n'); return lines.filter(function(l){return /^\s*[a-zA-Z_][a-zA-Z0-9_\-]*\s*:/.test(l);}).length*2+lines.filter(function(l){return /^\s*-\s+/.test(l);}).length; }
    function scoreJson(c) { var t=c.trim(); if((t[0]==='{' || t[0]==='[')&&(t[t.length-1]==='}'||t[t.length-1]===']')){try{JSON.parse(t);return 10;}catch(e){return 5;}} return 0; }
    function scorePHP(c)  { var s=0; if(/<\?php/.test(c))s+=5; if(/\$[a-z_]+\s*=/i.test(c))s+=1; if(/\b(function|class|namespace|use|echo)\b/.test(c))s+=1; if(/->/.test(c))s+=1; return s; }
    function scoreSQL(c)  { var s=0; if(/\bSELECT\b.+\bFROM\b/i.test(c))s+=5; if(/\bINSERT\s+INTO\b/i.test(c))s+=4; if(/\bUPDATE\b.+\bSET\b/i.test(c))s+=4; if(/\bDELETE\s+FROM\b/i.test(c))s+=4; return s; }
    function scoreJS(c)   { var s=0; if(/\b(const|let|var)\s+\w+\s*=/.test(c))s+=2; if(/=>\s*[\{\(]/.test(c))s+=3; if(/\bconsole\.(log|error|warn)\b/.test(c))s+=3; if(/document\.|window\.|require\(/.test(c))s+=2; return s; }
    function scorePy(c)   { var s=0; if(/\bdef\s+\w+\s*\(/.test(c))s+=3; if(/\bimport\s+\w+/.test(c))s+=2; if(/\bprint\s*\(/.test(c))s+=2; if(/^\s{4}\w/m.test(c))s+=1; return s; }
    function scoreHTML(c) { var s=0; if(/<(!DOCTYPE|html|head|body)/i.test(c))s+=5; if(/<\w+[^>]*>[\s\S]*<\/\w+>/.test(c))s+=3; return s; }
    function scoreCSS(c)  { var s=0; if(/[.#]?\w+\s*\{[^}]+\}/.test(c))s+=4; if(/:\s*[a-z#0-9]+\s*;/.test(c))s+=2; if(/@media|@keyframes|@import/.test(c))s+=3; return s; }

    /* ============================================================
       LOADER
       ============================================================ */
    function showLoader(block) {
        var existing = block.querySelector('.rdr-sh-loader');
        if (existing) return existing;
        var l = document.createElement('div');
        l.className = 'rdr-sh-loader';
        l.innerHTML = '<span></span><span></span><span></span>';
        block.style.position = 'relative';
        block.appendChild(l);
        return l;
    }
    function removeLoaderEl(loaderEl) {
        if (loaderEl && loaderEl.parentNode) loaderEl.remove();
    }

    /* ============================================================
       THÈME
       ============================================================ */
    function applyTheme(block) {
        block.setAttribute('data-rdr-theme',
            document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark');
    }
    new MutationObserver(function() {
        document.querySelectorAll('.rdr-sh-block').forEach(applyTheme);
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    /* ============================================================
       BOUTON COPIER
       ============================================================ */
    function initCopyBtn(block) {
        var btn = block.querySelector('.rdr-sh-copy-btn');
        if (!btn) return;
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            /*
             * Ordre de priorité :
             *  1. data-rdr-raw-code : code brut stocké avant colorisation
             *  2. textContent du <code> enfant (pas innerText — évite CSS visibility tricks)
             * On n'utilise JAMAIS block.innerText car il inclut le texte
             * de la toolbar (numéros de ligne, bouton "Copier"/"Copié !").
             */
            var rawCode = block.dataset.rdrRawCode || '';
            if (!rawCode) {
                var codeEl = block.querySelector('.rdr-sh-code code, .rdr-sh-code pre code, code');
                rawCode = codeEl ? (codeEl.textContent || '') : '';
            }
            rawCode = rawCode.trim();
            if (!rawCode) return;
            doCopy(rawCode, btn);
        });
    }
    function doCopy(text, btn) {
        var span = btn.querySelector('span'), orig = span ? span.textContent : 'Copier';
        var ok = function() {
            if (span) span.textContent = 'Copié !';
            btn.classList.add('rdr-sh-copy-btn--copied');
            setTimeout(function() {
                if (span) span.textContent = orig;
                btn.classList.remove('rdr-sh-copy-btn--copied');
            }, 2000);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(ok).catch(function() { fallback(text, ok); });
        } else { fallback(text, ok); }
    }
    function fallback(text, cb) {
        var ta = document.createElement('textarea');
        ta.value = text; ta.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
        document.body.appendChild(ta); ta.focus(); ta.select();
        try { document.execCommand('copy'); cb(); } catch(e) {}
        document.body.removeChild(ta);
    }

    /* ============================================================
       FILENAME — ouvrir + télécharger
       ============================================================ */
    function initFilenameButtons(block) {
        var fnBtn = block.querySelector('.rdr-sh-filename');
        if (fnBtn) {
            fnBtn.addEventListener('click', function() {
                var rawCode = block.dataset.rdrRawCode || '';
                var filename = this.dataset.filename || 'code.txt';
                if (!rawCode) return;
                var blob = new Blob([rawCode], { type: 'text/plain;charset=utf-8' });
                var url  = URL.createObjectURL(blob);
                var tab  = window.open(url, '_blank');
                if (tab) {
                    setTimeout(function() { URL.revokeObjectURL(url); }, 5000);
                } else {
                    triggerDownload(rawCode, filename);
                    URL.revokeObjectURL(url);
                }
            });
        }
        var dlBtn = block.querySelector('.rdr-sh-download');
        if (dlBtn) {
            dlBtn.addEventListener('click', function() {
                triggerDownload(block.dataset.rdrRawCode || '', this.dataset.filename || 'code.txt');
            });
        }
    }
    function triggerDownload(content, filename) {
        var blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href = url; a.download = filename;
        a.style.cssText = 'position:fixed;opacity:0;pointer-events:none';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
        setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
    }

    function escHTML(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ============================================================
       API PUBLIQUE
       ============================================================ */
    window.rdrSH.enable  = function() {
        if (isEnabled) return;
        isEnabled = true;
        localStorage.setItem(LS_KEY, 'true');
        syncAllSwitches(true);
        startLazyHighlight();
    };
    window.rdrSH.disable = function() {
        if (!isEnabled) return;
        isEnabled = false;
        localStorage.setItem(LS_KEY, 'false');
        syncAllSwitches(false);
        stopHighlight();
    };
    window.rdrSH.isEnabled = function() { return isEnabled; };

    /* ============================================================
       DÉMARRAGE
       ============================================================ */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
