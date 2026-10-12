<?php
/**
 * Réglages — Admin Menu Organizer
 * Variables : $slug, $module, $settings
 */
defined( 'ABSPATH' ) || exit;
?>

<div class="rdr-amo-wrap" id="rdr-amo-app">

    <header class="rdr-amo-header">
        <div class="rdr-amo-header__brand">
            <div>
                <h1 class="rdr-amo-header__title">
                    <?php echo esc_html( $module['name'] ?? 'Admin Menu Organizer' ); ?>
                </h1>
                <p class="rdr-amo-header__sub">
                    <?php esc_html_e( 'Reorgranisez votre menu admin par groupes personnalises.', 'rdr-it-suite' ); ?>
                </p>
            </div>
        </div>
        <div class="rdr-amo-header__actions">
            <button class="rdr-btn rdr-btn--ghost" id="rdr-amo-reset">
                <?php esc_html_e( 'Reinitialiser', 'rdr-it-suite' ); ?>
            </button>
            <button class="rdr-btn rdr-btn--save" id="rdr-amo-save">
                <?php esc_html_e( 'Enregistrer', 'rdr-it-suite' ); ?>
            </button>
        </div>
    </header>

    <div class="rdr-amo-toast" id="rdr-amo-toast" aria-live="polite"></div>

    <div class="rdr-amo-layout">

        <!-- Source -->
        <aside class="rdr-amo-panel rdr-amo-panel--source">
            <div class="rdr-amo-panel__header">
                <h2><?php esc_html_e( 'Elements disponibles', 'rdr-it-suite' ); ?></h2>
            </div>
            <p class="rdr-amo-panel__hint">
                <?php esc_html_e( 'Glissez vers un groupe ou "Non groupes"', 'rdr-it-suite' ); ?>
            </p>
            <ul class="rdr-amo-source-list" id="rdr-amo-source" role="list"></ul>
        </aside>

        <!-- Groupes + ungrouped -->
        <main class="rdr-amo-panel rdr-amo-panel--config">

            <div class="rdr-amo-panel__header">
                <h2><?php esc_html_e( 'Groupes', 'rdr-it-suite' ); ?></h2>
                <button class="rdr-btn rdr-btn--ghost" id="rdr-amo-add-group">
                    + <?php esc_html_e( 'Ajouter un groupe', 'rdr-it-suite' ); ?>
                </button>
            </div>

            <div class="rdr-amo-groups" id="rdr-amo-groups"></div>

            <!-- Zone non groupés -->
            <div class="rdr-amo-ungrouped" id="rdr-amo-ungrouped">
                <div class="rdr-amo-ungrouped__header">
                    <span class="dashicons dashicons-menu-alt2"></span>
                    <h3><?php esc_html_e( 'Non groupes (sans separateur)', 'rdr-it-suite' ); ?></h3>
                </div>
                <p class="rdr-amo-panel__hint">
                    <?php esc_html_e( 'Ces elements apparaissent dans le menu sans separateur de groupe.', 'rdr-it-suite' ); ?>
                </p>
                <ul class="rdr-amo-ungroup-list" role="list"></ul>
            </div>

        </main>

    </div>

    <!-- Apercu -->
    <section class="rdr-amo-preview">
        <h2 class="rdr-amo-preview__title">
            <?php esc_html_e( 'Apercu du menu', 'rdr-it-suite' ); ?>
        </h2>
        <div class="rdr-amo-preview__menu" id="rdr-amo-preview-menu"></div>
    </section>

</div>
