<?php
/**
 * Suivi de progression des cours
 *
 * STRATÉGIE :
 *   Connecté  → stockage en user_meta (wp_usermeta, propre et fiable)
 *   Anonyme   → localStorage uniquement (token auto, pas de banneau,
 *               pas de wp_options — UX simple, RGPD ok car local seulement)
 *
 * Le JS marque les leçons vues automatiquement à l'ouverture.
 * Pas de consentement demandé pour le localStorage (données purement locales).
 */

/* ── AJAX : marquer une leçon ────────────────────────────── */
add_action( 'wp_ajax_nopriv_rdrc_mark_lesson', 'rdrc_ajax_mark_lesson' );
add_action( 'wp_ajax_rdrc_mark_lesson',        'rdrc_ajax_mark_lesson' );

function rdrc_ajax_mark_lesson() {
    check_ajax_referer( 'rdrc_progress', 'nonce' );

    $lesson_id = (int) ( $_POST['lesson_id'] ?? 0 );
    $done      = ! empty( $_POST['done'] );

    if ( ! $lesson_id ) wp_send_json_error( 'invalid' );

    $course_id = (int) get_post_meta( $lesson_id, '_rdrc_parent_course', true );

    if ( is_user_logged_in() ) {
        // ── Connecté : user_meta ──────────────────────────
        $user_id = get_current_user_id();
        $key     = '_rdrc_progress_' . $course_id;
        $data    = get_user_meta( $user_id, $key, true );
        if ( ! is_array( $data ) ) $data = [];

        if ( $done ) {
            $data[ $lesson_id ] = time();
        } else {
            unset( $data[ $lesson_id ] );
        }

        update_user_meta( $user_id, $key, $data );
        wp_send_json_success( [ 'count' => count( $data ), 'storage' => 'db' ] );

    } else {
        // ── Anonyme : le JS gère localStorage, on ne stocke rien côté serveur
        wp_send_json_success( [ 'storage' => 'local' ] );
    }
}

/* ── AJAX : récupérer la progression d'un cours ─────────── */
add_action( 'wp_ajax_nopriv_rdrc_get_progress', 'rdrc_ajax_get_progress' );
add_action( 'wp_ajax_rdrc_get_progress',        'rdrc_ajax_get_progress' );

function rdrc_ajax_get_progress() {
    check_ajax_referer( 'rdrc_progress', 'nonce' );

    $course_id = (int) ( $_POST['course_id'] ?? 0 );

    if ( is_user_logged_in() ) {
        $user_id = get_current_user_id();
        $key     = '_rdrc_progress_' . $course_id;
        $data    = get_user_meta( $user_id, $key, true );
        if ( ! is_array( $data ) ) $data = [];
        $done = array_map( 'intval', array_keys( $data ) );
        wp_send_json_success( [ 'done' => $done, 'storage' => 'db' ] );

    } else {
        // Anonyme : le JS lit localStorage et renvoie directement la liste
        // On retourne juste storage=local pour que le JS sache quoi faire
        wp_send_json_success( [ 'done' => [], 'storage' => 'local' ] );
    }
}


/* ── AJAX : progression de plusieurs cours d'un coup ─────────
 * Utilise par la page "Mes formations" et la vue parcours pour
 * hydrater localStorage depuis la BDD quand l'utilisateur est connecte.
 * ─────────────────────────────────────────────────────────── */
add_action( 'wp_ajax_rdrc_get_progress_bulk', function() {
    check_ajax_referer( 'rdrc_progress', 'nonce' );

    if ( ! is_user_logged_in() ) wp_send_json_success( [ 'courses' => (object) [] ] );

    $ids = isset( $_POST['course_ids'] ) ? (string) $_POST['course_ids'] : '';
    $ids = array_filter( array_map( 'intval', explode( ',', $ids ) ) );
    if ( empty( $ids ) ) wp_send_json_success( [ 'courses' => (object) [] ] );

    $user_id = get_current_user_id();
    $out     = [];

    foreach ( array_slice( $ids, 0, 100 ) as $cid ) {
        $data = get_user_meta( $user_id, '_rdrc_progress_' . $cid, true );
        $out[ $cid ] = is_array( $data ) ? array_map( 'intval', array_keys( $data ) ) : [];
    }

    wp_send_json_success( [ 'courses' => $out ] );
} );

/* ── AJAX : pousser un lot de lecons vers la BDD ─────────────
 * Permet de remonter en base ce qui a ete fait hors ligne /
 * avant connexion (fusion localStorage -> usermeta).
 * ─────────────────────────────────────────────────────────── */
add_action( 'wp_ajax_rdrc_push_progress', function() {
    check_ajax_referer( 'rdrc_progress', 'nonce' );
    if ( ! is_user_logged_in() ) wp_send_json_error( 'not_logged_in' );

    $course_id = (int) ( $_POST['course_id'] ?? 0 );
    $lessons   = isset( $_POST['lesson_ids'] ) ? (string) $_POST['lesson_ids'] : '';
    $lessons   = array_filter( array_map( 'intval', explode( ',', $lessons ) ) );

    if ( ! $course_id ) wp_send_json_error( 'invalid' );

    $user_id = get_current_user_id();
    $key     = '_rdrc_progress_' . $course_id;
    $data    = get_user_meta( $user_id, $key, true );
    if ( ! is_array( $data ) ) $data = [];

    foreach ( $lessons as $lid ) {
        if ( ! isset( $data[ $lid ] ) ) $data[ $lid ] = time();
    }

    update_user_meta( $user_id, $key, $data );
    wp_send_json_success( [ 'count' => count( $data ) ] );
} );

/* ── Enqueue : données JS ────────────────────────────────── */
add_action( 'wp_enqueue_scripts', function() {
    if ( ! rdrc_is_course_page() ) return;

    $data = [
        'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
        'nonce'      => wp_create_nonce( 'rdrc_progress' ),
        'lessonId'   => is_singular( 'rdrc_lesson' ) ? get_the_ID() : 0,
        'courseId'   => is_singular( 'rdrc_course' )
            ? get_the_ID()
            : (int) get_post_meta( get_the_ID(), '_rdrc_parent_course', true ),
        'loggedIn'   => is_user_logged_in(),
        'userId'     => is_user_logged_in() ? get_current_user_id() : 0,
    ];

    wp_localize_script( 'rdr-courses', 'rdrcProgress', $data );
}, 20 );
