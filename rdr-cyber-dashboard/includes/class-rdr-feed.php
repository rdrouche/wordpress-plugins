<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Génère un flux RSS personnalisé des alertes cyber.
 * Accessible à l'URL : /feed/cyber-alerts/
 * Paramètres GET supportés : source, severity, limit (max 50)
 */
class RDR_Feed {

    const FEED_SLUG = 'cyber-alerts';

    public static function init() {
        add_action( 'init',          array( __CLASS__, 'register_feed' ) );
        add_action( 'do_feed_' . self::FEED_SLUG, array( __CLASS__, 'output_feed' ) );
    }

    public static function register_feed() {
        add_feed( self::FEED_SLUG, array( __CLASS__, 'output_feed' ) );
    }

    public static function output_feed() {
        $source   = sanitize_text_field( $_GET['source']   ?? '' );
        $severity = sanitize_text_field( $_GET['severity'] ?? '' );
        $limit    = min( 50, max( 1, intval( $_GET['limit'] ?? 25 ) ) );

        $items = RDR_Database::query_items( '', $source, $severity, '', 1, $limit );

        $blog_name = get_bloginfo('name');
        $feed_url  = home_url('/feed/' . self::FEED_SLUG . '/');
        $site_url  = home_url();
        $now_rfc   = date( DATE_RSS );

        header( 'Content-Type: application/rss+xml; charset=UTF-8' );
        header( 'X-Robots-Tag: noindex', true );
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        ?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">
    <channel>
        <title><?php echo esc_xml( $blog_name . ' — Cyber Alertes' ); ?></title>
        <link><?php echo esc_url( $site_url ); ?></link>
        <atom:link href="<?php echo esc_url( $feed_url ); ?>" rel="self" type="application/rss+xml"/>
        <description>Alertes et vulnérabilités cybersécurité — CERT-FR, NVD et autres sources</description>
        <language>fr-FR</language>
        <lastBuildDate><?php echo esc_html( $now_rfc ); ?></lastBuildDate>
        <generator>RDR Cyber Dashboard <?php echo RDR_CYBER_VERSION; ?></generator>
        <?php foreach ( $items as $item ) : ?>
        <?php
        $pub_rfc  = $item['pub_date'] ? date( DATE_RSS, strtotime($item['pub_date']) ) : $now_rfc;
        $link     = $item['link'] ?: $site_url;
        $sev_tag  = $item['severity'] ? '[' . strtoupper($item['severity']) . '] ' : '';
        $cve_tag  = $item['cve_id']   ? '[' . $item['cve_id'] . '] '              : '';
        $desc     = wp_strip_all_tags( $item['description'] ?? '' );
        $desc_out = $cve_tag . $desc;
        if ( $item['cvss_score'] !== null ) $desc_out .= ' | CVSS: ' . number_format(floatval($item['cvss_score']),1);
        ?>
        <item>
            <title><?php echo esc_xml( $sev_tag . $cve_tag . $item['title'] ); ?></title>
            <link><?php echo esc_url( $link ); ?></link>
            <guid isPermaLink="<?php echo $item['link'] ? 'true' : 'false'; ?>"><?php echo esc_url( $link ); ?></guid>
            <pubDate><?php echo esc_html( $pub_rfc ); ?></pubDate>
            <description><![CDATA[<?php echo $desc_out; ?>]]></description>
            <dc:subject><?php echo esc_xml( $item['source'] . ' / ' . $item['item_type'] ); ?></dc:subject>
            <?php if ( $item['cve_id'] ) : ?><dc:identifier><?php echo esc_xml($item['cve_id']); ?></dc:identifier><?php endif; ?>
            <category><?php echo esc_xml( $item['source'] ); ?></category>
            <category><?php echo esc_xml( $item['severity'] ); ?></category>
        </item>
        <?php endforeach; ?>
    </channel>
</rss>
        <?php
        exit;
    }
}
