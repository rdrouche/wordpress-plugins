<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function rdrnl_fetch_og( $url ) {
    $url = esc_url_raw( $url );
    if ( empty( $url ) ) return false;

    // ── Lien interne : utilise les données WP directement ──
    if ( str_starts_with( $url, home_url() ) ) {
        $post_id = url_to_postid( $url );
        if ( $post_id ) {
            $thumb = get_the_post_thumbnail_url( $post_id, 'medium' );
            if ( ! $thumb ) {
                $post    = get_post( $post_id );
                $content = $post->post_content ?? '';
                if ( preg_match( '/<img[^>]+src=["\x27]([^"\x27]+)["\x27]/i', $content, $m ) ) {
                    $thumb = $m[1];
                }
            }
            return [
                'url'         => $url,
                'title'       => get_the_title( $post_id ),
                'description' => wp_trim_words( get_the_excerpt( $post_id ), 25, '...' ),
                'image'       => $thumb ?: '',
                'site_name'   => get_bloginfo( 'name' ),
            ];
        }
    }

    // ── URL externe : fetch HTTP ──
    $response = wp_remote_get( $url, [
        'timeout'    => 8,
        'user-agent' => 'Mozilla/5.0 (compatible; RDR-IT Newsletter Bot/1.0)',
        'sslverify'  => false,
    ]);
    if ( is_wp_error( $response ) ) return false;

    $html = wp_remote_retrieve_body( $response );
    if ( empty( $html ) ) return false;

    $data = [
        'url'         => $url,
        'title'       => '',
        'description' => '',
        'image'       => '',
        'site_name'   => '',
    ];

    // Parse <head>
    $head = '';
    if ( preg_match( '/<head[^>]*>(.*?)<\/head>/is', $html, $m ) ) {
        $head = $m[1];
    } else {
        $head = substr( $html, 0, 10000 );
    }

    // Toutes les balises <meta>
    preg_match_all( '/<meta[^>]+>/i', $head, $metas );
    foreach ( $metas[0] as $tag ) {
        $prop    = '';
        $content = '';

        if      ( preg_match( '/(?:property|name)=["\']og:title["\']/i',       $tag ) ) $prop = 'title';
        elseif  ( preg_match( '/(?:property|name)=["\']og:description["\']/i', $tag ) ) $prop = 'description';
        elseif  ( preg_match( '/(?:property|name)=["\']og:image["\']/i',       $tag ) ) $prop = 'image';
        elseif  ( preg_match( '/(?:property|name)=["\']og:site_name["\']/i',   $tag ) ) $prop = 'site_name';
        elseif  ( preg_match( '/name=["\']twitter:title["\']/i',               $tag ) ) $prop = 'title';
        elseif  ( preg_match( '/name=["\']twitter:description["\']/i',         $tag ) ) $prop = 'description';
        elseif  ( preg_match( '/name=["\']twitter:image(?::src)?["\']/i',      $tag ) ) $prop = 'image';
        elseif  ( preg_match( '/name=["\']description["\']/i',                 $tag ) ) $prop = 'description_meta';

        if ( $prop && preg_match( '/content=["\']([^"\']*)["\']/', $tag, $cm ) ) {
            $content = html_entity_decode( $cm[1], ENT_QUOTES, 'UTF-8' );
        }

        switch ( $prop ) {
            case 'title':            if ( ! $data['title'] )       $data['title']       = $content; break;
            case 'description':      if ( ! $data['description'] ) $data['description'] = $content; break;
            case 'image':            if ( ! $data['image'] )       $data['image']       = $content; break;
            case 'site_name':        if ( ! $data['site_name'] )   $data['site_name']   = $content; break;
            case 'description_meta': if ( ! $data['description'] ) $data['description'] = $content; break;
        }
    }

    // Fallback titre : balise <title>
    if ( empty( $data['title'] ) && preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $head, $tm ) ) {
        $data['title'] = html_entity_decode( trim( $tm[1] ), ENT_QUOTES, 'UTF-8' );
    }

    // Fallback image : première <img> dans le body (hors GIF/SVG)
    if ( empty( $data['image'] ) ) {
        $body_start = strpos( $html, '<body' );
        $body_part  = substr( $html, $body_start !== false ? $body_start : 0, 20000 );
        preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $body_part, $imgs );
        foreach ( $imgs[1] as $src ) {
            if ( preg_match( '/\.(gif|svg)/i', $src ) ) continue;
            if ( filter_var( $src, FILTER_VALIDATE_URL ) ) {
                $data['image'] = $src;
                break;
            }
        }
    }

    // Fallback site_name : domaine
    if ( empty( $data['site_name'] ) ) {
        $parsed = parse_url( $url );
        $data['site_name'] = isset( $parsed['host'] )
            ? preg_replace( '/^www\./', '', $parsed['host'] )
            : '';
    }

    // Résolution des URLs d'image relatives
    if ( ! empty( $data['image'] ) && ! preg_match( '/^https?:\/\//i', $data['image'] ) ) {
        $parsed        = parse_url( $url );
        $data['image'] = $parsed['scheme'] . '://' . $parsed['host'] . '/' . ltrim( $data['image'], '/' );
    }

    // Tronquer la description
    if ( ! empty( $data['description'] ) && mb_strlen( $data['description'] ) > 200 ) {
        $data['description'] = mb_substr( $data['description'], 0, 197 ) . '...';
    }

    return $data;
}
