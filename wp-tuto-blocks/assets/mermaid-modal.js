/**
 * WP Tuto Blocks — Mermaid modal/lightbox
 * Vanilla JS, zéro dépendance.
 * Chargé uniquement si un bloc Mermaid avec enableModal=true est présent.
 */
( function () {
	'use strict';

	var modal      = null;
	var modalSvg   = null;
	var modalClose = null;

	function buildModal() {
		if ( modal ) return;

		modal = document.createElement( 'div' );
		modal.className = 'rdrit-mermaid-modal';
		modal.setAttribute( 'role', 'dialog' );
		modal.setAttribute( 'aria-modal', 'true' );
		modal.setAttribute( 'aria-label', 'Diagramme agrandi' );

		modalClose = document.createElement( 'button' );
		modalClose.className   = 'rdrit-mermaid-modal__close';
		modalClose.textContent = '✕';
		modalClose.setAttribute( 'aria-label', 'Fermer' );

		var modalBody = document.createElement( 'div' );
		modalBody.className = 'rdrit-mermaid-modal__body';

		modalSvg = document.createElement( 'div' );
		modalSvg.className = 'rdrit-mermaid-modal__svg';

		modalBody.appendChild( modalSvg );
		modal.appendChild( modalClose );
		modal.appendChild( modalBody );
		document.body.appendChild( modal );

		modalClose.addEventListener( 'click', closeModal );
		modal.addEventListener( 'click', function ( e ) {
			if ( e.target === modal ) closeModal();
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) closeModal();
		} );
	}

	function openModal( svgContent ) {
		buildModal();
		modalSvg.innerHTML = svgContent;
		var svg = modalSvg.querySelector( 'svg' );
		if ( svg ) {
			svg.removeAttribute( 'width' );
			svg.removeAttribute( 'height' );
			svg.style.width    = '100%';
			svg.style.height   = '100%';
			svg.style.maxWidth = 'none';
		}
		modal.classList.add( 'is-open' );
		document.body.classList.add( 'rdrit-modal-open' );
		modalClose.focus();
	}

	function closeModal() {
		if ( ! modal ) return;
		modal.classList.remove( 'is-open' );
		document.body.classList.remove( 'rdrit-modal-open' );
	}

	/* ── Init sur un bloc spécifique (appelé par le renderer async) ── */
	function rdritMermaidModalInit( block ) {
		if ( ! block || block.dataset.zoomInit ) return;
		if ( block.getAttribute( 'data-enable-modal' ) !== 'true' ) return;

		block.dataset.zoomInit = '1';

		var btn    = block.querySelector( '.wp-tuto-mermaid__zoom-btn' );
		var inner  = block.querySelector( '.wp-tuto-mermaid__inner' );

		function tryOpen() {
			var svg = block.querySelector( '.wp-tuto-mermaid__diagram svg' );
			if ( svg ) {
				openModal( svg.outerHTML );
			}
		}

		if ( btn )   btn.addEventListener( 'click', tryOpen );
		if ( inner ) inner.addEventListener( 'click', function ( e ) {
			if ( e.target !== btn ) tryOpen();
		} );
	}

	/* Exposé globalement pour être appelé depuis le renderer mermaid */
	window.rdritMermaidModalInit = rdritMermaidModalInit;

	/* Fallback : init sur tous les blocs déjà rendus au chargement */
	function initAll() {
		document.querySelectorAll( '.wp-tuto-mermaid--zoomable.is-rendered' ).forEach( function ( block ) {
			rdritMermaidModalInit( block.closest( '.wp-tuto-mermaid' ) );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAll );
	} else {
		initAll();
	}
} )();
