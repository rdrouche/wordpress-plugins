( function () {
	'use strict';
	var richText    = window.wp.richText;
	var blockEditor = window.wp.blockEditor;
	var components  = window.wp.components;
	var element     = window.wp.element;
	var i18n        = window.wp.i18n;
	var registerFormatType    = richText.registerFormatType;
	var toggleFormat          = richText.toggleFormat;
	var removeFormat          = richText.removeFormat;
	var getActiveFormat       = richText.getActiveFormat;
	var RichTextToolbarButton = blockEditor.RichTextToolbarButton;
	var Popover               = components.Popover;
	var useState              = element.useState;
	var createElement         = element.createElement;
	var Fragment              = element.Fragment;
	var __                    = i18n.__;
	var FORMAT_PREFIX = 'wp-tuto-blocks/badge-';
	var BADGE_STYLES = [
		{ name: 'primary',   label: 'Primary',   bg: '#007bff', fg: '#fff'    },
		{ name: 'secondary', label: 'Secondary', bg: '#6c757d', fg: '#fff'    },
		{ name: 'success',   label: 'Succès',    bg: '#28a745', fg: '#fff'    },
		{ name: 'danger',    label: 'Danger',    bg: '#dc3545', fg: '#fff'    },
		{ name: 'warning',   label: 'Warning',   bg: '#ffc107', fg: '#212529' },
		{ name: 'info',      label: 'Info',      bg: '#17a2b8', fg: '#fff'    },
		{ name: 'dark',      label: 'Sombre',    bg: '#343a40', fg: '#fff'    },
	];
	var BadgeIcon = createElement(
		'svg', { xmlns: 'http://www.w3.org/2000/svg', viewBox: '0 0 24 24', width: '20', height: '20' },
		createElement( 'rect', { x: '1', y: '7', width: '22', height: '10', rx: '5', fill: 'none', stroke: 'currentColor', strokeWidth: '1.5' } ),
		createElement( 'text', { x: '12', y: '15.5', textAnchor: 'middle', fontSize: '7.5', fontWeight: 'bold', fill: 'currentColor', fontFamily: 'monospace' }, '42' )
	);
	function BadgeButton( props ) {
		var value = props.value; var onChange = props.onChange;
		var openState = useState( false ); var isOpen = openState[0]; var setOpen = openState[1];
		var activeFormat = null;
		BADGE_STYLES.forEach( function ( b ) { if ( getActiveFormat( value, FORMAT_PREFIX + b.name ) ) activeFormat = FORMAT_PREFIX + b.name; } );
		function applyBadge( name ) {
			var v = value;
			BADGE_STYLES.forEach( function ( b ) { v = removeFormat( v, FORMAT_PREFIX + b.name ); } );
			v = toggleFormat( v, { type: FORMAT_PREFIX + name } );
			onChange( v ); setOpen( false );
		}
		function removeBadge() {
			var v = value;
			BADGE_STYLES.forEach( function ( b ) { v = removeFormat( v, FORMAT_PREFIX + b.name ); } );
			onChange( v ); setOpen( false );
		}
		return createElement( Fragment, null,
			createElement( RichTextToolbarButton, { icon: BadgeIcon, title: __( 'Badge pill', 'wp-tuto-blocks' ), onClick: function () { setOpen( ! isOpen ); }, isActive: !! activeFormat } ),
			isOpen && createElement( Popover, { position: 'bottom center', onClose: function () { setOpen( false ); } },
				createElement( 'div', { style: { padding: '10px', minWidth: '165px' } },
					createElement( 'p', { style: { margin: '0 0 8px 0', fontSize: '11px', fontWeight: '600', textTransform: 'uppercase', color: '#757575' } }, __( 'Couleur du badge', 'wp-tuto-blocks' ) ),
					BADGE_STYLES.map( function ( badge ) {
						return createElement( 'button', { key: badge.name, onClick: function () { applyBadge( badge.name ); },
							style: { display:'flex',alignItems:'center',gap:'8px',width:'100%',padding:'5px 6px',marginBottom:'2px',
								background: activeFormat === FORMAT_PREFIX + badge.name ? '#f0f0f0':'transparent',
								border:'1px solid transparent',borderRadius:'4px',cursor:'pointer' } },
							createElement( 'span', { style: { display:'inline-block',padding:'1px 9px',borderRadius:'50px',background:badge.bg,color:badge.fg,fontSize:'11px',fontWeight:'700' } }, '42' ),
							createElement( 'span', { style: { fontSize: '13px' } }, badge.label )
						);
					} ),
					activeFormat && createElement( 'button', { onClick: removeBadge,
						style: { marginTop:'6px',width:'100%',background:'none',border:'none',
							borderTop:'1px solid #e0e0e0',color:'#cc1818',fontSize:'12px',cursor:'pointer',textAlign:'left',padding:'8px 6px 2px' } },
						__( '✕ Retirer le badge', 'wp-tuto-blocks' ) )
				)
			)
		);
	}
	BADGE_STYLES.forEach( function ( badge, index ) {
		registerFormatType( FORMAT_PREFIX + badge.name, {
			title: 'Badge ' + badge.label,
			tagName: 'span',
			className: 'rdrit-badge-pill--' + badge.name,
			edit: index === 0 ? BadgeButton : function () { return null; },
		} );
	} );
} )();
