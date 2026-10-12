<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class WPS_Public {

    public static function init() {
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'assets' ] );
        add_filter( 'the_content',        [ __CLASS__, 'inject_poll' ] );
        add_filter( 'template_include',   [ __CLASS__, 'archive_template' ] );
        add_filter( 'template_include',   [ __CLASS__, 'single_template' ] );
    }

    /* ── Assets ── */
    public static function assets() {
        wp_enqueue_style(
            'wps-public',
            WPS_PLUGIN_URL . 'public/css/wps-public.css',
            [], WPS_VERSION
        );
        wp_enqueue_script(
            'wps-public',
            WPS_PLUGIN_URL . 'public/js/wps-public.js',
            [], WPS_VERSION, true   // zéro dépendance jQuery
        );
        wp_localize_script( 'wps-public', 'wpsData', [
            'ajaxurl'  => admin_url( 'admin-ajax.php' ),
            // Nonce de repli uniquement. La source de vérité est le nonce
            // frais renvoyé par les réponses AJAX (jamais mises en cache).
            'nonce'    => wp_create_nonce( 'wps_write_action' ),
            'is_admin' => current_user_can( 'manage_options' ) ? '1' : '0',
            'i18n'     => [
                'vote_success'  => __( 'Merci pour votre vote !', 'wp-sondage' ),
                'vote_error'    => __( 'Erreur lors du vote.', 'wp-sondage' ),
                'flood'         => __( 'Vous avez déjà voté.', 'wp-sondage' ),
                'closed'        => __( 'Ce sondage est fermé.', 'wp-sondage' ),
                'pick_one'      => __( 'Veuillez choisir une réponse.', 'wp-sondage' ),
                'loading'       => __( 'Chargement…', 'wp-sondage' ),
                'results_priv'  => __( 'Résultats privés.', 'wp-sondage' ),
                'reset_poll'    => __( 'Réinitialiser les votes', 'wp-sondage' ),
                'reset_confirm' => __( 'Supprimer tous les votes de ce sondage ? Cette action est irréversible.', 'wp-sondage' ),
                'see_results'   => __( 'Voir les résultats', 'wp-sondage' ),
                'already_voted' => __( 'Vous avez déjà répondu à ce sondage.', 'wp-sondage' ),
            ],
        ] );
    }

    /* ── Injection page single ── */
    public static function inject_poll( $content ) {
        if ( ! is_singular( 'wps_poll' ) || ! in_the_loop() || ! is_main_query() ) return $content;
        ob_start();
        self::render_poll_single( get_the_ID() );
        return $content . ob_get_clean();
    }

    /* ── Templates ── */
    public static function archive_template( $template ) {
        if ( is_post_type_archive( 'wps_poll' ) || is_tax( 'wps_category' ) ) {
            $t = WPS_PLUGIN_DIR . 'templates/archive-poll.php';
            if ( file_exists( $t ) ) return $t;
        }
        return $template;
    }

    public static function single_template( $template ) {
        if ( is_singular( 'wps_poll' ) ) {
            $theme = locate_template( [ 'single-wps_poll.php', 'single.php' ] );
            if ( $theme ) return $theme;
            $plugin = WPS_PLUGIN_DIR . 'templates/single-wps_poll.php';
            if ( file_exists( $plugin ) ) return $plugin;
        }
        return $template;
    }

    /* ════════════════════════════════════════
       RENDER — page détail
    ════════════════════════════════════════ */
    public static function render_poll_single( int $poll_id ) {
        $question   = wps_question( $poll_id );
        $type       = get_post_meta( $poll_id, '_wps_type',    true ) ?: 'radio';
        $results_ok = get_post_meta( $poll_id, '_wps_results', true ) === 'public';
        $is_open    = WPS_Ajax::poll_is_open( $poll_id );

        // IMPORTANT : has_voted() n'est PAS appelé au rendu.
        // C'est un état personnel : figé dans le HTML mis en cache, il serait
        // servi à tous les visiteurs. Le JS interroge wps_poll_state après
        // chargement (réponse jamais mise en cache) et bascule l'affichage.
        // Le formulaire est donc toujours rendu → bon pour le SEO.
        $has_voted  = false;

        global $wpdb;
        $choices = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wps_choices WHERE poll_id = %d ORDER BY parent_id, sort_order",
            $poll_id
        ) );

        include WPS_PLUGIN_DIR . 'templates/poll-single.php';
    }

    /* ════════════════════════════════════════
       RENDER — widget / shortcode
    ════════════════════════════════════════ */
    /**
     * Rendu widget — appelé UNIQUEMENT via AJAX (wps_load_poll).
     * La réponse AJAX n'étant jamais mise en cache, has_voted() est fiable ici.
     */
    public static function render_poll_widget( int $poll_id ) {
        $question   = wps_question( $poll_id );
        $type       = get_post_meta( $poll_id, '_wps_type',    true ) ?: 'radio';
        $results_ok = get_post_meta( $poll_id, '_wps_results', true ) === 'public';
        $is_open    = WPS_Ajax::poll_is_open( $poll_id );
        $has_voted  = WPS_Antiflood::has_voted( $poll_id );

        global $wpdb;
        $choices = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wps_choices WHERE poll_id = %d ORDER BY parent_id, sort_order",
            $poll_id
        ) );

        include WPS_PLUGIN_DIR . 'templates/poll-widget.php';
    }

    /* ════════════════════════════════════════
       HELPERS
    ════════════════════════════════════════ */

    /** Construit l'arbre de choix depuis un tableau à plat. */
    public static function build_tree( array $choices, int $parent = 0 ): array {
        $tree = [];
        foreach ( $choices as $c ) {
            if ( (int) $c->parent_id === $parent ) {
                $c->children = self::build_tree( $choices, (int) $c->id );
                $tree[] = $c;
            }
        }
        return $tree;
    }

    /**
     * Rendu récursif des choix (formulaire).
     * Applique le filtre wps_choice_label pour Polylang/WPML.
     */
    public static function render_choice_tree( array $tree, string $type, int $depth = 0 ) {
        foreach ( $tree as $choice ) {
            $has_children = ! empty( $choice->children );
            $label        = wps_choice_label( $choice->label, (int) $choice->id );
            $cls          = $depth > 0 ? 'wps-choice-item wps-choice-child' : 'wps-choice-item';
            ?>
            <div class="<?php echo esc_attr( $cls ); ?>"
                 data-id="<?php echo (int) $choice->id; ?>"
                 <?php echo $has_children ? 'data-has-children="1"' : ''; ?>
                 <?php echo $depth > 0 ? 'data-depth="' . $depth . '"' : ''; ?>>
                <label class="wps-choice-label">
                    <input type="<?php echo esc_attr( $type ); ?>"
                           name="wps_choice[]"
                           value="<?php echo (int) $choice->id; ?>"
                           class="wps-choice-input"
                           <?php echo $has_children ? 'data-has-children="1"' : ''; ?>>
                    <span class="wps-choice-text"><?php echo esc_html( $label ); ?></span>
                </label>
                <?php if ( $has_children ) : ?>
                <div class="wps-sub-choices" style="display:none">
                    <?php self::render_choice_tree( $choice->children, $type, $depth + 1 ); ?>
                </div>
                <?php endif; ?>
            </div>
            <?php
        }
    }

    /**
     * Rendu HTML des barres de résultats.
     * Applique le filtre wps_choice_label pour Polylang/WPML.
     * data-pct déclenche l'animation CSS/JS au chargement de page.
     */
    public static function render_results_html( array $results ) {
        $total     = (int) $results['total'];
        $poll_type = $results['poll_type'] ?? 'radio';
        $is_checkbox = ( $poll_type === 'checkbox' );
        ?>
        <div class="wps-results" data-total="<?php echo $total; ?>" data-poll-type="<?php echo esc_attr( $poll_type ); ?>">
            <p class="wps-total">
                <?php printf( _n( '%d votant', '%d votants', $total, 'wp-sondage' ), $total ); ?>
                <?php if ( $is_checkbox ) : ?>
                <span class="wps-total-note"><?php _e( '— choix multiples, les % peuvent dépasser 100%', 'wp-sondage' ); ?></span>
                <?php endif; ?>
            </p>
            <?php
            $top = array_filter( $results['choices'], fn( $c ) => $c['parent_id'] === 0 );
            foreach ( $top as $choice ) :
                $label = wps_choice_label( $choice['label'], $choice['id'] );
                $pct   = (float) $choice['percent'];
                $subs  = array_filter( $results['choices'], fn( $c ) => $c['parent_id'] === $choice['id'] );
            ?>
            <div class="wps-result-row" data-choice-id="<?php echo esc_attr( $choice['id'] ); ?>">
                <div class="wps-result-label"><?php echo esc_html( $label ); ?></div>
                <div class="wps-result-bar-wrap">
                    <div class="wps-result-bar wps-bar-animate"
                         data-pct="<?php echo $pct; ?>"
                         style="width:0%"></div>
                </div>
                <div class="wps-result-pct"><?php echo $pct; ?>%</div>
                <div class="wps-result-count">(<?php echo (int) $choice['count']; ?>)</div>
                <?php if ( $subs ) : ?>
                <div class="wps-sub-results">
                    <?php foreach ( $subs as $sub ) :
                        $sub_label = wps_choice_label( $sub['label'], $sub['id'] );
                        $sub_pct   = (float) $sub['percent'];
                    ?>
                    <div class="wps-result-row wps-result-sub">
                        <div class="wps-result-label">↳ <?php echo esc_html( $sub_label ); ?></div>
                        <div class="wps-result-bar-wrap">
                            <div class="wps-result-bar wps-bar-animate"
                                 data-pct="<?php echo $sub_pct; ?>"
                                 style="width:0%"></div>
                        </div>
                        <div class="wps-result-pct"><?php echo $sub_pct; ?>%</div>
                        <div class="wps-result-count">(<?php echo (int) $sub['count']; ?>)</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
