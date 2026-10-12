( function ( blocks, blockEditor, components, element, i18n ) {
	'use strict';
	var registerBlockType  = blocks.registerBlockType;
	var useBlockProps      = blockEditor.useBlockProps;
	var InnerBlocks        = blockEditor.InnerBlocks;
	var InspectorControls  = blockEditor.InspectorControls;
	var RichText           = blockEditor.RichText;
	var PanelBody          = components.PanelBody;
	var SelectControl      = components.SelectControl;
	var ToggleControl      = components.ToggleControl;
	var useState           = element.useState;
	var createElement      = element.createElement;
	var __                 = i18n.__;
	var STYLE_OPTIONS = [
		{ label: 'Info (bleu)',      value: 'info'    },
		{ label: 'Astuce (violet)',  value: 'tip'     },
		{ label: 'Succès (vert)',    value: 'success' },
		{ label: 'Attention (orange)', value: 'warning' },
		{ label: 'Danger (rouge)',   value: 'danger'  },
	];
	function Edit( props ) {
		var attributes = props.attributes; var setAttributes = props.setAttributes;
		var label = attributes.label; var labelOpen = attributes.labelOpen;
		var style = attributes.style; var startOpen = attributes.startOpen;
		var previewState = useState( startOpen ); var previewOpen = previewState[0]; var setPreview = previewState[1];
		var blockProps = useBlockProps( { className: 'wp-tuto-spoiler wp-tuto-spoiler--' + style } );
		return createElement( element.Fragment, null,
			createElement( InspectorControls, null,
				createElement( PanelBody, { title: 'Paramètres du spoiler' },
					createElement( SelectControl, { label:'Style', value:style, options:STYLE_OPTIONS, onChange:function(v){setAttributes({style:v});} } ),
					createElement( ToggleControl, { label:'Ouvert par défaut', checked:startOpen, onChange:function(v){setAttributes({startOpen:v});setPreview(v);} } )
				)
			),
			createElement( 'div', blockProps,
				createElement( 'div', { className: 'wp-tuto-spoiler__header' },
					createElement( 'span', { className: 'wp-tuto-spoiler__icon' } ),
					createElement( RichText, { tagName:'span', className:'wp-tuto-spoiler__label',
						value: previewOpen ? labelOpen : label,
						onChange: function(v){ setAttributes( previewOpen ? {labelOpen:v} : {label:v} ); },
						placeholder: 'Texte du bouton…', allowedFormats: [] } ),
					createElement( 'button', { type:'button', className:'wp-tuto-spoiler__toggle-preview', onClick:function(){setPreview(!previewOpen);} },
						previewOpen ? '↑ Replier (éditeur)' : '↓ Déplier (éditeur)' )
				),
				createElement( 'div', { className:'wp-tuto-spoiler__content'+(previewOpen?' is-open':''), 'aria-hidden':String(!previewOpen) },
					createElement( InnerBlocks, { template:[ ['core/paragraph',{placeholder:'Contenu de la solution…'}] ] } )
				)
			)
		);
	}
	function Save( props ) {
		var a = props.attributes;
		var blockProps = useBlockProps.save( { className: 'wp-tuto-spoiler wp-tuto-spoiler--' + a.style } );
		return createElement( 'div', Object.assign({}, blockProps, {'data-start-open': a.startOpen?'true':'false'}),
			createElement( 'button', { type:'button', className:'wp-tuto-spoiler__header', 'aria-expanded': a.startOpen?'true':'false' },
				createElement( 'span', { className:'wp-tuto-spoiler__icon', 'aria-hidden':'true' } ),
				createElement( 'span', { className:'wp-tuto-spoiler__label', 'data-label-closed':a.label, 'data-label-open':a.labelOpen }, a.startOpen ? a.labelOpen : a.label )
			),
			createElement( 'div', { className:'wp-tuto-spoiler__content'+(a.startOpen?' is-open':''), 'aria-hidden':a.startOpen?'false':'true' },
				createElement( InnerBlocks.Content, null )
			)
		);
	}
	registerBlockType( 'wp-tuto-blocks/spoiler', { edit: Edit, save: Save } );
} )( window.wp.blocks, window.wp.blockEditor, window.wp.components, window.wp.element, window.wp.i18n );
