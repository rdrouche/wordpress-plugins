# WP IT Problem

**Version:** 1.0.0
**Author:** RDR-IT — [rdr-it.com](https://rdr-it.com)
**Licence:** Propriétaire
**Requires:** WordPress 6.0+, PHP 8.0+
**Compatible:** Polylang, LiteSpeed Cache, GeneratePress, WP Premium Access

Plugin WordPress permettant de publier des problèmes IT (avec contexte, infra, blocs de code) et de programmer la révélation de la réponse à une date définie (ex. J+7). Le countdown est affiché en temps réel côté front, la réponse est injectée en AJAX sans rechargement.

---

## Sommaire

- [Installation](#installation)
- [Concepts](#concepts)
- [Post Types](#post-types)
- [Taxonomies](#taxonomies)
- [Meta keys](#meta-keys)
- [Shortcodes](#shortcodes)
- [REST API](#rest-api)
- [Hooks et Filtres](#hooks-et-filtres)
- [Polylang](#polylang)
- [WP Premium Access](#wp-premium-access)
- [FAQ](#faq)
- [Structure des fichiers](#structure-des-fichiers)

---

## Installation

1. Uploader le ZIP dans **Extensions → Ajouter → Uploader**
2. Activer le plugin
3. Aller dans **IT Problems → Settings** :
   - Définir le délai de révélation par défaut (en jours)
   - Activer ou désactiver le countdown côté front
4. Cliquer **Update database tables** pour créer les tables
5. Créer une page native, y coller le shortcode `[itpb_list]`

### Pages à créer manuellement

| Contenu | Shortcode à insérer |
|---|---|
| Liste des problèmes | `[itpb_list]` |

> L'intégration se fait via shortcode dans une page native pour conserver le squelette du thème (sidebar, header, footer).

---

## Concepts

### Flux de publication

```
Admin crée un it_problem (publié immédiatement)
└── Admin clique "Create and link answer"
    └── Un it_answer est créé (statut : Private)
        └── Admin rédige la réponse dans Gutenberg
            └── À reveal_at (UTC) : cron flip Private → Publish
                └── Front : countdown → 0 → AJAX inject réponse
```

### Deux posts, un lien

Chaque problème est lié à une réponse via deux meta croisées :

- `it_problem` → `_itpb_answer_id` → ID du `it_answer`
- `it_answer` → `_itpb_problem_id` → ID du `it_problem`

La réponse a son propre éditeur Gutenberg complet (images, code, tableaux, etc.).

### Countdown AJAX

Le JS (`frontend.js`) poll l'endpoint REST `GET /itpb/v1/status/{id}` :
- Toutes les **30 secondes** quand `time_left > 5 minutes`
- Toutes les **10 secondes** dans les **5 dernières minutes**

Quand `revealed: true` → `GET /itpb/v1/answer/{id}` → injection HTML dans `#itpb-answer-zone` → dispatch `itpb:revealed`.

---

## Post Types

### `it_problem`

| Propriété | Valeur |
|---|---|
| Slug URL | `/it-problem/{slug}/` |
| Archive | Non (intégration via shortcode) |
| Gutenberg | Oui |
| Supports | `title`, `editor`, `thumbnail`, `comments`, `revisions` |
| Visible dans Recherche | Oui |
| Visible dans Sitemap | Oui |

### `it_answer`

| Propriété | Valeur |
|---|---|
| Slug URL | `/it-answer/{slug}/` |
| Archive | Non |
| Gutenberg | Oui |
| Supports | `title`, `editor`, `revisions` |
| Visible dans Recherche | Non (exclu via `pre_get_posts`) |
| Visible dans Sitemap | Non (`sitemap_excluded: true`) |
| Statut initial | `private` → `publish` au reveal |
| Menu admin | Sous-menu de IT Problems |

> L'accès direct à `/it-answer/` est bloqué pour les visiteurs tant que la réponse n'est pas publiée — redirection vers le problème parent.

---

## Taxonomies

### `itpb_infra` — Infrastructure

Hiérarchique (comme les catégories). Exemples : Windows, Linux, Linux → Ubuntu, Docker, Kubernetes, Réseau.

```
IT Problems → Infrastructure → Gérer les termes
```

### `itpb_difficulty` — Difficulté

Plate, vocabulaire fixe (seeded à l'activation). Radio buttons dans l'éditeur (un seul niveau par problème).

| Slug | Label | Couleur front |
|---|---|---|
| `beginner` | Beginner | Vert cyan |
| `intermediate` | Intermediate | Amber |
| `expert` | Expert | Rouge |

---

## Meta keys

Constantes définies dans `wp-it-problem.php` :

| Constante | Meta key | Post | Type | Description |
|---|---|---|---|---|
| `ITPB_META_REVEAL_AT` | `_itpb_reveal_at` | `it_problem` | `string` | Datetime UTC `Y-m-d H:i:s` |
| `ITPB_META_ANSWER_ID` | `_itpb_answer_id` | `it_problem` | `int` | ID du `it_answer` lié |
| `ITPB_META_PROBLEM_ID` | `_itpb_problem_id` | `it_answer` | `int` | ID du `it_problem` parent |
| `ITPB_META_INFRA` | `_itpb_infra` | `it_problem` | `string` | Contexte infra (texte libre) |
| `ITPB_META_DIFFICULTY` | `_itpb_difficulty` | `it_problem` | `string` | Slug de difficulté |

### Helpers PHP

```php
// Date de révélation (DateTimeImmutable UTC ou null)
$dt = itpb_get_reveal_at( $problem_id );

// Est-ce que la réponse est révélée maintenant ?
$ok = itpb_is_revealed( $problem_id );

// Récupérer le post it_answer lié
$answer = itpb_get_answer_post( $problem_id );

// Récupérer le post it_problem parent depuis une réponse
$problem = itpb_get_problem_post( $answer_id );
```

---

## Shortcodes

### `[itpb_list]`

Liste des problèmes IT dans une page native. Pagination native WP.

```
[itpb_list
  limit="10"
  infra="linux,docker"
  difficulty="intermediate,expert"
  status="all"
  orderby="date"
  order="DESC"
  show_infra="true"
  show_status="true"
  show_excerpt="true"
]
```

| Attribut | Valeurs | Défaut | Description |
|---|---|---|---|
| `limit` | `1`–`100` | `10` | Problèmes par page |
| `infra` | slugs séparés par `,` | vide | Filtre par infrastructure |
| `difficulty` | `beginner`, `intermediate`, `expert` | vide | Filtre par difficulté |
| `status` | `all`, `revealed`, `pending` | `all` | Filtre par état de révélation |
| `orderby` | `date`, `reveal_at`, `title` | `date` | Tri |
| `order` | `ASC`, `DESC` | `DESC` | Sens du tri |
| `show_infra` | `true`/`false` | `true` | Afficher les tags infra + difficulté |
| `show_status` | `true`/`false` | `true` | Afficher le badge Revealed/Pending |
| `show_excerpt` | `true`/`false` | `true` | Afficher l'extrait (2 lignes) |

### `[itpb_countdown id="123"]`

Countdown standalone pour un problème spécifique. Utilisable n'importe où sur le site (widgets, pages, articles).

```
[itpb_countdown id="123"]
```

| Attribut | Type | Requis | Description |
|---|---|---|---|
| `id` | `int` | Oui | ID du post `it_problem` |

Affiche "The answer is now available." avec lien si déjà révélé.

---

## REST API

### `GET /wp-json/itpb/v1/status/{id}`

Statut de révélation d'un problème. Endpoint public, rate-limité (30 req/IP/min).

**Réponse :**
```json
{
  "revealed":  false,
  "reveal_at": 1748822400,
  "time_left": 43172,
  "cached":    true
}
```

| Champ | Type | Description |
|---|---|---|
| `revealed` | `bool` | La réponse est-elle publiée ? |
| `reveal_at` | `int\|null` | Unix timestamp UTC de révélation |
| `time_left` | `int` | Secondes restantes (0 si révélé) |
| `cached` | `bool` | Résultat depuis le cache transient |

Headers : `Cache-Control: public, max-age=10` (pending) / `max-age=0` (non révélé).

### `GET /wp-json/itpb/v1/answer/{id}`

Contenu HTML de la réponse. Retourne 403 si non révélé.

**Réponse révélée :**
```json
{
  "revealed": true,
  "html":     "<div class=\"itpb-answer-header\">…</div>"
}
```

**Réponse non révélée (HTTP 403) :**
```json
{
  "revealed":  false,
  "time_left": 43172
}
```

Headers : `Cache-Control: public, max-age=3600` une fois révélé (compatible LiteSpeed).

### Paramètre `?lang=`

Compatible Polylang. `GET /wp-json/itpb/v1/status/42?lang=fr` redirige automatiquement vers la version FR du problème si elle existe.

---

## Hooks et Filtres

### Actions

| Hook | Arguments | Description |
|---|---|---|
| `itpb_loaded` | — | Tous les composants sont initialisés |
| `itpb_activated` | — | Plugin activé |
| `itpb_deactivated` | — | Plugin désactivé |
| `itpb_answer_created` | `$answer_id, $problem_id` | Réponse créée et liée |
| `itpb_answer_revealed` | `$problem_id, $answer_id` | Réponse publiée par le cron |
| `itpb_cron_complete` | `$count, $datetime_utc` | Fin du job cron quotidien |

### Filtres

| Filtre | Arguments | Description |
|---|---|---|
| `itpb_list_query_args` | `array $args` | Args `WP_Query` du shortcode `[itpb_list]` |
| `itpb_api_problem_post` | `\WP_Post\|null, \WP_REST_Request` | Post problem dans les endpoints REST |

### Event JS `itpb:revealed`

Dispatché sur `document` après injection de la réponse. Écouter pour re-lancer la coloration syntaxique :

```js
document.addEventListener('itpb:revealed', function(e) {
    // e.detail.node     = HTMLElement #itpb-answer-zone
    // e.detail.problemId = int
    myHighlighter.run(e.detail.node);
});
```

---

## Polylang

Le plugin est entièrement compatible Polylang. Aucune configuration requise : la détection est automatique.

### Ce qui est traduit automatiquement

- Les posts `it_problem` et `it_answer` apparaissent dans l'interface de traduction Polylang
- Les taxonomies `itpb_infra` et `itpb_difficulty` sont translateables
- Les labels statiques UI sont enregistrés via `pll_register_string()` dans **Langues → Traductions de chaînes**

### Synchronisation des liens

Quand une traduction d'un `it_problem` ou `it_answer` est créée via Polylang, les meta `_itpb_answer_id` et `_itpb_problem_id` sont automatiquement mis à jour pour pointer vers les posts de la même langue.

### Shortcode multilingue

`[itpb_list]` filtre automatiquement par `pll_current_language()` — chaque version de la page affiche uniquement les problèmes dans sa langue.

---

## WP Premium Access

Le plugin est conçu pour s'intégrer avec [WP Premium Access](https://rdr-it.com) via les hooks.

### Gate premium sur la réponse (futur)

```php
// Bloquer la révélation pour les non-premium
add_filter( 'itpb_api_problem_post', function( $problem, $request ) {
    if ( ! apply_filters( 'wpa_is_premium', false, get_current_user_id() ) ) {
        return null; // 404 pour les non-premium
    }
    return $problem;
}, 10, 2 );
```

### Notification à la révélation (futur)

```php
// Envoyer un email aux abonnés premium quand une réponse est révélée
add_action( 'itpb_answer_revealed', function( $problem_id, $answer_id ) {
    // … logique d'envoi via ITPB_Install::get subscribers
}, 10, 2 );
```

---

## FAQ

**Le countdown ne démarre pas ?**
Vérifier que la meta `_itpb_reveal_at` est bien sauvegardée (box "Reveal date" dans l'éditeur). En mode admin, le JS n'est pas chargé pour les posts déjà révélés.

**La réponse ne s'affiche pas après le reveal_at ?**
Le cron WP s'exécute sur les visites. Si personne ne visite le site dans les minutes suivant le reveal_at, le cron peut être en retard. Aller dans **IT Problems → Settings → Update database tables** ne le force pas — utiliser WP-CLI : `wp cron event run itpb_daily_reveal`.

**Le it_answer apparaît dans Google ?**
Non : `sitemap_excluded: true`, exclu de `pre_get_posts` search, et statut `private` jusqu'au reveal. Après reveal, il est `publish` mais sans lien entrant naturel — il peut éventuellement être indexé via son permalink `/it-answer/`.

**Comment forcer la révélation manuellement ?**
```php
$cron = new ITPB_Cron();
$count = $cron->trigger_manual();
```
Ou via WP-CLI : `wp cron event run itpb_daily_reveal`

**Comment vider le cache de statut d'un problème ?**
```php
( new ITPB_Cron() )->bust_transient( $problem_id );
```

**Polylang : la liste affiche des problèmes en mauvaise langue ?**
S'assurer que Polylang est activé avant WP IT Problem. L'ordre de chargement est géré par `itpb_boot()` à priorité 20.

**La table `wp_itpb_reveal_log` n'existe pas ?**
Cliquer "Update database tables" dans **IT Problems → Settings**, ou désactiver/réactiver le plugin.

---

## Structure des fichiers

```
wp-it-problem/
├── wp-it-problem.php               # Bootstrap, constantes, autoloader, helpers
├── uninstall.php                   # Nettoyage complet à la suppression
├── assets/
│   ├── css/
│   │   ├── frontend.css            # Countdown, answer, liste — dark/light RDR-IT
│   │   └── admin.css               # Meta boxes, colonnes, settings
│   └── js/
│       ├── frontend.js             # Countdown + REST poll + AJAX reveal (Vanilla)
│       └── admin.js                # Date picker shortcuts, confirm dialog (Vanilla)
├── languages/
│   ├── wp-it-problem.pot           # Template de traduction
│   └── wp-it-problem-fr_FR.po      # Traduction FR (à compléter)
└── includes/
    ├── class-itpb-install.php      # Activation, tables DB, upgrade, uninstall
    ├── class-itpb-i18n.php         # Textdomain, Polylang strings, itpb_str()
    ├── class-itpb-cpt.php          # CPTs it_problem + it_answer, taxonomies
    ├── class-itpb-meta.php         # Meta boxes natives WP (reveal_at, infra, lien)
    ├── class-itpb-admin.php        # Colonnes liste, création answer, settings page
    ├── class-itpb-cron.php         # Cron quotidien, reveal, transient cache
    ├── class-itpb-frontend.php     # Injection contenu, enqueue assets, guard answer
    ├── class-itpb-shortcodes.php   # [itpb_list], [itpb_countdown]
    ├── class-itpb-api.php          # REST /itpb/v1/status + /answer, rate limiting
    └── class-itpb-polylang.php     # Enregistrement CPT/tax, sync meta, lang filter
```

---

## Tables DB

| Table | Description |
|---|---|
| `wp_itpb_subscriptions` | Abonnements aux notifications (réservé futur) |
| `wp_itpb_reveal_log` | Journal des révélations (problem_id, answer_id, datetime, trigger) |

---

*WP IT Problem — développé par [RDR-IT](https://rdr-it.com)*
