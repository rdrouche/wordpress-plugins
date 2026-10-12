<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/* Admin-configured feeds for the newsletter compose */
function rdrnl_fetch_feeds() {
    $settings     = get_option( RDRNL_OPTION, [] );
    $feeds        = $settings['feeds'] ?? [];
    $global_limit = intval( $settings['items_per_feed'] ?? 5 ); // fallback for old entries
    $results      = [];

    foreach ( $feeds as $feed ) {
        if ( empty( $feed['url'] ) || empty( $feed['active'] ) ) continue;
        // Per-feed limit takes priority over global setting
        $limit = isset($feed['limit']) && intval($feed['limit']) > 0
            ? min(20, intval($feed['limit']))
            : $global_limit;
        $items = rdrnl_parse_feed_cached( $feed['url'], $limit );
        if ( ! empty( $items ) ) {
            $results[] = [
                'feed_label' => $feed['label'] ?: $feed['url'],
                'feed_url'   => $feed['url'],
                'items'      => $items,
            ];
        }
    }
    return $results;
}

/* Extract image from feed item — used by rdrnl_parse_feed_cached in feed-cache.php */
function rdrnl_extract_feed_image( $item ) {
    $enc = $item->get_enclosure();
    if ( $enc && $enc->get_link() && preg_match('/\.(jpe?g|png|webp|gif)/i', $enc->get_link()) )
        return esc_url( $enc->get_link() );

    $media = $item->get_item_tags('http://search.yahoo.com/mrss/','content');
    if ( ! empty($media[0]['attribs']['']['url']) )
        return esc_url( $media[0]['attribs']['']['url'] );

    $content = $item->get_content() ?: $item->get_description();
    if ( $content && preg_match('/<img[^>]+src=["\']([^"\']+)["\']/', $content, $m)
         && filter_var($m[1], FILTER_VALIDATE_URL) )
        return esc_url( $m[1] );

    return '';
}

/**
 * Fetch own site posts — FR only (Polylang aware), within last 7 days.
 * Returns all posts regardless of selection; draft.php handles defaults.
 */
function rdrnl_fetch_own_posts() {
    $settings   = get_option( RDRNL_OPTION, [] );
    $post_types = $settings['post_types'] ?? ['post'];

    $args = [
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => 30,
        'date_query'     => [[ 'after' => '7 days ago', 'inclusive' => true ]],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];

    // Polylang: restrict to French
    if ( function_exists('pll_get_post_language') ) {
        $args['lang'] = 'fr';
    }

    $query = new WP_Query( $args );
    $posts = [];

    foreach ( $query->posts as $p ) {
        $cats = wp_get_post_terms( $p->ID, 'category', ['fields' => 'names'] );
        $posts[] = [
            'id'          => $p->ID,
            'title'       => get_the_title( $p->ID ),
            'url'         => get_permalink( $p->ID ),
            'description' => wp_trim_words( get_the_excerpt( $p->ID ), 25, '…' ),
            'date'        => get_the_date( 'Y-m-d', $p->ID ),
            'date_ts'     => get_the_time( 'U', $p->ID ),
            'image'       => get_the_post_thumbnail_url( $p->ID, 'medium' ) ?: '',
            'categories'  => is_wp_error($cats) ? [] : $cats,
            'post_type'   => get_post_type( $p->ID ),
        ];
    }

    return $posts;
}

/**
 * AJAX: search posts by keyword for the compose article selector.
 * Returns id, title, url, date, post_type for autocomplete.
 */
add_action('wp_ajax_rdrnl_search_posts', 'rdrnl_ajax_search_posts');
function rdrnl_ajax_search_posts() {
    check_ajax_referer('rdrnl_admin','nonce');
    if ( ! current_user_can('manage_options') ) wp_send_json_error('Unauthorized');

    $q          = sanitize_text_field( $_POST['q'] ?? '' );
    $settings   = get_option( RDRNL_OPTION, [] );
    $post_types = $settings['post_types'] ?? ['post'];

    $args = [
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => 15,
        's'              => $q,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ];
    if ( function_exists('pll_get_post_language') ) $args['lang'] = 'fr';

    $results = [];
    foreach ( (new WP_Query($args))->posts as $p ) {
        $results[] = [
            'id'        => $p->ID,
            'title'     => get_the_title($p->ID),
            'url'       => get_permalink($p->ID),
            'date'      => get_the_date('Y-m-d',$p->ID),
            'image'     => get_the_post_thumbnail_url($p->ID,'thumbnail') ?: '',
            'post_type' => get_post_type($p->ID),
        ];
    }

    wp_send_json_success($results);
}
