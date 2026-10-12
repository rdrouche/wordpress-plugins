( function () {
	'use strict';
	function initSpoilers() {
		document.querySelectorAll( '.wp-tuto-spoiler' ).forEach( function ( spoiler ) {
			var btn     = spoiler.querySelector( '.wp-tuto-spoiler__header' );
			var content = spoiler.querySelector( '.wp-tuto-spoiler__content' );
			var label   = spoiler.querySelector( '.wp-tuto-spoiler__label' );
			if ( ! btn || ! content || btn.dataset.spoilerInit ) return;
			btn.dataset.spoilerInit = '1';
			var labelClosed = label ? label.dataset.labelClosed : '';
			var labelOpen   = label ? label.dataset.labelOpen   : '';
			btn.addEventListener( 'click', function () {
				var isOpen = btn.getAttribute( 'aria-expanded' ) === 'true';
				btn.setAttribute( 'aria-expanded', isOpen ? 'false' : 'true' );
				content.setAttribute( 'aria-hidden', isOpen ? 'true' : 'false' );
				content.classList.toggle( 'is-open', ! isOpen );
				if ( label ) label.textContent = isOpen ? labelClosed : labelOpen;
			} );
		} );
	}
	if ( document.readyState === 'loading' ) document.addEventListener( 'DOMContentLoaded', initSpoilers );
	else initSpoilers();
} )();
