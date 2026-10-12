<?php
/**
 * Module : Admin Menu Organizer
 *
 * Config stockée : { groups: [{id, label, items:[slug]}], ungrouped: [slug] }
 *
 * Dans le menu admin :
 *   - Les groupes deviennent des accordéons (header cliquable)
 *   - Les items ungrouped apparaissent sans séparateur (Tableau de bord, etc.)
 *
 * @package RDR_IT_Suite\Modules\AdminMenuOrganizer
 */

namespace RDR\Modules\AdminMenuOrganizer;

defined( 'ABSPATH' ) || exit;

add_action( 'rdr_suite_module_loaded_admin-menu-organizer', static function (): void {

    // ── Réordonner $menu ──────────────────────────────────────────────────
    \add_action( 'admin_menu', static function (): void {
        global $menu;

        $config = get_menu_config();
        if ( empty( $config['groups'] ) && empty( $config['ungrouped'] ) ) {
            return;
        }

        // Index par slug
        $by_slug = [];
        foreach ( $menu as $pos => $item ) {
            $slug = $item[2] ?? '';
            if ( ! empty( $slug ) ) {
                $by_slug[ $slug ] = $item;
            }
        }

        // Tous les slugs configurés
        $configured = array_merge(
            $config['ungrouped'] ?? [],
            ...array_map( static fn( $g ) => $g['items'] ?? [], $config['groups'] ?? [] )
        );

        $new_menu = [];
        $pos      = 2;

        // 1. Items ungrouped EN PREMIER (Tableau de bord, etc.)
        foreach ( $config['ungrouped'] ?? [] as $slug ) {
            if ( isset( $by_slug[ $slug ] ) && ! \str_starts_with( $slug, 'separator' ) ) {
                $new_menu[ $pos ] = $by_slug[ $slug ];
                $pos++;
            }
        }

        // 2. Groupes avec séparateur
        foreach ( $config['groups'] ?? [] as $group ) {
            $group_items = array_values( array_filter(
                $group['items'] ?? [],
                static fn( $s ) => isset( $by_slug[ $s ] ) && ! \str_starts_with( $s, 'separator' )
            ) );

            if ( empty( $group_items ) ) {
                continue;
            }

            // Séparateur WP = header accordéon
            $sep_slug         = 'separator' . $pos;
            $new_menu[ $pos ] = [
                '',
                'read',
                $sep_slug,
                '',
                'wp-menu-separator rdr-group-sep',
                '',
                '',
            ];
            $pos++;

            foreach ( $group_items as $slug ) {
                $new_menu[ $pos ] = $by_slug[ $slug ];
                $pos++;
            }
        }

        // 3. Tout le reste non configuré — à la fin, intact
        foreach ( $by_slug as $slug => $item ) {
            if ( \str_starts_with( $slug, 'separator' ) ) {
                continue;
            }
            if ( ! in_array( $slug, $configured, true ) ) {
                $new_menu[ $pos ] = $item;
                $pos++;
            }
        }

        $menu = $new_menu; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

    }, 9999 );

    // ── CSS accordéon ─────────────────────────────────────────────────────
    \add_action( 'admin_head', static function (): void {
        ?>
        <style id="rdr-amo-acc-css">

        #adminmenu li.rdr-group-sep > a,
        #adminmenu li.rdr-group-sep > div.separator {
            display: none !important;
        }

        #adminmenu li.rdr-group-sep {
            height: auto !important;
            margin: 10px 0 0 !important;
            padding: 0 !important;
            cursor: pointer !important;
            pointer-events: auto !important;
            user-select: none;
            list-style: none;
        }

        #adminmenu li.rdr-group-sep .rdr-acc-hd {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 12px 5px 14px;
            border-top: 1px solid rgba(240,246,252,0.07);
        }

        #adminmenu li.rdr-group-sep:hover .rdr-acc-hd {
            background: rgba(240,246,252,0.04);
        }

        #adminmenu li.rdr-group-sep .rdr-acc-lbl {
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: rgba(240,246,252,0.4);
            transition: color 0.15s;
        }

        #adminmenu li.rdr-group-sep:hover .rdr-acc-lbl,
        #adminmenu li.rdr-group-sep.rdr-open .rdr-acc-lbl {
            color: rgba(240,246,252,0.8);
        }

        #adminmenu li.rdr-group-sep .rdr-acc-arrow {
            font-size: 8px;
            color: rgba(240,246,252,0.25);
            transition: transform 0.2s ease;
            display: inline-block;
        }

        #adminmenu li.rdr-group-sep.rdr-open .rdr-acc-arrow {
            transform: rotate(90deg);
            color: rgba(240,246,252,0.5);
        }

        #adminmenu li.rdr-acc-item.rdr-hidden {
            display: none !important;
        }

        /* Item actif = toujours visible */
        #adminmenu li.rdr-acc-item.current,
        #adminmenu li.rdr-acc-item.wp-has-current-submenu,
        #adminmenu li.rdr-acc-item.wp-menu-open {
            display: list-item !important;
        }

        /* Menu replié : tout visible */
        .folded #adminmenu li.rdr-acc-item { display: list-item !important; }
        .folded #adminmenu li.rdr-group-sep .rdr-acc-lbl,
        .folded #adminmenu li.rdr-group-sep .rdr-acc-arrow { display: none; }
        .folded #adminmenu li.rdr-group-sep .rdr-acc-hd { justify-content: center; padding: 6px 0; }

        </style>
        <?php
    } );

    // ── JS accordéon ──────────────────────────────────────────────────────
    \add_action( 'admin_footer', static function (): void {
        $config = get_menu_config();
        if ( empty( $config['groups'] ) ) {
            return;
        }

        $labels = [];
        foreach ( $config['groups'] ?? [] as $group ) {
            $items = array_filter( $group['items'] ?? [], static fn( $s ) => ! empty( $s ) );
            if ( ! empty( $items ) ) {
                $labels[] = $group['label'] ?? '';
            }
        }

        if ( empty( $labels ) ) {
            return;
        }

        $labels_json = \wp_json_encode( $labels );
        ?>
        <script id="rdr-amo-acc-js">
        ( function() {
            var LABELS    = <?php echo $labels_json; ?>;
            var STORE_KEY = 'rdr_acc_state';

            function loadState() {
                try { return JSON.parse( localStorage.getItem( STORE_KEY ) || '{}' ); }
                catch(e) { return {}; }
            }
            function saveState( s ) {
                try { localStorage.setItem( STORE_KEY, JSON.stringify( s ) ); }
                catch(e) {}
            }

            function init() {
                var seps  = document.querySelectorAll( '#adminmenu li.rdr-group-sep' );
                var state = loadState();

                seps.forEach( function( sep, i ) {
                    var label = LABELS[ i ] || '';

                    var hd = document.createElement( 'div' );
                    hd.className = 'rdr-acc-hd';

                    var lbl = document.createElement( 'span' );
                    lbl.className   = 'rdr-acc-lbl';
                    lbl.textContent = label;

                    var arrow = document.createElement( 'span' );
                    arrow.className   = 'rdr-acc-arrow';
                    arrow.textContent = '\u25B6';

                    hd.appendChild( lbl );
                    hd.appendChild( arrow );
                    sep.appendChild( hd );

                    // Items suivants jusqu'au prochain séparateur
                    var items = [];
                    var next  = sep.nextElementSibling;
                    while ( next && ! next.classList.contains( 'rdr-group-sep' ) ) {
                        if ( next.tagName === 'LI' && ! next.classList.contains( 'wp-menu-separator' ) ) {
                            next.classList.add( 'rdr-acc-item' );
                            items.push( next );
                        }
                        next = next.nextElementSibling;
                    }

                    var hasActive = items.some( function( li ) {
                        return li.classList.contains( 'current' ) ||
                               li.classList.contains( 'wp-has-current-submenu' ) ||
                               li.classList.contains( 'wp-menu-open' );
                    } );

                    var isOpen = hasActive || ( state[ label ] !== false );
                    if ( isOpen ) {
                        sep.classList.add( 'rdr-open' );
                    } else {
                        items.forEach( function( li ) {
                            if ( ! li.classList.contains( 'current' ) &&
                                 ! li.classList.contains( 'wp-has-current-submenu' ) ) {
                                li.classList.add( 'rdr-hidden' );
                            }
                        } );
                    }

                    sep.addEventListener( 'click', function() {
                        var open = sep.classList.toggle( 'rdr-open' );
                        state[ label ] = open;
                        saveState( state );
                        items.forEach( function( li ) {
                            if ( li.classList.contains( 'current' ) ||
                                 li.classList.contains( 'wp-has-current-submenu' ) ) return;
                            if ( open ) li.classList.remove( 'rdr-hidden' );
                            else li.classList.add( 'rdr-hidden' );
                        } );
                    } );
                } );
            }

            if ( document.readyState === 'loading' ) {
                document.addEventListener( 'DOMContentLoaded', init );
            } else {
                init();
            }
        } )();
        </script>
        <?php
    } );

    // ── Assets ────────────────────────────────────────────────────────────
    \add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
        if ( ! \str_contains( $hook, 'admin-menu-organizer' ) ) {
            return;
        }

        $base = \RDR_SUITE_URL . 'modules/admin-menu-organizer/assets/';

        \wp_enqueue_style(  'rdr-menu-organizer', $base . 'css/admin-menu-organizer.css', [], \RDR_SUITE_VERSION );
        \wp_enqueue_script( 'rdr-menu-organizer', $base . 'js/admin-menu-organizer.js',  [], \RDR_SUITE_VERSION, true );

        \wp_localize_script( 'rdr-menu-organizer', 'RDR_MENU', [
            'ajaxUrl'    => \admin_url( 'admin-ajax.php' ),
            'nonce'      => \wp_create_nonce( 'rdr_amo_nonce' ),
            'config'     => get_menu_config(),
            'nativeMenu' => get_native_menu_items(),
            'i18n'       => [
                'saving'       => 'Enregistrement...',
                'saved'        => 'Configuration sauvegardee',
                'error'        => 'Erreur lors de la sauvegarde',
                'resetConfirm' => 'Reinitialiser la configuration ?',
                'resetDone'    => 'Configuration reinitialisee',
                'groupName'    => 'Nom du groupe',
                'noItems'      => 'Glisser des elements ici',
            ],
        ] );
    } );

    // ── AJAX save ─────────────────────────────────────────────────────────
    \add_action( 'wp_ajax_rdr_amo_save', static function (): void {
        \check_ajax_referer( 'rdr_amo_nonce', 'nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $raw = isset( $_POST['config'] ) ? \wp_unslash( $_POST['config'] ) : '';
        if ( empty( $raw ) ) {
            \wp_send_json_error( [ 'message' => 'Empty config' ], 400 );
        }
        $decoded = json_decode( $raw, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            \wp_send_json_error( [ 'message' => 'Invalid JSON' ], 400 );
        }
        \update_option( 'rdr_menu_config', sanitize_config( $decoded ) );
        \wp_send_json_success( [ 'message' => 'Configuration sauvegardee.' ] );
    } );

    // ── AJAX reset ────────────────────────────────────────────────────────
    \add_action( 'wp_ajax_rdr_amo_reset', static function (): void {
        \check_ajax_referer( 'rdr_amo_nonce', 'nonce' );
        if ( ! \current_user_can( 'manage_options' ) ) {
            \wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        \update_option( 'rdr_menu_config', [] );
        \wp_send_json_success( [ 'message' => 'Configuration reinitialisee.' ] );
    } );

} );

