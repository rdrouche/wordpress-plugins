# RDR-IT Glossaire — v1.3.1

Plugin WordPress de glossaire technique avec tooltips inline.

## Caractéristiques

- **jQuery-free** — zéro dépendance jQuery en front-end
- Tooltips async via REST API + cache sessionStorage
- Compatible LiteSpeed Cache (localStorage)
- Compatible Polylang (multilingue)
- Dark/light via `data-theme` (thème RDR-IT)
- Schema.org `DefinedTerm` / `DefinedTermSet`

## Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[rdrg_glossaire]` | Page index du glossaire (SSR, filtrable) |
| `[rdrg_switch]` | Toggle glossaire inline à position libre |

## Switch flottant

Configurable dans **Réglages → Position du switch** :
- `Sous le titre` — injecté sous le H1
- `Flottant Gauche / Droite` — pill fixe via `window.rdrFloatingStack`

Le stack `window.rdrFloatingStack` est partagé avec `rdr-syntax-highlighter` pour l'empilement automatique des switches flottants.

## Fichiers front-end (jQuery-free)

- `assets/glossaire.js` — tooltips + toggle (vanilla JS)
- `assets/glossaire-index.js` — filtre alphabétique page index (vanilla JS)
- `assets/glossaire.css` — tooltips
- `assets/glossaire-page.css` — pages index et single
- `assets/glossaire-float.css` — switch flottant
