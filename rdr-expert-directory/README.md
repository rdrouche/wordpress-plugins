# RDR Expert Directory

Plugin WordPress d'annuaire d'experts IT pour rdr-it.com.

- **Version :** 1.5.8
- **Thème requis :** GeneratePress (parent ou enfant)
- **Plugins compatibles :** Polylang, RankMath, WP Premium Access (WPA)

---

## Installation

1. Uploader le ZIP dans **Extensions → Ajouter**
2. Activer le plugin
3. Aller dans **Réglages → Permaliens → Enregistrer** (flush rewrite rules)
4. Vérifier que les pages `/experts/` et `/mon-profil-expert/` ont été créées
5. Configurer dans **Experts IT → ⚙️ Paramètres**
6. *(Optionnel)* Créer une page "Messagerie" avec le shortcode `[rdr_expert_inbox]`

> ⚠️ Après mise à jour : désactiver puis réactiver le plugin pour appliquer les migrations de table et les nouveaux crons.

---

## Capacités custom (`wpa_*`)

Les capacités utilisent le préfixe `wpa_` pour s'intégrer avec WP Premium Access.
Toutes les occurrences dans le plugin passent par `RDR_ED_Capabilities::CAP_*`.

| Constante         | Valeur                      | Qui                  | Usage                                    |
|-------------------|-----------------------------|----------------------|------------------------------------------|
| `CAP_VALIDATE`    | `wpa_validate_expert`       | Admin                | Valider / refuser les fiches             |
| `CAP_DEACTIVATE`  | `wpa_deactivate_expert`     | Admin                | Désactiver une fiche manuellement        |
| `CAP_SKILLS`      | `wpa_manage_expert_skills`  | Admin                | Gérer la taxonomie compétences           |
| `CAP_SUBMIT`      | `wpa_submit_expert_profile` | Expert (front)       | Soumettre / modifier sa propre fiche     |

**Pour changer un nom :** modifier uniquement la valeur string dans `includes/class-capabilities.php`.
**Après modification :** désactiver puis réactiver le plugin (pour que `grant_admin_caps()` mette à jour le rôle admin).

---

## Shortcodes

### `[rdr_expert_directory]`

Annuaire AJAX des experts avec filtres, pagination et skeleton loading.

| Paramètre      | Défaut (admin) | Description                                  |
|----------------|----------------|----------------------------------------------|
| `per_page`     | `12`           | Nombre d'experts par page                    |
| `show_filters` | `true`         | Afficher la barre de filtres (true/false)     |

```
[rdr_expert_directory per_page="9" show_filters="true"]
```

**Comportement :**
- Chargement AJAX au premier affichage (skeleton → cartes)
- Nonce anti-cache injecté dans `data-nonce` (compatible Redis/Varnish)
- Filtre par compétence, pays, langue, type (indépendant/entreprise)
- Les experts non premium sont masqués pour les visiteurs anonymes
- Les admins voient toutes les fiches actives

---

### `[rdr_expert_profile_form]`

Formulaire de gestion du profil expert (front-end). Accès restreint aux utilisateurs avec `CAP_SUBMIT`.

Aucun paramètre. À placer sur la page `/mon-profil-expert/`.

**Contenu du formulaire :**
- Identité (prénom, nom, titre, type, entreprise)
- Photo de profil + bannière (upload AJAX direct, dossier `/uploads/rdr-experts/{user_id}/`)
- Compétences (taxonomie + propositions libres soumises à validation)
- Langues + pays
- Présentation rich text (TinyMCE)
- Références clients rich text (TinyMCE)
- Moyens de contact (email, téléphone, LinkedIn) avec option révélation
- Liens (LinkedIn entreprise, Credly, site web)
- Option noindex SEO
- Messagerie interne (messages reçus, onglets Nouveaux/Lus — **réservé aux comptes premium**)
- Zone de danger : suppression définitive du profil (confirmation `DeleteMyProfil`)

---

### `[rdr_expert_inbox]`

Boîte de réception de messagerie interne. Accès restreint aux experts connectés **premium**.

Aucun paramètre. À placer sur une page dédiée (ex: `/messagerie-expert/`).

