<?php
/**
 * Vue : Documentation RDR-IT Suite
 *
 * Onglets :
 *   1. Intégration  — créer un module from scratch
 *   2. Migration    — cohabitation avec le plugin source
 *   3. Starter      — template copier-coller
 *
 * @rdr-suite-context
 * @version        1.2.0
 * @php-min        8.1
 * @wp-min         6.0
 * @rule-namespace Obligatoire — chaque module utilise : namespace RDR\Modules\{PascalSlug};
 * @rule-guard     Obligatoire — vérifier class_exists('NomClasseSource') en tête de module
 * @rule-hooks     Obligatoire — has_action() / has_filter() avant tout add_action front
 * @rule-options   Obligatoire — conserver les clés wp_options du plugin source (pas de renommage)
 * @rule-jquery    Interdit    — Vanilla JS uniquement dans assets/js/
 * @rule-semver    A.B.C — A=rupture, B=fonctionnalité, C=patch
 * @modules-dir    wp-content/plugins/rdr-it-suite/modules/
 * @option-active  rdr_suite_active_modules (array de slugs)
 * @cohabitation   Le plugin source reste actif jusqu'à migration complète de TOUS les modules
 *
 * @package RDR_IT_Suite
 */

defined( 'ABSPATH' ) || exit;

$loader     = rdr_suite();
$active_cnt = count( $loader->get_active() );
$total_cnt  = count( $loader->get_discovered() );
$tab        = sanitize_key( $_GET['rdr_tab'] ?? 'integration' );
$tabs       = [
    'integration' => '📦 Intégration',
    'migration'   => '🔄 Migration',
    'starter'     => '🚀 Starter template',
];
?>
<div class="rdr-wrap" data-theme="dark">

    <!-- ── Header ───────────────────────────────────────────────────── -->
    <header class="rdr-header">
        <div class="rdr-header__brand">
            <span class="rdr-header__logo">
                <span class="rdr-logo-rdr">RDR</span><span class="rdr-logo-it">-IT</span>
            </span>
            <div class="rdr-header__meta">
                <h1 class="rdr-header__title">Documentation</h1>
                <span class="rdr-header__version">v<?php echo esc_html( RDR_SUITE_VERSION ); ?></span>
            </div>
        </div>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-it-suite' ) ); ?>" class="rdr-btn rdr-btn--ghost">
            ← <?php esc_html_e( 'Dashboard', 'rdr-it-suite' ); ?>
        </a>
    </header>

    <!-- ── Onglets ───────────────────────────────────────────────────── -->
    <nav class="rdr-tabs">
        <?php foreach ( $tabs as $key => $label ) : ?>
            <a class="rdr-tab<?php echo $tab === $key ? ' rdr-tab--active' : ''; ?>"
               href="<?php echo esc_url( admin_url( 'admin.php?page=rdr-it-suite-doc&rdr_tab=' . $key ) ); ?>">
                <?php echo esc_html( $label ); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <!-- ══════════════════════════════════════════════════════════════
         ONGLET 1 — INTÉGRATION
         ══════════════════════════════════════════════════════════════ -->
    <?php if ( 'integration' === $tab ) : ?>
    <section class="rdr-section rdr-doc-content">

        <h2 class="rdr-doc-h2">Structure d'un module</h2>
        <p class="rdr-doc-p">Chaque module est un dossier autonome dans <code>modules/</code>. Deux fichiers sont obligatoires :</p>

        <pre class="rdr-code">modules/
