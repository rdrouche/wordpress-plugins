# RDR Syntax Highlighter — Plugin WordPress v1.7

**Auteur** : Romain Drouche — rdr-it.com

---

## Dépendances JavaScript

**✅ 0 jQuery — Vanilla JS uniquement**

Le plugin n'a aucune dépendance à jQuery. Tous les scripts utilisent exclusivement l'API DOM native (ES5+ / Fetch API / IntersectionObserver / AbortController). Le bloc éditeur Gutenberg (`block-language-picker.js`) dépend uniquement des packages WordPress Gutenberg (`wp-blocks`, `wp-hooks`, `wp-element`, `wp-components`, `wp-block-editor`, `wp-i18n`) — aucun jQuery.

---

## Concept

Coloration syntaxique **à la demande** via switch utilisateur, 100% compatible LiteSpeed Cache :

- ✅ **0 impact perf par défaut** : les blocs `<code>`/`<pre>` restent en HTML natif (indexables par les bots)
- ✅ **Activation via switch** : stocké en `localStorage`, zéro cookie, zéro rechargement
- ✅ **Async + Lazy-load** : colorisation via `fetch()` + `IntersectionObserver` (blocs visibles uniquement)
- ✅ **Cache serveur** : transient WordPress + fichier disque `/wp-content/cache/rdr-syntax-highlighter/`
- ✅ **Dark / Light** : adaptatif via `data-theme` du thème RDR-IT
- ✅ **AbortController** : les fetches en cours sont annulés lors du toggle OFF → plus de loaders bloqués

---

## Architecture

```
rdr-syntax-highlighter/
├── rdr-syntax-highlighter.php      ← Point d'entrée, hooks, enqueue conditionnel
├── includes/
│   ├── class-highlighter.php       ← Moteur tokenisation + rendu HTML
│   ├── class-cache.php             ← Cache 2 niveaux (transient + disque)
│   ├── ajax-handler.php            ← Endpoint wp_ajax_rdr_sh_highlight
│   └── admin.php                   ← Réglages, hooks GP, shortcode
└── assets/
    ├── css/rdr-sh.css              ← Styles blocs, switch, filename, toolbar
    ├── js/rdr-sh.js                ← Frontend — 0 jQuery, Vanilla ES5+
    └── js/block-language-picker.js ← Sélecteur langage + filename dans Gutenberg
```

---

## Langages supportés

| Langage | Tokeniseur |
|---------|-----------|
| PHP | `highlight_string()` natif PHP |
| JavaScript / TypeScript | Regex avancé |
| HTML / XML | Regex |
| CSS / SCSS | Regex |
| Bash / Shell | Regex |
| PowerShell | Regex |
| SQL | Regex |
| JSON | Regex |
| YAML | Regex |
| Python | Regex |
| Dockerfile, Nginx, INI... | Plaintext (encodage HTML sûr) |

Auto-détection par score sur 10 langages si aucune classe n'est spécifiée.

---

## Déclarer le langage dans Gutenberg

Bloc **Code** → colonne droite → **🎨 Coloration syntaxique** → menu déroulant **Langage**.

Ou manuellement : **Avancé → Classe CSS supplémentaire** → `language-powershell`

---

## Nom de fichier

Dans Gutenberg : bloc **Code** → **🎨 Coloration syntaxique** → champ **Nom de fichier** (ex: `docker-compose.yml`).

La toolbar affiche alors :
- Le nom de fichier cliquable → ouvre le code en texte brut dans un nouvel onglet
- Un bouton ↓ pour télécharger le fichier

Autres méthodes :
- `data-rdr-filename="nginx.conf"` sur le `<pre>`
- `class="file-nginx.conf"` sur le `<code>`
- Commentaire dans le code : `# filename: docker-compose.yml`

---

## Positions du switch

| Option | Comportement |
|--------|-------------|
| Flottant droite | Fixed bas-droite, masqué sur mobile |
| Flottant gauche | Fixed bas-gauche, masqué sur mobile |
| Sous le titre | Via `generate_after_entry_title` (style glossaire) |
| Shortcode uniquement | `[rdr_sh_switch]` à placer manuellement |
| Désactivé | API JS uniquement |

### Coordination inter-plugins (`rdrFloatingStack`)

Registre global partagé entre `rdr-syntax-highlighter` (priorité 60) et `rdr-glossaire` (priorité 50).  
Les switchs flottants se placent automatiquement en colonne verticale sans superposition.

Patch pour rdr-glossaire : voir `rdr-floating-stack-patch-glossaire.js`

---

## Shortcode

```
[rdr_sh_switch]                         → switch complet
[rdr_sh_switch label="Colorer le code"] → label custom
[rdr_sh_switch style="minimal"]         → toggle seul
```

---

## API JavaScript (0 jQuery)

```js
window.rdrSH.enable()       // Active + localStorage
window.rdrSH.disable()      // Désactive + annule les fetches en cours
window.rdrSH.isEnabled()    // true | false

// Événement après colorisation d'un bloc :
document.addEventListener('rdr_sh_highlighted', e => {
    console.log(e.detail.block); // HTMLElement colorisé
});
```

---

## Polylang

Le libellé du switch est traduit via `pll_register_string()`.  
**Langues → Chaînes de traduction** → groupe **RDR Syntax Highlighter** → clé `rdr_sh_switch_label`.

---

## Cache

- Transient WordPress (+ Redis/Memcached si dispo)
- Fichier disque : `/wp-content/cache/rdr-syntax-highlighter/`
- Clé = `md5(code + langue + numéros_de_ligne + nom_de_fichier)`
- Vider : **Réglages → RDR Syntax Highlighter → Vider le cache**

---

## Installation

1. Uploader le dossier dans `/wp-content/plugins/`
2. Activer dans **Extensions**
3. Configurer dans **Réglages → RDR Syntax Highlighter**