**Comportement :**
- Onglets : Nouveaux / Lus / Spam / Supprimés
- Affiche expéditeur, email, téléphone, message complet
- Actions : marquer lu, supprimer
- Compte des messages non lus affiché dans le titre
- Invisible aux utilisateurs non connectés ou non premium

**Page recommandée :**
```
Slug de la page : messagerie-expert
Contenu : [rdr_expert_inbox]
```

---

### `[rdr_expert_status]`

Widget compact résumant le statut de l'expert connecté. Idéal pour une sidebar, un widget ou un bloc conditionnel.

> ⚠️ **Authentification WPA (magic link)** — Par défaut, rien n'est affiché aux visiteurs non connectés. Utiliser `show_login="true"` + `login_url` pour afficher un lien personnalisé vers la page WPA.

| Paramètre      | Défaut               | Description                                                    |
|----------------|----------------------|----------------------------------------------------------------|
| `roles`        | `expert_it`          | Rôle(s) autorisant l'affichage, séparés par virgule           |
| `cap`          | `wpa_submit_expert_profile` | Capacité autorisant l'affichage                        |
| `require_both` | `false`              | `true` = rôle ET capacité requis / `false` = rôle OU capacité |
| `inbox_page`   | `messagerie-expert`  | Slug de la page messagerie (pour le lien messages non lus)     |
| `show_login`   | `false`              | `true` = afficher un lien si non connecté                      |
| `login_text`   | `Accéder à l'espace Expert` | Texte du lien connexion (nécessite `show_login="true"`) |
| `login_url`    | _(vide)_             | URL de la page WPA magic link (nécessite `show_login="true"`)  |

```
[rdr_expert_status inbox_page="messagerie-expert"]
```

Avec restriction par rôle WPA :
```
[rdr_expert_status roles="expert_it,premium_basic" require_both="false" inbox_page="messagerie-expert"]
```

**Affiche (si accès autorisé) :**
- 📨 Nombre de messages non lus → lien vers `inbox_page` (premium uniquement)
- ⏳ Révision en attente → lien vers le formulaire profil
- 👤 Mon profil expert + statut Actif/Inactif → lien vers le formulaire profil

**N'affiche rien** si l'utilisateur n'est pas connecté ou n'a pas le rôle/la capacité requis.

**Usage typique pour conditionner les widgets WP :**
Le rôle `expert_it` est assigné automatiquement lors de l'activation du profil. Il peut être utilisé avec les plugins de conditions de widgets (ex : "Widget Logic", blocs Gutenberg conditionnels) pour afficher des contenus réservés aux experts.

---

### `[rdr_expert_activate]`

Bouton d'activation du profil expert ou lien de gestion selon l'état de l'utilisateur.

> ⚠️ **Authentification WPA (magic link)** — Le plugin ne génère jamais de lien vers `wp-login.php`.
> Par défaut, les visiteurs non connectés ne voient rien. Utiliser `show_login="true"` + `login_url` pour afficher un lien personnalisé vers la page WPA.

| Paramètre        | Défaut                        | Description                                                        |
|------------------|-------------------------------|--------------------------------------------------------------------|
| `roles`          | `expert_it`                   | Rôle(s) autorisant l'accès au bouton, séparés par virgule         |
| `cap`            | `wpa_submit_expert_profile`   | Capacité autorisant l'accès au bouton                             |
| `require_both`   | `false`                       | `true` = rôle ET capacité requis / `false` = rôle OU capacité     |
| `label_activate` | `Activer mon profil Expert`   | Texte du bouton d'activation (utilisateur éligible sans profil)   |
| `label_manage`   | `Gérer mon profil Expert`     | Texte du bouton si le profil expert est déjà actif                 |
| `show_login`     | `false`                       | `true` = afficher un lien si non connecté                          |
| `login_text`     | `Accéder à l'espace Expert`   | Texte du lien connexion (nécessite `show_login="true"`)            |
| `login_url`      | _(vide)_                      | URL de la page WPA magic link (nécessite `show_login="true"`)      |

```
[rdr_expert_activate
  roles="expert_it,premium_basic"
  cap="wpa_submit_expert_profile"
  require_both="false"
  label_activate="Devenir Expert IT"
  label_manage="Mon espace Expert"
  show_login="true"
  login_text="Accéder à l'espace Expert"
  login_url="/mon-acces-premium/"]
```

