<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Google Translate – méthode gratuite, sans clé API.
 * Utilise l'endpoint non officiel : https://www.google.com/async/translate
 * Inspiré de https://github.com/... (voir référence fournie).
 *
 * Fonctionne en GET, aucune clé requise.
 * Traduit le texte brut segment par segment.
 * Pour le HTML, extrait les nœuds texte via DOMDocument, les traduit individuellement,
 * puis les réinjecte — le balisage est préservé intact.
 *
 * Limites : endpoint non officiel, sujet à des changements Google.
 */
class WPPT_Google_Free_Provider implements WPPT_Provider_Interface {

    //private $endpoint = 'https://www.google.com/async/translate';
    private $endpoint = "https://www.google.com/async/translate";

    /**
     * Liste complète des langues acceptées par cet endpoint.
     */
    private static $accepted_languages = array(
        'af','sq','de','am','en','ar','hy','as','ay','az','bm','eu','bn','bho',
        'be','my','bs','bg','ca','ceb','ny','zh-CN','si','ko','co','ht','hr',
        'da','dv','doi','es','eo','et','ee','fi','fr','fy','gd','gl','cy','ka',
        'el','gn','gu','ha','haw','iw','hi','hmn','hu','ig','ilo','id','ga',
        'is','it','ja','jw','kn','kk','km','rw','ky','gom','kri','ku','ckb',
        'lo','la','lv','ln','lt','lg','lb','mk','mai','ms','ml','mg','mt','mi',
        'mr','mni-Mtei','lus','mn','nl','ne','no','or','om','ug','uz','ps','pa',
        'fa','tl','pl','pt','qu','ro','ru','sm','sa','nso','sr','st','sn','sd',
        'sk','sl','so','su','sv','sw','tg','ta','tt','cs','te','th','ti','ts',
        'tr','tk','ak','uk','ur','vi','xh','yi','yo','zu',
    );

    public function get_label() {
        return 'Google Translate (gratuit, sans clé)';
    }

    public function is_configured() {
        return true; // Aucune configuration requise.
    }

    /**
     * Normalise un code langue Polylang vers un code accepté par cet endpoint.
     * Ex : fr_FR → fr, en_US → en, zh_CN → zh-CN
     */
    private function normalize_lang( $lang ) {
        $lang = strtolower( str_replace( '_', '-', trim( $lang ) ) );
        // Check exact match.
        if ( in_array( $lang, self::$accepted_languages, true ) ) {
            return $lang;
        }
        // Base language only (fr-FR → fr).
        $base = explode( '-', $lang )[0];
        if ( in_array( $base, self::$accepted_languages, true ) ) {
            return $base;
        }
        return $base;
    }

    /**
     * Translate a plain text string.
     *
     * @param string      $text    Plain text (no HTML).
     * @param string      $source  Source language code.
     * @param string      $target  Target language code.
     * @return string|WP_Error
     */
    public function translate( $text, $source, $target ) {
        if ( empty( trim( $text ) ) ) {
            return $text;
        }

        $src = $this->normalize_lang( $source );
        $tgt = $this->normalize_lang( $target );

        if ( ! in_array( $tgt, self::$accepted_languages, true ) ) {
            return new WP_Error( 'google_free_lang', sprintf(
                __( 'Langue cible "%s" non supportée par Google Translate gratuit.', 'wp-polylang-translator' ),
                $tgt
            ) );
        }

        $params = array(
            'id:'   . time() . '100',
            'qc:true',
            'ac:true',
            '_id:tw-async-translate',
            '_pms:s',
            '_fmt:pc',
            'tl:'   . $tgt,
            'sl:'   . ( ! empty( $src ) && in_array( $src, self::$accepted_languages, true ) ? $src : 'auto' ),
            'st:'   . rawurlencode( $text ),
        );

        $query = 'async=' . implode( ',', $params );
        $url   = $this->endpoint . '?' . $query;

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept-Language' => 'fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
            ),
            'timeout'  => 20,
            'sslverify' => true,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( 200 !== $code ) {
            return new WP_Error( 'google_free_error', sprintf(
                __( 'Erreur Google Translate gratuit (%d).', 'wp-polylang-translator' ),
                $code
            ) );
        }

        // Extract translated text from HTML response.
        preg_match( '/<span(?:\s+dir="rtl")?\s+id="tw-answ-target-text">(.*?)<\/span>/is', $body, $matches );

        if ( ! empty( $matches[1] ) ) {
            return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }

        // Fallback: return original if we cannot parse the response.
        return new WP_Error( 'google_free_parse', __( 'Impossible d\'extraire la traduction depuis la réponse Google.', 'wp-polylang-translator' ) );
    }

    /**
     * Translate HTML content by walking DOM text nodes.
     * Tags and attributes are preserved; only text content is sent to the API.
     *
     * This is called by WPPT_Translator when it needs to translate HTML.
     * The content passed here has already been through WPPT_Content_Processor::protect(),
     * so code/pre/shortcodes are already placeholders.
     *
     * @param string $html        HTML content.
     * @param string $source_lang Source language code.
     * @param string $target_lang Target language code.
     * @return string|WP_Error
     */
    public function translate_html( $html, $source_lang, $target_lang ) {
        if ( empty( trim( $html ) ) ) {
            return $html;
        }

        libxml_use_internal_errors( true );

        $doc = new DOMDocument( '1.0', 'UTF-8' );
        // Wrap in a div to preserve structure; force UTF-8 BOM trick.
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?><div id="wppt-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();

        $root = $doc->getElementById( 'wppt-root' );
        if ( ! $root ) {
            // Fallback: translate as plain text.
            return $this->translate( wp_strip_all_tags( $html ), $source_lang, $target_lang );
        }

        $errors = array();
        $this->walk_and_translate( $root, $source_lang, $target_lang, $errors );

        if ( ! empty( $errors ) ) {
            WPPT_Translator::log( 'Google Free HTML errors', $errors );
        }

        // Extract inner HTML of the wrapper div.
        $inner = '';
        foreach ( $root->childNodes as $child ) {
            $inner .= $doc->saveHTML( $child );
        }

        return $inner;
    }

    /**
     * Recursively walk DOM text nodes and translate each one.
     */
    private function walk_and_translate( DOMNode $node, $source_lang, $target_lang, &$errors ) {
        // Skip nodes we should never touch.
        if ( $node instanceof DOMText ) {
            $text = $node->nodeValue;
            if ( mb_strlen( trim( $text ) ) > 0 ) {
                $translated = $this->translate( $text, $source_lang, $target_lang );
                if ( ! is_wp_error( $translated ) ) {
                    // Preserve surrounding whitespace.
                    $leading  = mb_strlen( $text ) - mb_strlen( ltrim( $text ) );
                    $trailing = mb_strlen( $text ) - mb_strlen( rtrim( $text ) );
                    $node->nodeValue = str_repeat( ' ', $leading ) . $translated . str_repeat( ' ', $trailing );
                } else {
                    $errors[] = $translated->get_error_message();
                }
            }
            return;
        }

        // Skip script, style, code, pre — shouldn't be here (already protected), but defensive.
        if ( $node instanceof DOMElement ) {
            $tag = strtolower( $node->nodeName );
            if ( in_array( $tag, array( 'script', 'style', 'code', 'pre' ), true ) ) {
                return;
            }
        }

        if ( $node->hasChildNodes() ) {
            // Clone to avoid iterator issues.
            $children = iterator_to_array( $node->childNodes );
            foreach ( $children as $child ) {
                $this->walk_and_translate( $child, $source_lang, $target_lang, $errors );
            }
        }
    }
}
