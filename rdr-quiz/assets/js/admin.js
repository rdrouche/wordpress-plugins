/**
 * RDR-IT Quiz — admin.js v1.5
 * Deux contextes indépendants :
 *   1. Éditeur de QUESTION (post_type=rdr_question) — switch type, sortable propositions
 *   2. Éditeur de QUIZ     (post_type=rdr_quiz)     — gestionnaire de questions AJAX
 */
jQuery(function ($) {
    'use strict';

    /* ══════════════════════════════════════════════════════════
       1. ÉDITEUR DE QUESTION — toujours actif si #rdr-question-editor présent
    ══════════════════════════════════════════════════════════ */

    if ($('#rdr-question-editor').length) {

        /* ── Switch type de réponse ──────────────────────── */
        $(document).on('change', 'input[name="rdr_answer_type"]', function () {
            var type = $(this).val();
            $('.rdr-type-btn').removeClass('active');
            $(this).closest('.rdr-type-btn').addClass('active');
            var tpl = (typeof rdrAnswerTemplates !== 'undefined') ? rdrAnswerTemplates[type] : '';
            if (tpl) {
                $('#rdr-answers-container').html(tpl);
                initChoicesSortable();
            }
        });

        /* ── Sortable propositions (radio/checkbox) ─────── */
        function initChoicesSortable() {
            if ($('#rdr-choices-sortable').length) {
                $('#rdr-choices-sortable').sortable({
                    handle:      '.rdr-handle',
                    placeholder: 'rdr-sortable-placeholder',
                    update:      reindexChoices,
                });
            }
            if ($('#rdr-order-sortable').length) {
                $('#rdr-order-sortable').sortable({
                    handle:      '.rdr-handle',
                    placeholder: 'rdr-sortable-placeholder',
                    update:      reindexOrder,
                });
            }
        }
        initChoicesSortable();

        function reindexChoices() {
            $('#rdr-choices-sortable .rdr-choice-row').each(function (i) {
                $(this).find('input[type="text"]').attr('name', 'rdr_answers[choices][' + i + '][text]');
                $(this).find('input[type="radio"], input[type="checkbox"]').attr('value', i);
            });
        }
        function reindexOrder() {
            $('#rdr-order-sortable .rdr-order-item').each(function (i) {
                $(this).find('input[type="text"]').attr('name', 'rdr_answers[items][' + i + '][text]');
            });
        }

        /* ── Ajouter / supprimer une proposition ─────────── */
        $(document).on('click', '.rdr-add-choice', function () {
            var type  = $(this).data('type');
            var $list = $('#rdr-choices-sortable');
            var i     = $list.children('.rdr-choice-row').length;
            $('<div>', { class: 'rdr-choice-row' }).html(
                '<span class="dashicons dashicons-move rdr-handle"></span>'
              + '<input type="' + (type === 'radio' ? 'radio' : 'checkbox') + '" name="rdr_answers[correct][]" value="' + i + '">'
              + '<input type="text" name="rdr_answers[choices][' + i + '][text]" placeholder="Texte de la proposition…">'
              + '<button type="button" class="button rdr-remove-choice">×</button>'
            ).appendTo($list).find('input[type="text"]').focus();
        });

        $(document).on('click', '.rdr-remove-choice', function () {
            $(this).closest('.rdr-choice-row').remove();
            reindexChoices();
        });

        /* ── Ajouter / supprimer un élément d'ordre ──────── */
        $(document).on('click', '.rdr-add-order', function () {
            var $list = $('#rdr-order-sortable');
            var i     = $list.children('.rdr-order-item').length;
            $('<li>', { class: 'rdr-order-item' }).html(
                '<span class="dashicons dashicons-menu rdr-handle"></span>'
              + '<input type="text" name="rdr_answers[items][' + i + '][text]" placeholder="Élément…">'
              + '<button type="button" class="button rdr-remove-order">×</button>'
            ).appendTo($list).find('input').focus();
        });

        $(document).on('click', '.rdr-remove-order', function () {
            $(this).closest('.rdr-order-item').remove();
            reindexOrder();
        });

    } // fin bloc éditeur question


    /* ══════════════════════════════════════════════════════════
       2. GESTIONNAIRE DE QUESTIONS (dans l'éditeur de quiz)
          Actif uniquement si #rdr-questions-manager présent
    ══════════════════════════════════════════════════════════ */

    if (!$('#rdr-questions-manager').length) return;

    var $available  = $('#rdr-available-list');
    var $selected   = $('#rdr-selected-list');
    var $btnApply   = $('#rdr-q-apply-filters');
    var $btnReset   = $('#rdr-q-reset-filters');
    var $searchTxt  = $('#rdr-q-search');
    var $searchId   = $('#rdr-q-search-id');
    var $filterDiff = $('#rdr-q-filter-diff');

    /* ── IDs déjà dans le quiz (exclure des résultats AJAX) ── */
    function getSelectedIds() {
        return $selected.children('li').map(function () {
            return parseInt($(this).data('id'), 10);
        }).get();
    }

    /* ── Compteurs ─────────────────────────────────────────── */
    function updateCounts() {
        var total   = $available.children('li').length;
        var visible = $available.children('li:visible').length;
        var sel     = $selected.children('li').length;
        $('#rdr-available-count').text(visible < total ? visible + ' / ' + total : total);
        $('#rdr-selected-count').text(sel);
    }

    /* ── Helpers ────────────────────────────────────────────── */
    function escAdm(str) {
        return String(str || '')
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function typeLabel(type) {
        return ({ yesno:'O/N', radio:'QCU', checkbox:'QCM', order:'TRI', free:'CLI', fill:'GAP' })[type] || '?';
    }

    /* ── Requête AJAX serveur ───────────────────────────────── */
    function applyFilters() {
        var tags    = $('.rdr-tag-checkbox:checked').map(function () { return $(this).val(); }).get();
        var diff    = $filterDiff.val() || '';
        var search  = ($searchTxt.val() || '').trim();
        var sid     = parseInt($searchId.val() || '0', 10);
        var exclude = getSelectedIds();

        $available.html(
            '<li class="rdr-loading-row"><span class="spinner is-active"></span> '
            + (rdrQuizAdmin.i18n.loading || 'Chargement…') + '</li>'
        );
        $('#rdr-available-count').text('…');

        $.post(rdrQuizAdmin.ajaxUrl, {
            action:    'rdr_get_questions',
            nonce:     rdrQuizAdmin.nonce,
            tags:      tags,
            diff:      diff,
            search:    search,
            search_id: sid,
            exclude:   exclude,
        }, function (resp) {
            $available.empty();
            $('#rdr-available-empty').remove();

            if (!resp.success || !resp.data || resp.data.length === 0) {
                $available.after(
                    '<p id="rdr-available-empty" class="rdr-no-results">'
                    + (rdrQuizAdmin.i18n.no_results || 'Aucune question trouvée') + '</p>'
                );
                updateCounts();
                return;
            }

            var diffMap = { debutant:'Deb', intermediaire:'Int', avance:'Avd', expert:'Exp' };

            resp.data.forEach(function (q) {
                var tagPills = (q.tags || []).map(function (t) {
                    return '<span class="rdr-q-tag-pill">' + escAdm(t) + '</span>';
                }).join('');

                var diffBadge = q.diff
                    ? '<span class="rdr-q-diff rdr-diff-' + q.diff + '">' + (diffMap[q.diff] || q.diff) + '</span>'
                    : '';

                var $li = $('<li>', {
                    'data-id':    q.id,
                    'data-title': (q.title || '').toLowerCase(),
                    'data-diff':  q.diff  || '',
                    'data-tags':  (q.slugs || []).join(','),
                }).html(
                    '<div class="rdr-q-item-inner">'
                  + '  <div class="rdr-q-item-badges">'
                  + '    <span class="rdr-q-type rdr-type-' + (q.type || 'radio') + '">' + typeLabel(q.type) + '</span>'
                  + diffBadge + tagPills
                  + '    <span class="rdr-q-id" title="ID">#' + q.id + '</span>'
                  + '  </div>'
                  + '  <span class="rdr-q-title">' + escAdm(q.title) + '</span>'
                  + '</div>'
                  + '<button type="button" class="rdr-add-q button button-small" title="Ajouter">+</button>'
                );
                $available.append($li);
            });

            updateCounts();
        });
    }

    /* ── Événements filtres ─────────────────────────────────── */
    $btnApply.on('click', applyFilters);

    $btnReset.on('click', function () {
        $searchTxt.val('');
        $searchId.val('');
        $filterDiff.val('');
        $('.rdr-tag-checkbox').prop('checked', false)
            .closest('.rdr-tag-filter-pill').removeClass('active');
        applyFilters();
    });

    $(document).on('change', '.rdr-tag-checkbox', function () {
        $(this).closest('.rdr-tag-filter-pill').toggleClass('active', $(this).is(':checked'));
        applyFilters();
    });

    $filterDiff.on('change', applyFilters);

    var debTimer;
    $searchTxt.on('input', function () {
        clearTimeout(debTimer);
        debTimer = setTimeout(applyFilters, 350);
    });

    $searchId.on('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); applyFilters(); }
    });

    // Chargement initial
    applyFilters();

    /* ── Ajouter une question au quiz ───────────────────────── */
    $(document).on('click', '.rdr-add-q', function () {
        var $li     = $(this).closest('li');
        var id      = $li.data('id');
        var type    = $li.find('.rdr-q-type').text();
        var title   = $li.find('.rdr-q-title').text();
        var diff    = $li.attr('data-diff') || '';
        var diffMap = { debutant:'Deb', intermediaire:'Int', avance:'Avd', expert:'Exp' };

        var $new = $('<li>', { 'data-id': id }).html(
            '<span class="dashicons dashicons-move rdr-handle"></span>'
          + '<div class="rdr-q-item-inner">'
          + '  <div class="rdr-q-item-badges">'
          + '    <span class="rdr-q-type">' + escAdm(type) + '</span>'
          + (diff ? '<span class="rdr-q-diff rdr-diff-' + diff + '">' + (diffMap[diff] || diff) + '</span>' : '')
          + '    <span class="rdr-q-id">#' + id + '</span>'
          + '  </div>'
          + '  <span class="rdr-q-title">' + escAdm(title) + '</span>'
          + '</div>'
          + '<input type="hidden" name="rdr_quiz_questions[]" value="' + id + '">'
          + '<button type="button" class="rdr-remove-q button button-small" title="Retirer">×</button>'
        );

        $selected.append($new);
        $li.remove();
        updateCounts();
    });

    /* ── Retirer une question du quiz ───────────────────────── */
    $(document).on('click', '.rdr-remove-q', function () {
        $(this).closest('li').remove();
        updateCounts();
        applyFilters(); // la question réapparaît dans la liste
    });

    /* ── Sortable — ordre des questions du quiz ─────────────── */
    if ($selected.length) {
        $selected.sortable({
            handle:      '.rdr-handle',
            placeholder: 'rdr-sortable-placeholder',
            tolerance:   'pointer',
        });
    }

}); // fin jQuery ready
