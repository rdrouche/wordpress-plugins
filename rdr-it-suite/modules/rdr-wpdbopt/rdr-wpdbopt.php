<?php
/**
 * Module : WP DB Optimizer
 * Slug   : rdr-wpdbopt
 * Version: 1.0.0
 *
 * Fonctionnalités :
 *   - Table wp_options : liste, toggle autoload, export (JSON/CSV), suppression, stats
 *   - Métas orphelines : postmeta / termmeta / usermeta
 *   - Métas par meta_key (suppression de clés de plugins désinstallés)
 *
 * Règles absolues :
 *   R1  Namespace obligatoire
 *   R5  Zéro jQuery — Vanilla JS uniquement
 *   R6  Tout le code dans le hook rdr_suite_module_loaded_{slug}
 *   Nonce dédié rdr_wpdbopt_nonce — circuit 100 % interne au module
 */

namespace RDR\Modules\Wpdbopt;

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------
   Chargement du module (R6)
--------------------------------------------------------------- */
\add_action( 'rdr_suite_module_loaded_rdr-wpdbopt', static function (): void {

    /* ── 1. Menu admin ── */
    \add_action( 'admin_menu', static function (): void {
        \add_submenu_page(
            'rdr-it-suite',
            'WP DB Optimizer',
            '🗄️ Meta Optimizer',
            'manage_options',
            'rdr-wpdbopt',
            __NAMESPACE__ . '\render_page'
        );
    } );

    /* ── 2. Enqueue assets (uniquement sur notre page) ── */
    \add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
        if ( ! str_contains( $hook, 'rdr-wpdbopt' ) ) {
            return;
        }
        \wp_enqueue_style(
            'rdr-wpdbopt-css',
            \RDR_SUITE_URL . 'modules/rdr-wpdbopt/assets/wpdbopt.css',
            [],
            '1.0.0'
        );
        \wp_enqueue_script(
            'rdr-wpdbopt-js',
            \RDR_SUITE_URL . 'modules/rdr-wpdbopt/assets/wpdbopt.js',
            [],
            '1.0.0',
            true
        );
        /* Nonce dédié — circuit 100 % interne, indépendant de rdrSuite.nonce */
        \wp_localize_script( 'rdr-wpdbopt-js', 'RDR_WPDBOPT', [
            'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
            'nonce'   => \wp_create_nonce( 'rdr_wpdbopt_nonce' ),
            'i18n'    => [
                'confirm_delete'   => __( 'Supprimer définitivement ? Cette action est irréversible.', 'rdr-wpdbopt' ),
                'confirm_autoload' => __( 'Modifier l\'autoload de cette option ?', 'rdr-wpdbopt' ),
                'loading'          => __( 'Chargement…', 'rdr-wpdbopt' ),
                'error'            => __( 'Erreur — voir la console.', 'rdr-wpdbopt' ),
                'done'             => __( 'Opération effectuée.', 'rdr-wpdbopt' ),
                'exported'         => __( 'Export téléchargé.', 'rdr-wpdbopt' ),
            ],
        ] );
    } );

    /* ── 3. Handlers AJAX ── */
    _register_ajax_handlers();

} );

/* ===============================================================
   RENDU PAGE
=============================================================== */
function render_page(): void {
    if ( ! \current_user_can( 'manage_options' ) ) {
        \wp_die( \esc_html__( 'Accès refusé.', 'rdr-wpdbopt' ) );
    }
    include __DIR__ . '/settings-view.php';
}

/* ===============================================================
   ENREGISTREMENT DES HANDLERS AJAX
=============================================================== */
function _register_ajax_handlers(): void {

    $actions = [
        'rdr_wpdbopt_get_options_stats'   => '_ajax_get_options_stats',
        'rdr_wpdbopt_get_options_list'    => '_ajax_get_options_list',
        'rdr_wpdbopt_toggle_autoload'     => '_ajax_toggle_autoload',
        'rdr_wpdbopt_delete_option'       => '_ajax_delete_option',
        'rdr_wpdbopt_export_options'      => '_ajax_export_options',
        'rdr_wpdbopt_save_export_file'    => '_ajax_save_export_file',
        'rdr_wpdbopt_get_meta_stats'      => '_ajax_get_meta_stats',
        'rdr_wpdbopt_get_orphan_meta'     => '_ajax_get_orphan_meta',
        'rdr_wpdbopt_delete_orphan_meta'  => '_ajax_delete_orphan_meta',
        'rdr_wpdbopt_get_meta_by_key'     => '_ajax_get_meta_by_key',
        'rdr_wpdbopt_delete_meta_by_key'  => '_ajax_delete_meta_by_key',
    ];

    foreach ( $actions as $action => $cb ) {
        \add_action( 'wp_ajax_' . $action, __NAMESPACE__ . '\\' . $cb );
    }
}

