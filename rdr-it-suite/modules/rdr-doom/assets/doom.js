/**
 * RDR Doom — Front JS  v1.1.0
 * Vanilla JS uniquement (règle R5 — zéro jQuery).
 *
 * Flux :
 *  1. Champ texte caché → l'utilisateur tape le code invincibilité
 *  2. AJAX → vérification côté serveur (nonce WP)
 *  3. Si correct → bouton "Jouer" apparaît
 *  4. Clic bouton → modale + lancement js-dos (API v8)
 *
 * Config injectée via wp_localize_script() → window.rdrDoomConfig
 */

( function () {
    'use strict';

    /* ── Config ─────────────────────────────────────────────────────────── */
    const cfg        = window.rdrDoomConfig || {};
    const AJAX_URL   = cfg.ajaxUrl   || '';
    const NONCE      = cfg.nonce     || '';
    const CHEAT_CODE = ( cfg.cheatCode || 'IDDQD' ).toUpperCase();
    const WAD_URL    = cfg.wadUrl    || '';
    const ENGINE_URL = cfg.engineUrl || 'https://v8.js-dos.com/latest/js-dos.js';

    /* ── État ────────────────────────────────────────────────────────────── */
    let dosStarted = false;

    /* ── Init ────────────────────────────────────────────────────────────── */
    document.addEventListener( 'DOMContentLoaded', init );

    function init() {
        initEasterEgg();
        initModal();
    }

    /* ══════════════════════════════════════════════════════════════════════
       EASTER EGG — saisie du code
    ══════════════════════════════════════════════════════════════════════ */

    function initEasterEgg() {
        const input   = document.getElementById( 'rdr-doom-secret-input' );
        const openBtn = document.getElementById( 'rdr-doom-open-btn' );
        const hint    = document.querySelector( '.rdr-doom-secret__hint' );

        if ( ! input || ! openBtn ) return;

        // Debounce pour éviter les appels AJAX à chaque frappe
        let debounceTimer = null;

        input.addEventListener( 'input', function () {
            clearTimeout( debounceTimer );
            const val = input.value.trim().toUpperCase();

            // Réinitialise l'état visuel
            input.classList.remove( 'is-success', 'is-error' );
            if ( hint ) hint.textContent = '';

            if ( val.length < 4 ) return;

            // Vérification locale rapide avant AJAX (UX fluide)
            if ( val === CHEAT_CODE ) {
                debounceTimer = setTimeout( function () {
                    verifyCodeAjax( input.value.trim(), openBtn, hint );
                }, 300 );
            } else if ( val.length >= CHEAT_CODE.length ) {
                // Longueur atteinte mais pas bon → feedback immédiat
                input.classList.add( 'is-error' );
                if ( hint ) hint.textContent = '✗ Code incorrect';
                if ( hint ) hint.style.color = '#e53e3e';
            }
        } );

        // Permet aussi de valider avec Entrée
        input.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Enter' ) {
                clearTimeout( debounceTimer );
                verifyCodeAjax( input.value.trim(), openBtn, hint );
            }
        } );
    }

    function verifyCodeAjax( code, openBtn, hint ) {
        const body = new URLSearchParams( {
            action:   'rdr_verify_doom_code',
            security: NONCE,
            code:     code,
        } );

        fetch( AJAX_URL, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body:    body.toString(),
        } )
        .then( function ( r ) { return r.json(); } )
        .then( function ( data ) {
            if ( data.success ) {
                onCodeValid( openBtn, hint );
            } else {
                onCodeInvalid( hint );
            }
        } )
        .catch( function ( err ) {
            console.warn( '[RDR Doom] AJAX error:', err );
        } );
    }

    function onCodeValid( openBtn, hint ) {
        const input = document.getElementById( 'rdr-doom-secret-input' );
        if ( input ) {
            input.classList.add( 'is-success' );
            input.disabled = true;
        }
        if ( hint ) {
            hint.textContent = '✓ Code accepté — God Mode activé !';
            hint.style.color = 'var(--rdr-accent-2, #00e5c0)';
        }

        // Révèle le bouton avec une petite animation
        openBtn.hidden        = false;
        openBtn.setAttribute( 'aria-hidden', 'false' );
        openBtn.classList.add( 'is-revealed' );

        // Auto-scroll vers le bouton
        setTimeout( function () {
            openBtn.scrollIntoView( { behavior: 'smooth', block: 'center' } );
            openBtn.focus();
        }, 200 );
    }

    function onCodeInvalid( hint ) {
        const input = document.getElementById( 'rdr-doom-secret-input' );
        if ( input ) input.classList.add( 'is-error' );
        if ( hint ) {
            hint.textContent = '✗ Code incorrect';
            hint.style.color = '#e53e3e';
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       MODALE
    ══════════════════════════════════════════════════════════════════════ */

    function initModal() {
        const modal   = document.getElementById( 'rdr-doom-modal' );
        const openBtn = document.getElementById( 'rdr-doom-open-btn' );
        if ( ! modal ) return;

        // Affiche le bon code dans le header modale
        const cheatEl = document.getElementById( 'rdr-doom-cheat-display' );
        if ( cheatEl ) cheatEl.textContent = CHEAT_CODE;

        // Bouton ouvrir
        if ( openBtn ) {
            openBtn.addEventListener( 'click', function () { openModal( modal ); } );
        }

        // Boutons fermer & plein écran
        modal.querySelectorAll( '[data-action="close"]' ).forEach( function ( el ) {
            el.addEventListener( 'click', function () { closeModal( modal ); } );
        } );

        const btnFs = modal.querySelector( '[data-action="fullscreen"]' );
        if ( btnFs ) {
            btnFs.addEventListener( 'click', function () { toggleFullscreen( modal ); } );
        }

        // Bouton copier code cheat
        modal.querySelectorAll( '[data-copy]' ).forEach( function ( btn ) {
            btn.addEventListener( 'click', function () {
                copyToClipboard( btn.dataset.copy, btn );
            } );
        } );

        // Échap
        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && modal.classList.contains( 'is-open' ) ) {
                closeModal( modal );
            }
        } );
    }

    function openModal( modal ) {
        modal.setAttribute( 'aria-hidden', 'false' );
        modal.classList.add( 'is-open' );
        document.body.style.overflow = 'hidden';

        if ( ! dosStarted ) {
            startDoom();
        }

        const closeBtn = modal.querySelector( '.rdr-doom-modal__close' );
        if ( closeBtn ) setTimeout( function () { closeBtn.focus(); }, 80 );
    }

    function closeModal( modal ) {
        modal.setAttribute( 'aria-hidden', 'true' );
        modal.classList.remove( 'is-open' );
        document.body.style.overflow = '';
    }

    function toggleFullscreen( modal ) {
        const panel = modal.querySelector( '.rdr-doom-modal__panel' );
        if ( ! panel ) return;
        if ( ! document.fullscreenElement ) {
            panel.requestFullscreen && panel.requestFullscreen();
        } else {
            document.exitFullscreen && document.exitFullscreen();
        }
    }

    /* ══════════════════════════════════════════════════════════════════════
       JS-DOS — détection v7 / v8 + gestion WAD brut vs bundle .jsdos
       ─────────────────────────────────────────────────────────────────────
       js-dos v8 NE PEUT PAS ouvrir un .wad brut via `url:` — il attend
       un bundle .jsdos (zip contenant dosbox.conf + le wad).
       Si l'URL se termine par .wad on passe par l'API v7 (Dos() → Promise)
       qui expose fs.extract() pour charger le WAD dans le FS virtuel.
       Si l'URL se termine par .jsdos on peut utiliser directement `url:`.
    ══════════════════════════════════════════════════════════════════════ */

    function startDoom() {
        dosStarted = true;
        setProgress( 5 );

        const mount = document.getElementById( 'rdr-doom-mount' );
        if ( ! mount ) { showError( 'Élément #rdr-doom-mount introuvable.' ); return; }

        if ( typeof window.Dos !== 'function' ) {
            showError( 'js-dos non chargé — vérifiez assets/js-dos/js-dos.js' );
            return;
        }

        // Crée le canvas
        const canvas = document.createElement( 'canvas' );
        canvas.id    = 'rdr-doom-canvas';
        mount.appendChild( canvas );

        setProgress( 15 );

        const isBundle = WAD_URL.toLowerCase().endsWith( '.jsdos' );

        if ( isBundle ) {
            // ── .jsdos : js-dos v8, url directe ──────────────────────────
            startWithBundle( canvas );
        } else {
            // ── .wad brut : js-dos v7, fetch + fs.extract ─────────────────
            startWithWad( canvas );
        }
    }

    /* ── Bundle .jsdos → js-dos v8 ──────────────────────────────────────── */
    function startWithBundle( canvas ) {
        let result;
        try {
            result = window.Dos( canvas, {
                url: WAD_URL,
                onprogress: function ( stage, loaded, total ) {
                    if ( total > 0 ) {
                        setProgress( Math.min( Math.round( ( loaded / total ) * 80 ) + 15, 95 ) );
                    }
                },
            } );
        } catch ( e ) {
            showError( 'Erreur js-dos bundle : ' + e.message );
            return;
        }

        // v8 retourne un objet (pas une Promise) — on simule la barre
        if ( result && typeof result.then !== 'function' ) {
            simulateProgress( 15, 100, 4000, hideLoader );
            return;
        }

        // v7 compat
        if ( result && typeof result.then === 'function' ) {
            result
                .then( function ( dos ) {
                    setProgress( 90 );
                    return dos.main ? dos.main( [] ) : Promise.resolve();
                } )
                .then( function () { setProgress( 100 ); setTimeout( hideLoader, 600 ); } )
                .catch( function ( e ) { showError( e.message || String( e ) ); } );
        }
    }

    /* ── WAD brut → js-dos v7, fs.extract + dosbox inline ───────────────── */
    function startWithWad( canvas ) {
        // js-dos v7 : Dos( canvas, options? ) → Promise<{ main, fs, … }>
        // On ne passe PAS d'url ici — on charge le WAD manuellement via fetch
        let dosPromise;
        try {
            dosPromise = window.Dos( canvas );
        } catch ( e ) {
            showError( 'Erreur init js-dos : ' + e.message );
            return;
        }

        if ( ! dosPromise || typeof dosPromise.then !== 'function' ) {
            showError(
                'WAD brut (.wad) nécessite js-dos v7. '
                + 'Utilisez un bundle .jsdos avec js-dos v8, '
                + 'ou remplacez js-dos.js par la version 7.'
            );
            return;
        }

        dosPromise
            .then( function ( dos ) {
                setProgress( 40 );

                // Télécharge le WAD et l'injecte dans le FS virtuel DOSBox
                return fetch( WAD_URL )
                    .then( function ( r ) {
                        if ( ! r.ok ) throw new Error( 'HTTP ' + r.status + ' — ' + WAD_URL );
                        return r.arrayBuffer();
                    } )
                    .then( function ( buf ) {
                        setProgress( 70 );
                        // Crée le fichier dans le FS virtuel
                        dos.fs.createFile( 'doom1.wad', new Uint8Array( buf ) );
                        setProgress( 85 );
                        // Crée un dosbox.conf minimal pour lancer DOOM
                        const conf = [
                            '[autoexec]',
                            'mount c .',
                            'c:',
                            'doom -iwad doom1.wad',
                        ].join( '\n' );
                        dos.fs.createFile( 'dosbox.conf', conf );
                        return dos.main( [ '-conf', 'dosbox.conf' ] );
                    } );
            } )
            .then( function () {
                setProgress( 100 );
                setTimeout( hideLoader, 600 );
            } )
            .catch( function ( err ) {
                showError( err.message || String( err ) );
            } );
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    function setProgress( pct ) {
        const bar = document.getElementById( 'rdr-doom-progress' );
        if ( bar ) bar.style.width = pct + '%';
    }

    function simulateProgress( from, to, durationMs, callback ) {
        const step  = ( to - from ) / ( durationMs / 50 );
        let   current = from;
        const id = setInterval( function () {
            current = Math.min( current + step, to );
            setProgress( Math.round( current ) );
            if ( current >= to ) {
                clearInterval( id );
                if ( typeof callback === 'function' ) callback();
            }
        }, 50 );
    }

    function hideLoader() {
        const loader = document.getElementById( 'rdr-doom-loader' );
        if ( loader ) loader.classList.add( 'is-hidden' );
    }

    function showError( msg ) {
        const loader = document.getElementById( 'rdr-doom-loader' );
        if ( ! loader ) return;
        const txt = loader.querySelector( '.rdr-doom-loader__text' );
        if ( txt ) {
            txt.style.color = '#e53e3e';
            txt.style.animation = 'none';
            txt.textContent = '⚠ ' + msg;
        }
        console.error( '[RDR Doom]', msg );
    }

    function copyToClipboard( text, btn ) {
        const doFlash = function () { flashCopied( btn ); };
        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            navigator.clipboard.writeText( text ).then( doFlash ).catch( function () {
                fallbackCopy( text, btn );
            } );
        } else {
            fallbackCopy( text, btn );
        }
    }

    function fallbackCopy( text, btn ) {
        const ta = document.createElement( 'textarea' );
        ta.value = text;
        ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
        document.body.appendChild( ta );
        ta.focus();
        ta.select();
        try { document.execCommand( 'copy' ); flashCopied( btn ); } catch ( _ ) {}
        document.body.removeChild( ta );
    }

    function flashCopied( btn ) {
        btn.classList.add( 'copied' );
        setTimeout( function () { btn.classList.remove( 'copied' ); }, 1800 );
    }

} )();
