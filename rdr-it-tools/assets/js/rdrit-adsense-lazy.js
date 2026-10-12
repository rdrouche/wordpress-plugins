/**
 * RDR-IT Tools — Lazy Load AdSense
 * Handle WP : rdrit-adsense-lazy
 * Config injectee via wp_localize_script : window.rdritAdsConfig
 */
(function () {
    'use strict';

    if (typeof window.rdritAdsConfig === 'undefined') return;

    var cfg      = window.rdritAdsConfig;
    var selector = cfg.lazySelector || '#main';
    var margin   = cfg.lazyMargin   || '1000';
    var src      = cfg.adsSrc       || '';

    if (!src) return;

    var el = document.querySelector(selector);
    if (!el) return;

    var loaded = false;

    var obs = new IntersectionObserver(function (entries) {
        if (entries[0].isIntersecting && !loaded) {
            loaded = true;
            var s       = document.createElement('script');
            s.src       = src;
            s.async     = true;
            s.crossOrigin = 'anonymous';
            document.head.appendChild(s);
            obs.disconnect();
        }
    }, { rootMargin: margin + 'px' });

    obs.observe(el);
}());
