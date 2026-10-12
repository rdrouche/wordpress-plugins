/* RDR-IT Glossaire — Admin JS */
jQuery(function ($) {
    // Synchroniser visuellement le toggle switch dans les réglages
    $('input[name="rdrg_enabled"]').on('change', function () {
        var checked = $(this).is(':checked');
        $(this).closest('.rdrg-big-switch').find('.rdrg-switch-label')
            .text(checked ? 'Glossaire activé' : 'Glossaire désactivé');
    });
});
