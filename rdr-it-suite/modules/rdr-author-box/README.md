# Module rdr-author-box

> **Boîte auteur dynamique — RDR-IT Suite**

**Version :** 1.0.3  
**Auteur :** Romain Drouche  
**Compatibilité :** RDR-IT Suite ≥ 1.2.0 · PHP ≥ 8.1 · WordPress ≥ 6.0

---

## Fonctionnalités

- Affichage automatique sur les types de contenu configurés (hook + priorité).
- **Photo de l'auteur** — 3 sources, priorité dans cet ordre :
  1. Photo uploadée directement (envoi AJAX → médiathèque WP)
  2. Photo choisie dans la médiathèque WP (frame `wp.media`)
  3. URL externe collée manuellement
  4. Fallback : gravatar WP natif
  - Bouton **Supprimer la photo** pour revenir au gravatar.
- **Nom affiché** (`display_name`) éditable dans le profil.
- **Titre traduit** par langue Polylang + fallback générique.
- **Bio** : champ natif WP *Renseignements biographiques*.
- **Réseaux sociaux** : activation + ordre par glisser-déposer, icônes SVG inline.
- **Meta box** dans l'éditeur : *Masquer l'Author Box* au cas par cas.
- Dark / Light via variables CSS `--rdr-*`. Zéro jQuery.

---

## Installation

Placer dans `wp-content/plugins/rdr-it-suite/modules/rdr-author-box/`  
puis activer depuis **RDR-IT Suite → Tableau de bord**.

---

## Photo — fonctionnement

Dans **Utilisateurs → Profil** (section *Author Box*) :

| Action | Résultat |
|---|---|
| Choisir un fichier + cliquer *Uploader* | Upload AJAX → stocké en médiathèque |
| Cliquer *Ouvrir la médiathèque* | Sélecteur WP natif |
| Coller une URL externe | Utilisée si aucune photo uploadée |
| Cliquer *Supprimer la photo* | Efface les metas, revient au gravatar |

## Données stockées

| Clé | Type | Description |
|---|---|---|
| `rdr_author_box_post_types` | `wp_options` | Types de contenu |
| `rdr_author_box_hook` | `wp_options` | Hook d'injection |
| `rdr_author_box_priority` | `wp_options` | Priorité hook |
| `rdr_author_box_avatar_size` | `wp_options` | Taille avatar px |
| `rdr_author_box_social_networks` | `wp_options` | Réseaux sociaux |
| `rdr_author_photo_id` | `user_meta` | ID attachment WP |
| `rdr_author_photo_url` | `user_meta` | URL photo uploadée/médiathèque |
| `rdr_author_photo_url_manual` | `user_meta` | URL manuelle externe |
| `rdr_author_title` | `user_meta` | Titre générique |
| `rdr_author_title_{lang}` | `user_meta` | Titre traduit (Polylang) |
| `rdr_social_{key}` | `user_meta` | URL réseau par utilisateur |
| `_rdr_author_box_hide` | `post_meta` | Masquer sur ce post |

---

## Changelog

### 1.0.4 — 2026-04-24
- Fix : le nom Author Box est maintenant stocké dans `rdr_author_display_name` (user_meta dédiée).
- Le champ WP `display_name` n'est plus modifié — les deux sont indépendants.
- Le champ profil affiche un placeholder avec le nom WP courant si la meta est vide.
- Laisser vide = fallback automatique sur le `display_name` WP.

### 1.0.3 — 2026-04-24
- Nouveau : gestion photo — upload direct, médiathèque WP, URL manuelle, suppression.
- Nouveau : prévisualisation immédiate de la photo avant upload.
- Fix : double author box → l'Élément GP doit être désactivé séparément.

### 1.0.2 — 2026-04-24
- Fix guard AJAX (nom de fonction nommée).
- Fix préfixe sécurité `rdr_author_box_`.
- Hook par défaut → `generate_after_entry_content`.
- Meta box "Masquer" par post.

### 1.0.1 — 2026-04-24
- Fix DOMContentLoaded. Pattern AJAX rdrit_save_option.

### 1.0.0 — 2026-04-24
- Version initiale.
