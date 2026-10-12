<?php
/**
 * Vue : Dashboard RDR-IT Suite
 *
 * Variables disponibles :
 *   $loader      RDR_Module_Loader
 *   $discovered  array<string, array>
 *   $active_cnt  int
 *   $total_cnt   int
 *
 * @package RDR_IT_Suite
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="rdr-wrap" data-theme="dark">

    <!-- ── Header ───────────────────────────────────────────────────── -->
    <header class="rdr-header">
        <div class="rdr-header__brand">
            <span class="rdr-header__logo">
                <span class="rdr-logo-rdr">RDR</span><span class="rdr-logo-it">-IT</span>
            </span>
            <div class="rdr-header__meta">
                <h1 class="rdr-header__title">Suite</h1>
                <span class="rdr-header__version">v<?php echo esc_html( RDR_SUITE_VERSION ); ?></span>
            </div>
        </div>
        <div class="rdr-header__stats">
            <div class="rdr-stat">
                <span class="rdr-stat__number"><?php echo esc_html( $active_cnt ); ?></span>
                <span class="rdr-stat__label"><?php esc_html_e( 'Actifs', 'rdr-it-suite' ); ?></span>
            </div>
            <div class="rdr-stat">
                <span class="rdr-stat__number"><?php echo esc_html( $total_cnt ); ?></span>
                <span class="rdr-stat__label"><?php esc_html_e( 'Modules', 'rdr-it-suite' ); ?></span>
            </div>
        </div>
    </header>

    <!-- ── Notices ──────────────────────────────────────────────────── -->
    <div id="rdr-notices" class="rdr-notices" aria-live="polite"></div>

    <!-- ── Grid modules ─────────────────────────────────────────────── -->
    <section class="rdr-section">
        <h2 class="rdr-section__title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
            <?php esc_html_e( 'Modules disponibles', 'rdr-it-suite' ); ?>
        </h2>

        <?php if ( empty( $discovered ) ) : ?>
            <div class="rdr-empty">
                <span class="rdr-empty__icon">📦</span>
                <p><?php esc_html_e( 'Aucun module trouvé dans /modules/. Consultez le README pour ajouter des sous-plugins.', 'rdr-it-suite' ); ?></p>
                <a class="rdr-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-it-suite-doc') ); ?>">
                    <?php esc_html_e( 'Documentation', 'rdr-it-suite' ); ?>
                </a>
            </div>
        <?php else : ?>
            <div class="rdr-grid">
                <?php foreach ( $discovered as $slug => $module ) :
                    $is_active     = $loader->is_active( $slug );
                    $incompatible  = ! empty( $module['incompatible'] );
                    $card_class    = 'rdr-card' . ( $is_active ? ' rdr-card--active' : '' ) . ( $incompatible ? ' rdr-card--incompatible' : '' );
                ?>
                <article class="<?php echo esc_attr( $card_class ); ?>" data-slug="<?php echo esc_attr( $slug ); ?>">

                    <div class="rdr-card__header">
                        <span class="rdr-card__icon" aria-hidden="true"><?php echo esc_html( $module['icon'] ?? '🔧' ); ?></span>
                        <div class="rdr-card__toggle-wrap">
                            <?php if ( $incompatible ) : ?>
                                <span class="rdr-badge rdr-badge--warn" title="<?php esc_attr_e( 'Version incompatible', 'rdr-it-suite' ); ?>">
                                    ⚠ <?php esc_html_e( 'Incompatible', 'rdr-it-suite' ); ?>
                                </span>
                            <?php else : ?>
                                <button
                                    class="rdr-toggle<?php echo $is_active ? ' rdr-toggle--on' : ''; ?>"
                                    data-slug="<?php echo esc_attr( $slug ); ?>"
                                    data-action="<?php echo $is_active ? 'deactivate' : 'activate'; ?>"
                                    aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
                                    aria-label="<?php echo $is_active
                                        ? esc_attr( sprintf( __( 'Désactiver %s', 'rdr-it-suite' ), $module['name'] ) )
                                        : esc_attr( sprintf( __( 'Activer %s',    'rdr-it-suite' ), $module['name'] ) ); ?>"
                                >
                                    <span class="rdr-toggle__track">
                                        <span class="rdr-toggle__thumb"></span>
                                    </span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <h3 class="rdr-card__name"><?php echo esc_html( $module['name'] ?? $slug ); ?></h3>
                    <p class="rdr-card__desc"><?php echo esc_html( $module['description'] ?? '' ); ?></p>

                    <footer class="rdr-card__footer">
                        <span class="rdr-badge <?php echo $is_active ? 'rdr-badge--active' : 'rdr-badge--inactive'; ?>">
                            <?php echo $is_active ? esc_html__( 'Actif', 'rdr-it-suite' ) : esc_html__( 'Inactif', 'rdr-it-suite' ); ?>
                        </span>
                        <div class="rdr-card__footer-right">
                            <?php if ( $is_active && file_exists( $module['dir'] . 'settings-view.php' ) ) : ?>
                                <a href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-module-' . $slug ) ); ?>" class="rdr-card__settings-link">
                                    ⚙ <?php esc_html_e( 'Réglages', 'rdr-it-suite' ); ?>
                                </a>
                            <?php endif; ?>
                            <span class="rdr-card__version">v<?php echo esc_html( $module['version'] ?? '—' ); ?></span>
                        </div>
                    </footer>

                </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ── Doc rapide ───────────────────────────────────────────────── -->
    <section class="rdr-section rdr-section--doc">
        <h2 class="rdr-section__title">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <?php esc_html_e( 'Ajouter un module', 'rdr-it-suite' ); ?>
        </h2>
        <div class="rdr-doc">
            <p><?php esc_html_e( 'Créez un dossier dans', 'rdr-it-suite' ); ?> <code>wp-content/plugins/rdr-it-suite/modules/mon-module/</code> <?php esc_html_e( 'contenant :', 'rdr-it-suite' ); ?></p>
            <pre class="rdr-code">{
  "slug":        "mon-module",
  "name":        "Mon Module",
  "description": "Description courte.",
  "version":     "1.0.0",
  "author":      "Auteur",
  "icon":        "🚀",
  "entry":       "mon-module.php",
  "min_version": "1.0.0"
}</pre>
            <p><?php esc_html_e( 'Puis créez le fichier d\'entrée', 'rdr-it-suite' ); ?> <code>mon-module.php</code>. <a href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-it-suite-doc') ); ?>"><?php esc_html_e( 'Documentation complète →', 'rdr-it-suite' ); ?></a></p>
        </div>
    </section>

</div><!-- .rdr-wrap -->
