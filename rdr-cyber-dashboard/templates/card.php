<?php
if ( ! defined( 'ABSPATH' ) ) exit;
/* $item is available from the loop in the AJAX handler */

$severity   = esc_attr( $item['severity'] ?? 'info' );
$source     = esc_html( $item['source']   ?? '' );
$type       = esc_html( $item['item_type'] ?? '' );
$title      = esc_html( $item['title']    ?? '' );
$link       = esc_url(  $item['link']     ?? '' );
$desc       = wp_strip_all_tags( $item['description'] ?? '' );
$cve_id     = esc_html( $item['cve_id']   ?? '' );
$cvss       = isset( $item['cvss_score'] ) && $item['cvss_score'] !== null ? floatval( $item['cvss_score'] ) : null;
$pub_date   = $item['pub_date'] ? date_i18n( 'd/m/Y H:i', strtotime( $item['pub_date'] ) ) : '';
$tags_raw   = $item['tags'] ?? '';
$tags       = $tags_raw ? array_filter( array_slice( explode( ',', $tags_raw ), 0, 4 ) ) : array();

/* Severity label */
$severity_labels = array(
    'critical' => 'Critique',
    'high'     => 'Haute',
    'medium'   => 'Moyenne',
    'low'      => 'Faible',
    'info'     => 'Info',
);
$severity_label = $severity_labels[ $item['severity'] ] ?? ucfirst( $item['severity'] );

/* Source dot class */
$source_class = strtolower( str_replace( array( ' ', '/' ), '-', $source ) );

/* Type label */
$type_labels = array(
    'alerte'        => 'Alerte',
    'avis'          => 'Avis',
    'ioc'           => 'IOC',
    'actualite'     => 'Actualité',
    'dur'           => 'Durée critique',
    'vulnerability' => 'CVE',
);
$type_label = $type_labels[ $item['item_type'] ?? '' ] ?? $type;

/* CVSS color */
$cvss_color = '#8b949e';
if ( $cvss !== null ) {
    if ( $cvss >= 9.0 )      $cvss_color = '#ff4757';
    elseif ( $cvss >= 7.0 )  $cvss_color = '#ff6b35';
    elseif ( $cvss >= 4.0 )  $cvss_color = '#f5a623';
    else                     $cvss_color = '#2ecc71';
}
$cvss_pct = $cvss !== null ? round( ( $cvss / 10 ) * 100 ) : 0;

/* Truncate description */
$desc_short = mb_strlen( $desc ) > 220 ? mb_substr( $desc, 0, 217 ) . '…' : $desc;
?>
<article class="rdr-card" data-severity="<?php echo $severity; ?>">

    <div class="rdr-card-stripe"></div>

    <div class="rdr-card-body">

        <!-- Header: badges + severity -->
        <div class="rdr-card-header">
            <div class="rdr-card-badges">
                <?php if ( $type_label ): ?>
                <span class="rdr-badge"><?php echo esc_html( $type_label ); ?></span>
                <?php endif; ?>
                <span class="rdr-badge-severity <?php echo esc_attr( $item['severity'] ?? 'info' ); ?>"><?php echo esc_html( $severity_label ); ?></span>
            </div>
        </div>

        <!-- CVE ID if present -->
        <?php if ( $cve_id ): ?>
        <div>
            <a href="https://nvd.nist.gov/vuln/detail/<?php echo esc_attr( $cve_id ); ?>"
               class="rdr-card-cve"
               target="_blank"
               rel="noopener noreferrer"><?php echo $cve_id; ?></a>
        </div>
        <?php endif; ?>

        <!-- Title -->
        <h3 class="rdr-card-title">
            <?php if ( $link ): ?>
            <a href="<?php echo $link; ?>" target="_blank" rel="noopener noreferrer"><?php echo $title; ?></a>
            <?php else: ?>
            <?php echo $title; ?>
            <?php endif; ?>
        </h3>

        <!-- Description -->
        <?php if ( $desc_short ): ?>
        <p class="rdr-card-desc"><?php echo esc_html( $desc_short ); ?></p>
        <?php endif; ?>

        <!-- CVSS score bar -->
        <?php if ( $cvss !== null ): ?>
        <div class="rdr-card-cvss">
            <span>CVSS&nbsp;<strong style="color:<?php echo esc_attr( $cvss_color ); ?>"><?php echo number_format( $cvss, 1 ); ?></strong></span>
            <div class="rdr-cvss-bar">
                <div class="rdr-cvss-fill" style="width:<?php echo $cvss_pct; ?>%;background:<?php echo esc_attr( $cvss_color ); ?>"></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tags -->
        <?php if ( $tags ): ?>
        <div class="rdr-card-tags">
            <?php foreach ( $tags as $tag ): ?>
            <?php if ( trim( $tag ) ): ?>
            <span class="rdr-tag"><?php echo esc_html( trim( $tag ) ); ?></span>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div><!-- .rdr-card-body -->

    <!-- Footer -->
    <div class="rdr-card-footer">
        <div class="rdr-card-source">
            <span class="rdr-source-dot <?php echo esc_attr( $source_class ); ?>"></span>
            <?php echo $source; ?>
        </div>

        <span><?php echo esc_html( $pub_date ); ?></span>

        <?php if ( $link ): ?>
        <a href="<?php echo $link; ?>" class="rdr-card-link" target="_blank" rel="noopener noreferrer">
            Détails
            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
        </a>
        <?php endif; ?>
    </div>

</article>
