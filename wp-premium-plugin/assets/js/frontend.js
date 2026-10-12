/**
 * WP Premium Access — Frontend JS (Vanilla JS, no jQuery)
 */
(function () {
    'use strict';

    const LS_KEY = 'wpa_session';
    const cfg    = window.WPA || {};
    const api    = cfg.apiUrl || '';
    const i18n   = cfg.i18n  || {};
    const nonce  = cfg.nonce || '';

    // =========================================================================
    // Fetch helper — replaces $.ajax
    // =========================================================================

    function apiFetch(endpoint, options = {}) {
        const url     = api + endpoint;
        const headers = { 'X-WP-Nonce': nonce, ...( options.headers || {}) };
        const method  = (options.method || 'GET').toUpperCase();

        const init = { method, headers, credentials: 'same-origin' };

        if (options.body) {
            headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(options.body);
        }

        return fetch(url, init).then(r => r.json());
    }

    function postForm(url, data) {
        const body = new URLSearchParams(data).toString();
        return fetch(url, {
            method:      'POST',
            credentials: 'same-origin',
            headers:     { 'Content-Type': 'application/x-www-form-urlencoded', 'X-WP-Nonce': nonce },
            body,
        }).then(r => r.json());
    }

    // =========================================================================
    // Session helpers
    // =========================================================================

    const Session = {
        get() {
            try { return JSON.parse(localStorage.getItem(LS_KEY) || 'null'); }
            catch { return null; }
        },
        set(data) {
            try { localStorage.setItem(LS_KEY, JSON.stringify(data)); }
            catch {}
        },
        clear() { localStorage.removeItem(LS_KEY); },
        isPremium() {
            const s = this.get();
            if (!s) return false;
            if (s.session_expires && new Date(s.session_expires) < new Date()) return false;
            return s.is_premium === true;
        },
        can(permission) {
            const s = this.get();
            const perms = s?.permissions;
            if (!Array.isArray(perms)) return false;
            return perms.includes(permission);
        },
        isExpiringSoon() {
            const s = this.get();
            if (!s?.premium_expires) return false;
            const diff = (new Date(s.premium_expires) - new Date()) / 86400000;
            return diff > 0 && diff <= 7;
        },
        isExpired() {
            const s = this.get();
            if (!s?.premium_expires) return false;
            return new Date(s.premium_expires) < new Date();
        },
    };

    // =========================================================================
    // DOM helper
    // =========================================================================

    const $ = sel => document.querySelector(sel);
    const $$ = sel => document.querySelectorAll(sel);
    const on = (el, ev, fn) => el && el.addEventListener(ev, fn);
    const delegate = (parent, sel, ev, fn) => {
        on(parent || document, ev, e => {
            const t = e.target.closest(sel);
            if (t) fn.call(t, e);
        });
    };

    // =========================================================================
    // Handle login payload after magic link redirect
    // =========================================================================

    function handleLoginPayload() {
        const params = new URLSearchParams(location.search);
        if (!params.get('wpa_login')) return;

        apiFetch('/verify-session').then(data => {
            if (data.authenticated) { Session.set(data); updateUI(); }
        });

        history.replaceState(null, '', location.pathname + location.hash);
    }

    // =========================================================================
    // Verify session on every page load
    // =========================================================================

    function verifySession() {
        const local = Session.get();
        if (local) updateUI();

        apiFetch('/verify-session').then(data => {
            if (data.authenticated) {
                Session.set(data);
            } else {
                Session.clear();
            }
            updateUI();
        }).catch(() => {});
    }

    // =========================================================================
    // Update UI
    // =========================================================================

    function updateUI() {
        const session = Session.get();
        const premium = session?.is_premium || false;

        document.body.classList.toggle('wpa-is-premium', premium);
        document.body.classList.toggle('wpa-is-guest',   !premium);

        $$('[data-wpa-show-if-premium]').forEach(el => el.style.display = premium ? '' : 'none');
        $$('[data-wpa-hide-if-premium]').forEach(el => el.style.display = premium ? 'none' : '');

        const toggle = $('#wpa-sync-toggle');
        if (toggle) toggle.checked = !!session?.sync_enabled;

        showExpiryNotice();
    }

    // =========================================================================
    // Expiry notice
    // =========================================================================

    function showExpiryNotice() {
        if ($('#wpa-expiry-notice')) return;

        const kofiUrl = cfg.kofiUrl || '#';
        let notice;

        if (Session.isExpired()) {
            notice = document.createElement('div');
            notice.id        = 'wpa-expiry-notice';
            notice.className = 'wpa-expiry-notice wpa-expiry-notice--expired';
            notice.setAttribute('role', 'alert');
            notice.innerHTML = `<span>${i18n.premium_expired || 'Your premium access has expired.'}</span>
                <a href="${kofiUrl}" class="wpa-btn wpa-btn--primary wpa-btn--sm" target="_blank" rel="noopener">${i18n.renew || 'Renew support'}</a>
                <button class="wpa-expiry-notice__close" aria-label="Close">&times;</button>`;
        } else if (Session.isExpiringSoon()) {
            const s       = Session.get();
            const expires = s?.premium_expires ? new Date(s.premium_expires).toLocaleDateString() : '';
            notice = document.createElement('div');
            notice.id        = 'wpa-expiry-notice';
            notice.className = 'wpa-expiry-notice wpa-expiry-notice--warning';
            notice.setAttribute('role', 'alert');
            notice.innerHTML = `<span>${(i18n.premium_expiring_soon || 'Your premium access expires on {date}.').replace('{date}', expires)}</span>
                <a href="${kofiUrl}" class="wpa-btn wpa-btn--secondary wpa-btn--sm" target="_blank" rel="noopener">${i18n.renew || 'Renew support'}</a>
                <button class="wpa-expiry-notice__close" aria-label="Close">&times;</button>`;
        }

        if (notice) {
            document.body.prepend(notice);
            notice.querySelector('.wpa-expiry-notice__close')?.addEventListener('click', () => {
                notice.style.transition = 'opacity .2s';
                notice.style.opacity    = '0';
                setTimeout(() => notice.remove(), 220);
            });
        }
    }

    // =========================================================================
    // localStorage key helpers — Polylang aware
    // =========================================================================

    const SyncKeys = {
        lang()      { return cfg.sync?.lang || 'fr'; },
        favorites() { return `rdr bm favorites ${this.lang()}`; },
        readLater() { return `rdr bm read later ${this.lang()}`; },
    };

    function readRdrLocalStorage(lsKey) {
        let raw;
        try { raw = JSON.parse(localStorage.getItem(lsKey) || '[]'); }
        catch { raw = []; }
        if (!Array.isArray(raw)) return [];
        return raw.filter(i => i?.id).map(i => ({
            article_id: String(i.id),
            saved_at:   i.saved_at || new Date().toISOString(),
            title:      i.title || '',
        }));
    }

    function writeRdrLocalStorage(lsKey, serverItems) {
        let local;
        try { local = JSON.parse(localStorage.getItem(lsKey) || '[]'); }
        catch { local = []; }
        if (!Array.isArray(local)) local = [];

        const localIds = new Set(local.map(i => String(i.id)));
        serverItems.forEach(item => {
            if (!localIds.has(item.article_id)) {
                local.push({ id: item.article_id, title: item.title || '', saved_at: item.saved_at });
            }
        });
        try { localStorage.setItem(lsKey, JSON.stringify(local)); } catch {}
    }

    // =========================================================================
    // Sync localStorage → server
    // =========================================================================

    function syncLocalStorageToServer(lsKey, endpoint) {
        const items = readRdrLocalStorage(lsKey);
        if (!items.length) return;
        apiFetch('/' + endpoint, { method: 'POST', body: { items } });
    }

    function mergeServerDataToLocalStorage() {
        if (!Session.can('sync') || !Session.get()?.sync_enabled) return;

        [{ endpoint: 'favorites', lsKey: SyncKeys.favorites() },
         { endpoint: 'read-later', lsKey: SyncKeys.readLater() }].forEach(({ endpoint, lsKey }) => {
            apiFetch('/' + endpoint).then(data => {
                if (data.items?.length) writeRdrLocalStorage(lsKey, data.items);
            }).catch(() => {});
        });
    }

    // =========================================================================
    // Magic link request form
    // =========================================================================

    function initLoginForm() {
        delegate(document, '#wpa-magic-link-form', 'submit', function (e) {
            e.preventDefault();
            const form     = this;
            const btn      = form.querySelector('[type=submit]');
            const feedback = form.querySelector('.wpa-form-feedback');
            const email    = form.querySelector('#wpa-email')?.value.trim();
            if (!email) return;

            btn.disabled = true;
            btn.textContent = i18n.sending || 'Sending...';
            feedback.className = 'wpa-form-feedback';
            feedback.textContent = '';

            apiFetch('/request-link', { method: 'POST', body: { email } }).then(data => {
                feedback.classList.add('is-success');
                feedback.textContent = data.message || i18n.sent || 'Check your inbox!';
                form.querySelector('#wpa-email').value = '';
            }).catch(() => {
                feedback.classList.add('is-error');
                feedback.textContent = i18n.error || 'Something went wrong.';
            }).finally(() => {
                btn.disabled    = false;
                btn.textContent = btn.dataset.origText || 'Receive my link';
            });
        });

        $$('[type=submit]').forEach(btn => btn.dataset.origText = btn.textContent);
    }

    // =========================================================================
    // Logout
    // =========================================================================

    function initLogout() {
        delegate(document, '#wpa-logout, #wpa-logout-widget', 'click', function () {
            if (!confirm(i18n.logout_confirm || 'Sign out?')) return;
            apiFetch('/logout', { method: 'POST' }).finally(() => {
                Session.clear();
                location.reload();
            });
        });
    }

    // =========================================================================
    // Sync toggle
    // =========================================================================

    function initSyncToggle() {
        delegate(document, '#wpa-sync-toggle', 'change', function () {
            const enabled = this.checked;
            if (!enabled && !confirm(i18n.sync_confirm)) {
                this.checked = true;
                return;
            }

            apiFetch('/sync-settings', { method: 'POST', body: { enabled } }).then(data => {
                const session = Session.get() || {};
                session.sync_enabled = data.sync_enabled;
                Session.set(session);
                if (data.sync_enabled) {
                    syncLocalStorageToServer(SyncKeys.favorites(),  'favorites');
                    syncLocalStorageToServer(SyncKeys.readLater(), 'read-later');
                    showToast(i18n.sync_on);
                } else {
                    showToast(i18n.sync_off);
                }
            });
        });
    }

    // =========================================================================
    // Stripe Checkout button
    // =========================================================================

    function initStripeButtons() {
        delegate(document, '.wpa-stripe-btn', 'click', function () {
            const btn         = this;
            const priceId     = btn.dataset.priceId;
            const checkoutUrl = btn.dataset.checkoutUrl;
            const btnNonce    = btn.dataset.nonce;
            const spinner     = btn.querySelector('.wpa-stripe-btn__spinner');

            btn.disabled = true;
            if (spinner) spinner.style.display = '';

            fetch(checkoutUrl, {
                method:      'POST',
                credentials: 'same-origin',
                headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': btnNonce },
                body:        JSON.stringify({ price_id: priceId }),
            }).then(r => r.json()).then(data => {
                if (data.url) {
                    location.href = data.url;
                } else {
                    alert(i18n.error || 'An error occurred.');
                    btn.disabled = false;
                    if (spinner) spinner.style.display = 'none';
                }
            }).catch(() => {
                alert(i18n.error || 'An error occurred.');
                btn.disabled = false;
                if (spinner) spinner.style.display = 'none';
            });
        });

        // Payment success/cancel notice
        const params = new URLSearchParams(location.search);
        const status = params.get('wpa_payment');
        if (status) {
            const msg = status === 'success'
                ? (i18n.payment_success || 'Payment successful! Check your inbox for your access link.')
                : (i18n.payment_cancel  || 'Payment cancelled.');
            const cls = status === 'success' ? 'is-success' : 'is-error';
            const n   = document.createElement('div');
            n.className     = 'wpa-payment-notice wpa-form-feedback ' + cls;
            n.textContent   = msg;
            n.style.cssText = 'position:fixed;top:16px;left:50%;transform:translateX(-50%);z-index:9999;padding:.6em 1.4em;border-radius:6px;font-size:.9rem;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.15)';
            document.body.appendChild(n);
            history.replaceState(null, '', location.pathname);
            setTimeout(() => { n.style.opacity = '0'; n.style.transition = 'opacity .4s'; setTimeout(() => n.remove(), 400); }, 6000);
        }
    }

    // =========================================================================
    // Toast
    // =========================================================================

    function showToast(message) {
        const t = document.createElement('div');
        t.className   = 'wpa-toast';
        t.textContent = message;
        t.setAttribute('role', 'status');
        document.body.appendChild(t);
        requestAnimationFrame(() => t.classList.add('wpa-toast--visible'));
        setTimeout(() => {
            t.classList.remove('wpa-toast--visible');
            setTimeout(() => t.remove(), 400);
        }, 3000);
    }

    // =========================================================================
    // Init
    // =========================================================================

    document.addEventListener('DOMContentLoaded', () => {
        handleLoginPayload();
        verifySession();
        initLoginForm();
        initLogout();
        initSyncToggle();
        initStripeButtons();
        mergeServerDataToLocalStorage();
    });

}());
