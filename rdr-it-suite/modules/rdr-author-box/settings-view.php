<?php
/**
 * settings-view.php — Réglages Author Box v1.0.2
 * AJAX pattern : rdrit_save_option / rdrit_nonce.
 * Clé préfixe obligatoire : rdr_author_box_
 *
 * @package RDR\Modules\AuthorBox
 */

namespace RDR\Modules\AuthorBox;

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Valeurs courantes ──────────────────────────────────────────────────────
$post_types_all = \get_post_types( [ 'public' => true ], 'objects' );

$saved_types = \get_option( 'rdr_author_box_post_types', [ 'post' ] );
if ( ! is_array( $saved_types ) ) $saved_types = [ 'post' ];

$hook        = (string) \get_option( 'rdr_author_box_hook',             'generate_after_entry_content' );
$priority    = (int)    \get_option( 'rdr_author_box_priority',         10 );
$avatar_size = (int)    \get_option( 'rdr_author_box_avatar_size',      100 );

$social_config = \get_option( 'rdr_author_box_social_networks', get_default_social_networks() );
if ( ! is_array( $social_config ) ) $social_config = get_default_social_networks();

// Nonce frais pour rdrit_save_option
$nonce = \wp_create_nonce( 'rdrit_nonce' );

$available_hooks = [
    'generate_after_entry_content' => 'generate_after_entry_content (GP — défaut)',
    'after_entry_content'          => 'after_entry_content (GP)',
    'before_entry_content'         => 'before_entry_content (GP)',
    'the_content'                  => 'the_content (filtre WP)',
];
?>

<div id="rdr-ab-wrap">

    <!-- ── Affichage ─────────────────────────────────────── -->
    <div class="rdr-settings-group">
        <div class="rdr-settings-group__title">Affichage</div>

        <div class="rdr-field">
            <label class="rdr-field__label">Types de contenus</label>
            <div class="rdr-checkbox-grid" id="ab-pt-grid">
                <?php foreach ( $post_types_all as $pt ) : ?>
                <label class="rdr-checkbox-label">
                    <input
                        type="checkbox"
                        class="rdr-ab-pt"
                        value="<?php echo \esc_attr( $pt->name ); ?>"
                        <?php checked( in_array( $pt->name, $saved_types, true ) ); ?>
                    />
                    <?php echo \esc_html( $pt->label ); ?>
                    <span class="rdr-checkbox-slug"><?php echo \esc_html( $pt->name ); ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <p class="rdr-field__help">
                L'Author Box s'affiche uniquement sur ces types de contenu.
                Possibilité de la masquer article par article via la meta box dans l'éditeur.
            </p>
            <span class="rdr-ind" id="ab-pt-ind" aria-live="polite"></span>
        </div>

        <div class="rdr-field">
            <label class="rdr-field__label" for="ab-hook">Hook d'injection</label>
            <select id="ab-hook" class="rdr-input"
                    data-key="rdr_author_box_hook" data-nonce="<?php echo \esc_attr( $nonce ); ?>">
                <?php foreach ( $available_hooks as $v => $l ) : ?>
                <option value="<?php echo \esc_attr( $v ); ?>" <?php selected( $hook, $v ); ?>>
                    <?php echo \esc_html( $l ); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <span class="rdr-ind" id="ab-hook-ind" aria-live="polite"></span>
        </div>

        <div class="rdr-field">
            <label class="rdr-field__label" for="ab-priority">Priorité</label>
            <input type="number" id="ab-priority" class="rdr-input"
                   data-key="rdr_author_box_priority" data-nonce="<?php echo \esc_attr( $nonce ); ?>"
                   value="<?php echo \esc_attr( $priority ); ?>" min="1" max="999" style="width:90px;" />
            <span class="rdr-ind" id="ab-prio-ind" aria-live="polite"></span>
            <p class="rdr-field__help">Valeur entière. Plus haute = s'affiche après les autres éléments du même hook.</p>
        </div>

        <div class="rdr-field">
            <label class="rdr-field__label" for="ab-avatar">Taille avatar (px)</label>
            <input type="number" id="ab-avatar" class="rdr-input"
                   data-key="rdr_author_box_avatar_size" data-nonce="<?php echo \esc_attr( $nonce ); ?>"
                   value="<?php echo \esc_attr( $avatar_size ); ?>" min="60" max="300" step="10" style="width:90px;" />
            <span class="rdr-ind" id="ab-avatar-ind" aria-live="polite"></span>
        </div>
    </div>

    <!-- ── Réseaux sociaux ───────────────────────────────── -->
    <div class="rdr-settings-group">
        <div class="rdr-settings-group__title">Réseaux sociaux</div>
        <p class="rdr-field__help" style="margin-bottom:12px;">
            Glisser-déposer pour réordonner. Activer uniquement les réseaux souhaités.
            Les URLs se renseignent dans le <strong>profil</strong> de chaque auteur.
        </p>

        <ul id="rdr-social-list" class="rdr-social-list">
            <?php foreach ( $social_config as $net ) :
                $k       = $net['key'];
                $l       = $net['label'] ?? $k;
                $enabled = ! empty( $net['enabled'] );
            ?>
            <li class="rdr-social-item"
                data-key="<?php echo \esc_attr( $k ); ?>"
                data-label="<?php echo \esc_attr( $l ); ?>">
                <span class="rdr-social-drag">⠿</span>
                <span class="rdr-social-icon"><?php echo get_social_icon( $k ); // phpcs:ignore ?></span>
                <span class="rdr-social-name"><?php echo \esc_html( $l ); ?></span>
                <label class="rdr-switch rdr-social-toggle">
                    <input type="checkbox" class="rdr-social-cb" <?php checked( $enabled ); ?> />
                    <span class="rdr-switch__track"><span class="rdr-switch__thumb"></span></span>
                </label>
            </li>
            <?php endforeach; ?>
        </ul>

        <div style="display:flex;align-items:center;margin-top:12px;gap:12px;">
            <button type="button" class="rdr-btn" id="rdr-social-save"
                    data-key="rdr_author_box_social_networks"
                    data-nonce="<?php echo \esc_attr( $nonce ); ?>">
                Enregistrer l'ordre &amp; l'activation
            </button>
            <span class="rdr-ind" id="ab-social-ind" aria-live="polite"></span>
        </div>
    </div>

