<?php
/**
 * RDR_SH_Highlighter — Moteur de coloration syntaxique côté serveur
 *
 * Utilise highlight_string() de PHP pour PHP, et des règles regex
 * pour les autres langages courants (bash, PowerShell, SQL, YAML, JSON,
 * JavaScript, CSS, HTML, Dockerfile, etc.)
 * Renvoie du HTML avec numéros de ligne et classes CSS thémables.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RDR_SH_Highlighter {

    /* --------------------------------------------------------
       LANGAGES SUPPORTÉS
       -------------------------------------------------------- */
    private static $supported_languages = array(
        'php', 'javascript', 'js', 'typescript', 'ts',
        'html', 'xml', 'css', 'scss',
        'bash', 'shell', 'sh', 'powershell', 'ps1',
        'sql', 'json', 'yaml', 'yml',
        'python', 'py', 'ruby', 'rb',
        'dockerfile', 'nginx', 'apache',
        'ini', 'conf', 'toml',
        'markdown', 'md',
        'plaintext', 'text', '',
    );

    /**
     * Point d'entrée principal.
     *
     * @param string $code     Code brut (déjà html_entity_decoded si besoin)
     * @param string $language Langage détecté
     * @param bool   $line_numbers Afficher les numéros de ligne
     * @return string HTML complet du bloc colorisé
     */
    public static function highlight( $code, $language = '', $line_numbers = true, $filename = '' ) {
        $language = strtolower( trim( $language ) );

        // Décoder les entités HTML (le contenu WP est souvent encodé)
        $code = html_entity_decode( $code, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

        // Tokeniser selon le langage
        $tokens = self::tokenize( $code, $language );

        // Construire le HTML
        return self::render( $tokens, $language, $line_numbers, $filename );
    }

    /* --------------------------------------------------------
       TOKENISEUR PAR LANGAGE
       -------------------------------------------------------- */
    private static function tokenize( $code, $language ) {
        switch ( $language ) {
            case 'php':
                return self::tokenize_php( $code );
            case 'javascript':
            case 'js':
            case 'typescript':
            case 'ts':
                return self::tokenize_js( $code );
            case 'html':
            case 'xml':
                return self::tokenize_html( $code );
            case 'css':
            case 'scss':
                return self::tokenize_css( $code );
            case 'bash':
            case 'shell':
            case 'sh':
                return self::tokenize_bash( $code );
            case 'powershell':
            case 'ps1':
                return self::tokenize_powershell( $code );
            case 'sql':
                return self::tokenize_sql( $code );
            case 'json':
                return self::tokenize_json( $code );
            case 'yaml':
            case 'yml':
                return self::tokenize_yaml( $code );
            case 'python':
            case 'py':
                return self::tokenize_python( $code );
            default:
                // Plaintext — juste échapper le HTML
                return array( array( 'type' => 'plain', 'value' => $code ) );
        }
    }

    /* --------------------------------------------------------
       RENDU HTML
       -------------------------------------------------------- */
    private static function render( $tokens, $language, $line_numbers, $filename = '' ) {
        // Reconstituer le texte coloré ligne par ligne
        $html_code = '';
        foreach ( $tokens as $token ) {
            if ( $token['type'] === 'raw_html' ) {
                // PHP : highlight_string() a déjà produit du HTML valide, ne pas ré-échapper
                $html_code .= $token['value'];
                continue;
            }
            $escaped = htmlspecialchars( $token['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            if ( $token['type'] === 'plain' ) {
                $html_code .= $escaped;
            } else {
                $html_code .= '<span class="rdr-t-' . esc_attr( $token['type'] ) . '">' . $escaped . '</span>';
            }
        }

        // Découper par lignes
        $lines = explode( "\n", $html_code );
        // Retirer la dernière ligne vide si le code finit par \n
        if ( end( $lines ) === '' ) {
            array_pop( $lines );
        }

        $lang_class   = $language ? ' rdr-lang-' . esc_attr( $language ) : '';
        $lang_display = esc_html( $language ?: 'code' );
        $fn_safe      = esc_html( $filename );
        $fn_attr      = esc_attr( $filename );

        $output  = '<div class="rdr-sh-block' . $lang_class . '" data-lang="' . esc_attr( $language ?: 'code' ) . '"';
        if ( $filename ) {
            $output .= ' data-rdr-filename="' . $fn_attr . '"';
        }
        $output .= '>';

        // ── Toolbar ──────────────────────────────────────────────
        $output .= '<div class="rdr-sh-toolbar">';

        // Côté gauche : badge langage + nom de fichier
        $output .= '<div class="rdr-sh-toolbar__left">';
        $output .= '<span class="rdr-sh-lang-badge">' . $lang_display . '</span>';

        if ( $filename ) {
            $icon_file = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm4 18H6V4h7v5h5v11z"/></svg>';
            $icon_dl   = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="12" height="12" fill="currentColor" aria-hidden="true"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>';
            $output .= '<span class="rdr-sh-filename-wrap">';
            $output .= '<button class="rdr-sh-filename" type="button"'
                     . ' data-filename="' . $fn_attr . '"'
                     . ' title="Ouvrir en texte brut">'
                     . $icon_file . ' ' . $fn_safe . '</button>';
            $output .= '<button class="rdr-sh-download" type="button"'
                     . ' data-filename="' . $fn_attr . '"'
                     . ' aria-label="Télécharger ' . $fn_attr . '"'
                     . ' title="Télécharger ' . $fn_attr . '">'
                     . $icon_dl . '</button>';
            $output .= '</span>';
        }

        $output .= '</div>'; // .rdr-sh-toolbar__left

        // Côté droit : bouton copier
        $output .= '<div class="rdr-sh-toolbar__right">';
        $output .= '<button class="rdr-sh-copy-btn" type="button" aria-label="Copier le code">';
        $output .= '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>';
        $output .= '<span>Copier</span>';
        $output .= '</button>';
        $output .= '</div>'; // .rdr-sh-toolbar__right

        $output .= '</div>'; // .rdr-sh-toolbar
        // ─────────────────────────────────────────────────────────

        $output .= '<div class="rdr-sh-inner">';

        if ( $line_numbers ) {
            // Colonne numéros de ligne
            $output .= '<div class="rdr-sh-gutter" aria-hidden="true">';
            foreach ( $lines as $i => $_ ) {
                $output .= '<span>' . ( $i + 1 ) . '</span>';
            }
            $output .= '</div>';
        }

        $output .= '<div class="rdr-sh-code"><pre><code>';
        $output .= implode( "\n", $lines );
        $output .= '</code></pre></div>';

        $output .= '</div>'; // .rdr-sh-inner
        $output .= '</div>'; // .rdr-sh-block

        return $output;
    }

    /* ======================================================
       TOKENISEURS PAR LANGAGE
       ====================================================== */

    /* -- PHP ------------------------------------------------ */
    private static function tokenize_php( $code ) {
        return self::regex_tokenize( $code, array(
            'comment'  => array(
                '/(#[^\n]*)/',
                '/(\/\/[^\n]*)/',
                '/(\/\*[\s\S]*?\*\/)/',
            ),
            'string'   => array(
                '/("(?:[^"\\\\]|\\\\.)*")/',
                "/(\'(?:[^\'\\\\]|\\\\.)*\')/"
            ),
            'variable' => array( '/(\$[a-zA-Z_][a-zA-Z0-9_]*)/' ),
            'number'   => array( '/\b(0x[\da-fA-F]+|\d+\.?\d*(?:[eE][+-]?\d+)?)\b/' ),
            'keyword'  => array( '/\b(abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|do|echo|else|elseif|empty|enddeclare|endfor|endforeach|endif|endswitch|endwhile|enum|extends|final|finally|fn|for|foreach|function|global|goto|if|implements|include|include_once|instanceof|insteadof|interface|isset|list|match|namespace|new|null|or|print|private|protected|public|readonly|require|require_once|return|self|static|switch|throw|trait|try|unset|use|var|while|xor|yield|true|false|__CLASS__|__DIR__|__FILE__|__FUNCTION__|__LINE__|__METHOD__|__NAMESPACE__|__TRAIT__)\b/i' ),
            'builtin'  => array( '/\b(abs|array_keys|array_map|array_merge|array_push|array_values|count|date|defined|explode|file_get_contents|header|htmlspecialchars|implode|in_array|intval|isset|json_decode|json_encode|nl2br|number_format|ob_start|ob_get_clean|preg_match|preg_replace|printf|rtrim|sprintf|str_contains|str_pad|str_replace|strlen|strpos|strtolower|strtoupper|substr|trim|var_dump|wp_enqueue_script|wp_localize_script|wp_send_json_error|wp_send_json_success)\b/' ),
            'tag'      => array( '/(<\?php|<\?=|\?>)/' ),
            'operator' => array( '/([=!<>+\-*\/&|^~%?:@.]+)/' ),
            'punct'    => array( '/([{}()\[\];,])/' ),
        ) );
    }

    /* -- JavaScript / TypeScript ---------------------------- */
    private static function tokenize_js( $code ) {
        return self::regex_tokenize( $code, array(
            'comment'  => array(
                '/(\/\/[^\n]*)/',
                '/(\/\*[\s\S]*?\*\/)/',
            ),
            'string'   => array(
                '/(`(?:[^`\\\\]|\\\\.)*`)/',
                '/("(?:[^"\\\\]|\\\\.)*")/',
                "/(\'(?:[^\'\\\\]|\\\\.)*\')/"
            ),
            'number'   => array( '/\b(0x[\da-fA-F]+|\d+\.?\d*(?:[eE][+-]?\d+)?)\b/' ),
            'keyword'  => array( '/\b(const|let|var|function|return|if|else|for|while|do|switch|case|break|continue|new|delete|typeof|instanceof|in|of|class|extends|import|export|default|async|await|try|catch|finally|throw|null|undefined|true|false|this|super|static|get|set|yield|from|as)\b/' ),
            'builtin'  => array( '/\b(console|document|window|Array|Object|String|Number|Boolean|Promise|Map|Set|JSON|Math|Date|RegExp|Error|fetch|setTimeout|setInterval|clearTimeout|clearInterval|parseInt|parseFloat|isNaN|isFinite|encodeURIComponent|decodeURIComponent)\b/' ),
            'operator' => array( '/([=!<>+\-*\/&|^~%?:]+)/' ),
            'punct'    => array( '/([{}()\[\];,\.])/' ),
        ) );
    }

    /* -- HTML / XML ----------------------------------------- */
    private static function tokenize_html( $code ) {
        return self::regex_tokenize( $code, array(
            'comment'   => array( '/(<!--[\s\S]*?-->)/' ),
            'doctype'   => array( '/(<!DOCTYPE[^>]*>)/i' ),
            'tag'       => array( '/(<\/?)([a-zA-Z][a-zA-Z0-9\-]*)/' ),
            'attr-name' => array( '/\b([a-zA-Z\-:]+)(?=\s*=)/' ),
            'string'    => array( '/(\"[^\"]*\"|\'[^\']*\')/' ),
            'entity'    => array( '/(&[a-zA-Z]+;|&#\d+;|&#x[\da-fA-F]+;)/' ),
        ) );
    }

    /* -- CSS / SCSS ----------------------------------------- */
    private static function tokenize_css( $code ) {
        return self::regex_tokenize( $code, array(
            'comment'   => array( '/(\/\*[\s\S]*?\*\/)/' ),
            'at-rule'   => array( '/(@[a-zA-Z\-]+)/' ),
            'selector'  => array( '/([.#]?[a-zA-Z][a-zA-Z0-9\-_]*)(?=\s*\{)/' ),
            'property'  => array( '/([a-zA-Z\-]+)(?=\s*:)/' ),
            'string'    => array( '/(\"[^\"]*\"|\'[^\']*\')/' ),
            'number'    => array( '/(\d+\.?\d*(?:px|em|rem|%|vh|vw|deg|s|ms)?)/' ),
            'color'     => array( '/(#[0-9a-fA-F]{3,8})\b/' ),
            'variable'  => array( '/(--[a-zA-Z][a-zA-Z0-9\-_]*)/' ),
        ) );
    }

    /* -- Bash / Shell --------------------------------------- */
    private static function tokenize_bash( $code ) {
        return self::regex_tokenize( $code, array(
            'comment'   => array( '/(#[^\n]*)/' ),
            'string'    => array(
                '/(`[^`]*`)/',
                '/(\"(?:[^\"\\\\]|\\\\.)*\")/',
                "/(\'[^\']*\')/"
            ),
            'variable'  => array( '/(\$\{?[a-zA-Z_][a-zA-Z0-9_]*\}?)/' ),
            'number'    => array( '/\b(\d+)\b/' ),
            'keyword'   => array( '/\b(if|then|else|elif|fi|for|while|do|done|case|esac|in|function|return|exit|export|local|declare|readonly|source|alias|unset|echo|printf|read|test|true|false|until|select|time|trap|exec|shift|set|unset|break|continue)\b/' ),
            'builtin'   => array( '/\b(ls|cd|pwd|cp|mv|rm|mkdir|rmdir|chmod|chown|grep|sed|awk|cat|tail|head|less|more|find|locate|which|whereis|man|ps|top|kill|killall|systemctl|service|apt|apt-get|yum|dnf|brew|curl|wget|ssh|scp|rsync|tar|gzip|gunzip|zip|unzip|git|docker|kubectl|helm|pip|npm|yarn|python|python3|ruby|perl|php|node|java|gcc|make|cmake|ansible|terraform)\b/' ),
            'flag'      => array( '/(\s-{1,2}[a-zA-Z][a-zA-Z0-9\-]*)/' ),
            'operator'  => array( '/([|><&;])/' ),
        ) );
    }

    /* -- PowerShell ----------------------------------------- */
    private static function tokenize_powershell( $code ) {
        return self::regex_tokenize( $code, array(
            'comment'   => array( '/(#[^\n]*)/', '/(^\s*<#[\s\S]*?#>)/m' ),
            'string'    => array(
                '/(\"(?:[^\"\\\\]|\\\\.)*\")/',
                "/(\'[^\']*\')/"
            ),
            'variable'  => array( '/(\$[a-zA-Z_][a-zA-Z0-9_]*)/' ),
            'cmdlet'    => array( '/\b([A-Z][a-z]+-[A-Z][a-zA-Z]+)\b/' ),
            'keyword'   => array( '/\b(if|else|elseif|while|for|foreach|do|switch|function|return|break|continue|param|begin|process|end|try|catch|finally|throw|exit|class|enum|using|namespace|module|import|export|workflow|parallel|sequence|inlinescript|configuration)\b/i' ),
            'number'    => array( '/\b(\d+\.?\d*)\b/' ),
            'operator'  => array( '/(-eq|-ne|-lt|-gt|-le|-ge|-and|-or|-not|-band|-bor|-xor|-match|-notmatch|-like|-notlike|-contains|-notcontains|-in|-notin|[=+\-*\/|&!])/' ),
            'flag'      => array( '/(\s-[a-zA-Z][a-zA-Z0-9]*)/' ),
        ) );
    }

    /* -- SQL ------------------------------------------------ */
    private static function tokenize_sql( $code ) {
        return self::regex_tokenize( $code, array(
            'comment'   => array( '/(--[^\n]*)/', '/(\/\*[\s\S]*?\*\/)/' ),
            'string'    => array( "/(\'(?:[^\'\\\\]|\\\\.|\'\')*\')/" ),
            'keyword'   => array( '/\b(SELECT|FROM|WHERE|JOIN|LEFT|RIGHT|INNER|OUTER|FULL|CROSS|ON|AS|GROUP BY|ORDER BY|HAVING|LIMIT|OFFSET|UNION|ALL|DISTINCT|INSERT|INTO|VALUES|UPDATE|SET|DELETE|CREATE|TABLE|VIEW|INDEX|DROP|ALTER|ADD|COLUMN|PRIMARY KEY|FOREIGN KEY|REFERENCES|NOT NULL|DEFAULT|AUTO_INCREMENT|CONSTRAINT|IF EXISTS|IF NOT EXISTS|DATABASE|SCHEMA|GRANT|REVOKE|COMMIT|ROLLBACK|BEGIN|TRANSACTION|EXPLAIN|SHOW|DESCRIBE|USE)\b/i' ),
            'function'  => array( '/\b(COUNT|SUM|AVG|MIN|MAX|COALESCE|NULLIF|CASE|WHEN|THEN|ELSE|END|CAST|CONVERT|NOW|CURDATE|DATE|YEAR|MONTH|DAY|CONCAT|LENGTH|UPPER|LOWER|TRIM|SUBSTRING|REPLACE|ROUND|FLOOR|CEIL|ABS|IFNULL|ISNULL|IS NULL|IS NOT NULL)\b/i' ),
            'number'    => array( '/\b(\d+\.?\d*)\b/' ),
            'operator'  => array( '/([=<>!+\-*\/,;()])/' ),
        ) );
    }

    /* -- JSON ----------------------------------------------- */
    private static function tokenize_json( $code ) {
        return self::regex_tokenize( $code, array(
            'key'       => array( '/(\"[^\"]*\")(?=\s*:)/' ),
            'string'    => array( '/(\"(?:[^\"\\\\]|\\\\.)*\")/' ),
            'number'    => array( '/\b(-?\d+\.?\d*(?:[eE][+-]?\d+)?)\b/' ),
            'keyword'   => array( '/\b(true|false|null)\b/' ),
            'operator'  => array( '/([{}\[\]:,])/' ),
        ) );
    }

    /* -- YAML ----------------------------------------------- */
    private static function tokenize_yaml( $code ) {
        return self::regex_tokenize( $code, array(
            'comment'   => array( '/(#[^\n]*)/' ),
            'key'       => array( '/^(\s*[a-zA-Z_][a-zA-Z0-9_\-]*)(?=\s*:)/m' ),
            'string'    => array(
                '/(\"(?:[^\"\\\\]|\\\\.)*\")/',
                "/(\'[^\']*\')/"
            ),
            'number'    => array( '/\b(\d+\.?\d*)\b/' ),
            'keyword'   => array( '/\b(true|false|null|yes|no|on|off)\b/i' ),
            'anchor'    => array( '/([&*][a-zA-Z][a-zA-Z0-9_]*)/' ),
            'directive' => array( '/(---)/', '/(\.\.\.)/'),
        ) );
    }

    /* -- Python --------------------------------------------- */
    private static function tokenize_python( $code ) {
        return self::regex_tokenize( $code, array(
            'comment'   => array( '/(#[^\n]*)/' ),
            'string'    => array(
                '/(\"\"\"[\s\S]*?\"\"\")/',
                "/(\'\'\'[\s\S]*?\'\'\')/" ,
                '/(f?\"(?:[^\"\\\\]|\\\\.)*\")/',
                "/(f?\'(?:[^\'\\\\]|\\\\.)*\')/"
            ),
            'number'    => array( '/\b(0x[\da-fA-F]+|\d+\.?\d*(?:[eE][+-]?\d+)?)\b/' ),
            'keyword'   => array( '/\b(False|None|True|and|as|assert|async|await|break|class|continue|def|del|elif|else|except|finally|for|from|global|if|import|in|is|lambda|nonlocal|not|or|pass|raise|return|try|while|with|yield)\b/' ),
            'builtin'   => array( '/\b(abs|all|any|ascii|bin|bool|breakpoint|bytearray|bytes|callable|chr|classmethod|compile|complex|copyright|credits|delattr|dict|dir|divmod|enumerate|eval|exec|filter|float|format|frozenset|getattr|globals|hasattr|hash|help|hex|id|input|int|isinstance|issubclass|iter|len|list|locals|map|max|memoryview|min|next|object|oct|open|ord|pow|print|property|range|repr|reversed|round|set|setattr|slice|sorted|staticmethod|str|sum|super|tuple|type|vars|zip)\b/' ),
            'decorator' => array( '/(@[a-zA-Z][a-zA-Z0-9_\.]*)/' ),
            'operator'  => array( '/([=!<>+\-*\/&|^~%@:,\.;()\[\]{}])/' ),
        ) );
    }

    /* ======================================================
       MOTEUR REGEX GÉNÉRIQUE
       ====================================================== */

    /**
     * Tokeniseur générique basé sur des règles regex ordonnées.
     * Parcourt le code caractère par caractère en appliquant
     * les règles dans l'ordre de priorité.
     *
     * @param string $code
     * @param array  $rules  [ 'type' => [ '/regex1/', '/regex2/' ], ... ]
     * @return array         [ [ 'type' => string, 'value' => string ], ... ]
     */
    private static function regex_tokenize( $code, $rules ) {
        $tokens = array();
        $pos    = 0;
        $length = strlen( $code ); // bytes — PCRE offset = bytes
        $plain  = '';

        while ( $pos < $length ) {
            $matched = false;

            foreach ( $rules as $type => $patterns ) {
                foreach ( $patterns as $pattern ) {
                    // Injecter \G pour ancrer le match à $pos
                    $delim    = $pattern[0];
                    $end_pos  = strrpos( $pattern, $delim, 1 );
                    $flags    = substr( $pattern, $end_pos + 1 );
                    $inner    = substr( $pattern, 1, $end_pos - 1 );
                    $anchored = $delim . '\\G(?:' . $inner . ')' . $delim . $flags;

                    if ( preg_match( $anchored, $code, $m, 0, $pos ) && isset( $m[0] ) && $m[0] !== '' ) {
                        if ( $plain !== '' ) {
                            $tokens[] = array( 'type' => 'plain', 'value' => $plain );
                            $plain    = '';
                        }
                        $tokens[] = array( 'type' => $type, 'value' => $m[0] );
                        $pos     += strlen( $m[0] );
                        $matched  = true;
                        break 2;
                    }
                }
            }

            if ( ! $matched ) {
                $plain .= $code[ $pos ];
                $pos++;
            }
        }

        if ( $plain !== '' ) {
            $tokens[] = array( 'type' => 'plain', 'value' => $plain );
        }

        return $tokens;
    }

    /* ======================================================
       RENDER SPÉCIAL POUR PHP (html brut)
       ====================================================== */
    private static function render_raw_html( $html, $line_numbers ) {
        // On ne peut pas facilement découper par lignes du HTML PHP coloré
        // → On wraps dans notre structure sans numéros de ligne
        $output  = '<div class="rdr-sh-block rdr-lang-php" data-lang="php">';
        $output .= '<div class="rdr-sh-toolbar">';
        $output .= '  <span class="rdr-sh-lang-badge">php</span>';
        $output .= '  <button class="rdr-sh-copy-btn" type="button" aria-label="Copier le code">';
        $output .= '    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M16 1H4c-1.1 0-2 .9-2 2v14h2V3h12V1zm3 4H8c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h11c1.1 0 2-.9 2-2V7c0-1.1-.9-2-2-2zm0 16H8V7h11v14z"/></svg>';
        $output .= '    <span>Copier</span>';
        $output .= '  </button>';
        $output .= '</div>';
        $output .= '<div class="rdr-sh-inner">';
        $output .= '<div class="rdr-sh-code rdr-php-native">' . $html . '</div>';
        $output .= '</div>';
        $output .= '</div>';
        return $output;
    }

    /**
     * Détecter le langage depuis une classe CSS (ex: language-bash, wp-block-code)
     */
    public static function detect_language( $class_attr ) {
        // Classes Gutenberg : language-xxx
        if ( preg_match( '/(?:language|lang)-([a-zA-Z0-9\-_]+)/i', $class_attr, $m ) ) {
            return strtolower( $m[1] );
        }
        // SyntaxHighlighter Evolved classes : brush:bash, etc.
        if ( preg_match( '/brush:\s*([a-zA-Z0-9]+)/i', $class_attr, $m ) ) {
            return strtolower( $m[1] );
        }
        return '';
    }

    /**
     * Vérifier si le langage est supporté
     */
    public static function is_supported( $language ) {
        return in_array( strtolower( $language ), self::$supported_languages, true );
    }
}
