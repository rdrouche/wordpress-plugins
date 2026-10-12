<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$stats    = RDR_Database::get_stats();
$sources  = RDR_Database::get_sources();
$per_page = intval( $atts['per_page'] ?? get_option( 'rdr_cyber_per_page', 12 ) );

/* Severity options */
$severities = array(
    ''         => 'Toutes sévérités',
    'critical' => 'Critique',
    'high'     => 'Haute',
    'medium'   => 'Moyenne',
    'low'      => 'Faible',
    'info'     => 'Info',
);

/* Type options */
$types = array(
    ''              => 'Tous les types',
    'alerte'        => 'Alerte CERT-FR',
    'avis'          => 'Avis CERT-FR',
    'ioc'           => 'IOC',
    'actualite'     => 'Actualité',
    'dur'           => 'Durée critique',
    'vulnerability' => 'CVE / NVD',
);

/* Last collected */
$last = $stats['last_collected'] ? date_i18n( 'd/m/Y H:i', strtotime( $stats['last_collected'] ) ) : 'Jamais';
?>
<div class="rdr-cyber-wrap">

    <!-- ===== STATS BAR ===== -->
    <div class="rdr-stats-bar">
        <div class="rdr-stat-card rdr-accent">
            <span class="rdr-stat-label">Total entrées</span>
            <span class="rdr-stat-number"><?php echo esc_html( number_format( $stats['total'] ) ); ?></span>
            <span class="rdr-stat-sub">toutes sources</span>
        </div>
        <div class="rdr-stat-card rdr-green">
            <span class="rdr-stat-label">Cette semaine</span>
            <span class="rdr-stat-number"><?php echo esc_html( $stats['this_week'] ); ?></span>
            <span class="rdr-stat-sub">derniers 7 jours</span>
        </div>
        <div class="rdr-stat-card">
            <span class="rdr-stat-label">Aujourd'hui</span>
            <span class="rdr-stat-number"><?php echo esc_html( $stats['today'] ); ?></span>
            <span class="rdr-stat-sub">nouvelles entrées</span>
        </div>
        <div class="rdr-stat-card rdr-critical">
            <span class="rdr-stat-label">Critique / Haute</span>
            <span class="rdr-stat-number"><?php echo esc_html( $stats['critical'] ); ?></span>
            <span class="rdr-stat-sub">sévérité élevée</span>
        </div>
        <?php foreach ( $stats['by_source'] as $src ): ?>
        <div class="rdr-stat-card">
            <span class="rdr-stat-label"><?php echo esc_html( $src['source'] ); ?></span>
            <span class="rdr-stat-number"><?php echo esc_html( $src['cnt'] ); ?></span>
            <span class="rdr-stat-sub">entrées</span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ===== TOOLBAR ===== -->
    <div class="rdr-toolbar">
        <div class="rdr-search-wrap">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
            <input
                type="search"
                id="rdr-search"
                class="rdr-search"
                placeholder="Rechercher CVE, titre, description…"
                autocomplete="off"
            >
        </div>

        <?php if ( $sources ): ?>
        <select id="rdr-filter-source" class="rdr-filter-select">
            <option value="">Toutes sources</option>
            <?php foreach ( $sources as $src ): ?>
            <option value="<?php echo esc_attr( $src ); ?>"><?php echo esc_html( $src ); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <select id="rdr-filter-severity" class="rdr-filter-select">
            <?php foreach ( $severities as $val => $label ): ?>
            <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>

        <select id="rdr-filter-type" class="rdr-filter-select">
            <?php foreach ( $types as $val => $label ): ?>
            <option value="<?php echo esc_attr( $val ); ?>"><?php echo esc_html( $label ); ?></option>
            <?php endforeach; ?>
        </select>

        <button id="rdr-reset" class="rdr-btn rdr-btn-ghost" type="button">Réinitialiser</button>
    </div>

    <!-- ===== RESULT INFO ===== -->
    <div class="rdr-result-info">
        <div class="rdr-result-count" id="rdr-result-count">Chargement…</div>
        <div class="rdr-last-sync">
            Dernière collecte&nbsp;: <span><?php echo esc_html( $last ); ?></span>
        </div>
    </div>

    <!-- ===== CARDS GRID ===== -->
    <div class="rdr-cards-grid" id="rdr-cards-grid">
        <div class="rdr-loading">
            <div class="rdr-spinner"></div>
            <span>Chargement des données…</span>
        </div>
    </div>

    <!-- ===== PAGINATION ===== -->
    <div class="rdr-pagination" id="rdr-pagination"></div>

</div>
