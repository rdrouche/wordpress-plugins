<?php
/**
 * RDR-IT Tools — Tweaks v1
 *
 * Regroupe les personnalisations WordPress issues de l ancien plugin custom.
 * Chaque groupe est activable independamment depuis l admin.
 *
 * Options :
 *   rdrit_tweak_disable_autoupdate  bool  Desactive MAJ auto themes+plugins
 *   rdrit_tweak_cloudflare_ssl      bool  Detection proxy SSL Cloudflare (toujours actif par defaut)
 *   rdrit_tweak_image_sizes         bool  Ajoute les tailles d image rdrit-*
 *   rdrit_tweak_jetpack_css         bool  Desactive l imbrication CSS Jetpack
 *   rdrit_tweak_block_css           bool  Charge les CSS blocs separement (only used)
 *   rdrit_tweak_jquery_migrate      bool  Retire jQuery Migrate en frontend
 *   rdrit_tweak_page_parents        bool  Affiche draft/private dans dropdown pages parentes
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   CLOUDFLARE SSL PROXY
   Toujours actif par defaut — desactivation peut causer boucle admin.
   ============================================================ */
add_action( 'init', 'rdrit_tweak_cloudflare_ssl', 1 );
function rdrit_tweak_cloudflare_ssl() {
    if ( ! (bool) get_option( 'rdrit_tweak_cloudflare_ssl', 1 ) ) return;

    if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] )
         && strtolower( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) === 'https' ) {
        $_SERVER['HTTPS']       = 'on';
        $_SERVER['SERVER_PORT'] = '443';
    }
}

/* ============================================================
   MAJ AUTO THEMES + PLUGINS
   ============================================================ */
add_action( 'init', 'rdrit_tweak_disable_autoupdate' );
function rdrit_tweak_disable_autoupdate() {
    if ( ! (bool) get_option( 'rdrit_tweak_disable_autoupdate', 1 ) ) return;
    add_filter( 'auto_update_plugin', '__return_false' );
    add_filter( 'auto_update_theme',  '__return_false' );
}

/* ============================================================
   TAILLES D IMAGE
   ============================================================ */
add_action( 'init', 'rdrit_tweak_image_sizes' );
function rdrit_tweak_image_sizes() {
    if ( ! (bool) get_option( 'rdrit_tweak_image_sizes', 1 ) ) return;

    add_image_size( 'rdrit-800', 800, 500 );
    add_image_size( 'rdrit-600', 600, 400 );
    add_image_size( 'rdrit-400', 400, 300 );
    add_image_size( 'rdrit-250', 250, 140 );
    add_image_size( 'rdrit-200', 200, 200, true );
    add_image_size( 'rdrit-60',   60,  60, true );

    add_filter( 'image_size_names_choose', 'rdrit_custom_image_sizes' );
}
function rdrit_custom_image_sizes( array $sizes ): array {
    return array_merge( $sizes, array(
        'rdrit-800' => 'rdrit-800 (800×500)',
        'rdrit-600' => 'rdrit-600 (600×400)',
        'rdrit-400' => 'rdrit-400 (400×300)',
        'rdrit-250' => 'rdrit-250 (250×140)',
        'rdrit-200' => 'rdrit-200 (200×200 crop)',
        'rdrit-60'  => 'rdrit-60  (60×60 crop)',
    ) );
}

/* ============================================================
   CSS JETPACK : forcer mode separe (pas d imbrication)
   ============================================================ */
add_action( 'init', 'rdrit_tweak_jetpack_css' );
function rdrit_tweak_jetpack_css() {
    if ( ! (bool) get_option( 'rdrit_tweak_jetpack_css', 1 ) ) return;
    add_filter( 'jetpack_implode_frontend_css', '__return_false' );
}

/* ============================================================
   CSS BLOCS GUTENBERG : charger uniquement les blocs utilises
   ============================================================ */
add_action( 'init', 'rdrit_tweak_block_css' );
function rdrit_tweak_block_css() {
    if ( ! (bool) get_option( 'rdrit_tweak_block_css', 1 ) ) return;
    add_filter( 'should_load_separate_core_block_assets', '__return_true' );
}

/* ============================================================
   JQUERY MIGRATE : retrait en frontend
   ============================================================ */
