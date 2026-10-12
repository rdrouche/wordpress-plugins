/* RDR Newsletter Admin — admin.js  v5
   Tout le code est dans le wrapper (function($){...})(jQuery)
*/
(function($){
    'use strict';

    if (typeof rdrnl === 'undefined') return;

    var nonce   = rdrnl.nonce;
    var ajaxurl = rdrnl.ajaxurl;

    /* ════════════════════════════════════════════
       UTILITAIRES
    ════════════════════════════════════════════ */

    function toast(msg, type) {
        var t = $('#rdrnl-toast');
        t.removeClass('success error').addClass(type || 'success').text(msg).show();
        setTimeout(function(){ t.fadeOut(300); }, 3500);
    }

    function updateCount(count) {
        if (count === undefined) return;
        var c = parseInt(count, 10);
        $('#rdrnl-count-badge, #rdrnl-count-badge-sidebar').text(c + ' items');
        $('#rdrnl-btn-send').prop('disabled', c < 1);
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ════════════════════════════════════════════
       REFRESH DRAFT
    ════════════════════════════════════════════ */

    $('#rdrnl-btn-refresh').on('click', function(){
        var btn = $(this).prop('disabled', true).html('<span class="rdrnl-spinner"></span> Chargement...');
        $.post(ajaxurl, { action: 'rdrnl_refresh_draft', nonce: nonce }, function(res){
            if (res.success) location.reload();
            else {
                toast('Erreur : ' + (res.data || 'inconnue'), 'error');
                btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Refresh');
            }
        });
    });

    /* ════════════════════════════════════════════
       RESET DRAFT
    ════════════════════════════════════════════ */

    $('#rdrnl-btn-reset').on('click', function(){
        if (!confirm('Supprimer le brouillon en cours et repartir de zéro ?\nToutes les sélections et notes seront perdues.')) return;
        var btn = $(this).prop('disabled', true).html('<span class="rdrnl-spinner"></span>');
        $.post(ajaxurl, { action: 'rdrnl_reset_draft', nonce: nonce }, function(res){
            if (res.success) {
                toast('Brouillon supprimé. Rechargement...');
                setTimeout(function(){ location.reload(); }, 1200);
            } else {
                toast('Erreur lors du reset.', 'error');
                btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Reset brouillon');
            }
        });
    });

    /* ════════════════════════════════════════════
       TOGGLE SÉLECTION ITEM
    ════════════════════════════════════════════ */

    $(document).on('change', '.rdrnl-select-item', function(){
        var item = $(this).closest('.rdrnl-item');
        item.toggleClass('rdrnl-item-deselected', !this.checked);
        $.post(ajaxurl, {
            action:    'rdrnl_update_selection',
            nonce:     nonce,
            item_type: item.data('type'),
            item_key:  item.data('key'),
            selected:  this.checked ? 1 : 0
        }, function(res){
            if (res.success) updateCount(res.data.count);
        });
    });

    /* ════════════════════════════════════════════
       NOTE + CATÉGORIE PAR ITEM
    ════════════════════════════════════════════ */

    $(document).on('click', '.rdrnl-toggle-meta', function(){
        $(this).next('.rdrnl-meta-panel').slideToggle(160);
    });

    $(document).on('click', '.rdrnl-save-meta', function(){
        var panel = $(this).closest('.rdrnl-meta-panel');
        var item  = $(this).closest('.rdrnl-item');
        var note  = panel.find('.rdrnl-note-field').val();
        var cat   = panel.find('.rdrnl-cat-field').val();
        var btn   = $(this).prop('disabled', true).text('Saving...');

        $.post(ajaxurl, {
            action:    'rdrnl_update_item_meta',
            nonce:     nonce,
            item_type: item.data('type'),
            item_key:  item.data('key'),
            note:      note,
            category:  cat
        }, function(res){
            btn.prop('disabled', false).text('Save');
            if (res.success) {
                toast('Sauvegardé !');
                var n    = note.length > 80 ? note.substr(0, 80) + '...' : note;
                var lbl  = (note || cat) ? 'Modifier note/catégorie' : 'Ajouter note/catégorie';
                item.find('.rdrnl-toggle-meta').html('<span class="dashicons dashicons-edit-large"></span> ' + lbl);
                var prev = panel.find('.rdrnl-note-preview');
                if (note) {
                    if (prev.length) prev.text(n); else panel.append('<span class="rdrnl-note-preview">' + escHtml(n) + '</span>');
                } else { prev.remove(); }
            } else { toast('Erreur.', 'error'); }
        });
    });

    /* ════════════════════════════════════════════
       COLLAPSE FEED SECTIONS
    ════════════════════════════════════════════ */

    $(document).on('click', '.rdrnl-feed-header', function(){
        $(this).closest('.rdrnl-feed-section').toggleClass('collapsed');
    });

    /* ════════════════════════════════════════════
       SUBJECT + MESSAGE AUTOSAVE
    ════════════════════════════════════════════ */

    var subTimer, msgTimer;

    $('#rdrnl-subject').on('input', function(){
        clearTimeout(subTimer);
        var v = $(this).val();
        subTimer = setTimeout(function(){
            $.post(ajaxurl, { action: 'rdrnl_save_subject', nonce: nonce, subject: v }, function(){
                $('#rdrnl-subject-saved').text('✓').fadeIn(100).delay(1500).fadeOut(400);
            });
        }, 700);
    });

    $('#rdrnl-personal-msg').on('input', function(){
        clearTimeout(msgTimer);
        var v = $(this).val();
        msgTimer = setTimeout(function(){
            $.post(ajaxurl, { action: 'rdrnl_save_message', nonce: nonce, message: v });
        }, 700);
    });

    /* ── Week label — autosave on input ── */
    var weekTimer;
    $('#rdrnl-week-label').on('input', function(){
        clearTimeout(weekTimer);
        var v = $(this).val();
        weekTimer = setTimeout(function(){
            $.post(ajaxurl, { action: 'rdrnl_save_week_label', nonce: nonce, week_label: v }, function(){
                $('#rdrnl-week-label-saved').text('✓').fadeIn(100).delay(1500).fadeOut(400);
            });
        }, 700);
    });

    /* ── Auto button — compute this week's Monday ── */
    $('#rdrnl-btn-week-auto').on('click', function(){
        // Compute Monday of current week in JS
        var now  = new Date();
        var dow  = now.getDay(); // 0=Sun, 1=Mon...
        var diff = (dow === 0) ? -6 : 1 - dow; // Mon = day 1
        var mon  = new Date(now);
        mon.setDate(now.getDate() + diff);
        var months = ['janvier','février','mars','avril','mai','juin',
                      'juillet','août','septembre','octobre','novembre','décembre'];
        var label = 'Semaine du ' + mon.getDate() + ' ' + months[mon.getMonth()] + ' ' + mon.getFullYear();
        $('#rdrnl-week-label').val(label);
        // Save immediately
        $.post(ajaxurl, { action: 'rdrnl_save_week_label', nonce: nonce, week_label: label }, function(){
            $('#rdrnl-week-label-saved').text('✓').fadeIn(100).delay(1500).fadeOut(400);
        });
    });

    /* ════════════════════════════════════════════
       FETCH OG (lien externe)
    ════════════════════════════════════════════ */

    $('#rdrnl-btn-fetch-og').on('click', function(){
        var url = $('#rdrnl-manual-url').val().trim();
        if (!url) { toast('Entrez une URL.', 'error'); return; }
        var btn = $(this).prop('disabled', true).html('<span class="rdrnl-spinner"></span> Chargement...');

        $.post(ajaxurl, { action: 'rdrnl_fetch_og', nonce: nonce, url: url }, function(res){
            btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Fetch info');
            var d = res.success ? res.data : { url: url, title: url, description: '', image: '', site_name: '' };
            $('#rdrnl-og-title').val(d.title || '');
            $('#rdrnl-og-desc').val(d.description || '');
            $('#rdrnl-og-site').text(d.site_name || '');
            if (d.image) $('#rdrnl-og-img').attr('src', d.image).show(); else $('#rdrnl-og-img').hide();
            $('#rdrnl-og-preview, #rdrnl-og-extra').show();
            $('#rdrnl-btn-add-manual').prop('disabled', false);
            if (!res.success) toast('Métadonnées non trouvées. Remplissez manuellement.', 'error');
        });
    });

    /* ════════════════════════════════════════════
       AJOUTER UN LIEN MANUEL
    ════════════════════════════════════════════ */

    $('#rdrnl-btn-add-manual').on('click', function(){
        var url  = $('#rdrnl-manual-url').val().trim();
        var btn  = $(this).prop('disabled', true).html('<span class="rdrnl-spinner"></span> Ajout...');

        $.post(ajaxurl, {
            action:      'rdrnl_add_manual_link',
            nonce:       nonce,
            url:         url,
            title:       $('#rdrnl-og-title').val().trim(),
            description: $('#rdrnl-og-desc').val().trim(),
            note:        $('#rdrnl-manual-note').val().trim(),
            category:    $('#rdrnl-manual-cat').val()
        }, function(res){
            btn.html('<span class="dashicons dashicons-plus"></span> Add to newsletter');
            if (res.success) {
                // Server-rendered HTML includes alt_links panel
                $('#rdrnl-manual-list').append(res.data.html);
                $('#rdrnl-manual-url').val('');
                $('#rdrnl-og-preview, #rdrnl-og-extra').hide();
                $('#rdrnl-manual-note').val('');
                $('#rdrnl-manual-cat').val('');
                updateCount(res.data.count);
                var n = parseInt($('#rdrnl-manual-count').text(), 10) + 1;
                $('#rdrnl-manual-count').text(n + ' ajouté' + (n > 1 ? 's' : ''));
                toast('Lien ajouté !');
            } else {
                toast(res.data || 'Erreur.', 'error');
                btn.prop('disabled', false);
            }
        });
    });

    /* ════════════════════════════════════════════
       SUPPRIMER UN LIEN MANUEL
    ════════════════════════════════════════════ */

    $(document).on('click', '.rdrnl-remove-manual', function(){
        var url = $(this).data('url') || $(this).closest('.rdrnl-item').data('key');
        var row = $(this).closest('.rdrnl-item');
        $.post(ajaxurl, { action: 'rdrnl_remove_manual', nonce: nonce, url: url }, function(res){
            if (res.success) { row.remove(); updateCount(res.data.count); toast('Supprimé.'); }
        });
    });

    /* ════════════════════════════════════════════
       LIENS ALTERNATIFS (alt_links)
    ════════════════════════════════════════════ */

    $(document).on('click', '.rdrnl-toggle-alt', function(){
        $(this).next('.rdrnl-alt-panel').slideToggle(160);
    });

    $(document).on('click', '.rdrnl-alt-add-row', function(){
        var list = $(this).closest('.rdrnl-alt-panel').find('.rdrnl-alt-list');
        list.append(
            '<div class="rdrnl-alt-row">'
            + '<input type="url"  class="rdrnl-alt-url"   placeholder="https://...">'
            + '<input type="text" class="rdrnl-alt-label" placeholder="Label (ex: LeMonde)">'
            + '<button type="button" class="rdrnl-alt-remove button-link"><span class="dashicons dashicons-no-alt"></span></button>'
            + '</div>'
        );
        list.find('.rdrnl-alt-row:last .rdrnl-alt-url').focus();
    });

    $(document).on('click', '.rdrnl-alt-remove', function(){
        $(this).closest('.rdrnl-alt-row').remove();
    });

    $(document).on('click', '.rdrnl-alt-save', function(){
        var panel = $(this).closest('.rdrnl-alt-panel');
        var item  = $(this).closest('.rdrnl-item');
        var btn   = $(this).prop('disabled', true).text('Saving...');
        var links = [];

        panel.find('.rdrnl-alt-row').each(function(){
            var url = $(this).find('.rdrnl-alt-url').val().trim();
            var lbl = $(this).find('.rdrnl-alt-label').val().trim();
            if (url) links.push({ url: url, label: lbl });
        });

        $.post(ajaxurl, {
            action:    'rdrnl_update_alt_links',
            nonce:     nonce,
            item_type: item.data('type'),
            item_key:  item.data('key'),
            alt_links: links
        }, function(res){
            btn.prop('disabled', false).text('Enregistrer');
            if (res.success) {
                toast('Liens alternatifs sauvegardés.');
                var n   = links.length;
                var lbl = n > 0 ? n + ' lien' + (n > 1 ? 's' : '') + ' alternatif' + (n > 1 ? 's' : '') : 'Ajouter des liens alternatifs';
                item.find('.rdrnl-toggle-alt').html('<span class="dashicons dashicons-share-alt2"></span> ' + lbl);
            } else { toast(res.data || 'Erreur.', 'error'); }
        });
    });

    /* ════════════════════════════════════════════
       PREVIEW
    ════════════════════════════════════════════ */

    $('#rdrnl-btn-preview').on('click', function(){
        var btn = $(this).prop('disabled', true);
        $.post(ajaxurl, { action: 'rdrnl_preview', nonce: nonce }, function(res){
            btn.prop('disabled', false);
            if (res.success) {
                document.getElementById('rdrnl-preview-frame').srcdoc = res.data.html;
                $('#rdrnl-preview-modal').show();
            } else { toast('Prévisualisation impossible.', 'error'); }
        });
    });

    $(document).on('click', '.rdrnl-modal-close, .rdrnl-modal-overlay', function(){
        $('#rdrnl-preview-modal').hide();
    });

    /* ════════════════════════════════════════════
       ENVOI TEST + ENVOI RÉEL
    ════════════════════════════════════════════ */

    $('#rdrnl-btn-test').on('click', function(){
        var email = $('#rdrnl-test-email').val().trim();
        if (!email) { toast('Entrez un email de test.', 'error'); return; }
        var btn = $(this).prop('disabled', true).html('<span class="rdrnl-spinner"></span> Envoi...');
        $.post(ajaxurl, { action: 'rdrnl_send_test', nonce: nonce, email: email }, function(res){
            btn.prop('disabled', false).html('<span class="dashicons dashicons-email"></span> Send Test');
            res.success ? toast(res.data || 'Test envoyé !') : toast(res.data || 'Échec.', 'error');
        });
    });

    $('#rdrnl-btn-send').on('click', function(){
        if (!confirm('Envoyer la newsletter à tous les abonnés actifs maintenant ?')) return;
        var btn = $(this).prop('disabled', true).html('<span class="rdrnl-spinner"></span> Envoi en cours...');
        $.post(ajaxurl, { action: 'rdrnl_send_now', nonce: nonce }, function(res){
            if (res.success) {
                toast(res.data || 'Newsletter envoyée !');
                rdrnlStartQueuePolling(); // Start polling immediately
            } else {
                toast(res.data || 'Échec.', 'error');
                btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Send to All');
            }
        });
    });

    /* ════════════════════════════════════════════
       QUEUE STATUS POLLING
    ════════════════════════════════════════════ */

    var queuePollTimer = null;

    function rdrnlStartQueuePolling() {
        if (queuePollTimer) return;
        queuePollTimer = setInterval(rdrnlPollQueue, 5000);
        rdrnlPollQueue();
    }

    function rdrnlPollQueue() {
        $.post(ajaxurl, { action: 'rdrnl_queue_status', nonce: nonce }, function(res){
            if (!res.success) return;
            var s = res.data;
            if (!s || !s.active) {
                clearInterval(queuePollTimer);
                queuePollTimer = null;
                $('#rdrnl-queue-status').remove();
                return;
            }
            var $qs = $('#rdrnl-queue-status');
            if (!$qs.length) {
                $qs = $('<div id="rdrnl-queue-status" class="rdrnl-queue-status">'
                    + '<div class="rdrnl-queue-info"></div>'
                    + '<div class="rdrnl-queue-bar-wrap"><div class="rdrnl-queue-bar" style="width:0%"></div></div>'
                    + '</div>');
                $('.rdrnl-toolbar').after($qs);
            }
            $qs.find('.rdrnl-queue-info').html(
                '📤 Envoi en cours — <strong>' + s.done + ' / ' + s.total + '</strong> destinataires'
                + (s.errors > 0 ? ' <span style="color:#f85149">(' + s.errors + ' erreur' + (s.errors > 1 ? 's' : '') + ')</span>' : '')
            );
            $qs.find('.rdrnl-queue-bar').css('width', s.pct + '%');
        });
    }

    // Auto-start polling on load if a queue is already active
    rdrnlPollQueue();

    /* ════════════════════════════════════════════
       PAGE ABONNÉS
    ════════════════════════════════════════════ */

    function updateSubStats(data) {
        if (data && data.active   !== undefined) $('#stat-active').text(data.active);
        if (data && data.waitlist !== undefined) $('#stat-waitlist').text(data.waitlist);
    }

    $(document).on('click', '.rdrnl-sub-activate', function(){
        var id = $(this).data('id');
        $.post(ajaxurl, { action: 'rdrnl_sub_status', nonce: nonce, id: id, status: 'active' }, function(res){
            if (res.success) {
                updateSubStats(res.data);
                $('#sub-row-' + id + ' td:nth-child(4)').html('<span class="rdrnl-log-type rdrnl-log-active">active</span>');
                toast('Abonné activé.');
            }
        });
    });

    $(document).on('click', '.rdrnl-sub-unsub', function(){
        var id = $(this).data('id');
        $.post(ajaxurl, { action: 'rdrnl_sub_status', nonce: nonce, id: id, status: 'unsubscribed' }, function(res){
            if (res.success) {
                updateSubStats(res.data);
                $('#sub-row-' + id + ' td:nth-child(4)').html('<span class="rdrnl-log-type rdrnl-log-unsubscribed">unsubscribed</span>');
                toast('Désinscrit.');
            }
        });
    });

    $(document).on('click', '.rdrnl-sub-del', function(){
        if (!confirm('Supprimer cet abonné ?')) return;
        var id = $(this).data('id');
        $.post(ajaxurl, { action: 'rdrnl_sub_delete', nonce: nonce, id: id }, function(res){
            if (res.success) { updateSubStats(res.data); $('#sub-row-' + id).remove(); toast('Supprimé.'); }
        });
    });

    $('#rdrnl-promote-btn').on('click', function(){
        $.post(ajaxurl, { action: 'rdrnl_sub_promote', nonce: nonce }, function(res){
            if (res.success) { updateSubStats(res.data); toast(res.data.email + ' promu(e) !'); location.reload(); }
            else toast(res.data || 'Erreur.', 'error');
        });
    });

    $('#rdrnl-add-sub-btn').on('click', function(){
        var email = $('#rdrnl-add-email').val().trim();
        var name  = $('#rdrnl-add-name').val().trim();
        if (!email) { toast('Entrez un email.', 'error'); return; }
        $.post(ajaxurl, { action: 'rdrnl_sub_add_manual', nonce: nonce, email: email, name: name }, function(res){
            if (res.success) {
                updateSubStats(res.data);
                var msg = res.data.status === 'waitlist' ? 'Ajouté en waitlist.' : 'Ajouté comme actif.';
                $('#rdrnl-add-sub-msg').text(msg);
                $('#rdrnl-add-email, #rdrnl-add-name').val('');
                toast(msg);
            } else toast(res.data || 'Erreur.', 'error');
        });
    });

    /* ════════════════════════════════════════════
       ARTICLE PICKER MODAL
    ════════════════════════════════════════════ */

    var pickerSelected = null;

    $(document).on('click', '#rdrnl-btn-add-post', function(){
        pickerSelected = null;
        $('#rdrnl-picker-meta').hide();
        $('#rdrnl-picker-results').html('<p class="rdrnl-picker-hint">Tapez au moins 2 caractères pour chercher...</p>');
        $('#rdrnl-picker-q').val('');
        $('#rdrnl-picker-modal').show();
        setTimeout(function(){ $('#rdrnl-picker-q').focus(); }, 100);
    });

    var pickerTimer;
    $(document).on('input', '#rdrnl-picker-q', function(){
        clearTimeout(pickerTimer);
        var q = $(this).val().trim();
        if (q.length < 2) {
            $('#rdrnl-picker-results').html('<p class="rdrnl-picker-hint">Tapez au moins 2 caractères...</p>');
            return;
        }
        pickerTimer = setTimeout(function(){ rdrnlSearchPosts(q); }, 350);
    });

    function rdrnlSearchPosts(q) {
        $('#rdrnl-picker-spinner').show();
        $.post(ajaxurl, { action: 'rdrnl_search_posts', nonce: nonce, q: q }, function(res){
            $('#rdrnl-picker-spinner').hide();
            if (!res.success || !res.data.length) {
                $('#rdrnl-picker-results').html('<p class="rdrnl-picker-hint">Aucun résultat pour «&nbsp;' + escHtml(q) + '&nbsp;»</p>');
                return;
            }
            var html = '';
            $.each(res.data, function(i, p){
                var img = p.image
                    ? '<img src="' + escHtml(p.image) + '" alt="">'
                    : '<div class="rdrnl-picker-thumb-placeholder">📄</div>';
                html += '<div class="rdrnl-picker-item"'
                    + ' data-id="'    + p.id        + '"'
                    + ' data-title="' + escHtml(p.title) + '"'
                    + ' data-url="'   + escHtml(p.url)   + '"'
                    + ' data-date="'  + escHtml(p.date)  + '"'
                    + ' data-type="'  + escHtml(p.post_type) + '"'
                    + ' data-image="' + escHtml(p.image || '') + '">'
                    + img
                    + '<div class="rdrnl-picker-item-body">'
                    + '<div class="rdrnl-picker-item-title">' + escHtml(p.title) + '</div>'
                    + '<div class="rdrnl-picker-item-meta">' + escHtml(p.post_type) + ' · ' + escHtml(p.date) + '</div>'
                    + '</div></div>';
            });
            $('#rdrnl-picker-results').html(html);
        }).fail(function(){ $('#rdrnl-picker-spinner').hide(); });
    }

    $(document).on('click', '.rdrnl-picker-item', function(){
        $('.rdrnl-picker-item').removeClass('selected');
        $(this).addClass('selected');
        pickerSelected = {
            id:    $(this).data('id'),
            title: $(this).data('title'),
            url:   $(this).data('url'),
            date:  $(this).data('date'),
            type:  $(this).data('type'),
            image: $(this).data('image') || ''
        };
        var imgHtml = pickerSelected.image
            ? '<img src="' + escHtml(pickerSelected.image) + '" alt="">'
            : '<div class="rdrnl-no-thumb" style="width:50px;height:38px;font-size:16px">📄</div>';
        $('#rdrnl-picker-selected-preview').html(
            imgHtml
            + '<div><div class="rdrnl-ps-title">' + escHtml(pickerSelected.title) + '</div>'
            + '<div class="rdrnl-ps-meta">' + escHtml(pickerSelected.type) + ' · ' + escHtml(pickerSelected.date) + '</div></div>'
        );
        $('#rdrnl-picker-meta').show();
        $('#rdrnl-picker-note').val('').focus();
    });

    $('#rdrnl-picker-add').on('click', function(){
        if (!pickerSelected) return;
        var btn = $(this).prop('disabled', true).html('<span class="rdrnl-spinner"></span> Ajout...');
        $.post(ajaxurl, {
            action:      'rdrnl_add_manual_link',
            nonce:       nonce,
            url:         pickerSelected.url,
            title:       pickerSelected.title,
            note:        $('#rdrnl-picker-note').val().trim(),
            category:    $('#rdrnl-picker-cat').val(),
            description: ''
        }, function(res){
            btn.prop('disabled', false).html('<span class="dashicons dashicons-plus"></span> Ajouter à la newsletter');
            if (res.success) {
                $('#rdrnl-manual-list').append(res.data.html);
                updateCount(res.data.count);
                var n = parseInt($('#rdrnl-manual-count').text(), 10) + 1;
                $('#rdrnl-manual-count').text(n + ' ajouté' + (n > 1 ? 's' : ''));
                $('#rdrnl-picker-modal').hide();
                toast('Article ajouté !');
            } else { toast(res.data || 'Erreur.', 'error'); btn.prop('disabled', false); }
        });
    });

    /* ════════════════════════════════════════════
       SETTINGS — Ajout/suppression flux RSS
    ════════════════════════════════════════════ */

    // Feed management — only one handler here (no inline script in admin.php)
    $(document).on('click', '#rdrnl-add-feed', function(){
        var idx = $('#rdrnl-feeds-list .rdrnl-feed-row').length;
        var row = $('<div class="rdrnl-feed-row">'
            + '<label class="rdrnl-feed-active" title="Activer/désactiver">'
            +   '<input type="checkbox" name="feed_active[' + idx + ']" value="1" checked>'
            + '</label>'
            + '<input type="url"    name="feed_url['   + idx + ']" placeholder="https://example.com/feed" class="regular-text">'
            + '<input type="text"   name="feed_label[' + idx + ']" placeholder="Label (ex: Korben)" style="width:140px">'
            + '<input type="number" name="feed_limit[' + idx + ']" value="5" min="1" max="20" style="width:60px" title="Nb articles">'
            + '<button type="button" class="button rdrnl-remove-feed" title="Supprimer">'
            +   '<span class="dashicons dashicons-trash"></span>'
            + '</button>'
            + '</div>');
        $('#rdrnl-feeds-list').append(row);
        row.find('input[type="url"]').focus();
    });

    $(document).on('click', '.rdrnl-remove-feed', function(){
        $(this).closest('.rdrnl-feed-row').remove();
    });

    /* ════════════════════════════════════════════
       INIT
    ════════════════════════════════════════════ */

    // Déselected state initial
    $('.rdrnl-select-item').each(function(){
        $(this).closest('.rdrnl-item').toggleClass('rdrnl-item-deselected', !this.checked);
    });

})(jQuery);
