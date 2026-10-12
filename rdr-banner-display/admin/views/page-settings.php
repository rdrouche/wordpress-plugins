<?php
/**
 * Admin page: Settings.
 *
 * @package RDR_Banner_Display
 */
defined( 'ABSPATH' ) || exit;

$no_ads_types   = (array) get_option( 'rdr_bd_no_ads_allowed_types', [ 'html' ] );
$no_ads_key     = get_option( 'rdr_bd_no_ads_key',  'wpa_no_ads' );
$no_ads_mode    = get_option( 'rdr_bd_no_ads_mode', 'capability' );
$stats_enabled  = get_option( 'rdr_bd_stats_enabled', '1' );
$retention      = (int) get_option( 'rdr_bd_stats_retention_days', 90 );
$adblock_delay  = (int) get_option( 'rdr_bd_adblock_delay', 2500 );
$skel_bg        = get_option( 'rdr_bd_skeleton_bg',    '#1c2128' );
$skel_shine     = get_option( 'rdr_bd_skeleton_shine', '#2ea8ff' );

global $wp_roles;
$roles_list = $wp_roles->get_names();
?>
<div class="wrap rdr-bd-wrap">
    <h1 class="rdr-bd-page-title">
        <span class="rdr-bd-logo">RDR</span><span class="rdr-bd-logo-accent">-IT</span>
        &nbsp; Settings
    </h1>

    <?php settings_errors( 'rdr_bd_settings_group' ); ?>

    <form method="post" action="options.php">
        <?php settings_fields( 'rdr_bd_settings_group' ); ?>

        <!-- Statistics -->
        <div class="rdr-bd-card">
            <h2>Statistics</h2>
            <table class="form-table">
                <tr>
                    <th>Enable Statistics</th>
                    <td>
                        <label>
                            <input type="checkbox" name="rdr_bd_stats_enabled" value="1" <?php checked( $stats_enabled, '1' ); ?>>
                            Track impressions and clicks
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Retention Period (days)</th>
                    <td>
                        <input type="number" name="rdr_bd_stats_retention_days"
                               value="<?php echo esc_attr( $retention ); ?>" min="1" max="3650">
                        <p class="description">Stats older than this will be automatically deleted. Set to 0 to keep forever.</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- No-Ads Users -->
        <div class="rdr-bd-card">
            <h2>No-Ads Users</h2>
            <table class="form-table">
                <tr>
                    <th>Detection Mode</th>
                    <td>
                        <fieldset>
                            <label style="display:block;margin-bottom:6px;">
                                <input type="radio" name="rdr_bd_no_ads_mode" value="capability"
                                       <?php checked( $no_ads_mode, 'capability' ); ?>>
                                <strong>WordPress Capability</strong> — <code>user_can( $user_id, '<?php echo esc_html( $no_ads_key ); ?>' )</code>
                                <p class="description" style="margin-left:22px;">Use this when the "no ads" flag is set as a <strong>capability</strong> by a membership/account plugin (e.g. WPA, MemberPress, etc.). This is the correct mode for <code>wpa_no_ads</code>.</p>
                            </label>
                            <label style="display:block;margin-top:8px;">
                                <input type="radio" name="rdr_bd_no_ads_mode" value="meta"
                                       <?php checked( $no_ads_mode, 'meta' ); ?>>
                                <strong>User Meta</strong> — <code>get_user_meta( $user_id, '<?php echo esc_html( $no_ads_key ); ?>', true )</code>
                                <p class="description" style="margin-left:22px;">Use this when the flag is stored as a <strong>user meta value</strong> set directly with <code>update_user_meta()</code>.</p>
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th>Capability / Meta Key</th>
                    <td>
                        <input type="text" name="rdr_bd_no_ads_key"
                               value="<?php echo esc_attr( $no_ads_key ); ?>"
                               class="regular-text">
                        <p class="description">
                            The capability name or meta key that identifies "no ads" users.<br>
                            Default: <code>wpa_no_ads</code>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Allowed Content Types for No-Ads Users</th>
                    <td>
                        <label>
                            <input type="checkbox" name="rdr_bd_no_ads_allowed_types[]"
                                   value="html" <?php checked( in_array( 'html', $no_ads_types, true ) ); ?>>
                            HTML
                        </label><br>
                        <label>
                            <input type="checkbox" name="rdr_bd_no_ads_allowed_types[]"
                                   value="shortcode" <?php checked( in_array( 'shortcode', $no_ads_types, true ) ); ?>>
                            WordPress Shortcode
                        </label>
                        <p class="description">AdSense is never shown to no-ads users. Choose which other content types they may see (items marked "No-ads users only").</p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Adblock Detection -->
        <div class="rdr-bd-card">
            <h2>Adblock Detection</h2>
            <table class="form-table">
                <tr>
                    <th>Detection Delay (ms)</th>
                    <td>
                        <input type="number" name="rdr_bd_adblock_delay"
                               value="<?php echo esc_attr( $adblock_delay ); ?>"
                               min="0" max="10000" step="100">
                        <p class="description">
                            Delay before the banner AJAX request fires, allowing adblock detection
                            scripts (e.g. BlockAdBlock) to initialise and set <code>window.isAdblockTrue</code>.<br>
                            <strong>0</strong> = instant. Recommended: <strong>2500</strong> ms.<br>
                            Bots always bypass this delay server-side.
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Skeleton / Layout -->
        <div class="rdr-bd-card">
            <h2>Skeleton / Layout</h2>
            <table class="form-table">
                <tr>
                    <th>Skeleton Background Color</th>
                    <td><input type="color" name="rdr_bd_skeleton_bg" value="<?php echo esc_attr( $skel_bg ); ?>"></td>
                </tr>
                <tr>
                    <th>Skeleton Shine Color</th>
                    <td><input type="color" name="rdr_bd_skeleton_shine" value="<?php echo esc_attr( $skel_shine ); ?>"></td>
                </tr>
            </table>
        </div>

        <!-- Advertiser Role -->
        <div class="rdr-bd-card">
            <h2>Advertiser Role</h2>
            <p>Grant the <code>rdr_bd_advertiser</code> capability to a role so users can submit campaign requests from the front end.</p>
            <div class="rdr-bd-role-manager">
                <?php foreach ( $roles_list as $role_slug => $role_label ) :
                    $role_obj = get_role( $role_slug );
                    $has_cap  = $role_obj && $role_obj->has_cap( RDR_BD_Capabilities::CAP_ADVERTISER );
                ?>
                <label>
                    <input type="checkbox" class="rdr-bd-role-cap"
                           data-role="<?php echo esc_attr( $role_slug ); ?>"
                           <?php checked( $has_cap ); ?>>
                    <?php echo esc_html( translate_user_role( $role_label ) ); ?>
                    <code><?php echo esc_html( $role_slug ); ?></code>
                </label>
                <?php endforeach; ?>
            </div>
            <p class="description" style="margin-top:10px;">Changes are saved immediately via AJAX when you toggle a checkbox.</p>
        </div>

        <?php submit_button( 'Save Settings' ); ?>
    </form>
</div>
