/**
 * RDR-IT Tools — Widget Visibility v2
 * Panneau InspectorControls dans l'éditeur de widgets Gutenberg.
 * Select type + 2e select dynamique selon le type choisi.
 */
(function () {
    'use strict';

    var wp            = window.wp;
    var el            = wp.element.createElement;
    var Fragment      = wp.element.Fragment;
    var useState      = wp.element.useState;
    var addFilter     = wp.hooks.addFilter;
    var SelectControl = wp.components.SelectControl;
    var CheckboxControl = wp.components.CheckboxControl;
    var TextControl   = wp.components.TextControl;
    var Button        = wp.components.Button;
    var PanelBody     = wp.components.PanelBody;
    var InspectorControls = wp.blockEditor.InspectorControls;

    var cfg     = window.rdritWVConfig || {};
    var roles   = cfg.roles      || {};
    var caps    = cfg.caps       || [];
    var langs   = cfg.langs      || [];
    var pages   = cfg.pages      || [];
    var cpts    = cfg.cpts       || [];
    var taxos   = cfg.taxonomies || [];
    var authors = cfg.authors    || [];

    /* ── Définition des types de conditions ──
       Chaque type peut avoir :
       - subType : 'none' | 'text' | 'select' | 'select_terms'
       - getOptions() : retourne les options du 2e select
    ── */
    var TYPES = [
        // Utilisateur
        { group:'👤 Utilisateur', value:'logged_in',    label:'Connecté',         subType:'none' },
        { group:'👤 Utilisateur', value:'logged_out',   label:'Non connecté',      subType:'none' },
        { group:'👤 Utilisateur', value:'role',         label:'Rôle',              subType:'select',
          getOptions: function(){ return Object.keys(roles).map(function(k){ return {value:k, label:roles[k]}; }); }
        },
        { group:'👤 Utilisateur', value:'capability',   label:'Capacité',          subType:'text',
          placeholder: 'manage_options,edit_posts…'
        },

        // Pages standard
        { group:'📄 Page standard', value:'is_front_page', label:'Page d\'accueil', subType:'none' },
        { group:'📄 Page standard', value:'is_home',       label:'Page des articles',subType:'none' },
        { group:'📄 Page standard', value:'is_404',        label:'Page Erreur 404', subType:'none' },
        { group:'📄 Page standard', value:'is_search',     label:'Résultats de recherche', subType:'none' },
        { group:'📄 Page standard', value:'is_date',       label:'Archive date',    subType:'none' },
        { group:'📄 Page standard', value:'is_sticky',     label:'Article épinglé', subType:'none' },

        // Type de contenu (singular)
        { group:'📦 Type de contenu', value:'post_type_single', label:'Type de contenu',
          subType:'select',
          getOptions: function(){ return cpts.map(function(c){ return {value:c.slug, label:c.label}; }); }
        },

        // Archives CPT
        { group:'📚 Archives', value:'post_type_archive', label:'Archive CPT',
          subType:'select',
          getOptions: function(){ return cpts.map(function(c){ return {value:c.slug, label:c.label}; }); }
        },
        { group:'📚 Archives', value:'is_archive',  label:'Toutes archives',  subType:'none' },
        { group:'📚 Archives', value:'is_author',   label:'Archive auteur',
          subType:'select',
          getOptions: function(){ return [{value:'',label:'Tous les auteurs'}].concat(
              authors.map(function(a){ return {value:a.slug, label:a.name}; })
          ); }
        },

        // Pages statiques
        { group:'🗂️ Page statique', value:'is_page', label:'Page statique',
          subType:'select',
          getOptions: function(){ return [{value:'',label:'Toutes les pages'}].concat(
              pages.map(function(p){ return {value:p.slug, label:p.title}; })
          ); }
        },

        // Taxonomies
        { group:'🏷️ Catégorie / Taxonomie', value:'is_category', label:'Catégorie',
          subType:'select_terms', taxSlug:'category'
        },
        { group:'🏷️ Catégorie / Taxonomie', value:'is_tag', label:'Tag',
          subType:'select_terms', taxSlug:'post_tag'
        },
        { group:'🏷️ Catégorie / Taxonomie', value:'is_tax', label:'Taxonomie personnalisée',
          subType:'select_terms_tax'
        },

        // Langue
        { group:'🌍 Langue', value:'language', label:'Langue (Polylang)',
          subType: langs.length ? 'checkboxes' : 'text',
          placeholder: 'fr,en,de…',
          getOptions: function(){ return langs.map(function(l){ return {value:l.slug, label:l.name+' ('+l.slug+')'}; }); }
        },
    ];

    /* ── Construire les optgroups pour le 1er select ── */
    function buildTypeOptions() {
        var groups = {};
        TYPES.forEach(function(t){
            if(!groups[t.group]) groups[t.group] = [];
            groups[t.group].push({ value:t.value, label:t.label });
        });
        var opts = [{ value:'', label:'— Choisir —' }];
        Object.keys(groups).forEach(function(g){
            // SelectControl de Gutenberg ne supporte pas optgroup nativement
            // On préfixe le label avec le groupe
            groups[g].forEach(function(o){
                opts.push({ value:o.value, label:o.label });
            });
        });
        return opts;
    }

    /* ── Obtenir les options du 2e select selon le type ── */
    function getSubOptions(typeDef, subValue) {
        if (!typeDef) return null;

        if (typeDef.subType === 'select' || typeDef.subType === 'select_terms') {
            var baseOpts = typeDef.getOptions ? typeDef.getOptions() : [];
            return baseOpts;
        }
        if (typeDef.subType === 'select_terms') {
            var tax = taxos.find(function(t){ return t.slug === typeDef.taxSlug; });
            if (!tax) return null;
            return [{value:'',label:'Tous les termes'}].concat(
                tax.terms.map(function(t){ return {value:t.slug, label:t.name}; })
            );
        }
        if (typeDef.subType === 'select_terms_tax') {
            // D'abord choisir la taxonomie, puis le terme
            return taxos.map(function(t){ return {value:t.slug, label:t.label}; });
        }
        return null;
    }

    /* ── Composant ligne de condition ── */
    function ConditionRow(props) {
        var cond     = props.cond;
        var idx      = props.index;
        var onChange = props.onChange;
        var onRemove = props.onRemove;

        var typeDef  = TYPES.find(function(t){ return t.value === cond.type; });
        var subOpts  = typeDef ? getSubOptions(typeDef, cond.value) : null;

        var valueStr = Array.isArray(cond.value)
            ? cond.value.join(',')
            : (cond.value || '');

        function updateType(val) {
            onChange(idx, { type: val, value: '' });
        }
        function updateValue(val) {
            // Valeurs multiples séparées par virgule → tableau
            var v = typeof val === 'string' && val.indexOf(',') !== -1
                ? val.split(',').map(function(s){ return s.trim(); }).filter(Boolean)
                : val;
            onChange(idx, Object.assign({}, cond, { value: v }));
        }

        return el('div', {
            style: { display:'flex', gap:'4px', marginBottom:'6px',
                     alignItems:'flex-start', flexWrap:'wrap' }
        },
            // Select type (1er)
            el('div', { style:{ flex:'1', minWidth:'140px' } },
                el(SelectControl, {
                    value: cond.type || '',
                    options: buildTypeOptions(),
                    onChange: updateType,
                    __nextHasNoMarginBottom: true,
                })
            ),

            // 2e select / checkboxes / texte selon subType
            typeDef && typeDef.subType === 'none' ? null :
            typeDef && typeDef.subType === 'checkboxes' && typeDef.getOptions ?
                el('div', { style:{ flex:'1', minWidth:'110px',
                                    background:'#f9fafb', border:'1px solid #e5e7eb',
                                    borderRadius:'4px', padding:'4px 8px',
                                    maxHeight:'120px', overflowY:'auto' } },
                    typeDef.getOptions().map(function(opt) {
                        var selected = Array.isArray(cond.value)
                            ? cond.value
                            : (cond.value ? [cond.value] : []);
                        return el(CheckboxControl, {
                            key: opt.value,
                            label: opt.label,
                            checked: selected.indexOf(opt.value) !== -1,
                            onChange: function(checked) {
                                var prev = Array.isArray(cond.value)
                                    ? cond.value.slice()
                                    : (cond.value ? [cond.value] : []);
                                var next = checked
                                    ? prev.concat([opt.value])
                                    : prev.filter(function(v){ return v !== opt.value; });
                                onChange(idx, Object.assign({}, cond, { value: next }));
                            },
                            __nextHasNoMarginBottom: true,
                        });
                    })
                ) :
            typeDef && (typeDef.subType === 'select' || typeDef.subType === 'select_terms' || typeDef.subType === 'select_terms_tax') && subOpts ?
                el('div', { style:{ flex:'1', minWidth:'110px' } },
                    el(SelectControl, {
                        value: valueStr,
                        options: [{value:'', label:'Tous'}].concat(subOpts),
                        onChange: updateValue,
                        __nextHasNoMarginBottom: true,
                    })
                ) :
            typeDef && typeDef.subType === 'text' ?
                el('div', { style:{ flex:'1', minWidth:'110px' } },
                    el(TextControl, {
                        value: valueStr,
                        placeholder: typeDef.placeholder || 'Valeur',
                        onChange: updateValue,
                        __nextHasNoMarginBottom: true,
                    })
                ) : null,

            // Bouton supprimer
            el(Button, {
                isSmall: true,
                isDestructive: true,
                onClick: function(){ onRemove(idx); },
                style: { marginTop:'2px', padding:'0 8px' }
            }, '✕')
        );
    }

    /* ── Panneau principal ── */
    function VisibilityPanel(props) {
        var attributes    = props.attributes;
        var setAttributes = props.setAttributes;

        var vis        = attributes.rdritVisibility || {};
        var conditions = vis.conditions || [];
        var logic      = vis.logic      || 'OR';
        var mode       = vis.mode       || 'show';
        var device     = vis.device     || [];

        function update(patch) {
            setAttributes({ rdritVisibility: Object.assign({}, vis, patch) });
        }

        return el(InspectorControls, null,
            el(PanelBody, {
                title: '👁️ Widget Visibilité',
                initialOpen: conditions.length > 0 || device.length > 0,
            },

                // Mode + Logique
                el('div', { style:{ display:'flex', gap:'8px', marginBottom:'10px', flexWrap:'wrap' } },
                    el('div', { style:{ flex:'1' } },
                        el(SelectControl, {
                            label: 'Mode',
                            value: mode,
                            options: [
                                { value:'show', label:'Afficher si…' },
                                { value:'hide', label:'Masquer si…'  },
                            ],
                            onChange: function(v){ update({ mode:v }); },
                            __nextHasNoMarginBottom: true,
                        })
                    ),
                    el('div', { style:{ flex:'1' } },
                        el(SelectControl, {
                            label: 'Logique',
                            value: logic,
                            options: [
                                { value:'OR',  label:'OU — au moins 1' },
                                { value:'AND', label:'ET — toutes' },
                            ],
                            onChange: function(v){ update({ logic:v }); },
                            __nextHasNoMarginBottom: true,
                        })
                    )
                ),

                // Conditions
                el('p', { style:{ fontSize:'.75em', fontWeight:'700',
                                  color:'#6b7280', margin:'0 0 6px',
                                  textTransform:'uppercase', letterSpacing:'.05em' }
                }, 'Conditions'),

                conditions.map(function(cond, i) {
                    return el(ConditionRow, {
                        key: i,
                        cond: cond,
                        index: i,
                        onChange: function(idx, newCond) {
                            var next = conditions.slice();
                            next[idx] = newCond;
                            update({ conditions: next });
                        },
                        onRemove: function(idx) {
                            update({ conditions: conditions.filter(function(_,j){ return j!==idx; }) });
                        }
                    });
                }),

                el(Button, {
                    variant: 'secondary',
                    isSmall: true,
                    onClick: function(){ update({ conditions: conditions.concat([{type:'',value:''}]) }); },
                    style: { width:'100%', justifyContent:'center', marginBottom:'12px' }
                }, '+ Ajouter une condition'),

                // Device
                el('div', { style:{ borderTop:'1px dashed #e5e7eb', paddingTop:'10px' } },
                    el('p', { style:{ fontSize:'.75em', fontWeight:'700',
                                      color:'#6b7280', margin:'0 0 6px',
                                      textTransform:'uppercase', letterSpacing:'.05em' }
                    }, '📱 Device (CSS)'),

                    ['mobile','tablet','desktop'].map(function(d){
                        var labels = { mobile:'📱 Mobile', tablet:'💻 Tablette', desktop:'🖥️ Desktop' };
                        return el(CheckboxControl, {
                            key: d,
                            label: labels[d],
                            checked: device.indexOf(d) !== -1,
                            onChange: function(checked) {
                                var next = checked
                                    ? device.concat([d])
                                    : device.filter(function(x){ return x!==d; });
                                update({ device: next });
                            },
                            __nextHasNoMarginBottom: true,
                        });
                    })
                ),

                // Résumé
                (conditions.length > 0 || device.length > 0) ? el('div', {
                    style: { background:'#eff6ff', border:'1px solid #bfdbfe',
                             borderRadius:'6px', padding:'6px 8px', marginTop:'10px',
                             fontSize:'.75em', color:'#1e40af', lineHeight:'1.4' }
                },
                    el('strong', null, mode === 'show' ? '✅ Afficher si ' : '❌ Masquer si '),
                    conditions.length + ' condition(s) [' + logic + ']',
                    device.length > 0 ? el('span', null,
                        ' — Device: ' + device.join(', ')
                    ) : null
                ) : null
            )
        );
    }

    /* ── Ajouter l'attribut rdritVisibility sur les blocs non-SSR ── */
    addFilter(
        'blocks.registerBlockType',
        'rdrit/wv-attr',
        function(settings, name) {
            // Les blocs ServerSideRender ont save() qui retourne null
            // Ils font une requête REST au rendu et rejettent les attrs inconnus → 400
            // On les détecte via leur fonction save
            var isSsr = false;
            try {
                if (typeof settings.save === 'function') {
                    var saveResult = settings.save({ attributes: {} });
                    isSsr = (saveResult === null);
                } else if (settings.save === null) {
                    isSsr = true;
                }
            } catch(e) {
                // En cas d'erreur on laisse passer
                isSsr = false;
            }

            if (isSsr) return settings;

            // Blacklist de sécurité pour les blocs connus SSR
            // (blocs qui utilisent ServerSideRender et font des requêtes REST /block-renderer)
            var blacklist = [
                'core/calendar', 'core/latest-comments',
                'core/rss', 'core/archives', 'core/page-list', 'core/latest-posts',
                'core/navigation', 'core/loginout', 'core/categories',
                'core/query', 'core/comments', 'woocommerce/product-categories',
            ];
            // Exclus intentionnellement :
            // core/tag-cloud → save:null mais rendu PHP direct, PAS ServerSideRender ✅
            // core/search    → save:function, rendu client ✅
            if (blacklist.indexOf(name) !== -1) return settings;

            if (!settings.attributes) settings.attributes = {};
            settings.attributes.rdritVisibility = { type:'object', default:{} };
            return settings;
        }
    );

    /* ── Injecter le panneau dans tous les blocs (widgets uniquement) ── */
    var isWidgetScreen = !!(
        document.body &&
        ( document.body.classList.contains('widgets-php') ||
          document.querySelector('.wp-full-overlay') )
    );

    if (isWidgetScreen) {
        addFilter(
            'editor.BlockEdit',
            'rdrit/wv-panel',
            function(BlockEdit) {
                return function(props) {
                    return el(Fragment, null,
                        el(BlockEdit, props),
                        el(VisibilityPanel, props)
                    );
                };
            }
        );
    }

}());
