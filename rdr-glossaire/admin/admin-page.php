<?php
/**
 * Admin — RDR-IT Glossaire
 * Gestion des définitions + settings + meta boxes CPT
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ============================================================
   MENU ADMIN
   ============================================================ */
add_action( 'admin_menu', 'rdrg_admin_menu' );
function rdrg_admin_menu() {
    // Menu principal
    add_menu_page(
        __( 'Glossaire RDR-IT', 'rdr-glossaire' ),
        __( 'Glossaire', 'rdr-glossaire' ),
        'manage_options',
        'rdr-glossaire',
        'rdrg_admin_main_page',
        'dashicons-book-alt',
        30
    );

    // Sous-menu : liste des définitions (pointe vers le CPT natif)
    add_submenu_page(
        'rdr-glossaire',
        __( 'Définitions', 'rdr-glossaire' ),
        __( 'Définitions', 'rdr-glossaire' ),
        'manage_options',
        'edit.php?post_type=' . RDRG_CPT
    );

    // Sous-menu : ajouter une définition
    add_submenu_page(
        'rdr-glossaire',
        __( 'Ajouter', 'rdr-glossaire' ),
        __( '+ Ajouter', 'rdr-glossaire' ),
        'manage_options',
        'post-new.php?post_type=' . RDRG_CPT
    );

    // Sous-menu : réglages
    add_submenu_page(
        'rdr-glossaire',
        __( 'Réglages', 'rdr-glossaire' ),
        __( 'Réglages', 'rdr-glossaire' ),
        'manage_options',
        'rdr-glossaire-settings',
        'rdrg_admin_settings_page'
    );
}

/* ============================================================
   ASSETS ADMIN
   ============================================================ */
add_action( 'admin_enqueue_scripts', 'rdrg_admin_enqueue' );
function rdrg_admin_enqueue( $hook ) {
    // Charger sur toutes les pages du plugin
    $screens = [
        'toplevel_page_rdr-glossaire',
        'glossaire_page_rdr-glossaire-settings',
        'edit-' . RDRG_CPT,
        RDRG_CPT,
    ];

    $current = get_current_screen();
    $is_plugin_page = $current && (
        strpos( $current->id, 'rdr-glossaire' ) !== false ||
        strpos( $current->id, RDRG_CPT ) !== false
    );

    if ( ! $is_plugin_page ) return;

    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_style(
        'rdrg-admin-css',
        RDRG_URL . 'assets/admin.css',
        [],
        RDRG_VERSION
    );
    wp_enqueue_script(
        'rdrg-admin-js',
        RDRG_URL . 'assets/admin.js',
        [ 'jquery', 'wp-color-picker' ],
        RDRG_VERSION,
        true
    );
}

/* ============================================================
   META BOXES CPT — Champs supplémentaires
   ============================================================ */
add_action( 'add_meta_boxes', 'rdrg_cpt_meta_boxes' );
function rdrg_cpt_meta_boxes() {
    add_meta_box(
        'rdrg-term-details',
        __( '⚙️ Paramètres du terme', 'rdr-glossaire' ),
        'rdrg_cpt_meta_box_render',
        RDRG_CPT,
        'normal',
        'high'
    );
}

