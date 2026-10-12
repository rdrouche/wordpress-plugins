<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Publie sur une PAGE Facebook via l'API Graph v19.
 *
 * Prérequis :
 *  - Créer une app Facebook sur https://developers.facebook.com
 *  - Permissions : pages_manage_posts, pages_read_engagement
 *  - Générer un Page Access Token (long-lived de préférence : 60 jours)
 *  - Renseigner l'ID de la page Facebook
 */
class SAP_Facebook {

    const GRAPH_URL = 'https://graph.facebook.com/v19.0/';

    private static function options(): array {
        return (array) get_option( 'sap_facebook', [] );
    }

    /**
     * Publie sur la page Facebook configurée.
     */
    public static function publish( string $message, int $post_id ): array|WP_Error {
        $opts         = self::options();
        $page_token   = $opts['page_token'] ?? '';
        $page_id      = $opts['page_id'] ?? '';

        if ( empty( $page_token ) || empty( $page_id ) ) {
            return new WP_Error( 'sap_facebook_missing_config', __( 'Facebook : Page Token ou Page ID manquant.', 'social-autoposter' ) );
        }

        $thumb_id = get_post_thumbnail_id( $post_id );
        $endpoint = self::GRAPH_URL . $page_id . '/';

        if ( $thumb_id ) {
            // Publication avec photo
            $image_url = wp_get_attachment_image_url( $thumb_id, 'large' );
            $body      = [
                'message'      => $message,
                'url'          => $image_url,
                'access_token' => $page_token,
            ];
            $api_path = $endpoint . 'photos';
        } else {
            // Publication texte simple
            $body = [
                'message'      => $message,
                'access_token' => $page_token,
            ];
            $api_path = $endpoint . 'feed';
        }

        $response = wp_remote_post(
            $api_path,
            [
                'body'    => $body,
                'timeout' => 30,
            ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 400 || isset( $data['error'] ) ) {
            $msg = $data['error']['message'] ?? 'Erreur API Facebook (' . $code . ')';
            return new WP_Error( 'sap_facebook_api_error', $msg );
        }

        return $data ?? [];
    }

    /**
     * Teste la connexion – retourne le nom de la page ou WP_Error.
     */
    public static function test_connection(): string|WP_Error {
        $opts       = self::options();
        $page_token = $opts['page_token'] ?? '';
        $page_id    = $opts['page_id'] ?? '';

        if ( empty( $page_token ) || empty( $page_id ) ) {
            return new WP_Error( 'sap_facebook_missing', 'Configuration incomplète.' );
        }

        $response = wp_remote_get(
            self::GRAPH_URL . $page_id . '?fields=name&access_token=' . $page_token,
            [ 'timeout' => 15 ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $data['error'] ) ) {
            return new WP_Error( 'sap_facebook_api', $data['error']['message'] );
        }

        return $data['name'] ?? 'Page Facebook connectée';
    }
}
