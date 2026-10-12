<?php
/**
 * Converts HTML content to Markdown.
 * Handles headings, paragraphs, lists, links, images, bold, italic, code, blockquotes, tables.
 */
class WPME_HTML_To_Markdown {

    /**
     * Convert an HTML string to Markdown.
     *
     * @param string $html
     * @return string
     */
    public static function convert( $html ) {
        if ( empty( $html ) ) {
            return '';
        }

        // Preserve line breaks
        $html = str_replace( [ "\r\n", "\r" ], "\n", $html );

        $doc = new DOMDocument();
        libxml_use_internal_errors( true );
        $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        libxml_clear_errors();

        return trim( self::parse_node( $doc ) );
    }

    /**
     * Recursively parse a DOM node.
     *
     * @param DOMNode $node
     * @return string
     */
    private static function parse_node( DOMNode $node ) {
        $output = '';

        foreach ( $node->childNodes as $child ) {
            $output .= self::convert_node( $child );
        }

        return $output;
    }

    /**
     * Convert a single DOMNode to Markdown.
     *
     * @param DOMNode $node
     * @return string
     */
    private static function convert_node( DOMNode $node ) {
        if ( $node->nodeType === XML_TEXT_NODE ) {
            return $node->nodeValue;
        }

        if ( $node->nodeType !== XML_ELEMENT_NODE ) {
            return '';
        }

        $tag      = strtolower( $node->nodeName );
        $inner    = self::parse_node( $node );
        $inner    = trim( $inner );

        switch ( $tag ) {
            // Headings
            case 'h1': return "\n# {$inner}\n\n";
            case 'h2': return "\n## {$inner}\n\n";
            case 'h3': return "\n### {$inner}\n\n";
            case 'h4': return "\n#### {$inner}\n\n";
            case 'h5': return "\n##### {$inner}\n\n";
            case 'h6': return "\n###### {$inner}\n\n";

            // Paragraph / div
            case 'p':
            case 'div':
                return "\n{$inner}\n\n";

            // Line break
            case 'br':
                return "  \n";

            // Bold
            case 'strong':
            case 'b':
                return "**{$inner}**";

            // Italic
            case 'em':
            case 'i':
                return "_{$inner}_";

            // Strikethrough
            case 's':
            case 'strike':
            case 'del':
                return "~~{$inner}~~";

            // Inline code
            case 'code':
                // Check if parent is <pre> — handled below
                if ( $node->parentNode && strtolower( $node->parentNode->nodeName ) === 'pre' ) {
                    return $node->nodeValue;
                }
                return "`{$inner}`";

            // Code block
            case 'pre':
                $lang = '';
                // Try to detect language from class (e.g. language-php)
                if ( $node->hasAttribute( 'class' ) ) {
                    preg_match( '/language-(\w+)/', $node->getAttribute( 'class' ), $m );
                    $lang = $m[1] ?? '';
                }
                $code = $node->textContent;
                return "\n```{$lang}\n{$code}\n```\n\n";

            // Blockquote
            case 'blockquote':
                $lines = explode( "\n", trim( $inner ) );
                $quoted = array_map( fn( $l ) => '> ' . $l, $lines );
                return "\n" . implode( "\n", $quoted ) . "\n\n";

            // Horizontal rule
            case 'hr':
                return "\n---\n\n";

            // Links
            case 'a':
                $href  = $node->getAttribute( 'href' );
                $title = $node->getAttribute( 'title' );
                if ( $title ) {
                    return "[{$inner}]({$href} \"{$title}\")";
                }
                return "[{$inner}]({$href})";

            // Images
            case 'img':
                $src   = $node->getAttribute( 'src' );
                $alt   = $node->getAttribute( 'alt' );
                $title = $node->getAttribute( 'title' );
                if ( $title ) {
                    return "![{$alt}]({$src} \"{$title}\")";
                }
                return "![{$alt}]({$src})";

            // Unordered list
            case 'ul':
                return "\n" . self::convert_list( $node, 'ul' ) . "\n";

            // Ordered list
            case 'ol':
                return "\n" . self::convert_list( $node, 'ol' ) . "\n";

            // List item (handled inside list converters)
            case 'li':
                return $inner;

            // Table
            case 'table':
                return "\n" . self::convert_table( $node ) . "\n";

            // Ignore these tags but keep inner content
            case 'span':
            case 'section':
            case 'article':
            case 'header':
            case 'footer':
            case 'main':
            case 'aside':
            case 'tbody':
            case 'thead':
            case 'tfoot':
                return $inner;

            // figure : image + légende éventuelle
            case 'figure':
                return "\n" . $inner . "\n";

            case 'figcaption':
                return $inner ? "\n*{$inner}*\n" : '';

            // details / summary → bloc repliable simulé
            case 'summary':
                return "**{$inner}**\n";

            case 'details':
                return "\n{$inner}\n";

            // Iframes (YouTube, Vimeo, etc.) → lien de secours
            case 'iframe':
                $src   = $node->getAttribute( 'src' );
                $title = $node->getAttribute( 'title' ) ?: 'Embedded content';
                if ( $src ) {
                    return "\n[{$title}]({$src})\n\n";
                }
                return '';

            // Vidéo HTML5
            case 'video':
                $src = $node->getAttribute( 'src' );
                // Chercher aussi un <source> enfant
                if ( ! $src ) {
                    foreach ( $node->childNodes as $child ) {
                        if ( $child->nodeType === XML_ELEMENT_NODE && strtolower( $child->nodeName ) === 'source' ) {
                            $src = $child->getAttribute( 'src' );
                            break;
                        }
                    }
                }
                if ( $src ) {
                    return "\n[🎬 Vidéo]({$src})\n\n";
                }
                return '';

            // Audio HTML5
            case 'audio':
                $src = $node->getAttribute( 'src' );
                if ( ! $src ) {
                    foreach ( $node->childNodes as $child ) {
                        if ( $child->nodeType === XML_ELEMENT_NODE && strtolower( $child->nodeName ) === 'source' ) {
                            $src = $child->getAttribute( 'src' );
                            break;
                        }
                    }
                }
                if ( $src ) {
                    return "\n[🔊 Audio]({$src})\n\n";
                }
                return '';

            // Balise <mark> (surlignage)
            case 'mark':
                return "=={$inner}==";

            // Balise <abbr>
            case 'abbr':
                $title = $node->getAttribute( 'title' );
                return $title ? "{$inner} ({$title})" : $inner;

            // Définitions
            case 'dl':
                return "\n" . $inner . "\n";
            case 'dt':
                return "\n**{$inner}**\n";
            case 'dd':
                return ": {$inner}\n";

            // Exposant / Indice (pas de MD natif, on garde lisible)
            case 'sup':
                return "^{$inner}^";
            case 'sub':
                return "~{$inner}~";

            // Skip scripts / styles / comments
            case 'script':
            case 'style':
            case 'noscript':
            case 'source': // géré dans video/audio
                return '';

            default:
                // Pour toute balise inconnue : si elle contient du texte, on le garde.
                // On ajoute un commentaire HTML discret pour signaler la perte de structure.
                if ( trim( $inner ) !== '' ) {
                    return "\n<!-- tag:{$tag} -->\n{$inner}\n<!-- /tag:{$tag} -->\n";
                }
                return '';
        }
    }

