<?php
/**
 * Helper functions.
 *
 * @package RDR_Banner_Display
 */

defined( 'ABSPATH' ) || exit;

function rdr_bd_is_bot(): bool {
    $ua = strtolower( $_SERVER['HTTP_USER_AGENT'] ?? '' );
    if ( empty( $ua ) ) return false;
    $patterns = [
        'googlebot','bingbot','slurp','duckduckbot','baiduspider','yandexbot',
        'facebot','ia_archiver','msnbot','teoma','semrushbot','ahrefsbot',
        'dotbot','rogerbot','exabot','gigabot','sogou','facebookexternalhit',
        'linkedinbot','twitterbot','applebot','petalbot','bytespider','gptbot',
        'anthropic-ai','claude-web','ccbot','omgili','scrapy','python-requests',
        'curl/','wget/','libwww-perl','java/','go-http-client',
    ];
    foreach ( $patterns as $p ) { if ( str_contains( $ua, $p ) ) return true; }
    return false;
}

function rdr_bd_user_has_no_ads( ?int $user_id = null ): bool {
    if ( null === $user_id ) $user_id = get_current_user_id();
    if ( ! $user_id ) return false;
    $key  = get_option( 'rdr_bd_no_ads_key',  'wpa_no_ads' );
    $mode = get_option( 'rdr_bd_no_ads_mode', 'capability' );
    return $mode === 'meta'
        ? (bool) get_user_meta( $user_id, $key, true )
        : user_can( $user_id, $key );
}

function rdr_bd_no_ads_allowed_types(): array {
    $types = get_option( 'rdr_bd_no_ads_allowed_types', [ 'html' ] );
    return is_array( $types ) ? $types : [ 'html' ];
}

/**
 * Read _rdr_bd_items directly from DB, bypassing WP object cache entirely.
 *
 * We bypass get_post_meta() because:
 * 1. We write via $wpdb->update() (not update_post_meta) to skip sanitize_callback.
 * 2. WP's postmeta object cache may return a stale value after our direct write,
 *    even after wp_cache_delete() / clean_post_cache() — especially with Redis.
 *
 * Using $wpdb->get_var() always reads the current DB value.
 * We add our own short-lived per-request cache on top.
 */
function rdr_bd_get_items( int $banner_id ): array {
    // Per-request cache (avoids repeated SQL within the same PHP process)
    static $cache = [];
    if ( isset( $cache[ $banner_id ] ) ) return $cache[ $banner_id ];

    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $raw = $wpdb->get_var( $wpdb->prepare(
        "SELECT meta_value FROM {$wpdb->postmeta}
         WHERE post_id = %d AND meta_key = '_rdr_bd_items'
         LIMIT 1",
        $banner_id
    ) );

    $items = rdr_bd_decode_items_json( $raw );
    $cache[ $banner_id ] = $items;
    return $items;
}

/**
 * Decode items JSON — tries direct then stripslashes fallback.
 */
function rdr_bd_decode_items_json( ?string $raw ): array {
    if ( empty( $raw ) || $raw === '[]' ) return [];
    $items = json_decode( $raw, true );
    if ( is_array( $items ) ) return $items;
    // Fallback: WP may have slashed the value on some server configs
    $items = json_decode( stripslashes( $raw ), true );
    return is_array( $items ) ? $items : [];
}

/**
 * Render a single item.
 */
function rdr_bd_render_item( array $item ): string {
    $type    = $item['type']    ?? '';
    $content = $item['content'] ?? '';
    if ( trim( $content ) === '' ) return '';
    switch ( $type ) {
        case 'html':      return wp_kses( $content, rdr_bd_kses_html_allowed() );
        case 'adsense':   return $content;
        case 'shortcode': return do_shortcode( wp_kses_post( $content ) );
        default:          return '';
    }
}

function rdr_bd_kses_html_allowed(): array {
    $allowed = wp_kses_allowed_html( 'post' );
    $global  = [ 'id'=>true,'class'=>true,'style'=>true,'title'=>true,'role'=>true,'data-*'=>true,'aria-label'=>true,'aria-hidden'=>true ];
    foreach ( [ 'div','span','p','figure','picture','source' ] as $tag ) {
        $allowed[$tag] = array_merge( $allowed[$tag] ?? [], $global );
    }
    $allowed['a']   = array_merge( $allowed['a']   ?? [], [ 'href'=>true,'target'=>true,'rel'=>true,'title'=>true,'class'=>true,'style'=>true ] );
    $allowed['img'] = array_merge( $allowed['img'] ?? [], [ 'src'=>true,'srcset'=>true,'sizes'=>true,'alt'=>true,'title'=>true,'width'=>true,'height'=>true,'class'=>true,'style'=>true,'loading'=>true,'decoding'=>true ] );
    return $allowed;
}

function rdr_bd_hash_ip( string $ip ): string {
    $salt = defined( 'AUTH_SALT' ) ? AUTH_SALT : 'rdr-bd-salt';
    return hash( 'sha256', $salt . $ip );
}
