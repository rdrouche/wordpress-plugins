<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Core translation orchestrator.
 * Selects the provider, handles fallback, and processes content.
 */
class WPPT_Translator {

    private static $instance = null;

    /** @var WPPT_Provider_Interface */
    private $primary_provider;

    /** @var WPPT_Provider_Interface|null */
    private $fallback_provider;

    /** @var WPPT_Content_Processor */
    private $processor;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $service = get_option( 'wppt_primary_service', 'deepl' );

        switch ( $service ) {
            case 'google':
                $this->primary_provider = new WPPT_Google_Provider();
                break;
            case 'google_free':
                $this->primary_provider = new WPPT_Google_Free_Provider();
                break;
            case 'libretranslate':
                $this->primary_provider = new WPPT_LibreTranslate_Provider();
                break;
            case 'libretranslate_free':
                $this->primary_provider = new WPPT_LibreTranslate_Free_Provider();
                break;
            case 'deepl':
            default:
                $this->primary_provider = new WPPT_DeepL_Provider();
                if ( '1' === get_option( 'wppt_deepl_fallback', '1' ) ) {
                    $fallback_svc = get_option( 'wppt_deepl_fallback_service', 'google_free' );
                    if ( 'libretranslate' === $fallback_svc ) {
                        $this->fallback_provider = new WPPT_LibreTranslate_Provider();
                    } else {
                        $this->fallback_provider = new WPPT_Google_Free_Provider();
                    }
                }
                break;
        }

        $this->processor = new WPPT_Content_Processor();
    }

    /** Libellé du service principal (diagnostic). */
    public function get_primary_label() {
        return $this->primary_provider->get_label();
    }

    /**
     * Translate a piece of HTML content.
     *
     * @param string $content     HTML content.
     * @param string $source_lang Source language (e.g. 'fr').
     * @param string $target_lang Target language (e.g. 'en').
     * @return string|WP_Error
     */
    public function translate_content( $content, $source_lang, $target_lang ) {
        if ( empty( trim( $content ) ) ) {
            return $content;
        }

        $blocks_before = substr_count( $content, '<!-- wp:' );

        // Protect code, shortcodes, untranslated words.
        $protected = $this->processor->protect( $content );

        // If the primary provider handles HTML natively (node-by-node), delegate entirely.
        if ( $this->primary_provider instanceof WPPT_Google_Free_Provider ) {
            $translated = $this->primary_provider->translate_html( $protected, $source_lang, $target_lang );
            if ( is_wp_error( $translated ) ) {
                return $translated;
            }
            $restored = $this->processor->restore( $translated );
        } else {
            // Standard path: send HTML to API with tag_handling (DeepL) or as HTML.
            $chunks  = $this->processor->split_content( $protected );
            $results = array();

            foreach ( $chunks as $chunk ) {
                $translated = $this->run_translation( $chunk, $source_lang, $target_lang );
                if ( is_wp_error( $translated ) ) {
                    return $translated;
                }
                $results[] = $translated;
            }

            $restored = $this->processor->restore( implode( '', $results ) );
        }

        // ---------------------------------------------------------------------
        // Contrôle d'intégrité : si le service de traduction a supprimé ou
        // fusionné des jetons, les délimiteurs de blocs Gutenberg (<!-- wp:… -->)
        // sont perdus et l'éditeur affiche un contenu vide / non éditable.
        // Dans ce cas on refuse la traduction plutôt que d'écraser l'article.
        // ---------------------------------------------------------------------
        $lost          = $this->processor->get_lost_count();
        $blocks_after  = substr_count( $restored, '<!-- wp:' );

        if ( $lost > 0 || ( $blocks_before > 0 && $blocks_after < $blocks_before ) ) {
            self::log( 'Markup integrity check failed', array(
                'provider'      => $this->primary_provider->get_label(),
                'placeholders'  => $this->processor->get_placeholder_count(),
                'restored'      => $this->processor->get_restored_count(),
                'lost'          => $lost,
                'blocks_before' => $blocks_before,
                'blocks_after'  => $blocks_after,
            ) );

            return new WP_Error(
                'wppt_markup_lost',
                sprintf(
                    /* translators: 1: lost tokens, 2: total tokens, 3: provider label */
                    __( 'Le service de traduction (%3$s) a supprimé %1$d marqueur(s) de balisage sur %2$d : les blocs Gutenberg seraient perdus. La traduction du contenu a été annulée pour ne pas casser l\'article.', 'wp-polylang-translator' ),
                    $lost,
                    $this->processor->get_placeholder_count(),
                    $this->primary_provider->get_label()
                )
            );
        }

        return $this->processor->apply_custom_translations( $restored, $source_lang, $target_lang );
    }

    /**
     * Translate a plain text string (titles, excerpts, taxonomy names).
     */
    public function translate_text( $text, $source_lang, $target_lang ) {
        if ( empty( trim( $text ) ) ) {
            return $text;
        }

        $protected  = $this->processor->protect( $text );
        $translated = $this->run_translation( $protected, $source_lang, $target_lang );

        if ( is_wp_error( $translated ) ) {
            return $translated;
        }

        $restored = $this->processor->restore( $translated );
        $restored = $this->processor->apply_custom_translations( $restored, $source_lang, $target_lang );

        return $restored;
    }

    /**
     * Run the actual translation with optional fallback.
     */
    private function run_translation( $text, $source_lang, $target_lang ) {
        $result = $this->primary_provider->translate( $text, $source_lang, $target_lang );

        if ( is_wp_error( $result ) ) {
            self::log( 'Provider error', array(
                'provider' => $this->primary_provider->get_label(),
                'code'     => $result->get_error_code(),
                'message'  => $result->get_error_message(),
            ) );
        }

        // On quota exceeded / error for DeepL, try fallback.
        if ( is_wp_error( $result ) && null !== $this->fallback_provider ) {
            if ( in_array( $result->get_error_code(), array( 'deepl_quota_exceeded', 'deepl_error' ), true ) ) {
                $result = $this->fallback_provider->translate( $text, $source_lang, $target_lang );
            }
        }

        return $result;
    }

    /**
     * Log an error in a consistent way.
     * Les 20 derniers messages sont aussi conservés en option pour affichage
     * dans la page de réglages (utile quand WP_DEBUG_LOG est désactivé).
     */
    public static function log( $message, $context = array() ) {
        $line = '[WPPT] ' . $message . ( empty( $context ) ? '' : ' ' . wp_json_encode( $context ) );

        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( $line );
        }

        $history   = get_option( 'wppt_log', array() );
        if ( ! is_array( $history ) ) {
            $history = array();
        }
        $history[] = array(
            'time' => current_time( 'mysql' ),
            'line' => $line,
        );
        if ( count( $history ) > 20 ) {
            $history = array_slice( $history, -20 );
        }
        update_option( 'wppt_log', $history, false );
    }
}
