<?php
defined( 'ABSPATH' ) || exit;

if ( empty( $attributes['code'] ) ) {
	return;
}

// ── Chargement conditionnel ───────────────────────────────────────────────────
if ( ! wp_script_is( 'wp-tuto-mermaid', 'enqueued' ) ) {

	$local_file  = plugin_dir_path( __FILE__ ) . '../../assets/vendor/mermaid.min.js';
	$local_url   = plugin_dir_url( __FILE__ )  . '../../assets/vendor/mermaid.min.js';
	$cdn_url     = 'https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.min.js';
	$mermaid_url = file_exists( $local_file ) ? $local_url : $cdn_url;

	wp_enqueue_script( 'wp-tuto-mermaid', $mermaid_url, array(), null, array(
		'strategy'  => 'defer',
		'in_footer' => false,
	) );

	// Script d'init injecté après mermaid.min.js
	// Rendu async : on traite chaque bloc indépendamment via mermaid.render()
	// Le code est lu depuis data-code (attribut HTML encodé), jamais depuis le DOM
	// interne du bloc — plus aucun risque qu'un bouton ou autre élément HTML
	// parasite soit parsé comme du Mermaid.
	wp_add_inline_script(
		'wp-tuto-mermaid',
		'(function() {
			function rdritRenderMermaid() {
				if (typeof mermaid === "undefined") return;

				var dark = document.documentElement.getAttribute("data-theme") === "dark"
					|| document.body.getAttribute("data-theme") === "dark";

				if (typeof mermaid.registerIconPacks === "function") {
					mermaid.registerIconPacks([]);
				}

				mermaid.initialize({
					startOnLoad: false,
					theme: dark ? "dark" : "default",
					securityLevel: "loose",
					fontFamily: "inherit",
				});

				// Rendu async bloc par bloc
				var blocks = document.querySelectorAll(".wp-tuto-mermaid[data-mermaid-code]");
				var counter = 0;

				blocks.forEach(function(block) {
					var code   = block.getAttribute("data-mermaid-code");
					var target = block.querySelector(".wp-tuto-mermaid__diagram");
					if (!code || !target) return;

					// ID unique pour mermaid.render()
					var id = "mermaid-svg-" + (++counter) + "-" + Math.random().toString(36).slice(2);

					mermaid.render(id, code).then(function(result) {
						target.innerHTML = result.svg;
						target.classList.add("is-rendered");

						// Déclenche l\'init de la modale sur ce bloc si activée
						if (typeof rdritMermaidModalInit === "function") {
							rdritMermaidModalInit(block);
						}
					}).catch(function(err) {
						target.innerHTML =
							"<div class=\"wp-tuto-mermaid__error\">"
							+ "<strong>Erreur Mermaid</strong><br>"
							+ "<code>" + err.message.replace(/</g,"&lt;").replace(/>/g,"&gt;") + "</code>"
							+ "</div>";
						target.classList.add("is-error");
					});
				});
			}

			if (document.readyState === "loading") {
				document.addEventListener("DOMContentLoaded", rdritRenderMermaid);
			} else {
				rdritRenderMermaid();
			}
		})();',
		'after'
	);
}

// ── Modale ───────────────────────────────────────────────────────────────────
$enable_modal = $attributes['enableModal'] ?? true;

if ( $enable_modal && ! wp_script_is( 'wp-tuto-mermaid-modal', 'enqueued' ) ) {
	wp_enqueue_script( 'wp-tuto-mermaid-modal', plugin_dir_url( __FILE__ ) . '../../assets/mermaid-modal.js', array(), '1.1.0', array(
		'strategy'  => 'defer',
		'in_footer' => false,
	) );
	wp_enqueue_style( 'wp-tuto-mermaid-modal', plugin_dir_url( __FILE__ ) . '../../assets/mermaid-modal.css', array(), '1.1.0' );
}

// ── Rendu HTML ────────────────────────────────────────────────────────────────
$align     = sanitize_key( $attributes['align'] ?? 'center' );
$max_width = sanitize_text_field( $attributes['maxWidth'] ?? '100%' );
if ( ! preg_match( '/^\d+(\.\d+)?(px|%|em|rem)$|^auto$/', $max_width ) ) {
	$max_width = '100%';
}

$inline_style = 'max-width:' . esc_attr( $max_width ) . ';';
if ( $align === 'center' ) $inline_style .= 'margin-left:auto;margin-right:auto;';
if ( $align === 'right'  ) $inline_style .= 'margin-left:auto;';

// Le code Mermaid est stocké dans data-mermaid-code (encodé HTML).
// Le JS le lira depuis cet attribut — jamais depuis le DOM interne.
// Ainsi aucun élément HTML (bouton, etc.) ne peut parasiter le parsing.
$wrapper_attributes = get_block_wrapper_attributes( array(
	'class'              => 'wp-tuto-mermaid wp-tuto-mermaid--' . $align
		. ( $enable_modal ? ' wp-tuto-mermaid--zoomable' : '' ),
	'data-mermaid-code'  => $attributes['code'], // esc_attr appliqué par WP
	'data-enable-modal'  => $enable_modal ? 'true' : 'false',
) );

printf(
	'<div %s>
		<div class="wp-tuto-mermaid__inner" style="%s">
			<div class="wp-tuto-mermaid__diagram wp-tuto-mermaid__loading">
				<span class="wp-tuto-mermaid__spinner" aria-hidden="true"></span>
				<span class="wp-tuto-mermaid__loading-text">Chargement…</span>
			</div>
			%s
		</div>
	</div>',
	$wrapper_attributes,
	$inline_style,
	$enable_modal
		? '<button class="wp-tuto-mermaid__zoom-btn" aria-label="Agrandir le diagramme" title="Agrandir">⤢</button>'
		: ''
);
