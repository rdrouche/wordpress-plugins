<?php
/**
 * WP Tuto Blocks — Parser Markdown minimal
 *
 * Supporte :
 *   - Titres          # H1 … ###### H6
 *   - Gras            **texte** ou __texte__
 *   - Italique        *texte* ou _texte_
 *   - Gras+italique   ***texte***
 *   - Code inline     `code`
 *   - Barré           ~~texte~~
 *   - Liens           [texte](url) et [texte](url "titre")
 *   - Images          ![alt](url)
 *   - Blocs de code   ```lang\n…\n``` et indentation 4 espaces
 *   - Blockquote      > texte
 *   - Listes ul       - / * / + item
 *   - Listes ol       1. item
 *   - Ligne hr        --- / *** / ___
 *   - Tableaux        | col | col |
 *   - Paragraphes & sauts de ligne
 *
 * Pas de dépendances externes.
 */

defined( 'ABSPATH' ) || exit;

class RdritMarkdownParser {

	/**
	 * Point d'entrée principal.
	 */
	public static function parse( string $markdown ): string {
		$md = self::normalize( $markdown );
		$md = self::parse_blocks( $md );
		return $md;
	}

	/* ── Normalisation ────────────────────────────────────────────────────── */

	private static function normalize( string $text ): string {
		$text = str_replace( "\r\n", "\n", $text );
		$text = str_replace( "\r", "\n", $text );
		return $text;
	}

	/* ── Parsing niveau bloc ──────────────────────────────────────────────── */

	private static function parse_blocks( string $text ): string {
		$lines  = explode( "\n", $text );
		$output = '';
		$i      = 0;
		$count  = count( $lines );

		while ( $i < $count ) {
			$line = $lines[ $i ];

			// ── Bloc de code fenced ─────────────────────────────────────────
			if ( preg_match( '/^(`{3,}|~{3,})(\w*)/', $line, $m ) ) {
				$fence = $m[1];
				$lang  = $m[2] ? ' class="language-' . esc_attr( $m[2] ) . '"' : '';
				$code  = '';
				$i++;
				while ( $i < $count && ! str_starts_with( $lines[ $i ], $fence ) ) {
					$code .= htmlspecialchars( $lines[ $i ] ) . "\n";
					$i++;
				}
				$output .= '<pre><code' . $lang . '>' . rtrim( $code ) . '</code></pre>' . "\n";
				$i++;
				continue;
			}

			// ── Bloc de code indenté (4 espaces / 1 tab) ───────────────────
			if ( preg_match( '/^(    |\t)(.*)/', $line, $m ) ) {
				$code = htmlspecialchars( $m[2] ) . "\n";
				$i++;
				while ( $i < $count && preg_match( '/^(    |\t)(.*)/', $lines[ $i ], $m2 ) ) {
					$code .= htmlspecialchars( $m2[2] ) . "\n";
					$i++;
				}
				$output .= '<pre><code>' . rtrim( $code ) . '</code></pre>' . "\n";
				continue;
			}

			// ── Blockquote ──────────────────────────────────────────────────
			if ( str_starts_with( $line, '>' ) ) {
				$bq = '';
				while ( $i < $count && str_starts_with( $lines[ $i ], '>' ) ) {
					$bq .= ltrim( substr( $lines[ $i ], 1 ) ) . "\n";
					$i++;
				}
				$output .= '<blockquote>' . self::parse_blocks( $bq ) . '</blockquote>' . "\n";
				continue;
			}

			// ── Ligne horizontale ───────────────────────────────────────────
			if ( preg_match( '/^(\*{3,}|-{3,}|_{3,})\s*$/', $line ) ) {
				$output .= "<hr>\n";
				$i++;
				continue;
			}

			// ── Titre ───────────────────────────────────────────────────────
			if ( preg_match( '/^(#{1,6})\s+(.+)/', $line, $m ) ) {
				$level   = strlen( $m[1] );
				$content = self::parse_inline( $m[2] );
				$slug    = sanitize_title( $m[2] );
				$output .= "<h{$level} id=\"{$slug}\">{$content}</h{$level}>\n";
				$i++;
				continue;
			}

			// ── Tableau ─────────────────────────────────────────────────────
			if ( str_contains( $line, '|' ) && $i + 1 < $count && preg_match( '/^\|?[\s\-:]+\|/', $lines[ $i + 1 ] ) ) {
				$headers = self::parse_table_row( $line );
				$i += 2; // saute la ligne de séparation
				$thead = '<thead><tr>';
				foreach ( $headers as $h ) {
					$thead .= '<th>' . self::parse_inline( $h ) . '</th>';
				}
				$thead  .= '</tr></thead>';
				$tbody   = '<tbody>';
				while ( $i < $count && str_contains( $lines[ $i ], '|' ) ) {
					$cells  = self::parse_table_row( $lines[ $i ] );
					$tbody .= '<tr>';
					foreach ( $cells as $c ) {
						$tbody .= '<td>' . self::parse_inline( $c ) . '</td>';
					}
					$tbody .= '</tr>';
					$i++;
				}
				$tbody  .= '</tbody>';
				$output .= '<div class="wp-tuto-table-wrap"><table>' . $thead . $tbody . '</table></div>' . "\n";
				continue;
			}

			// ── Liste non ordonnée ──────────────────────────────────────────
			if ( preg_match( '/^(\s*)([-*+])\s+(.+)/', $line, $m ) ) {
				$items = '';
				while ( $i < $count && preg_match( '/^(\s*)([-*+])\s+(.+)/', $lines[ $i ], $lm ) ) {
					$items .= '<li>' . self::parse_inline( $lm[3] ) . '</li>';
					$i++;
				}
				$output .= '<ul>' . $items . '</ul>' . "\n";
				continue;
			}

			// ── Liste ordonnée ──────────────────────────────────────────────
			if ( preg_match( '/^\d+\.\s+(.+)/', $line, $m ) ) {
				$items = '';
				while ( $i < $count && preg_match( '/^\d+\.\s+(.+)/', $lines[ $i ], $lm ) ) {
					$items .= '<li>' . self::parse_inline( $lm[1] ) . '</li>';
					$i++;
				}
				$output .= '<ol>' . $items . '</ol>' . "\n";
				continue;
			}

			// ── Ligne vide ──────────────────────────────────────────────────
			if ( trim( $line ) === '' ) {
				$i++;
				continue;
			}

			// ── Paragraphe ──────────────────────────────────────────────────
			$para = '';
			while ( $i < $count && trim( $lines[ $i ] ) !== ''
				&& ! preg_match( '/^#{1,6}\s/', $lines[ $i ] )
				&& ! preg_match( '/^(`{3,}|~{3,})/', $lines[ $i ] )
				&& ! preg_match( '/^(\*{3,}|-{3,}|_{3,})\s*$/', $lines[ $i ] )
				&& ! preg_match( '/^(\s*)([-*+]|\d+\.)\s/', $lines[ $i ] )
				&& ! str_starts_with( $lines[ $i ], '>' )
			) {
				$para .= ( $para !== '' ? ' ' : '' ) . trim( $lines[ $i ] );
				$i++;
			}
			if ( $para !== '' ) {
				$output .= '<p>' . self::parse_inline( $para ) . '</p>' . "\n";
			}
		}

		return $output;
	}

