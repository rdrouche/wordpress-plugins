<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Draft item structure (shared by own posts, feed items, manual links):
 * {
 *   url, title, description, image,
 *   selected: bool,
 *   note: string,          // ← personal note, shown in email if set
 *   category: string,      // ← thematic category for grouping
 *   // + type-specific fields
 * }
 */

function rdrnl_get_draft() {
    return get_option( RDRNL_DRAFT_KEY, null );
}

function rdrnl_save_draft( $draft ) {
    $draft['updated_at'] = time();
    update_option( RDRNL_DRAFT_KEY, $draft, false );
}

function rdrnl_delete_draft() {
    delete_option( RDRNL_DRAFT_KEY );
}

function rdrnl_build_draft() {
    $existing = rdrnl_get_draft();
    $settings = get_option( RDRNL_OPTION, [] );

    // Get this week's Monday — always recalculated on refresh
    // 'last Monday' would return previous week if today IS Monday, so we check first
    $now    = new DateTimeImmutable('now', wp_timezone());
    $dow    = intval($now->format('N')); // 1=Mon … 7=Sun
    $monday = $dow === 1 ? $now : $now->modify('last Monday');
    $week_label_auto = 'Semaine du ' . $monday->format('j F Y');

    // Preserve manual label if user edited it, otherwise use auto-calculated
    $existing = rdrnl_get_draft();
    $week_label = ( $existing && ! empty($existing['week_label']) )
        ? $existing['week_label']
        : $week_label_auto;

    /* ── Own posts ── */
    $own_posts = [];
    foreach ( rdrnl_fetch_own_posts() as $p ) {
        $prev = rdrnl_find_existing_item( $existing, 'own_posts', 'id', $p['id'] );
        $p['selected']  = $prev ? $prev['selected'] : true;
        $p['note']      = $prev ? ( $prev['note']      ?? '' ) : '';
        $p['category']  = $prev ? ( $prev['category']  ?? '' ) : '';
        $p['alt_links'] = $prev ? ( $prev['alt_links'] ?? [] ) : [];
        $own_posts[]    = $p;
    }

    /* ── Feed sections ── */
    $feed_sections = [];
    foreach ( rdrnl_fetch_feeds() as $section ) {
        $items = [];
        foreach ( $section['items'] as $item ) {
            $prev = rdrnl_find_existing_feed_item( $existing, $section['feed_url'], $item['url'] );
            $item['selected']  = $prev ? $prev['selected'] : $item['is_new'];
            $item['note']      = $prev ? ( $prev['note']      ?? '' ) : '';
            $item['category']  = $prev ? ( $prev['category']  ?? '' ) : '';
            $item['alt_links'] = $prev ? ( $prev['alt_links'] ?? [] ) : [];
            $items[] = $item;
        }
        $feed_sections[] = [
            'feed_label' => $section['feed_label'],
            'feed_url'   => $section['feed_url'],
            'items'      => $items,
        ];
    }

    /* ── Manual links (keep as-is) ── */
    $manual_links = $existing['manual_links'] ?? [];

    /* ── Subject ── */
    $prefix  = $settings['subject_prefix'] ?? '[RDR-IT] Weekly Recap —';
    $subject = $existing['subject'] ?? $prefix . ' ' . date( 'F j, Y' );

    $draft = [
        'created_at'       => $existing['created_at'] ?? time(),
        'updated_at'       => time(),
        'week_label'       => $week_label,        // editable in compose
        'subject'          => $subject,
        'personal_message' => $existing['personal_message'] ?? '',
        'own_posts'        => $own_posts,
        'feed_sections'    => $feed_sections,
        'manual_links'     => $manual_links,
    ];

    rdrnl_save_draft( $draft );
    return $draft;
}

/* ── Helpers ── */

function rdrnl_find_existing_item( $draft, $key, $field, $value ) {
    if ( ! $draft || empty( $draft[$key] ) ) return null;
    foreach ( $draft[$key] as $item ) {
        if ( isset($item[$field]) && (string)$item[$field] === (string)$value ) return $item;
    }
    return null;
}

function rdrnl_find_existing_feed_item( $draft, $feed_url, $item_url ) {
    if ( ! $draft || empty( $draft['feed_sections'] ) ) return null;
    foreach ( $draft['feed_sections'] as $section ) {
        if ( $section['feed_url'] !== $feed_url ) continue;
        foreach ( $section['items'] as $item ) {
            if ( $item['url'] === $item_url ) return $item;
        }
    }
    return null;
}

function rdrnl_draft_count( $draft ) {
    $n = 0;
    foreach ( $draft['own_posts']   ?? [] as $p ) { if ( $p['selected'] ) $n++; }
    foreach ( $draft['feed_sections'] ?? [] as $s ) {
        foreach ( $s['items'] as $i ) { if ( $i['selected'] ) $n++; }
    }
    $n += count( $draft['manual_links'] ?? [] );
    return $n;
}

/**
 * Collect all selected items grouped by category for the email renderer
 * Returns: [ 'CategoryName' => [ ...items ], '' => [ ...uncategorised ] ]
 */
function rdrnl_draft_get_categorized( $draft ) {
    $groups = [];

    // Own posts
    foreach ( $draft['own_posts'] ?? [] as $p ) {
        if ( ! $p['selected'] ) continue;
        $p['_source'] = 'own';
        $cat = trim( $p['category'] ?? '' ) ?: '__own__';
        $groups[$cat][] = $p;
    }

    // Manual links
    foreach ( $draft['manual_links'] ?? [] as $link ) {
        $link['_source'] = 'manual';
        $cat = trim( $link['category'] ?? '' ) ?: '__manual__';
        $groups[$cat][] = $link;
    }

    // Feed items
    foreach ( $draft['feed_sections'] ?? [] as $section ) {
        foreach ( $section['items'] as $item ) {
            if ( ! $item['selected'] ) continue;
            $item['_source']     = 'feed';
            $item['_feed_label'] = $section['feed_label'];
            $cat = trim( $item['category'] ?? '' ) ?: '__feed_' . $section['feed_label'] . '__';
            $groups[$cat][] = $item;
        }
    }

    return $groups;
}


/* ============================================================
   SHARED HELPERS  (used by email template + edition.php)
   ============================================================ */

function rdrnl_section_label( $key ) {
    if ( $key === '__own__' )     return 'Nouveautés RDR-IT';
    if ( $key === '__manual__' )  return 'À lire aussi';
    if ( str_starts_with($key,'__feed_') ) return trim( str_replace(['__feed_','__'], '', $key) );
    return $key;
}

function rdrnl_is_external( $url ) {
    return ! str_starts_with( $url, home_url() );
}

function rdrnl_ext_attrs( $url ) {
    return rdrnl_is_external($url) ? ' rel="nofollow" target="_blank"' : '';
}
