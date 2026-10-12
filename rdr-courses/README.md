# RDR-IT Courses — Plugin WordPress

Plugin de gestion de cours pour RDR-IT.com. Alternative légère à LearnDash, conçu sur mesure pour le workflow rdr-it.com.

---

## Installation

1. Dézipper dans `/wp-content/plugins/rdr-courses/`
2. Activer dans **Extensions** → **Extensions installées**
3. Aller dans **Cours RDR-IT** → **📋 Gérer les cours**

---

## Fonctionnalités

### CPT enregistrés
- `rdrc_course` — Cours (avec image, excerpt, contenu)
- `rdrc_lesson` — Leçons (articles/vidéos/TP)
- Taxonomies : `rdrc_cat` (catégories), `rdrc_level` (niveau)

### URLs générées
- Archive cours : `/course/`
- Page cours : `/course/active-directory-les-bases/`
- Leçon : `/lesson/presentation-active-directory/`

### Builder de structure (meta-box)
Dans l'éditeur d'un **Cours**, la meta-box "Structure" permet de :
- Ajouter des **sections** (chapitres)
- Ajouter des **leçons existantes** via un picker
- Créer une nouvelle leçon directement
- Réordonner par **glisser-déposer**

### Navigation automatique
Sur chaque leçon, le plugin génère automatiquement :
- **Breadcrumb** : Accueil › Cours › [Nom du cours] › [Section]
- **Prev/Next** : boutons leçon précédente / suivante
- **Sommaire dépliable** : table des matières du cours avec leçon active mise en évidence

### Shortcodes

```
[rdrc_course_grid]
```
Affiche tous les cours en grille de cards.

Paramètres :
- `category="windows-server"` — filtrer par catégorie (slug)
- `level="debutant"` — filtrer par niveau (`debutant`, `intermediaire`, `avance`)
- `count="12"` — nombre de cours
- `columns="3"` — colonnes (1-4)

```
[rdrc_course_content id="123"]
```
Affiche le sommaire complet d'un cours (sections + leçons cliquables + bouton "Commencer le cours").

---

### `[rdrc_progress_bar]` — barre de suivi d'une leçon

Barre compacte affichant la position dans le module et dans le parcours, avec
bouton « Marquer comme lu » et navigation précédent / suivant.
Prévue pour les leçons, TP et quiz.

```
[rdrc_progress_bar]
```

Sans attribut, la leçon courante et son cours parent sont détectés automatiquement.
Placez-la **dans le contenu d'une leçon**, ou **en widget** de sidebar (bloc
*Shortcode* ou widget *Texte*).

**Paramètres**

| Attribut | Défaut | Rôle |
|---|---|---|
| `lesson_id` | auto | forcer la leçon |
| `course_id` | auto | forcer le cours |
| `show_module` | `1` | jauge du module (section courante) |
| `show_course` | `1` | jauge du parcours complet |
| `show_nav` | `1` | liens précédent / suivant |
| `show_mark` | `1` | bouton « Marquer comme lu » |
| `layout` | `auto` | `auto`, `compact` ou `full` |

**Exemples**

```
[rdrc_progress_bar]                                  <!-- tout affiché -->
[rdrc_progress_bar show_nav="0" layout="compact"]    <!-- sidebar étroite -->
[rdrc_progress_bar show_module="0"]                  <!-- parcours seul -->
[rdrc_progress_bar lesson_id="482" course_id="120"]  <!-- contexte forcé -->
```

**Sur la page de présentation d'un cours**, le même shortcode bascule
automatiquement en **vue parcours** : jauge globale, détail module par module,
et bouton *Commencer* / *Reprendre* / *Revoir* pointant sur la première leçon
non terminée. Cette vue est **injectée automatiquement** au-dessus du contenu du
cours — inutile d'ajouter le shortcode à la main. Pour la désactiver :

```php
add_filter( 'rdrc_auto_course_progress', '__return_false' );
```

Le sommaire du cours affiche par ailleurs une pastille ✓ sur chaque leçon
terminée, et un lien *Réinitialiser ma progression* apparaît dès qu'au moins une
leçon est validée.

**Adaptation à l'espace** — la mise en page réagit à la largeur du *conteneur*
(container queries), pas à celle de l'écran. Sous 420 px les jauges s'empilent
et le bouton passe pleine largeur ; sous 260 px les libellés MODULE / PARCOURS
disparaissent. Une barre en pleine page et une autre en sidebar s'affichent donc
correctement côte à côte sur un même écran.

---

### `[rdrc_my_courses]` — vue d'ensemble de tous les parcours

Page « Mes formations » : bandeau de synthèse, filtres, et une carte par
parcours avec sa progression et un bouton contextuel
(*Commencer* / *Reprendre* / *Revoir*). Le bouton *Reprendre* pointe
directement sur la **première leçon non terminée**.

```
[rdrc_my_courses]
```

**Paramètres**

| Attribut | Défaut | Rôle |
|---|---|---|
| `count` | `-1` | nombre de parcours (`-1` = tous) |
| `category` | — | filtrer par slug de `rdrc_cat` (séparés par virgules) |
| `level` | — | filtrer par slug de `rdrc_level` |
| `columns` | `3` | colonnes de la grille (1–4) |
| `orderby` | `title` | `title`, `date` ou `menu_order` |
| `order` | `ASC` | `ASC` ou `DESC` |
| `show_stats` | `1` | bandeau de synthèse en haut |
| `show_filter` | `1` | filtres Tous / En cours / Terminés / Non commencés |
| `empty_text` | — | message si aucun parcours |

**Exemples**

