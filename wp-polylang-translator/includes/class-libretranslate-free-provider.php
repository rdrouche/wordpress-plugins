<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Increase timeout for lange post
set_time_limit(600);

/**
 * LibreTranslate (sans clé API) provider.
 *
 * Utilise :
 *  1. L'URL personnalisée configurée dans les réglages (si renseignée), en premier.
 *  2. Des instances publiques LibreTranslate en fallback (aucune configuration nécessaire).
 *
 * Aucune clé API n'est requise (certaines instances privées en acceptent une en option,
 * mais ce provider l'omet volontairement — utiliser l'autre provider LibreTranslate
 * si une clé est nécessaire).
 */
class WPPT_LibreTranslate_Free_Provider implements WPPT_Provider_Interface {

    /**
     * Instances publiques de secours (sans clé API).
     * Essayées dans l'ordre si l'URL personnalisée échoue ou n'est pas définie.
     */
    const PUBLIC_INSTANCES = array(
        'http://libretranslate:5000',
        //'https://translate.terraprint.co',
        //'https://lt.vern.cc',
        //'https://translate.projectsegfau.lt',
        //'https://libretranslate.pussthecat.org',
    );

    /** @var int Timeout en secondes par requête. */
    private $timeout = 600;

    /**
     * Retourne true : pas de clé obligatoire, toujours "configuré".
     * Si l'URL personnalisée est vide les instances publiques prennent le relais.
     */
    public function get_label() {
        return __( 'LibreTranslate (sans clé API)', 'wp-polylang-translator' );
    }

    public function is_configured() {
        return true;
    }

    /**
     * Construit la liste ordonnée des instances à essayer.
     * L'URL personnalisée passe en premier si elle est définie.
     *
     * @return string[]
     */
    private function get_instances() {
        $custom = trim( (string) get_option( 'wppt_libretranslate_free_url', '' ) );
        $custom = rtrim( $custom, '/' );

        $instances = self::PUBLIC_INSTANCES;

        if ( ! empty( $custom ) ) {
            // Mettre l'URL personnalisée en tête de liste.
            array_unshift( $instances, $custom );
        }

        return $instances;
    }

    /**
     * Traduit un texte.
     *
     * @param string $text        Texte à traduire.
     * @param string $source_lang Code ISO 639-1 source (ex. 'fr') ou vide pour auto-détection.
     * @param string $target_lang Code ISO 639-1 cible (ex. 'en').
     * @return string|WP_Error
     */
    public function translate( $text, $source_lang, $target_lang ) {
        if ( empty( trim( $text ) ) ) {
            return $text;
        }

        $src = empty( $source_lang ) ? 'auto' : strtolower( $source_lang );
        $tgt = strtolower( $target_lang );

        $body = wp_json_encode( array(
            'q'      => $text,
            'source' => $src,
            'target' => $tgt,
            'format' => 'html',
        ) );

        $last_error = new WP_Error(
            'lt_free_unavailable',
            __( 'Aucune instance LibreTranslate n\'a répondu (instance personnalisée + instances publiques). Vérifiez l\'URL configurée et votre connexion.', 'wp-polylang-translator' )
        );

        foreach ( $this->get_instances() as $base ) {
            $response = wp_remote_post( trailingslashit( $base ) . 'translate', array(
                'timeout' => $this->timeout,
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => $body,
            ) );

            if ( is_wp_error( $response ) ) {
                $last_error = $response;
                WPPT_Translator::log( 'LibreTranslate Free: connexion échouée', array( 'instance' => $base, 'error' => $response->get_error_message() ) );
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $data = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 === $code && isset( $data['translatedText'] ) ) {
                //return (string) $data['translatedText'];
                return html_entity_decode( $data['translatedText'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            }

            $err_msg    = isset( $data['error'] ) ? $data['error'] : "HTTP $code";
            $last_error = new WP_Error( 'lt_free_error', "$base : $err_msg" );
            WPPT_Translator::log( 'LibreTranslate Free: réponse invalide', array( 'instance' => $base, 'code' => $code, 'error' => $err_msg ) );
        }

        return $last_error;
    }
}
