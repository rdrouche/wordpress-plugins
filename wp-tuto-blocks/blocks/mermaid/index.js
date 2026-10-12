( function () {
	'use strict';

	var blocks      = window.wp.blocks;
	var blockEditor = window.wp.blockEditor;
	var components  = window.wp.components;
	var element     = window.wp.element;
	var i18n        = window.wp.i18n;

	var registerBlockType  = blocks.registerBlockType;
	var useBlockProps      = blockEditor.useBlockProps;
	var InspectorControls  = blockEditor.InspectorControls;
	var PanelBody          = components.PanelBody;
	var SelectControl      = components.SelectControl;
	var ToggleControl      = components.ToggleControl;
	var TextControl        = components.TextControl;
	var RangeControl       = components.RangeControl;
	var createElement      = element.createElement;
	var useState           = element.useState;
	var useEffect          = element.useEffect;
	var useRef             = element.useRef;
	var Fragment           = element.Fragment;
	var __                 = i18n.__;

	var EXAMPLE = 'flowchart LR\n    A[Début] --> B{Condition}\n    B -- Oui --> C[Action]\n    B -- Non --> D[Fin]';

	/* ── Chargement Mermaid pour la preview éditeur ── */
	var mermaidLoaded = false; var mermaidLoading = false; var mermaidQueue = [];
	function loadMermaid( cb ) {
		if ( mermaidLoaded ) { cb(); return; }
		mermaidQueue.push( cb );
		if ( mermaidLoading ) return;
		mermaidLoading = true;
		var s = document.createElement('script');
		s.src = 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js';
		s.onload = function() {
			window.mermaid.initialize({startOnLoad:false,theme:'default',securityLevel:'loose'});
			mermaidLoaded = true;
			mermaidQueue.forEach(function(fn){fn();});
			mermaidQueue = [];
		};
		document.head.appendChild(s);
	}

	function MermaidPreview( props ) {
		var code = props.code;
		var ref  = useRef(null);
		var idSt = useState('mm-' + Math.random().toString(36).slice(2));
		var id   = idSt[0];
		useEffect(function(){
			if(!code||!ref.current) return;
			loadMermaid(function(){
				try {
					window.mermaid.render(id, code).then(function(r){
						if(ref.current) ref.current.innerHTML = r.svg;
					}).catch(function(e){
						if(ref.current) ref.current.innerHTML = '<p style="color:red;font-size:12px">Erreur : '+e.message+'</p>';
					});
				} catch(e){
					if(ref.current) ref.current.innerHTML = '<p style="color:red;font-size:12px">'+e.message+'</p>';
				}
			});
		},[code]);
		return createElement('div',{ref:ref,className:'wp-tuto-mermaid__preview-container'},
			createElement('p',{style:{color:'#999',fontSize:'12px'}},'Chargement du diagramme…')
		);
	}

	function Edit( props ) {
		var attributes    = props.attributes;
		var setAttributes = props.setAttributes;
		var code          = attributes.code        || '';
		var align         = attributes.align       || 'center';
		var maxWidth      = attributes.maxWidth    || '100%';
		var enableModal   = attributes.enableModal !== false;

		var tabSt      = useState( code ? 'preview' : 'edit' );
		var activeTab  = tabSt[0]; var setTab = tabSt[1];

		var blockProps = useBlockProps({ className:'wp-tuto-mermaid-editor' });

		/* Contrôle de maxWidth : on distingue "%" et "px" pour proposer un slider adapté */
		var isPercent = maxWidth.endsWith('%');
		var numVal    = parseInt( maxWidth ) || 100;

		return createElement( Fragment, null,
			createElement( InspectorControls, null,
				createElement( PanelBody, { title: __('Paramètres','wp-tuto-blocks') },

					/* Alignement */
					createElement( SelectControl, {
						label: __('Alignement','wp-tuto-blocks'), value: align,
						options: [{value:'left',label:'Gauche'},{value:'center',label:'Centré'},{value:'right',label:'Droite'}],
						onChange: function(v){ setAttributes({align:v}); },
					}),

					/* Largeur max — unité */
					createElement( SelectControl, {
						label: __('Unité de largeur','wp-tuto-blocks'),
						value: isPercent ? '%' : 'px',
						options: [{value:'%',label:'% (relatif)'},{value:'px',label:'px (fixe)'}],
						onChange: function(unit){
							setAttributes({ maxWidth: (unit==='%' ? Math.min(numVal,100) : Math.max(numVal,100)) + unit });
						},
					}),

					/* Largeur max — slider */
					createElement( RangeControl, {
						label: __('Largeur max','wp-tuto-blocks'),
						value: numVal,
						min:   isPercent ? 10  : 100,
						max:   isPercent ? 100 : 1400,
						step:  isPercent ? 5   : 50,
						onChange: function(v){ setAttributes({ maxWidth: v + (isPercent?'%':'px') }); },
					}),

					/* Saisie libre */
					createElement( TextControl, {
						label: __('Valeur exacte (ex: 600px, 80%)','wp-tuto-blocks'),
						value: maxWidth,
						onChange: function(v){ setAttributes({maxWidth:v}); },
					}),

					/* Modale */
					createElement( ToggleControl, {
						label: __('Ouvrir en plein écran au clic','wp-tuto-blocks'),
						checked: enableModal,
						onChange: function(v){ setAttributes({enableModal:v}); },
					})
				)
			),

			createElement( 'div', blockProps,
				/* Header */
				createElement('div',{className:'wp-tuto-mermaid-editor__header'},
					createElement('span',{className:'wp-tuto-mermaid-editor__title'},'📊 Diagramme Mermaid'+(enableModal?' 🔍':'')),
					createElement('div',{className:'wp-tuto-mermaid-editor__tabs'},
						createElement('button',{type:'button',className:'wp-tuto-mermaid-editor__tab'+(activeTab==='edit'?' is-active':''),onClick:function(){setTab('edit');}},'✏ Code'),
						createElement('button',{type:'button',className:'wp-tuto-mermaid-editor__tab'+(activeTab==='preview'?' is-active':''),onClick:function(){setTab('preview');}},'👁 Aperçu')
					)
				),
				/* Code */
				activeTab === 'edit' && createElement('div',{className:'wp-tuto-mermaid-editor__code-wrap'},
					createElement('textarea',{className:'wp-tuto-mermaid-editor__textarea',value:code,
						onChange:function(e){setAttributes({code:e.target.value});},
						placeholder:EXAMPLE,rows:10,spellCheck:false}),
					createElement('p',{className:'wp-tuto-mermaid-editor__hint'},
						'Syntaxe Mermaid : flowchart, sequenceDiagram, classDiagram, gantt… ',
						createElement('a',{href:'https://mermaid.js.org/syntax/flowchart.html',target:'_blank',rel:'noopener'},'Documentation ↗')
					)
				),
				/* Preview */
				activeTab === 'preview' && createElement('div',{className:'wp-tuto-mermaid-editor__preview-wrap',style:{maxWidth:maxWidth,margin:align==='center'?'0 auto':align==='right'?'0 0 0 auto':'0'}},
					code ? createElement(MermaidPreview,{code:code})
						 : createElement('p',{style:{color:'#999',textAlign:'center',padding:'2rem'}},'Saisissez du code Mermaid dans l\'onglet "Code".')
				)
			)
		);
	}

	function Save() { return null; }
	registerBlockType('wp-tuto-blocks/mermaid', {edit:Edit,save:Save});
} )();
