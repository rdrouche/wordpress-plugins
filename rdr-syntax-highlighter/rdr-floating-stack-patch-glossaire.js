/**
 * rdr-floating-stack-patch-glossaire.js
 * =========================================
 * Patch à ajouter dans le JS du plugin rdr-glossaire.
 *
 * INSTRUCTIONS :
 * Dans le fichier JS du glossaire qui crée le switch flottant,
 * remplacer le code de positionnement manuel (bottom/right/left)
 * par un appel à window.rdrFloatingStack.register().
 *
 * AVANT (dans rdr-glossaire.js) :
 * ─────────────────────────────────
 *   sw.style.position = 'fixed';
 *   sw.style.bottom   = '24px';
 *   sw.style.right    = '24px';
 *   document.body.appendChild(sw);
 *
 * APRÈS :
 * ─────────────────────────────────
 *   document.body.appendChild(sw);
 *   // Enregistrement dans le stack partagé — priorité 50 (plus bas que sh=60)
 *   window.rdrFloatingStack.register(sw, 'right', 50);
 *   // Pour la gauche :
 *   // window.rdrFloatingStack.register(sw, 'left', 50);
 *
 * Le stack est initialisé par rdr-syntax-highlighter ou par le glossaire,
 * quel que soit l'ordre de chargement. Si le glossaire se charge en premier,
 * il crée le stack ; si rdr-sh se charge en premier, il le réutilise.
 *
 * RÉSULTAT :
 *   bottom 24px → switch glossaire  (priorité 50, plus bas)
 *   bottom ~80px → switch coloration (priorité 60, au-dessus)
 *
 * Le stack gère aussi le back-to-top et le responsive (masqué < 768px).
 */

/* Initialisation défensive du stack (si glossaire se charge avant sh) */
window.rdrFloatingStack = window.rdrFloatingStack || (function() {
    var entries = [];
    var GAP = 10, MARGIN = 24;

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
        var right = entries.filter(function(e){ return e.side !== 'left'; });
        var left  = entries.filter(function(e){ return e.side === 'left'; });
        [right, left].forEach(function(group) {
            group.sort(function(a,b){ return a.priority - b.priority; });
            var side = group.length && group[0].side === 'left' ? 'left' : 'right';
            var cur  = getBtBottom();
            group.forEach(function(entry) {
                var el = entry.el;
                if (window.innerWidth < 768) { el.style.display = 'none'; return; }
                el.style.display = '';
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
        register:   function(el, side, priority) { for(var i=0;i<entries.length;i++){if(entries[i].el===el)return;} entries.push({el:el,side:side||'right',priority:priority||50}); requestAnimationFrame(reflow); },
        unregister: function(el) { entries=entries.filter(function(e){return e.el!==el;}); requestAnimationFrame(reflow); },
        reflow:     reflow
    };
})();
