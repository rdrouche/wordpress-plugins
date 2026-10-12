<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SAP_DB {

    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . SAP_TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id      BIGINT(20) UNSIGNED NOT NULL,
            network      VARCHAR(20)         NOT NULL,
            message      LONGTEXT            NOT NULL,
            scheduled_at DATETIME            NOT NULL,
            status       VARCHAR(20)         NOT NULL DEFAULT 'pending',
            response     LONGTEXT            NULL,
            created_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id    (post_id),
            KEY network    (network),
            KEY status     (status),
            KEY scheduled_at (scheduled_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        add_option( 'sap_db_version', SAP_VERSION );
    }

    // ── Insère une publication planifiée ────────────────────────────────────
    public static function insert( array $data ): int|false {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . SAP_TABLE,
            [
                'post_id'      => (int) $data['post_id'],
                'network'      => sanitize_text_field( $data['network'] ),
                'message'      => wp_kses_post( $data['message'] ),
                'scheduled_at' => $data['scheduled_at'],
                'status'       => 'pending',
            ],
            [ '%d', '%s', '%s', '%s', '%s' ]
        );
        return $wpdb->insert_id ?: false;
    }

    // ── Met à jour le statut après publication ───────────────────────────────
    public static function update_status( int $id, string $status, string $response = '' ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . SAP_TABLE,
            [ 'status' => $status, 'response' => $response ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    // ── Récupère les publications en attente ─────────────────────────────────
    public static function get_pending( string $network = '' ): array {
        global $wpdb;
        $table = $wpdb->prefix . SAP_TABLE;
        $now   = current_time( 'mysql' );

        if ( $network ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table} WHERE status='pending' AND network=%s AND scheduled_at <= %s ORDER BY scheduled_at ASC",
                    $network, $now
                )
            );
        }
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status='pending' AND scheduled_at <= %s ORDER BY scheduled_at ASC",
                $now
            )
        );
    }

    // ── Historique pour un article ───────────────────────────────────────────
    public static function get_by_post( int $post_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . SAP_TABLE;
        return $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id=%d ORDER BY scheduled_at DESC", $post_id )
        );
    }

    // ── Toutes les publications (page admin) ─────────────────────────────────
    public static function get_all( array $args = [] ): array {
        global $wpdb;
        $table   = $wpdb->prefix . SAP_TABLE;
        $limit   = isset( $args['limit'] )  ? (int) $args['limit']  : 50;
        $offset  = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
        $status  = isset( $args['status'] ) ? sanitize_text_field( $args['status'] ) : '';

        if ( $status ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT s.*, p.post_title FROM {$table} s LEFT JOIN {$wpdb->posts} p ON p.ID=s.post_id WHERE s.status=%s ORDER BY s.scheduled_at DESC LIMIT %d OFFSET %d",
                    $status, $limit, $offset
                )
            );
        }
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.*, p.post_title FROM {$table} s LEFT JOIN {$wpdb->posts} p ON p.ID=s.post_id ORDER BY s.scheduled_at DESC LIMIT %d OFFSET %d",
                $limit, $offset
            )
        );
    }

    public static function delete( int $id ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . SAP_TABLE, [ 'id' => $id ], [ '%d' ] );
    }
}
