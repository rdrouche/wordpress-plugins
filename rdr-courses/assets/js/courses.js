/**
 * RDR-IT Courses — noyau de progression
 *
 * SOURCE UNIQUE : localStorage (cle rdrc_progress_<courseId>).
 * Tous les affichages lisent la, quel que soit l'etat de connexion.
 *
 * Utilisateur connecte : la BDD (usermeta) sert de miroir.
 *   - au chargement : BDD -> localStorage (retrouve sa progression
 *     sur un autre appareil / apres vidage du cache navigateur)
 *   - a chaque validation : localStorage -> BDD
 * Aucun cookie n'est utilise.
 */
window.RdrcProgress = (function () {
    'use strict';

    var SYNC = window.rdrcSync || { loggedIn: false };

    function key(courseId) { return 'rdrc_progress_' + courseId; }

    function read(courseId) {
        try {
            var raw = localStorage.getItem(key(courseId));
            var obj = raw ? JSON.parse(raw) : {};
            return (obj && typeof obj === 'object') ? obj : {};
        } catch (e) { return {}; }
    }

    function write(courseId, data) {
        try { localStorage.setItem(key(courseId), JSON.stringify(data)); return true; }
        catch (e) { return false; }
    }

    /** Notifie tous les composants de la page. */
    function emit(courseId) {
        document.dispatchEvent(new CustomEvent('rdrc:progress', { detail: { courseId: courseId } }));
    }

    function isDone(courseId, lessonId) { return !!read(courseId)[lessonId]; }

    /** Marque / demarque une lecon. Ecrit localStorage puis miroir BDD. */
    function set(courseId, lessonId, done) {
        var store = read(courseId);
        if (done) store[lessonId] = Date.now();
        else      delete store[lessonId];

        var ok = write(courseId, store);
        emit(courseId);

        if (SYNC.loggedIn && SYNC.ajaxUrl) {
            fetch(SYNC.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                body: new URLSearchParams({
                    action: 'rdrc_mark_lesson',
                    nonce: SYNC.nonce,
                    lesson_id: lessonId,
                    done: done ? 1 : 0
                })
            }).catch(function () {});
        }
        return ok;
    }

    /**
     * Hydrate localStorage depuis la BDD pour les cours presents sur la page,
     * puis repousse ce qui n'existait qu'en local (fusion non destructive).
     */
    function hydrate(courseIds, cb) {
        if (!SYNC.loggedIn || !SYNC.ajaxUrl || !courseIds.length) { if (cb) cb(); return; }

        fetch(SYNC.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: new URLSearchParams({
                action: 'rdrc_get_progress_bulk',
                nonce: SYNC.nonce,
                course_ids: courseIds.join(',')
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res || !res.success) { if (cb) cb(); return; }
            var courses = res.data.courses || {};

            courseIds.forEach(function (cid) {
                var remote = courses[cid] || [];
                var local  = read(cid);
                var changed = false;

                // BDD -> local
                remote.forEach(function (lid) {
                    if (!local[lid]) { local[lid] = Date.now(); changed = true; }
                });
                if (changed) write(cid, local);

                // local -> BDD (ce qui a ete fait avant connexion)
                var missing = Object.keys(local).filter(function (lid) {
                    return remote.indexOf(parseInt(lid, 10)) === -1;
                });
                if (missing.length) {
                    fetch(SYNC.ajaxUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        body: new URLSearchParams({
                            action: 'rdrc_push_progress',
                            nonce: SYNC.nonce,
                            course_id: cid,
                            lesson_ids: missing.join(',')
                        })
                    }).catch(function () {});
                }

                emit(cid);
            });
            if (cb) cb();
        })
        .catch(function () { if (cb) cb(); });
    }

    /** Ids de cours presents dans le DOM (toutes vues confondues). */
    function courseIdsOnPage() {
        var ids = [];
        document.querySelectorAll('[data-course-id]').forEach(function (el) {
            var id = parseInt(el.dataset.courseId, 10);
            if (id && ids.indexOf(id) === -1) ids.push(id);
        });
        return ids;
    }

    // Relais du changement inter-onglets
    window.addEventListener('storage', function (e) {
        if (e.key && e.key.indexOf('rdrc_progress_') === 0) {
            emit(parseInt(e.key.replace('rdrc_progress_', ''), 10));
        }
    });

    return {
        read: read, write: write, set: set, isDone: isDone,
        hydrate: hydrate, emit: emit, courseIdsOnPage: courseIdsOnPage,
        loggedIn: !!SYNC.loggedIn
    };
}());