**Comportement selon l'état :**
- **Non connecté + `show_login="false"`** → rien (silencieux)
- **Non connecté + `show_login="true"`** → lien vers `login_url` (page WPA, pas `wp-login.php`)
- **Connecté, déjà rôle `expert_it` OU cap `CAP_SUBMIT`** → lien "Gérer mon profil Expert"
- **Connecté, accès OK selon `roles`/`cap`, pas encore activé** → bouton AJAX → assigne rôle `expert_it` + cap → redirige vers `/mon-profil-expert/`
- **Connecté, sans accès** → rien (silencieux)

**Logique d'accès au bouton d'activation :**
Le paramètre `roles` définit quels rôles WP peuvent voir le bouton. Par exemple, si tu veux que seuls les abonnés premium (`premium_basic`) puissent activer un profil expert :
```
[rdr_expert_activate roles="premium_basic" cap=""]
```

---

### `[rdr_featured_experts]`

Experts mis en avant du jour. Chargement AJAX + skeleton. Invisible aux robots d'indexation.

| Paramètre | Défaut           | Description                               |
|-----------|------------------|-------------------------------------------|
| `limit`   | `3`              | Nombre d'experts à afficher               |
| `title`   | `Experts du jour`| Titre de la section (vide = pas de titre) |

```
[rdr_featured_experts limit="3" title="Experts du jour"]
```

**Comportement :**
- Les experts affichés sont tirés aléatoirement parmi les experts mis en avant
- Le tirage quotidien est géré par un cron à 6h00 (configurable)
- Le pourcentage d'experts mis en avant est configurable dans les Paramètres
- Utilise `wp_options` (pas de transients) → compatible Redis/Varnish
- Invisible aux robots d'indexation (détection User-Agent côté PHP)

---

## Paramètres admin (Experts IT → ⚙️ Paramètres)

### Compétences

| Clé                    | Défaut | Description                                          |
|------------------------|--------|------------------------------------------------------|
| `skill_max`            | `5`    | Nombre max de compétences par fiche                  |
| `skill_allow_free_tags`| `1`    | Autoriser les experts à proposer de nouvelles compétences |
| `skill_free_tags_review`| `1`  | Les nouvelles compétences nécessitent une validation admin |

### Notifications workflow

| Clé                    | Défaut | Description                                          |
|------------------------|--------|------------------------------------------------------|
| `notif_admin_submit`   | `1`    | Email admin à chaque soumission de fiche             |
| `notif_expert_approved`| `1`    | Email expert quand sa fiche est validée              |
| `notif_expert_rejected`| `1`    | Email expert quand sa fiche est refusée              |
| `notif_admin_email`    | `''`   | Email admin (vide = `admin_email` WP)                |
| `notif_from_name`      | `RDR-IT Annuaire` | Nom expéditeur des emails                 |
| `notif_from_email`     | `''`   | Email expéditeur (vide = défaut WP)                  |

### Annuaire

| Clé                    | Défaut | Description                                          |
|------------------------|--------|------------------------------------------------------|
| `directory_per_page`   | `12`   | Experts par page par défaut                          |
| `directory_order`      | `rand` | Tri : `rand`, `date`, `name`, `modified`             |
| `directory_show_type`  | `1`    | Afficher badge Indépendant/Entreprise sur les cartes |
| `directory_show_country`| `1`   | Afficher le pays sur les cartes                      |
| `directory_show_lang`  | `1`    | Afficher la langue sur les cartes                    |

### Contact

| Clé                    | Défaut | Description                                          |
|------------------------|--------|------------------------------------------------------|
| `contact_form_enabled` | `1`    | Activer le formulaire de contact (global)            |
| `contact_email_reveal` | `1`    | Autoriser la révélation directe de l'email           |

### Experts mis en avant

| Clé                      | Défaut | Description                                        |
|--------------------------|--------|----------------------------------------------------|
| `featured_percentage`    | `20`   | % d'experts mis en avant (1–100)                   |
| `notif_internal_message` | `1`    | Email immédiat à l'expert à chaque message reçu    |
| `notif_message_reminder` | `1`    | Rappel quotidien si messages non lus               |
| `notif_reminder_hour`    | `8`    | Heure d'envoi du rappel (0 = 00:00, 8 = 08:00…)   |