└── mon-module/
    ├── module.json        ← manifeste (obligatoire)
    ├── mon-module.php     ← point d'entrée PHP (obligatoire)
    └── settings-view.php  ← formulaire réglages (optionnel)</pre>

        <h2 class="rdr-doc-h2">Le manifeste <code>module.json</code></h2>
        <pre class="rdr-code">{
  "slug":        "mon-module",
  "name":        "Mon Module",
  "description": "Description courte affichée dans le Dashboard.",
  "version":     "1.0.0",
  "author":      "Romain Drouche",
  "icon":        "🚀",
  "entry":       "mon-module.php",
  "min_version": "1.2.0"
}</pre>

        <div class="rdr-doc-table-wrap">
            <table class="rdr-doc-table">
                <thead><tr><th>Clé</th><th>Obligatoire</th><th>Description</th></tr></thead>
                <tbody>
                    <tr><td><code>slug</code></td><td>✅</td><td>Identifiant unique kebab-case</td></tr>
                    <tr><td><code>name</code></td><td>✅</td><td>Nom affiché sur la card Dashboard</td></tr>
                    <tr><td><code>description</code></td><td>✅</td><td>Description courte (1-2 phrases)</td></tr>
                    <tr><td><code>version</code></td><td>✅</td><td>Version SemVer du module</td></tr>
                    <tr><td><code>author</code></td><td>—</td><td>Auteur du module</td></tr>
                    <tr><td><code>icon</code></td><td>—</td><td>Emoji card (défaut : 🔧)</td></tr>
                    <tr><td><code>entry</code></td><td>—</td><td>Fichier PHP à inclure (défaut : <code>{slug}.php</code>)</td></tr>
                    <tr><td><code>min_version</code></td><td>—</td><td>Version minimale de RDR-IT Suite requise</td></tr>
                </tbody>
            </table>
        </div>

        <h2 class="rdr-doc-h2">Le fichier d'entrée PHP</h2>
        <pre class="rdr-code">&lt;?php
/**
 * Module : Mon Module
 * @package RDR_IT_Suite\Modules\MonModule
 */
namespace RDR\Modules\MonModule;  // ← namespace obligatoire

defined( 'ABSPATH' ) || exit;

add_action( 'rdr_suite_module_loaded_mon-module', static function (): void {

    // Lire un réglage
    $val = \RDR_Module_Settings::get( 'mon-module', 'ma_cle', 'defaut' );

    // Enregistrer via hooks WP — jamais de code direct au niveau racine
    add_action( 'wp_head', static function () use ( $val ): void {
        // ...
    } );

} );</pre>

        <h2 class="rdr-doc-h2">La page de réglages <code>settings-view.php</code></h2>
        <p class="rdr-doc-p">Si ce fichier existe, un sous-menu et un lien "⚙ Réglages" apparaissent automatiquement. Variables disponibles : <code>$slug</code>, <code>$module</code>, <code>$settings</code>.</p>

        <pre class="rdr-code">&lt;?php
defined( 'ABSPATH' ) || exit;
$mon_option = $settings['mon_option'] ?? '0';
?&gt;
&lt;div class="rdr-settings-group"&gt;
    &lt;h2 class="rdr-settings-group__title"&gt;Mes réglages&lt;/h2&gt;

    &lt;!-- Toggle switch --&gt;
    &lt;div class="rdr-field"&gt;
        &lt;label class="rdr-field__label rdr-field__label--switch"&gt;
            &lt;span class="rdr-switch"&gt;
                &lt;input type="checkbox" name="settings[mon_option]" value="1"
                    &lt;?php checked( '1', $mon_option ); ?&gt;&gt;
                &lt;span class="rdr-switch__track"&gt;&lt;span class="rdr-switch__thumb"&gt;&lt;/span&gt;&lt;/span&gt;
            &lt;/span&gt;
            &lt;span&gt;
                &lt;strong&gt;Activer la fonctionnalité&lt;/strong&gt;
                &lt;small&gt;Description de ce que ça fait.&lt;/small&gt;
            &lt;/span&gt;
        &lt;/label&gt;
    &lt;/div&gt;

    &lt;!-- Champ texte --&gt;
    &lt;div class="rdr-field"&gt;
        &lt;label class="rdr-field__label" for="mon_champ"&gt;Ma valeur&lt;/label&gt;
        &lt;input type="text" id="mon_champ" name="settings[ma_cle]"
               class="rdr-input" value="&lt;?php echo esc_attr( $settings['ma_cle'] ?? '' ); ?&gt;"&gt;
    &lt;/div&gt;
&lt;/div&gt;</pre>

        <h2 class="rdr-doc-h2">API <code>RDR_Module_Settings</code></h2>
        <pre class="rdr-code">// Lire une valeur
$val = RDR_Module_Settings::get( 'mon-module', 'ma_cle', 'defaut' );

// Lire toutes les valeurs
$all = RDR_Module_Settings::all( 'mon-module' );

// Écrire une valeur
RDR_Module_Settings::set( 'mon-module', 'ma_cle', $val );

// Écrire plusieurs valeurs d'un coup
RDR_Module_Settings::set_many( 'mon-module', [
    'cle_1' => 'valeur_1',
    'cle_2' => 'valeur_2',
] );

// Supprimer une clé
RDR_Module_Settings::delete( 'mon-module', 'ma_cle' );

