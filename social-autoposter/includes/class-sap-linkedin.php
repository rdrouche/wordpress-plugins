<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Publication LinkedIn – 3 modes au choix dans les réglages :
 *
 *  1. direct  → API LinkedIn /rest/posts (requiert approbation Community Management API)
 *  2. zapier  → Webhook Zapier (aucune approbation LinkedIn requise)
 *  3. buffer  → Buffer API (aucune approbation LinkedIn requise, ~6$/mois)
 */
class SAP_LinkedIn {

    const REST_BASE  = 'https://api.linkedin.com/rest/';
    const LI_VERSION = '202501';

    private static function options(): array {
        return (array) get_option( 'sap_linkedin', [] );
    }

    // ── Point d'entrée unique ────────────────────────────────────────────────
    public static function publish( string $message, int $post_id ): array|WP_Error {
        $opts = self::options();
        $mode = $opts['mode'] ?? 'direct';

        return match ( $mode ) {
            'zapier' => self::publish_via_zapier( $message, $post_id, $opts ),
            'buffer' => self::publish_via_buffer( $message, $post_id, $opts ),
            default  => self::publish_direct( $message, $post_id, $opts ),
        };
    }

    // ════════════════════════════════════════════════════════════════════════
    // MODE 1 : API LinkedIn directe
    // ════════════════════════════════════════════════════════════════════════

