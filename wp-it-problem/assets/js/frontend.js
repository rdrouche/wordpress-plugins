/**
 * WP IT Problem — frontend.js
 *
 * Handles:
 *  1. Countdown timer (updates every second)
 *  2. REST poll near reveal time (every 10s in last 5min, every 30s before)
 *  3. AJAX answer injection when revealed
 *  4. Custom event dispatch for syntax highlighting re-run
 *
 * Dependencies: none (vanilla JS, ES2020)
 * Loaded: footer, only on it_problem pages with a pending answer
 * Data:   window.itpbData (injected via wp_localize_script)
 *
 * @package WP_IT_Problem
 * @since   1.0.0
 */

( function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Guards
    // -------------------------------------------------------------------------

    if ( typeof window.itpbData === 'undefined' ) {
        return;
    }

    const cfg = window.itpbData;

    // Required fields
    if ( ! cfg.problemId || ! cfg.revealAt || ! cfg.restUrl ) {
        return;
    }

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    const state = {
        problemId   : parseInt( cfg.problemId, 10 ),
        revealAt    : parseInt( cfg.revealAt,  10 ),   // Unix timestamp UTC
        restUrl     : cfg.restUrl,
        nonce       : cfg.nonce   || '',
        labels      : cfg.labels  || {},
        revealed    : false,
        fetching    : false,    // prevents concurrent answer fetches
        pollTimer   : null,     // setInterval handle for REST poll
        tickTimer   : null,     // setInterval handle for countdown tick
    };

    // -------------------------------------------------------------------------
    // DOM references (resolved once on DOMContentLoaded)
    // -------------------------------------------------------------------------

    let elCountdown  = null;   // #itpb-countdown
    let elAnswerZone = null;   // #itpb-answer-zone

    // Unit value spans (inside the main countdown — null on standalone widgets)
    let elDays    = null;
    let elHours   = null;
    let elMinutes = null;
    let elSeconds = null;

    // Standalone countdown widgets (may be > 1 on the same page)
    let standalones = [];

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    document.addEventListener( 'DOMContentLoaded', boot );

    function boot() {
        elCountdown  = document.getElementById( 'itpb-countdown' );
        elAnswerZone = document.getElementById( 'itpb-answer-zone' );

        // Standalone widgets (shortcode [itpb_countdown] outside single page)
        standalones = Array.from(
            document.querySelectorAll( '[data-standalone="true"][data-problem-id="' + state.problemId + '"]' )
        );

        if ( ! elCountdown && standalones.length === 0 ) {
            return;
        }

        // Resolve unit spans for the main countdown
        if ( elCountdown ) {
            elDays    = elCountdown.querySelector( '#itpb-unit-days .itpb-unit-value' );
            elHours   = elCountdown.querySelector( '#itpb-unit-hours .itpb-unit-value' );
            elMinutes = elCountdown.querySelector( '#itpb-unit-minutes .itpb-unit-value' );
            elSeconds = elCountdown.querySelector( '#itpb-unit-seconds .itpb-unit-value' );
        }

        const now     = Math.floor( Date.now() / 1000 );
        const diff    = state.revealAt - now;

        if ( diff <= 0 ) {
            // Already past reveal time on page load — poll immediately
            startPoll( true );
        } else {
            startTick();
            startPoll( false );
        }
    }

    // -------------------------------------------------------------------------
    // Countdown tick (setInterval 1s)
    // -------------------------------------------------------------------------

    function startTick() {
        tick(); // immediate first render
        state.tickTimer = setInterval( tick, 1000 );
    }

    function stopTick() {
        if ( state.tickTimer !== null ) {
            clearInterval( state.tickTimer );
            state.tickTimer = null;
        }
    }

    function tick() {
        const now  = Math.floor( Date.now() / 1000 );
        const diff = state.revealAt - now;

        if ( diff <= 0 ) {
            // Countdown finished — stop ticking, let poll handle the rest
            renderUnits( 0, 0, 0, 0 );
            stopTick();
            return;
        }

        const d = Math.floor( diff / 86400 );
        const h = Math.floor( ( diff % 86400 ) / 3600 );
        const m = Math.floor( ( diff % 3600  ) / 60 );
        const s = diff % 60;

        renderUnits( d, h, m, s );
        renderStandalones( d, h, m, s );
    }

    function renderUnits( d, h, m, s ) {
        if ( ! elCountdown ) return;
        if ( elDays    ) elDays.textContent    = d;
        if ( elHours   ) elHours.textContent   = pad( h );
        if ( elMinutes ) elMinutes.textContent  = pad( m );
        if ( elSeconds ) elSeconds.textContent  = pad( s );
    }

    function renderStandalones( d, h, m, s ) {
        standalones.forEach( function ( el ) {
            const vals = el.querySelectorAll( '.itpb-unit-value' );
            if ( vals.length === 4 ) {
                vals[0].textContent = d;
                vals[1].textContent = pad( h );
                vals[2].textContent = pad( m );
                vals[3].textContent = pad( s );
            }
        } );
    }

    function pad( n ) {
        return String( n ).padStart( 2, '0' );
    }

    // -------------------------------------------------------------------------
    // REST poll (adaptive interval)
    // -------------------------------------------------------------------------

    /**
     * Start polling the status endpoint.
     *
     * Interval strategy:
     *  - time_left > 5 min  → poll every 30s (low frequency, save requests)
     *  - time_left <= 5 min → poll every 10s (high frequency, fast reveal)
     *  - immediate = true   → poll now then decide interval
     */
    function startPoll( immediate ) {
        if ( immediate ) {
            pollStatus();
        }

        schedulePoll();
    }

    function schedulePoll() {
        stopPoll();

        const now      = Math.floor( Date.now() / 1000 );
        const timeLeft = Math.max( 0, state.revealAt - now );
        const interval = timeLeft <= 300 ? 10000 : 30000;  // ms

        state.pollTimer = setInterval( pollStatus, interval );
    }

    function stopPoll() {
        if ( state.pollTimer !== null ) {
            clearInterval( state.pollTimer );
            state.pollTimer = null;
        }
    }

    function pollStatus() {
        if ( state.revealed ) {
            stopPoll();
            return;
        }

        const url = state.restUrl + 'status/' + state.problemId;

        fetch( url, {
            method  : 'GET',
            headers : {
                'X-WP-Nonce' : state.nonce,
            },
            // No credentials — public endpoint, avoid CORS preflight overhead
        } )
        .then( function ( res ) {
            if ( ! res.ok ) {
                return null;
            }
            return res.json();
        } )
        .then( function ( data ) {
            if ( ! data ) return;

            if ( data.revealed ) {
                onRevealed();
            } else {
                // Re-schedule with updated interval based on new time_left
                const timeLeft = data.time_left || 0;
                const now      = Math.floor( Date.now() / 1000 );
                // Sync state.revealAt in case server time differs slightly
                if ( timeLeft > 0 ) {
                    state.revealAt = now + timeLeft;
                }
                schedulePoll();
            }
        } )
        .catch( function () {
            // Network error — retry on current schedule, no action
        } );
    }

    // -------------------------------------------------------------------------
    // Reveal sequence
    // -------------------------------------------------------------------------

    /**
     * Called when the status endpoint confirms revealed: true.
     * 1. Stop tick + poll
     * 2. Show loading state
     * 3. Fetch answer HTML
     * 4. Inject + dispatch event
     */
    function onRevealed() {
        if ( state.revealed || state.fetching ) return;

        state.revealed = true;
        stopTick();
        stopPoll();

        showLoadingState();
        fetchAnswer();
    }

    function showLoadingState() {
        // Replace countdown with a loading message
        if ( elCountdown ) {
            const units = elCountdown.querySelector( '.itpb-countdown-units' );
            if ( units ) {
                units.innerHTML = '<p class="itpb-loading">' +
                    escapeHtml( state.labels.loading || 'Loading answer\u2026' ) +
                    '</p>';
            }
        }

        // Make the answer zone visible and accessible
        if ( elAnswerZone ) {
            elAnswerZone.removeAttribute( 'aria-hidden' );
            elAnswerZone.classList.remove( 'itpb-answer-hidden' );
        }

        // Update standalone widgets
        standalones.forEach( function ( el ) {
            const units = el.querySelector( '.itpb-countdown-units' );
            if ( units ) {
                units.innerHTML = '<p class="itpb-revealed-notice">' +
                    escapeHtml( state.labels.revealed || 'The answer is now available.' ) +
                    '</p>';
            }
        } );
    }

    function fetchAnswer() {
        if ( state.fetching ) return;
        state.fetching = true;

        const url = state.restUrl + 'answer/' + state.problemId;

        fetch( url, {
            method  : 'GET',
            headers : {
                'X-WP-Nonce' : state.nonce,
            },
        } )
        .then( function ( res ) {
            if ( ! res.ok ) {
                throw new Error( 'HTTP ' + res.status );
            }
            return res.json();
        } )
        .then( function ( data ) {
            state.fetching = false;

            if ( data && data.revealed && data.html ) {
                injectAnswer( data.html );
            } else if ( data && ! data.revealed ) {
                // Edge case: status said revealed but answer not ready yet
                // (double cron lag) — retry once after 5s
                setTimeout( function () {
                    state.revealed = false;
                    state.fetching = false;
                    pollStatus();
                }, 5000 );
            }
        } )
        .catch( function () {
            state.fetching = false;
            // Show a fallback refresh prompt
            if ( elAnswerZone ) {
                elAnswerZone.innerHTML =
                    '<p class="itpb-cron-lag-msg">' +
                    escapeHtml( 'The answer is ready. Please refresh the page.' ) +
                    '</p>';
            }
        } );
    }

    // -------------------------------------------------------------------------
    // Answer injection
    // -------------------------------------------------------------------------

    function injectAnswer( html ) {
        // Hide + remove countdown
        if ( elCountdown ) {
            elCountdown.style.transition = 'opacity 0.3s ease';
            elCountdown.style.opacity    = '0';
            setTimeout( function () {
                elCountdown.remove();
            }, 320 );
        }

        if ( ! elAnswerZone ) return;

        // Inject HTML
        elAnswerZone.innerHTML = html;
        elAnswerZone.classList.add( 'itpb-answer-wrap' );
        elAnswerZone.removeAttribute( 'aria-hidden' );

        // Animate in
        elAnswerZone.style.opacity    = '0';
        elAnswerZone.style.transition = 'opacity 0.4s ease';
        requestAnimationFrame( function () {
            requestAnimationFrame( function () {
                elAnswerZone.style.opacity = '1';
            } );
        } );

        // Scroll to the answer zone (smooth, not jarring)
        setTimeout( function () {
            elAnswerZone.scrollIntoView( { behavior: 'smooth', block: 'start' } );
        }, 400 );

        // Dispatch custom event so the syntax highlighter plugin can re-run
        // on the newly injected nodes
        document.dispatchEvent( new CustomEvent( 'itpb:revealed', {
            bubbles : true,
            detail  : {
                node      : elAnswerZone,
                problemId : state.problemId,
            },
        } ) );
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    function escapeHtml( str ) {
        return String( str )
            .replace( /&/g,  '&amp;'  )
            .replace( /</g,  '&lt;'   )
            .replace( />/g,  '&gt;'   )
            .replace( /"/g,  '&quot;' )
            .replace( /'/g,  '&#039;' );
    }

} )();
