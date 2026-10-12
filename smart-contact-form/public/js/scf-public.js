/**
 * Smart Contact Form – vanilla JS (zero jQuery dependency)
 *
 * Handles:
 *  - Async form loading via fetch()
 *  - reCAPTCHA v3 token injection
 *  - AJAX submission with inline field validation
 *  - Accessible live-region status announcements
 */
(function () {
  'use strict';

  /* ── helpers ─────────────────────────────────────────────── */

  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(data),
    }).then(function (r) { return r.json(); });
  }

  function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
  function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

  /* ── boot: load all wrappers ─────────────────────────────── */

  function init() {
    qsa('.scf-wrapper').forEach(loadForm);
  }

  /* ── load form HTML via AJAX ─────────────────────────────── */

  function loadForm(wrapper) {
    var formId = wrapper.dataset.formId;
    var container = qs('.scf-form-container', wrapper);

    post(SCF.ajaxUrl, { action: 'scf_load_form', form_id: formId })
      .then(function (res) {
        if (res.success) {
          container.classList.remove('scf-loading');
          container.innerHTML = res.data.html;
          bindForm(qs('.scf-form', container));
        }
      })
      .catch(function () {
        container.innerHTML = '<p class="scf-load-error">' + SCF.i18n.error + '</p>';
      });
  }

  /* ── bind events to a rendered form ─────────────────────── */

  function bindForm(form) {
    if (!form) return;

    // Store original button label
    var btn = qs('.scf-submit', form);
    if (btn) btn.dataset.originalText = qs('.scf-submit__text', btn).textContent;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      submitForm(form);
    });

    // Clear field error on input
    form.addEventListener('input', function (e) {
      var field = e.target.closest('.scf-field');
      if (field) {
        field.classList.remove('scf-field--error');
        var err = qs('.scf-error', field);
        if (err) err.textContent = '';
      }
    });
  }

  /* ── submit ──────────────────────────────────────────────── */

  function submitForm(form) {
    clearErrors(form);
    setLoading(form, true);

    var resp = qs('.scf-response', form);
    resp.className = 'scf-response';
    resp.textContent = '';

    var siteKey = SCF.recaptchaSite;

    if (siteKey && typeof grecaptcha !== 'undefined') {
      grecaptcha.ready(function () {
        grecaptcha.execute(siteKey, { action: 'contact_form' }).then(function (token) {
          var inp = qs('[name="scf_recaptcha_token"]', form);
          if (inp) inp.value = token;
          doSubmit(form);
        });
      });
    } else {
      doSubmit(form);
    }
  }

  function doSubmit(form) {
    var data = Object.fromEntries(new FormData(form));
    data.action = 'scf_submit';

    // FormData doesn't include unchecked checkboxes — fine for our use case.

    post(SCF.ajaxUrl, data)
      .then(function (res) {
        setLoading(form, false);

        if (res.success) {
          var resp = qs('.scf-response', form);
          resp.classList.add('scf-response--success');
          resp.textContent = res.data.message;
          resp.focus();
          // Clear all visible inputs
          qsa('input:not([type=hidden]),textarea,select', form).forEach(function (el) {
            el.value = '';
          });
          form.dispatchEvent(new CustomEvent('scf:success', { detail: res.data, bubbles: true }));
        } else {
          if (res.data && res.data.errors) {
            showErrors(form, res.data.errors);
          } else {
            var msg = (res.data && res.data.message) ? res.data.message : SCF.i18n.error;
            var resp = qs('.scf-response', form);
            resp.classList.add('scf-response--error');
            resp.textContent = msg;
          }
          form.dispatchEvent(new CustomEvent('scf:error', { detail: res.data, bubbles: true }));
        }
      })
      .catch(function () {
        setLoading(form, false);
        var resp = qs('.scf-response', form);
        resp.classList.add('scf-response--error');
        resp.textContent = SCF.i18n.error;
      });
  }

  /* ── UI helpers ──────────────────────────────────────────── */

  function setLoading(form, on) {
    var btn  = qs('.scf-submit', form);
    var text = qs('.scf-submit__text', btn);
    btn.disabled = on;
    btn.classList.toggle('scf-submit--loading', on);
    text.textContent = on ? SCF.i18n.sending : (btn.dataset.originalText || text.textContent);
  }

  function clearErrors(form) {
    qsa('.scf-field--error', form).forEach(function (f) { f.classList.remove('scf-field--error'); });
    qsa('.scf-error', form).forEach(function (e) { e.textContent = ''; });
  }

  function showErrors(form, errors) {
    Object.keys(errors).forEach(function (name) {
      var input = qs('[name="' + name + '"]', form);
      if (!input) return;
      var field = input.closest('.scf-field');
      if (field) {
        field.classList.add('scf-field--error');
        var err = qs('.scf-error', field);
        if (err) err.textContent = errors[name];
      }
    });
    var first = qs('.scf-field--error input, .scf-field--error textarea, .scf-field--error select', form);
    if (first) first.focus();
  }

  /* ── boot ────────────────────────────────────────────────── */

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

}());