// Supprimer tous les réglages du module
RDR_Module_Settings::purge( 'mon-module' );</pre>

        <h2 class="rdr-doc-h2">Hook de sanitisation personnalisée</h2>
        <pre class="rdr-code">add_filter( 'rdr_sanitize_settings_mon-module', static function ( array $raw ): array {
    return [
        'mon_url'    => esc_url_raw( $raw['mon_url']    ?? '' ),
        'mon_entier' => absint(      $raw['mon_entier']  ?? 0  ),
        'mon_bool'   => in_array( $raw['mon_bool'], ['0','1'], true ) ? $raw['mon_bool'] : '0',
    ];
} );</pre>

    </section>

    <!-- ══════════════════════════════════════════════════════════════
         ONGLET 2 — MIGRATION
         ══════════════════════════════════════════════════════════════ -->
    <?php elseif ( 'migration' === $tab ) : ?>
    <section class="rdr-section rdr-doc-content">

        <div class="rdr-alert rdr-alert--info">
            <span>ℹ️</span>
            <span>Le plugin source <strong>reste actif</strong> jusqu'à ce que <strong>tous</strong> les modules soient migrés. Les deux cohabitent sans conflit grâce aux règles ci-dessous.</span>
        </div>

        <h2 class="rdr-doc-h2">Règle 1 — Namespace PHP obligatoire</h2>
        <p class="rdr-doc-p">Chaque module déclare son propre namespace pour éviter tout conflit de nom de fonction ou de classe avec le plugin source.</p>
        <pre class="rdr-code">// ✅ Dans chaque module
namespace RDR\Modules\NomDuModule;

// Les fonctions et classes sont isolées
function ma_fonction() { ... }       // = RDR\Modules\NomDuModule\ma_fonction()
class MaClasse { ... }               // = RDR\Modules\NomDuModule\MaClasse

// Appeler le core WP ou RDR Suite depuis un namespace
\add_action( 'wp_head', ... );       // ← antislash obligatoire
\RDR_Module_Settings::get( ... );    // ← antislash obligatoire</pre>

        <h2 class="rdr-doc-h2">Règle 2 — Guard <code>class_exists()</code></h2>
        <p class="rdr-doc-p">Si le plugin source est encore actif, sa classe est déjà instanciée. Le module cède la main plutôt que de doubler l'exécution.</p>
        <pre class="rdr-code">add_action( 'rdr_suite_module_loaded_mon-module', static function (): void {

    // Guard — si le plugin source est actif, il gère déjà cette fonctionnalité
    if ( class_exists( 'RDR_NomDeLaClasseSource' ) ) {
        return;
    }

    // Plugin source inactif → le module prend le relais
    \add_action( 'wp_head', static function (): void {
        // ...
    } );

} );</pre>

        <h2 class="rdr-doc-h2">Règle 3 — <code>has_action()</code> pour les hooks front</h2>
        <p class="rdr-doc-p">Pour les hooks sensibles (wp_head, wp_footer, the_content…), vérifier qu'ils ne sont pas déjà enregistrés.</p>
        <pre class="rdr-code">// Vérifier avant d'ajouter un hook front
if ( ! \has_action( 'wp_head', 'rdr_fonction_du_plugin_source' ) ) {
    \add_action( 'wp_head', static function (): void {
        // ...
    } );
}</pre>

        <h2 class="rdr-doc-h2">Règle 4 — Conserver les clés <code>wp_options</code></h2>
        <p class="rdr-doc-p">Ne jamais renommer les clés de base de données du plugin source. Les modules lisent et écrivent dans les mêmes clés.</p>
        <pre class="rdr-code">// ❌ À ne PAS faire — casserait les données existantes
\RDR_Module_Settings::get( 'mon-module', 'nouvelle_cle' );

// ✅ Conserver la clé originale du plugin source
$val = \get_option( 'rdr_option_originale', 'defaut' );
\update_option( 'rdr_option_originale', $nouvelle_valeur );