/* ===============================================================
   UTILITAIRE — Vérification nonce + capability
=============================================================== */
function _check_ajax(): void {
    if ( ! \check_ajax_referer( 'rdr_wpdbopt_nonce', 'nonce', false ) ) {
        \wp_send_json_error( [ 'message' => 'Nonce invalide.' ], 403 );
    }
    if ( ! \current_user_can( 'manage_options' ) ) {
        \wp_send_json_error( [ 'message' => 'Accès refusé.' ], 403 );
    }
}

/* ===============================================================
   AJAX — STATS OPTIONS
=============================================================== */
function _ajax_get_options_stats(): void {
    _check_ajax();
    global $wpdb;

    $total_size = (int) $wpdb->get_var(
        "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options}"
    );
    $total_count = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options}"
    );
    $autoload_yes = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload IN ('yes','on','true','1')"
    );
    $autoload_no = $total_count - $autoload_yes;

    $autoload_size = (int) $wpdb->get_var(
        "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','true','1')"
    );

    \wp_send_json_success( [
        'total_count'   => $total_count,
        'total_size'    => _format_bytes( $total_size ),
        'autoload_yes'  => $autoload_yes,
        'autoload_no'   => $autoload_no,
        'autoload_size' => _format_bytes( $autoload_size ),
    ] );
}

/* ===============================================================
   AJAX — LISTE OPTIONS (paginée, filtrable)
=============================================================== */
function _ajax_get_options_list(): void {
    _check_ajax();
    global $wpdb;

    $page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
    $per_page = min( 200, max( 10, (int) ( $_POST['per_page'] ?? 50 ) ) );
    $search   = sanitize_text_field( $_POST['search'] ?? '' );
    $autoload_filter = sanitize_key( $_POST['autoload'] ?? 'all' ); // all | yes | no
    $offset   = ( $page - 1 ) * $per_page;

    $where  = '1=1';
    $params = [];

    if ( '' !== $search ) {
        $where   .= ' AND option_name LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $search ) . '%';
    }
    if ( 'yes' === $autoload_filter ) {
        $where .= " AND autoload IN ('yes','on','true','1')";
    } elseif ( 'no' === $autoload_filter ) {
        $where .= " AND autoload NOT IN ('yes','on','true','1')";
    }

    $count_sql = "SELECT COUNT(*) FROM {$wpdb->options} WHERE {$where}";
    $total     = ( $params )
        ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
        : (int) $wpdb->get_var( $count_sql );

    $params[] = $per_page;
    $params[] = $offset;
    $data_sql  = "SELECT option_id, option_name, autoload,
                         LENGTH(option_value) AS size_bytes,
                         LEFT(option_value, 120) AS value_preview
                  FROM {$wpdb->options}
                  WHERE {$where}
                  ORDER BY size_bytes DESC
                  LIMIT %d OFFSET %d";

    $rows = $wpdb->get_results( $wpdb->prepare( $data_sql, ...$params ), \ARRAY_A );

    /* Formatage taille + autoload normalisé */
    $options_core = _get_protected_option_names();
    foreach ( $rows as &$row ) {
        $row['size_human']       = _format_bytes( (int) $row['size_bytes'] );
        $row['autoload_bool']    = in_array( $row['autoload'], [ 'yes', 'on', 'true', '1' ], true );
        $row['is_protected']     = in_array( $row['option_name'], $options_core, true );
    }
    unset( $row );

    \wp_send_json_success( [
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'rows'     => $rows,
    ] );
}

