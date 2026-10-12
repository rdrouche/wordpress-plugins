<?php
/**
 * Module RDR Doom
 *
 * Easter egg : le bouton de lancement n'apparaît qu'après saisie
 * du code invincibilité (IDDQD). Vérification via AJAX + nonce WP.
 * Assets chargés uniquement si le shortcode est présent.
 * Zéro jQuery (règle R5).
 *
 * @package RDR\Modules\RdrDoom
 * @version 1.1.0
 */

declare( strict_types=1 );

namespace RDR\Modules\RdrDoom;

\add_action( 'rdr_suite_module_loaded_rdr-doom', static function (): void {

    \add_shortcode( 'rdr_doom', __NAMESPACE__ . '\\render_shortcode' );
    \add_action( 'wp_enqueue_scripts', __NAMESPACE__ . '\\maybe_enqueue_assets' );
    \add_action( 'wp_footer', __NAMESPACE__ . '\\maybe_render_modal' );

    // Handler AJAX — connecté et non connecté (visiteur public)
    \add_action( 'wp_ajax_rdr_verify_doom_code',        __NAMESPACE__ . '\\ajax_verify_code' );
    \add_action( 'wp_ajax_nopriv_rdr_verify_doom_code', __NAMESPACE__ . '\\ajax_verify_code' );

    // ── Sanitisation des settings avant sauvegarde AJAX ──────────────────
    // Sans ce filtre, class-rdr-admin.php ne sauvegarde rien.
    \add_filter( 'rdr_sanitize_settings_rdr-doom', __NAMESPACE__ . '\\sanitize_settings' );

    // ── Fallback : sauvegarde directe si le filtre n'est pas déclenché ───
    // Permet de déboguer et de fonctionner même si l'implémentation admin
    // diffère de ce qu'on attend.
    \add_action( 'wp_ajax_rdr_save_doom_settings', __NAMESPACE__ . '\\ajax_save_settings_direct' );

} );

/* ── Shortcode ────────────────────────────────────────────────────────────── */

function render_shortcode( array|string $atts ): string {

    $atts = \shortcode_atts(
        [
            'title'       => 'DOOM',
            'placeholder' => 'Entrez le code…',
        ],
        (array) $atts,
        'rdr_doom'
    );

    global $rdr_doom_active;
    $rdr_doom_active = true;

    $title = \esc_attr( $atts['title'] );
    $ph    = \esc_attr( $atts['placeholder'] );

    return sprintf(
        '<div class="rdr-doom-launcher" data-title="%1$s">'
            . '<div class="rdr-doom-secret">'
                . '<input type="text"'
                .        ' id="rdr-doom-secret-input"'
                .        ' class="rdr-doom-secret__input"'
                .        ' maxlength="10"'
                .        ' autocomplete="off"'
                .        ' spellcheck="false"'
                .        ' aria-label="Code secret"'
                .        ' placeholder="%2$s">'
                . '<span class="rdr-doom-secret__hint" aria-live="polite"></span>'
            . '</div>'
            . '<button type="button"'
            .         ' id="rdr-doom-open-btn"'
            .         ' class="rdr-doom-open button"'
            .         ' aria-hidden="true"'
            .         ' hidden>'
            .     '▶ Jouer à DOOM'
            . '</button>'
        . '</div>',
        $title,
        $ph
    );
}

/* ── Assets conditionnels ────────────────────────────────────────────────── */

function maybe_enqueue_assets(): void {

    global $rdr_doom_active, $post;

    $needed = ! empty( $rdr_doom_active )
        || ( $post instanceof \WP_Post && \has_shortcode( $post->post_content, 'rdr_doom' ) );

    if ( ! $needed ) {
        return;
    }

    $url = \RDR_SUITE_URL . 'modules/rdr-doom/assets/';
    $ver = '1.1.0';

    \wp_enqueue_style( 'rdr-doom', $url . 'doom.css', [], $ver );

    // js-dos v8 — chargé depuis jsDelivr (CDN public) ou localement si disponible
    // Pour usage local : placer js-dos.js dans assets/js-dos/js-dos.js
    $jsdos_local = \RDR_SUITE_DIR . 'modules/rdr-doom/assets/js-dos/js-dos.js';
    $jsdos_url   = file_exists( $jsdos_local )
        ? $url . 'js-dos/js-dos.js'
        : 'https://v8.js-dos.com/latest/js-dos.js';

    \wp_enqueue_script( 'rdr-doom-engine', $jsdos_url, [], null, true );

    \wp_enqueue_script( 'rdr-doom', $url . 'doom.js', [ 'rdr-doom-engine' ], $ver, true );

    $cheat_code  = \RDR_Module_Settings::get( 'rdr-doom', 'cheat_code',  'IDDQD' );
    $modal_title = \RDR_Module_Settings::get( 'rdr-doom', 'modal_title', 'DOOM'  );
    $wad_url     = \RDR_Module_Settings::get( 'rdr-doom', 'wad_url',     '' );
    if ( empty( $wad_url ) ) {
        $wad_url = $url . 'doom1.wad';
    }

    \wp_localize_script(
        'rdr-doom',
        'rdrDoomConfig',
        [
            'ajaxUrl'    => \admin_url( 'admin-ajax.php' ),
            'nonce'      => \wp_create_nonce( 'doom_secret_nonce' ),
            'cheatCode'  => \strtoupper( $cheat_code ),  // affiché dans la modale
            'modalTitle' => $modal_title,
            'wadUrl'     => $wad_url,
            'engineUrl'  => $jsdos_url,
        ]
    );
}

