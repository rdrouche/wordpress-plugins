/**
 * RDR-IT Tools — Extension bloc Citation Gutenberg
 * Handle WP : rdrit-quotes-editor
 *
 * Ajoute un sélecteur de couleur dans la sidebar du bloc core/quote.
 * La couleur choisie est stockée dans l'attribut rdritQuoteColor.
 * Le PHP (render_block) injecte ensuite data-quote="slug" dans le HTML.
 */
(function () {
    var el       = window.wp.element.createElement;
    var addFilter = window.wp.hooks.addFilter;
    var SelectControl = window.wp.components.SelectControl;
    var InspectorControls = window.wp.blockEditor.InspectorControls;
    var PanelBody = window.wp.components.PanelBody;
    var createHigherOrderComponent = window.wp.compose.createHigherOrderComponent;
    var Fragment = window.wp.element.Fragment;

    var cfg    = window.rdritQuotesConfig || {};
    var colors = cfg.colors || [];

    if ( ! colors.length ) return;

    /* ── Options du select ── */
    var colorOptions = [ { label: '— Défaut (thème) —', value: '' } ].concat(
        colors.map( function ( c ) {
            return { label: c.label + ' (' + c.slug + ')', value: c.slug };
        } )
    );

    /* ── Enregistrer l'attribut custom ── */
    addFilter(
        'blocks.registerBlockType',
        'rdrit/quote-color-attribute',
        function ( settings, name ) {
            if ( name !== 'core/quote' ) return settings;
            return Object.assign( {}, settings, {
                attributes: Object.assign( {}, settings.attributes, {
                    rdritQuoteColor: {
                        type: 'string',
                        default: '',
                    },
                } ),
            } );
        }
    );

    /* ── Ajouter le panneau dans la sidebar ── */
    var withQuoteColorControl = createHigherOrderComponent( function ( BlockEdit ) {
        return function ( props ) {
            if ( props.name !== 'core/quote' ) {
                return el( BlockEdit, props );
            }

            var currentColor = props.attributes.rdritQuoteColor || '';

            // Trouver la couleur sélectionnée pour l'aperçu
            var selectedColor = colors.find( function ( c ) {
                return c.slug === currentColor;
            } );

            return el(
                Fragment,
                null,
                el( BlockEdit, props ),
                el(
                    InspectorControls,
                    null,
                    el(
                        PanelBody,
                        {
                            title: '🎨 Couleur RDR-IT',
                            initialOpen: true,
                        },
                        el( SelectControl, {
                            label: 'Style de citation',
                            value: currentColor,
                            options: colorOptions,
                            onChange: function ( val ) {
                                props.setAttributes( { rdritQuoteColor: val } );
                            },
                        } ),
                        // Aperçu couleur
                        selectedColor
                            ? el(
                                  'div',
                                  {
                                      style: {
                                          marginTop: '8px',
                                          borderLeft: '5px solid ' + selectedColor.color,
                                          background: selectedColor.light,
                                          padding: '8px 12px',
                                          borderRadius: '0 6px 6px 0',
                                          fontSize: '.82em',
                                          color: '#1e293b',
                                      },
                                  },
                                  '✏️ Aperçu — ' + selectedColor.label
                              )
                            : null
                    )
                )
            );
        };
    }, 'withQuoteColorControl' );

    addFilter(
        'editor.BlockEdit',
        'rdrit/quote-color-control',
        withQuoteColorControl
    );

    /* ── Appliquer la prévisualisation live dans l'éditeur ── */
    addFilter(
        'blocks.getSaveElement',
        'rdrit/quote-color-save',
        function ( element, blockType, attributes ) {
            // On ne modifie pas le save — tout se fait via render_block PHP
            return element;
        }
    );

    /* ── Appliquer la classe/data dans l'éditeur (BlockListBlock) ── */
    var withQuoteColorPreview = createHigherOrderComponent( function ( BlockListBlock ) {
        return function ( props ) {
            if ( props.name !== 'core/quote' ) {
                return el( BlockListBlock, props );
            }

            var color = props.attributes.rdritQuoteColor;
            if ( ! color ) return el( BlockListBlock, props );

            // Ajouter wrapperProps pour prévisualisation dans l'éditeur
            var wrapperProps = Object.assign( {}, props.wrapperProps, {
                'data-quote': color,
            } );

            return el( BlockListBlock, Object.assign( {}, props, { wrapperProps: wrapperProps } ) );
        };
    }, 'withQuoteColorPreview' );

    addFilter(
        'editor.BlockListBlock',
        'rdrit/quote-color-preview',
        withQuoteColorPreview
    );

}() );