// La settings-view.php lit aussi depuis la clé originale
// $settings = get_option('rdr_option_originale', []);</pre>

        <h2 class="rdr-doc-h2">Phases de migration</h2>
        <div class="rdr-doc-table-wrap">
            <table class="rdr-doc-table">
                <thead><tr><th>Phase</th><th>État</th><th>Action</th></tr></thead>
                <tbody>
                    <tr>
                        <td><strong>1 — Cohabitation</strong></td>
                        <td>Plugin source <span class="rdr-badge rdr-badge--active">Actif</span> + modules <span class="rdr-badge rdr-badge--active">Actifs</span></td>
                        <td>Guards actifs, plugin source a la priorité. Tester module par module.</td>
                    </tr>
                    <tr>
                        <td><strong>2 — Bascule</strong></td>
                        <td>Plugin source <span class="rdr-badge rdr-badge--inactive">Désactivé</span></td>
                        <td>Désactiver quand tous les modules sont migrés et validés. Les guards tombent, les modules prennent le relais.</td>
                    </tr>
                    <tr>
                        <td><strong>3 — Nettoyage</strong> (optionnel)</td>
                        <td>Migration complète</td>
                        <td>Renommer les clés wp_options si souhaité, supprimer les guards.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <h2 class="rdr-doc-h2">Checklist par module migré</h2>
        <ul class="rdr-doc-checklist">
            <li><span>☐</span> Namespace déclaré en tête de fichier</li>
            <li><span>☐</span> Guard <code>class_exists()</code> présent</li>
            <li><span>☐</span> <code>has_action()</code> sur tous les hooks front</li>
            <li><span>☐</span> Clés <code>wp_options</code> originales conservées</li>
            <li><span>☐</span> Testé avec plugin source actif (cohabitation)</li>
            <li><span>☐</span> Testé avec plugin source désactivé (standalone)</li>
            <li><span>☐</span> <code>settings-view.php</code> lit depuis les bonnes clés</li>
            <li><span>☐</span> Zéro jQuery dans les assets du module</li>
        </ul>

    </section>

    <!-- ══════════════════════════════════════════════════════════════
         ONGLET 3 — STARTER TEMPLATE
         ══════════════════════════════════════════════════════════════ -->
    <?php elseif ( 'starter' === $tab ) : ?>
    <section class="rdr-section rdr-doc-content">

        <p class="rdr-doc-p">Copiez les blocs ci-dessous, remplacez <code>mon-module</code> (kebab-case) et <code>MonModule</code> (PascalCase) par le nom de votre module.</p>

        <h2 class="rdr-doc-h2">📄 <code>module.json</code></h2>
        <div class="rdr-code-block">
            <button class="rdr-copy-btn" data-target="starter-json">Copier</button>
            <pre class="rdr-code" id="starter-json">{
  "slug":        "mon-module",
  "name":        "Mon Module",
  "description": "Description courte.",
  "version":     "1.0.0",
  "author":      "Romain Drouche",
  "icon":        "🔧",
  "entry":       "mon-module.php",
  "min_version": "<?php echo esc_html( RDR_SUITE_VERSION ); ?>"
}</pre>
        </div>

        <h2 class="rdr-doc-h2">📄 <code>mon-module.php</code> — Nouveau module</h2>
        <div class="rdr-code-block">
            <button class="rdr-copy-btn" data-target="starter-php-new">Copier</button>
            <pre class="rdr-code" id="starter-php-new">&lt;?php
/**
 * Module : Mon Module
 *
 * @package RDR_IT_Suite\Modules\MonModule
 */
namespace RDR\Modules\MonModule;

defined( 'ABSPATH' ) || exit;

add_action( 'rdr_suite_module_loaded_mon-module', static function (): void {

    $s = static fn( string $key, mixed $default = null )
        =&gt; \RDR_Module_Settings::get( 'mon-module', $key, $default );

    // Exemple : ajouter quelque chose dans wp_head
    if ( '1' === $s( 'mon_option', '0' ) ) {
        \add_action( 'wp_head', static function (): void {
            // Votre code ici
        } );
    }

} );</pre>
        </div>

        <h2 class="rdr-doc-h2">📄 <code>mon-module.php</code> — Migration depuis plugin source</h2>
        <div class="rdr-code-block">
            <button class="rdr-copy-btn" data-target="starter-php-migration">Copier</button>
            <pre class="rdr-code" id="starter-php-migration">&lt;?php
/**
 * Module : Mon Module (migration)
 *
 * Cohabite avec RDR_NomClasseSource tant que le plugin source est actif.
 * Clés wp_options conservées : 'rdr_option_originale'
 *
 * @package RDR_IT_Suite\Modules\MonModule
 */
namespace RDR\Modules\MonModule;

defined( 'ABSPATH' ) || exit;

