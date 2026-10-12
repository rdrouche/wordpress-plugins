<?php
/**
 * RDR Doom — Page réglages admin
 *
 * @package RDR\Modules\RdrDoom
 */

declare( strict_types=1 );

$wad_url     = \RDR_Module_Settings::get( 'rdr-doom', 'wad_url',     '' );
$cheat_code  = \RDR_Module_Settings::get( 'rdr-doom', 'cheat_code',  'IDDQD' );
$modal_title = \RDR_Module_Settings::get( 'rdr-doom', 'modal_title', 'DOOM' );
?>

<div class="rdr-settings-group">
    <div class="rdr-settings-group__title">Fichier WAD / Bundle</div>

    <div class="rdr-field">
        <label class="rdr-field__label" for="rdr_doom_wad_url">URL du fichier .wad ou .jsdos</label>
        <input type="url"
               id="rdr_doom_wad_url"
               name="wad_url"
               class="rdr-input"
               value="<?php echo \esc_attr( $wad_url ); ?>"
               placeholder="https://rdr-it.com/wp-content/plugins/rdr-it-suite/modules/rdr-doom/assets/doom1.wad">
        <p class="rdr-field__help">
            • <strong>.jsdos</strong> (recommandé) — bundle complet, compatible js-dos v8.<br>
            • <strong>.wad</strong> — fichier brut, nécessite js-dos v7 dans <code>assets/js-dos/js-dos.js</code>.<br>
            Si vide, le module cherche <code>assets/doom1.wad</code> automatiquement.
        </p>
    </div>
</div>

<div class="rdr-settings-group">
    <div class="rdr-settings-group__title">Interface</div>

    <div class="rdr-field">
        <label class="rdr-field__label" for="rdr_doom_modal_title">Titre de la modale</label>
        <input type="text"
               id="rdr_doom_modal_title"
               name="modal_title"
               class="rdr-input"
               value="<?php echo \esc_attr( $modal_title ); ?>"
               placeholder="DOOM">
    </div>

    <div class="rdr-field">
        <label class="rdr-field__label" for="rdr_doom_cheat_code">Code invincibilité</label>
        <input type="text"
               id="rdr_doom_cheat_code"
               name="cheat_code"
               class="rdr-input"
               value="<?php echo \esc_attr( $cheat_code ); ?>"
               placeholder="IDDQD"
               style="font-family:'JetBrains Mono',monospace;letter-spacing:3px;text-transform:uppercase;max-width:160px;">
        <p class="rdr-field__help">
            <strong>IDDQD</strong> = God Mode &nbsp;·&nbsp; <strong>IDKFA</strong> = toutes armes + clés
        </p>
    </div>
</div>

<?php $nonce = \wp_create_nonce( 'rdr_suite_nonce' ); ?>
<div class="rdr-settings-group">
    <button type="button"
            id="rdr-doom-save-btn"
            class="rdr-btn rdr-btn--save"
            data-nonce="<?php echo \esc_attr( $nonce ); ?>"
            data-ajax="<?php echo \esc_url( \admin_url( 'admin-ajax.php' ) ); ?>">
        Enregistrer les réglages
    </button>
    <span id="rdr-doom-save-status" style="margin-left:12px;font-size:13px;"></span>
</div>

<div class="rdr-settings-group">
    <div class="rdr-settings-group__title">Utilisation</div>
    <div class="rdr-alert rdr-alert--info">
        <div class="rdr-code-block">
            <pre class="rdr-code" id="rdr-doom-sc-1">[rdr_doom]</pre>
            <button class="rdr-copy-btn" data-target="rdr-doom-sc-1">Copier</button>
        </div>
        <div class="rdr-code-block" style="margin-top:8px;">
            <pre class="rdr-code" id="rdr-doom-sc-2">[rdr_doom title="DOOM — God Mode" placeholder="Code secret…"]</pre>
            <button class="rdr-copy-btn" data-target="rdr-doom-sc-2">Copier</button>
        </div>
        <p style="margin-top:10px;font-size:12px;color:var(--rdr-text-secondary);">
            ⚠ JS/CSS chargés <strong>uniquement</strong> sur les pages contenant ce shortcode.
        </p>
    </div>
</div>

<script>
( function () {
    'use strict';
    const btn    = document.getElementById( 'rdr-doom-save-btn' );
    const status = document.getElementById( 'rdr-doom-save-status' );
    if ( ! btn ) return;

    btn.addEventListener( 'click', function () {
        btn.disabled = true;
        status.style.color = 'var(--rdr-text-secondary)';
        status.textContent = 'Enregistrement…';

        const body = new URLSearchParams( {
            action:      'rdr_save_doom_settings',
            security:    btn.dataset.nonce,
            wad_url:     document.getElementById( 'rdr_doom_wad_url'    )?.value.trim() || '',
            cheat_code:  ( document.getElementById( 'rdr_doom_cheat_code' )?.value.trim() || 'IDDQD' ).toUpperCase(),
            modal_title: document.getElementById( 'rdr_doom_modal_title' )?.value.trim() || 'DOOM',
        } );

        fetch( btn.dataset.ajax, {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body:    body.toString(),
        } )
        .then( function ( r ) { return r.json(); } )
        .then( function ( data ) {
            if ( data.success ) {
                status.style.color = 'var(--rdr-accent-2,#00e5c0)';
                status.textContent = '✓ Réglages enregistrés';
            } else {
                status.style.color = '#e53e3e';
                status.textContent = '✗ ' + ( data.data?.message || JSON.stringify( data.data ) );
                console.error( '[RDR Doom settings]', data );
            }
        } )
        .catch( function ( err ) {
            status.style.color = '#e53e3e';
            status.textContent = '✗ Réseau : ' + err.message;
        } )
        .finally( function () {
            btn.disabled = false;
            setTimeout( function () { status.textContent = ''; }, 4000 );
        } );
    } );
} )();
</script>
