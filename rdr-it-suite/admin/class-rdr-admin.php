<?php
/**
 * Gestion de l'interface d'administration — v1.1.0
 *
 * @package RDR_IT_Suite
 */

defined( 'ABSPATH' ) || exit;

class RDR_Admin {

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'register_menu'  ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_rdr_toggle_module',        [ $this, 'ajax_toggle'         ] );
        add_action( 'wp_ajax_rdr_save_module_settings', [ $this, 'ajax_save_settings'  ] );
    }

    // ── Menu ──────────────────────────────────────────────────────────────────

    public function register_menu(): void {
        add_menu_page(
            __( 'RDR-IT Suite', 'rdr-it-suite' ),
            'RDR-IT Suite',
            'manage_options',
            'rdr-it-suite',
            [ $this, 'render_dashboard' ],
            'data:image/svg+xml;base64,' . base64_encode( $this->logo_svg() ),
            58
        );

        add_submenu_page(
            'rdr-it-suite',
            __( 'Dashboard', 'rdr-it-suite' ),
            __( 'Dashboard', 'rdr-it-suite' ),
            'manage_options',
            'rdr-it-suite',
            [ $this, 'render_dashboard' ]
        );

        // Sous-pages dynamiques pour chaque module qui expose settings-view.php
        foreach ( rdr_suite()->get_discovered() as $slug => $module ) {
            $settings_view = $module['dir'] . 'settings-view.php';
            if ( ! file_exists( $settings_view ) ) {
                continue;
            }

            $icon = $module['icon'] ?? '';
            $name = $module['name'] ?? $slug;

            add_submenu_page(
                'rdr-it-suite',
                sprintf( __( '%s — Réglages', 'rdr-it-suite' ), $name ),
                $icon . ' ' . $name,
                'manage_options',
                'rdr-module-' . $slug,
                function () use ( $slug, $module, $settings_view ) {
                    $this->render_module_settings( $slug, $module, $settings_view );
                }
            );
        }

        add_submenu_page(
            'rdr-it-suite',
            __( 'Documentation', 'rdr-it-suite' ),
            __( 'Documentation', 'rdr-it-suite' ),
            'manage_options',
            'rdr-it-suite-doc',
            [ $this, 'render_doc' ]
        );
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public function enqueue_assets( string $hook ): void {
        if ( strpos( $hook, 'rdr-it-suite' ) === false && strpos( $hook, 'rdr-module-' ) === false && strpos( $hook, 'rdr-it-suite-doc' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'rdr-suite-admin',
            RDR_SUITE_URL . 'assets/css/admin.css',
            [],
            RDR_SUITE_VERSION
        );

        wp_enqueue_script(
            'rdr-suite-admin',
            RDR_SUITE_URL . 'assets/js/admin.js',
            [],
            RDR_SUITE_VERSION,
            true
        );

        wp_localize_script( 'rdr-suite-admin', 'rdrSuite', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'rdr_suite_nonce' ),
            'i18n'    => [
                'activating'   => __( 'Activation…',          'rdr-it-suite' ),
                'deactivating' => __( 'Désactivation…',       'rdr-it-suite' ),
                'saving'       => __( 'Sauvegarde…',          'rdr-it-suite' ),
                'saved'        => __( 'Réglages sauvegardés.','rdr-it-suite' ),
                'error'        => __( 'Erreur réseau.',        'rdr-it-suite' ),
            ],
        ] );
    }

    // ── AJAX : toggle ─────────────────────────────────────────────────────────

    public function ajax_toggle(): void {
        check_ajax_referer( 'rdr_suite_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        $slug   = sanitize_key( $_POST['slug']          ?? '' );
        $action = sanitize_key( $_POST['module_action'] ?? '' );

        if ( ! $slug || ! in_array( $action, [ 'activate', 'deactivate' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid params' ], 400 );
        }

        $loader = rdr_suite();
        $ok = 'activate' === $action
            ? $loader->activate_module( $slug )
            : $loader->deactivate_module( $slug );

        if ( $ok ) {
            $discovered    = $loader->get_discovered();
            $has_settings  = isset( $discovered[ $slug ] ) && file_exists( $discovered[ $slug ]['dir'] . 'settings-view.php' );
            wp_send_json_success( [
                'slug'         => $slug,
                'active'       => $loader->is_active( $slug ),
                'settings_url' => $has_settings ? admin_url( 'admin.php?page=rdr-module-' . $slug ) : '',
            ] );
        } else {
            wp_send_json_error( [ 'message' => 'Module not found' ], 404 );
        }
    }

    // ── AJAX : save settings ──────────────────────────────────────────────────

    public function ajax_save_settings(): void {
        check_ajax_referer( 'rdr_suite_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Forbidden' ], 403 );
        }

        $slug = sanitize_key( $_POST['slug'] ?? '' );
        if ( ! $slug ) {
            wp_send_json_error( [ 'message' => 'Missing slug' ], 400 );
        }

        $raw       = $_POST['settings'] ?? [];
        $sanitized = apply_filters( "rdr_sanitize_settings_{$slug}", $raw );

        $to_save = [];
        foreach ( (array) $sanitized as $key => $val ) {
            $to_save[ sanitize_key( $key ) ] = sanitize_textarea_field( $val );
        }

        RDR_Module_Settings::set_many( $slug, $to_save );

        wp_send_json_success( [ 'slug' => $slug ] );
    }

    // ── Vues ──────────────────────────────────────────────────────────────────

    public function render_dashboard(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $loader     = rdr_suite();
        $discovered = $loader->get_discovered();
        $active_cnt = count( $loader->get_active() );
        $total_cnt  = count( $discovered );
        require_once RDR_SUITE_DIR . 'admin/views/dashboard.php';
    }

    public function render_module_settings( string $slug, array $module, string $settings_view ): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $settings = RDR_Module_Settings::all( $slug );
        $loader   = rdr_suite();
        require_once RDR_SUITE_DIR . 'admin/views/module-settings-wrap.php';
    }

    public function render_doc(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        require_once RDR_SUITE_DIR . 'admin/views/doc.php';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function logo_svg(): string {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>';
    }
}