add_filter( 'wp_default_scripts', 'rdrit_tweak_jquery_migrate', PHP_INT_MAX );
function rdrit_tweak_jquery_migrate( WP_Scripts &$scripts ) {
    if ( ! (bool) get_option( 'rdrit_tweak_jquery_migrate', 1 ) ) return;
    if ( is_admin() ) return;

    $scripts->remove( 'jquery' );
    $scripts->add( 'jquery', false, array( 'jquery-core' ), '1.12.4' );
}

/* ============================================================
   DROPDOWN PAGES PARENTES : inclure draft/private/future/pending
   ============================================================ */
add_action( 'init', 'rdrit_tweak_page_parents_init' );
function rdrit_tweak_page_parents_init() {
    if ( ! (bool) get_option( 'rdrit_tweak_page_parents', 1 ) ) return;
    add_filter( 'page_attributes_dropdown_pages_args', 'rdrit_dropdown_pages_args' );
    add_filter( 'quick_edit_dropdown_pages_args',      'rdrit_dropdown_pages_args' );
}
function rdrit_dropdown_pages_args( array $args ): array {
    $args['post_status'] = array( 'publish', 'draft', 'pending', 'future', 'private' );
    return $args;
}

/* ============================================================
   AJAX — SUPPRIMER UNE CAPACITÉ ORPHELINE
   Retire la capacité de tous les rôles WP.
   ============================================================ */
