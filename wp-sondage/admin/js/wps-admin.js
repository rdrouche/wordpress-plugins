/* WP Sondage — Admin JS (sortable placeholder) */
(function ($) {
    // Si jQuery UI Sortable est disponible, activer le tri par drag & drop
    if ($.fn.sortable) {
        $('#wps-choices-wrapper').sortable({
            handle: '.wps-sort-handle',
            placeholder: 'wps-sort-placeholder',
            update: function () {
                // Mettre à jour les champs order
                $(this).find('.wps-choice-row').each(function (i) {
                    $(this).find('input[name*="[order]"]').val(i);
                });
            }
        });
    }
})(jQuery);
