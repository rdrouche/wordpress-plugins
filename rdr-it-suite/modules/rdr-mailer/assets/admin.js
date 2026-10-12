/**
 * RDR-IT Suite — Module Mailer — Admin JS
 * Vanilla JS (R5 — zéro jQuery).
 *
 * Pattern identique au source settings-page.php :
 *   - save(key, value, nonce) → wp_ajax_rdrit_save_option
 *   - Checkboxes  : change → save immédiat
 *   - Selects     : change → save immédiat + toggle sections
 *   - Text/Number : input  → save avec debounce 500ms
 *   - Mot de passe SMTP     : blur → btoa → save (vide = conservé)
 *   - Clé secrète Mailjet   : blur → btoa → save (vide = conservée)
 *   - Test d'envoi          : AJAX rdrit_mail_test
 */

( function () {
    'use strict';

    const ajaxUrl = RDR_MAILER.ajaxUrl;
    const nonce   = RDR_MAILER.nonce;

    /* ── Notification save (flash discret) ───────────────────────────── */
    function showNotice() {
        let el = document.getElementById( 'rdrm-save-notice' );
        if ( ! el ) {
            el = document.createElement( 'span' );
            el.id = 'rdrm-save-notice';
            el.style.cssText = 'position:fixed;bottom:20px;right:20px;background:#16a34a;color:#fff;'
                + 'padding:7px 16px;border-radius:6px;font-size:13px;font-weight:600;'
                + 'z-index:99999;opacity:0;transition:opacity .2s';
            el.textContent = '✓ Enregistré';
            document.body.appendChild( el );
        }
        el.style.opacity = '1';
        clearTimeout( el.__t );
        el.__t = setTimeout( () => { el.style.opacity = '0'; }, 2000 );
    }

    /* ── save() — identique au source ────────────────────────────────── */
    function save( key, value, n ) {
        return fetch( ajaxUrl, {
            method  : 'POST',
            headers : { 'Content-Type': 'application/x-www-form-urlencoded' },
            body    : new URLSearchParams( {
                action     : 'rdrit_save_option',
                nonce      : n || nonce,
                option_key : key,
                value      : value,
            } ),
        } )
        .then( r => r.json() )
        .then( d => { if ( d.success ) showNotice(); } );
    }

    /* ── Selects ─────────────────────────────────────────────────────── */
    document.querySelectorAll( '.rdrm-saveable-select' ).forEach( el => {
        el.addEventListener( 'change', function () {
            save( this.dataset.key, this.value, this.dataset.nonce );

            // Toggle sections SMTP / Mailjet selon le mode
            if ( this.dataset.key === 'rdrit_mail_mode' ) {
                toggleMailSections( this.value );
            }
        } );
    } );

    /* ── Checkboxes ──────────────────────────────────────────────────── */
    document.querySelectorAll( '.rdrm-saveable-check' ).forEach( el => {
        el.addEventListener( 'change', function () {
            save( this.dataset.key, this.checked ? 1 : 0, this.dataset.nonce );

            // Toggle champs auth SMTP
            if ( this.dataset.key === 'rdrit_smtp_auth' ) {
                const f = document.querySelector( '.rdrm-smtp-auth-fields' );
                if ( f ) f.style.display = this.checked ? '' : 'none';
            }
        } );
    } );

    /* ── Text inputs (debounce 500ms) ────────────────────────────────── */
    document.querySelectorAll( '.rdrm-saveable-text' ).forEach( el => {
        let t;
        el.addEventListener( 'input', function () {
            clearTimeout( t );
            const key = this.dataset.key, val = this.value, n = this.dataset.nonce;
            t = setTimeout( () => save( key, val, n ), 500 );
        } );
    } );

    /* ── Number inputs (debounce 400ms) ──────────────────────────────── */
    document.querySelectorAll( '.rdrm-saveable-number' ).forEach( el => {
        let t;
        el.addEventListener( 'input', function () {
            clearTimeout( t );
            const key = this.dataset.key, val = this.value, n = this.dataset.nonce;
            t = setTimeout( () => save( key, val, n ), 400 );
        } );
    } );

    /* ── Mot de passe SMTP — btoa sur blur, vide = conservé ─────────── */
    document.querySelectorAll( '.rdrm-smtp-pass' ).forEach( el => {
        el.addEventListener( 'blur', function () {
            if ( ! this.value ) return;
            const encoded = btoa( this.value );
            save( this.dataset.key, encoded, this.dataset.nonce );
            this.value = '';
            this.placeholder = '•••••••• (modifié)';
        } );
    } );

    /* ── Clé secrète Mailjet — btoa + encodeURIComponent sur blur ────── */
    document.querySelectorAll( '.rdrm-mj-secret' ).forEach( el => {
        el.addEventListener( 'blur', function () {
            if ( ! this.value ) return;
            const encoded = btoa( unescape( encodeURIComponent( this.value ) ) );
            save( this.dataset.key, encoded, this.dataset.nonce );
            this.value = '';
            this.placeholder = '•••••••• (modifiée)';
        } );
    } );

    /* ── Toggle sections SMTP / Mailjet ──────────────────────────────── */
    function toggleMailSections( val ) {
        const smtp    = document.querySelector( '.rdrm-smtp-section' );
        const mailjet = document.querySelector( '.rdrm-mailjet-section' );
        if ( smtp )    smtp.style.display    = val === 'smtp'    ? '' : 'none';
        if ( mailjet ) mailjet.style.display = val === 'mailjet' ? '' : 'none';
    }

    /* ── Test d'envoi ────────────────────────────────────────────────── */
    const testBtn    = document.getElementById( 'rdrm-test-btn' );
    const testToEl   = document.getElementById( 'rdrm-test-to' );
    const testResult = document.getElementById( 'rdrm-test-result' );

    if ( testBtn && testResult ) {
        testBtn.addEventListener( 'click', function () {
            const to = testToEl ? testToEl.value.trim() : '';
            if ( ! to ) {
                testResult.innerHTML = '<span style="color:#dc2626">Adresse email requise.</span>';
                return;
            }

            testBtn.disabled    = true;
            testBtn.textContent = '⏳ Envoi…';
            testResult.innerHTML = '';

            fetch( ajaxUrl, {
                method  : 'POST',
                headers : { 'Content-Type': 'application/x-www-form-urlencoded' },
                body    : new URLSearchParams( {
                    action : 'rdrit_mail_test',
                    nonce  : testBtn.dataset.nonce || nonce,
                    to     : to,
                } ),
            } )
            .then( r => r.json() )
            .then( data => {
                testBtn.disabled    = false;
                testBtn.textContent = '✉️ Envoyer un mail test';
                testResult.innerHTML = data.success
                    ? '<span style="color:#16a34a">✅ ' + ( data.data.msg || 'Envoyé' ) + '</span>'
                    : '<span style="color:#dc2626">❌ ' + ( data.data || 'Erreur inconnue' ) + '</span>';
            } )
            .catch( () => {
                testBtn.disabled    = false;
                testBtn.textContent = '✉️ Envoyer un mail test';
                testResult.innerHTML = '<span style="color:#dc2626">❌ Erreur réseau</span>';
            } );
        } );
    }

} )();