### Autres

| Clé                       | Défaut | Description                                       |
|---------------------------|--------|---------------------------------------------------|
| `shortlink_enabled`       | `1`    | Activer les shortlinks `/go/expert/{id}/`         |
| `rankmath_default_noindex`| `0`    | Mettre les nouvelles fiches en noindex par défaut |

---

## Architecture des fichiers

```
rdr-expert-directory/
├── rdr-expert-directory.php          ← Point d'entrée, autoload, hooks activation
├── uninstall.php                     ← Nettoyage complet à la désinstallation
├── README.md
├── includes/
│   ├── class-capabilities.php        ← Rôle expert_it, capacités wpa_*, shortcodes activate
│   ├── class-contact-protection.php  ← Anti-scraping 6 couches (HMAC, challenge JS, rate limit)
│   ├── class-featured.php            ← Experts du jour (cron 6h, wp_options, shortcode, settings)
│   ├── class-free-tags.php           ← Tags libres + validation admin
│   ├── class-gp-compat.php           ← Fallbacks GP via méthodes statiques (pas de fonctions globales)
│   ├── class-media.php               ← Upload photos/bannières, dossier dédié, page admin médias
│   ├── class-messaging.php           ← Messagerie interne, rappels email, shortcodes inbox/status
│   ├── class-notifications.php       ← Emails workflow (soumission, validation, refus)
│   ├── class-polylang.php            ← Intégration Polylang (sync metas, hreflang, chaînes)
│   ├── class-post-type.php           ← CPT expert_profile (rewrites natifs WP)
│   ├── class-premium.php             ← WPA try/catch + cache mémoire
│   ├── class-profile-deletion.php    ← Suppression totale profil (DeleteMyProfil)
│   ├── class-rankmath.php            ← noindex par fiche + non-premium
│   ├── class-settings.php            ← Page paramètres admin + getter centralisé
│   ├── class-shortlinks.php          ← /go/expert/ID/ + compteur de clics
│   ├── class-taxonomy.php            ← expert_skill, expert_language, expert_country
│   └── class-versioning.php          ← Pending + 2 versions archivées
├── admin/
│   ├── class-admin.php               ← Méta boxes, colonnes liste, pages admin, AJAX admin
│   ├── css/admin.css
│   └── js/admin.js
└── public/
    ├── class-ajax.php                ← Révélation contact sécurisée + formulaire contact
    ├── class-profile-form.php        ← [rdr_expert_profile_form] + AJAX save
    ├── class-shortcode.php           ← [rdr_expert_directory] + AJAX load experts
    ├── css/expert-directory.css
    ├── js/expert-directory.js
    └── templates/
        ├── archive-expert_profile.php
        ├── page-mon-profil-expert.php
        ├── profile-form-i18n-section.php ← Switcher de langue (liens basés sur page courante)
        ├── profile-form.php               ← Formulaire profil complet
        └── single-expert_profile.php      ← Fiche expert publique
```

---

## Sécurité

- **Nonce anti-cache :** injecté dans `data-nonce` du shortcode (compatible Redis/Varnish)
- **Rate limiting :** via `wp_options` (pas de transients, compatible Redis)
- **Honeypot + timing :** sur tous les formulaires front
- **Challenge JS HMAC :** pour la révélation de contacts
- **Auto-message bloqué :** un expert ne peut pas s'envoyer un message
- **Auto-contact bloqué :** formulaire masqué sur sa propre fiche
- **Upload MIME check :** vérification réelle du type MIME + filtre `upload_mimes` temporaire

---

## Messagerie interne

### Table SQL : `{prefix}rdr_ed_messages`

| Colonne           | Type      | Description                              |
|-------------------|-----------|------------------------------------------|
| `id`              | BIGINT    | Clé primaire                             |
| `post_id`         | BIGINT    | ID de la fiche expert destinataire       |
| `sender_name`     | VARCHAR   | Nom de l'expéditeur                      |
| `sender_firstname`| VARCHAR   | Prénom                                   |
| `sender_email`    | VARCHAR   | Email                                    |
| `sender_phone`    | VARCHAR   | Téléphone (optionnel)                    |
| `message`         | TEXT      | Contenu du message                       |
| `status`          | ENUM      | `new` / `read` / `spam` / `deleted`      |
| `created_at`      | DATETIME  | Date de réception                        |
| `ip_hash`         | VARCHAR   | Hash SHA-256 de l'IP (traçabilité)       |

