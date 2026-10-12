( function () {
	'use strict';

	var blocks        = window.wp.blocks;
	var blockEditor   = window.wp.blockEditor;
	var element       = window.wp.element;
	var i18n          = window.wp.i18n;

	var registerBlockType = blocks.registerBlockType;
	var useBlockProps     = blockEditor.useBlockProps;
	var createElement     = element.createElement;
	var useState          = element.useState;
	var useEffect         = element.useEffect;
	var __                = i18n.__;

	/* ── Parser Markdown minimal (miroir JS du parser PHP) ── */
	function parseMarkdown( md ) {
		if ( ! md ) return '';
		var html   = '';
		var lines  = md.split( '\n' );
		var i      = 0;
		var n      = lines.length;

		function parseInline( text ) {
			// Images
			text = text.replace( /!\[([^\]]*)\]\(([^\)"\s]+)(?:\s+"([^"]*)")?\)/g,
				function( _, alt, src, title ) {
					return '<img src="' + src + '" alt="' + alt + '"' + ( title ? ' title="' + title + '"' : '' ) + '>';
				} );
			// Liens
			text = text.replace( /\[([^\]]+)\]\(([^\)"\s]+)(?:\s+"([^"]*)")?\)/g,
				function( _, label, href, title ) {
					return '<a href="' + href + '"' + ( title ? ' title="' + title + '"' : '' ) + '>' + label + '</a>';
				} );
			// Code inline
			text = text.replace( /`([^`]+)`/g, function( _, code ) {
				return '<code>' + code.replace( /</g, '&lt;' ).replace( />/g, '&gt;' ) + '</code>';
			} );
			text = text.replace( /\*{3}(.+?)\*{3}/gs, '<strong><em>$1</em></strong>' );
			text = text.replace( /_{3}(.+?)_{3}/gs,   '<strong><em>$1</em></strong>' );
			text = text.replace( /\*{2}(.+?)\*{2}/gs, '<strong>$1</strong>' );
			text = text.replace( /_{2}(.+?)_{2}/gs,   '<strong>$1</strong>' );
			text = text.replace( /\*(.+?)\*/gs,  '<em>$1</em>' );
			text = text.replace( /_(.+?)_/gs,    '<em>$1</em>' );
			text = text.replace( /~~(.+?)~~/gs,  '<del>$1</del>' );
			return text;
		}

		while ( i < n ) {
			var line = lines[ i ];

			// Fenced code block
			var fenceMatch = line.match( /^(`{3,}|~{3,})(\w*)/ );
			if ( fenceMatch ) {
				var fence = fenceMatch[1];
				var lang  = fenceMatch[2] ? ' class="language-' + fenceMatch[2] + '"' : '';
				var code  = '';
				i++;
				while ( i < n && lines[i].indexOf( fence ) !== 0 ) { code += lines[i] + '\n'; i++; }
				html += '<pre><code' + lang + '>' + code.replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).trimEnd() + '</code></pre>\n';
				i++;
				continue;
			}

			// Blockquote
			if ( line.charAt(0) === '>' ) {
				var bq = '';
				while ( i < n && lines[i].charAt(0) === '>' ) { bq += lines[i].slice(1).trimStart() + '\n'; i++; }
				html += '<blockquote>' + parseMarkdown( bq ) + '</blockquote>\n';
				continue;
			}

			// HR
			if ( /^(\*{3,}|-{3,}|_{3,})\s*$/.test( line ) ) { html += '<hr>\n'; i++; continue; }

			// Heading
			var hMatch = line.match( /^(#{1,6})\s+(.+)/ );
			if ( hMatch ) {
				html += '<h' + hMatch[1].length + '>' + parseInline( hMatch[2] ) + '</h' + hMatch[1].length + '>\n';
				i++; continue;
			}

			// UL
			if ( /^(\s*)([-*+])\s+/.test( line ) ) {
				html += '<ul>';
				while ( i < n && /^(\s*)([-*+])\s+(.+)/.test( lines[i] ) ) {
					html += '<li>' + parseInline( lines[i].replace( /^(\s*)([-*+])\s+/, '' ) ) + '</li>';
					i++;
				}
				html += '</ul>\n';
				continue;
			}

			// OL
			if ( /^\d+\.\s+/.test( line ) ) {
				html += '<ol>';
				while ( i < n && /^\d+\.\s+(.+)/.test( lines[i] ) ) {
					html += '<li>' + parseInline( lines[i].replace( /^\d+\.\s+/, '' ) ) + '</li>';
					i++;
				}
				html += '</ol>\n';
				continue;
			}

			// Empty line
			if ( line.trim() === '' ) { i++; continue; }

			// Paragraph
			var para = '';
			while ( i < n && lines[i].trim() !== '' && !/^#{1,6}\s/.test(lines[i]) && !/^(`{3,}|~{3,})/.test(lines[i]) && !/^(\*{3,}|-{3,}|_{3,})\s*$/.test(lines[i]) && !/^(\s*)([-*+]|\d+\.)\s/.test(lines[i]) && lines[i].charAt(0) !== '>' ) {
				para += ( para ? ' ' : '' ) + lines[i].trim();
				i++;
			}
			if ( para ) html += '<p>' + parseInline( para ) + '</p>\n';
		}
		return html;
	}

	/* ── Composant éditeur ── */
	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;
		var content       = attributes.content || '';

		var tabState    = useState( 'edit' );
		var activeTab   = tabState[0];
		var setActiveTab = tabState[1];

		var blockProps = useBlockProps( { className: 'wp-tuto-markdown-editor' } );

		return createElement(
			'div',
			blockProps,
			/* Tabs */
			createElement(
				'div',
				{ className: 'wp-tuto-markdown-editor__tabs' },
				createElement(
					'button',
					{
						type:      'button',
						className: 'wp-tuto-markdown-editor__tab' + ( activeTab === 'edit' ? ' is-active' : '' ),
						onClick:   function() { setActiveTab( 'edit' ); },
					},
					__( '✏ Markdown', 'wp-tuto-blocks' )
				),
				createElement(
					'button',
					{
						type:      'button',
						className: 'wp-tuto-markdown-editor__tab' + ( activeTab === 'preview' ? ' is-active' : '' ),
						onClick:   function() { setActiveTab( 'preview' ); },
					},
					__( '👁 Aperçu', 'wp-tuto-blocks' )
				)
			),
			/* Edit */
			activeTab === 'edit' && createElement(
				'textarea',
				{
					className:   'wp-tuto-markdown-editor__textarea',
					value:       content,
					onChange:    function( e ) { setAttributes( { content: e.target.value } ); },
					placeholder: __( 'Collez votre Markdown ici…', 'wp-tuto-blocks' ),
					rows:        12,
					spellCheck:  false,
				}
			),
			/* Preview */
			activeTab === 'preview' && createElement(
				'div',
				{
					className:           'wp-tuto-markdown wp-tuto-markdown-editor__preview',
					dangerouslySetInnerHTML: { __html: parseMarkdown( content ) || '<em style="color:#999">' + __( 'Rien à afficher', 'wp-tuto-blocks' ) + '</em>' },
				}
			)
		);
	}

	function Save() { return null; }

	registerBlockType( 'wp-tuto-blocks/markdown', { edit: Edit, save: Save } );
} )();