/* Garde : si le noyau n'a pas ete charge (JS combine/minifie casse),
   on fournit un repli localStorage pur plutot que de lever une erreur. */
if (typeof window.RdrcProgress === 'undefined') {
    window.RdrcProgress = {
        read: function (id) {
            try { return JSON.parse(localStorage.getItem('rdrc_progress_' + id) || '{}') || {}; }
            catch (e) { return {}; }
        },
        write: function () { return false; },
        set: function () { return false; },
        isDone: function () { return false; },
        hydrate: function (ids, cb) { if (cb) cb(); },
        emit: function () {},
        courseIdsOnPage: function () { return []; },
        loggedIn: false
    };
}

/**
 * RDR-IT Courses — Vanilla JS
 *
 * PROGRESSION :
 *   Connecté  → AJAX → usermeta BDD
 *   Anonyme   → localStorage uniquement (clé rdrc_progress_<courseId>)
 * Pas de banneau de consentement, pas de token — UX silencieux.
 */
(function () {
    'use strict';

    var qs  = function (sel, ctx) { return (ctx || document).querySelector(sel); };
    var qsa = function (sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); };

    function delegate(root, sel, evt, fn) {
        root.addEventListener(evt, function (e) {
            var t = e.target.closest(sel);
            if (t) fn.call(t, e);
        });
    }

    function slideToggle(el, ms) {
        if (!el) return;
        ms = ms || 200;
        if (el.hidden) {
            el.hidden = false;
            el.style.cssText = 'overflow:hidden;height:0';
            var h = el.scrollHeight, s = null;
            requestAnimationFrame(function step(t) {
                if (!s) s = t;
                var p = Math.min((t - s) / ms, 1);
                el.style.height = (h * p) + 'px';
                if (p < 1) requestAnimationFrame(step);
                else el.style.cssText = '';
            });
        } else {
            var h2 = el.scrollHeight, s2 = null;
            el.style.cssText = 'overflow:hidden;height:' + h2 + 'px';
            requestAnimationFrame(function step2(t) {
                if (!s2) s2 = t;
                var p = Math.min((t - s2) / ms, 1);
                el.style.height = (h2 * (1 - p)) + 'px';
                if (p < 1) requestAnimationFrame(step2);
                else { el.style.cssText = ''; el.hidden = true; }
            });
        }
    }

    function post(url, data, cb) {
        fetch(url, { method: 'POST', body: new URLSearchParams(data) })
            .then(function (r) { return r.json(); })
            .then(cb || function(){}).catch(function () {});
    }

    /* ── TOC toggle ─────────────────────────────────────── */
    delegate(document, '.rdrc-toc-toggle', 'click', function () {
        var open = this.getAttribute('aria-expanded') === 'true';
        slideToggle(this.nextElementSibling);
        this.setAttribute('aria-expanded', open ? 'false' : 'true');
        var a = qs('.rdrc-toc-arrow', this);
        if (a) a.textContent = open ? '▼' : '▲';
    });

    /* ── Ressources toggle ──────────────────────────────── */
    delegate(document, '.rdrc-resources-toggle', 'click', function () {
        var open = this.getAttribute('aria-expanded') === 'true';
        slideToggle(this.nextElementSibling);
        this.setAttribute('aria-expanded', open ? 'false' : 'true');
        var a = qs('.rdrc-res-arrow', this);
        if (a) a.textContent = open ? '▼' : '▲';
    });

    /* ── Progression ────────────────────────────────────── */
    if (typeof window.rdrcProgress === 'undefined') return;

    var P         = window.rdrcProgress;
    var LS_KEY    = 'rdrc_progress_' + P.courseId; // localStorage par cours

    if (!P.courseId) return;

    /* Marquer la leçon courante et charger la progression */
    /* Source unique : localStorage. Le noyau RdrcProgress se charge
       du miroir en BDD quand l'utilisateur est connecte. */
    function repaint() {
        applyProgress(Object.keys(RdrcProgress.read(P.courseId)).map(Number));
    }

    // Marquer la lecon courante comme vue
    if (P.lessonId) RdrcProgress.set(P.courseId, P.lessonId, true);

    // Hydratation depuis la BDD (connecte), puis rafraichissement
    RdrcProgress.hydrate([P.courseId], repaint);

    document.addEventListener('rdrc:progress', function (e) {
        if (!e.detail || e.detail.courseId === P.courseId) repaint();
    });

    repaint();

    /* ── Appliquer la progression au DOM ────────────── */
    function applyProgress(doneIds) {
        var lessons = qsa('.rdrc-toc-lesson[data-lesson-id]');
        var nbDone  = 0;
        lessons.forEach(function (li) {
            if (doneIds.indexOf(parseInt(li.dataset.lessonId, 10)) !== -1) {
                li.classList.add('rdrc-lesson-done');
                nbDone++;
            }
        });
        var pct = lessons.length ? Math.min(100, Math.round(nbDone / lessons.length * 100)) : 0;
        renderProgressBlock(pct, doneIds.length);
    }

    function renderProgressBlock(pct, nbDone) {
        var storageLabel = RdrcProgress.loggedIn
            ? '☁️ Synchronisé avec votre compte'
            : '💾 Sauvegardé localement';

        var html =
            '<div class="rdrc-progress-block">' +
            '<div class="rdrc-progress-header">' +
            '<span class="rdrc-progress-label">Progression</span>' +
            '<span class="rdrc-progress-pct">' + pct + '%</span>' +
            '</div>' +
            '<div class="rdrc-progress-track">' +
            '<div class="rdrc-progress-bar" style="width:0%"></div>' +
            '</div>' +
            '<div class="rdrc-progress-storage">' + storageLabel + '</div>' +
            '</div>';

        // Remplacer l'ancre inline (PHP)
        var inline = qs('#rdrc-progress-inline');
        if (inline) inline.outerHTML = html;

        // Remplacer l'ancre sidebar si présente
        var sidebar = qs('#rdrc-progress-widget-anchor');
        if (sidebar) sidebar.outerHTML = html;

        // Animer la barre après insertion
        setTimeout(function () {
            qsa('.rdrc-progress-bar').forEach(function (b) {
                b.style.width = pct + '%';
            });
        }, 100);
    }

    /* ── Copier token (retro-compat si widget présent) ── */
    delegate(document, '.rdrc-copy-token', 'click', function () {
        var btn = this, val = (qs('.rdrc-token-val') || {}).textContent || '';
        if (val && navigator.clipboard) navigator.clipboard.writeText(val).then(function () {
            btn.textContent = '✅';
            setTimeout(function () { btn.textContent = '📋'; }, 2000);
        });
    });

}());