</div><!-- #rdr-ab-wrap -->

<style>
.rdr-checkbox-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:8px;margin-top:8px;}
.rdr-checkbox-label{display:flex;align-items:center;gap:8px;padding:6px 10px;border-radius:6px;border:1px solid var(--rdr-border);background:var(--rdr-bg-card);cursor:pointer;font-size:13px;color:var(--rdr-text-primary);transition:border-color .2s;}
.rdr-checkbox-label:hover{border-color:var(--rdr-accent);}
.rdr-checkbox-label input{margin:0;}
.rdr-checkbox-slug{color:var(--rdr-text-muted);font-size:11px;font-family:'JetBrains Mono',monospace;}

.rdr-social-list{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:6px;}
.rdr-social-item{display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--rdr-bg-card);border:1px solid var(--rdr-border);border-radius:8px;transition:border-color .2s;user-select:none;}
.rdr-social-item.sortable-ghost{opacity:.35;}
.rdr-social-item.sortable-chosen{border-color:var(--rdr-accent);}
.rdr-social-drag{cursor:grab;color:var(--rdr-text-muted);font-size:18px;flex-shrink:0;}
.rdr-social-drag:active{cursor:grabbing;}
.rdr-social-icon{width:20px;height:20px;color:var(--rdr-text-secondary);flex-shrink:0;}
.rdr-social-icon svg{width:100%;height:100%;}
.rdr-social-name{flex:1;font-size:13.5px;color:var(--rdr-text-primary);}
.rdr-social-toggle{margin-left:auto;}

