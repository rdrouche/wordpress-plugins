/* Social AutoPoster – Admin JS */
(function ($) {
    'use strict';

    // ── Onglets (page réglages) ──────────────────────────────────────────────
    $(document).on('click', '.sap-tab', function (e) {
        e.preventDefault();
        var target = $(this).attr('href');
        $('.sap-tab').removeClass('active');
        $(this).addClass('active');
        $('.sap-tab-content').removeClass('active');
        $(target).addClass('active');
    });

    // ── Test connexion ───────────────────────────────────────────────────────
    $(document).on('click', '.sap-test-btn', function () {
        var $btn    = $(this);
        var network = $btn.data('network');
        var $result = $('#sap-test-' + network);

        $result.text('Test en cours…').removeClass('ok err');
        $btn.prop('disabled', true);

        $.post(ajaxurl, {
            action:  'sap_test_connection',
            nonce:   sapData.nonce,
            network: network,
        })
        .done(function (res) {
            if (res.success) {
                $result.text('✅ ' + res.data).addClass('ok');
            } else {
                $result.text('❌ ' + res.data).addClass('err');
            }
        })
        .fail(function () {
            $result.text('❌ Erreur réseau.').addClass('err');
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    // ── Supprimer un élément (tous statuts) ──────────────────────────────────
    $(document).on('click', '.sap-delete-btn', function () {
        var label = $(this).closest('tr').find('.sap-badge').text().trim() || '';
        var msg   = label.indexOf('Publié') >= 0
            ? 'Supprimer cet enregistrement publié de l\'historique ?'
            : 'Annuler et supprimer cette publication planifiée ?';

        if (!confirm(msg)) return;

        var $btn = $(this);
        var id   = $btn.data('id');

        $btn.prop('disabled', true).text('…');

        $.post(ajaxurl, {
            action: 'sap_delete_item',
            nonce:  sapData.nonce,
            id:     id,
        })
        .done(function (res) {
            if (res.success) {
                $('#sap-row-' + id).fadeOut(280, function () { $(this).remove(); });
            } else {
                $btn.prop('disabled', false).text('🗑 Supprimer');
            }
        })
        .fail(function () {
            $btn.prop('disabled', false).text('🗑 Supprimer');
        });
    });

    // ── Modal – afficher le message complet ──────────────────────────────────
    var $overlay = $('#sap-modal-overlay');
    var $body    = $('#sap-modal-body');
    var $title   = $('#sap-modal-title');
    var $netIcon = $('#sap-modal-network-icon');
    var $copyBtn = $('#sap-modal-copy');

    function openModal(message, network, postTitle) {
        var icon = network === 'linkedin' ? '🔷' : (network === 'facebook' ? '🔵' : '🌐');
        $netIcon.text(icon);
        $title.text(postTitle);
        $body.text(message);
        $copyBtn.text('📋 Copier le message').removeClass('copied');
        $overlay.fadeIn(160);
        $('body').css('overflow', 'hidden');
    }

    function closeModal() {
        $overlay.fadeOut(140);
        $('body').css('overflow', '');
    }

    $(document).on('click', '.sap-view-msg-btn', function () {
        openModal(
            $(this).data('message'),
            $(this).data('network'),
            $(this).data('post')
        );
    });

    $('#sap-modal-close, #sap-modal-close2').on('click', closeModal);

    $overlay.on('click', function (e) {
        if ($(e.target).is($overlay)) closeModal();
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });

    $copyBtn.on('click', function () {
        var text = $body.text();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () {
                $copyBtn.text('✅ Copié !').addClass('copied');
                setTimeout(function () {
                    $copyBtn.text('📋 Copier le message').removeClass('copied');
                }, 2000);
            });
        } else {
            // Fallback ancien navigateur
            var $tmp = $('<textarea>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $tmp.remove();
            $copyBtn.text('✅ Copié !').addClass('copied');
            setTimeout(function () {
                $copyBtn.text('📋 Copier le message').removeClass('copied');
            }, 2000);
        }
    });

})(jQuery);
