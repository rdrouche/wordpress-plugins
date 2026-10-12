<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPS_Metaboxes {

    public static function init() {
        add_action( 'add_meta_boxes', [ __CLASS__, 'add' ] );
        add_action( 'save_post_wps_poll', [ __CLASS__, 'save' ], 10, 2 );
    }

    public static function add() {
        add_meta_box( 'wps_settings', __( 'Paramètres du sondage', 'wp-sondage' ),
            [ __CLASS__, 'render_settings' ], 'wps_poll', 'side', 'high' );
        add_meta_box( 'wps_choices', __( 'Question & Réponses', 'wp-sondage' ),
            [ __CLASS__, 'render_choices' ], 'wps_poll', 'normal', 'high' );
    }

    /* ── Settings metabox ── */
    public static function render_settings( $post ) {
        wp_nonce_field( 'wps_save_meta', 'wps_nonce' );

        $type       = get_post_meta( $post->ID, '_wps_type',          true ) ?: 'radio';
        $end_date   = get_post_meta( $post->ID, '_wps_end_date',      true );
        $closed     = get_post_meta( $post->ID, '_wps_closed',        true );
        $results    = get_post_meta( $post->ID, '_wps_results',       true ) ?: 'public';
        $active     = get_post_meta( $post->ID, '_wps_active',        true );
        $active     = ( $active === '' ) ? '1' : $active;
        $sync_off   = get_post_meta( $post->ID, '_wps_sync_disabled', true ) === '1';
        ?>
        <p>
            <label><strong><?php _e( 'Type de réponse', 'wp-sondage' ); ?></strong></label><br>
            <label><input type="radio" name="wps_type" value="radio" <?php checked( $type, 'radio' ); ?>> <?php _e( 'Choix unique (radio)', 'wp-sondage' ); ?></label><br>
            <label><input type="radio" name="wps_type" value="checkbox" <?php checked( $type, 'checkbox' ); ?>> <?php _e( 'Choix multiples (checkbox)', 'wp-sondage' ); ?></label>
        </p>
        <p>
            <label><strong><?php _e( 'Date de fin (optionnelle)', 'wp-sondage' ); ?></strong></label><br>
            <input type="datetime-local" name="wps_end_date" value="<?php echo esc_attr( $end_date ); ?>" style="width:100%">
        </p>
        <p>
            <label><input type="checkbox" name="wps_closed" value="1" <?php checked( $closed, '1' ); ?>> <?php _e( 'Clore manuellement', 'wp-sondage' ); ?></label>
        </p>
        <p>
            <label><strong><?php _e( 'Résultats', 'wp-sondage' ); ?></strong></label><br>
            <label><input type="radio" name="wps_results" value="public" <?php checked( $results, 'public' ); ?>> <?php _e( 'Publics', 'wp-sondage' ); ?></label><br>
            <label><input type="radio" name="wps_results" value="private" <?php checked( $results, 'private' ); ?>> <?php _e( 'Privés (admin uniquement)', 'wp-sondage' ); ?></label>
        </p>
        <p>
            <label><input type="checkbox" name="wps_active" value="1" <?php checked( $active, '1' ); ?>> <?php _e( 'Afficher en front-end', 'wp-sondage' ); ?></label>
        </p>
        <?php
        // Informations sur le groupe de traduction Polylang
        if ( function_exists( 'pll_get_post_translations' ) ) :
            $translations = pll_get_post_translations( $post->ID );
            if ( count( $translations ) > 1 ) :
                $master_id = class_exists( 'WPS_Sync' ) ? WPS_Sync::get_master_poll_id( $post->ID ) : 0;
        ?>
        <hr style="border:none;border-top:1px solid #eee;margin:10px 0">
        <p style="font-size:12px;color:#646970">
            <strong><?php _e( 'Groupe multilingue', 'wp-sondage' ); ?></strong><br>
            <?php foreach ( $translations as $lang => $pid ) : ?>
            <span style="<?php echo $pid == $master_id ? 'font-weight:bold;color:#2271b1' : ''; ?>">
                <?php echo esc_html( strtoupper( $lang ) ); ?>
                <?php echo $pid == $master_id ? ' ★ maître' : ''; ?>
            </span>
            <?php endforeach; ?>
        </p>
        <p>
            <label>
                <input type="checkbox" name="wps_sync_disabled" value="1" <?php checked( $sync_off ); ?>>
                <?php _e( 'Désactiver la synchro des votes', 'wp-sondage' ); ?>
            </label>
            <br><small style="color:#646970"><?php _e( 'Si coché, les votes sont comptés séparément par langue.', 'wp-sondage' ); ?></small>
        </p>
        <?php
            endif;
        endif;
        ?>
        <?php
    }

    /* ── Choices metabox ── */
    public static function render_choices( $post ) {
        global $wpdb;
        $table   = $wpdb->prefix . 'wps_choices';
        $choices = $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM $table WHERE poll_id = %d ORDER BY parent_id ASC, sort_order ASC", $post->ID )
        );

        $question = get_post_meta( $post->ID, '_wps_question', true );
        ?>
        <p>
            <label><strong><?php _e( 'Question principale', 'wp-sondage' ); ?></strong></label><br>
            <input type="text" name="wps_question" value="<?php echo esc_attr( $question ); ?>" style="width:100%"
                   placeholder="<?php _e( 'Votre question…', 'wp-sondage' ); ?>">
        </p>
        <hr>
        <p><strong><?php _e( 'Réponses', 'wp-sondage' ); ?></strong>
            <small><?php _e( '— ajoutez des sous-réponses via le bouton « + » à côté d\'une réponse.', 'wp-sondage' ); ?></small>
        </p>
        <div id="wps-choices-wrapper">
        <?php
        // Top-level choices
        $parents = array_filter( $choices, fn($c) => $c->parent_id == 0 );
        foreach ( $parents as $choice ) {
            self::render_choice_row( $choice, $choices );
        }
        ?>
        </div>

        <button type="button" class="button" id="wps-add-choice" data-parent="0">
            <?php _e( '+ Ajouter une réponse', 'wp-sondage' ); ?>
        </button>

        <script>
        (function($){
            let idx = <?php echo count($choices) + 100; ?>;

            function addRow(parentId, $container) {
                const row = `<div class="wps-choice-row" data-parent="${parentId}" style="margin:4px 0 4px ${parentId > 0 ? '30px' : '0'}">
                    <span class="dashicons dashicons-menu wps-sort-handle" style="cursor:move;vertical-align:middle"></span>
                    <input type="hidden" name="wps_choice[${idx}][parent]" value="${parentId}">
                    <input type="hidden" name="wps_choice[${idx}][id]" value="0">
                    <input type="text" name="wps_choice[${idx}][label]" placeholder="<?php _e('Réponse…','wp-sondage'); ?>" style="width:55%">
                    <input type="number" name="wps_choice[${idx}][order]" value="${idx}" style="width:50px" title="Ordre">
                    <button type="button" class="button button-small wps-add-sub" data-newparent="${idx}"><?php _e('+ Sous-réponse','wp-sondage'); ?></button>
                    <button type="button" class="button button-small wps-remove-choice"><?php _e('✕','wp-sondage'); ?></button>
                </div>`;
                $container.append(row);
                idx++;
            }

            $('#wps-add-choice').on('click', function(){
                addRow(0, $('#wps-choices-wrapper'));
            });

            $(document).on('click', '.wps-add-sub', function(){
                const newParent = $(this).data('newparent');
                let $sub = $(`.wps-choice-row[data-parent="${newParent}"]`).last();
                if (!$sub.length) $sub = $(this).closest('.wps-choice-row');
                addRow(newParent, $sub.parent());
            });

            $(document).on('click', '.wps-remove-choice', function(){
                $(this).closest('.wps-choice-row').remove();
            });
        })(jQuery);
        </script>
        <style>
        .wps-choice-row { display:flex; align-items:center; gap:6px; padding:3px 0; }
        </style>
        <?php
    }

    private static function render_choice_row( $choice, $all_choices ) {
        $children = array_filter( $all_choices, fn($c) => $c->parent_id == $choice->id );
        $indent   = $choice->parent_id > 0 ? 'margin-left:30px;' : '';
        ?>
        <div class="wps-choice-row" data-parent="<?php echo esc_attr( $choice->parent_id ); ?>"
             style="display:flex;align-items:center;gap:6px;padding:3px 0;<?php echo $indent; ?>">
            <span class="dashicons dashicons-menu wps-sort-handle" style="cursor:move;vertical-align:middle"></span>
            <input type="hidden" name="wps_choice[<?php echo $choice->id; ?>][id]" value="<?php echo esc_attr( $choice->id ); ?>">
            <input type="hidden" name="wps_choice[<?php echo $choice->id; ?>][parent]" value="<?php echo esc_attr( $choice->parent_id ); ?>">
            <input type="text" name="wps_choice[<?php echo $choice->id; ?>][label]"
                   value="<?php echo esc_attr( $choice->label ); ?>" style="width:55%"
                   placeholder="<?php _e('Réponse…','wp-sondage'); ?>">
            <input type="number" name="wps_choice[<?php echo $choice->id; ?>][order]"
                   value="<?php echo esc_attr( $choice->sort_order ); ?>" style="width:50px">
            <button type="button" class="button button-small wps-add-sub"
                    data-newparent="<?php echo esc_attr( $choice->id ); ?>"><?php _e('+ Sous-réponse','wp-sondage'); ?></button>
            <button type="button" class="button button-small wps-remove-choice">✕</button>
        </div>
        <?php
        foreach ( $children as $child ) {
            self::render_choice_row( $child, $all_choices );
        }
    }

    /* ── Save ── */
    public static function save( $post_id, $post ) {
        if ( ! isset( $_POST['wps_nonce'] ) || ! wp_verify_nonce( $_POST['wps_nonce'], 'wps_save_meta' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $fields = [
            '_wps_type'          => sanitize_text_field( $_POST['wps_type'] ?? 'radio' ),
            '_wps_end_date'      => sanitize_text_field( $_POST['wps_end_date'] ?? '' ),
            '_wps_closed'        => isset( $_POST['wps_closed'] ) ? '1' : '0',
            '_wps_results'       => sanitize_text_field( $_POST['wps_results'] ?? 'public' ),
            '_wps_active'        => isset( $_POST['wps_active'] ) ? '1' : '0',
            '_wps_question'      => sanitize_text_field( $_POST['wps_question'] ?? '' ),
            '_wps_sync_disabled' => isset( $_POST['wps_sync_disabled'] ) ? '1' : '0',
        ];
        foreach ( $fields as $key => $val ) {
            update_post_meta( $post_id, $key, $val );
        }

        self::save_choices( $post_id, $_POST['wps_choice'] ?? [] );

        /**
         * Déclenché après la sauvegarde complète d'un sondage.
         * Utilisé par WPS_Polylang pour ré-enregistrer les chaînes traduisibles.
         *
         * @param int   $post_id   ID du sondage
         * @param array $submitted Données brutes du formulaire
         */
        do_action( 'wps_after_save_poll', $post_id, $_POST );
    }

    private static function save_choices( $poll_id, $submitted ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wps_choices';

        // IDs existants en base
        $existing_ids = array_map( 'intval', $wpdb->get_col(
            $wpdb->prepare( "SELECT id FROM $table WHERE poll_id = %d", $poll_id )
        ) );

        $submitted_ids = [];          // IDs DB gardés/créés dans ce save
        $key_to_db_id  = [];          // map : clé PHP form → DB id (pour résoudre les parents)

        /* ════ PASSE 1 : traiter d'abord les items sans parent (parent == 0 ou parent = clé existante) ════ */
        $ordered = [];
        foreach ( $submitted as $key => $data ) {
            $parent = absint( $data['parent'] ?? 0 );
            $db_id  = absint( $data['id']     ?? 0 );
            // Top-level en premier, puis enfants
            $ordered[ $parent > 0 ? 1 : 0 ][ $key ] = $data;
        }
        ksort( $ordered );

        foreach ( $ordered as $group ) {
            foreach ( $group as $key => $data ) {
                $label   = sanitize_text_field( $data['label']  ?? '' );
                $order   = absint( $data['order']  ?? 0 );
                $db_id   = absint( $data['id']     ?? 0 );
                $form_parent = absint( $data['parent'] ?? 0 );

                if ( $label === '' ) continue;

                // Résoudre le parent_id réel
                $real_parent = 0;
                if ( $form_parent > 0 ) {
                    if ( in_array( $form_parent, $existing_ids, true ) ) {
                        // Parent existait déjà en DB
                        $real_parent = $form_parent;
                    } elseif ( isset( $key_to_db_id[ $form_parent ] ) ) {
                        // Parent créé dans cette même session (passe 1)
                        $real_parent = $key_to_db_id[ $form_parent ];
                    }
                    // sinon on l'attache à la racine (fallback sûr)
                }

                if ( $db_id > 0 && in_array( $db_id, $existing_ids, true ) ) {
                    // Mise à jour d'un choix existant
                    $wpdb->update(
                        $table,
                        [ 'label' => $label, 'parent_id' => $real_parent, 'sort_order' => $order ],
                        [ 'id'    => $db_id ],
                        [ '%s', '%d', '%d' ],
                        [ '%d' ]
                    );
                    $submitted_ids[]      = $db_id;
                    $key_to_db_id[ $key ] = $db_id;
                } else {
                    // Nouveau choix
                    $wpdb->insert(
                        $table,
                        [ 'poll_id' => $poll_id, 'label' => $label, 'parent_id' => $real_parent, 'sort_order' => $order ],
                        [ '%d', '%s', '%d', '%d' ]
                    );
                    $new_id = (int) $wpdb->insert_id;
                    $submitted_ids[]      = $new_id;
                    $key_to_db_id[ $key ] = $new_id;
                }
            }
        }

        // Supprimer les choix retirés (et leurs enfants en cascade)
        $to_delete = array_diff( $existing_ids, $submitted_ids );
        if ( $to_delete ) {
            $ph = implode( ',', array_map( 'intval', $to_delete ) );
            $wpdb->query( "DELETE FROM $table WHERE id IN ($ph) OR parent_id IN ($ph)" );
        }
    }
}
