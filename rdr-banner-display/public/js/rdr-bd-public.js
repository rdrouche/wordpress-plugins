/**
 * RDR Banner Display — Public JS  v2.5.2
 *
 * - No jQuery
 * - IntersectionObserver lazy loading
 * - Adblock detection delay with bot fast-path
 * - Bots: NO AJAX fired — skeleton kept as-is (stable dimensions, zero CLS)
 * - Impressions buffered server-side via Redis queue
 * - Clicks: sendBeacon per click
 * - no_ads users: banner space collapsed to zero height
 *
 * @package RDR_Banner_Display
 */
( function () {
    'use strict';

    const cfg = window.rdrBD || {};
    if ( ! cfg.ajaxUrl || ! cfg.nonce ) return;

    const ADBLOCK_DELAY = typeof cfg.delay === 'number' ? cfg.delay : 2500;

    // ── Bot detection (client-side) ───────────────────────────────────────────
    // If we detect a bot UA, we do NOT fire any AJAX at all.
    // The skeleton placeholder stays in place with its CSS dimensions → zero CLS.
    // Server-side detection in load_banner() is the authoritative check,
    // but for bots that execute JS (Googlebot) stopping here is better:
    // no request = no response = no DOM change = no layout shift.
    const IS_BOT = /bot|crawl|slurp|spider|mediapartners|googlebot|bingbot|yandex|baidu|duckduck|teoma|facebot|applebot|petalbot|bytespider|gptbot|anthropic|headless/i
        .test( navigator.userAgent );

    // If we are a bot, convert all skeletons to stable empty boxes immediately
    // (remove the shimmer animation — bots don't need it — but keep dimensions).
    if ( IS_BOT ) {
        document.querySelectorAll( '.rdr-bd-wrapper[data-banner-id]' ).forEach( el => {
            setBotPlaceholder( el );
        } );
        return; // ← exit entirely, no AJAX, no observer
    }

    const placeholders = document.querySelectorAll( '.rdr-bd-wrapper[data-banner-id]' );
    if ( ! placeholders.length ) return;

    // ── Adblock resolution ────────────────────────────────────────────────────
    const pendingLoad     = new Set();
    let adblockResolved   = false;
    let adblockValue      = false;

    const observer = new IntersectionObserver( entries => {
        entries.forEach( entry => {
            if ( ! entry.isIntersecting ) return;
            observer.unobserve( entry.target );
            const el = /** @type {HTMLElement} */ ( entry.target );
            if ( adblockResolved ) {
                loadBanner( el, adblockValue );
            } else {
                pendingLoad.add( el );
            }
        } );
    }, { rootMargin: '150px 0px' } );

    placeholders.forEach( el => observer.observe( el ) );

    function onAdblockResolved( isAdblock ) {
        if ( adblockResolved ) return;
        adblockResolved = true;
        adblockValue    = isAdblock;
        pendingLoad.forEach( el => loadBanner( el, isAdblock ) );
        pendingLoad.clear();
    }

    const startTime = Date.now();
    function pollAdblock() {
        if ( typeof window.isAdblockTrue === 'boolean' ) { onAdblockResolved( window.isAdblockTrue ); return; }
        if ( Date.now() - startTime >= ADBLOCK_DELAY )   { onAdblockResolved( false ); return; }
        setTimeout( pollAdblock, 100 );
    }
    pollAdblock();

    // ── Load a single banner ──────────────────────────────────────────────────
    async function loadBanner( el, adblock ) {
        const bannerId = el.dataset.bannerId;
        if ( ! bannerId ) return;

        let data;
        try {
            const body = new URLSearchParams( {
                action: 'rdr_bd_load', nonce: cfg.nonce,
                banner_id: bannerId, adblock: adblock ? '1' : '0',
            } );
            const res = await fetch( cfg.ajaxUrl, {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:    body.toString(),
            } );
            data = await res.json();
        } catch {
            setSilent( el );
            return;
        }

        if ( ! data.success ) { setSilent( el ); return; }

        const { html, is_bot, campaign_id, no_ads, width, height, item_hash } = data.data;

        // Always enforce server-side dimensions (CLS prevention)
        el.style.width  = width  + 'px';
        el.style.height = height + 'px';

        // Defensive: server says bot despite client check — stable empty box
        if ( is_bot ) { setBotPlaceholder( el ); return; }

        // Empty content with no_ads → collapse space entirely (no gap)
        if ( no_ads && ( ! html || ! html.trim() ) ) {
            collapseSpace( el );
            return;
        }

        // Empty content for any other reason (adblock_only item, caps reached, campaign ended)
        // → keep the space so layout is preserved (same as having an ad slot with no fill)
        if ( ! html || ! html.trim() ) { setSilent( el ); return; }

        // Inject content
        const inner = document.createElement( 'div' );
        inner.className = 'rdr-bd-inner';
        inner.innerHTML = html;

        el.classList.remove( 'rdr-bd-skeleton' );
        el.classList.add( 'rdr-bd-loaded' );
        el.appendChild( inner );

        // Re-execute <script> tags (needed for AdSense)
        inner.querySelectorAll( 'script' ).forEach( orig => {
            const s = document.createElement( 'script' );
            [ ...orig.attributes ].forEach( a => s.setAttribute( a.name, a.value ) );
            if ( ! orig.src ) s.textContent = orig.textContent;
            orig.replaceWith( s );
        } );

        // Record impression (buffered server-side via Redis queue)
        recordEvent( 'impression', bannerId, campaign_id, adblock, item_hash || '' );

        // Click tracking
        el.addEventListener( 'click', () => recordEvent( 'click', bannerId, campaign_id, adblock, item_hash || '' ), { passive: true, once: true } );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Bot placeholder: remove skeleton shimmer but keep exact dimensions.
     * No height change → zero CLS for Googlebot and other crawlers.
     */
    function setBotPlaceholder( el ) {
        el.classList.remove( 'rdr-bd-skeleton' );
        el.classList.add( 'rdr-bd-bot' );
        // Dimensions are already set via inline style from the shortcode PHP.
        // We don't touch width/height — layout is frozen.
    }

    /**
     * Silent empty state (adblock with no fallback, caps reached…).
     * Keeps dimensions intact — no layout shift.
     */
    function setSilent( el ) {
        el.classList.remove( 'rdr-bd-skeleton' );
        el.classList.add( 'rdr-bd-empty' );
    }

    /**
     * Collapse to zero height — only for no-ads users.
     * Animated to avoid a jarring jump.
     */
    function collapseSpace( el ) {
        el.classList.remove( 'rdr-bd-skeleton' );
        el.classList.add( 'rdr-bd-collapsed' );
        el.style.overflow   = 'hidden';
        el.style.transition = 'height 0.3s ease, margin 0.3s ease, padding 0.3s ease';
        el.style.height     = '0';
        el.style.margin     = '0';
        el.style.padding    = '0';
        el.style.minHeight  = '0';
    }

    function recordEvent( event, bannerId, campaignId, adblock, itemHash ) {
        const body = new URLSearchParams( {
            action:      'rdr_bd_stat',
            nonce:       cfg.nonce,
            banner_id:   bannerId,
            event,
            campaign_id: campaignId || 0,
            adblock:     adblock ? '1' : '0',
            item_hash:   itemHash || '',
        } );

        if ( event === 'click' && navigator.sendBeacon ) {
            navigator.sendBeacon( cfg.ajaxUrl, body );
        } else {
            fetch( cfg.ajaxUrl, {
                method:    'POST',
                headers:   { 'Content-Type': 'application/x-www-form-urlencoded' },
                body:      body.toString(),
                keepalive: true,
            } ).catch( () => {} );
        }
    }

}() );
