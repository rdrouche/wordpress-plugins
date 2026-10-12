( function () {
	'use strict';

	function isWidgetEditor() {
		if ( window.pagenow === 'widgets' ) return true;
		if ( window.location && window.location.pathname ) {
			var p = window.location.pathname;
			if ( p.indexOf('widgets.php') !== -1 || p.indexOf('customize.php') !== -1 ) return true;
		}
		return false;
	}

	var IS_WIDGET_EDITOR = isWidgetEditor();

	var hooks       = window.wp.hooks;
	var blockEditor = window.wp.blockEditor;
	var components  = window.wp.components;
	var element     = window.wp.element;
	var compose     = window.wp.compose;
	var i18n        = window.wp.i18n;
	var addFilter   = hooks.addFilter;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody     = components.PanelBody;
	var SelectControl = components.SelectControl;
	var CheckboxControl = components.CheckboxControl;
	var TextControl   = components.TextControl;
	var Button        = components.Button;
	var createElement = element.createElement;
	var Fragment      = element.Fragment;
	var useState      = element.useState;
	var createHigherOrderComponent = compose.createHigherOrderComponent;
	var __            = i18n.__;

	var WP_ROLES = [
		{ value: 'administrator', label: 'Administrateur' },
		{ value: 'editor',        label: 'Éditeur'        },
		{ value: 'author',        label: 'Auteur'         },
		{ value: 'contributor',   label: 'Contributeur'   },
		{ value: 'subscriber',    label: 'Abonné'         },
	];
	var CAP_SUGGESTIONS = [ 'manage_options','edit_posts','edit_others_posts','publish_posts','read','upload_files','manage_categories' ];
	var DEVICES = [
		{ value: 'mobile',  label: 'Mobile'   },
		{ value: 'tablet',  label: 'Tablette' },
		{ value: 'desktop', label: 'Desktop'  },
	];
	var EXCLUDED_BLOCKS = [ 'core/html', 'core/freeform' ];

	function addVisibilityAttributes( settings, name ) {
		if ( IS_WIDGET_EDITOR || EXCLUDED_BLOCKS.indexOf( name ) !== -1 ) return settings;
		return Object.assign( {}, settings, {
			attributes: Object.assign( {}, settings.attributes, {
				rdritVisibilityLogin:        { type: 'string',  default: 'all' },
				rdritVisibilityRoles:        { type: 'array',   default: [], items: { type: 'string' } },
				rdritVisibilityCapabilities: { type: 'array',   default: [], items: { type: 'string' } },
				rdritVisibilityHideOn:       { type: 'array',   default: [], items: { type: 'string' } },
			} ),
		} );
	}
	addFilter( 'blocks.registerBlockType', 'wp-tuto-blocks/visibility-attributes', addVisibilityAttributes );

	function VisibilitySummary( props ) {
		var parts = [];
		if ( props.login === 'logged_in' )  parts.push( '🔒 Connectés uniquement' );
		if ( props.login === 'logged_out' ) parts.push( '👤 Non connectés uniquement' );
		if ( props.roles.length )  parts.push( '🎭 Rôles : ' + props.roles.join(', ') );
		if ( props.caps.length )   parts.push( '🔑 Capacités : ' + props.caps.join(', ') );
		if ( props.hideOn.length ) parts.push( '📱 Masqué sur : ' + props.hideOn.join(', ') );
		if ( ! parts.length ) return null;
		return createElement( 'div', { style: { margin:'0 0 8px',padding:'6px 10px',background:'#fff3cd',border:'1px solid #ffc107',borderRadius:'4px',fontSize:'11px',lineHeight:'1.6',color:'#6d4c00' } },
			parts.map( function(p,i) { return createElement('div',{key:i},p); } )
		);
	}

	var withVisibilityControls = createHigherOrderComponent( function( BlockEdit ) {
		return function( props ) {
			if ( IS_WIDGET_EDITOR || EXCLUDED_BLOCKS.indexOf( props.name ) !== -1 ) return createElement( BlockEdit, props );
			var attrs = props.attributes; var setAttributes = props.setAttributes;
			var login  = attrs.rdritVisibilityLogin        || 'all';
			var roles  = attrs.rdritVisibilityRoles        || [];
			var caps   = attrs.rdritVisibilityCapabilities || [];
			var hideOn = attrs.rdritVisibilityHideOn       || [];
			var capInputState = useState(''); var capInput = capInputState[0]; var setCapInput = capInputState[1];
			function toggle(arr, val) { return arr.indexOf(val)===-1 ? arr.concat([val]) : arr.filter(function(v){return v!==val;}); }
			function addCap(cap) {
				cap = cap.trim().toLowerCase().replace(/[^a-z0-9_]/g,'');
				if ( cap && caps.indexOf(cap)===-1 ) setAttributes({rdritVisibilityCapabilities:caps.concat([cap])});
				setCapInput('');
			}
			var hasConditions = login !== 'all' || roles.length || caps.length || hideOn.length;
			return createElement( Fragment, null,
				createElement( BlockEdit, props ),
				createElement( InspectorControls, null,
					createElement( PanelBody, { title: hasConditions ? '👁 Visibilité ●' : '👁 Visibilité', initialOpen: false },
						createElement( VisibilitySummary, { login:login, roles:roles, caps:caps, hideOn:hideOn } ),
						createElement('p',{style:{fontSize:'11px',fontWeight:'600',textTransform:'uppercase',color:'#757575',margin:'0 0 4px'}},'Statut de connexion'),
						createElement( SelectControl, { value:login, options:[
							{value:'all',       label:'Tout le monde'},
							{value:'logged_in', label:'Connectés uniquement'},
							{value:'logged_out',label:'Non connectés uniquement'},
						], onChange: function(v){setAttributes({rdritVisibilityLogin:v});} } ),
						createElement('p',{style:{fontSize:'11px',fontWeight:'600',textTransform:'uppercase',color:'#757575',margin:'12px 0 4px'}},'Rôles autorisés'),
						createElement('p',{style:{fontSize:'11px',color:'#999',margin:'0 0 6px'}},'Vide = tous les rôles. Plusieurs rôles = OU.'),
						WP_ROLES.map(function(role){
							return createElement(CheckboxControl,{key:role.value,label:role.label,checked:roles.indexOf(role.value)!==-1,
								onChange:function(){setAttributes({rdritVisibilityRoles:toggle(roles,role.value)});}});
						}),
						createElement('p',{style:{fontSize:'11px',fontWeight:'600',textTransform:'uppercase',color:'#757575',margin:'12px 0 4px'}},'Capacités requises'),
						createElement('p',{style:{fontSize:'11px',color:'#999',margin:'0 0 6px'}},'Plusieurs capacités = ET (toutes requises).'),
						caps.length ? createElement('div',{style:{display:'flex',flexWrap:'wrap',gap:'4px',marginBottom:'6px'}},
							caps.map(function(cap){
								return createElement('span',{key:cap,style:{display:'inline-flex',alignItems:'center',gap:'4px',padding:'2px 8px',background:'#e0e7ff',borderRadius:'50px',fontSize:'11px',color:'#3730a3'}},
									cap,
									createElement('button',{onClick:function(){setAttributes({rdritVisibilityCapabilities:caps.filter(function(c){return c!==cap;})});},
										style:{background:'none',border:'none',cursor:'pointer',color:'#3730a3',padding:'0',lineHeight:'1',fontSize:'13px'}},'×')
								);
							})
						) : null,
						createElement('div',{style:{display:'flex',flexWrap:'wrap',gap:'3px',marginBottom:'6px'}},
							CAP_SUGGESTIONS.filter(function(c){return caps.indexOf(c)===-1;}).map(function(cap){
								return createElement('button',{key:cap,onClick:function(){addCap(cap);},
									style:{padding:'2px 7px',background:'#f3f4f6',border:'1px solid #d1d5db',borderRadius:'50px',fontSize:'10px',cursor:'pointer',color:'#374151'}},
									'+ '+cap);
							})
						),
						createElement('div',{style:{display:'flex',gap:'4px'}},
							createElement(TextControl,{placeholder:'capacité personnalisée…',value:capInput,onChange:setCapInput,
								onKeyDown:function(e){if(e.key==='Enter'){e.preventDefault();addCap(capInput);}},style:{flex:'1',margin:'0'}}),
							createElement(Button,{variant:'secondary',onClick:function(){addCap(capInput);}},__('Ajouter','wp-tuto-blocks'))
						),
						createElement('p',{style:{fontSize:'11px',fontWeight:'600',textTransform:'uppercase',color:'#757575',margin:'12px 0 4px'}},'Masquer sur'),
						createElement('p',{style:{fontSize:'11px',color:'#999',margin:'0 0 6px'}},'Via CSS display:none — le HTML reste présent.'),
						DEVICES.map(function(device){
							return createElement(CheckboxControl,{key:device.value,label:device.label,checked:hideOn.indexOf(device.value)!==-1,
								onChange:function(){setAttributes({rdritVisibilityHideOn:toggle(hideOn,device.value)});}});
						}),
						hasConditions ? createElement('div',{style:{marginTop:'12px',borderTop:'1px solid #e5e7eb',paddingTop:'10px'}},
							createElement(Button,{variant:'tertiary',isDestructive:true,onClick:function(){
								setAttributes({rdritVisibilityLogin:'all',rdritVisibilityRoles:[],rdritVisibilityCapabilities:[],rdritVisibilityHideOn:[]});
							}}, '✕ Réinitialiser la visibilité')
						) : null
					)
				)
			);
		};
	}, 'withVisibilityControls' );
	addFilter( 'editor.BlockEdit', 'wp-tuto-blocks/visibility-controls', withVisibilityControls );
	addFilter( 'blocks.getSaveContent.extraProps', 'wp-tuto-blocks/visibility-save-props', function(p){return p;} );
} )();
