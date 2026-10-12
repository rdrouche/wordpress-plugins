<?php
/**
 * RDR-IT Tools — Citations colorées v1
 *
 * Injecte le CSS des blocs citation colorés via is_singular().
 * Compatible :
 *   - Ancien système : classes .quote-blue, .quote-memo, etc.
 *   - Nouveau système : data-quote="blue" sur le bloc wp-block-quote
 *
 * Chaque couleur est configurable depuis l'admin (nom + valeur hex).
 * Le CSS généré utilise CSS custom properties pour le mode clair/sombre.
 *
 * Options (une entrée JSON par couleur) :
 *   rdrit_quotes_colors  — tableau JSON des couleurs configurées
 *   rdrit_quotes_enabled — bool, active l'injection CSS
 *
 * Structure d'une couleur :
 *   { "slug":"blue", "label":"Bleu", "color":"#0d6efd", "light":"#cfe2ff", "dark":"#031633" }
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   COULEURS PAR DÉFAUT (12 couleurs Bootstrap 5.3)
   ============================================================ */
function rdrit_quotes_defaults(): array {
    return array(
        array( 'slug' => 'blue',    'label' => 'Bleu',    'color' => '#0d6efd', 'light' => '#cfe2ff', 'dark' => '#031633' ),
        array( 'slug' => 'indigo',  'label' => 'Indigo',  'color' => '#6610f2', 'light' => '#e0cffc', 'dark' => '#0d0025' ),
        array( 'slug' => 'purple',  'label' => 'Violet',  'color' => '#6f42c1', 'light' => '#e2d9f3', 'dark' => '#0f0520' ),
        array( 'slug' => 'pink',    'label' => 'Rose',    'color' => '#d63384', 'light' => '#f7d6e6', 'dark' => '#2c0b1a' ),
        array( 'slug' => 'red',     'label' => 'Rouge',   'color' => '#dc3545', 'light' => '#f8d7da', 'dark' => '#2c0b0e' ),
        array( 'slug' => 'orange',  'label' => 'Orange',  'color' => '#fd7e14', 'light' => '#ffe5d0', 'dark' => '#321a00' ),
        array( 'slug' => 'yellow',  'label' => 'Jaune',   'color' => '#ffc107', 'light' => '#fff3cd', 'dark' => '#332701' ),
        array( 'slug' => 'green',   'label' => 'Vert',    'color' => '#198754', 'light' => '#d1e7dd', 'dark' => '#051b11' ),
        array( 'slug' => 'teal',    'label' => 'Teal',    'color' => '#20c997', 'light' => '#d2f4ea', 'dark' => '#031f15' ),
        array( 'slug' => 'cyan',    'label' => 'Cyan',    'color' => '#0dcaf0', 'light' => '#cff4fc', 'dark' => '#032830' ),
        array( 'slug' => 'gray',    'label' => 'Gris',    'color' => '#6c757d', 'light' => '#f8f9fa', 'dark' => '#1a1d20' ),
        array( 'slug' => 'memo',    'label' => 'Mémo',    'color' => '#2264ad', 'light' => '#dbeafe', 'dark' => '#0c1a2e' ),
    );
}

/**
 * Retourne les couleurs actives (depuis wp_options ou défauts).
 */
function rdrit_quotes_get_colors(): array {
    $saved = get_option( 'rdrit_quotes_colors', '' );
    if ( $saved ) {
        $decoded = json_decode( $saved, true );
        if ( is_array( $decoded ) && ! empty( $decoded ) ) return $decoded;
    }
    return rdrit_quotes_defaults();
}

/* ============================================================
   INJECTION CSS — is_singular()
   ============================================================ */
