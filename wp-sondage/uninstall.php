<?php
/**
 * Uninstall — supprime toutes les données du plugin lors de la désinstallation.
 * Ce fichier n'est exécuté que si l'utilisateur clique "Supprimer" dans l'admin WP.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

global $wpdb;

// Supprimer les tables
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wps_votes" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wps_choices" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wps_snapshots" );

// Supprimer les options
delete_option( 'wps_db_version' );
delete_option( 'wps_antiflood_minutes' );
delete_option( 'wps_results_default' );

// Supprimer les posts et leurs meta
$poll_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wps_poll'" );
foreach ( $poll_ids as $id ) {
    wp_delete_post( (int) $id, true );
}

// Supprimer les termes de la taxonomie
$terms = get_terms( [ 'taxonomy' => 'wps_category', 'hide_empty' => false ] );
if ( ! is_wp_error( $terms ) ) {
    foreach ( $terms as $term ) {
        wp_delete_term( $term->term_id, 'wps_category' );
    }
}
