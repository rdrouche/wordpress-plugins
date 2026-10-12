<?php
/**
 * Admin page: Campaign management.
 *
 * @package RDR_Banner_Display
 */
defined( 'ABSPATH' ) || exit;

$campaign_obj = new RDR_BD_Campaign();
$campaigns    = $campaign_obj->get_all();
?>
<div class="wrap rdr-bd-wrap">
    <h1 class="rdr-bd-page-title">
        <span class="rdr-bd-logo">RDR</span><span class="rdr-bd-logo-accent">-IT</span>
        &nbsp; Campaigns
    </h1>

    <div class="rdr-bd-card">
        <div class="rdr-bd-card-header">
            <h2>All Campaigns</h2>
            <button type="button" id="rdr-bd-new-campaign" class="button button-primary">+ New Campaign</button>
        </div>

        <table class="widefat rdr-bd-table" id="rdr-bd-campaigns-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Owner</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Budget</th>
                    <th>Impressions</th>
                    <th>Clicks</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $campaigns ) ) : ?>
                <tr><td colspan="10" style="text-align:center;padding:24px;">No campaigns yet.</td></tr>
                <?php else : ?>
                    <?php foreach ( $campaigns as $c ) : ?>
                    <tr data-id="<?php echo esc_attr( $c['id'] ); ?>">
                        <td><?php echo esc_html( $c['id'] ); ?></td>
                        <td><strong><?php echo esc_html( $c['title'] ); ?></strong></td>
                        <td><span class="rdr-bd-status rdr-bd-status--<?php echo esc_attr( $c['status'] ); ?>"><?php echo esc_html( ucfirst( $c['status'] ) ); ?></span></td>
                        <td><?php echo $c['owner_name'] ? esc_html( $c['owner_name'] ) : '—'; ?></td>
                        <td><?php echo $c['start_date'] ? esc_html( substr( $c['start_date'], 0, 10 ) ) : '—'; ?></td>
                        <td><?php echo $c['end_date']   ? esc_html( substr( $c['end_date'], 0, 10 ) )   : '—'; ?></td>
                        <td><?php echo $c['budget']     ? esc_html( number_format( (float) $c['budget'], 2 ) ) . ' €' : '—'; ?></td>
                        <td><?php echo esc_html( number_format( (int) $c['impressions'] ) ); ?></td>
                        <td><?php echo esc_html( number_format( (int) $c['clicks'] ) ); ?></td>
                        <td class="rdr-bd-actions">
                            <button type="button" class="button button-small rdr-bd-edit-campaign"
                                    data-campaign='<?php echo esc_attr( wp_json_encode( $c ) ); ?>'>Edit</button>
                            <button type="button" class="button button-small rdr-bd-delete-campaign"
                                    data-id="<?php echo esc_attr( $c['id'] ); ?>">Delete</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Campaign modal -->
    <div id="rdr-bd-campaign-modal" class="rdr-bd-modal" style="display:none;">
        <div class="rdr-bd-modal-content">
            <h2 id="rdr-bd-modal-title">New Campaign</h2>
            <input type="hidden" id="rdr-bd-campaign-id" value="0">

            <div class="rdr-bd-form-grid">
                <div class="rdr-bd-field">
                    <label>Title <span class="required">*</span></label>
                    <input type="text" id="rdr-bd-campaign-title" required>
                </div>
                <div class="rdr-bd-field">
                    <label>Status</label>
                    <select id="rdr-bd-campaign-status">
                        <option value="pending">Pending</option>
                        <option value="active">Active</option>
                        <option value="paused">Paused</option>
                        <option value="ended">Ended</option>
                    </select>
                </div>
                <div class="rdr-bd-field">
                    <label>Owner (user ID)</label>
                    <input type="number" id="rdr-bd-campaign-owner" min="0">
                </div>
                <div class="rdr-bd-field">
                    <label>Budget (€)</label>
                    <input type="number" id="rdr-bd-campaign-budget" min="0" step="0.01">
                </div>
                <div class="rdr-bd-field">
                    <label>Start Date</label>
                    <input type="datetime-local" id="rdr-bd-campaign-start">
                </div>
                <div class="rdr-bd-field">
                    <label>End Date</label>
                    <input type="datetime-local" id="rdr-bd-campaign-end">
                </div>
                <div class="rdr-bd-field">
                    <label>Impression Cap</label>
                    <input type="number" id="rdr-bd-campaign-impression-cap" min="0">
                </div>
                <div class="rdr-bd-field">
                    <label>Click Cap</label>
                    <input type="number" id="rdr-bd-campaign-click-cap" min="0">
                </div>
            </div>

            <div class="rdr-bd-modal-footer">
                <button type="button" id="rdr-bd-save-campaign" class="button button-primary">Save Campaign</button>
                <button type="button" id="rdr-bd-cancel-campaign" class="button">Cancel</button>
            </div>
        </div>
    </div>
</div>
