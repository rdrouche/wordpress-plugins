# WP Markdown Exporter

Export n'importe quel post WordPress (Posts, Pages, CPTs) vers des fichiers **Markdown** `.md` avec front matter YAML, directement depuis l'administration.

---

## Fonctionnalités

| Fonctionnalité | Détail |
|---|---|
| **Export unitaire** | Lien "Export .md" sur chaque ligne de la liste des posts |
| **Export groupé** | Action groupée "Exporter en Markdown" dans les listes (ZIP) |
| **Page dédiée** | `Outils → Markdown Export` avec filtres avancés |
| **Tous les CPTs** | Posts, Pages + tous les Custom Post Types détectés automatiquement |
| **Front matter YAML** | Titre, date, auteur, slug, statut, taxonomies, permalink, extrait |
| **Conversion HTML→MD** | Titres, gras, italique, listes, liens, images, tableaux, code, blockquotes |
| **Image à la une** | Incluse en haut du fichier Markdown |
| **Custom fields** | Champs publics exportés en tableau Markdown |
| **Format ZIP ou .md unique** | Choix lors de l'export depuis la page dédiée |

---

## Installation

1. Copier le dossier `wp-markdown-exporter/` dans `/wp-content/plugins/`
2. Activer le plugin depuis `Extensions → Extensions installées`
3. Aller dans `Outils → Markdown Export`

### Prérequis

- WordPress 5.5+
- PHP 7.4+
- Extension PHP `ZipArchive` (pour les exports ZIP — incluse par défaut sur la plupart des hébergements)
- Extension PHP `DOMDocument` (incluse par défaut)

---

## Utilisation

### 1. Export d'un post unique

Dans la liste des posts (`Articles`, `Pages`, ou tout CPT), survolez un post et cliquez sur **Export .md**.

Le fichier `.md` se télécharge immédiatement.

### 2. Export groupé (bulk)

1. Cochez plusieurs posts dans la liste
2. Dans le menu déroulant "Actions groupées", sélectionnez **Exporter en Markdown**
3. Cliquez sur **Appliquer**
4. Un fichier ZIP contenant tous les `.md` se télécharge

### 3. Export avancé (page dédiée)

`Outils → Markdown Export`

Permet de :
- Choisir un ou plusieurs types de contenu
- Filtrer par statut (publié, brouillon, etc.)
- Limiter le nombre de posts exportés
- Activer/désactiver : front matter, image à la une, custom fields, taxonomies
- Choisir entre ZIP ou fichier `.md` unique concaténé

---

## Exemple de sortie Markdown

```markdown
---
title: "Mon article de blog"
date: 2024-01-15
modified: 2024-02-10
author: "Jean Dupont"
slug: "mon-article-de-blog"
status: publish
type: post
permalink: "https://example.com/mon-article-de-blog/"
excerpt: "Un résumé de l'article"
category:
  - "WordPress"
  - "Développement"
post_tag:
  - "PHP"
  - "Plugin"
---

# Mon article de blog

![Mon article de blog](https://example.com/wp-content/uploads/image.jpg)

## Introduction

Voici le contenu de l'article converti en **Markdown**.

- Point 1
- Point 2

[Voir plus](https://example.com)

---

## Custom Fields

| Field | Value |
| --- | --- |
| prix | 99 |
| couleur | bleu |
```

---

## Structure du plugin

```
wp-markdown-exporter/
├── wp-markdown-exporter.php        # Point d'entrée principal
└── includes/
    ├── class-html-to-markdown.php  # Convertisseur HTML → Markdown (DOMDocument)
    ├── class-post-exporter.php     # Export d'un ou plusieurs posts
    ├── class-admin-page.php        # Page Outils → Markdown Export
    ├── class-bulk-export.php       # Action groupée dans les listes
    └── class-row-action.php        # Lien "Export .md" par ligne
```

---

## Sécurité

- Toutes les actions sont protégées par **nonce WordPress**
- Vérification des **capabilities** (`edit_posts`, `edit_post`)
- Les clés de meta privées (préfixées par `_`) sont **exclues** de l'export
- Aucune donnée sensible n'est exposée

---

## Licence

GPL-2.0+
