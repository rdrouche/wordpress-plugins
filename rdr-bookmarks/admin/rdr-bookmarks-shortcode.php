<?php
defined( 'ABSPATH' ) || exit;

/* ──────────────────────────────────────────────
   Shortcode [rdr_bookmarks]
   Les boutons trigger ont leurs styles critiques
   en inline — zéro dépendance au CSS externe
   pour l'affichage initial (no FOUT, no render-block).
   La modale reste dans le CSS externe (hidden au load).
   ────────────────────────────────────────────── */
add_shortcode( 'rdr_bookmarks', function () {
    if ( rdr_bm_is_bot() ) return '';

    $label_defaults = [
        'favorite'   => 'Favoris',
        'read_later' => 'À lire',
        'search'     => 'Rechercher…',
        'empty_fav'  => 'Aucun favori enregistré.',
        'empty_rl'   => 'Aucun article à lire enregistré.',
        'no_results' => 'Aucun résultat pour cette recherche.',
    ];
    $labels  = array_merge( $label_defaults, (array) get_option( 'rdr_bm_labels', [] ) );
    $emo_fav = get_option( 'rdr_bm_emoji_favorite',  '★' );
    $emo_rl  = get_option( 'rdr_bm_emoji_read_later', '🕐' );

    if ( function_exists( 'pll__' ) ) {
        foreach ( $labels as $k => $v ) { $labels[ $k ] = pll__( $v ); }
    }

    /*
     * STYLES INLINE — boutons trigger uniquement.
     * Utilise les variables CSS du thème (--rdr-sh-sw-*)
     * déjà présentes dans le DOM au moment du rendu.
     * Le CSS externe n'est donc pas bloquant pour ces éléments.
     */
    $s_wrap = 'display:flex;gap:10px;flex-wrap:wrap;';

    $s_btn =
        'display:inline-flex;align-items:center;gap:.4rem;' .
        'padding:.4em 1em;' .
        'background:var(--rdr-sh-sw-bg,rgba(22,27,34,.95));' .
        'color:var(--rdr-sh-sw-text,#8b949e);' .
        'border:1.5px solid var(--rdr-sh-sw-border,rgba(255,255,255,.10));' .
        'border-radius:6px;' .
        'cursor:pointer;' .
        'font-size:13px;font-weight:600;line-height:1.9;' .
        'white-space:nowrap;' .
        'backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);' .
        'transition:color .2s,border-color .2s;';

    $s_badge =
        'display:inline-flex;align-items:center;justify-content:center;' .
        'min-width:18px;height:18px;padding:0 5px;' .
        'background:var(--rdr-sh-sw-track-on,#2ea8ff);' .
        'color:#fff;border:none;border-radius:999px;' .
        'font-size:11px;font-weight:700;line-height:1;';

    ob_start();
    ?>
    <div class="rdr-bm-shortcode" id="rdr-bm">

        <!-- ── Boutons trigger — styles critiques inline ── -->
        <div style="<?= esc_attr( $s_wrap ) ?>">

            <button class="rdr-bm-trigger-btn"
                    style="<?= esc_attr( $s_btn ) ?>"
                    data-tab="favorites"
                    aria-haspopup="dialog"
                    aria-controls="rdr-bm-modal">
                <span style="font-size:14px;line-height:1"><?= esc_html( $emo_fav ) ?></span>
                <span><?= esc_html( $labels['favorite'] ) ?></span>
                <span class="rdr-bm-trigger-count"
                      style="<?= esc_attr( $s_badge ) ?>"
                      data-list="favorites">0</span>
            </button>

            <button class="rdr-bm-trigger-btn"
                    style="<?= esc_attr( $s_btn ) ?>"
                    data-tab="read_later"
                    aria-haspopup="dialog"
                    aria-controls="rdr-bm-modal">
                <span style="font-size:14px;line-height:1"><?= esc_html( $emo_rl ) ?></span>
                <span><?= esc_html( $labels['read_later'] ) ?></span>
                <span class="rdr-bm-trigger-count"
                      style="<?= esc_attr( $s_badge ) ?>"
                      data-list="read_later">0</span>
            </button>

        </div>

        <!-- ── Modale (hidden au load — CSS externe non bloquant) ── -->
        <div class="rdr-bm-modal-overlay" id="rdr-bm-modal"
             role="dialog" aria-modal="true"
             aria-label="<?= esc_attr( $labels['favorite'] . ' / ' . $labels['read_later'] ) ?>"
             hidden>
            <div class="rdr-bm-modal" id="rdr-bm-wrap">

                <div class="rdr-bm-tabs" role="tablist">

                    <button class="rdr-bm-tab active" role="tab"
                            aria-selected="true"
                            data-tab="favorites"
                            id="rdr-tab-favorites"
                            aria-controls="rdr-panel-favorites">
                        <span class="rdr-bm-tab-emo"><?= esc_html( $emo_fav ) ?></span>
                        <?= esc_html( $labels['favorite'] ) ?>
                        <span class="rdr-bm-tab-count" data-list="favorites">0</span>
                    </button>

                    <button class="rdr-bm-tab" role="tab"
                            aria-selected="false"
                            data-tab="read_later"
                            id="rdr-tab-read_later"
                            aria-controls="rdr-panel-read_later">
                        <span class="rdr-bm-tab-emo"><?= esc_html( $emo_rl ) ?></span>
                        <?= esc_html( $labels['read_later'] ) ?>
                        <span class="rdr-bm-tab-count" data-list="read_later">0</span>
                    </button>

                    <button class="rdr-bm-modal-close" aria-label="Fermer">✕</button>

                </div>

                <div class="rdr-bm-search-wrap">
                    <span class="rdr-bm-search-icon">🔍</span>
                    <input type="search"
                           class="rdr-bm-search"
                           id="rdr-bm-search"
                           placeholder="<?= esc_attr( $labels['search'] ) ?>"
                           autocomplete="off"
                           aria-label="<?= esc_attr( $labels['search'] ) ?>">
                    <button class="rdr-bm-search-clear" aria-label="Effacer" hidden>✕</button>
                </div>

                <div class="rdr-bm-panel active"
                     id="rdr-panel-favorites"
                     role="tabpanel"
                     aria-labelledby="rdr-tab-favorites"
                     data-panel="favorites">
                    <div class="rdr-bm-loading" hidden><span class="rdr-bm-spinner"></span></div>
                    <div class="rdr-bm-cards" aria-live="polite"></div>
                    <p class="rdr-bm-empty"     hidden><?= esc_html( $labels['empty_fav'] ) ?></p>
                    <p class="rdr-bm-no-results" hidden><?= esc_html( $labels['no_results'] ) ?></p>
                </div>

                <div class="rdr-bm-panel"
                     id="rdr-panel-read_later"
                     role="tabpanel"
                     aria-labelledby="rdr-tab-read_later"
                     data-panel="read_later"
                     hidden>
                    <div class="rdr-bm-loading" hidden><span class="rdr-bm-spinner"></span></div>
                    <div class="rdr-bm-cards" aria-live="polite"></div>
                    <p class="rdr-bm-empty"     hidden><?= esc_html( $labels['empty_rl'] ) ?></p>
                    <p class="rdr-bm-no-results" hidden><?= esc_html( $labels['no_results'] ) ?></p>
                </div>

            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
} );

