/**
 * RDR Expert Directory — Admin JS
 * Actions : valider, refuser, toggle statut, compétences en attente
 */
(function ($) {
    'use strict';

    const ajax = RDR_ED_Admin.ajaxurl;
    const nonce = RDR_ED_Admin.nonce;

    /* ── Valider une révision ── */
    $(document).on('click', '.rdr-validate-btn', function () {
        const $btn   = $(this);
        const postId = $btn.data('post');

        if (!confirm('Valider et publier cette révision ?')) return;

        $btn.prop('disabled', true).text('⏳ Validation…');

        $.post(ajax, {
            action:  'rdr_ed_validate',
            nonce:   nonce,
            post_id: postId,
        }, function (res) {
            if (res.success) {
                showNotice(res.data.message, 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                showNotice(res.data || 'Erreur.', 'error');
                $btn.prop('disabled', false).text('✅ Valider');
            }
        });
    });

    /* ── Afficher le champ motif de refus ── */
    $(document).on('click', '.rdr-reject-btn', function () {
        $('#rdr-reject-reason').slideToggle(200);
    });

    /* ── Confirmer le refus ── */
    $(document).on('click', '.rdr-reject-confirm', function () {
        const $btn   = $(this);
        const postId = $btn.data('post');
        const reason = $('#rdr-reject-text').val().trim();

        $btn.prop('disabled', true).text('⏳ Envoi…');

        $.post(ajax, {
            action:  'rdr_ed_reject',
            nonce:   nonce,
            post_id: postId,
            reason:  reason,
        }, function (res) {
            if (res.success) {
                showNotice(res.data.message, 'success');
                setTimeout(() => location.reload(), 1200);
            } else {
                showNotice(res.data || 'Erreur.', 'error');
                $btn.prop('disabled', false).text('Confirmer le refus');
            }
        });
    });

    /* ── Toggle statut actif / inactif ── */
    $(document).on('click', '.rdr-toggle-status', function () {
        const $btn   = $(this);
        const postId = $btn.data('post');
        const btnNonce = $btn.data('nonce');

        $btn.prop('disabled', true).text('⏳ …');

        $.post(ajax, {
            action:  'rdr_ed_toggle_status',
            nonce:   btnNonce || nonce,
            post_id: postId,
        }, function (res) {
            if (res.success) {
                const newStatus = res.data.status;
                const $badge = $btn.closest('.rdr-ed-workflow-box').find('.rdr-status-badge');

                $badge
                    .removeClass('rdr-status-active rdr-status-inactive')
                    .addClass('rdr-status-' + newStatus)
                    .text(newStatus === 'active' ? '✅ Active' : '🔴 Inactive');

                $btn.prop('disabled', false)
                    .text(newStatus === 'active' ? 'Désactiver la fiche' : 'Réactiver la fiche');

                showNotice(
                    newStatus === 'active' ? 'Fiche réactivée.' : 'Fiche désactivée.',
                    'success'
                );
            } else {
                $btn.prop('disabled', false).text('Modifier le statut');
                showNotice('Erreur lors du changement de statut.', 'error');
            }
        });
    });

    /* ── Compétences en attente de validation ── */
    $(document).on('click', '#rdr-load-pending-skills', function () {
        const $btn  = $(this);
        const btnNonce = $btn.data('nonce');
        const $list = $('#rdr-pending-skills-list');

        $btn.prop('disabled', true).text('Chargement…');

        $.post(ajax, {
            action: 'rdr_ed_load_pending_skills',
            nonce:  btnNonce || nonce,
        }, function (res) {
            $btn.prop('disabled', false).text('Actualiser');
            if (!res.success) return;

            const rows = res.data.rows;
            if (!rows.length) {
                $list.html('<p>Aucune compétence en attente.</p>');
                return;
            }

            let html = '<table class="wp-list-table widefat striped" style="margin-bottom:0;">';
            html += '<thead><tr><th>Compétence</th><th>Proposée par</th><th>Date</th><th>Actions</th></tr></thead><tbody>';
            rows.forEach(row => {
                html += `<tr data-key="${row.key}">
                    <td><strong>${escHtml(row.name)}</strong></td>
                    <td>${escHtml(row.user)}</td>
                    <td>${escHtml(row.submitted)}</td>
                    <td>
                        <button type="button" class="button button-primary button-small rdr-approve-skill" data-key="${row.key}">✅ Approuver</button>
                        <button type="button" class="button button-small rdr-reject-skill" data-key="${row.key}" style="margin-left:4px;">❌ Refuser</button>
                    </td>
                </tr>`;
            });
            html += '</tbody></table>';
            $list.html(html);
        });
    });

    /* ── Approuver une compétence ── */
    $(document).on('click', '.rdr-approve-skill', function () {
        const $btn = $(this);
        const key  = $btn.data('key');
        $btn.prop('disabled', true).text('⏳…');

        $.post(ajax, {
            action: 'rdr_ed_approve_skill',
            nonce:  nonce,
            key:    key,
        }, function (res) {
            if (res.success) {
                $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
                showNotice(res.data.message, 'success');
            } else {
                $btn.prop('disabled', false).text('✅ Approuver');
                showNotice(res.data.message || 'Erreur.', 'error');
            }
        });
    });

    /* ── Refuser une compétence ── */
    $(document).on('click', '.rdr-reject-skill', function () {
        const $btn = $(this);
        const key  = $btn.data('key');
        $btn.prop('disabled', true).text('⏳…');

        $.post(ajax, {
            action: 'rdr_ed_reject_skill',
            nonce:  nonce,
            key:    key,
        }, function (res) {
            if (res.success) {
                $btn.closest('tr').fadeOut(300, function () { $(this).remove(); });
                showNotice('Compétence refusée.', 'info');
            } else {
                $btn.prop('disabled', false).text('❌ Refuser');
                showNotice('Erreur.', 'error');
            }
        });
    });

    /* ── Utilitaires ── */
    function showNotice(msg, type) {
        const $n = $('<div>')
            .addClass('notice notice-' + (type === 'success' ? 'success' : type === 'info' ? 'info' : 'error') + ' is-dismissible')
            .html('<p>' + msg + '</p>');
        $('.wrap h1').first().after($n);
        setTimeout(() => $n.fadeOut(400, function () { $(this).remove(); }), 3000);
    }

    function escHtml(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

})(jQuery);
