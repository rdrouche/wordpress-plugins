# RDR Newsletter Recap — Plugin WordPress

Newsletter hebdomadaire avec gestion des abonnés, personnalisation par flux RSS, tracking ouvertures/clics, réengagement automatique et publication web des éditions.

---

## Installation

1. Uploader le dossier `rdr-newsletter/` dans `wp-content/plugins/`
2. Activer dans **Extensions → Extensions installées**
3. **⚠️ Indispensable** : aller dans **Réglages → Permaliens** et cliquer **Enregistrer** (flush des rewrite rules — sans ça, toutes les URLs du plugin retournent 404)
4. Configurer dans **Newsletter → Settings**

---

## Shortcodes

### `[rdrnl_newsletter_page]`

**Recommandé pour la page archive.** À placer dans une vraie page WP — GeneratePress gère automatiquement le layout et la sidebar. La page `/newsletter/{slug}/` continue de fonctionner via le CPT.

```
[rdrnl_newsletter_page per_page="12" columns="2"]
```

| Paramètre | Défaut | Description |
|-----------|--------|-------------|
| `per_page` | 12 | Éditions par page |
| `columns` | 2 | Colonnes : 1 à 4 |

**Mise en place** : Créer une page WP → coller le shortcode → la page bénéficie du layout GP complet (sidebar, header, footer, dark/light mode).

---

### `[rdrnl_newsletter]`

Bloc d'éditions à intégrer n'importe où (home page, sidebar, article) :

```
[rdrnl_newsletter count="3" columns="3"]
```

---

### `[rdrnl_subscribe]`

Formulaire d'inscription à la newsletter.

```
[rdrnl_subscribe
  title="Recevoir le récap hebdomadaire"
  description="Chaque vendredi — actus IT + nouveaux tutos."
  show_name="yes"
  button="S'inscrire"
  style="card"
]
```

| Paramètre | Valeurs | Défaut |
|-----------|---------|--------|
| `style` | `card` / `inline` / `minimal` | `card` |
| `show_name` | `yes` / `no` | `yes` |
| `title` | Texte libre | — |
| `description` | Texte libre | — |
| `button` | Texte du bouton | `S'inscrire` |

---

### `[rdrnl_profile_request]`

Formulaire pour qu'un abonné reçoive son lien de personnalisation par email. **Réservé aux abonnés confirmés.**

```
[rdrnl_profile_request
  title="Accéder à mes préférences"
  description="Entrez votre email pour recevoir votre lien."
  button="Recevoir mon lien"
  style="card"
]
```

---

## URLs générées automatiquement

| URL | Description |
|-----|-------------|
| `/newsletter/` | Archive des éditions publiées |
| `/newsletter/{slug}/` | Édition individuelle (ex: `semaine-12-2026`) |
| `/feed/rdrnl-editions/` | Flux RSS des éditions |
| `/editions/profil/{slug}/` | Page profil abonné (accès par token) |

---

## Flux complet abonné

```
[rdrnl_subscribe]
    ↓
Email double opt-in (lien valable 24h)
    ↓
Confirmation → active (ou waitlist si limite atteinte)
    ↓
Email de bienvenue (contient le lien de personnalisation)
    ↓
Newsletter hebdo chaque vendredi à 12h
    ↓
Dans chaque email : bouton "Gérer mes préférences" (token 24h)
    ↓
Page profil : 3 flux RSS personnalisés, max 10 articles/flux
```

---

## Tâches planifiées (WP Cron)