/* ──────────────────────────────────────────────
   Shortcode [rdr_bookmarks_page]
   Affiche le contenu Favoris / À lire directement
   dans une page (pas de modale — intégré dans le
   flux de contenu).
   Usage : créer une page WP, coller [rdr_bookmarks_page]
   dans le contenu, mettre son URL dans l'admin.
   ────────────────────────────────────────────── */
add_shortcode( 'rdr_bookmarks_page', function () {
    if ( rdr_bm_is_bot() ) return '';

    $label_defaults = [
        'favorite'   => 'Favoris',
        'read_later' => 'À lire',
        'search'     => 'Rechercher…',
        'empty_fav'  => 'Aucun favori enregistré.',
        'empty_rl'   => 'Aucun article à lire enregistré.',
        'no_results' => 'Aucun résultat pour cette recherche.',
    ];
    $labels  = array_merge( $label_defaults, (array) get_option( 'rdr_bm_labels', [] ) );
    $emo_fav = get_option( 'rdr_bm_emoji_favorite',  '★' );
    $emo_rl  = get_option( 'rdr_bm_emoji_read_later', '🕐' );

    if ( function_exists( 'pll__' ) ) {
        foreach ( $labels as $k => $v ) { $labels[ $k ] = pll__( $v ); }
    }

    ob_start();
    ?>
    <div class="rdr-bm-page" id="rdr-bm-page">

        <!-- Onglets -->
        <div class="rdr-bm-page-tabs" role="tablist">
            <button class="rdr-bm-page-tab active" role="tab"
                    aria-selected="true" data-tab="favorites"
                    id="rdr-page-tab-favorites" aria-controls="rdr-page-panel-favorites">
                <span><?= esc_html( $emo_fav ) ?></span>
                <?= esc_html( $labels['favorite'] ) ?>
                <span class="rdr-bm-page-count" data-list="favorites">0</span>
            </button>
            <button class="rdr-bm-page-tab" role="tab"
                    aria-selected="false" data-tab="read_later"
                    id="rdr-page-tab-read_later" aria-controls="rdr-page-panel-read_later">
                <span><?= esc_html( $emo_rl ) ?></span>
                <?= esc_html( $labels['read_later'] ) ?>
                <span class="rdr-bm-page-count" data-list="read_later">0</span>
            </button>
        </div>

        <!-- Recherche -->
        <div class="rdr-bm-page-search-wrap">
            <span class="rdr-bm-search-icon">🔍</span>
            <input type="search"
                   class="rdr-bm-page-search rdr-bm-search"
                   placeholder="<?= esc_attr( $labels['search'] ) ?>"
                   autocomplete="off"
                   aria-label="<?= esc_attr( $labels['search'] ) ?>">
            <button class="rdr-bm-search-clear" aria-label="Effacer" hidden>✕</button>
        </div>

        <!-- Panneau Favoris -->
        <div class="rdr-bm-page-panel active"
             id="rdr-page-panel-favorites"
             role="tabpanel"
             aria-labelledby="rdr-page-tab-favorites"
             data-panel="favorites">
            <div class="rdr-bm-loading" hidden><span class="rdr-bm-spinner"></span></div>
            <div class="rdr-bm-cards" aria-live="polite"></div>
            <p class="rdr-bm-empty"     hidden><?= esc_html( $labels['empty_fav'] ) ?></p>
            <p class="rdr-bm-no-results" hidden><?= esc_html( $labels['no_results'] ) ?></p>
        </div>

        <!-- Panneau À lire -->
        <div class="rdr-bm-page-panel"
             id="rdr-page-panel-read_later"
             role="tabpanel"
             aria-labelledby="rdr-page-tab-read_later"
             data-panel="read_later"
             hidden>
            <div class="rdr-bm-loading" hidden><span class="rdr-bm-spinner"></span></div>
            <div class="rdr-bm-cards" aria-live="polite"></div>
            <p class="rdr-bm-empty"     hidden><?= esc_html( $labels['empty_rl'] ) ?></p>
            <p class="rdr-bm-no-results" hidden><?= esc_html( $labels['no_results'] ) ?></p>
        </div>

    </div>
    <?php
    return ob_get_clean();
} );
