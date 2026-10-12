/**
 * WP IT Problem — admin.js
 *
 * Minimal admin enhancements.
 * No jQuery. Loaded only on it_problem / it_answer edit screens.
 *
 * Features:
 *  1. Confirm dialog before "Create and link answer" links
 *     (prevents accidental double-clicks)
 *  2. Reveal date meta box: highlight when date is in the past
 *  3. Auto-set reveal date to "now + N days" shortcut buttons
 *
 * @package WP_IT_Problem
 * @since   1.0.0
 */

( function () {
    'use strict';

    document.addEventListener( 'DOMContentLoaded', function () {
        initRevealDateBox();
        initCreateAnswerConfirm();
    } );

    // -------------------------------------------------------------------------
    // 1. Reveal date box enhancements
    // -------------------------------------------------------------------------

    function initRevealDateBox() {
        const input = document.getElementById( 'itpb_reveal_at' );
        if ( ! input ) return;

        // Visual feedback: past date = warning style
        function checkDate() {
            const val = input.value;
            if ( ! val ) return;

            const selected = new Date( val ).getTime();
            const now      = Date.now();

            if ( selected < now ) {
                input.style.borderColor  = '#d63638';
                input.style.boxShadow    = '0 0 0 1px #d63638';
                input.title              = 'Warning: this date is in the past. The answer will be revealed immediately.';
            } else {
                input.style.borderColor  = '';
                input.style.boxShadow    = '';
                input.title              = '';
            }
        }

        input.addEventListener( 'change', checkDate );
        checkDate(); // run on load

        // --- Shortcut buttons (+1d, +7d, +30d) ---
        const shortcuts = [
            { label: '+1 day',   days: 1  },
            { label: '+7 days',  days: 7  },
            { label: '+30 days', days: 30 },
        ];

        const wrap = document.createElement( 'p' );
        wrap.style.marginTop = '6px';
        wrap.style.display   = 'flex';
        wrap.style.gap       = '6px';
        wrap.style.flexWrap  = 'wrap';

        shortcuts.forEach( function ( sc ) {
            const btn = document.createElement( 'button' );
            btn.type      = 'button';
            btn.textContent = sc.label;
            btn.className   = 'button button-small';
            btn.style.fontSize = '11px';

            btn.addEventListener( 'click', function () {
                const base   = input.value
                    ? new Date( input.value )
                    : new Date();

                base.setDate( base.getDate() + sc.days );

                // Format as datetime-local value: YYYY-MM-DDTHH:mm
                const pad    = n => String( n ).padStart( 2, '0' );
                const y      = base.getFullYear();
                const mo     = pad( base.getMonth() + 1 );
                const d      = pad( base.getDate() );
                const h      = pad( base.getHours() );
                const mi     = pad( base.getMinutes() );

                input.value = y + '-' + mo + '-' + d + 'T' + h + ':' + mi;
                input.dispatchEvent( new Event( 'change' ) );
            } );

            wrap.appendChild( btn );
        } );

        input.parentNode.appendChild( wrap );
    }

    // -------------------------------------------------------------------------
    // 2. Confirm before "Create answer" action links
    // -------------------------------------------------------------------------

    function initCreateAnswerConfirm() {
        // Catch all links that contain itpb_create_answer in the href
        document.querySelectorAll( 'a[href*="itpb_create_answer"]' ).forEach( function ( link ) {
            link.addEventListener( 'click', function ( e ) {
                const ok = window.confirm(
                    'Create and link an answer post to this problem?\n\nYou will be redirected to the answer editor.'
                );
                if ( ! ok ) {
                    e.preventDefault();
                }
            } );
        } );
    }

} )();
