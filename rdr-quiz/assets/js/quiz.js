/**
 * RDR-IT Quiz Engine — v1.5
 * Vanilla JS pur, zéro jQuery, zéro dépendance CDN externe
 * SortableJS bundlé localement (assets/js/sortable.min.js)
 */
(function () {
    'use strict';

    const cfg   = window.rdrQuiz || {};
    const i18n  = cfg.i18n || {};
    const AJAX  = cfg.ajaxUrl || '';
    const NONCE = cfg.nonce  || '';

    /* ── State ── */
    const states = {};
    function state(id) {
        if (!states[id]) states[id] = {
            questions: [], currentIndex: 0, answers: [],
            score: 0, total: 0, loading: false, validated: false,
        };
        return states[id];
    }

    /* ── Utils ── */
    const qs = (sel, ctx) => (ctx || document).querySelector(sel);
    function mk(tag, cls, html) {
        const e = document.createElement(tag);
        if (cls)  e.className   = cls;
        if (html !== undefined) e.innerHTML = html;
        return e;
    }
    function escHtml(str) {
        return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;')
                                .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function t(key) { return i18n[key] || key; }

    /* ── AJAX avec refresh nonce auto (LiteSpeed safe) ── */
    let currentNonce = NONCE;

    function refreshNonce() {
        const fd = new FormData();
        fd.append('action', 'rdr_refresh_nonce');
        return fetch(AJAX, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { if (d.success && d.data.nonce) currentNonce = d.data.nonce; });
    }

    function ajax(action, data, _retry) {
        const fd = new FormData();
        fd.append('action', action);
        fd.append('nonce',  currentNonce);
        for (const [k, v] of Object.entries(data)) {
            if (Array.isArray(v)) v.forEach(item => fd.append(k + '[]', item));
            else if (v !== null && v !== undefined) fd.append(k, v);
        }
        return fetch(AJAX, { method: 'POST', body: fd })
            .then(r => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(json => {
                if ((json === -1 || json === 0 || json === '-1' || json === '0') && !_retry)
                    return refreshNonce().then(() => ajax(action, data, true));
                return json;
            });
    }

    const showEl    = sel => { const e = qs(sel); if (e) e.hidden = false; };
    const hideEl    = sel => { const e = qs(sel); if (e) e.hidden = true; };
    const setLoader = (id, v) => { const e = qs(`#rdr-quiz-loader-${id}`); if (e) e.hidden = !v; };

    /* ════════════════════════════════════════════════════════
       INIT
    ════════════════════════════════════════════════════════ */
    // Délégation globale sur document — fonctionne pour les boutons
    // présents au chargement ET injectés dynamiquement (modale, AJAX)
    document.addEventListener('click', function (e) {
        const btn = e.target.closest('.rdr-btn-start');
        if (btn) {
            e.preventDefault();
            startQuiz(btn.dataset.quizId);
        }
    });

    // Exposer rdrInitQuiz pour quiz-modal.js (appelé après injection HTML)
    // Pas de travail supplémentaire nécessaire grâce à la délégation
    window.rdrInitQuiz = function () {};

    document.addEventListener('DOMContentLoaded', () => {
        if (qs('#rdr-quiz-archive')) initArchive();
    });

    /* ════════════════════════════════════════════════════════
       DÉMARRER
    ════════════════════════════════════════════════════════ */
    async function startQuiz(quizId) {
        const s = state(quizId);
        if (s.loading) return;
        s.loading = true;
        setLoader(quizId, true);
        try {
            const res = await ajax('rdr_load_quiz', { quiz_id: quizId });
            if (!res.success) { alert(res.data?.message || t('error_load')); throw new Error('load_failed'); }
            Object.assign(s, {
                questions: res.data.questions, total: res.data.total,
                currentIndex: 0, score: 0, answers: [], validated: false,
            });
            hideEl(`#rdr-quiz-intro-${quizId}`);
            hideEl(`#rdr-quiz-results-${quizId}`);
            showEl(`#rdr-quiz-player-${quizId}`);
            renderQuestion(quizId);
        } catch (e) {
            console.error('[RDR Quiz] load:', e);
            alert(t('error_load'));
        } finally {
            s.loading = false;
            setLoader(quizId, false);
        }
    }

    /* ════════════════════════════════════════════════════════
       AFFICHER UNE QUESTION
    ════════════════════════════════════════════════════════ */
    function renderQuestion(quizId) {
        const s = state(quizId);
        const q = s.questions[s.currentIndex];
        if (!q) return;

        s.validated = false;
        updateProgress(quizId);

        const area = qs(`#rdr-question-area-${quizId}`);
        area.innerHTML = '';
        const card = mk('div', 'rdr-question-card');

        // Titre
        card.appendChild(mk('h3', 'rdr-question-title', escHtml(q.title)));

        // Corps enrichi (code, image, contexte)
        if (q.body && q.body.trim()) {
            const bodyDiv = mk('div', 'rdr-question-body');
            bodyDiv.innerHTML = q.body;
            card.appendChild(bodyDiv);
        } else if (q.content && q.content.trim()) {
            card.appendChild(mk('div', 'rdr-question-content', q.content));
        }

        // Zone de réponse
        const ansArea = mk('div', 'rdr-answer-area');
        ansArea.dataset.type       = q.type;
        ansArea.dataset.questionId = q.id;

        // Dispatcher — toutes les fonctions sont déclarées PLUS BAS (function declarations = hoisted)
        const renderers = {
            yesno:    renderYesNo,
            radio:    renderRadio,
            checkbox: renderCheckbox,
            order:    renderOrder,
            free:     renderFree,
            fill:     renderFill,
        };
        (renderers[q.type] || function(){})(ansArea, q);

        card.appendChild(ansArea);

        const btnValidate = mk('button', 'rdr-btn rdr-btn-validate', t('validate'));
        btnValidate.addEventListener('click', () => validateAnswer(quizId, q, ansArea, btnValidate));
        card.appendChild(btnValidate);

        area.appendChild(card);
    }

    /* ════════════════════════════════════════════════════════
       FONCTIONS DE RENDU (déclarées comme function → hoistées)
    ════════════════════════════════════════════════════════ */

    function renderYesNo(area, q) {
        const wrap = mk('div', 'rdr-choices');
        [['yes', 'Oui'], ['no', 'Non']].forEach(([val, lbl]) => {
            const label = mk('label', 'rdr-choice');
            label.innerHTML = `<input type="radio" name="q_${q.id}" value="${val}"> ${lbl}`;
            wrap.appendChild(label);
        });
        area.appendChild(wrap);
    }

    function renderRadio(area, q) {
        const wrap = mk('div', 'rdr-choices');
        (q.choices || []).forEach(c => {
            const label = mk('label', 'rdr-choice');
            label.innerHTML = `<input type="radio" name="q_${q.id}" value="${escHtml(String(c.id))}"> ${escHtml(c.text)}`;
            wrap.appendChild(label);
        });
        area.appendChild(wrap);
    }

    function renderCheckbox(area, q) {
        area.appendChild(mk('p', 'rdr-choice-hint', '(Plusieurs réponses possibles)'));
        const wrap = mk('div', 'rdr-choices');
        (q.choices || []).forEach(c => {
            const label = mk('label', 'rdr-choice');
            label.innerHTML = `<input type="checkbox" name="q_${q.id}" value="${escHtml(String(c.id))}"> ${escHtml(c.text)}`;
            wrap.appendChild(label);
        });
        area.appendChild(wrap);
    }

    function renderOrder(area, q) {
        area.appendChild(mk('p', 'rdr-choice-hint', t('drag_order')));
        const ul = mk('ul', 'rdr-sortable-list');
        (q.items || []).forEach(item => {
            const li = mk('li', 'rdr-sortable-item');
            li.innerHTML = `<span class="rdr-drag-handle" aria-hidden="true">⠿</span> ${escHtml(item)}`;
            li.dataset.value = item;
            ul.appendChild(li);
        });
        area.appendChild(ul);
        // SortableJS chargé localement — pas de CDN
        if (typeof Sortable !== 'undefined') {
            new Sortable(ul, { handle: '.rdr-drag-handle', animation: 150, ghostClass: 'rdr-sortable-ghost' });
        }
    }

    function renderFree(area) {
        const wrap = mk('div', 'rdr-free-input');
        wrap.innerHTML = '<span class="rdr-cli-prompt">$</span>';
        const input = document.createElement('input');
        Object.assign(input, {
            type: 'text', className: 'rdr-cli-input',
            placeholder: t('type_answer'), autocomplete: 'off', spellcheck: false,
        });
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter')
                area.closest('.rdr-question-card')?.querySelector('.rdr-btn-validate')?.click();
        });
        wrap.appendChild(input);
        area.appendChild(wrap);
    }

    function renderFill(area, q) {
        const tmpl   = q.template || '';
        const parts  = tmpl.split('___');
        const nbTrous = parts.length - 1;

        area.appendChild(mk('p', 'rdr-choice-hint', '✏️ Complétez la phrase en remplissant chaque champ'));

        const wrap = mk('div', 'rdr-fill-wrap');
        parts.forEach((part, i) => {
            if (part) {
                const span = document.createElement('span');
                span.className   = 'rdr-fill-text';
                span.textContent = part;
                wrap.appendChild(span);
            }
            if (i < nbTrous) {
                const inp = document.createElement('input');
                inp.type         = 'text';
                inp.className    = 'rdr-fill-input';
                inp.dataset.index = String(i);
                inp.placeholder  = `(${i + 1})`;
                inp.autocomplete = 'off';
                inp.spellcheck   = false;
                inp.addEventListener('keydown', e => {
                    if (e.key === 'Enter') {
                        if (i === nbTrous - 1) {
                            area.closest('.rdr-question-card')?.querySelector('.rdr-btn-validate')?.click();
                        } else {
                            area.querySelector(`.rdr-fill-input[data-index="${i + 1}"]`)?.focus();
                        }
                    }
                });
                wrap.appendChild(inp);
            }
        });
        area.appendChild(wrap);
    }

    /* ════════════════════════════════════════════════════════
       RÉCUPÉRER LA RÉPONSE
    ════════════════════════════════════════════════════════ */
    function getAnswer(area, type) {
        switch (type) {
            case 'yesno':
            case 'radio': {
                const el = area.querySelector('input[type="radio"]:checked');
                return el ? el.value : null;
            }
            case 'checkbox': {
                const els = [...area.querySelectorAll('input[type="checkbox"]:checked')];
                return els.length ? els.map(e => parseInt(e.value, 10)) : null;
            }
            case 'order':
                return [...area.querySelectorAll('.rdr-sortable-item')].map(li => li.dataset.value);
            case 'free': {
                const v = area.querySelector('.rdr-cli-input')?.value.trim();
                return v || null;
            }
            case 'fill': {
                const inputs = area.querySelectorAll('.rdr-fill-input');
                if (!inputs.length) return null;
                const vals = {};
                inputs.forEach(inp => { vals[inp.dataset.index] = inp.value.trim(); });
                // Valider qu'au moins un trou est rempli
                const hasValue = Object.values(vals).some(v => v.length > 0);
                return hasValue ? vals : null;
            }
        }
        return null;
    }

    /* ════════════════════════════════════════════════════════
       VALIDER UNE RÉPONSE — UN SEUL APPEL AJAX (verrou)
    ════════════════════════════════════════════════════════ */
    async function validateAnswer(quizId, q, ansArea, btn) {
        const s = state(quizId);
        if (s.validated) return;

        const userAnswer = getAnswer(ansArea, q.type);
        if (userAnswer === null || (Array.isArray(userAnswer) && !userAnswer.length)) {
            ansArea.classList.add('rdr-error-empty');
            setTimeout(() => ansArea.classList.remove('rdr-error-empty'), 700);
            return;
        }

        s.validated  = true;
        btn.disabled = true;
        btn.textContent = '…';

        try {
            const payload = { question_id: q.id };
            if (Array.isArray(userAnswer)) {
                payload['answer'] = userAnswer;
            } else if (typeof userAnswer === 'object' && userAnswer !== null) {
                // fill : objet indexé {0: 'val', 1: 'val'}
                Object.entries(userAnswer).forEach(([k, v]) => {
                    payload[`answer[${k}]`] = v;
                });
            } else {
                payload.answer = userAnswer;
            }

            const res = await ajax('rdr_check_answer', payload);
            if (!res.success) throw new Error('server error');

            const data = res.data;
            if (data.correct) s.score++;

            s.answers.push({
                questionId: q.id, questionTitle: q.title,
                correct: data.correct, userAnswer,
                correctData: data.correct_data,
                explanation: data.explanation,
                docLink: data.doc_link,
                type: q.type,
                choices: q.choices || [],
                items:   q.items   || [],
            });

            ansArea.querySelectorAll('input, .rdr-cli-input, .rdr-fill-input')
                .forEach(el => el.disabled = true);
            ansArea.classList.add(data.correct ? 'rdr-answer-correct' : 'rdr-answer-wrong');

            const fb = mk('div', `rdr-feedback ${data.correct ? 'rdr-feedback-correct' : 'rdr-feedback-wrong'}`);
            let fbHtml = `<div class="rdr-feedback-header">${data.correct ? t('correct') : t('wrong')}</div>`;
            if (data.explanation) fbHtml += `<div class="rdr-feedback-explanation"><strong>${t('explanation')} :</strong> ${data.explanation}</div>`;
            if (data.doc_link)    fbHtml += `<a href="${escHtml(data.doc_link)}" target="_blank" rel="noopener" class="rdr-feedback-link">${t('learn_more')}</a>`;
            fb.innerHTML = fbHtml;
            btn.closest('.rdr-question-card').appendChild(fb);

            const isLast    = s.currentIndex >= s.total - 1;
            btn.disabled    = false;
            btn.textContent = isLast ? t('results') : t('next');
            btn.className   = 'rdr-btn rdr-btn-next';
            btn.onclick     = () => nextQuestion(quizId);
            // Pas de scroll auto — l'utilisateur reste en place

        } catch (e) {
            console.error('[RDR Quiz] check_answer:', e);
            s.validated  = false;
            btn.disabled = false;
            btn.textContent = t('validate');
        }
    }

    /* ════════════════════════════════════════════════════════
       SUIVANT / RÉSULTATS
    ════════════════════════════════════════════════════════ */
    function nextQuestion(quizId) {
        const s = state(quizId);
        s.currentIndex++;
        s.validated = false;
        s.currentIndex >= s.total ? showResults(quizId) : renderQuestion(quizId);
    }

    async function showResults(quizId) {
        const s   = state(quizId);
        const pct = Math.round((s.score / s.total) * 100);

        ajax('rdr_submit_quiz', { quiz_id: quizId, score: s.score, total: s.total }).catch(() => {});

        hideEl(`#rdr-quiz-player-${quizId}`);
        const el = qs(`#rdr-quiz-results-${quizId}`);
        if (!el) return;

        const cls = pct >= 80 ? 'rdr-score-great' : pct >= 50 ? 'rdr-score-ok' : 'rdr-score-bad';

        let html = `
        <div class="rdr-results-header">
            <div class="rdr-score-circle ${cls}">
                <span class="rdr-score-pct">${pct}<small>%</small></span>
                <span class="rdr-score-label">${s.score} / ${s.total}</span>
            </div>
            <h2>${t('your_score')}</h2>
            <p class="rdr-score-msg">${scoreMsg(pct)}</p>
            <div class="rdr-results-actions">
                <button class="rdr-btn rdr-btn-retry" id="rdr-retry-${quizId}">🔄 ${t('retry')}</button>
            </div>
        </div>
        <div class="rdr-results-review"><h3>📝 ${t('review')}</h3>`;

        s.answers.forEach((ans, i) => {
            html += `
            <div class="rdr-review-item ${ans.correct ? 'rdr-review-correct' : 'rdr-review-wrong'}">
                <div class="rdr-review-num">${i + 1}</div>
                <div class="rdr-review-body">
                    <div class="rdr-review-q">${escHtml(ans.questionTitle)}</div>
                    <div class="rdr-review-verdict">${ans.correct ? '✅' : '❌'}</div>
                    ${!ans.correct ? `<div class="rdr-review-correct-ans"><em>${t('correct_was')}</em> ${fmtCorrect(ans)}</div>` : ''}
                    ${ans.explanation ? `<div class="rdr-review-explanation">${ans.explanation}</div>` : ''}
                    ${ans.docLink ? `<a href="${escHtml(ans.docLink)}" target="_blank" rel="noopener" class="rdr-feedback-link">${t('learn_more')}</a>` : ''}
                </div>
            </div>`;
        });

        el.innerHTML = html + '</div>';
        showEl(`#rdr-quiz-results-${quizId}`);
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });

        qs(`#rdr-retry-${quizId}`)?.addEventListener('click', () => {
            hideEl(`#rdr-quiz-results-${quizId}`);
            showEl(`#rdr-quiz-intro-${quizId}`);
        });
    }

    /* ── Formater la bonne réponse dans la revue ── */
    function fmtCorrect(ans) {
        const cd = ans.correctData;
        if (ans.type === 'yesno')    return cd === 'yes' ? 'Oui' : 'Non';
        if (ans.type === 'free')     return Array.isArray(cd) ? cd.map(escHtml).join(' ou ') : escHtml(String(cd));
        if (ans.type === 'order')    return Array.isArray(cd) ? cd.map(escHtml).join(' → ') : escHtml(String(cd));
        if (ans.type === 'fill') {
            if (!Array.isArray(cd)) return '';
            return cd.map((item, idx) =>
                `Trou ${idx + 1}: <strong class="${item.correct ? 'rdr-fill-ok' : 'rdr-fill-ko'}">${escHtml(item.user || '(vide)')}</strong>`
                + (!item.correct ? ` → ${(item.accepted || []).map(escHtml).join(' / ')}` : '')
            ).join(' &nbsp;|&nbsp; ');
        }
        if (ans.type === 'radio') {
            const found = ans.choices.find(c => String(c.id) === String(cd));
            return found ? escHtml(found.text) : escHtml(String(cd));
        }
        if (ans.type === 'checkbox') {
            const ids = Array.isArray(cd) ? cd.map(String) : [String(cd)];
            return ans.choices.filter(c => ids.includes(String(c.id))).map(c => escHtml(c.text)).join(', ');
        }
        return escHtml(String(cd));
    }

    function scoreMsg(pct) {
        if (pct === 100) return '🏆 Parfait ! Vous maîtrisez le sujet !';
        if (pct >= 80)   return '🎉 Excellent résultat !';
        if (pct >= 60)   return '👍 Bon résultat, continuez comme ça !';
        if (pct >= 40)   return '📚 Pas mal, il reste encore un peu de travail…';
        return '💪 Courage ! Relisez l\'article et retentez le quiz.';
    }

    /* ════════════════════════════════════════════════════════
       PROGRESS BAR
    ════════════════════════════════════════════════════════ */
    function updateProgress(quizId) {
        const s   = state(quizId);
        const pct = (s.currentIndex / s.total) * 100;
        const fill = qs(`#rdr-progress-${quizId}`);
        const txt  = qs(`#rdr-progress-text-${quizId}`);
        if (fill) fill.style.width = pct + '%';
        if (txt)  txt.textContent  = `${t('question')} ${s.currentIndex + 1} ${t('of')} ${s.total}`;
    }

    /* ════════════════════════════════════════════════════════
       ARCHIVE ASYNC
    ════════════════════════════════════════════════════════ */
    function initArchive() {
        let curCat = '', curDiff = '', curTag = '', curPage = 1;
        const archive = qs('#rdr-quiz-archive');
        const useModal = archive?.dataset.modal === '1';
        const grid   = qs('#rdr-quiz-grid');
        const pag    = qs('#rdr-quiz-pagination');
        const catSel = qs('#rdr-filter-cat');
        const difSel = qs('#rdr-filter-diff');
        const tagSel = qs('#rdr-filter-tag');

        function load(page) {
            curPage = page;
            if (grid) grid.innerHTML = `<div class="rdr-quiz-loading"><div class="rdr-spinner"></div></div>`;
            ajax('rdr_quiz_list', { cat: curCat, diff: curDiff, tag: curTag, paged: page })
                .then(res => {
                    if (!res.success || !res.data.quizzes.length) {
                        if (grid) grid.innerHTML = `<p class="rdr-no-quiz">${t('no_quiz')}</p>`;
                        if (pag)  pag.innerHTML  = '';
                        return;
                    }
                    if (grid) grid.innerHTML = res.data.quizzes.map(q => cardHtml(q, useModal)).join('');
                    // Attacher les boutons modal nouvellement créés
                    if (useModal && window.rdrModal) window.rdrModal.attachButtons();
                    buildPagination(res.data, page);
                })
                .catch(() => { if (grid) grid.innerHTML = `<p class="rdr-error">${t('error_load')}</p>`; });
        }

        function buildPagination(data, cur) {
            if (!pag || data.max_pages <= 1) { if (pag) pag.innerHTML = ''; return; }
            let html = '<div class="rdr-pagination">';
            for (let i = 1; i <= data.max_pages; i++)
                html += `<button class="rdr-page-btn${i === cur ? ' active' : ''}" data-page="${i}">${i}</button>`;
            pag.innerHTML = html + '</div>';
            pag.querySelectorAll('.rdr-page-btn').forEach(btn =>
                btn.addEventListener('click', () => load(parseInt(btn.dataset.page, 10))));
        }

        catSel?.addEventListener('change', () => { curCat  = catSel.value; load(1); });
        difSel?.addEventListener('change', () => { curDiff = difSel.value; load(1); });
        tagSel?.addEventListener('change', () => { curTag  = tagSel.value; load(1); });
        load(1);
    }

    function cardHtml(q, useModal) {
        const colors = window.rdrDiffColors || {};
        const color  = colors[q.difficulty] || '#6b7280';
        const label  = t(q.difficulty) || q.difficulty;
        const avg    = q.avg_score !== null ? q.avg_score + '%' : '—';
        const thumb  = q.thumbnail
            ? `<div class="rdr-quiz-card-img" style="background-image:url('${escHtml(q.thumbnail)}')"></div>` : '';
        const tagsHtml = (q.tags || []).slice(0, 4)
            .map(tag => `<span class="rdr-card-tag-pill">${escHtml(tag.name)}</span>`)
            .join('');

        return `
        <div class="rdr-quiz-card">
            ${thumb}
            <div class="rdr-quiz-card-body">
                ${tagsHtml ? `<div class="rdr-quiz-card-tags">${tagsHtml}</div>` : ''}
                <div class="rdr-quiz-card-badges">
                    <span class="rdr-badge rdr-badge-diff" style="--diff-color:${color}">${escHtml(label)}</span>
                    <span class="rdr-badge">❓ ${q.q_count} ${t('questions')}</span>
                    <span class="rdr-badge">⏱ ${q.duration} min</span>
                </div>
                <h3 class="rdr-quiz-card-title"><a href="${escHtml(q.url)}">${escHtml(q.title)}</a></h3>
                <p class="rdr-quiz-card-excerpt">${escHtml(q.excerpt)}</p>
                <div class="rdr-quiz-card-stats">
                    <span>📊 ${q.attempts} ${t('attempts')}</span>
                    <span>🎯 ${avg} ${t('avg_score')}</span>
                </div>
                ${useModal
                    ? `<button type="button" class="rdr-quiz-modal-btn rdr-btn-sm"
                               data-quiz-id="${escHtml(String(q.id))}">${t('start')}</button>`
                    : `<a href="${escHtml(q.url)}" class="rdr-btn rdr-btn-sm">${t('start')}</a>`
                }
            </div>
        </div>`;
    }

})();
