/**
 * RDR-IT Quiz — Modal Engine v1.0
 * Vanilla JS pur, zéro jQuery.
 * Chargé uniquement si un bouton .rdr-quiz-modal-btn est présent dans la page.
 */
(function () {
    'use strict';

    /* ── Config injectée par wp_localize_script ── */
    const cfg   = window.rdrQuizModal || {};
    const AJAX  = cfg.ajaxUrl || '';
    const NONCE = cfg.nonce   || '';

    /* ── Créer le conteneur modal (une seule fois) ── */
    let overlay = null;
    let modalEl = null;
    let currentQuizId = null;

    function buildModal() {
        if (overlay) return;

        overlay = document.createElement('div');
        overlay.id        = 'rdr-modal-overlay';
        overlay.className = 'rdr-modal-overlay';
        overlay.setAttribute('aria-hidden', 'true');
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');

        overlay.innerHTML = `
            <div class="rdr-modal-box" id="rdr-modal-box">
                <button class="rdr-modal-close" id="rdr-modal-close"
                        aria-label="Fermer le quiz" type="button">✕</button>
                <div class="rdr-modal-body" id="rdr-modal-body">
                    <div class="rdr-modal-loading">
                        <div class="rdr-spinner"></div>
                    </div>
                </div>
            </div>`;

        document.body.appendChild(overlay);
        modalEl = overlay.querySelector('#rdr-modal-box');

        /* Fermer sur clic overlay */
        overlay.addEventListener('click', e => {
            if (e.target === overlay) closeModal();
        });

        /* Fermer sur bouton × */
        overlay.querySelector('#rdr-modal-close').addEventListener('click', closeModal);

        /* Fermer sur Échap */
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && overlay.getAttribute('aria-hidden') === 'false') {
                closeModal();
            }
        });
    }

    function openModal(quizId) {
        buildModal();
        currentQuizId = quizId;
        overlay.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        const body = overlay.querySelector('#rdr-modal-body');
        body.innerHTML = '<div class="rdr-modal-loading"><div class="rdr-spinner"></div></div>';

        loadQuizInModal(quizId, body);
    }

    function closeModal() {
        if (!overlay) return;
        overlay.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        currentQuizId = null;
    }

    /* ── Charger le HTML du quiz via AJAX ── */
    function loadQuizInModal(quizId, container) {
        const fd = new FormData();
        fd.append('action',  'rdr_quiz_html');
        fd.append('nonce',   NONCE);
        fd.append('quiz_id', quizId);

        fetch(AJAX, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    container.innerHTML = `<p class="rdr-modal-error">Impossible de charger le quiz.</p>`;
                    return;
                }
                container.innerHTML = res.data.html;

                /* Initialiser le moteur quiz dans ce contexte */
                if (typeof window.rdrInitQuiz === 'function') {
                    window.rdrInitQuiz(quizId);
                }
            })
            .catch(() => {
                container.innerHTML = `<p class="rdr-modal-error">Erreur réseau.</p>`;
            });
    }

    /* ── Attacher les boutons modal ── */
    function attachButtons() {
        document.querySelectorAll('.rdr-quiz-modal-btn').forEach(btn => {
            if (btn.dataset.modalBound) return;
            btn.dataset.modalBound = '1';
            btn.addEventListener('click', e => {
                e.preventDefault();
                const quizId = btn.dataset.quizId;
                if (quizId) openModal(quizId);
            });
        });
    }

    /* ── Exposer pour usage externe (ex: après chargement AJAX) ── */
    window.rdrModal = { open: openModal, close: closeModal, attachButtons };

    /* Init */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachButtons);
    } else {
        attachButtons();
    }

})();
