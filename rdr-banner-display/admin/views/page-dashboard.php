<?php
/**
 * Admin page: Dashboard overview.
 *
 * @package RDR_Banner_Display
 */
defined( 'ABSPATH' ) || exit;

$stats   = new RDR_BD_Stats();
$summary = $stats->get_global_summary( 30 );
$top     = $stats->get_top_banners( 5, 30 );

$total_banners   = wp_count_posts( RDR_BD_Post_Type::CPT );
$published       = $total_banners->publish ?? 0;

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$total_campaigns = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rdr_bd_campaigns" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$active_campaigns = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}rdr_bd_campaigns WHERE status = 'active'" );
?>
<div class="wrap rdr-bd-wrap">
    <h1 class="rdr-bd-page-title">
        <span class="rdr-bd-logo">RDR</span><span class="rdr-bd-logo-accent">-IT</span>
        &nbsp; Banner Display — Dashboard
    </h1>

    <!-- KPI cards -->
    <div class="rdr-bd-kpi-grid">
        <div class="rdr-bd-kpi-card">
            <div class="rdr-bd-kpi-value"><?php echo esc_html( number_format( $summary['impressions'] ) ); ?></div>
            <div class="rdr-bd-kpi-label">Impressions (30 days)</div>
        </div>
        <div class="rdr-bd-kpi-card">
            <div class="rdr-bd-kpi-value"><?php echo esc_html( number_format( $summary['clicks'] ) ); ?></div>
            <div class="rdr-bd-kpi-label">Clicks (30 days)</div>
        </div>
        <div class="rdr-bd-kpi-card">
            <div class="rdr-bd-kpi-value"><?php echo esc_html( $summary['ctr'] ); ?>%</div>
            <div class="rdr-bd-kpi-label">CTR (30 days)</div>
        </div>
        <div class="rdr-bd-kpi-card">
            <div class="rdr-bd-kpi-value"><?php echo esc_html( $published ); ?></div>
            <div class="rdr-bd-kpi-label">Active Banners</div>
        </div>
        <div class="rdr-bd-kpi-card">
            <div class="rdr-bd-kpi-value"><?php echo esc_html( $active_campaigns ); ?> / <?php echo esc_html( $total_campaigns ); ?></div>
            <div class="rdr-bd-kpi-label">Active / Total Campaigns</div>
        </div>
    </div>

    <!-- Top banners table -->
    <?php if ( ! empty( $top ) ) : ?>
    <div class="rdr-bd-card">
        <h2>Top Banners (last 30 days)</h2>
        <table class="widefat rdr-bd-table">
            <thead>
                <tr>
                    <th>Banner</th>
                    <th>Impressions</th>
                    <th>Clicks</th>
                    <th>CTR</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $top as $row ) :
                    $ctr = $row['impressions'] > 0 ? round( $row['clicks'] / $row['impressions'] * 100, 2 ) : 0;
                ?>
                <tr>
                    <td><strong><?php echo esc_html( $row['post_title'] ); ?></strong> <code>#<?php echo esc_html( $row['banner_id'] ); ?></code></td>
                    <td><?php echo esc_html( number_format( $row['impressions'] ) ); ?></td>
                    <td><?php echo esc_html( number_format( $row['clicks'] ) ); ?></td>
                    <td><?php echo esc_html( $ctr ); ?>%</td>
                    <td>
                        <a href="<?php echo esc_url( get_edit_post_link( $row['banner_id'] ) ); ?>">Edit</a>
                        &nbsp;|&nbsp;
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-bd-stats&banner_id=' . $row['banner_id'] ) ); ?>">Stats</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Stats data integrity check -->
    <?php
    $diag = RDR_BD_Migrations::get_diagnostics();
    if ( $diag['old_table_active'] ) : ?>
    <div class="notice notice-warning" style="margin:0 0 16px;">
        <p>
            <strong>⚠ Stats data integrity:</strong>
            The old <code>wp_rdr_bd_stats</code> table still exists and has
            <strong><?php echo number_format($diag['old_stats']['rows']); ?></strong> rows.
            The plugin is now using the new <code>wp_rdr_bd_stats_daily</code> table
            (<?php echo number_format($diag['stats_daily']['rows']); ?> rows) and
            <code>wp_rdr_bd_clicks</code> (<?php echo number_format($diag['clicks']['rows']); ?> rows).
            <br>Update the plugin to v2.5.0+ to trigger the clean migration that renames the old table.
            If already on v2.5.0, reset the DB version option to force re-migration:
        </p>
        <form method="post" style="margin-top:8px;">
            <?php wp_nonce_field('rdr_bd_force_migrate'); ?>
            <input type="hidden" name="rdr_bd_action" value="force_migrate">
            <button type="submit" class="button button-primary">Force clean migration now</button>
            <em style="font-size:12px;color:#646970;margin-left:8px;">
                This will truncate stats_daily, re-import from old table, then rename old table to _archived.
            </em>
        </form>
    </div>
    <?php endif; ?>

    <?php
    // Handle force migration
    if ( isset($_POST['rdr_bd_action']) && $_POST['rdr_bd_action'] === 'force_migrate'
         && check_admin_referer('rdr_bd_force_migrate') && current_user_can('manage_options') ) {

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Truncate new tables
        $wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}rdr_bd_stats_daily" );
        $wpdb->query( "DELETE FROM {$wpdb->prefix}rdr_bd_clicks WHERE 1=1" );

        // Re-import
        $old_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}rdr_bd_stats'" );
        if ( $old_exists ) {
            $wpdb->query(
                "INSERT INTO {$wpdb->prefix}rdr_bd_stats_daily
                    (stat_date, banner_id, campaign_id, item_hash, adblock, impressions)
                 SELECT DATE(created_at), banner_id, COALESCE(campaign_id,0),
                        COALESCE(item_hash,''), adblock, SUM(COALESCE(`count`,1))
                 FROM {$wpdb->prefix}rdr_bd_stats
                 WHERE event_type = 'impression'
                 GROUP BY DATE(created_at), banner_id, COALESCE(campaign_id,0),
                          COALESCE(item_hash,''), adblock
                 ON DUPLICATE KEY UPDATE impressions = impressions + VALUES(impressions)"
            );
            $wpdb->query(
                "INSERT INTO {$wpdb->prefix}rdr_bd_clicks
                    (clicked_at, banner_id, campaign_id, item_hash, adblock, ip_hash, user_agent)
                 SELECT created_at, banner_id, COALESCE(campaign_id,0),
                        COALESCE(item_hash,''), adblock, ip_hash, user_agent
                 FROM {$wpdb->prefix}rdr_bd_stats WHERE event_type = 'click'"
            );
            $wpdb->query( "RENAME TABLE {$wpdb->prefix}rdr_bd_stats TO {$wpdb->prefix}rdr_bd_stats_archived" );
        }

        // phpcs:ignore
        $imp_total = (int) $wpdb->get_var("SELECT SUM(impressions) FROM {$wpdb->prefix}rdr_bd_stats_daily");
        // phpcs:ignore
        $clk_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rdr_bd_clicks");
        echo '<div class="notice notice-success is-dismissible"><p>';
        printf( 'Migration complete. Imported <strong>%s</strong> impressions and <strong>%s</strong> clicks into new tables.',
            number_format($imp_total), number_format($clk_total) );
        echo '</p></div>';
        // Redirect to refresh
        echo '<meta http-equiv="refresh" content="2;url=' . admin_url('admin.php?page=rdr-bd') . '">';
    }
    ?>

    <!-- Redis queue status -->
    <?php
    $queue = new RDR_BD_Queue();
    $qs    = $queue->get_queue_sizes();
    ?>
    <div class="rdr-bd-card" style="margin-bottom:14px;">
        <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
            <div>
                <strong>Redis queue:</strong>
                <?php if ( $qs['redis'] ) : ?>
                    <span style="color:#00a32a;">&#9679; Connected</span>
                    &nbsp;—&nbsp;
                    <strong><?php echo number_format($qs['impressions']); ?></strong> impressions pending,
                    <strong><?php echo number_format($qs['clicks']); ?></strong> clicks pending
                    <em style="color:#646970;font-size:12px;">(flushed to DB by cron every 5 min)</em>
                <?php else : ?>
                    <span style="color:#d63638;">&#9679; Not available</span>
                    <em style="color:#646970;font-size:12px;">— stats written directly to DB (fallback mode)</em>
                    <em style="color:#646970;font-size:12px;">Configure <code>RDR_BD_REDIS_HOST</code> in wp-config.php or install a Redis object cache plugin.</em>
                <?php endif; ?>
            </div>
            <?php if ( $qs['redis'] && ( $qs['impressions'] > 0 || $qs['clicks'] > 0 ) ) : ?>
            <form method="post" action="">
                <?php wp_nonce_field('rdr_bd_flush_now'); ?>
                <input type="hidden" name="rdr_bd_action" value="flush_queue">
                <button type="submit" class="button button-small">Flush now</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Handle manual flush -->
    <?php
    if ( isset($_POST['rdr_bd_action']) && $_POST['rdr_bd_action'] === 'flush_queue'
         && check_admin_referer('rdr_bd_flush_now') && current_user_can('manage_options') ) {
        ( new RDR_BD_Queue() )->flush();
        echo '<div class="notice notice-success is-dismissible"><p>Queue flushed to DB.</p></div>';
    }
    ?>

    <!-- Quick links -->
    <div class="rdr-bd-quick-links">
        <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=rdr_banner' ) ); ?>" class="button button-primary">+ Add Banner</a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-bd-campaigns' ) ); ?>" class="button">Manage Campaigns</a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-bd-stats' ) ); ?>" class="button">View Statistics</a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-bd-settings' ) ); ?>" class="button">Settings</a>
    </div>
</div>
