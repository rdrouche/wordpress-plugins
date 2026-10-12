<?php
/**
 * Plugin Name:  CCSS Self-Hosted Bridge v6
 * Description:  Génère le Critical CSS via votre conteneur auto-hébergé.
 *
 *  INSTALLATION
 *  ────────────
 *  1. cp ccss-bridge.php wp-content/mu-plugins/
 *  2. Dans wp-config.php :
 *       define( 'CCSS_SERVER_URL', 'https://ccss-gen.app.rdr-it.com' );
 *       define( 'CCSS_SECRET',     '' );   // optionnel
 *       define( 'CCSS_DEBUG',      true ); // false en prod
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'CCSS_SERVER_URL' ) ) define( 'CCSS_SERVER_URL', 'http://localhost:3000' );
if ( ! defined( 'CCSS_SECRET' ) )     define( 'CCSS_SECRET', '' );
if ( ! defined( 'CCSS_DEBUG' ) )      define( 'CCSS_DEBUG', false );

define( 'CCSS_LITESPEED_DIR', WP_CONTENT_DIR . '/litespeed' );
define( 'CCSS_DIR',           CCSS_LITESPEED_DIR . '/ccss' );
define( 'CCSS_DAT_FILE',      CCSS_DIR . '/.litespeed_conf.dat' );
define( 'CCSS_TYPE',          3 ); // css=1, js=2, ccss=3, ucss=4

// ═════════════════════════════════════════════════════════════════════════════
//  0. NETTOYAGE PRÉVENTIF DE LA QUEUE
//
//  LSCache écrit les URLs dans .litespeed_conf.dat au fil des visites,
//  sans filtrage. On nettoie ce fichier à chaque requête WP (init)
//  pour retirer les URLs invalides avant que le cron ne les traite.
//
//  Cela résout l'affichage dans l'admin LSCache qui montre ces URLs.
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'init', 'ccss_bridge_clean_dat_file', 20 );

function ccss_bridge_clean_dat_file() {
    // Ne tourner qu'une fois toutes les 5 minutes pour ne pas I/O à chaque requête
    $transient_key = 'ccss_bridge_last_clean';
    if ( get_transient( $transient_key ) ) {
        return;
    }
    set_transient( $transient_key, 1, 5 * MINUTE_IN_SECONDS );

    if ( ! file_exists( CCSS_DAT_FILE ) ) return;

    $raw  = file_get_contents( CCSS_DAT_FILE );
    $data = json_decode( $raw, true );

    if ( ! is_array( $data ) || empty( $data ) ) return;

    $before  = count( $data );
    $removed = [];

    foreach ( $data as $key => $item ) {
        if ( ! is_array( $item ) || empty( $item['url'] ) ) {
            $removed[] = $key;
            continue;
        }

        $url = $item['url'];
        $tag = $item['url_tag'] ?? '';

        // Retirer les url_tag=404
        if ( $tag === '404' ) {
            $removed[] = $key;
            continue;
        }

        $path = trim( parse_url( $url, PHP_URL_PATH ) ?? '', '/' );

        // Retirer fichiers statiques
        if ( preg_match( '/\.(css|js|png|jpe?g|gif|webp|svg|woff2?|ttf|eot|ico|pdf|xml|txt|map|gz)$/i', $path ) ) {
            $removed[] = $key;
            continue;
        }

        // Retirer pagination
        if ( preg_match( '#/page/\d+/?$#', $path ) ) {
            $removed[] = $key;
            continue;
        }

        // Retirer archives et chemins système
        if ( preg_match(
            '#^([a-z]{2}/)?(?:category|tag|author|feed|search|wp\-content|wp\-includes|wp\-admin|wp\-json|sitemap|apply_to|tutorials?)(/|$)#i',
            $path
        ) ) {
            $removed[] = $key;
            continue;
        }

        // Retirer URLs non canoniques (301 Polylang) via DB
        // Seulement si le check est rapide (post existant + permalink différent)
        if ( function_exists( 'url_to_postid' ) ) {
            $post_id = url_to_postid( $url );
            if ( $post_id ) {
                $canonical = trailingslashit( get_permalink( $post_id ) );
                if ( trailingslashit( $url ) !== $canonical ) {
                    $removed[] = $key;
                    ccss_debug( "🚫 [clean-dat] 301 retiré : {$url} → {$canonical}" );
                    continue;
                }
            }
        }
    }

    if ( empty( $removed ) ) return;

    foreach ( $removed as $key ) {
        unset( $data[ $key ] );
    }

    file_put_contents( CCSS_DAT_FILE, wp_json_encode( $data ) );
    ccss_debug( "🧹 Queue nettoyée : {$before} → " . count( $data ) . " (" . count( $removed ) . " retirées)" );
}

// ═════════════════════════════════════════════════════════════════════════════
//  1. INTERCEPTION DU CRON LITESPEED
//
//  IMPORTANT : on s'accroche à priorité 1 (avant LSCache) mais on ne vide
//  PAS la queue. On traite les items et on laisse LSCache gérer son état
//  interne normalement — sinon il ne replanifie plus le cron suivant.
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'litespeed_task_ccss', 'ccss_bridge_intercept_cron', 1 );

function ccss_bridge_intercept_cron() {
    ccss_debug( '🔄 Cron CCSS intercepté' );

    $queue = ccss_bridge_read_queue();

    if ( empty( $queue ) ) {
        ccss_debug( 'File vide — rien à faire' );
        return;
    }

    $total = count( $queue );
    ccss_debug( "{$total} URL(s) dans la file" );

    $ok = $skip = $fail = 0;
    foreach ( $queue as $queue_key => $item ) {
        $result = ccss_bridge_process_item( $queue_key, $item );
        if ( $result === 'ok' )   $ok++;
        if ( $result === 'skip' ) $skip++;
        if ( $result === 'fail' ) $fail++;
    }

    // On vide uniquement les entrées TRAITÉES (ok + skip)
    // Les fail restent en queue pour être retentés au prochain cron
    $to_clear = [];
    foreach ( $queue as $key => $item ) {
        // On retire tout sauf les fail — LSCache les retentera
        $to_clear[ $key ] = $item;
    }
    ccss_bridge_clear_queue( $to_clear );

    ccss_debug( "✅ ok={$ok} skip={$skip} fail={$fail} / {$total}" );
}

// ═════════════════════════════════════════════════════════════════════════════
//  2. TRAITEMENT D'UN ITEM
// ═════════════════════════════════════════════════════════════════════════════
function ccss_bridge_process_item( $queue_key, $item ) {
    $url     = $item['url']       ?? '';
    $url_tag = $item['url_tag']   ?? '';
    $vary    = $item['vary']      ?? '';
    $mobile  = ! empty( $item['is_mobile'] );

    // ── Filtres rapides sans requête HTTP ─────────────────────────────────────

    // Skip url_tag=404 explicite
    if ( $url_tag === '404' ) {
        ccss_debug( "⏭️  Skip url_tag=404 : {$url}" );
        return 'skip';
    }

    // Skip URL invalide
    if ( ! $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
        ccss_debug( "⏭️  Skip URL invalide : '{$url}'" );
        return 'skip';
    }

    // Skip fichiers statiques évidents (CSS, JS, images, fonts)
    $path = parse_url( $url, PHP_URL_PATH ) ?? '';
    if ( preg_match( '/\.(css|js|png|jpg|jpeg|gif|webp|svg|woff|woff2|ttf|ico|pdf|xml|txt)$/i', $path ) ) {
        ccss_debug( "⏭️  Skip fichier statique : {$url}" );
        return 'skip';
    }

    // Skip pages de pagination (/page/2/, /page/3/…)
    if ( preg_match( '#/page/\d+/?$#', $path ) ) {
        ccss_debug( "⏭️  Skip pagination : {$url}" );
        return 'skip';
    }

    // ── Résolution Polylang ───────────────────────────────────────────────────
    $url = ccss_bridge_resolve_polylang_url( $url );

    // ── Vérification is_singular via DB (fonctionne en contexte cron) ─────────
    // On cherche l'URL dans wp_litespeed_url et on vérifie via la DB WordPress
    // si c'est un post/page/CPT — sans appel HTTP et sans is_singular()
    if ( ! ccss_bridge_is_valid_content_url( $url ) ) {
        ccss_debug( "⏭️  Skip non-singular : {$url}" );
        return 'skip';
    }

    ccss_debug( "⚙️  Traitement", [ 'url' => $url, 'vary' => $vary ?: '(vide)', 'mobile' => $mobile ] );

    // ── Appel au conteneur ────────────────────────────────────────────────────
    // La vérification HTTP 200 est faite CÔTÉ CONTENEUR (node-fetch + redirect:follow)
    // On ne la refait pas ici pour éviter 2500 requêtes HEAD supplémentaires
    $ccss = ccss_bridge_call_server( $url, $mobile );

    if ( $ccss === null ) {
        ccss_debug( "❌ Génération échouée pour {$url}" );
        return 'fail';
    }

    ccss_debug( "✅ CSS reçu : " . strlen( $ccss ) . " octets" );

    $filename_md5 = md5( $ccss );
    $vary_raw     = $vary;

    ccss_debug( "🔑 filename={$filename_md5}.css  vary='{$vary_raw}'" );

    if ( ! ccss_bridge_write_file( $filename_md5, $ccss ) ) {
        return 'fail';
    }

    $url_id = ccss_bridge_get_or_create_url_id( $url );
    if ( ! $url_id ) {
        return 'fail';
    }

    ccss_bridge_upsert_url_file( $url_id, $vary_raw, $filename_md5, $mobile );

    return 'ok';
}

// ═════════════════════════════════════════════════════════════════════════════
//  3. VÉRIFICATION is_singular + URL CANONIQUE
//
//  Stratégie en deux temps pour être efficace ET précis :
//
//  Étape A — Filtres structurels rapides (regex sur le path, pas de DB)
//    → rejette archives, paginations, fichiers statiques, feed...
//
//  Étape B — Vérification DB + URL canonique
//    → cherche le slug dans wp_posts
//    → reconstruit l'URL canonique via get_permalink()
//    → si l'URL demandée ≠ URL canonique → c'est un 301 → skip
//    → evite toute requête HTTP inutile
//
//  Retourne : false = skip | true = traiter
// ═════════════════════════════════════════════════════════════════════════════
function ccss_bridge_is_valid_content_url( $url ) {
    global $wpdb;

    $path = trim( parse_url( $url, PHP_URL_PATH ) ?? '', '/' );

    // ── A1. Homepage ──────────────────────────────────────────────────────────
    if ( $path === '' ) {
        return true;
    }

    // ── A2. Préfixes exclus d'emblée (archives, système) ──────────────────────
    // Couvre toutes les langues Polylang avec un regex générique
    // Pattern : (2-lettres-optionnel/)type-archive/
    if ( preg_match(
        '#^([a-z]{2}/)?(?:category|tag|author|feed|search|wp-content|wp-includes|wp-admin|wp-json|wp-login|sitemap|robots|apply_to|tutorials?|tutoriales?)(/|$)#i',
        $path
    ) ) {
        ccss_debug( "✗ Path exclu (archive/système) : {$path}" );
        return false;
    }

    // ── A3. Pagination ────────────────────────────────────────────────────────
    if ( preg_match( '#/page/\d+/?$#', $path ) ) {
        ccss_debug( "✗ Path exclu (pagination) : {$path}" );
        return false;
    }

    // ── A4. Fichiers statiques (au cas où le regex du process_item aurait raté) ─
    if ( preg_match( '#\.(css|js|png|jpe?g|gif|webp|svg|woff2?|ttf|eot|ico|pdf|xml|txt|map|gz)$#i', $path ) ) {
        ccss_debug( "✗ Path exclu (fichier statique) : {$path}" );
        return false;
    }

    // ── B. Recherche en DB + vérification URL canonique ───────────────────────
    $segments = array_values( array_filter( explode( '/', $path ) ) );

    // Retirer le préfixe de langue Polylang si présent (2 lettres minuscules)
    if ( ! empty( $segments ) && preg_match( '#^[a-z]{2}$#', $segments[0] ) ) {
        array_shift( $segments );
    }

    if ( empty( $segments ) ) {
        return true; // Homepage d'une langue (/en/, /es/...)
    }

    $slug = end( $segments ); // Dernier segment = slug du post

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $post_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_name = %s
           AND post_status = 'publish'
           AND post_type NOT IN ('attachment','revision','nav_menu_item','custom_css','customize_changeset','ld-exam')
         LIMIT 1",
        $slug
    ) );

    if ( ! $post_id ) {
        ccss_debug( "✗ Slug '{$slug}' absent de wp_posts → skip" );
        return false;
    }

    // Vérifier que l'URL demandée correspond à l'URL canonique du post
    // Si elles diffèrent, l'URL fait un 301 → pas besoin de générer le CCSS
    $canonical = trailingslashit( get_permalink( (int) $post_id ) );
    $requested = trailingslashit( $url );

    if ( $canonical !== $requested ) {
        ccss_debug( "✗ URL non canonique (301 probable)", [
            'demandée'   => $requested,
            'canonique'  => $canonical,
            'post_id'    => $post_id,
        ] );
        return false;
    }

    ccss_debug( "✓ URL canonique OK (post_id={$post_id}, slug='{$slug}')" );
    return true;
}

// ═════════════════════════════════════════════════════════════════════════════
//  4. RÉSOLUTION URL POLYLANG
// ═════════════════════════════════════════════════════════════════════════════
function ccss_bridge_resolve_polylang_url( $url ) {
    if ( ! function_exists( 'pll_get_post_language' ) ) {
        return $url;
    }

    $post_id = url_to_postid( $url );

    if ( ! $post_id ) {
        return $url;
    }

    $localized_url = get_permalink( $post_id );

    if ( ! $localized_url || $localized_url === $url ) {
        return $url;
    }

    ccss_debug( "🌐 Polylang résolution", [
        'post_id'    => $post_id,
        'langue'     => pll_get_post_language( $post_id, 'slug' ),
        'original'   => $url,
        'résolu'     => $localized_url,
    ] );

    return $localized_url;
}

// ═════════════════════════════════════════════════════════════════════════════
//  5. APPEL SYNCHRONE AU CONTENEUR
// ═════════════════════════════════════════════════════════════════════════════
function ccss_bridge_call_server( $url, $mobile = false ) {
    $endpoint = rtrim( CCSS_SERVER_URL, '/' ) . '/generate-sync';
    $body     = wp_json_encode( [ 'url' => $url, 'mobile' => $mobile ] );

    $headers = [ 'Content-Type' => 'application/json' ];
    if ( CCSS_SECRET ) {
        $headers['X-CCSS-Signature'] = hash_hmac( 'sha256', $body, CCSS_SECRET );
    }

    ccss_debug( "📤 → {$endpoint}", [ 'url' => $url, 'mobile' => $mobile ] );

    $response = wp_remote_post( $endpoint, [
        'headers'  => $headers,
        'body'     => $body,
        'timeout'  => 90,
        'blocking' => true,
    ] );

    if ( is_wp_error( $response ) ) {
        ccss_debug( "❌ Erreur réseau : " . $response->get_error_message() );
        return null;
    }

    $code     = wp_remote_retrieve_response_code( $response );
    $body_raw = wp_remote_retrieve_body( $response );

    ccss_debug( "← HTTP {$code} (" . strlen( $body_raw ) . " octets)" );

    if ( $code !== 200 ) {
        ccss_debug( "❌ HTTP {$code}", [ 'body' => substr( $body_raw, 0, 200 ) ] );
        return null;
    }

    $data = json_decode( $body_raw, true );

    if ( empty( $data['css'] ) ) {
        ccss_debug( "❌ Réponse sans CSS", [ 'keys' => array_keys( $data ?? [] ) ] );
        return null;
    }

    return $data['css'];
}

// ═════════════════════════════════════════════════════════════════════════════
//  6. ÉCRITURE DU FICHIER CSS
// ═════════════════════════════════════════════════════════════════════════════
function ccss_bridge_write_file( $filename_md5, $css ) {
    if ( ! file_exists( CCSS_DIR ) ) {
        wp_mkdir_p( CCSS_DIR );
    }

    $filepath = CCSS_DIR . '/' . $filename_md5 . '.css';

    if ( file_exists( $filepath ) ) {
        ccss_debug( "📄 Fichier déjà existant : {$filename_md5}.css" );
        return true;
    }

    if ( file_put_contents( $filepath, $css ) === false ) {
        ccss_debug( "❌ Écriture impossible : {$filepath}" );
        return false;
    }

    ccss_debug( "💾 Fichier écrit : {$filename_md5}.css" );
    return true;
}

// ═════════════════════════════════════════════════════════════════════════════
//  7. GESTION DE LA DB
// ═════════════════════════════════════════════════════════════════════════════
function ccss_bridge_get_or_create_url_id( $url ) {
    global $wpdb;
    $table = $wpdb->prefix . 'litespeed_url';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM `{$table}` WHERE url = %s LIMIT 1", $url ) );

    if ( $id ) {
        ccss_debug( "🔍 url_id existant : {$id}" );
        return (int) $id;
    }

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $wpdb->insert( $table, [ 'url' => $url, 'cache_tags' => '' ], [ '%s', '%s' ] );

    if ( ! $wpdb->insert_id ) {
        ccss_debug( "❌ INSERT wp_litespeed_url échoué : " . $wpdb->last_error );
        return false;
    }

    ccss_debug( "➕ Nouvelle URL, url_id={$wpdb->insert_id}" );
    return $wpdb->insert_id;
}

function ccss_bridge_upsert_url_file( $url_id, $vary_raw, $filename_md5, $mobile ) {
    global $wpdb;
    $table  = $wpdb->prefix . 'litespeed_url_file';
    $mobile = $mobile ? 1 : 0;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $existing_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM `{$table}` WHERE url_id = %d AND type = %d AND mobile = %d LIMIT 1",
        $url_id, CCSS_TYPE, $mobile
    ) );

    if ( $existing_id ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->update(
            $table,
            [ 'vary' => $vary_raw, 'filename' => $filename_md5, 'expired' => 0 ],
            [ 'id' => $existing_id ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );
        ccss_debug( "✏️  url_file mis à jour", [ 'id' => $existing_id, 'vary' => $vary_raw, 'filename' => $filename_md5 ] );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->insert(
            $table,
            [ 'url_id' => $url_id, 'vary' => $vary_raw, 'filename' => $filename_md5,
              'type' => CCSS_TYPE, 'mobile' => $mobile, 'webp' => 0, 'expired' => 0 ],
            [ '%d', '%s', '%s', '%d', '%d', '%d', '%d' ]
        );
        ccss_debug( "➕ url_file inséré", [ 'id' => $wpdb->insert_id, 'vary' => $vary_raw, 'filename' => $filename_md5 ] );
    }
}

// ═════════════════════════════════════════════════════════════════════════════
//  8. LECTURE / VIDAGE DE LA QUEUE (.litespeed_conf.dat)
// ═════════════════════════════════════════════════════════════════════════════
function ccss_bridge_read_queue() {
    if ( ! file_exists( CCSS_DAT_FILE ) ) {
        ccss_debug( "⚠️  Fichier queue absent : " . CCSS_DAT_FILE );
        return [];
    }

    $raw  = file_get_contents( CCSS_DAT_FILE );
    $data = json_decode( $raw, true );

    if ( ! is_array( $data ) || empty( $data ) ) {
        return [];
    }

    $queue = array_filter( $data, fn( $item ) => is_array( $item ) && isset( $item['url'] ) );

    ccss_debug( count( $queue ) . " item(s) dans queue", [
        'apercu' => array_slice( array_column( array_values( $queue ), 'url' ), 0, 3 ),
    ] );

    return $queue;
}

function ccss_bridge_clear_queue( $processed_queue ) {
    if ( ! file_exists( CCSS_DAT_FILE ) || empty( $processed_queue ) ) return;

    $raw  = file_get_contents( CCSS_DAT_FILE );
    $data = json_decode( $raw, true );

    if ( ! is_array( $data ) ) return;

    $before = count( $data );

    foreach ( array_keys( $processed_queue ) as $key ) {
        unset( $data[ $key ] );
    }

    file_put_contents( CCSS_DAT_FILE, wp_json_encode( $data ) );
    ccss_debug( "🗑️  Queue : {$before} → " . count( $data ) . " entrées" );
}

// ═════════════════════════════════════════════════════════════════════════════
//  9. MIGRATION : corriger les vary incorrects des versions précédentes
// ═════════════════════════════════════════════════════════════════════════════
function ccss_bridge_migrate_vary() {
    global $wpdb;
    $table = $wpdb->prefix . 'litespeed_url_file';

    $corrections = [
        'd41d8cd98f00b204e9800998ecf8427e' => '',
        '7a8dda16c64c5efb6e19532fc1bed627' => '+ismobile',
    ];

    $total = 0;
    foreach ( $corrections as $wrong => $correct ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $n = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table}` WHERE type = %d AND vary = %s",
            CCSS_TYPE, $wrong
        ) );

        if ( $n > 0 ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->query( $wpdb->prepare(
                "UPDATE `{$table}` SET vary = %s WHERE type = %d AND vary = %s",
                $correct, CCSS_TYPE, $wrong
            ) );
            ccss_debug( "🔧 Migration : '{$wrong}' → '{$correct}' ({$n} entrées)" );
            $total += $n;
        }
    }

    return $total;
}

// ═════════════════════════════════════════════════════════════════════════════
//  10. AUTORISER LES CALLBACKS
// ═════════════════════════════════════════════════════════════════════════════
add_filter( 'litespeed_is_from_cloud', '__return_true' );

// ═════════════════════════════════════════════════════════════════════════════
//  11. ENDPOINTS REST (admin uniquement)
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'rest_api_init', 'ccss_bridge_register_rest' );

function ccss_bridge_register_rest() {
    $admin = fn() => current_user_can( 'manage_options' );

    register_rest_route( 'ccss-bridge/v1', '/status',       [ 'methods' => 'GET',  'callback' => 'ccss_bridge_rest_status',       'permission_callback' => $admin ] );
    register_rest_route( 'ccss-bridge/v1', '/queue',        [ 'methods' => 'GET',  'callback' => 'ccss_bridge_rest_queue',        'permission_callback' => $admin ] );
    register_rest_route( 'ccss-bridge/v1', '/db',           [ 'methods' => 'GET',  'callback' => 'ccss_bridge_rest_db',           'permission_callback' => $admin ] );
    register_rest_route( 'ccss-bridge/v1', '/process-now',  [ 'methods' => 'POST', 'callback' => 'ccss_bridge_rest_process_now',  'permission_callback' => $admin ] );
    register_rest_route( 'ccss-bridge/v1', '/migrate-vary', [ 'methods' => 'POST', 'callback' => 'ccss_bridge_rest_migrate_vary', 'permission_callback' => $admin ] );
}

function ccss_bridge_rest_status() {
    global $wpdb;
    $queue    = ccss_bridge_read_queue();
    $files    = file_exists( CCSS_DIR ) ? glob( CCSS_DIR . '/*.css' ) : [];
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $db_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}litespeed_url_file` WHERE type = " . CCSS_TYPE );

    return [
        'bridge_version'   => '6.0',
        'server_url'       => CCSS_SERVER_URL,
        'debug'            => CCSS_DEBUG,
        'secret_set'       => ! empty( CCSS_SECRET ),
        'dat_file_exists'  => file_exists( CCSS_DAT_FILE ),
        'queue_count'      => count( $queue ),
        'queue_urls'       => array_column( array_values( $queue ), 'url' ),
        'ccss_files_count' => count( $files ),
        'db_ccss_entries'  => $db_count,
    ];
}

function ccss_bridge_rest_queue() {
    return [
        'dat_file' => CCSS_DAT_FILE,
        'exists'   => file_exists( CCSS_DAT_FILE ),
        'queue'    => ccss_bridge_read_queue(),
    ];
}

function ccss_bridge_rest_db() {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $rows  = $wpdb->get_results(
        "SELECT f.id, u.url, f.vary, f.filename, f.mobile, f.expired
         FROM `{$wpdb->prefix}litespeed_url_file` f
         JOIN `{$wpdb->prefix}litespeed_url` u ON u.id = f.url_id
         WHERE f.type = " . CCSS_TYPE . "
         ORDER BY f.id DESC LIMIT 20"
    );
    $files = file_exists( CCSS_DIR ) ? array_map( 'basename', glob( CCSS_DIR . '/*.css' ) ) : [];
    return [ 'total' => count( $rows ), 'entries' => $rows, 'files' => $files ];
}

function ccss_bridge_rest_migrate_vary() {
    $fixed = ccss_bridge_migrate_vary();
    return new WP_REST_Response( [ 'status' => 'ok', 'fixed' => $fixed ], 200 );
}

function ccss_bridge_rest_process_now() {
    $queue = ccss_bridge_read_queue();
    if ( empty( $queue ) ) {
        return new WP_REST_Response( [ 'status' => 'ok', 'message' => 'Queue vide' ], 200 );
    }
    ccss_bridge_intercept_cron();
    return new WP_REST_Response( [ 'status' => 'ok', 'processed' => count( $queue ) ], 200 );
}

// ═════════════════════════════════════════════════════════════════════════════
//  12. NOTICE ADMIN
// ═════════════════════════════════════════════════════════════════════════════
add_action( 'admin_notices', 'ccss_bridge_admin_notice' );

function ccss_bridge_admin_notice() {
    $screen = get_current_screen();
    if ( ! $screen || strpos( $screen->id, 'litespeed' ) === false ) return;

    global $wpdb;
    $queue    = ccss_bridge_read_queue();
    $files    = file_exists( CCSS_DIR ) ? glob( CCSS_DIR . '/*.css' ) : [];
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
    $db_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}litespeed_url_file` WHERE type = " . CCSS_TYPE );

    echo "<div class='notice notice-info is-dismissible'>
        <p><strong>🔧 CCSS Self-Hosted Bridge v6</strong></p>
        <table style='border-collapse:collapse;line-height:1.9'>
            <tr><td style='padding-right:16px'><b>Serveur</b></td><td><code>" . esc_html( CCSS_SERVER_URL ) . "</code></td></tr>
            <tr><td>Debug</td><td>" . ( CCSS_DEBUG ? '✅ activé' : 'désactivé' ) . "</td></tr>
            <tr><td>Queue</td><td><b>" . count( $queue ) . "</b> URL(s) en attente</td></tr>
            <tr><td>Fichiers .css</td><td><b>" . count( $files ) . "</b></td></tr>
            <tr><td>DB (type=3)</td><td><b>{$db_count}</b> entrées</td></tr>
        </table>
        <p style='margin-top:8px'>
            <a href='" . esc_url( rest_url( 'ccss-bridge/v1/status' ) ) . "' target='_blank'>📊 Statut</a> &nbsp;|&nbsp;
            <a href='" . esc_url( rest_url( 'ccss-bridge/v1/queue' ) ) . "' target='_blank'>📋 Queue</a> &nbsp;|&nbsp;
            <a href='" . esc_url( rest_url( 'ccss-bridge/v1/db' ) ) . "' target='_blank'>🗃️ DB</a>
        </p>
    </div>";
}

// ═════════════════════════════════════════════════════════════════════════════
//  UTILITAIRE LOG
// ═════════════════════════════════════════════════════════════════════════════
function ccss_debug( $message, $context = [] ) {
    if ( ! CCSS_DEBUG ) return;
    $log = '[CCSS-Bridge] ' . $message;
    if ( ! empty( $context ) ) $log .= ' ' . wp_json_encode( $context );
    error_log( $log );
}
