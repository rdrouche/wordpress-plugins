<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class SAP_Scheduler {

    const HOOK = 'sap_process_queue';

    public static function init(): void {
        add_action( self::HOOK, [ __CLASS__, 'process_queue' ] );

        // Ajoute un intervalle toutes les 5 minutes si pas encore enregistré
        add_filter( 'cron_schedules', [ __CLASS__, 'add_intervals' ] );

        if ( ! wp_next_scheduled( self::HOOK ) ) {
            wp_schedule_event( time(), 'every_5_minutes', self::HOOK );
        }
    }

    public static function add_intervals( array $schedules ): array {
        $schedules['every_5_minutes'] = [
            'interval' => 300,
            'display'  => __( 'Toutes les 5 minutes', 'social-autoposter' ),
        ];
        return $schedules;
    }

    public static function clear_all_events(): void {
        wp_clear_scheduled_hook( self::HOOK );
    }

    /**
     * Traite les publications en attente dont la date est passée.
     */
    public static function process_queue(): void {
        $pending = SAP_DB::get_pending();

        foreach ( $pending as $item ) {
            $message = self::interpolate( $item->message, (int) $item->post_id );

            if ( $item->network === 'linkedin' ) {
                $result = SAP_LinkedIn::publish( $message, (int) $item->post_id );
            } elseif ( $item->network === 'facebook' ) {
                $result = SAP_Facebook::publish( $message, (int) $item->post_id );
            } else {
                continue;
            }

            if ( is_wp_error( $result ) ) {
                SAP_DB::update_status( (int) $item->id, 'failed', $result->get_error_message() );
            } else {
                SAP_DB::update_status( (int) $item->id, 'published', wp_json_encode( $result ) );
            }
        }
    }

    /**
     * Remplace les variables du message : {{URL_POST}}, {{TITRE}}, {{EXTRAIT}}
     */
    public static function interpolate( string $message, int $post_id ): string {
        $url     = get_permalink( $post_id );
        $title   = get_the_title( $post_id );
        $excerpt = get_the_excerpt( $post_id );

        $message = str_replace( '{{URL_POST}}',  esc_url( $url ),     $message );
        $message = str_replace( '{{TITRE}}',     esc_html( $title ),  $message );
        $message = str_replace( '{{EXTRAIT}}',   esc_html( $excerpt ), $message );

        // Convertit le HTML basique (gras, italique) en texte Unicode pour les API
        $message = self::html_to_unicode_text( $message );

        return $message;
    }

    /**
     * Convertit <b>, <strong>, <em>, <i> en caractères Unicode gras/italique
     * (seul moyen d'avoir du formatage dans les posts LinkedIn/Facebook via API)
     */
    private static function html_to_unicode_text( string $html ): string {
        // Supprime les balises non reconnues mais conserve le texte
        $text = wp_strip_all_tags( $html );
        // Note : pour une conversion gras/italique Unicode complète, une librairie
        // dédiée est nécessaire. Ce plugin conserve le texte brut sans formatage
        // pour la compatibilité maximale avec les deux APIs.
        return $text;
    }
}
