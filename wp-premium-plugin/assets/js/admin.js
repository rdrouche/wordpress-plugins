/**
 * WP Premium Access — Admin JS
 */
(function ($) {
    'use strict';

    const cfg   = window.WPA_Admin || {};
    const nonce = cfg.nonce   || '';
    const ajax  = cfg.ajaxUrl || '';
    const i18n  = cfg.i18n   || {};

    // =========================================================================
    // Tabs — Roles page
    // =========================================================================

    $(document).on('click', '.wpa-tab', function () {
        const tab = $(this).data('tab');
        $('.wpa-tab').removeClass('active');
        $('.wpa-tab-content').removeClass('active');
        $(this).addClass('active');
        $('#tab-' + tab).addClass('active');
    });

    // =========================================================================
    // Permission cards — toggle checked state
    // =========================================================================

    $(document).on('change', '.wpa-perm-input', function () {
        $(this).closest('.wpa-perm-card').toggleClass('is-checked', this.checked);
    });

    // =========================================================================
    // Save role permissions
    // =========================================================================

    $(document).on('click', '#wpa-save-role', function () {
        const $btn      = $(this);
        const role      = $btn.data('role');
        const $feedback = $('#wpa-save-feedback');

        const permissions = $('.wpa-perm-input:checked')
            .map(function () { return $(this).data('perm'); }).get();

        $btn.prop('disabled', true);
        $feedback.text('').removeClass('wpa-feedback--ok wpa-feedback--error');

        $.post(ajax, { action: 'wpa_save_role', nonce, role, permissions })
        .done(function (res) {
            if (res.success) {
                $feedback.text(i18n.saved || 'Saved.').addClass('wpa-feedback--ok');
            } else {
                $feedback.text(res.data || i18n.error).addClass('wpa-feedback--error');
            }
        })
        .fail(function () {
            $feedback.text(i18n.error).addClass('wpa-feedback--error');
        })
        .always(function () {
            $btn.prop('disabled', false);
            setTimeout(() => $feedback.text('').removeClass('wpa-feedback--ok wpa-feedback--error'), 3000);
        });
    });

    // =========================================================================
    // New role — show/hide form
    // =========================================================================

    $('#wpa-show-new-role').on('click', function () {
        $('#wpa-new-role-form').addClass('visible');
        $('#new-role-slug').focus();
    });

    $('#wpa-cancel-new-role').on('click', function () {
        $('#wpa-new-role-form').removeClass('visible');
        $('#new-role-slug').val('');
        $('#wpa-create-feedback').text('');
    });

    $('#wpa-create-role').on('click', function () {
        const $btn      = $(this);
        const role      = $('#new-role-slug').val().trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');
        const $feedback = $('#wpa-create-feedback');

        if (!role) return;

        $btn.prop('disabled', true);

        $.post(ajax, { action: 'wpa_save_role', nonce, role, permissions: [] })
        .done(function (res) {
            if (res.success) {
                // Redirect to new role
                window.location.href = `${window.location.pathname}?page=wpa-roles&role=${encodeURIComponent(role)}`;
            } else {
                $feedback.text(res.data || i18n.error).addClass('wpa-feedback--error');
                $btn.prop('disabled', false);
            }
        })
        .fail(function () {
            $feedback.text(i18n.error).addClass('wpa-feedback--error');
            $btn.prop('disabled', false);
        });
    });

    // =========================================================================
    // Delete role
    // =========================================================================

    $(document).on('click', '#wpa-delete-role', function () {
        const role = $(this).data('role');
        if (!confirm(i18n.confirm_delete || 'Delete this role?')) return;

        $.post(ajax, { action: 'wpa_delete_role', nonce, role })
        .done(function (res) {
            if (res.success) {
                window.location.href = `${window.location.pathname}?page=wpa-roles`;
            } else {
                alert(res.data || i18n.error);
            }
        });
    });

    // =========================================================================
    // Grant user role — Users tab
    // =========================================================================

    $(document).on('click', '#wpa-grant-user', function () {
        const $btn      = $(this);
        const role      = $btn.data('role');
        const email     = $('#grant-email').val().trim();
        const expires   = $('#grant-expires').val();
        const $feedback = $('#wpa-grant-feedback');

        if (!email) {
            $feedback.text('Email required.').addClass('wpa-feedback--error');
            return;
        }

        $btn.prop('disabled', true);
        $feedback.text('').removeClass('wpa-feedback--ok wpa-feedback--error');

        $.post(ajax, { action: 'wpa_grant_user_role', nonce, email, role, expires })
        .done(function (res) {
            if (res.success) {
                const d   = res.data;
                const exp = d.expires
                    ? `Expires ${d.expires.split(' ')[0]}`
                    : 'Lifetime';

                const $row = $(`<div class="wpa-user-row">
                    <div class="wpa-avatar">${d.initials}</div>
                    <span class="wpa-user-email">${d.email}</span>
                    <span class="wpa-user-expiry">${exp}</span>
                    <button class="wpa-btn wpa-btn--danger wpa-revoke-user"
                            data-user-id="${d.user_id}" data-role="${role}">Revoke</button>
                </div>`);

                // Add to list or create list
                if ($('.wpa-users-list').length) {
                    $('.wpa-users-list').append($row);
                } else {
                    $('#tab-users').prepend('<div class="wpa-users-list"></div>');
                    $('.wpa-users-list').append($row);
                }

                $('#grant-email').val('');
                $('#grant-expires').val('');
                $feedback.text(i18n.saved || 'Granted.').addClass('wpa-feedback--ok');
            } else {
                $feedback.text(res.data || i18n.error).addClass('wpa-feedback--error');
            }
        })
        .fail(function () {
            $feedback.text(i18n.error).addClass('wpa-feedback--error');
        })
        .always(function () {
            $btn.prop('disabled', false);
            setTimeout(() => $feedback.text('').removeClass('wpa-feedback--ok wpa-feedback--error'), 4000);
        });
    });

    // =========================================================================
    // Revoke user role — Users tab
    // =========================================================================

    $(document).on('click', '.wpa-revoke-user', function () {
        const $btn    = $(this);
        const userId  = $btn.data('user-id');
        const role    = $btn.data('role');

        if (!confirm('Revoke this role?')) return;

        $.post(ajax, { action: 'wpa_revoke_user_role', nonce, user_id: userId, role })
        .done(function (res) {
            if (res.success) {
                $btn.closest('.wpa-user-row').fadeOut(200, function () { $(this).remove(); });
            } else {
                alert(res.data || i18n.error);
            }
        });
    });

    // =========================================================================
    // Capabilities resync
    // =========================================================================

    $(document).on('click', '#wpa-resync-caps', function () {
        const $btn      = $(this);
        const $feedback = $('#wpa-resync-feedback');

        $btn.prop('disabled', true);
        $feedback.text('Syncing...').removeClass('wpa-feedback--ok wpa-feedback--error');

        $.post(ajax, { action: 'wpa_resync_capabilities', nonce })
        .done(function (res) {
            if (res.success) {
                $feedback.text(res.data.message).addClass('wpa-feedback--ok');
            } else {
                $feedback.text(res.data || i18n.error).addClass('wpa-feedback--error');
            }
        })
        .fail(function () { $feedback.text(i18n.error).addClass('wpa-feedback--error'); })
        .always(function () {
            $btn.prop('disabled', false);
            setTimeout(() => $feedback.text('').removeClass('wpa-feedback--ok wpa-feedback--error'), 5000);
        });
    });

    // =========================================================================
    // Webhook simulator — show/hide extra fields
    // =========================================================================

    $('#sim-type').on('change', function () {
        const t = $(this).val();
        $('#sim-tiername-row').toggle(t === 'Donation' || t === 'Subscription' || t === 'Subscription_renewal');
        $('#sim-shopcode-row').toggle(t === 'Shop Order');
    });

    $('#wpa-simulate-btn').on('click', function () {
        const $btn      = $(this);
        const $feedback = $('#wpa-sim-feedback');
        const type      = $('#sim-type').val();
        const email     = $('#sim-email').val().trim();
        const amount    = $('#sim-amount').val();
        const tiername  = $('#sim-tiername').val().trim();
        const shopcode  = $('#sim-shopcode').val().trim();

        if (!email) { $feedback.text('Email required.').css('color','#dc2626'); return; }

        $btn.prop('disabled', true);
        $feedback.text(i18n.simulating || 'Sending...').css('color','#6b7280');

        $.post(ajax, { action: 'wpa_simulate_webhook', nonce, type, email, amount, tiername, shopcode })
        .done(function (res) {
            if (res.success) {
                $feedback.text(i18n.sim_ok || 'Done.').css('color','#16a34a');
            } else {
                $feedback.text((i18n.sim_error || 'Error') + ': ' + JSON.stringify(res.data)).css('color','#dc2626');
            }
        })
        .fail(function () { $feedback.text(i18n.sim_error || 'Error').css('color','#dc2626'); })
        .always(function () {
            $btn.prop('disabled', false);
            setTimeout(() => $feedback.text(''), 5000);
        });
    });

    // =========================================================================
    // Dynamic tier rows — Settings & Stripe
    // =========================================================================

    function makeRoles() {
        return (window.WPA_Admin_Roles || []).map(r => `<option value="${r}">${r}</option>`).join('');
    }

    function tierRow(prefix, i) {
        return `<div class="wpa-tier-row">
            <span>Min</span>
            <input type="number" name="${prefix}[${i}][min]" value="" min="0" step="1" style="width:65px"> &euro;
            <span>Role</span>
            <select name="${prefix}[${i}][role]">${makeRoles()}</select>
            <span>Duration</span>
            <input type="number" name="${prefix}[${i}][months]" placeholder="months" min="1" style="width:65px">
            <span class="description">(empty = lifetime)</span>
            <button type="button" class="button wpa-remove-tier">&times;</button>
        </div>`;
    }

    function tierNameRow(i) {
        return `<div class="wpa-tier-row">
            <span>Tier name</span>
            <input type="text" name="wpa_kofi_tier_names[new_${i}][_name]" value="" style="width:150px" placeholder="Gold Member">
            <span>Role</span>
            <select name="wpa_kofi_tier_names[new_${i}][role]">${makeRoles()}</select>
            <span>Duration</span>
            <input type="number" name="wpa_kofi_tier_names[new_${i}][months]" placeholder="months" min="1" style="width:65px">
            <span class="description">(empty = lifetime)</span>
            <button type="button" class="button wpa-remove-tier">&times;</button>
        </div>`;
    }

    function shopRow(i) {
        return `<div class="wpa-tier-row">
            <span>Code</span>
            <input type="text" name="wpa_shop_items[new_${i}][_code]" value="" style="width:130px" placeholder="abc123">
            <span>Role</span>
            <select name="wpa_shop_items[new_${i}][role]">${makeRoles()}</select>
            <span>Duration</span>
            <input type="number" name="wpa_shop_items[new_${i}][months]" placeholder="months" min="1" style="width:65px">
            <span class="description">(empty = lifetime)</span>
            <button type="button" class="button wpa-remove-tier">&times;</button>
        </div>`;
    }

    function stripeProductRow(i) {
        return `<div class="wpa-tier-row">
            <span>Price ID</span>
            <input type="text" name="wpa_stripe_products[new_${i}][_price_id]" value="" style="width:190px" placeholder="price_xxx">
            <span>Mode</span>
            <select name="wpa_stripe_products[new_${i}][mode]">
                <option value="payment">One-time</option>
                <option value="subscription">Subscription</option>
            </select>
            <span>Role</span>
            <select name="wpa_stripe_products[new_${i}][role]">${makeRoles()}</select>
            <span>Duration</span>
            <input type="number" name="wpa_stripe_products[new_${i}][months]" placeholder="months" min="1" style="width:65px">
            <span class="description">(empty = lifetime)</span>
            <button type="button" class="button wpa-remove-tier">&times;</button>
        </div>`;
    }

    let idx = { tier: 100, name: 100, shop: 100, stripe: 100 };

    $('#wpa-add-tier').on('click',           () => $('#wpa-tiers-wrap').append(tierRow('wpa_kofi_tiers', idx.tier++)));
    $('#wpa-add-tiername').on('click',       () => $('#wpa-tiernames-wrap').append(tierNameRow(idx.name++)));
    $('#wpa-add-shop').on('click',           () => $('#wpa-shop-wrap').append(shopRow(idx.shop++)));
    $('#wpa-add-stripe-product').on('click', () => $('#wpa-stripe-products-wrap').append(stripeProductRow(idx.stripe++)));
    $(document).on('click', '.wpa-remove-tier', function () { $(this).closest('.wpa-tier-row').remove(); });

    // =========================================================================
    // Manual email — target toggle
    // =========================================================================

    $('#manual-target-type').on('change', function () {
        const isEmail = $(this).val() === 'email';
        $('#manual-role-row').toggle(!isEmail);
        $('#manual-email-row').toggle(isEmail);
    });

    $('#wpa-send-manual').on('click', function () {
        const $btn      = $(this);
        const $feedback = $('#wpa-manual-feedback');
        const type      = $('#manual-target-type').val();
        const target    = type === 'email' ? $('#manual-email').val() : $('#manual-role').val();
        const subject   = $('#manual-subject').val().trim();
        const body      = $('#manual-body').val().trim();
        const format    = $('#manual-format').val();

        if (!subject || !body || !target) {
            $feedback.text('Subject, body and target required.').css('color','#dc2626');
            return;
        }
        if (!confirm(`Send to: ${target}?`)) return;

        $btn.prop('disabled', true);
        $feedback.text('Sending...').css('color','#6b7280');

        $.post(ajax, { action: 'wpa_send_manual_email', nonce, target_type: type, target, subject, body, format })
        .done(function (res) {
            if (res.success) {
                $feedback.text(res.data.message).css('color','#16a34a');
            } else {
                $feedback.text(res.data || i18n.error).css('color','#dc2626');
            }
        })
        .fail(function () { $feedback.text(i18n.error).css('color','#dc2626'); })
        .always(function () { $btn.prop('disabled', false); });
    });

    // =========================================================================
    // Email preview modal
    // =========================================================================

    $(document).on('click', '.wpa-preview-email', function () {
        const type     = $(this).data('type');
        const $section = $(this).closest('table');
        const subject  = $section.find('input[name*="subject"]').val();
        const body     = $section.find('textarea[name*="body"]').val();
        const format   = $section.find('select[name*="format"]').val() || 'html';

        $.post(ajax, { action: 'wpa_preview_email', nonce, type, subject, body, format })
        .done(function (res) {
            if (res.success) {
                $('#wpa-email-preview-modal').css('display','flex');
                document.getElementById('wpa-preview-frame').srcdoc = res.data.html;
            }
        });
    });

    $('#wpa-close-preview').on('click', function () {
        $('#wpa-email-preview-modal').hide();
        document.getElementById('wpa-preview-frame').srcdoc = '';
    });
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') $('#wpa-email-preview-modal').hide();
    });

    // =========================================================================
    // Copy webhook URL to clipboard
    // =========================================================================

    $(document).on('click', '.wpa-copyable', function () {
        navigator.clipboard?.writeText($(this).text()).then(() => {
            const $el = $(this);
            $el.after('<span class="wpa-copied"> &#10003; Copied</span>');
            setTimeout(() => $el.next('.wpa-copied').remove(), 2000);
        });
    });


    // =========================================================================
    // Capability management — create, save, delete
    // =========================================================================

    // Auto-fill wp_cap from slug
    $('#new-cap-slug').on('input', function () {
        const slug = $(this).val().trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');
        $('#new-cap-wpcap').val(slug ? 'wpa_' + slug : '');
    });

    // Create new capability
    $('#wpa-create-cap').on('click', function () {
        const $btn      = $(this);
        const slug      = $('#new-cap-slug').val().trim().toLowerCase().replace(/[^a-z0-9_]/g, '_');
        const label     = $('#new-cap-label').val().trim();
        const wp_cap    = $('#new-cap-wpcap').val().trim();
        const $feedback = $('#wpa-cap-feedback');

        if (!slug) { $feedback.text('Slug required.').addClass('wpa-feedback--error'); return; }

        $btn.prop('disabled', true);
        $feedback.text('').removeClass('wpa-feedback--ok wpa-feedback--error');

        $.post(ajax, { action: 'wpa_create_capability', nonce, slug, label, wp_cap })
        .done(function (res) {
            if (res.success) {
                $feedback.text('Created. Reload to see it in Permissions.').addClass('wpa-feedback--ok');
                $('#new-cap-slug, #new-cap-label, #new-cap-wpcap').val('');
                // Add row to table immediately
                const d = res.data;
                $('table.wpa-cap-table-list, .wp-list-table tbody').first().append(
                    `<tr>
                        <td><code>${d.slug}</code></td>
                        <td><input type="text" class="wpa-cap-label-edit" data-slug="${d.slug}"
                                   value="${d.label}" style="width:100%;font-size:.85rem" /></td>
                        <td><input type="text" class="wpa-cap-wpcap-edit" data-slug="${d.slug}"
                                   value="${d.wp_cap}" style="width:100%;font-family:monospace;font-size:.8rem" /></td>
                        <td><span class="wpa-tag wpa-tag--custom">custom</span></td>
                        <td>
                            <button class="wpa-btn wpa-btn--ghost wpa-btn--sm wpa-save-cap"
                                    data-slug="${d.slug}" style="margin-right:4px">Save</button>
                            <button class="wpa-btn wpa-btn--danger wpa-delete-cap"
                                    data-slug="${d.slug}">&times;</button>
                        </td>
                    </tr>`
                );
            } else {
                $feedback.text(res.data || i18n.error).addClass('wpa-feedback--error');
            }
        })
        .fail(function () { $feedback.text(i18n.error).addClass('wpa-feedback--error'); })
        .always(function () { $btn.prop('disabled', false); });
    });

    // Save (inline edit) capability
    $(document).on('click', '.wpa-save-cap', function () {
        const $btn  = $(this);
        const slug  = $btn.data('slug');
        const $row  = $btn.closest('tr');
        const label = $row.find('.wpa-cap-label-edit').val().trim();
        const wp_cap = $row.find('.wpa-cap-wpcap-edit').val().trim();

        $btn.prop('disabled', true).text('...');

        $.post(ajax, { action: 'wpa_save_capability', nonce, slug, label, wp_cap })
        .done(function (res) {
            $btn.text(res.success ? '✓' : '✗');
            setTimeout(() => $btn.text('Save').prop('disabled', false), 1500);
        })
        .fail(function () { $btn.text('✗').prop('disabled', false); });
    });

    // Delete capability
    $(document).on('click', '.wpa-delete-cap', function () {
        const $btn = $(this);
        const slug = $btn.data('slug');
        if (!confirm(`Delete capability "${slug}"? It will be removed from all roles.`)) return;

        $.post(ajax, { action: 'wpa_delete_capability', nonce, slug })
        .done(function (res) {
            if (res.success) {
                $btn.closest('tr').fadeOut(200, function () { $(this).remove(); });
            } else {
                alert(res.data || i18n.error);
            }
        });
    });


