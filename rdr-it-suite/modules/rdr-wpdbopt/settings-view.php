<?php
/**
 * Vue admin — WP DB Optimizer
 * Pas de logique ici, tout passe par AJAX (wpdbopt.js)
 */
defined( 'ABSPATH' ) || exit;
if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Accès refusé.' ); }
?>
<div class="wrap rdr-wpdbopt-wrap">

    <!-- ── En-tête ── -->
    <div class="rdr-wpdbopt-header">
        <span class="rdr-wpdbopt-header__icon">🗄️</span>
        <div>
            <h1 class="rdr-wpdbopt-header__title">WP DB Optimizer</h1>
            <p class="rdr-wpdbopt-header__sub">Analyse et nettoyage de la base de données WordPress — options &amp; métas.</p>
        </div>
    </div>

    <!-- ── Alerte sécurité ── -->
    <div class="rdr-alert rdr-alert--warn rdr-wpdbopt-alert-top">
        <span class="rdr-wpdbopt-alert-icon">⚠️</span>
        <div>
            <strong>Attention :</strong> toute suppression est <strong>irréversible</strong>.
            Effectuez une sauvegarde de la base de données avant d'utiliser les fonctions de suppression.
        </div>
    </div>

    <!-- ── Onglets ── -->
    <nav class="rdr-wpdbopt-tabs" role="tablist">
        <button class="rdr-wpdbopt-tab rdr-wpdbopt-tab--active" data-tab="options" role="tab" aria-selected="true">
            ⚙️ Options &amp; Autoload
        </button>
        <button class="rdr-wpdbopt-tab" data-tab="orphans" role="tab" aria-selected="false">
            👻 Métas orphelines
        </button>
        <button class="rdr-wpdbopt-tab" data-tab="keys" role="tab" aria-selected="false">
            🔑 Métas par clé
        </button>
    </nav>

    <!-- ══════════════════════════════════════════════════════════
         ONGLET 1 — Options & Autoload
    ══════════════════════════════════════════════════════════ -->
    <div id="tab-options" class="rdr-wpdbopt-tabpanel rdr-wpdbopt-tabpanel--active">

        <!-- Stats globales -->
        <div class="rdr-wpdbopt-stats-grid" id="options-stats">
            <div class="rdr-wpdbopt-stat-card">
                <div class="rdr-stat-number" id="stat-total-count">—</div>
                <div class="rdr-stat-label">Options totales</div>
            </div>
            <div class="rdr-wpdbopt-stat-card">
                <div class="rdr-stat-number" id="stat-total-size">—</div>
                <div class="rdr-stat-label">Taille totale</div>
            </div>
            <div class="rdr-wpdbopt-stat-card rdr-wpdbopt-stat-card--warn">
                <div class="rdr-stat-number" id="stat-autoload-yes">—</div>
                <div class="rdr-stat-label">Autoload ON</div>
            </div>
            <div class="rdr-wpdbopt-stat-card">
                <div class="rdr-stat-number" id="stat-autoload-no">—</div>
                <div class="rdr-stat-label">Autoload OFF</div>
            </div>
            <div class="rdr-wpdbopt-stat-card rdr-wpdbopt-stat-card--warn">
                <div class="rdr-stat-number" id="stat-autoload-size">—</div>
                <div class="rdr-stat-label">Taille autoload ON</div>
            </div>
        </div>

        <!-- Filtres + export -->
        <div class="rdr-wpdbopt-toolbar">
            <input type="text" id="wpdbopt-opt-search" class="rdr-input" placeholder="Rechercher une option…" autocomplete="off">
            <select id="wpdbopt-autoload-filter" class="rdr-input" style="width:auto">
                <option value="all">Tous</option>
                <option value="yes">Autoload ON</option>
                <option value="no">Autoload OFF</option>
            </select>
            <button class="rdr-btn" id="wpdbopt-wpdbopt-search-btn">🔍 Filtrer</button>
            <div class="rdr-wpdbopt-toolbar-sep"></div>
            <button class="rdr-btn rdr-btn--ghost" id="wpdbopt-export-json-dl">⬇️ JSON</button>
            <button class="rdr-btn rdr-btn--ghost" id="wpdbopt-export-csv-dl">⬇️ CSV</button>
            <button class="rdr-btn rdr-btn--ghost" id="wpdbopt-export-json-save">💾 JSON serveur</button>
            <button class="rdr-btn rdr-btn--ghost" id="wpdbopt-export-csv-save">💾 CSV serveur</button>
        </div>

        <!-- Table options -->
        <div class="rdr-wpdbwpdbopt-table-wrap">
            <table class="rdr-wpdbwpdbopt-table" id="wpdbopt-table">
                <thead>
                    <tr>
                        <th style="width:32px"><input type="checkbox" id="wpdbopt-check-all" title="Tout sélectionner"></th>
                        <th>Nom de l'option</th>
                        <th>Autoload</th>
                        <th>Taille</th>
                        <th>Aperçu valeur</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="wpdbopt-tbody">
                    <tr><td colspan="6" class="rdr-wpdbopt-empty">Chargement…</td></tr>
                </tbody>
            </table>
        </div>
        <div class="rdr-wpdbwpdbopt-pagination" id="wpdbopt-pagination"></div>

        <!-- Actions groupées options -->
        <div class="rdr-wpdbwpdbopt-bulk" id="wpdbopt-bulk" style="display:none">
            <span id="wpdbopt-selected-count">0 sélectionnée(s)</span>
            <button class="rdr-btn rdr-btn--ghost" id="wpdbwpdbopt-bulk-autoload-on">Autoload → ON</button>
            <button class="rdr-btn rdr-btn--ghost" id="wpdbwpdbopt-bulk-autoload-off">Autoload → OFF</button>
            <button class="rdr-btn rdr-btn--danger" id="wpdbwpdbopt-bulk-delete">🗑️ Supprimer</button>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         ONGLET 2 — Métas orphelines
    ══════════════════════════════════════════════════════════ -->
    <div id="tab-orphans" class="rdr-wpdbopt-tabpanel" hidden>

        <!-- Stats métas globales -->
        <div class="rdr-wpdbopt-stats-grid rdr-wpdbopt-stats-grid--3" id="meta-stats">
            <?php foreach ( [ 'post' => '📄 Postmeta', 'term' => '🏷️ Termmeta', 'user' => '👤 Usermeta' ] as $t => $label ) : ?>
            <div class="rdr-wpdbopt-stat-card">
                <div class="rdr-wpdbopt-stat-card__title"><?= esc_html( $label ) ?></div>
                <div class="rdr-wpdbopt-stat-row">
                    <span class="rdr-wpdbopt-stat-item">Total : <strong id="ms-<?= $t ?>-total">—</strong></span>
                    <span class="rdr-wpdbopt-stat-item rdr-wpdbopt-stat-item--warn">Orphelines : <strong id="ms-<?= $t ?>-orphans">—</strong></span>
                    <span class="rdr-wpdbopt-stat-item">Clés : <strong id="ms-<?= $t ?>-keys">—</strong></span>
                    <span class="rdr-wpdbopt-stat-item">Taille : <strong id="ms-<?= $t ?>-size">—</strong></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Sélecteur type + actions -->
        <div class="rdr-wpdbopt-toolbar">
            <label class="rdr-field__label">Type :</label>
            <select id="orphan-type" class="rdr-input" style="width:auto">
                <option value="post">Postmeta</option>
                <option value="term">Termmeta</option>
                <option value="user">Usermeta</option>
            </select>
            <button class="rdr-btn" id="orphan-load-btn">🔍 Charger</button>
            <div class="rdr-wpdbopt-toolbar-sep"></div>
            <button class="rdr-btn rdr-btn--danger" id="orphan-delete-all-btn">🗑️ Supprimer TOUT</button>
        </div>

        <div class="rdr-wpdbwpdbopt-table-wrap">
            <table class="rdr-wpdbwpdbopt-table" id="orphan-table">
                <thead>
                    <tr>
                        <th style="width:32px"><input type="checkbox" id="orphan-check-all" title="Tout sélectionner"></th>
                        <th>meta_id</th>
                        <th>object_id</th>
                        <th>meta_key</th>
                        <th>Aperçu valeur</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="orphan-tbody">
                    <tr><td colspan="6" class="rdr-wpdbopt-empty">Sélectionnez un type puis cliquez sur Charger.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="rdr-wpdbwpdbopt-pagination" id="orphan-pagination"></div>

        <div class="rdr-wpdbwpdbopt-bulk" id="orphan-bulk" style="display:none">
            <span id="orphan-selected-count">0 sélectionnée(s)</span>
            <button class="rdr-btn rdr-btn--danger" id="orphan-bulk-delete">🗑️ Supprimer la sélection</button>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         ONGLET 3 — Métas par clé
    ══════════════════════════════════════════════════════════ -->
    <div id="tab-keys" class="rdr-wpdbopt-tabpanel" hidden>

        <div class="rdr-wpdbopt-toolbar">
            <label class="rdr-field__label">Type :</label>
            <select id="key-type" class="rdr-input" style="width:auto">
                <option value="post">Postmeta</option>
                <option value="term">Termmeta</option>
                <option value="user">Usermeta</option>
            </select>
            <input type="text" id="key-search" class="rdr-input" placeholder="Rechercher une meta_key…" autocomplete="off">
            <button class="rdr-btn" id="key-load-btn">🔍 Charger</button>
        </div>

        <div class="rdr-wpdbwpdbopt-table-wrap">
            <table class="rdr-wpdbwpdbopt-table" id="key-table">
                <thead>
                    <tr>
                        <th>meta_key</th>
                        <th>Occurrences</th>
                        <th>Taille</th>
                        <th>Protégée</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="key-tbody">
                    <tr><td colspan="5" class="rdr-wpdbopt-empty">Sélectionnez un type puis cliquez sur Charger.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="rdr-wpdbwpdbopt-pagination" id="key-pagination"></div>
    </div>

    <!-- ── Toast notification ── -->
    <div id="rdr-wpdbopt-toast" class="rdr-wpdbopt-toast" aria-live="polite"></div>

</div><!-- .rdr-wpdbopt-wrap -->
