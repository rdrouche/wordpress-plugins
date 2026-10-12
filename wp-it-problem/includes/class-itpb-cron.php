<?php
/**
 * ITPB_Cron
 *
 * Schedules and runs the daily reveal job.
 *
 * Logic:
 *  - One WP-Cron event: itpb_daily_reveal — fires once a day
 *  - On each run: query all it_problem posts whose _itpb_reveal_at <= NOW (UTC)
 *    and whose linked it_answer is still private → flip to publish
 *  - Logs each reveal in wp_itpb_reveal_log
 *  - Invalidates the per-problem status transient so the REST endpoint
 *    and front-end reflect the change immediately
 *
 * No email. No external HTTP. Pure WP.
 *
 * @package WP_IT_Problem
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class ITPB_Cron {

    /** WP-Cron hook name */
    const HOOK = 'itpb_daily_reveal';

    /** Transient prefix for per-problem reveal status cache */
    const TRANSIENT_PREFIX = 'itpb_status_';

    public function init(): void {
        // Register the cron event on activation (idempotent)
        add_action( 'itpb_activated', [ $this, 'schedule' ] );

        // Also schedule on init if not already scheduled (handles edge cases)
        add_action( 'init', [ $this, 'maybe_schedule' ] );

        // The actual job
        add_action( self::HOOK, [ $this, 'run_reveal' ] );

        // Clean up on deactivation (handled in ITPB_Install::deactivate too,
        // but we keep it here for clarity)
        add_action( 'itpb_deactivated', [ $this, 'unschedule' ] );
    }

    // -----------------------------------------------------------------------
    // Schedule management
    // -----------------------------------------------------------------------

    /**
     * Schedule the daily cron if not already scheduled.
     * Safe to call multiple times.
     */
    public function schedule(): void {
        if ( ! wp_next_scheduled( self::HOOK ) ) {
            // First run: 5 minutes from now, then daily
            wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'daily', self::HOOK );
        }
    }

    public function maybe_schedule(): void {
        $this->schedule();
    }

    public function unschedule(): void {
        $timestamp = wp_next_scheduled( self::HOOK );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::HOOK );
        }
    }

    // -----------------------------------------------------------------------
    // Main reveal job
    // -----------------------------------------------------------------------

    /**
     * Find all problems whose reveal_at has passed and whose answer is still
     * private, then publish those answers.
     *
     * Called by WP-Cron. Can also be triggered manually (e.g. from WP-CLI
     * or a diagnostic button): ITPB_Cron::instance()->run_reveal()
     */
    public function run_reveal(): void {
        $now = current_time( 'mysql', true ); // UTC

        /**
         * Query problems that:
         *  - are published (the problem itself is live)
         *  - have a _itpb_reveal_at meta <= NOW
         *  - have a _itpb_answer_id meta (linked answer exists)
         *
         * We fetch IDs only for performance.
         */
        $problem_ids = $this->get_due_problem_ids( $now );

        if ( empty( $problem_ids ) ) {
            return;
        }

        $revealed_count = 0;

        foreach ( $problem_ids as $problem_id ) {
            $result = $this->reveal_problem( $problem_id );
            if ( $result ) {
                $revealed_count++;
            }
        }

        // Log a summary to WP debug log if WP_DEBUG is on
        if ( $revealed_count > 0 && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( sprintf( '[ITPB] Cron run at %s UTC — %d answer(s) revealed.', $now, $revealed_count ) );
        }

        /**
         * Fires after the cron reveal job completes.
         *
         * @param int    $revealed_count Number of answers revealed this run.
         * @param string $now            UTC datetime of this run.
         * @since 1.0.0
         */
        do_action( 'itpb_cron_complete', $revealed_count, $now );
    }

    // -----------------------------------------------------------------------
    // Per-problem reveal
    // -----------------------------------------------------------------------

    /**
     * Reveal the answer for a single problem.
     *
     * @param  int $problem_id
     * @return bool  True if revealed, false if skipped / error.
     */
    public function reveal_problem( int $problem_id ): bool {
        $answer = itpb_get_answer_post( $problem_id );

        if ( ! $answer ) {
            return false;
        }

        // Already published — nothing to do
        if ( $answer->post_status === 'publish' ) {
            $this->bust_transient( $problem_id );
            return false;
        }

        // Only flip private → publish (don't touch draft/trash/etc.)
        if ( $answer->post_status !== 'private' ) {
            return false;
        }

        // Publish the answer
        $updated = wp_update_post( [
            'ID'          => $answer->ID,
            'post_status' => 'publish',
        ], true );

        if ( is_wp_error( $updated ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( sprintf(
                    '[ITPB] Failed to reveal answer %d for problem %d: %s',
                    $answer->ID,
                    $problem_id,
                    $updated->get_error_message()
                ) );
            }
            return false;
        }

        // Log the reveal
        $this->log_reveal( $problem_id, $answer->ID, 'cron' );

        // Bust the status transient so REST + front-end react immediately
        $this->bust_transient( $problem_id );

        /**
         * Fires after an answer has been revealed.
         *
         * @param int $problem_id  The it_problem post ID.
         * @param int $answer_id   The it_answer post ID, now published.
         * @since 1.0.0
         */
        do_action( 'itpb_answer_revealed', $problem_id, $answer->ID );

        return true;
    }

    // -----------------------------------------------------------------------
    // Query helpers
    // -----------------------------------------------------------------------

    /**
     * Return IDs of it_problem posts whose reveal_at has passed.
     *
     * @param  string $now  UTC datetime string (Y-m-d H:i:s).
     * @return int[]
     */
    private function get_due_problem_ids( string $now ): array {
        $query = new \WP_Query( [
            'post_type'      => ITPB_CPT_PROBLEM,
            'post_status'    => 'publish',
            'posts_per_page' => 200,   // Safety cap — adjust if site grows
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => ITPB_META_REVEAL_AT,
                    'value'   => $now,
                    'compare' => '<=',
                    'type'    => 'DATETIME',
                ],
                [
                    'key'     => ITPB_META_ANSWER_ID,
                    'compare' => 'EXISTS',
                ],
            ],
        ] );

        return array_map( 'intval', $query->posts );
    }

    // -----------------------------------------------------------------------
    // DB logging
    // -----------------------------------------------------------------------

    /**
     * Insert a row into wp_itpb_reveal_log.
     *
     * @param int    $problem_id
     * @param int    $answer_id
     * @param string $trigger_src  'cron' | 'manual' | 'api'
     */
    private function log_reveal( int $problem_id, int $answer_id, string $trigger_src = 'cron' ): void {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'itpb_reveal_log',
            [
                'problem_id'  => $problem_id,
                'answer_id'   => $answer_id,
                'revealed_at' => current_time( 'mysql', true ),
                'trigger_src' => sanitize_key( $trigger_src ),
            ],
            [ '%d', '%d', '%s', '%s' ]
        );
    }

    // -----------------------------------------------------------------------
    // Transient management
    // -----------------------------------------------------------------------

    /**
     * Delete the cached reveal status for a problem.
     * The REST endpoint (ITPB_Api) will regenerate it on next request.
     *
     * @param int $problem_id
     */
    public function bust_transient( int $problem_id ): void {
        delete_transient( self::TRANSIENT_PREFIX . $problem_id );
    }

    /**
     * Get the cached reveal status, or null if not cached.
     *
     * @param  int $problem_id
     * @return array{revealed: bool, reveal_at: int|null, time_left: int}|null
     */
    public static function get_cached_status( int $problem_id ): ?array {
        $data = get_transient( self::TRANSIENT_PREFIX . $problem_id );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Store reveal status in transient.
     * TTL: 60s while pending (short — countdown must stay accurate),
     *      1 hour once revealed (stable).
     *
     * @param int   $problem_id
     * @param array $status
     */
    public static function set_cached_status( int $problem_id, array $status ): void {
        $ttl = $status['revealed'] ? HOUR_IN_SECONDS : 60;
        set_transient( self::TRANSIENT_PREFIX . $problem_id, $status, $ttl );
    }

    // -----------------------------------------------------------------------
    // Manual trigger (WP-CLI / admin diagnostic)
    // -----------------------------------------------------------------------

    /**
     * Public method to manually trigger the reveal job.
     * Used by a future diagnostic page or WP-CLI command.
     *
     * @return int  Number of answers revealed.
     */
    public function trigger_manual(): int {
        $now         = current_time( 'mysql', true );
        $problem_ids = $this->get_due_problem_ids( $now );
        $count       = 0;

        foreach ( $problem_ids as $problem_id ) {
            if ( $this->reveal_problem( $problem_id ) ) {
                $this->log_reveal(
                    $problem_id,
                    (int) get_post_meta( $problem_id, ITPB_META_ANSWER_ID, true ),
                    'manual'
                );
                $count++;
            }
        }

        return $count;
    }
}
