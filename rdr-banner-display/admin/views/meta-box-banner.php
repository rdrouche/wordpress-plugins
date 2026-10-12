<?php
/**
 * Admin view: Banner meta box.
 * Items are submitted as individual POST fields (no JS JSON serialisation).
 * PHP reads them directly — zero JS dependency for saving.
 *
 * @package RDR_Banner_Display
 */
defined( 'ABSPATH' ) || exit;
?>
<div class="rdr-bd-meta-box"
     data-post-id="<?php echo (int) $post->ID; ?>"
     data-campaigns="<?php echo esc_attr( wp_json_encode( $campaigns, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); ?>">

    <!-- Dimensions + mode -->
    <div class="rdr-bd-row">
        <div class="rdr-bd-field">
            <label for="rdr_bd_width"><?php esc_html_e( 'Width (px)', 'rdr-banner-display' ); ?></label>
            <input type="number" id="rdr_bd_width" name="rdr_bd_width"
                   value="<?php echo esc_attr( $width ); ?>" min="1" max="2000">
        </div>
        <div class="rdr-bd-field">
            <label for="rdr_bd_height"><?php esc_html_e( 'Height (px)', 'rdr-banner-display' ); ?></label>
            <input type="number" id="rdr_bd_height" name="rdr_bd_height"
                   value="<?php echo esc_attr( $height ); ?>" min="1" max="2000">
        </div>
        <div class="rdr-bd-field">
            <label for="rdr_bd_mode"><?php esc_html_e( 'Display Mode', 'rdr-banner-display' ); ?></label>
            <select id="rdr_bd_mode" name="rdr_bd_mode">
                <option value="single" <?php selected( $mode, 'single' ); ?>><?php esc_html_e( 'Always first', 'rdr-banner-display' ); ?></option>
                <option value="random" <?php selected( $mode, 'random' ); ?>><?php esc_html_e( 'Random', 'rdr-banner-display' ); ?></option>
            </select>
        </div>
    </div>

    <!-- Internal note -->
    <div class="rdr-bd-field rdr-bd-field--full">
        <label for="rdr_bd_note"><?php esc_html_e( 'Internal Note (memo)', 'rdr-banner-display' ); ?></label>
        <textarea id="rdr_bd_note" name="rdr_bd_note" rows="2"
                  placeholder="<?php esc_attr_e( 'Private note — placement, client, expiry...', 'rdr-banner-display' ); ?>"><?php echo esc_textarea( $note ); ?></textarea>
    </div>

    <hr>

    <!-- Content items -->
    <div class="rdr-bd-section-header">
        <h3 class="rdr-bd-section-title">
            <?php esc_html_e( 'Content Items', 'rdr-banner-display' ); ?>
            <span class="rdr-bd-hint"><?php esc_html_e( 'In "Random" mode a random eligible item is served each page load.', 'rdr-banner-display' ); ?></span>
        </h3>
        <button type="button" id="rdr-bd-add-item" class="button button-secondary">
            + <?php esc_html_e( 'Add Content Item', 'rdr-banner-display' ); ?>
        </button>
    </div>

    <?php
    // ── Campaign status warning ─────────────────────────────────────────────
    // Warn if any item is linked to a non-active campaign — it won't serve.
    $inactive_campaigns = [];
    foreach ( $items as $item ) {
        $cid = (int) ( $item['campaign_id'] ?? 0 );
        if ( ! $cid ) continue;
        foreach ( $campaigns as $c ) {
            if ( (int) $c['id'] === $cid && $c['status'] !== 'active' ) {
                $inactive_campaigns[ $cid ] = $c['title'] . ' [' . $c['status'] . ']';
            }
        }
    }
    if ( ! empty( $inactive_campaigns ) ) : ?>
    <div class="notice notice-warning inline" style="margin:8px 0 12px;">
        <p>
            <strong>⚠ <?php esc_html_e( 'Inactive campaign linked to items:', 'rdr-banner-display' ); ?></strong>
            <?php echo esc_html( implode( ', ', $inactive_campaigns ) ); ?>.
            <?php esc_html_e( 'Items linked to non-active campaigns will NOT be served. Set the campaign status to "Active" in', 'rdr-banner-display' ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-bd-campaigns' ) ); ?>">
                <?php esc_html_e( 'Campaigns', 'rdr-banner-display' ); ?>
            </a>.
        </p>
    </div>
    <?php endif; ?>

    <div id="rdr-bd-items-wrapper">
        <?php
        foreach ( $items as $index => $item ) {
            // Use a closure to isolate variable scope — prevents $content, $type etc.
            // from leaking between iterations when using include.
            ( static function ( $index, $item, $campaigns ) {
                include __DIR__ . '/partial-item-row.php';
            } )( $index, $item, $campaigns );
        }
        ?>
    </div>

    <!-- Preview + shortcode footer -->
    <div class="rdr-bd-meta-footer">
        <div class="rdr-bd-preview-bar">
            <button type="button" id="rdr-bd-preview-btn" class="button button-secondary">
                &#128065; <?php esc_html_e( 'Preview stored content', 'rdr-banner-display' ); ?>
            </button>
            <span class="rdr-bd-preview-hint"><?php esc_html_e( 'Shows what is currently saved in the database (save first)', 'rdr-banner-display' ); ?></span>
        </div>
        <div class="rdr-bd-shortcode-hint">
            <strong><?php esc_html_e( 'Shortcode:', 'rdr-banner-display' ); ?></strong>
            <code>[rdr-dif-ban id="<?php echo (int) $post->ID; ?>"]</code>
        </div>
    </div>

    <div id="rdr-bd-preview-output" style="display:none;"></div>

</div>