/* ===============================================================
   AJAX — TOGGLE AUTOLOAD
=============================================================== */
function _ajax_toggle_autoload(): void {
    _check_ajax();
    global $wpdb;

    $option_name = sanitize_key( $_POST['option_name'] ?? '' );
    if ( '' === $option_name ) {
        \wp_send_json_error( [ 'message' => 'option_name manquant.' ] );
    }

    /* Protections critiques — on ne touche pas à ces clés */
    if ( in_array( $option_name, _get_protected_option_names(), true ) ) {
        \wp_send_json_error( [ 'message' => 'Option protégée — autoload non modifiable.' ] );
    }

    $new_autoload = sanitize_key( $_POST['autoload'] ?? 'yes' ); // 'yes' | 'no'
    if ( ! in_array( $new_autoload, [ 'yes', 'no' ], true ) ) {
        \wp_send_json_error( [ 'message' => 'Valeur autoload invalide.' ] );
    }

    /*
     * update_option() ne permet pas de changer uniquement l'autoload sans
     * modifier la valeur. On passe par wpdb->update() ciblé.
     * On utilise quand même update_option d'abord pour déclencher les hooks,
     * puis on corrige l'autoload en SQL.
     */
    $current_value = \get_option( $option_name );
    \update_option( $option_name, $current_value, $new_autoload );

    \wp_send_json_success( [ 'autoload' => $new_autoload ] );
}

/* ===============================================================
   AJAX — SUPPRESSION OPTION
=============================================================== */
function _ajax_delete_option(): void {
    _check_ajax();

    $option_name = sanitize_key( $_POST['option_name'] ?? '' );
    if ( '' === $option_name ) {
        \wp_send_json_error( [ 'message' => 'option_name manquant.' ] );
    }
    if ( in_array( $option_name, _get_protected_option_names(), true ) ) {
        \wp_send_json_error( [ 'message' => 'Option protégée — suppression refusée.' ] );
    }

    $deleted = \delete_option( $option_name );
    if ( $deleted ) {
        \wp_send_json_success( [ 'message' => "Option « {$option_name} » supprimée." ] );
    } else {
        \wp_send_json_error( [ 'message' => "Impossible de supprimer « {$option_name} »." ] );
    }
}

/* ===============================================================
   AJAX — EXPORT OPTIONS (download JSON ou CSV)
=============================================================== */
function _ajax_export_options(): void {
    _check_ajax();
    global $wpdb;

    $format       = in_array( $_POST['format'] ?? 'json', [ 'json', 'csv' ], true ) ? $_POST['format'] : 'json';
    $autoload_filter = sanitize_key( $_POST['autoload'] ?? 'all' );
    $search       = sanitize_text_field( $_POST['search'] ?? '' );

    $where  = '1=1';
    $params = [];
    if ( '' !== $search ) {
        $where   .= ' AND option_name LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $search ) . '%';
    }
    if ( 'yes' === $autoload_filter ) {
        $where .= " AND autoload IN ('yes','on','true','1')";
    } elseif ( 'no' === $autoload_filter ) {
        $where .= " AND autoload NOT IN ('yes','on','true','1')";
    }

    $sql  = "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE {$where} ORDER BY option_name ASC";
    $rows = $params
        ? $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), \ARRAY_A )
        : $wpdb->get_results( $sql, \ARRAY_A );

    if ( 'csv' === $format ) {
        $out  = "option_name,autoload,option_value\n";
        foreach ( $rows as $r ) {
            $val  = str_replace( '"', '""', $r['option_value'] );
            $out .= '"' . $r['option_name'] . '","' . $r['autoload'] . '","' . $val . '"' . "\n";
        }
        \wp_send_json_success( [ 'format' => 'csv', 'data' => $out ] );
    } else {
        \wp_send_json_success( [ 'format' => 'json', 'data' => \wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ] );
    }
}

