# RDR-IT Tools — v7.6.0

Plugin WordPress maison pour rdr-it.com — thème enfant GeneratePress.

---

## Statut jQuery

> **Front-end : jQuery-free** — Aucun fichier JS du plugin ne dépend de jQuery sur le front.
> L'option Tweaks permet de désactiver jQuery globalement si aucun autre plugin/thème ne l'utilise.

---

## Modules

### 🏷️ Badges (`inc/badges.php`)
Remplacements `the_content` : `<<<`/`<<`/`>>` → badges colorés, alertes `%start_p_*%`.

### 🗂️ Tax Manager (`inc/tax-manager.php`)
Taxonomies publiques — position, icône, style, couleur, séparateurs, drag&drop.
**Option par taxonomie** : désactiver le lien vers l'archive (badge sans `<a>`).

### 📚 Related Engine (`inc/related-engine.php`)
Scoring catégories ×3 + tags ×2 + titre ×1. Cache transients. Modes sync/async.

### ↕️ Post Footer (`inc/post-footer.php`)
Hooks GP `generate_after_entry_header` / `generate_after_entry_content`. Partage par post.
Boutons : Ko-fi, partage social (Facebook / X / LinkedIn / Reddit),
et **Google Sources préférées** (`google.com/preferences/source?q=DOMAINE`) —
domaine auto-détecté ou personnalisé, libellé traduisible via Polylang
(groupe « RDR-IT Post Footer »).

### 📡 Feeds (`inc/feeds.php`)
CPT dans le flux RSS. Support Polylang (`pre_get_posts` → `lang`).

### 💰 AdSense & AdBlock (`inc/adsense.php`)
- Lazy load AdSense par capacité
- Détection AdBlock (`window.isAdblockTrue = true` dans `applyAdblockActions`)
- **Notice AdBlock** : bannière bas de page + modale centée
  - HTML libre, JSON multi-langue `{"fr":"...","en":"..."}`
  - Délai configurable par mode (bannière / modale indépendants)
  - Modale : mode aléatoire (1/N pages), mémorisation localStorage TTL configurable
  - Async, invisible aux bots (UA filtering)
  - Désactivation par capacité WP
- AMP Auto Ads

### 🧩 Shortcodes (`inc/shortcodes.php`)
`[rdrit_latest]`, `[rdrit_random]`, `[rdrit_localstorage]`, `[rdrit_localstorage_link]`.

### ✉️ Mailer (`inc/mailer.php`)
Modes : `php` | `smtp` | `mailjet`. Test d'envoi AJAX.

### 📊 Matomo (`inc/matomo.php`)
Injection `wp_footer`. Exclusion par capacité.

### 🔧 Tweaks (`inc/tweaks.php`)
- Cloudflare SSL, image sizes, jQuery Migrate, page parents
- **Désactiver jQuery front** : off / admins / connectés / tout le monde
- LiteSpeed Cache — Widget Visibility

### 🖼️ Lightbox (`inc/lightbox.php`)
GLightbox v3.3.1 bundlé, zéro jQuery.

### 💬 Citations (`inc/quotes.php`)
12 couleurs Bootstrap 5.3. Attribut Gutenberg `rdritQuoteColor`.

