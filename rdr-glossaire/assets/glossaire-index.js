/**
 * RDR-IT Glossaire — JS page index /glossaire/
 * Filtre alphabétique + recherche live — 100% client-side sur le HTML SSR.
 * Pas de requête AJAX : tout le contenu est déjà dans le DOM.
 */
(function () {
    'use strict';

    var searchInput  = document.getElementById('rdrg-search');
    var searchCount  = document.getElementById('rdrg-search-count');
    var noResults    = document.getElementById('rdrg-no-results');
    var alphaBtns    = document.querySelectorAll('.rdrg-alpha-btn');
    var sections     = document.querySelectorAll('.rdrg-letter-section');
    var cards        = document.querySelectorAll('.rdrg-term-card');

    if (!searchInput) return; // pas sur la page index

    var currentLetter = 'all';
    var currentSearch = '';
    var debounceTimer = null;

    /* ── Filtre alphabétique ──────────────────────── */
    alphaBtns.forEach(function (btn) {
        btn.addEventListener('click', function () {
            alphaBtns.forEach(function (b) { b.classList.remove('active'); });
            btn.classList.add('active');
            currentLetter = btn.getAttribute('data-letter');
            // Vider la recherche quand on change de lettre
            searchInput.value = '';
            currentSearch = '';
            applyFilters();
        });
    });

    /* ── Recherche live (debounce 200ms) ─────────── */
    searchInput.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () {
            currentSearch = searchInput.value.trim().toLowerCase();
            // Recherche → réinitialiser le filtre alphabétique
            if (currentSearch) {
                alphaBtns.forEach(function (b) { b.classList.remove('active'); });
                currentLetter = 'all';
            }
            applyFilters();
        }, 200);
    });

    /* ── Application des filtres ─────────────────── */
    function applyFilters() {
        var visibleCount = 0;

        sections.forEach(function (section) {
            var letter      = section.getAttribute('data-letter');
            var letterMatch = (currentLetter === 'all' || currentLetter === letter);
            var sectionVisible = false;

            section.querySelectorAll('.rdrg-term-card').forEach(function (card) {
                var term    = card.getAttribute('data-term') || '';
                var aliases = card.getAttribute('data-aliases') || '';
                var excerpt = (card.querySelector('.rdrg-term-excerpt') || {}).textContent || '';
                var haystack= (term + ' ' + aliases + ' ' + excerpt).toLowerCase();

                var searchMatch = !currentSearch || haystack.indexOf(currentSearch) !== -1;
                var visible     = letterMatch && searchMatch;

                card.hidden = !visible;
                if (visible) {
                    sectionVisible = true;
                    visibleCount++;
                }
            });

            section.hidden = !sectionVisible;
        });

        // Afficher "aucun résultat"
        if (noResults) noResults.hidden = visibleCount > 0;

        // Compteur de résultats (affiché seulement en mode recherche)
        if (searchCount) {
            if (currentSearch) {
                searchCount.textContent = visibleCount + ' résultat' + (visibleCount !== 1 ? 's' : '');
            } else {
                searchCount.textContent = '';
            }
        }
    }

    /* ── Ancre lettre depuis l'URL (#lettre-D) ───── */
    if (window.location.hash) {
        var hash = window.location.hash.replace('#lettre-', '').toUpperCase();
        var matchBtn = document.querySelector('.rdrg-alpha-btn[data-letter="' + hash + '"]');
        if (matchBtn && !matchBtn.disabled) matchBtn.click();
    }

})();
