# WP Sondage

Plugin WordPress de sondage avancé — intégration thème native, RGPD-friendly, Vanilla JS.

[![WordPress](https://img.shields.io/badge/WordPress-6.0%2B-blue?logo=wordpress)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-purple?logo=php)](https://php.net)
[![Licence](https://img.shields.io/badge/licence-GPL--2.0-green)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Version](https://img.shields.io/badge/version-1.5.0-orange)]()

---

## Fonctionnalités

### Front-end
- **Types** : radio (choix unique) ou checkbox (choix multiples)
- **Sous-questions conditionnelles** : affichées si le choix parent est sélectionné (ex. Exchange 2016/2019/SE sous "Exchange on-prem")
- **Aperçu résultats** : bouton "Voir les résultats" sans voter (si résultats publics)
- **Dark / Light** : détection automatique via les classes CSS du thème, MutationObserver pour le toggle dynamique
- **Skeleton loader** : animation shimmer pendant le lazy-loading
- **Zéro jQuery** : Vanilla JS uniquement en front

### Administration
- **Catégories** de sondages (taxonomie hiérarchique)
- **Date de fin** optionnelle / clôture manuelle
- **Résultats** publics ou privés
- **Actions bulk** : clore, ouvrir, masquer, réinitialiser les votes
- **Tri par votes** dans la liste admin
- **Réinitialisation** des votes depuis la liste ou la page du sondage (admin)

### Page archive
- **Page WordPress native** comme archive — pas de CPT archive, intégration thème parfaite
- **Pagination full AJAX** (pas d'intérêt SEO)
- **Breadcrumb Rank Math** automatique : HOME → Page archive → Titre du sondage
- **Polylang** : une page archive par langue, configurable dans les réglages

### Statistiques
- KPI globaux : votes, sondages, fréquence, tendance mensuelle
- Graphique d'activité 30 jours (Chart.js)
- Top 5 sondages
- Modal de détail : répartition des votes, activité 30j, distribution horaire

### Cache & CDN

Conçu pour tourner derrière LiteSpeed Cache, WP Rocket, W3TC et Cloudflare **sans purge manuelle** :

| Problème classique | Solution appliquée |
|---|---|
| Nonce figé dans le HTML caché → 403 après 24h | Nonce servi par les réponses AJAX (jamais cachées) |
| Bot déclenche un rendu vide → page vide cachée pour tous | Détection bot déportée côté AJAX uniquement |
| `has_voted()` d'un visiteur figé pour tous | État chargé après le rendu via `wps_poll_state` |
| `admin-ajax.php` mis en cache | En-têtes `no-store` + exclusions automatiques |
| Nonce périmé au moment du vote | Retry automatique avec nonce frais |

Les pages contenant des sondages restent **entièrement cacheables** — le contenu dynamique est injecté après chargement.

### RGPD & Antiflood
- Hash SHA-256 de l'IP + User-Agent — aucune donnée personnelle en clair
- Aucun cookie
- Bots détectés et exclus (shortcodes retournent `""` pour les crawlers)

---

## Installation

1. Téléchargez le [dernier release](./releases)
2. Uploadez le dossier `wp-sondage` dans `wp-content/plugins/`
3. Activez depuis **Extensions**
4. Régénérez les permaliens : **Réglages → Permaliens → Enregistrer**

---

## Configuration de la page archive

### Étape 1 — Créer la page

Créez une page WordPress normale :
- **Titre** : `Sondages` (ou la traduction dans chaque langue)
- **Slug** : `sondages` (libre — le CPT utilise `survey`, aucun conflit)
- **Contenu** : optionnel — intro, description (affiché avant la liste)

> ℹ️ Aucun shortcode n'est nécessaire sur cette page.

### Étape 2 — Configurer le plugin

Allez dans **Sondages → Réglages** et sélectionnez la page dans le menu déroulant.

**Avec Polylang** : les réglages affichent une ligne par langue (avec drapeau), chacune avec son propre sélecteur de page.

### Breadcrumb Rank Math

Le plugin injecte automatiquement la page archive dans le breadcrumb des pages sondage individuelles :

```
HOME >> Sondages >> Quel hyperviseur utilisez-vous ?
```

Aucune configuration supplémentaire requise.

---

## Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[wps_poll id="ID"]` | Affiche un sondage (lazy-loading par défaut) |
| `[wps_poll id="ID" lazy="0"]` | Chargement immédiat |
| `[wps_random]` | Sondage aléatoire non encore répondu |
| `[wps_random category="slug"]` | Sondage aléatoire dans une catégorie |

> La page archive ne nécessite aucun shortcode.

---

## Compatibilité

| Plugin | Support |
|--------|---------|
| **Polylang** | ✅ Questions/réponses traduisibles, page archive par langue |
| **WPML** | ✅ Via `icl_register_string` / `icl_t` |
| **Rank Math SEO** | ✅ Breadcrumb automatique |
| **GeneratePress** | ✅ Variables CSS `--rdr-*` réutilisées nativement |

---

## Structure des fichiers

```
wp-sondage/
├── wp-sondage.php               # Point d'entrée
├── uninstall.php                # Nettoyage complet à la désinstallation
├── readme.txt                   # WordPress.org readme
├── README.md                    # Ce fichier
├── admin/
│   ├── class-wps-admin.php      # Interface admin, settings, colonnes, bulk
│   ├── class-wps-stats.php      # Page statistiques avec graphiques
│   ├── css/wps-admin.css
│   └── js/wps-admin.js
├── includes/
│   ├── class-wps-install.php    # Création tables DB, migrations
│   ├── class-wps-post-type.php  # CPT wps_poll (slug: survey)
│   ├── class-wps-taxonomy.php   # Catégories (slug: survey-cat)
│   ├── class-wps-metaboxes.php  # Interface édition sondage
│   ├── class-wps-ajax.php       # Handlers AJAX (vote, peek, reset)
│   ├── class-wps-antiflood.php  # Protection RGPD SHA-256
│   ├── class-wps-archive-page.php # Page archive native + breadcrumb
│   ├── class-wps-shortcodes.php # [wps_poll] [wps_random]
│   ├── class-wps-widget.php     # Widget sidebar WordPress
│   └── class-wps-polylang.php   # Polylang + WPML
├── public/
│   ├── class-wps-public.php     # Rendu front, assets, templates
│   ├── css/wps-public.css       # CSS (variables thème, dark/light, skeleton)
│   ├── js/wps-public.js         # JS principal (Vanilla, vote, peek, lazy)
│   └── js/wps-archive.js        # JS page archive (AJAX, filtres, pagination)
├── templates/
│   ├── single-wps_poll.php      # Template page sondage
│   ├── poll-single.php          # Fragment injecté dans the_content
│   ├── poll-widget.php          # Fragment shortcode/widget compact
│   └── archive-poll.php         # Fallback CPT archive (non utilisé par défaut)
└── languages/
    └── wp-sondage.pot           # Fichier de traduction
```

---

## Tables de base de données

### `{prefix}wps_votes`

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | BIGINT | Auto-increment |
| `poll_id` | BIGINT | ID du sondage |
| `choice_ids` | TEXT | IDs des réponses séparés par virgule |
| `ip_hash` | VARCHAR(64) | SHA-256(IP + salt) |
| `ua_hash` | VARCHAR(64) | SHA-256(User-Agent + salt) |
| `voted_at` | DATETIME | Horodatage du vote |

### `{prefix}wps_choices`

| Colonne | Type | Description |
|---------|------|-------------|
| `id` | BIGINT | Auto-increment |
| `poll_id` | BIGINT | ID du sondage |
| `parent_id` | BIGINT | `0` = top-level, sinon ID du choix parent |
| `label` | TEXT | Texte de la réponse |
| `sort_order` | SMALLINT | Ordre d'affichage |

---

## Hooks développeurs

```php
// Traduire une question (Polylang/WPML)
add_filter( 'wps_poll_question', function( $question, $poll_id ) {
    return pll__( 'wps_question_' . $poll_id ) ?: $question;
}, 10, 2 );

// Modifier les données de résultats avant envoi AJAX
add_filter( 'wps_results_data', function( $data ) {
    // $data = ['choices' => [...], 'total' => int]
    return $data;
} );

// Action après sauvegarde d'un sondage
add_action( 'wps_after_save_poll', function( $poll_id, $post_data ) {
    // Re-enregistrer les chaînes Polylang, invalider un cache, etc.
}, 10, 2 );
```

---

## Changelog

### 1.5.0
- **Nouveau** : page WordPress native comme archive (remplace le CPT archive)
- **Nouveau** : pagination full AJAX sur la page archive
- **Nouveau** : skeleton loader sur l'archive (6 cartes animées)
- **Nouveau** : sélecteur de page par langue dans les réglages (drapeau + slug)
- **Nouveau** : breadcrumb Rank Math automatique via `rank_math/breadcrumbs/items`
- **Fix** : slug CPT `survey` (évite le conflit avec la page "sondages")
- **Fix** : `has_archive = false` sur le CPT
- **Fix** : appel `pll_languages_list()` corrigé + affichage drapeau emoji

### 1.4.0
- Migration complète vers Vanilla JS (zéro jQuery en front)
- CSS réduit de 22% via variables `--rdr-*` du thème
- Fix dark mode sur l'archive (variables thème, plus de media query)

### 1.3.1
- Fix modal stats : boucle de scroll infinie (modal dans `<body>`, hauteur px fixe)

### 1.3.0
- Bouton "Voir les résultats" sans voter
- Page de statistiques admin avec graphiques Chart.js

### 1.2.0
- Actions bulk, tri par votes, valeurs par défaut

### 1.1.0
- Widget sidebar, template single, uninstall.php

### 1.0.0
- Version initiale

---

## Licence

GPL-2.0 ou ultérieure — voir [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html)
