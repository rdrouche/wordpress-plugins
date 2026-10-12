<?php
/**
 * RDR-IT Tools — Menu Sync Polylang v2
 *
 * Deux modes :
 *   diff     — Met à jour uniquement les items sélectionnés, garde le reste
 *   full     — Vide le menu cible et recrée tous les items FR traduits
 *
 * Correspondance source/cible : par objet lié
 *   post_type → pll_get_post($id, $lang) = ID traduit
 *   taxonomy  → pll_get_term($id, $lang) = ID traduit
 *   custom    → URL identique
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   AJAX — ANALYSER LE DIFF
   ============================================================ */
add_action( 'wp_ajax_rdrit_menu_diff', 'rdrit_menu_diff_ajax' );
function rdrit_menu_diff_ajax() {
    check_ajax_referer( 'rdrit_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
    if ( ! function_exists( 'pll_languages_list' ) ) wp_send_json_error( 'Polylang non actif.' );

    $source_id      = (int) ( $_POST['source_menu']  ?? 0 );
    $target_lang    = sanitize_key( $_POST['target_lang']  ?? '' );
    $target_menu_id = (int) ( $_POST['target_menu']  ?? 0 );
    if ( ! $source_id || ! $target_lang ) wp_send_json_error( 'Paramètres manquants.' );

    $diff = rdrit_build_diff( $source_id, $target_lang, $target_menu_id );
    wp_send_json_success( $diff );
}

/* ============================================================
   AJAX — SYNCHRONISER
   ============================================================ */
add_action( 'wp_ajax_rdrit_menu_sync', 'rdrit_menu_sync_ajax' );
function rdrit_menu_sync_ajax() {
    check_ajax_referer( 'rdrit_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Unauthorized' );
    if ( ! function_exists( 'pll_languages_list' ) ) wp_send_json_error( 'Polylang non actif.' );

    $source_id      = (int) ( $_POST['source_menu']  ?? 0 );
    $target_lang    = sanitize_key( $_POST['target_lang']  ?? '' );
    $target_menu_id = (int) ( $_POST['target_menu']  ?? 0 );
    $mode           = in_array( $_POST['mode'] ?? '', array( 'diff', 'full' ), true )
                      ? $_POST['mode'] : 'diff';
    $selected       = json_decode( stripslashes( $_POST['selected'] ?? '[]' ), true );

    if ( ! $source_id || ! $target_lang ) wp_send_json_error( 'Paramètres manquants.' );

    $result = ( $mode === 'full' )
        ? rdrit_sync_full( $source_id, $target_lang, $target_menu_id )
        : rdrit_sync_diff( $source_id, $target_lang, (array) $selected, $target_menu_id );

    wp_send_json_success( $result );
}

/* ============================================================
   RÉSOLUTION D'UN ITEM SOURCE → données cible
   Retourne false si pas de traduction disponible
   ============================================================ */
function rdrit_resolve_item( object $src, string $target_lang ): ?array {
    $resolved = array(
        'source_id'   => $src->ID,
        'title'       => $src->title,
        'url'         => $src->url,
        'type'        => $src->type,
        'object'      => $src->object,
        'object_id'   => (int) $src->object_id,
        'parent'      => (int) $src->menu_item_parent,
        'position'    => (int) $src->menu_order,
        'is_external' => false,
        'target_obj_id' => 0,
    );

    if ( $src->type === 'custom' ) {
        // Lien externe : copié tel quel
        $resolved['is_external']  = true;
        $resolved['target_obj_id'] = 0;
        return $resolved;
    }

    if ( $src->type === 'post_type' ) {
        $translated = (int) pll_get_post( (int) $src->object_id, $target_lang );
        if ( ! $translated ) return null; // Pas de traduction
        $resolved['title']        = get_the_title( $translated ) ?: $src->title;
        $resolved['url']          = get_permalink( $translated ) ?: $src->url;
        $resolved['object_id']    = $translated;
        $resolved['target_obj_id'] = $translated;
        return $resolved;
    }

    if ( $src->type === 'taxonomy' ) {
        $translated = (int) pll_get_term( (int) $src->object_id, $target_lang );
        if ( ! $translated ) return null;
        $term = get_term( $translated );
        $resolved['title']        = ( $term && ! is_wp_error( $term ) ) ? $term->name : $src->title;
        $resolved['url']          = get_term_link( $translated ) ?: $src->url;
        $resolved['object_id']    = $translated;
        $resolved['target_obj_id'] = $translated;
        return $resolved;
    }

    // Fallback : traiter comme externe
    $resolved['is_external'] = true;
    return $resolved;
}

/* ============================================================
   CONSTRUIRE L'INDEX DU MENU CIBLE
   Clé : "type|object_id" ou "custom|url|title|position"
   Pour les liens custom avec URL ambiguë (#, vide, dupliquée),
   on discrimine aussi par titre et position dans le menu.
   ============================================================ */
function rdrit_build_target_index( int $target_menu_id ): array {
    $index     = array(); // clé → item
    $url_count = array(); // comptage des URLs custom pour détecter les doublons
    if ( ! $target_menu_id ) return $index;

    $items = wp_get_nav_menu_items( $target_menu_id, array( 'update_post_term_cache' => false ) );
    if ( ! $items ) return $index;

    // Premier passage : compter les URLs custom
    foreach ( $items as $item ) {
        if ( $item->type === 'custom' ) {
            $url = trim( $item->url );
            $url_count[ $url ] = ( $url_count[ $url ] ?? 0 ) + 1;
        }
    }

    // Deuxième passage : compter les combinaisons url|titre dupliquées
    $url_title_count = array();
    foreach ( $items as $item ) {
        if ( $item->type === 'custom' ) {
            $k = trim( $item->url ) . '|' . $item->title;
            $url_title_count[ $k ] = ( $url_title_count[ $k ] ?? 0 ) + 1;
        }
    }

    foreach ( $items as $item ) {
        if ( $item->type === 'custom' ) {
            $url = trim( $item->url );
            $utk = $url . '|' . $item->title;
            // URL ambiguë (#, vide, ou dupliquée) → ajouter titre
            if ( $url === '#' || $url === '' || ( $url_count[ $url ] ?? 0 ) > 1 ) {
                // Titre aussi dupliqué → ajouter position
                if ( ( $url_title_count[ $utk ] ?? 0 ) > 1 ) {
                    $key = 'custom|' . $url . '|' . $item->title . '|' . $item->menu_order;
                } else {
                    $key = 'custom|' . $url . '|' . $item->title;
                }
            } else {
                $key = 'custom|' . $url;
            }
        } else {
            $key = $item->type . '|' . $item->object . '|' . (int) $item->object_id;
        }
        $index[ $key ] = $item;
    }
    return $index;
}

function rdrit_resolved_key( array $r ): string {
    if ( $r['is_external'] ) {
        $url = trim( $r['url'] );
        // Pour les # ou vides : titre + position pour être certain d'être unique
        if ( $url === '#' || $url === '' ) {
            return 'custom|' . $url . '|' . $r['title'] . '|' . $r['position'];
        }
        return 'custom|' . $url;
    }
    return $r['type'] . '|' . $r['object'] . '|' . $r['target_obj_id'];
}

/* ============================================================
   TROUVER LE MENU CIBLE
   Si target_menu_id fourni et > 0 : on l'utilise directement.
   Sinon tentative via les locations Polylang (sans fallback par nom).
   ============================================================ */
function rdrit_get_target_menu( int $source_id, string $target_lang, int $explicit_target = 0 ): int {
    // Menu cible explicitement sélectionné par l'utilisateur — priorité absolue
    if ( $explicit_target > 0 ) return $explicit_target;

    $locations = get_nav_menu_locations();

    foreach ( $locations as $loc => $mid ) {
        if ( (int) $mid !== $source_id ) continue;
        if ( preg_match( '/__([a-z]{2,5})$/', $loc, $m ) ) {
            $source_lang = $m[1];
            $target_loc  = str_replace( '__' . $source_lang, '__' . $target_lang, $loc );
            if ( isset( $locations[ $target_loc ] ) && (int) $locations[ $target_loc ] > 0 ) {
                return (int) $locations[ $target_loc ];
            }
        }
    }

    // Pas de fallback par nom — on retourne 0 et l'UI demandera de choisir
    return 0;
}

/* ============================================================
   CONSTRUIRE LE DIFF
   ============================================================ */
function rdrit_build_diff( int $source_id, string $target_lang, int $explicit_target = 0 ): array {
    $target_menu_id = rdrit_get_target_menu( $source_id, $target_lang, $explicit_target );

    $source_items = wp_get_nav_menu_items( $source_id, array( 'update_post_term_cache' => false ) ) ?: array();
    $target_index = rdrit_build_target_index( $target_menu_id );

    $diff_items    = array();
    $target_seen   = array(); // clés vues côté source (pour détecter supprimés)

    foreach ( $source_items as $src ) {
        $resolved = rdrit_resolve_item( $src, $target_lang );

        if ( $resolved === null ) {
            $diff_items[] = array(
                'source_id'    => $src->ID,
                'source_title' => $src->title,
                'source_url'   => $src->url,
                'status'       => 'no_translation',
                'parent'       => (int) $src->menu_item_parent,
                'position'     => (int) $src->menu_order,
            );
            continue;
        }

        $key         = rdrit_resolved_key( $resolved );
        $target_seen[] = $key;

        $row = array_merge( $resolved, array(
            'source_title'  => $src->title,
            'source_url'    => $src->url,
            'target_id'     => 0,
            'target_title'  => '',
            'target_url'    => '',
            'target_parent' => 0,
            'status'        => 'new',
            'changes'       => array(),
        ) );

        if ( isset( $target_index[ $key ] ) ) {
            $tgt = $target_index[ $key ];
            $row['target_id']     = $tgt->ID;
            $row['target_title']  = $tgt->title;
            $row['target_url']    = $tgt->url;
            $row['target_parent'] = (int) $tgt->menu_item_parent;

            $changes = array();
            if ( $tgt->title !== $resolved['title'] )             $changes[] = 'titre';
            if ( (int) $tgt->menu_order !== $resolved['position'] ) $changes[] = 'position';

            if ( $resolved['is_external'] ) {
                $row['status'] = 'external';
            } elseif ( empty( $changes ) ) {
                $row['status'] = 'identical';
            } else {
                $row['status']  = 'modified';
                $row['changes'] = $changes;
            }
        } else {
            $row['status'] = $resolved['is_external'] ? 'external' : 'new';
        }

        $diff_items[] = $row;
    }

    // Items supprimés : dans cible mais pas dans source
    $deleted = array();
    foreach ( $target_index as $key => $tgt ) {
        if ( ! in_array( $key, $target_seen, true ) ) {
            $deleted[] = array(
                'target_id'    => $tgt->ID,
                'target_title' => $tgt->title,
                'target_url'   => $tgt->url,
                'status'       => 'deleted',
            );
        }
    }

    return array(
        'target_menu_id' => $target_menu_id,
        'target_lang'    => $target_lang,
        'items'          => $diff_items,
        'deleted'        => $deleted,
    );
}

/* ============================================================
   SYNC DIFFÉRENTIEL — applique uniquement les items sélectionnés
   ============================================================ */
function rdrit_sync_diff( int $source_id, string $target_lang, array $selected, int $explicit_target = 0 ): array {
    $diff           = rdrit_build_diff( $source_id, $target_lang, $explicit_target );
    $target_menu_id = (int) $diff['target_menu_id'];

    if ( ! $target_menu_id ) {
        return array( 'error' => 'Menu cible introuvable pour la langue ' . $target_lang );
    }

    $counts = array( 'added' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0 );
    $errors = array();

    // Table source_id → new target item_id (pour résolution des parents)
    $parent_map = array();

    // Pré-remplir la parent_map avec les items existants non sélectionnés
    foreach ( $diff['items'] as $row ) {
        if ( $row['target_id'] ) {
            $parent_map[ $row['source_id'] ] = $row['target_id'];
        }
    }

    // Appliquer les items sélectionnés
    foreach ( $diff['items'] as $row ) {
        $key = 'item_' . $row['source_id'];

        if ( $row['status'] === 'no_translation' ) {
            $counts['skipped']++;
            continue;
        }
        if ( $row['status'] === 'identical' && ! in_array( $key, $selected, true ) ) {
            $counts['skipped']++;
            continue;
        }
        if ( ! in_array( $key, $selected, true ) ) {
            $counts['skipped']++;
            continue;
        }

        $target_parent = 0;
        if ( $row['parent'] && isset( $parent_map[ $row['parent'] ] ) ) {
            $target_parent = $parent_map[ $row['parent'] ];
        }

        $new_id = rdrit_upsert_item(
            $target_menu_id,
            $row['target_id'],
            $row,
            $target_parent
        );

        if ( is_wp_error( $new_id ) ) {
            $errors[] = $new_id->get_error_message();
        } else {
            $parent_map[ $row['source_id'] ] = $new_id;
            $row['target_id'] ? $counts['updated']++ : $counts['added']++;
        }
    }

    // Suppressions sélectionnées
    foreach ( $diff['deleted'] as $del ) {
        if ( ! in_array( 'deleted_' . $del['target_id'], $selected, true ) ) {
            $counts['skipped']++;
            continue;
        }
        if ( wp_delete_post( $del['target_id'], true ) ) {
            $counts['deleted']++;
        } else {
            $errors[] = 'Impossible de supprimer item #' . $del['target_id'];
        }
    }

    return rdrit_sync_result( $counts, $errors );
}

/* ============================================================
   SYNC COMPLET — vide le menu cible et recrée tout depuis FR
   ============================================================ */
function rdrit_sync_full( int $source_id, string $target_lang, int $explicit_target = 0 ): array {
    $target_menu_id = rdrit_get_target_menu( $source_id, $target_lang, $explicit_target );

    if ( ! $target_menu_id ) {
        return array( 'error' => 'Menu cible introuvable pour la langue ' . $target_lang );
    }

    // 1. Vider le menu cible
    $existing = wp_get_nav_menu_items( $target_menu_id, array( 'update_post_term_cache' => false ) ) ?: array();
    foreach ( $existing as $item ) {
        wp_delete_post( $item->ID, true );
    }

    // 2. Récupérer les items source dans l'ordre
    $source_items = wp_get_nav_menu_items( $source_id, array( 'update_post_term_cache' => false ) ) ?: array();

    $counts     = array( 'added' => 0, 'updated' => 0, 'deleted' => count( $existing ), 'skipped' => 0 );
    $errors     = array();
    $parent_map = array(); // source_item_id → new_target_item_id

    foreach ( $source_items as $src ) {
        $resolved = rdrit_resolve_item( $src, $target_lang );

        if ( $resolved === null ) {
            $counts['skipped']++;
            continue;
        }

        $target_parent = 0;
        if ( $resolved['parent'] && isset( $parent_map[ $resolved['parent'] ] ) ) {
            $target_parent = $parent_map[ $resolved['parent'] ];
        }

        $new_id = rdrit_upsert_item( $target_menu_id, 0, $resolved, $target_parent );

        if ( is_wp_error( $new_id ) ) {
            $errors[] = $new_id->get_error_message();
            $counts['skipped']++;
        } else {
            $parent_map[ $src->ID ] = $new_id;
            $counts['added']++;
        }
    }

    return rdrit_sync_result( $counts, $errors );
}

/* ============================================================
   INSÉRER OU METTRE À JOUR UN ITEM DE MENU
   $item_id = 0 → insert, > 0 → update
   ============================================================ */
function rdrit_upsert_item( int $menu_id, int $item_id, array $resolved, int $parent_id ) {
    $args = array(
        'menu-item-title'     => $resolved['title'],
        'menu-item-url'       => $resolved['url'],
        'menu-item-status'    => 'publish',
        'menu-item-position'  => $resolved['position'],
        'menu-item-parent-id' => $parent_id,
        'menu-item-type'      => $resolved['type'],
        'menu-item-object'    => $resolved['object'],
    );

    // Pour les items non-custom, pointer vers l'objet traduit
    if ( ! $resolved['is_external'] && ! empty( $resolved['target_obj_id'] ) ) {
        $args['menu-item-object-id'] = $resolved['target_obj_id'];
    }

    return wp_update_nav_menu_item( $menu_id, $item_id, $args );
}

/* ============================================================
   FORMATER LE RÉSULTAT
   ============================================================ */
function rdrit_sync_result( array $counts, array $errors ): array {
    return array(
        'counts' => $counts,
        'errors' => $errors,
        'msg'    => sprintf(
            '%d ajouté(s), %d modifié(s), %d supprimé(s), %d ignoré(s)',
            $counts['added'], $counts['updated'], $counts['deleted'], $counts['skipped']
        ),
    );
}

/* ============================================================
   CPT — rdrit_menu_anchor
   Post type léger pour les items de menu "parent sans lien".
   Polylang le gère nativement → pll_get_post() fonctionne.
   L'URL dans le menu est filtrée vers "#slug".
   ============================================================ */
add_action( 'init', 'rdrit_register_menu_anchor_cpt' );
function rdrit_register_menu_anchor_cpt() {
    register_post_type( 'rdrit_menu_anchor', array(
        'labels' => array(
            'name'               => 'Ancres de menu',
            'singular_name'      => 'Ancre de menu',
            'add_new'            => 'Ajouter',
            'add_new_item'       => 'Ajouter une ancre',
            'edit_item'          => 'Modifier l\'ancre',
            'new_item'           => 'Nouvelle ancre',
            'view_item'          => 'Voir l\'ancre',
            'search_items'       => 'Rechercher',
            'not_found'          => 'Aucune ancre trouvée',
            'not_found_in_trash' => 'Aucune ancre dans la corbeille',
            'menu_name'          => 'Ancres menu',
        ),
        'public'              => false,  // Pas de front-end dédié
        'publicly_queryable'  => false,
        'show_ui'             => true,   // Visible dans l'admin
        'show_in_menu'        => true,   // Sous le menu Apparence serait mieux mais on met true
        'show_in_nav_menus'   => true,   // ← Clé : apparaît dans Apparence > Menus
        'show_in_rest'        => true,   // Gutenberg
        'supports'            => array( 'title' ), // Titre uniquement
        'has_archive'         => false,
        'rewrite'             => false,
        'query_var'           => false,
        'menu_icon'           => 'dashicons-menu-alt',
        'menu_position'       => 61,
    ) );
}

/* ── Filtrer l'URL du permalink → "#slug" ── */
add_filter( 'post_type_link', 'rdrit_menu_anchor_permalink', 10, 2 );
function rdrit_menu_anchor_permalink( string $url, WP_Post $post ): string {
    if ( $post->post_type !== 'rdrit_menu_anchor' ) return $url;
    return '#' . $post->post_name;
}

/* ── Même chose dans les menus (nav_menu_item) ── */
add_filter( 'nav_menu_link_attributes', 'rdrit_menu_anchor_nav_attr', 10, 3 );
function rdrit_menu_anchor_nav_attr( array $atts, WP_Post $item ): array {
    if ( $item->object !== 'rdrit_menu_anchor' ) return $atts;
    $post = get_post( $item->object_id );
    if ( $post ) {
        $atts['href'] = '#' . $post->post_name;
    }
    return $atts;
}

/* ── Enregistrer le CPT auprès de Polylang (public=>false mais géré par PLL) ── */
add_filter( 'pll_get_post_types', 'rdrit_anchor_pll_post_types', 10, 2 );
function rdrit_anchor_pll_post_types( array $post_types, bool $is_settings ): array {
    // $is_settings=true → onglet Polylang admin (coché automatiquement)
    // $is_settings=false → runtime (filtrage des requêtes)
    $post_types['rdrit_menu_anchor'] = 'rdrit_menu_anchor';
    return $post_types;
}

/* ── Masquer la colonne "Permalien" dans la liste admin ── */
add_filter( 'post_row_actions', 'rdrit_menu_anchor_row_actions', 10, 2 );
function rdrit_menu_anchor_row_actions( array $actions, WP_Post $post ): array {
    if ( $post->post_type !== 'rdrit_menu_anchor' ) return $actions;
    unset( $actions['view'] );
    return $actions;
}

/* Handler rdrit_anchor_create supprimé — création via interface WP standard */
