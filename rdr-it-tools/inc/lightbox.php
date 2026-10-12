<?php
/**
 * RDR-IT Tools — Lightbox v1
 *
 * Gestion de la lightbox GLightbox (vanilla JS, zero dependance jQuery).
 * Les fichiers JS/CSS sont servis par le serveur (pas de CDN externe).
 *
 * Options :
 *   rdrit_lightbox_enabled   bool    Active GLightbox
 *   rdrit_lightbox_scope     string  Selecteur CSS zone article
 *                                    defaut : .inside-article, .entry-content
 *   rdrit_lightbox_gallery   string  Nom du groupe galerie (defaut : article)
 *   rdrit_lightbox_loop      bool    Navigation en boucle (defaut : 1)
 *   rdrit_lightbox_zoomable  bool    Zoom au clic (defaut : 1)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_enqueue_scripts', 'rdrit_lightbox_enqueue' );
function rdrit_lightbox_enqueue() {
    if ( ! (bool) get_option( 'rdrit_lightbox_enabled', 0 ) ) return;

    // GLightbox CSS
    wp_enqueue_style(
        'rdrit-glightbox',
        RDRIT_TOOLS_URL . 'assets/lib/glightbox/glightbox.min.css',
        array(),
        '3.3.1'
    );

    // GLightbox JS
    wp_enqueue_script(
        'rdrit-glightbox',
        RDRIT_TOOLS_URL . 'assets/lib/glightbox/glightbox.min.js',
        array(),
        '3.3.1',
        true
    );

    // Init JS
    wp_enqueue_script(
        'rdrit-glightbox-init',
        RDRIT_TOOLS_URL . 'assets/js/rdrit-glightbox-init.js',
        array( 'rdrit-glightbox' ),
        RDRIT_TOOLS_VERSION,
        true
    );

    wp_localize_script( 'rdrit-glightbox-init', 'rdritGlightboxConfig', array(
        'scope'    => sanitize_text_field( get_option( 'rdrit_lightbox_scope',
                        '.inside-article, .entry-content, .post-content' ) ),
        'gallery'  => sanitize_text_field( get_option( 'rdrit_lightbox_gallery', 'article' ) ),
        'loop'     => (bool) get_option( 'rdrit_lightbox_loop',     1 ),
        'zoomable' => (bool) get_option( 'rdrit_lightbox_zoomable', 1 ),
    ) );
}

/**
 * Retourne le selecteur CSS du lien lightbox actif.
 * Utilise par le module AdSense/AdBlock pour le remplacement d'image.
 */
function rdrit_lightbox_img_selector(): string {
    if ( ! (bool) get_option( 'rdrit_lightbox_enabled', 0 ) ) {
        // GLightbox desactive — retourner le selecteur configure manuellement
        return get_option( 'rdrit_adblock_img_selector', '.wp-block-image > a' );
    }
    return '.wp-block-image > .glightbox';
}