/* ── AJAX — vérification du code ─────────────────────────────────────────── */

function ajax_verify_code(): void {

    \check_ajax_referer( 'doom_secret_nonce', 'security' );

    $input = isset( $_POST['code'] ) ? \strtolower( \trim( \sanitize_text_field( \wp_unslash( $_POST['code'] ) ) ) ) : '';
    $cheat = \strtolower( \RDR_Module_Settings::get( 'rdr-doom', 'cheat_code', 'IDDQD' ) );

    if ( $input === $cheat ) {
        $url = \RDR_Module_Settings::get( 'rdr-doom', 'wad_url', '' );
        if ( empty( $url ) ) {
            $url = \RDR_SUITE_URL . 'modules/rdr-doom/assets/doom1.wad';
        }
        \wp_send_json_success( [ 'wadUrl' => $url ] );
    }

    \wp_send_json_error( [ 'message' => 'Code incorrect.' ] );
}

/* ── Sanitisation settings admin ─────────────────────────────────────────── */

/**
 * Appelé par apply_filters('rdr_sanitize_settings_rdr-doom', $_POST)
 * dans class-rdr-admin.php::ajax_save_settings().
 * Retourne un tableau propre prêt pour RDR_Module_Settings::set_many().
 *
 * @param array<string,mixed> $raw Données brutes de $_POST
 * @return array<string,string>
 */
function sanitize_settings( array $raw ): array {

    $clean = [];

    // URL du fichier WAD / bundle .jsdos
    if ( isset( $raw['wad_url'] ) ) {
        $url = \esc_url_raw( \trim( (string) $raw['wad_url'] ) );
        // On accepte aussi les chemins relatifs commençant par /
        $clean['wad_url'] = $url;
    }

    // Code invincibilité — lettres/chiffres uniquement, max 10 car., uppercase
    if ( isset( $raw['cheat_code'] ) ) {
        $code = \strtoupper( \preg_replace( '/[^A-Z0-9]/i', '', (string) $raw['cheat_code'] ) );
        $clean['cheat_code'] = \substr( $code, 0, 10 ) ?: 'IDDQD';
    }

    // Titre de la modale
    if ( isset( $raw['modal_title'] ) ) {
        $clean['modal_title'] = \sanitize_text_field( (string) $raw['modal_title'] ) ?: 'DOOM';
    }

    return $clean;
}

/**
 * Fallback : sauvegarde directe indépendante du mécanisme admin central.
 * Utilisé si apply_filters('rdr_sanitize_settings_rdr-doom') n'est pas
 * appelé par class-rdr-admin.php, ou pour déboguer.
 * Appelé via action AJAX : rdr_save_doom_settings
 */
function ajax_save_settings_direct(): void {
    \check_ajax_referer( 'rdr_suite_nonce', 'security' );

    if ( ! \current_user_can( 'manage_options' ) ) {
        \wp_send_json_error( [ 'message' => 'Permission refusée.' ] );
    }

    $clean = sanitize_settings( $_POST );
    \RDR_Module_Settings::set_many( 'rdr-doom', $clean );

    \wp_send_json_success( [ 'saved' => $clean ] );
}

/* ── Modale HTML (footer) ────────────────────────────────────────────────── */

function maybe_render_modal(): void {

    global $rdr_doom_active, $post;

    $needed = ! empty( $rdr_doom_active )
        || ( $post instanceof \WP_Post && \has_shortcode( $post->post_content, 'rdr_doom' ) );

    if ( ! $needed ) {
        return;
    }

    ?>
    <div id="rdr-doom-modal"
         class="rdr-doom-modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="rdr-doom-modal-title"
         aria-hidden="true">

        <div class="rdr-doom-modal__backdrop" data-action="close"></div>

        <div class="rdr-doom-modal__panel">

            <header class="rdr-doom-modal__header">
                <h2 id="rdr-doom-modal-title" class="rdr-doom-modal__title">DOOM</h2>
                <div class="rdr-doom-modal__cheat">
                    <span class="rdr-doom-modal__cheat-label">God Mode :</span>
                    <code class="rdr-doom-modal__cheat-code" id="rdr-doom-cheat-display">IDDQD</code>
                    <button type="button"
                            class="rdr-doom-modal__cheat-copy"
                            aria-label="Copier le code"
                            data-copy="IDDQD">📋</button>
                </div>
                <button type="button"
                        class="rdr-doom-modal__close"
                        data-action="close"
                        aria-label="Fermer">✕</button>
            </header>

            <div class="rdr-doom-modal__body">
                <div class="rdr-doom-canvas-wrapper">
                    <div id="rdr-doom-loader" class="rdr-doom-loader">
                        <span class="rdr-doom-loader__skull">💀</span>
                        <span class="rdr-doom-loader__text">Chargement de DOOM…</span>
                        <div class="rdr-doom-loader__bar">
                            <div class="rdr-doom-loader__progress" id="rdr-doom-progress"></div>
                        </div>
                    </div>
                    <div id="rdr-doom-mount"></div>
                </div>
            </div>

            <footer class="rdr-doom-modal__footer">
                <span class="rdr-doom-modal__hint">
                    🎮 Flèches · Ctrl = tirer · Espace · Alt · Entrée · Échap
                </span>
                <button type="button" class="button button-sm button-ghost" data-action="fullscreen">⛶ Plein écran</button>
            </footer>

        </div>
    </div>
    <?php
}