// wp_footer garantit que notre CSS est injecté APRÈS les feuilles du thème
// => notre spécificité (0,2,1) bat naturellement le (0,1,1) du thème sans !important
add_action( 'wp_footer', 'rdrit_quotes_inject_css', 1 );
function rdrit_quotes_inject_css() {
    if ( ! (bool) get_option( 'rdrit_quotes_enabled', 1 ) ) return;
    if ( ! is_singular() ) return;

    $colors = rdrit_quotes_get_colors();
    if ( empty( $colors ) ) return;

    // !important optionnel — nécessaire si le thème utilise !important sur blockquote
    $i = (bool) get_option( 'rdrit_quotes_force_important', 1 ) ? ' !important' : '';

    echo "\n<style id=\"rdrit-quotes-css\">\n";

    foreach ( $colors as $c ) {
        $slug  = sanitize_key( $c['slug'] );
        $color = sanitize_hex_color( $c['color'] ) ?: '#0d6efd';
        $light = sanitize_hex_color( $c['light'] ) ?: '#cfe2ff';
        $dark  = sanitize_hex_color( $c['dark']  ) ?: '#031633';

        // Sélecteurs haute spécificité (0,2,1) pour battre .entry-content blockquote (0,1,1)
        // + sélecteurs sans préfixe pour rétrocompat hors .entry-content
        $sels = array(
            ".entry-content blockquote.quote-{$slug}",
            ".entry-content blockquote[data-quote=\"{$slug}\"]",
            ".entry-content .wp-block-quote.quote-{$slug}",
            ".entry-content .wp-block-quote[data-quote=\"{$slug}\"]",
            ".wp-block-quote.quote-{$slug}",
            ".wp-block-quote[data-quote=\"{$slug}\"]",
            "blockquote.quote-{$slug}",
            "blockquote[data-quote=\"{$slug}\"]",
        );
        $sel = implode( ",\n", $sels );

        echo "/* {$c['label']} */\n";
        echo "{$sel}{\n";
        echo "  border-left: 5px solid {$color}{$i};\n";
        echo "  background: {$light}{$i};\n";
        echo "  color: #1e293b{$i};\n";
        echo "  border-radius: 0 6px 6px 0{$i};\n";
        echo "  padding: 1em 1.2em{$i};\n";
        echo "  margin: 1.2em 0{$i};\n";
        echo "}\n";

        // Mode sombre
        $sel_dark = implode( ",\n", array_map( function( $s ) {
            return "[data-theme=\"dark\"] {$s}";
        }, $sels ) );

        echo "{$sel_dark}{\n";
        echo "  background: {$dark}{$i};\n";
        echo "  color: #e2e8f0{$i};\n";
        echo "  border-left-color: {$color}{$i};\n";
        echo "}\n\n";
    }

    echo "</style>\n";
}

/* ============================================================
   CSS ÉDITEUR GUTENBERG — aperçu des couleurs dans l'éditeur
   ============================================================ */
add_action( 'enqueue_block_editor_assets', 'rdrit_quotes_editor_assets' );
function rdrit_quotes_editor_assets() {
    if ( ! (bool) get_option( 'rdrit_quotes_enabled', 1 ) ) return;

    // JS de l'extension du bloc citation
    wp_enqueue_script(
        'rdrit-quotes-editor',
        RDRIT_TOOLS_URL . 'assets/js/rdrit-quotes-editor.js',
        array( 'wp-blocks', 'wp-hooks', 'wp-element', 'wp-components',
               'wp-block-editor', 'wp-compose' ),
        RDRIT_TOOLS_VERSION,
        false
    );

    // Passer les couleurs configurées au JS
    wp_localize_script( 'rdrit-quotes-editor', 'rdritQuotesConfig', array(
        'colors' => rdrit_quotes_get_colors(),
    ) );

    // CSS aperçu dans l'éditeur (même règles que le front)
    add_action( 'admin_head', 'rdrit_quotes_inject_css' );
}

/* ============================================================
   FILTRE SAVE — ajouter data-quote sur le HTML sauvegardé
   Permet la rétrocompat : si data-quote est présent, on le garde.
   ============================================================ */
add_filter( 'render_block', 'rdrit_quotes_render_block', 10, 2 );
function rdrit_quotes_render_block( string $html, array $block ): string {
    if ( $block['blockName'] !== 'core/quote' ) return $html;
    if ( ! is_singular() ) return $html;
    if ( ! (bool) get_option( 'rdrit_quotes_enabled', 1 ) ) return $html;

    // Si data-quote déjà présent dans les attributs, l'ajouter au HTML
    $quote_color = $block['attrs']['rdritQuoteColor'] ?? '';
    if ( $quote_color ) {
        $slug = sanitize_key( $quote_color );
        // Injecter data-quote sur le <blockquote>
        $html = preg_replace(
            '/(<blockquote\b)/',
            '$1 data-quote="' . esc_attr( $slug ) . '"',
            $html,
            1
        );
    }

    return $html;
}
