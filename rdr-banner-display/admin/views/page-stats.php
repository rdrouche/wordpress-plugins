<?php
/**
 * Admin page: Statistics.
 *
 * @package RDR_Banner_Display
 */
defined( 'ABSPATH' ) || exit;

$banner_id = absint( $_GET['banner_id'] ?? 0 );
$days      = absint( $_GET['days']      ?? 30 );
$days      = in_array( $days, [ 7, 14, 30, 60, 90 ], true ) ? $days : 30;

// Get banners list for selector
$banners = get_posts( [
    'post_type'      => RDR_BD_Post_Type::CPT,
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
] );
?>
<div class="wrap rdr-bd-wrap">
    <h1 class="rdr-bd-page-title">
        <span class="rdr-bd-logo">RDR</span><span class="rdr-bd-logo-accent">-IT</span>
        &nbsp; Statistics
    </h1>

    <!-- Filters -->
    <?php if ( $banner_id ) : ?>
    <div class="rdr-bd-reset-bar">
        <button type="button" class="button rdr-bd-reset-stats-btn" data-banner-id="<?php echo (int) $banner_id; ?>">
            &#x267b; Reset standalone stats for this banner
        </button>
        <span style="font-size:12px;color:#646970;">Removes impressions &amp; clicks not linked to a campaign. Campaign stats are preserved.</span>
    </div>
    <?php endif; ?>

    <div class="rdr-bd-filters">
        <form method="get">
            <input type="hidden" name="page" value="rdr-bd-stats">
            <label>Banner:
                <select name="banner_id" id="rdr-bd-stat-banner">
                    <option value="0">— All Banners —</option>
                    <?php foreach ( $banners as $b ) : ?>
                        <option value="<?php echo esc_attr( $b->ID ); ?>" <?php selected( $banner_id, $b->ID ); ?>>
                            <?php echo esc_html( $b->post_title ); ?> (#<?php echo esc_html( $b->ID ); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Period:
                <select name="days" id="rdr-bd-stat-days">
                    <?php foreach ( [ 7 => '7 days', 14 => '14 days', 30 => '30 days', 60 => '60 days', 90 => '90 days' ] as $v => $l ) : ?>
                        <option value="<?php echo esc_attr( $v ); ?>" <?php selected( $days, $v ); ?>><?php echo esc_html( $l ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="button">Apply</button>
        </form>
    </div>

    <!-- Campaign stats section (shown when a campaign filter is set) -->
    <?php
    global $wpdb;
    $campaign_id_filter = absint( $_GET['campaign_id'] ?? 0 );
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $all_campaigns = $wpdb->get_results( "SELECT id, title, status FROM {$wpdb->prefix}rdr_bd_campaigns ORDER BY title ASC", ARRAY_A ) ?: [];
    ?>

    <!-- Filter bar additions: campaign selector -->
    <div class="rdr-bd-card" style="margin-bottom:14px;padding:12px 18px;">
        <form method="get" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
            <input type="hidden" name="page" value="rdr-bd-stats">
            <label style="font-size:13px;">Banner:
                <select name="banner_id">
                    <option value="0">— All —</option>
                    <?php foreach ( get_posts(['post_type'=>RDR_BD_Post_Type::CPT,'post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']) as $b ) : ?>
                        <option value="<?php echo $b->ID; ?>" <?php selected($banner_id,$b->ID); ?>><?php echo esc_html($b->post_title); ?> (#<?php echo $b->ID; ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="font-size:13px;">Campaign:
                <select name="campaign_id">
                    <option value="0">— All —</option>
                    <?php foreach ( $all_campaigns as $c ) : ?>
                        <option value="<?php echo esc_attr($c['id']); ?>" <?php selected($campaign_id_filter,(int)$c['id']); ?>>
                            <?php echo esc_html($c['title']); ?> [<?php echo esc_html($c['status']); ?>]
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="font-size:13px;">Period:
                <select name="days">
                    <?php foreach([7=>'7 days',14=>'14 days',30=>'30 days',60=>'60 days',90=>'90 days'] as $v=>$l): ?>
                        <option value="<?php echo $v;?>" <?php selected($days,$v);?>><?php echo esc_html($l);?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button type="submit" class="button">Apply</button>
        </form>
    </div>

    <?php if ( $campaign_id_filter ) :
        $stats   = new RDR_BD_Stats();
        $c_daily = $stats->get_campaign_daily( $campaign_id_filter, $days );
        $c_banners = $stats->get_campaign_stats( $campaign_id_filter, $days );
        $c_total_imp = array_sum(array_column($c_banners,'impressions'));
        $c_total_clk = array_sum(array_column($c_banners,'clicks'));
        $c_ctr = $c_total_imp > 0 ? round($c_total_clk/$c_total_imp*100,2) : 0;
    ?>
    <div class="rdr-bd-kpi-grid">
        <div class="rdr-bd-kpi-card"><div class="rdr-bd-kpi-value"><?php echo number_format($c_total_imp); ?></div><div class="rdr-bd-kpi-label">Campaign Impressions</div></div>
        <div class="rdr-bd-kpi-card"><div class="rdr-bd-kpi-value"><?php echo number_format($c_total_clk); ?></div><div class="rdr-bd-kpi-label">Campaign Clicks</div></div>
        <div class="rdr-bd-kpi-card"><div class="rdr-bd-kpi-value"><?php echo $c_ctr; ?>%</div><div class="rdr-bd-kpi-label">Campaign CTR</div></div>
    </div>

    <div class="rdr-bd-card">
        <h2>Campaign breakdown by banner</h2>
        <table class="widefat rdr-bd-table">
            <thead><tr><th>Banner</th><th>Impressions</th><th>Clicks</th><th>CTR</th><th>Stats</th></tr></thead>
            <tbody>
            <?php foreach($c_banners as $row):
                $ctr = $row['impressions']>0 ? round($row['clicks']/$row['impressions']*100,2) : 0;
            ?>
            <tr>
                <td><strong><?php echo esc_html($row['banner_title']??'#'.$row['banner_id']); ?></strong></td>
                <td><?php echo number_format($row['impressions']); ?></td>
                <td><?php echo number_format($row['clicks']); ?></td>
                <td><?php echo $ctr; ?>%</td>
                <td><a href="?page=rdr-bd-stats&banner_id=<?php echo $row['banner_id']; ?>&days=<?php echo $days; ?>">View</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="rdr-bd-card">
        <h2>Daily trend</h2>
        <canvas id="rdr-bd-chart-campaign" height="80"></canvas>
        <p id="rdr-bd-chart-campaign-msg" style="text-align:center;color:var(--rdr-text-muted);">Loading…</p>
    </div>
    <script>window.rdrBDCampaignDaily = <?php echo wp_json_encode($c_daily); ?>;</script>
    <?php endif; ?>

    <!-- KPI row (filled via JS) -->
    <div class="rdr-bd-kpi-grid" id="rdr-bd-stat-kpis">
        <div class="rdr-bd-kpi-card"><div class="rdr-bd-kpi-value" id="stat-impressions">—</div><div class="rdr-bd-kpi-label">Impressions</div></div>
        <div class="rdr-bd-kpi-card"><div class="rdr-bd-kpi-value" id="stat-clicks">—</div><div class="rdr-bd-kpi-label">Clicks</div></div>
        <div class="rdr-bd-kpi-card"><div class="rdr-bd-kpi-value" id="stat-ctr">—</div><div class="rdr-bd-kpi-label">CTR</div></div>
        <?php if ( $banner_id ) : ?>
        <div class="rdr-bd-kpi-card"><div class="rdr-bd-kpi-value" id="stat-adblock">—</div><div class="rdr-bd-kpi-label">AdBlock Impressions</div></div>
        <?php endif; ?>
    </div>

    <!-- Chart area -->
    <div class="rdr-bd-card">
        <h2>Daily Breakdown</h2>
        <canvas id="rdr-bd-chart" height="80"></canvas>
        <p id="rdr-bd-chart-msg" style="text-align:center;color:var(--rdr-text-secondary);">Loading…</p>
    </div>

    <!-- Top banners (global only) -->
    <?php if ( ! $banner_id ) : ?>
    <div class="rdr-bd-card">
        <h2>Top Banners</h2>
        <table class="widefat rdr-bd-table" id="rdr-bd-top-table">
            <thead><tr><th>Banner</th><th>Impressions</th><th>Clicks</th><th>CTR</th><th></th></tr></thead>
            <tbody><tr><td colspan="5" style="text-align:center;">Loading…</td></tr></tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
window.rdrBDStats = {
    bannerId: <?php echo (int) $banner_id; ?>,
    days: <?php echo (int) $days; ?>
};
</script>