	/* ── Parsing niveau inline ────────────────────────────────────────────── */

	private static function parse_inline( string $text ): string {
		// Images avant les liens (syntaxe similaire)
		$text = preg_replace_callback(
			'/!\[([^\]]*)\]\(([^\)"\s]+)(?:\s+"([^"]*)")?\)/',
			fn( $m ) => '<img src="' . esc_url( $m[2] ) . '" alt="' . esc_attr( $m[1] ) . '"' . ( ! empty( $m[3] ) ? ' title="' . esc_attr( $m[3] ) . '"' : '' ) . '>',
			$text
		);

		// Liens
		$text = preg_replace_callback(
			'/\[([^\]]+)\]\(([^\)"\s]+)(?:\s+"([^"]*)")?\)/',
			fn( $m ) => '<a href="' . esc_url( $m[2] ) . '"' . ( ! empty( $m[3] ) ? ' title="' . esc_attr( $m[3] ) . '"' : '' ) . '>' . $m[1] . '</a>',
			$text
		);

		// Code inline (avant gras/italique pour ne pas interférer)
		$text = preg_replace_callback(
			'/`([^`]+)`/',
			fn( $m ) => '<code>' . htmlspecialchars( $m[1] ) . '</code>',
			$text
		);

		// Gras + italique
		$text = preg_replace( '/\*{3}(.+?)\*{3}/s', '<strong><em>$1</em></strong>', $text );
		$text = preg_replace( '/_{3}(.+?)_{3}/s',   '<strong><em>$1</em></strong>', $text );

		// Gras
		$text = preg_replace( '/\*{2}(.+?)\*{2}/s', '<strong>$1</strong>', $text );
		$text = preg_replace( '/_{2}(.+?)_{2}/s',   '<strong>$1</strong>', $text );

		// Italique
		$text = preg_replace( '/\*(.+?)\*/s', '<em>$1</em>', $text );
		$text = preg_replace( '/_(.+?)_/s',   '<em>$1</em>', $text );

		// Barré
		$text = preg_replace( '/~~(.+?)~~/s', '<del>$1</del>', $text );

		// Saut de ligne forcé (deux espaces en fin de ligne)
		$text = preg_replace( '/  $/', '<br>', $text );

		return $text;
	}

	/* ── Utilitaire tableau ───────────────────────────────────────────────── */

	private static function parse_table_row( string $line ): array {
		$line  = trim( $line, '| ' );
		$cells = explode( '|', $line );
		return array_map( 'trim', $cells );
	}
}
