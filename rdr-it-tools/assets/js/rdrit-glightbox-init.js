/**
 * RDR-IT Tools — GLightbox init
 * Handle WP : rdrit-glightbox-init
 *
 * - Cible tous les <a href="..."> contenant une <img> dans .inside-article
 * - Ajoute la classe .glightbox + data-gallery + data-title (depuis alt)
 * - Initialise GLightbox
 * - Compatible avec le systeme anti-adblock (sélecteur .wp-block-image > .glightbox)
 */
(function () {
    'use strict';

    function init() {
        var cfg    = window.rdritGlightboxConfig || {};
        var scope  = cfg.scope  || '.inside-article, .entry-content, .post-content';
        var gallery = cfg.gallery || 'article';

        // Selectionner tous les liens contenant une image dans la zone article
        var containers = document.querySelectorAll(scope);
        containers.forEach(function (container) {
            container.querySelectorAll('a').forEach(function (el) {
                // Ne cibler que les liens vers une image (ext connues ou déjà .glightbox)
                var href = el.getAttribute('href') || '';
                var hasImg = el.querySelector('img');
                if (!hasImg) return;
                if (!href.match(/\.(jpe?g|png|gif|webp|avif|svg)(\?|$)/i) && !el.classList.contains('glightbox')) return;

                el.classList.add('glightbox');
                el.setAttribute('data-gallery', gallery);

                // Titre depuis l'alt de l'image
                var img = el.querySelector('img');
                var alt = img ? (img.getAttribute('alt') || '') : '';
                if (alt && !el.getAttribute('data-title')) {
                    el.setAttribute('data-title', alt);
                }
            });
        });

        // Init GLightbox
        if (typeof GLightbox !== 'undefined') {
            // Stocker l'instance pour permettre la reinit par rdrit-adblock.js
            window.rdritGlightboxInstance = GLightbox({
                selector : '.glightbox',
                touchNavigation : true,
                loop        : cfg.loop        !== undefined ? cfg.loop        : true,
                autoplayVideos : false,
                zoomable    : cfg.zoomable    !== undefined ? cfg.zoomable    : true,
                draggable   : true,
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