    private static function publish_direct( string $message, int $post_id, array $opts ): array|WP_Error {
        $access_token = $opts['access_token'] ?? '';
        $org_urn      = self::normalize_urn( $opts['org_urn'] ?? '' );

        if ( empty( $access_token ) || empty( $org_urn ) ) {
            return new WP_Error( 'sap_li_config', 'LinkedIn (direct) : access token ou URN de page manquant.' );
        }

        $body = [
            'author'        => $org_urn,
            'commentary'    => $message,
            'visibility'    => 'PUBLIC',
            'distribution'  => [
                'feedDistribution'               => 'MAIN_FEED',
                'targetEntities'                 => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState'            => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        $image_urn = self::upload_image_direct( $post_id, $access_token, $org_urn );
        if ( $image_urn ) {
            $body['content'] = [ 'media' => [ 'id' => $image_urn, 'title' => get_the_title( $post_id ) ] ];
        }

        $response = wp_remote_post(
            self::REST_BASE . 'posts',
            [
                'headers' => self::li_headers( $access_token ),
                'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
                'timeout' => 30,
            ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $data = json_decode( $raw, true ) ?? [];

        if ( $code >= 400 ) {
            $msg = $data['message'] ?? ( 'LinkedIn API ' . $code . ' : ' . substr( $raw, 0, 300 ) );
            return new WP_Error( 'sap_li_api', $msg );
        }

        return [ 'mode' => 'direct', 'urn' => wp_remote_retrieve_header( $response, 'x-restli-id' ), 'status' => $code ];
    }

    private static function upload_image_direct( int $post_id, string $token, string $org_urn ): string {
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( ! $thumb_id ) return '';
        $image_path = get_attached_file( $thumb_id );
        if ( ! $image_path || ! file_exists( $image_path ) ) return '';

        $init = wp_remote_post(
            self::REST_BASE . 'images?action=initializeUpload',
            [
                'headers' => self::li_headers( $token ),
                'body'    => wp_json_encode( [ 'initializeUploadRequest' => [ 'owner' => $org_urn ] ] ),
                'timeout' => 20,
            ]
        );
        if ( is_wp_error( $init ) ) return '';

        $d = json_decode( wp_remote_retrieve_body( $init ), true );
        $upload_url = $d['value']['uploadUrl'] ?? '';
        $image_urn  = $d['value']['image']     ?? '';
        if ( ! $upload_url || ! $image_urn ) return '';

        $put = wp_remote_request( $upload_url, [
            'method'  => 'PUT',
            'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => mime_content_type( $image_path ) ?: 'image/jpeg', 'LinkedIn-Version' => self::LI_VERSION ],
            'body'    => file_get_contents( $image_path ), // phpcs:ignore
            'timeout' => 60,
        ] );
        if ( is_wp_error( $put ) || (int) wp_remote_retrieve_response_code( $put ) >= 400 ) return '';

        return $image_urn;
    }

    private static function li_headers( string $token ): array {
        return [
            'Authorization'             => 'Bearer ' . $token,
            'Content-Type'              => 'application/json',
            'X-Restli-Protocol-Version' => '2.0.0',
            'LinkedIn-Version'          => self::LI_VERSION,
        ];
    }

    private static function normalize_urn( string $raw ): string {
        $raw = trim( $raw );
        if ( str_starts_with( $raw, 'urn:li:organization:' ) )     return $raw;
        if ( str_starts_with( $raw, 'urn:li:organizationPage:' ) ) return 'urn:li:organization:' . substr( $raw, 24 );
        if ( ctype_digit( $raw ) )                                  return 'urn:li:organization:' . $raw;
        return $raw;
    }

    // ════════════════════════════════════════════════════════════════════════
    // MODE 2 : Zapier Webhook (aucune approbation LinkedIn requise)
    // ════════════════════════════════════════════════════════════════════════
    // Setup :
    //   1. Créer un Zap : Trigger = "Webhooks by Zapier" (Catch Hook)
    //   2. Action = "LinkedIn" → "Create Company Update"
    //   3. Coller l'URL du webhook ici dans les réglages
    //   Plan Zapier gratuit : 100 tâches/mois.

    private static function publish_via_zapier( string $message, int $post_id, array $opts ): array|WP_Error {
        $webhook_url = $opts['zapier_webhook'] ?? '';

        if ( empty( $webhook_url ) ) {
            return new WP_Error( 'sap_li_zapier', 'LinkedIn (Zapier) : URL du webhook manquante.' );
        }

        $thumb_url = '';
        $thumb_id  = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $thumb_url = wp_get_attachment_image_url( $thumb_id, 'large' ) ?: '';
        }

        $payload = [
            'message'   => $message,
            'post_url'  => get_permalink( $post_id ),
            'post_title'=> get_the_title( $post_id ),
            'image_url' => $thumb_url,
            'timestamp' => current_time( 'c' ),
        ];

        $response = wp_remote_post( $webhook_url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ] );

        if ( is_wp_error( $response ) ) return $response;

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code >= 400 ) {
            return new WP_Error( 'sap_li_zapier', 'Zapier webhook erreur ' . $code );
        }

        return [ 'mode' => 'zapier', 'status' => $code ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // MODE 3 : Buffer API (aucune approbation LinkedIn requise)
    // ════════════════════════════════════════════════════════════════════════
    // Setup :
    //   1. buffer.com → Settings → API → Créer un Access Token
    //   2. buffer.com → ton canal LinkedIn Page → noter le Profile ID
    //      (visible dans l'URL ou via GET https://api.bufferapp.com/1/profiles.json)
    //   Plan Buffer gratuit : 3 canaux, 10 posts planifiés.

    private static function publish_via_buffer( string $message, int $post_id, array $opts ): array|WP_Error {
        $access_token = $opts['buffer_token']      ?? '';
        $profile_id   = $opts['buffer_profile_id'] ?? '';

        if ( empty( $access_token ) || empty( $profile_id ) ) {
            return new WP_Error( 'sap_li_buffer', 'LinkedIn (Buffer) : Access Token ou Profile ID manquant.' );
        }

        $body = [
            'text'        => $message,
            'profile_ids' => [ $profile_id ],
            'now'         => true,   // publier immédiatement (false = ajouter à la file Buffer)
        ];

        // Joindre l'image mise en avant si disponible
        $thumb_id = get_post_thumbnail_id( $post_id );
        if ( $thumb_id ) {
            $thumb_url = wp_get_attachment_image_url( $thumb_id, 'large' );
            if ( $thumb_url ) {
                $body['media'] = [ 'photo' => $thumb_url ];
            }
        }

        $response = wp_remote_post(
            'https://api.bufferapp.com/1/updates/create.json',
            [
                'headers' => [ 'Authorization' => 'Bearer ' . $access_token, 'Content-Type' => 'application/x-www-form-urlencoded' ],
                'body'    => http_build_query( [
                    'text'             => $message,
                    'profile_ids[]'    => $profile_id,
                    'now'              => 'true',
                    'media[photo]'     => $body['media']['photo'] ?? '',
                ] ),
                'timeout' => 20,
            ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true ) ?? [];

        if ( $code >= 400 || ( isset( $data['error'] ) ) ) {
            $msg = $data['error'] ?? ( 'Buffer API erreur ' . $code );
            return new WP_Error( 'sap_li_buffer', $msg );
        }

        return [ 'mode' => 'buffer', 'status' => $code, 'id' => $data['updates'][0]['id'] ?? '' ];
    }

    // ════════════════════════════════════════════════════════════════════════
    // Test de connexion (adapté selon le mode)
    // ════════════════════════════════════════════════════════════════════════

    public static function test_connection(): string|WP_Error {
        $opts = self::options();
        $mode = $opts['mode'] ?? 'direct';

        if ( $mode === 'zapier' ) {
            $url = $opts['zapier_webhook'] ?? '';
            if ( empty( $url ) ) return new WP_Error( 'sap_li_zapier', 'URL du webhook vide.' );
            // Ping le webhook avec un payload de test
            $r = wp_remote_post( $url, [
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body'    => wp_json_encode( [ 'test' => true, 'source' => 'social-autoposter' ] ),
                'timeout' => 10,
            ] );
            if ( is_wp_error( $r ) ) return $r;
            return 'Zapier webhook OK (statut ' . wp_remote_retrieve_response_code( $r ) . ')';
        }

        if ( $mode === 'buffer' ) {
            $token = $opts['buffer_token'] ?? '';
            if ( empty( $token ) ) return new WP_Error( 'sap_li_buffer', 'Access Token Buffer vide.' );
            $r = wp_remote_get( 'https://api.bufferapp.com/1/user.json', [
                'headers' => [ 'Authorization' => 'Bearer ' . $token ],
                'timeout' => 10,
            ] );
            if ( is_wp_error( $r ) ) return $r;
            $d = json_decode( wp_remote_retrieve_body( $r ), true );
            if ( isset( $d['error'] ) ) return new WP_Error( 'sap_li_buffer', $d['error'] );
            return 'Buffer connecté : ' . ( $d['name'] ?? $d['email'] ?? 'OK' );
        }

        // Mode direct : vérifier l'organisation
        $access_token = $opts['access_token'] ?? '';
        $org_urn      = self::normalize_urn( $opts['org_urn'] ?? '' );
        if ( empty( $access_token ) || empty( $org_urn ) ) return new WP_Error( 'sap_li_config', 'Configuration incomplète.' );

        $org_id = preg_replace( '/^urn:li:organization:/', '', $org_urn );
        $r = wp_remote_get( self::REST_BASE . 'organizations/' . $org_id, [
            'headers' => self::li_headers( $access_token ),
            'timeout' => 15,
        ] );
        if ( is_wp_error( $r ) ) return $r;
        $code = (int) wp_remote_retrieve_response_code( $r );
        $data = json_decode( wp_remote_retrieve_body( $r ), true );
        if ( $code >= 400 ) return new WP_Error( 'sap_li_api', $data['message'] ?? 'Erreur ' . $code );
        return $data['localizedName'] ?? 'Page connectée (' . $org_urn . ')';
    }
}