add_action( 'rdr_suite_module_loaded_mon-module', static function (): void {

    // ── Guard — plugin source encore actif → il garde la main ────────
    if ( class_exists( 'RDR_NomClasseSource' ) ) {
        return;
    }

    // ── Lire les options originales (même clés que le plugin source) ──
    $options = \get_option( 'rdr_option_originale', [] );
    $val     = $options['ma_cle'] ?? 'defaut';

    // ── Hooks front avec vérification anti-doublon ────────────────────
    if ( ! \has_action( 'wp_head', 'rdr_fonction_du_plugin_source' ) ) {
        \add_action( 'wp_head', static function () use ( $val ): void {
            // Votre code ici
        } );
    }

} );</pre>
        </div>

        <h2 class="rdr-doc-h2">📄 <code>settings-view.php</code></h2>
        <div class="rdr-code-block">
            <button class="rdr-copy-btn" data-target="starter-settings">Copier</button>
            <pre class="rdr-code" id="starter-settings">&lt;?php
/**
 * Réglages — Mon Module
 * Variables : $slug, $module, $settings
 *
 * @package RDR_IT_Suite\Modules\MonModule
 */
defined( 'ABSPATH' ) || exit;

// Pour migration : lire depuis les clés originales
// $raw      = \get_option( 'rdr_option_originale', [] );
// $settings = array_merge( $raw, $settings );

$mon_option = $settings['mon_option'] ?? '0';
$ma_valeur  = $settings['ma_valeur']  ?? '';
?&gt;

&lt;div class="rdr-settings-group"&gt;
    &lt;h2 class="rdr-settings-group__title"&gt;Réglages généraux&lt;/h2&gt;

    &lt;!-- Toggle switch --&gt;
    &lt;div class="rdr-field"&gt;
        &lt;label class="rdr-field__label rdr-field__label--switch"&gt;
            &lt;span class="rdr-switch"&gt;
                &lt;input type="checkbox" name="settings[mon_option]" value="1"
                    &lt;?php checked( '1', $mon_option ); ?&gt;&gt;
                &lt;span class="rdr-switch__track"&gt;&lt;span class="rdr-switch__thumb"&gt;&lt;/span&gt;&lt;/span&gt;
            &lt;/span&gt;
            &lt;span&gt;
                &lt;strong&gt;Titre de l'option&lt;/strong&gt;
                &lt;small&gt;Description de ce que ça fait.&lt;/small&gt;
            &lt;/span&gt;
        &lt;/label&gt;
    &lt;/div&gt;

    &lt;!-- Champ texte --&gt;
    &lt;div class="rdr-field"&gt;
        &lt;label class="rdr-field__label" for="ma_valeur"&gt;Ma valeur&lt;/label&gt;
        &lt;input type="text" id="ma_valeur" name="settings[ma_valeur]"
               class="rdr-input"
               value="&lt;?php echo esc_attr( $ma_valeur ); ?&gt;"
               placeholder="Valeur par défaut"&gt;
        &lt;p class="rdr-field__help"&gt;Aide contextuelle.&lt;/p&gt;
    &lt;/div&gt;

&lt;/div&gt;</pre>
        </div>

        <h2 class="rdr-doc-h2">📄 Hook de sanitisation</h2>
        <div class="rdr-code-block">
            <button class="rdr-copy-btn" data-target="starter-sanitize">Copier</button>
            <pre class="rdr-code" id="starter-sanitize">// À ajouter dans mon-module.php (hors du callback principal)
\add_filter( 'rdr_sanitize_settings_mon-module', static function ( array $raw ): array {
    return [
        'mon_option' =&gt; in_array( $raw['mon_option'] ?? '0', [ '0', '1' ], true )
                        ? $raw['mon_option'] : '0',
        'ma_valeur'  =&gt; sanitize_text_field( $raw['ma_valeur'] ?? '' ),
    ];
} );</pre>
        </div>

    </section>
    <?php endif; ?>

    <!-- ── Infos version ─────────────────────────────────────────────── -->
    <footer class="rdr-doc-footer">
        <span>RDR-IT Suite v<?php echo esc_html( RDR_SUITE_VERSION ); ?></span>
        <span>·</span>
        <span>WordPress <?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
        <span>·</span>
        <span>PHP <?php echo esc_html( PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ); ?></span>
        <span>·</span>
        <span><?php echo esc_html( $active_cnt ); ?>/<?php echo esc_html( $total_cnt ); ?> modules actifs</span>
    </footer>

</div><!-- .rdr-wrap -->
