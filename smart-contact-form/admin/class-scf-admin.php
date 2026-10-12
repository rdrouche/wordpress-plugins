<?php
defined( 'ABSPATH' ) || exit;

class SCF_Admin {

    public static function init(): void {
        add_action( 'admin_menu',            [ __CLASS__, 'add_menus' ] );
        add_action( 'admin_notices',         [ __CLASS__, 'db_missing_notice' ] );
        add_action( 'add_meta_boxes',        [ __CLASS__, 'add_meta_boxes' ] );
        add_action( 'save_post_scf_form',    [ __CLASS__, 'save_meta' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
    }

    public static function enqueue( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'scf_form_page_scf-settings', 'scf_form_page_scf-submissions', 'scf_form_page_scf-tools' ], true ) ) return;

        wp_enqueue_style( 'scf-admin', SCF_PLUGIN_URL . 'admin/css/scf-admin.css', [], SCF_VERSION );
        wp_enqueue_script( 'scf-admin', SCF_PLUGIN_URL . 'admin/js/scf-admin.js', [], SCF_VERSION, true );
        wp_localize_script( 'scf-admin', 'SCF_Admin', [
            'nonce'   => wp_create_nonce( 'scf_admin' ),
            'confirm' => __( 'Are you sure?', 'smart-contact-form' ),
            'i18n'    => [
                'fieldName'    => __( 'Field Name (slug)', 'smart-contact-form' ),
                'fieldLabel'   => __( 'Label', 'smart-contact-form' ),
                'fieldType'    => __( 'Type', 'smart-contact-form' ),
                'required'     => __( 'Required', 'smart-contact-form' ),
                'remove'       => __( 'Remove', 'smart-contact-form' ),
            ],
        ] );
    }

    // ------------------------------------------------------------------
    // Menus
    // ------------------------------------------------------------------

    /** Count unread (status = new) submissions for menu badge. */
    private static function unread_count(): int {
        global $wpdb;
        if ( ! SCF_Install::table_exists( 'scf_submissions' ) ) return 0;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}scf_submissions WHERE status = 'new'"
        );
    }

    public static function add_menus(): void {
        $unread = self::unread_count();
        $badge  = $unread
            ? ' <span class="awaiting-mod count-' . $unread . '"><span class="pending-count" aria-hidden="true">'
              . number_format_i18n( $unread )
              . '</span><span class="screen-reader-text">'
              . sprintf( _n( '%s unread submission', '%s unread submissions', $unread, 'smart-contact-form' ), $unread )
              . '</span></span>'
            : '';

        add_menu_page(
            __( 'Contact Forms', 'smart-contact-form' ),
            __( 'Contact Forms', 'smart-contact-form' ) . $badge,
            'manage_options',
            'edit.php?post_type=scf_form',
            '',
            'dashicons-email-alt',
            56
        );

        add_submenu_page(
            'edit.php?post_type=scf_form',
            __( 'All Forms', 'smart-contact-form' ),
            __( 'All Forms', 'smart-contact-form' ),
            'manage_options',
            'edit.php?post_type=scf_form'
        );

        add_submenu_page(
            'edit.php?post_type=scf_form',
            __( 'Add New Form', 'smart-contact-form' ),
            __( 'Add New', 'smart-contact-form' ),
            'manage_options',
            'post-new.php?post_type=scf_form'
        );

        add_submenu_page(
            'edit.php?post_type=scf_form',
            __( 'Submissions', 'smart-contact-form' ),
            __( 'Submissions', 'smart-contact-form' ) . $badge,
            'manage_options',
            'scf-submissions',
            [ __CLASS__, 'page_submissions' ]
        );

        add_submenu_page(
            'edit.php?post_type=scf_form',
            __( 'Settings', 'smart-contact-form' ),
            __( 'Settings', 'smart-contact-form' ),
            'manage_options',
            'scf-settings',
            [ __CLASS__, 'page_settings' ]
        );
        add_submenu_page(
            'edit.php?post_type=scf_form',
            __( 'Tools & Diagnostics', 'smart-contact-form' ),
            __( '🔧 Tools', 'smart-contact-form' ),
            'manage_options',
            'scf-tools',
            [ __CLASS__, 'page_tools' ]
        );
    }

    // ------------------------------------------------------------------
    // Meta boxes
    // ------------------------------------------------------------------

    public static function add_meta_boxes(): void {
        add_meta_box( 'scf_shortcode',    __( 'Shortcode', 'smart-contact-form' ),        [ __CLASS__, 'mb_shortcode' ],    'scf_form', 'side', 'high' );
        add_meta_box( 'scf_fields',       __( 'Form Fields', 'smart-contact-form' ),       [ __CLASS__, 'mb_fields' ],       'scf_form', 'normal', 'high' );
        add_meta_box( 'scf_settings',     __( 'Form Settings', 'smart-contact-form' ),     [ __CLASS__, 'mb_form_settings' ],'scf_form', 'normal', 'default' );
        add_meta_box( 'scf_email_notify', __( 'Email Notifications', 'smart-contact-form' ),[ __CLASS__, 'mb_email' ],      'scf_form', 'normal', 'default' );
    }

    public static function mb_shortcode( WP_Post $post ): void {
        echo '<p class="description">' . esc_html__( 'Copy & paste this shortcode anywhere:', 'smart-contact-form' ) . '</p>';
        echo '<code class="scf-shortcode" onclick="this.select()">[smart_contact_form id="' . esc_attr( $post->ID ) . '"]</code>';
    }

    public static function mb_fields( WP_Post $post ): void {
        wp_nonce_field( 'scf_save_meta', 'scf_meta_nonce' );
        $fields = get_post_meta( $post->ID, '_scf_fields', true ) ?: SCF_Ajax::default_fields();
        include SCF_PLUGIN_DIR . 'admin/views/meta-fields.php';
    }

    public static function mb_form_settings( WP_Post $post ): void {
        $success = get_post_meta( $post->ID, '_scf_success_message', true ) ?: __( 'Thank you! Your message has been sent.', 'smart-contact-form' );
        include SCF_PLUGIN_DIR . 'admin/views/meta-settings.php';
    }

    public static function mb_email( WP_Post $post ): void {
        $recipient    = get_post_meta( $post->ID, '_scf_recipient_email', true ) ?: get_option( 'admin_email' );
        $subject      = get_post_meta( $post->ID, '_scf_email_subject', true );
        $send_copy    = get_post_meta( $post->ID, '_scf_send_copy', true );
        $copy_subject = get_post_meta( $post->ID, '_scf_copy_subject', true );
        $copy_body    = get_post_meta( $post->ID, '_scf_copy_body', true );
        include SCF_PLUGIN_DIR . 'admin/views/meta-email.php';
    }

    // ------------------------------------------------------------------
    // Save meta
    // ------------------------------------------------------------------

    public static function save_meta( int $post_id ): void {
        if ( ! isset( $_POST['scf_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scf_meta_nonce'] ) ), 'scf_save_meta' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Fields
        if ( isset( $_POST['scf_field_names'] ) ) {
            $names   = array_map( 'sanitize_key', (array) $_POST['scf_field_names'] );
            $labels  = array_map( 'sanitize_text_field', (array) ( $_POST['scf_field_labels']  ?? [] ) );
            $types   = array_map( 'sanitize_key',        (array) ( $_POST['scf_field_types']   ?? [] ) );
            $placeholders = array_map( 'sanitize_text_field', (array) ( $_POST['scf_field_placeholders'] ?? [] ) );
            $required = (array) ( $_POST['scf_field_required'] ?? [] );

            $fields = [];
            foreach ( $names as $i => $name ) {
                if ( empty( $name ) ) continue;
                $fields[] = [
                    'name'        => $name,
                    'label'       => $labels[ $i ] ?? $name,
                    'type'        => $types[ $i ] ?? 'text',
                    'placeholder' => $placeholders[ $i ] ?? '',
                    'required'    => in_array( $i, $required, false ),
                ];
            }
            update_post_meta( $post_id, '_scf_fields', $fields );
        }

        // Fields are submitted with their full name including leading underscore.
        // We read them directly from $_POST using the exact key.
        $meta_map = [
            '_scf_success_message' => 'sanitize_text_field',
            '_scf_recipient_email' => 'sanitize_email',
            '_scf_email_subject'   => 'sanitize_text_field',
            '_scf_copy_subject'    => 'sanitize_text_field',
            '_scf_copy_body'       => 'sanitize_textarea_field',
        ];
        foreach ( $meta_map as $key => $cb ) {
            // $_POST key matches the HTML name attribute exactly (includes leading _)
            if ( isset( $_POST[ $key ] ) ) {
                update_post_meta( $post_id, $key, $cb( wp_unslash( $_POST[ $key ] ) ) );
            } else {
                // Field present in form but sent empty (e.g. cleared text field)
                update_post_meta( $post_id, $key, '' );
            }
        }

        // Checkbox: only present in $_POST when checked, so we always update explicitly
        $send_copy = isset( $_POST['_scf_send_copy'] ) ? '1' : '0';
        update_post_meta( $post_id, '_scf_send_copy', $send_copy );
    }

    // ------------------------------------------------------------------
    // Submissions page (list + detail router)
    // ------------------------------------------------------------------

    public static function page_submissions(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'scf_submissions';

        // ---- Single action: DELETE ----
        if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
            check_admin_referer( 'scf_submission_' . (int) $_GET['id'] );
            $wpdb->delete( $table, [ 'id' => (int) $_GET['id'] ], [ '%d' ] );
            wp_safe_redirect( admin_url( 'admin.php?page=scf-submissions&scf_notice=deleted' ) );
            exit;
        }

        // ---- Single action: STATUS CHANGE (mark read/new/spam/ham) ----
        if ( isset( $_GET['action'], $_GET['id'] ) && in_array( $_GET['action'], [ 'mark_read', 'mark_new', 'mark_spam', 'mark_ham' ], true ) ) {
            check_admin_referer( 'scf_submission_' . (int) $_GET['id'] );
            $map = [
                'mark_read' => 'read',
                'mark_new'  => 'new',
                'mark_spam' => 'spam',
                'mark_ham'  => 'new',
            ];
            $wpdb->update( $table, [ 'status' => $map[ $_GET['action'] ] ], [ 'id' => (int) $_GET['id'] ], [ '%s' ], [ '%d' ] );
            $back = isset( $_GET['ref'] ) && 'detail' === $_GET['ref']
                ? admin_url( 'admin.php?page=scf-submissions&view=' . (int) $_GET['id'] . '&scf_notice=updated' )
                : admin_url( 'admin.php?page=scf-submissions&scf_notice=updated' );
            wp_safe_redirect( $back );
            exit;
        }

        // ---- Single action: SEND REPLY ----
        if ( isset( $_POST['scf_reply_nonce'] ) ) {
            $sub_id = (int) ( $_POST['sub_id'] ?? 0 );
            if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scf_reply_nonce'] ) ), 'scf_reply_' . $sub_id ) ) {
                wp_die( esc_html__( 'Security check failed.', 'smart-contact-form' ) );
            }
            self::handle_reply( $sub_id );
            wp_safe_redirect( admin_url( 'admin.php?page=scf-submissions&view=' . $sub_id . '&scf_notice=replied' ) );
            exit;
        }

        // ---- Notices ----
        $notice = sanitize_key( $_GET['scf_notice'] ?? '' );

        // ---- Detail view ----
        if ( isset( $_GET['view'] ) ) {
            $sub_id = (int) $_GET['view'];
            $sub    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $sub_id ) );
            if ( ! $sub ) {
                echo '<div class="wrap"><p>' . esc_html__( 'Submission not found.', 'smart-contact-form' ) . '</p></div>';
                return;
            }
            // Auto-mark as read
            if ( 'new' === $sub->status ) {
                $wpdb->update( $table, [ 'status' => 'read' ], [ 'id' => $sub_id ], [ '%s' ], [ '%d' ] );
                $sub->status = 'read';
            }
            $fields_data  = json_decode( $sub->fields, true ) ?: [];
            $form_fields  = get_post_meta( $sub->form_id, '_scf_fields', true ) ?: SCF_Ajax::default_fields();
            $reply_log    = get_post_meta( $sub->form_id, '_scf_replies_' . $sub_id, true ) ?: [];
            include SCF_PLUGIN_DIR . 'admin/views/page-submission-detail.php';
            return;
        }

        // ---- List view ----
        $filter_status = sanitize_key( $_GET['status'] ?? '' );
        $per_page      = 20;
        $page          = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
        $offset        = ( $page - 1 ) * $per_page;

        $where = $filter_status ? $wpdb->prepare( 'WHERE status = %s', $filter_status ) : '';
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table $where" );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ) );

        // Status counts for filter tabs
        $counts = [];
        foreach ( $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM $table GROUP BY status" ) as $r ) {
            $counts[ $r->status ] = (int) $r->n;
        }
        $counts['all'] = array_sum( $counts );

        include SCF_PLUGIN_DIR . 'admin/views/page-submissions.php';
    }

    // ------------------------------------------------------------------
    // Reply handler
    // ------------------------------------------------------------------

    private static function handle_reply( int $sub_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'scf_submissions';

        $sub = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $sub_id ) );
        if ( ! $sub ) return;

        $fields_data = json_decode( $sub->fields, true ) ?: [];
        $to_email    = sanitize_email( $fields_data['scf_email'] ?? '' );
        $to_name     = sanitize_text_field( $fields_data['scf_name'] ?? '' );

        if ( ! is_email( $to_email ) ) return;

        $subject = sanitize_text_field( wp_unslash( $_POST['reply_subject'] ?? '' ) );
        $body    = sanitize_textarea_field( wp_unslash( $_POST['reply_body'] ?? '' ) );

        if ( empty( $subject ) || empty( $body ) ) return;

        $from_name  = get_option( 'scf_from_name', get_option( 'blogname' ) );
        $from_email = get_option( 'scf_from_email', get_option( 'admin_email' ) );

        $html_body = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;">'
            . '<div style="background:#1a1a2e;padding:16px 20px;"><h2 style="color:#fff;margin:0;font-size:18px;">'
            . esc_html( get_option( 'blogname' ) ) . '</h2></div>'
            . '<div style="padding:24px;background:#fff;">'
            . '<p>' . esc_html( sprintf( __( 'Hello %s,', 'smart-contact-form' ), $to_name ) ) . '</p>'
            . nl2br( esc_html( $body ) )
            . '</div></div>';

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
        ];

        $sent = wp_mail( $to_email, $subject, $html_body, $headers );

        if ( $sent ) {
            // Log the reply as post meta on the form
            $log   = get_post_meta( $sub->form_id, '_scf_replies_' . $sub_id, true ) ?: [];
            $log[] = [
                'date'    => current_time( 'mysql' ),
                'subject' => $subject,
                'body'    => $body,
                'by'      => wp_get_current_user()->display_name,
            ];
            update_post_meta( $sub->form_id, '_scf_replies_' . $sub_id, $log );

            // Mark submission as "replied"
            $wpdb->update( $table, [ 'status' => 'replied' ], [ 'id' => $sub_id ], [ '%s' ], [ '%d' ] );
        }
    }

    // ------------------------------------------------------------------
    // Settings page
    // ------------------------------------------------------------------

    public static function page_settings(): void {
        if ( isset( $_POST['scf_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scf_settings_nonce'] ) ), 'scf_save_settings' ) ) {
            $options = [
                'scf_recaptcha_site_key'   => 'sanitize_text_field',
                'scf_recaptcha_secret_key' => 'sanitize_text_field',
                'scf_recaptcha_threshold'  => 'floatval',
                'scf_rate_limit_max'       => 'absint',
                'scf_rate_limit_window'    => 'absint',
                'scf_min_fill_time'        => 'absint',
                'scf_admin_email'          => 'sanitize_email',
                'scf_from_name'            => 'sanitize_text_field',
                'scf_from_email'           => 'sanitize_email',
            ];
            foreach ( $options as $key => $cb ) {
                if ( isset( $_POST[ $key ] ) ) {
                    update_option( $key, $cb( wp_unslash( $_POST[ $key ] ) ) );
                }
            }
            echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'smart-contact-form' ) . '</p></div>';
        }

        include SCF_PLUGIN_DIR . 'admin/views/page-settings.php';
    }

    // ------------------------------------------------------------------
    // Admin notice when DB tables are missing
    // ------------------------------------------------------------------

    public static function db_missing_notice(): void {
        // Only show on our own pages or the plugins list
        $screen = get_current_screen();
        if ( ! $screen ) return;
        $our_pages = [ 'edit-scf_form', 'scf_form', 'scf_form_page_scf-submissions', 'scf_form_page_scf-settings', 'scf_form_page_scf-tools', 'plugins' ];
        if ( ! in_array( $screen->id, $our_pages, true ) ) return;

        if ( SCF_Install::all_tables_exist() ) return;

        $tools_url = admin_url( 'admin.php?page=scf-tools' );
        printf(
            '<div class="notice notice-error"><p><strong>Smart Contact Form:</strong> %s <a href="%s" class="button button-small" style="margin-left:8px;">%s</a></p></div>',
            esc_html__( 'Required database tables are missing — form submissions will fail.', 'smart-contact-form' ),
            esc_url( $tools_url ),
            esc_html__( '🛠 Fix now', 'smart-contact-form' )
        );
    }


    // ------------------------------------------------------------------
    // Tools & Diagnostics page
    // ------------------------------------------------------------------

    public static function page_tools(): void {
        global $wpdb;

        $result   = null;
        $message  = '';
        $db_error = '';

        // Handle force-create action
        if ( isset( $_POST['scf_tools_nonce'] ) &&
             wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['scf_tools_nonce'] ) ), 'scf_force_create_tables' ) ) {

            // Capture any DB errors during creation
            $wpdb->show_errors();
            ob_start();
            $result = SCF_Install::force_create();
            ob_end_clean();
            $wpdb->hide_errors();

            $db_error = $wpdb->last_error;
            $all_ok   = ! in_array( false, $result, true );
            $message  = $all_ok
                ? __( 'All tables created successfully!', 'smart-contact-form' )
                : __( 'Some tables could not be created. See the error below.', 'smart-contact-form' );
        }

        // Current table status
        $tables = [];
        foreach ( SCF_Install::TABLES as $t ) {
            $tables[ $t ] = SCF_Install::table_exists( $t );
        }
        $all_ok = ! in_array( false, $tables, true );

        include SCF_PLUGIN_DIR . 'admin/views/page-tools.php';
    }

}