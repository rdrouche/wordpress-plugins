<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/*
 * RDR Newsletter — Security Layer
 *
 * 1. Feed URL allowlist/blocklist (domain-level, applies to all subscribers)
 * 2. URL sanitization helpers
 * 3. Input validation for all user-supplied data
 * 4. Random profile slugs (no enumerable sub-N pattern)
 */

define( 'RDRNL_FEED_RULES_KEY', 'rdrnl_feed_rules' );

/* ============================================================
   FEED DOMAIN RULES
   Stored as:
   {
     'blocked'  => ['malicious.com', 'spam.net'],
     'allowed'  => ['korben.info', 'it-connect.fr'],
     'mode'     => 'blacklist' | 'whitelist'
   }
   mode=blacklist : all domains allowed except blocked list
   mode=whitelist : only explicitly allowed domains accepted
   ============================================================ */

function rdrnl_get_feed_rules() {
    return wp_parse_args( get_option( RDRNL_FEED_RULES_KEY, [] ), [
        'blocked' => [],
        'allowed' => [],
        'mode'    => 'blacklist',
    ]);
}

function rdrnl_save_feed_rules( $rules ) {
    update_option( RDRNL_FEED_RULES_KEY, $rules, false );
}

/**
 * Check if a feed URL is permitted.
 * Returns true if allowed, WP_Error if blocked/not whitelisted.
 */
function rdrnl_feed_url_permitted( $url ) {
    $url = esc_url_raw( trim($url) );

    // Must be a valid HTTPS or HTTP URL
    if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
        return new WP_Error( 'invalid_url', 'URL invalide.' );
    }

    // Must be http(s)
    $scheme = parse_url( $url, PHP_URL_SCHEME );
    if ( ! in_array( $scheme, ['http','https'], true ) ) {
        return new WP_Error( 'invalid_scheme', 'Seuls les protocoles http et https sont acceptés.' );
    }

    // Must not be a local/private IP
    $host = parse_url( $url, PHP_URL_HOST );
    if ( rdrnl_is_private_host( $host ) ) {
        return new WP_Error( 'private_host', 'Les adresses locales ou privées ne sont pas autorisées.' );
    }

    $rules  = rdrnl_get_feed_rules();
    $domain = rdrnl_extract_domain( $host );

    if ( $rules['mode'] === 'whitelist' ) {
        // Only explicitly allowed domains
        if ( ! rdrnl_domain_in_list( $domain, $rules['allowed'] ) ) {
            return new WP_Error( 'not_whitelisted',
                "Le domaine « {$domain} » n'est pas dans la liste des domaines autorisés." );
        }
    } else {
        // Blacklist mode — block listed domains
        if ( rdrnl_domain_in_list( $domain, $rules['blocked'] ) ) {
            return new WP_Error( 'blocked',
                "Le domaine « {$domain} » est bloqué par l'administrateur." );
        }
    }

    return true;
}

/**
 * Check if a domain matches any entry in a list (supports wildcards: *.example.com).
 */
function rdrnl_domain_in_list( $domain, array $list ) {
    $domain = strtolower( trim($domain) );
    foreach ( $list as $entry ) {
        $entry = strtolower( trim($entry) );
        if ( $entry === '' ) continue;
        // Wildcard: *.example.com matches sub.example.com and example.com
        if ( str_starts_with($entry, '*.') ) {
            $base = substr($entry, 2);
            if ( $domain === $base || str_ends_with($domain, '.' . $base) ) return true;
        } elseif ( $domain === $entry || str_ends_with($domain, '.' . $entry) ) {
            return true;
        }
    }
    return false;
}

/**
 * Extract registrable domain (e.g. sub.example.com → example.com).
 */
function rdrnl_extract_domain( $host ) {
    $host  = strtolower( trim($host) );
    $parts = explode('.', $host);
    if ( count($parts) >= 2 ) {
        return implode('.', array_slice($parts, -2));
    }
    return $host;
}

/**
 * Detect private/local IP or hostname.
 */
function rdrnl_is_private_host( $host ) {
    // localhost variants
    if ( in_array(strtolower($host), ['localhost','127.0.0.1','::1'], true) ) return true;
    // Private IP ranges
    if ( filter_var($host, FILTER_VALIDATE_IP) ) {
        return ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    // .local / .internal / .test / .localhost TLDs
    if ( preg_match('/\.(local|internal|test|localhost|invalid|example)$/i', $host) ) return true;
    return false;
}

/* ============================================================
   SECURE URL SANITIZATION FOR NOTES
   wp_kses with strict href validation (no javascript:, data:, etc.)
   ============================================================ */

function rdrnl_kses_note( $content ) {
    $allowed = [
        'a'      => [
            'href'   => [],
            'title'  => [],
            'target' => [],
            'rel'    => [],
        ],
        'strong' => [],
        'em'     => [],
        'code'   => [],
        'br'     => [],
    ];

    $clean = wp_kses( $content, $allowed );

    // Extra: strip javascript: and data: from href attributes
    $clean = preg_replace_callback(
        '/href=["\']([^"\']*)["\']/',
        function( $m ) {
            $href = strtolower( trim( $m[1] ) );
            if ( preg_match('/^(javascript|data|vbscript):/i', $href) ) {
                return 'href="#"';
            }
            return $m[0];
        },
        $clean
    );

    return $clean;
}

/* ============================================================
   RANDOM PROFILE SLUG  (replaces enumerable sub-{id})
   ============================================================ */

function rdrnl_generate_profile_slug() {
    return 'p-' . bin2hex( random_bytes(8) ); // e.g. p-3a7f2b1c4e8d9f0a
}

/* ============================================================
   ADMIN: Feed Rules page (registered in admin.php)
   ============================================================ */
add_action( 'admin_post_rdrnl_save_feed_rules', 'rdrnl_save_feed_rules_handler' );
function rdrnl_save_feed_rules_handler() {
    check_admin_referer( 'rdrnl_feed_rules_nonce' );
    if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');

    $mode = sanitize_text_field( $_POST['mode'] ?? 'blacklist' );
    if ( ! in_array($mode, ['blacklist','whitelist'], true) ) $mode = 'blacklist';

    $parse_list = function( $raw ) {
        return array_values( array_filter(
            array_map('trim', preg_split('/[\r\n,]+/', sanitize_textarea_field($raw)))
        ));
    };

    rdrnl_save_feed_rules([
        'mode'    => $mode,
        'blocked' => $parse_list( $_POST['blocked'] ?? '' ),
        'allowed' => $parse_list( $_POST['allowed'] ?? '' ),
    ]);

    wp_redirect( admin_url('admin.php?page=rdr-newsletter-feed-rules&saved=1') );
    exit;
}
