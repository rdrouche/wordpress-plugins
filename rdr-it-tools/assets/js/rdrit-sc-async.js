/**
 * RDR-IT Tools — Shortcode async loader
 * Handle WP : rdrit-sc-async
 */
(function () {
    'use strict';

    if (typeof window.rdritScConfig === 'undefined') return;

    var ajaxUrl = window.rdritScConfig.ajaxUrl;

    function loadWidget(el) {
        var raw = el.dataset.rdritSc;
        if (!raw) return;

        var cfg;
        try { cfg = JSON.parse(raw); } catch (e) { return; }

        var body = new URLSearchParams({
            action  : 'rdrit_sc_load',
            nonce   : cfg.nonce    || '',
            cpt     : cfg.cpt      || 'post',
            count   : cfg.count    || 3,
            category: cfg.category || '',
            mode    : cfg.mode     || 'latest',
            title   : cfg.title    || '',
            cache   : cfg.cache    || 60,
            lang    : cfg.lang     || '',
        });

        /* Nettoyage du placeholder quand aucun contenu n'a pu etre charge :
           on masque completement le wrapper au lieu de laisser un skeleton
           vide ou un bloc blanc dans la page. */
        function fail(reason) {
            if (window.rdritScDebug) {
                console.warn('[RDRIT Shortcode] Echec du chargement :', reason, el);
            }
            el.style.minHeight = '';
            el.removeAttribute('aria-busy');
            el.className = el.className.replace('rdrit-sc-skeleton', '').trim();
            el.innerHTML = '';
            el.style.display = 'none';
        }

        function render(html) {
            var currentH = el.offsetHeight;
            el.style.minHeight = currentH + 'px';

            el.innerHTML = html;
            el.className = el.className.replace('rdrit-sc-skeleton', '').trim();
            el.removeAttribute('aria-busy');
            el.removeAttribute('data-rdrit-sc');

            requestAnimationFrame(function () {
                el.style.transition = 'min-height .3s ease';
                el.style.minHeight  = '';
                setTimeout(function () { el.style.transition = ''; }, 350);
            });
        }

        fetch(ajaxUrl, {
            method     : 'POST',
            headers    : { 'Content-Type': 'application/x-www-form-urlencoded' },
            body       : body.toString(),
            credentials: 'same-origin',
            cache      : 'no-store',
        })
        .then(function (r) {
            /* WordPress renvoie "-1" en texte brut si le nonce echoue :
               r.json() leverait une exception. On lit d'abord en texte. */
            return r.text().then(function (txt) {
                if (txt === '-1' || txt === '0') {
                    throw new Error('nonce_or_permission');
                }
                try {
                    return JSON.parse(txt);
                } catch (e) {
                    throw new Error('invalid_json: ' + txt.slice(0, 120));
                }
            });
        })
        .then(function (data) {
            if (!data || !data.success || !data.data || !data.data.html) {
                fail('reponse vide ou invalide');
                return;
            }
            render(data.data.html);
        })
        .catch(function (err) {
            fail(err && err.message ? err.message : 'erreur reseau');
        });
    }

    function init() {
        var widgets = document.querySelectorAll('[data-rdrit-sc]');
        if (!widgets.length) return;

        if (!('IntersectionObserver' in window)) {
            widgets.forEach(loadWidget);
            return;
        }

        var obs = new IntersectionObserver(function (entries, observer) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    observer.unobserve(entry.target);
                    loadWidget(entry.target);
                }
            });
        }, { rootMargin: '300px' });

        widgets.forEach(function (el) { obs.observe(el); });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

}());