/* ══════════════════════════════════════════════════════════
   BARRE DE SUIVI — [rdrc_progress_bar]
   Stockage : localStorage uniquement (aucun cookie, aucun POST).
   Cle : rdrc_progress_<courseId> -> { "<lessonId>": <timestamp> }
   ══════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var bars = document.querySelectorAll('[data-rdrc-pbar]');
    if (!bars.length) return;

    function keyFor(courseId) { return 'rdrc_progress_' + courseId; }
    function readStore(courseId) { return RdrcProgress.read(courseId); }

    function idList(el, attr) {
        var raw = el.getAttribute(attr) || '';
        return raw.split(',')
            .map(function (s) { return parseInt(s, 10); })
            .filter(function (n) { return !isNaN(n); });
    }

    function countDone(ids, store) {
        var n = 0;
        for (var i = 0; i < ids.length; i++) {
            if (store[ids[i]]) n++;
        }
        return n;
    }

    function paintMeter(meter, done, total) {
        if (!meter) return;
        var pct = total > 0 ? Math.round((done / total) * 100) : 0;

        var fill = meter.querySelector('[data-rdrc-fill]');
        if (fill) fill.style.width = pct + '%';

        var elDone = meter.querySelector('[data-rdrc-done]');
        if (elDone) elDone.textContent = done;

        var elTotal = meter.querySelector('[data-rdrc-total]');
        if (elTotal) elTotal.textContent = total;

        var elPct = meter.querySelector('[data-rdrc-pct]');
        if (elPct) elPct.textContent = '(' + pct + '%)';

        meter.classList.toggle('is-complete', total > 0 && done >= total);
    }

    function setupBar(bar) {
        var courseId  = parseInt(bar.dataset.courseId, 10);
        var lessonId  = parseInt(bar.dataset.lessonId, 10);
        var moduleIds = idList(bar, 'data-module-ids');
        var courseIds = idList(bar, 'data-course-ids');

        var meterModule = bar.querySelector('[data-rdrc-meter="module"]');
        var meterCourse = bar.querySelector('[data-rdrc-meter="course"]');
        var markBtn     = bar.querySelector('[data-rdrc-mark]');

        function refresh() {
            var store = readStore(courseId);

            paintMeter(meterModule, countDone(moduleIds, store), moduleIds.length);
            paintMeter(meterCourse, countDone(courseIds, store), courseIds.length);

            if (markBtn) {
                var isDone = !!store[lessonId];
                markBtn.setAttribute('aria-pressed', isDone ? 'true' : 'false');
                var txt = markBtn.querySelector('.rdrc-pbar__mark-txt');
                if (txt) txt.textContent = isDone ? (markBtn.dataset.txtDone || 'Lu')
                                                  : (markBtn.dataset.txtTodo || txt.textContent);
            }
        }

        if (markBtn) {
            // Libelles fournis par PHP (traduits via Polylang) ; repli sur le DOM
            var txtEl = markBtn.querySelector('.rdrc-pbar__mark-txt');
            if (!markBtn.dataset.txtTodo) {
                markBtn.dataset.txtTodo = txtEl ? txtEl.textContent : 'Marquer comme lu';
            }
            if (!markBtn.dataset.txtDone) {
                markBtn.dataset.txtDone = 'Lu';
            }

            markBtn.addEventListener('click', function () {
                var now = !RdrcProgress.isDone(courseId, lessonId);
                if (!RdrcProgress.set(courseId, lessonId, now)) {
                    markBtn.disabled = true;
                    markBtn.title = 'Stockage local indisponible dans ce navigateur.';
                    return;
                }
                refresh();
            });
        }

        document.addEventListener('rdrc:progress', function (e) {
            if (!e.detail || e.detail.courseId === courseId) refresh();
        });

        RdrcProgress.hydrate([courseId], refresh);
        refresh();
    }

    for (var i = 0; i < bars.length; i++) setupBar(bars[i]);
}());

/* ══════════════════════════════════════════════════════════
   VUE D'ENSEMBLE — [rdrc_my_courses]
   Lit localStorage (aucun cookie, aucun appel serveur) et
   calcule l'avancement de chaque parcours cote client.
   ══════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var roots = document.querySelectorAll('[data-rdrc-mycourses]');
    if (!roots.length) return;

    function readStore(courseId) { return RdrcProgress.read(courseId); }

    function parseLessons(card) {
        var tag = card.querySelector('[data-rdrc-mc-lessons]');
        if (!tag) return [];
        try {
            var arr = JSON.parse(tag.textContent || '[]');
            return Array.isArray(arr) ? arr : [];
        } catch (e) {
            return [];
        }
    }

    function paintCard(card) {
        var courseId = parseInt(card.dataset.courseId, 10);
        var lessons  = parseLessons(card);
        var total    = lessons.length;
        if (!total) return null;

        var store = readStore(courseId);

        var done = 0;
        var nextUrl = null;
        for (var i = 0; i < total; i++) {
            if (store[lessons[i].id]) {
                done++;
            } else if (nextUrl === null) {
                nextUrl = lessons[i].url; // premiere lecon non terminee
            }
        }

        var pct = Math.round((done / total) * 100);

        var fill = card.querySelector('[data-rdrc-mc-fill]');
        if (fill) fill.style.width = pct + '%';

        var elDone = card.querySelector('[data-rdrc-mc-done]');
        if (elDone) elDone.textContent = done;

        var elPct = card.querySelector('[data-rdrc-mc-pct]');
        if (elPct) elPct.textContent = '(' + pct + '%)';

        // Statut : todo / progress / done
        var status = done === 0 ? 'todo' : (done >= total ? 'done' : 'progress');
        card.dataset.status = status;
        card.classList.toggle('is-complete', status === 'done');

        var ribbon = card.querySelector('[data-rdrc-mc-ribbon]');
        if (ribbon) ribbon.hidden = (status !== 'done');

        // Bouton contextuel : Commencer / Reprendre / Revoir
        var cta = card.querySelector('[data-rdrc-mc-cta]');
        if (cta) {
            if (status === 'todo') {
                cta.textContent = cta.dataset.txtStart;
                cta.href = lessons[0].url;
            } else if (status === 'progress') {
                cta.textContent = cta.dataset.txtResume;
                cta.href = nextUrl || lessons[0].url;
            } else {
                cta.textContent = cta.dataset.txtReview;
                cta.href = cta.dataset.urlCourse || lessons[0].url;
            }
        }

        return { total: total, done: done, status: status };
    }

    function paintStats(root, rows) {
        var started = 0, completed = 0, lessonsDone = 0, lessonsTotal = 0;

        rows.forEach(function (r) {
            if (r.done > 0)        started++;
            if (r.status === 'done') completed++;
            lessonsDone  += r.done;
            lessonsTotal += r.total;
        });

        var global = lessonsTotal > 0 ? Math.round((lessonsDone / lessonsTotal) * 100) : 0;

        function set(sel, val) {
            var el = root.querySelector(sel);
            if (el) el.textContent = val;
        }
        set('[data-rdrc-mc-started]', started);
        set('[data-rdrc-mc-done]',    completed);
        set('[data-rdrc-mc-lessons]', lessonsDone);
        set('[data-rdrc-mc-global]',  global + '%');
    }

    function applyFilter(root, filter) {
        var cards   = root.querySelectorAll('[data-rdrc-mc-card]');
        var visible = 0;

        for (var i = 0; i < cards.length; i++) {
            var match = (filter === 'all') || (cards[i].dataset.status === filter);
            cards[i].hidden = !match;
            if (match) visible++;
        }

        var none = root.querySelector('[data-rdrc-mc-none]');
        if (none) none.hidden = (visible > 0);
    }

    function setupRoot(root) {
        var cards = root.querySelectorAll('[data-rdrc-mc-card]');

        function refresh() {
            var rows = [];
            for (var i = 0; i < cards.length; i++) {
                var r = paintCard(cards[i]);
                if (r) rows.push(r);
            }
            paintStats(root, rows);

            var active = root.querySelector('.rdrc-mc-filter.is-active');
            applyFilter(root, active ? active.dataset.filter : 'all');
        }

        var filters = root.querySelector('[data-rdrc-mc-filters]');
        if (filters) {
            filters.addEventListener('click', function (e) {
                var btn = e.target.closest('.rdrc-mc-filter');
                if (!btn) return;
                var all = filters.querySelectorAll('.rdrc-mc-filter');
                for (var i = 0; i < all.length; i++) all[i].classList.remove('is-active');
                btn.classList.add('is-active');
                applyFilter(root, btn.dataset.filter);
            });
        }

        document.addEventListener('rdrc:progress', refresh);

        // Connecte : recuperer la progression de tous les cours affiches
        var ids = [];
        for (var j = 0; j < cards.length; j++) {
            var cid = parseInt(cards[j].dataset.courseId, 10);
            if (cid && ids.indexOf(cid) === -1) ids.push(cid);
        }
        RdrcProgress.hydrate(ids, refresh);

        refresh();
    }

    for (var i = 0; i < roots.length; i++) setupRoot(roots[i]);
}());

/* ══════════════════════════════════════════════════════════
   PAGE COURS — vue parcours + marquage du sommaire
   localStorage uniquement, aucun cookie, aucun appel serveur.
   ══════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    function readStore(courseId) { return RdrcProgress.read(courseId); }

    function paint(meter, done, total) {
        if (!meter) return;
        var pct = total > 0 ? Math.round(done / total * 100) : 0;
        var f = meter.querySelector('[data-rdrc-fill]'); if (f) f.style.width = pct + '%';
        var d = meter.querySelector('[data-rdrc-done]'); if (d) d.textContent = done;
        var t = meter.querySelector('[data-rdrc-total]'); if (t) t.textContent = total;
        var p = meter.querySelector('[data-rdrc-pct]');  if (p) p.textContent = '(' + pct + '%)';
        meter.classList.toggle('is-complete', total > 0 && done >= total);
    }

    /* ── Sommaire : cocher les lecons terminees ───────────── */
    document.querySelectorAll('[data-rdrc-syllabus]').forEach(function (syl) {
        var courseId = parseInt(syl.dataset.courseId, 10);
        if (!courseId) return;

        function refresh() {
            var store = readStore(courseId);
            syl.querySelectorAll('[data-rdrc-syl-lesson]').forEach(function (row) {
                var id = parseInt(row.dataset.lessonId, 10);
                row.classList.toggle('is-done', !!store[id]);
            });
        }
        document.addEventListener('rdrc:progress', function (e) {
            if (!e.detail || e.detail.courseId === courseId) refresh();
        });
        RdrcProgress.hydrate([courseId], refresh);
        refresh();
    });

    /* ── Vue parcours (page de presentation du cours) ─────── */
    document.querySelectorAll('[data-rdrc-course-overview]').forEach(function (box) {
        var courseId = parseInt(box.dataset.courseId, 10);

        var payload = { flat: [], modules: [] };
        var tag = box.querySelector('[data-rdrc-co-data]');
        try { payload = JSON.parse(tag.textContent) || payload; } catch (e) { return; }

        var flat    = payload.flat || [];
        var modules = payload.modules || [];
        if (!flat.length) return;

        var meterTotal = box.querySelector('[data-rdrc-co-total]');
        var cta        = box.querySelector('[data-rdrc-co-cta]');
        var state      = box.querySelector('[data-rdrc-co-state]');
        var resetWrap  = box.querySelector('[data-rdrc-co-resetwrap]');
        var resetBtn   = box.querySelector('[data-rdrc-co-reset]');

        function refresh() {
            var store = readStore(courseId);

            var done = 0, nextUrl = null;
            for (var i = 0; i < flat.length; i++) {
                if (store[flat[i].id]) done++;
                else if (nextUrl === null) nextUrl = flat[i].url;
            }
            paint(meterTotal, done, flat.length);

            // Detail par module
            for (var m = 0; m < modules.length; m++) {
                var meter = box.querySelector('[data-rdrc-co-mod="' + m + '"]');
                if (!meter) continue;
                var ls = modules[m].lessons || [], dn = 0;
                for (var k = 0; k < ls.length; k++) if (store[ls[k].id]) dn++;
                paint(meter, dn, ls.length);
            }

            var status = done === 0 ? 'todo' : (done >= flat.length ? 'done' : 'progress');
            box.dataset.status = status;
            box.classList.toggle('is-complete', status === 'done');

            if (cta) {
                if (status === 'todo') {
                    cta.textContent = cta.dataset.txtStart;
                    cta.href = cta.dataset.urlFirst;
                } else if (status === 'progress') {
                    cta.textContent = cta.dataset.txtResume;
                    cta.href = nextUrl || cta.dataset.urlFirst;
                } else {
                    cta.textContent = cta.dataset.txtReview;
                    cta.href = cta.dataset.urlFirst;
                }
            }
            if (state) {
                state.textContent = done + ' / ' + flat.length;
            }
            if (resetWrap) resetWrap.hidden = (done === 0);
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                var store = RdrcProgress.read(courseId);
                Object.keys(store).forEach(function (lid) {
                    RdrcProgress.set(courseId, parseInt(lid, 10), false);
                });
                try { localStorage.removeItem('rdrc_progress_' + courseId); } catch (e) {}
                RdrcProgress.emit(courseId);
                refresh();
            });
        }

        document.addEventListener('rdrc:progress', function (e) {
            if (!e.detail || e.detail.courseId === courseId) refresh();
        });

        RdrcProgress.hydrate([courseId], refresh);
        refresh();
    });
}());
