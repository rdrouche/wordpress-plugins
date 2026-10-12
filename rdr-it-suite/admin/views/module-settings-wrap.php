<?php
/**
 * Vue : Wrapper page de réglages d'un module
 *
 * Variables disponibles :
 *   $slug          string
 *   $module        array
 *   $settings      array<string, mixed>
 *   $loader        RDR_Module_Loader
 *   $settings_view string  chemin absolu vers le settings-view.php du module
 *
 * @package RDR_IT_Suite
 */

defined( 'ABSPATH' ) || exit;

$is_active = $loader->is_active( $slug );
?>
<div class="rdr-wrap" data-theme="dark">

    <!-- ── Header ───────────────────────────────────────────────────── -->
    <header class="rdr-header">
        <div class="rdr-header__brand">
            <span class="rdr-header__logo">
                <span class="rdr-logo-rdr">RDR</span><span class="rdr-logo-it">-IT</span>
            </span>
            <div class="rdr-header__meta">
                <h1 class="rdr-header__title">
                    <span class="rdr-header__module-icon"><?php echo esc_html( $module['icon'] ?? '🔧' ); ?></span>
                    <?php echo esc_html( $module['name'] ?? $slug ); ?>
                    <span class="rdr-header__subtitle">— <?php esc_html_e( 'Réglages', 'rdr-it-suite' ); ?></span>
                </h1>
                <span class="rdr-header__version">v<?php echo esc_html( $module['version'] ?? '—' ); ?></span>
            </div>
        </div>
        <div class="rdr-header__actions">
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-it-suite' ) ); ?>" class="rdr-btn rdr-btn--ghost">
                ← <?php esc_html_e( 'Dashboard', 'rdr-it-suite' ); ?>
            </a>
            <span class="rdr-badge <?php echo $is_active ? 'rdr-badge--active' : 'rdr-badge--inactive'; ?>">
                <?php echo $is_active ? esc_html__( 'Actif', 'rdr-it-suite' ) : esc_html__( 'Inactif', 'rdr-it-suite' ); ?>
            </span>
        </div>
    </header>

    <!-- ── Notices ──────────────────────────────────────────────────── -->
    <div id="rdr-notices" class="rdr-notices" aria-live="polite"></div>

    <?php if ( ! $is_active ) : ?>
    <div class="rdr-section">
        <div class="rdr-alert rdr-alert--warn">
            <span>⚠</span>
            <?php printf(
                esc_html__( 'Le module %s est inactif. Activez-le depuis le Dashboard pour que les réglages prennent effet.', 'rdr-it-suite' ),
                '<strong>' . esc_html( $module['name'] ?? $slug ) . '</strong>'
            ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-it-suite' ) ); ?>">
                <?php esc_html_e( 'Aller au Dashboard →', 'rdr-it-suite' ); ?>
            </a>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Formulaire de settings (délégué au module) ────────────────── -->
    <section class="rdr-section">
        <form class="rdr-settings-form" data-slug="<?php echo esc_attr( $slug ); ?>">

            <?php
            // Le module injecte ses propres champs ici
            // Variables disponibles dans le scope : $slug, $module, $settings
            require $settings_view;
            ?>

            <div class="rdr-settings-footer">
                <button type="submit" class="rdr-btn rdr-btn--save">
                    <?php esc_html_e( 'Enregistrer les réglages', 'rdr-it-suite' ); ?>
                </button>
                <span class="rdr-settings-status" aria-live="polite"></span>
            </div>

        </form>
    </section>

</div><!-- .rdr-wrap -->