// ============================================================================
// FONCTIONS INTERNES
// ============================================================================

function get_menu_config(): array {
    $v = \get_option( 'rdr_menu_config', [] );
    return is_array( $v ) ? $v : [];
}

function get_native_menu_items(): array {
    global $menu, $submenu;
    $items = [];
    if ( ! is_array( $menu ) ) return $items;

    foreach ( $menu as $position => $item ) {
        $slug  = $item[2] ?? '';
        $label = \wp_strip_all_tags( $item[0] ?? '' );

        if ( empty( $slug ) || \str_starts_with( $slug, 'separator' ) || \str_starts_with( $slug, 'rdr-group-' ) ) {
            continue;
        }

        $label = trim( preg_replace( '/<span[^>]*>.*?<\/span>/s', '', $label ) );
        if ( empty( $label ) ) continue;

        $entry = [
            'slug'     => $slug,
            'label'    => $label,
            'icon'     => $item[6] ?? '',
            'position' => (int) $position,
            'children' => [],
        ];

        if ( isset( $submenu[ $slug ] ) && is_array( $submenu[ $slug ] ) ) {
            foreach ( $submenu[ $slug ] as $sub ) {
                $sub_label = trim( preg_replace( '/<span[^>]*>.*?<\/span>/s', '', \wp_strip_all_tags( $sub[0] ?? '' ) ) );
                $sub_slug  = $sub[2] ?? '';
                if ( $sub_label && $sub_slug ) {
                    $entry['children'][] = [ 'slug' => $sub_slug, 'label' => $sub_label ];
                }
            }
        }
        $items[] = $entry;
    }
    return $items;
}

function sanitize_config( array $config ): array {
    $out = [
        'groups'    => [],
        'ungrouped' => array_map( '\sanitize_text_field', $config['ungrouped'] ?? [] ),
    ];
    foreach ( $config['groups'] ?? [] as $group ) {
        $out['groups'][] = [
            'id'    => \sanitize_key( $group['id'] ?? uniqid( 'grp_', true ) ),
            'label' => \sanitize_text_field( $group['label'] ?? '' ),
            'items' => array_map( '\sanitize_text_field', $group['items'] ?? [] ),
        ];
    }
    return $out;
}