    /**
     * Convert a <ul> or <ol> node to Markdown list.
     *
     * @param DOMNode $node
     * @param string  $type 'ul' or 'ol'
     * @param int     $depth
     * @return string
     */
    private static function convert_list( DOMNode $node, $type = 'ul', $depth = 0 ) {
        $output  = '';
        $counter = 1;
        $indent  = str_repeat( '  ', $depth );

        foreach ( $node->childNodes as $child ) {
            if ( $child->nodeType !== XML_ELEMENT_NODE ) {
                continue;
            }

            $tag = strtolower( $child->nodeName );
            if ( $tag !== 'li' ) {
                continue;
            }

            $prefix  = $type === 'ul' ? '- ' : "{$counter}. ";
            $counter ++;

            // Check for nested lists inside li
            $text        = '';
            $nested      = '';
            foreach ( $child->childNodes as $liChild ) {
                $liTag = strtolower( $liChild->nodeName );
                if ( $liChild->nodeType === XML_ELEMENT_NODE && in_array( $liTag, [ 'ul', 'ol' ] ) ) {
                    $nested .= self::convert_list( $liChild, $liTag, $depth + 1 );
                } else {
                    $text .= self::convert_node( $liChild );
                }
            }

            $output .= $indent . $prefix . trim( $text ) . "\n";
            if ( $nested ) {
                $output .= $nested;
            }
        }

        return $output;
    }

    /**
     * Convert an HTML table to a Markdown table.
     *
     * @param DOMNode $node
     * @return string
     */
    private static function convert_table( DOMNode $node ) {
        $rows = [];

        // Collect all tr elements (from thead, tbody, tfoot or direct)
        $xpath = new DOMXPath( $node->ownerDocument );
        $trs   = $xpath->query( './/tr', $node );

        foreach ( $trs as $tr ) {
            $cells = [];
            foreach ( $tr->childNodes as $cell ) {
                if ( $cell->nodeType !== XML_ELEMENT_NODE ) {
                    continue;
                }
                $cellTag = strtolower( $cell->nodeName );
                if ( in_array( $cellTag, [ 'td', 'th' ] ) ) {
                    $cells[] = trim( self::parse_node( $cell ) );
                }
            }
            if ( $cells ) {
                $rows[] = [ 'cells' => $cells, 'is_header' => ( $cellTag === 'th' ) ];
            }
        }

        if ( empty( $rows ) ) {
            return '';
        }

        // Determine column count
        $col_count = max( array_map( fn( $r ) => count( $r['cells'] ), $rows ) );

        $output     = '';
        $header_set = false;

        foreach ( $rows as $i => $row ) {
            // Pad cells
            while ( count( $row['cells'] ) < $col_count ) {
                $row['cells'][] = '';
            }

            $output .= '| ' . implode( ' | ', $row['cells'] ) . " |\n";

            // Insert separator after first row (treat as header)
            if ( ! $header_set ) {
                $sep         = array_fill( 0, $col_count, '---' );
                $output     .= '| ' . implode( ' | ', $sep ) . " |\n";
                $header_set  = true;
            }
        }

        return $output;
    }
}
