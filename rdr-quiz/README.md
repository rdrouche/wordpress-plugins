# RDR-IT Quiz — Plugin WordPress

Système de quiz interactif pour WordPress. Async, sans rechargement de page, compatible LiteSpeed Cache, Polylang et GeneratePress.

**Version :** 1.6.2  
**Auteur :** Romain Drouche — [rdr-it.com](https://rdr-it.com)  
**Requires :** WordPress 6.0+, PHP 8.0+  
**Testé avec :** GeneratePress, Rank Math SEO, Polylang

---

## Fonctionnalités

### Types de questions
| Badge | Type | Description |
|-------|------|-------------|
| `O/N` | Oui / Non | Question binaire |
| `QCU` | Choix unique | Radio — une seule bonne réponse |
| `QCM` | Choix multiple | Checkbox — plusieurs bonnes réponses |
| `TRI` | Ordre à glisser | Drag & drop pour remettre dans l'ordre |
| `CLI` | Saisie libre | Réponse texte exacte (ex : commande Linux) |
| `GAP` | Phrase à trou | Compléter avec `___` (1 à 3 trous) |

### Fonctionnalités principales
- Moteur front-end vanilla JS pur (zéro jQuery côté visiteur)
- Vérification des réponses côté serveur (AJAX)
- Explication + lien documentation après chaque réponse
- Résultats avec score, revue complète, bouton recommencer
- Statistiques anonymes (RGPD) : tentatives, score moyen, historique 30 jours
- Tags sur les questions pour organisation et filtrage
- Difficulté par question et par quiz
- Compatible LiteSpeed Cache (nonce auto-refresh)
- Compatible Polylang (multi-langue)
- Compatible Rank Math SEO (breadcrumb)

---

## Installation

1. Téléverser le ZIP dans **Extensions → Ajouter → Téléverser**
2. Activer le plugin
3. Aller dans **Réglages → Permaliens → Enregistrer** (flush des règles de réécriture)
4. Créer une page WordPress avec le shortcode `[rdr_quiz_list]` pour la liste des quiz
5. *(Optionnel)* Ajouter une redirection 301 de `/quiz/` vers cette page si vous aviez l'ancienne URL

---

## Structure des URLs

| URL | Description |
|-----|-------------|
| `/quiz/` | Page liste (page WP native avec `[rdr_quiz_list]`) |
| `/quiz-it/mon-quiz/` | Page d'un quiz individuel |

---

## Shortcodes

### `[rdr_quiz id="X"]`
Affiche un quiz complet (intro + questions + résultats).

```
[rdr_quiz id="42"]
```

### `[rdr_quiz_list]`
Affiche la grille de tous les quiz avec filtres (catégorie, difficulté, sujet).

| Attribut | Valeurs | Défaut | Description |
|----------|---------|--------|-------------|
| `modal` | `yes` / `no` | `no` | Ouvre le quiz dans une modale au lieu de naviguer |
| `per_page` | entier | `12` | Nombre de quiz par page |
| `cat` | slug catégorie | — | Filtrer par catégorie |
| `diff` | `debutant` `intermediaire` `avance` `expert` | — | Filtrer par difficulté |

```
[rdr_quiz_list]
[rdr_quiz_list modal="yes"]
[rdr_quiz_list per_page="6" diff="debutant"]
```

### `[rdr_quiz_link id="X"]`
Bouton CTA ou lien vers un quiz, à intégrer dans n'importe quel article ou page.

| Attribut | Valeurs | Défaut | Description |
|----------|---------|--------|-------------|
| `id` | ID du quiz | — | **Obligatoire** |
| `style` | `button` `card` `inline` | `button` | Style d'affichage |
| `modal` | `yes` / `no` | `no` | Ouvrir dans une modale |
| `label` | texte | — | Label personnalisé du bouton |

```
[rdr_quiz_link id="42"]
[rdr_quiz_link id="42" style="card"]
[rdr_quiz_link id="42" modal="yes" label="Tester mes connaissances Docker"]
[rdr_quiz_link id="42" style="inline"]
```

---

## Administration

### Créer une question

**Quiz → Questions → Ajouter une nouvelle**

- **Titre** : énoncé principal de la question
- **Corps** *(optionnel)* : éditeur riche pour ajouter contexte, code, image
- **Type de réponse** : choisir parmi les 6 types disponibles
- **Difficulté** : Débutant / Intermédiaire / Avancé / Expert
- **Tags** : mots-clés pour filtrer (ex : `docker`, `linux`, `active-directory`)
- **Explication** : texte affiché après validation de la réponse
- **Lien documentation** : URL vers un article ou ressource externe

#### Type "Phrase à trou" (GAP)
Rédiger la phrase avec `___` (3 underscores) à chaque trou :
```
La commande ___ permet de lister les conteneurs Docker actifs.
```
Puis renseigner les variantes acceptées pour chaque trou (une par ligne, insensible à la casse) :
```
docker ps
docker container ls
```

### Créer un quiz

**Quiz → Ajouter un nouveau**

**Paramètres :**
| Champ | Description |
|-------|-------------|
| Nombre de questions | Toutes ou nombre fixe (5, 10, 15…) |
| Ordre | Aléatoire ou fixe |
| Difficulté globale | Affiché sur la card de la liste |
| Durée estimée | En minutes |
| Lien article associé | URL d'un article de référence |
| Masquer les sujets abordés | Cache les tags de questions côté visiteur |
| Masquer de la liste | Exclut le quiz de `[rdr_quiz_list]` (accessible par URL directe) |
| **Page liste associée** | **Sélectionner la page WP contenant `[rdr_quiz_list]` — nécessaire pour le breadcrumb Rank Math** |

**Gestionnaire de questions :**
- Filtres en temps réel : recherche texte, ID, tags (multi-sélection ET), difficulté
- Glisser-déposer pour ordonner les questions sélectionnées

---

## Page Statistiques

**Quiz → 📊 Statistiques**

- KPI globaux : tentatives totales, score moyen, nombre de quiz actifs
- Graphique 30 jours (tentatives + score moyen)
- Tableau par quiz : tentatives, score moyen, lien vers détail par question
- Taux de réussite par question
- Remise à zéro par quiz ou globale *(réservé aux administrateurs)*

> **RGPD** : aucune donnée personnelle n'est stockée. Les tables enregistrent uniquement `quiz_id`, `score_pct` et `answered_at`.

---

## Intégration thème

Le plugin s'appuie intégralement sur les variables CSS `--rdr-*` du thème RDR-IT :

```css
--rdr-accent        /* couleur principale (bleu) */
--rdr-accent-2      /* couleur secondaire (vert) */
--rdr-bg-surface    /* fond des cards */
--rdr-bg-card       /* fond secondaire */
--rdr-text-primary  /* texte principal */
--rdr-text-secondary
--rdr-border        /* bordure standard */
--rdr-border-strong
--rdr-shadow-card
```

Le dark/light toggle du thème fonctionne automatiquement sur tous les éléments du quiz.

---

## Compatibilité LiteSpeed Cache

LiteSpeed met en cache les pages avec le nonce WP intégré. Après 12-24h le nonce expire.

**Solution implémentée :**
- `submit_quiz` et `check_answer` n'utilisent pas de nonce (données publiques anonymes)
- `load_quiz` utilise un nonce avec **refresh automatique** : si WP renvoie `-1`, le JS appelle `rdr_refresh_nonce` pour obtenir un nouveau nonce et relance la requête

---

## Compatibilité Polylang

Les taxonomies `rdr_quiz_cat` et `rdr_question_tag` sont enregistrées auprès de Polylang.

Pour le breadcrumb multi-langue : sélectionner **la page liste dans chaque langue** dans les paramètres de chaque quiz. Le plugin traduit automatiquement l'ID via `pll_get_post()`.

---

## Structure des fichiers

```
rdr-quiz/
├── rdr-quiz.php                    ← Bootstrap, filtres WP, breadcrumb, helpers
├── README.md
├── includes/
│   ├── class-cpt.php               ← CPT rdr_quiz + rdr_question + taxonomies
│   ├── class-admin-ui.php          ← Meta boxes, gestionnaire questions, stats box
│   ├── class-ajax.php              ← Endpoints AJAX (load, check, submit, list, html)
│   ├── class-shortcodes.php        ← [rdr_quiz] [rdr_quiz_list] [rdr_quiz_link]
│   ├── class-stats.php             ← Tables BDD, enregistrement, page admin stats
│   └── class-polylang.php          ← Filtres Polylang
├── assets/
│   ├── css/
│   │   ├── quiz.css                ← Styles front (variables --rdr-*)
│   │   ├── quiz-modal.css          ← Styles modale (chargé conditionnel)
│   │   └── admin.css               ← Styles back-office
│   └── js/
│       ├── quiz.js                 ← Moteur quiz vanilla JS
│       ├── quiz-modal.js           ← Moteur modale vanilla JS (chargé conditionnel)
│       ├── admin.js                ← Interface admin (jQuery)
│       └── sortable.min.js         ← SortableJS 1.15.2 (bundlé, MIT)
└── templates/
    ├── single-rdr_quiz.php         ← Template de secours (non utilisé par défaut)
    └── archive-rdr_quiz.php        ← Template de secours (non utilisé par défaut)
```

---

## Tables BDD créées

| Table | Description |
|-------|-------------|
| `{prefix}_rdr_quiz_stats` | Agrégats par quiz (attempts, total_score) |
| `{prefix}_rdr_quiz_history` | Historique anonyme (quiz_id, score_pct, answered_at) |
| `{prefix}_rdr_question_stats` | Agrégats par question (total, correct) |

---

## Changelog

### v1.6.2
- Fix : bouton "Démarrer" dans la modale (délégation d'événement)
- Fix : format des items breadcrumb Rank Math (tableau numérique `[url, text, is_current]`)
- Fix : `$use_modal` non déclaré avant utilisation dans `[rdr_quiz_list]`

### v1.6.0
- Nouveau : modale quiz — `[rdr_quiz_link modal="yes"]` et `[rdr_quiz_list modal="yes"]`
- Nouveau : assets modale chargés conditionnellement (CSS + JS uniquement si bouton présent)
- Fix : SortableJS en local (plus de CDN jsdelivr)
- Fix : `renderFill` non défini (réécriture complète de quiz.js)

### v1.5.x
- Désactivation archive CPT `/quiz` — liste via page WP + `[rdr_quiz_list]`
- Slug CPT `quiz-it` pour libérer `/quiz/`
- Skeletons de chargement dans la liste
- CSS entièrement réécrit sur variables `--rdr-*` du thème
- Suppression du scroll automatique entre questions
- Fix LiteSpeed Cache : nonce auto-refresh, `check_ajax_referer` retiré des endpoints publics
- Fix spinner infini (`[hidden]` écrasé par `display:flex`)
- Fix boucle infinie dans `the_content` (flag `static $running`)

### v1.4.x
- Nouveau : type de question "Phrase à trou" (GAP)
- Nouveau : corps de question enrichi (TinyMCE)
- Filtres admin AJAX multi-tags ET + recherche par ID
- Difficulté par question
- Page statistiques avec graphique 30 jours

### v1.3.x
- Page statistiques admin
- Historique anonyme RGPD
- Stats par question
- Reset par quiz ou global

### v1.2.x
- Tags sur les questions (`rdr_question_tag`)
- Shortcode + bloc Gutenberg `[rdr_quiz_link]`
- Pleine largeur sans sidebar sur les pages quiz
- Bouton "Démarrer" visible dans les cards

### v1.1.x
- Fix filtre tag front (sérialisation WP)
- Filtre par tag dans la liste archive
- Fix `&#8211;` dans les excerpts

### v1.0.0
- Version initiale
- CPT rdr_quiz + rdr_question
- 5 types de questions (O/N, QCU, QCM, TRI, CLI)
- Statistiques de base
- Compatible Polylang
