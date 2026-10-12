<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Shared feed cache.
 * Multiple subscribers with the same feed URL share the same cached result.
 * TTL: 15 minutes. Key: md5 of the URL.
 */

define( 'RDRNL_FEED_CACHE_TTL', 15 * MINUTE_IN_SECONDS );

/**
 * Get cached feed items for a URL.
 * Returns array of items or false.
 */
function rdrnl_feed_cache_get( $url ) {
    $key = 'rdrnl_feed_' . md5( esc_url_raw( $url ) );
    $cached = get_transient( $key );
    return $cached !== false ? $cached : false;
}

/**
 * Store feed items in cache.
 */
function rdrnl_feed_cache_set( $url, $items ) {
    $key = 'rdrnl_feed_' . md5( esc_url_raw( $url ) );
    set_transient( $key, $items, RDRNL_FEED_CACHE_TTL );
}

/**
 * Parse a feed URL with cache.
 * Used by both admin compose and subscriber personalisation.
 */
function rdrnl_parse_feed_cached( $url, $limit = 10 ) {
    $url   = esc_url_raw( $url );
    $limit = min( intval( $limit ), 10 ); // hard cap at 10

    $cached = rdrnl_feed_cache_get( $url );
    if ( $cached !== false ) {
        return array_slice( $cached, 0, $limit );
    }

    // Full fetch (up to 10, the maximum)
    if ( ! function_exists( 'fetch_feed' ) ) require_once ABSPATH . WPINC . '/feed.php';

    add_filter( 'wp_feed_cache_transient_lifetime', function() { return RDRNL_FEED_CACHE_TTL; } );

    $feed = fetch_feed( $url );
    if ( is_wp_error( $feed ) ) {
        rdrnl_feed_cache_set( $url, [] ); // cache the failure briefly
        return [];
    }

    $raw   = $feed->get_items( 0, $feed->get_item_quantity( 10 ) );
    $week  = strtotime( '-7 days' );
    $items = [];

    foreach ( $raw as $item ) {
        $ts      = $item->get_date('U');
        $items[] = [
            'title'       => wp_strip_all_tags( $item->get_title() ),
            'url'         => esc_url( $item->get_permalink() ),
            'description' => wp_trim_words( wp_strip_all_tags( $item->get_description() ), 25, '…' ),
            'date'        => $item->get_date('Y-m-d'),
            'date_ts'     => $ts,
            'is_new'      => ( $ts && $ts >= $week ),
            'image'       => rdrnl_extract_feed_image( $item ),
        ];
    }

    rdrnl_feed_cache_set( $url, $items );
    return array_slice( $items, 0, $limit );
}

/**
 * Validate a user-provided feed URL.
 * Returns [ 'ok' => bool, 'title' => string, 'error' => string ]
 */
function rdrnl_validate_feed_url( $url ) {
    $url = esc_url_raw( $url );
    if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
        return [ 'ok' => false, 'title' => '', 'error' => 'URL invalide.' ];
    }

    if ( ! function_exists( 'fetch_feed' ) ) require_once ABSPATH . WPINC . '/feed.php';
    $feed = fetch_feed( $url );

    if ( is_wp_error( $feed ) ) {
        return [ 'ok' => false, 'title' => '', 'error' => 'Impossible de lire ce flux RSS : ' . $feed->get_error_message() ];
    }

    return [ 'ok' => true, 'title' => wp_strip_all_tags( $feed->get_title() ), 'error' => '' ];
}