/* Indicateur inline */
.rdr-ind{display:inline-block;font-size:12px;margin-left:8px;opacity:0;transition:opacity .2s;}
.rdr-ind.on{opacity:1;}
.rdr-ind.ok{color:var(--rdr-accent-2,#00e5c0);}
.rdr-ind.err{color:#e53e3e;}
.rdr-ind.wait{color:var(--rdr-text-muted);}
</style>

<script>
/* ── Isolé dans DOMContentLoaded — plus de null possible ─────────────── */
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    /* ajaxurl est injecté automatiquement par WP dans l'admin */
    var ajax = (typeof ajaxurl !== 'undefined') ? ajaxurl : '/wp-admin/admin-ajax.php';

    /* ── Indicateur ──────────────────────────────────────────────────── */
    function ind(el, state, msg) {
        if (!el) return;
        el.textContent = msg;
        el.className   = 'rdr-ind on ' + state;
        if (state !== 'wait') {
            setTimeout(function () { el.className = 'rdr-ind'; }, 2600);
        }
    }

    /* ── Requête AJAX ────────────────────────────────────────────────── */
    function save(key, value, nonce, indEl) {
        ind(indEl, 'wait', 'Enregistrement…');
        var strVal = (typeof value === 'string') ? value : JSON.stringify(value);
        var body   = new URLSearchParams({
            action:     'rdrit_save_option',
            nonce:      nonce,
            option_key: key,
            value:      strVal,
        });
        fetch(ajax, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('http_' + r.status);
                return r.json();
            })
            .then(function (d) {
                if (d && d.success) { ind(indEl, 'ok',  '✓ Enregistré'); }
                else                { ind(indEl, 'err', '✗ ' + (d && d.data && d.data.message ? d.data.message : 'Erreur')); }
            })
            .catch(function (e) { ind(indEl, 'err', '✗ Réseau (' + e.message + ')'); });
    }

    /* ── Post types — save dès qu'une case change ────────────────────── */
    var ptGrid = document.getElementById('ab-pt-grid');
    var ptInd  = document.getElementById('ab-pt-ind');
    /* Récupérer nonce + key depuis la première checkbox */
    var firstPt = ptGrid ? ptGrid.querySelector('.rdr-ab-pt') : null;
    var ptNonce = firstPt ? '<?php echo \esc_js( $nonce ); ?>' : '';
    var ptKey   = 'rdr_author_box_post_types';

    if (ptGrid) {
        ptGrid.addEventListener('change', function (e) {
            if (!e.target.classList.contains('rdr-ab-pt')) return;
            var vals = Array.from(ptGrid.querySelectorAll('.rdr-ab-pt:checked')).map(function (i) { return i.value; });
            save(ptKey, vals, ptNonce, ptInd);
        });
    }

    /* ── Champs scalaires (select + number) ──────────────────────────── */
    var indMap = {
        'ab-hook':     document.getElementById('ab-hook-ind'),
        'ab-priority': document.getElementById('ab-prio-ind'),
        'ab-avatar':   document.getElementById('ab-avatar-ind'),
    };

    ['ab-hook', 'ab-priority', 'ab-avatar'].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        var ev = (el.tagName === 'SELECT') ? 'change' : 'blur';
        el.addEventListener(ev, function () {
            save(el.dataset.key, el.value, el.dataset.nonce, indMap[id]);
        });
    });

    /* ── Réseaux sociaux — SortableJS + bouton ───────────────────────── */
    var socialList = document.getElementById('rdr-social-list');
    var socialBtn  = document.getElementById('rdr-social-save');
    var socialInd  = document.getElementById('ab-social-ind');

    function initSortable() {
        if (socialList && window.Sortable) {
            Sortable.create(socialList, {
                handle:      '.rdr-social-drag',
                animation:   150,
                ghostClass:  'sortable-ghost',
                chosenClass: 'sortable-chosen',
            });
        }
    }

    if (socialBtn) {
        socialBtn.addEventListener('click', function () {
            if (!socialList) return;
            var data = Array.from(socialList.querySelectorAll('.rdr-social-item')).map(function (li) {
                return {
                    key:     li.dataset.key,
                    label:   li.dataset.label,
                    enabled: li.querySelector('.rdr-social-cb').checked,
                };
            });
            save(socialBtn.dataset.key, data, socialBtn.dataset.nonce, socialInd);
        });
    }

    /* Charger SortableJS si besoin */
    if (socialList) {
        if (window.Sortable) {
            initSortable();
        } else {
            var sc   = document.createElement('script');
            sc.src   = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.3/Sortable.min.js';
            sc.onload = initSortable;
            document.head.appendChild(sc);
        }
    }
});
</script>
