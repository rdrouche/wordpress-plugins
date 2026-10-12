/* RDR Cyber Dashboard v1.3.0 — Web Push frontend */
'use strict';

(function () {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    if (typeof rdrCyber === 'undefined' || !rdrCyber.push_on || rdrCyber.push_on !== '1') return;
    if (!rdrCyber.vapid_key) return;

    var SW_URL    = rdrCyber.sw_url;
    var VAPID_KEY = rdrCyber.vapid_key;
    var swReg     = null;

    function urlB64ToUint8Array(base64String) {
        var padding   = '='.repeat((4 - base64String.length % 4) % 4);
        var base64    = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData   = atob(base64);
        var outputArr = new Uint8Array(rawData.length);
        for (var i = 0; i < rawData.length; i++) outputArr[i] = rawData.charCodeAt(i);
        return outputArr;
    }

    function ajaxPost(url, data) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams(data).toString(),
        }).then(function(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); });
    }

    function updateBtn(btn, subscribed) {
        if (!btn) return;
        btn.dataset.subscribed = subscribed ? '1' : '0';
        btn.textContent = subscribed ? '🔔 Désactiver les alertes push' : '🔔 Activer les alertes push';
        btn.disabled = false;
        btn.style.opacity = '1';
    }

    function subscribe(btn) {
        btn.disabled = true; btn.style.opacity = '0.6';
        Notification.requestPermission().then(function(perm) {
            if (perm !== 'granted') { updateBtn(btn, false); return; }
            swReg.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlB64ToUint8Array(VAPID_KEY),
            }).then(function(sub) {
                var j    = sub.toJSON();
                var keys = j.keys || {};
                return ajaxPost(rdrCyber.ajaxurl, {
                    action:   'rdr_cyber_push_subscribe',
                    nonce:    rdrCyber.nonce,
                    endpoint: j.endpoint,
                    p256dh:   keys.p256dh || '',
                    auth:     keys.auth   || '',
                });
            }).then(function() {
                updateBtn(btn, true);
            }).catch(function(err) {
                console.error('[RDR Push] subscribe error', err);
                updateBtn(btn, false);
            });
        });
    }

    function unsubscribe(btn) {
        btn.disabled = true; btn.style.opacity = '0.6';
        swReg.pushManager.getSubscription().then(function(sub) {
            if (!sub) { updateBtn(btn, false); return; }
            var endpoint = sub.endpoint;
            return sub.unsubscribe().then(function() {
                return ajaxPost(rdrCyber.ajaxurl, {
                    action:   'rdr_cyber_push_unsubscribe',
                    nonce:    rdrCyber.nonce,
                    endpoint: endpoint,
                });
            }).then(function() {
                updateBtn(btn, false);
            });
        }).catch(function(err) {
            console.error('[RDR Push] unsubscribe error', err);
            updateBtn(btn, false);
        });
    }

    /* Init SW + bouton */
    navigator.serviceWorker.register(SW_URL, { scope: '/' }).then(function(reg) {
        swReg = reg;
        return reg.pushManager.getSubscription();
    }).then(function(sub) {
        var btn = document.getElementById('rdr-push-btn');
        if (!btn) return;
        updateBtn(btn, !!sub);
        btn.addEventListener('click', function() {
            if (btn.dataset.subscribed === '1') unsubscribe(btn);
            else subscribe(btn);
        });
    }).catch(function(err) {
        console.error('[RDR Push] SW registration error', err);
    });

})();