/* ===============================================================
   AJAX — SAUVEGARDE EXPORT SUR SERVEUR
=============================================================== */
function _ajax_save_export_file(): void {
    _check_ajax();
    global $wpdb;

    $format       = in_array( $_POST['format'] ?? 'json', [ 'json', 'csv' ], true ) ? $_POST['format'] : 'json';
    $autoload_filter = sanitize_key( $_POST['autoload'] ?? 'all' );

    $where  = '1=1';
    if ( 'yes' === $autoload_filter ) {
        $where .= " AND autoload IN ('yes','on','true','1')";
    } elseif ( 'no' === $autoload_filter ) {
        $where .= " AND autoload NOT IN ('yes','on','true','1')";
    }

    $rows = $wpdb->get_results(
        "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE {$where} ORDER BY option_name ASC",
        \ARRAY_A
    );

    /* Dossier dédié wp-content/rdr-exports/ */
    $upload_dir = \wp_upload_dir();
    $export_dir = \trailingslashit( WP_CONTENT_DIR ) . 'rdr-exports';
    if ( ! is_dir( $export_dir ) ) {
        \wp_mkdir_p( $export_dir );
        file_put_contents( $export_dir . '/.htaccess', "Options -Indexes\nDeny from all\n" );
        file_put_contents( $export_dir . '/index.php', '<?php // Silence' );
    }

    $filename = 'options-export-' . \gmdate( 'Y-m-d-His' ) . '.' . $format;
    $filepath = $export_dir . '/' . $filename;

    if ( 'csv' === $format ) {
        $out  = "option_name,autoload,option_value\n";
        foreach ( $rows as $r ) {
            $val  = str_replace( '"', '""', $r['option_value'] );
            $out .= '"' . $r['option_name'] . '","' . $r['autoload'] . '","' . $val . '"' . "\n";
        }
        file_put_contents( $filepath, $out );
    } else {
        file_put_contents( $filepath, \wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
    }

    \wp_send_json_success( [
        'path'     => $filepath,
        'filename' => $filename,
        'size'     => _format_bytes( filesize( $filepath ) ),
    ] );
}

/* ===============================================================
   AJAX — STATS MÉTAS
=============================================================== */
function _ajax_get_meta_stats(): void {
    _check_ajax();
    global $wpdb;

    $stats = [];
    $tables = [
        'post' => $wpdb->postmeta,
        'term' => $wpdb->termmeta,
        'user' => $wpdb->usermeta,
    ];
    foreach ( $tables as $type => $table ) {
        $col_id   = ( 'user' === $type ) ? 'user_id' : ( 'term' === $type ? 'term_id' : 'post_id' );
        $pk_table = ( 'user' === $type ) ? $wpdb->users : ( 'term' === $type ? $wpdb->terms : $wpdb->posts );

        $total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $orphans   = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} m
             LEFT JOIN {$pk_table} p ON p.ID = m.{$col_id}
             WHERE p.ID IS NULL"
        );
        $keys_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT meta_key) FROM {$table}" );
        $size       = (int) $wpdb->get_var( "SELECT SUM(LENGTH(meta_value)) FROM {$table}" );

        $stats[ $type ] = [
            'total'      => $total,
            'orphans'    => $orphans,
            'keys_count' => $keys_count,
            'size'       => _format_bytes( $size ),
        ];
    }

    \wp_send_json_success( $stats );
}

/* ===============================================================
   AJAX — LISTE MÉTAS ORPHELINES
=============================================================== */
function _ajax_get_orphan_meta(): void {
    _check_ajax();
    global $wpdb;

    $type = sanitize_key( $_POST['meta_type'] ?? 'post' );
    if ( ! in_array( $type, [ 'post', 'term', 'user' ], true ) ) {
        \wp_send_json_error( [ 'message' => 'Type de méta invalide.' ] );
    }

    $page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
    $per_page = min( 200, max( 10, (int) ( $_POST['per_page'] ?? 50 ) ) );
    $offset   = ( $page - 1 ) * $per_page;

    [ $table, $pk_table, $col_id, $pk_col ] = _meta_table_info( $type );

    $total = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$table} m
         LEFT JOIN {$pk_table} p ON p.{$pk_col} = m.{$col_id}
         WHERE p.{$pk_col} IS NULL"
    );

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT m.meta_id, m.{$col_id} AS object_id, m.meta_key, LEFT(m.meta_value, 80) AS meta_value_preview
         FROM {$table} m
         LEFT JOIN {$pk_table} p ON p.{$pk_col} = m.{$col_id}
         WHERE p.{$pk_col} IS NULL
         ORDER BY m.{$col_id} ASC
         LIMIT %d OFFSET %d",
        $per_page,
        $offset
    ), \ARRAY_A );

    \wp_send_json_success( [
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'rows'     => $rows,
    ] );
}

