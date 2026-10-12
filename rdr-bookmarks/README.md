# RDR Bookmarks — Plugin WordPress

## Structure

```
rdr-bookmarks/
├── rdr-bookmarks.php                 ← Fichier principal
├── admin/
│   ├── rdr-bookmarks-admin.php       ← Page d'administration
│   └── rdr-bookmarks-shortcode.php   ← Shortcode [rdr_bookmarks]
└── assets/
    ├── rdr-bookmarks-front.js        ← Logique front (localStorage, switches, modale)
    └── rdr-bookmarks-front.css       ← Styles
```

---

## Installation

1. Copier le dossier `rdr-bookmarks/` dans `/wp-content/plugins/`
2. Activer dans **Réglages > Extensions**
3. Configurer dans **Réglages > RDR Bookmarks**

---

## Configuration admin

### Types de contenu activés
Cocher les CPT sur lesquels les switches doivent apparaître.

### Position des switches flottants
- **Côté** : droite (défaut) ou gauche
- **Priorité Favoris** : défaut 40 (en-dessous du Glossaire = 50 et Coloration = 60)
- **Priorité À lire** : défaut 30

**Ordre recommandé (bas → haut) :**
```
À lire (30) → Favoris (40) → Glossaire (50) → Coloration syntaxique (60)
```

### Labels
Tous les textes sont personnalisables. Si **Polylang** est actif, les valeurs
sont enregistrées dans le gestionnaire de chaînes Polylang et traduisibles
via **Polylang > Chaînes**.

---

## Shortcode

```
[rdr_bookmarks]
```

Placez ce shortcode dans un **widget texte/HTML** de votre pied de page.
Il affiche deux boutons avec compteur :

```
★ Favoris  [3]     🕐 À lire  [2]
```

Un clic ouvre une **modale à onglets** avec les cards des articles sauvegardés.
Les données sont chargées **en async via AJAX** (pas de requête serveur à la page).

---

## Intégration floating stack

Le plugin utilise `window.rdrFloatingStack` (déjà défini par rdr-syntax-highlighter
ou rdr-glossaire). S'il n'est pas encore chargé, le plugin l'initialise lui-même —
**l'ordre de chargement est sans importance**.

Chaque switch s'enregistre :
```js
window.rdrFloatingStack.register(swFav, 'right', 40);  // Favoris
window.rdrFloatingStack.register(swRL,  'right', 30);  // À lire
```

Les switches sont **masqués automatiquement** sur mobile (< 768 px) par le stack.

---

## RGPD & localStorage

- Aucun cookie n'est utilisé
- Les données persistent après fermeture du navigateur (localStorage)
- Au **premier clic** sur un switch, une popup demande le consentement
- Si l'utilisateur refuse, les switches restent visibles mais inactifs
- Le consentement est stocké dans `rdr_bm_consent` (`yes` | `no`)

### Clés localStorage
```
rdr_bm_consent           → 'yes' | 'no'
rdr_bm_favorites_fr      → [{id, title, url, thumb}, …]
rdr_bm_read_later_fr     → [{id, title, url, thumb}, …]
```
Le suffixe `_fr` correspond à la langue courante (Polylang ou locale WP).

---

## Détection bots

Si l'user-agent contient des mots-clés de bots (Googlebot, Bingbot, etc.),
**aucun script ni style n'est chargé**. Cela évite toute requête inutile
pendant l'indexation.

---

## Compatibilité

| Élément | Support |
|---------|---------|
| WordPress | 6.0+ |
| Polylang | Compatible (pll_current_language, pll__) |
| rdr-syntax-highlighter | Partage rdrFloatingStack |
| rdr-glossaire | Partage rdrFloatingStack |
| PHP | 7.4+ |
| JS | ES5+ (pas de transpilation nécessaire) |
| Navigateurs | Tous les navigateurs modernes (localStorage requis) |