function rdrg_cpt_meta_box_render( $post ) {
    wp_nonce_field( 'rdrg_cpt_meta_nonce', 'rdrg_cpt_nonce_field' );

    $aliases = get_post_meta( $post->ID, '_rdrg_aliases', true );
    $link    = get_post_meta( $post->ID, '_rdrg_link', true );
    $enabled = get_post_meta( $post->ID, '_rdrg_term_enabled', true );
    $enabled = $enabled === '' ? '1' : $enabled; // actif par défaut
    ?>
    <style>
    .rdrg-field-row { margin-bottom: 16px; }
    .rdrg-field-row label { display: block; font-weight: 600; margin-bottom: 4px; }
    .rdrg-field-row input[type="text"],
    .rdrg-field-row input[type="url"] { width: 100%; }
    .rdrg-field-row .description { color: #666; font-size: 12px; margin-top: 4px; }
    .rdrg-toggle-wrap { display: flex; align-items: center; gap: 10px; }
    .rdrg-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
    .rdrg-switch input { opacity: 0; width: 0; height: 0; }
    .rdrg-slider { position: absolute; cursor: pointer; inset: 0; background: #ccc; border-radius: 24px; transition: .3s; }
    .rdrg-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: .3s; }
    input:checked + .rdrg-slider { background: #2271b1; }
    input:checked + .rdrg-slider:before { transform: translateX(20px); }
    </style>

    <div class="rdrg-field-row">
        <label for="rdrg_aliases"><?php _e( '🔤 Alias / variantes', 'rdr-glossaire' ); ?></label>
        <input type="text"
               id="rdrg_aliases"
               name="rdrg_aliases"
               value="<?php echo esc_attr( $aliases ); ?>"
               placeholder="dhcp, DHCP, Dynamic Host Configuration Protocol">
        <p class="description">
            <?php _e( 'Séparés par des virgules. Ces variantes déclencheront aussi le tooltip. Insensible à la casse.', 'rdr-glossaire' ); ?>
        </p>
    </div>

    <div class="rdrg-field-row">
        <label for="rdrg_link"><?php _e( '🔗 Lien vers un article / catégorie (optionnel)', 'rdr-glossaire' ); ?></label>
        <input type="url"
               id="rdrg_link"
               name="rdrg_link"
               value="<?php echo esc_url( $link ); ?>"
               placeholder="https://rdr-it.com/categorie/dhcp/">
        <p class="description">
            <?php _e( 'Si renseigné, un bouton "En savoir plus" apparaîtra dans le tooltip.', 'rdr-glossaire' ); ?>
        </p>
    </div>

    <div class="rdrg-field-row">
        <div class="rdrg-toggle-wrap">
            <label class="rdrg-switch">
                <input type="checkbox" name="rdrg_term_enabled" value="1" <?php checked( $enabled, '1' ); ?>>
                <span class="rdrg-slider"></span>
            </label>
            <span><?php _e( 'Terme actif (affiché en tooltip)', 'rdr-glossaire' ); ?></span>
        </div>
    </div>

    <div class="rdrg-field-row" style="background:#f0f6ff;padding:10px;border-radius:6px;border-left:3px solid #2271b1;">
        <strong><?php _e( '💡 Conseil :', 'rdr-glossaire' ); ?></strong>
        <?php _e( 'La définition courte est utilisée dans le tooltip. Utilisez l\'<strong>extrait</strong> pour un résumé plus précis, ou le contenu principal pour la définition complète.', 'rdr-glossaire' ); ?>
    </div>
    <?php
}

add_action( 'save_post_' . RDRG_CPT, 'rdrg_save_cpt_meta' );
function rdrg_save_cpt_meta( $post_id ) {
    if ( ! isset( $_POST['rdrg_cpt_nonce_field'] ) ) return;
    if ( ! wp_verify_nonce( $_POST['rdrg_cpt_nonce_field'], 'rdrg_cpt_meta_nonce' ) ) return;
    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

    // Aliases
    $aliases = isset( $_POST['rdrg_aliases'] ) ? sanitize_text_field( $_POST['rdrg_aliases'] ) : '';
    update_post_meta( $post_id, '_rdrg_aliases', $aliases );

    // Link
    $link = isset( $_POST['rdrg_link'] ) ? esc_url_raw( $_POST['rdrg_link'] ) : '';
    update_post_meta( $post_id, '_rdrg_link', $link );

    // Enabled
    $term_enabled = isset( $_POST['rdrg_term_enabled'] ) ? '1' : '0';
    update_post_meta( $post_id, '_rdrg_term_enabled', $term_enabled );

    // Vider le cache
    rdrg_clear_cache();
}

/* ============================================================
   PAGE PRINCIPALE — Dashboard statistiques
   ============================================================ */
function rdrg_admin_main_page() {
    $settings  = get_option( RDRG_OPTION, [] );
    $enabled   = ! empty( $settings['enabled'] );
    $count_all = wp_count_posts( RDRG_CPT )->publish;
    ?>
    <div class="wrap rdrg-wrap">
        <h1 class="rdrg-page-title">
            <span class="dashicons dashicons-book-alt"></span>
            <?php _e( 'Glossaire RDR-IT', 'rdr-glossaire' ); ?>
        </h1>

        <!-- Bandeau statut global -->
        <div class="rdrg-status-banner <?php echo $enabled ? 'rdrg-status-on' : 'rdrg-status-off'; ?>">
            <div class="rdrg-status-left">
                <span class="rdrg-status-dot"></span>
                <strong>
                    <?php echo $enabled
                        ? __( 'Glossaire ACTIVÉ — les tooltips sont visibles sur le site', 'rdr-glossaire' )
                        : __( 'Glossaire DÉSACTIVÉ — aucun tooltip n\'est affiché', 'rdr-glossaire' );
                    ?>
                </strong>
            </div>
            <a href="<?php echo admin_url( 'admin.php?page=rdr-glossaire-settings' ); ?>" class="button button-primary">
                <?php _e( '⚙️ Réglages', 'rdr-glossaire' ); ?>
            </a>
        </div>

        <!-- Statistiques -->
        <div class="rdrg-stats-grid">
            <div class="rdrg-stat-card">
                <div class="rdrg-stat-number"><?php echo intval( $count_all ); ?></div>
                <div class="rdrg-stat-label"><?php _e( 'Définitions publiées', 'rdr-glossaire' ); ?></div>
            </div>
            <?php if ( function_exists( 'pll_languages_list' ) ): ?>
            <div class="rdrg-stat-card">
                <div class="rdrg-stat-number"><?php echo count( pll_languages_list() ); ?></div>
                <div class="rdrg-stat-label"><?php _e( 'Langues Polylang', 'rdr-glossaire' ); ?></div>
            </div>
            <?php endif; ?>
            <div class="rdrg-stat-card">
                <div class="rdrg-stat-number">
                    <?php
                    $args = [
                        'post_type'  => RDRG_CPT,
                        'post_status'=> 'publish',
                        'posts_per_page' => -1,
                        'fields'     => 'ids',
                        'meta_query' => [[
                            'key'     => '_rdrg_link',
                            'compare' => '!=',
                            'value'   => '',
                        ]],
                    ];
                    echo count( get_posts( $args ) );
                    ?>
                </div>
                <div class="rdrg-stat-label"><?php _e( 'Termes avec lien', 'rdr-glossaire' ); ?></div>
            </div>
        </div>

        <!-- Actions rapides -->
        <div class="rdrg-quick-actions">
            <h2><?php _e( 'Actions rapides', 'rdr-glossaire' ); ?></h2>
            <a href="<?php echo admin_url( 'post-new.php?post_type=' . RDRG_CPT ); ?>" class="button button-primary button-hero">
                + <?php _e( 'Ajouter une définition', 'rdr-glossaire' ); ?>
            </a>
            <a href="<?php echo admin_url( 'edit.php?post_type=' . RDRG_CPT ); ?>" class="button button-secondary button-hero">
                <?php _e( '📋 Voir toutes les définitions', 'rdr-glossaire' ); ?>
            </a>
            <a href="<?php echo esc_url( home_url( '/glossaire/' ) ); ?>" target="_blank" class="button button-secondary button-hero">
                <?php _e( '🌐 Voir la page glossaire', 'rdr-glossaire' ); ?>
            </a>
            <a href="<?php echo rest_url( 'rdr-glossaire/v1/terms' ); ?>" target="_blank" class="button button-secondary button-hero">
                <?php _e( '🔍 Voir l\'API REST', 'rdr-glossaire' ); ?>
            </a>
        </div>

        <!-- Dernières définitions -->
        <?php
        $recent = get_posts([
            'post_type'      => RDRG_CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 8,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        if ( $recent ):
        ?>
        <div class="rdrg-recent-terms">
            <h2><?php _e( 'Définitions récentes', 'rdr-glossaire' ); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e( 'Terme', 'rdr-glossaire' ); ?></th>
                        <th><?php _e( 'Alias', 'rdr-glossaire' ); ?></th>
                        <th><?php _e( 'Lien', 'rdr-glossaire' ); ?></th>
                        <th><?php _e( 'Statut', 'rdr-glossaire' ); ?></th>
                        <th><?php _e( 'Actions', 'rdr-glossaire' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $recent as $p ):
                    $aliases = get_post_meta( $p->ID, '_rdrg_aliases', true );
                    $link    = get_post_meta( $p->ID, '_rdrg_link', true );
                    $active  = get_post_meta( $p->ID, '_rdrg_term_enabled', true );
                    $active  = $active === '' ? '1' : $active;
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $p->post_title ); ?></strong></td>
                        <td><code><?php echo $aliases ? esc_html( $aliases ) : '—'; ?></code></td>
                        <td><?php echo $link ? '<a href="' . esc_url( $link ) . '" target="_blank">🔗</a>' : '—'; ?></td>
                        <td>
                            <?php if ( $active === '1' ): ?>
                                <span style="color:#00a32a;">✅ <?php _e( 'Actif', 'rdr-glossaire' ); ?></span>
                            <?php else: ?>
                                <span style="color:#d63638;">⏸ <?php _e( 'Inactif', 'rdr-glossaire' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo get_edit_post_link( $p->ID ); ?>"><?php _e( 'Modifier', 'rdr-glossaire' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <style>
    .rdrg-wrap { max-width: 1100px; }
    .rdrg-page-title { display: flex; align-items: center; gap: 10px; font-size: 24px; margin-bottom: 20px; }
    .rdrg-page-title .dashicons { font-size: 28px; width: 28px; height: 28px; }
    .rdrg-status-banner { display:flex; justify-content:space-between; align-items:center;
        padding: 14px 20px; border-radius: 8px; margin-bottom: 24px; }
    .rdrg-status-on  { background: #d7eddf; border: 1px solid #00a32a; }
    .rdrg-status-off { background: #fce8e8; border: 1px solid #d63638; }
    .rdrg-status-left { display: flex; align-items: center; gap: 10px; }
    .rdrg-status-dot { width: 12px; height: 12px; border-radius: 50%; flex-shrink: 0; }
    .rdrg-status-on .rdrg-status-dot  { background: #00a32a; box-shadow: 0 0 0 3px rgba(0,163,42,.3); }
    .rdrg-status-off .rdrg-status-dot { background: #d63638; }
    .rdrg-stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 16px; margin-bottom: 28px; }
    .rdrg-stat-card { background: #fff; border: 1px solid #ddd; border-radius: 8px; padding: 20px; text-align: center; }
    .rdrg-stat-number { font-size: 36px; font-weight: 700; color: #2271b1; }
    .rdrg-stat-label { font-size: 13px; color: #666; margin-top: 4px; }
    .rdrg-quick-actions { margin-bottom: 28px; display: flex; gap: 12px; flex-wrap: wrap; }
    .rdrg-recent-terms { margin-top: 8px; }
    </style>
    <?php
}

/* ============================================================
   PAGE RÉGLAGES
   ============================================================ */
function rdrg_admin_settings_page() {
    if ( isset( $_POST['rdrg_save_settings'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'rdrg_save_settings' ) ) {
        $allowed_match_modes = [ 'all', 'first_article', 'first_block' ];
        $match_mode = sanitize_text_field( $_POST['rdrg_match_mode'] ?? 'first_block' );
        if ( ! in_array( $match_mode, $allowed_match_modes ) ) $match_mode = 'first_block';

        $settings = [
            'enabled'         => isset( $_POST['rdrg_enabled'] ) ? 1 : 0,
            'post_types'      => isset( $_POST['rdrg_post_types'] ) ? array_map( 'sanitize_text_field', (array) $_POST['rdrg_post_types'] ) : [ 'post' ],
            'tooltip_pos'     => sanitize_text_field( $_POST['rdrg_tooltip_pos'] ?? 'top' ),
            'show_link'       => isset( $_POST['rdrg_show_link'] ) ? 1 : 0,
            'polylang'        => isset( $_POST['rdrg_polylang'] ) ? 1 : 0,
            'highlight'       => sanitize_text_field( $_POST['rdrg_highlight'] ?? 'underline' ),
            'match_mode'      => $match_mode,
            'switch_position' => sanitize_text_field( $_POST['rdrg_switch_position'] ?? 'inline' ),
        ];
        update_option( RDRG_OPTION, $settings );
        echo '<div class="notice notice-success"><p>' . __( '✅ Réglages sauvegardés.', 'rdr-glossaire' ) . '</p></div>';
    }

    $settings   = get_option( RDRG_OPTION, [] );
    $enabled    = ! empty( $settings['enabled'] );
    $post_types = isset( $settings['post_types'] ) ? $settings['post_types'] : [ 'post' ];
    $pos        = $settings['tooltip_pos'] ?? 'top';
    $show_link  = isset( $settings['show_link'] ) ? $settings['show_link'] : 1;
    $polylang   = ! empty( $settings['polylang'] );
    $highlight  = $settings['highlight'] ?? 'underline';
    $match_mode = $settings['match_mode'] ?? 'first_block';
    $switch_pos = $settings['switch_position'] ?? 'inline';

    $all_post_types = get_post_types( [ 'public' => true ], 'objects' );
    ?>
    <div class="wrap rdrg-wrap">
        <h1><?php _e( '⚙️ Réglages — Glossaire RDR-IT', 'rdr-glossaire' ); ?></h1>

        <form method="post">
            <?php wp_nonce_field( 'rdrg_save_settings' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e( 'Activation globale', 'rdr-glossaire' ); ?></th>
                    <td>
                        <label class="rdrg-big-switch">
                            <input type="checkbox" name="rdrg_enabled" value="1" <?php checked( $enabled ); ?>>
                            <span class="rdrg-switch-track">
                                <span class="rdrg-switch-thumb"></span>
                            </span>
                            <span class="rdrg-switch-label">
                                <?php _e( 'Activer les tooltips glossaire sur le site', 'rdr-glossaire' ); ?>
                            </span>
                        </label>
                        <p class="description">
                            <?php _e( 'Quand désactivé, aucun JS ni CSS n\'est chargé en front-end.', 'rdr-glossaire' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php _e( 'Types de contenu', 'rdr-glossaire' ); ?></th>
                    <td>
                        <?php foreach ( $all_post_types as $pt ): ?>
                        <?php if ( in_array( $pt->name, [ 'attachment', RDRG_CPT ] ) ) continue; ?>
                        <label style="display:block;margin-bottom:6px;">
                            <input type="checkbox"
                                   name="rdrg_post_types[]"
                                   value="<?php echo esc_attr( $pt->name ); ?>"
                                   <?php checked( in_array( $pt->name, $post_types ) ); ?>>
                            <?php echo esc_html( $pt->labels->singular_name ); ?>
                            <code style="color:#888;"><?php echo esc_html( $pt->name ); ?></code>
                        </label>
                        <?php endforeach; ?>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php _e( 'Position du tooltip', 'rdr-glossaire' ); ?></th>
                    <td>
                        <select name="rdrg_tooltip_pos">
                            <option value="top"    <?php selected( $pos, 'top' ); ?>><?php _e( 'Haut (par défaut)', 'rdr-glossaire' ); ?></option>
                            <option value="bottom" <?php selected( $pos, 'bottom' ); ?>><?php _e( 'Bas', 'rdr-glossaire' ); ?></option>
                            <option value="auto"   <?php selected( $pos, 'auto' ); ?>><?php _e( 'Auto (détecte le bord)', 'rdr-glossaire' ); ?></option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php _e( 'Style du terme', 'rdr-glossaire' ); ?></th>
                    <td>
                        <select name="rdrg_highlight">
                            <option value="underline" <?php selected( $highlight, 'underline' ); ?>><?php _e( 'Soulignement pointillé (recommandé)', 'rdr-glossaire' ); ?></option>
                            <option value="dotted"    <?php selected( $highlight, 'dotted' ); ?>><?php _e( 'Soulignement simple', 'rdr-glossaire' ); ?></option>
                            <option value="bg"        <?php selected( $highlight, 'bg' ); ?>><?php _e( 'Fond coloré discret', 'rdr-glossaire' ); ?></option>
                            <option value="none"      <?php selected( $highlight, 'none' ); ?>><?php _e( 'Aucun (juste le curseur)', 'rdr-glossaire' ); ?></option>
                        </select>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php _e( 'Occurrences du terme', 'rdr-glossaire' ); ?></th>
                    <td>
                        <select name="rdrg_match_mode">
                            <option value="first_block"   <?php selected( $match_mode, 'first_block' ); ?>>
                                <?php _e( 'Premier par paragraphe/bloc (recommandé)', 'rdr-glossaire' ); ?>
                            </option>
                            <option value="first_article" <?php selected( $match_mode, 'first_article' ); ?>>
                                <?php _e( 'Premier dans tout l\'article uniquement', 'rdr-glossaire' ); ?>
                            </option>
                            <option value="all"           <?php selected( $match_mode, 'all' ); ?>>
                                <?php _e( 'Toutes les occurrences', 'rdr-glossaire' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php _e( '<strong>Par paragraphe</strong> : souligne la première occurrence dans chaque bloc de texte — équilibre lisibilité et couverture.<br>
                            <strong>Article entier</strong> : souligne uniquement la toute première occurrence dans la page, plus discret.<br>
                            <strong>Toutes</strong> : souligne chaque occurrence, utile pour les glossaires denses.', 'rdr-glossaire' ); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php _e( 'Bouton "En savoir plus"', 'rdr-glossaire' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rdrg_show_link" value="1" <?php checked( $show_link ); ?>>
                            <?php _e( 'Afficher le lien vers l\'article si disponible', 'rdr-glossaire' ); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php _e( 'Position du switch glossaire', 'rdr-glossaire' ); ?></th>
                    <td>
                        <select name="rdrg_switch_position">
                            <option value="under-title" <?php selected( $switch_pos, 'under-title' ); ?>>
                                <?php _e( 'Sous le titre de l\'article', 'rdr-glossaire' ); ?>
                            </option>
                            <option value="floating-left" <?php selected( $switch_pos, 'floating-left' ); ?>>
                                <?php _e( 'Flottant — Gauche', 'rdr-glossaire' ); ?>
                            </option>
                            <option value="floating-right" <?php selected( $switch_pos, 'floating-right' ); ?>>
                                <?php _e( 'Flottant — Droite', 'rdr-glossaire' ); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php _e( 'Le shortcode <code>[rdrg_switch]</code> insère le toggle à l\'endroit exact dans le contenu.<br>Les positions flottantes détectent automatiquement le bouton de coloration syntaxique pour s\'empiler.', 'rdr-glossaire' ); ?>
                        </p>
                    </td>
                </tr>

                <?php if ( function_exists( 'pll_languages_list' ) ): ?>
                <tr>
                    <th scope="row"><?php _e( 'Polylang', 'rdr-glossaire' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="rdrg_polylang" value="1" <?php checked( $polylang ); ?>>
                            <?php _e( 'Activer la traduction des définitions (ajoute le CPT à Polylang)', 'rdr-glossaire' ); ?>
                        </label>
                        <p class="description">
                            <?php _e( 'Chaque définition peut être traduite indépendamment. Les tooltips afficheront la définition dans la langue courante.', 'rdr-glossaire' ); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
            </table>

            <hr>

            <h2><?php _e( '🔧 Performances & Cache', 'rdr-glossaire' ); ?></h2>
            <p>
                <?php _e( 'Les définitions sont chargées de façon <strong>asynchrone</strong> via l\'API REST WordPress et mises en cache côté navigateur (sessionStorage). Un cache serveur transient de 10 minutes est également actif.', 'rdr-glossaire' ); ?>
            </p>

            <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px;">
                <button type="button"
                        id="rdrg-clear-cache"
                        class="button button-secondary"
                        data-nonce="<?php echo wp_create_nonce( 'rdrg_clear_cache' ); ?>">
                    🗑️ <?php _e( 'Vider le cache transient', 'rdr-glossaire' ); ?>
                </button>
                <span id="rdrg-cache-msg" style="color:green;display:none;">✅ Cache vidé !</span>
            </div>

            <p class="submit">
                <button type="submit" name="rdrg_save_settings" class="button button-primary button-large">
                    <?php _e( '💾 Sauvegarder les réglages', 'rdr-glossaire' ); ?>
                </button>
            </p>
        </form>
    </div>

    <style>
    .rdrg-wrap { max-width: 900px; }
    .rdrg-big-switch { display: flex; align-items: center; gap: 12px; cursor: pointer; }
    .rdrg-switch-track {
        position: relative; width: 52px; height: 28px; flex-shrink: 0;
        background: #ccc; border-radius: 28px; transition: background .3s;
    }
    .rdrg-big-switch input:checked ~ .rdrg-switch-track { background: #2271b1; }
    .rdrg-switch-thumb {
        position: absolute; top: 3px; left: 3px;
        width: 22px; height: 22px; background: white;
        border-radius: 50%; transition: transform .3s;
    }
    .rdrg-big-switch input:checked ~ .rdrg-switch-track .rdrg-switch-thumb { transform: translateX(24px); }
    .rdrg-big-switch input { display: none; }
    .rdrg-switch-label { font-weight: 600; }
    </style>

    <script>
    jQuery(function($){
        $('#rdrg-clear-cache').on('click', function(){
            var btn = $(this);
            btn.prop('disabled', true).text('⏳ Vidage...');
            $.post(ajaxurl, {
                action: 'rdrg_admin_clear_cache',
                nonce: btn.data('nonce')
            }, function(r){
                btn.prop('disabled', false).text('🗑️ Vider le cache transient');
                if(r.success){ $('#rdrg-cache-msg').show().delay(2500).fadeOut(); }
            });
        });
    });
    </script>
    <?php
}

/* ============================================================
   AJAX — Vider le cache depuis l'admin
   ============================================================ */
add_action( 'wp_ajax_rdrg_admin_clear_cache', 'rdrg_ajax_admin_clear_cache' );
function rdrg_ajax_admin_clear_cache() {
    check_ajax_referer( 'rdrg_clear_cache', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_die();
    rdrg_clear_cache();
    wp_send_json_success();
}