### 🌐 Menu Sync (`inc/menu-sync.php`)
Synchronisation menus Polylang. CPT `rdrit_menu_anchor` (#ancres). Diff interactif.

### 🧱 HTML Blocks (`inc/html-blocks.php`)
CPT `rdrit_html_block` + shortcode `[rdrit_block id="slug"]`. Support Polylang.

### 👁️ Widget Visibility (`inc/widget-visibility.php`)
Panneau "Widget Visibilité" dans l'éditeur de widgets Gutenberg.
Conditions : utilisateur, type de page, CPT, archives, taxonomies, langue (Polylang), device CSS.
Hook `wp` (après init de `$wp_query`) pour conditions de page correctes.

### 🔒 Sécurité login (`inc/login-security/`)
- **Anti-bot** : honeypot + math challenge
- **Brute-force** : comptage par IP, ban automatique, prolongation du ban, 403 + page blanche
- **Logs** : table `wp_rdrit_login_logs` — IP, UA, identifiant, hash pwd (sha256), geo, résultat
- **Geo IP** : fichier local `.mmdb` (MaxMind GeoLite2) + fallback `ip-api.com`
- **Reset password** : logging des demandes
- **Stats** : graphique 30 jours (Chart.js), top IPs, bans actifs + débannir

---

## Onglets admin

| Onglet | Description |
|--------|-------------|
| 🏷️ Badges | Remplacements contenu |
| 🗂️ Taxonomies | Gestion taxonomies + lien archive |
| ↕️ Ordre & Espaces | Drag & drop zones |
| ❤️ Ko-fi & Partage | Boutons partage |
| 📚 Articles similaires | Moteur similarité |
| 📡 Flux RSS | CPT + Polylang |
| 💰 AdSense & AdBlock | Annonces + détection + notice bannière/modale |
| 🧩 Shortcodes | Widgets sidebar |
| ✉️ Mail | SMTP / Mailjet |
| 📊 Matomo | Tracking analytics |
| 🔧 Tweaks | jQuery front, LiteSpeed, page parents |
| 🖼️ Lightbox | GLightbox config |
| 💬 Citations | Citations Gutenberg |
| 🌐 Menus | Sync Polylang + Ancres |
| 🧱 Blocs HTML | Blocs HTML réutilisables |
| 📖 Capacités | Doc + diagnostic |
| 🔒 Sécurité login | Anti-brute force + logs + stats |

---

## Geo IP — Installation locale

1. Créer le dossier `wp-content/rdrit-geoip/`
2. Télécharger `GeoLite2-City.mmdb` sur [maxmind.com](https://www.maxmind.com/en/geolite2/signup) (compte gratuit)
3. Déposer le fichier dans le dossier créé
4. Mettre à jour mensuellement (cron ou manuellement)

Sans fichier local → fallback automatique sur `ip-api.com` (45 req/min gratuit).

---

## Cache agressif — points d'attention

Les endpoints AJAX publics (`rdrit_get_related`, `rdrit_sc_load`) utilisent une
vérification de nonce **non bloquante**. Raison : avec LiteSpeed + Cloudflare APO,
la page HTML reste en cache plusieurs jours alors qu'un nonce WordPress expire
au bout de 12–24 h. Un `check_ajax_referer()` bloquant renverrait `-1` et
afficherait du vide. Ces endpoints étant en lecture seule sur des contenus
déjà publiés, il n'y a aucun risque CSRF.

Les transients ne mettent jamais en cache un résultat vide sur la durée complète :
un échec ponctuel figerait sinon l'affichage à vide pendant tout le TTL.

En cas de bloc vide : activer **🔧 Tweaks → Debug front (console)** pour voir
les messages `[RDRIT Related]` et `[RDRIT Shortcode]`.

---

## Handles JS — exclusions LiteSpeed

| Handle | Exclure de |
|--------|-----------|
| `rdrit-adsense-lazy` | Minify + Combine + Defer |
| `rdrit-adblock` | Minify + Combine + Defer |
| `rdrit-sc-async` | Minify + Combine |
| `rdrit-glightbox` | Minify + Combine |
| `rdrit-glightbox-init` | Minify + Combine |
| `rdrit-widget-visibility` | Minify + Combine |

---

## Historique versions

| Version | Description |
|---------|-------------|
| 7.6.0 | Bouton Google Sources préférées dans la barre de partage |
| 7.5.0 | Fix blocs vides avec cache agressif (nonce, transients), debug front |
| 7.4.x | Cron articles planifiés manqués, jQuery front étendu |
| 7.3.0 | Notice AdBlock (bannière+modale), taxo lien archive, jQuery front, README |
| 7.2.x | Module sécurité login complet |
| 7.1.x | Timing widget visibility, feed Polylang |
| 7.0.x | Widget Visibility Gutenberg stable |
| 6.x | Widget Visibility v1, HTML Blocks, Citations, Menu Sync |
| 5.x | Shortcodes, AdSense/AdBlock, Matomo |
| 3.0.0 | Version initiale |
