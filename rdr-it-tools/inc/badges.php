<?php
/**
 * RDR-IT Tools — Badges & remplacements contenu
 *
 * Syntaxes supportées dans les articles :
 *   <<<  texte >>>   → badge pill vert  (succès)
 *   <<   texte >>>   → badge pill rouge (danger)
 *   %start_p_primary% ... %end_p%   → alerte bleue
 *   %start_p_warning% ... %end_p%   → alerte jaune
 *   %start_p_danger%  ... %end_p%   → alerte rouge
 *   git.rdr-it.io                   → git.rdr-it.com
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'the_content', 'rdrit_replace_badges', 1000 );

function rdrit_replace_badges( $html ) {

    /* --- Badges inline (<<<, <<, >>) --- */
    if ( get_option( 'rdrit_badges_enabled', 1 ) ) {
        $search  = array( '&lt;&lt;&lt;', '&lt;&lt;', '&gt;&gt;', '>>' );
        $replace = array(
            '<span class="rdrit-badge rdrit-badge--success">',
            '<span class="rdrit-badge rdrit-badge--danger">',
            '</span>',
            '</span>',
        );
        $html = str_replace( $search, $replace, $html );
    }

    /* --- Blocs paragraphe %start_p_*% / %end_p% --- */
    if ( get_option( 'rdrit_badges_p_blocks_enabled', 1 ) ) {
        $search_p  = array(
            '%start_p_primary%',
            '%start_p_warning%',
            '%start_p_danger%',
            '%end_p%',
        );
        $replace_p = array(
            '<p class="rdrit-alert rdrit-alert--primary" role="alert">',
            '<p class="rdrit-alert rdrit-alert--warning" role="alert">',
            '<p class="rdrit-alert rdrit-alert--danger"  role="alert">',
            '</p>',
        );
        $html = str_replace( $search_p, $replace_p, $html );
    }

    /* --- Redirection git.rdr-it.io → git.rdr-it.com --- */
    if ( get_option( 'rdrit_badges_git_redirect', 1 ) ) {
        $html = str_replace( 'git.rdr-it.io', 'git.rdr-it.com', $html );
    }

    return $html;
}

/* --- CSS injecté en front-end uniquement si badges activés --- */
add_action( 'wp_head', 'rdrit_badges_inline_css' );

function rdrit_badges_inline_css() {
    if ( ! get_option( 'rdrit_badges_enabled', 1 ) && ! get_option( 'rdrit_badges_p_blocks_enabled', 1 ) ) {
        return;
    }
    ?>
<style id="rdrit-badges-css">
/* =============================================
   RDR-IT Badges — mode clair (défaut)
   ============================================= */
.rdrit-badge {
    display: inline-block;
    padding: .25em .55em;
    font-size: .78em;
    font-weight: 700;
    line-height: 1;
    border-radius: 50px;
    vertical-align: middle;
    white-space: nowrap;
}
.rdrit-badge--success {
    background-color: #28a745;
    color: #fff;
}
.rdrit-badge--danger {
    background-color: #dc3545;
    color: #fff;
}

/* Blocs alerte */
.rdrit-alert {
    padding: .85em 1.1em;
    border-left: 4px solid;
    border-radius: 6px;
    margin: 1.2em 0;
    font-size: .95em;
    line-height: 1.6;
}
.rdrit-alert--primary {
    background-color: #e8f4fd;
    border-color: #2196f3;
    color: #1a4a6e;
}
.rdrit-alert--warning {
    background-color: #fff8e1;
    border-color: #ff9800;
    color: #6d4c00;
}
.rdrit-alert--danger {
    background-color: #fdecea;
    border-color: #f44336;
    color: #7d1c14;
}

/* =============================================
   RDR-IT Badges — mode sombre (automatique)
   ============================================= */
[data-theme="dark"] .rdrit-badge--success {
    background-color: #1e5c33;
    color: #a8f0be;
}
[data-theme="dark"] .rdrit-badge--danger {
    background-color: #6b1c26;
    color: #f5a8ae;
}
[data-theme="dark"] .rdrit-alert--primary {
    background-color: #0d2a3d;
    border-color: #42a5f5;
    color: #90caf9;
}
[data-theme="dark"] .rdrit-alert--warning {
    background-color: #2d2000;
    border-color: #ffb74d;
    color: #ffe082;
}
[data-theme="dark"] .rdrit-alert--danger {
    background-color: #2d0a0a;
    border-color: #ef5350;
    color: #ffcdd2;
}
</style>
    <?php
}