/* ===============================================================
   AJAX — SUPPRESSION MÉTAS ORPHELINES
=============================================================== */
function _ajax_delete_orphan_meta(): void {
    _check_ajax();
    global $wpdb;

    $type = sanitize_key( $_POST['meta_type'] ?? 'post' );
    if ( ! in_array( $type, [ 'post', 'term', 'user' ], true ) ) {
        \wp_send_json_error( [ 'message' => 'Type de méta invalide.' ] );
    }

    /* meta_ids fournis par le JS (suppression ciblée) ou suppression globale */
    $meta_ids_raw = $_POST['meta_ids'] ?? [];
    $delete_all   = filter_var( $_POST['delete_all'] ?? false, FILTER_VALIDATE_BOOLEAN );

    [ $table, $pk_table, $col_id, $pk_col ] = _meta_table_info( $type );

    if ( $delete_all ) {
        /* Suppression de TOUTES les orphelines du type — requête directe */
        $deleted = $wpdb->query(
            "DELETE m FROM {$table} m
             LEFT JOIN {$pk_table} p ON p.{$pk_col} = m.{$col_id}
             WHERE p.{$pk_col} IS NULL"
        );
    } else {
        if ( empty( $meta_ids_raw ) || ! is_array( $meta_ids_raw ) ) {
            \wp_send_json_error( [ 'message' => 'Aucun meta_id fourni.' ] );
        }
        /* Sanitisation stricte : IDs entiers uniquement */
        $meta_ids = array_map( 'intval', $meta_ids_raw );
        $meta_ids = array_filter( $meta_ids, static fn( $id ) => $id > 0 );
        if ( empty( $meta_ids ) ) {
            \wp_send_json_error( [ 'message' => 'IDs invalides.' ] );
        }
        /* Vérification que chaque meta_id est bien orphelin avant suppression */
        $placeholders = implode( ',', array_fill( 0, count( $meta_ids ), '%d' ) );
        $verified_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT m.meta_id FROM {$table} m
             LEFT JOIN {$pk_table} p ON p.{$pk_col} = m.{$col_id}
             WHERE p.{$pk_col} IS NULL
             AND m.meta_id IN ({$placeholders})",
            ...$meta_ids
        ) );

        if ( empty( $verified_ids ) ) {
            \wp_send_json_error( [ 'message' => 'Aucune orpheline confirmée parmi les IDs fournis.' ] );
        }

        $vp = implode( ',', array_fill( 0, count( $verified_ids ), '%d' ) );
        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE meta_id IN ({$vp})",
            ...$verified_ids
        ) );
    }

    \wp_send_json_success( [ 'deleted' => (int) $deleted ] );
}

/* ===============================================================
   AJAX — LISTE META PAR CLE
=============================================================== */
function _ajax_get_meta_by_key(): void {
    _check_ajax();
    global $wpdb;

    $type = sanitize_key( $_POST['meta_type'] ?? 'post' );
    if ( ! in_array( $type, [ 'post', 'term', 'user' ], true ) ) {
        \wp_send_json_error( [ 'message' => 'Type de méta invalide.' ] );
    }

    $page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
    $per_page = min( 200, max( 10, (int) ( $_POST['per_page'] ?? 50 ) ) );
    $search   = sanitize_text_field( $_POST['search'] ?? '' );
    $offset   = ( $page - 1 ) * $per_page;

    [ $table ] = _meta_table_info( $type );

    $where  = '1=1';
    $params = [];
    if ( '' !== $search ) {
        $where   .= ' AND meta_key LIKE %s';
        $params[] = '%' . $wpdb->esc_like( $search ) . '%';
    }

    $count_sql = "SELECT COUNT(DISTINCT meta_key) FROM {$table} WHERE {$where}";
    $total = $params
        ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
        : (int) $wpdb->get_var( $count_sql );

    $params[] = $per_page;
    $params[] = $offset;
    $data_sql = "SELECT meta_key,
                        COUNT(*) AS count,
                        SUM(LENGTH(meta_value)) AS size_bytes
                 FROM {$table}
                 WHERE {$where}
                 GROUP BY meta_key
                 ORDER BY count DESC
                 LIMIT %d OFFSET %d";

    $rows = $wpdb->get_results(
        $params ? $wpdb->prepare( $data_sql, ...$params ) : $data_sql,
        \ARRAY_A
    );

    foreach ( $rows as &$row ) {
        $row['size_human']    = _format_bytes( (int) $row['size_bytes'] );
        $row['is_protected']  = _is_protected_meta_key( $row['meta_key'], $type );
    }
    unset( $row );

    \wp_send_json_success( [
        'total'    => $total,
        'page'     => $page,
        'per_page' => $per_page,
        'rows'     => $rows,
    ] );
}

