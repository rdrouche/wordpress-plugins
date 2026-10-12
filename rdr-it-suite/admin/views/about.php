<?php
/**
 * Vue : À propos de RDR-IT Suite
 *
 * @package RDR_IT_Suite
 */

defined( 'ABSPATH' ) || exit;

$loader     = rdr_suite();
$active_cnt = count( $loader->get_active() );
$total_cnt  = count( $loader->get_discovered() );
?>
<div class="rdr-wrap" data-theme="dark">

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
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-it-suite' ) ); ?>" class="rdr-btn rdr-btn--ghost">
            ← <?php esc_html_e( 'Dashboard', 'rdr-it-suite' ); ?>
        </a>
    </header>

    <section class="rdr-section">
        <div class="rdr-about">

            <div class="rdr-about__hero">
                <p class="rdr-about__tagline">
                    <?php esc_html_e( 'Infrastructure de modules WordPress — conçue pour rdr-it.com', 'rdr-it-suite' ); ?>
                </p>
            </div>

            <div class="rdr-about__grid">

                <div class="rdr-about__card">
                    <h3>📦 <?php esc_html_e( 'Version', 'rdr-it-suite' ); ?></h3>
                    <p><code><?php echo esc_html( RDR_SUITE_VERSION ); ?></code></p>
                </div>

                <div class="rdr-about__card">
                    <h3>⚙️ <?php esc_html_e( 'Modules', 'rdr-it-suite' ); ?></h3>
                    <p><?php printf( esc_html__( '%d actif(s) sur %d installé(s)', 'rdr-it-suite' ), $active_cnt, $total_cnt ); ?></p>
                </div>

                <div class="rdr-about__card">
                    <h3>🛠 <?php esc_html_e( 'Environnement', 'rdr-it-suite' ); ?></h3>
                    <p>WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?> · PHP <?php echo esc_html( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ); ?></p>
                </div>

                <div class="rdr-about__card">
                    <h3>👤 <?php esc_html_e( 'Auteur', 'rdr-it-suite' ); ?></h3>
                    <p><a href="https://rdr-it.com" target="_blank" rel="noopener">Romain Drouche — rdr-it.com</a></p>
                </div>

            </div>

            <div class="rdr-about__principles">
                <h3><?php esc_html_e( 'Principes de développement', 'rdr-it-suite' ); ?></h3>
                <ul>
                    <li>🚫 <?php esc_html_e( 'Zéro jQuery — JavaScript Vanilla uniquement', 'rdr-it-suite' ); ?></li>
                    <li>🎨 <?php esc_html_e( 'Design aligné sur le thème enfant GeneratePress RDR-IT', 'rdr-it-suite' ); ?></li>
                    <li>🔒 <?php esc_html_e( 'Sécurité : nonces, capabilities, sanitisation systématique', 'rdr-it-suite' ); ?></li>
                    <li>🧩 <?php esc_html_e( 'Modules autonomes : un dossier = un sous-plugin', 'rdr-it-suite' ); ?></li>
                    <li>📐 <?php esc_html_e( 'Versionning SemVer A.B.C', 'rdr-it-suite' ); ?></li>
                    <li>🐘 <?php esc_html_e( 'PHP 8.1+ — types stricts, classes modernes', 'rdr-it-suite' ); ?></li>
                </ul>
            </div>

            <div class="rdr-about__changelog">
                <h3><?php esc_html_e( 'Changelog', 'rdr-it-suite' ); ?></h3>
                <div class="rdr-changelog-entry">
                    <span class="rdr-badge rdr-badge--active">1.1.0</span>
                    <ul>
                        <li><?php esc_html_e( 'Ajout page de réglages par module (settings-view.php)', 'rdr-it-suite' ); ?></li>
                        <li><?php esc_html_e( 'Ajout RDR_Module_Settings — store clé/valeur par module', 'rdr-it-suite' ); ?></li>
                        <li><?php esc_html_e( 'Page "À propos" avec infos environnement', 'rdr-it-suite' ); ?></li>
                        <li><?php esc_html_e( 'Modules SEO Tools, Performance, Security enrichis avec settings réels', 'rdr-it-suite' ); ?></li>
                        <li><?php esc_html_e( 'AJAX save_module_settings avec hook de sanitisation par module', 'rdr-it-suite' ); ?></li>
                        <li><?php esc_html_e( 'Nonce unifié rdr_suite_nonce', 'rdr-it-suite' ); ?></li>
                    </ul>
                </div>
                <div class="rdr-changelog-entry">
                    <span class="rdr-badge rdr-badge--inactive">1.0.0</span>
                    <ul>
                        <li><?php esc_html_e( 'Version initiale — Dashboard + toggle modules', 'rdr-it-suite' ); ?></li>
                    </ul>
                </div>
            </div>

        </div>
    </section>

</div><!-- .rdr-wrap -->