| Hook | Déclenchement | Rôle |
|------|--------------|------|
| `rdrnl_cron_weekly` | Vendredi 12h (configurable) | Envoi auto + rappel la veille |
| `rdrnl_process_batch` | Toutes les 2 min (pendant l'envoi) | Traitement par lot de 50 emails |
| `rdrnl_cron_reengagement` | Mardi 12h | Détection inactifs 28j → email de réengagement |
| `rdrnl_cron_cleanup` | Mercredi 12h | Suppression abonnés sans réponse après 14j |

> **Conseil** : sur un site peu fréquenté, configurer un vrai cron système :
> ```
> */5 * * * * curl https://rdr-it.com/wp-cron.php
> ```

---

## Gestion des abonnés

### Statuts
- `active` — abonné confirmé, reçoit les emails
- `pending` — en attente de confirmation double opt-in
- `waitlist` — confirmé mais liste pleine, en attente d'une place
- `unsubscribed` — désinscrit

### Limite d'abonnés (Mailjet free : 1000)
Configurable dans **Newsletter → Settings → Max subscribers**. `0` = illimité.

Quand la liste est pleine, les nouvelles inscriptions confirmées vont en `waitlist`. À chaque désinscription, le premier en attente est automatiquement promu en `active` et reçoit un email de notification.

---

## Tracking

### Pixel d'ouverture
- URL : `/?rdrnl_track=open&tk={token}`
- Renvoie un GIF 1×1 transparent
- Fiabilité ~60-70% (Apple Mail Privacy ouvre les pixels automatiquement côté serveur)

#### Activation / désactivation (conformité CNIL 🇫🇷)
Le pixel d'ouverture dépose un traceur qui, en France, relève du suivi soumis au
consentement selon les recommandations de la CNIL. Il est donc **désactivable** :

**Newsletter → Settings → Confidentialité & suivi → Pixel d'ouverture**

- **Coché** (défaut) : le pixel est inséré dans les emails, les taux d'ouverture sont mesurés.
- **Décoché** : aucun pixel n'est inséré, aucune donnée d'ouverture n'est collectée. Les
  compteurs d'ouverture restent à zéro pour les envois suivants.

Le **suivi des clics** (redirection des liens) reste actif indépendamment du pixel — il est
nécessaire au fonctionnement des liens et au réengagement, et ne dépose pas de traceur côté
destinataire. La page **Newsletter → Tracking** affiche un bandeau indiquant l'état courant du pixel.

### Tracking clic
- Tous les liens de l'email passent par `/?rdrnl_track=click&sub={id}&url={dest}`
- Redirect 302 vers la destination
- Fiabilité élevée
- **Exemples non-trackés** : liens de désinscription, profil, pixel

### Réengagement automatique
- **Critère** : aucune ouverture ET aucun clic depuis 28 jours
- **Mardi 12h** : email de réengagement envoyé, lien valable 14 jours
- **Mercredi 12h** : sans réponse → `unsubscribed` + promotion waitlist automatique

---

## Compose — fonctionnalités

### Sélecteur d'articles (bouton "Article du site")
Bouton **"Article du site"** dans la section Liens & Articles. Ouvre une modal de recherche par titre sur tous tes CPT configurés (Polylang FR). Utile pour mettre en avant un article plus ancien qui ne figure pas dans les 7 derniers jours automatiquement chargés, ou n'importe quel CPT (cours, domotique, etc.).

### Notes par item
Chaque article, lien ou item RSS peut recevoir une **note personnelle** et une **catégorie thématique**. La note apparaît sous l'item dans l'email et sur la page web de l'édition.

### Liens alternatifs
Bouton **"Ajouter des liens alternatifs"** sur chaque item → champ URL + label. Permet de proposer la même actu vue par plusieurs sources. Liens externes en `nofollow target="_blank"`.

### Reset brouillon
Bouton **"Reset brouillon"** dans la toolbar de la compose. Supprime le brouillon en cours (sélections, notes, liens manuels) et repart de zéro. À utiliser si le brouillon est dans un état incohérent ou pour repartir sur une semaine vierge.

### Envoi par lot
L'envoi ne bloque pas le serveur : file d'attente WP Cron, 50 emails toutes les 2 minutes. Une barre de progression s'affiche en temps réel dans la compose.

---

## Structure des fichiers

```
rdr-newsletter/
├── rdr-newsletter.php          ← Plugin principal, constantes, includes, activation
├── README.md                   ← Ce fichier
├── inc/
│   ├── db.php                  ← Table abonnés, CRUD, promotion waitlist
│   ├── feed-cache.php          ← Cache partagé des flux RSS (15 min, par URL)
│   ├── feeds.php               ← Fetch flux RSS + posts du site (Polylang FR)
│   ├── draft.php               ← Gestion du brouillon en cours (option WP)
│   ├── mailer.php              ← Système d'envoi batch + wrapping liens tracking
│   ├── scheduler.php           ← WP Cron weekly + batch processor
│   ├── tracking.php            ← Pixel, click, réengagement, cleanup
│   ├── subscribers.php         ← Flux inscription, double opt-in, welcome email
│   ├── profile.php             ← CPT rdrnl_profile, tokens d'accès, feeds perso
│   ├── edition.php             ← CPT rdrnl_edition, RSS, publication web
│   ├── shortcode.php           ← [rdrnl_subscribe] + [rdrnl_profile_request]
│   ├── ajax.php                ← Tous les handlers AJAX admin + front
│   └── admin.php               ← Pages admin (Compose, Subscribers, Settings, Tracking, Doc)
├── templates/
│   ├── email.php               ← Template HTML email (dark, responsive)
│   ├── profile.php             ← Template page profil abonné
│   ├── edition-single.php      ← Template édition individuelle
│   └── edition-archive.php     ← Template archive /newsletter/
└── assets/
    ├── admin.css               ← Styles interface admin
    ├── admin.js                ← JS admin (compose, picker, subscribers, queue)
    ├── front.css               ← Styles front (dark/light via variables CSS thème)
    └── front.js                ← JS front (formulaires inscription + profil)
```

---

## Tables base de données

### `wp_rdrnl_subscribers`
| Colonne | Type | Description |
|---------|------|-------------|
| `id` | BIGINT | ID auto |
| `email` | VARCHAR(191) | Email unique |
| `name` | VARCHAR(191) | Nom (optionnel) |
| `status` | ENUM | `active` / `pending` / `waitlist` / `unsubscribed` |
| `token` | VARCHAR(64) | Token actif (confirmation ou désinscription) |
| `token_expiry` | DATETIME | Expiration du token |
| `subscribed_at` | DATETIME | Date d'inscription |
| `confirmed_at` | DATETIME | Date de confirmation |
| `ip` | VARCHAR(64) | IP à l'inscription |

### `wp_rdrnl_tracking`
| Colonne | Type | Description |
|---------|------|-------------|
| `id` | BIGINT | ID auto |
| `subscriber_id` | BIGINT | FK vers subscribers |
| `edition_slug` | VARCHAR(191) | Slug de l'édition |
| `pixel_token` | VARCHAR(64) | Token du pixel d'ouverture |
| `sent_at` | DATETIME | Date d'envoi |
| `opened_at` | DATETIME | Première ouverture |
| `last_click_at` | DATETIME | Dernier clic |
| `open_count` | INT | Nombre d'ouvertures |
| `click_count` | INT | Nombre de clics |
| `reengagement_sent_at` | DATETIME | Date d'envoi email réengagement |
| `reengagement_token` | VARCHAR(64) | Token réengagement |
| `reengagement_expiry` | DATETIME | Expiration (14j) |

---

## CPT WordPress

| CPT | Slug | Archive | Rôle |
|-----|------|---------|------|
| `rdrnl_edition` | `newsletter` | Oui (`/newsletter/`) | Éditions publiées, version web |
| `rdrnl_profile` | `newsletter/profil` | Non | Profils abonnés (privés) |

---

## Checklist d'installation

- [ ] Uploader et activer le plugin
- [ ] **Réglages → Permaliens → Enregistrer**
- [ ] **Newsletter → Settings** : From name, From email, limite abonnés, catégories, flux RSS
- [ ] Configurer SMTP (WP Mail SMTP, FluentSMTP, etc.)
- [ ] Placer `[rdrnl_subscribe]` sur une page
- [ ] Optionnel : `[rdrnl_profile_request]` sur une page "Mes préférences"
- [ ] **Newsletter → Compose → Refresh** → créer le premier brouillon
- [ ] Tester via "Send Test" sur votre email
- [ ] Vérifier **Newsletter → Tracking** après le premier envoi réel

---

## JavaScript

### Admin (`admin.js`)
Utilise **jQuery** — disponible nativement dans l'admin WordPress. Wrappé dans `(function($){...})(jQuery)`.

### Front (`front.js`)
**100% Vanilla JS — aucune dépendance jQuery.**
- Fetch API pour les requêtes AJAX
- `addEventListener` / `querySelector` natifs
- Compatible LiteSpeed Cache (pas de cookie)
- Aucun conflit avec les thèmes qui désactivent jQuery en front

## Compatibilité

- WordPress 6.0+
- PHP 8.0+
- Polylang (optionnel — le plugin détecte automatiquement et filtre les posts en FR)
- LiteSpeed Cache compatible (toggle dark/light 100% localStorage, pas de cookie)
- Testé avec GeneratePress (thème enfant RDR-IT)
