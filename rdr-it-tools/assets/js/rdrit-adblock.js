/**
 * RDR-IT Tools — Détection AdBlock
 * Handle WP : rdrit-adblock
 * Config : window.rdritAdblockConfig (wp_localize_script)
 *
 * Mode debug : activer via rdrit_adblock_debug=1 dans les réglages AdSense.
 * Tous les logs sont préfixés [RDRIT AdBlock] dans la console.
 */
(function () {
    'use strict';

    if (typeof window.rdritAdblockConfig === 'undefined') return;

    var cfg         = window.rdritAdblockConfig;
    var adsUrl      = cfg.adsUrl      || '';
    var action      = cfg.action      || 'none';
    var bannerMsg   = cfg.bannerMsg   || '';
    var bannerColor = cfg.bannerColor || '#1e40af';
    var imgUrl      = cfg.imgUrl      || '';
    var imgSelector = cfg.imgSelector || '.wp-block-image > a';
    var debug       = !!cfg.debug;

    var doBanner = (action === 'banner' || action === 'both');
    var doImages = (action === 'images' || action === 'both');

    /* ── Logger ── */
    var P = '[RDRIT AdBlock]';
    function log() {
        if (!debug) return;
        var args = Array.prototype.slice.call(arguments);
        args.unshift(P);
        console.log.apply(console, args);
    }
    function warn() {
        if (!debug) return;
        var args = Array.prototype.slice.call(arguments);
        args.unshift(P);
        console.warn.apply(console, args);
    }
    function info() {
        if (!debug) return;
        var args = Array.prototype.slice.call(arguments);
        args.unshift('%c' + P, 'color:#2563eb;font-weight:bold');
        console.info.apply(console, args);
    }

    if (debug) {
        info('Initialisation');
        log('Config reçue :', {
            adsUrl      : adsUrl,
            action      : action,
            doBanner    : doBanner,
            doImages    : doImages,
            imgSelector : imgSelector,
            imgUrl      : imgUrl,
        });
    }

    /* ── Injecter le bandeau dans le DOM ── */
    if (doBanner && bannerMsg) {
        var banner       = document.createElement('div');
        banner.id        = 'rdrit-adblock-banner';
        banner.style.cssText = [
            'display:none',
            'position:fixed',
            'bottom:0',
            'left:0',
            'right:0',
            'z-index:99999',
            'background:' + bannerColor,
            'color:#fff',
            'padding:14px 20px',
            'font-size:.95rem',
            'font-weight:600',
            'text-align:center',
            'box-shadow:0 -2px 12px rgba(0,0,0,.2)',
            'align-items:center',
            'justify-content:center',
            'gap:16px',
        ].join(';');

        var msg  = document.createTextNode(bannerMsg);
        var span = document.createElement('span');
        span.appendChild(msg);

        var btn           = document.createElement('button');
        btn.textContent   = '✕ Fermer';
        btn.style.cssText = 'background:rgba(255,255,255,.2);border:none;color:#fff;padding:5px 14px;border-radius:20px;cursor:pointer;font-weight:700;margin-left:12px';
        btn.addEventListener('click', function () {
            banner.style.display = 'none';
            log('Bannière fermée par l\'utilisateur');
        });

        banner.appendChild(span);
        banner.appendChild(btn);
        document.body.appendChild(banner);
        log('Bannière créée dans le DOM (masquée)');
    }

    /* ── Réinitialiser GLightbox après remplacement des href ── */
    function reinitGlightbox() {
        if (typeof GLightbox === 'undefined') return;
        try {
            if (window.rdritGlightboxInstance && typeof window.rdritGlightboxInstance.destroy === 'function') {
                window.rdritGlightboxInstance.destroy();
                log('GLightbox : instance détruite');
            }
            var cfg2 = window.rdritGlightboxConfig || {};
            window.rdritGlightboxInstance = GLightbox({
                selector       : '.glightbox',
                touchNavigation: true,
                loop           : cfg2.loop     !== undefined ? cfg2.loop     : true,
                zoomable       : cfg2.zoomable !== undefined ? cfg2.zoomable : true,
                draggable      : true,
                autoplayVideos : false,
            });
            log('GLightbox : réinitialisé');
        } catch(e) {
            warn('GLightbox : erreur lors de la réinit :', e);
        }
    }

    /* ── Actions quand AdBlock détecté ── */
    function applyAdblockActions() {
        window.isAdblockTrue = true;
        info('AdBlock DÉTECTÉ — application des actions (action=' + action + ')');

        if (doBanner) {
            var b = document.getElementById('rdrit-adblock-banner');
            if (b) {
                b.style.display = 'flex';
                log('Bannière affichée');
            } else {
                warn('Bannière introuvable dans le DOM (#rdrit-adblock-banner absent)');
            }
        }

        if (doImages && imgUrl) {
            setTimeout(function () {
                var els = document.querySelectorAll(imgSelector);
                log('Remplacement images — sélecteur :', imgSelector, '— éléments trouvés :', els.length);
                if (els.length === 0) {
                    warn('Aucun élément trouvé avec le sélecteur "' + imgSelector + '" — vérifier la config');
                }
                els.forEach(function (el) {
                    var oldHref = el.getAttribute('href');
                    el.setAttribute('href', imgUrl);
                    log('Image remplacée :', oldHref, '→', imgUrl, 'sur', el);
                });
                reinitGlightbox();
            }, 50);
        } else if (doImages && !imgUrl) {
            warn('Action images activée mais imgUrl est vide — aucun remplacement effectué');
        }
    }

    /* ── Détection via XHR + sonde DOM ── */
    function checkAdBlock() {
        if (!adsUrl) {
            warn('adsUrl est vide — détection impossible. Vérifier la config AdSense (ID client requis).');
            return;
        }

        log('Lancement XHR vers :', adsUrl);

        var xhr = new XMLHttpRequest();
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== XMLHttpRequest.DONE) return;

            log('XHR terminé — status:', xhr.status, '— responseURL:', xhr.responseURL);

            var blockedByXhr = (xhr.status === 0 || xhr.responseURL !== adsUrl);
            log('XHR bloqué :', blockedByXhr,
                '(status=0 :', xhr.status === 0,
                '/ URL différente :', xhr.responseURL !== adsUrl, ')');

            if (blockedByXhr) {
                var probe = document.getElementById('rdrittestnav');
                log('Sonde DOM (#rdrittestnav) :', probe ? 'trouvée' : 'absente',
                    probe ? '— offsetHeight=' + probe.offsetHeight : '');

                if (probe && probe.offsetHeight > 0) {
                    warn('Sonde DOM visible malgré XHR bloqué → faux positif réseau. AdBlock NON déclenché.');
                    return;
                }

                log('Sonde absente ou cachée → AdBlock confirmé');
                applyAdblockActions();
            } else {
                log('XHR réussi → pas d\'AdBlock détecté. Aucune action.');
            }
        };

        xhr.open('HEAD', adsUrl, true);
        xhr.send(null);
        log('XHR envoyé');
    }

    if (document.readyState === 'loading') {
        log('DOM pas encore prêt — attente DOMContentLoaded');
        document.addEventListener('DOMContentLoaded', checkAdBlock);
    } else {
        log('DOM prêt — lancement immédiat');
        checkAdBlock();
    }

}());
