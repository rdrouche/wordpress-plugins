<?php
/**
 * Uninstall — runs when the plugin is deleted from WP admin.
 *
 * WordPress calls this file automatically when a plugin is deleted.
 * It does NOT run on deactivation.
 *
 * Rules:
 *  - Drop all custom tables
 *  - Delete all plugin options
 *  - Delete all post meta for it_problem and it_answer posts
 *  - Do NOT delete the posts themselves (user data — let admin decide)
 *
 * @package WP_IT_Problem
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// ---------------------------------------------------------------------------
// Drop tables
// ---------------------------------------------------------------------------

$tables = [
    $wpdb->prefix . 'itpb_subscriptions',
    $wpdb->prefix . 'itpb_reveal_log',
];

foreach ( $tables as $table ) {
    // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
    $wpdb->query( "DROP TABLE IF EXISTS `$table`" );
}

// ---------------------------------------------------------------------------
// Delete options
// ---------------------------------------------------------------------------

$options = [
    'itpb_db_version',
    'itpb_default_reveal_days',
    'itpb_show_countdown',
    'itpb_pll_sync',
];

foreach ( $options as $option ) {
    delete_option( $option );
}

// ---------------------------------------------------------------------------
// Delete transients
// ---------------------------------------------------------------------------

// Reveal status transients are keyed: itpb_status_{problem_id}
// We delete them via a prefix scan
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '_transient_itpb_%'
        OR option_name LIKE '_transient_timeout_itpb_%'"
);

// ---------------------------------------------------------------------------
// Unschedule cron
// ---------------------------------------------------------------------------

$timestamp = wp_next_scheduled( 'itpb_daily_reveal' );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, 'itpb_daily_reveal' );
}
