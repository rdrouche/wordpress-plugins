<?php
/**
 * ITPB_Meta
 *
 * Native WordPress meta boxes for the it_problem edit screen.
 *
 * Boxes:
 *  1. Reveal date      — datetime-local input → _itpb_reveal_at
 *  2. Infra context    — textarea (free JSON-like text) → _itpb_infra
 *  3. Linked answer    — read-only info + "Create answer" button (plain GET link)
 *
 * No AJAX. Everything saves on the standard post save_post hook.
 *
 * @package WP_IT_Problem
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class ITPB_Meta {

    public function init(): void {
        add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
        add_action( 'save_post_' . ITPB_CPT_PROBLEM, [ $this, 'save_problem_meta' ], 10, 2 );
        add_action( 'save_post_' . ITPB_CPT_ANSWER,  [ $this, 'save_answer_meta' ],  10, 2 );

        // Register meta keys for REST + Gutenberg visibility
        $this->register_meta_keys();
    }

    // -----------------------------------------------------------------------
    // Meta key registration (REST-exposed, sanitized)
    // -----------------------------------------------------------------------

    private function register_meta_keys(): void {
        // it_problem meta
        register_post_meta( ITPB_CPT_PROBLEM, ITPB_META_REVEAL_AT, [
            'type'              => 'string',
            'description'       => 'UTC datetime when the answer is revealed (Y-m-d H:i:s)',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => [ $this, 'sanitize_datetime' ],
            'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
            'show_in_rest'      => true,
        ] );

        register_post_meta( ITPB_CPT_PROBLEM, ITPB_META_ANSWER_ID, [
            'type'              => 'integer',
            'description'       => 'Post ID of the linked it_answer',
            'single'            => true,
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
            'show_in_rest'      => true,
        ] );

        register_post_meta( ITPB_CPT_PROBLEM, ITPB_META_INFRA, [
            'type'              => 'string',
            'description'       => 'Free-text infrastructure context',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => 'sanitize_textarea_field',
            'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
            'show_in_rest'      => true,
        ] );

        register_post_meta( ITPB_CPT_PROBLEM, ITPB_META_DIFFICULTY, [
            'type'              => 'string',
            'description'       => 'Difficulty slug',
            'single'            => true,
            'default'           => '',
            'sanitize_callback' => 'sanitize_key',
            'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
            'show_in_rest'      => true,
        ] );

        // it_answer meta
        register_post_meta( ITPB_CPT_ANSWER, ITPB_META_PROBLEM_ID, [
            'type'              => 'integer',
            'description'       => 'Post ID of the parent it_problem',
            'single'            => true,
            'default'           => 0,
            'sanitize_callback' => 'absint',
            'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
            'show_in_rest'      => true,
        ] );
    }

    // -----------------------------------------------------------------------
    // Meta box registration
    // -----------------------------------------------------------------------

    public function register_meta_boxes(): void {
        // Box 1 — Reveal date (high priority, always visible)
        add_meta_box(
            'itpb_reveal',
            __( 'Answer reveal date', 'wp-it-problem' ),
            [ $this, 'render_reveal_box' ],
            ITPB_CPT_PROBLEM,
            'side',
            'high'
        );

        // Box 2 — Infra context
        add_meta_box(
            'itpb_infra',
            __( 'Infrastructure context', 'wp-it-problem' ),
            [ $this, 'render_infra_box' ],
            ITPB_CPT_PROBLEM,
            'normal',
            'high'
        );

        // Box 3 — Linked answer (read-only status + action link)
        add_meta_box(
            'itpb_answer_link',
            __( 'Linked answer', 'wp-it-problem' ),
            [ $this, 'render_answer_link_box' ],
            ITPB_CPT_PROBLEM,
            'side',
            'default'
        );

        // Box on the it_answer screen — back-link to its problem
        add_meta_box(
            'itpb_back_to_problem',
            __( 'Parent problem', 'wp-it-problem' ),
            [ $this, 'render_back_to_problem_box' ],
            ITPB_CPT_ANSWER,
            'side',
            'high'
        );
    }

    // -----------------------------------------------------------------------
    // Render — Box 1 : Reveal date
    // -----------------------------------------------------------------------

    public function render_reveal_box( \WP_Post $post ): void {
        wp_nonce_field( 'itpb_save_reveal', 'itpb_reveal_nonce' );

        $raw        = get_post_meta( $post->ID, ITPB_META_REVEAL_AT, true );
        $revealed   = itpb_is_revealed( $post->ID );

        // Convert stored UTC (Y-m-d H:i:s) to datetime-local format (Y-m-d\TH:i)
        $input_val  = '';
        if ( $raw ) {
            try {
                $dt        = new \DateTimeImmutable( $raw, new \DateTimeZone( 'UTC' ) );
                $input_val = $dt->format( 'Y-m-d\TH:i' );
            } catch ( \Exception ) {}
        }

        // Default: now + default_reveal_days
        if ( ! $input_val ) {
            $days      = (int) get_option( 'itpb_default_reveal_days', 7 );
            $input_val = ( new \DateTimeImmutable( "+{$days} days", new \DateTimeZone( 'UTC' ) ) )
                           ->format( 'Y-m-d\TH:i' );
        }

        $status_label = $revealed
            ? '<span style="color:#00a32a;">&#10003; ' . esc_html__( 'Answer revealed', 'wp-it-problem' ) . '</span>'
            : '<span style="color:#dba617;">&#8987; ' . esc_html__( 'Pending reveal', 'wp-it-problem' ) . '</span>';
        ?>
        <p><?php echo wp_kses_post( $status_label ); ?></p>
        <p>
            <label for="itpb_reveal_at">
                <strong><?php esc_html_e( 'Reveal date (UTC)', 'wp-it-problem' ); ?></strong>
            </label><br>
            <input
                type="datetime-local"
                id="itpb_reveal_at"
                name="itpb_reveal_at"
                value="<?php echo esc_attr( $input_val ); ?>"
                style="width:100%; margin-top:4px;"
            >
        </p>
        <p style="color:#646970; font-size:12px; margin-top:4px;">
            <?php esc_html_e( 'All times are UTC. The cron job runs daily and will publish the answer automatically.', 'wp-it-problem' ); ?>
        </p>
        <?php
    }

    // -----------------------------------------------------------------------
    // Render — Box 2 : Infra context
    // -----------------------------------------------------------------------

    public function render_infra_box( \WP_Post $post ): void {
        wp_nonce_field( 'itpb_save_infra', 'itpb_infra_nonce' );

        $infra = get_post_meta( $post->ID, ITPB_META_INFRA, true );
        ?>
        <p style="color:#646970; font-size:12px; margin-top:0;">
            <?php esc_html_e( 'Describe the infrastructure context: OS, versions, topology, constraints…', 'wp-it-problem' ); ?>
        </p>
        <textarea
            id="itpb_infra"
            name="itpb_infra"
            rows="6"
            style="width:100%; font-family:monospace; font-size:13px;"
            placeholder="<?php esc_attr_e( 'e.g. Ubuntu 22.04 LTS, Docker 24.0, behind nginx reverse proxy…', 'wp-it-problem' ); ?>"
        ><?php echo esc_textarea( $infra ); ?></textarea>
        <?php
    }

    // -----------------------------------------------------------------------
    // Render — Box 3 : Linked answer
    // -----------------------------------------------------------------------

    public function render_answer_link_box( \WP_Post $post ): void {
        $answer = itpb_get_answer_post( $post->ID );

        if ( $answer ) {
            $edit_url    = get_edit_post_link( $answer->ID );
            $preview_url = get_preview_post_link( $answer->ID );
            $status      = get_post_status( $answer->ID );
            $status_label = $status === 'publish'
                ? '<span style="color:#00a32a;">&#10003; ' . esc_html__( 'Published', 'wp-it-problem' ) . '</span>'
                : '<span style="color:#dba617;">&#128274; ' . esc_html__( 'Private (not yet revealed)', 'wp-it-problem' ) . '</span>';
            ?>
            <p><?php echo wp_kses_post( $status_label ); ?></p>
            <p>
                <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-secondary" style="margin-right:6px;">
                    <?php esc_html_e( 'Edit answer', 'wp-it-problem' ); ?>
                </a>
                <a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" class="button button-secondary">
                    <?php esc_html_e( 'Preview', 'wp-it-problem' ); ?>
                </a>
            </p>
            <p style="color:#646970; font-size:12px;">
                <?php
                printf(
                    /* translators: %d: answer post ID */
                    esc_html__( 'Answer post ID: %d', 'wp-it-problem' ),
                    $answer->ID
                );
                ?>
            </p>
            <?php
        } else {
            // No answer yet — offer a plain link to create one
            // ITPB_Admin::handle_create_answer() catches this GET param
            $create_url = wp_nonce_url(
                add_query_arg(
                    [ 'itpb_create_answer' => $post->ID ],
                    admin_url( 'edit.php?post_type=' . ITPB_CPT_PROBLEM )
                ),
                'itpb_create_answer_' . $post->ID
            );
            ?>
            <p style="color:#646970; font-size:12px;">
                <?php esc_html_e( 'No answer linked yet.', 'wp-it-problem' ); ?>
            </p>
            <a href="<?php echo esc_url( $create_url ); ?>" class="button button-primary">
                <?php esc_html_e( 'Create and link answer', 'wp-it-problem' ); ?>
            </a>
            <p style="color:#646970; font-size:12px; margin-top:6px;">
                <?php esc_html_e( 'Save the problem first, then create the answer.', 'wp-it-problem' ); ?>
            </p>
            <?php
        }
    }

    // -----------------------------------------------------------------------
    // Render — Box 4 : Back-link on it_answer screen
    // -----------------------------------------------------------------------

    public function render_back_to_problem_box( \WP_Post $post ): void {
        $problem = itpb_get_problem_post( $post->ID );

        if ( $problem ) {
            $edit_url = get_edit_post_link( $problem->ID );
            ?>
            <p>
                <strong><?php echo esc_html( get_the_title( $problem->ID ) ); ?></strong>
            </p>
            <p>
                <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-secondary">
                    <?php esc_html_e( '&larr; Back to problem', 'wp-it-problem' ); ?>
                </a>
            </p>
            <?php
            // Show reveal date info on answer screen too
            $reveal_at = itpb_get_reveal_at( $problem->ID );
            if ( $reveal_at ) {
                $fmt = $reveal_at->format( 'Y-m-d H:i' ) . ' UTC';
                printf(
                    '<p style="color:#646970; font-size:12px;">' .
                    /* translators: %s: formatted datetime */
                    esc_html__( 'Reveal scheduled: %s', 'wp-it-problem' ) . '</p>',
                    esc_html( $fmt )
                );
            }
        } else {
            ?>
            <p style="color:#d63638;">
                <?php esc_html_e( 'No parent problem linked. This answer is orphaned.', 'wp-it-problem' ); ?>
            </p>
            <?php
        }
    }

    // -----------------------------------------------------------------------
    // Save — it_problem
    // -----------------------------------------------------------------------

    /**
     * @param int      $post_id
     * @param \WP_Post $post
     */
    public function save_problem_meta( int $post_id, \WP_Post $post ): void {
        // Standard WP guards
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        // --- Reveal date ---
        if (
            isset( $_POST['itpb_reveal_nonce'] )
            && wp_verify_nonce( sanitize_key( $_POST['itpb_reveal_nonce'] ), 'itpb_save_reveal' )
            && isset( $_POST['itpb_reveal_at'] )
        ) {
            $raw      = sanitize_text_field( wp_unslash( $_POST['itpb_reveal_at'] ) );
            $clean    = $this->sanitize_datetime( $raw );

            if ( $clean ) {
                update_post_meta( $post_id, ITPB_META_REVEAL_AT, $clean );
            } else {
                delete_post_meta( $post_id, ITPB_META_REVEAL_AT );
            }
        }

        // --- Infra context ---
        if (
            isset( $_POST['itpb_infra_nonce'] )
            && wp_verify_nonce( sanitize_key( $_POST['itpb_infra_nonce'] ), 'itpb_save_infra' )
            && isset( $_POST['itpb_infra'] )
        ) {
            $infra = sanitize_textarea_field( wp_unslash( $_POST['itpb_infra'] ) );
            update_post_meta( $post_id, ITPB_META_INFRA, $infra );
        }
    }

    // -----------------------------------------------------------------------
    // Save — it_answer (nothing editable on its own screen for now)
    // -----------------------------------------------------------------------

    public function save_answer_meta( int $post_id, \WP_Post $post ): void {
        // Guard
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        // problem_id is set programmatically by ITPB_Admin::create_answer_post()
        // nothing to save from the form here
    }

    // -----------------------------------------------------------------------
    // Sanitize helpers
    // -----------------------------------------------------------------------

    /**
     * Sanitize a datetime-local input value (Y-m-d\TH:i) or a full datetime
     * string and return Y-m-d H:i:s (UTC) or empty string on failure.
     *
     * @param  string $raw
     * @return string
     */
    public function sanitize_datetime( string $raw ): string {
        $raw = trim( $raw );
        if ( ! $raw ) {
            return '';
        }

        // datetime-local: "2026-06-01T14:00" → "2026-06-01 14:00:00"
        $raw = str_replace( 'T', ' ', $raw );

        try {
            $dt = new \DateTimeImmutable( $raw, new \DateTimeZone( 'UTC' ) );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( \Exception ) {
            return '';
        }
    }
}
