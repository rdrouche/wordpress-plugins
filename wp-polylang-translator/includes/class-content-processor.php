<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPPT_Content_Processor {

    /** @var array<int,string> Contenu original indexé par id de jeton. */
    private $placeholders = array();

    /** @var int */
    private $counter = 0;

    /** @var int Nombre de jetons effectivement restaurés lors du dernier restore(). */
    private $restored_count = 0;

    private $untranslated_words  = array();
    private $custom_translations = array();

    public function __construct() {
        $this->load_config();
    }

    private function load_config() {
        $raw_words = get_option( 'wppt_untranslated_words', '' );
        if ( ! empty( $raw_words ) ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", $raw_words ) ) );
            $this->untranslated_words = array_values( $lines );
        }

        $raw_custom = get_option( 'wppt_custom_translations', '' );
        if ( ! empty( $raw_custom ) ) {
            $lines = array_filter( array_map( 'trim', explode( "\n", $raw_custom ) ) );
            foreach ( $lines as $line ) {
                $pairs = array_map( 'trim', explode( '|', $line ) );
                $entry = array();
                foreach ( $pairs as $pair ) {
                    $parts = explode( ':', $pair, 2 );
                    if ( count( $parts ) === 2 ) {
                        $entry[ strtoupper( trim( $parts[0] ) ) ] = trim( $parts[1] );
                    }
                }
                if ( ! empty( $entry ) ) {
                    $this->custom_translations[] = $entry;
                }
            }
        }
    }

    /**
     * Crée un jeton HTML neutre pour un fragment à ne pas traduire.
     * Le contenu original est stocké indexé par ID entier : la restauration
     * se fait par ID et non par comparaison de chaîne, ce qui rend le processus
     * tolérant aux réécritures du balisage par l'API (auto-fermeture, guillemets
     * simples, attributs réordonnés, espaces ajoutés…).
     */
    private function make_placeholder( $content ) {
        $id = $this->counter;
        $this->placeholders[ $id ] = $content;
        $this->counter++;
        return '<wppt id="' . $id . '"></wppt>';
    }

    public function protect( $content ) {
        $this->placeholders   = array();
        $this->counter        = 0;
        $this->restored_count = 0;

        // 1. Tous les commentaires HTML (délimiteurs de blocs Gutenberg inclus).
        $content = preg_replace_callback(
            '/<!--.*?-->/s',
            function ( $m ) {
                return $this->make_placeholder( $m[0] );
            },
            $content
        );

        // 2. Blocs <pre> et <code>.
        $content = preg_replace_callback(
            '/<(pre|code)(\s[^>]*)?>.*?<\/\1>/si',
            function ( $m ) {
                return $this->make_placeholder( $m[0] );
            },
            $content
        );

        // 3. Shortcodes.
        $content = preg_replace_callback(
            '/\[([a-z_-]+)[^\]]*\](?:.*?\[\/\1\])?/si',
            function ( $m ) {
                return $this->make_placeholder( $m[0] );
            },
            $content
        );

        // 4. Mots non traduisibles.
        foreach ( $this->untranslated_words as $word ) {
            if ( empty( $word ) ) {
                continue;
            }
            $content = preg_replace_callback(
                '/\b' . preg_quote( $word, '/' ) . '\b/u',
                function ( $m ) {
                    return $this->make_placeholder( $m[0] );
                },
                $content
            );
        }

        return $content;
    }

    /**
     * Restaure les fragments protégés.
     *
     * Tolère toutes les variantes que peut produire un moteur de traduction
     * traitant le HTML : <wppt id="3"></wppt>, <wppt id="3"/>, <wppt id='3'>,
     * <wppt  id = "3" >, attributs supplémentaires, etc.
     */
    public function restore( $content ) {
        $this->restored_count = 0;

        $content = preg_replace_callback(
            '#<\s*wppt\b[^>]*?id\s*=\s*["\']?\s*(\d+)\s*["\']?[^>]*?>(\s*<\s*/\s*wppt\s*>)?#i',
            function ( $m ) {
                $id = (int) $m[1];
                if ( isset( $this->placeholders[ $id ] ) ) {
                    $this->restored_count++;
                    return $this->placeholders[ $id ];
                }
                return '';
            },
            $content
        );

        // Nettoyage d'éventuels résidus <wppt> orphelins (id illisible).
        $content = preg_replace( '#<\s*/?\s*wppt\b[^>]*>#i', '', $content );

        return $content;
    }

    /** Nombre de fragments protégés lors du dernier protect(). */
    public function get_placeholder_count() {
        return count( $this->placeholders );
    }

    /** Nombre de fragments restaurés lors du dernier restore(). */
    public function get_restored_count() {
        return $this->restored_count;
    }

    /**
     * Nombre de fragments perdus par le service de traduction
     * (jetons envoyés mais absents de la réponse).
     */
    public function get_lost_count() {
        return max( 0, count( $this->placeholders ) - $this->restored_count );
    }

    public function apply_custom_translations( $content, $source_lang, $target_lang ) {
        $source_lang = strtoupper( $source_lang );
        $target_lang = strtoupper( $target_lang );

        foreach ( $this->custom_translations as $entry ) {
            if ( isset( $entry[ $source_lang ] ) && isset( $entry[ $target_lang ] ) ) {
                $from    = $entry[ $source_lang ];
                $to      = $entry[ $target_lang ];
                $content = preg_replace(
                    '/\b' . preg_quote( $from, '/' ) . '\b/ui',
                    $to,
                    $content
                );
            }
        }

        return $content;
    }

    public function split_content( $content, $max_length = 8000 ) {
        if ( mb_strlen( $content ) <= $max_length ) {
            return array( $content );
        }

        $paragraphs = preg_split( '/(\n\n+)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
        $chunks  = array();
        $current = '';

        foreach ( $paragraphs as $part ) {
            $combined = $current . $part;

            if ( mb_strlen( $combined ) > $max_length
                && ! empty( $current )
                // Ne jamais couper juste après un jeton : l'API perdrait le contexte.
                && ! preg_match( '#<\s*wppt\b[^>]*>\s*(<\s*/\s*wppt\s*>)?\s*$#i', rtrim( $current ) )
            ) {
                $chunks[]  = $current;
                $current   = $part;
            } else {
                $current = $combined;
            }
        }

        if ( ! empty( $current ) ) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
