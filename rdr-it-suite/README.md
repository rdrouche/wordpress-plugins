# RDR-IT Suite

> Meta plugin WordPress — Gestionnaire centralisé de modules (sous-plugins) pour [rdr-it.com](https://rdr-it.com).

**Version :** 1.1.0  
**Auteur :** [Romain Drouche](https://rdr-it.com)  
**Licence :** GPL-2.0-or-later  
**Requis :** WordPress ≥ 6.0 · PHP ≥ 8.1

---

## Présentation

RDR-IT Suite est un **meta plugin** : il ne fait rien par lui-même, mais il fournit une infrastructure pour charger, activer et désactiver des **modules** (sous-plugins) depuis un dashboard d'administration unifié.

Chaque module est un dossier autonome dans `modules/`. Il suffit de le déposer et de l'activer depuis le Dashboard — aucun autre plugin à installer.

---

## Installation

1. Déposez le dossier `rdr-it-suite/` dans `wp-content/plugins/`.
2. Activez **RDR-IT Suite** dans *Extensions → Extensions installées*.
3. Accédez à **RDR-IT Suite** dans le menu d'administration.

---

## Modules fournis

| Slug | Nom | Description | Settings |
|---|---|---|---|
| `rdr-seo-tools` | 🔍 SEO Tools | Meta, Open Graph, version masquée, noindex | ✅ |
| `rdr-performance` | ⚡ Performance | Emoji, embeds, query strings, lazy-load, DNS prefetch | ✅ |
| `rdr-security` | 🛡️ Security | XML-RPC, REST anon, version WP, X-Powered-By, éditeur fichiers | ✅ |

---

## Intégrer un nouveau module

### 1. Structure minimale

```
wp-content/plugins/rdr-it-suite/modules/
└── mon-module/
    ├── module.json        ← manifeste obligatoire
    └── mon-module.php     ← point d'entrée PHP
```

Pour exposer une page de réglages, ajoutez optionnellement :

```
└── mon-module/
    ├── module.json
    ├── mon-module.php
    └── settings-view.php  ← formulaire de réglages (optionnel)
```

### 2. Manifeste `module.json`

```json
{
  "slug":        "mon-module",
  "name":        "Mon Module",
  "description": "Description courte affichée dans le Dashboard.",
  "version":     "1.0.0",
  "author":      "Prénom Nom",
  "icon":        "🚀",
  "entry":       "mon-module.php",
  "min_version": "1.0.0"
}
```

| Clé | Obligatoire | Description |
|---|---|---|
| `slug` | ✅ | Identifiant unique kebab-case |
| `name` | ✅ | Nom affiché dans le Dashboard |
| `description` | ✅ | Description courte |
| `version` | ✅ | Version SemVer du module |
| `author` | — | Auteur |
| `icon` | — | Emoji affiché sur la card (défaut : 🔧) |
| `entry` | — | Fichier PHP à inclure (défaut : `{slug}.php`) |
| `min_version` | — | Version minimale de RDR-IT Suite requise |

### 3. Fichier d'entrée `mon-module.php`

```php
<?php
defined( 'ABSPATH' ) || exit;

// Attendre le hook de chargement du module (recommandé)
add_action( 'rdr_suite_module_loaded_mon-module', static function (): void {

    // Lire un réglage
    $val = RDR_Module_Settings::get( 'mon-module', 'ma_cle', 'defaut' );

    // Enregistrer fonctionnalités via hooks WP
    add_action( 'wp_head', static function () use ( $val ): void {
        // ...
    } );

} );
```

> **Règle :** Ne jamais exécuter de code directement au niveau racine du fichier. Toujours passer par des hooks WordPress.

### 4. Page de réglages `settings-view.php` (optionnel)

Si ce fichier existe, un sous-menu apparaît automatiquement dans le menu *RDR-IT Suite* et un lien "⚙ Réglages" s'affiche sur la card du module quand il est actif.

```php
<?php
// Variables disponibles : $slug, $module, $settings (array des valeurs sauvegardées)
defined( 'ABSPATH' ) || exit;

$ma_valeur = $settings['ma_cle'] ?? 'defaut';
?>

<div class="rdr-settings-group">
    <h2 class="rdr-settings-group__title">Mes réglages</h2>

    <!-- Toggle switch -->
    <div class="rdr-field">
        <label class="rdr-field__label rdr-field__label--switch">
            <span class="rdr-switch">
                <input type="checkbox" name="settings[mon_option]" value="1"
                    <?php checked( '1', $settings['mon_option'] ?? '0' ); ?>>
                <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
            </span>
            <span>
                <strong>Activer la fonctionnalité</strong>
                <small>Description de ce que ça fait.</small>
            </span>
        </label>
    </div>

    <!-- Champ texte -->
    <div class="rdr-field">
        <label class="rdr-field__label" for="mon_champ">Ma clé API</label>
        <input type="text" id="mon_champ" name="settings[ma_cle]"
               class="rdr-input" value="<?php echo esc_attr( $ma_valeur ); ?>">
    </div>
</div>
```

Les réglages sont sauvegardés automatiquement via AJAX sur soumission du formulaire.

### 5. API `RDR_Module_Settings`

```php
// Lire une valeur
$val = RDR_Module_Settings::get( 'mon-module', 'ma_cle', 'defaut' );

// Lire toutes les valeurs du module
$all = RDR_Module_Settings::all( 'mon-module' );

// Écrire une valeur
RDR_Module_Settings::set( 'mon-module', 'ma_cle', $nouvelle_valeur );

// Écrire plusieurs valeurs d'un coup
RDR_Module_Settings::set_many( 'mon-module', [
    'cle_1' => 'valeur_1',
    'cle_2' => 'valeur_2',
] );

// Supprimer une clé
RDR_Module_Settings::delete( 'mon-module', 'ma_cle' );

// Supprimer tous les réglages du module
RDR_Module_Settings::purge( 'mon-module' );
```

### 6. Hook de sanitisation personnalisée

Par défaut, chaque valeur passe par `sanitize_textarea_field()`. Pour une sanitisation spécifique :

```php
add_filter( 'rdr_sanitize_settings_mon-module', static function ( array $raw ): array {
    return [
        'mon_url'   => esc_url_raw( $raw['mon_url']   ?? '' ),
        'mon_entier'=> absint(      $raw['mon_entier'] ?? 0  ),
    ];
} );
```

---

## Architecture

```
rdr-it-suite/
├── rdr-it-suite.php                    ← Point d'entrée + constantes + bootstrap
├── includes/
│   ├── class-rdr-module-loader.php     ← Charge les modules actifs, gère activate/deactivate
│   ├── class-rdr-module-registry.php   ← Scanne /modules/, lit les module.json
│   └── class-rdr-module-settings.php   ← Store clé/valeur par module (wp_options)
├── admin/
│   ├── class-rdr-admin.php             ← Menu WP Admin, AJAX toggle + save settings
│   └── views/
│       ├── dashboard.php               ← Page principale — grille de modules
│       ├── module-settings-wrap.php    ← Chrome commun pour toutes les pages réglages
│       └── about.php                   ← Page "À propos" + changelog
├── assets/
│   ├── css/admin.css                   ← Styles (variables RDR-IT, dark mode)
│   └── js/admin.js                     ← Vanilla JS — toggle AJAX + save settings AJAX
└── modules/
    ├── rdr-seo-tools/
    │   ├── module.json
    │   ├── rdr-seo-tools.php
    │   └── settings-view.php
    ├── rdr-performance/
    │   ├── module.json
    │   ├── rdr-performance.php
    │   └── settings-view.php
    └── rdr-security/
        ├── module.json
        ├── rdr-security.php
        └── settings-view.php
```

---

## Principes de développement

- **Zéro jQuery** — JavaScript Vanilla uniquement
- **Design RDR-IT** — Variables CSS `--rdr-*` alignées sur le thème enfant GeneratePress
- **Sécurité** — Nonce unifié `rdr_suite_nonce`, `current_user_can('manage_options')`, `sanitize_key()`, `esc_html()` systématiques
- **Modules autonomes** — un dossier = un sous-plugin, zéro couplage
- **PHP 8.1+** — types stricts, arrow functions, `readonly`, `match`
- **SemVer A.B.C** — versionning sémantique strict

---

## Versionning SemVer A.B.C

| Position | Signification | Exemple de changement |
|---|---|---|
| **A** majeur | Rupture de compatibilité | Refonte API module, renommage constantes |
| **B** mineur | Nouvelle fonctionnalité rétrocompatible | Nouveau hook, nouveau champ `module.json`, nouveau module fourni |
| **C** patch | Correction de bug | Fix JS/CSS, typo, comportement incorrect |

### Procédure de release

```bash
# 1. Mettre à jour la version dans :
#    - rdr-it-suite.php  (Version: A.B.C  +  define RDR_SUITE_VERSION)
#    - README.md         (**Version :** A.B.C)

# 2. Ajouter une entrée dans le Changelog (README + page About)

# 3. Commit + tag
git add .
git commit -m "chore: release vA.B.C"
git tag vA.B.C
git push && git push --tags
```

---

## Changelog

### 1.1.0 — 2026-04-12
- Ajout page de réglages par module (`settings-view.php` autodétecté)
- Ajout `RDR_Module_Settings` — store clé/valeur isolé par module
- Page "À propos" avec infos environnement + changelog
- Modules SEO Tools, Performance, Security enrichis (lecture des settings, hooks conditionnels)
- AJAX `rdr_save_module_settings` avec hook de sanitisation par module
- Nonce unifié `rdr_suite_nonce`
- Lien "⚙ Réglages" sur les cards actives
- Formulaire settings avec switches CSS natifs (zéro JS supplémentaire)

### 1.0.0 — 2026-04-12
- Version initiale
- Dashboard admin avec grille de modules
- Activation/désactivation par toggle AJAX (Vanilla JS)
- Découverte automatique via `module.json`
- 3 modules fournis : SEO Tools, Performance, Security

---

## Licence

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) — © 2026 Romain Drouche / [rdr-it.com](https://rdr-it.com)
