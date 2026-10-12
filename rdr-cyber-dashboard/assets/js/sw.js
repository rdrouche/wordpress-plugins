/* RDR Cyber Dashboard v1.3.0 — Service Worker VAPID */
'use strict';

var SITE_URL   = '__SITE_URL__';
var AJAX_URL   = '__AJAX_URL__';
var CACHE_NAME = 'rdr-cyber-v1';

self.addEventListener('install', function(e) {
    self.skipWaiting();
});

self.addEventListener('activate', function(e) {
    e.waitUntil(clients.claim());
});

/* ---------------------------------------------------------
   Push event — empty payload, on affiche une notif générique
   et on récupère les données via fetch
   --------------------------------------------------------- */
self.addEventListener('push', function(e) {
    e.waitUntil(
        fetchAndNotify()
    );
});

function fetchAndNotify() {
    return fetch(AJAX_URL + '?action=rdr_cyber_push_payload', {
        method: 'GET',
        cache: 'no-store',
    }).then(function(r) {
        if (!r.ok) throw new Error('fetch failed');
        return r.json();
    }).then(function(data) {
        var title   = (data && data.title) ? data.title : 'Alerte Cybersécurité';
        var body    = (data && data.body)  ? data.body  : 'Nouvelles alertes disponibles.';
        var url     = (data && data.url)   ? data.url   : SITE_URL;
        var icon    = '__PLUGIN_URL__assets/img/icon-192.png';
        var badge   = '__PLUGIN_URL__assets/img/badge-72.png';
        return self.registration.showNotification(title, {
            body: body, icon: icon, badge: badge,
            tag: 'rdr-cyber-alert', renotify: true,
            data: { url: url },
        });
    }).catch(function() {
        return self.registration.showNotification('Cyber Dashboard', {
            body: 'Nouvelles alertes disponibles.',
            icon: '__PLUGIN_URL__assets/img/icon-192.png',
            badge: '__PLUGIN_URL__assets/img/badge-72.png',
            tag: 'rdr-cyber-alert',
            data: { url: SITE_URL },
        });
    });
}

/* ---------------------------------------------------------
   Clic sur la notification
   --------------------------------------------------------- */
self.addEventListener('notificationclick', function(e) {
    e.notification.close();
    var url = (e.notification.data && e.notification.data.url) ? e.notification.data.url : SITE_URL;
    e.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(wcs) {
            for (var i = 0; i < wcs.length; i++) {
                if (wcs[i].url === url && 'focus' in wcs[i]) return wcs[i].focus();
            }
            if (clients.openWindow) return clients.openWindow(url);
        })
    );
});
