/* global jQuery, wpptAdmin, wp */
(function ($) {
    'use strict';

    // -------------------------------------------------------------------------
    // Settings page – test connection button
    // -------------------------------------------------------------------------
    $(document).on('click', '.wppt-test-btn', function () {
        var $btn    = $(this);
        var $result = $btn.siblings('.wppt-test-result');
        var service = $btn.data('service');

        $result.text(wpptAdmin.strings && wpptAdmin.strings.testing ? wpptAdmin.strings.testing : 'Test…').removeClass('ok error');
        $btn.prop('disabled', true);

        $.post(wpptAdmin.ajaxUrl, {
            action:  'wppt_test_connection',
            nonce:   wpptAdmin.nonce,
            service: service
        }, function (response) {
            if (response.success) {
                $result.text(response.data.message).addClass('ok').removeClass('error');
            } else {
                $result.text((wpptAdmin.strings.error || 'Erreur : ') + response.data.message).addClass('error').removeClass('ok');
            }
        }).fail(function () {
            $result.text('Erreur réseau').addClass('error').removeClass('ok');
        }).always(function () {
            $btn.prop('disabled', false);
        });
    });

    // -------------------------------------------------------------------------
    // Post edit screen – auto-translate on page load (new translation)
    // Ne se déclenche que si le chemin serveur (load-post.php) n'a pas déjà
    // traduit et redirigé — c'est-à-dire quand `from_post` est encore dans l'URL.
    // -------------------------------------------------------------------------
    $(document).ready(function () {
        if (
            typeof wpptAdmin === 'undefined' ||
            !wpptAdmin.fromPost ||
            !wpptAdmin.postId   ||
            !wpptAdmin.newLang  ||
            wpptAdmin.autoTranslate !== '1'
        ) {
            return;
        }

        setTimeout(function () {
            triggerTranslation(
                wpptAdmin.postId,
                wpptAdmin.fromPost,
                wpptAdmin.sourceLang || '',
                wpptAdmin.newLang,
                true
            );
        }, 1000);
    });

    // -------------------------------------------------------------------------
    // Post edit screen – Re-translate button
    // -------------------------------------------------------------------------
    $(document).on('click', '#wppt-retranslate-btn', function () {
        var $btn       = $(this);
        var $status    = $('#wppt-retranslate-status');
        var postId     = $btn.data('post-id');
        var sourceId   = $btn.data('source-id');
        var sourceLang = $btn.data('source-lang');
        var targetLang = $btn.data('target-lang');

        if (!confirm('Retraduire depuis la langue source ? Le contenu actuel sera remplacé.')) {
            return;
        }

        $status.html('<em>' + (wpptAdmin.strings.retranslating || 'Retraduction…') + '</em>');
        $btn.prop('disabled', true);

        triggerTranslation(postId, sourceId, sourceLang, targetLang, false, function (success, msg, data) {
            if (success) {
                // La traduction est DÉJÀ enregistrée en base par PHP.
                // On ne l'injecte donc pas dans l'éditeur : cela marquerait le
                // post comme « modifié » et le rechargement déclencherait
                // l'alerte « quitter le site ? » — avec le risque d'afficher
                // un état différent de la base. On recharge simplement.
                $status.html('<span style="color:#0a8a45;font-weight:600;">' +
                    (wpptAdmin.strings.done || 'Traduction terminée !') + '</span>');
                markEditorClean();
                setTimeout(function () { location.reload(); }, 800);
            } else {
                $status.html('<span style="color:#c0392b;">' + (wpptAdmin.strings.error || 'Erreur : ') + msg + '</span>');
                $btn.prop('disabled', false);
            }
        });
    });

    // -------------------------------------------------------------------------
    // Helper – AJAX translation call
    // -------------------------------------------------------------------------
    function triggerTranslation(targetId, sourceId, sourceLang, targetLang, isNew, callback) {
        var $indicator = isNew ? createNewTranslationIndicator() : null;

        $.post(wpptAdmin.ajaxUrl, {
            action:      'wppt_translate_post',
            nonce:       wpptAdmin.nonce,
            source_id:   sourceId,
            target_id:   targetId,
            source_lang: sourceLang,
            target_lang: targetLang
        }, function (response) {
            if ($indicator) { $indicator.remove(); }

            if (response.success) {
                var data = response.data || {};

                if (isNew) {
                    applyToEditor(data);
                    showNotice('success', wpptAdmin.strings.done || 'Traduction terminée ! Publiez pour confirmer.');
                }

                if (typeof callback === 'function') { callback(true, data.message, data); }
            } else {
                var msg = response.data ? response.data.message : 'Erreur inconnue';
                if (isNew) {
                    showNotice('error', (wpptAdmin.strings.error || 'Erreur : ') + msg);
                }
                if (typeof callback === 'function') { callback(false, msg, null); }
            }
        }).fail(function () {
            if ($indicator) { $indicator.remove(); }
            var msg = 'Erreur réseau lors de la traduction.';
            if (isNew) { showNotice('error', msg); }
            if (typeof callback === 'function') { callback(false, msg, null); }
        });
    }

    // -------------------------------------------------------------------------
    // Neutralise l'alerte « modifications non enregistrées » avant un reload
    // volontaire : le contenu à jour se trouve déjà en base.
    // -------------------------------------------------------------------------
    function markEditorClean() {
        $(window).off('beforeunload');
        window.onbeforeunload = null;
    }

    // -------------------------------------------------------------------------
    // Inject translated strings into the Gutenberg (block editor) state.
    // -------------------------------------------------------------------------
    function applyToEditor(data) {
        if (window.wp && wp.data && wp.data.dispatch) {
            var editorDispatch = wp.data.dispatch('core/editor');

            if (data.title && editorDispatch) {
                editorDispatch.editPost({ title: data.title });
            }

            if (data.excerpt && editorDispatch) {
                editorDispatch.editPost({ excerpt: data.excerpt });
            }

            if (data.content) {
                var blockEditorDispatch = wp.data.dispatch('core/block-editor');
                if (blockEditorDispatch && wp.blocks && wp.blocks.parse) {
                    try {
                        var blocks = wp.blocks.parse(data.content);
                        blockEditorDispatch.resetBlocks(blocks);
                    } catch (e) {
                        redirectToClean();
                    }
                }
            }
            return;
        }

        // Classic editor fallback: reload to a clean URL.
        redirectToClean();
    }

    function redirectToClean() {
        if (wpptAdmin.postId) {
            markEditorClean();
            window.location.href = (wpptAdmin.adminUrl || '') + 'post.php?post=' + wpptAdmin.postId + '&action=edit&wppt_translated=1';
        }
    }

    // -------------------------------------------------------------------------
    // UI helpers
    // -------------------------------------------------------------------------
    function createNewTranslationIndicator() {
        var $div = $('<div class="notice notice-info wppt-translating-notice" style="margin:5px 0;">' +
            '<p><span class="spinner" style="float:none;visibility:visible;margin-right:6px;vertical-align:middle;"></span>' +
            (wpptAdmin.strings.translating || 'Traduction en cours…') + '</p></div>');
        $('h1.wp-heading-inline, h1').first().after($div);
        return $div;
    }

    function showNotice(type, message) {
        var cssClass = type === 'success' ? 'notice-success' : 'notice-error';
        var $notice  = $('<div class="notice ' + cssClass + ' is-dismissible" style="margin:5px 0;"><p>' + message + '</p></div>');
        $('h1.wp-heading-inline, h1').first().after($notice);
        $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Fermer</span></button>');
        $notice.on('click', '.notice-dismiss', function () { $notice.remove(); });
    }

}(jQuery));
