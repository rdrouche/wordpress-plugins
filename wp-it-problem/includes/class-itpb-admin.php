<?php
/**
 * ITPB_Admin
 *
 * Admin-only enhancements:
 *  - Custom columns on the it_problem list screen (reveal date, status, answer link)
 *  - "Create and link answer" GET action (no AJAX)
 *  - Admin notice feedback (success / error)
 *  - Settings page (default reveal days)
 *
 * Intentionally no JS beyond what WP core already loads.
 *
 * @package WP_IT_Problem
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class ITPB_Admin {

    public function init(): void {
        // Columns — it_problem list
        add_filter( 'manage_' . ITPB_CPT_PROBLEM . '_posts_columns',       [ $this, 'add_problem_columns' ] );
        add_action( 'manage_' . ITPB_CPT_PROBLEM . '_posts_custom_column', [ $this, 'render_problem_column' ], 10, 2 );
        add_filter( 'manage_edit-' . ITPB_CPT_PROBLEM . '_sortable_columns', [ $this, 'sortable_problem_columns' ] );

        // Columns — it_answer list
        add_filter( 'manage_' . ITPB_CPT_ANSWER . '_posts_columns',       [ $this, 'add_answer_columns' ] );
        add_action( 'manage_' . ITPB_CPT_ANSWER . '_posts_custom_column', [ $this, 'render_answer_column' ], 10, 2 );

        // Handle the "create answer" GET action (fires early, before headers)
        add_action( 'admin_action_itpb_create_answer', [ $this, 'handle_create_answer' ] );
        // Also catch the query arg variant (from the meta box link)
        add_action( 'admin_init', [ $this, 'maybe_handle_create_answer' ] );

        // Admin notices
        add_action( 'admin_notices', [ $this, 'show_admin_notices' ] );

        // Settings page
        add_action( 'admin_menu',            [ $this, 'register_settings_page' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );

        // Sort by reveal_at
        add_action( 'pre_get_posts', [ $this, 'handle_reveal_at_orderby' ] );

        // Row actions on it_problem list
        add_filter( 'post_row_actions', [ $this, 'add_row_actions' ], 10, 2 );

        // Admin assets
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    // -----------------------------------------------------------------------
    // Columns — it_problem
    // -----------------------------------------------------------------------

    public function add_problem_columns( array $columns ): array {
        // Insert after title
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['itpb_infra']      = __( 'Infra', 'wp-it-problem' );
                $new['itpb_reveal_at']  = __( 'Reveal date', 'wp-it-problem' );
                $new['itpb_status']     = __( 'Status', 'wp-it-problem' );
                $new['itpb_answer']     = __( 'Answer', 'wp-it-problem' );
            }
        }
        return $new;
    }

    public function render_problem_column( string $column, int $post_id ): void {
        switch ( $column ) {

            case 'itpb_infra':
                $terms = get_the_terms( $post_id, 'itpb_infra' );
                if ( $terms && ! is_wp_error( $terms ) ) {
                    $names = array_map( fn( $t ) => esc_html( $t->name ), $terms );
                    echo implode( ', ', $names );
                } else {
                    echo '<span style="color:#646970;">—</span>';
                }
                break;

            case 'itpb_reveal_at':
                $reveal_at = itpb_get_reveal_at( $post_id );
                if ( $reveal_at ) {
                    echo esc_html( $reveal_at->format( 'Y-m-d H:i' ) . ' UTC' );
                } else {
                    echo '<span style="color:#646970;">—</span>';
                }
                break;

            case 'itpb_status':
                if ( itpb_is_revealed( $post_id ) ) {
                    echo '<span style="color:#00a32a; font-weight:600;">&#10003; ' . esc_html__( 'Revealed', 'wp-it-problem' ) . '</span>';
                } else {
                    $reveal_at = itpb_get_reveal_at( $post_id );
                    if ( $reveal_at ) {
                        $diff  = $reveal_at->getTimestamp() - time();
                        $days  = max( 0, (int) ceil( $diff / DAY_IN_SECONDS ) );
                        /* translators: %d: number of days */
                        printf(
                            '<span style="color:#dba617;">&#8987; ' . esc_html__( 'In %d day(s)', 'wp-it-problem' ) . '</span>',
                            $days
                        );
                    } else {
                        echo '<span style="color:#646970;">—</span>';
                    }
                }
                break;

            case 'itpb_answer':
                $answer = itpb_get_answer_post( $post_id );
                if ( $answer ) {
                    $edit_url = get_edit_post_link( $answer->ID );
                    printf(
                        '<a href="%s">%s</a>',
                        esc_url( $edit_url ),
                        esc_html__( 'Edit answer', 'wp-it-problem' )
                    );
                } else {
                    $create_url = wp_nonce_url(
                        add_query_arg(
                            [ 'itpb_create_answer' => $post_id ],
                            admin_url( 'edit.php?post_type=' . ITPB_CPT_PROBLEM )
                        ),
                        'itpb_create_answer_' . $post_id
                    );
                    printf(
                        '<a href="%s" style="color:#d63638;">%s</a>',
                        esc_url( $create_url ),
                        esc_html__( '+ Create', 'wp-it-problem' )
                    );
                }
                break;
        }
    }

    public function sortable_problem_columns( array $columns ): array {
        $columns['itpb_reveal_at'] = 'itpb_reveal_at';
        return $columns;
    }

    // -----------------------------------------------------------------------
    // Columns — it_answer
    // -----------------------------------------------------------------------

    public function add_answer_columns( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                $new['itpb_parent_problem'] = __( 'Parent problem', 'wp-it-problem' );
                $new['itpb_answer_status']  = __( 'Status', 'wp-it-problem' );
            }
        }
        return $new;
    }

    public function render_answer_column( string $column, int $post_id ): void {
        switch ( $column ) {

            case 'itpb_parent_problem':
                $problem = itpb_get_problem_post( $post_id );
                if ( $problem ) {
                    printf(
                        '<a href="%s">%s</a>',
                        esc_url( get_edit_post_link( $problem->ID ) ),
                        esc_html( get_the_title( $problem->ID ) )
                    );
                } else {
                    echo '<span style="color:#d63638;">' . esc_html__( 'Orphaned', 'wp-it-problem' ) . '</span>';
                }
                break;

            case 'itpb_answer_status':
                $status = get_post_status( $post_id );
                if ( $status === 'publish' ) {
                    echo '<span style="color:#00a32a;">&#10003; ' . esc_html__( 'Public', 'wp-it-problem' ) . '</span>';
                } else {
                    echo '<span style="color:#646970;">&#128274; ' . esc_html__( 'Private', 'wp-it-problem' ) . '</span>';
                }
                break;
        }
    }

    // -----------------------------------------------------------------------
    // Create answer — GET handler
    // -----------------------------------------------------------------------

    /**
     * Catches ?itpb_create_answer={problem_id}&_wpnonce=... on admin_init.
     */
    public function maybe_handle_create_answer(): void {
        if ( ! isset( $_GET['itpb_create_answer'] ) ) {
            return;
        }

        $problem_id = absint( $_GET['itpb_create_answer'] );

        if ( ! $problem_id ) {
            return;
        }

        if (
            ! isset( $_GET['_wpnonce'] )
            || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'itpb_create_answer_' . $problem_id )
        ) {
            wp_die( esc_html__( 'Security check failed.', 'wp-it-problem' ) );
        }

        if ( ! current_user_can( 'edit_post', $problem_id ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'wp-it-problem' ) );
        }

        $this->create_answer_post( $problem_id );
    }

    /** Alias for admin_action_ hook */
    public function handle_create_answer(): void {
        $this->maybe_handle_create_answer();
    }

    /**
     * Create the it_answer post, link it to the problem, redirect back.
     *
     * @param int $problem_id
     */
    private function create_answer_post( int $problem_id ): void {
        $problem = get_post( $problem_id );

        if ( ! $problem || $problem->post_type !== ITPB_CPT_PROBLEM ) {
            $this->redirect_with_notice( $problem_id, 'error', __( 'Invalid problem ID.', 'wp-it-problem' ) );
            return;
        }

        // Bail if already linked
        $existing = itpb_get_answer_post( $problem_id );
        if ( $existing ) {
            $this->redirect_with_notice( $problem_id, 'warning', __( 'An answer is already linked to this problem.', 'wp-it-problem' ) );
            return;
        }

        // Create the answer post (private until reveal)
        $answer_id = wp_insert_post( [
            'post_type'   => ITPB_CPT_ANSWER,
            /* translators: %s: problem title */
            'post_title'  => sprintf( __( 'Answer: %s', 'wp-it-problem' ), get_the_title( $problem_id ) ),
            'post_status' => 'private',
            'post_author' => get_current_user_id(),
        ], true );

        if ( is_wp_error( $answer_id ) ) {
            $this->redirect_with_notice( $problem_id, 'error', $answer_id->get_error_message() );
            return;
        }

        // Cross-link
        update_post_meta( $problem_id, ITPB_META_ANSWER_ID,  $answer_id );
        update_post_meta( $answer_id,  ITPB_META_PROBLEM_ID, $problem_id );

        /**
         * Fires after a new it_answer post is created and linked.
         *
         * @param int $answer_id   Newly created answer post ID.
         * @param int $problem_id  Parent problem post ID.
         * @since 1.0.0
         */
        do_action( 'itpb_answer_created', $answer_id, $problem_id );

        // Redirect to the answer edit screen
        wp_safe_redirect(
            add_query_arg(
                [ 'itpb_notice' => 'answer_created' ],
                get_edit_post_link( $answer_id, 'url' )
            )
        );
        exit;
    }

    // -----------------------------------------------------------------------
    // Admin notices
    // -----------------------------------------------------------------------

    public function show_admin_notices(): void {
        if ( ! isset( $_GET['itpb_notice'] ) ) {
            return;
        }

        $notice = sanitize_key( $_GET['itpb_notice'] );

        $messages = [
            'answer_created' => [ 'success', __( 'Answer post created and linked. Write your answer below and save as Private — it will be published automatically on the reveal date.', 'wp-it-problem' ) ],
            'db_updated'     => [ 'success', __( 'Database updated successfully.', 'wp-it-problem' ) ],
            'already_linked' => [ 'warning', __( 'An answer is already linked to this problem.', 'wp-it-problem' ) ],
            'error'          => [ 'error',   __( 'An error occurred. Please try again.', 'wp-it-problem' ) ],
        ];

        if ( ! isset( $messages[ $notice ] ) ) {
            return;
        }

        [ $type, $text ] = $messages[ $notice ];
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr( $type ),
            esc_html( $text )
        );
    }

    // -----------------------------------------------------------------------
    // Settings page
    // -----------------------------------------------------------------------

    public function register_settings_page(): void {
        add_submenu_page(
            'edit.php?post_type=' . ITPB_CPT_PROBLEM,
            __( 'IT Problem Settings', 'wp-it-problem' ),
            __( 'Settings', 'wp-it-problem' ),
            'manage_options',
            'itpb-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'itpb_settings_group', 'itpb_default_reveal_days', [
            'type'              => 'integer',
            'sanitize_callback' => fn( $v ) => max( 1, absint( $v ) ),
            'default'           => 7,
        ] );

        register_setting( 'itpb_settings_group', 'itpb_show_countdown', [
            'type'              => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default'           => true,
        ] );

        register_setting( 'itpb_settings_group', 'itpb_archive_page_id', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 0,
        ] );

        add_settings_section(
            'itpb_general',
            __( 'General', 'wp-it-problem' ),
            '__return_false',
            'itpb-settings'
        );

        add_settings_field(
            'itpb_default_reveal_days',
            __( 'Default reveal delay (days)', 'wp-it-problem' ),
            [ $this, 'field_default_reveal_days' ],
            'itpb-settings',
            'itpb_general'
        );

        add_settings_field(
            'itpb_show_countdown',
            __( 'Show countdown to visitors', 'wp-it-problem' ),
            [ $this, 'field_show_countdown' ],
            'itpb-settings',
            'itpb_general'
        );

        add_settings_field(
            'itpb_archive_page_id',
            __( 'Problems archive page', 'wp-it-problem' ),
            [ $this, 'field_archive_page_id' ],
            'itpb-settings',
            'itpb_general'
        );
    }

    public function field_default_reveal_days(): void {
        $val = (int) get_option( 'itpb_default_reveal_days', 7 );
        printf(
            '<input type="number" name="itpb_default_reveal_days" value="%d" min="1" max="365" style="width:80px;"> %s',
            $val,
            esc_html__( 'days after problem creation', 'wp-it-problem' )
        );
    }

    public function field_show_countdown(): void {
        $val = (bool) get_option( 'itpb_show_countdown', true );
        printf(
            '<input type="checkbox" name="itpb_show_countdown" value="1" %s> %s',
            checked( $val, true, false ),
            esc_html__( 'Display a countdown block when the answer is not yet revealed', 'wp-it-problem' )
        );
    }

    public function field_archive_page_id(): void {
        $val = (int) get_option( 'itpb_archive_page_id', 0 );
        wp_dropdown_pages( [
            'name'              => 'itpb_archive_page_id',
            'selected'          => $val,
            'show_option_none'  => __( '— None —', 'wp-it-problem' ),
            'option_none_value' => '0',
        ] );
        echo '<p class="description">' . esc_html__( 'Page containing [itpb_list]. Used for the breadcrumb middle link and the Rank Math breadcrumb.', 'wp-it-problem' ) . '</p>';
    }

    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'IT Problem — Settings', 'wp-it-problem' ); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'itpb_settings_group' );
                do_settings_sections( 'itpb-settings' );
                submit_button();
                ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Database', 'wp-it-problem' ); ?></h2>
            <p>
                <?php
                printf(
                    /* translators: %s: current db version */
                    esc_html__( 'Current DB version: %s', 'wp-it-problem' ),
                    '<strong>' . esc_html( get_option( 'itpb_db_version', '—' ) ) . '</strong>'
                );
                ?>
            </p>
            <a
                href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'itpb_action', 'update_db', admin_url( 'edit.php?post_type=' . ITPB_CPT_PROBLEM . '&page=itpb-settings' ) ), 'itpb_update_db' ) ); ?>"
                class="button button-secondary"
            >
                <?php esc_html_e( 'Update database tables', 'wp-it-problem' ); ?>
            </a>
        </div>
        <?php

        // Handle DB update action (same request, after form rendered — notice shows on reload)
        if (
            isset( $_GET['itpb_action'] ) && $_GET['itpb_action'] === 'update_db'
            && isset( $_GET['_wpnonce'] )
            && wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'itpb_update_db' )
            && current_user_can( 'manage_options' )
        ) {
            ITPB_Install::run_upgrade();
            wp_safe_redirect( add_query_arg( 'itpb_notice', 'db_updated', remove_query_arg( [ 'itpb_action', '_wpnonce' ] ) ) );
            exit;
        }
    }

    // -----------------------------------------------------------------------
    // Orderby — reveal_at column
    // -----------------------------------------------------------------------

    public function handle_reveal_at_orderby( \WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }
        if ( $query->get( 'orderby' ) !== 'itpb_reveal_at' ) {
            return;
        }

        $query->set( 'meta_key', ITPB_META_REVEAL_AT );
        $query->set( 'orderby',  'meta_value' );
    }

    // -----------------------------------------------------------------------
    // Row actions
    // -----------------------------------------------------------------------

    /**
     * Add "Create answer" row action on it_problem list when no answer exists.
     *
     * @param  array    $actions
     * @param  \WP_Post $post
     * @return array
     */
    public function add_row_actions( array $actions, \WP_Post $post ): array {
        if ( $post->post_type !== ITPB_CPT_PROBLEM ) {
            return $actions;
        }

        $answer = itpb_get_answer_post( $post->ID );

        if ( ! $answer ) {
            $url = wp_nonce_url(
                add_query_arg(
                    [ 'itpb_create_answer' => $post->ID ],
                    admin_url( 'edit.php?post_type=' . ITPB_CPT_PROBLEM )
                ),
                'itpb_create_answer_' . $post->ID
            );
            $actions['itpb_create_answer'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url( $url ),
                esc_html__( 'Create answer', 'wp-it-problem' )
            );
        } else {
            $actions['itpb_edit_answer'] = sprintf(
                '<a href="%s">%s</a>',
                esc_url( get_edit_post_link( $answer->ID ) ),
                esc_html__( 'Edit answer', 'wp-it-problem' )
            );
        }

        return $actions;
    }

    // -----------------------------------------------------------------------
    // Admin assets
    // -----------------------------------------------------------------------

    public function enqueue_admin_assets( string $hook ): void {
        $screen = get_current_screen();
        if ( ! $screen ) return;

        // Only on it_problem and it_answer edit screens
        $cpts = [ ITPB_CPT_PROBLEM, ITPB_CPT_ANSWER ];
        if ( ! in_array( $screen->post_type, $cpts, true ) ) {
            return;
        }
        if ( ! in_array( $screen->base, [ 'post', 'edit' ], true ) ) {
            return;
        }

        wp_enqueue_style(
            'itpb-admin',
            ITPB_URL . 'assets/css/admin.css',
            [],
            ITPB_VERSION
        );

        wp_enqueue_script(
            'itpb-admin',
            ITPB_URL . 'assets/js/admin.js',
            [],           // no jQuery
            ITPB_VERSION,
            true
        );
    }

    // -----------------------------------------------------------------------
    // Redirect helper
    // -----------------------------------------------------------------------

    private function redirect_with_notice( int $problem_id, string $type, string $message ): void {
        // Store message in transient (60s) keyed by user
        set_transient( 'itpb_notice_' . get_current_user_id(), [ $type, $message ], 60 );

        wp_safe_redirect(
            add_query_arg(
                [ 'itpb_notice' => $type ],
                get_edit_post_link( $problem_id, 'url' )
            )
        );
        exit;
    }
}