add_action( 'wp_ajax_rdrit_cap_delete', 'rdrit_cap_delete_ajax' );
function rdrit_cap_delete_ajax() {
    check_ajax_referer( 'rdrit_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );

    $cap = sanitize_key( $_POST['cap'] ?? '' );
    if ( ! $cap ) wp_send_json_error( 'Capacité manquante.' );

    // Refuser la suppression des capacités Core
    $core_caps = array(
        'activate_plugins','create_users','delete_others_pages','delete_others_posts',
        'delete_pages','delete_plugins','delete_posts','delete_private_pages',
        'delete_private_posts','delete_published_pages','delete_published_posts',
        'delete_themes','delete_users','edit_dashboard','edit_files','edit_others_pages',
        'edit_others_posts','edit_pages','edit_plugins','edit_posts','edit_private_pages',
        'edit_private_posts','edit_published_pages','edit_published_posts','edit_theme_options',
        'edit_themes','edit_users','export','import','install_plugins','install_themes',
        'list_users','manage_categories','manage_links','manage_options','moderate_comments',
        'promote_users','publish_pages','publish_posts','read','read_private_pages',
        'read_private_posts','remove_users','switch_themes','unfiltered_html','unfiltered_upload',
        'update_core','update_plugins','update_themes','upload_files','customize','delete_site',
        'manage_network','manage_network_options','manage_network_plugins','manage_network_themes',
        'manage_network_users','manage_sites',
    );

    if ( in_array( $cap, $core_caps, true ) ) {
        wp_send_json_error( 'Les capacités Core WordPress ne peuvent pas être supprimées.' );
    }

    // Supprimer via l'API WP_Roles — la seule méthode qui couvre
    // les caps ajoutées au runtime ET celles persistées en base
    global $wpdb;
    $removed = 0;

    // Méthode 1 : via l'objet WP_Roles (couvre caps runtime + DB)
    $wp_roles = wp_roles();
    foreach ( $wp_roles->role_objects as $role_slug => $role_obj ) {
        if ( isset( $role_obj->capabilities[ $cap ] ) ) {
            $role_obj->remove_cap( $cap );
            $removed++;
        }
    }

    // Méthode 2 : forcer la persistance en modifiant directement l'option DB
    // (remove_cap() persiste normalement mais on s'en assure)
    $option_key = $wp_roles->role_key;
    $user_roles = get_option( $option_key, array() );

    // Essayer aussi avec le préfixe wpdb si différent
    if ( empty( $user_roles ) ) {
        $option_key = $wpdb->get_blog_prefix() . 'user_roles';
        $user_roles = get_option( $option_key, array() );
    }

    $changed = false;
    foreach ( $user_roles as $role_slug => &$role_data ) {
        if ( isset( $role_data['capabilities'][ $cap ] ) ) {
            unset( $role_data['capabilities'][ $cap ] );
            $changed = true;
        }
    }
    unset( $role_data );

    if ( $changed ) {
        update_option( $option_key, $user_roles );
    }

    if ( $removed === 0 && ! $changed ) {
        wp_send_json_error( "Capacité \"{$cap}\" introuvable — elle est peut-être ajoutée uniquement au runtime par un plugin (non persistée en base)." );
    }

    wp_send_json_success( array(
        'cap'        => $cap,
        'removed'    => $removed,
        'option_key' => $option_key,
        'msg'        => "Capacité \"{$cap}\" supprimée de {$removed} rôle(s).",
    ) );
}

/* ============================================================
   DÉSACTIVER JQUERY EN FRONT
   Options : 'off' | 'admin' | 'logged_in' | 'all'
   ============================================================ */
add_action( 'wp_enqueue_scripts', 'rdrit_tweak_disable_jquery_front', PHP_INT_MAX );
function rdrit_tweak_disable_jquery_front(): void {
    $mode = get_option( 'rdrit_tweak_jquery_front', 'off' );
    if ( $mode === 'off' ) return;

    $should_disable = false;
    if ( $mode === 'all' ) {
        $should_disable = true;
    } elseif ( $mode === 'all_except_admin' && ! current_user_can( 'manage_options' ) ) {
        $should_disable = true;
    } elseif ( $mode === 'logged_in' && is_user_logged_in() ) {
        $should_disable = true;
    } elseif ( $mode === 'admin' && current_user_can( 'manage_options' ) ) {
        $should_disable = true;
    }

    if ( $should_disable ) {
        wp_dequeue_script( 'jquery' );
        wp_dequeue_script( 'jquery-core' );
        wp_dequeue_script( 'jquery-migrate' );
        wp_deregister_script( 'jquery' );
        wp_deregister_script( 'jquery-core' );
        wp_deregister_script( 'jquery-migrate' );
    }
}

/* ============================================================
   MISSED SCHEDULED POSTS — Cron toutes les 5 minutes
   
   Remplace l'approche "wp_head" qui ne fonctionne pas avec
   un cache agressif (LiteSpeed + Cloudflare APO) car les pages
   sont servies sans passer par PHP.
   
   Le cron WP se déclenche via les visites OU via un vrai cron
   serveur (recommandé : désactiver DISABLE_WP_CRON et ajouter
   une entrée crontab toutes les 5 min avec curl wp-cron.php)
   ============================================================ */

add_action( 'init', 'rdrit_tweak_missed_posts_cron_init' );
function rdrit_tweak_missed_posts_cron_init(): void {
    if ( ! (bool) get_option( 'rdrit_tweak_missed_posts', 0 ) ) {
        // Désactivé → nettoyer le cron si planifié
        $next = wp_next_scheduled( 'rdrit_publish_missed_posts' );
        if ( $next ) wp_unschedule_event( $next, 'rdrit_publish_missed_posts' );
        return;
    }

    // Enregistrer l'intervalle custom "every5minutes"
    if ( ! wp_next_scheduled( 'rdrit_publish_missed_posts' ) ) {
        wp_schedule_event( time(), 'rdrit_every5min', 'rdrit_publish_missed_posts' );
    }
}

add_filter( 'cron_schedules', 'rdrit_tweak_add_5min_schedule' );
function rdrit_tweak_add_5min_schedule( array $schedules ): array {
    if ( ! isset( $schedules['rdrit_every5min'] ) ) {
        $schedules['rdrit_every5min'] = array(
            'interval' => 300,
            'display'  => 'Toutes les 5 minutes (RDR-IT)',
        );
    }
    return $schedules;
}

add_action( 'rdrit_publish_missed_posts', 'rdrit_tweak_do_publish_missed' );
function rdrit_tweak_do_publish_missed(): void {
    global $wpdb;

    // Tous les CPT publics + post + page
    $post_types = array_merge(
        array( 'post', 'page' ),
        get_post_types( array( 'public' => true, '_builtin' => false ), 'names' )
    );
    $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
    $now          = gmdate( 'Y-m-d H:i:s' );

    $ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type IN ($placeholders)
             AND post_status = 'future'
             AND post_date_gmt <= %s",
            array_merge( $post_types, array( $now ) )
        )
    );

    if ( empty( $ids ) ) return;

    foreach ( $ids as $post_id ) {
        wp_publish_post( (int) $post_id );
    }

    // Log dans le debug WP si actif
    if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( sprintf(
            '[RDR-IT] Missed posts publiés : %d article(s) — IDs : %s',
            count( $ids ),
            implode( ', ', $ids )
        ) );
    }
}
