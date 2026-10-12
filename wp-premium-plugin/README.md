# WP Premium Access

**Version:** 1.1.0  
**Auteur:** RDR-IT — [rdr-it.com](https://rdr-it.com)  
**Licence:** Propriétaire  
**Requires:** WordPress 6.0+, PHP 8.0+

Plugin WordPress de gestion d'accès premium sans mot de passe. Authentification par lien magique, gestion des rôles et capacités, intégration Ko-fi/Stripe, API REST et SSO JWT multi-domaine.

---

## Sommaire

- [Installation](#installation)
- [Authentification — Lien Magique](#authentification--lien-magique)
- [Rôles et Capacités](#rôles-et-capacités)
- [Shortcodes](#shortcodes)
- [Intégrations Paiement](#intégrations-paiement)
- [API REST](#api-rest)
- [SSO JWT](#sso-jwt)
- [Hooks et Filtres](#hooks-et-filtres)
- [FAQ](#faq)

---

## Installation

1. Uploader le ZIP dans **Extensions → Ajouter → Uploader**
2. Activer le plugin
3. Aller dans **Premium Access → Settings** et configurer :
   - Token Ko-fi (copié depuis ko-fi.com/manage/webhooks)
   - Couleur accent des emails
   - Pages de redirection post-login
4. Cliquer **Update database** pour créer les tables
5. Coller l'URL webhook Ko-fi dans les settings Ko-fi

### Pages créées automatiquement

| Page | Slug | Shortcode |
|---|---|---|
| Connexion premium | `/connexion-premium/` | `[wpa_login_form]` |
| Mon compte | `/mon-compte-premium/` | `[wpa_account]` |

---

## Authentification — Lien Magique

L'utilisateur saisit son email → reçoit un lien à usage unique (24h) → clique → connecté.

- Cookie WP natif posé (`wp_set_auth_cookie`) — compatible LiteSpeed, Gutenberg, AJAX
- Cookie WPA posé (`wpa_session`) — session propre au plugin
- Max 3 sessions simultanées par utilisateur (FIFO)
- Historique des 30 dernières connexions

### Rate limiting

| Type | Défaut | Configurable |
|---|---|---|
| Par email | 3 demandes / 15 min | Settings → Rate limits |
| Par IP | 10 demandes / 1h | Settings → Rate limits |

**Mode test** — Settings → Test mode : bypasse les rate limits, affiche le lien dans Magic Link Log.

---

## Rôles et Capacités

### Rôles built-in

| Rôle | Capacités par défaut |
|---|---|
| `premium_basic` | `no_ads` |
| `premium_pro` | `no_ads`, `early_access`, `export_md`, `sync` |
| `premium_lifetime` | toutes les capacités |
| `editor` | — |
| `admin` | — |

### Capacités WPA

| Slug | Cap WP | Description |
|---|---|---|
| `no_ads` | `wpa_no_ads` | Suppression des publicités |
| `early_access` | `wpa_early_access` | Accès aux articles en avant-première |
| `export_md` | `wpa_export_md` | Export des articles en Markdown |
| `flux_6` | `wpa_flux_6` | Flux newsletter élargi |
| `sync` | `wpa_sync` | Synchronisation favoris/lire plus tard |
| `priority_support` | `wpa_priority_support` | Support prioritaire |

### Vérification dans le code

```php
// Vérifier si premium
if (current_user_can('wpa_premium')) { }

// Vérifier une capacité spécifique
if (current_user_can('wpa_no_ads')) { }
if (current_user_can('wpa_early_access')) { }

// Via les filtres WPA
$is_premium = apply_filters('wpa_is_premium', false, $user_id);
$expires    = apply_filters('wpa_get_user_expiry', null, $user_id);
$perms      = apply_filters('wpa_user_permissions', [], $user_id);
```

### Déclarer une capacité custom (depuis un autre plugin/thème)

```php
// functions.php ou plugin
add_filter('wpa_register_capabilities', function ($caps) {
    $caps['wpa_submit_expert_profile'] = 'Soumettre un profil expert';
    return $caps;
});
```

La capacité apparaîtra dans **Roles & Capabilities → WP Capabilities** et sera assignable à n'importe quel rôle.

### Rôles WP natifs générés

Pour chaque rôle WPA, un rôle WP natif `wpa_{slug}` est créé automatiquement :

```
premium_basic → wpa_premium_basic
premium_pro   → wpa_premium_pro
```

Ces rôles sont visibles dans LiteSpeed Cache (exclusion du cache), Jetpack (Widget Visibility), Gutenberg (Block Visibility), etc.

---

## Shortcodes

### `[wpa_login_form]`
Formulaire de demande de lien magique.

### `[wpa_account]`
Panneau compte utilisateur.

```
[wpa_account
  show_expiry="yes"
  show_devices="yes"
  early_access_url="/avant-premiere/"
  early_access_label="Avant-première ✨"
  early_access_perm="early_access"
]
```

| Attribut | Valeur | Défaut |
|---|---|---|
| `show_expiry` | `yes`/`no` | `yes` |
| `show_devices` | `yes`/`no` | `yes` |
| `early_access_url` | URL | vide (bouton masqué) |
| `early_access_label` | texte | `Early access` |
| `early_access_perm` | permission WPA | `early_access` |

### `[wpa_premium_gate permission="x"]`
Protège du contenu par permission.

```
[wpa_premium_gate permission="export_md"]
Contenu réservé
[/wpa_premium_gate]
```

### `[wpa_early_access]`
Liste les articles programmés accessibles en avant-première.

```
[wpa_early_access
  post_type="post,tutoriel"
  limit="10"
  order="ASC"
  show_date="yes"
  show_type="no"
]
```

### `[wpa_guest_only]`
Contenu visible uniquement pour les guests (non premium).

### `[wpa_export_button id="123"]`
Bouton export Markdown inline.

### `[wpa_stripe_button price_id="price_xxx"]`
Bouton Stripe Checkout.

---

## Intégrations Paiement

### Ko-fi

1. Configurer le token dans Settings → Ko-fi Verification Token
2. Coller l'URL webhook dans Ko-fi Settings → Webhooks :
   `https://votre-site.com/wp-json/wpa/v1/kofi-webhook`
3. Configurer les tiers de montant (Settings → Ko-fi Amount Tiers)

**Prolongement automatique** — si l'utilisateur fait un nouveau don avant expiration, la durée est ajoutée à partir de la date d'expiration actuelle (pas d'aujourd'hui).

### Stripe

1. Configurer les clés API dans Settings → Stripe
2. Coller l'URL webhook dans Stripe Dashboard → Developers → Webhooks :
   `https://votre-site.com/wp-json/wpa/v1/stripe-webhook`
3. Mapper les Price IDs aux rôles dans Settings → Stripe → Products

---

## API REST

Accès sécurisé par **Bearer token** généré dans **Premium Access → API**.

### Authentification

```http
Authorization: Bearer wpa_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

### Endpoints

| Méthode | URL | Description |
|---|---|---|
| `GET` | `/wp-json/wpa/v1/api/status` | Statut de l'API |
| `GET` | `/wp-json/wpa/v1/api/users` | Liste des utilisateurs |
| `GET` | `/wp-json/wpa/v1/api/users/{id}` | Utilisateur par ID |
| `GET` | `/wp-json/wpa/v1/api/users/email/{email}` | Utilisateur par email |
| `GET` | `/wp-json/wpa/v1/api/me` | Utilisateur courant (cookie ou JWT) |

### Paramètres `/api/users`

| Paramètre | Type | Description |
|---|---|---|
| `page` | int | Page (défaut: 1) |
| `per_page` | int | Résultats par page, max 200 (défaut: 50) |
| `search` | string | Recherche dans username, email, display_name |
| `premium` | `true` | Filtre utilisateurs premium uniquement |

### Structure utilisateur

```json
{
  "id": 35,
  "email": "romain@rdr-it.com",
  "first_name": "Romain",
  "last_name": "Doe",
  "display_name": "Romain",
  "username": "romain",
  "registered_at": "2026-01-15 10:22:00",
  "wp_roles": ["subscriber", "wpa_premium_basic"],
  "wp_caps": ["read", "wpa_premium", "wpa_no_ads"],
  "wpa": {
    "is_premium": true,
    "is_active": true,
    "expires_at": "2026-05-27 00:00:00",
    "is_lifetime": false,
    "roles": [
      {
        "role": "premium_basic",
        "granted_at": "2026-03-26 12:00:00",
        "granted_by": "kofi",
        "expires_at": "2026-05-27 00:00:00",
        "is_active": true,
        "is_lifetime": false
      }
    ],
    "roles_expired": [],
    "permissions": ["no_ads", "early_access", "export_md"]
  }
}
```

---

## SSO JWT

Authentification SSO multi-domaine basée sur JWT HMAC-SHA256.

### Configuration

**Premium Access → SSO** :
- **Allowed origins** — un domaine par ligne, wildcards supportés
- **JWT lifetime** — durée de vie du JWT en secondes (défaut: 300)

### Endpoints publics (sans token admin)

| Méthode | URL | Auth |
|---|---|---|
| `GET` | `/wp-json/wpa/v1/sso/init?redirect=URL` | Session WP/WPA |
| `POST` | `/wp-json/wpa/v1/sso/verify` body: `{"token":"eyJ..."}` | JWT |
| `GET` | `/wp-json/wpa/v1/api/me` | Cookie ou Bearer JWT |

### Flow même domaine (`*.rdr-it.com`)

Le cookie de session est partagé automatiquement — aucun redirect nécessaire.

```javascript
// app.rdr-it.com
const res = await fetch('https://rdr-it.com/wp-json/wpa/v1/api/me', {
  credentials: 'include', // envoie les cookies
});
const { user } = await res.json();
```

### Flow cross-domaine

```javascript
// Étape 1 — rediriger l'utilisateur vers WP
const callback = encodeURIComponent(window.location.origin + '/callback');
window.location.href = 'https://rdr-it.com/wp-json/wpa/v1/sso/init?redirect=' + callback;

// Étape 2 — sur /callback, récupérer le JWT
const jwt = new URLSearchParams(location.search).get('jwt');

// Étape 3 — vérifier côté serveur
const res = await fetch('https://rdr-it.com/wp-json/wpa/v1/sso/verify', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ token: jwt }),
});
const { valid, user } = await res.json();
```

### PHP — vérification JWT

```php
$jwt = $_GET['jwt'] ?? '';
$res = file_get_contents('https://rdr-it.com/wp-json/wpa/v1/sso/verify', false,
  stream_context_create(['http' => [
    'method'  => 'POST',
    'header'  => 'Content-Type: application/json',
    'content' => json_encode(['token' => $jwt]),
  ]])
);
$data = json_decode($res, true);
if (!$data['valid']) { /* rejeter */ }
$user = $data['user'];
```

### Structure du JWT

```json
{
  "iss": "https://rdr-it.com/",
  "aud": "https://app.autre.com",
  "sub": 35,
  "email": "romain@rdr-it.com",
  "iat": 1234567890,
  "exp": 1234568190,
  "jti": "uuid4-single-use",
  "wpa": {
    "is_premium": true,
    "roles": ["premium_basic"],
    "permissions": ["no_ads", "early_access"]
  }
}
```

### Sécurité JWT

- Signé HMAC-SHA256 avec `AUTH_KEY` de `wp-config.php`
- **Usage unique** — le `jti` est marqué utilisé après le premier `/sso/verify`
- **TTL court** — 5 minutes par défaut
- **Audience validée** — l'`aud` doit correspondre au domaine de callback
- **Issuer validé** — l'`iss` doit correspondre à l'URL WP

---

## Hooks et Filtres

### Actions

| Hook | Arguments | Description |
|---|---|---|
| `wpa_role_granted` | `$user_id, $role` | Rôle accordé |
| `wpa_role_revoked` | `$user_id, $role` | Rôle révoqué |
| `wpa_role_expired` | `$user_id, $role` | Rôle expiré (cron) |
| `wpa_roles_revoked_all` | `$user_id` | Tous les rôles révoqués |
| `wpa_send_welcome` | `$user_id` | Premier login premium |
| `wpa_sso_login` | `$user_id` | Connexion SSO réussie |
| `wpa_daily_cron` | — | Cron quotidien |

### Filtres

| Filtre | Arguments | Description |
|---|---|---|
| `wpa_get_user_expiry` | `null, $user_id` | Date d'expiration |
| `wpa_is_premium` | `false, $user_id` | Est premium ? |
| `wpa_user_permissions` | `[], $user_id` | Permissions actives |
| `wpa_register_capabilities` | `[]` | Déclarer des caps custom |
| `wpa_sso_jwt_ttl` | `300` | Durée de vie JWT (secondes) |
| `wpa_sso_allowed_origins` | `[]` | Origines SSO autorisées |
| `wpa_sso_jwt_payload` | `array, $user_id` | Modifier le payload JWT |
| `wpa_mail_headers` | `array, $type` | Headers des emails |
| `wpa_mail_subject` | `string, $type, $user` | Sujet des emails |
| `wpa_mail_body` | `string, $type, $user, $vars` | Corps des emails |
| `wpa_ad_block_html` | `string` | HTML du bloc pub |

---

## FAQ

**Les rôles WPA apparaissent-ils dans LiteSpeed Cache ?**  
Oui — chaque rôle WPA crée un rôle WP natif `wpa_{slug}`. Visible dans LiteSpeed → Exclude → Roles.

**Comment débloquer un utilisateur bloqué par rate limiting ?**  
Premium Access → Settings → Rate limits → bouton "Reset ALL rate limits", ou bouton "Clear" sur la ligne concernée.

**La table `wp_wpa_login_history` n'existe pas ?**  
Cliquer "Update database" dans Settings → Database. Ou désactiver/réactiver le plugin.

**Le webhook Ko-fi retourne 404 ?**  
Aller dans Diagnostic & Docs → cliquer "Fix automatically" pour flusher les permaliens.

**Comment tester le webhook Ko-fi sans vrai paiement ?**  
Premium Access → Settings → Webhook Simulator, ou activer le Mode Test qui affiche les liens dans Magic Link Log.

**Les previews d'articles retournent 404 ?**  
Mettre à jour vers la version 1.1.0 — le filtre `allow_scheduled_posts` excluait les requêtes de prévisualisation.

---

## Structure des fichiers

```
wp-premium-plugin/
├── wp-premium-access.php          # Bootstrap, hooks, filtres publics
├── README.md                      # Cette documentation
├── assets/
│   ├── css/frontend.css           # Styles frontend (dark/light)
│   ├── css/admin.css              # Styles admin
│   ├── js/frontend.js             # JS frontend (Vanilla, no jQuery)
│   └── js/admin.js                # JS admin (jQuery WP)
├── templates/
│   └── gate.php                   # Template gate contenu premium
└── includes/
    ├── class-wpa-install.php      # Tables DB, activation, migration
    ├── class-wpa-roles.php        # Gestion rôles, permissions, expiry
    ├── class-wpa-capabilities.php # Capacités WP natives, sync
    ├── class-wpa-sessions.php     # Sessions (max 3, FIFO)
    ├── class-wpa-auth.php         # Login magic link, redirection SSO
    ├── class-wpa-magic-link.php   # Génération tokens, rate limiting
    ├── class-wpa-content.php      # Gate contenu, early access, export MD
    ├── class-wpa-shortcodes.php   # Tous les shortcodes
    ├── class-wpa-mailer.php       # Emails HTML (magic link, welcome, expiry)
    ├── class-wpa-kofi-webhook.php # Handler webhook Ko-fi
    ├── class-wpa-stripe.php       # Stripe Checkout + webhooks
    ├── class-wpa-api.php          # API REST (tokens admin)
    ├── class-wpa-sso.php          # SSO JWT multi-domaine
    ├── class-wpa-admin.php        # Interface d'administration
    ├── class-wpa-diagnostic.php   # Page diagnostic + docs shortcodes
    ├── class-wpa-sync.php         # Sync favoris/lire plus tard
    ├── class-wpa-widgets.php      # Widgets sidebar
    ├── class-wpa-mailer.php       # Emails
    ├── class-wpa-i18n.php         # Internationalisation
    └── class-wpa-templates.php    # Templates PHP
```

---

*WP Premium Access — développé par [RDR-IT](https://rdr-it.com)*