### Protection anti-spam

1. **Honeypot** — champ caché `website_internal`
2. **Timing** — délai minimum 1s entre affichage et soumission (tolérance ±5s)
3. **Rate limiting IP** — max 3 messages / 10 minutes (via `wp_options`)
4. **Akismet** — si le plugin est actif, vérification du contenu

### Rappels email (anti-doublon)

Le système envoie **au maximum 1 rappel par expert par tranche de nouveaux messages** :
- J+0 : nouveau message → rappel envoyé → stockage `{sent_at, count}`
- J+1 : même message non lu → **aucun rappel** (pas de nouveaux messages depuis J+0)
- J+1 : nouveau message reçu → rappel envoyé (nouveaux messages détectés depuis J+0)

---

## Médias experts

- **Dossier :** `/wp-content/uploads/rdr-experts/{user_id}/`
- **Protégé :** `.htaccess` avec `Options -Indexes`
- **Traçabilité :** meta `_rdr_ed_uploader` = user_id sur chaque attachment
- **Page admin :** Experts IT → 🖼️ Médias (liste avec aperçu, type, uploader, fiche liée)
- **Suppression :** automatique lors de la suppression du profil (et via uninstall.php)

---

## Changelog

### v1.5.8
- `[rdr_expert_activate]` : paramètres `roles`, `cap`, `require_both`, `show_login`, `login_url`, `login_text`
- `[rdr_expert_status]` : mêmes paramètres d'accès
- Helper centralisé `user_has_expert_access()` (logique OR/AND)
- Rôle `expert_it` assigné au clic "Activer" pour le conditionnement des widgets WP
- Suppression du lien `wp-login.php` — auth gérée par WPA (magic link)
- Doc : note WPA sur tous les shortcodes concernés

### v1.5.8
- Fix upload : filtre `upload_mimes` temporaire pour les non-admins
- Fix timing messagerie : seuil 1s + tolérance désynchronisation ±5s
- Rappels email quotidiens pour messages non lus (anti-doublon, heure configurable)
- Paramètre `inbox_page` sur `[rdr_expert_status]`

### v1.5.8
- Fix div mal fermé dans profile-form → sidebar GP repositionnée correctement
- Fix auto-contact sur sa propre fiche (côté template)
- Fix upload nonce (data-upload-nonce fresh, anti-cache)
- Cards : 2 colonnes + fix chevauchement avatar/bannière

### v1.5.8
- Fix TDZ JS (`search` déclaré avant `load()`) → annuaire fonctionnel
- Shortcodes `[rdr_expert_status]` et `[rdr_expert_activate]`
- Messagerie front intégrée au formulaire profil (premium uniquement)
- CSS sidebar GP + spinner upload

### v1.5.8
- Fix annuaire vide → réécriture complète `ajax_load()`
- Messagerie inline supprimée du formulaire → shortcode `[rdr_expert_inbox]`
- Références en rich text (wp_editor)
- Suppression `wp.media` → upload AJAX direct sans dépendance admin

### v1.5.8
- `class-media.php` : upload dédié + page admin médias
- `class-profile-deletion.php` : suppression totale avec confirmation `DeleteMyProfil`
- Messagerie front premium dans le formulaire profil
- Fix sidebar GP layout

### v1.5.8
- `class-messaging.php` : messagerie interne + Akismet + rate limiting
- `class-featured.php` : experts du jour (cron 6h, wp_options)
- `[rdr_featured_experts]` : AJAX + skeleton + invisible aux bots
- `[rdr_expert_inbox]` : boîte de réception front
- Fix annuaire : nonce anti-cache dans `data-nonce`

### v1.3.x
- Fix `sync_shared_metas` : signature `array $translations` (Polylang)
- Fix `pre_get_posts` → fusionné dans `parse_filter()`
- Fix GP compat : suppression des fonctions globales → méthodes statiques

### v1.2.x — v1.1.x
- Corrections apostrophes, accolades, acces wpa_
- Fix `Cannot redeclare generate_do_attr()`
- Capacités renommées `wpa_*`
- Uninstall script complet
