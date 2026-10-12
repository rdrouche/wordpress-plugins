/**
 * RDR Syntax Highlighter — Sélecteur de langage + nom de fichier dans Gutenberg
 * Compatible WordPress 6.x — 0 jQuery
 */
(function() {
    'use strict';

    var LANGUAGES = [
        { value: '',           label: '— Auto-détection —' },
        { value: 'bash',       label: 'Bash / Shell' },
        { value: 'powershell', label: 'PowerShell' },
        { value: 'python',     label: 'Python' },
        { value: 'javascript', label: 'JavaScript' },
        { value: 'typescript', label: 'TypeScript' },
        { value: 'php',        label: 'PHP' },
        { value: 'html',       label: 'HTML / XML' },
        { value: 'css',        label: 'CSS / SCSS' },
        { value: 'sql',        label: 'SQL' },
        { value: 'json',       label: 'JSON' },
        { value: 'yaml',       label: 'YAML' },
        { value: 'dockerfile', label: 'Dockerfile' },
        { value: 'nginx',      label: 'Nginx' },
        { value: 'text',       label: 'Texte brut' },
    ];

    var el                = wp.element.createElement;
    var addFilter         = wp.hooks.addFilter;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var PanelBody         = wp.components.PanelBody;
    var SelectControl     = wp.components.SelectControl;
    var TextControl       = wp.components.TextControl;
    var Fragment          = wp.element.Fragment;

    /* ── 1. Enregistrer les attributs sur core/code ── */
    addFilter('blocks.registerBlockType', 'rdr-sh/add-attrs', function(settings, name) {
        if (name !== 'core/code') return settings;
        return Object.assign({}, settings, {
            attributes: Object.assign({}, settings.attributes, {
                rdrLang: {
                    type:    'string',
                    default: '',
                    source:  'attribute',
                    selector: 'pre',
                    attribute: 'data-rdr-lang',
                },
                rdrFilename: {
                    type:    'string',
                    default: '',
                    source:  'attribute',
                    selector: 'pre',
                    attribute: 'data-rdr-filename',
                },
            }),
        });
    });

    /* ── 2. Panneau inspecteur ── */
    addFilter('editor.BlockEdit', 'rdr-sh/inspector-panel', function(BlockEdit) {
        return function(props) {
            if (props.name !== 'core/code') {
                return el(BlockEdit, props);
            }

            var lang     = props.attributes.rdrLang     || '';
            var filename = props.attributes.rdrFilename  || '';
            var setAttr  = props.setAttributes;

            return el(
                Fragment, {},
                el(BlockEdit, props),
                el(InspectorControls, {},
                    el(PanelBody,
                        {
                            title:       '🎨 Coloration syntaxique',
                            initialOpen: true,
                        },
                        el(SelectControl, {
                            label:    'Langage',
                            value:    lang,
                            options:  LANGUAGES,
                            onChange: function(val) { setAttr({ rdrLang: val }); },
                            help:     lang === ''
                                ? 'Auto-détection active'
                                : 'Langage : ' + lang,
                        }),
                        el(TextControl, {
                            label:       'Nom de fichier',
                            value:       filename,
                            placeholder: 'ex: docker-compose.yml',
                            onChange:    function(val) {
                                setAttr({ rdrFilename: val.trim() });
                            },
                            help: filename
                                ? '📎 ' + filename + ' — cliquable (texte brut) + téléchargeable'
                                : 'Optionnel. Ex : nginx.conf, script.ps1',
                        })
                    )
                )
            );
        };
    });

    /* ── 3. Injecter les data-attributes sur le <pre> sauvegardé ── */
    addFilter('blocks.getSaveContent.extraProps', 'rdr-sh/save-attrs', function(extraProps, blockType, attributes) {
        if (blockType.name !== 'core/code') return extraProps;
        if (attributes.rdrLang)     extraProps['data-rdr-lang']     = attributes.rdrLang;
        if (attributes.rdrFilename) extraProps['data-rdr-filename']  = attributes.rdrFilename;
        return extraProps;
    });

})();
