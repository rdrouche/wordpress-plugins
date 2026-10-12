/**
 * RDR-IT Suite — Admin JS v1.1.0
 * Vanilla JS uniquement. Aucune dépendance jQuery.
 *
 * @package RDR_IT_Suite
 */

( function () {
    'use strict';

    // ── Utilitaires ─────────────────────────────────────────────────────────

    function showNotice( type, message, targetId ) {
        const container = document.getElementById( targetId || 'rdr-notices' );
        if ( ! container ) return;

        const el = document.createElement( 'div' );
        el.className = `rdr-notice rdr-notice--${ type }`;
        el.setAttribute( 'role', 'alert' );
        el.textContent = message;

        container.appendChild( el );
        setTimeout( () => el.remove(), 5000 );
    }

    function updateActiveCounter( delta ) {
        const el = document.querySelector( '.rdr-stat:first-child .rdr-stat__number' );
        if ( ! el ) return;
        el.textContent = Math.max( 0, parseInt( el.textContent, 10 ) + delta );
    }

    // ── POST helper ─────────────────────────────────────────────────────────

    function post( payload ) {
        const body = new URLSearchParams( {
            nonce: rdrSuite.nonce,
            ...payload,
        } );
        return fetch( rdrSuite.ajaxUrl, {
            method  : 'POST',
            headers : { 'Content-Type': 'application/x-www-form-urlencoded' },
            body    : body.toString(),
        } ).then( r => r.json() );
    }

    // ── Toggle module ────────────────────────────────────────────────────────

    function toggleModule( btn ) {
        const slug   = btn.dataset.slug;
        const action = btn.dataset.action;
        const card   = btn.closest( '.rdr-card' );

        btn.classList.add( 'rdr-toggle--loading' );
        btn.disabled = true;

        post( { action: 'rdr_toggle_module', slug, module_action: action } )
            .then( json => {
                if ( ! json.success ) throw new Error( json.data?.message ?? 'Error' );

                const isActive    = json.data.active;
                const settingsUrl = json.data.settings_url ?? '';

                // Badge statut
                const badge = card.querySelector( '.rdr-badge--active, .rdr-badge--inactive' );
                if ( badge ) {
                    badge.className   = `rdr-badge rdr-badge--${ isActive ? 'active' : 'inactive' }`;
                    badge.textContent = isActive ? 'Actif' : 'Inactif';
                }

                // Lien settings (apparaît si le module en a)
                let settingsLink = card.querySelector( '.rdr-card__settings-link' );
                if ( isActive && settingsUrl ) {
                    if ( ! settingsLink ) {
                        settingsLink = document.createElement( 'a' );
                        settingsLink.className = 'rdr-card__settings-link';
                        card.querySelector( '.rdr-card__footer' )?.prepend( settingsLink );
                    }
                    settingsLink.href        = settingsUrl;
                    settingsLink.textContent = '⚙ Réglages';
                } else if ( settingsLink ) {
                    settingsLink.remove();
                }

                card.classList.toggle( 'rdr-card--active', isActive );

                btn.classList.toggle( 'rdr-toggle--on', isActive );
                btn.dataset.action = isActive ? 'deactivate' : 'activate';
                btn.setAttribute( 'aria-pressed', isActive ? 'true' : 'false' );

                updateActiveCounter( isActive ? 1 : -1 );
            } )
            .catch( () => showNotice( 'error', rdrSuite.i18n.error ) )
            .finally( () => {
                btn.classList.remove( 'rdr-toggle--loading' );
                btn.disabled = false;
            } );
    }

    // ── Save module settings ─────────────────────────────────────────────────

    function saveSettings( form ) {
        const slug      = form.dataset.slug;
        const submitBtn = form.querySelector( '.rdr-btn--save' );
        const status    = form.querySelector( '.rdr-settings-status' );

        if ( submitBtn ) {
            submitBtn.disabled    = true;
            submitBtn.textContent = rdrSuite.i18n.saving;
        }

        // Sérialise tous les champs (checkboxes non cochées = absentes → on gère)
        const settings = {};
        form.querySelectorAll( '[name^="settings["]' ).forEach( el => {
            const key = el.name.match( /settings\[(.+)\]/ )?.[1];
            if ( ! key ) return;
            if ( el.type === 'checkbox' ) {
                settings[ key ] = el.checked ? '1' : '0';
            } else {
                settings[ key ] = el.value;
            }
        } );

        const payload = { action: 'rdr_save_module_settings', slug };
        Object.entries( settings ).forEach( ( [ k, v ] ) => { payload[ `settings[${ k }]` ] = v; } );

        post( payload )
            .then( json => {
                if ( ! json.success ) throw new Error();
                if ( status ) {
                    status.textContent  = rdrSuite.i18n.saved;
                    status.className    = 'rdr-settings-status rdr-settings-status--ok';
                    setTimeout( () => { status.textContent = ''; status.className = 'rdr-settings-status'; }, 4000 );
                }
            } )
            .catch( () => showNotice( 'error', rdrSuite.i18n.error ) )
            .finally( () => {
                if ( submitBtn ) {
                    submitBtn.disabled    = false;
                    submitBtn.textContent = 'Enregistrer les réglages';
                }
            } );
    }

    // ── Init ─────────────────────────────────────────────────────────────────

    function init() {
        const wrap = document.querySelector( '.rdr-wrap' );
        if ( ! wrap ) return;

        // Délégation — toggle
        wrap.addEventListener( 'click', function ( e ) {
            const btn = e.target.closest( '.rdr-toggle' );
            if ( btn && ! btn.disabled ) toggleModule( btn );
        } );

        // Délégation — form settings
        wrap.addEventListener( 'submit', function ( e ) {
            const form = e.target.closest( '.rdr-settings-form' );
            if ( form ) {
                e.preventDefault();
                saveSettings( form );
            }
        } );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }

} )();

// ── Boutons copier (page Documentation) ────────────────────────────────────
( function initCopyButtons() {
    document.addEventListener( 'click', function ( e ) {
        const btn = e.target.closest( '.rdr-copy-btn[data-target]' );
        if ( ! btn ) return;

        const targetId = btn.dataset.target;
        const pre      = document.getElementById( targetId );
        if ( ! pre ) return;

        navigator.clipboard.writeText( pre.textContent ).then( () => {
            const orig = btn.textContent;
            btn.textContent = '✓ Copié';
            btn.classList.add( 'copied' );
            setTimeout( () => {
                btn.textContent = orig;
                btn.classList.remove( 'copied' );
            }, 2000 );
        } ).catch( () => {} );
    } );
} )();