// =========================================================================
    // Rate limit reset
    // =========================================================================

    $('#wpa-reset-rate-limits').on('click', function () {
        const $btn      = $(this);
        const $feedback = $('#wpa-rate-reset-feedback');

        $btn.prop('disabled', true);
        $feedback.text('Resetting...').css('color', '#6b7280');

        $.post(ajax, { action: 'wpa_reset_rate_limits', nonce })
        .done(function (res) {
            if (res.success) {
                $feedback.text(res.data.message).css('color', '#16a34a');
            } else {
                $feedback.text(i18n.error).css('color', '#dc2626');
            }
        })
        .fail(function () { $feedback.text(i18n.error).css('color', '#dc2626'); })
        .always(function () {
            $btn.prop('disabled', false);
            setTimeout(() => $feedback.text(''), 5000);
        });
    });

    // =========================================================================
    // Manual database update
    // =========================================================================

    $('#wpa-update-db').on('click', function () {
        const $btn      = $(this);
        const $feedback = $('#wpa-db-feedback');

        if (!confirm('Update database now?')) return;

        $btn.prop('disabled', true).text('Updating...');
        $feedback.text('').css('color', '');

        $.post(ajax, { action: 'wpa_update_database', nonce })
        .done(function (res) {
            if (res.success) {
                $feedback.text(res.data.message).css('color', '#16a34a');
                // Reload after 2s to reflect new version number
                setTimeout(() => window.location.reload(), 2000);
            } else {
                $feedback.text(res.data || i18n.error).css('color', '#dc2626');
                $btn.prop('disabled', false).text('Update database');
            }
        })
        .fail(function () {
            $feedback.text(i18n.error).css('color', '#dc2626');
            $btn.prop('disabled', false).text('Update database');
        });
    });


    // =========================================================================
    // Clear single rate limit entry
    // =========================================================================

    $(document).on('click', '.wpa-clear-single-rl', function () {
        const $btn  = $(this);
        const hash  = $btn.data('hash');

        $btn.prop('disabled', true).text('...');

        $.post(ajax, { action: 'wpa_clear_single_rl', nonce, hash })
        .done(function (res) {
            if (res.success) {
                $btn.closest('tr').fadeOut(200, function () { $(this).remove(); });
            } else {
                $btn.prop('disabled', false).text('Clear');
            }
        })
        .fail(function () { $btn.prop('disabled', false).text('Clear'); });
    });


    // =========================================================================
    // API token management
    // =========================================================================

    // Generate token
    $('#wpa-generate-token').on('click', function () {
        const $btn  = $(this);
        const desc  = $('#wpa-api-desc').val().trim();

        if (!desc) {
            $('#wpa-api-desc').focus();
            return;
        }

        $btn.prop('disabled', true).text('Generating...');

        $.post(ajax, { action: 'wpa_generate_api_token', nonce, description: desc })
        .done(function (res) {
            if (res.success) {
                $('#wpa-new-token-val').text(res.data.token);
                $('#wpa-new-token-wrap').slideDown(200);
                $('#wpa-api-desc').val('');
                // Reload after 3s to show new token in list
                setTimeout(() => window.location.reload(), 3000);
            } else {
                alert(i18n.error);
            }
        })
        .always(function () {
            $btn.prop('disabled', false).text('Generate token');
        });
    });

    // Copy token
    $(document).on('click', '#wpa-copy-token', function () {
        const val = $('#wpa-new-token-val').text();
        navigator.clipboard.writeText(val).then(() => {
            $(this).text('Copied!');
            setTimeout(() => $(this).text('Copy'), 2000);
        });
    });

    // Revoke token
    $(document).on('click', '.wpa-revoke-token', function () {
        const $btn = $(this);
        const id   = $btn.data('id');

        if (!confirm('Revoke this token? All API calls using it will fail immediately.')) return;

        $btn.prop('disabled', true);
        $.post(ajax, { action: 'wpa_revoke_api_token', nonce, id })
        .done(function (res) {
            if (res.success) window.location.reload();
            else $btn.prop('disabled', false);
        });
    });

    // Delete token
    $(document).on('click', '.wpa-delete-token', function () {
        const $btn = $(this);
        const id   = $btn.data('id');

        if (!confirm('Permanently delete this token?')) return;

        $btn.prop('disabled', true);
        $.post(ajax, { action: 'wpa_delete_api_token', nonce, id })
        .done(function (res) {
            if (res.success) {
                $('#wpa-token-row-' + id).fadeOut(200, function () { $(this).remove(); });
            } else {
                $btn.prop('disabled', false);
            }
        });
    });

}(jQuery));
