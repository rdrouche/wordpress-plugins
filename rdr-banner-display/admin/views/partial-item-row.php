<?php
/**
 * Partial: one content item row.
 * Uses native HTML form fields with name="rdr_bd_item[N][field]".
 * PHP reads them directly from $_POST — zero JS dependency for saving.
 *
 * Flag semantics:
 *   for_no_ads       → visible ONLY to users with the no-ads capability
 *   adblock_only     → visible ONLY to users WITH an adblocker
 *   force_on_adblock → AdSense: serve even when adblocker detected
 *
 * @package RDR_Banner_Display
 */
defined( 'ABSPATH' ) || exit;

$type             = $item['type']             ?? 'html';
$content          = $item['content']          ?? '';
$campaign_id      = (int) ( $item['campaign_id']      ?? 0 );
$for_no_ads       = (bool) ( $item['for_no_ads']       ?? false );
$adblock_only     = (bool) ( $item['adblock_only']     ?? false );
$force_on_adblock = (bool) ( $item['force_on_adblock'] ?? false );
$impression_cap   = isset( $item['impression_cap'] ) && $item['impression_cap'] > 0 ? (int) $item['impression_cap'] : '';
$click_cap        = isset( $item['click_cap'] )      && $item['click_cap']      > 0 ? (int) $item['click_cap']      : '';
$n                = (int) $index; // sequential index for field names
?>
<div class="rdr-bd-item-row">

    <div class="rdr-bd-item-header">

        <select name="rdr_bd_item[<?php echo $n; ?>][type]" class="rdr-bd-item-type">
            <option value="html"      <?php selected( $type, 'html' ); ?>>HTML</option>
            <option value="adsense"   <?php selected( $type, 'adsense' ); ?>>Google AdSense</option>
            <option value="shortcode" <?php selected( $type, 'shortcode' ); ?>>WordPress Shortcode</option>
        </select>

        <label class="rdr-bd-item-flag" title="Show ONLY to users with the no-ads capability">
            <input type="checkbox"
                   name="rdr_bd_item[<?php echo $n; ?>][for_no_ads]"
                   value="1" <?php checked( $for_no_ads ); ?>>
            <?php esc_html_e( 'No-ads only', 'rdr-banner-display' ); ?>
        </label>

        <label class="rdr-bd-item-flag rdr-bd-flag--adblock-only"
               title="Show ONLY to users who have an adblocker. Regular users will NOT see this item.">
            <input type="checkbox"
                   name="rdr_bd_item[<?php echo $n; ?>][adblock_only]"
                   value="1" <?php checked( $adblock_only ); ?>>
            <?php esc_html_e( 'Adblock only', 'rdr-banner-display' ); ?>
        </label>

        <label class="rdr-bd-item-flag rdr-bd-flag--force-adblock"
               title="AdSense: force display even when adblocker detected">
            <input type="checkbox"
                   name="rdr_bd_item[<?php echo $n; ?>][force_on_adblock]"
                   value="1" <?php checked( $force_on_adblock ); ?>>
            <?php esc_html_e( 'Force if adblock', 'rdr-banner-display' ); ?>
        </label>

        <div class="rdr-bd-item-caps">
            <label class="rdr-bd-cap-label" title="Stop after N impressions (0 = unlimited)">
                <span><?php esc_html_e( 'Imp. cap', 'rdr-banner-display' ); ?></span>
                <input type="number" class="small-text"
                       name="rdr_bd_item[<?php echo $n; ?>][impression_cap]"
                       value="<?php echo esc_attr( $impression_cap ); ?>" min="0" placeholder="∞">
            </label>
            <label class="rdr-bd-cap-label" title="Stop after N clicks (0 = unlimited)">
                <span><?php esc_html_e( 'Click cap', 'rdr-banner-display' ); ?></span>
                <input type="number" class="small-text"
                       name="rdr_bd_item[<?php echo $n; ?>][click_cap]"
                       value="<?php echo esc_attr( $click_cap ); ?>" min="0" placeholder="∞">
            </label>
        </div>

        <button type="button" class="rdr-bd-remove-item button-link-delete">
            &#x2715; <?php esc_html_e( 'Remove', 'rdr-banner-display' ); ?>
        </button>
    </div>

    <!-- Campaign -->
    <div class="rdr-bd-item-campaign">
        <label class="rdr-bd-item-campaign-label">
            <?php esc_html_e( 'Campaign:', 'rdr-banner-display' ); ?>
            <select name="rdr_bd_item[<?php echo $n; ?>][campaign_id]">
                <option value="0" <?php selected( $campaign_id, 0 ); ?>><?php esc_html_e( '— Standalone —', 'rdr-banner-display' ); ?></option>
                <?php foreach ( $campaigns as $c ) :
                    $label = esc_html( $c['title'] );
                    if ( $c['status'] !== 'active' ) $label .= ' [' . esc_html( $c['status'] ) . ']';
                ?>
                <option value="<?php echo esc_attr( $c['id'] ); ?>" <?php selected( $campaign_id, (int) $c['id'] ); ?>>
                    <?php echo $label; ?>
                </option>
                <?php endforeach; ?>
            </select>
        </label>
    </div>

    <!-- Content — use base64 in a hidden field to survive WordPress slashing -->
    <input type="hidden"
           name="rdr_bd_item[<?php echo $n; ?>][content_b64]"
           class="rdr-bd-content-b64"
           value="<?php echo esc_attr( base64_encode( $content ) ); ?>">
    <textarea class="rdr-bd-item-content"
              rows="6"
              placeholder="<?php esc_attr_e( 'Paste your HTML / AdSense code / shortcode here', 'rdr-banner-display' ); ?>"><?php echo esc_textarea( $content ); ?></textarea>

</div>