```
[rdrc_my_courses]
[rdrc_my_courses columns="2" show_filter="0"]
[rdrc_my_courses category="linux,docker" orderby="date" order="DESC"]
[rdrc_my_courses count="6" show_stats="0"]
```

Le bandeau de synthèse totalise : parcours commencés, parcours terminés,
leçons terminées, et progression globale (toutes leçons confondues).

---

### Stockage de la progression

**Source unique : `localStorage`** — aucun cookie n'est utilisé.
Tous les affichages (barre de leçon, vue parcours, sommaire, page « Mes
formations ») lisent la même clé, donc restent cohérents entre eux.

Pour un **utilisateur connecté**, la base (`wp_usermeta`) sert de **miroir** :

- au chargement, la progression enregistrée en base est réinjectée dans
  `localStorage` — l'avancement se retrouve donc sur un autre appareil ou après
  un vidage du cache navigateur ;
- ce qui n'existait qu'en local (progression faite avant connexion) est
  automatiquement remonté en base, sans écraser l'existant ;
- chaque validation est répercutée en base en arrière-plan.

Pour un visiteur **anonyme**, tout reste strictement local au navigateur.

- Clé : `rdrc_progress_<courseId>`
- Valeur : `{ "<lessonId>": <timestamp>, ... }`

C'est la même clé que le suivi anonyme intégré au plugin : les affichages
restent donc cohérents entre eux. Un écouteur `storage` synchronise les onglets
ouverts sur un même cours.

**À savoir :** la progression étant strictement locale au navigateur, elle ne
suit pas l'utilisateur d'un appareil à l'autre et disparaît si les données de
navigation sont effacées. Si `localStorage` est indisponible (navigation privée
stricte, quota saturé), l'affichage bascule en lecture seule sans erreur.

---

## Workflow recommandé

### Créer un cours complet

1. **Cours RDR-IT → Ajouter un cours**
   - Titre, description, image à la une
   - Paramètres : icône, durée, niveau, gratuit/payant

2. **Meta-box "Structure"** :
   - Cliquer "+ Ajouter une section" → nommer la section
   - Cliquer "+ Ajouter une leçon existante" ou "Créer une nouvelle leçon"
   - Répéter pour chaque chapitre
   - Réordonner par glisser-déposer si besoin

3. **Chaque leçon** :
   - Se rédige comme un post WordPress normal
   - La meta-box "Infos leçon" permet de définir : type (article/vidéo/TP/quiz), durée, URL vidéo
   - Le cours parent est automatiquement assigné

4. **Page d'accueil des cours** :
   Ajouter le shortcode `[rdrc_course_grid]` dans n'importe quelle page.

7. **Créer la page « Mes formations »**
   Nouvelle page → y placer `[rdrc_my_courses]`. Les visiteurs y retrouvent
   leurs parcours en cours et peuvent reprendre là où ils s'étaient arrêtés.

8. **Ajouter le suivi dans les leçons**
   Placer `[rdrc_progress_bar]` en haut du contenu des leçons, ou en widget de
   sidebar avec `[rdrc_progress_bar show_nav="0" layout="compact"]`.

---

## Exemple — Cours Active Directory

Structure dans la meta-box :

```
📁 Introduction
  📄 Présentation de l'Active Directory
  📄 Historique
  📄 Glossaire

📁 Notions de base
  📄 Définition de domaine, arbre et forêt
  📄 L'annuaire Active Directory
  📄 Les contrôleurs de domaine
  📄 Authentification : NTLM et Kerberos

📁 Installation d'un contrôleur de domaine
  📄 Planifier l'installation
  🔧 TP – Déployer un environnement Active Directory
...
```

---

## Traduction (Polylang)

Toutes les chaînes front sont enregistrées via `pll_register_string()` dans le
groupe **RDR-IT Courses**, visible dans **Polylang → Traductions de chaînes** :
boutons de navigation, libellés des jauges, badges, niveaux, textes de la vue
d'ensemble. Sans Polylang, le plugin retombe sur `__()` avec le domaine
`rdr-courses`.

Pour ajouter une chaîne : la déclarer dans `rdrc_i18n_strings()`
(`includes/i18n.php`), puis l'afficher avec `rdrc__('ma_cle')` ou
`rdrc_e('ma_cle')`.

---

## Thème clair/sombre

Le CSS utilise les variables CSS de votre thème enfant GeneratePress (`data-theme="dark|light"`). Tout s'adapte automatiquement.

---

## Fichiers

```
rdr-courses/
├── rdr-courses.php          ← Plugin principal
├── includes/
│   ├── cpt.php              ← Enregistrement CPT + taxonomies
│   ├── meta-boxes.php       ← Builder structure, paramètres cours, infos leçon
│   ├── navigation.php       ← Breadcrumb, prev/next, TOC
│   ├── shortcodes.php       ← [rdrc_course_grid], [rdrc_course_content]
│   ├── progress-bar.php     ← [rdrc_progress_bar], [rdrc_my_courses]
│   ├── progress.php         ← Suivi (BDD connectés / localStorage anonymes)
│   ├── resources.php        ← Ressources complémentaires des leçons
│   ├── widgets.php          ← Zones de widgets + layout sidebar GP
│   ├── i18n.php             ← Chaînes traduisibles (Polylang)
│   ├── course-builder.php   ← Constructeur de parcours (drag & drop)
│   └── rewrite.php          ← URLs /course/slug/lecon/
├── assets/
│   ├── css/courses.css      ← Styles front (dark/light)
│   ├── js/courses.js        ← JS front (TOC toggle)
│   └── admin/
│       ├── admin.css        ← Styles admin
│       └── admin.js         ← Builder drag & drop
└── README.md
```