/* ===============================================================
   AJAX — SUPPRESSION META PAR CLE
=============================================================== */
function _ajax_delete_meta_by_key(): void {
    _check_ajax();
    global $wpdb;

    $type = sanitize_key( $_POST['meta_type'] ?? 'post' );
    if ( ! in_array( $type, [ 'post', 'term', 'user' ], true ) ) {
        \wp_send_json_error( [ 'message' => 'Type de méta invalide.' ] );
    }

    $meta_key = sanitize_text_field( $_POST['meta_key'] ?? '' );
    if ( '' === $meta_key ) {
        \wp_send_json_error( [ 'message' => 'meta_key manquant.' ] );
    }
    if ( _is_protected_meta_key( $meta_key, $type ) ) {
        \wp_send_json_error( [ 'message' => "Clé « {$meta_key} » protégée — suppression refusée." ] );
    }

    [ $table ] = _meta_table_info( $type );

    $deleted = $wpdb->delete( $table, [ 'meta_key' => $meta_key ], [ '%s' ] );

    if ( false === $deleted ) {
        \wp_send_json_error( [ 'message' => 'Erreur SQL lors de la suppression.' ] );
    }

    \wp_send_json_success( [ 'deleted' => (int) $deleted ] );
}

/* ===============================================================
   HELPERS INTERNES
=============================================================== */

/**
 * Retourne [ $table, $pk_table, $col_id_in_meta, $pk_col ]
 *
 * @param string $type post|term|user
 * @return array{string,string,string,string}
 */
function _meta_table_info( string $type ): array {
    global $wpdb;
    return match ( $type ) {
        'term' => [ $wpdb->termmeta, $wpdb->terms,    'term_id', 'term_id' ],
        'user' => [ $wpdb->usermeta, $wpdb->users,    'user_id', 'ID'      ],
        default=> [ $wpdb->postmeta, $wpdb->posts,    'post_id', 'ID'      ],
    };
}

/**
 * Liste des options WordPress critiques — ni autoload ni suppression.
 *
 * @return string[]
 */
function _get_protected_option_names(): array {
    return [
        'siteurl', 'blogname', 'blogdescription', 'admin_email', 'blogpublic',
        'template', 'stylesheet', 'active_plugins', 'wp_user_roles',
        'db_version', 'initial_db_version', 'upload_path', 'upload_url_path',
        'permalink_structure', 'category_base', 'tag_base',
        'blog_charset', 'blog_public', 'default_role', 'timezone_string',
        'gmt_offset', 'date_format', 'time_format', 'start_of_week',
        'users_can_register', 'default_comment_status', 'default_ping_status',
        'rewrite_rules', 'widget_recent-posts', 'sidebars_widgets',
        \RDR_SUITE_OPTION,
    ];
}

/**
 * Clés meta protégées (WP core) par type.
 *
 * @return bool
 */
function _is_protected_meta_key( string $key, string $type ): bool {
    $protected = [
        'post' => [
            '_wp_page_template', '_wp_attached_file', '_wp_attachment_metadata',
            '_thumbnail_id', '_edit_lock', '_edit_last',
            '_wp_trash_meta_status', '_wp_trash_meta_time',
        ],
        'term' => [],
        'user' => [
            'session_tokens', 'wp_capabilities', 'wp_user_level',
            'dismissed_wp_pointers', 'show_welcome_panel',
            'wp_dashboard_quick_press_last_post_id',
        ],
    ];
    return in_array( $key, $protected[ $type ] ?? [], true );
}

/**
 * Formate un nombre d'octets en unité lisible.
 */
function _format_bytes( int $bytes ): string {
    if ( $bytes < 1024 ) {
        return $bytes . ' B';
    }
    if ( $bytes < 1048576 ) {
        return round( $bytes / 1024, 2 ) . ' KB';
    }
    return round( $bytes / 1048576, 2 ) . ' MB';
}
